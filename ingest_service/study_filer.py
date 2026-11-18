import os
import re
import sys
import json
import shutil
import uuid
import traceback
from datetime import datetime, date, time as dt_time, timedelta

import numpy as np
import pydicom
from pydicom.pixel_data_handlers.util import apply_voi_lut
from PIL import Image

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import (
    load_config,
    get_db_conn,
    normalize_patient_id,
    normalize_kana_yomi,
    sha256_file,
    atomic_write_json,
    log_ingest_event,
    match_modality_profile,
)
import ecg_render
import dcm_volume_helper


IMAGE_EXTS = {"jpg", "jpeg", "png", "webp", "bmp"}
VIDEO_EXTS = {"mp4", "webm", "mov", "m4v", "avi", "mpg", "mpeg", "wmv", "mkv", "mts", "ogv"}
ECG_EXTS = {"ecg", "001", "mwf", "nkc"}
PDF_EXTS = {"pdf"}


def _is_browser_safe_jpeg_stream(jpg_bytes: bytes) -> bool:
    """
    DICOMカプセル化データ内のJPEGストリームが、ブラウザで直接描画可能な
    8bit Baseline (SOF0=FFC0) または Progressive (SOF2=FFC2) であるか判定する。
    16bit/12bit JPEG Lossless (SOF3=FFC3) や JPEG-LS (FFF7) は False を返し pydicom デコードへ回す。
    """
    n = len(jpg_bytes)
    if n < 16 or jpg_bytes[0:3] != b"\xff\xd8\xff":
        return False
    i = 2
    while i < n - 4:
        if jpg_bytes[i] != 0xFF:
            i += 1
            continue
        while i < n and jpg_bytes[i] == 0xFF:
            i += 1
        if i >= n:
            break
        marker = jpg_bytes[i]
        i += 1
        if marker in (0xD8, 0xD9) or (0xD0 <= marker <= 0xD7) or marker == 0x01:
            continue
        if marker == 0xDA:  # SOS
            break
        if i + 2 > n:
            break
        seg_len = (jpg_bytes[i] << 8) | jpg_bytes[i + 1]
        if seg_len < 2 or i + seg_len > n:
            break
        # SOF markers
        if marker in (0xC0, 0xC2):
            precision = jpg_bytes[i + 2] if i + 2 < n else 0
            return precision == 8
        if marker in (0xC1, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF, 0xF7):
            return False
        i += seg_len
    return False


def decode_dicom_text(val) -> str:
    if val is None:
        return ""
    if isinstance(val, bytes):
        for enc in ("utf-8", "cp932", "iso2022_jp", "latin1"):
            try:
                return val.decode(enc).strip()
            except Exception:
                continue
        return val.decode("utf-8", errors="replace").strip()
    return str(val).strip()


def parse_dicom_date(raw: str) -> date | None:
    if not raw:
        return None
    digits = re.sub(r"[^0-9]", "", str(raw))
    if len(digits) >= 8:
        try:
            return date(int(digits[0:4]), int(digits[4:6]), int(digits[6:8]))
        except ValueError:
            return None
    return None


def parse_dicom_time(raw: str) -> dt_time | None:
    if not raw:
        return None
    digits = re.sub(r"[^0-9]", "", str(raw))
    if len(digits) >= 6:
        try:
            return dt_time(int(digits[0:2]), int(digits[2:4]), int(digits[4:6]))
        except ValueError:
            return None
    if len(digits) == 4:
        try:
            return dt_time(int(digits[0:2]), int(digits[2:4]), 0)
        except ValueError:
            return None
    return None


def is_likely_dicom(filepath: str) -> bool:
    ext = os.path.splitext(filepath)[1].lower().lstrip(".")
    if ext in IMAGE_EXTS or ext in VIDEO_EXTS or ext in ECG_EXTS or ext in PDF_EXTS or ext in ("txt", "csv", "json", "html", "htm", "xml", "inf", "ini", "exe", "dll", "dat", "idx", "thu", "gif"):
        return False
    base_upper = os.path.basename(filepath).upper()
    if base_upper == "DICOMDIR":
        return False
    try:
        with open(filepath, "rb") as f:
            header = f.read(132)
            if len(header) >= 132 and header[128:132] == b"DICM":
                return True
        ds = pydicom.dcmread(filepath, stop_before_pixels=True, force=True)
        return any(k in ds for k in ("Modality", "SOPInstanceUID", "StudyInstanceUID", "PatientID", "Rows"))
    except Exception:
        return False


def inspect_file_metadata(filepath: str, source_protocol: str = "FOLDER_WATCH", extra_meta: dict = None) -> dict:
    """
    着信ファイル（DICOM / フクダECG / 一般画像 / PDF）からメタデータ辞書を抽出する。
    """
    extra_meta = extra_meta or {}
    orig_filename = extra_meta.get("orig_filename") or os.path.basename(filepath)
    ext = os.path.splitext(orig_filename)[1].lower().lstrip(".")

    meta = {
        "orig_filename": orig_filename,
        "file_ext": ext,
        "file_kind": "OTHER",
        "source_protocol": source_protocol,
        "source_ip": extra_meta.get("source_ip", ""),
        "calling_ae_title": extra_meta.get("calling_ae_title", ""),
        "watch_subfolder": extra_meta.get("watch_subfolder", ""),
        "raw_patient_id": extra_meta.get("patient_id", ""),
        "raw_patient_name": extra_meta.get("patient_name", ""),
        "raw_birth_date": extra_meta.get("birth_date", ""),
        "raw_sex": extra_meta.get("sex", ""),
        "study_date": None,
        "study_time": None,
        "study_date_raw": extra_meta.get("study_date", ""),
        "dicom_modality": extra_meta.get("modality", ""),
        "station_name": extra_meta.get("station_name", ""),
        "manufacturer": extra_meta.get("manufacturer", ""),
        "model_name": extra_meta.get("model_name", ""),
        "body_part": extra_meta.get("body_part", ""),
        "protocol_name": extra_meta.get("protocol_name", ""),
        "study_description": extra_meta.get("study_description", ""),
        "series_description": extra_meta.get("series_description", ""),
        "accession_number": extra_meta.get("accession_number", ""),
        "study_instance_uid": extra_meta.get("study_instance_uid", ""),
        "sop_instance_uid": "",
        "transfer_syntax": "",
        "width": None,
        "height": None,
        "doctor": extra_meta.get("doctor", ""),
        "department": extra_meta.get("department", ""),
        "findings": extra_meta.get("findings", ""),
        "comment": extra_meta.get("comment", ""),
        "ecg_parsed": None,
    }

    # 1. 心電図 (.ECG / .001 フクダ電子 ／ .MWF MFER標準・日本光電 ／ .NKC 日本光電)
    if ext in ECG_EXTS or extra_meta.get("force_kind") == "ECG":
        meta["file_kind"] = "ECG"
        meta["dicom_modality"] = meta["dicom_modality"] or "ECG"
        stem = os.path.splitext(orig_filename)[0]

        # (A) Fukuda_LAN_RS_Base.exe 純正出力形式:
        #     {E12|A40|ATN|ARY|HLT|STS}_{R|E|P|N}_{YYYYMMDD}_{HHMMSS}_ID{患者ID}.ECG
        m_lan = re.match(
            r"^(?:E12|A40|ATN|ARY|HLT|STS)(?:_[A-Z0-9]+)?_(\d{8})_(\d{6})_ID(.*)$",
            stem,
            re.IGNORECASE,
        )
        # (B) 日本光電 ConvName_Online / Cardiofax 出力形式:
        #     {被検者ID(最大12桁)}_{YYYYMMDDhhmmss}.{nkc|mwf} 例: "999999080343_20261117093000.mwf"
        m_nk = re.match(r"^([0-9A-Za-z()\-]+)_(\d{8})(\d{6})$", stem)
        if m_lan:
            if not meta["study_date"]:
                meta["study_date"] = parse_dicom_date(m_lan.group(1))
            if not meta["study_time"]:
                meta["study_time"] = parse_dicom_time(m_lan.group(2))
            if not meta["raw_patient_id"]:
                meta["raw_patient_id"] = m_lan.group(3).strip()
        elif m_nk:
            if not meta["raw_patient_id"]:
                meta["raw_patient_id"] = m_nk.group(1).strip()
            if not meta["study_date"]:
                meta["study_date"] = parse_dicom_date(m_nk.group(2))
            if not meta["study_time"]:
                meta["study_time"] = parse_dicom_time(m_nk.group(3))
        elif not meta["raw_patient_id"]:
            m_id_tail = re.search(r"_ID([0-9A-Za-z()\-]*)$", stem, re.IGNORECASE)
            if m_id_tail:
                meta["raw_patient_id"] = m_id_tail.group(1).strip()
            else:
                # (C) 旧RS-Base格納形式: "83003(D)~001.ECG", "00070755_20261117.ECG", "83003.ECG"
                stem_head = re.split(r"[~_]", stem)[0]
                if stem_head.isdigit() or re.match(r"^\d+\([A-Za-z]+\)$", stem_head):
                    meta["raw_patient_id"] = stem_head

        parsed_ecg = ecg_render.parse_ecg_file(filepath)
        if parsed_ecg:
            meta["ecg_parsed"] = parsed_ecg
            vendor = str(parsed_ecg.get("vendor") or "")
            meta["manufacturer"] = "Nihon Kohden" if ("NIHON_KOHDEN" in vendor or ext in ("mwf", "nkc")) else "Fukuda Denshi"
            meta["model_name"] = parsed_ecg.get("device") or ("Cardiofax" if ext in ("mwf", "nkc") else "FCP")
            if not meta["raw_patient_id"] and parsed_ecg.get("patient_id"):
                meta["raw_patient_id"] = str(parsed_ecg["patient_id"]).strip()
            if not meta["raw_patient_name"] and parsed_ecg.get("patient_name"):
                meta["raw_patient_name"] = str(parsed_ecg["patient_name"]).strip()
            dt_str = parsed_ecg.get("date") or ""
            if dt_str:
                meta["study_date_raw"] = dt_str
                try:
                    dt_obj = datetime.strptime(dt_str, "%Y/%m/%d %H:%M:%S")
                    meta["study_date"] = dt_obj.date()
                    meta["study_time"] = dt_obj.time()
                except ValueError:
                    pass

    # 2. PDF 検査レポート
    elif ext in PDF_EXTS:
        meta["file_kind"] = "PDF"
        meta["dicom_modality"] = meta["dicom_modality"] or "DOC"

    # 3. 一般画像 (JPEG / PNG / WebP / BMP)
    elif ext in IMAGE_EXTS:
        meta["file_kind"] = "IMAGE"
        meta["dicom_modality"] = meta["dicom_modality"] or "OT"
        try:
            with Image.open(filepath) as im:
                meta["width"], meta["height"] = im.size
        except Exception:
            pass

    # 3.5 動画ファイル (MP4 / MOV / WebM / AVI / MPG / WMV 等)
    elif ext in VIDEO_EXTS:
        meta["file_kind"] = "VIDEO"
        meta["dicom_modality"] = meta["dicom_modality"] or "ES"
        try:
            import cv2
            cap = cv2.VideoCapture(filepath)
            if cap.isOpened():
                w = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH) or 0)
                h = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT) or 0)
                if w > 0 and h > 0:
                    meta["width"], meta["height"] = w, h
            cap.release()
        except Exception:
            pass

    # 4. DICOM ファイル
    elif is_likely_dicom(filepath):
        meta["file_kind"] = "DICOM"
        ds = pydicom.dcmread(filepath, stop_before_pixels=True, force=True)
        if not meta["raw_patient_id"]:
            meta["raw_patient_id"] = decode_dicom_text(getattr(ds, "PatientID", ""))
        if not meta["raw_patient_name"]:
            kj, _ = dcm_volume_helper.parse_japanese_person_name(decode_dicom_text(getattr(ds, "PatientName", "")))
            meta["raw_patient_name"] = kj
        if not meta["raw_birth_date"]:
            meta["raw_birth_date"] = decode_dicom_text(getattr(ds, "PatientBirthDate", ""))
        if not meta["raw_sex"]:
            sex_tag = decode_dicom_text(getattr(ds, "PatientSex", "")).upper()
            meta["raw_sex"] = "男" if sex_tag == "M" else ("女" if sex_tag == "F" else sex_tag)

        s_date = decode_dicom_text(getattr(ds, "StudyDate", "")) or decode_dicom_text(getattr(ds, "ContentDate", "")) or decode_dicom_text(getattr(ds, "AcquisitionDate", ""))
        s_time = decode_dicom_text(getattr(ds, "StudyTime", "")) or decode_dicom_text(getattr(ds, "ContentTime", "")) or decode_dicom_text(getattr(ds, "AcquisitionTime", ""))
        if extra_meta.get("force_study_date") and extra_meta.get("study_date"):
            meta["study_date"] = parse_dicom_date(extra_meta["study_date"])
        if not meta["study_date"]:
            meta["study_date"] = parse_dicom_date(s_date)
        if not meta["study_time"]:
            meta["study_time"] = parse_dicom_time(s_time)
        meta["study_date_raw"] = s_date or meta["study_date_raw"]

        meta["dicom_modality"] = (meta["dicom_modality"] or decode_dicom_text(getattr(ds, "Modality", "")) or "OT").upper()
        meta["station_name"] = meta["station_name"] or decode_dicom_text(getattr(ds, "StationName", ""))
        meta["manufacturer"] = meta["manufacturer"] or decode_dicom_text(getattr(ds, "Manufacturer", ""))
        meta["model_name"] = meta["model_name"] or decode_dicom_text(getattr(ds, "ManufacturerModelName", ""))
        meta["body_part"] = meta["body_part"] or decode_dicom_text(getattr(ds, "BodyPartExamined", ""))
        meta["protocol_name"] = meta["protocol_name"] or decode_dicom_text(getattr(ds, "ProtocolName", ""))
        meta["study_description"] = meta["study_description"] or decode_dicom_text(getattr(ds, "StudyDescription", ""))
        meta["series_description"] = meta["series_description"] or decode_dicom_text(getattr(ds, "SeriesDescription", ""))
        meta["accession_number"] = meta["accession_number"] or decode_dicom_text(getattr(ds, "AccessionNumber", ""))
        meta["study_instance_uid"] = meta["study_instance_uid"] or decode_dicom_text(getattr(ds, "StudyInstanceUID", ""))
        meta["sop_instance_uid"] = decode_dicom_text(getattr(ds, "SOPInstanceUID", ""))
        meta["institution_name"] = dcm_volume_helper.friendly_institution_name(decode_dicom_text(getattr(ds, "InstitutionName", "")))

        if hasattr(ds, "file_meta") and ds.file_meta is not None:
            meta["transfer_syntax"] = decode_dicom_text(getattr(ds.file_meta, "TransferSyntaxUID", ""))

        rows = getattr(ds, "Rows", None)
        cols = getattr(ds, "Columns", None)
        if cols is not None and rows is not None:
            meta["width"] = int(cols)
            meta["height"] = int(rows)
        if not meta["doctor"]:
            if source_protocol in ("HTTP_API", "CD_IMPORT") and meta.get("institution_name"):
                meta["doctor"] = meta["institution_name"]
            else:
                meta["doctor"] = decode_dicom_text(getattr(ds, "PerformingPhysicianName", "")).replace("^", " ").strip()

    # 日付のフォールバック（手動指定または当日）
    if meta["study_date"] is None and meta["study_date_raw"]:
        meta["study_date"] = parse_dicom_date(meta["study_date_raw"])
    if meta["study_date"] is None:
        meta["study_date"] = date.today()
    if meta["study_time"] is None:
        now_t = datetime.now().time()
        meta["study_time"] = dt_time(now_t.hour, now_t.minute, now_t.second)

    return meta


def generate_study_caches(filepath: str, file_kind: str, cache_root: str, study_id: int, seq_no: int, ecg_parsed: dict = None):
    """
    診察室で開いた瞬間の待ち時間を0秒にするための先行キャッシュ生成。
    - DICOM / IMAGE -> cache/thumbs/{study_id}/{seq:04d}_view.jpg & _thumb.jpg
    - ECG -> cache/ecg_json/{study_id}/{seq:04d}.json
    """
    if file_kind == "ECG":
        data = ecg_parsed or ecg_render.parse_ecg_file(filepath)
        if data and data.get("leads"):
            out_dir = os.path.join(cache_root, "ecg_json", str(study_id))
            os.makedirs(out_dir, exist_ok=True)
            payload = {"status": "success", "file": filepath, "data": data}
            atomic_write_json(os.path.join(out_dir, f"{seq_no:04d}.json"), payload)
        return

    if file_kind not in ("DICOM", "IMAGE", "VIDEO"):
        return

    out_dir = os.path.join(cache_root, "thumbs", str(study_id))
    os.makedirs(out_dir, exist_ok=True)
    view_path = os.path.join(out_dir, f"{seq_no:04d}_view.jpg")
    thumb_path = os.path.join(out_dir, f"{seq_no:04d}_thumb.jpg")

    if file_kind == "VIDEO":
        if dcm_volume_helper.extract_video_thumbnail(filepath, view_path, is_thumb=False):
            try:
                with Image.open(view_path) as im_v:
                    th = im_v.convert("RGB")
                    th.thumbnail((220, 220), Image.Resampling.LANCZOS)
                    th.save(thumb_path, format="JPEG", quality=82)
            except Exception:
                pass
        return

    pil_img = None
    if file_kind == "IMAGE":
        try:
            with Image.open(filepath) as src_im:
                pil_img = src_im.convert("RGB")
        except Exception:
            return
    else:
        # 1. 8bit Baseline/Progressive 単フレームカプセル化JPEGのみ高速直接抽出
        #    (16bit/12bit JPEG Lossless SOF3 やマルチフレームシネDICOMは必ず pydicom デコードへ回す)
        try:
            ds_hdr = pydicom.dcmread(filepath, stop_before_pixels=True, force=True)
            num_frames = int(getattr(ds_hdr, "NumberOfFrames", 1) or 1)
            bits_stored = int(getattr(ds_hdr, "BitsStored", 8) or 8)
            ts_uid = str(getattr(ds_hdr.file_meta, "TransferSyntaxUID", "")).strip() if hasattr(ds_hdr, "file_meta") else ""
            if num_frames == 1 and bits_stored <= 8 and ts_uid in ("1.2.840.10008.1.2.4.50", "1.2.840.10008.1.2.4.51", ""):
                with open(filepath, "rb") as f:
                    raw = f.read()
                soi = raw.find(b"\xff\xd8\xff")
                eoi = raw.rfind(b"\xff\xd9")
                if soi != -1 and eoi != -1 and eoi > soi:
                    jpg_bytes = raw[soi : eoi + 2]
                    if _is_browser_safe_jpeg_stream(jpg_bytes):
                        tmp_view = view_path + f".tmp_{os.getpid()}"
                        with open(tmp_view, "wb") as wf:
                            wf.write(jpg_bytes)
                        os.replace(tmp_view, view_path)
                        with Image.open(view_path) as im_v:
                            thumb_im = im_v.convert("RGB")
                            thumb_im.thumbnail((220, 220), Image.Resampling.LANCZOS)
                            thumb_im.save(thumb_path, format="JPEG", quality=82)
                        return
        except Exception:
            pass

        # 2. pydicom (gdcm / pylibjpeg / pillow) による 16bit Lossless CT/MR・XP・マルチフレーム適正階調レンダリング
        try:
            ds = pydicom.dcmread(filepath, force=True)
            arr = ds.pixel_array
            if arr.ndim == 4 and arr.shape[3] in (3, 4):
                pil_img = Image.fromarray(arr[0, :, :, :3].astype(np.uint8), mode="RGB")
            elif arr.ndim == 3 and arr.shape[2] in (3, 4):
                pil_img = Image.fromarray(arr[:, :, :3].astype(np.uint8), mode="RGB")
            else:
                if arr.ndim == 3:
                    arr = arr[0]
                try:
                    arr_lut = apply_voi_lut(arr, ds)
                except Exception:
                    arr_lut = arr
                if hasattr(ds, "RescaleIntercept") and hasattr(ds, "RescaleSlope") and arr_lut is arr:
                    try:
                        arr_lut = arr_lut.astype(np.float32) * float(ds.RescaleSlope) + float(ds.RescaleIntercept)
                    except Exception:
                        pass
                min_v = float(np.min(arr_lut))
                max_v = float(np.max(arr_lut))
                if max_v > min_v:
                    norm = ((arr_lut - min_v) / (max_v - min_v) * 255.0).astype(np.uint8)
                else:
                    norm = np.zeros(arr_lut.shape, dtype=np.uint8)
                if str(getattr(ds, "PhotometricInterpretation", "")).strip().upper() == "MONOCHROME1":
                    norm = 255 - norm
                pil_img = Image.fromarray(norm, mode="L")
        except Exception:
            return

    if pil_img is not None:
        pil_img.save(view_path, format="JPEG", quality=88)
        thumb_im = pil_img.copy()
        thumb_im.thumbnail((220, 220), Image.Resampling.LANCZOS)
        thumb_im.save(thumb_path, format="JPEG", quality=82)


def sync_study_meta_json(conn, cfg: dict, study_id: int):
    """
    DBの最新状態に基づいて検査フォルダ直下の study_meta.json をアトミック更新する。
    """
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT s.*, mp.profile_code
            FROM studies s
            LEFT JOIN modality_profiles mp ON mp.profile_id = s.profile_id
            WHERE s.study_id = %s
            """,
            (study_id,),
        )
        st = cur.fetchone()
        if not st:
            return
        cur.execute(
            """
            SELECT seq_no, filename, orig_filename, file_kind, sop_instance_uid,
                   sha256_hash, file_bytes, width, height, transfer_syntax
            FROM study_files
            WHERE study_id = %s
            ORDER BY seq_no ASC
            """,
            (study_id,),
        )
        files = cur.fetchall()

    vol_cfg = cfg["storage"]["volumes"].get(st["volume_id"])
    if not vol_cfg:
        return
    study_abs_dir = os.path.join(vol_cfg["path"], st["rel_path"].replace("/", os.sep))
    if not os.path.isdir(study_abs_dir):
        return

    meta_payload = {
        "schema_version": 1,
        "study_id": st["study_id"],
        "legacy_renban": st["legacy_renban"],
        "patient_id": st["patient_id"],
        "study_date": st["study_date"].isoformat() if st["study_date"] else "",
        "study_time": st["study_time"].strftime("%H:%M:%S") if st["study_time"] else "",
        "modality": st["modality"],
        "kensamei": st["kensamei"],
        "display_category": st["display_category"],
        "badge_color": st["badge_color"],
        "profile_code": st.get("profile_code") or "",
        "station_name": st["station_name"],
        "manufacturer": st["manufacturer"],
        "model_name": st["model_name"],
        "doctor": st["doctor"],
        "department": st["department"],
        "findings": st["findings"],
        "comment": st["comment"],
        "primary_file_type": st["primary_file_type"],
        "study_instance_uid": st["study_instance_uid"] or "",
        "is_deleted": bool(st["is_deleted"]),
        "files": files,
        "updated_at": datetime.now().astimezone().isoformat(),
    }
    atomic_write_json(os.path.join(study_abs_dir, "study_meta.json"), meta_payload)


def simulate_file_routing(filepath: str, source_protocol: str = "SIMULATOR", extra_meta: dict = None) -> dict:
    """
    Modality Studio ドラッグ＆ドロップ振分シミュレータ用（DB・ストレージ無変更ドライラン）。
    """
    cfg = load_config()
    meta = inspect_file_metadata(filepath, source_protocol=source_protocol, extra_meta=extra_meta)
    norm_pid, pid_err = normalize_patient_id(meta["raw_patient_id"])

    conn = get_db_conn(cfg)
    try:
        matched = match_modality_profile(conn, meta, record_discovery=False)
        patient_db = None
        if norm_pid:
            with conn.cursor() as cur:
                cur.execute("SELECT patient_id, kanja_kanji, kanja_yomi, sex, birth_date FROM patients WHERE patient_id = %s", (norm_pid,))
                row = cur.fetchone()
                if row:
                    patient_db = {
                        "patient_id": row["patient_id"],
                        "kanja_kanji": row["kanja_kanji"],
                        "kanja_yomi": row["kanja_yomi"],
                        "sex": row["sex"],
                        "birth_date": row["birth_date"].isoformat() if row["birth_date"] else "",
                    }
    finally:
        conn.close()

    viewer_opts = matched.get("viewer_options") or {}
    if isinstance(viewer_opts, str):
        viewer_opts = json.loads(viewer_opts)

    return {
        "status": "success",
        "will_quarantine": norm_pid is None,
        "quarantine_reason": pid_err,
        "normalized_patient_id": norm_pid,
        "patient_in_master": patient_db,
        "extracted_meta": {
            "orig_filename": meta["orig_filename"],
            "file_kind": meta["file_kind"],
            "raw_patient_id": meta["raw_patient_id"],
            "raw_patient_name": meta["raw_patient_name"],
            "study_date": meta["study_date"].isoformat() if meta["study_date"] else "",
            "study_time": meta["study_time"].strftime("%H:%M:%S") if meta["study_time"] else "",
            "dicom_modality": meta["dicom_modality"],
            "station_name": meta["station_name"],
            "manufacturer": meta["manufacturer"],
            "model_name": meta["model_name"],
            "body_part": meta["body_part"],
            "protocol_name": meta["protocol_name"],
            "study_description": meta["study_description"],
            "resolution": f"{meta['width']}x{meta['height']}" if meta["width"] and meta["height"] else "",
            "transfer_syntax": meta["transfer_syntax"],
        },
        "matched_profile": {
            "profile_id": matched.get("profile_id"),
            "profile_code": matched.get("profile_code"),
            "device_name": matched.get("device_name"),
            "icon_emoji": matched.get("icon_emoji", "🏥"),
            "badge_color": matched.get("badge_color", "#2563eb"),
            "display_category": matched.get("display_category", "other"),
            "modality_code": matched.get("modality_code", "OT"),
            "resolved_kensamei": matched.get("resolved_kensamei", "検査画像"),
            "priority_order": matched.get("priority_order", 999),
            "viewer_options": viewer_opts,
        },
    }


def _quarantine_file(conn, cfg: dict, filepath: str, meta: dict, matched: dict, reason: str, remove_source: bool) -> dict:
    q_root = cfg["storage"]["quarantine_root"]
    ts_str = datetime.now().strftime("%Y%m%d_%H%M%S")
    mod_tag = re.sub(r"[^A-Za-z0-9]", "", meta.get("dicom_modality") or "OT")[:8] or "OT"
    q_rel = f"{ts_str}_{mod_tag}_{uuid.uuid4().hex[:6]}"
    q_abs_dir = os.path.join(q_root, q_rel)
    os.makedirs(q_abs_dir, exist_ok=True)

    safe_orig = re.sub(r'[\\/:*?"<>|]+', "_", meta["orig_filename"]) or "file.dat"
    dst_path = os.path.join(q_abs_dir, safe_orig)
    if remove_source:
        shutil.move(filepath, dst_path)
    else:
        shutil.copy2(filepath, dst_path)

    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO quarantine_queue
            (source_protocol, source_ip, raw_patient_id, raw_patient_name, study_date_raw,
             modality, kensamei_guess, file_count, quarantine_rel, reason, status)
            VALUES (%s, %s, %s, %s, %s, %s, %s, 1, %s, %s, 'PENDING')
            RETURNING quarantine_id
            """,
            (
                meta["source_protocol"],
                meta["source_ip"],
                meta["raw_patient_id"] or "",
                meta["raw_patient_name"] or "",
                meta["study_date_raw"] or (meta["study_date"].isoformat() if meta["study_date"] else ""),
                meta["dicom_modality"] or matched.get("modality_code") or "OT",
                matched.get("resolved_kensamei") or "",
                q_rel,
                reason,
            ),
        )
        q_id = cur.fetchone()["quarantine_id"]

    log_ingest_event(
        conn,
        level="WARN",
        channel=meta["source_protocol"],
        event_type="QUARANTINE_IN",
        summary=f"未紐付け隔離: {reason} (元ファイル: {meta['orig_filename']})",
        patient_id=meta["raw_patient_id"] or "",
        detail={"quarantine_id": q_id, "quarantine_rel": q_rel, "raw_patient_name": meta["raw_patient_name"]},
    )
    conn.commit()
    return {"status": "quarantined", "quarantine_id": q_id, "reason": reason, "quarantine_rel": q_rel}


def ingest_single_file(
    filepath: str,
    source_protocol: str = "FOLDER_WATCH",
    extra_meta: dict = None,
    remove_source: bool = True,
    override_kensamei: str = None,
    target_volume_id: str = None,
    force_study_id: int = None,
    shared_conn=None,
    shared_cfg: dict = None,
    skip_sync_meta: bool = False,
    defer_cache_after_seq: int = 0,
) -> dict:
    """
    1ファイルを検証・重複チェック・プロファイル判定し、階層ストレージ＋PostgreSQL＋先行キャッシュへ格納する。
    """
    if not os.path.isfile(filepath):
        return {"status": "error", "message": f"File not found: {filepath}"}

    cfg = shared_cfg or load_config()
    extra_meta = extra_meta or {}
    meta = inspect_file_metadata(filepath, source_protocol=source_protocol, extra_meta=extra_meta)
    if meta["file_kind"] == "OTHER":
        return {"status": "skipped", "message": f"対象外ファイル形式のためスキップしました: {meta['orig_filename']}"}

    # Apply per-StudyInstanceUID overrides from CD Import UI if provided
    study_overrides = extra_meta.get("study_overrides") or {}
    if meta.get("study_instance_uid") and meta["study_instance_uid"] in study_overrides:
        ov = study_overrides[meta["study_instance_uid"]] or {}
        if ov.get("kensamei"):
            override_kensamei = ov["kensamei"]
        if ov.get("doctor"):
            meta["doctor"] = ov["doctor"]
        if ov.get("findings"):
            meta["findings"] = ov["findings"]

    file_hash = sha256_file(filepath)
    file_bytes = os.path.getsize(filepath)
    norm_pid, pid_err = normalize_patient_id(meta["raw_patient_id"])

    owns_conn = shared_conn is None
    conn = shared_conn or get_db_conn(cfg)
    try:
        matched = match_modality_profile(conn, meta, record_discovery=True)
        kensamei = (override_kensamei or "").strip() or matched["resolved_kensamei"]
        modality_code = (meta["dicom_modality"] or matched.get("modality_code") or "OT").upper()
        display_category = matched.get("display_category") or "other"
        badge_color = matched.get("badge_color") or "#2563eb"
        profile_id = matched.get("profile_id")

        # 1. 患者ID不正時は隔離キューへ安全保護
        if norm_pid is None:
            return _quarantine_file(conn, cfg, filepath, meta, matched, pid_err, remove_source=remove_source)

        # 2. 重複送信チェック (SOPInstanceUID または SHA-256)
        with conn.cursor() as cur:
            if meta["sop_instance_uid"]:
                cur.execute(
                    """
                    SELECT sf.file_id, sf.study_id, s.patient_id
                    FROM study_files sf
                    JOIN studies s ON s.study_id = sf.study_id
                    WHERE sf.sop_instance_uid = %s
                    LIMIT 1
                    """,
                    (meta["sop_instance_uid"],),
                )
                dup = cur.fetchone()
                if dup:
                    if remove_source and os.path.exists(filepath):
                        os.remove(filepath)
                    log_ingest_event(
                        conn,
                        "INFO",
                        source_protocol,
                        "DUPLICATE_SKIPPED",
                        f"重複SOPInstanceUIDのためスキップ ({meta['orig_filename']})",
                        patient_id=norm_pid,
                        study_id=dup["study_id"],
                    )
                    conn.commit()
                    return {"status": "duplicate", "study_id": dup["study_id"], "patient_id": norm_pid}

            cur.execute(
                """
                SELECT sf.file_id, sf.study_id
                FROM study_files sf
                JOIN studies s ON s.study_id = sf.study_id
                WHERE sf.sha256_hash = %s AND s.patient_id = %s AND NOT s.is_deleted
                LIMIT 1
                """,
                (file_hash, norm_pid),
            )
            dup_hash = cur.fetchone()
            if dup_hash:
                if remove_source and os.path.exists(filepath):
                    os.remove(filepath)
                conn.commit()
                return {"status": "duplicate", "study_id": dup_hash["study_id"], "patient_id": norm_pid}

        # 3. 患者マスタの存在保証（MALL3 CSVより先に緊急検査が届いた場合も自動で枠を用意）
        with conn.cursor() as cur:
            cur.execute("SELECT patient_id, kanja_kanji FROM patients WHERE patient_id = %s", (norm_pid,))
            pat_row = cur.fetchone()
            if not pat_row:
                raw_name = meta["raw_patient_name"] or f"未登録患者({norm_pid})"
                b_date = parse_dicom_date(meta["raw_birth_date"])
                cur.execute(
                    """
                    INSERT INTO patients (patient_id, kanja_kanji, kanja_yomi, kanja_yomi_norm, birth_date, sex, source_channel)
                    VALUES (%s, %s, '', '', %s, %s, 'DICOM_AUTO')
                    ON CONFLICT (patient_id) DO NOTHING
                    """,
                    (norm_pid, raw_name, b_date, meta["raw_sex"] or ""),
                )

        # 3.5 事前撮影依頼 (modality_orders) との自動突合・属性引き継ぎ
        matched_order = None
        with conn.cursor() as cur:
            if meta.get("accession_number"):
                cur.execute(
                    "SELECT * FROM modality_orders WHERE accession_number = %s AND status <> 'CANCELLED' ORDER BY order_id DESC LIMIT 1",
                    (meta["accession_number"],),
                )
                matched_order = cur.fetchone()
            if not matched_order and meta.get("study_instance_uid"):
                cur.execute(
                    "SELECT * FROM modality_orders WHERE study_instance_uid = %s AND status <> 'CANCELLED' ORDER BY order_id DESC LIMIT 1",
                    (meta["study_instance_uid"],),
                )
                matched_order = cur.fetchone()
            if not matched_order:
                mod_candidates = ["DX", "CR"] if modality_code in ("DX", "CR") else [modality_code]
                cur.execute(
                    """
                    SELECT * FROM modality_orders
                    WHERE patient_id = %s AND scheduled_date = %s AND modality = ANY(%s)
                      AND status IN ('SCHEDULED', 'IN_PROGRESS')
                    ORDER BY order_id ASC LIMIT 1
                    """,
                    (norm_pid, meta["study_date"], mod_candidates),
                )
                matched_order = cur.fetchone()

        if matched_order:
            has_device_part = bool(meta.get("body_part") or meta.get("protocol_name"))
            if not override_kensamei and matched_order.get("kensamei") and (not has_device_part or meta.get("accession_number")):
                kensamei = matched_order["kensamei"]
            if not meta["doctor"] and matched_order.get("requesting_doctor"):
                meta["doctor"] = matched_order["requesting_doctor"]
            if not meta["department"] and matched_order.get("requesting_dept"):
                meta["department"] = matched_order["requesting_dept"]
            if not meta["comment"] and matched_order.get("order_comment"):
                meta["comment"] = matched_order["order_comment"]

        # 4. 既存Studyへの集約判定
        # - CT / MR / PT / NM (他院紹介CD等の3Dマルチシリーズ検査) は 1 StudyInstanceUID = 1検査として集約
        # - X線(DX/CR)・透視(RF)・内視鏡(ES)・エコー(US)等は、同一受付(同一StudyInstanceUID)内で
        #   「胸部＋腰部」「胸部＋腹部」など複数部位を連続撮影した場合に撮影部位(modality + kensamei)ごとに別検査として登録
        vol_id = target_volume_id or cfg["storage"]["active_volume_id"]
        vol_cfg = cfg["storage"]["volumes"][vol_id]
        s_date: date = meta["study_date"]
        s_time: dt_time = meta["study_time"]

        existing_study = None
        with conn.cursor() as cur:
            if force_study_id:
                cur.execute("SELECT * FROM studies WHERE study_id = %s AND NOT is_deleted FOR UPDATE", (force_study_id,))
                existing_study = cur.fetchone()
            elif meta["study_instance_uid"]:
                if modality_code in ("CT", "MR", "PT", "NM"):
                    cur.execute(
                        """
                        SELECT * FROM studies
                        WHERE patient_id = %s AND study_instance_uid = %s AND NOT is_deleted
                        ORDER BY study_id DESC LIMIT 1
                        FOR UPDATE
                        """,
                        (norm_pid, meta["study_instance_uid"]),
                    )
                else:
                    cur.execute(
                        """
                        SELECT * FROM studies
                        WHERE patient_id = %s
                          AND study_instance_uid = %s
                          AND modality = %s
                          AND kensamei = %s
                          AND NOT is_deleted
                        ORDER BY study_id DESC LIMIT 1
                        FOR UPDATE
                        """,
                        (norm_pid, meta["study_instance_uid"], modality_code, kensamei),
                    )
                existing_study = cur.fetchone()
            elif meta["file_kind"] != "ECG":
                # UIDを持たないFTP内視鏡や連続JPEG投入: 直近30分以内の同一患者・同一日・同一検査名へ集約
                # (※心電図 .ECG は再検や負荷前後など1ファイル＝1独立検査として扱う)
                cutoff_ts = datetime.now().astimezone() - timedelta(minutes=30)
                cur.execute(
                    """
                    SELECT * FROM studies
                    WHERE patient_id = %s AND study_date = %s AND modality = %s AND kensamei = %s
                      AND primary_file_type = %s AND created_at >= %s AND NOT is_deleted
                    ORDER BY study_id DESC LIMIT 1
                    FOR UPDATE
                    """,
                    (norm_pid, s_date, modality_code, kensamei, meta["file_kind"], cutoff_ts),
                )
                existing_study = cur.fetchone()

            if existing_study:
                study_id = existing_study["study_id"]
                vol_id = existing_study["volume_id"]
                vol_cfg = cfg["storage"]["volumes"][vol_id]
                rel_path = existing_study["rel_path"]
                study_abs_dir = os.path.join(vol_cfg["path"], rel_path.replace("/", os.sep))
                os.makedirs(study_abs_dir, exist_ok=True)

                # Upgrade generic Scout kensamei (e.g. '他院紹介CT') if a subsequent slice has specific protocol (e.g. '胸〜骨盤CT(単純)')
                old_k = existing_study.get("kensamei") or ""
                if kensamei and old_k in ("他院紹介CT", "他院紹介MRI", "外部取込画像", "CT", "MR") and kensamei not in ("他院紹介CT", "他院紹介MRI", "外部取込画像"):
                    cur.execute("UPDATE studies SET kensamei = %s WHERE study_id = %s", (kensamei, study_id))
                if meta.get("doctor") and not existing_study.get("doctor"):
                    cur.execute("UPDATE studies SET doctor = %s WHERE study_id = %s", (meta["doctor"], study_id))

                cur.execute("SELECT COALESCE(MAX(seq_no), 0) AS max_seq FROM study_files WHERE study_id = %s", (study_id,))
                seq_no = int(cur.fetchone()["max_seq"]) + 1
            else:
                date_sub = f"studies/{s_date.year:04d}/{s_date.month:02d}/{s_date.day:02d}"
                hhmmss = s_time.strftime("%H%M%S") if s_time else "000000"
                pid_padded = norm_pid.zfill(8) if norm_pid.isdigit() else re.sub(r"[^A-Za-z0-9]", "", norm_pid)
                seq_folder = 1
                while True:
                    cand_rel = f"{date_sub}/P{pid_padded}_{modality_code}_{hhmmss}_{seq_folder:02d}"
                    cand_abs = os.path.join(vol_cfg["path"], cand_rel.replace("/", os.sep))
                    cur.execute("SELECT 1 FROM studies WHERE rel_path = %s", (cand_rel,))
                    if not cur.fetchone() and not os.path.exists(cand_abs):
                        rel_path = cand_rel
                        study_abs_dir = cand_abs
                        break
                    seq_folder += 1

                os.makedirs(study_abs_dir, exist_ok=True)
                cur.execute(
                    """
                    INSERT INTO studies
                    (patient_id, profile_id, study_date, study_time, daytime_raw, modality, kensamei,
                     display_category, badge_color, station_name, manufacturer, model_name,
                     doctor, department, findings, comment, volume_id, rel_path,
                     file_count, total_bytes, primary_file_type, study_instance_uid)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 0, 0, %s, %s)
                    RETURNING study_id
                    """,
                    (
                        norm_pid,
                        profile_id,
                        s_date,
                        s_time,
                        f"{s_date.isoformat()} {s_time.strftime('%H:%M:%S') if s_time else ''}".strip(),
                        modality_code,
                        kensamei,
                        display_category,
                        badge_color,
                        meta["station_name"],
                        meta["manufacturer"],
                        meta["model_name"],
                        meta["doctor"],
                        meta["department"],
                        meta["findings"],
                        meta["comment"],
                        vol_id,
                        rel_path,
                        meta["file_kind"],
                        meta["study_instance_uid"] or None,
                    ),
                )
                study_id = cur.fetchone()["study_id"]
                seq_no = 1

            # 5. 正規化ファイル名の決定と実ファイル格納
            if meta["file_kind"] == "DICOM":
                dst_ext = ".dcm"
            elif meta["file_kind"] == "ECG":
                dst_ext = ".MWF" if meta["file_ext"] == "mwf" else (".NKC" if meta["file_ext"] == "nkc" else ".ECG")
            elif meta["file_kind"] == "PDF":
                dst_ext = ".pdf"
            elif meta["file_kind"] == "VIDEO":
                dst_ext = "." + (meta["file_ext"] if meta["file_ext"] in VIDEO_EXTS else "mp4")
            else:
                dst_ext = "." + (meta["file_ext"] if meta["file_ext"] in IMAGE_EXTS else "jpg")

            dst_filename = f"{seq_no:04d}{dst_ext}"
            dst_abs_path = os.path.join(study_abs_dir, dst_filename)
            if remove_source:
                shutil.move(filepath, dst_abs_path)
            else:
                shutil.copy2(filepath, dst_abs_path)

            cur.execute(
                """
                INSERT INTO study_files
                (study_id, seq_no, filename, orig_filename, file_kind, sop_instance_uid,
                 sha256_hash, file_bytes, width, height, transfer_syntax)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                RETURNING file_id
                """,
                (
                    study_id,
                    seq_no,
                    dst_filename,
                    meta["orig_filename"],
                    meta["file_kind"],
                    meta["sop_instance_uid"] or None,
                    file_hash,
                    file_bytes,
                    meta["width"],
                    meta["height"],
                    meta["transfer_syntax"],
                ),
            )
            file_id = cur.fetchone()["file_id"]

            cur.execute(
                """
                UPDATE studies
                SET file_count = (SELECT COUNT(*) FROM study_files WHERE study_id = %s),
                    total_bytes = (SELECT COALESCE(SUM(file_bytes), 0) FROM study_files WHERE study_id = %s),
                    updated_at = NOW()
                WHERE study_id = %s
                """,
                (study_id, study_id, study_id),
            )

            if profile_id and existing_study is None:
                cur.execute(
                    """
                    UPDATE modality_profiles
                    SET last_received_at = NOW(),
                        total_received = total_received + 1
                    WHERE profile_id = %s
                    """,
                    (profile_id,),
                )
            elif profile_id:
                cur.execute("UPDATE modality_profiles SET last_received_at = NOW() WHERE profile_id = %s", (profile_id,))

            if matched_order:
                cur.execute(
                    """
                    UPDATE modality_orders
                    SET status = 'COMPLETED',
                        linked_study_id = %s,
                        completed_at = COALESCE(completed_at, NOW()),
                        updated_at = NOW()
                    WHERE order_id = %s
                    """,
                    (study_id, matched_order["order_id"]),
                )

        # 6. study_meta.json と先行表示キャッシュを生成
        if not skip_sync_meta:
            sync_study_meta_json(conn, cfg, study_id)
            log_ingest_event(
                conn,
                level="INFO",
                channel=source_protocol,
                event_type="FILE_INGESTED",
                summary=f"格納完了: {kensamei} ({dst_filename}) -> 患者ID {norm_pid}",
                patient_id=norm_pid,
                study_id=study_id,
                detail={
                    "file_id": file_id,
                    "orig_filename": meta["orig_filename"],
                    "profile_code": matched.get("profile_code"),
                    "rel_path": rel_path,
                },
            )
        conn.commit()
    except Exception as e:
        conn.rollback()
        raise e
    finally:
        if owns_conn:
            conn.close()

    if defer_cache_after_seq <= 0 or seq_no <= defer_cache_after_seq:
        try:
            generate_study_caches(
                dst_abs_path,
                meta["file_kind"],
                cfg["storage"]["cache_root"],
                study_id,
                seq_no,
                ecg_parsed=meta.get("ecg_parsed"),
            )
        except Exception:
            pass

    return {
        "status": "success",
        "study_id": study_id,
        "file_id": file_id,
        "seq_no": seq_no,
        "patient_id": norm_pid,
        "kensamei": kensamei,
        "modality": modality_code,
        "rel_path": rel_path,
        "profile_code": matched.get("profile_code"),
    }


def rescue_quarantine_item(
    quarantine_id: int,
    target_patient_id: str,
    override_kensamei: str = None,
    doctor: str = "",
) -> dict:
    """
    隔離キュー (quarantine_queue) 内のファイルを指定した正しい患者IDへ紐付け救済する。
    """
    norm_pid, pid_err = normalize_patient_id(target_patient_id)
    if norm_pid is None:
        return {"status": "error", "message": pid_err}

    cfg = load_config()
    conn = get_db_conn(cfg)
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT * FROM quarantine_queue WHERE quarantine_id = %s", (quarantine_id,))
            q = cur.fetchone()
            if not q:
                return {"status": "error", "message": "指定された隔離データが見つかりません"}
            if q["status"] == "RESOLVED":
                return {"status": "error", "message": "この隔離データは既に救済済みです"}
    finally:
        conn.close()

    q_abs_dir = os.path.join(cfg["storage"]["quarantine_root"], q["quarantine_rel"].replace("/", os.sep))
    if not os.path.isdir(q_abs_dir):
        return {"status": "error", "message": f"隔離フォルダが見つかりません: {q_abs_dir}"}

    files = sorted(
        [os.path.join(q_abs_dir, f) for f in os.listdir(q_abs_dir) if os.path.isfile(os.path.join(q_abs_dir, f))]
    )
    if not files:
        return {"status": "error", "message": "隔離フォルダ内にファイルがありません"}

    resolved_study_id = None
    ingested_count = 0
    for fp in files:
        res = ingest_single_file(
            fp,
            source_protocol="QUARANTINE_RESCUE",
            extra_meta={
                "patient_id": norm_pid,
                "source_ip": q["source_ip"],
                "doctor": doctor,
                "study_date": q["study_date_raw"],
            },
            remove_source=True,
            override_kensamei=override_kensamei or q["kensamei_guess"] or None,
            force_study_id=resolved_study_id,
        )
        if res.get("status") in ("success", "duplicate"):
            resolved_study_id = res.get("study_id") or resolved_study_id
            ingested_count += 1

    shutil.rmtree(q_abs_dir, ignore_errors=True)

    conn2 = get_db_conn(cfg)
    try:
        with conn2.cursor() as cur:
            cur.execute(
                """
                UPDATE quarantine_queue
                SET status = 'RESOLVED',
                    resolved_patient_id = %s,
                    resolved_study_id = %s,
                    resolved_at = NOW()
                WHERE quarantine_id = %s
                """,
                (norm_pid, resolved_study_id, quarantine_id),
            )
        log_ingest_event(
            conn2,
            level="INFO",
            channel="WEB_ADMIN",
            event_type="QUARANTINE_RESCUED",
            summary=f"隔離データ #{quarantine_id} を患者ID {norm_pid} へ救済完了 ({ingested_count}件)",
            patient_id=norm_pid,
            study_id=resolved_study_id,
        )
        conn2.commit()
    finally:
        conn2.close()

    return {
        "status": "success",
        "quarantine_id": quarantine_id,
        "patient_id": norm_pid,
        "study_id": resolved_study_id,
        "ingested_count": ingested_count,
    }


def main():
    """
    PHPフロントエンドから CLI JSON ブリッジとして呼び出せるエントリポイント。
    Usage:
      python -m ingest_service.study_filer simulate <filepath> [--meta-json <json_file>]
      python -m ingest_service.study_filer batch_import <manifest_json_file>
      python -m ingest_service.study_filer rescue <quarantine_id> <patient_id> [kensamei] [doctor]
      python -m ingest_service.study_filer sync_meta <study_id>
    """
    if len(sys.argv) < 3:
        print(json.dumps({"status": "error", "message": "Insufficient arguments"}, ensure_ascii=False))
        sys.exit(1)

    cmd = sys.argv[1]
    try:
        if cmd == "simulate":
            fp = sys.argv[2]
            extra = {}
            if len(sys.argv) >= 5 and sys.argv[3] == "--meta-json" and os.path.isfile(sys.argv[4]):
                with open(sys.argv[4], "r", encoding="utf-8") as mf:
                    extra = json.load(mf)
            out = simulate_file_routing(fp, source_protocol=extra.get("source_protocol", "SIMULATOR"), extra_meta=extra)
            print(json.dumps(out, ensure_ascii=False))
            sys.exit(0)

        if cmd == "batch_import":
            manifest_path = sys.argv[2]
            with open(manifest_path, "r", encoding="utf-8") as f:
                manifest = json.load(f)
            files = manifest.get("files", [])
            patient_id = manifest.get("patient_id", "")
            kensamei = manifest.get("kensamei") or None
            study_date = manifest.get("study_date", "")
            force_study_date = bool(manifest.get("force_study_date", False))
            doctor = manifest.get("doctor", "")
            findings = manifest.get("findings", "")
            modality = manifest.get("modality", "")
            group_single_study = bool(manifest.get("group_single_study", False))
            study_overrides = manifest.get("study_overrides") or {}
            forced_sid = int(manifest["force_non_dicom_study_id"]) if manifest.get("force_non_dicom_study_id") else None

            cfg = load_config()
            conn = get_db_conn(cfg)
            results = []
            touched_study_ids = set()
            try:
                for item in files:
                    fp = item["path"]
                    orig_name = item.get("orig_filename") or os.path.basename(fp)
                    ext_low = os.path.splitext(orig_name)[1].lower().lstrip(".")
                    is_non_dicom_media = ext_low in IMAGE_EXTS or ext_low in VIDEO_EXTS or ext_low in PDF_EXTS

                    r = ingest_single_file(
                        fp,
                        source_protocol=manifest.get("source_protocol", "HTTP_API"),
                        extra_meta={
                            "orig_filename": orig_name,
                            "patient_id": patient_id,
                            "study_date": study_date,
                            "force_study_date": force_study_date,
                            "doctor": doctor,
                            "findings": findings,
                            "modality": modality,
                            "source_ip": manifest.get("source_ip", ""),
                            "study_overrides": study_overrides,
                        },
                        remove_source=bool(manifest.get("remove_source", True)),
                        override_kensamei=kensamei,
                        force_study_id=(forced_sid if (group_single_study and is_non_dicom_media) else None),
                        shared_conn=conn,
                        shared_cfg=cfg,
                        skip_sync_meta=True,
                        defer_cache_after_seq=4,
                    )
                    sid = r.get("study_id")
                    if sid:
                        touched_study_ids.add(sid)
                        if group_single_study and is_non_dicom_media and forced_sid is None:
                            forced_sid = sid
                    results.append(r)

                for sid in touched_study_ids:
                    sync_study_meta_json(conn, cfg, sid)
                    log_ingest_event(
                        conn,
                        level="INFO",
                        channel=manifest.get("source_protocol", "HTTP_API"),
                        event_type="BATCH_IMPORTED",
                        summary=f"外部取込バッチ反映完了 (Study #{sid}, 今回チャンク {len(files)} 件)",
                        patient_id=str(patient_id),
                        study_id=sid,
                    )
                conn.commit()
            finally:
                conn.close()

            print(json.dumps({
                "status": "success",
                "results": results,
                "non_dicom_study_id": forced_sid,
                "study_ids": sorted(list(touched_study_ids)),
            }, ensure_ascii=False))
            sys.exit(0)

        if cmd == "rescue":
            qid = int(sys.argv[2])
            pid = sys.argv[3]
            kensa = sys.argv[4] if len(sys.argv) >= 5 and sys.argv[4] != "" else None
            doc = sys.argv[5] if len(sys.argv) >= 6 else ""
            out = rescue_quarantine_item(qid, pid, override_kensamei=kensa, doctor=doc)
            print(json.dumps(out, ensure_ascii=False))
            sys.exit(0)

        if cmd == "sync_meta":
            sid = int(sys.argv[2])
            cfg = load_config()
            conn = get_db_conn(cfg)
            try:
                sync_study_meta_json(conn, cfg, sid)
            finally:
                conn.close()
            print(json.dumps({"status": "success", "study_id": sid}, ensure_ascii=False))
            sys.exit(0)

        print(json.dumps({"status": "error", "message": f"Unknown command: {cmd}"}, ensure_ascii=False))
        sys.exit(2)
    except Exception as e:
        print(
            json.dumps(
                {"status": "error", "message": str(e), "trace": traceback.format_exc()},
                ensure_ascii=False,
            )
        )
        sys.exit(3)


if __name__ == "__main__":
    main()

