<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next リアルタイム受信モニター・SSD残量・未紐付け画像救済センター (ingest_dashboard.php)
// - 全4チャネル常駐監視状態 & 非RAIDマルチSSD容量ヘルス
// - Quarantine（ID打ち忘れ・バーコード誤読ファイル）プレビュー確認＆1クリック患者救済
// - リアルタイム監査ログストリーム
// ==============================================================================

require_once __DIR__ . '/lib/bootstrap.php';
$cfg = rsnext_config();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📡 受信モニター・未紐付け救済センター - RS-Next</title>
    <link rel="stylesheet" href="assets/rsnext_ui.css">
    <style>
        .stat-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-tile {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            box-shadow: var(--shadow-sm);
        }
        .stat-label { font-size: 0.76rem; color: var(--text-muted); font-weight: 700; }
        .stat-val { font-size: 1.35rem; font-weight: 800; color: var(--text-main); margin-top: 2px; }

        .vol-bar-bg { height: 10px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin-top: 8px; }
        .vol-bar-fill { height: 100%; background: linear-gradient(90deg, #2563eb, #0d9488); }

        .q-card {
            background: #fffbeb;
            border: 1.5px solid #fcd34d;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 12px;
            display: grid;
            grid-template-columns: 150px 1fr 310px;
            gap: 16px;
            align-items: center;
        }
        @media (max-width: 960px) {
            .q-card { grid-template-columns: 1fr; }
        }
        .q-thumb {
            width: 146px;
            height: 104px;
            background: #0f172a;
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .q-thumb img { width: 100%; height: 100%; object-fit: cover; }

        .log-badge { padding: 2px 7px; border-radius: 4px; font-size: 0.72rem; font-weight: 800; }
        .lv-INFO { background: #dbeafe; color: #1e40af; }
        .lv-WARN { background: #fef3c7; color: #92400e; }
        .lv-ERROR { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

<header class="rs-topbar">
    <a href="index.php" class="rs-brand">
        <span class="rs-brand-logo">🏥 RS-Next</span>
        <span class="rs-brand-sub">受信モニター ＆ 隔離救済センター</span>
    </a>
    <nav class="rs-nav">
        <a href="index.php" class="rs-nav-link">🖥️ 検査一覧</a>
        <a href="worklist.php" class="rs-nav-link">📋 撮影依頼・MWL</a>
        <a href="modality_studio.php" class="rs-nav-link">🎛️ Modality Studio</a>
        <a href="import_media.php" class="rs-nav-link">💿 外部CD/画像取込</a>
        <a href="duplicate_studies.php" class="rs-nav-link">🔍 重複抽出</a>
        <a href="ingest_dashboard.php" class="rs-nav-link active">📡 受信モニター</a>
        <a href="hardware_monitor.php" class="rs-nav-link">🖥️ ハード監視</a>
        <a href="backup_manager.php" class="rs-nav-link">🛡️ バックアップ</a>
    </nav>
</header>

<main class="rs-main">

    <!-- 上部ステータスタイル -->
    <section class="stat-strip" id="statStrip">
        <div class="stat-tile"><div class="stat-label">常駐Ingestエンジン</div><div class="stat-val" id="stEngine">確認中...</div></div>
        <div class="stat-tile"><div class="stat-label">登録患者マスタ数</div><div class="stat-val" id="stPatients">-</div></div>
        <div class="stat-tile"><div class="stat-label">有効検査数 / 総ファイル数</div><div class="stat-val" id="stStudies">-</div></div>
        <div class="stat-tile"><div class="stat-label">未紐付け隔離（要確認）</div><div class="stat-val" id="stQuarantine">-</div></div>
    </section>

    <!-- マルチSSD ボリューム状況 -->
    <section class="rs-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <h2 style="font-size:1.02rem;font-weight:800;color:var(--primary);">💾 非RAID マルチSSDボリューム残量 ＆ ミラー構成ステータス</h2>
            <button type="button" class="btn btn-sm" onclick="loadDashboard()">🔄 今すぐ更新</button>
        </div>
        <div id="volumesGrid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;"></div>
    </section>

    <!-- 👥 MALL3 カルテサーバ別カスタム自動巡回 ＆ 🚚 旧RS-Base バックグラウンド移行コントロール -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(460px,1fr));gap:14px;margin-bottom:16px;">
        <section class="rs-card" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
                <h2 style="font-size:0.98rem;font-weight:800;color:#0f766e;">👥 MALL3 カルテサーバ別 患者マスタ自動巡回</h2>
                <div style="display:flex;gap:6px;align-items:center;">
                    <button type="button" class="btn btn-sm" id="btnMall3Master" onclick="toggleMall3Master()">⏸️ 全体一時停止</button>
                    <button type="button" class="btn btn-sm btn-teal" onclick="syncMall3Now('')">⚡ 全サーバ今すぐ同期</button>
                </div>
            </div>
            <div id="mall3StatusBox" style="font-size:0.82rem;color:#334155;">読込中...</div>
        </section>

        <section class="rs-card" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
                <h2 style="font-size:0.98rem;font-weight:800;color:#1d4ed8;">🚚 旧RS-Base (192.168.1.164) 無停止バックグラウンド移行</h2>
                <div style="display:flex;gap:6px;">
                    <button type="button" class="btn btn-sm btn-primary" id="btnStartMig" onclick="controlMigration('start_full')">▶️ 全件移行開始 (最新順)</button>
                    <button type="button" class="btn btn-sm" onclick="controlMigration('delta')" title="本番切替時の直近差分のみ高速同期">⚡ 差分同期</button>
                    <button type="button" class="btn btn-sm btn-danger" id="btnStopMig" onclick="controlMigration('stop')">⏸️ 安全停止</button>
                </div>
            </div>
            <div id="migStatusBox" style="font-size:0.82rem;color:#334155;">読込中...</div>
        </section>
    </div>

    <!-- 🛡️ Quarantine 未紐付け救済キュー -->
    <section class="rs-card" id="quarantine">
        <h2 style="font-size:1.05rem;font-weight:800;color:#b45309;margin-bottom:6px;">🛡️ 未紐付け・ID不正データ隔離BOX（Quarantine 救済キュー）</h2>
        <p style="font-size:0.83rem;color:var(--text-muted);margin-bottom:12px;">
            モダリティ側での患者ID入力忘れやバーコード誤読により保護されたファイルです。プレビューを確認し、正しい患者IDを指定して「✅ 救済格納」を押すと即座に本来の患者一覧へ反映されます。
        </p>
        <div id="quarantineContainer"></div>
    </section>

    <!-- 📜 監査・着信ログ -->
    <section class="rs-card">
        <h2 style="font-size:1.02rem;font-weight:800;color:var(--primary);margin-bottom:10px;">📜 リアルタイム着信・操作監査ログ（直近80件）</h2>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid var(--border);">
                        <th style="padding:8px;text-align:left;width:155px;">日時</th>
                        <th style="padding:8px;text-align:left;width:70px;">レベル</th>
                        <th style="padding:8px;text-align:left;width:130px;">チャネル</th>
                        <th style="padding:8px;text-align:left;width:160px;">イベント</th>
                        <th style="padding:8px;text-align:left;width:95px;">患者ID</th>
                        <th style="padding:8px;text-align:left;">内容サマリー</th>
                    </tr>
                </thead>
                <tbody id="logsBody"></tbody>
            </table>
        </div>
    </section>

</main>

<div class="rs-toast" id="rsToast"></div>

<script>
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

function fmtBytes(b) {
    if (!b || b <= 0) return '0 MB';
    const gb = b / (1024 ** 3);
    if (gb >= 1000) return (gb / 1024).toFixed(2) + ' TB';
    if (gb >= 1) return gb.toFixed(1) + ' GB';
    return (b / (1024 ** 2)).toFixed(1) + ' MB';
}

let gMall3MasterOn = true;
const MALL3_PRESETS = [
    { sec: 15,   label: '15秒' },
    { sec: 30,   label: '30秒' },
    { sec: 60,   label: '60秒 (1分)' },
    { sec: 120,  label: '120秒 (2分)' },
    { sec: 180,  label: '180秒 (3分)' },
    { sec: 300,  label: '300秒 (5分)' },
    { sec: 600,  label: '600秒 (10分)' },
    { sec: 1800, label: '1800秒 (30分)' },
    { sec: 3600, label: '3600秒 (1時間)' },
];

async function loadDashboard(forceMall3Render = false) {
    const res = await fetch('modality_api.php?action=dashboard_status');
    const data = await res.json();
    if (data.status !== 'success') return;

    const hb = data.heartbeat;
    const stEngine = document.getElementById('stEngine');
    if (hb && hb.is_alive) {
        stEngine.innerHTML = `<span style="color:#16a34a;">● 稼働中</span> <small style="font-size:0.75rem;color:#64748b;">(PID:${hb.pid})</small>`;
    } else {
        stEngine.innerHTML = `<span style="color:#64748b;">○ 待機 / オンデマンド</span>`;
    }

    document.getElementById('stPatients').textContent = `${data.totals.patients.toLocaleString()} 名`;
    document.getElementById('stStudies').textContent = `${data.totals.studies.toLocaleString()} 件 (${data.totals.files.toLocaleString()} 枚)`;
    const qEl = document.getElementById('stQuarantine');
    qEl.textContent = `${data.totals.quarantine} 件`;
    qEl.style.color = data.totals.quarantine > 0 ? '#d97706' : '#16a34a';

    // MALL3 カルテサーバ別巡回コントロール＆ステータス描画
    gMall3MasterOn = data.mall3_master_on !== false;
    const masterBtn = document.getElementById('btnMall3Master');
    if (masterBtn) {
        if (gMall3MasterOn) {
            masterBtn.textContent = '⏸️ 全体一時停止';
            masterBtn.className = 'btn btn-sm';
        } else {
            masterBtn.textContent = '▶️ 全体巡回を再開';
            masterBtn.className = 'btn btn-sm btn-primary';
        }
    }

    const m3Box = document.getElementById('mall3StatusBox');
    const activeEl = document.activeElement;
    const isEditingM3 = !forceMall3Render && activeEl && m3Box.contains(activeEl) && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'SELECT');
    if (!isEditingM3) {
        const servers = Array.isArray(data.mall3_servers) ? data.mall3_servers : [];
        if (servers.length === 0) {
            m3Box.innerHTML = '<div style="color:#64748b;">カルテサーバ設定がありません。</div>';
        } else {
            m3Box.innerHTML = servers.map(s => {
                const isAct = !!s.effective_active;
                const iv = Number(s.poll_interval_sec || 60);
                const ts = (s.last_sync_at || '').slice(0, 19).replace('T', ' ') || '-';
                const nextTs = isAct
                    ? ((s.next_poll_at || '').slice(11, 19) || 'まもなく')
                    : '停止中';
                const wmTs = (s.last_watermark || '').slice(0, 19) || '-';
                const isPreset = MALL3_PRESETS.some(p => p.sec === iv);
                const presetOpts = MALL3_PRESETS.map(p =>
                    `<option value="${p.sec}" ${p.sec === iv ? 'selected' : ''}>${esc(p.label)}</option>`
                ).join('') + `<option value="custom" ${!isPreset ? 'selected' : ''}>カスタム秒数</option>`;

                const bg = isAct ? '#f0fdfa' : '#f8fafc';
                const bd = isAct ? '#99f6e4' : '#cbd5e1';
                const badgeHtml = isAct
                    ? `<span style="background:#ccfbf1;color:#0f766e;padding:2px 8px;border-radius:999px;font-size:0.72rem;font-weight:800;">🟢 巡回中 (${iv}秒間隔)</span>`
                    : `<span style="background:#e2e8f0;color:#475569;padding:2px 8px;border-radius:999px;font-size:0.72rem;font-weight:800;">⏸️ ${!gMall3MasterOn && s.enabled ? '全体一時停止中' : '停止中'}</span>`;

                const toggleBtnHtml = isAct
                    ? `<button type="button" class="btn btn-sm btn-danger" style="height:28px;padding:0 10px;" onclick="toggleMall3Source('${esc(s.code)}', false)">⏸️ 巡回停止</button>`
                    : `<button type="button" class="btn btn-sm btn-primary" style="height:28px;padding:0 10px;" onclick="toggleMall3Source('${esc(s.code)}', true)">▶️ 巡回開始</button>`;

                const errHtml = s.last_error
                    ? `<div style="margin-top:4px;padding:4px 8px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;color:#b91c1c;font-size:0.74rem;">⚠️ 通信警告: ${esc(s.last_error)}</div>`
                    : '';

                return `
                    <div style="padding:10px 12px;background:${bg};border:1px solid ${bd};border-radius:10px;margin-bottom:8px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                <strong style="color:${isAct ? '#0f766e' : '#334155'};font-size:0.86rem;">${esc(s.label)}</strong>
                                ${badgeHtml}
                            </div>
                            <div style="font-size:0.76rem;text-align:right;">
                                <span style="font-weight:800;color:#0f766e;">+${s.last_changed || 0} 名更新</span>
                                <span style="color:#64748b;">(${s.last_elapsed_ms || 0} ms)</span>
                            </div>
                        </div>
                        <div style="font-size:0.74rem;color:#475569;margin-top:4px;">
                            最終巡回: <code>${esc(ts)}</code> ／ 次回予定: <code>${esc(nextTs)}</code> ／ DB基準: <code>${esc(wmTs)}</code>
                        </div>
                        ${errHtml}
                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:8px;padding-top:7px;border-top:1px dashed ${bd};">
                            ${toggleBtnHtml}
                            <span style="font-size:0.75rem;color:#475569;margin-left:2px;">間隔:</span>
                            <select id="m3Preset_${esc(s.code)}" class="input" style="width:auto;height:28px;padding:2px 6px;font-size:0.77rem;" onchange="onMall3PresetChange('${esc(s.code)}', this.value)">
                                ${presetOpts}
                            </select>
                            <input type="number" id="m3Iv_${esc(s.code)}" class="input" min="5" max="86400" step="5" value="${iv}" style="width:74px;height:28px;padding:2px 6px;font-size:0.79rem;text-align:right;" onkeydown="if(event.key==='Enter')saveMall3Interval('${esc(s.code)}')">
                            <span style="font-size:0.75rem;color:#475569;">秒</span>
                            <button type="button" class="btn btn-sm" style="height:28px;padding:0 9px;" onclick="saveMall3Interval('${esc(s.code)}')">💾 秒数保存</button>
                            <button type="button" class="btn btn-sm btn-teal" style="height:28px;padding:0 9px;margin-left:auto;" onclick="syncMall3Now('${esc(s.code)}')">⚡ 単体同期</button>
                        </div>
                    </div>
                `;
            }).join('');
        }
    }

    // バックグラウンド移行進捗描画
    const mp = data.migration_progress;
    const migBox = document.getElementById('migStatusBox');
    if (!mp) {
        migBox.innerHTML = `
            <div style="padding:10px 12px;background:#f8fafc;border:1px solid var(--border-light);border-radius:8px;">
                現在待機中です（移行済検査: <strong>${data.totals.studies.toLocaleString()}</strong> 件）。<br>
                <span style="font-size:0.76rem;color:#64748b;">夜間などに「▶️ 全件移行開始」を押すと、現行サーバに負荷をかけず最新検査から順番に裏でコピー＆サムネイル生成します（いつでも一時停止・再開可能）。</span>
            </div>
        `;
    } else {
        const stColor = mp.state === 'RUNNING' ? '#2563eb' : (mp.state === 'PAUSED' ? '#d97706' : '#16a34a');
        const stLabel = mp.state === 'RUNNING' ? '🚚 移行実行中' : (mp.state === 'PAUSED' ? '⏸️ 一時停止中' : '✅ 待機 / 完了');
        const etaTxt = (mp.state === 'RUNNING' && mp.eta_minutes != null)
            ? ` ／ 残り約 ${mp.eta_minutes >= 60 ? (mp.eta_minutes / 60).toFixed(1) + '時間' : mp.eta_minutes + '分'} (${mp.speed_per_min} 件/分)`
            : '';
        migBox.innerHTML = `
            <div style="padding:10px 12px;background:#f8fafc;border:1px solid var(--border-light);border-radius:8px;">
                <div style="display:flex;justify-content:space-between;font-weight:800;">
                    <span style="color:${stColor};">${stLabel}</span>
                    <span>${(mp.done_total || 0).toLocaleString()} / ${(mp.legacy_total || 0).toLocaleString()} 検査 (${mp.percent || 0}%)</span>
                </div>
                <div class="vol-bar-bg" style="margin:6px 0;"><div class="vol-bar-fill" style="width:${mp.percent || 0}%;"></div></div>
                <div style="font-size:0.75rem;color:#475569;">
                    今回コピー: <strong>${(mp.run_migrated || 0).toLocaleString()}</strong> 件 (${fmtBytes(mp.run_bytes || 0)})
                    ${mp.current_renban ? ` ／ 処理中 #${mp.current_renban} (${esc(mp.current_date || '')})` : ''}${etaTxt}
                </div>
            </div>
        `;
    }

    // ボリューム描画
    document.getElementById('volumesGrid').innerHTML = (data.volumes || []).map(v => {
        const used = Math.max(0, v.disk_total - v.disk_free);
        const pct = v.disk_total > 0 ? Math.round((used / v.disk_total) * 100) : 0;
        return `
            <div style="border:1px solid var(--border-light);border-radius:10px;padding:12px 14px;background:#f8fafc;">
                <div style="display:flex;justify-content:space-between;font-weight:800;font-size:0.88rem;">
                    <span>💿 ${esc(v.volume_id)} — ${esc(v.label)}</span>
                    <span>使用率 ${pct}%</span>
                </div>
                <div class="vol-bar-bg"><div class="vol-bar-fill" style="width:${pct}%;"></div></div>
                <div style="font-size:0.76rem;color:#475569;margin-top:6px;display:flex;justify-content:space-between;">
                    <span>RS-Next格納: <strong>${v.study_count}</strong> 検査 (${fmtBytes(v.stored_bytes)})</span>
                    <span>ドライブ空き: <strong>${fmtBytes(v.disk_free)}</strong> / ${fmtBytes(v.disk_total)}</span>
                </div>
                <div style="font-size:0.72rem;color:#64748b;margin-top:3px;">実体: <code>${esc(v.path)}</code> ／ ミラー: <code>${esc(v.mirror_path)}</code></div>
            </div>
        `;
    }).join('');

    // Quarantine 描画
    const qList = data.quarantine || [];
    const qBox = document.getElementById('quarantineContainer');
    if (qList.length === 0) {
        qBox.innerHTML = '<div style="padding:16px;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;color:#15803d;font-weight:700;font-size:0.86rem;">✅ 現在、未紐付けの隔離データはありません（全データ正常格納済み）。</div>';
    } else {
        qBox.innerHTML = qList.map(q => `
            <div class="q-card">
                <div class="q-thumb">
                    <img src="modality_api.php?action=quarantine_preview&quarantine_id=${q.quarantine_id}" alt="preview">
                </div>
                <div>
                    <div style="font-weight:800;color:#92400e;font-size:0.92rem;">⚠️ 隔離 #${q.quarantine_id}: ${esc(q.reason)}</div>
                    <div style="font-size:0.8rem;color:#334155;margin-top:4px;line-height:1.55;">
                        着信日時: <strong>${esc((q.received_at || '').slice(0, 19).replace('T', ' '))}</strong> ／ 経路: <code>${esc(q.source_protocol)}</code> (${esc(q.source_ip || '-')})<br>
                        生PatientID: <code>${esc(q.raw_patient_id || '(空欄)')}</code> ／ 生患者名タグ: <code>${esc(q.raw_patient_name || '-')}</code><br>
                        推定検査種別: <strong>${esc(q.kensamei_guess || q.modality || '検査画像')}</strong>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <input type="text" id="rescuePid_${q.quarantine_id}" class="input" placeholder="正しい患者ID (例: 70755)">
                    <input type="text" id="rescueKensa_${q.quarantine_id}" class="input" value="${esc(q.kensamei_guess || '')}" placeholder="検査名 (任意)">
                    <div style="display:flex;gap:6px;">
                        <button type="button" class="btn btn-teal btn-sm" style="flex:1;" onclick="rescueItem(${q.quarantine_id})">✅ この患者へ救済格納</button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="discardItem(${q.quarantine_id})">🗑️ 破棄</button>
                    </div>
                </div>
            </div>
        `).join('');
    }

    // ログ描画
    document.getElementById('logsBody').innerHTML = (data.logs || []).map(l => `
        <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:7px 8px;font-family:monospace;color:#475569;">${esc((l.logged_at || '').slice(0, 19).replace('T', ' '))}</td>
            <td style="padding:7px 8px;"><span class="log-badge lv-${esc(l.level)}">${esc(l.level)}</span></td>
            <td style="padding:7px 8px;"><code>${esc(l.channel)}</code></td>
            <td style="padding:7px 8px;font-weight:700;">${esc(l.event_type)}</td>
            <td style="padding:7px 8px;font-family:monospace;">${l.patient_id ? `<a href="index.php?patient_id=${encodeURIComponent(l.patient_id)}">${esc(l.patient_id)}</a>` : '-'}</td>
            <td style="padding:7px 8px;">${esc(l.summary)}</td>
        </tr>
    `).join('');
}

async function rescueItem(qid) {
    const pid = document.getElementById('rescuePid_' + qid).value.trim();
    const kensa = document.getElementById('rescueKensa_' + qid).value.trim();
    if (!pid) {
        alert('救済先の正しい患者IDを入力してください');
        return;
    }
    const res = await fetch('modality_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'rescue_quarantine', quarantine_id: qid, patient_id: pid, kensamei: kensa }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast(`✅ 隔離データ #${qid} を患者ID ${out.patient_id} へ救済しました！`);
        loadDashboard();
    } else {
        alert(out.message || '救済に失敗しました');
    }
}

async function discardItem(qid) {
    if (!confirm('この隔離データをテスト撮影・不要ファイルとして完全破棄しますか？')) return;
    await fetch('modality_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'discard_quarantine', quarantine_id: qid }),
    });
    showToast('🗑️ 破棄しました');
    loadDashboard();
}

async function updateMall3Poller(payload) {
    const res = await fetch('modality_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ action: 'update_mall3_poller' }, payload)),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast('✅ ' + (out.message || '巡回設定を反映しました'));
        // 常駐Pollerが1秒以内にホットリロードするため直後と1.4秒後に再描画
        await loadDashboard(true);
        setTimeout(() => loadDashboard(true), 1400);
    } else {
        alert(out.message || '巡回設定の更新に失敗しました');
    }
}

function toggleMall3Master() {
    updateMall3Poller({ master_enabled: !gMall3MasterOn });
}

function toggleMall3Source(code, enable) {
    updateMall3Poller({ source_code: code, enabled: !!enable });
}

function onMall3PresetChange(code, val) {
    const inp = document.getElementById('m3Iv_' + code);
    if (!inp) return;
    if (val === 'custom') {
        inp.focus();
        inp.select();
        return;
    }
    const sec = parseInt(val, 10);
    if (sec >= 5) {
        inp.value = String(sec);
        saveMall3Interval(code);
    }
}

function saveMall3Interval(code) {
    const inp = document.getElementById('m3Iv_' + code);
    if (!inp) return;
    const sec = parseInt(inp.value, 10);
    if (isNaN(sec) || sec < 5 || sec > 86400) {
        alert('巡回秒数は 5秒 〜 86400秒 (24時間) の範囲で入力してください');
        inp.focus();
        return;
    }
    if (document.activeElement === inp) inp.blur();
    updateMall3Poller({ source_code: code, poll_interval_sec: sec });
}

async function syncMall3Now(sourceCode = '') {
    showToast(sourceCode ? `🔄 ${sourceCode} を単体照会中...` : '🔄 全MALL3 SQL Server を照会中...');
    const res = await fetch('modality_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'sync_mall3_now', source_code: sourceCode }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        const chg = (out.results || []).reduce((a, b) => a + (b.changed || 0), 0);
        showToast(`✅ MALL3同期完了！（新規・変更反映: ${chg} 名）`);
        loadDashboard(true);
    } else {
        alert(out.message || 'MALL3同期に失敗しました');
    }
}

async function controlMigration(cmd) {
    if (cmd === 'start_full' && !confirm('現行 RS-Base (192.168.1.164) から全件バックグラウンド移行を開始しますか？\n（移行済みの検査は自動スキップされ、いつでも「安全停止」で中断できます）')) {
        return;
    }
    const res = await fetch('modality_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'control_migration', cmd }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast('✅ ' + (out.message || 'コマンドを送信しました'));
        setTimeout(loadDashboard, 1500);
    } else {
        alert(out.message || '操作エラー');
    }
}

loadDashboard();
setInterval(loadDashboard, 8000);
</script>
</body>
</html>

