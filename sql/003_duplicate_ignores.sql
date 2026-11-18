-- ============================================================================
-- RS-Next 003: 重複検査抽出・同日別検査除外管理スキーマ ＆ 高速化インデックス
-- ============================================================================

CREATE TABLE IF NOT EXISTS duplicate_study_ignores (
    group_sig   VARCHAR(64) PRIMARY KEY,
    study_ids   BIGINT[] NOT NULL,
    note        TEXT NOT NULL DEFAULT '',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_dup_ignores_created ON duplicate_study_ignores(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_study_files_sid_seq ON study_files(study_id, seq_no);
CREATE INDEX IF NOT EXISTS idx_study_files_sha256_sid ON study_files(sha256_hash, study_id);
