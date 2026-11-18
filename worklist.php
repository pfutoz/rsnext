<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next 撮影依頼・DICOM MWL ワークリスト ＆ 検査室バーコードボード (worklist.php)
// - 電子カルテ URL 連携 (?patient_id=XXXXX) 対応：1クリック複数検査同時オーダー発行
// - DICOM MWL (SHIMADZU CXDI / ARIETTA 65 / TEAC MV-3C) リアルタイム配信＆C-FIND実機シミュレータ
// - フクダ電子心電計 (FCP-8300 / FCP-8321) 等のための高コントラスト画面 Code39 バーコード表示・指示票印刷
// ==============================================================================

require_once __DIR__ . '/lib/bootstrap.php';

$cfg = rsnext_config();
$rawPid = trim((string)($_GET['patient_id'] ?? $_GET['id'] ?? ''));
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
    <title>📋 撮影依頼・DICOM MWL ワークリスト - RS-Next</title>
    <link rel="stylesheet" href="assets/rsnext_ui.css">
    <style>
        .wl-layout {
            display: grid;
            grid-template-columns: 440px 1fr;
            gap: 18px;
            align-items: start;
        }
        @media (max-width: 1080px) {
            .wl-layout { grid-template-columns: 1fr; }
        }

        /* 検査プリセット選択グリッド */
        .preset-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 12px;
        }
        .preset-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 11px;
            border-radius: 9px;
            border: 2px solid var(--border-light);
            background: #ffffff;
            cursor: pointer;
            text-align: left;
            font-family: inherit;
            transition: all 0.14s ease;
        }
        .preset-btn:hover {
            border-color: #93c5fd;
            background: #f8fbff;
        }
        .preset-btn.selected {
            border-color: var(--primary);
            background: #eff6ff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .preset-emoji {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.05rem;
            flex-shrink: 0;
        }
        .preset-title { font-size: 0.82rem; font-weight: 800; color: #0f172a; line-height: 1.25; }
        .preset-mod { font-size: 0.69rem; color: var(--text-muted); font-weight: 700; }

        /* 受付患者クイックピル */
        .recent-pat-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #1e293b;
            cursor: pointer;
            margin: 2px;
        }
        .recent-pat-pill:hover { background: #dbeafe; border-color: #60a5fa; color: #1e40af; }

        /* 右側: 検査室ボードカード */
        .room-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .room-tab {
            padding: 6px 13px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: var(--bg-subtle);
            font-size: 0.8rem;
            font-weight: 800;
            color: var(--text-sub);
            cursor: pointer;
        }
        .room-tab.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
        }

        .order-card {
            background: #ffffff;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            padding: 13px 16px;
            margin-bottom: 10px;
            display: grid;
            grid-template-columns: 130px 1fr auto;
            gap: 14px;
            align-items: center;
            transition: all 0.15s;
        }
        @media (max-width: 820px) {
            .order-card { grid-template-columns: 1fr; }
        }
        .order-card.st-IN_PROGRESS {
            border-color: #0284c7;
            background: #f0f9ff;
        }
        .order-card.st-COMPLETED {
            opacity: 0.72;
            background: #f8fafc;
        }

        .st-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 800;
        }
        .st-pill-SCHEDULED   { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .st-pill-IN_PROGRESS { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
        .st-pill-COMPLETED   { background: #dcfce7; color: #166534; border: 1px solid #86efac; }

        /* バーコード指示票印刷専用ルール */
        @media print {
            body * { visibility: hidden; }
            #barcodeModal, #barcodeModal * { visibility: visible; }
            #barcodeModal { position: absolute; inset: 0; background: #ffffff; display: block !important; padding: 0; }
            #barcodeModal .modal-box { box-shadow: none; border: 2px solid #000; max-width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<header class="rs-topbar no-print">
    <div style="display:flex;align-items:center;gap:14px;">
        <a href="index.php" class="rs-brand">
            <span class="rs-brand-logo">🏥 RS-Next</span>
            <span class="rs-brand-sub">撮影依頼・DICOM MWL ワークリスト</span>
        </a>
        <?php if ($quarantineCount > 0): ?>
            <a href="ingest_dashboard.php#quarantine" class="rs-quarantine-pill">⚠️ 未紐付け <?= (int)$quarantineCount ?> 件</a>
        <?php endif; ?>
    </div>
    <nav class="rs-nav">
        <a href="index.php" class="rs-nav-link">🖥️ 検査一覧</a>
        <a href="worklist.php" class="rs-nav-link active">📋 撮影依頼・MWL</a>
        <a href="modality_studio.php" class="rs-nav-link">🎛️ Modality Studio</a>
        <a href="import_media.php" class="rs-nav-link">💿 外部CD/画像取込</a>
        <a href="ingest_dashboard.php" class="rs-nav-link">📡 受信モニター</a>
        <a href="hardware_monitor.php" class="rs-nav-link">🖥️ ハード監視</a>
        <a href="backup_manager.php" class="rs-nav-link">🛡️ バックアップ</a>
        <a href="仕様書/mwl_order_spec.html" target="_blank" class="rs-nav-link">📚 MWL仕様書</a>
    </nav>
</header>

<main class="rs-main no-print">

    <div class="wl-layout">
        <!-- 左カラム: 診察室・受付 ワンクリック撮影依頼フォーム -->
        <section class="rs-card" style="position:sticky;top:70px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <h2 style="font-size:1.05rem;font-weight:800;color:var(--primary);">🩺 撮影・検査依頼の発行</h2>
                <span style="font-size:0.75rem;color:var(--teal);font-weight:800;">MWL＋バーコード同時配信</span>
            </div>

            <!-- 1. 患者選択 -->
            <div style="margin-bottom:10px;">
                <label class="form-label">① 患者検索（ID・氏名・カナ）または直近受付患者から選択</label>
                <input type="text" id="patQuery" class="input" placeholder="患者ID（例: 70755）または氏名を入力..." oninput="lookupPatients()">
                <div id="patLookupList" style="display:none;max-height:150px;overflow-y:auto;border:1px solid var(--border-light);border-radius:8px;margin-top:4px;background:#fff;"></div>
            </div>

            <div id="recentPatientsBox" style="margin-bottom:10px;"></div>

            <div style="background:linear-gradient(135deg,#eff6ff,#f0fdfa);border:1.5px solid #93c5fd;border-radius:10px;padding:10px 12px;margin-bottom:14px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                    <span style="font-size:0.76rem;color:#1e40af;font-weight:800;">対象患者ID *</span>
                    <input type="text" id="orderPid" class="input" value="<?= rsnext_h($initPid) ?>" placeholder="半角数字ID" style="width:150px;font-weight:800;padding:5px 9px;">
                </div>
                <div id="orderPatSummary" style="font-size:0.86rem;font-weight:800;color:#0f172a;margin-top:5px;">
                    <?php if ($initPatient): ?>
                        👤 <?= rsnext_h($initPatient['kanja_kanji']) ?> <small style="color:#475569;">(<?= rsnext_h($initPatient['kanja_yomi']) ?>)</small>
                    <?php else: ?>
                        <span style="color:#64748b;font-weight:600;font-size:0.8rem;">患者を選択またはIDを入力してください</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. 検査プリセット選択（複数同時選択可） -->
            <label class="form-label">② 依頼する検査を選択（複数同時クリックで一括オーダー可）</label>
            <div class="preset-grid" id="presetGrid"></div>

            <!-- 3. オプション詳細 -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                <div>
                    <label class="form-label">予定日</label>
                    <input type="date" id="orderDate" class="input" value="<?= date('Y-m-d') ?>" onchange="loadWorklist()">
                </div>
                <div>
                    <label class="form-label">依頼医</label>
                    <input type="text" id="orderDoctor" class="input" placeholder="例: 小野院長">
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label class="form-label">検査室への申し送り・部位コメント（任意）</label>
                <input type="text" id="orderComment" class="input" placeholder="例: 抗血栓薬なし・鎮静希望 / 立位・臥位2方向">
            </div>

            <button type="button" class="btn btn-primary" style="width:100%;padding:11px;font-size:0.94rem;" onclick="submitOrders()">
                🚀 撮影依頼を発行する (<span id="selCount">0</span>件選択中)
            </button>
        </section>

        <!-- 右カラム: 検査室リアルタイム待ちリスト ＆ MWL/バーコード運用ボード -->
        <section>
            <!-- KPI & MWL テストツールバー -->
            <div class="rs-card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;">
                    <div>
                        <div style="font-size:0.74rem;color:var(--text-muted);font-weight:700;">🟡 撮影待ち (MWL配信中)</div>
                        <div style="font-size:1.3rem;font-weight:800;color:#b45309;" id="kpiScheduled">0 件</div>
                    </div>
                    <div>
                        <div style="font-size:0.74rem;color:var(--text-muted);font-weight:700;">🔵 撮影中 (MPPS)</div>
                        <div style="font-size:1.3rem;font-weight:800;color:#0284c7;" id="kpiInProgress">0 件</div>
                    </div>
                    <div>
                        <div style="font-size:0.74rem;color:var(--text-muted);font-weight:700;">🟢 本日完了 (画像紐付済)</div>
                        <div style="font-size:1.3rem;font-weight:800;color:#15803d;" id="kpiCompleted">0 件</div>
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-purple btn-sm" onclick="openMwlTestModal()">🧪 DICOM MWL 実通信テスト (C-FIND)</button>
                    <button type="button" class="btn btn-sm" onclick="loadWorklist()">🔄 更新</button>
                </div>
            </div>

            <!-- 検査室フィルタタブ -->
            <div class="rs-card" style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div class="room-tabs" id="roomTabs">
                    <button type="button" class="room-tab active" onclick="setRoomFilter('all', this)">すべて</button>
                    <button type="button" class="room-tab" onclick="setRoomFilter('endoscopy', this)">🔬 内視鏡室 (MV-3C/EP-8000)</button>
                    <button type="button" class="room-tab" onclick="setRoomFilter('xray', this)">🩻 レントゲン・透視室 (CXDI)</button>
                    <button type="button" class="room-tab" onclick="setRoomFilter('us', this)">🔊 エコー室 (ARIETTA 65)</button>
                    <button type="button" class="room-tab" onclick="setRoomFilter('ecg', this)">📈 処置室 心電図 (FCP-8300/8321)</button>
                </div>
                <label style="font-size:0.8rem;font-weight:700;color:var(--text-sub);cursor:pointer;display:flex;align-items:center;gap:5px;">
                    <input type="checkbox" id="chkShowCompleted" checked onchange="loadWorklist()"> 完了済も表示
                </label>
            </div>

            <!-- オーダーカードリスト -->
            <div id="ordersList"></div>
        </section>
    </div>

</main>

<!-- 📊 画面バーコード読み取り ＆ 検査指示票印刷モーダル（心電計 FCP-8300/8321・非MWL装置用） -->
<div class="modal-backdrop" id="barcodeModal">
    <div class="modal-box" style="max-width:560px;">
        <div class="modal-head no-print">
            <h3>📊 検査室バーコード読み取り・指示票</h3>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">🖨️ 指示票を印刷</button>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('barcodeModal').classList.remove('open')">✕ 閉じる</button>
            </div>
        </div>
        <div class="modal-body" style="text-align:center;padding:28px 24px;">
            <div style="font-size:0.82rem;color:#64748b;font-weight:700;" id="bcOrderSub"></div>
            <div style="font-size:1.5rem;font-weight:800;color:#0f172a;margin:6px 0;" id="bcPatientName"></div>
            <div style="font-size:0.9rem;color:#334155;margin-bottom:18px;" id="bcPatientMeta"></div>

            <!-- 液晶画面から直接バーコードスキャナで100%読み取れる高コントラスト白余白付きSVG Code39 -->
            <div style="background:#ffffff;border:3px solid #0f172a;border-radius:12px;padding:22px 24px;display:inline-block;">
                <div style="font-size:0.75rem;color:#475569;font-weight:700;margin-bottom:8px;">患者ID バーコード (Code39 — 心電計・内視鏡バーコードリーダー対応)</div>
                <svg id="bcSvgCode39" width="380" height="96"></svg>
                <div style="font-family:Consolas,monospace;font-size:1.6rem;font-weight:800;letter-spacing:0.18em;color:#000;margin-top:6px;" id="bcPidText"></div>
            </div>

            <div style="margin-top:16px;font-size:0.84rem;color:#334155;" id="bcCommentBox"></div>
        </div>
    </div>
</div>

<!-- 🧪 DICOM MWL 実パケット疎通シミュレータモーダル -->
<div class="modal-backdrop no-print" id="mwlTestModal">
    <div class="modal-box" style="max-width:780px;">
        <div class="modal-head">
            <h3>🧪 DICOM MWL (C-FIND) 実パケット照会テスト</h3>
            <button type="button" class="btn btn-sm" onclick="document.getElementById('mwlTestModal').classList.remove('open')">✕ 閉じる</button>
        </div>
        <div class="modal-body">
            <p style="font-size:0.84rem;color:var(--text-sub);margin-bottom:12px;">
                ローカルで稼働中の DICOM MWL SCP サーバへ実際に <code>pynetdicom C-FIND</code> パケットを送信し、島津 CXDI・ARIETTA 65・TEAC MV-3C が受け取る DICOM タグ（JIS <code>ISO 2022 IR 87</code> 3コンポーネント氏名等）を確認できます。<br>
                <strong>💡 スマートID照会テスト:</strong> 事前オーダーを出していない患者IDでも、患者ID欄に入力して検索するとマスタから自動応答されることを確認できます。
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px;align-items:end;margin-bottom:14px;">
                <div>
                    <label class="form-label">Modality フィルタ</label>
                    <select id="mwlTestMod" class="input">
                        <option value="">すべて (指定なし)</option>
                        <option value="DX">DX (島津レントゲン CXDI)</option>
                        <option value="RF">RF (島津X線透視)</option>
                        <option value="US">US (エコー ARIETTA 65)</option>
                        <option value="ES">ES (内視鏡 MV-3C / EP-8000)</option>
                        <option value="ECG">ECG (心電図)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">PatientID (ID照会テスト用)</label>
                    <input type="text" id="mwlTestPid" class="input" placeholder="空欄=一覧 / 例: 70755">
                </div>
                <div>
                    <label class="form-label">検査予定日</label>
                    <input type="date" id="mwlTestDate" class="input" value="<?= date('Y-m-d') ?>">
                </div>
                <button type="button" class="btn btn-purple" onclick="runMwlCfindTest()">📡 C-FIND 送信</button>
            </div>
            <div id="mwlTestResult" style="max-height:360px;overflow-y:auto;"></div>
        </div>
    </div>
</div>

<div class="rs-toast" id="rsToast"></div>

<script>
let allPresets = [];
let selectedPresets = new Set();
let currentRoom = 'all';

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

async function lookupPatients() {
    const q = document.getElementById('patQuery').value.trim();
    const box = document.getElementById('patLookupList');
    if (!q) {
        box.style.display = 'none';
        return;
    }
    const res = await fetch(`study_edit_api.php?action=lookup_patient&query=${encodeURIComponent(q)}`);
    const data = await res.json();
    box.style.display = 'block';
    if (!data.patients || data.patients.length === 0) {
        if (data.normalized_id) {
            document.getElementById('orderPid').value = data.normalized_id;
        }
        box.innerHTML = '<div style="padding:7px 10px;font-size:0.8rem;color:#64748b;">マスタ未登録ID（このまま番号指定で発行可能）</div>';
        return;
    }
    box.innerHTML = data.patients.map(p => `
        <div style="padding:7px 10px;border-bottom:1px solid #f1f5f9;cursor:pointer;display:flex;justify-content:space-between;align-items:center;"
             onclick="choosePatient('${esc(p.patient_id)}', '${esc(p.kanja_kanji)}', '${esc(p.kanja_yomi)}', '${esc(p.age)}', '${esc(p.sex)}')">
            <div><strong>ID: ${esc(p.patient_id)}</strong> — ${esc(p.kanja_kanji)} <small style="color:#64748b;">(${esc(p.kanja_yomi)}) ${p.age ? p.age+'歳' : ''} ${esc(p.sex)}</small></div>
            <span class="btn btn-sm">選択</span>
        </div>
    `).join('');
}

function choosePatient(pid, kanji, yomi, age, sex) {
    document.getElementById('orderPid').value = pid;
    document.getElementById('orderPatSummary').innerHTML =
        `👤 <strong>${esc(kanji)}</strong> <small style="color:#475569;">(${esc(yomi)}) ${age ? esc(age)+'歳' : ''} ${esc(sex)}</small>`;
    document.getElementById('patLookupList').style.display = 'none';
}

function togglePreset(code) {
    if (selectedPresets.has(code)) {
        selectedPresets.delete(code);
    } else {
        selectedPresets.add(code);
    }
    renderPresets();
}

function renderPresets() {
    document.getElementById('selCount').textContent = String(selectedPresets.size);
    document.getElementById('presetGrid').innerHTML = allPresets.map(pr => {
        const isSel = selectedPresets.has(pr.preset_code);
        return `
            <button type="button" class="preset-btn ${isSel ? 'selected' : ''}" onclick="togglePreset('${esc(pr.preset_code)}')">
                <span class="preset-emoji" style="background:${esc(pr.badge_color)};">${esc(pr.icon_emoji)}</span>
                <div>
                    <div class="preset-title">${esc(pr.preset_label)}</div>
                    <div class="preset-mod">${esc(pr.modality)} ➔ ${esc(pr.kensamei)}</div>
                </div>
            </button>
        `;
    }).join('');
}

async function loadWorklist() {
    const dt = document.getElementById('orderDate').value;
    const showComp = document.getElementById('chkShowCompleted').checked ? '1' : '0';
    const res = await fetch(`worklist_api.php?action=list_worklist&date=${encodeURIComponent(dt)}&room_group=${encodeURIComponent(currentRoom)}&show_completed=${showComp}`);
    const data = await res.json();
    if (data.status !== 'success') return;

    allPresets = data.presets || [];
    renderPresets();

    document.getElementById('kpiScheduled').textContent = `${data.kpis.SCHEDULED || 0} 件`;
    document.getElementById('kpiInProgress').textContent = `${data.kpis.IN_PROGRESS || 0} 件`;
    document.getElementById('kpiCompleted').textContent = `${data.kpis.COMPLETED || 0} 件`;

    // 直近受付患者ピル
    const rpBox = document.getElementById('recentPatientsBox');
    if (data.recent_patients && data.recent_patients.length > 0) {
        rpBox.innerHTML = `<div style="font-size:0.73rem;color:#64748b;font-weight:700;margin-bottom:3px;">⚡ 直近受付・登録患者（クリックで即セット）:</div>`
            + data.recent_patients.map(p =>
                `<button type="button" class="recent-pat-pill" onclick="choosePatient('${esc(p.patient_id)}','${esc(p.kanja_kanji)}','${esc(p.kanja_yomi)}','${esc(p.age)}','${esc(p.sex)}')">${esc(p.patient_id)}: ${esc(p.kanja_kanji)}</button>`
            ).join('');
    }

    // オーダーカード描画
    const listEl = document.getElementById('ordersList');
    const orders = data.orders || [];
    if (orders.length === 0) {
        listEl.innerHTML = `<div class="rs-card" style="text-align:center;color:var(--text-muted);padding:36px;">現在この条件の撮影依頼はありません。左パネルからワンクリックで発行できます。</div>`;
        return;
    }

    const stLabels = {
        'SCHEDULED': '🟡 撮影待ち (MWL配信中)',
        'IN_PROGRESS': '🔵 撮影中 (MPPS)',
        'COMPLETED': '🟢 撮影完了',
    };

    listEl.innerHTML = orders.map(o => {
        const bCol = o.badge_color || '#2563eb';
        const emoji = o.icon_emoji || '🩺';
        const tm = o.scheduled_time ? o.scheduled_time.slice(0, 5) : '';
        const viewUrl = o.linked_study_id
            ? ((o.linked_file_type === 'ECG' ? 'ecg_view.php' : 'dicom_view.php') + '?study_id=' + o.linked_study_id)
            : '';

        return `
            <div class="order-card st-${esc(o.status)}">
                <div>
                    <span class="st-pill st-pill-${esc(o.status)}">${stLabels[o.status] || esc(o.status)}</span>
                    <div style="font-family:Consolas,monospace;font-size:0.74rem;color:#64748b;margin-top:5px;">
                        Acc: ${esc(o.accession_number)}<br>予定: ${esc(tm)}
                    </div>
                </div>

                <div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span style="background:${esc(bCol)};color:#fff;padding:3px 10px;border-radius:6px;font-size:0.82rem;font-weight:800;">
                            ${esc(emoji)} ${esc(o.procedure_desc || o.kensamei)}
                        </span>
                        <span style="font-family:Consolas,monospace;font-weight:800;font-size:0.95rem;color:#1d4ed8;">ID: ${esc(o.patient_id)}</span>
                        <strong style="font-size:1.02rem;color:#0f172a;">${esc(o.kanja_kanji)}</strong>
                        <span style="font-size:0.8rem;color:#475569;">(${esc(o.kanja_yomi)}) ${o.age ? esc(o.age)+'歳' : ''} ${esc(o.sex)}</span>
                    </div>
                    <div style="font-size:0.78rem;color:#475569;margin-top:5px;">
                        保存検査名: <code>${esc(o.kensamei)}</code>
                        ${o.device_name ? ` ／ 対象装置: <strong>${esc(o.device_name)}</strong>` : ''}
                        ${o.requesting_doctor ? ` ／ 依頼医: ${esc(o.requesting_doctor)}` : ''}
                        ${o.order_comment ? `<span style="color:#b45309;font-weight:700;margin-left:8px;">💬 ${esc(o.order_comment)}</span>` : ''}
                    </div>
                </div>

                <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                    <button type="button" class="btn btn-sm"
                            onclick='openBarcodeModal(${JSON.stringify({
                                pid: o.patient_id,
                                name: o.kanja_kanji,
                                yomi: o.kanja_yomi,
                                age: o.age,
                                sex: o.sex,
                                proc: o.procedure_desc || o.kensamei,
                                acc: o.accession_number,
                                comment: o.order_comment || ""
                            })})'>📊 バーコード</button>

                    ${o.status === 'SCHEDULED'
                        ? `<button type="button" class="btn btn-sm" onclick="changeOrderStatus(${o.order_id}, 'IN_PROGRESS')">▶ 開始</button>`
                        : ''}
                    ${o.status !== 'COMPLETED'
                        ? `<button type="button" class="btn btn-sm" onclick="changeOrderStatus(${o.order_id}, 'CANCELLED')">取消</button>`
                        : ''}
                    ${viewUrl
                        ? `<a href="${esc(viewUrl)}" target="_blank" class="btn btn-teal btn-sm">🖼️ 撮影画像を開く</a>`
                        : ''}
                </div>
            </div>
        `;
    }).join('');
}

function setRoomFilter(rg, btn) {
    currentRoom = rg;
    document.querySelectorAll('#roomTabs .room-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    loadWorklist();
}

async function submitOrders() {
    const pid = document.getElementById('orderPid').value.trim();
    if (!pid) {
        alert('対象患者IDを選択または入力してください');
        return;
    }
    if (selectedPresets.size === 0) {
        alert('依頼する検査ボタンを1つ以上クリックして選択してください');
        return;
    }

    const payload = {
        action: 'create_orders',
        patient_id: pid,
        preset_codes: Array.from(selectedPresets),
        scheduled_date: document.getElementById('orderDate').value,
        requesting_doctor: document.getElementById('orderDoctor').value.trim(),
        order_comment: document.getElementById('orderComment').value.trim(),
    };

    const res = await fetch('worklist_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast(`✅ ${out.count}件の撮影依頼を発行しました（DICOM MWL 即時配信中）`);
        selectedPresets.clear();
        document.getElementById('orderComment').value = '';
        loadWorklist();
    } else {
        alert(out.message || 'オーダー発行エラー');
    }
}

async function changeOrderStatus(orderId, status) {
    if (status === 'CANCELLED' && !confirm('この撮影依頼を取り消しますか？')) return;
    await fetch('worklist_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_order_status', order_id: orderId, status }),
    });
    loadWorklist();
}

// ---------------------------------------------------------------------------
// 純粋SVG Code39 バーコード描画（医療機器スキャナで100%読める標準規格・外部ライブラリ不要）
// ---------------------------------------------------------------------------
const CODE39_PATTERNS = {
    '0': 'nnnwwnwnn', '1': 'wnnwnnnnw', '2': 'nnwwnnnnw', '3': 'wnwwnnnnn',
    '4': 'nnnwwnnnw', '5': 'wnnwwnnnn', '6': 'nnwwwnnnn', '7': 'nnnwnnwnw',
    '8': 'wnnwnnwnn', '9': 'nnwwnnwnn', '*': 'nwnnwnwnn', '-': 'nwnnnnwnw'
};

function renderCode39Svg(svgEl, rawText) {
    const clean = String(rawText).replace(/[^0-9\-]/g, '');
    const encoded = `*${clean}*`;
    const narrow = 3;
    const wide = 7;

    let bars = [];
    let x = 20; // クワイエットゾーン
    for (let idx = 0; idx < encoded.length; idx++) {
        const pat = CODE39_PATTERNS[encoded[idx]];
        if (!pat) continue;
        for (let i = 0; i < 9; i++) {
            const w = pat[i] === 'w' ? wide : narrow;
            if (i % 2 === 0) {
                bars.push(`<rect x="${x}" y="6" width="${w}" height="82" fill="#000000"/>`);
            }
            x += w;
        }
        x += narrow; // 文字間ギャップ
    }
    const totalW = x + 20;
    svgEl.setAttribute('viewBox', `0 0 ${totalW} 94`);
    svgEl.setAttribute('width', Math.min(440, Math.max(260, totalW)));
    svgEl.innerHTML = `<rect width="${totalW}" height="94" fill="#ffffff"/>` + bars.join('');
}

function openBarcodeModal(info) {
    document.getElementById('bcOrderSub').textContent = `検査指示: ${info.proc}  (Acc: ${info.acc})`;
    document.getElementById('bcPatientName').textContent = `${info.name} 様 (${info.yomi})`;
    document.getElementById('bcPatientMeta').textContent = `患者ID: ${info.pid} ／ ${info.age ? info.age + '歳' : ''} ${info.sex}`;
    document.getElementById('bcPidText').textContent = info.pid;
    document.getElementById('bcCommentBox').textContent = info.comment ? `申し送り: ${info.comment}` : '';
    renderCode39Svg(document.getElementById('bcSvgCode39'), info.pid);
    document.getElementById('barcodeModal').classList.add('open');
}

// ---------------------------------------------------------------------------
// DICOM MWL C-FIND 実パケットテスト
// ---------------------------------------------------------------------------
function openMwlTestModal() {
    document.getElementById('mwlTestModal').classList.add('open');
    runMwlCfindTest();
}

async function runMwlCfindTest() {
    const box = document.getElementById('mwlTestResult');
    box.innerHTML = '<div style="padding:12px;color:#4f46e5;font-weight:700;">📡 ローカル DICOM MWL SCP へ C-FIND パケット送信中...</div>';
    const payload = {
        action: 'test_mwl_cfind',
        modality: document.getElementById('mwlTestMod').value,
        patient_id: document.getElementById('mwlTestPid').value.trim(),
        sched_date: document.getElementById('mwlTestDate').value,
    };
    const res = await fetch('worklist_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const out = await res.json();
    if (out.status !== 'success') {
        box.innerHTML = `<div style="padding:12px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;font-weight:700;">❌ ${esc(out.message)}</div>`;
        return;
    }

    if (out.count === 0) {
        box.innerHTML = `<div style="padding:12px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;color:#475569;">該当するMWL応答は 0 件でした（Port ${out.port} 正常応答 Success 0x0000）。</div>`;
        return;
    }

    box.innerHTML = `<div style="font-size:0.8rem;color:#15803d;font-weight:800;margin-bottom:8px;">✅ DICOM MWL SCP (${esc(out.host)}:${out.port}) から ${out.count} 件のワークリスト項目を受信しました:</div>`
        + out.items.map(it => `
            <div style="background:#0f172a;color:#e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:8px;font-family:Consolas,monospace;font-size:0.78rem;line-height:1.55;">
                <div>(0010,0020) PatientID   : <strong style="color:#38bdf8;">${esc(it.patient_id)}</strong>   | (0010,0040) Sex: ${esc(it.sex)} | (0010,0030) DOB: ${esc(it.birth_date)}</div>
                <div>(0010,0010) PatientName : <strong style="color:#fde047;">${esc(it.patient_name_dicom)}</strong></div>
                <div>(0008,0050) AccessionNo : ${esc(it.accession_number)} | (0008,0060) Modality: <strong>${esc(it.modality)}</strong> | Date: ${esc(it.start_date)} ${esc(it.start_time)}</div>
                <div>(0040,0007) StepDesc    : <span style="color:#86efac;">${esc(it.procedure_desc)}</span> | Charset: ${esc(it.charset)}</div>
                <div style="color:#94a3b8;font-size:0.72rem;">(0020,000D) StudyUID    : ${esc(it.study_instance_uid)}</div>
            </div>
        `).join('');
}

loadWorklist();
setInterval(loadWorklist, 8000);
</script>
</body>
</html>

