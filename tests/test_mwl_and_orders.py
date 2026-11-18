import os
import sys
import json
import time
import ftplib
import tempfile
import unittest
import subprocess

from pydicom.dataset import Dataset
from pydicom.sequence import Sequence
from pydicom.uid import (
    ExplicitVRLittleEndian,
    generate_uid,
)
from pynetdicom import AE
from pynetdicom.sop_class import (
    ModalityWorklistInformationFind,
    ModalityPerformedProcedureStep,
)

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import load_config, get_db_conn
from ingest_service.run_all import RsNextIngestSupervisor
from ingest_service.mwl_scp import query_mwl_scu_test
from tests.test_ingest_pipeline import make_jpeg_dicom


class TestMwlAndOrderWorkflow(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # 既存のバックグラウンドサービスがあれば一時停止してからテスト用インスタンスを起動
        subprocess.run(
            ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", os.path.join(BASE_DIR, "stop_ingest_service.ps1")],
            capture_output=True,
        )
        time.sleep(0.5)
        cls.cfg = load_config()
        cls.supervisor = RsNextIngestSupervisor(cls.cfg)
        cls.supervisor.start_all()
        time.sleep(0.6)

        # テスト用患者 (70755 山田太郎 / 83003 佐藤花子 / 異体字テスト 88001 髙橋﨑太郎) を確実に用意
        conn = get_db_conn(cls.cfg)
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO patients (patient_id, kanja_kanji, kanja_yomi, kanja_yomi_norm, birth_date, sex, source_channel)
                    VALUES
                      ('70755', '山田　太郎', 'ヤマダ タロウ', 'ヤマダタロウ', '1962-04-15', '男', 'DRS'),
                      ('83003', '佐藤　花子', 'サトウ ハナコ', 'サトウハナコ', '1975-09-20', '女', 'DRS'),
                      ('88001', '髙橋　﨑太郎', 'タカハシ サキタロウ', 'タカハシサキタロウ', '1980-01-10', '男', 'DRS')
                    ON CONFLICT (patient_id) DO UPDATE SET
                      kanja_kanji = EXCLUDED.kanja_kanji,
                      kanja_yomi  = EXCLUDED.kanja_yomi
                    """
                )
                cur.execute("DELETE FROM modality_orders WHERE patient_id IN ('70755', '83003', '88001') AND scheduled_date = CURRENT_DATE")
            conn.commit()
        finally:
            conn.close()

    @classmethod
    def tearDownClass(cls):
        cls.supervisor.stop_all()

    def _call_php_post_json(self, script_name: str, payload: dict) -> dict:
        php_code = (
            f"chdir({json.dumps(BASE_DIR)}); "
            f"$_SERVER['REQUEST_METHOD'] = 'POST'; "
            f"$_POST = json_decode({json.dumps(json.dumps(payload, ensure_ascii=False))}, true); "
            f"include {json.dumps(script_name)};"
        )
        proc = subprocess.run([r"C:\php\php.exe", "-r", php_code], capture_output=True)
        stdout_text = proc.stdout.decode("utf-8", errors="replace").strip()
        return json.loads(stdout_text)

    def test_01_create_orders_and_mwl_cfind_japanese_jis(self):
        # 1. PHP API 経由で 70755 (山田太郎) に「胸部レントゲン」＋「胃がん検診(内視鏡)」、88001 (髙橋﨑太郎) に「腹部エコー」を発行
        r1 = self._call_php_post_json(
            "worklist_api.php",
            {
                "action": "create_orders",
                "patient_id": "70755",
                "preset_codes": ["DX_CHEST", "ES_GANKENSIN"],
                "requesting_doctor": "小野院長",
                "order_comment": "検診セットオーダー",
            },
        )
        self.assertEqual(r1["status"], "success")
        self.assertEqual(r1["count"], 2)

        r2 = self._call_php_post_json(
            "worklist_api.php",
            {
                "action": "create_orders",
                "patient_id": "88001",
                "preset_codes": ["US_ABDOMEN"],
                "requesting_doctor": "小野院長",
            },
        )
        self.assertEqual(r2["status"], "success")

        # 2. 島津CXDI想定で Modality=CR (DXとの相互ヒット確認) の DICOM MWL C-FIND 照会を実行
        mwl_dx = query_mwl_scu_test(calling_aet="SHIMADZU-PC", modality="CR", sched_date=time.strftime("%Y%m%d"))
        self.assertEqual(mwl_dx["status"], "success")
        self.assertGreaterEqual(mwl_dx["count"], 1)
        hit = next(x for x in mwl_dx["items"] if x["patient_id"] == "70755")
        self.assertEqual(hit["patient_name_dicom"], "YAMADA^TARO=山田^太郎=ヤマダ^タロウ")
        self.assertEqual(hit["sex"], "M")
        self.assertEqual(hit["birth_date"], "19620415")
        self.assertEqual(hit["procedure_desc"], "胸部正面 PA")

        # 3. JIS外異体字 (髙橋 﨑太郎) もエラーなく高橋 崎太郎へ安全正規化されてMWL応答されるか確認
        mwl_us = query_mwl_scu_test(calling_aet="AR1", modality="US", patient_id="88001")
        self.assertEqual(mwl_us["status"], "success")
        self.assertEqual(mwl_us["count"], 1)
        self.assertEqual(mwl_us["items"][0]["patient_name_dicom"], "TAKAHASHI^SAKITARO=高橋^崎太郎=タカハシ^サキタロウ")

        # 4. CSV Outbox が自動出力されていることを確認
        csv_path = os.path.join(self.cfg["ingest"]["dicom_scp"]["orders_outbox_dir"], "orders_today_cp932.csv")
        self.assertTrue(os.path.isfile(csv_path))

    def test_02_smart_id_lookup_fallback_without_prior_order(self):
        # 事前オーダーが1件も出ていない 83003 (佐藤 花子) を装置側で PatientID 指定 C-FIND 検索
        mwl_id = query_mwl_scu_test(calling_aet="AR1", modality="US", patient_id="83003")
        self.assertEqual(mwl_id["status"], "success")
        self.assertEqual(mwl_id["count"], 1)
        self.assertEqual(mwl_id["items"][0]["patient_name_dicom"], "SATO^HANAKO=佐藤^花子=サトウ^ハナコ")
        self.assertEqual(mwl_id["items"][0]["sex"], "F")

    def test_03_mpps_in_progress_and_completed(self):
        # 70755 の DX オーダーの AccessionNumber を取得
        mwl_dx = query_mwl_scu_test(modality="DX", patient_id="70755")
        order_item = mwl_dx["items"][0]
        acc_no = order_item["accession_number"]
        st_uid = order_item["study_instance_uid"]

        port = int(self.cfg["ingest"]["dicom_scp"]["port"])
        ae = AE(ae_title="SHIMADZU-PC")
        ae.add_requested_context(ModalityPerformedProcedureStep, [ExplicitVRLittleEndian])
        assoc = ae.associate("127.0.0.1", port, ae_title="RSNEXT_SCP")
        self.assertTrue(assoc.is_established)

        mpps_uid = generate_uid()
        attr = Dataset()
        attr.PatientID = "70755"
        attr.Modality = "DX"
        attr.PerformedProcedureStepStatus = "IN PROGRESS"
        ssa = Dataset()
        ssa.AccessionNumber = acc_no
        ssa.StudyInstanceUID = st_uid
        attr.ScheduledStepAttributesSequence = Sequence([ssa])

        # N-CREATE (撮影開始)
        st_create, _ = assoc.send_n_create(attr, ModalityPerformedProcedureStep, mpps_uid)
        self.assertEqual(st_create.Status, 0x0000)

        conn = get_db_conn(self.cfg)
        try:
            with conn.cursor() as cur:
                cur.execute("SELECT status FROM modality_orders WHERE accession_number = %s", (acc_no,))
                self.assertEqual(cur.fetchone()["status"], "IN_PROGRESS")
        finally:
            conn.close()

        # N-SET (撮影完了)
        mod_ds = Dataset()
        mod_ds.PerformedProcedureStepStatus = "COMPLETED"
        st_set, _ = assoc.send_n_set(mod_ds, ModalityPerformedProcedureStep, mpps_uid)
        assoc.release()
        self.assertEqual(st_set.Status, 0x0000)

        conn = get_db_conn(self.cfg)
        try:
            with conn.cursor() as cur:
                cur.execute("SELECT status FROM modality_orders WHERE accession_number = %s", (acc_no,))
                self.assertEqual(cur.fetchone()["status"], "COMPLETED")
        finally:
            conn.close()

    def test_04_ftp_ingest_auto_inherits_order_kensamei_and_completes(self):
        # フジ内視鏡 EP-8000 は単体では ES_General になるが、事前に「胃がん検診」オーダーがあるため自動で「胃がん検診」になり完了消し込みされるか検証
        ftp_port = int(self.cfg["ingest"]["ftp"]["port"])
        with tempfile.TemporaryDirectory() as td:
            dcm_p = os.path.join(td, "fuji_screen.dcm")
            ds = make_jpeg_dicom(dcm_p, "70755", "YAMADA^TARO", 1280, 1024, "FUJIFILM Corporation", "EP-8000", "MV3C", inst_no=1)

            ftp = ftplib.FTP()
            ftp.connect("127.0.0.1", ftp_port, timeout=5)
            ftp.login(self.cfg["ingest"]["ftp"]["username"], self.cfg["ingest"]["ftp"]["password"])
            with open(dcm_p, "rb") as rf:
                ftp.storbinary("STOR fuji_gankensin.dcm", rf)
            ftp.quit()

        deadline = time.time() + 8.0
        matched = None
        while time.time() < deadline:
            conn = get_db_conn(self.cfg)
            try:
                with conn.cursor() as cur:
                    cur.execute("SELECT study_id, kensamei, doctor FROM studies WHERE study_instance_uid = %s", (str(ds.StudyInstanceUID),))
                    st = cur.fetchone()
                    if st:
                        cur.execute(
                            "SELECT status, linked_study_id FROM modality_orders WHERE patient_id = '70755' AND preset_code = 'ES_GANKENSIN' ORDER BY order_id DESC LIMIT 1"
                        )
                        ord_r = cur.fetchone()
                        matched = (st, ord_r)
                        break
            finally:
                conn.close()
            time.sleep(0.2)

        self.assertIsNotNone(matched, "FTP image was not filed")
        st_row, ord_row = matched
        self.assertEqual(st_row["kensamei"], "胃がん検診")
        self.assertEqual(st_row["doctor"], "小野院長")
        self.assertEqual(ord_row["status"], "COMPLETED")
        self.assertEqual(ord_row["linked_study_id"], st_row["study_id"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
