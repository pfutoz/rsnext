import os
import sys
import queue
import logging
import threading

from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import FTPHandler
from pyftpdlib.servers import ThreadedFTPServer

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import load_config
from ingest_service.study_filer import ingest_single_file

logger = logging.getLogger("rsnext.ftp")


class RsNextFTPHandler(FTPHandler):
    ingest_queue: queue.Queue = None

    def on_file_received(self, file: str):
        remote_ip = getattr(self, "remote_ip", "") or ""
        if self.ingest_queue is not None:
            self.ingest_queue.put((file, remote_ip))
        else:
            try:
                ingest_single_file(
                    file,
                    source_protocol="FTP",
                    extra_meta={"source_ip": remote_ip, "orig_filename": os.path.basename(file)},
                    remove_source=True,
                )
            except Exception as e:
                logger.error("FTP ingest error for %s: %s", file, e)

    def on_incomplete_file_received(self, file: str):
        # 通信切断による不完全ファイルは自動破棄し、壊れたDICOMの混入を防止
        try:
            if os.path.exists(file):
                os.remove(file)
        except Exception:
            pass


class FtpIngestService:
    def __init__(self, cfg: dict = None):
        self.cfg = cfg or load_config()
        self.ftp_cfg = self.cfg["ingest"]["ftp"]
        self.inbox_dir = os.path.join(self.cfg["storage"]["inbox_root"], "ftp")
        os.makedirs(self.inbox_dir, exist_ok=True)

        self.q = queue.Queue()
        self._stop_event = threading.Event()
        self.server: ThreadedFTPServer | None = None
        self.server_thread: threading.Thread | None = None
        self.worker_thread: threading.Thread | None = None

    def _worker_loop(self):
        while not self._stop_event.is_set():
            try:
                filepath, remote_ip = self.q.get(timeout=0.5)
            except queue.Empty:
                continue
            try:
                if os.path.isfile(filepath):
                    ingest_single_file(
                        filepath,
                        source_protocol="FTP",
                        extra_meta={"source_ip": remote_ip, "orig_filename": os.path.basename(filepath)},
                        remove_source=True,
                    )
            except Exception as e:
                logger.error("FTP worker failed on %s: %s", filepath, e)
            finally:
                self.q.task_done()

    def start(self):
        authorizer = DummyAuthorizer()
        user = self.ftp_cfg.get("username") or "esuser"
        pw = self.ftp_cfg.get("password") or "esuser"
        authorizer.add_user(user, pw, self.inbox_dir, perm="elradfmwMT")
        # 日本光電 心電計標準FTPアカウント (CEX / CEX) も標準受理
        nk_users = self.ftp_cfg.get("extra_users") or [{"username": "CEX", "password": "CEX"}, {"username": "cex", "password": "cex"}]
        for eu in nk_users:
            eu_name = str(eu.get("username") or "").strip()
            eu_pw = str(eu.get("password") or "").strip()
            if eu_name and eu_name != user and not authorizer.has_user(eu_name):
                authorizer.add_user(eu_name, eu_pw, self.inbox_dir, perm="elradfmwMT")
        if self.ftp_cfg.get("allow_anonymous", True):
            authorizer.add_anonymous(self.inbox_dir, perm="elradfmwMT")

        handler_cls = type("BoundRsNextFTPHandler", (RsNextFTPHandler,), {})
        handler_cls.authorizer = authorizer
        handler_cls.banner = self.ftp_cfg.get("banner") or "220 ES Server (RS-Next Compatible)"
        handler_cls.passive_ports = range(60000, 60100)
        handler_cls.ingest_queue = self.q

        bind_host = self.ftp_cfg.get("bind_host", "0.0.0.0")
        port = int(self.ftp_cfg.get("port", 2121))
        self.server = ThreadedFTPServer((bind_host, port), handler_cls)

        self.worker_thread = threading.Thread(target=self._worker_loop, name="FTP-Filer-Worker", daemon=True)
        self.worker_thread.start()

        self.server_thread = threading.Thread(
            target=self.server.serve_forever, kwargs={"timeout": 0.5, "blocking": True}, name="FTP-Server", daemon=True
        )
        self.server_thread.start()
        logger.info("FTP Receiver listening on %s:%d -> %s", bind_host, port, self.inbox_dir)

    def stop(self):
        self._stop_event.set()
        if self.server is not None:
            try:
                self.server.close_all()
            except Exception:
                pass
        if self.server_thread is not None:
            self.server_thread.join(timeout=3.0)
        if self.worker_thread is not None:
            self.worker_thread.join(timeout=3.0)


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(name)s: %(message)s")
    svc = FtpIngestService()
    svc.start()
    try:
        while True:
            threading.Event().wait(3600)
    except KeyboardInterrupt:
        svc.stop()


if __name__ == "__main__":
    main()

