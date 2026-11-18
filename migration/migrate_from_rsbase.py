import os
import re
import sys
import json
import time
import shutil
import subprocess
import argparse
from datetime import datetime, date, time as dt_time

import psycopg
from psycopg.rows import dict_row

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

PROGRESS_FILE = os.path.join(BASE_DIR, "tmp", "migration_progress.json")
STOP_FLAG_FILE = os.path.join(BASE_DIR, "tmp", "migration_stop.flag")

from ingest_service.common import (
    load_config,
    get_db_conn,
    normalize_patient_id,
    normalize_kana_yomi,
    sha256_file,
    atomic_write_json,
    log_ingest_event,
    match_modality_profile,
    infer_category_and_style,
)
from ingest_service.mall3_csv_watcher import parse_mall3_birth_date, normalize_sex_label
from ingest_service.study_filer import (
    inspect_file_metadata,
    generate_study_caches,
    sync_study_meta_json,
    ingest_single_file,
)


def decode_cp932(val) -> str:
    if val is None:
        return ""
    if isinstance(val, bytes):
        return val.decode("cp932", errors="replace").strip()
    return str(val).strip()


def connect_legacy_db_readonly(src_cfg: dict):
    """
    旧サーバ (192.168.1.164 / gazouDB) へ完全 Read-Only トランザクションモードで接続する。
    """
    conn = psycopg.connect(
        host=src_cfg["pg_host"],
        port=int(src_cfg.get("pg_port", 5432)),
        dbname=src_cfg["pg_dbname"],
        user=src_cfg["pg_user"],
        password=src_cfg["pg_password"],
        row_factory=dict_row,
        autocommit=True,
        options="-c default_transaction_read_only=on",
    )
    with conn.cursor() as cur:
        # psycopg3 が raw CP932 バイトを壊さずUTF-8変換できるよう PG サーバ側で SJIS->UTF8 自動変換させる
        cur.execute("SET client_encoding TO 'UTF8'")
    return conn


def ensure_legacy_smb_share_accessible(share_public_html: str) -> None:
    if not share_public_html or os.path.isdir(share_public_html):
        return
    m = re.match(r"^(\\\\[^\\]+\\[^\\]+)", share_public_html.strip().replace("/", "\\"))
    if m and os.name == "nt":
        unc_root = m.group(1)
        subprocess.run(
            ["cmd.exe", "/c", f'net use "{unc_root}" "" /user:rsnext_reader >nul 2>&1'],
            timeout=10,
            check=False,
        )


def resolve_legacy_folder_path(raw_folder: str, share_public_html: str) -> str:
    if not raw_folder:
        return ""
    f = str(raw_folder).strip().replace("/", "\\")
    if f.startswith("\\\\"):
        return f
    # public_html からの相対指定のフォールバック
    rel = f.lstrip("\\")
    return os.path.join(share_public_html, rel)


def parse_legacy_date(raw_date: str) -> date:
    if raw_date:
        m = re.search(r"([12][0-9]{3})[/\.\-]?([0-9]{1,2})[/\.\-]?([0-9]{1,2})", str(raw_date))
        if m:
            try:
                return date(int(m.group(1)), int(m.group(2)), int(m.group(3)))
            except ValueError:
                pass
    return date(2000, 1, 1)


def parse_legacy_time(raw_daytime: str) -> dt_time | None:
    if not raw_daytime:
        return None
    s = str(raw_daytime).strip()
    # RS-Base 標準形式: YYYYMMDDHHMMSScc (例: 2026111711031600 -> 11:03:16)
    m_digits = re.match(r"^[12][0-9]{7}([0-2][0-9])([0-5][0-9])([0-5][0-9])", s)
    if m_digits:
        try:
            return dt_time(int(m_digits.group(1)), int(m_digits.group(2)), int(m_digits.group(3)))
        except ValueError:
            pass
    m = re.search(r"([0-9]{1,2}):([0-9]{1,2})(?::([0-9]{1,2}))?", s)
    if m:
        try:
            return dt_time(int(m.group(1)), int(m.group(2)), int(m.group(3) or 0))
        except ValueError:
            return None
    return None


def _upsert_patient_row(dst_conn, r: dict) -> bool:
    raw_id = str(r.get("id") or "").strip()
    norm_id, _ = normalize_patient_id(raw_id)
    if not norm_id:
        return False

    kanji = decode_cp932(r.get("kanja_kanji") or r.get("name") or "")
    yomi = decode_cp932(r.get("kanja_yomi") or r.get("yomi") or "")
    yomi_norm = normalize_kana_yomi(yomi)
    birth_raw = decode_cp932(r.get("birth") or "")
    birth_dt = parse_mall3_birth_date(birth_raw)
    sex = normalize_sex_label(decode_cp932(r.get("sex") or ""))
    postal = decode_cp932(r.get("yubin") or r.get("zip") or "")
    addr = decode_cp932(r.get("address") or r.get("jyusyo") or "")
    tel1 = decode_cp932(r.get("phone1") or r.get("tel1") or r.get("tel") or "")
    tel2 = decode_cp932(r.get("phone2") or r.get("tel2") or "")

    with dst_conn.cursor() as dcur:
        dcur.execute(
            """
            INSERT INTO patients
            (patient_id, kanja_kanji, kanja_yomi, kanja_yomi_norm, birth_date, birth_raw,
             sex, postal_code, address, phone1, phone2, source_channel)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'MIGRATION')
            ON CONFLICT (patient_id) DO UPDATE SET
                kanja_kanji     = CASE WHEN EXCLUDED.kanja_kanji <> '' THEN EXCLUDED.kanja_kanji ELSE patients.kanja_kanji END,
                kanja_yomi      = CASE WHEN EXCLUDED.kanja_yomi <> '' THEN EXCLUDED.kanja_yomi ELSE patients.kanja_yomi END,
                kanja_yomi_norm = CASE WHEN EXCLUDED.kanja_yomi_norm <> '' THEN EXCLUDED.kanja_yomi_norm ELSE patients.kanja_yomi_norm END,
                birth_date      = COALESCE(EXCLUDED.birth_date, patients.birth_date),
                birth_raw       = CASE WHEN EXCLUDED.birth_raw <> '' THEN EXCLUDED.birth_raw ELSE patients.birth_raw END,
                sex             = CASE WHEN EXCLUDED.sex <> '' THEN EXCLUDED.sex ELSE patients.sex END,
                postal_code     = CASE WHEN EXCLUDED.postal_code <> '' THEN EXCLUDED.postal_code ELSE patients.postal_code END,
                address         = CASE WHEN EXCLUDED.address <> '' THEN EXCLUDED.address ELSE patients.address END,
                phone1          = CASE WHEN EXCLUDED.phone1 <> '' THEN EXCLUDED.phone1 ELSE patients.phone1 END,
                phone2          = CASE WHEN EXCLUDED.phone2 <> '' THEN EXCLUDED.phone2 ELSE patients.phone2 END
            """,
            (norm_id, kanji, yomi, yomi_norm, birth_dt, birth_raw, sex, postal, addr, tel1, tel2),
        )
    return True


def migrate_patients(legacy_conn, dst_conn, dry_run: bool = False, limit: int = 0, target_ids: list[int] | None = None) -> int:
    if target_ids is not None:
        if not target_ids:
            return 0
        with legacy_conn.cursor() as lcur:
            lcur.execute("SELECT * FROM name_tbl WHERE id = ANY(%s)", (target_ids,))
            rows = lcur.fetchall()
    else:
        sql = "SELECT * FROM name_tbl ORDER BY id ASC"
        if limit > 0:
            sql += f" LIMIT {int(limit)}"
        with legacy_conn.cursor() as lcur:
            lcur.execute(sql)
            rows = lcur.fetchall()

    count = 0
    for r in rows:
        raw_id = str(r.get("id") or "").strip()
        norm_id, _ = normalize_patient_id(raw_id)
        if not norm_id:
            continue
        if not dry_run:
            _upsert_patient_row(dst_conn, r)
        count += 1
        if not dry_run and count % 2000 == 0:
            dst_conn.commit()

    if not dry_run:
        dst_conn.commit()
    return count


def _natural_sort_key(s: str):
    return [int(t) if t.isdigit() else t.lower() for t in re.split(r"(\d+)", s)]


def collect_clean_exam_files(legacy_target: str) -> list[str]:
    if not legacy_target or not os.path.exists(legacy_target):
        return []
    if os.path.isfile(legacy_target):
        return [legacy_target]

    valid_files = []
    skip_names = {"small.jpg", "thumb.jpg", "kensa.txt", "000.shoken", "thumbs.db", "desktop.ini"}
    for entry in sorted(os.listdir(legacy_target), key=_natural_sort_key):
        if entry.lower() in skip_names or entry.lower().startswith("txt1rs"):
            continue
        fp = os.path.join(legacy_target, entry)
        if not os.path.isfile(fp):
            continue
        ext = os.path.splitext(entry)[1].lower().lstrip(".")
        if ext in ("dcm", "ecg", "001", "jpg", "jpeg", "png", "pdf", ""):
            valid_files.append(fp)
    return valid_files


def migrate_studies(
    cfg: dict,
    legacy_conn,
    dst_conn,
    dry_run: bool = False,
    delta_only: bool = False,
    limit: int = 0,
    generate_thumbs: bool = False,
    recent_first: bool = False,
    sample_both_volumes: bool = False,
) -> tuple[dict, list[int]]:
    cutoff_year = int(cfg["storage"].get("archive_cutoff_year", 2021))
    share_root = cfg["legacy_rsbase_source"]["share_public_html"]
    ensure_legacy_smb_share_accessible(share_root)

    min_renban = 0
    if delta_only:
        with dst_conn.cursor() as dcur:
            dcur.execute("SELECT COALESCE(MAX(legacy_renban), 0) AS mr FROM studies")
            min_renban = int(dcur.fetchone()["mr"])

    with legacy_conn.cursor() as lcur:
        if sample_both_volumes and limit > 0:
            arc_n = max(1, min(15, limit // 6))
            act_n = max(1, limit - arc_n)
            lcur.execute("SELECT * FROM gazou_tbl ORDER BY renban DESC LIMIT %s", (act_n,))
            act_rows = lcur.fetchall()
            lcur.execute(
                "SELECT * FROM gazou_tbl WHERE date LIKE %s OR date LIKE %s ORDER BY renban DESC LIMIT %s",
                ("2021/%", "2020/%", arc_n),
            )
            arc_rows = lcur.fetchall()
            rows = act_rows + arc_rows
        else:
            order_dir = "DESC" if recent_first else "ASC"
            sql = f"SELECT * FROM gazou_tbl WHERE renban > %s ORDER BY renban {order_dir}"
            if limit > 0:
                sql += f" LIMIT {int(limit)}"
            lcur.execute(sql, (min_renban,))
            rows = lcur.fetchall()

    # 対応する患者ID群を収集して先に name_tbl から完全な患者属性を取り込む
    target_int_ids = []
    for r in rows:
        raw_pid = decode_cp932(r.get("id") or "")
        npid, _ = normalize_patient_id(raw_pid)
        if npid and npid.isdigit():
            target_int_ids.append(int(npid))
    target_int_ids = sorted(set(target_int_ids))
    if target_int_ids and limit > 0:
        migrate_patients(legacy_conn, dst_conn, dry_run=dry_run, target_ids=target_int_ids)

    stats = {
        "scanned": len(rows),
        "migrated": 0,
        "skipped_existing": 0,
        "missing_files": 0,
        "total_files": 0,
        "total_bytes": 0,
        "stopped_early": False,
        "by_volume": {"VOL_ACTIVE": 0, "VOL_ARCHIVE": 0},
    }

    with legacy_conn.cursor() as lcur:
        lcur.execute("SELECT COUNT(*) AS cnt FROM gazou_tbl")
        legacy_total = int(lcur.fetchone()["cnt"])
    with dst_conn.cursor() as dcur:
        dcur.execute("SELECT COUNT(*) AS cnt FROM studies WHERE legacy_renban IS NOT NULL")
        base_migrated = int(dcur.fetchone()["cnt"])

    os.makedirs(os.path.dirname(PROGRESS_FILE), exist_ok=True)
    if os.path.isfile(STOP_FLAG_FILE):
        try:
            os.remove(STOP_FLAG_FILE)
        except OSError:
            pass

    t_start = time.time()

    def _report_progress(state_str: str, cur_renban: int = 0, cur_date: str = ""):
        elapsed = max(0.5, time.time() - t_start)
        done_total = base_migrated + stats["migrated"]
        pct = round((done_total / max(1, legacy_total)) * 100.0, 2)
        spm = round((stats["migrated"] / elapsed) * 60.0, 1) if stats["migrated"] > 0 else 0.0
        rem = max(0, legacy_total - done_total)
        eta_min = int(round(rem / spm)) if spm > 0 else None
        atomic_write_json(
            PROGRESS_FILE,
            {
                "state": state_str,
                "pid": os.getpid(),
                "updated_at": datetime.now().astimezone().isoformat(),
                "legacy_total": legacy_total,
                "done_total": done_total,
                "percent": min(100.0, pct),
                "run_migrated": stats["migrated"],
                "run_skipped": stats["skipped_existing"],
                "run_files": stats["total_files"],
                "run_bytes": stats["total_bytes"],
                "speed_per_min": spm,
                "eta_minutes": eta_min,
                "current_renban": cur_renban,
                "current_date": cur_date,
                "by_volume": stats["by_volume"],
            },
        )

    _report_progress("RUNNING")

    for idx_row, r in enumerate(rows, start=1):
        if os.path.isfile(STOP_FLAG_FILE):
            try:
                os.remove(STOP_FLAG_FILE)
            except OSError:
                pass
            stats["stopped_early"] = True
            _report_progress("PAUSED", int(r["renban"]), str(r.get("date") or ""))
            break

        renban = int(r["renban"])
        with dst_conn.cursor() as dcur:
            dcur.execute("SELECT 1 FROM studies WHERE legacy_renban = %s", (renban,))
            if dcur.fetchone():
                stats["skipped_existing"] += 1
                continue

        raw_pid = decode_cp932(r.get("id") or "")
        norm_pid, _ = normalize_patient_id(raw_pid)
        if not norm_pid:
            continue

        s_date = parse_legacy_date(decode_cp932(r.get("date") or ""))
        s_time = parse_legacy_time(decode_cp932(r.get("daytime") or ""))
        vol_id = "VOL_ARCHIVE" if s_date.year <= cutoff_year else "VOL_ACTIVE"
        vol_cfg = cfg["storage"]["volumes"][vol_id]

        kensamei = decode_cp932(r.get("kensamei") or "検査画像")
        doctor = decode_cp932(r.get("doctor") or "")
        department = decode_cp932(r.get("jusin_ka") or "")
        comment = decode_cp932(r.get("comment") or "")
        findings_parts = [
            decode_cp932(r.get(k) or "") for k in ("sindan1", "sindan2", "sindan3", "sindan4")
        ]
        findings = "\n".join([p for p in findings_parts if p]).strip()

        src_target = resolve_legacy_folder_path(decode_cp932(r.get("folder") or ""), share_root)
        exam_files = collect_clean_exam_files(src_target)
        if not exam_files:
            stats["missing_files"] += 1
            continue

        if dry_run:
            stats["migrated"] += 1
            stats["by_volume"][vol_id] = stats["by_volume"].get(vol_id, 0) + 1
            continue

        first_meta = inspect_file_metadata(exam_files[0], source_protocol="MIGRATION")
        first_hash = sha256_file(exam_files[0])

        # 並列稼働（モダリティからの同時転送）ですでにRS-Next側へリアルタイム着信済みの検査があれば、
        # 二重登録せずに legacy_renban と旧RS-Base側の所見・担当医情報だけを既存レコードへ統合する
        existing_live_sid = None
        with dst_conn.cursor() as dcur:
            if first_meta.get("sop_instance_uid"):
                dcur.execute(
                    """
                    SELECT sf.study_id
                    FROM study_files sf
                    JOIN studies s ON s.study_id = sf.study_id
                    WHERE sf.sop_instance_uid = %s AND NOT s.is_deleted
                    LIMIT 1
                    """,
                    (first_meta["sop_instance_uid"],),
                )
                row_dup = dcur.fetchone()
                if row_dup:
                    existing_live_sid = int(row_dup["study_id"])

            if existing_live_sid is None and first_meta.get("study_instance_uid"):
                dcur.execute(
                    """
                    SELECT study_id FROM studies
                    WHERE study_instance_uid = %s AND patient_id = %s AND kensamei = %s AND NOT is_deleted
                    LIMIT 1
                    """,
                    (first_meta["study_instance_uid"], norm_pid, kensamei),
                )
                row_dup = dcur.fetchone()
                if row_dup:
                    existing_live_sid = int(row_dup["study_id"])

            if existing_live_sid is None:
                dcur.execute(
                    """
                    SELECT sf.study_id
                    FROM study_files sf
                    JOIN studies s ON s.study_id = sf.study_id
                    WHERE sf.sha256_hash = %s AND s.patient_id = %s AND NOT s.is_deleted
                    LIMIT 1
                    """,
                    (first_hash, norm_pid),
                )
                row_dup = dcur.fetchone()
                if row_dup:
                    existing_live_sid = int(row_dup["study_id"])

            if existing_live_sid is not None:
                dcur.execute(
                    """
                    UPDATE studies
                    SET legacy_renban = COALESCE(legacy_renban, %s),
                        doctor        = CASE WHEN doctor = '' AND %s <> '' THEN %s ELSE doctor END,
                        department    = CASE WHEN department = '' AND %s <> '' THEN %s ELSE department END,
                        findings      = CASE WHEN findings = '' AND %s <> '' THEN %s ELSE findings END,
                        comment       = CASE WHEN comment = '' AND %s <> '' THEN %s ELSE comment END,
                        updated_at    = NOW()
                    WHERE study_id = %s
                    """,
                    (
                        renban,
                        doctor, doctor,
                        department, department,
                        findings, findings,
                        comment, comment,
                        existing_live_sid,
                    ),
                )
                sync_study_meta_json(dst_conn, cfg, existing_live_sid)
                dst_conn.commit()
                stats["skipped_existing"] += 1
                continue

        matched = match_modality_profile(dst_conn, first_meta, record_discovery=False)
        mod_code = first_meta["dicom_modality"] or matched.get("modality_code") or "OT"
        disp_cat = matched.get("display_category") or "other"
        badge_col = matched.get("badge_color") or "#2563eb"
        if disp_cat == "other":
            inf_cat, inf_mod, inf_col = infer_category_and_style(kensamei, first_meta["file_kind"], first_meta["dicom_modality"])
            if inf_cat != "other":
                disp_cat = inf_cat
                if mod_code in ("OT", ""):
                    mod_code = inf_mod
                badge_col = inf_col

        # 患者存在保証
        with dst_conn.cursor() as dcur:
            dcur.execute(
                """
                INSERT INTO patients (patient_id, kanja_kanji, kanja_yomi, kanja_yomi_norm, sex, source_channel)
                VALUES (%s, %s, %s, %s, %s, 'MIGRATION')
                ON CONFLICT (patient_id) DO NOTHING
                """,
                (
                    norm_pid,
                    decode_cp932(r.get("kanja_kanji") or f"ID:{norm_pid}"),
                    decode_cp932(r.get("kanja_yomi") or ""),
                    normalize_kana_yomi(decode_cp932(r.get("kanja_yomi") or "")),
                    normalize_sex_label(decode_cp932(r.get("sex") or "")),
                ),
            )

            date_sub = f"studies/{s_date.year:04d}/{s_date.month:02d}/{s_date.day:02d}"
            hhmmss = s_time.strftime("%H%M%S") if s_time else "000000"
            rel_path = f"{date_sub}/P{norm_pid.zfill(8)}_{mod_code}_{hhmmss}_R{renban}"
            study_abs_dir = os.path.join(vol_cfg["path"], rel_path.replace("/", os.sep))
            os.makedirs(study_abs_dir, exist_ok=True)

            dcur.execute(
                """
                INSERT INTO studies
                (legacy_renban, patient_id, profile_id, study_date, study_time, daytime_raw,
                 modality, kensamei, display_category, badge_color, station_name, manufacturer,
                 model_name, doctor, department, findings, comment, volume_id, rel_path, primary_file_type, study_instance_uid)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                RETURNING study_id
                """,
                (
                    renban,
                    norm_pid,
                    matched.get("profile_id"),
                    s_date,
                    s_time,
                    decode_cp932(r.get("daytime") or ""),
                    mod_code,
                    kensamei,
                    disp_cat,
                    badge_col,
                    first_meta["station_name"],
                    first_meta["manufacturer"],
                    first_meta["model_name"],
                    doctor,
                    department,
                    findings,
                    comment,
                    vol_id,
                    rel_path,
                    first_meta["file_kind"],
                    first_meta["study_instance_uid"] or None,
                ),
            )
            study_id = dcur.fetchone()["study_id"]

            total_b = 0
            for idx, sfp in enumerate(exam_files, start=1):
                fmeta = first_meta if idx == 1 else inspect_file_metadata(sfp, source_protocol="MIGRATION")
                ext_out = ".ECG" if fmeta["file_kind"] == "ECG" else (".dcm" if fmeta["file_kind"] == "DICOM" else f".{fmeta['file_ext'] or 'jpg'}")
                dst_fn = f"{idx:04d}{ext_out}"
                dst_fp = os.path.join(study_abs_dir, dst_fn)
                shutil.copy2(sfp, dst_fp)
                fb = os.path.getsize(dst_fp)
                total_b += fb
                dcur.execute(
                    """
                    INSERT INTO study_files
                    (study_id, seq_no, filename, orig_filename, file_kind, sop_instance_uid,
                     sha256_hash, file_bytes, width, height, transfer_syntax)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ON CONFLICT DO NOTHING
                    """,
                    (
                        study_id,
                        idx,
                        dst_fn,
                        os.path.basename(sfp),
                        fmeta["file_kind"],
                        fmeta["sop_instance_uid"] or None,
                        sha256_file(dst_fp),
                        fb,
                        fmeta["width"],
                        fmeta["height"],
                        fmeta["transfer_syntax"],
                    ),
                )
                if generate_thumbs:
                    generate_study_caches(dst_fp, fmeta["file_kind"], cfg["storage"]["cache_root"], study_id, idx)

            dcur.execute(
                "UPDATE studies SET file_count = %s, total_bytes = %s WHERE study_id = %s",
                (len(exam_files), total_b, study_id),
            )

        sync_study_meta_json(dst_conn, cfg, study_id)
        dst_conn.commit()
        stats["migrated"] += 1
        stats["total_files"] += len(exam_files)
        stats["total_bytes"] += total_b
        stats["by_volume"][vol_id] = stats["by_volume"].get(vol_id, 0) + 1
        if stats["migrated"] % 10 == 0 or idx_row % 50 == 0:
            _report_progress("RUNNING", renban, s_date.strftime("%Y-%m-%d"))

    if not stats["stopped_early"]:
        _report_progress("IDLE")

    return stats, target_int_ids


def rescue_legacy_cr_nonumber(cr_dir: str, dry_run: bool = False) -> int:
    if not os.path.isdir(cr_dir):
        return 0
    rescued = 0
    for fn in sorted(os.listdir(cr_dir)):
        if "nonumber" in fn.lower() or fn.startswith("~"):
            fp = os.path.join(cr_dir, fn)
            if os.path.isfile(fp):
                if not dry_run:
                    ingest_single_file(fp, source_protocol="LEGACY_CR_RESCUE", remove_source=False)
                rescued += 1
    return rescued


def verify_migration_counts(legacy_conn, dst_conn) -> dict:
    with legacy_conn.cursor() as lcur:
        lcur.execute("SELECT COUNT(*) AS cnt, COALESCE(MAX(renban), 0) AS max_r FROM gazou_tbl")
        leg_s = lcur.fetchone()
        lcur.execute("SELECT COUNT(*) AS cnt FROM name_tbl")
        leg_p = lcur.fetchone()

    with dst_conn.cursor() as dcur:
        dcur.execute("SELECT COUNT(*) AS cnt, COALESCE(MAX(legacy_renban), 0) AS max_r FROM studies WHERE legacy_renban IS NOT NULL")
        dst_s = dcur.fetchone()
        dcur.execute("SELECT COUNT(*) AS cnt FROM patients")
        dst_p = dcur.fetchone()

    return {
        "legacy_patients": leg_p["cnt"],
        "rsnext_patients": dst_p["cnt"],
        "legacy_studies": leg_s["cnt"],
        "rsnext_migrated_studies": dst_s["cnt"],
        "legacy_max_renban": leg_s["max_r"],
        "rsnext_max_renban": dst_s["max_r"],
        "latest_renban_matched": leg_s["max_r"] == dst_s["max_r"],
    }


def main():
    parser = argparse.ArgumentParser(description="RS-Base (192.168.1.164) 完全 Read-Only 無停止マイグレータ")
    parser.add_argument("--dry-run", action="store_true", help="書き込みを行わず対象件数のみ検証")
    parser.add_argument("--delta", action="store_true", help="最大 legacy_renban 以降の差分のみ高速同期")
    parser.add_argument("--verify", action="store_true", help="移行前後の件数・最新連番整合性を検証")
    parser.add_argument("--limit", type=int, default=0, help="テスト移行時の上限件数")
    parser.add_argument("--recent", action="store_true", help="最新検査(renban降順)から優先移行")
    parser.add_argument("--skip-patients", action="store_true", help="MALL3 SQL同期済みの環境で name_tbl 全件走査を省略")
    parser.add_argument("--sample-both-volumes", action="store_true", help="limit指定時に最新検査(VOL_ACTIVE)と2021年以前(VOL_ARCHIVE)の両方をバランス移行")
    parser.add_argument("--thumbs", action="store_true", help="移行と同時にサムネイルキャッシュを生成")
    parser.add_argument("--rescue-cr-dir", type=str, default="", help="旧 D:\\CR 内の ~NoNumber.dcm を隔離BOXへ回収")
    args = parser.parse_args()

    cfg = load_config()
    leg_conn = connect_legacy_db_readonly(cfg["legacy_rsbase_source"])
    dst_conn = get_db_conn(cfg)
    try:
        if args.verify and not args.delta and args.limit == 0 and not args.dry_run:
            v = verify_migration_counts(leg_conn, dst_conn)
            print(json.dumps({"status": "success", "verification": v}, ensure_ascii=False, indent=2))
            return

        pat_cnt = 0
        if args.limit == 0 and not args.skip_patients and not args.delta:
            pat_cnt = migrate_patients(leg_conn, dst_conn, dry_run=args.dry_run, limit=0)

        st_stats, target_ids = migrate_studies(
            cfg,
            leg_conn,
            dst_conn,
            dry_run=args.dry_run,
            delta_only=args.delta,
            limit=args.limit,
            generate_thumbs=args.thumbs,
            recent_first=(args.recent or args.limit > 0),
            sample_both_volumes=args.sample_both_volumes,
        )
        if args.limit > 0:
            pat_cnt = len(target_ids)
        rescued_cnt = rescue_legacy_cr_nonumber(args.rescue_cr_dir, dry_run=args.dry_run) if args.rescue_cr_dir else 0
        verify_res = verify_migration_counts(leg_conn, dst_conn) if args.verify else None

        if not args.dry_run:
            log_ingest_event(
                dst_conn,
                "INFO",
                "MIGRATION",
                "MIGRATION_RUN",
                f"RS-Base移行バッチ完了: 患者 {pat_cnt}件 / 検査 {st_stats['migrated']}件",
                detail={"patients": pat_cnt, "studies": st_stats, "rescued_cr": rescued_cnt},
            )
            dst_conn.commit()

        print(
            json.dumps(
                {
                    "status": "success",
                    "dry_run": args.dry_run,
                    "patients_processed": pat_cnt,
                    "studies": st_stats,
                    "rescued_cr_nonumber": rescued_cnt,
                    "verification": verify_res,
                },
                ensure_ascii=False,
                indent=2,
            )
        )
    finally:
        leg_conn.close()
        dst_conn.close()


if __name__ == "__main__":
    main()

