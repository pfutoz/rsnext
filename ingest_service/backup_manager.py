import os
import re
import sys
import json
import glob
import time
import shutil
import logging
import argparse
import threading
import subprocess
from datetime import datetime, timedelta

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import CONFIG_PATH, load_config, get_db_conn, atomic_write_json, log_ingest_event

logger = logging.getLogger("rsnext.backup")

BACKUP_LIVE_FILE = os.path.join(BASE_DIR, "tmp", "backup_live_status.json")
BACKUP_HISTORY_FILE = os.path.join(BASE_DIR, "tmp", "backup_history.json")
BACKUP_QUEUE_FILE = os.path.join(BASE_DIR, "tmp", "backup_trigger_queue.json")
BACKUP_STOP_FLAG = os.path.join(BASE_DIR, "tmp", "backup_stop.flag")

DEFAULT_JOBS = [
    {
        "code": "MIRROR_ACTIVE",
        "label": "現役SSD 高速ミラー同期 (E: ➔ G:)",
        "description": "本院の現役検査データ領域を別物理NVMe SSDへリアルタイム複製",
        "enabled": True,
        "schedule_type": "interval",
        "interval_min": 60,
        "daily_time": "00:15",
    },
    {
        "code": "MIRROR_ARCHIVE",
        "label": "過去アーカイブSSD ミラー同期 (D: ➔ F:)",
        "description": "過去アーカイブ領域を別物理NVMe SSDへ日次ミラー複製",
        "enabled": True,
        "schedule_type": "daily",
        "interval_min": 1440,
        "daily_time": "01:30",
    },
    {
        "code": "DB_DUMP_HDD",
        "label": "PostgreSQL DB世代ダンプ ＆ 設定保護 (➔ H:)",
        "description": "rsnext_db 圧縮ダンプ作成 ＋ pg_restore 整合性自動テスト ＋ 世代保持",
        "enabled": True,
        "schedule_type": "daily",
        "interval_min": 1440,
        "daily_time": "22:45",
        "retention_days": 30,
        "verify_after_dump": True,
    },
    {
        "code": "FULL_HDD_SYNC",
        "label": "8TB バックアップHDD 全体同期 (SSD ➔ H:)",
        "description": "別筐体メディア (8TB SATA HDD) への第3世代フルミラー保存",
        "enabled": True,
        "schedule_type": "daily",
        "interval_min": 1440,
        "daily_time": "23:00",
        "include_archive_on_hdd": True,
    },
]


def resolve_backup_config(cfg: dict) -> dict:
    raw = cfg.get("backup_manager") or {}
    master_on = bool(raw.get("master_enabled", True))
    raw_jobs = {str(j.get("code")): j for j in (raw.get("jobs") or []) if j.get("code")}
    merged_jobs = []
    for dj in DEFAULT_JOBS:
        c = dj["code"]
        ov = raw_jobs.get(c, {})
        item = dict(dj)
        if "enabled" in ov:
            item["enabled"] = bool(ov["enabled"])
        if "schedule_type" in ov and ov["schedule_type"] in ("interval", "daily"):
            item["schedule_type"] = ov["schedule_type"]
        if "interval_min" in ov:
            item["interval_min"] = max(5, min(10080, int(ov["interval_min"])))
        if "daily_time" in ov and re.match(r"^\d{2}:\d{2}$", str(ov["daily_time"])):
            item["daily_time"] = str(ov["daily_time"])
        if "retention_days" in ov:
            item["retention_days"] = max(3, min(3650, int(ov["retention_days"])))
        if "include_archive_on_hdd" in ov:
            item["include_archive_on_hdd"] = bool(ov["include_archive_on_hdd"])
        if "verify_after_dump" in ov:
            item["verify_after_dump"] = bool(ov["verify_after_dump"])
        merged_jobs.append(item)
    return {"master_enabled": master_on, "jobs": merged_jobs}


def find_pg_tool(exe_name: str) -> str:
    for ver in ("18", "17", "16", "15"):
        cand = rf"C:\Program Files\PostgreSQL\{ver}\bin\{exe_name}"
        if os.path.isfile(cand):
            return cand
    found = shutil.which(exe_name)
    return found or exe_name


def verify_pg_dump_file(dump_path: str) -> dict:
    if not os.path.isfile(dump_path):
        return {"ok": False, "toc_entries": 0, "error": "ダンプファイルが存在しません"}
    pg_restore = find_pg_tool("pg_restore.exe")
    try:
        p = subprocess.run(
            [pg_restore, "--list", dump_path],
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=30,
        )
        lines = [ln for ln in p.stdout.splitlines() if ln.strip() and not ln.strip().startswith(";")]
        if p.returncode == 0 and len(lines) > 0:
            return {"ok": True, "toc_entries": len(lines), "error": ""}
        return {"ok": False, "toc_entries": len(lines), "error": (p.stderr or "pg_restore 検証エラー").strip()[:200]}
    except Exception as e:
        return {"ok": False, "toc_entries": 0, "error": str(e)}


def list_db_dumps(cfg: dict) -> list[dict]:
    bk_root = str(cfg.get("storage", {}).get("backup_hdd_root") or "")
    dump_dir = os.path.join(bk_root, "db_dumps") if bk_root else ""
    if not dump_dir or not os.path.isdir(dump_dir):
        return []
    out = []
    for fp in sorted(glob.glob(os.path.join(dump_dir, "rsnext_db_*.dump")), reverse=True)[:60]:
        try:
            st = os.stat(fp)
            meta_fp = fp + ".meta.json"
            verified = None
            toc_cnt = 0
            if os.path.isfile(meta_fp):
                try:
                    with open(meta_fp, "r", encoding="utf-8") as mf:
                        md = json.load(mf)
                    verified = md.get("verified")
                    toc_cnt = int(md.get("toc_entries") or 0)
                except Exception:
                    pass
            out.append(
                {
                    "filename": os.path.basename(fp),
                    "created_at": datetime.fromtimestamp(st.st_mtime).astimezone().isoformat(),
                    "size_bytes": int(st.st_size),
                    "verified": verified,
                    "toc_entries": toc_cnt,
                }
            )
        except OSError:
            continue
    return out


def inspect_storage_pairs(cfg: dict) -> list[dict]:
    vols = cfg.get("storage", {}).get("volumes", {})
    bk_root = str(cfg.get("storage", {}).get("backup_hdd_root") or "")
    pairs = []
    for vid, vcfg in vols.items():
        src = str(vcfg.get("path") or "")
        mir = str(vcfg.get("mirror_path") or "")
        src_exists = os.path.isdir(src)
        mir_exists = os.path.isdir(mir)
        src_free = shutil.disk_usage(src).free if src_exists else 0
        src_used = shutil.disk_usage(src).used if src_exists else 0
        mir_free = shutil.disk_usage(mir).free if mir_exists else 0
        mir_used = shutil.disk_usage(mir).used if mir_exists else 0
        pairs.append(
            {
                "volume_id": vid,
                "label": str(vcfg.get("label") or vid),
                "source_path": src,
                "mirror_path": mir,
                "source_online": src_exists,
                "mirror_online": mir_exists,
                "source_used_bytes": src_used,
                "source_free_bytes": src_free,
                "mirror_used_bytes": mir_used,
                "mirror_free_bytes": mir_free,
            }
        )
    if bk_root:
        bk_exists = os.path.isdir(bk_root)
        du = shutil.disk_usage(bk_root) if bk_exists else None
        pairs.append(
            {
                "volume_id": "BACKUP_HDD",
                "label": "第3世代 夜間バックアップHDD (8TB)",
                "source_path": "rsnext_db + SSDデータ",
                "mirror_path": bk_root,
                "source_online": True,
                "mirror_online": bk_exists,
                "source_used_bytes": 0,
                "source_free_bytes": 0,
                "mirror_used_bytes": du.used if du else 0,
                "mirror_free_bytes": du.free if du else 0,
            }
        )
    return pairs


def _run_robocopy_mirror(src_dir: str, dst_dir: str, progress_cb=None) -> dict:
    if not os.path.isdir(src_dir):
        raise RuntimeError(f"コピー元フォルダが存在しません: {src_dir}")
    os.makedirs(dst_dir, exist_ok=True)

    cmd = [
        "robocopy.exe",
        src_dir,
        dst_dir,
        "/MIR",
        "/FFT",
        "/R:2",
        "/W:2",
        "/MT:16",
        "/NP",
        "/NFL",
        "/NDL",
    ]
    proc = subprocess.Popen(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        encoding="cp932",
        errors="replace",
    )
    out_chunks = []
    t0 = time.time()
    stopped = False
    while True:
        if os.path.isfile(BACKUP_STOP_FLAG):
            proc.terminate()
            stopped = True
            break
        try:
            out, _ = proc.communicate(timeout=1.0)
            if out:
                out_chunks.append(out)
            break
        except subprocess.TimeoutExpired:
            if progress_cb:
                progress_cb(f"Robocopy 同期中: {src_dir} ➔ {dst_dir} ({int(time.time() - t0)}秒経過)")
            continue

    if stopped:
        try:
            proc.wait(timeout=3.0)
        except Exception:
            proc.kill()
        return {"status": "STOPPED", "exit_code": -1, "summary": "ユーザー操作により安全停止しました", "tail": ""}

    full_out = "".join(out_chunks)
    rc = proc.returncode if proc.returncode is not None else 0
    # Robocopy: 0..7 = 正常 (0=変更なし, 1=コピー成功, 2=Extra削除, 3=コピー+削除), >=8 = エラー
    ok = 0 <= rc < 8

    # ファイル行・バイト行の抽出試行
    tail_lines = [ln.strip() for ln in full_out.splitlines() if ln.strip()][-8:]
    return {
        "status": "SUCCESS" if ok else "ERROR",
        "exit_code": rc,
        "changed": rc in (1, 2, 3, 5, 6, 7),
        "summary": f"Robocopy 完了 (code={rc}: {'変更反映済' if rc > 0 else '完全一致・変更なし'})" if ok else f"Robocopy エラー (code={rc})",
        "tail": "\n".join(tail_lines),
    }


def execute_single_backup_job(cfg: dict, job: dict, trigger: str = "SCHEDULED", progress_cb=None) -> dict:
    code = job["code"]
    label = job["label"]
    t0 = time.perf_counter()
    started_iso = datetime.now().astimezone().isoformat()
    vols = cfg.get("storage", {}).get("volumes", {})
    bk_root = str(cfg.get("storage", {}).get("backup_hdd_root") or "")

    try:
        if code == "MIRROR_ACTIVE":
            v = vols.get("VOL_ACTIVE") or {}
            res = _run_robocopy_mirror(str(v.get("path") or ""), str(v.get("mirror_path") or ""), progress_cb=progress_cb)
            status = res["status"]
            summary = f"E:➔G: {res['summary']}"
            detail_log = res["tail"]

        elif code == "MIRROR_ARCHIVE":
            v = vols.get("VOL_ARCHIVE") or {}
            res = _run_robocopy_mirror(str(v.get("path") or ""), str(v.get("mirror_path") or ""), progress_cb=progress_cb)
            status = res["status"]
            summary = f"D:➔F: {res['summary']}"
            detail_log = res["tail"]

        elif code == "DB_DUMP_HDD":
            if not bk_root:
                raise RuntimeError("backup_hdd_root が未設定です")
            dump_dir = os.path.join(bk_root, "db_dumps")
            cfg_bk_dir = os.path.join(bk_root, "config_snapshots")
            os.makedirs(dump_dir, exist_ok=True)
            os.makedirs(cfg_bk_dir, exist_ok=True)

            ts = datetime.now().strftime("%Y%m%d_%H%M%S")
            dump_file = os.path.join(dump_dir, f"rsnext_db_{ts}.dump")
            cfg_snap = os.path.join(cfg_bk_dir, f"server_config_{ts}.json")
            if os.path.isfile(CONFIG_PATH):
                shutil.copy2(CONFIG_PATH, cfg_snap)

            db_cfg = cfg["db"]
            pg_dump = find_pg_tool("pg_dump.exe")
            env = os.environ.copy()
            env["PGPASSWORD"] = str(db_cfg.get("password") or "")

            if progress_cb:
                progress_cb("PostgreSQL rsnext_db 圧縮ダンプを実行中...")

            p = subprocess.run(
                [
                    pg_dump,
                    "-h", str(db_cfg.get("host") or "127.0.0.1"),
                    "-p", str(db_cfg.get("port") or 5432),
                    "-U", str(db_cfg.get("user") or "postgres"),
                    "-Fc",
                    str(db_cfg.get("dbname") or "rsnext_db"),
                    "-f", dump_file,
                ],
                env=env,
                capture_output=True,
                text=True,
                encoding="utf-8",
                errors="replace",
                timeout=600,
            )
            if p.returncode != 0 or not os.path.isfile(dump_file):
                raise RuntimeError(f"pg_dump 失敗: {(p.stderr or p.stdout).strip()[:240]}")

            dump_bytes = os.path.getsize(dump_file)
            ver_ok = True
            toc_cnt = 0
            if job.get("verify_after_dump", True):
                if progress_cb:
                    progress_cb("pg_restore --list によるダンプ整合性検証を実行中...")
                vres = verify_pg_dump_file(dump_file)
                ver_ok = vres["ok"]
                toc_cnt = vres["toc_entries"]
                atomic_write_json(
                    dump_file + ".meta.json",
                    {
                        "verified": ver_ok,
                        "toc_entries": toc_cnt,
                        "verified_at": datetime.now().astimezone().isoformat(),
                        "trigger": trigger,
                    },
                )
                if not ver_ok:
                    raise RuntimeError(f"ダンプ検証失敗: {vres['error']}")

            # 世代ローテーション (最低直近3世代は必ず残す安全設計)
            ret_days = int(job.get("retention_days") or 30)
            cutoff_ts = time.time() - (ret_days * 86400)
            all_dumps = sorted(glob.glob(os.path.join(dump_dir, "rsnext_db_*.dump")), reverse=True)
            purged = 0
            for old_fp in all_dumps[3:]:
                try:
                    if os.path.getmtime(old_fp) < cutoff_ts:
                        os.remove(old_fp)
                        if os.path.isfile(old_fp + ".meta.json"):
                            os.remove(old_fp + ".meta.json")
                        purged += 1
                except OSError:
                    pass

            size_mb = round(dump_bytes / (1024 * 1024), 2)
            status = "SUCCESS"
            summary = f"DBダンプ完了: {os.path.basename(dump_file)} ({size_mb} MB / TOC {toc_cnt}項目検証OK" + (f" / 古世代{purged}件整理)" if purged else ")")
            detail_log = f"File: {dump_file} ({dump_bytes} bytes), pg_restore verified={ver_ok}"

        elif code == "FULL_HDD_SYNC":
            if not bk_root:
                raise RuntimeError("backup_hdd_root が未設定です")
            os.makedirs(bk_root, exist_ok=True)
            parts = []
            tails = []
            status = "SUCCESS"

            v_act = vols.get("VOL_ACTIVE") or {}
            if v_act.get("path") and os.path.isdir(v_act["path"]):
                r1 = _run_robocopy_mirror(v_act["path"], os.path.join(bk_root, "vol_active"), progress_cb=progress_cb)
                parts.append(f"現役(E:➔H:):code={r1['exit_code']}")
                tails.append(r1["tail"])
                if r1["status"] != "SUCCESS":
                    status = r1["status"]

            if status == "SUCCESS" and job.get("include_archive_on_hdd", True):
                v_arc = vols.get("VOL_ARCHIVE") or {}
                if v_arc.get("path") and os.path.isdir(v_arc["path"]):
                    r2 = _run_robocopy_mirror(v_arc["path"], os.path.join(bk_root, "vol_archive"), progress_cb=progress_cb)
                    parts.append(f"過去(D:➔H:):code={r2['exit_code']}")
                    tails.append(r2["tail"])
                    if r2["status"] != "SUCCESS":
                        status = r2["status"]

            summary = "8TB HDD同期完了 (" + " / ".join(parts) + ")"
            detail_log = "\n---\n".join(t for t in tails if t)

        else:
            raise RuntimeError(f"未知のバックアップジョブ: {code}")

    except Exception as e:
        status = "ERROR"
        summary = f"エラー: {e}"
        detail_log = str(e)

    dur_sec = round(time.perf_counter() - t0, 2)
    rec = {
        "job_code": code,
        "job_label": label,
        "trigger": trigger,
        "started_at": started_iso,
        "finished_at": datetime.now().astimezone().isoformat(),
        "duration_sec": dur_sec,
        "status": status,
        "summary": summary,
        "detail_log": detail_log[-1500:] if detail_log else "",
    }

    # 履歴ファイルへ追記 (最新200件保持)
    hist = []
    if os.path.isfile(BACKUP_HISTORY_FILE):
        try:
            with open(BACKUP_HISTORY_FILE, "r", encoding="utf-8") as f:
                hist = json.load(f)
        except Exception:
            hist = []
    hist.insert(0, rec)
    atomic_write_json(BACKUP_HISTORY_FILE, hist[:200])

    # DB監査ログへ記録
    try:
        conn = get_db_conn(cfg)
        try:
            lv = "INFO" if status == "SUCCESS" else ("WARN" if status == "STOPPED" else "ERROR")
            log_ingest_event(conn, lv, "BACKUP_MGR", f"BACKUP_{status}", f"[{label}] {summary} ({dur_sec}s)")
            conn.commit()
        finally:
            conn.close()
    except Exception:
        pass

    return rec


def compute_next_daily_ts(hhmm: str, now_dt: datetime | None = None) -> float:
    now_dt = now_dt or datetime.now().astimezone()
    try:
        hh, mm = [int(x) for x in hhmm.split(":")]
    except Exception:
        hh, mm = 23, 0
    cand = now_dt.replace(hour=hh, minute=mm, second=0, microsecond=0)
    if cand <= now_dt:
        cand += timedelta(days=1)
    return cand.timestamp()


class BackupSchedulerService:
    """
    server_config.json の backup_manager 設定を1秒ホットリロードし、
    各ミラー・DBダンプ・8TB HDDバックアップの自動スケジュールとWeb手動実行キューを統括する常駐サービス
    """

    def __init__(self, cfg: dict):
        self.cfg = cfg
        self._cfg_mtime = 0.0
        self._stop_evt = threading.Event()
        self._thread: threading.Thread | None = None
        self._next_due: dict[str, float] = {}
        self._sched_sig: dict[str, str] = {}
        self._last_results: dict[str, dict] = {}
        self._load_initial_history()

    def _load_initial_history(self):
        if os.path.isfile(BACKUP_HISTORY_FILE):
            try:
                with open(BACKUP_HISTORY_FILE, "r", encoding="utf-8") as f:
                    hist = json.load(f)
                for r in reversed(hist):
                    c = r.get("job_code")
                    if c:
                        self._last_results[c] = r
            except Exception:
                pass

    def _schedule_signature(self, j: dict, master_on: bool) -> str:
        return f"{master_on}|{j['enabled']}|{j['schedule_type']}|{j.get('interval_min')}|{j.get('daily_time')}"

    def _recalc_schedules_if_needed(self, force: bool = False):
        try:
            st = os.stat(CONFIG_PATH)
            if force or st.st_mtime != self._cfg_mtime:
                self.cfg = load_config()
                self._cfg_mtime = st.st_mtime
                bcfg = resolve_backup_config(self.cfg)
                master_on = bcfg["master_enabled"]
                now = time.time()
                for j in bcfg["jobs"]:
                    code = j["code"]
                    sig = self._schedule_signature(j, master_on)
                    if sig != self._sched_sig.get(code):
                        self._sched_sig[code] = sig
                        if master_on and j["enabled"]:
                            if j["schedule_type"] == "interval":
                                self._next_due[code] = now + (int(j["interval_min"]) * 60.0)
                            else:
                                self._next_due[code] = compute_next_daily_ts(j["daily_time"])
                        else:
                            self._next_due.pop(code, None)
                self._write_live_status("IDLE")
        except Exception as e:
            logger.warning("Backup config reload check failed: %s", e)

    def _write_live_status(self, state: str = "IDLE", running_info: dict | None = None):
        bcfg = resolve_backup_config(self.cfg)
        master_on = bcfg["master_enabled"]
        jobs_out = []
        last_overall = "OK"
        for j in bcfg["jobs"]:
            c = j["code"]
            eff_on = master_on and bool(j["enabled"])
            due = self._next_due.get(c)
            lr = self._last_results.get(c, {})
            if lr.get("status") == "ERROR":
                last_overall = "ERROR"
            sched_txt = (
                f"{j['interval_min']}分おき"
                if j["schedule_type"] == "interval"
                else f"毎日 {j['daily_time']}"
            )
            jobs_out.append(
                {
                    **j,
                    "effective_active": eff_on,
                    "schedule_text": sched_txt,
                    "next_run_at": datetime.fromtimestamp(due).astimezone().isoformat() if (eff_on and due) else "",
                    "last_run_at": lr.get("finished_at", ""),
                    "last_status": lr.get("status", ""),
                    "last_duration_sec": lr.get("duration_sec", 0),
                    "last_summary": lr.get("summary", ""),
                }
            )

        payload = {
            "updated_at": datetime.now().astimezone().isoformat(),
            "state": state,
            "master_enabled": master_on,
            "last_overall_status": last_overall,
            "running": running_info,
            "jobs": jobs_out,
            "storage_pairs": inspect_storage_pairs(self.cfg),
            "db_dumps": list_db_dumps(self.cfg),
        }
        atomic_write_json(BACKUP_LIVE_FILE, payload)

    def _run_job_with_status(self, job: dict, trigger: str):
        if os.path.isfile(BACKUP_STOP_FLAG):
            try:
                os.remove(BACKUP_STOP_FLAG)
            except OSError:
                pass
        t_start = time.time()

        def _prog(msg: str):
            self._write_live_status(
                "RUNNING",
                {
                    "job_code": job["code"],
                    "job_label": job["label"],
                    "trigger": trigger,
                    "started_at": datetime.fromtimestamp(t_start).astimezone().isoformat(),
                    "elapsed_sec": round(time.time() - t_start, 1),
                    "message": msg,
                },
            )

        _prog(f"{job['label']} を開始しました...")
        rec = execute_single_backup_job(self.cfg, job, trigger=trigger, progress_cb=_prog)
        self._last_results[job["code"]] = rec

        # 次回スケジュール更新
        bcfg = resolve_backup_config(self.cfg)
        if bcfg["master_enabled"] and job.get("enabled", True):
            if job["schedule_type"] == "interval":
                self._next_due[job["code"]] = time.time() + (int(job["interval_min"]) * 60.0)
            else:
                self._next_due[job["code"]] = compute_next_daily_ts(job["daily_time"])
        self._write_live_status("IDLE")

    def _check_manual_queue(self):
        if not os.path.isfile(BACKUP_QUEUE_FILE):
            return
        try:
            with open(BACKUP_QUEUE_FILE, "r", encoding="utf-8") as f:
                req = json.load(f)
            os.remove(BACKUP_QUEUE_FILE)
        except Exception:
            return

        target_code = str(req.get("job_code") or "").strip()
        bcfg = resolve_backup_config(self.cfg)
        jobs_map = {j["code"]: j for j in bcfg["jobs"]}
        if target_code == "ALL":
            for c in ("MIRROR_ACTIVE", "MIRROR_ARCHIVE", "DB_DUMP_HDD", "FULL_HDD_SYNC"):
                if self._stop_evt.is_set() or os.path.isfile(BACKUP_STOP_FLAG):
                    break
                if c in jobs_map:
                    self._run_job_with_status(jobs_map[c], trigger="MANUAL")
        elif target_code in jobs_map:
            self._run_job_with_status(jobs_map[target_code], trigger="MANUAL")

    def start(self):
        if self._thread and self._thread.is_alive():
            return
        self._stop_evt.clear()
        self._thread = threading.Thread(target=self._loop, name="BackupScheduler", daemon=True)
        self._thread.start()
        logger.info("Backup Scheduler & Monitor service started")

    def stop(self):
        self._stop_evt.set()
        if self._thread and self._thread.is_alive():
            self._thread.join(timeout=5.0)

    def _loop(self):
        self._recalc_schedules_if_needed(force=True)
        last_status_refresh = time.monotonic()
        while not self._stop_evt.is_set():
            self._recalc_schedules_if_needed(force=False)
            self._check_manual_queue()

            bcfg = resolve_backup_config(self.cfg)
            if bcfg["master_enabled"]:
                now = time.time()
                for j in bcfg["jobs"]:
                    if self._stop_evt.is_set():
                        break
                    c = j["code"]
                    if not j["enabled"]:
                        continue
                    due = self._next_due.get(c)
                    if due and now >= due:
                        self._run_job_with_status(j, trigger="SCHEDULED")

            # 30秒ごとにストレージ残量やダンプ一覧を最新化
            if (time.monotonic() - last_status_refresh) >= 30.0:
                last_status_refresh = time.monotonic()
                self._write_live_status("IDLE")

            self._stop_evt.wait(1.0)


def main():
    parser = argparse.ArgumentParser(description="RS-Next バックアップ＆ミラーリング管理CLI")
    parser.add_argument("--run", type=str, default="", help="即時実行するジョブコード (MIRROR_ACTIVE / MIRROR_ARCHIVE / DB_DUMP_HDD / FULL_HDD_SYNC)")
    parser.add_argument("--verify-dump", type=str, default="", help="指定した .dump ファイル名を pg_restore --list で検証")
    args = parser.parse_args()

    cfg = load_config()
    if args.verify_dump:
        bk_root = str(cfg.get("storage", {}).get("backup_hdd_root") or "")
        safe_fn = os.path.basename(args.verify_dump)
        fp = os.path.join(bk_root, "db_dumps", safe_fn)
        vres = verify_pg_dump_file(fp)
        if os.path.isfile(fp):
            atomic_write_json(
                fp + ".meta.json",
                {
                    "verified": vres["ok"],
                    "toc_entries": vres["toc_entries"],
                    "verified_at": datetime.now().astimezone().isoformat(),
                },
            )
        print(json.dumps({"status": "success" if vres["ok"] else "error", **vres}, ensure_ascii=False))
        return

    if args.run:
        bcfg = resolve_backup_config(cfg)
        jmap = {j["code"]: j for j in bcfg["jobs"]}
        if args.run not in jmap:
            print(json.dumps({"status": "error", "message": f"Unknown job: {args.run}"}, ensure_ascii=False))
            return
        rec = execute_single_backup_job(cfg, jmap[args.run], trigger="CLI_MANUAL")
        print(json.dumps({"status": "success", "record": rec}, ensure_ascii=False))


if __name__ == "__main__":
    main()

