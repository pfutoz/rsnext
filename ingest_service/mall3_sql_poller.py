import os
import re
import sys
import json
import time
import logging
import argparse
import threading
from datetime import datetime, timedelta, date

import pymssql

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import (
    CONFIG_PATH,
    load_config,
    get_db_conn,
    normalize_kana_yomi,
    atomic_write_json,
    log_ingest_event,
)

logger = logging.getLogger("rsnext.mall3_sql")
WATERMARK_FILE = os.path.join(BASE_DIR, "tmp", "mall3_sql_watermark.json")

BLOCKED_SQL_RE = re.compile(
    r"\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|MERGE|EXEC|EXECUTE)\b",
    re.IGNORECASE,
)


def _assert_readonly_sql(sql: str) -> None:
    if BLOCKED_SQL_RE.search(sql):
        raise RuntimeError("【Read-Only保護】MALL3 SQL Serverへの変更クエリは禁止されています。")


def format_mall3_patient_id(patient_no: int, prefix_nine: bool = False) -> str | None:
    if patient_no is None or int(patient_no) <= 0:
        return None
    n = int(patient_no)
    if prefix_nine:
        return f"9{n:05d}"
    return str(n)


def format_mall3_sex(sex_val) -> str:
    try:
        code = int(sex_val or 0)
    except (ValueError, TypeError):
        return ""
    if code == 1:
        return "男"
    if code == 2:
        return "女"
    return ""


def clean_phone(val) -> str:
    s = str(val or "").strip()
    if s in ("-", "ー", "―", "0"):
        return ""
    return s[:64]


def transform_mall3_row(r: dict, source_code: str, prefix_nine: bool) -> tuple | None:
    pno = r.get("PatientNo")
    pid = format_mall3_patient_id(pno, prefix_nine=prefix_nine)
    if not pid:
        return None

    kanji = str(r.get("PatientName") or "").strip()
    yomi = str(r.get("PatientKana") or "").strip()
    yomi_norm = normalize_kana_yomi(yomi)

    bday = r.get("BirthDay")
    birth_dt: date | None = None
    birth_raw = ""
    if isinstance(bday, datetime):
        if bday.year >= 1870:
            birth_dt = bday.date()
            birth_raw = birth_dt.strftime("%Y/%m/%d")
    elif isinstance(bday, date):
        if bday.year >= 1870:
            birth_dt = bday
            birth_raw = birth_dt.strftime("%Y/%m/%d")

    sex = format_mall3_sex(r.get("Sex"))
    zip_code = str(r.get("Zip") or "").strip()[:20]
    add1 = str(r.get("Add1") or "").strip()
    add2 = str(r.get("Add2") or "").strip()
    address = f"{add1} {add2}".strip() if (add1 and add2) else (add1 or add2)

    tel1 = clean_phone(r.get("Tel"))
    tel2 = clean_phone(r.get("CarryTel"))
    if not tel1 and tel2:
        tel1, tel2 = tel2, ""

    death_flag = "死亡" if int(r.get("DeathFlag") or 0) == 1 else ""

    return (
        pid,
        kanji,
        yomi,
        yomi_norm,
        birth_dt,
        birth_raw,
        sex,
        zip_code,
        address,
        tel1,
        tel2,
        death_flag,
        source_code,
    )


UPSERT_SQL = """
INSERT INTO patients (
    patient_id, kanja_kanji, kanja_yomi, kanja_yomi_norm,
    birth_date, birth_raw, sex, postal_code, address,
    phone1, phone2, death_or_job, source_channel, updated_at
)
VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
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
    source_channel  = EXCLUDED.source_channel,
    updated_at      = NOW()
WHERE
    patients.kanja_kanji     IS DISTINCT FROM EXCLUDED.kanja_kanji OR
    patients.kanja_yomi      IS DISTINCT FROM EXCLUDED.kanja_yomi OR
    patients.kanja_yomi_norm IS DISTINCT FROM EXCLUDED.kanja_yomi_norm OR
    patients.birth_date      IS DISTINCT FROM COALESCE(EXCLUDED.birth_date, patients.birth_date) OR
    patients.sex             IS DISTINCT FROM (CASE WHEN EXCLUDED.sex <> '' THEN EXCLUDED.sex ELSE patients.sex END) OR
    patients.postal_code     IS DISTINCT FROM (CASE WHEN EXCLUDED.postal_code <> '' THEN EXCLUDED.postal_code ELSE patients.postal_code END) OR
    patients.address         IS DISTINCT FROM (CASE WHEN EXCLUDED.address <> '' THEN EXCLUDED.address ELSE patients.address END) OR
    patients.phone1          IS DISTINCT FROM (CASE WHEN EXCLUDED.phone1 <> '' THEN EXCLUDED.phone1 ELSE patients.phone1 END) OR
    patients.phone2          IS DISTINCT FROM (CASE WHEN EXCLUDED.phone2 <> '' THEN EXCLUDED.phone2 ELSE patients.phone2 END) OR
    patients.death_or_job    IS DISTINCT FROM (CASE WHEN EXCLUDED.death_or_job <> '' THEN EXCLUDED.death_or_job ELSE patients.death_or_job END)
"""


def load_watermarks() -> dict:
    if os.path.isfile(WATERMARK_FILE):
        try:
            with open(WATERMARK_FILE, "r", encoding="utf-8") as f:
                return json.load(f)
        except Exception:
            pass
    return {"sources": {}}


def save_watermarks(wm: dict) -> None:
    os.makedirs(os.path.dirname(WATERMARK_FILE), exist_ok=True)
    atomic_write_json(WATERMARK_FILE, wm)


def resolve_source_interval(src: dict, sql_cfg: dict) -> int:
    default_sec = int(sql_cfg.get("poll_interval_sec", 60) or 60)
    raw = src.get("poll_interval_sec", default_sec)
    try:
        val = int(raw)
    except (ValueError, TypeError):
        val = default_sec
    return max(5, min(86400, val))


def sync_single_mall3_source(cfg: dict, src: dict, since_iso: str | None = None, batch_size: int = 4000) -> dict:
    code = str(src["code"])
    host = str(src["host"])
    port = int(src.get("port", 1433))
    dbname = str(src.get("dbname", "his00"))
    user = str(src["user"])
    password = str(src["password"])
    prefix_nine = bool(src.get("prefix_nine", False))

    ms_conn = pymssql.connect(
        server=host,
        port=port,
        user=user,
        password=password,
        database=dbname,
        charset="UTF-8",
        login_timeout=4,
        timeout=60,
    )
    try:
        mcur = ms_conn.cursor(as_dict=True)
        mcur.execute("SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;")

        mcur.execute("SELECT CONVERT(varchar(23), GETDATE(), 121) AS ServerNow;")
        server_now_str = str(mcur.fetchone()["ServerNow"])

        base_select = """
            SELECT p.PatientID, p.PatientNo, p.PatientName, p.PatientKana, p.Sex, p.BirthDay,
                   p.DeathFlag, p.InsertDateTime, p.LastUpdateTime,
                   pa.Zip, pa.Add1, pa.Add2, pa.Tel, pa.CarryTel
            FROM dbo.M_Patient p WITH (NOLOCK)
            OUTER APPLY (
                SELECT TOP 1 Zip, Add1, Add2, Tel, CarryTel
                FROM dbo.M_PatientAdd WITH (NOLOCK)
                WHERE PatientID = p.PatientID AND ISNULL(StatusFlag, 0) = 0
                ORDER BY AddRank ASC, PatientAddID ASC
            ) pa
            WHERE ISNULL(p.StatusFlag, 0) = 0 AND p.PatientNo > 0
        """

        if since_iso:
            # 3分の安全オーバーラップを設けて時計ズレやトランザクション前後を取りこぼさない
            try:
                dt_since = datetime.fromisoformat(since_iso.replace(" ", "T")) - timedelta(minutes=3)
                query_since = dt_since.strftime("%Y-%m-%d %H:%M:%S")
            except ValueError:
                query_since = since_iso

            sql = base_select + """
                AND (
                    p.LastUpdateTime >= %s
                    OR p.InsertDateTime >= %s
                    OR EXISTS (
                        SELECT 1 FROM dbo.M_PatientAdd pa2 WITH (NOLOCK)
                        WHERE pa2.PatientID = p.PatientID
                          AND ISNULL(pa2.StatusFlag, 0) = 0
                          AND pa2.LastUpdateTime >= %s
                    )
                )
                ORDER BY p.PatientNo ASC
            """
            _assert_readonly_sql(sql)
            mcur.execute(sql, (query_since, query_since, query_since))
        else:
            sql = base_select + " ORDER BY p.PatientNo ASC"
            _assert_readonly_sql(sql)
            mcur.execute(sql)

        pg_conn = get_db_conn(cfg)
        scanned = 0
        changed = 0
        sample_pids = []
        try:
            while True:
                rows = mcur.fetchmany(batch_size)
                if not rows:
                    break
                params_batch = []
                for r in rows:
                    scanned += 1
                    tup = transform_mall3_row(r, code, prefix_nine)
                    if tup is not None:
                        params_batch.append(tup)
                        if len(sample_pids) < 5:
                            sample_pids.append(f"{tup[0]}({tup[1]})")

                if params_batch:
                    with pg_conn.cursor() as pcur:
                        if since_iso is None:
                            pcur.executemany(UPSERT_SQL, params_batch)
                            changed += len(params_batch)
                        else:
                            for tup in params_batch:
                                pcur.execute(UPSERT_SQL, tup)
                                if pcur.rowcount > 0:
                                    changed += pcur.rowcount
                    pg_conn.commit()

            if changed > 0:
                mode_label = "増分巡回" if since_iso else "初回全件"
                log_ingest_event(
                    pg_conn,
                    "INFO",
                    "MALL3_SQL",
                    "PATIENT_SYNC",
                    f"MALL3 ({code}) {mode_label}同期: {changed} 名の新規・変更を反映 (走査 {scanned} 件)",
                    detail={"source": code, "host": host, "scanned": scanned, "changed": changed, "samples": sample_pids},
                )
                pg_conn.commit()
        finally:
            pg_conn.close()

        return {
            "source": code,
            "host": host,
            "mode": "delta" if since_iso else "full",
            "scanned": scanned,
            "changed": changed,
            "watermark": server_now_str,
        }
    finally:
        ms_conn.close()


def run_mall3_sync_cycle(
    cfg: dict,
    force_full: bool = False,
    only_source: str | None = None,
    ignore_enabled: bool = False,
) -> dict:
    sql_cfg = cfg.get("ingest", {}).get("mall3_sql", {})
    sources = sql_cfg.get("sources") or []
    wm = load_watermarks()
    results = []

    for src in sources:
        code = str(src["code"])
        if only_source and code != only_source:
            continue
        is_src_enabled = bool(src.get("enabled", True))
        if not ignore_enabled and not is_src_enabled:
            continue

        prev_entry = wm.get("sources", {}).get(code, {})
        prev_ts = None if force_full else prev_entry.get("last_watermark")
        t0 = time.perf_counter()
        res = sync_single_mall3_source(cfg, src, since_iso=prev_ts)
        res["elapsed_ms"] = int(round((time.perf_counter() - t0) * 1000))
        interval_sec = resolve_source_interval(src, sql_cfg)
        wm.setdefault("sources", {})[code] = {
            "label": str(src.get("label") or code),
            "host": str(src.get("host") or ""),
            "prefix_nine": bool(src.get("prefix_nine", False)),
            "enabled": is_src_enabled,
            "effective_active": bool(sql_cfg.get("enabled", True)) and is_src_enabled,
            "poll_interval_sec": interval_sec,
            "last_watermark": res["watermark"],
            "last_sync_at": datetime.now().astimezone().isoformat(),
            "next_poll_at": str(prev_entry.get("next_poll_at") or ""),
            "last_scanned": res["scanned"],
            "last_changed": res["changed"],
            "last_elapsed_ms": res["elapsed_ms"],
            "last_error": "",
        }
        save_watermarks(wm)
        results.append(res)

    return {"status": "success", "results": results}


class Mall3SqlPollerService:
    """
    カルテサーバ別 (192.168.1.77 新カルテ / 192.168.1.52 おのカルテ) に独立した
    巡回秒数・開始/停止設定で動作し、server_config.json の変更を1秒以内に即時ホットリロードする常駐スレッド
    """

    def __init__(self, cfg: dict):
        self.cfg = cfg
        self._cfg_mtime = 0.0
        self._stop_evt = threading.Event()
        self._thread: threading.Thread | None = None
        self._next_due: dict[str, float] = {}
        self._prev_intervals: dict[str, int] = {}
        self._prev_enabled: dict[str, bool] = {}
        self.last_status = "RUNNING"

    def _reload_cfg_if_needed(self) -> None:
        try:
            st = os.stat(CONFIG_PATH)
            if st.st_mtime != self._cfg_mtime:
                self.cfg = load_config()
                self._cfg_mtime = st.st_mtime
                sql_cfg = self.cfg.get("ingest", {}).get("mall3_sql", {})
                master_on = bool(sql_cfg.get("enabled", True))
                wm = load_watermarks()
                wm["master_enabled"] = master_on
                now = time.time()
                for src in (sql_cfg.get("sources") or []):
                    code = str(src["code"])
                    iv = resolve_source_interval(src, sql_cfg)
                    en = bool(src.get("enabled", True)) and master_on
                    # 初回起動時・停止→開始への切替時・秒数変更時のスケジュール再計算
                    if code not in self._next_due:
                        self._next_due[code] = now + 2.0
                    elif self._prev_enabled.get(code) is False and en:
                        self._next_due[code] = now  # 再開時は即時1回実行
                    elif self._prev_intervals.get(code) != iv:
                        self._next_due[code] = now + iv

                    self._prev_intervals[code] = iv
                    self._prev_enabled[code] = en

                    entry = wm.setdefault("sources", {}).setdefault(code, {})
                    entry["label"] = str(src.get("label") or code)
                    entry["host"] = str(src.get("host") or "")
                    entry["prefix_nine"] = bool(src.get("prefix_nine", False))
                    entry["enabled"] = bool(src.get("enabled", True))
                    entry["effective_active"] = en
                    entry["poll_interval_sec"] = iv
                    entry["next_poll_at"] = (
                        datetime.fromtimestamp(self._next_due[code]).astimezone().isoformat() if en else ""
                    )
                save_watermarks(wm)
                logger.info("MALL3 SQL Poller reloaded config (master=%s)", master_on)
        except Exception as e:
            logger.warning("MALL3 config reload check failed: %s", e)

    def start(self):
        if self._thread and self._thread.is_alive():
            return
        self._stop_evt.clear()
        self._thread = threading.Thread(target=self._loop, name="Mall3SqlPoller", daemon=True)
        self._thread.start()
        logger.info("MALL3 SQL Poller started (per-server independent scheduling + hot-reload)")

    def stop(self):
        self._stop_evt.set()
        if self._thread and self._thread.is_alive():
            self._thread.join(timeout=5.0)

    def _loop(self):
        while not self._stop_evt.is_set():
            self._reload_cfg_if_needed()
            sql_cfg = self.cfg.get("ingest", {}).get("mall3_sql", {})
            master_on = bool(sql_cfg.get("enabled", True))
            now = time.time()

            if master_on:
                for src in (sql_cfg.get("sources") or []):
                    if self._stop_evt.is_set():
                        break
                    code = str(src["code"])
                    iv = resolve_source_interval(src, sql_cfg)
                    en = bool(src.get("enabled", True))
                    if not en:
                        continue
                    due = self._next_due.get(code, now)
                    if now >= due:
                        try:
                            res = run_mall3_sync_cycle(self.cfg, force_full=False, only_source=code, ignore_enabled=True)
                            chg = sum(r["changed"] for r in res["results"])
                            self._next_due[code] = time.time() + iv
                            wm = load_watermarks()
                            entry = wm.setdefault("sources", {}).setdefault(code, {})
                            entry["effective_active"] = True
                            entry["next_poll_at"] = datetime.fromtimestamp(self._next_due[code]).astimezone().isoformat()
                            save_watermarks(wm)
                            self.last_status = f"{code}:+{chg} ({datetime.now().strftime('%H:%M:%S')})"
                        except Exception as e:
                            self._next_due[code] = time.time() + iv
                            wm = load_watermarks()
                            entry = wm.setdefault("sources", {}).setdefault(code, {})
                            entry["last_error"] = str(e)
                            entry["next_poll_at"] = datetime.fromtimestamp(self._next_due[code]).astimezone().isoformat()
                            save_watermarks(wm)
                            self.last_status = f"{code}:ERR"
                            logger.warning("MALL3 SQL Poller [%s] warning: %s", code, e)
            else:
                self.last_status = "PAUSED_ALL"

            self._stop_evt.wait(1.0)


def main():
    parser = argparse.ArgumentParser(description="MALL3 SQL Server 完全 Read-Only (NOLOCK) 患者マスタ自動同期")
    parser.add_argument("--full", action="store_true", help="ウォーターマークを無視して全件初期同期を実行")
    parser.add_argument("--source", type=str, default="", help="特定ソースコードのみ同期 (例: MALL3_NEW / MALL3_ONO)")
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
    cfg = load_config()
    only_src = args.source.strip() or None
    out = run_mall3_sync_cycle(cfg, force_full=args.full, only_source=only_src, ignore_enabled=True)
    print(json.dumps(out, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
