import os
import sys
import uuid
import queue
import logging
import threading

from pydicom.uid import (
    ImplicitVRLittleEndian,
    ExplicitVRLittleEndian,
    ExplicitVRBigEndian,
    DeflatedExplicitVRLittleEndian,
    JPEGBaseline8Bit,
    JPEGExtended12Bit,
    JPEGLossless,
    JPEGLosslessSV1,
    JPEGLSLossless,
    JPEGLSNearLossless,
    JPEG2000Lossless,
    JPEG2000,
    RLELossless,
)
from pynetdicom import AE, evt, AllStoragePresentationContexts, VerificationPresentationContexts
from pynetdicom.sop_class import (
    ModalityWorklistInformationFind,
    ModalityPerformedProcedureStep,
)

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import load_config
from ingest_service.study_filer import ingest_single_file
from ingest_service.mwl_scp import handle_mwl_find, handle_mpps_create, handle_mpps_set

logger = logging.getLogger("rsnext.dicom_scp")

ALL_TRANSFER_SYNTAXES = [
    ImplicitVRLittleEndian,
    ExplicitVRLittleEndian,
    DeflatedExplicitVRLittleEndian,
    ExplicitVRBigEndian,
    JPEGBaseline8Bit,
    JPEGExtended12Bit,
    JPEGLossless,
    JPEGLosslessSV1,
    JPEGLSLossless,
    JPEGLSNearLossless,
    JPEG2000Lossless,
    JPEG2000,
    RLELossless,
]


class DicomScpService:
    def __init__(self, cfg: dict = None):
        self.cfg = cfg or load_config()
        self.scp_cfg = self.cfg["ingest"]["dicom_scp"]
        self.inbox_dir = os.path.join(self.cfg["storage"]["inbox_root"], "dicom_scp")
        os.makedirs(self.inbox_dir, exist_ok=True)

        self.q = queue.Queue()
        self._stop_event = threading.Event()
        self.ae: AE | None = None
        self.scp_server = None
        self.worker_thread: threading.Thread | None = None

    def _handle_echo(self, event):
        return 0x0000

    def _handle_store(self, event):
        try:
            ds = event.dataset
            ds.file_meta = event.file_meta

            req = event.assoc.requestor
            source_ip = getattr(req, "address", "") or ""
            calling_ae = getattr(req, "ae_title", "") or ""
            if isinstance(calling_ae, bytes):
                calling_ae = calling_ae.decode("ascii", errors="ignore")
            calling_ae = str(calling_ae).strip()

            sop_uid = str(getattr(ds, "SOPInstanceUID", "") or uuid.uuid4().hex).strip()
            safe_uid = "".join(ch if ch.isalnum() or ch in "._-" else "_" for ch in sop_uid)[:90]
            tmp_file = os.path.join(self.inbox_dir, f"cstore_{safe_uid}_{uuid.uuid4().hex[:6]}.dcm")
            try:
                ds.save_as(tmp_file, enforce_file_format=True)
            except TypeError:
                ds.save_as(tmp_file, write_like_original=False)

            self.q.put((tmp_file, source_ip, calling_ae))
            return 0x0000
        except Exception as e:
            logger.error("C-STORE receive error: %s", e)
            return 0xC210

    def _worker_loop(self):
        while not self._stop_event.is_set():
            try:
                filepath, source_ip, calling_ae = self.q.get(timeout=0.5)
            except queue.Empty:
                continue
            try:
                if os.path.isfile(filepath):
                    ingest_single_file(
                        filepath,
                        source_protocol="DICOM_SCP",
                        extra_meta={
                            "source_ip": source_ip,
                            "calling_ae_title": calling_ae,
                            "orig_filename": os.path.basename(filepath),
                        },
                        remove_source=True,
                    )
            except Exception as e:
                logger.error("DICOM SCP worker error on %s: %s", filepath, e)
            finally:
                self.q.task_done()

    def start(self):
        self.ae = AE(ae_title="RSNEXT_SCP")
        if self.scp_cfg.get("promiscuous_ae_title", True):
            self.ae.require_called_aet = False
            self.ae.require_calling_aet = []

        for cx in VerificationPresentationContexts:
            self.ae.add_supported_context(cx.abstract_syntax, ALL_TRANSFER_SYNTAXES)

        seen_syntaxes = set()
        for cx in AllStoragePresentationContexts:
            if cx.abstract_syntax not in seen_syntaxes:
                seen_syntaxes.add(cx.abstract_syntax)
                self.ae.add_supported_context(cx.abstract_syntax, ALL_TRANSFER_SYNTAXES)

        handlers = [
            (evt.EVT_C_ECHO, self._handle_echo),
            (evt.EVT_C_STORE, self._handle_store),
        ]

        if self.scp_cfg.get("mwl_enabled", True):
            self.ae.add_supported_context(ModalityWorklistInformationFind, [ImplicitVRLittleEndian, ExplicitVRLittleEndian])
            handlers.append((evt.EVT_C_FIND, lambda event: handle_mwl_find(event, self.cfg)))

        if self.scp_cfg.get("mpps_enabled", True):
            self.ae.add_supported_context(ModalityPerformedProcedureStep, [ImplicitVRLittleEndian, ExplicitVRLittleEndian])
            handlers.append((evt.EVT_N_CREATE, lambda event: handle_mpps_create(event, self.cfg)))
            handlers.append((evt.EVT_N_SET, lambda event: handle_mpps_set(event, self.cfg)))

        self.worker_thread = threading.Thread(target=self._worker_loop, name="DICOM-Filer-Worker", daemon=True)
        self.worker_thread.start()

        bind_host = self.scp_cfg.get("bind_host", "0.0.0.0")
        port = int(self.scp_cfg.get("port", 11104))
        self.scp_server = self.ae.start_server((bind_host, port), block=False, evt_handlers=handlers)
        logger.info("DICOM Storage SCP listening on %s:%d (promiscuous AE=%s)", bind_host, port, self.scp_cfg.get("promiscuous_ae_title", True))

    def stop(self):
        self._stop_event.set()
        if self.scp_server is not None:
            try:
                self.scp_server.shutdown()
            except Exception:
                pass
        if self.worker_thread is not None:
            self.worker_thread.join(timeout=3.0)


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(name)s: %(message)s")
    svc = DicomScpService()
    svc.start()
    try:
        while True:
            threading.Event().wait(3600)
    except KeyboardInterrupt:
        svc.stop()


if __name__ == "__main__":
    main()

