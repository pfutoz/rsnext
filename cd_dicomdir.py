# -*- coding: utf-8 -*-
"""
RS-Base CD作成用 DICOM / DICOMDIR パッケージビルダー (cd_dicomdir.py)
- 既存のDICOMファイル（ヘッダ欠損・空タグを含むRS-Base各種モダリティ対応）を
  IHE PDI / DICOM Part 10 規格準拠の DICOMDIR + PT/ST/SE/IM 階層に整えて出力します。
- 心電図等の原本バイナリファイル (.ECG 等) も RAW_ECG/ へ安全に控えコピーします。
"""

import sys
import os
import json
import shutil
import traceback
import pydicom
from pydicom.dataset import Dataset, FileMetaDataset
from pydicom.uid import (
    ExplicitVRLittleEndian,
    ImplicitVRLittleEndian,
    UID,
    generate_uid,
)

try:
    from pydicom.fileset import FileSet
except ImportError:
    FileSet = None

JPEGBaseline8Bit = getattr(pydicom.uid, "JPEGBaseline8Bit", UID("1.2.840.10008.1.2.4.50"))
SecondaryCaptureImageStorage = getattr(
    pydicom.uid, "SecondaryCaptureImageStorage", UID("1.2.840.10008.5.1.4.1.1.7")
)


def has_non_empty(ds, keyword):
    if keyword not in ds:
        return False
    elem = ds[keyword]
    if elem.VM == 0 or elem.value is None or str(elem.value).strip() == "":
        return False
    return True


def detect_encapsulated_jpeg(raw_bytes):
    """DICOMファイル内にカプセル化されたJPEGストリームがあるか簡易判定"""
    soi = raw_bytes.find(b"\xff\xd8\xff")
    eoi = raw_bytes.rfind(b"\xff\xd9")
    return soi != -1 and eoi != -1 and eoi > soi


def prepare_part10_dicom(src_path, temp_dir, patient_info, study_info, study_uid, series_uid, inst_num):
    """
    RS-Base内の多様なDICOMファイル (Part10ヘッダ無し、StudyTime/StudyID空など) を
    pydicom FileSet (DICOM PS3.3 F.5) が要求する必須属性を満たすPart10ファイルへ正規化する。
    元の画素データ・既存UID・メタデータは極力そのまま保持する。
    """
    with open(src_path, "rb") as f:
        raw_bytes = f.read()

    ds = pydicom.dcmread(src_path, force=True)

    # SOPClassUID / SOPInstanceUID の補完
    sop_class = str(getattr(ds, "SOPClassUID", "") or "").strip()
    if not sop_class:
        sop_class = str(SecondaryCaptureImageStorage)
        ds.SOPClassUID = sop_class

    sop_inst = str(getattr(ds, "SOPInstanceUID", "") or "").strip()
    if not sop_inst:
        sop_inst = str(generate_uid())
        ds.SOPInstanceUID = sop_inst

    # File Meta Information (0002,xxxx) の整備
    if not hasattr(ds, "file_meta") or ds.file_meta is None:
        ds.file_meta = FileMetaDataset()

    ts_uid = str(getattr(ds.file_meta, "TransferSyntaxUID", "") or "").strip()
    if not ts_uid:
        # ピクセルデータがカプセル化(未定義長)かJPEGを含む場合などを考慮
        is_encap = False
        if "PixelData" in ds and getattr(ds["PixelData"], "is_undefined_length", False):
            is_encap = True
        elif detect_encapsulated_jpeg(raw_bytes) and not hasattr(ds, "Rows"):
            is_encap = True

        if is_encap:
            ts_uid = str(JPEGBaseline8Bit)
        else:
            # 既存エンディアン・VR設定を尊重しつつ標準Explicit VR Little Endianへフォールバック
            if getattr(ds, "is_implicit_VR", False):
                ts_uid = str(ImplicitVRLittleEndian)
            else:
                ts_uid = str(ExplicitVRLittleEndian)
        ds.file_meta.TransferSyntaxUID = ts_uid

    ds.file_meta.MediaStorageSOPClassUID = sop_class
    ds.file_meta.MediaStorageSOPInstanceUID = sop_inst

    # 文字コード指定がない日本語タグの文字化け防止（既存SpecificCharacterSetがあれば保持）
    if not has_non_empty(ds, "SpecificCharacterSet"):
        ds.SpecificCharacterSet = "ISO_IR 192"  # UTF-8

    # PATIENT レベル必須属性 (PS3.3 F.5)
    pat_id = str(patient_info.get("id") or "00000").strip() or "00000"
    if not has_non_empty(ds, "PatientID"):
        ds.PatientID = pat_id

    if not has_non_empty(ds, "PatientName"):
        pat_name = str(patient_info.get("name") or pat_id).strip()
        ds.PatientName = pat_name

    if not has_non_empty(ds, "PatientSex"):
        sex_raw = str(patient_info.get("sex") or "").strip()
        if sex_raw in ("男", "M", "Male"):
            ds.PatientSex = "M"
        elif sex_raw in ("女", "F", "Female"):
            ds.PatientSex = "F"
        else:
            ds.PatientSex = "O"

    # STUDY レベル必須属性: StudyDate, StudyTime, StudyID, StudyInstanceUID
    clean_date = "".join(ch for ch in str(study_info.get("date") or "") if ch.isdigit())
    if len(clean_date) < 8:
        clean_date = "20260101"
    else:
        clean_date = clean_date[:8]

    if not has_non_empty(ds, "StudyDate"):
        ds.StudyDate = clean_date

    clean_time = "".join(ch for ch in str(study_info.get("time") or "") if ch.isdigit())
    if len(clean_time) == 4:
        clean_time += "00"
    elif len(clean_time) < 6:
        clean_time = "090000"
    else:
        clean_time = clean_time[:6]

    if not has_non_empty(ds, "StudyTime"):
        ds.StudyTime = clean_time

    if not has_non_empty(ds, "StudyID"):
        renban_str = str(study_info.get("renban") or study_info.get("exam_index") or "1").strip()
        ds.StudyID = renban_str[:16] if renban_str else "1"

    if not has_non_empty(ds, "StudyInstanceUID"):
        ds.StudyInstanceUID = study_uid

    if not has_non_empty(ds, "StudyDescription") and study_info.get("description"):
        ds.StudyDescription = str(study_info.get("description"))[:64]

    if "AccessionNumber" not in ds:
        ds.AccessionNumber = str(study_info.get("renban") or "")[:16]

    # SERIES レベル必須属性: Modality, SeriesInstanceUID, SeriesNumber
    if not has_non_empty(ds, "Modality"):
        ds.Modality = str(study_info.get("modality_code") or "OT").strip() or "OT"

    if not has_non_empty(ds, "SeriesInstanceUID"):
        ds.SeriesInstanceUID = series_uid

    if not has_non_empty(ds, "SeriesNumber"):
        ds.SeriesNumber = int(study_info.get("exam_index") or 1)

    # IMAGE レベル必須属性: InstanceNumber
    if not has_non_empty(ds, "InstanceNumber"):
        ds.InstanceNumber = int(inst_num)

    # 一時Part10ファイルとして保存
    norm_path = os.path.join(temp_dir, f"norm_{study_info.get('exam_index', 1)}_{inst_num:04d}.dcm")
    ds.preamble = b"\x00" * 128
    try:
        ds.save_as(norm_path, enforce_file_format=True)
    except TypeError:
        # pydicomバージョン差異のフォールバック
        ds.save_as(norm_path, write_like_original=False)
    return norm_path


def build_dicom_package(manifest_path):
    with open(manifest_path, "r", encoding="utf-8") as f:
        manifest = json.load(f)

    out_root = manifest["out_root"]
    patient_info = manifest.get("patient", {})
    studies = manifest.get("studies", [])
    temp_norm_dir = os.path.join(out_root, "_tmp_dcm_norm")
    os.makedirs(temp_norm_dir, exist_ok=True)

    result = {
        "status": "success",
        "dicom_added": 0,
        "raw_copied": 0,
        "ecg_copied": 0,
        "dicomdir_created": False,
        "warnings": [],
    }

    fs = FileSet() if FileSet is not None else None
    has_fileset_items = False

    try:
        for s_idx, study in enumerate(studies, start=1):
            study["exam_index"] = s_idx
            study_uid = str(generate_uid())
            series_uid = str(generate_uid())

            # 1. DICOMファイル群の登録
            dcm_files = study.get("dcm_files", [])
            for i_idx, src_dcm in enumerate(dcm_files, start=1):
                if not src_dcm or not os.path.isfile(src_dcm):
                    continue
                try:
                    if fs is None:
                        raise RuntimeError("pydicom.fileset unavailable; falling back to DICOM_RAW")
                    norm_dcm = prepare_part10_dicom(
                        src_dcm, temp_norm_dir, patient_info, study, study_uid, series_uid, i_idx
                    )
                    fs.add(norm_dcm)
                    has_fileset_items = True
                    result["dicom_added"] += 1
                except Exception as e:
                    # 万一DICOMDIR登録できない特殊ファイルでも原本はRAW_DICOMに保全
                    fallback_dir = os.path.join(out_root, "DICOM_RAW", f"EXAM_{s_idx:02d}")
                    os.makedirs(fallback_dir, exist_ok=True)
                    dst_raw = os.path.join(fallback_dir, os.path.basename(src_dcm))
                    try:
                        shutil.copy2(src_dcm, dst_raw)
                        result["raw_copied"] += 1
                    except Exception:
                        pass
                    result["warnings"].append(f"{os.path.basename(src_dcm)}: {str(e)}")

            # 2. 心電図等の原本バイナリ控えコピー
            ecg_files = study.get("ecg_files", [])
            for src_ecg in ecg_files:
                if not src_ecg or not os.path.isfile(src_ecg):
                    continue
                ecg_dir = os.path.join(out_root, "RAW_ECG", f"EXAM_{s_idx:02d}")
                os.makedirs(ecg_dir, exist_ok=True)
                dst_ecg = os.path.join(ecg_dir, os.path.basename(src_ecg))
                try:
                    shutil.copy2(src_ecg, dst_ecg)
                    result["ecg_copied"] += 1
                except Exception as e:
                    result["warnings"].append(f"ECG copy {os.path.basename(src_ecg)}: {str(e)}")

        # DICOMDIR + DICOM階層の書き出し
        if has_fileset_items:
            fs.write(out_root)
            dicomdir_path = os.path.join(out_root, "DICOMDIR")
            result["dicomdir_created"] = os.path.isfile(dicomdir_path)

    finally:
        if os.path.exists(temp_norm_dir):
            shutil.rmtree(temp_norm_dir, ignore_errors=True)

    return result


def main():
    if len(sys.argv) < 2:
        sys.stderr.write("Usage: python cd_dicomdir.py <manifest.json>\n")
        sys.exit(1)

    manifest_path = sys.argv[1]
    if not os.path.isfile(manifest_path):
        print(json.dumps({"status": "error", "message": f"Manifest not found: {manifest_path}"}))
        sys.exit(2)

    try:
        res = build_dicom_package(manifest_path)
        print(json.dumps(res, ensure_ascii=False))
        sys.exit(0)
    except Exception as e:
        print(
            json.dumps(
                {
                    "status": "error",
                    "message": str(e),
                    "trace": traceback.format_exc(),
                },
                ensure_ascii=False,
            )
        )
        sys.exit(3)


if __name__ == "__main__":
    main()
