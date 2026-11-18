import sys
import os
import pydicom
from pydicom.pixel_data_handlers.util import apply_voi_lut
import numpy as np
from PIL import Image

VIDEO_EXTS = {"mp4", "webm", "mov", "m4v", "avi", "mpg", "mpeg", "wmv", "mkv", "mts", "ogv"}


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


def render_video_poster(video_path: str, out_path: str, is_thumb: bool) -> bool:
    try:
        import cv2
        cap = cv2.VideoCapture(video_path)
        if not cap.isOpened():
            return False
        fcnt = int(cap.get(cv2.CAP_PROP_FRAME_COUNT) or 0)
        fps = float(cap.get(cv2.CAP_PROP_FPS) or 30.0)
        target = min(max(0, int(fps * 1.0)), max(0, fcnt // 5)) if fcnt > 5 else 0
        if target > 0:
            cap.set(cv2.CAP_PROP_POS_FRAMES, target)
        ok, frame = cap.read()
        if not ok or frame is None:
            cap.set(cv2.CAP_PROP_POS_FRAMES, 0)
            ok, frame = cap.read()
        cap.release()
        if not ok or frame is None:
            return False
        rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        img = Image.fromarray(rgb)
        if is_thumb:
            img.thumbnail((220, 220), Image.Resampling.LANCZOS)
        out_dir = os.path.dirname(out_path)
        if out_dir:
            os.makedirs(out_dir, exist_ok=True)
        img.save(out_path, format="JPEG", quality=85 if not is_thumb else 80)
        return True
    except Exception:
        return False


def main():
    # Usage: python dcm_render.py <src_path> <out_path> [thumb|full] [wl] [ww] [frame_idx]
    if len(sys.argv) < 3:
        sys.exit(1)
    src_path = sys.argv[1]
    out_path = sys.argv[2]
    is_thumb = len(sys.argv) >= 4 and sys.argv[3] == "thumb"
    custom_wl = float(sys.argv[4]) if len(sys.argv) >= 5 and sys.argv[4] not in ("", "auto") else None
    custom_ww = float(sys.argv[5]) if len(sys.argv) >= 6 and sys.argv[5] not in ("", "auto") else None
    frame_idx = int(sys.argv[6]) if len(sys.argv) >= 7 and sys.argv[6] != "" else 0

    ext = os.path.splitext(src_path)[1].lower().lstrip(".")
    if ext in VIDEO_EXTS:
        sys.exit(0 if render_video_poster(src_path, out_path, is_thumb) else 2)

    try:
        ds = pydicom.dcmread(src_path, force=True)

        # Encapsulated Video DICOM poster frame
        ts_uid = ""
        if hasattr(ds, "file_meta") and ds.file_meta is not None:
            ts_uid = str(getattr(ds.file_meta, "TransferSyntaxUID", "")).strip()
        if ts_uid.startswith("1.2.840.10008.1.2.4.10"):
            from pydicom.encaps import generate_pixel_data_frame
            tmp_vid = out_path + f".tmp_{os.getpid()}.mp4"
            try:
                with open(tmp_vid, "wb") as rf:
                    for fb in generate_pixel_data_frame(ds.PixelData):
                        rf.write(fb)
                ok = render_video_poster(tmp_vid, out_path, is_thumb)
            finally:
                if os.path.exists(tmp_vid):
                    os.remove(tmp_vid)
            sys.exit(0 if ok else 2)

        if not hasattr(ds, "pixel_array"):
            sys.exit(2)

        arr = ds.pixel_array

        # Handle Multi-frame Color (Frames, H, W, 3/4) or Single Color (H, W, 3/4)
        if arr.ndim == 4 and arr.shape[3] in (3, 4):
            f_i = max(0, min(arr.shape[0] - 1, frame_idx))
            frame = arr[f_i, :, :, :3]
            img = Image.fromarray(frame.astype(np.uint8), mode="RGB")
        elif arr.ndim == 3 and arr.shape[2] in (3, 4):
            img = Image.fromarray(arr[:, :, :3].astype(np.uint8), mode="RGB")
        else:
            # Grayscale Single-frame (H, W) or Multi-frame (Frames, H, W)
            if arr.ndim == 3:
                f_i = max(0, min(arr.shape[0] - 1, frame_idx))
                arr = arr[f_i]

            slope = _first_float(getattr(ds, "RescaleSlope", None), 1.0)
            intercept = _first_float(getattr(ds, "RescaleIntercept", None), 0.0)

            if custom_wl is not None and custom_ww is not None and custom_ww > 0:
                hu = arr.astype(np.float32) * slope + intercept
                low = custom_wl - (custom_ww / 2.0)
                high = custom_wl + (custom_ww / 2.0)
                norm_arr = np.clip((hu - low) / (high - low) * 255.0, 0.0, 255.0).astype(np.uint8)
            else:
                try:
                    arr_lut = apply_voi_lut(arr, ds)
                except Exception:
                    arr_lut = arr.astype(np.float32) * slope + intercept

                min_val = float(np.min(arr_lut))
                max_val = float(np.max(arr_lut))
                if max_val > min_val:
                    norm_arr = ((arr_lut - min_val) / (max_val - min_val) * 255.0).astype(np.uint8)
                else:
                    norm_arr = np.zeros(arr_lut.shape, dtype=np.uint8)

            photo_interp = str(getattr(ds, "PhotometricInterpretation", "")).strip().upper()
            if photo_interp == "MONOCHROME1":
                norm_arr = 255 - norm_arr

            img = Image.fromarray(norm_arr, mode="L")

        if is_thumb:
            img.thumbnail((220, 220), Image.Resampling.LANCZOS)

        out_dir = os.path.dirname(out_path)
        if out_dir and not os.path.exists(out_dir):
            os.makedirs(out_dir, exist_ok=True)

        img.save(out_path, format="JPEG", quality=88 if not is_thumb else 82)
        sys.exit(0)

    except Exception as e:
        sys.stderr.write(str(e))
        sys.exit(3)


if __name__ == "__main__":
    main()
