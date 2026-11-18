import os
import sys
import time
import signal
import logging
from datetime import datetime

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import load_config, atomic_write_json
from ingest_service.ftp_receiver import FtpIngestService
from ingest_service.dicom_scp import DicomScpService
from ingest_service.fukuda_ecg_watcher import FukudaEcgWatcherService
from ingest_service.mall3_csv_watcher import Mall3CsvWatcherService
from ingest_service.mall3_sql_poller import Mall3SqlPollerService
from ingest_service.hw_monitor import HardwareMonitorService
from ingest_service.backup_manager import BackupSchedulerService

logger = logging.getLogger("rsnext.supervisor")
STATUS_FILE = os.path.join(BASE_DIR, "tmp", "ingest_service_status.json")


class RsNextIngestSupervisor:
    def __init__(self, cfg: dict = None):
        self.cfg = cfg or load_config()
        self.services = {}
        self._running = False

    def _write_heartbeat(self, state: str = "RUNNING"):
        payload = {
            "state": state,
            "pid": os.getpid(),
            "updated_at": datetime.now().astimezone().isoformat(),
            "channels": {
                "ftp": {
                    "enabled": bool(self.cfg["ingest"]["ftp"].get("enabled", True)),
                    "port": self.cfg["ingest"]["ftp"].get("port"),
                    "active": "ftp" in self.services,
                },
                "dicom_scp": {
                    "enabled": bool(self.cfg["ingest"]["dicom_scp"].get("enabled", True)),
                    "port": self.cfg["ingest"]["dicom_scp"].get("port"),
                    "promiscuous_ae": bool(self.cfg["ingest"]["dicom_scp"].get("promiscuous_ae_title", True)),
                    "active": "dicom_scp" in self.services,
                },
                "fukuda_ecg": {
                    "enabled": bool(self.cfg["ingest"]["fukuda_ecg"].get("enabled", True)),
                    "watch_subdir": self.cfg["ingest"]["fukuda_ecg"].get("watch_subdir"),
                    "active": "fukuda_ecg" in self.services,
                },
                "mall3_csv": {
                    "enabled": bool(self.cfg["ingest"].get("mall3_csv", {}).get("enabled", True)),
                    "drs_subdir": self.cfg["ingest"].get("mall3_csv", {}).get("drs_subdir"),
                    "drs2_subdir": self.cfg["ingest"].get("mall3_csv", {}).get("drs2_subdir"),
                    "active": "mall3_csv" in self.services,
                },
                "mall3_sql": {
                    "enabled": bool(self.cfg["ingest"].get("mall3_sql", {}).get("enabled", True)),
                    "poll_interval_sec": self.cfg["ingest"].get("mall3_sql", {}).get("poll_interval_sec", 60),
                    "active": "mall3_sql" in self.services,
                    "last_status": getattr(self.services.get("mall3_sql"), "last_status", ""),
                },
            },
        }
        try:
            atomic_write_json(STATUS_FILE, payload)
        except Exception:
            pass

    def start_all(self):
        ing = self.cfg["ingest"]
        if ing.get("ftp", {}).get("enabled", True):
            ftp_svc = FtpIngestService(self.cfg)
            ftp_svc.start()
            self.services["ftp"] = ftp_svc

        if ing.get("dicom_scp", {}).get("enabled", True):
            scp_svc = DicomScpService(self.cfg)
            scp_svc.start()
            self.services["dicom_scp"] = scp_svc

        if ing.get("fukuda_ecg", {}).get("enabled", True):
            ecg_svc = FukudaEcgWatcherService(self.cfg)
            ecg_svc.start()
            self.services["fukuda_ecg"] = ecg_svc

        if ing.get("mall3_csv", {}).get("enabled", True):
            csv_svc = Mall3CsvWatcherService(self.cfg)
            csv_svc.start()
            self.services["mall3_csv"] = csv_svc

        if ing.get("mall3_sql", {}).get("sources"):
            sql_svc = Mall3SqlPollerService(self.cfg)
            sql_svc.start()
            self.services["mall3_sql"] = sql_svc

        hw_svc = HardwareMonitorService(self.cfg)
        hw_svc.start()
        self.services["hw_monitor"] = hw_svc

        bk_svc = BackupSchedulerService(self.cfg)
        bk_svc.start()
        self.services["backup_scheduler"] = bk_svc

        self._running = True
        self._write_heartbeat("RUNNING")
        logger.info("RS-Next Ingest Supervisor started all active channels: %s", list(self.services.keys()))

    def stop_all(self):
        self._running = False
        for name, svc in list(self.services.items()):
            try:
                svc.stop()
                logger.info("Stopped %s", name)
            except Exception as e:
                logger.warning("Error stopping %s: %s", name, e)
        self.services.clear()
        self._write_heartbeat("STOPPED")

    def serve_forever(self):
        self.start_all()

        def _sig_handler(signum, frame):
            self._running = False

        signal.signal(signal.SIGINT, _sig_handler)
        signal.signal(signal.SIGTERM, _sig_handler)

        try:
            while self._running:
                self._write_heartbeat("RUNNING")
                time.sleep(5.0)
        finally:
            self.stop_all()


def main():
    os.makedirs(os.path.join(BASE_DIR, "tmp"), exist_ok=True)
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    )
    sup = RsNextIngestSupervisor()
    sup.serve_forever()


if __name__ == "__main__":
    main()

