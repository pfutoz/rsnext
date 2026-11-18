import os
import sys
import json
import math
import struct
import hashlib
import subprocess
import numpy as np
import pydicom
from pydicom.pixel_data_handlers.util import apply_voi_lut
from PIL import Image

VIDEO_EXTS = {"mp4", "webm", "mov", "m4v", "avi", "mpg", "mpeg", "wmv", "mkv", "mts", "ogv"}
BROWSER_NATIVE_VIDEO_EXTS = {"mp4", "webm", "m4v", "ogv"}
IMAGE_EXTS = {"jpg", "jpeg", "png", "webp", "bmp"}
PDF_EXTS = {"pdf"}

INSTITUTION_JP_MAP = [
    ("KOCHI HEALTH SCIENCES CENTER", "高知医療センター"),
    ("KOCHI RED CROSS", "高知赤十字病院"),
    ("CHIKAMORI", "近森病院"),
    ("KOCHI MEDICAL SCHOOL", "高知大学医学部附属病院"),
    ("KOCHI UNIVERSITY", "高知大学医学部附属病院"),
    ("NATIONAL HOSPITAL ORGANIZATION KOCHI", "国立病院機構高知病院"),
    ("NHO KOCHI", "国立病院機構高知病院"),
    ("HATA KENMIN", "幡多けんみん病院"),
    ("AKI GENERAL", "安芸総合病院"),
    ("TOSA SHIMIN", "土佐市民病院"),
    ("SUSAKI KUROSHIO", "須崎くろしお病院"),
    ("JA KOCHI", "JA高知病院"),
    ("HOSOGI", "細木病院"),
    ("ATAGO", "愛宕病院"),
    ("FUJIMURA", "藤村病院"),
    ("ISEI", "いせい病院"),
    ("Kochi", "高知"),
]


def friendly_institution_name(raw_inst: str) -> str:
    s = (raw_inst or "").strip()
    if not s:
        return ""
    up = s.upper()
    for key, jp in INSTITUTION_JP_MAP:
        if key.upper() in up:
            if key == "Kochi":
                continue
            return jp
    return s


def clean_series_desc(raw_desc: str, plane: str = "", slice_count: int = 0, modality: str = "", thickness=None) -> str:
    s = (raw_desc or "").strip()
    # Clean Toshiba/Canon comma-separated format e.g. "ChestAbd 336,,5.0,ChestAbd 336," or ",,2.0,,"
    if "," in s:
        parts = [p.strip() for p in s.split(",") if p.strip()]
        dedup = []
        for p in parts:
            if p not in dedup:
                dedup.append(p)
        non_num = [p for p in dedup if not p.replace(".", "", 1).isdigit()]
        nums = [p for p in dedup if p.replace(".", "", 1).isdigit()]
        if not non_num:
            if modality == "CT" and slice_count <= 2:
                return "位置決め (Scout)"
            return f"{modality} ({nums[0]}mm)" if nums else (modality or "Series")
        base = " / ".join(non_num)
        if nums:
            return f"{base} ({nums[0]}mm)"
        return base
    if not s:
        if modality == "CT" and slice_count <= 2:
            return "位置決め (Scout)"
        return f"{modality} ({thickness}mm)" if thickness else (modality or "Series")
    return s


def parse_japanese_person_name(raw_pn: str) -> tuple[str, str]:
    """
    DICOM PatientName ('ｼﾐｽﾞ^ﾔｽﾋﾛ=清水^康宏' 等) から (漢字氏名, カナ氏名) を抽出する。
    """
    import unicodedata
    import re
    if not raw_pn:
        return "", ""
    groups = [g.strip() for g in str(raw_pn).split("=") if g.strip()]
    kanji_cand = ""
    kana_cand = ""
    alpha_cand = ""
    for g in groups:
        norm_g = unicodedata.normalize("NFKC", g).replace("^", " ").strip()
        norm_g = re.sub(r"\s+", " ", norm_g)
        if re.search(r"[\u4e00-\u9fff]", norm_g):
            kanji_cand = norm_g
        elif re.search(r"[\u30a0-\u30ff\u3040-\u309f]", norm_g):
            kana_cand = norm_g
        elif norm_g and not alpha_cand:
            alpha_cand = norm_g
    return (kanji_cand or kana_cand or alpha_cand), kana_cand


def _first_float(val, default=None):
    if val is None:
        return default
    try:
        if hasattr(val, "__iter__") and not isinstance(val, (str, bytes)):
            lst = list(val)
            return float(lst[0]) if lst else default
        return float(val)
    except Exception:
        return default


def _decode_text(val) -> str:
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


def _compute_plane_and_normal(iop):
    if not iop or len(iop) < 6:
        return "2D", None
    try:
        r = np.array([float(iop[0]), float(iop[1]), float(iop[2])], dtype=np.float64)
        c = np.array([float(iop[3]), float(iop[4]), float(iop[5])], dtype=np.float64)
        n = np.cross(r, c)
        norm_len = np.linalg.norm(n)
        if norm_len < 1e-6:
            return "2D", None
        n = n / norm_len
        ax = np.abs(n)
        if ax[2] >= ax[0] and ax[2] >= ax[1]:
            return "AXIAL", n
        elif ax[1] >= ax[0]:
            return "CORONAL", n
        else:
            return "SAGITTAL", n
    except Exception:
        return "2D", None


def inspect_folder(folder_path: str, out_json_path: str):
    if not os.path.isdir(folder_path):
        raise FileNotFoundError(f"Folder not found: {folder_path}")

    entries = []
    for f in os.listdir(folder_path):
        low = f.lower()
        if f in (".", "..") or low in ("study_meta.json", "thumbs.db", "desktop.ini", "dicomdir"):
            continue
        fp = os.path.join(folder_path, f)
        if not os.path.isfile(fp):
            continue
        ext = os.path.splitext(f)[1].lower().lstrip(".")
        if ext in ("json", "txt", "xml", "html", "htm", "js", "css", "exe", "dll", "ini", "inf"):
            continue
        entries.append((f, fp, ext))

    entries.sort(key=lambda x: x[0].lower())

    flat_files = []
    series_map = {}

    for fname, fp, ext in entries:
        fsize = os.path.getsize(fp)
        fmtime = int(os.path.getmtime(fp))

        if ext in VIDEO_EXTS:
            width, height, fps, duration, num_frames = None, None, 30.0, 0.0, 0
            try:
                import cv2
                cap = cv2.VideoCapture(fp)
                if cap.isOpened():
                    w = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH) or 0)
                    h = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT) or 0)
                    vfps = float(cap.get(cv2.CAP_PROP_FPS) or 0.0)
                    fcnt = int(cap.get(cv2.CAP_PROP_FRAME_COUNT) or 0)
                    if w > 0 and h > 0:
                        width, height = w, h
                    if 1.0 <= vfps <= 240.0:
                        fps = round(vfps, 2)
                    if fcnt > 0:
                        num_frames = fcnt
                        if fps > 0:
                            duration = round(fcnt / fps, 2)
                cap.release()
            except Exception:
                pass

            rec = {
                "filename": fname,
                "ext": ext,
                "size": fsize,
                "mtime": fmtime,
                "media_type": "video",
                "browser_native": ext in BROWSER_NATIVE_VIDEO_EXTS,
                "width": width,
                "height": height,
                "fps": fps,
                "duration": duration,
                "num_frames": num_frames,
            }
            flat_files.append(rec)
            continue

        if ext in IMAGE_EXTS:
            flat_files.append({
                "filename": fname,
                "ext": ext,
                "size": fsize,
                "mtime": fmtime,
                "media_type": "image",
                "num_frames": 1,
            })
            continue

        if ext in PDF_EXTS:
            flat_files.append({
                "filename": fname,
                "ext": ext,
                "size": fsize,
                "mtime": fmtime,
                "media_type": "pdf",
                "num_frames": 1,
            })
            continue

        if ext in ("ecg", "001", "mwf", "nkc"):
            continue

        # Try reading as DICOM header
        try:
            ds = pydicom.dcmread(fp, stop_before_pixels=True, force=True)
            if not any(k in ds for k in ("Modality", "SOPInstanceUID", "StudyInstanceUID", "Rows", "Columns")):
                continue
        except Exception:
            continue

        modality = (_decode_text(getattr(ds, "Modality", "")) or "OT").upper()
        series_uid = _decode_text(getattr(ds, "SeriesInstanceUID", "")) or f"SER_{modality}"
        try:
            series_num = int(getattr(ds, "SeriesNumber", 1) or 1)
        except Exception:
            series_num = 1
        series_desc = (
            _decode_text(getattr(ds, "SeriesDescription", ""))
            or _decode_text(getattr(ds, "ProtocolName", ""))
            or _decode_text(getattr(ds, "BodyPartExamined", ""))
            or _decode_text(getattr(ds, "StudyDescription", ""))
            or modality
        )
        try:
            inst_num = int(getattr(ds, "InstanceNumber", 0) or 0)
        except Exception:
            inst_num = 0

        rows = int(getattr(ds, "Rows", 0) or 0)
        cols = int(getattr(ds, "Columns", 0) or 0)
        samples = int(getattr(ds, "SamplesPerPixel", 1) or 1)
        bits_stored = int(getattr(ds, "BitsStored", 8) or 8)
        num_frames = int(getattr(ds, "NumberOfFrames", 1) or 1)

        ts_uid = ""
        if hasattr(ds, "file_meta") and ds.file_meta is not None:
            ts_uid = _decode_text(getattr(ds.file_meta, "TransferSyntaxUID", ""))
        is_encap_video = ts_uid.startswith("1.2.840.10008.1.2.4.10")

        frame_time_ms = _first_float(getattr(ds, "FrameTime", None), None)
        if not frame_time_ms:
            cine_rate = _first_float(
                getattr(ds, "RecommendedDisplayFrameRate", None) or getattr(ds, "CineRate", None),
                None,
            )
            if cine_rate and cine_rate > 0:
                frame_time_ms = round(1000.0 / cine_rate, 2)
            else:
                frame_time_ms = 100.0  # 10 fps default

        ps_raw = getattr(ds, "PixelSpacing", None) or getattr(ds, "ImagerPixelSpacing", None)
        pixel_spacing = None
        if ps_raw is not None and len(ps_raw) >= 2:
            try:
                pixel_spacing = [round(float(ps_raw[0]), 4), round(float(ps_raw[1]), 4)]
            except Exception:
                pixel_spacing = None

        slice_thick = _first_float(getattr(ds, "SliceThickness", None), None)
        slice_loc = _first_float(getattr(ds, "SliceLocation", None), None)

        ipp_raw = getattr(ds, "ImagePositionPatient", None)
        ipp = None
        if ipp_raw is not None and len(ipp_raw) >= 3:
            try:
                ipp = [float(ipp_raw[0]), float(ipp_raw[1]), float(ipp_raw[2])]
            except Exception:
                ipp = None

        iop_raw = getattr(ds, "ImageOrientationPatient", None)
        iop = None
        if iop_raw is not None and len(iop_raw) >= 6:
            try:
                iop = [float(iop_raw[i]) for i in range(6)]
            except Exception:
                iop = None

        plane, normal = _compute_plane_and_normal(iop)
        z_proj = None
        if ipp is not None and normal is not None:
            z_proj = float(np.dot(np.array(ipp, dtype=np.float64), normal))
            if slice_loc is None:
                slice_loc = round(z_proj, 2)

        wl = _first_float(getattr(ds, "WindowCenter", None), None)
        ww = _first_float(getattr(ds, "WindowWidth", None), None)
        slope = _first_float(getattr(ds, "RescaleSlope", None), 1.0)
        intercept = _first_float(getattr(ds, "RescaleIntercept", None), 0.0)
        is_grayscale_16 = (samples == 1 and bits_stored > 8 and not is_encap_video)

        media_type = "video_dicom" if is_encap_video else ("cine_dicom" if num_frames > 1 else "dicom")

        item = {
            "filename": fname,
            "ext": ext,
            "size": fsize,
            "mtime": fmtime,
            "media_type": media_type,
            "modality": modality,
            "series_uid": series_uid,
            "series_number": series_num,
            "series_desc": series_desc,
            "instance_number": inst_num,
            "rows": rows,
            "cols": cols,
            "samples": samples,
            "bits_stored": bits_stored,
            "is_16bit": is_grayscale_16,
            "num_frames": num_frames,
            "frame_time_ms": frame_time_ms,
            "pixel_spacing": pixel_spacing,
            "slice_thickness": round(slice_thick, 3) if slice_thick else None,
            "slice_location": round(slice_loc, 2) if slice_loc is not None else None,
            "z_proj": z_proj,
            "plane": plane,
            "wl": round(wl, 1) if wl is not None else None,
            "ww": round(ww, 1) if ww is not None else None,
            "slope": slope,
            "intercept": intercept,
            "transfer_syntax": ts_uid,
        }
        flat_files.append(item)

        if series_uid not in series_map:
            series_map[series_uid] = {
                "series_uid": series_uid,
                "series_number": series_num,
                "series_desc": series_desc,
                "modality": modality,
                "plane": plane,
                "rows": rows,
                "cols": cols,
                "samples": samples,
                "is_16bit": is_grayscale_16,
                "pixel_spacing": pixel_spacing,
                "slice_thickness": round(slice_thick, 3) if slice_thick else None,
                "default_wl": round(wl, 1) if wl is not None else (40.0 if modality == "CT" else None),
                "default_ww": round(ww, 1) if ww is not None else (350.0 if modality == "CT" else None),
                "items": [],
            }
        series_map[series_uid]["items"].append(item)

    # Sort series and sort slices inside each series
    series_list = list(series_map.values())
    series_list.sort(key=lambda s: (s["series_number"], s["series_uid"]))

    ordered_dicom_files = []
    for s_idx, ser in enumerate(series_list):
        items = ser["items"]
        # Sort by InstanceNumber if distinct, otherwise by z_proj / SliceLocation, then filename
        inst_nums = [it["instance_number"] for it in items if it["instance_number"] > 0]
        if len(set(inst_nums)) == len(items) and len(items) > 1:
            items.sort(key=lambda it: (it["instance_number"], it["filename"]))
        elif sum(1 for it in items if it["z_proj"] is not None) >= len(items) * 0.8 and len(items) > 1:
            # Determine direction: if InstanceNumber increases as z_proj decreases (standard Axial CT head->feet), preserve cranial->caudal
            valid_pairs = [(it["instance_number"], it["z_proj"]) for it in items if it["instance_number"] > 0 and it["z_proj"] is not None]
            reverse_z = True
            if len(valid_pairs) >= 2:
                valid_pairs.sort(key=lambda p: p[0])
                if valid_pairs[-1][1] > valid_pairs[0][1]:
                    reverse_z = False
            items.sort(key=lambda it: (-(it["z_proj"] or 0.0) if reverse_z else (it["z_proj"] or 0.0), it["instance_number"], it["filename"]))
        else:
            items.sort(key=lambda it: (it["instance_number"], it["filename"]))

        # Check 3D MPR capability
        z_vals = [it["z_proj"] for it in items if it["z_proj"] is not None]
        unique_z = sorted(set(round(z, 3) for z in z_vals))
        same_dims = len(set((it["rows"], it["cols"]) for it in items)) == 1
        single_frame_all = all(it["num_frames"] == 1 and it["samples"] == 1 for it in items)
        can_mpr = bool(len(items) >= 8 and len(unique_z) >= 8 and same_dims and single_frame_all and ser["rows"] > 0 and ser["cols"] > 0)

        slice_spacing_mm = ser["slice_thickness"] or 1.0
        if len(z_vals) >= 2:
            diffs = [abs(z_vals[i + 1] - z_vals[i]) for i in range(len(z_vals) - 1) if abs(z_vals[i + 1] - z_vals[i]) > 0.05]
            if diffs:
                slice_spacing_mm = round(float(np.median(diffs)), 4)

        ser["series_index"] = s_idx
        ser["slice_count"] = len(items)
        ser["series_desc"] = clean_series_desc(
            ser["series_desc"],
            plane=ser["plane"],
            slice_count=len(items),
            modality=ser["modality"],
            thickness=ser["slice_thickness"],
        )
        ser["can_mpr"] = can_mpr
        ser["slice_spacing_mm"] = slice_spacing_mm
        ser["filenames"] = [it["filename"] for it in items]
        for it in items:
            del it["z_proj"]
        ordered_dicom_files.extend(items)
        del ser["items"]

    non_dicom_files = [f for f in flat_files if f["media_type"] in ("video", "image", "pdf")]
    final_files = ordered_dicom_files + non_dicom_files if ordered_dicom_files else non_dicom_files

    is_cross_sectional = any(
        s["modality"] in ("CT", "MR", "PT", "NM") or s["can_mpr"] or len(series_list) > 1
        for s in series_list
    )
    has_video = any(f["media_type"] in ("video", "video_dicom", "cine_dicom") for f in final_files)

    result = {
        "status": "success",
        "total": len(final_files),
        "is_cross_sectional": is_cross_sectional,
        "has_video": has_video,
        "series_count": len(series_list),
        "series": series_list,
        "files": final_files,
    }

    os.makedirs(os.path.dirname(out_json_path), exist_ok=True)
    tmp_path = out_json_path + f".tmp_{os.getpid()}"
    with open(tmp_path, "w", encoding="utf-8") as wf:
        json.dump(result, wf, ensure_ascii=False)
    os.replace(tmp_path, out_json_path)


def _decode_slice_hu16(dcm_path: str, frame_idx: int = 0):
    ds = pydicom.dcmread(dcm_path, force=True)
    arr = ds.pixel_array
    if arr.ndim == 3 and arr.shape[-1] not in (3, 4):
        idx = max(0, min(arr.shape[0] - 1, frame_idx))
        arr = arr[idx]
    elif arr.ndim == 4:
        idx = max(0, min(arr.shape[0] - 1, frame_idx))
        arr = arr[idx]
    if arr.ndim == 3 and arr.shape[-1] in (3, 4):
        # Convert RGB to luminance if requested as 16bit
        arr = (0.299 * arr[:, :, 0] + 0.587 * arr[:, :, 1] + 0.114 * arr[:, :, 2]).astype(np.float32)
    else:
        arr = arr.astype(np.float32)

    slope = _first_float(getattr(ds, "RescaleSlope", None), 1.0)
    intercept = _first_float(getattr(ds, "RescaleIntercept", None), 0.0)
    hu = arr * slope + intercept

    photo = _decode_text(getattr(ds, "PhotometricInterpretation", "")).upper()
    if photo == "MONOCHROME1":
        hu = float(np.max(hu)) + float(np.min(hu)) - hu

    hu_i16 = np.clip(np.rint(hu), -32768, 32767).astype("<i2")
    h, w = hu_i16.shape

    wl = _first_float(getattr(ds, "WindowCenter", None), None)
    ww = _first_float(getattr(ds, "WindowWidth", None), None)
    min_hu = int(np.min(hu_i16)) if hu_i16.size else 0
    max_hu = int(np.max(hu_i16)) if hu_i16.size else 1
    if wl is None or ww is None or ww <= 0:
        mod = _decode_text(getattr(ds, "Modality", "")).upper()
        if mod == "CT":
            wl, ww = 40.0, 350.0
        else:
            p2, p98 = np.percentile(hu_i16, [2, 98]) if hu_i16.size else (min_hu, max_hu)
            ww = max(1.0, float(p98 - p2))
            wl = float((p98 + p2) / 2.0)

    ps_raw = getattr(ds, "PixelSpacing", None) or getattr(ds, "ImagerPixelSpacing", None)
    ps_row = float(ps_raw[0]) if (ps_raw is not None and len(ps_raw) >= 2) else 0.0
    ps_col = float(ps_raw[1]) if (ps_raw is not None and len(ps_raw) >= 2) else 0.0

    return hu_i16, w, h, int(round(wl)), max(1, int(round(ww))), min_hu, max_hu, ps_row, ps_col


def write_rs16_binary(out_bin_path: str, hu_i16: np.ndarray, def_wl: int, def_ww: int, ps_row: float = 0.0, ps_col: float = 0.0):
    h, w = hu_i16.shape
    min_hu = int(np.min(hu_i16)) if hu_i16.size else 0
    max_hu = int(np.max(hu_i16)) if hu_i16.size else 1
    def_wl_clamped = max(-32768, min(32767, int(def_wl)))
    def_ww_clamped = max(1, min(65535, int(def_ww)))
    # 24-byte header:
    # 0..4: b'RS16'
    # 4..6: uint16 width
    # 6..8: uint16 height
    # 8..10: int16 default_wl
    # 10..12: uint16 default_ww
    # 12..14: int16 min_hu
    # 14..16: int16 max_hu
    # 16..20: float32 pixel_spacing_row_mm
    # 20..24: float32 pixel_spacing_col_mm
    header = struct.pack(
        "<4sHHhHhhff",
        b"RS16",
        w,
        h,
        def_wl_clamped,
        def_ww_clamped,
        max(-32768, min(32767, min_hu)),
        max(-32768, min(32767, max_hu)),
        float(ps_row),
        float(ps_col),
    )
    os.makedirs(os.path.dirname(out_bin_path), exist_ok=True)
    tmp = out_bin_path + f".tmp_{os.getpid()}"
    with open(tmp, "wb") as wf:
        wf.write(header)
        wf.write(np.ascontiguousarray(hu_i16, dtype="<i2").tobytes())
    os.replace(tmp, out_bin_path)


def export_slice_16bit(dcm_path: str, out_bin_path: str, frame_idx: int = 0):
    hu_i16, w, h, wl, ww, _, _, ps_r, ps_c = _decode_slice_hu16(dcm_path, frame_idx)
    write_rs16_binary(out_bin_path, hu_i16, wl, ww, ps_r, ps_c)


def _ensure_mpr_volume(folder_path: str, series_uid: str, cache_dir: str):
    os.makedirs(cache_dir, exist_ok=True)
    key = hashlib.md5((os.path.abspath(folder_path) + "|" + series_uid).encode("utf-8")).hexdigest()
    npy_path = os.path.join(cache_dir, f"vol_{key}.npy")
    meta_path = os.path.join(cache_dir, f"vol_{key}.json")

    if os.path.isfile(npy_path) and os.path.isfile(meta_path):
        with open(meta_path, "r", encoding="utf-8") as mf:
            vmeta = json.load(mf)
        vol = np.load(npy_path, mmap_mode="r")
        return vol, vmeta

    # Inspect folder to get sorted slice list for this series
    tmp_inspect = os.path.join(cache_dir, f"insp_{key}_{os.getpid()}.json")
    inspect_folder(folder_path, tmp_inspect)
    with open(tmp_inspect, "r", encoding="utf-8") as f:
        info = json.load(f)
    try:
        os.remove(tmp_inspect)
    except Exception:
        pass

    target_ser = None
    for s in info.get("series", []):
        if s["series_uid"] == series_uid:
            target_ser = s
            break
    if not target_ser and info.get("series"):
        target_ser = max(info["series"], key=lambda s: s["slice_count"])

    if not target_ser or not target_ser.get("can_mpr"):
        raise RuntimeError("MPR reconstruction is not available for this series")

    fnames = target_ser["filenames"]
    # Ensure slice order goes Superior (Head, high Z) -> Inferior (Feet, low Z) for Axial volumes
    slices_with_z = []
    for fn in fnames:
        fp = os.path.join(folder_path, fn)
        ds = pydicom.dcmread(fp, stop_before_pixels=True, force=True)
        ipp = getattr(ds, "ImagePositionPatient", None)
        iop = getattr(ds, "ImageOrientationPatient", None)
        _, normal = _compute_plane_and_normal(iop)
        z_val = float(np.dot(np.array([float(x) for x in ipp[:3]]), normal)) if (ipp is not None and normal is not None) else 0.0
        slices_with_z.append((z_val, fn))

    if target_ser.get("plane") == "AXIAL":
        # Superior (larger Z in LPS) at index 0 -> head at top of Coronal/Sagittal!
        slices_with_z.sort(key=lambda x: -x[0])
    else:
        slices_with_z.sort(key=lambda x: x[0])

    first_fp = os.path.join(folder_path, slices_with_z[0][1])
    first_hu, w, h, def_wl, def_ww, _, _, ps_r, ps_c = _decode_slice_hu16(first_fp, 0)
    nz = len(slices_with_z)

    tmp_npy = npy_path + f".tmp_{os.getpid()}.npy"
    vol_mm = np.lib.format.open_memmap(tmp_npy, mode="w+", dtype="<i2", shape=(nz, h, w))
    vol_mm[0] = first_hu
    for idx in range(1, nz):
        fp = os.path.join(folder_path, slices_with_z[idx][1])
        sl_hu, sw, sh, _, _, _, _, _, _ = _decode_slice_hu16(fp, 0)
        if sw == w and sh == h:
            vol_mm[idx] = sl_hu
    vol_mm.flush()
    del vol_mm
    os.replace(tmp_npy, npy_path)

    vmeta = {
        "series_uid": target_ser["series_uid"],
        "plane": target_ser.get("plane", "AXIAL"),
        "nz": nz,
        "ny": h,
        "nx": w,
        "slice_spacing_mm": float(target_ser.get("slice_spacing_mm") or 1.0),
        "ps_row_mm": float(ps_r or 1.0),
        "ps_col_mm": float(ps_c or 1.0),
        "default_wl": int(def_wl),
        "default_ww": int(def_ww),
    }
    with open(meta_path, "w", encoding="utf-8") as mf:
        json.dump(vmeta, mf, ensure_ascii=False)

    vol = np.load(npy_path, mmap_mode="r")
    return vol, vmeta


def _resample_vertical_linear(img2d: np.ndarray, out_rows: int) -> np.ndarray:
    in_rows, cols = img2d.shape
    if out_rows <= 1 or in_rows <= 1 or out_rows == in_rows:
        return img2d.astype("<i2")
    y_coords = np.linspace(0.0, in_rows - 1.0, out_rows, dtype=np.float32)
    y0 = np.floor(y_coords).astype(np.int32)
    y1 = np.minimum(y0 + 1, in_rows - 1)
    wy = (y_coords - y0)[:, None]
    f0 = img2d[y0].astype(np.float32)
    f1 = img2d[y1].astype(np.float32)
    res = f0 * (1.0 - wy) + f1 * wy
    return np.clip(np.rint(res), -32768, 32767).astype("<i2")


def export_mpr_slice(folder_path: str, series_uid: str, mpr_plane: str, slice_idx: int, cache_dir: str, out_bin_path: str):
    vol, vmeta = _ensure_mpr_volume(folder_path, series_uid, cache_dir)
    nz, ny, nx = vmeta["nz"], vmeta["ny"], vmeta["nx"]
    dz = max(0.1, float(vmeta["slice_spacing_mm"]))
    dy = max(0.1, float(vmeta["ps_row_mm"]))
    dx = max(0.1, float(vmeta["ps_col_mm"]))

    plane = mpr_plane.lower()
    if plane == "coronal":
        y_idx = max(0, min(ny - 1, int(slice_idx)))
        raw_plane = np.array(vol[:, y_idx, :], dtype="<i2")  # (nz, nx)
        phys_height_px = max(32, min(2048, int(round(nz * (dz / dx)))))
        resampled = _resample_vertical_linear(raw_plane, phys_height_px)
        write_rs16_binary(out_bin_path, resampled, vmeta["default_wl"], vmeta["default_ww"], dx, dx)
    elif plane == "sagittal":
        x_idx = max(0, min(nx - 1, int(slice_idx)))
        raw_plane = np.array(vol[:, :, x_idx], dtype="<i2")  # (nz, ny)
        phys_height_px = max(32, min(2048, int(round(nz * (dz / dy)))))
        resampled = _resample_vertical_linear(raw_plane, phys_height_px)
        write_rs16_binary(out_bin_path, resampled, vmeta["default_wl"], vmeta["default_ww"], dy, dy)
    else:
        z_idx = max(0, min(nz - 1, int(slice_idx)))
        raw_plane = np.array(vol[z_idx, :, :], dtype="<i2")
        write_rs16_binary(out_bin_path, raw_plane, vmeta["default_wl"], vmeta["default_ww"], dy, dx)


def ensure_playable_mp4(src_path: str, out_mp4_path: str):
    import imageio_ffmpeg
    ffmpeg_exe = imageio_ffmpeg.get_ffmpeg_exe()
    os.makedirs(os.path.dirname(out_mp4_path), exist_ok=True)
    tmp_mp4 = out_mp4_path + f".tmp_{os.getpid()}.mp4"

    ext = os.path.splitext(src_path)[1].lower().lstrip(".")
    if ext in VIDEO_EXTS:
        cmd = [
            ffmpeg_exe, "-y", "-i", src_path,
            "-c:v", "libx264", "-preset", "ultrafast", "-crf", "21",
            "-pix_fmt", "yuv420p", "-vf", "scale=trunc(iw/2)*2:trunc(ih/2)*2",
            "-movflags", "+faststart",
            "-c:a", "aac", "-b:a", "128k",
            tmp_mp4,
        ]
        subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True, timeout=180)
        os.replace(tmp_mp4, out_mp4_path)
        return

    # Encapsulated Video DICOM or Multi-Frame Cine DICOM
    ds = pydicom.dcmread(src_path, force=True)
    ts_uid = ""
    if hasattr(ds, "file_meta") and ds.file_meta is not None:
        ts_uid = _decode_text(getattr(ds.file_meta, "TransferSyntaxUID", ""))

    if ts_uid.startswith("1.2.840.10008.1.2.4.10"):
        from pydicom.encaps import generate_pixel_data_frame
        raw_vid = tmp_mp4 + ".rawvid"
        with open(raw_vid, "wb") as rf:
            for frame_bytes in generate_pixel_data_frame(ds.PixelData):
                rf.write(frame_bytes)
        try:
            cmd = [
                ffmpeg_exe, "-y", "-i", raw_vid,
                "-c:v", "libx264", "-preset", "ultrafast", "-crf", "21",
                "-pix_fmt", "yuv420p", "-movflags", "+faststart",
                tmp_mp4,
            ]
            subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True, timeout=180)
            os.replace(tmp_mp4, out_mp4_path)
        finally:
            if os.path.exists(raw_vid):
                os.remove(raw_vid)
        return

    # Multi-frame Cine DICOM -> pipe RGB frames to ffmpeg
    arr = ds.pixel_array
    frame_time_ms = _first_float(getattr(ds, "FrameTime", None), None)
    fps = 10.0
    if frame_time_ms and frame_time_ms > 0:
        fps = max(1.0, min(60.0, 1000.0 / frame_time_ms))
    else:
        cine_rate = _first_float(getattr(ds, "RecommendedDisplayFrameRate", None) or getattr(ds, "CineRate", None), None)
        if cine_rate and cine_rate > 0:
            fps = max(1.0, min(60.0, cine_rate))

    if arr.ndim == 4 and arr.shape[-1] in (3, 4):
        frames_rgb = arr[:, :, :, :3].astype(np.uint8)
    elif arr.ndim == 3 and arr.shape[-1] not in (3, 4):
        try:
            lut = apply_voi_lut(arr, ds)
        except Exception:
            lut = arr.astype(np.float32)
        mn, mx = float(np.min(lut)), float(np.max(lut))
        norm = ((lut - mn) / (mx - mn) * 255.0).astype(np.uint8) if mx > mn else np.zeros(lut.shape, dtype=np.uint8)
        if _decode_text(getattr(ds, "PhotometricInterpretation", "")).upper() == "MONOCHROME1":
            norm = 255 - norm
        frames_rgb = np.stack([norm, norm, norm], axis=-1)
    else:
        raise RuntimeError("Not a multi-frame DICOM")

    nf, h, w, _ = frames_rgb.shape
    w_even = w - (w % 2)
    h_even = h - (h % 2)
    frames_rgb = np.ascontiguousarray(frames_rgb[:, :h_even, :w_even, :])

    cmd = [
        ffmpeg_exe, "-y",
        "-f", "rawvideo", "-vcodec", "rawvideo",
        "-pix_fmt", "rgb24", "-s", f"{w_even}x{h_even}",
        "-r", f"{fps:.2f}", "-i", "-",
        "-c:v", "libx264", "-preset", "ultrafast", "-crf", "20",
        "-pix_fmt", "yuv420p", "-movflags", "+faststart",
        tmp_mp4,
    ]
    proc = subprocess.Popen(cmd, stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    proc.communicate(input=frames_rgb.tobytes(), timeout=180)
    if proc.returncode != 0:
        raise RuntimeError("ffmpeg cine encode failed")
    os.replace(tmp_mp4, out_mp4_path)


def extract_video_thumbnail(src_path: str, out_jpg_path: str, is_thumb: bool = False) -> bool:
    try:
        import cv2
        cap = cv2.VideoCapture(src_path)
        if cap.isOpened():
            fcnt = int(cap.get(cv2.CAP_PROP_FRAME_COUNT) or 0)
            fps = float(cap.get(cv2.CAP_PROP_FPS) or 30.0)
            target_frame = min(max(0, int(fps * 1.0)), max(0, fcnt // 5)) if fcnt > 5 else 0
            if target_frame > 0:
                cap.set(cv2.CAP_PROP_POS_FRAMES, target_frame)
            ok, frame = cap.read()
            if not ok or frame is None:
                cap.set(cv2.CAP_PROP_POS_FRAMES, 0)
                ok, frame = cap.read()
            cap.release()
            if ok and frame is not None:
                rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                im = Image.fromarray(rgb)
                if is_thumb:
                    im.thumbnail((220, 220), Image.Resampling.LANCZOS)
                os.makedirs(os.path.dirname(out_jpg_path), exist_ok=True)
                im.save(out_jpg_path, format="JPEG", quality=85 if not is_thumb else 80)
                return True
    except Exception:
        pass
    return False


def infer_smart_kensamei(modality: str, study_desc: str = "", proto_name: str = "", series_descs=None, body_part: str = "") -> str:
    import re
    mod = (modality or "OT").upper()
    series_descs = series_descs or []
    sd = (study_desc or "").strip()
    pr = (proto_name or "").strip()
    bp = (body_part or "").strip()

    # Clean Toshiba/hospital protocol prefix like "単純のみ）胸〜骨盤" -> "胸〜骨盤(単純)"
    if pr:
        m_prefix = re.match(r"^(単純のみ|単純|造影のみ|単純\+造影|単純・造影|造影)[）\)\s:：]+(.+)$", pr)
        if m_prefix:
            kind_lbl = "単純" if "単純" in m_prefix.group(1) and "造影" not in m_prefix.group(1) else ("造影" if "造影" in m_prefix.group(1) else "")
            region = m_prefix.group(2).strip()
            if mod in ("CT", "MR"):
                mod_disp = "MRI" if mod == "MR" else "CT"
                return f"{region}{mod_disp}" + (f"({kind_lbl})" if kind_lbl else "")
            return f"{mod}_{region}"

    if sd and not re.fullmatch(r"[,.\d\s_\-]+", sd):
        if mod == "CT" and "CT" not in sd.upper():
            return f"CT({sd})"
        if mod == "MR" and "MR" not in sd.upper():
            return f"MRI({sd})"
        return sd

    if pr and not re.fullmatch(r"[,.\d\s_\-]+", pr):
        if mod == "CT" and "CT" not in pr.upper():
            return f"CT({pr})"
        if mod == "MR" and "MR" not in pr.upper():
            return f"MRI({pr})"
        return pr

    joined = " ".join([sd, pr, bp] + list(series_descs)).upper()
    if mod in ("CT", "MR"):
        mod_disp = "MRI" if mod == "MR" else "CT"
        has_chest = any(k in joined for k in ("CHEST", "THORAX", "LUNG", "胸", "肺"))
        has_abd = any(k in joined for k in ("ABD", "LIVER", "PANCREAS", "腹", "肝", "膵"))
        has_pelvis = any(k in joined for k in ("PELV", "RECT", "COLON", "骨盤", "直腸", "下部"))
        has_head = any(k in joined for k in ("HEAD", "BRAIN", "CRANI", "頭", "脳"))
        has_spine = any(k in joined for k in ("SPINE", "LUMBAR", "CERVICAL", "腰椎", "頸椎", "頚椎", "胸椎", "脊椎"))
        if has_chest and has_abd and has_pelvis:
            return f"胸〜骨盤{mod_disp}"
        if has_chest and has_abd:
            return f"胸腹部{mod_disp}"
        if has_abd and has_pelvis:
            return f"腹部・骨盤{mod_disp}"
        if has_pelvis:
            return f"骨盤{mod_disp}"
        if has_abd:
            return f"腹部{mod_disp}"
        if has_chest:
            return f"胸部{mod_disp}"
        if has_head:
            return f"頭部{mod_disp}"
        if has_spine:
            return f"脊椎{mod_disp}"
        return f"他院紹介{mod_disp}"

    if mod == "ES":
        return "内視鏡"
    if mod == "US":
        return "超音波(US)"
    if mod in ("DX", "CR"):
        return "単純X線(紹介)"
    if mod == "RF":
        return "X線透視(RF)"
    if mod == "VIDEO":
        return "内視鏡・検査動画"
    if mod == "PDF":
        return "紹介状・検査PDF"
    if mod == "IMAGE":
        return "外部取込画像"
    return f"紹介画像({mod})"


def inspect_cd_manifest(input_json_path: str, out_json_path: str):
    """
    DICOMDIR およびサンプルファイルのヘッダを高速解析し、
    紹介元医療機関・CD内患者情報・当院患者マスタ候補・検査単位リスト（撮影日・検査種別自動推定）を返す。
    """
    import re
    from datetime import date

    with open(input_json_path, "r", encoding="utf-8") as f:
        req = json.load(f)

    dicomdir_path = req.get("dicomdir_path") or ""
    sample_files = req.get("sample_files") or []  # [{"path": ..., "rel_path": ..., "orig_filename": ...}]

    # Parse sample slice headers first to enrich institution / protocol / device info per StudyInstanceUID or rel_path prefix
    sample_meta_by_uid = {}
    sample_meta_list = []
    institution_found = ""
    manufacturer_found = ""
    model_found = ""
    patient_kanji = ""
    patient_kana = ""
    patient_birth = ""
    patient_sex = ""
    ext_patient_id = ""

    for sf in sample_files:
        fp = sf.get("path") or ""
        rel = (sf.get("rel_path") or sf.get("orig_filename") or "").replace("\\", "/").lstrip("/")
        if not fp or not os.path.isfile(fp):
            continue
        ext = os.path.splitext(rel)[1].lower().lstrip(".")
        if ext in VIDEO_EXTS or ext in IMAGE_EXTS or ext in PDF_EXTS:
            continue
        try:
            ds = pydicom.dcmread(fp, stop_before_pixels=True, force=True)
            if not any(k in ds for k in ("Modality", "SOPInstanceUID", "StudyInstanceUID", "Rows")):
                continue
            suid = _decode_text(getattr(ds, "StudyInstanceUID", ""))
            inst = _decode_text(getattr(ds, "InstitutionName", ""))
            mfr = _decode_text(getattr(ds, "Manufacturer", ""))
            mdl = _decode_text(getattr(ds, "ManufacturerModelName", ""))
            proto = _decode_text(getattr(ds, "ProtocolName", ""))
            sdesc = _decode_text(getattr(ds, "StudyDescription", ""))
            serdesc = _decode_text(getattr(ds, "SeriesDescription", ""))
            bp = _decode_text(getattr(ds, "BodyPartExamined", ""))
            mod = (_decode_text(getattr(ds, "Modality", "")) or "OT").upper()
            sdate = _decode_text(getattr(ds, "StudyDate", ""))
            stime = _decode_text(getattr(ds, "StudyTime", ""))

            if inst and not institution_found:
                institution_found = friendly_institution_name(inst)
            if mfr and not manufacturer_found:
                manufacturer_found = mfr
            if mdl and not model_found:
                model_found = mdl

            if not patient_kanji:
                kj, kn = parse_japanese_person_name(_decode_text(getattr(ds, "PatientName", "")))
                if kj:
                    patient_kanji = kj
                if kn:
                    patient_kana = kn
            if not ext_patient_id:
                ext_patient_id = _decode_text(getattr(ds, "PatientID", ""))
            if not patient_birth:
                patient_birth = _decode_text(getattr(ds, "PatientBirthDate", ""))
            if not patient_sex:
                sx = _decode_text(getattr(ds, "PatientSex", "")).upper()
                patient_sex = "男" if sx == "M" else ("女" if sx == "F" else sx)

            info = {
                "rel_path": rel,
                "study_uid": suid,
                "institution": friendly_institution_name(inst) if inst else "",
                "raw_institution": inst,
                "manufacturer": mfr,
                "model_name": mdl,
                "protocol_name": proto,
                "study_desc": sdesc,
                "series_desc": serdesc,
                "body_part": bp,
                "modality": mod,
                "study_date": sdate,
                "study_time": stime,
            }
            sample_meta_list.append(info)
            if suid and (suid not in sample_meta_by_uid or (proto and not sample_meta_by_uid[suid].get("protocol_name"))):
                sample_meta_by_uid[suid] = info
        except Exception:
            continue

    studies_out = []

    # Parse DICOMDIR if present
    if dicomdir_path and os.path.isfile(dicomdir_path):
        try:
            dd = pydicom.dcmread(dicomdir_path, force=True)
            cur_study = None
            cur_series = None

            for rec in getattr(dd, "DirectoryRecordSequence", []):
                rtype = _decode_text(getattr(rec, "DirectoryRecordType", "")).upper()
                if rtype == "PATIENT":
                    if not ext_patient_id:
                        ext_patient_id = _decode_text(getattr(rec, "PatientID", ""))
                    kj, kn = parse_japanese_person_name(_decode_text(getattr(rec, "PatientName", "")))
                    if kj and not patient_kanji:
                        patient_kanji = kj
                    if kn and not patient_kana:
                        patient_kana = kn
                    if not patient_birth:
                        patient_birth = _decode_text(getattr(rec, "PatientBirthDate", ""))
                    if not patient_sex:
                        sx = _decode_text(getattr(rec, "PatientSex", "")).upper()
                        patient_sex = "男" if sx == "M" else ("女" if sx == "F" else sx)

                elif rtype == "STUDY":
                    suid = _decode_text(getattr(rec, "StudyInstanceUID", ""))
                    sdate_raw = re.sub(r"[^0-9]", "", _decode_text(getattr(rec, "StudyDate", "")))
                    stime_raw = re.sub(r"[^0-9]", "", _decode_text(getattr(rec, "StudyTime", "")))
                    sdate_iso = f"{sdate_raw[0:4]}-{sdate_raw[4:6]}-{sdate_raw[6:8]}" if len(sdate_raw) >= 8 else ""
                    stime_hm = f"{stime_raw[0:2]}:{stime_raw[2:4]}" if len(stime_raw) >= 4 else ""
                    cur_study = {
                        "study_uid": suid or f"STUDY_{len(studies_out)+1}",
                        "study_date": sdate_iso,
                        "study_time": stime_hm,
                        "study_desc": _decode_text(getattr(rec, "StudyDescription", "")),
                        "accession_number": _decode_text(getattr(rec, "AccessionNumber", "")),
                        "series": [],
                        "rel_paths": [],
                    }
                    studies_out.append(cur_study)
                    cur_series = None

                elif rtype == "SERIES" and cur_study is not None:
                    smod = (_decode_text(getattr(rec, "Modality", "")) or "OT").upper()
                    try:
                        snum = int(getattr(rec, "SeriesNumber", 0) or 0)
                    except Exception:
                        snum = 0
                    cur_series = {
                        "series_number": snum,
                        "modality": smod,
                        "raw_desc": _decode_text(getattr(rec, "SeriesDescription", "")),
                        "body_part": _decode_text(getattr(rec, "BodyPartExamined", "")),
                        "count": 0,
                    }
                    cur_study["series"].append(cur_series)

                elif rtype in ("IMAGE", "ENCAP DOC", "PRESENTATION", "SR", "WAVEFORM") and cur_study is not None:
                    ref_id = getattr(rec, "ReferencedFileID", None)
                    if ref_id:
                        if isinstance(ref_id, (list, tuple)) or hasattr(ref_id, "__iter__") and not isinstance(ref_id, (str, bytes)):
                            rel_p = "/".join(str(x).strip() for x in ref_id if str(x).strip())
                        else:
                            rel_p = str(ref_id).replace("\\", "/").strip("/")
                        if rel_p:
                            cur_study["rel_paths"].append(rel_p)
                    if cur_series is not None:
                        cur_series["count"] += 1
        except Exception:
            studies_out = []

    # Finalize DICOMDIR studies with sample slice metadata (ProtocolName, InstitutionName, clean Series names)
    formatted_studies = []
    if studies_out:
        for st in studies_out:
            suid = st["study_uid"]
            smeta = sample_meta_by_uid.get(suid) or {}
            # Also match by rel_path prefix if UID wasn't in sample_meta_by_uid
            if not smeta and st["rel_paths"]:
                first_prefix = st["rel_paths"][0].rsplit("/", 1)[0] if "/" in st["rel_paths"][0] else ""
                for sm in sample_meta_list:
                    if first_prefix and sm["rel_path"].startswith(first_prefix + "/"):
                        smeta = sm
                        break

            active_series = [s for s in st["series"] if s["count"] > 0 and s["modality"] not in ("PR", "KO")]
            if not active_series:
                active_series = [s for s in st["series"] if s["count"] > 0]

            # Primary modality = modality of largest series
            primary_mod = "OT"
            if active_series:
                largest = max(active_series, key=lambda x: x["count"])
                primary_mod = largest["modality"]
            elif smeta.get("modality"):
                primary_mod = smeta["modality"]

            cleaned_series = []
            raw_descs = []
            body_part = smeta.get("body_part") or ""
            for s in active_series:
                raw_descs.append(s["raw_desc"])
                if not body_part and s.get("body_part"):
                    body_part = s["body_part"]
                cleaned_series.append({
                    "series_number": s["series_number"],
                    "modality": s["modality"],
                    "desc": clean_series_desc(s["raw_desc"], slice_count=s["count"], modality=s["modality"]),
                    "count": s["count"],
                })

            inst_name = smeta.get("institution") or institution_found
            kensamei = infer_smart_kensamei(
                primary_mod,
                study_desc=st["study_desc"] or smeta.get("study_desc", ""),
                proto_name=smeta.get("protocol_name", ""),
                series_descs=raw_descs,
                body_part=body_part,
            )

            formatted_studies.append({
                "study_uid": suid,
                "study_date": st["study_date"],
                "study_time": st["study_time"],
                "modality": primary_mod,
                "kensamei": kensamei,
                "institution": inst_name,
                "file_count": len(st["rel_paths"]) or sum(s["count"] for s in active_series),
                "series": cleaned_series,
                "rel_paths": st["rel_paths"],
            })
        # Sort newest study first for display clarity
        formatted_studies.sort(key=lambda x: (x["study_date"] or "", x["study_time"] or ""), reverse=True)

    elif sample_meta_list:
        # Loose DICOMs without DICOMDIR: group by StudyInstanceUID
        by_uid = {}
        for sm in sample_meta_list:
            uid = sm["study_uid"] or "LOOSE_DICOM"
            if uid not in by_uid:
                sd_raw = re.sub(r"[^0-9]", "", sm["study_date"] or "")
                st_raw = re.sub(r"[^0-9]", "", sm["study_time"] or "")
                by_uid[uid] = {
                    "study_uid": uid,
                    "study_date": f"{sd_raw[0:4]}-{sd_raw[4:6]}-{sd_raw[6:8]}" if len(sd_raw) >= 8 else "",
                    "study_time": f"{st_raw[0:2]}:{st_raw[2:4]}" if len(st_raw) >= 4 else "",
                    "modality": sm["modality"],
                    "kensamei": infer_smart_kensamei(sm["modality"], sm["study_desc"], sm["protocol_name"], [sm["series_desc"]], sm["body_part"]),
                    "institution": sm["institution"] or institution_found,
                    "file_count": 0,
                    "series": [],
                    "rel_paths": [],
                }
        formatted_studies = list(by_uid.values())

    # Format birth date YYYY-MM-DD
    b_digits = re.sub(r"[^0-9]", "", patient_birth or "")
    birth_iso = f"{b_digits[0:4]}-{b_digits[4:6]}-{b_digits[6:8]}" if len(b_digits) >= 8 else ""
    age_val = None
    if birth_iso:
        try:
            bd = date.fromisoformat(birth_iso)
            today = date.today()
            age_val = today.year - bd.year - ((today.month, today.day) < (bd.month, bd.day))
        except Exception:
            age_val = None

    # Lookup matching clinic patients in rsnext_db
    matched_clinic_patients = []
    try:
        BASE_DIR = os.path.dirname(os.path.abspath(__file__))
        if BASE_DIR not in sys.path:
            sys.path.insert(0, BASE_DIR)
        from ingest_service.common import get_db_conn, load_config, normalize_kana_yomi

        cfg = load_config()
        conn = get_db_conn(cfg)
        try:
            kanji_nospace = re.sub(r"[\s　]+", "", patient_kanji or "")
            yomi_norm = normalize_kana_yomi(patient_kana or "")
            seen_pids = set()
            with conn.cursor() as cur:
                if kanji_nospace:
                    cur.execute(
                        """
                        SELECT patient_id, kanja_kanji, kanja_yomi, birth_date, sex
                        FROM patients
                        WHERE REGEXP_REPLACE(kanja_kanji, '[\\s　]+', '', 'g') = %s
                        ORDER BY updated_at DESC LIMIT 5
                        """,
                        (kanji_nospace,),
                    )
                    for r in cur.fetchall():
                        seen_pids.add(r["patient_id"])
                        pid_s = str(r["patient_id"])
                        chart_tag = "おのカルテ" if (len(pid_s) == 6 and pid_s.startswith("9")) else "新カルテ"
                        b_str = r["birth_date"].isoformat() if r["birth_date"] else ""
                        m_lbl = f"氏名＋生年月日完全一致・{chart_tag}" if (birth_iso and b_str == birth_iso) else f"氏名一致・{chart_tag}"
                        matched_clinic_patients.append({
                            "patient_id": pid_s,
                            "kanja_kanji": r["kanja_kanji"],
                            "kanja_yomi": r["kanja_yomi"],
                            "birth_date": b_str,
                            "sex": r["sex"] or "",
                            "match_type": m_lbl,
                            "_sort_key": 0 if chart_tag == "新カルテ" else 1,
                        })
                    matched_clinic_patients.sort(key=lambda x: x.pop("_sort_key", 9))

                if birth_iso and not matched_clinic_patients:
                    cur.execute(
                        """
                        SELECT patient_id, kanja_kanji, kanja_yomi, birth_date, sex, kanja_yomi_norm
                        FROM patients
                        WHERE birth_date = %s
                        ORDER BY updated_at DESC LIMIT 10
                        """,
                        (birth_iso,),
                    )
                    for r in cur.fetchall():
                        if r["patient_id"] in seen_pids:
                            continue
                        if yomi_norm and r.get("kanja_yomi_norm") == yomi_norm:
                            mtype = "生年月日＋カナ完全一致"
                        elif not yomi_norm and patient_sex and r.get("sex") == patient_sex:
                            mtype = "生年月日＋性別一致"
                        else:
                            continue
                        seen_pids.add(r["patient_id"])
                        matched_clinic_patients.append({
                            "patient_id": r["patient_id"],
                            "kanja_kanji": r["kanja_kanji"],
                            "kanja_yomi": r["kanja_yomi"],
                            "birth_date": r["birth_date"].isoformat() if r["birth_date"] else "",
                            "sex": r["sex"] or "",
                            "match_type": mtype,
                        })
        finally:
            conn.close()
    except Exception:
        pass

    payload = {
        "status": "success",
        "institution": institution_found,
        "manufacturer": manufacturer_found,
        "model_name": model_found,
        "cd_patient": {
            "ext_patient_id": ext_patient_id,
            "kanji": patient_kanji,
            "kana": patient_kana,
            "birth_date": birth_iso,
            "age": age_val,
            "sex": patient_sex,
        },
        "clinic_candidates": matched_clinic_patients,
        "studies": formatted_studies,
    }

    os.makedirs(os.path.dirname(out_json_path), exist_ok=True)
    tmp_p = out_json_path + f".tmp_{os.getpid()}"
    with open(tmp_p, "w", encoding="utf-8") as wf:
        json.dump(payload, wf, ensure_ascii=False)
    os.replace(tmp_p, out_json_path)


def main():
    if len(sys.argv) < 3:
        sys.exit(1)
    cmd = sys.argv[1]
    try:
        if cmd == "inspect_folder":
            inspect_folder(sys.argv[2], sys.argv[3])
        elif cmd == "inspect_cd":
            inspect_cd_manifest(sys.argv[2], sys.argv[3])
        elif cmd == "slice_16bit":
            f_idx = int(sys.argv[4]) if len(sys.argv) >= 5 else 0
            export_slice_16bit(sys.argv[2], sys.argv[3], f_idx)
        elif cmd == "mpr_slice":
            # mpr_slice <folder> <series_uid> <plane> <slice_idx> <cache_dir> <out_bin>
            export_mpr_slice(sys.argv[2], sys.argv[3], sys.argv[4], int(sys.argv[5]), sys.argv[6], sys.argv[7])
        elif cmd == "ensure_mp4":
            ensure_playable_mp4(sys.argv[2], sys.argv[3])
        elif cmd == "video_thumb":
            is_th = len(sys.argv) >= 5 and sys.argv[4] == "thumb"
            if not extract_video_thumbnail(sys.argv[2], sys.argv[3], is_th):
                sys.exit(2)
        else:
            sys.exit(2)
    except Exception as e:
        sys.stderr.write(str(e))
        sys.exit(3)


if __name__ == "__main__":
    main()

