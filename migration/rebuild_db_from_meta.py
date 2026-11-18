import os
import sys
import json
from datetime import date, time as dt_time

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import load_config, get_db_conn, log_ingest_event


def rebuild_database_from_volumes() -> dict:
    """
    BCP災害復旧ツール:
    万一 PostgreSQL データベースが全損・初期化された場合でも、
    各ボリューム (VOL_ARCHIVE / VOL_ACTIVE) 内の study_meta.json を走査するだけで
    検査履歴・所見・個別ファイル情報を 100% 自動再構築する。
    """
    cfg = load_config()
    volumes = cfg["storage"]["volumes"]
    restored_studies = 0
    restored_files = 0

    conn = get_db_conn(cfg)
    try:
        for vol_id, vol_cfg in volumes.items():
            vol_root = vol_cfg["path"]
            studies_root = os.path.join(vol_root, "studies")
            if not os.path.isdir(studies_root):
                continue

            for dirpath, _, filenames in os.walk(studies_root):
                if "study_meta.json" not in filenames:
                    continue
                meta_fp = os.path.join(dirpath, "study_meta.json")
                try:
                    with open(meta_fp, "r", encoding="utf-8") as f:
                        meta = json.load(f)
                except Exception:
                    continue

                rel_path = os.path.relpath(dirpath, vol_root).replace("\\", "/")
                pid = str(meta.get("patient_id") or "").strip()
                if not pid:
                    continue

                s_date = date.fromisoformat(meta["study_date"]) if meta.get("study_date") else date.today()
                s_time = dt_time.fromisoformat(meta["study_time"]) if meta.get("study_time") else None
                files = meta.get("files") or []
                total_bytes = sum(int(x.get("file_bytes") or 0) for x in files)

                with conn.cursor() as cur:
                    cur.execute(
                        """
                        INSERT INTO patients (patient_id, kanja_kanji, source_channel)
                        VALUES (%s, %s, 'META_RECOVERY')
                        ON CONFLICT (patient_id) DO NOTHING
                        """,
                        (pid, f"復元患者({pid})"),
                    )

                    cur.execute(
                        """
                        INSERT INTO studies
                        (legacy_renban, patient_id, study_date, study_time, modality, kensamei,
                         display_category, badge_color, station_name, manufacturer, model_name,
                         doctor, department, findings, comment, volume_id, rel_path,
                         file_count, total_bytes, primary_file_type, study_instance_uid, is_deleted)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                        ON CONFLICT (rel_path) DO UPDATE SET
                            patient_id = EXCLUDED.patient_id,
                            findings   = EXCLUDED.findings,
                            comment    = EXCLUDED.comment,
                            doctor     = EXCLUDED.doctor,
                            is_deleted = EXCLUDED.is_deleted,
                            updated_at = NOW()
                        RETURNING study_id
                        """,
                        (
                            meta.get("legacy_renban"),
                            pid,
                            s_date,
                            s_time,
                            meta.get("modality") or "OT",
                            meta.get("kensamei") or "検査画像",
                            meta.get("display_category") or "other",
                            meta.get("badge_color") or "#2563eb",
                            meta.get("station_name") or "",
                            meta.get("manufacturer") or "",
                            meta.get("model_name") or "",
                            meta.get("doctor") or "",
                            meta.get("department") or "",
                            meta.get("findings") or "",
                            meta.get("comment") or "",
                            vol_id,
                            rel_path,
                            len(files),
                            total_bytes,
                            meta.get("primary_file_type") or "DICOM",
                            meta.get("study_instance_uid") or None,
                            bool(meta.get("is_deleted", False)),
                        ),
                    )
                    study_id = cur.fetchone()["study_id"]
                    restored_studies += 1

                    for fi in files:
                        cur.execute(
                            """
                            INSERT INTO study_files
                            (study_id, seq_no, filename, orig_filename, file_kind, sop_instance_uid,
                             sha256_hash, file_bytes, width, height, transfer_syntax)
                            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                            ON CONFLICT (study_id, filename) DO NOTHING
                            """,
                            (
                                study_id,
                                int(fi["seq_no"]),
                                fi["filename"],
                                fi.get("orig_filename") or fi["filename"],
                                fi.get("file_kind") or "DICOM",
                                fi.get("sop_instance_uid") or None,
                                fi["sha256_hash"],
                                int(fi.get("file_bytes") or 0),
                                fi.get("width"),
                                fi.get("height"),
                                fi.get("transfer_syntax") or "",
                            ),
                        )
                        restored_files += 1

        log_ingest_event(
            conn,
            "WARN",
            "BCP_RECOVERY",
            "DB_REBUILT_FROM_DISK",
            f"study_meta.json 走査によるDB自己再構築完了: 検査 {restored_studies}件 / ファイル {restored_files}件",
        )
        conn.commit()
    finally:
        conn.close()

    return {"status": "success", "restored_studies": restored_studies, "restored_files": restored_files}


if __name__ == "__main__":
    res = rebuild_database_from_volumes()
    print(json.dumps(res, ensure_ascii=False, indent=2))

