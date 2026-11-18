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

