import os
import sys
import time
import logging
import threading
from watchdog.observers import Observer
from watchdog.events import FileSystemEventHandler

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import load_config
from ingest_service.study_filer import ingest_single_file

logger = logging.getLogger("rsnext.ecg_watcher")


def wait_until_file_ready(filepath: str, timeout_sec: float = 8.0) -> bool:
    deadline = time.time() + timeout_sec
    last_size = -1
    stable_ticks = 0
    while time.time() < deadline:
        if not os.path.isfile(filepath):
            return False
        try:
            sz = os.path.getsize(filepath)
            if sz > 0 and sz == last_size:
                with open(filepath, "rb"):
                    pass
                stable_ticks += 1
                if stable_ticks >= 2:
                    return True
            else:
                stable_ticks = 0
                last_size = sz
        except OSError:
            stable_ticks = 0
        time.sleep(0.15)
    return os.path.isfile(filepath) and os.path.getsize(filepath) > 0


class _EcgEventHandler(FileSystemEventHandler):
    def __init__(self, callback):
        super().__init__()
        self.callback = callback

    def on_created(self, event):
        if not event.is_directory:
            self.callback(event.src_path)

    def on_moved(self, event):
        if not event.is_directory:
            self.callback(event.dest_path)


class FukudaEcgWatcherService:
    """
    マルチベンダー心電図ウォッチャー:
    1) フクダ電子 (C:/FUKUDA_ECG, C:/FUKUDA_ECG/ORG): .ECG, .001
    2) 日本光電 (C:/NIHON_KOHDEN_ECG/INBOX): .MWF (MFER標準), .NKC (ConvName_Online), .PDF, .JPG (光電ECGレポート画像)
    """

    def __init__(self, cfg: dict = None):
        self.cfg = cfg or load_config()
        ecg_cfg = self.cfg["ingest"]["fukuda_ecg"]
        sub = ecg_cfg.get("watch_subdir") or "fukuda_ecg"
        if os.path.isabs(sub):
            if os.path.basename(sub.rstrip("/\\")).upper() == "ORG":
                self.watch_dir = os.path.dirname(sub.rstrip("/\\"))
            else:
                self.watch_dir = sub
            self.subfolder_label = "fukuda_ecg"
        else:
            self.watch_dir = os.path.join(self.cfg["storage"]["inbox_root"], sub)
            self.subfolder_label = sub
        os.makedirs(self.watch_dir, exist_ok=True)
        os.makedirs(os.path.join(self.watch_dir, "ORG"), exist_ok=True)

        # 日本光電 受信用フォルダ解決
        nk_cfg = self.cfg["ingest"].get("nihon_kohden_ecg") or {}
        self.nk_enabled = bool(nk_cfg.get("enabled", True))
        nk_sub = nk_cfg.get("watch_subdir") or (
            "C:/NIHON_KOHDEN_ECG/INBOX" if os.path.isabs(sub) else "nihon_kohden_ecg"
        )
        if os.path.isabs(nk_sub):
            self.nk_watch_dir = nk_sub
        else:
            self.nk_watch_dir = os.path.join(self.cfg["storage"]["inbox_root"], nk_sub)
        if self.nk_enabled:
            os.makedirs(self.nk_watch_dir, exist_ok=True)

        self._lock = threading.Lock()
        self._in_progress = set()
        self.observer: Observer | None = None

    def process_file(self, filepath: str, vendor_label: str = "fukuda_ecg"):
        norm_p = os.path.abspath(filepath)
        # BIN / TOOLS サブフォルダ配下のモジュール本体は除外
        parts_upper = [p.upper() for p in norm_p.split(os.sep)]
        if "BIN" in parts_upper or "TOOLS" in parts_upper:
            return

        ext = os.path.splitext(norm_p)[1].lower().lstrip(".")
        # 日本光電 ConvName_Online の付帯インデックスCSVは不要なため静かに掃除
        if vendor_label == "nihon_kohden_ecg" and ext == "csv":
            try:
                if wait_until_file_ready(norm_p, timeout_sec=2.0):
                    os.remove(norm_p)
            except OSError:
                pass
            return

        allowed_exts = (
            ("ecg", "001", "mwf", "nkc", "pdf", "jpg", "jpeg", "png")
            if vendor_label == "nihon_kohden_ecg"
            else ("ecg", "001", "mwf", "nkc")
        )
        if ext not in allowed_exts:
            return

        with self._lock:
            if norm_p in self._in_progress:
                return
            self._in_progress.add(norm_p)

        def _task():
            try:
                if wait_until_file_ready(norm_p):
                    extra = {
                        "watch_subfolder": vendor_label,
                        "orig_filename": os.path.basename(norm_p),
                    }
                    if ext in ("ecg", "001", "mwf", "nkc"):
                        extra["force_kind"] = "ECG"
                    elif vendor_label == "nihon_kohden_ecg":
                        # 日本光電 JPG/PDFレポート出力モード時も心電図として分類
                        extra["modality"] = "ECG"
                    ingest_single_file(
                        norm_p,
                        source_protocol="FOLDER_WATCH",
                        extra_meta=extra,
                        remove_source=True,
                    )
            except Exception as e:
                logger.error("ECG watcher ingest error on %s: %s", norm_p, e)
            finally:
                with self._lock:
                    self._in_progress.discard(norm_p)

        threading.Thread(target=_task, daemon=True).start()

    def sweep_existing(self):
        targets = [(self.watch_dir, self.subfolder_label)]
        if self.nk_enabled and os.path.isdir(self.nk_watch_dir):
            targets.append((self.nk_watch_dir, "nihon_kohden_ecg"))
        for base_d, lbl in targets:
            for root, dirs, files in os.walk(base_d):
                dirs[:] = [d for d in dirs if d.upper() not in ("BIN", "TOOLS")]
                for fn in sorted(files):
                    self.process_file(os.path.join(root, fn), vendor_label=lbl)

    def start(self):
        self.sweep_existing()
        self.observer = Observer()
        self.observer.schedule(
            _EcgEventHandler(lambda p: self.process_file(p, self.subfolder_label)),
            self.watch_dir,
            recursive=True,
        )
        if self.nk_enabled and os.path.isdir(self.nk_watch_dir):
            self.observer.schedule(
                _EcgEventHandler(lambda p: self.process_file(p, "nihon_kohden_ecg")),
                self.nk_watch_dir,
                recursive=True,
            )
        self.observer.start()
        logger.info("Multi-Vendor ECG Watcher active on Fukuda=%s, NihonKohden=%s", self.watch_dir, self.nk_watch_dir)

    def stop(self):
        if self.observer is not None:
            self.observer.stop()
            self.observer.join(timeout=3.0)


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(name)s: %(message)s")
    svc = FukudaEcgWatcherService()
    svc.start()
    try:
        while True:
            time.sleep(3600)
    except KeyboardInterrupt:
        svc.stop()


if __name__ == "__main__":
    main()
