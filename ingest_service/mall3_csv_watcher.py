import os
import re
import csv
import io
import sys
import json
import time
import logging
import threading
from datetime import date
from watchdog.observers import Observer
from watchdog.events import FileSystemEventHandler

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import (
    load_config,
    get_db_conn,
    normalize_patient_id,
    normalize_kana_yomi,
    log_ingest_event,
)

logger = logging.getLogger("rsnext.mall3_csv")


def parse_mall3_birth_date(raw: str) -> date | None:
    if not raw:
        return None
    s = str(raw).strip()
    if not s:
        return None

    # 和暦形式 (例: S50/04/12, H03.11.05, R02-05-01, 昭和50年4月12日)
    era_offsets = {
        "M": 1867, "明": 1867, "明治": 1867,
        "T": 1911, "大": 1911, "大正": 1911,
        "S": 1925, "昭": 1925, "昭和": 1925,
        "H": 1988, "平": 1988, "平成": 1988,
        "R": 2018, "令": 2018, "令和": 2018,
    }
    m_era = re.match(r"^(明治|大正|昭和|平成|令和|[MTSHR明大昭平令])\s*([0-9]+|元)[/\.\-年]\s*([0-9]{1,2})[/\.\-月]\s*([0-9]{1,2})", s, re.IGNORECASE)
    if m_era:
        era_key = m_era.group(1).upper()
        yr_str = m_era.group(2)
        yr_num = 1 if yr_str == "元" else int(yr_str)
        y = era_offsets[era_key] + yr_num
        try:
            return date(y, int(m_era.group(3)), int(m_era.group(4)))
        except ValueError:
            return None

    # 西暦区切り形式 (YYYY/MM/DD, YYYY-MM-DD, YYYY.MM.DD)
    m_iso = re.match(r"^([12][0-9]{3})[/\.\-]([0-9]{1,2})[/\.\-]([0-9]{1,2})", s)
    if m_iso:
        try:
            return date(int(m_iso.group(1)), int(m_iso.group(2)), int(m_iso.group(3)))
        except ValueError:
            return None

    # 8桁数字 (YYYYMMDD)
    digits = re.sub(r"[^0-9]", "", s)
    if len(digits) == 8:
        try:
            return date(int(digits[0:4]), int(digits[4:6]), int(digits[6:8]))
        except ValueError:
            return None
    return None


def decode_mall3_bytes(raw_bytes: bytes) -> str:
    # UTF-8 BOM または厳密な UTF-8 として読める場合は UTF-8、それ以外は標準 MALL3 の CP932 でデコード
    if raw_bytes.startswith(b"\xef\xbb\xbf"):
        return raw_bytes.decode("utf-8-sig", errors="replace")
    try:
        return raw_bytes.decode("utf-8", errors="strict")
    except UnicodeDecodeError:
        return raw_bytes.decode("cp932", errors="replace")


def normalize_sex_label(raw_sex: str) -> str:
    s = (raw_sex or "").strip()
    if s in ("1", "M", "m", "男", "男性"):
        return "男"
    if s in ("2", "F", "f", "女", "女性"):
        return "女"
    return s


def process_mall3_csv_file(filepath: str, is_drs2: bool = False, remove_after: bool = True) -> dict:
    """
    MALL3 DRS / DRS2 21列CSV (kanja.txt) を読み取り patients テーブルへ一括 UPSERT する。
    列定義:
      [0]患者ID, [1]カナ氏名, [2]漢字氏名, [3]生年月日, [4]郵便番号, [5]住所,
      [6]性別, [7]電話1, [8]電話2, [9]転帰/職業, [10..14]保険1..5, [15..19]公費1..5, [20]予備
    """
    if not os.path.isfile(filepath):
        return {"status": "error", "message": f"CSV not found: {filepath}"}

    cfg = load_config()
    apply_nine = is_drs2 and bool(cfg["ingest"]["mall3_csv"].get("drs2_prefix_nine", True))
    channel_name = "DRS2" if is_drs2 else "DRS"

    with open(filepath, "rb") as f:
        raw_bytes = f.read()

    text = decode_mall3_bytes(raw_bytes)
    reader = csv.reader(io.StringIO(text))

    upserted = 0
    skipped = 0
    conn = get_db_conn(cfg)
    try:
        with conn.cursor() as cur:
            for row in reader:
                if not row or all(not c.strip() for c in row):
                    continue
                raw_id = row[0].strip()
                if raw_id.lower() in ("id", "patient_id", "患者番号", "患者id"):
                    continue

                norm_id, _ = normalize_patient_id(raw_id, is_drs2=apply_nine)
                if not norm_id:
                    skipped += 1
                    continue

                def col(idx: int) -> str:
                    return row[idx].strip() if idx < len(row) else ""

                yomi = col(1)
                kanji = col(2) or yomi or f"ID:{norm_id}"
                birth_raw = col(3)
                birth_dt = parse_mall3_birth_date(birth_raw)
                postal = col(4)
                addr = col(5)
                sex = normalize_sex_label(col(6))
                phone1 = col(7)
                phone2 = col(8)
                death_or_job = col(9)

                ins_info = {
                    "hoken": [col(i) for i in range(10, 15) if col(i)],
                    "kohi": [col(i) for i in range(15, 20) if col(i)],
                    "extra": col(20),
                }
                yomi_norm = normalize_kana_yomi(yomi)

                cur.execute(
                    """
                    INSERT INTO patients
                    (patient_id, kanja_kanji, kanja_yomi, kanja_yomi_norm, birth_date, birth_raw,
                     sex, postal_code, address, phone1, phone2, death_or_job, insurance_info, source_channel, updated_at)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
                    ON CONFLICT (patient_id) DO UPDATE SET
                        kanja_kanji     = EXCLUDED.kanja_kanji,
                        kanja_yomi      = EXCLUDED.kanja_yomi,
                        kanja_yomi_norm = EXCLUDED.kanja_yomi_norm,
                        birth_date      = COALESCE(EXCLUDED.birth_date, patients.birth_date),
                        birth_raw       = CASE WHEN EXCLUDED.birth_raw <> '' THEN EXCLUDED.birth_raw ELSE patients.birth_raw END,
                        sex             = CASE WHEN EXCLUDED.sex <> '' THEN EXCLUDED.sex ELSE patients.sex END,
                        postal_code     = CASE WHEN EXCLUDED.postal_code <> '' THEN EXCLUDED.postal_code ELSE patients.postal_code END,
                        address         = CASE WHEN EXCLUDED.address <> '' THEN EXCLUDED.address ELSE patients.address END,
                        phone1          = CASE WHEN EXCLUDED.phone1 <> '' THEN EXCLUDED.phone1 ELSE patients.phone1 END,
                        phone2          = CASE WHEN EXCLUDED.phone2 <> '' THEN EXCLUDED.phone2 ELSE patients.phone2 END,
                        death_or_job    = CASE WHEN EXCLUDED.death_or_job <> '' THEN EXCLUDED.death_or_job ELSE patients.death_or_job END,
                        insurance_info  = EXCLUDED.insurance_info,
                        source_channel  = EXCLUDED.source_channel,
                        updated_at      = NOW()
                    """,
                    (
                        norm_id,
                        kanji,
                        yomi,
                        yomi_norm,
                        birth_dt,
                        birth_raw,
                        sex,
                        postal,
                        addr,
                        phone1,
                        phone2,
                        death_or_job,
                        json.dumps(ins_info, ensure_ascii=False),
                        channel_name,
                    ),
                )
                upserted += 1

        log_ingest_event(
            conn,
            level="INFO",
            channel=f"MALL3_{channel_name}",
            event_type="PATIENT_CSV_SYNC",
            summary=f"MALL3 ({channel_name}) 患者マスタ更新完了: {upserted}名反映 (スキップ {skipped}件)",
            detail={"file": os.path.basename(filepath), "upserted": upserted, "skipped": skipped},
        )
        conn.commit()
    except Exception as e:
        conn.rollback()
        raise e
    finally:
        conn.close()

    if remove_after and os.path.exists(filepath):
        try:
            os.remove(filepath)
        except OSError:
            pass

    return {"status": "success", "channel": channel_name, "upserted": upserted, "skipped": skipped}


class _CsvHandler(FileSystemEventHandler):
    def __init__(self, callback, is_drs2: bool):
        super().__init__()
        self.callback = callback
        self.is_drs2 = is_drs2

    def on_created(self, event):
        if not event.is_directory:
            self.callback(event.src_path, self.is_drs2)

    def on_modified(self, event):
        if not event.is_directory:
            self.callback(event.src_path, self.is_drs2)

    def on_moved(self, event):
        if not event.is_directory:
            self.callback(event.dest_path, self.is_drs2)


class Mall3CsvWatcherService:
    def __init__(self, cfg: dict = None):
        self.cfg = cfg or load_config()
        m_cfg = self.cfg["ingest"]["mall3_csv"]
        inbox = self.cfg["storage"]["inbox_root"]

        drs1 = m_cfg.get("drs_subdir") or "drs"
        drs2 = m_cfg.get("drs2_subdir") or "drs2"
        self.drs1_dir = drs1 if os.path.isabs(drs1) else os.path.join(inbox, drs1)
        self.drs2_dir = drs2 if os.path.isabs(drs2) else os.path.join(inbox, drs2)
        os.makedirs(self.drs1_dir, exist_ok=True)
        os.makedirs(self.drs2_dir, exist_ok=True)

        self._lock = threading.Lock()
        self._busy = set()
        self.observer: Observer | None = None

    def _on_csv(self, filepath: str, is_drs2: bool):
        ext = os.path.splitext(filepath)[1].lower().lstrip(".")
        if ext not in ("txt", "csv"):
            return
        norm_p = os.path.abspath(filepath)
        with self._lock:
            if norm_p in self._busy:
                return
            self._busy.add(norm_p)

        def _run():
            try:
                time.sleep(0.25)
                if os.path.isfile(norm_p) and os.path.getsize(norm_p) > 0:
                    process_mall3_csv_file(norm_p, is_drs2=is_drs2, remove_after=True)
            except Exception as e:
                logger.error("MALL3 CSV error on %s: %s", norm_p, e)
            finally:
                with self._lock:
                    self._busy.discard(norm_p)

        threading.Thread(target=_run, daemon=True).start()

    def sweep_existing(self):
        for d, flag in ((self.drs1_dir, False), (self.drs2_dir, True)):
            for fn in sorted(os.listdir(d)):
                fp = os.path.join(d, fn)
                if os.path.isfile(fp):
                    self._on_csv(fp, flag)

    def start(self):
        self.sweep_existing()
        self.observer = Observer()
        self.observer.schedule(_CsvHandler(self._on_csv, False), self.drs1_dir, recursive=False)
        self.observer.schedule(_CsvHandler(self._on_csv, True), self.drs2_dir, recursive=False)
        self.observer.start()
        logger.info("MALL3 CSV Watcher active on DRS=%s, DRS2=%s", self.drs1_dir, self.drs2_dir)

    def stop(self):
        if self.observer is not None:
            self.observer.stop()
            self.observer.join(timeout=3.0)


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(name)s: %(message)s")
    svc = Mall3CsvWatcherService()
    svc.start()
    try:
        while True:
            time.sleep(3600)
    except KeyboardInterrupt:
        svc.stop()


if __name__ == "__main__":
    main()

