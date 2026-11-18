<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next 他院紹介CD (PDI/DICOMDIR)・動画・デジカメ写真・PDF スマート自動取込 (import_media.php)
// - 高知医療センター・近森病院等の紹介CDドライブ丸ごと選択 / フォルダD&D完全対応
// - IHE_PDI / VIEWER / VINFO 等の不要ファイルを自動除外し、DICOMDIR＋ヘッダから
//   紹介元病院・CD内患者氏名・撮影日・検査種別・シリーズ構成を0.3秒で自動識別
// - 当院の患者IDを入力するだけで複数検査・大容量動画(MP4/AVI/MOV/MPG)も安全一括格納
// ==============================================================================

require_once __DIR__ . '/lib/bootstrap.php';

$cfg = rsnext_config();
$rawPid = trim((string)($_GET['patient_id'] ?? ''));
$initPid = $rawPid !== '' ? (rsnext_normalize_patient_id($rawPid) ?? $rawPid) : '';
$initPatient = null;
$quarantineCount = 0;

try {
    $pdo = rsnext_pdo();
    $quarantineCount = rsnext_get_pending_quarantine_count($pdo);
    if ($initPid !== '') {
        $st = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
        $st->execute([$initPid]);
        $initPatient = $st->fetch() ?: null;
    }
} catch (Throwable $e) {
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💿 外部紹介CD・動画・画像・PDF スマート取込 - RS-Next</title>
    <link rel="stylesheet" href="assets/rsnext_ui.css">
    <style>
        .import-grid {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 18px;
            align-items: start;
        }
        @media (max-width: 1024px) {
            .import-grid { grid-template-columns: 1fr; }
        }
        .big-dropzone {
            border: 2.5px dashed #0284c7;
            background: linear-gradient(180deg, #f0f9ff 0%, #ffffff 100%);
            border-radius: 14px;
            padding: 26px 18px;
            text-align: center;
            cursor: pointer;
            transition: all 0.16s;
        }
        .big-dropzone:hover, .big-dropzone.dragover {
            background: #e0f2fe;
            border-color: #1d4ed8;
            transform: translateY(-1px);
        }
        .cd-action-row {
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 14px;
        }
        .btn-cd-hero {
            background: linear-gradient(135deg, #0284c7 0%, #1d4ed8 100%);
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 0.92rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.28);
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }
        .btn-cd-hero:hover { filter: brightness(1.06); }
        .cd-banner {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #f8fafc;
            border-radius: 12px;
            padding: 14px 16px;
            margin-top: 14px;
            border: 1px solid #334155;
        }
        .cd-study-card {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 11px 13px;
            margin-top: 9px;
            transition: all 0.15s;
        }
        .cd-study-card.checked {
            border-color: #2563eb;
            background: #f8fafc;
        }
        .series-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #e2e8f0;
            color: #1e293b;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
        }
        .cand-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            text-align: left;
            background: #ecfdf5;
            border: 1.5px solid #34d399;
            color: #065f46;
            border-radius: 8px;
            padding: 8px 11px;
            margin-top: 6px;
            cursor: pointer;
            font-size: 0.84rem;
            font-weight: 700;
        }
        .cand-btn:hover { background: #d1fae5; }
        .preset-chip {
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: var(--bg-subtle);
            font-size: 0.76rem;
            font-weight: 700;
            cursor: pointer;
        }
        .preset-chip:hover { background: var(--primary-soft); border-color: #93c5fd; color: var(--primary); }
    </style>
</head>
<body>

<header class="rs-topbar">
    <div style="display:flex;align-items:center;gap:14px;">
        <a href="index.php" class="rs-brand">
            <span class="rs-brand-logo">🏥 RS-Next</span>
            <span class="rs-brand-sub">外部メディア自動識別取込</span>
        </a>
        <?php if ($quarantineCount > 0): ?>
            <a href="ingest_dashboard.php#quarantine" class="rs-quarantine-pill">⚠️ 未紐付け <?= (int)$quarantineCount ?> 件</a>
        <?php endif; ?>
    </div>
    <nav class="rs-nav">
        <a href="index.php" class="rs-nav-link">🖥️ 検査一覧</a>
        <a href="worklist.php" class="rs-nav-link">📋 撮影依頼・MWL</a>
        <a href="modality_studio.php" class="rs-nav-link">🎛️ Modality Studio</a>
        <a href="import_media.php" class="rs-nav-link active">💿 外部CD/動画取込</a>
        <a href="ingest_dashboard.php" class="rs-nav-link">📡 受信モニター</a>
        <a href="hardware_monitor.php" class="rs-nav-link">🖥️ ハード監視</a>
        <a href="backup_manager.php" class="rs-nav-link">🛡️ バックアップ</a>
    </nav>
</header>

<main class="rs-main">
    <div class="rs-card" style="background:linear-gradient(135deg,#0f172a 0%,#0369a1 100%);color:#fff;border:none;padding:16px 20px;">
        <h1 style="font-size:1.28rem;font-weight:800;margin-bottom:4px;">💿 他院紹介CD（CT/MRI等）・検査動画・写真・PDF スマート自動取込</h1>
        <p style="font-size:0.84rem;color:#bae6fd;margin:0;">
            高知医療センター・近森病院等の紹介CDドライブを丸ごと選択（またはドロップ）すると、付属ビューワ等を自動除外し、<strong>撮影日・検査種別・紹介元病院</strong>をDICOMから自動抽出します。<strong>当院の患者IDを入力するだけ</strong>で取り込めます。
        </p>
    </div>

    <div class="import-grid">
        <!-- 左カラム Step 1: CDドライブ / ファイル選択 & 自動解析プレビュー -->
        <div class="rs-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <h3 style="font-size:1.02rem;font-weight:800;color:var(--primary);margin:0;">① 紹介CDドライブ または 動画・画像・PDFを選択</h3>
                <button type="button" class="btn btn-sm" onclick="clearQueuedFiles()">🗑️ クリア</button>
            </div>

            <div class="big-dropzone" id="importDropzone">
                <div style="font-size:2.3rem;margin-bottom:4px;">💿 🌀 🎬 📸 📄</div>
                <div style="font-size:1.02rem;font-weight:800;color:#0c4a6e;">
                    ここにCDドライブ・フォルダ・動画(MP4/AVI/MOV)・写真・PDFをドロップ
                </div>
                <div style="font-size:0.8rem;color:var(--text-muted);margin-top:4px;">
                    紹介CDの場合は <code>Q:\</code> 等のドライブ直下をそのまま選択しても <code>VIEWER</code> / <code>IHE_PDI</code> 等の不要ファイルは自動除外されます
                </div>
                <div class="cd-action-row" onclick="event.stopPropagation();">
                    <button type="button" class="btn-cd-hero" onclick="document.getElementById('folderPicker').click()">
                        💿 紹介CDドライブ / フォルダ丸ごと選択
                    </button>
                    <button type="button" class="btn" onclick="document.getElementById('filePicker').click()">
                        🎬 動画・画像・PDFファイル選択
                    </button>
                </div>
                <input type="file" id="filePicker" multiple style="display:none;" onchange="handleFileInput(this.files, false)">
                <input type="file" id="folderPicker" webkitdirectory directory multiple style="display:none;" onchange="handleFileInput(this.files, true)">
            </div>

            <!-- CD解析中インジケータ -->
            <div id="cdInspectLoading" style="display:none;margin-top:12px;padding:12px;border-radius:10px;background:#eff6ff;color:#1d4ed8;font-weight:700;font-size:0.88rem;">
                🔍 CD内の DICOMDIR・検査ヘッダを自動識別しています...
            </div>

            <!-- CD / DICOM 自動識別結果パネル -->
            <div id="cdAutoPanel" style="display:none;">
                <div class="cd-banner" id="cdSummaryBanner"></div>
                <div id="cdCandidateBox" style="margin-top:8px;"></div>
                <div style="margin-top:12px;font-size:0.84rem;font-weight:800;color:#334155;display:flex;justify-content:space-between;align-items:center;">
                    <span>📋 自動識別された検査（撮影日・検査種別は自動セット済み／必要に応じて編集可）</span>
                    <span id="excludedJunkBadge" style="font-size:0.74rem;color:#64748b;font-weight:600;"></span>
                </div>
                <div id="cdStudiesList"></div>
            </div>

            <!-- 一般ファイル（動画・デジカメ写真・PDF）キュー表示 -->
            <div id="looseFilesPanel" style="display:none;margin-top:14px;">
                <div style="font-size:0.85rem;font-weight:800;color:#334155;margin-bottom:6px;">
                    📂 選択中のファイル (<span id="looseCountLabel">0</span>件)
                </div>
                <div id="queuedListBox" style="max-height:200px;overflow-y:auto;border:1px solid var(--border-light);border-radius:8px;padding:6px;font-size:0.8rem;color:var(--text-sub);"></div>
            </div>
        </div>

        <!-- 右カラム Step 2: 当院の患者ID入力 & ワンクリック取込実行 -->
        <div class="rs-card">
            <h3 style="font-size:1.02rem;font-weight:800;color:var(--primary);margin-bottom:10px;">② 当院の患者IDを入力して取り込み</h3>

            <div style="background:#eff6ff;border:2px solid #60a5fa;border-radius:12px;padding:13px 15px;margin-bottom:14px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <label class="form-label" style="color:#1e40af;font-weight:800;margin:0;">当院の患者ID（半角数字）*</label>
                    <button type="button" class="btn btn-sm" onclick="document.getElementById('newPatModal').classList.add('open')">＋新患手動登録</button>
                </div>
                <input type="text" id="selectedPid" class="input" value="<?= rsnext_h($initPid) ?>"
                       placeholder="例: 80343 （ここに入力するだけでOK）"
                       style="margin-top:6px;font-weight:800;font-size:1.18rem;letter-spacing:0.03em;"
                       oninput="onPidDirectInput(this.value)"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();executeBatchImport();}">
                <div id="selectedPatLabel" style="font-size:0.86rem;color:#0f172a;font-weight:700;margin-top:7px;min-height:20px;">
                    <?php if ($initPatient): ?>
                        ✅ 当院カルテ確認: 👤 <?= rsnext_h($initPatient['kanja_kanji']) ?> (<?= rsnext_h($initPatient['kanja_yomi']) ?>) ／ <?= rsnext_h($initPatient['birth_date'] ?? '') ?> <?= rsnext_h($initPatient['sex'] ?? '') ?>
                    <?php else: ?>
                        <span style="color:#64748b;font-weight:500;font-size:0.8rem;">患者IDを入力すると当院マスタの氏名が自動表示されます</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 氏名・カナからの補助検索 -->
            <div style="margin-bottom:14px;">
                <label class="form-label" style="font-size:0.78rem;color:var(--text-muted);">患者IDが不明な場合：氏名・カナ検索</label>
                <input type="text" id="patSearchInput" class="input" placeholder="氏名またはフリガナで検索..." oninput="searchPatients()">
                <div id="patSearchResults" style="max-height:150px;overflow-y:auto;border:1px solid var(--border-light);border-radius:8px;margin-top:4px;display:none;"></div>
            </div>

            <!-- 取込実行ボタン -->
            <button type="button" class="btn btn-primary" id="startImportBtn" onclick="executeBatchImport()" disabled
                    style="width:100%;padding:13px 16px;font-size:1.02rem;font-weight:800;justify-content:center;">
                🚀 当院患者IDへ取り込み開始 (<span id="queueCount">0</span>ファイル)
            </button>

            <!-- 進捗バー & 完了リンク -->
            <div id="importProgressBox" style="display:none;margin-top:14px;background:#f8fafc;border:1px solid var(--border-light);border-radius:10px;padding:13px;">
                <div id="importProgressText" style="font-weight:800;font-size:0.88rem;color:var(--primary);margin-bottom:7px;">準備中...</div>
                <div style="height:10px;background:#e2e8f0;border-radius:999px;overflow:hidden;">
                    <div id="importProgressBar" style="height:100%;width:0%;background:linear-gradient(90deg,#2563eb,#0d9488);transition:width 0.18s;"></div>
                </div>
                <div id="importDoneActions" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;"></div>
            </div>

            <!-- オプション詳細（デジカメ・動画・PDF等の非DICOM時、またはメモ追記用・折りたたみ） -->
            <details id="optionalMetaDetails" style="margin-top:16px;border-top:1px solid var(--border-light);padding-top:12px;">
                <summary style="cursor:pointer;font-size:0.82rem;font-weight:800;color:#475569;">
                    ⚙️ 所見メモ追記・非DICOMファイル（動画・デジカメ・PDF）用の任意設定
                </summary>
                <div style="margin-top:10px;">
                    <div style="margin-bottom:8px;">
                        <label class="form-label">非DICOM用の検査名プリセット（DICOM CDは自動識別されるため空欄でOK）</label>
                        <div style="display:flex;flex-wrap:wrap;gap:5px;">
                            <?php foreach (['内視鏡動画', '超音波動画', 'デジカメ', '紹介状・検査PDF', '骨密度レポート', '他院紹介CT', '他院紹介MRI'] as $pr): ?>
                                <button type="button" class="preset-chip" onclick="document.getElementById('impKensamei').value='<?= $pr ?>'"><?= $pr ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:8px;margin-bottom:8px;">
                        <div>
                            <label class="form-label">検査種別上書き（空欄＝自動判定）</label>
                            <input type="text" id="impKensamei" class="input" placeholder="空欄でDICOMから自動設定">
                        </div>
                        <div>
                            <label class="form-label">非DICOM用の検査日</label>
                            <input type="date" id="impDate" class="input" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div style="margin-bottom:8px;">
                        <label class="form-label">紹介元医療機関 / 担当医（CDから自動抽出時は空欄でOK）</label>
                        <input type="text" id="impDoctor" class="input" placeholder="空欄でCDの施設名を自動反映">
                    </div>
                    <div style="margin-bottom:8px;">
                        <label class="form-label">共通所見・コメントメモ</label>
                        <textarea id="impFindings" class="input" rows="2" placeholder="紹介状コメント・検査メモ等（任意）"></textarea>
                    </div>
                    <label style="display:flex;align-items:center;gap:7px;font-size:0.8rem;font-weight:700;cursor:pointer;">
                        <input type="checkbox" id="impGroupSingle" checked>
                        複数枚のデジカメ写真・動画を1つの検査枠にまとめる（※DICOM検査は検査UIDごとに自動で正しく分割されます）
                    </label>
                </div>
            </details>
        </div>
    </div>
</main>

<!-- 新患手動登録モーダル -->
<div class="modal-backdrop" id="newPatModal">
    <div class="modal-box" style="max-width:460px;">
        <div class="modal-head">
            <h3>👤 患者マスタ手動登録</h3>
            <button type="button" class="btn btn-sm" onclick="document.getElementById('newPatModal').classList.remove('open')">✕</button>
        </div>
        <div class="modal-body">
            <div style="margin-bottom:10px;">
                <label class="form-label">当院の患者ID（半角数字）*</label>
                <input type="text" id="npId" class="input" placeholder="例: 85001">
            </div>
            <div style="margin-bottom:10px;">
                <label class="form-label">患者漢字氏名 *</label>
                <input type="text" id="npKanji" class="input" placeholder="例: 清水　康宏">
            </div>
            <div style="margin-bottom:10px;">
                <label class="form-label">フリガナ</label>
                <input type="text" id="npYomi" class="input" placeholder="例: シミズ ヤスヒロ">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                    <label class="form-label">生年月日</label>
                    <input type="date" id="npBirth" class="input">
                </div>
                <div>
                    <label class="form-label">性別</label>
                    <select id="npSex" class="input">
                        <option value="男">男</option>
                        <option value="女">女</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <span></span>
            <button type="button" class="btn btn-primary" onclick="saveNewPatient()">登録して選択</button>
        </div>
    </div>
</div>

<div class="rs-toast" id="rsToast"></div>

<script>
// 内部状態
let queuedItems = []; // [{ file: File, relPath: string }]
let dicomdirFile = null;
let cdManifest = null; // inspect_cd の結果
let excludedJunkCount = 0;
let pidLookupTimer = null;

const PDI_JUNK_DIRS = new Set([
    'ihe_pdi', 'viewer', 'vinfo', 'plugins', 'jre', 'bin', 'html', 'help',
    'asset', 'assets', 'common', 'system volume information', '$recycle.bin', 'autorun'
]);
const SKIP_BASENAMES = new Set([
    'dicomdir', 'autorun.inf', 'thumbs.db', 'desktop.ini', 'index.htm', 'index.html',
    'readme.txt', 'cdid.txt', 'patient.htm', 'patient_data.js'
]);
const SKIP_EXTS = new Set([
    'html', 'htm', 'js', 'css', 'exe', 'dll', 'bat', 'cmd', 'inf', 'ini',
    'dat', 'idx', 'thu', 'jar', 'cab', 'ocx', 'lnk', 'gif', 'ico', 'xml', 'xsl'
]);

function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[m]);
}

function showToast(msg) {
    const t = document.getElementById('rsToast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3400);
}

// 患者ID直接入力時のリアルタイム氏名確認
function onPidDirectInput(rawVal) {
    clearTimeout(pidLookupTimer);
    const pid = rawVal.trim();
    const label = document.getElementById('selectedPatLabel');
    if (!pid) {
        label.innerHTML = '<span style="color:#64748b;font-weight:500;font-size:0.8rem;">患者IDを入力すると当院マスタの氏名が自動表示されます</span>';
        return;
    }
    pidLookupTimer = setTimeout(async () => {
        try {
            const res = await fetch(`study_edit_api.php?action=lookup_patient&query=${encodeURIComponent(pid)}`);
            const data = await res.json();
            const exact = (data.patients || []).find(p => String(p.patient_id) === String(pid).replace(/^0+/, ''));
            if (exact) {
                label.innerHTML = `✅ 当院カルテ一致: <strong style="color:#0369a1;">👤 ${esc(exact.kanja_kanji)}</strong> (${esc(exact.kanja_yomi)}) ／ ${esc(exact.birth_date || '')} ${esc(exact.sex || '')}`;
            } else if (/^\d+$/.test(pid)) {
                const cdKanji = cdManifest?.cd_patient?.kanji || '';
                label.innerHTML = `<span style="color:#b45309;">ℹ️ 当院DB未登録のID（取込時に ${cdKanji ? `CD記載氏名「${esc(cdKanji)}」で` : ''}自動枠作成されます）</span>`;
            }
        } catch (_) {}
    }, 220);
}

async function searchPatients() {
    const q = document.getElementById('patSearchInput').value.trim();
    const box = document.getElementById('patSearchResults');
    if (!q) {
        box.style.display = 'none';
        return;
    }
    const res = await fetch(`study_edit_api.php?action=lookup_patient&query=${encodeURIComponent(q)}`);
    const data = await res.json();
    box.style.display = 'block';
    if (!data.patients || data.patients.length === 0) {
        box.innerHTML = '<div style="padding:8px;font-size:0.8rem;color:#64748b;">該当なし（上の患者ID欄へ直接入力も可能です）</div>';
        return;
    }
    box.innerHTML = data.patients.map(p => `
        <div style="padding:7px 10px;border-bottom:1px solid #f1f5f9;cursor:pointer;display:flex;justify-content:space-between;align-items:center;"
             onclick="selectPatient('${esc(p.patient_id)}', '${esc(p.kanja_kanji)}', '${esc(p.kanja_yomi)}', '${esc(p.birth_date || '')}', '${esc(p.sex || '')}')">
            <div><strong>ID: ${esc(p.patient_id)}</strong> — ${esc(p.kanja_kanji)} <small style="color:#64748b;">(${esc(p.kanja_yomi)}) ${esc(p.birth_date || '')}</small></div>
            <span class="btn btn-sm">セット</span>
        </div>
    `).join('');
}

function selectPatient(pid, kanji, yomi, birth = '', sex = '') {
    document.getElementById('selectedPid').value = pid;
    document.getElementById('selectedPatLabel').innerHTML =
        `✅ 当院カルテ選択: <strong style="color:#0369a1;">👤 ${esc(kanji)}</strong> (${esc(yomi)}) ${birth ? '／ ' + esc(birth) : ''} ${esc(sex)}`;
    document.getElementById('patSearchResults').style.display = 'none';
    showToast(`👤 患者ID ${pid} (${kanji}) をセットしました`);
}

async function saveNewPatient() {
    const payload = {
        action: 'upsert_patient',
        patient_id: document.getElementById('npId').value,
        kanja_kanji: document.getElementById('npKanji').value,
        kanja_yomi: document.getElementById('npYomi').value,
        birth_date: document.getElementById('npBirth').value,
        sex: document.getElementById('npSex').value,
    };
    const res = await fetch('study_edit_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const out = await res.json();
    if (out.status === 'success') {
        selectPatient(out.patient_id, payload.kanja_kanji, payload.kanja_yomi, payload.birth_date, payload.sex);
        document.getElementById('newPatModal').classList.remove('open');
    } else {
        alert(out.message || '登録エラー');
    }
}

// フォルダD&D時の再帰ディレクトリ走査 (webkitGetAsEntry)
const dz = document.getElementById('importDropzone');
dz.addEventListener('click', () => document.getElementById('folderPicker').click());
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
dz.addEventListener('drop', async e => {
    e.preventDefault();
    dz.classList.remove('dragover');
    if (e.dataTransfer.items && e.dataTransfer.items.length > 0) {
        const entries = [];
        for (let i = 0; i < e.dataTransfer.items.length; i++) {
            const it = e.dataTransfer.items[i];
            const entry = it.webkitGetAsEntry ? it.webkitGetAsEntry() : null;
            if (entry) entries.push(entry);
        }
        if (entries.length > 0) {
            excludedJunkCount = 0;
            const collected = [];
            for (const ent of entries) {
                await traverseFileTree(ent, '', collected);
            }
            processRawCandidates(collected, true);
            return;
        }
    }
    if (e.dataTransfer.files) {
        handleFileInput(e.dataTransfer.files, false);
    }
});

function normalizeCdRelPath(rawRel) {
    let r = String(rawRel || '').replace(/\\/g, '/').replace(/^\/+/, '');
    const up = r.toUpperCase();
    // ドライブ名やボリュームラベル (例: "AOC_MINI/DICOM/..." や "Q:/DICOM/...") が先頭に付いている場合 CDルート相対 "DICOM/..." に正規化
    const dcmIdx = up.indexOf('/DICOM/');
    if (dcmIdx !== -1) {
        return r.slice(dcmIdx + 1);
    }
    if (up.endsWith('/DICOMDIR')) {
        return 'DICOMDIR';
    }
    return r;
}

async function traverseFileTree(entry, prefix, outList) {
    if (entry.isFile) {
        const file = await new Promise(res => entry.file(res, () => res(null)));
        if (file) {
            const rel = prefix ? `${prefix}/${file.name}` : file.name;
            outList.push({ file, relPath: rel });
        }
    } else if (entry.isDirectory) {
        const dirNameLow = (entry.name || '').toLowerCase();
        if (PDI_JUNK_DIRS.has(dirNameLow)) {
            excludedJunkCount += 1;
            return;
        }
        const reader = entry.createReader();
        const cleanName = (entry.name || '').replace(/^\/+|\/+$/g, '');
        const nextPrefix = prefix ? (cleanName ? `${prefix}/${cleanName}` : prefix) : cleanName;
        while (true) {
            const batch = await new Promise(res => reader.readEntries(res, () => res([])));
            if (!batch || batch.length === 0) break;
            for (const child of batch) {
                await traverseFileTree(child, nextPrefix, outList);
            }
        }
    }
}

function handleFileInput(fileList, isFolderMode) {
    excludedJunkCount = 0;
    const raw = [];
    for (const f of fileList) {
        let rel = (f.webkitRelativePath || f.name || '').replace(/\\/g, '/');
        if (isFolderMode && rel.includes('/')) {
            const segs = rel.split('/').filter(Boolean);
            if (segs.length >= 2 && (segs[1].toUpperCase() === 'DICOMDIR' || segs[1].toUpperCase() === 'DICOM' || PDI_JUNK_DIRS.has(segs[1].toLowerCase()))) {
                rel = segs.slice(1).join('/');
            } else {
                rel = segs.join('/');
            }
        }
        raw.push({ file: f, relPath: rel });
    }
    processRawCandidates(raw, true);
}

async function processRawCandidates(candidates, keepPriorJunkCount = false) {
    queuedItems = [];
    dicomdirFile = null;
    cdManifest = null;
    if (!keepPriorJunkCount) excludedJunkCount = 0;

    for (const c of candidates) {
        const relNorm = normalizeCdRelPath(c.relPath);
        const segs = relNorm.split('/').filter(Boolean);
        const baseLow = (segs[segs.length - 1] || c.file.name).toLowerCase();

        if (baseLow === 'dicomdir') {
            dicomdirFile = c.file;
            continue;
        }

        // PDI不要フォルダ (IHE_PDI, VIEWER, VINFO, HELP 等) の自動除外
        let inJunkDir = false;
        for (let i = 0; i < segs.length - 1; i++) {
            if (PDI_JUNK_DIRS.has(segs[i].toLowerCase())) {
                inJunkDir = true;
                break;
            }
        }
        if (inJunkDir) {
            excludedJunkCount++;
            continue;
        }

        if (SKIP_BASENAMES.has(baseLow)) {
            excludedJunkCount++;
            continue;
        }

        const dotParts = baseLow.split('.');
        const ext = dotParts.length > 1 ? dotParts[dotParts.length - 1] : '';
        if (ext && SKIP_EXTS.has(ext)) {
            excludedJunkCount++;
            continue;
        }
        if (ext === 'txt') {
            excludedJunkCount++;
            continue;
        }

        // PDI CD の場合で DICOM/ 配下と ルート雑多ファイルが混在していれば DICOM/ 側およびPDF/動画を優先
        queuedItems.push({ file: c.file, relPath: relNorm });
    }

    renderQueueSummary();

    // DICOMDIR または 拡張子なし/dcm ファイルがある場合は自動CDヘッダ識別を実行
    const likelyDicomItems = queuedItems.filter(it => {
        const b = it.file.name.toLowerCase();
        const ext = b.includes('.') ? b.split('.').pop() : '';
        return ext === '' || ext === 'dcm';
    });

    if (dicomdirFile || likelyDicomItems.length > 0) {
        await runAutoCdInspect(likelyDicomItems);
    }
}

async function runAutoCdInspect(dicomItems) {
    const loadEl = document.getElementById('cdInspectLoading');
    loadEl.style.display = 'block';
    document.getElementById('cdAutoPanel').style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('action', 'inspect_cd');
        if (dicomdirFile) {
            fd.append('dicomdir', dicomdirFile, 'DICOMDIR');
        }

        // サブフォルダごとに先頭2ファイル（Scout + 本スライス）だけサンプル送信して施設名・プロトコル名を0.2秒で取得
        const folderCounts = {};
        let sampleAdded = 0;
        for (const it of dicomItems) {
            const parentKey = it.relPath.includes('/') ? it.relPath.slice(0, it.relPath.lastIndexOf('/')) : '_root';
            folderCounts[parentKey] = (folderCounts[parentKey] || 0) + 1;
            if (folderCounts[parentKey] <= 2 && sampleAdded < 14) {
                fd.append('samples[]', it.file, it.file.name);
                fd.append('sample_rel_paths[]', it.relPath);
                sampleAdded++;
            }
        }

        const res = await fetch('modality_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        loadEl.style.display = 'none';

        if (data.status === 'success' && (data.studies?.length > 0 || data.cd_patient?.kanji)) {
            cdManifest = data;
            renderCdManifestUI(data);
        }
    } catch (e) {
        loadEl.style.display = 'none';
        console.warn('CD auto inspect warning:', e);
    }
}

function renderCdManifestUI(data) {
    const panel = document.getElementById('cdAutoPanel');
    const banner = document.getElementById('cdSummaryBanner');
    const candBox = document.getElementById('cdCandidateBox');
    const listBox = document.getElementById('cdStudiesList');
    const junkBadge = document.getElementById('excludedJunkBadge');

    panel.style.display = 'block';
    document.getElementById('looseFilesPanel').style.display = 'none';

    const inst = data.institution || '外部医療機関';
    const equip = [data.manufacturer, data.model_name].filter(Boolean).join(' ');
    const cp = data.cd_patient || {};

    // 新患登録モーダルの初期値にも自動セット（未登録患者だった場合に1クリック登録できるよう配慮）
    if (cp.kanji) document.getElementById('npKanji').value = cp.kanji;
    if (cp.kana) document.getElementById('npYomi').value = cp.kana;
    if (cp.birth_date) document.getElementById('npBirth').value = cp.birth_date;
    if (cp.sex === '男' || cp.sex === '女') document.getElementById('npSex').value = cp.sex;

    banner.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
            <div>
                <div style="font-size:0.76rem;color:#38bdf8;font-weight:800;">💿 紹介メディア自動識別完了</div>
                <div style="font-size:1.08rem;font-weight:800;margin-top:2px;">
                    🏥 ${esc(inst)} ${equip ? `<span style="font-size:0.78rem;color:#94a3b8;font-weight:600;">(${esc(equip)})</span>` : ''}
                </div>
            </div>
            <div style="background:rgba(255,255,255,0.09);padding:6px 12px;border-radius:8px;font-size:0.83rem;">
                <div>CD内患者: <strong style="color:#fde68a;font-size:0.95rem;">${esc(cp.kanji || '氏名未記載')}</strong> ${cp.kana ? `<small>(${esc(cp.kana)})</small>` : ''}</div>
                <div style="color:#cbd5e1;font-size:0.76rem;">
                    生年月日: ${esc(cp.birth_date || '-')} ${cp.age !== null && cp.age !== undefined ? `(${cp.age}歳)` : ''} ／ 性別: ${esc(cp.sex || '-')} ／ 先方ID: <code>${esc(cp.ext_patient_id || '-')}</code>
                </div>
            </div>
        </div>
    `;

    junkBadge.textContent = excludedJunkCount > 0 ? `🧹 付属ビューワ等 ${excludedJunkCount} ファイルを自動除外済` : '';

    // 当院患者マスタ候補サジェスト
    const cands = data.clinic_candidates || [];
    if (cands.length > 0) {
        candBox.innerHTML = cands.map(c => `
            <button type="button" class="cand-btn"
                    onclick="selectPatient('${esc(c.patient_id)}', '${esc(c.kanja_kanji)}', '${esc(c.kanja_yomi)}', '${esc(c.birth_date)}', '${esc(c.sex)}')">
                <span>💡 当院カルテ候補 (${esc(c.match_type)}): <strong>ID ${esc(c.patient_id)} — ${esc(c.kanja_kanji)}</strong> (${esc(c.kanja_yomi)}) ${esc(c.birth_date)}</span>
                <span style="background:#059669;color:#fff;padding:3px 10px;border-radius:6px;font-size:0.78rem;">この患者IDをセット ➔</span>
            </button>
        `).join('');
        // まだ患者IDが空欄で、氏名完全一致または生年月日＋カナ完全一致が1件だけなら自動セット！
        const curPid = document.getElementById('selectedPid').value.trim();
        if (!curPid && cands.length === 1 && (cands[0].match_type === '氏名一致' || cands[0].match_type === '生年月日＋カナ完全一致')) {
            selectPatient(cands[0].patient_id, cands[0].kanja_kanji, cands[0].kanja_yomi, cands[0].birth_date, cands[0].sex);
        }
    } else {
        candBox.innerHTML = '';
    }

    // 検査リスト描画
    const studies = data.studies || [];
    listBox.innerHTML = studies.map((st, idx) => {
        const serHtml = (st.series || []).map(s =>
            `<span class="series-pill">S${s.series_number}: ${esc(s.desc)} (${s.count}枚)</span>`
        ).join(' ');
        const dateDisp = (st.study_date || '日付自動').replace(/-/g, '/');
        return `
            <div class="cd-study-card checked" id="cdStudyCard_${idx}">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:800;color:#0f172a;">
                        <input type="checkbox" class="cd-study-chk" data-idx="${idx}" checked onchange="onStudyToggleChange()">
                        <span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:6px;font-family:Consolas,monospace;">
                            📅 ${esc(dateDisp)} ${esc(st.study_time || '')}
                        </span>
                        <span style="background:#0284c7;color:#fff;padding:2px 7px;border-radius:5px;font-size:0.76rem;">
                            ${esc(st.modality)}
                        </span>
                        <span style="font-size:0.82rem;color:#475569;">計 ${st.file_count || '?'} 枚</span>
                    </label>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="font-size:0.76rem;color:#64748b;font-weight:700;">検査名:</span>
                        <input type="text" class="input cd-kensa-input" data-idx="${idx}" value="${esc(st.kensamei)}"
                               style="width:190px;padding:4px 8px;font-size:0.84rem;font-weight:700;">
                    </div>
                </div>
                ${serHtml ? `<div style="margin-top:7px;display:flex;flex-wrap:wrap;gap:5px;">${serHtml}</div>` : ''}
            </div>
        `;
    }).join('');

    updateActiveCountFromStudies();
    document.getElementById('selectedPid').focus();
}

function onStudyToggleChange() {
    document.querySelectorAll('.cd-study-chk').forEach(chk => {
        const idx = chk.dataset.idx;
        const card = document.getElementById('cdStudyCard_' + idx);
        if (card) card.classList.toggle('checked', chk.checked);
    });
    updateActiveCountFromStudies();
}

function getSelectedItemsForImport() {
    if (!cdManifest || !cdManifest.studies || cdManifest.studies.length === 0) {
        return queuedItems;
    }
    const chks = Array.from(document.querySelectorAll('.cd-study-chk'));
    const checkedChks = chks.filter(c => c.checked);
    if (checkedChks.length === 0) {
        return [];
    }
    // 全検査にチェックが入っている場合はキュー内の全DICOMスライスをそのまま対象にする（ドライブ名・ラベル差の影響ゼロ）
    if (checkedChks.length === cdManifest.studies.length) {
        return queuedItems;
    }

    const allowedRelSet = new Set();
    const allowedSuffixes = [];
    checkedChks.forEach(chk => {
        const st = cdManifest.studies[parseInt(chk.dataset.idx, 10)];
        if (st && Array.isArray(st.rel_paths) && st.rel_paths.length > 0) {
            st.rel_paths.forEach(rp => {
                const norm = rp.replace(/\\/g, '/').replace(/^\/+/, '').toUpperCase();
                allowedRelSet.add(norm);
                allowedSuffixes.push('/' + norm);
            });
        }
    });
    if (allowedRelSet.size === 0) {
        return queuedItems;
    }
    const matched = queuedItems.filter(it => {
        const relUp = normalizeCdRelPath(it.relPath).toUpperCase();
        if (allowedRelSet.has(relUp) || allowedRelSet.has('DICOM/' + relUp)) return true;
        return allowedSuffixes.some(suf => relUp.endsWith(suf));
    });
    return matched.length > 0 ? matched : queuedItems;
}

function updateActiveCountFromStudies() {
    const items = getSelectedItemsForImport();
    document.getElementById('queueCount').textContent = String(items.length);
    document.getElementById('startImportBtn').disabled = items.length === 0;
}

function clearQueuedFiles() {
    queuedItems = [];
    dicomdirFile = null;
    cdManifest = null;
    excludedJunkCount = 0;
    document.getElementById('cdAutoPanel').style.display = 'none';
    document.getElementById('cdInspectLoading').style.display = 'none';
    document.getElementById('filePicker').value = '';
    document.getElementById('folderPicker').value = '';
    renderQueueSummary();
}

function renderQueueSummary() {
    const count = queuedItems.length;
    document.getElementById('queueCount').textContent = String(count);
    document.getElementById('startImportBtn').disabled = count === 0;

    const loosePanel = document.getElementById('looseFilesPanel');
    const box = document.getElementById('queuedListBox');
    document.getElementById('looseCountLabel').textContent = String(count);

    if (count === 0) {
        loosePanel.style.display = 'none';
        box.innerHTML = '';
        return;
    }
    loosePanel.style.display = 'block';
    const preview = queuedItems.slice(0, 35).map((it, i) => {
        const mb = (it.file.size / (1024 * 1024));
        const szTxt = mb >= 1 ? `${mb.toFixed(1)} MB` : `${Math.max(1, Math.round(it.file.size / 1024))} KB`;
        return `<div style="padding:3px 6px;border-bottom:1px solid #f1f5f9;">#${i+1} <code>${esc(it.relPath)}</code> (${szTxt})</div>`;
    }).join('');
    const more = count > 35 ? `<div style="padding:6px;color:#64748b;">...他 ${count - 35} ファイル</div>` : '';
    box.innerHTML = preview + more;
}

async function executeBatchImport() {
    const pid = document.getElementById('selectedPid').value.trim();
    if (!pid) {
        alert('当院の患者IDを入力してください');
        document.getElementById('selectedPid').focus();
        return;
    }
    const targetItems = getSelectedItemsForImport();
    if (targetItems.length === 0) {
        alert('取り込む検査またはファイルを選択してください');
        return;
    }

    // StudyInstanceUID ごとの検査種別名・紹介元病院オーバーライド辞書を構築
    const studyOverrides = {};
    if (cdManifest && Array.isArray(cdManifest.studies)) {
        document.querySelectorAll('.cd-kensa-input').forEach(inp => {
            const idx = parseInt(inp.dataset.idx, 10);
            const st = cdManifest.studies[idx];
            if (st && st.study_uid) {
                studyOverrides[st.study_uid] = {
                    kensamei: inp.value.trim() || st.kensamei,
                    doctor: document.getElementById('impDoctor').value.trim() || st.institution || cdManifest.institution || '',
                    findings: document.getElementById('impFindings').value.trim(),
                };
            }
        });
    }

    const pBox = document.getElementById('importProgressBox');
    const pBar = document.getElementById('importProgressBar');
    const pTxt = document.getElementById('importProgressText');
    const doneActions = document.getElementById('importDoneActions');
    pBox.style.display = 'block';
    doneActions.innerHTML = '';
    document.getElementById('startImportBtn').disabled = true;

    const kensameiManual = document.getElementById('impKensamei').value.trim();
    const dateVal = document.getElementById('impDate').value;
    const doctorVal = document.getElementById('impDoctor').value.trim() || (cdManifest?.institution || '');
    const findingsVal = document.getElementById('impFindings').value.trim();
    const groupNonDicom = document.getElementById('impGroupSingle').checked;

    let totalImported = 0;
    let totalDup = 0;
    let nonDicomStudyId = null;
    const createdStudyIds = new Set();
    let processedCount = 0;

    // 大容量ファイル (> 12MB: HD動画等) と 通常スライス群 に分離して安全・高速送信
    const LARGE_THRESHOLD = 12 * 1024 * 1024;
    const CHUNK_BYTES = 8 * 1024 * 1024;
    const MAX_BATCH_FILES = 12;
    const MAX_BATCH_BYTES = 10 * 1024 * 1024;

    const smallBatches = [];
    const largeItems = [];
    let curBatch = [];
    let curBytes = 0;

    for (const it of targetItems) {
        if (it.file.size > LARGE_THRESHOLD) {
            largeItems.push(it);
        } else {
            if (curBatch.length >= MAX_BATCH_FILES || (curBatch.length > 0 && curBytes + it.file.size > MAX_BATCH_BYTES)) {
                smallBatches.push(curBatch);
                curBatch = [];
                curBytes = 0;
            }
            curBatch.push(it);
            curBytes += it.file.size;
        }
    }
    if (curBatch.length > 0) smallBatches.push(curBatch);

    try {
        // 1. 通常ファイル群バッチ送信
        for (const batch of smallBatches) {
            const fd = new FormData();
            fd.append('action', 'import_files');
            fd.append('patient_id', pid);
            fd.append('kensamei', kensameiManual);
            fd.append('study_date', dateVal);
            fd.append('doctor', doctorVal);
            fd.append('findings', findingsVal);
            if (groupNonDicom) fd.append('group_single_study', '1');
            if (nonDicomStudyId) fd.append('force_non_dicom_study_id', String(nonDicomStudyId));
            fd.append('study_overrides', JSON.stringify(studyOverrides));

            batch.forEach(it => {
                fd.append('files[]', it.file, it.file.name);
                fd.append('rel_paths[]', it.relPath);
            });

            pTxt.textContent = `取り込み中... (${Math.min(processedCount + batch.length, targetItems.length)} / ${targetItems.length} ファイル)`;
            const res = await fetch('modality_api.php', { method: 'POST', body: fd });
            const rawTxt = await res.text();
            let out;
            try {
                out = JSON.parse(rawTxt);
            } catch (_) {
                throw new Error(`サーバ応答解析エラー (HTTP ${res.status}): ` + rawTxt.slice(0, 200));
            }
            if (out.status !== 'success') {
                throw new Error(out.message || '取込エラーが発生しました');
            }
            totalImported += (out.imported_count || 0);
            totalDup += (out.duplicate_count || 0);
            if (out.non_dicom_study_id) nonDicomStudyId = out.non_dicom_study_id;
            (out.study_ids || []).forEach(id => createdStudyIds.add(id));
            if (out.study_id) createdStudyIds.add(out.study_id);

            processedCount += batch.length;
            pBar.style.width = `${Math.round((processedCount / targetItems.length) * 100)}%`;
        }

        // 2. 大容量動画ファイル等の 8MB チャンク分割アップロード
        for (const it of largeItems) {
            const totalChunks = Math.max(1, Math.ceil(it.file.size / CHUNK_BYTES));
            const uploadId = Date.now() + '_' + Math.random().toString(36).slice(2, 8);
            for (let c = 0; c < totalChunks; c++) {
                const start = c * CHUNK_BYTES;
                const end = Math.min(it.file.size, start + CHUNK_BYTES);
                const blob = it.file.slice(start, end);

                const fd = new FormData();
                fd.append('action', 'upload_chunk');
                fd.append('patient_id', pid);
                fd.append('upload_id', uploadId);
                fd.append('chunk_index', String(c));
                fd.append('total_chunks', String(totalChunks));
                fd.append('orig_filename', it.file.name);
                fd.append('kensamei', kensameiManual);
                fd.append('study_date', dateVal);
                fd.append('doctor', doctorVal);
                fd.append('findings', findingsVal);
                if (groupNonDicom) fd.append('group_single_study', '1');
                if (nonDicomStudyId) fd.append('force_non_dicom_study_id', String(nonDicomStudyId));
                fd.append('chunk', blob, it.file.name);

                const mbDone = Math.round(end / (1024 * 1024));
                const mbTotal = Math.round(it.file.size / (1024 * 1024));
                pTxt.textContent = `🎬 大容量動画アップロード中: ${it.file.name} (${mbDone} / ${mbTotal} MB)`;

                const res = await fetch('modality_api.php', { method: 'POST', body: fd });
                const out = await res.json();
                if (c === totalChunks - 1) {
                    if (out.status !== 'success') throw new Error(out.message || '動画取込エラー');
                    totalImported += (out.imported_count || 0);
                    totalDup += (out.duplicate_count || 0);
                    if (out.non_dicom_study_id) nonDicomStudyId = out.non_dicom_study_id;
                    (out.study_ids || []).forEach(id => createdStudyIds.add(id));
                    if (out.study_id) createdStudyIds.add(out.study_id);
                } else if (out.status !== 'chunk_ok') {
                    throw new Error(out.message || 'チャンク転送エラー');
                }
            }
            processedCount++;
            pBar.style.width = `${Math.round((processedCount / targetItems.length) * 100)}%`;
        }

        pBar.style.width = '100%';
        const sids = Array.from(createdStudyIds);
        pTxt.innerHTML = `✅ 取込完了！ 新規格納 <strong>${totalImported}</strong> ファイル（重複スキップ ${totalDup} 件 ／ 検査枠 ${sids.length} 件）`;

        let btnsHtml = `<a href="index.php?patient_id=${encodeURIComponent(pid)}&all_dates=1" class="btn btn-teal btn-sm">🖥️ 患者ID ${esc(pid)} の全検査一覧を開く ➔</a>`;
        sids.forEach((sid, idx) => {
            btnsHtml += `<a href="dicom_view.php?study_id=${sid}" target="_blank" class="btn btn-primary btn-sm">👁️ ビューワで開く (#${sid}) ↗</a>`;
        });
        doneActions.innerHTML = btnsHtml;
        showToast('✅ 外部データの取り込みが完了しました');
    } catch (err) {
        alert(err.message || '取込中にエラーが発生しました');
        document.getElementById('startImportBtn').disabled = false;
    }
}
</script>
</body>
</html>
