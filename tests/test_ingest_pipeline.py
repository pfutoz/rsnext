import io
import os
import sys
import json
import math
import time
import struct
import ftplib
import tempfile
import unittest

import numpy as np
from PIL import Image, ImageDraw
import pydicom
from pydicom.dataset import Dataset, FileMetaDataset
from pydicom.uid import (
    ExplicitVRLittleEndian,
    JPEGBaseline8Bit,
    SecondaryCaptureImageStorage,
    DigitalXRayImageStorageForPresentation,
    UltrasoundImageStorage,
    VideoEndoscopicImageStorage,
    generate_uid,
)
from pydicom.encaps import encapsulate
from pynetdicom import AE, VerificationPresentationContexts

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import load_config, get_db_conn
from ingest_service.run_all import RsNextIngestSupervisor
from ingest_service.study_filer import simulate_file_routing, rescue_quarantine_item
from migration.rebuild_db_from_meta import rebuild_database_from_volumes
import ecg_render


class BitWriter16BE:
    def __init__(self):
        self.bits = []

    def put_bits(self, val: int, count: int):
        for i in range(count - 1, -1, -1):
            self.bits.append((val >> i) & 1)

    def to_bytes(self) -> bytes:
        while len(self.bits) % 16 != 0:
            self.bits.append(0)
        out = bytearray()
        for w in range(0, len(self.bits), 16):
            word = 0
            for b in range(16):
                word = (word << 1) | self.bits[w + b]
            out.append((word >> 8) & 0xFF)
            out.append(word & 0xFF)
        return bytes(out)


def build_synthetic_fukuda_ecg_bytes(samples_per_lead: int = 500) -> bytes:
    """
    ecg_render.py のブロック構造＋固定ハフマン木と完全互換の 12誘導心電図バイナリを生成する。
    HUFF_ROOT=13:
      delta  0 (node 5): bits '10'  (2 bits)
      delta -1 (node 4): bits '11'  (2 bits)
      delta +1 (node 6): bits '010' (3 bits)
      8-bit signed delta (node 11): '001101' -> wait, let's verify node 11 path or stick to smooth +/-1/0 second-order deltas!
    """
    # 8基本誘導 (I, II, V1..V6) の 2階差分を {-1, 0, +1} だけで滑らかな P-QRS-T 波形として合成
    n_leads = 8
    scales = [0.7, 1.1, -0.5, -0.2, 0.4, 0.9, 1.1, 0.8]
    bw = BitWriter16BE()

    prev2 = 0
    prev1 = 0
    for lead_idx in range(n_leads):
        sc = scales[lead_idx]
        for i in range(samples_per_lead):
            t = (i % 220) / 220.0
            # 目標振幅 (avm_nv=2000 -> 1 unit = 0.002 mV -> R波 500 units = 1.0 mV)
            target_mv = (
                0.12 * math.exp(-((t - 0.18) / 0.035) ** 2)
                - 0.08 * math.exp(-((t - 0.30) / 0.012) ** 2)
                + 0.95 * math.exp(-((t - 0.33) / 0.016) ** 2)
                - 0.20 * math.exp(-((t - 0.36) / 0.015) ** 2)
                + 0.26 * math.exp(-((t - 0.56) / 0.055) ** 2)
            ) * sc
            desired_x = int(round(target_mv / 0.002))
            pred_x = 2 * prev1 - prev2
            raw_delta = desired_x - pred_x
            delta = 1 if raw_delta > 0 else (-1 if raw_delta < 0 else 0)

            if delta == 0:
                bw.put_bits(0b10, 2)     # 13 -(1)-> 14 -(0)-> 5 (delta=0)
            elif delta == -1:
                bw.put_bits(0b11, 2)     # 13 -(1)-> 14 -(1)-> 4 (delta=-1)
            else:
                bw.put_bits(0b010, 3)    # 13 -(0)-> 15 -(1)-> 16 -(0)-> 6 (delta=+1)

            x = pred_x + delta
            prev2, prev1 = prev1, x

    # End marker: 13 -(0)-> 15 -(0)-> 14? Wait: HUFF_TREE[3*13+0]=15, HUFF_TREE[3*15+0]=14? Let's pad extra 0-deltas safely
    for _ in range(16):
        bw.put_bits(0b10, 2)

    stream_bytes = bw.to_bytes()

    # Header (0x00..0x70) + Block 1 (0x40 bytes) + Block 3 Tag 4 (0x60 bytes) + Block 5 Wave
    header = bytearray(0x70)
    struct.pack_into(">H", header, 0x1E, 2)  # num_blocks = 2
    # Entry 0 (0x20): Tag 1, len 0x40
    struct.pack_into(">H", header, 0x20, 1)
    struct.pack_into(">I", header, 0x24, 0x40)
    # Entry 1 (0x30): Tag 4 (Measurements), len 0x60
    struct.pack_into(">H", header, 0x30, 4)
    struct.pack_into(">I", header, 0x34, 0x60)

    b1 = bytearray(0x40)
    b1[0:9] = b"FCP-8800\x00"
    struct.pack_into(">6H", b1, 16, 2026, 11, 17, 9, 45, 12)

    b3 = bytearray(0x60)
    # hr=68, rr=882, pr=156, qrs=88, qt=384, qtc_b=409, qtc_f=400, axis=52
    struct.pack_into(">8h", b3, 0x34, 68, 882, 156, 88, 384, 409, 400, 52)
    struct.pack_into(">8h", b3, 0x44, 0, 0, 400, 0, 0, 0, 0, 0)

    wave_hdr = bytearray(44)
    wave_hdr[0:3] = b"\x00\x09\x02"
    total_wave_len = 44 + len(stream_bytes) + 8
    struct.pack_into(">I", wave_hdr, 4, total_wave_len)
    struct.pack_into(">H", wave_hdr, 10, 2000)              # 500 Hz
    struct.pack_into(">H", wave_hdr, 12, 2000)              # avm_nv = 2000 nV
    struct.pack_into(">I", wave_hdr, 14, samples_per_lead)  # samples_per_lead
    struct.pack_into(">H", wave_hdr, 20, n_leads)           # 8 base leads

    return bytes(header + b1 + b3 + wave_hdr + stream_bytes + b"\x00" * 8)


def make_jpeg_dicom(
    path: str,
    patient_id: str,
    patient_name: str,
    width: int,
    height: int,
    manufacturer: str,
    model_name: str,
    station_name: str,
    modality: str = "ES",
    study_uid: str = None,
    inst_no: int = 1,
    label_text: str = "",
):
    im = Image.new("RGB", (width, height), (14, 18, 26))
    dr = ImageDraw.Draw(im)
    r = min(width, height) // 2 - 30
    cx, cy = width // 2 + 60, height // 2
    dr.ellipse((cx - r, cy - r, cx + r, cy + r), fill=(196, 92, 88), outline=(235, 160, 150), width=4)
    dr.ellipse((cx - 70, cy - 50, cx + 40, cy + 45), fill=(155, 42, 48))
    dr.text((28, 32), f"ID: {patient_id}  {patient_name}", fill=(240, 248, 255))
    dr.text((28, 64), f"{manufacturer} {model_name} ({width}x{height}) #{inst_no}", fill=(125, 211, 252))
    if label_text:
        dr.text((28, 96), label_text, fill=(253, 224, 71))

    buf = io.BytesIO()
    im.save(buf, format="JPEG", quality=90)
    jpg_bytes = buf.getvalue()

    sop_uid = generate_uid()
    fm = FileMetaDataset()
    fm.MediaStorageSOPClassUID = VideoEndoscopicImageStorage
    fm.MediaStorageSOPInstanceUID = sop_uid
    fm.TransferSyntaxUID = JPEGBaseline8Bit

    ds = Dataset()
    ds.file_meta = fm
    ds.SpecificCharacterSet = "ISO_IR 192"
    ds.PatientID = patient_id
    ds.PatientName = patient_name
    ds.PatientSex = "M"
    ds.PatientBirthDate = "19620415"
    ds.StudyDate = time.strftime("%Y%m%d")
    ds.StudyTime = "093015"
    ds.Modality = modality
    ds.Manufacturer = manufacturer
    ds.ManufacturerModelName = model_name
    ds.StationName = station_name
    ds.StudyInstanceUID = study_uid or generate_uid()
    ds.SeriesInstanceUID = generate_uid()
    ds.SOPClassUID = fm.MediaStorageSOPClassUID
    ds.SOPInstanceUID = sop_uid
    ds.InstanceNumber = inst_no
    ds.Rows = height
    ds.Columns = width
    ds.SamplesPerPixel = 3
    ds.PhotometricInterpretation = "YBR_FULL_422"
    ds.PlanarConfiguration = 0
    ds.BitsAllocated = 8
    ds.BitsStored = 8
    ds.HighBit = 7
    ds.PixelRepresentation = 0
    ds.PixelData = encapsulate([jpg_bytes])
    ds["PixelData"].VR = "OB"
    ds["PixelData"].is_undefined_length = True
    ds.preamble = b"\x00" * 128
    ds.save_as(path, enforce_file_format=True)
    return ds


def make_16bit_xray_dicom(path: str, patient_id: str, body_part: str = "CHEST") -> Dataset:
    w, h = 512, 512
    yy, xx = np.mgrid[0:h, 0:w]
    img16 = np.clip(1800 + 1400 * np.sin(xx / 45.0) * np.cos(yy / 55.0), 0, 4095).astype(np.uint16)

    sop_uid = generate_uid()
    fm = FileMetaDataset()
    fm.MediaStorageSOPClassUID = DigitalXRayImageStorageForPresentation
    fm.MediaStorageSOPInstanceUID = sop_uid
    fm.TransferSyntaxUID = ExplicitVRLittleEndian

    ds = Dataset()
    ds.file_meta = fm
    ds.is_little_endian = True
    ds.is_implicit_VR = False
    ds.SpecificCharacterSet = "ISO_IR 192"
    ds.PatientID = patient_id
    ds.PatientName = "SHIMADZU^TEST"
    ds.StudyDate = time.strftime("%Y%m%d")
    ds.StudyTime = "101200"
    ds.Modality = "DX"
    ds.BodyPartExamined = body_part
    ds.StationName = "SHIMADZU-PC"
    ds.Manufacturer = "Canon Inc."
    ds.ManufacturerModelName = "CXDI Controller RF"
    ds.StudyInstanceUID = generate_uid()
    ds.SeriesInstanceUID = generate_uid()
    ds.SOPClassUID = fm.MediaStorageSOPClassUID
    ds.SOPInstanceUID = sop_uid
    ds.InstanceNumber = 1
    ds.Rows = h
    ds.Columns = w
    ds.SamplesPerPixel = 1
    ds.PhotometricInterpretation = "MONOCHROME2"
    ds.BitsAllocated = 16
    ds.BitsStored = 12
    ds.HighBit = 11
    ds.PixelRepresentation = 0
    ds.WindowCenter = 2048
    ds.WindowWidth = 3000
    ds.PixelData = img16.tobytes()
    ds.preamble = b"\x00" * 128
    ds.save_as(path, enforce_file_format=True)
    return ds


def make_rgb_us_dicom(path: str, patient_id: str, protocol_name: str = "Adult_Abd") -> Dataset:
    im = Image.new("RGB", (640, 480), (10, 14, 22))
    dr = ImageDraw.Draw(im)
    dr.pieslice((120, -30, 520, 430), 48, 132, fill=(65, 75, 88), outline=(125, 211, 252))
    dr.text((24, 24), f"ARIETTA 65 US {protocol_name} ID:{patient_id}", fill=(224, 242, 254))
    arr = np.asarray(im, dtype=np.uint8)

    sop_uid = generate_uid()
    fm = FileMetaDataset()
    fm.MediaStorageSOPClassUID = UltrasoundImageStorage
    fm.MediaStorageSOPInstanceUID = sop_uid
    fm.TransferSyntaxUID = ExplicitVRLittleEndian

    ds = Dataset()
    ds.file_meta = fm
    ds.is_little_endian = True
    ds.is_implicit_VR = False
    ds.PatientID = patient_id
    ds.PatientName = "ARIETTA^PATIENT"
    ds.StudyDate = time.strftime("%Y%m%d")
    ds.StudyTime = "104530"
    ds.Modality = "US"
    ds.ProtocolName = protocol_name
    ds.StationName = "AR1"
    ds.Manufacturer = "FUJIFILM Healthcare"
    ds.ManufacturerModelName = "ARIETTA 65"
    ds.StudyInstanceUID = generate_uid()
    ds.SeriesInstanceUID = generate_uid()
    ds.SOPClassUID = fm.MediaStorageSOPClassUID
    ds.SOPInstanceUID = sop_uid
    ds.InstanceNumber = 1
    ds.Rows, ds.Columns, _ = arr.shape
    ds.SamplesPerPixel = 3
    ds.PhotometricInterpretation = "RGB"
    ds.PlanarConfiguration = 0
    ds.BitsAllocated = 8
    ds.BitsStored = 8
    ds.HighBit = 7
    ds.PixelRepresentation = 0
    ds.PixelData = arr.tobytes()
    ds.preamble = b"\x00" * 128
    ds.save_as(path, enforce_file_format=True)
    return ds


class TestRsNextEndToEndPipeline(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.cfg = load_config()
        cls.supervisor = RsNextIngestSupervisor(cls.cfg)
        cls.supervisor.start_all()
        time.sleep(0.6)

    @classmethod
    def tearDownClass(cls):
        cls.supervisor.stop_all()

    def _wait_for_condition(self, fn, timeout: float = 8.0):
        deadline = time.time() + timeout
        while time.time() < deadline:
            val = fn()
            if val:
                return val
            time.sleep(0.2)
        return fn()

    def test_01_mall3_csv_drs_and_drs2(self):
        inbox = self.cfg["storage"]["inbox_root"]
        drs_file = os.path.join(inbox, "drs", "kanja.txt")
        drs2_file = os.path.join(inbox, "drs2", "kanja.txt")

        # 21列 CP932 CSV
        rows_drs = [
            "00070755,ヤマダ タロウ,山田　太郎,S37/04/15,780-0000,高知県高知市1-2-3,男,088-000-1111,090-1111-2222,,国保,,,,,,,,,,",
            "83003,サトウ ハナコ,佐藤　花子,1975/09/20,780-0001,高知県高知市2-3-4,女,088-000-3333,,,社保,,,,,,,,,,",
        ]
        rows_drs2 = [
            "123,スズキ イチロウ,鈴木　一郎,H02/11/03,780-0002,高知県高知市3-4-5,1,088-000-5555,,,後期,,,,,,,,,,",
        ]
        with open(drs_file, "wb") as f:
            f.write(("\r\n".join(rows_drs) + "\r\n").encode("cp932"))
        with open(drs2_file, "wb") as f:
            f.write(("\r\n".join(rows_drs2) + "\r\n").encode("cp932"))

        def check_patients():
            conn = get_db_conn(self.cfg)
            try:
                with conn.cursor() as cur:
                    cur.execute("SELECT patient_id, kanja_kanji, birth_date, sex FROM patients WHERE patient_id IN ('70755', '83003', '900123')")
                    rows = {r["patient_id"]: r for r in cur.fetchall()}
                    return rows if len(rows) == 3 else None
            finally:
                conn.close()

        found = self._wait_for_condition(check_patients)
        self.assertIsNotNone(found, "MALL3 DRS / DRS2 CSV was not ingested in time")
        self.assertEqual(found["70755"]["kanja_kanji"], "山田　太郎")
        self.assertEqual(str(found["70755"]["birth_date"]), "1962-04-15")
        self.assertEqual(found["900123"]["kanja_kanji"], "鈴木　一郎")
        self.assertEqual(found["900123"]["sex"], "男")

    def test_02_ftp_fuji_and_olympus_endoscopy(self):
        ftp_port = int(self.cfg["ingest"]["ftp"]["port"])
        with tempfile.TemporaryDirectory() as td:
            st_uid = str(generate_uid())
            f1 = os.path.join(td, "fuji1.dcm")
            f2 = os.path.join(td, "fuji2.dcm")
            oly = os.path.join(td, "olympus_fhd.dcm")

            make_jpeg_dicom(f1, "70755", "YAMADA^TARO", 1280, 1024, "FUJIFILM Corporation", "EP-8000", "MV3C", study_uid=st_uid, inst_no=1)
            make_jpeg_dicom(f2, "70755", "YAMADA^TARO", 1280, 1024, "FUJIFILM Corporation", "EP-8000", "MV3C", study_uid=st_uid, inst_no=2)
            make_jpeg_dicom(oly, "83003", "SATO^HANAKO", 1920, 1080, "OLYMPUS", "CV-1500", "MV3C", inst_no=1)

            ftp = ftplib.FTP()
            ftp.connect("127.0.0.1", ftp_port, timeout=5)
            ftp.login(self.cfg["ingest"]["ftp"]["username"], self.cfg["ingest"]["ftp"]["password"])
            for idx, src in enumerate((f1, f2, oly), start=1):
                with open(src, "rb") as rf:
                    ftp.storbinary(f"STOR endo_{idx}.dcm", rf)
            ftp.quit()

        def check_endo():
            conn = get_db_conn(self.cfg)
            try:
                with conn.cursor() as cur:
                    cur.execute("SELECT study_id, kensamei, file_count FROM studies WHERE study_instance_uid = %s", (st_uid,))
                    fuji_st = cur.fetchone()
                    cur.execute("SELECT study_id, kensamei FROM studies WHERE patient_id = '83003' AND kensamei = '内視鏡(D)' ORDER BY study_id DESC LIMIT 1")
                    oly_st = cur.fetchone()
                    if fuji_st and fuji_st["file_count"] == 2 and oly_st:
                        return (fuji_st, oly_st)
                    return None
            finally:
                conn.close()

        res = self._wait_for_condition(check_endo)
        self.assertIsNotNone(res, "FTP Endoscopy studies were not filed")
        fuji_st, oly_st = res
        self.assertEqual(fuji_st["kensamei"], "ES_General")
        self.assertEqual(oly_st["kensamei"], "内視鏡(D)")

        # 先行サムネイルキャッシュが生成されていることを確認
        thumb_p = os.path.join(self.cfg["storage"]["cache_root"], "thumbs", str(fuji_st["study_id"]), "0001_thumb.jpg")
        self._wait_for_condition(lambda: os.path.isfile(thumb_p), timeout=4.0)
        self.assertTrue(os.path.isfile(thumb_p))

    def test_03_dicom_scp_shimadzu_dx_and_arietta_us_any_ae(self):
        scp_port = int(self.cfg["ingest"]["dicom_scp"]["port"])
        with tempfile.TemporaryDirectory() as td:
            dx_path = os.path.join(td, "shimadzu_chest.dcm")
            us_path = os.path.join(td, "arietta_us.dcm")
            ds_dx = make_16bit_xray_dicom(dx_path, "70755", "CHEST")
            ds_us = make_rgb_us_dicom(us_path, "83003", "Adult_Abd")

            # 任意の未登録AEタイトルから送信しても100%受理されることを検証
            ae = AE(ae_title="ANY_MODALITY_AE")
            for cx in VerificationPresentationContexts:
                ae.add_requested_context(cx.abstract_syntax)
            ae.add_requested_context(DigitalXRayImageStorageForPresentation, [ExplicitVRLittleEndian])
            ae.add_requested_context(UltrasoundImageStorage, [ExplicitVRLittleEndian])

            assoc = ae.associate("127.0.0.1", scp_port, ae_title="ANY_CALLED_AE")
            self.assertTrue(assoc.is_established, "DICOM Association failed")
            echo_st = assoc.send_c_echo()
            self.assertEqual(echo_st.Status, 0x0000)

            st1 = assoc.send_c_store(ds_dx)
            st2 = assoc.send_c_store(ds_us)
            assoc.release()
            self.assertEqual(st1.Status, 0x0000)
            self.assertEqual(st2.Status, 0x0000)

        def check_scp():
            conn = get_db_conn(self.cfg)
            try:
                with conn.cursor() as cur:
                    cur.execute("SELECT kensamei FROM studies WHERE study_instance_uid = %s", (str(ds_dx.StudyInstanceUID),))
                    r1 = cur.fetchone()
                    cur.execute("SELECT kensamei FROM studies WHERE study_instance_uid = %s", (str(ds_us.StudyInstanceUID),))
                    r2 = cur.fetchone()
                    return (r1, r2) if (r1 and r2) else None
            finally:
                conn.close()

        got = self._wait_for_condition(check_scp)
        self.assertIsNotNone(got)
        self.assertEqual(got[0]["kensamei"], "胸部(D)")
        self.assertEqual(got[1]["kensamei"], "US_Adult_Abd_")

    def test_04_fukuda_ecg_huffman_watcher(self):
        ecg_bytes = build_synthetic_fukuda_ecg_bytes(500)
        inbox_ecg = os.path.join(self.cfg["storage"]["inbox_root"], "fukuda_ecg", "83003(D)~001.ECG")
        with open(inbox_ecg, "wb") as f:
            f.write(ecg_bytes)

        # パーサ単体でも12誘導とHR=68が完全復元されるか検証
        def check_ecg_study():
            conn = get_db_conn(self.cfg)
            try:
                with conn.cursor() as cur:
                    cur.execute("SELECT study_id, kensamei FROM studies WHERE patient_id = '83003' AND primary_file_type = 'ECG' ORDER BY study_id DESC LIMIT 1")
                    return cur.fetchone()
            finally:
                conn.close()

        ecg_st = self._wait_for_condition(check_ecg_study)
        self.assertIsNotNone(ecg_st, "Fukuda ECG file was not ingested")
        self.assertEqual(ecg_st["kensamei"], "心電図(D)")

        ecg_cache = os.path.join(self.cfg["storage"]["cache_root"], "ecg_json", str(ecg_st["study_id"]), "0001.json")
        self._wait_for_condition(lambda: os.path.isfile(ecg_cache), timeout=4.0)
        self.assertTrue(os.path.isfile(ecg_cache))
        with open(ecg_cache, "r", encoding="utf-8") as cf:
            j = json.load(cf)
        self.assertEqual(j["data"]["measurements"]["hr"], 68)
        self.assertEqual(len(j["data"]["leads"]["II"]), 500)

    def test_05_quarantine_and_rescue_and_simulator_and_bcp_rebuild(self):
        ftp_port = int(self.cfg["ingest"]["ftp"]["port"])
        with tempfile.TemporaryDirectory() as td:
            bad_dcm = os.path.join(td, "~NoNumber_bad.dcm")
            make_jpeg_dicom(bad_dcm, "$17077_BARCODE_ERR", "UNKNOWN", 1280, 1024, "FUJIFILM Corporation", "EP-8000", "MV3C")

            # Modality Studio シミュレータのドライラン検証
            sim = simulate_file_routing(bad_dcm)
            self.assertTrue(sim["will_quarantine"])
            self.assertEqual(sim["matched_profile"]["profile_code"], "FUJI_ENDO_EP8000")

            # FTP経由で送信 -> Quarantineへ保護されることを確認
            ftp = ftplib.FTP()
            ftp.connect("127.0.0.1", ftp_port, timeout=5)
            ftp.login(self.cfg["ingest"]["ftp"]["username"], self.cfg["ingest"]["ftp"]["password"])
            with open(bad_dcm, "rb") as rf:
                ftp.storbinary("STOR bad_barcode.dcm", rf)
            ftp.quit()

        def check_quarantine():
            conn = get_db_conn(self.cfg)
            try:
                with conn.cursor() as cur:
                    cur.execute("SELECT quarantine_id FROM quarantine_queue WHERE status = 'PENDING' ORDER BY quarantine_id DESC LIMIT 1")
                    return cur.fetchone()
            finally:
                conn.close()

        q_row = self._wait_for_condition(check_quarantine)
        self.assertIsNotNone(q_row, "Invalid patient ID DICOM was not quarantined")

        # 正しい患者ID (900123) へワンクリック救済実行
        rescue_res = rescue_quarantine_item(q_row["quarantine_id"], "900123", override_kensamei="胃がん検診", doctor="小野院長")
        self.assertEqual(rescue_res["status"], "success")
        self.assertEqual(rescue_res["patient_id"], "900123")

        # BCP自己修復テスト (study_meta.json 走査によるDB整合性再構築)
        bcp_res = rebuild_database_from_volumes()
        self.assertEqual(bcp_res["status"], "success")
        self.assertGreaterEqual(bcp_res["restored_studies"], 5)


if __name__ == "__main__":
    unittest.main(verbosity=2)

