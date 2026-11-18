-- ============================================================================
-- RS-Next (Post RS-Base) 完全 UTF-8 正規化スキーマ (PostgreSQL 16/18+)
-- Modality Studio (プラグ＆プレイ装置管理・自動検出レーダー対応)
-- ============================================================================

CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- 1. 患者マスタ（MALL3 DRS / DRS2 21列CSV連携 ＋ 高速カナ・漢字検索）
CREATE TABLE IF NOT EXISTS patients (
    patient_id       VARCHAR(32) PRIMARY KEY,
    patient_no_int   BIGINT GENERATED ALWAYS AS (
                         CASE WHEN patient_id ~ '^[0-9]+$' AND length(patient_id) <= 18
                              THEN patient_id::BIGINT
                              ELSE NULL
                         END
                     ) STORED,
    kanja_kanji      TEXT NOT NULL DEFAULT '',
    kanja_yomi       TEXT NOT NULL DEFAULT '',
    kanja_yomi_norm  TEXT NOT NULL DEFAULT '',
    birth_date       DATE,
    birth_raw        VARCHAR(32) NOT NULL DEFAULT '',
    sex              VARCHAR(16) NOT NULL DEFAULT '',
    postal_code      VARCHAR(20) NOT NULL DEFAULT '',
    address          TEXT NOT NULL DEFAULT '',
    phone1           VARCHAR(64) NOT NULL DEFAULT '',
    phone2           VARCHAR(64) NOT NULL DEFAULT '',
    death_or_job     VARCHAR(100) NOT NULL DEFAULT '',
    insurance_info   JSONB NOT NULL DEFAULT '{}'::jsonb,
    source_channel   VARCHAR(24) NOT NULL DEFAULT 'DRS',
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_patients_no_int    ON patients(patient_no_int);
CREATE INDEX IF NOT EXISTS idx_patients_kanji_gin ON patients USING gin (kanja_kanji gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_patients_yomi_gin  ON patients USING gin (kanja_yomi_norm gin_trgm_ops);

-- 2. モダリティプロファイル（Modality Studio: ノーコード装置追加・入替・振分ルール）
CREATE TABLE IF NOT EXISTS modality_profiles (
    profile_id         SERIAL PRIMARY KEY,
    profile_code       VARCHAR(64) NOT NULL UNIQUE,
    device_name        VARCHAR(128) NOT NULL,            -- 例: 'フジ内視鏡システム #1・#2'
    room_or_location   VARCHAR(64) NOT NULL DEFAULT '',  -- 例: '内視鏡室1・2'
    protocol           VARCHAR(32) NOT NULL DEFAULT 'DICOM_SCP', -- 'FTP', 'DICOM_SCP', 'FOLDER_WATCH', 'HTTP_API'
    modality_code      VARCHAR(16) NOT NULL DEFAULT 'OT',        -- 'ES', 'DX', 'RF', 'US', 'ECG', 'DOC', 'OT'
    display_category   VARCHAR(32) NOT NULL DEFAULT 'other',     -- 'endoscopy', 'xray', 'us', 'ecg', 'report', 'other'
    default_kensamei   VARCHAR(100) NOT NULL,            -- 例: 'ES_General', '内視鏡(D)', '胸部(D)'
    kensamei_from_tag  VARCHAR(64) NOT NULL DEFAULT 'FIXED',     -- 'FIXED', 'BODY_OR_PROTOCOL', 'MODALITY_PROTOCOL'
    icon_emoji         VARCHAR(16) NOT NULL DEFAULT '🏥',
    badge_color        VARCHAR(16) NOT NULL DEFAULT '#2563eb',
    priority_order     INTEGER NOT NULL DEFAULT 100,     -- 小さい数字ほど優先マッチ
    is_enabled         BOOLEAN NOT NULL DEFAULT TRUE,
    unit_count         INTEGER NOT NULL DEFAULT 1,
    match_rules        JSONB NOT NULL DEFAULT '{}'::jsonb,
    -- match_rules 例:
    -- {
    --   "source_ip": "",
    --   "station_name": "MV3C",
    --   "manufacturer_contains": "FUJIFILM",
    --   "model_contains": "EP-8000",
    --   "dicom_modality": "ES",
    --   "min_width": 0, "max_width": 0,
    --   "file_ext": "",
    --   "watch_subfolder": ""
    -- }
    viewer_options     JSONB NOT NULL DEFAULT '{}'::jsonb,
    -- viewer_options 例: {"olympus_circle_fit": true, "default_wl": null, "default_ww": null}
    notes              TEXT NOT NULL DEFAULT '',
    last_received_at   TIMESTAMPTZ,
    total_received     BIGINT NOT NULL DEFAULT 0,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_modality_profiles_prio ON modality_profiles(priority_order ASC, profile_id ASC);

-- 3. 自動検出レーダー（未登録の新装置からデータが届いた際に指紋を自動記録 -> 1クリック登録用）
CREATE TABLE IF NOT EXISTS discovered_devices (
    discovery_id       SERIAL PRIMARY KEY,
    fingerprint_key    VARCHAR(255) NOT NULL UNIQUE,
    source_protocol    VARCHAR(32) NOT NULL,
    source_ip          VARCHAR(64) NOT NULL DEFAULT '',
    calling_ae_title   VARCHAR(64) NOT NULL DEFAULT '',
    station_name       VARCHAR(128) NOT NULL DEFAULT '',
    manufacturer       VARCHAR(128) NOT NULL DEFAULT '',
    model_name         VARCHAR(128) NOT NULL DEFAULT '',
    dicom_modality     VARCHAR(32) NOT NULL DEFAULT '',
    sample_resolution  VARCHAR(32) NOT NULL DEFAULT '',
    sample_file_ext    VARCHAR(16) NOT NULL DEFAULT '',
    first_seen_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    seen_count         INTEGER NOT NULL DEFAULT 1,
    linked_profile_id  INTEGER REFERENCES modality_profiles(profile_id) ON DELETE SET NULL,
    is_dismissed       BOOLEAN NOT NULL DEFAULT FALSE
);

-- 4. 検査（Study）テーブル（非RAIDマルチSSD volume_id 対応・旧 renban 継承対応）
CREATE TABLE IF NOT EXISTS studies (
    study_id           BIGSERIAL PRIMARY KEY,
    legacy_renban      INTEGER UNIQUE,
    patient_id         VARCHAR(32) NOT NULL REFERENCES patients(patient_id) ON UPDATE CASCADE,
    profile_id         INTEGER REFERENCES modality_profiles(profile_id) ON DELETE SET NULL,
    study_date         DATE NOT NULL,
    study_time         TIME,
    daytime_raw        VARCHAR(32) NOT NULL DEFAULT '',
    modality           VARCHAR(16) NOT NULL DEFAULT 'OT',
    kensamei           VARCHAR(100) NOT NULL,
    display_category   VARCHAR(32) NOT NULL DEFAULT 'other',
    badge_color        VARCHAR(16) NOT NULL DEFAULT '',
    station_name       VARCHAR(64) NOT NULL DEFAULT '',
    manufacturer       VARCHAR(128) NOT NULL DEFAULT '',
    model_name         VARCHAR(128) NOT NULL DEFAULT '',
    doctor             VARCHAR(128) NOT NULL DEFAULT '',
    department         VARCHAR(64) NOT NULL DEFAULT '',
    findings           TEXT NOT NULL DEFAULT '',
    comment            TEXT NOT NULL DEFAULT '',
    volume_id          VARCHAR(32) NOT NULL DEFAULT 'VOL_ACTIVE',
    rel_path           TEXT NOT NULL UNIQUE,
    file_count         INTEGER NOT NULL DEFAULT 0,
    total_bytes        BIGINT NOT NULL DEFAULT 0,
    primary_file_type  VARCHAR(16) NOT NULL DEFAULT 'DICOM', -- 'DICOM', 'ECG', 'IMAGE', 'PDF'
    study_instance_uid VARCHAR(128),
    is_deleted         BOOLEAN NOT NULL DEFAULT FALSE,
    deleted_at         TIMESTAMPTZ,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_studies_date_desc ON studies(study_date DESC, study_id DESC) WHERE NOT is_deleted;
CREATE INDEX IF NOT EXISTS idx_studies_patient   ON studies(patient_id, study_date DESC, study_id DESC) WHERE NOT is_deleted;
CREATE INDEX IF NOT EXISTS idx_studies_uid       ON studies(study_instance_uid) WHERE study_instance_uid IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_studies_deleted   ON studies(deleted_at DESC) WHERE is_deleted;

-- 5. 個別ファイル管理テーブル（SOPInstanceUID / SHA-256 二重取込防止）
CREATE TABLE IF NOT EXISTS study_files (
    file_id          BIGSERIAL PRIMARY KEY,
    study_id         BIGINT NOT NULL REFERENCES studies(study_id) ON DELETE CASCADE,
    seq_no           INTEGER NOT NULL,
    filename         VARCHAR(128) NOT NULL,
    orig_filename    VARCHAR(255) NOT NULL DEFAULT '',
    file_kind        VARCHAR(16) NOT NULL DEFAULT 'DICOM', -- 'DICOM', 'ECG', 'IMAGE', 'PDF'
    sop_instance_uid VARCHAR(128),
    sha256_hash      CHAR(64) NOT NULL,
    file_bytes       BIGINT NOT NULL DEFAULT 0,
    width            INTEGER,
    height           INTEGER,
    transfer_syntax  VARCHAR(64) NOT NULL DEFAULT '',
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (study_id, filename)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_study_files_sop_uid ON study_files(sop_instance_uid) WHERE sop_instance_uid IS NOT NULL AND sop_instance_uid <> '';
CREATE INDEX IF NOT EXISTS idx_study_files_sha256 ON study_files(sha256_hash);

-- 6. 未紐付け・ID不正データ隔離キュー（旧 ~NoNumber.dcm 放置問題の解決）
CREATE TABLE IF NOT EXISTS quarantine_queue (
    quarantine_id       BIGSERIAL PRIMARY KEY,
    received_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    source_protocol     VARCHAR(32) NOT NULL,
    source_ip           VARCHAR(64) NOT NULL DEFAULT '',
    raw_patient_id      VARCHAR(128) NOT NULL DEFAULT '',
    raw_patient_name    TEXT NOT NULL DEFAULT '',
    study_date_raw      VARCHAR(32) NOT NULL DEFAULT '',
    modality            VARCHAR(32) NOT NULL DEFAULT '',
    kensamei_guess      VARCHAR(100) NOT NULL DEFAULT '',
    file_count          INTEGER NOT NULL DEFAULT 1,
    quarantine_rel      TEXT NOT NULL UNIQUE,
    reason              TEXT NOT NULL DEFAULT '',
    status              VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    resolved_patient_id VARCHAR(32),
    resolved_study_id   BIGINT REFERENCES studies(study_id) ON DELETE SET NULL,
    resolved_at         TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_quarantine_pending ON quarantine_queue(received_at DESC) WHERE status = 'PENDING';

-- 7. 着信・操作監査ログテーブル
CREATE TABLE IF NOT EXISTS ingest_logs (
    log_id            BIGSERIAL PRIMARY KEY,
    logged_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    level             VARCHAR(16) NOT NULL DEFAULT 'INFO',
    channel           VARCHAR(32) NOT NULL,
    event_type        VARCHAR(32) NOT NULL,
    patient_id        VARCHAR(32) NOT NULL DEFAULT '',
    study_id          BIGINT,
    summary           TEXT NOT NULL,
    detail            JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX IF NOT EXISTS idx_ingest_logs_recent ON ingest_logs(logged_at DESC);

-- ============================================================================
-- 初期プリセット: 当院現行モダリティ 5種（計7台）＋ 汎用フォールバック
-- ============================================================================
INSERT INTO modality_profiles
(profile_code, device_name, room_or_location, protocol, modality_code, display_category, default_kensamei, kensamei_from_tag, icon_emoji, badge_color, priority_order, unit_count, match_rules, viewer_options, notes)
VALUES
(
  'FUJI_ENDO_EP8000',
  'フジフイルム内視鏡 ELUXEO 8000',
  '内視鏡室 (2台共通FTP)',
  'FTP',
  'ES',
  'endoscopy',
  'ES_General',
  'FIXED',
  '🔬',
  '#059669',
  10,
  2,
  '{"dicom_modality":"ES","manufacturer_contains":"FUJIFILM","model_contains":"EP-8000"}'::jsonb,
  '{"olympus_circle_fit":false}'::jsonb,
  'フジ内視鏡2台（同一FTPインターフェイス / MV3C経由 1280x1024 JPEG DICOM）'
),
(
  'OLYMPUS_ENDO_FHD',
  'オリンパス内視鏡システム',
  '内視鏡室 (1台)',
  'FTP',
  'ES',
  'endoscopy',
  '内視鏡(D)',
  'FIXED',
  '⭕',
  '#0d9488',
  20,
  1,
  '{"dicom_modality":"ES","min_width":1800}'::jsonb,
  '{"olympus_circle_fit":true}'::jsonb,
  'オリンパス内視鏡（1920x1080 Full HD / 8枚印刷時は自動でO社サークルフィット適用）'
),
(
  'SHIMADZU_DX_XRAY',
  '島津製作所 レントゲン撮影 (CR/DX)',
  'レントゲン室',
  'DICOM_SCP',
  'DX',
  'xray',
  '胸部(D)',
  'BODY_OR_PROTOCOL',
  '🩻',
  '#7c3aed',
  30,
  1,
  '{"dicom_modality":"DX,CR"}'::jsonb,
  '{}'::jsonb,
  '島津製作所 単純X線（CHEST→胸部(D)、ABDOMEN→腹部(D) 等を自動判定）'
),
(
  'SHIMADZU_RF_FLUORO',
  '島津製作所 X線TV・透視装置 (RF)',
  'レントゲン透視室',
  'DICOM_SCP',
  'RF',
  'xray',
  'RF_透視',
  'MODALITY_PROTOCOL',
  '⚡',
  '#6d28d9',
  35,
  1,
  '{"dicom_modality":"RF"}'::jsonb,
  '{}'::jsonb,
  '島津製作所 透視撮影（腰椎RF・ガストロ等をプロトコル名から自動付与）'
),
(
  'CANON_ARIETTA_US',
  '超音波診断装置 (エコー)',
  'エコー診察室',
  'DICOM_SCP',
  'US',
  'us',
  'US_Adult_Abd_',
  'MODALITY_PROTOCOL',
  '🔊',
  '#0284c7',
  40,
  1,
  '{"dicom_modality":"US"}'::jsonb,
  '{}'::jsonb,
  '超音波エコー（ARIETTA 65 / キャノン系 US DICOM自動受信）'
),
(
  'FUKUDA_ECG_12LEAD',
  'フクダ電子 12誘導心電計',
  '処置室・検査室 (2台)',
  'FOLDER_WATCH',
  'ECG',
  'ecg',
  '心電図(D)',
  'FIXED',
  '📈',
  '#e11d48',
  50,
  2,
  '{"file_ext":"ecg,001"}'::jsonb,
  '{}'::jsonb,
  'フクダ電子心電計2台（TCP 1001 → FUKUDA_ECG\\ORG 出力監視 → 高精細Web波形生成）'
),
(
  'GENERIC_EXTERNAL_MEDIA',
  '他院紹介CD・汎用DICOM / カメラ・PDF',
  'Web取込 / 汎用着信',
  'HTTP_API',
  'OT',
  'other',
  '外部取込画像',
  'BODY_OR_PROTOCOL',
  '💿',
  '#475569',
  999,
  1,
  '{}'::jsonb,
  '{}'::jsonb,
  '紹介状添付CT/MRI CD、デジカメ写真、PDF検査レポート等の汎用受け皿プロファイル'
)
ON CONFLICT (profile_code) DO NOTHING;

-- ============================================================================
-- RS-Next 002: 撮影依頼・ワークリスト (DICOM MWL / MPPS / バーコード連携) 拡張スキーマ
-- ============================================================================

-- 1. modality_profiles へ MWL・患者受渡設定カラムを追加
ALTER TABLE modality_profiles
    ADD COLUMN IF NOT EXISTS mwl_config JSONB NOT NULL DEFAULT '{}'::jsonb;

-- 現行5種7台プロファイルへ各装置固有の最適受渡設定を反映
UPDATE modality_profiles SET mwl_config = '{
  "primary_delivery": "DICOM_MWL_OR_BARCODE",
  "supports_dicom_mwl": true,
  "supports_mpps": true,
  "mwl_via_device": "TEAC MV-3C (MV-3CS-D等) または EP-8000 (要DL-4450)",
  "charset": "ISO_2022_IR_87",
  "scheduled_aet": "",
  "barcode_supported": true,
  "auto_link_on_ingest": true
}'::jsonb WHERE profile_code = 'FUJI_ENDO_EP8000';

UPDATE modality_profiles SET mwl_config = '{
  "primary_delivery": "DICOM_MWL_OR_BARCODE",
  "supports_dicom_mwl": true,
  "supports_mpps": true,
  "mwl_via_device": "TEAC MV-3C (MV-3CS-D/MV-3CH-D MWM機能)",
  "charset": "ISO_2022_IR_87",
  "scheduled_aet": "",
  "barcode_supported": true,
  "auto_link_on_ingest": true
}'::jsonb WHERE profile_code = 'OLYMPUS_ENDO_FHD';

UPDATE modality_profiles SET mwl_config = '{
  "primary_delivery": "DICOM_MWL",
  "supports_dicom_mwl": true,
  "supports_mpps": true,
  "mwl_via_device": "Canon CXDI Control Software (SHIMADZU-PC)",
  "charset": "ISO_2022_IR_87",
  "scheduled_aet": "SHIMADZU-PC",
  "barcode_supported": true,
  "auto_link_on_ingest": true
}'::jsonb WHERE profile_code IN ('SHIMADZU_DX_XRAY', 'SHIMADZU_RF_FLUORO');

UPDATE modality_profiles SET mwl_config = '{
  "primary_delivery": "DICOM_MWL",
  "supports_dicom_mwl": true,
  "supports_mpps": true,
  "mwl_via_device": "FUJIFILM Healthcare ARIETTA 65 (Station: AR1)",
  "charset": "ISO_2022_IR_87",
  "scheduled_aet": "AR1",
  "barcode_supported": true,
  "auto_link_on_ingest": true
}'::jsonb WHERE profile_code = 'CANON_ARIETTA_US';

UPDATE modality_profiles SET mwl_config = '{
  "primary_delivery": "BARCODE_TERMINAL_AND_AUTO_LINK",
  "supports_dicom_mwl": false,
  "supports_mpps": false,
  "mwl_via_device": "FCP-8300 / FCP-8321 (処置室Webバーコード読取 ＋ 着信時オーダ自動突合 / 上位オプション時MWL・CSV対応)",
  "charset": "ISO_2022_IR_87",
  "scheduled_aet": "",
  "barcode_supported": true,
  "csv_outbox_supported": true,
  "auto_link_on_ingest": true
}'::jsonb WHERE profile_code = 'FUKUDA_ECG_12LEAD';

-- 2. ワンクリック撮影依頼プリセットマスタ
CREATE TABLE IF NOT EXISTS order_presets (
    preset_id        SERIAL PRIMARY KEY,
    preset_code      VARCHAR(64) NOT NULL UNIQUE,
    preset_label     VARCHAR(100) NOT NULL,
    icon_emoji       VARCHAR(16) NOT NULL DEFAULT '🩺',
    badge_color      VARCHAR(16) NOT NULL DEFAULT '#2563eb',
    modality         VARCHAR(16) NOT NULL,               -- 'ES', 'DX', 'RF', 'US', 'ECG'
    target_profile   VARCHAR(64) NOT NULL DEFAULT '',    -- modality_profiles.profile_code (任意)
    kensamei         VARCHAR(100) NOT NULL,              -- RS-Next 格納時の検査種別名
    procedure_desc   VARCHAR(128) NOT NULL,              -- モダリティ画面に表示する手技・部位名
    body_part        VARCHAR(32) NOT NULL DEFAULT '',    -- DICOM BodyPartExamined
    protocol_code    VARCHAR(64) NOT NULL DEFAULT '',    -- 装置側プロトコル連動コード
    room_group       VARCHAR(32) NOT NULL DEFAULT 'all', -- 'endoscopy', 'xray', 'us', 'ecg'
    sort_order       INTEGER NOT NULL DEFAULT 100,
    is_enabled       BOOLEAN NOT NULL DEFAULT TRUE
);

INSERT INTO order_presets
(preset_code, preset_label, icon_emoji, badge_color, modality, target_profile, kensamei, procedure_desc, body_part, protocol_code, room_group, sort_order)
VALUES
('ES_UPPER_FUJI', '上部内視鏡 (フジ)', '🔬', '#059669', 'ES', 'FUJI_ENDO_EP8000', 'ES_General', '上部消化管内視鏡 (GIF)', 'STOMACH', 'ES_UPPER', 'endoscopy', 10),
('ES_GANKENSIN',  '胃がん検診 (内視鏡)', '📷', '#0d9488', 'ES', 'FUJI_ENDO_EP8000', '胃がん検診', '胃がん検診 上部内視鏡', 'STOMACH', 'ES_SCREEN', 'endoscopy', 20),
('ES_OLYMPUS',    '内視鏡・大腸 (オリンパス)', '⭕', '#0f766e', 'ES', 'OLYMPUS_ENDO_FHD', '内視鏡(D)', '内視鏡検査 (Olympus)', 'COLON', 'ES_CF', 'endoscopy', 30),
('DX_CHEST',      '胸部レントゲン (正面)', '🩻', '#7c3aed', 'DX', 'SHIMADZU_DX_XRAY', '胸部(D)', '胸部正面 PA', 'CHEST', '胸部-PA', 'xray', 40),
('DX_ABDOMEN',    '腹部レントゲン (単純)', '🩻', '#6d28d9', 'DX', 'SHIMADZU_DX_XRAY', '腹部(D)', '腹部単純 XP', 'ABDOMEN', '腹部-AP', 'xray', 50),
('RF_LSPINE',     '腰椎・X線透視 (RF)', '⚡', '#5b21b6', 'RF', 'SHIMADZU_RF_FLUORO', 'RF_腰椎_RF', '腰椎透視撮影', 'LSPINE', '腰椎 RF', 'xray', 60),
('RF_FLUORO_GI',  '消化管・X線透視 (RF)', '⚡', '#4c1d95', 'RF', 'SHIMADZU_RF_FLUORO', 'RF_透視', '消化管X線透視', 'ABDOMEN', 'RF_GI', 'xray', 65),
('US_ABDOMEN',    '腹部エコー (US)', '🔊', '#0284c7', 'US', 'CANON_ARIETTA_US', 'US_Adult_Abd_', '腹部超音波検査', 'ABDOMEN', 'Adult Abd.', 'us', 70),
('US_CAROTID',    '頸動脈・表在エコー', '🔊', '#0369a1', 'US', 'CANON_ARIETTA_US', 'US_Carotid_', '頸動脈超音波検査', 'NECK', 'Carotid', 'us', 80),
('ECG_12LEAD',    '12誘導心電図', '📈', '#e11d48', 'ECG', 'FUKUDA_ECG_12LEAD', '心電図(D)', '安静時12誘導心電図', 'HEART', 'ECG12', 'ecg', 90)
ON CONFLICT (preset_code) DO NOTHING;

-- 3. 撮影・検査オーダーテーブル (DICOM MWL / MPPS / バーコード指示ボード統合管理)
CREATE TABLE IF NOT EXISTS modality_orders (
    order_id           BIGSERIAL PRIMARY KEY,
    accession_number   VARCHAR(32) NOT NULL UNIQUE,
    study_instance_uid VARCHAR(128) NOT NULL UNIQUE,
    patient_id         VARCHAR(32) NOT NULL REFERENCES patients(patient_id) ON UPDATE CASCADE,
    profile_id         INTEGER REFERENCES modality_profiles(profile_id) ON DELETE SET NULL,
    modality           VARCHAR(16) NOT NULL,
    scheduled_aet      VARCHAR(64) NOT NULL DEFAULT '',
    scheduled_station  VARCHAR(64) NOT NULL DEFAULT '',
    scheduled_date     DATE NOT NULL DEFAULT CURRENT_DATE,
    scheduled_time     TIME,
    preset_code        VARCHAR(64) NOT NULL DEFAULT '',
    kensamei           VARCHAR(100) NOT NULL,
    procedure_desc     VARCHAR(128) NOT NULL DEFAULT '',
    body_part          VARCHAR(32) NOT NULL DEFAULT '',
    protocol_code      VARCHAR(64) NOT NULL DEFAULT '',
    room_group         VARCHAR(32) NOT NULL DEFAULT 'other',
    requesting_doctor  VARCHAR(128) NOT NULL DEFAULT '',
    requesting_dept    VARCHAR(64) NOT NULL DEFAULT '',
    order_comment      TEXT NOT NULL DEFAULT '',
    status             VARCHAR(24) NOT NULL DEFAULT 'SCHEDULED', -- 'SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'
    mpps_sop_uid       VARCHAR(128),
    linked_study_id    BIGINT REFERENCES studies(study_id) ON DELETE SET NULL,
    started_at         TIMESTAMPTZ,
    completed_at       TIMESTAMPTZ,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_orders_sched_date ON modality_orders(scheduled_date DESC, status, order_id DESC);
CREATE INDEX IF NOT EXISTS idx_orders_patient    ON modality_orders(patient_id, scheduled_date DESC);
CREATE INDEX IF NOT EXISTS idx_orders_active_mwl ON modality_orders(scheduled_date, modality) WHERE status IN ('SCHEDULED', 'IN_PROGRESS');
