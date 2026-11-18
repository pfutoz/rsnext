<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next Modality Studio (modality_studio.php)
// - ノーコード・再起動不要の直感的モダリティ追加・ルール管理スタジオ
// - 📡 未登録装置の自動検出レーダー（1クリックプロファイル昇格登録）
// - 🧪 ドラッグ＆ドロップ振分シミュレータ（DICOM / ECG / JPEG / PDF 対応）
// - 📄 医療機器メーカー担当者向け「接続設定案内シート」A4クリーン印刷
// ==============================================================================

require_once __DIR__ . '/lib/bootstrap.php';

$cfg = rsnext_config();
$quarantineCount = 0;
try {
    $quarantineCount = rsnext_get_pending_quarantine_count(rsnext_pdo());
} catch (Throwable $e) {
}
$serverIpHint = $_SERVER['SERVER_ADDR'] ?? '192.168.1.164';
if ($serverIpHint === '::1' || $serverIpHint === '127.0.0.1') {
    $serverIpHint = @gethostbyname((string)@gethostname()) ?: '192.168.1.164';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎛️ Modality Studio - RS-Next</title>
    <link rel="stylesheet" href="assets/rsnext_ui.css">
    <style>
        .studio-hero {
            background: linear-gradient(135deg, #0f172a 0%, #312e81 52%, #0d9488 100%);
            color: #ffffff;
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.16);
        }
        .studio-hero h1 { font-size: 1.48rem; font-weight: 800; margin-bottom: 6px; }
        .studio-hero p { font-size: 0.88rem; color: #e0e7ff; max-width: 780px; }

        /* 自動検出レーダー */
        .radar-section {
            background: linear-gradient(135deg, #f5f3ff 0%, #eef2ff 100%);
            border: 2px solid #a78bfa;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 18px;
            display: none;
        }
        .radar-card {
            background: #ffffff;
            border: 1px solid #ddd6fe;
            border-radius: 10px;
            padding: 12px 16px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        /* 2カラム: 左 プロファイルカード群 / 右 シミュレータ */
        .studio-layout {
            display: grid;
            grid-template-columns: 1fr 390px;
            gap: 18px;
            align-items: start;
        }
        @media (max-width: 1080px) {
            .studio-layout { grid-template-columns: 1fr; }
        }

        .profiles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
            gap: 14px;
        }
        .prof-card {
            background: #ffffff;
            border: 1.5px solid var(--border);
            border-radius: 14px;
            padding: 16px 18px;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 12px;
            transition: transform 0.16s, box-shadow 0.16s, border-color 0.16s;
            position: relative;
        }
        .prof-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .prof-card.disabled { opacity: 0.55; background: #f8fafc; }
        .prof-card.sim-hit {
            border-color: #10b981;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.25);
        }

        .prof-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .prof-icon {
            width: 44px;
            height: 44px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.45rem;
            color: #ffffff;
            flex-shrink: 0;
        }
        .prof-title { font-size: 0.98rem; font-weight: 800; color: #0f172a; line-height: 1.3; }
        .prof-sub { font-size: 0.77rem; color: var(--text-muted); margin-top: 2px; }
        .rule-chip {
            display: inline-block;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #334155;
            font-family: Consolas, monospace;
            font-size: 0.74rem;
            padding: 2px 7px;
            border-radius: 5px;
            margin: 2px 3px 2px 0;
        }

        /* D&D 振分シミュレータ */
        .sim-dropzone {
            border: 2px dashed #6366f1;
            background: linear-gradient(180deg, #f5f3ff 0%, #ffffff 100%);
            border-radius: 12px;
            padding: 24px 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.15s;
        }
        .sim-dropzone.dragover {
            background: #ede9fe;
            border-color: #4f46e5;
            transform: scale(1.01);
        }

        /* 業者向け接続案内シート印刷制御 */
        @media print {
            body * { visibility: hidden; }
            #vendorSheetModal, #vendorSheetModal * { visibility: visible; }
            #vendorSheetModal { position: absolute; inset: 0; background: #ffffff; padding: 0; display: block !important; }
            #vendorSheetModal .modal-box { box-shadow: none; border: none; max-width: 100%; max-height: none; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<header class="rs-topbar no-print">
    <div style="display:flex;align-items:center;gap:14px;">
        <a href="index.php" class="rs-brand">
            <span class="rs-brand-logo">🏥 RS-Next</span>
            <span class="rs-brand-sub">Modality Studio</span>
        </a>
        <?php if ($quarantineCount > 0): ?>
            <a href="ingest_dashboard.php#quarantine" class="rs-quarantine-pill">⚠️ 未紐付け <?= (int)$quarantineCount ?> 件</a>
        <?php endif; ?>
    </div>
    <nav class="rs-nav">
        <a href="index.php" class="rs-nav-link">🖥️ 検査一覧</a>
        <a href="worklist.php" class="rs-nav-link">📋 撮影依頼・MWL</a>
        <a href="modality_studio.php" class="rs-nav-link active">🎛️ Modality Studio</a>
        <a href="import_media.php" class="rs-nav-link">💿 外部CD/画像取込</a>
        <a href="ingest_dashboard.php" class="rs-nav-link">📡 受信モニター</a>
        <a href="hardware_monitor.php" class="rs-nav-link">🖥️ ハード監視</a>
        <a href="backup_manager.php" class="rs-nav-link">🛡️ バックアップ</a>
        <a href="仕様書/modality_studio_ingest_spec.html" target="_blank" class="rs-nav-link">📚 仕様書</a>
    </nav>
</header>

<main class="rs-main">

    <!-- スタジオヒーロー -->
    <section class="studio-hero no-print">
        <div>
            <h1>🎛️ Modality Studio（モダリティ設定スタジオ）</h1>
            <p>
                新しい検査機器の追加・機種入替・検査名ルールの変更をブラウザ上で完結できます。保存した設定は常駐サービスの再起動なしで<strong>0秒即時反映</strong>されます。
            </p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="btn" onclick="openVendorSheetModal()">📄 業者向け「接続設定シート」表示・印刷</button>
            <button type="button" class="btn btn-teal" onclick="openProfileModal(null)">＋ 新しいモダリティを追加</button>
        </div>
    </section>

    <!-- 📡 新規装置の自動検出レーダー -->
    <section class="radar-section no-print" id="radarSection">
        <div style="display:flex;align-items:center;justify-content:space-between;">
            <div style="font-weight:800;color:#4c1d95;font-size:1rem;">
                📡 自動検出レーダー：未登録の新しい装置からの着信を検知しました！
            </div>
            <span style="font-size:0.78rem;color:#6d28d9;font-weight:700;">1クリックでルール自動入力＆登録可能</span>
        </div>
        <div id="radarList"></div>
    </section>

    <div class="studio-layout no-print">
        <!-- 左ペイン: プロファイルカード群 -->
        <div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <h2 style="font-size:1.05rem;font-weight:800;color:#0f172a;">登録モダリティ・振分プロファイル一覧（優先度順）</h2>
                <span style="font-size:0.78rem;color:var(--text-muted);">優先度番号が小さいプロファイルから順に評価されます</span>
            </div>
            <div class="profiles-grid" id="profilesGrid">
                <div class="rs-card">読み込み中...</div>
            </div>
        </div>

        <!-- 右ペイン: D&D 振分シミュレータ -->
        <div class="rs-card" style="position:sticky;top:70px;">
            <h3 style="font-size:1rem;font-weight:800;color:#312e81;margin-bottom:6px;">🧪 ドラッグ＆ドロップ振分シミュレータ</h3>
            <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:12px;">
                手元の検査ファイル（<code>.dcm</code> / <code>.ECG</code> / <code>.jpg</code> / <code>.pdf</code>）をドロップすると、本番DBを変更せずに「どの装置プロファイルにマッチし、どの検査名・バッジになるか」をその場で判定します。
            </p>

            <div class="sim-dropzone" id="simDropzone" onclick="document.getElementById('simFileInput').click()">
                <div style="font-size:2rem;margin-bottom:6px;">📂</div>
                <div style="font-weight:800;color:#312e81;font-size:0.9rem;">ここにサンプルファイルをドロップ</div>
                <div style="font-size:0.76rem;color:var(--text-muted);margin-top:4px;">またはクリックしてファイル選択</div>
                <input type="file" id="simFileInput" style="display:none;" onchange="if(this.files[0]) runSimulation(this.files[0])">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;">
                <div>
                    <label class="form-label" style="font-size:0.73rem;">仮想送信元IP（任意）</label>
                    <input type="text" id="simSourceIp" class="input" style="padding:5px 8px;font-size:0.8rem;" placeholder="例: 192.168.1.50">
                </div>
                <div>
                    <label class="form-label" style="font-size:0.73rem;">監視サブフォルダ（任意）</label>
                    <input type="text" id="simWatchSub" class="input" style="padding:5px 8px;font-size:0.8rem;" placeholder="例: fukuda_ecg">
                </div>
            </div>

            <div id="simResultBox" style="margin-top:14px;display:none;"></div>
        </div>
    </div>

</main>

<!-- プロファイル作成・編集モーダル -->
<div class="modal-backdrop no-print" id="profileModal">
    <div class="modal-box" style="max-width:740px;">
        <div class="modal-head">
            <h3 id="profModalTitle">🎛️ モダリティプロファイル設定</h3>
            <button type="button" class="btn btn-sm" onclick="closeProfileModal()">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="pmProfileId">
            <input type="hidden" id="pmDiscoveryId">

            <div style="display:grid;grid-template-columns:2fr 1.2fr 0.8fr;gap:10px;margin-bottom:12px;">
                <div>
                    <label class="form-label">装置名称（画面表示名）*</label>
                    <input type="text" id="pmDeviceName" class="input" placeholder="例: オリンパス内視鏡 EVIS X1">
                </div>
                <div>
                    <label class="form-label">プロファイル識別コード</label>
                    <input type="text" id="pmProfileCode" class="input" placeholder="例: OLYMPUS_EVIS_X1">
                </div>
                <div>
                    <label class="form-label">評価優先度</label>
                    <input type="number" id="pmPriority" class="input" value="50">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1.2fr 1fr 0.8fr 0.8fr 0.6fr;gap:10px;margin-bottom:12px;">
                <div>
                    <label class="form-label">設置場所・検査室</label>
                    <input type="text" id="pmRoom" class="input" placeholder="例: 内視鏡室2">
                </div>
                <div>
                    <label class="form-label">受信プロトコル</label>
                    <select id="pmProtocol" class="input">
                        <option value="DICOM_SCP">DICOM C-STORE (Port 104)</option>
                        <option value="FTP">FTP送信 (Port 21)</option>
                        <option value="FOLDER_WATCH">共有フォルダ監視</option>
                        <option value="HTTP_API">Web取込 / HTTP API</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Modality</label>
                    <select id="pmModalityCode" class="input">
                        <option value="ES">ES (内視鏡)</option>
                        <option value="DX">DX (単純X線)</option>
                        <option value="RF">RF (X線透視)</option>
                        <option value="US">US (超音波)</option>
                        <option value="ECG">ECG (心電図)</option>
                        <option value="CT">CT</option>
                        <option value="MR">MR</option>
                        <option value="DOC">DOC (PDFレポート)</option>
                        <option value="OT">OT (その他・カメラ)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">一覧タブ分類</label>
                    <select id="pmCategory" class="input">
                        <option value="endoscopy">内視鏡</option>
                        <option value="xray">X線・透視</option>
                        <option value="us">超音波</option>
                        <option value="ecg">心電図</option>
                        <option value="other">外部・レポート</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">台数</label>
                    <input type="number" id="pmUnits" class="input" value="1" min="1">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1.4fr 1.6fr 0.6fr 0.8fr;gap:10px;margin-bottom:14px;">
                <div>
                    <label class="form-label">既定の検査種別名 *</label>
                    <input type="text" id="pmDefaultKensa" class="input" placeholder="例: 内視鏡(D)">
                </div>
                <div>
                    <label class="form-label">検査名の自動決定ルール</label>
                    <select id="pmStrategy" class="input">
                        <option value="FIXED">固定（常に既定の検査名を使用）</option>
                        <option value="BODY_OR_PROTOCOL">部位・プロトコル判定（胸部(D)/腹部(D)等）</option>
                        <option value="MODALITY_PROTOCOL">Modality接頭辞＋装置プロトコル名</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">絵文字</label>
                    <input type="text" id="pmEmoji" class="input" value="🏥" style="text-align:center;">
                </div>
                <div>
                    <label class="form-label">バッジ色</label>
                    <input type="color" id="pmColor" class="input" value="#2563eb" style="height:38px;padding:3px;">
                </div>
            </div>

            <!-- 自動識別条件 (match_rules) -->
            <div style="background:#f8fafc;border:1px solid var(--border-light);border-radius:10px;padding:12px 14px;margin-bottom:12px;">
                <div style="font-weight:800;font-size:0.85rem;color:#1e293b;margin-bottom:8px;">🔍 自動振分マッチ条件（指定した項目すべてに一致したデータをこの装置として分類・空欄は任意）</div>
                <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:10px;">
                    <div>
                        <label class="form-label">DICOM Modality (カンマ区切り可)</label>
                        <input type="text" id="mrModality" class="input" placeholder="例: ES または DX,CR">
                    </div>
                    <div>
                        <label class="form-label">StationName</label>
                        <input type="text" id="mrStation" class="input" placeholder="例: SHIMADZU-PC, MV3C">
                    </div>
                    <div>
                        <label class="form-label">Calling AE Title</label>
                        <input type="text" id="mrAeTitle" class="input" placeholder="例: US_ARIETTA">
                    </div>
                    <div>
                        <label class="form-label">Manufacturer（部分一致）</label>
                        <input type="text" id="mrManufacturer" class="input" placeholder="例: FUJIFILM">
                    </div>
                    <div>
                        <label class="form-label">ModelName（部分一致）</label>
                        <input type="text" id="mrModel" class="input" placeholder="例: EP-8000">
                    </div>
                    <div>
                        <label class="form-label">送信元IP（カンマ区切り可）</label>
                        <input type="text" id="mrSourceIp" class="input" placeholder="例: 192.168.1.55">
                    </div>
                    <div>
                        <label class="form-label">最小横幅 px (min_width)</label>
                        <input type="number" id="mrMinWidth" class="input" placeholder="例: 1800 (FHD判別)">
                    </div>
                    <div>
                        <label class="form-label">拡張子 (file_ext)</label>
                        <input type="text" id="mrFileExt" class="input" placeholder="例: ecg,001 または pdf">
                    </div>
                    <div>
                        <label class="form-label">監視サブフォルダ</label>
                        <input type="text" id="mrSubfolder" class="input" placeholder="例: fukuda_ecg">
                    </div>
                </div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <label style="display:inline-flex;align-items:center;gap:7px;font-size:0.85rem;font-weight:700;cursor:pointer;">
                    <input type="checkbox" id="pmOlympusFit">
                    ⭕ 2×4 A4印刷時に「オリンパス内視鏡 サークルフィット」を初期ONにする
                </label>
                <input type="text" id="pmNotes" class="input" placeholder="保守メモ・備考（任意）" style="max-width:340px;">
            </div>
        </div>
        <div class="modal-foot">
            <div id="pmDeleteArea"></div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn" onclick="closeProfileModal()">キャンセル</button>
                <button type="button" class="btn btn-primary" onclick="saveProfile()">💾 プロファイルを保存（即時反映）</button>
            </div>
        </div>
    </div>
</div>

<!-- 📄 メーカー担当者向け「接続設定案内シート」モーダル (A4印刷対応) -->
<div class="modal-backdrop" id="vendorSheetModal">
    <div class="modal-box" style="max-width:820px;">
        <div class="modal-head no-print">
            <h3>📄 医療機器メーカーご担当者様向け サーバ接続設定シート</h3>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">🖨️ A4印刷する</button>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('vendorSheetModal').classList.remove('open')">✕ 閉じる</button>
            </div>
        </div>
        <div class="modal-body" style="padding:26px;">
            <div style="border-bottom:3px solid #0f4c81;padding-bottom:12px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:flex-end;">
                <div>
                    <div style="font-size:0.8rem;color:#64748b;font-weight:700;"><?= rsnext_h($cfg['clinic_name'] ?? '') ?></div>
                    <h2 style="font-size:1.35rem;color:#0f4c81;font-weight:800;">院内医用画像ファイリングサーバ（RS-Next）接続仕様書</h2>
                </div>
                <div style="font-size:0.8rem;color:#475569;">発行日: <?= date('Y年m月d日') ?></div>
            </div>

            <p style="font-size:0.88rem;color:#334155;margin-bottom:14px;">
                検査装置の設置・入替時は、下記のいずれかのプロトコルで画像・波形データを送信してください。<strong>当サーバは「自動検出レーダー（Plug &amp; Play）」を搭載しているため、事前のAEタイトル登録作業なしでテスト送信1枚から自動認識します。</strong>
            </p>

            <table style="width:100%;border-collapse:collapse;font-size:0.86rem;margin-bottom:18px;">
                <thead>
                    <tr style="background:#f1f5f9;">
                        <th style="border:1px solid #cbd5e1;padding:9px;text-align:left;width:24%;">接続方式</th>
                        <th style="border:1px solid #cbd5e1;padding:9px;text-align:left;">設定パラメータ（本番運用値 / 現テスト環境値）</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="border:1px solid #cbd5e1;padding:9px;font-weight:800;">① DICOM Storage SCP<br><small>(X線・透視・エコー・内視鏡等)</small></td>
                        <td style="border:1px solid #cbd5e1;padding:9px;">
                            ・<strong>サーバIPアドレス</strong>: 本番 <code>192.168.1.164</code> （現テスト機: <code><?= rsnext_h($serverIpHint) ?></code>）<br>
                            ・<strong>ポート番号</strong>: 本番 <code>104</code> （開発検証ポート: <code><?= (int)($cfg['ingest']['dicom_scp']['port'] ?? 11104) ?></code>）<br>
                            ・<strong>Called / Calling AE Title</strong>: <strong>任意（すべてのAEタイトルを無条件で自動受理します）</strong><br>
                            ・<strong>対応構文</strong>: Implicit/Explicit VR Little Endian, JPEG Baseline (.50), JPEG Lossless (.70), JPEG2000, C-ECHO対応
                        </td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #cbd5e1;padding:9px;font-weight:800;">①-B DICOM MWL / MPPS<br><small>(患者リスト・オーダー配信)</small></td>
                        <td style="border:1px solid #cbd5e1;padding:9px;">
                            ・<strong>MWL / MPPS サーバIP・ポート</strong>: Storage SCP と完全同一（本番 <code>192.168.1.164 : 104</code> ／ 検証 <code><?= (int)($cfg['ingest']['dicom_scp']['port'] ?? 11104) ?></code>）<br>
                            ・<strong>Called / Calling AE Title</strong>: <strong>任意（自動受理）</strong> ／ <strong>文字コード</strong>: <code>ISO 2022 IR 87</code>（JIS 3コンポーネント氏名）または <code>ISO_IR 192</code><br>
                            ・<strong>スマートID照会対応</strong>: 事前オーダーなしでも装置側で患者IDを検索すると18万件の患者マスタから氏名・生年月日・性別を即時返却します
                        </td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #cbd5e1;padding:9px;font-weight:800;">② FTP 画像転送<br><small>(フジ/オリンパス内視鏡等)</small></td>
                        <td style="border:1px solid #cbd5e1;padding:9px;">
                            ・<strong>FTPホスト</strong>: 本番 <code>192.168.1.164</code> ／ <strong>ポート</strong>: 本番 <code>21</code> （開発検証: <code><?= (int)($cfg['ingest']['ftp']['port'] ?? 2121) ?></code>）<br>
                            ・<strong>ユーザー名 / パスワード</strong>: <code><?= rsnext_h($cfg['ingest']['ftp']['username'] ?? 'esuser') ?></code> / <code><?= rsnext_h($cfg['ingest']['ftp']['password'] ?? 'esuser') ?></code> （Anonymous接続も可・PASV対応）
                        </td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #cbd5e1;padding:9px;font-weight:800;">③ 心電図 / フォルダ出力<br><small>(フクダ電子・PDF出力装置等)</small></td>
                        <td style="border:1px solid #cbd5e1;padding:9px;">
                            ・<strong>フクダ電子心電計</strong>: TCP Port <code>1001</code> ➔ <code>C:\FUKUDA_ECG\ORG</code> リアルタイム監視<br>
                            ・<strong>PDFレポート / 汎用画像出力機器</strong>: 指定SMB共有フォルダまたはHTTP APIへDICOM/JPEG/PDF出力可能
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 14px;font-size:0.83rem;color:#14532d;">
                <strong>✅ 設置時の疎通確認手順:</strong> 装置からテスト患者ID（または実ID）で1枚送信後、院内管理者が「Modality Studio」画面を開くと送信装置のメーカー・型番・解像度が自動表示され、ワンクリックで本登録が完了します。
            </div>
        </div>
    </div>
</div>

<div class="rs-toast" id="rsToast"></div>

<script>
let studioProfiles = [];
let studioDiscovered = [];

function showToast(msg) {
    const t = document.getElementById('rsToast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3200);
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

async function loadStudio() {
    const res = await fetch('modality_api.php?action=list_studio');
    const data = await res.json();
    if (data.status !== 'success') return;
    studioProfiles = data.profiles || [];
    studioDiscovered = data.discovered || [];
    renderRadar();
    renderProfiles(null);
}

function renderRadar() {
    const unlinked = studioDiscovered.filter(d => !d.linked_profile_id);
    const sec = document.getElementById('radarSection');
    const list = document.getElementById('radarList');
    if (unlinked.length === 0) {
        sec.style.display = 'none';
        return;
    }
    sec.style.display = 'block';
    list.innerHTML = unlinked.map(d => `
        <div class="radar-card">
            <div>
                <div style="font-weight:800;color:#1e1b4b;font-size:0.92rem;">
                    ✨ [${esc(d.source_protocol)}] ${esc(d.manufacturer || '不明メーカー')} ${esc(d.model_name || '')}
                    ${d.dicom_modality ? `<span class="rule-chip">Modality: ${esc(d.dicom_modality)}</span>` : ''}
                    ${d.sample_resolution ? `<span class="rule-chip">${esc(d.sample_resolution)}</span>` : ''}
                </div>
                <div style="font-size:0.78rem;color:#475569;margin-top:3px;">
                    送信元IP: <code>${esc(d.source_ip || '-')}</code> / AE: <code>${esc(d.calling_ae_title || '-')}</code> / Station: <code>${esc(d.station_name || '-')}</code> / 着信回数: <strong>${d.seen_count}回</strong>
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-purple btn-sm" onclick="promoteDiscovered(${d.discovery_id})">✨ 1クリックで装置登録</button>
                <button type="button" class="btn btn-sm" onclick="dismissDiscovered(${d.discovery_id})">非表示</button>
            </div>
        </div>
    `).join('');
}

function renderProfiles(hitProfileCode) {
    const grid = document.getElementById('profilesGrid');
    grid.innerHTML = studioProfiles.map(p => {
        const rules = p.match_rules || {};
        const ruleChips = Object.entries(rules).map(([k, v]) => `<span class="rule-chip">${esc(k)}=${esc(v)}</span>`).join('');
        const isHit = hitProfileCode && p.profile_code === hitProfileCode;
        const vOpts = p.viewer_options || {};

        return `
            <div class="prof-card ${p.is_enabled ? '' : 'disabled'} ${isHit ? 'sim-hit' : ''}" id="pcard_${esc(p.profile_code)}">
                <div>
                    <div class="prof-head">
                        <div style="display:flex;gap:11px;align-items:center;">
                            <div class="prof-icon" style="background:${esc(p.badge_color)};">${esc(p.icon_emoji)}</div>
                            <div>
                                <div class="prof-title">${esc(p.device_name)}</div>
                                <div class="prof-sub">📍 ${esc(p.room_or_location || '未設定')} ・ <strong>${p.unit_count}台</strong> ・ <code>${esc(p.protocol)}</code></div>
                            </div>
                        </div>
                        <span class="rule-chip" style="font-weight:800;">優先 #${p.priority_order}</span>
                    </div>

                    <div style="margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span style="font-size:0.76rem;color:#64748b;font-weight:700;">出力バッジ:</span>
                        <span style="background:${esc(p.badge_color)};color:#fff;padding:3px 10px;border-radius:6px;font-size:0.79rem;font-weight:800;">
                            ${esc(p.icon_emoji)} ${esc(p.default_kensamei)}
                        </span>
                        <span style="font-size:0.74rem;color:#475569;">(${esc(p.kensamei_from_tag)})</span>
                        ${vOpts.olympus_circle_fit ? '<span class="rule-chip" style="background:#f0fdfa;border-color:#5eead4;color:#0f766e;">⭕ O社フィット</span>' : ''}
                    </div>

                    <div style="margin-top:8px;">
                        ${ruleChips || '<span style="font-size:0.75rem;color:#94a3b8;">条件なし（汎用フォールバック受け皿）</span>'}
                    </div>
                </div>

                <div style="border-top:1px dashed #e2e8f0;padding-top:10px;display:flex;align-items:center;justify-content:space-between;font-size:0.77rem;color:#64748b;">
                    <span>累積受信: <strong>${p.total_received}</strong> 件</span>
                    <div style="display:flex;gap:6px;">
                        <button type="button" class="btn btn-sm" onclick="toggleProfile(${p.profile_id}, ${!p.is_enabled})">${p.is_enabled ? '有効中' : '無効'}</button>
                        <button type="button" class="btn btn-sm btn-primary" onclick="openProfileModal(${p.profile_id})">⚙️ 設定編集</button>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function openProfileModal(profileId) {
    const p = profileId ? studioProfiles.find(x => x.profile_id === profileId) : null;
    document.getElementById('pmProfileId').value = p ? p.profile_id : '';
    document.getElementById('pmDiscoveryId').value = '';
    document.getElementById('pmDeviceName').value = p ? p.device_name : '';
    document.getElementById('pmProfileCode').value = p ? p.profile_code : '';
    document.getElementById('pmPriority').value = p ? p.priority_order : 50;
    document.getElementById('pmRoom').value = p ? p.room_or_location : '';
    document.getElementById('pmProtocol').value = p ? p.protocol : 'DICOM_SCP';
    document.getElementById('pmModalityCode').value = p ? p.modality_code : 'ES';
    document.getElementById('pmCategory').value = p ? p.display_category : 'endoscopy';
    document.getElementById('pmUnits').value = p ? p.unit_count : 1;
    document.getElementById('pmDefaultKensa').value = p ? p.default_kensamei : '';
    document.getElementById('pmStrategy').value = p ? p.kensamei_from_tag : 'FIXED';
    document.getElementById('pmEmoji').value = p ? p.icon_emoji : '🏥';
    document.getElementById('pmColor').value = p ? p.badge_color : '#2563eb';
    document.getElementById('pmNotes').value = p ? p.notes : '';

    const r = (p && p.match_rules) ? p.match_rules : {};
    document.getElementById('mrModality').value = r.dicom_modality || '';
    document.getElementById('mrStation').value = r.station_name || '';
    document.getElementById('mrAeTitle').value = r.calling_ae_title || '';
    document.getElementById('mrManufacturer').value = r.manufacturer_contains || '';
    document.getElementById('mrModel').value = r.model_contains || '';
    document.getElementById('mrSourceIp').value = r.source_ip || '';
    document.getElementById('mrMinWidth').value = r.min_width || '';
    document.getElementById('mrFileExt').value = r.file_ext || '';
    document.getElementById('mrSubfolder').value = r.watch_subfolder || '';

    const vo = (p && p.viewer_options) ? p.viewer_options : {};
    document.getElementById('pmOlympusFit').checked = !!vo.olympus_circle_fit;

    const delArea = document.getElementById('pmDeleteArea');
    if (p && p.profile_code !== 'GENERIC_EXTERNAL_MEDIA') {
        delArea.innerHTML = `<button type="button" class="btn btn-danger btn-sm" onclick="deleteProfile(${p.profile_id})">🗑️ 削除</button>`;
    } else {
        delArea.innerHTML = '';
    }
    document.getElementById('profileModal').classList.add('open');
}

function promoteDiscovered(discoveryId) {
    const d = studioDiscovered.find(x => x.discovery_id === discoveryId);
    if (!d) return;
    openProfileModal(null);
    document.getElementById('pmDiscoveryId').value = d.discovery_id;
    document.getElementById('pmDeviceName').value = `${d.manufacturer || '新規装置'} ${d.model_name || d.station_name || ''}`.trim();
    document.getElementById('pmProfileCode').value = `AUTO_${(d.dicom_modality || 'DEV')}_${d.discovery_id}`;
    document.getElementById('pmProtocol').value = d.source_protocol || 'DICOM_SCP';
    document.getElementById('pmModalityCode').value = d.dicom_modality || 'OT';
    document.getElementById('pmDefaultKensa').value = d.dicom_modality ? `${d.dicom_modality}_検査` : '新規装置検査';
    document.getElementById('mrModality').value = d.dicom_modality || '';
    document.getElementById('mrStation').value = d.station_name || '';
    document.getElementById('mrAeTitle').value = d.calling_ae_title || '';
    document.getElementById('mrManufacturer').value = d.manufacturer || '';
    document.getElementById('mrModel').value = d.model_name || '';
    document.getElementById('mrSourceIp').value = d.source_ip || '';
}

function closeProfileModal() {
    document.getElementById('profileModal').classList.remove('open');
}

async function saveProfile() {
    const payload = {
        action: 'save_profile',
        profile_id: document.getElementById('pmProfileId').value,
        discovery_id: document.getElementById('pmDiscoveryId').value,
        device_name: document.getElementById('pmDeviceName').value,
        profile_code: document.getElementById('pmProfileCode').value,
        priority_order: parseInt(document.getElementById('pmPriority').value, 10) || 100,
        room_or_location: document.getElementById('pmRoom').value,
        protocol: document.getElementById('pmProtocol').value,
        modality_code: document.getElementById('pmModalityCode').value,
        display_category: document.getElementById('pmCategory').value,
        unit_count: parseInt(document.getElementById('pmUnits').value, 10) || 1,
        default_kensamei: document.getElementById('pmDefaultKensa').value,
        kensamei_from_tag: document.getElementById('pmStrategy').value,
        icon_emoji: document.getElementById('pmEmoji').value,
        badge_color: document.getElementById('pmColor').value,
        notes: document.getElementById('pmNotes').value,
        match_rules: {
            dicom_modality: document.getElementById('mrModality').value.trim(),
            station_name: document.getElementById('mrStation').value.trim(),
            calling_ae_title: document.getElementById('mrAeTitle').value.trim(),
            manufacturer_contains: document.getElementById('mrManufacturer').value.trim(),
            model_contains: document.getElementById('mrModel').value.trim(),
            source_ip: document.getElementById('mrSourceIp').value.trim(),
            min_width: parseInt(document.getElementById('mrMinWidth').value, 10) || 0,
            file_ext: document.getElementById('mrFileExt').value.trim(),
            watch_subfolder: document.getElementById('mrSubfolder').value.trim(),
        },
        viewer_options: {
            olympus_circle_fit: document.getElementById('pmOlympusFit').checked,
        },
    };
    const res = await fetch('modality_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast('✅ プロファイルを保存し即時反映しました');
        closeProfileModal();
        loadStudio();
    } else {
        alert(out.message || '保存エラー');
    }
}

async function toggleProfile(profileId, enable) {
    await fetch('modality_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'toggle_profile', profile_id: profileId, is_enabled: enable }),
    });
    loadStudio();
}

async function deleteProfile(profileId) {
    if (!confirm('このプロファイルを削除しますか？（既存の検査データは消えません）')) return;
    await fetch('modality_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_profile', profile_id: profileId }),
    });
    closeProfileModal();
    loadStudio();
}

async function dismissDiscovered(discoveryId) {
    await fetch('modality_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'dismiss_discovered', discovery_id: discoveryId }),
    });
    loadStudio();
}

function openVendorSheetModal() {
    document.getElementById('vendorSheetModal').classList.add('open');
}

// D&D シミュレータ実行
const dz = document.getElementById('simDropzone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
dz.addEventListener('drop', e => {
    e.preventDefault();
    dz.classList.remove('dragover');
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
        runSimulation(e.dataTransfer.files[0]);
    }
});

async function runSimulation(file) {
    const box = document.getElementById('simResultBox');
    box.style.display = 'block';
    box.innerHTML = '<div style="font-size:0.84rem;color:#4f46e5;font-weight:700;">🔍 ファイルを解析してプロファイル照合中...</div>';

    const fd = new FormData();
    fd.append('action', 'simulate_drop');
    fd.append('sample_file', file);
    fd.append('source_ip', document.getElementById('simSourceIp').value.trim());
    fd.append('watch_subfolder', document.getElementById('simWatchSub').value.trim());

    const res = await fetch('modality_api.php', { method: 'POST', body: fd });
    const out = await res.json();
    if (out.status !== 'success') {
        box.innerHTML = `<div style="color:#dc2626;font-size:0.84rem;font-weight:700;">❌ ${esc(out.message)}</div>`;
        return;
    }

    const mp = out.matched_profile;
    const em = out.extracted_meta;
    renderProfiles(mp.profile_code);

    const cardEl = document.getElementById('pcard_' + mp.profile_code);
    if (cardEl) cardEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    box.innerHTML = `
        <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:12px 14px;">
            <div style="font-size:0.76rem;color:#166534;font-weight:800;margin-bottom:4px;">✅ マッチした装置プロファイル (優先 #${mp.priority_order})</div>
            <div style="font-weight:800;font-size:0.96rem;color:#0f172a;margin-bottom:8px;">${esc(mp.device_name)}</div>
            <div style="margin-bottom:8px;">
                <span style="font-size:0.78rem;color:#475569;">決定される検査バッジ: </span>
                <span style="background:${esc(mp.badge_color)};color:#fff;padding:4px 10px;border-radius:6px;font-weight:800;font-size:0.84rem;">
                    ${esc(mp.icon_emoji)} ${esc(mp.resolved_kensamei)}
                </span>
            </div>
            ${out.will_quarantine
                ? `<div style="background:#fffbeb;border:1px solid #fcd34d;color:#92400e;padding:6px 9px;border-radius:6px;font-size:0.78rem;font-weight:700;">⚠️ 患者ID判定: Quarantine隔離対象 (${esc(out.quarantine_reason)})</div>`
                : `<div style="font-size:0.8rem;color:#15803d;font-weight:700;">👤 正規化患者ID: <code>${esc(out.normalized_patient_id)}</code> ${out.patient_in_master ? `(${esc(out.patient_in_master.kanja_kanji)} 様)` : '(新患自動枠)'}</div>`
            }
            <div style="margin-top:8px;padding-top:8px;border-top:1px dashed #bbf7d0;font-size:0.76rem;color:#334155;line-height:1.5;">
                種別: <code>${esc(em.file_kind)}</code> / Modality: <code>${esc(em.dicom_modality || '-')}</code> / 解像度: <code>${esc(em.resolution || '-')}</code><br>
                Maker: <code>${esc(em.manufacturer || '-')}</code> / Model: <code>${esc(em.model_name || '-')}</code> / Station: <code>${esc(em.station_name || '-')}</code>
            </div>
        </div>
    `;
}

loadStudio();
</script>
</body>
</html>

