<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next バックアップ管理 ＆ 非RAIDマルチSSDミラーリング・DB世代モニター (backup_manager.php)
// ==============================================================================

require_once __DIR__ . '/lib/bootstrap.php';
$cfg = rsnext_config();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🛡️ バックアップ管理・モニター - RS-Next</title>
    <link rel="stylesheet" href="assets/rsnext_ui.css">
    <style>
        .bk-hero {
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            color: #ffffff;
            background: linear-gradient(90deg, #0f172a, #1e3a8a);
        }
        .bk-hero.running {
            background: linear-gradient(90deg, #0369a1, #0d9488);
        }

        .tier-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .tier-card {
            background: #ffffff;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            padding: 12px 15px;
            box-shadow: var(--shadow-sm);
        }

        .jobs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(440px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .job-card {
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            padding: 13px 15px;
            background: #ffffff;
        }
        .job-card.active-sched {
            border-color: #99f6e4;
            background: #f0fdfa;
        }

        .pill-ok { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; }
        .pill-warn { background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; }
        .pill-err { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; }
        .pill-off { background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1.35fr;
            gap: 14px;
        }
        @media (max-width: 1040px) {
            .two-col { grid-template-columns: 1fr; }
        }
        table.bk-tbl { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        table.bk-tbl th { background: #f1f5f9; padding: 7px 8px; text-align: left; font-size: 0.73rem; color: #334155; border-bottom: 1.5px solid var(--border); }
        table.bk-tbl td { padding: 6px 8px; border-bottom: 1px solid #edf2f7; vertical-align: middle; }
    </style>
</head>
<body>

<header class="rs-topbar">
    <a href="index.php" class="rs-brand">
        <span class="rs-brand-logo">🏥 RS-Next</span>
        <span class="rs-brand-sub">バックアップ管理 ＆ ミラーモニター</span>
    </a>
    <nav class="rs-nav">
        <a href="index.php" class="rs-nav-link">🖥️ 検査一覧</a>
        <a href="worklist.php" class="rs-nav-link">📋 撮影依頼・MWL</a>
        <a href="modality_studio.php" class="rs-nav-link">🎛️ Modality Studio</a>
        <a href="ingest_dashboard.php" class="rs-nav-link">📡 受信監視</a>
        <a href="hardware_monitor.php" class="rs-nav-link">🖥️ ハード監視</a>
        <a href="backup_manager.php" class="rs-nav-link active">🛡️ バックアップ</a>
    </nav>
</header>

<main class="rs-main">

    <!-- ライブ実行ステータスバナー -->
    <div id="bkHero" class="bk-hero">
        <div>
            <div id="bkHeroTitle" style="font-size:1.04rem;font-weight:900;">🛡️ 3重データ保護・自動ミラーリング＆世代バックアップ管理</div>
            <div id="bkHeroSub" style="font-size:0.78rem;opacity:0.9;margin-top:3px;">状態を読み込み中...</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button type="button" class="btn btn-sm" id="btnBkMaster" onclick="toggleBackupMaster()">⏸️ 全体スケジュール一時停止</button>
            <button type="button" class="btn btn-sm btn-teal" onclick="triggerJob('DB_DUMP_HDD')">💾 今すぐDBダンプ保存</button>
            <button type="button" class="btn btn-sm btn-primary" onclick="triggerJob('ALL')">⚡ 全保護ジョブ一括実行</button>
            <button type="button" class="btn btn-sm btn-danger" id="btnStopJob" style="display:none;" onclick="stopRunningJob()">⏹️ 安全停止</button>
        </div>
    </div>

    <!-- 3重ストレージ保護ペア状態 -->
    <section class="tier-grid" id="storagePairsGrid"></section>

    <!-- 4系統ジョブ 個別カスタム設定 ＆ 手動実行カード -->
    <section class="rs-card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
            <h2 style="font-size:0.98rem;font-weight:800;color:var(--primary);">⚙️ バックアップ・ミラーリング スケジュール設定 ＆ 単体実行コントロール</h2>
            <span style="font-size:0.75rem;color:#64748b;">設定変更はサービス再起動なしで1秒以内に即時反映されます</span>
        </div>
        <div class="jobs-grid" id="jobsGrid" style="margin-bottom:0;"></div>
    </section>

    <!-- DBダンプ世代一覧 ＆ 実行履歴ログ -->
    <div class="two-col">
        <section class="rs-card" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <h2 style="font-size:0.96rem;font-weight:800;color:#7c3aed;">🗄️ PostgreSQL DB世代ダンプ一覧 (H:\db_dumps)</h2>
                <button type="button" class="btn btn-sm btn-teal" onclick="triggerJob('DB_DUMP_HDD')">＋ 新規作成</button>
            </div>
            <div style="max-height:460px;overflow-y:auto;">
                <table class="bk-tbl">
                    <thead>
                        <tr>
                            <th>ダンプファイル / 作成日時</th>
                            <th>サイズ</th>
                            <th>整合性検証</th>
                        </tr>
                    </thead>
                    <tbody id="dumpsBody"></tbody>
                </table>
            </div>
        </section>

        <section class="rs-card" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <h2 style="font-size:0.96rem;font-weight:800;color:var(--primary);">📜 バックアップ・ミラーリング 実行モニター履歴（直近80件）</h2>
                <button type="button" class="btn btn-sm" onclick="loadBackup(true)">🔄 更新</button>
            </div>
            <div style="max-height:460px;overflow-y:auto;">
                <table class="bk-tbl">
                    <thead>
                        <tr>
                            <th style="width:138px;">完了日時</th>
                            <th style="width:74px;">起動</th>
                            <th>ジョブ / 結果サマリー</th>
                            <th style="width:68px;">所要</th>
                            <th style="width:78px;">状態</th>
                        </tr>
                    </thead>
                    <tbody id="historyBody"></tbody>
                </table>
            </div>
        </section>
    </div>

</main>

<div class="rs-toast" id="rsToast"></div>

<script>
let gBkMasterOn = true;

function showToast(msg) {
    const t = document.getElementById('rsToast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3400);
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
    return (b / (1024 ** 2)).toFixed(2) + ' MB';
}

async function loadBackup(forceRender = false) {
    const res = await fetch('ops_api.php?action=backup_status');
    const data = await res.json();
    if (data.status !== 'success') return;

    const live = data.live;
    const hist = data.history || [];

    if (!live) {
        document.getElementById('bkHeroSub').textContent = '常駐バックアップスケジューラの初回起動データを待機中...';
        return;
    }

    gBkMasterOn = live.master_enabled !== false;
    const mBtn = document.getElementById('btnBkMaster');
    mBtn.textContent = gBkMasterOn ? '⏸️ 全体スケジュール一時停止' : '▶️ 全体スケジュール再開';

    const hero = document.getElementById('bkHero');
    const stopBtn = document.getElementById('btnStopJob');
    if (live.state === 'RUNNING' && live.running) {
        hero.className = 'bk-hero running';
        stopBtn.style.display = 'inline-flex';
        document.getElementById('bkHeroTitle').textContent = `🚚 現在実行中: ${live.running.job_label} (${live.running.elapsed_sec || 0}秒経過)`;
        document.getElementById('bkHeroSub').textContent = live.running.message || '同期処理を実行しています...';
    } else {
        hero.className = 'bk-hero';
        stopBtn.style.display = 'none';
        document.getElementById('bkHeroTitle').textContent = gBkMasterOn
            ? '🟢 3重データ保護（NVMeミラー × 2系統 ＋ 8TB HDD世代バックアップ）自動稼働中'
            : '⏸️ 自動バックアップスケジュールは現在一時停止中です（手動実行はいつでも可能）';
        document.getElementById('bkHeroSub').textContent =
            `最終ステータス更新: ${(live.updated_at || '').slice(0, 19).replace('T', ' ')} ／ DBスナップショット保有数: ${(live.db_dumps || []).length} 世代`;
    }

    // 3重ストレージ保護ペア描画
    document.getElementById('storagePairsGrid').innerHTML = (live.storage_pairs || []).map(p => {
        const bothOk = p.source_online && p.mirror_online;
        return `
            <div class="tier-card">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <strong style="font-size:0.86rem;color:#0f172a;">🛡️ ${esc(p.label)}</strong>
                    <span class="${bothOk ? 'pill-ok' : 'pill-err'}">${bothOk ? '🟢 ペア正常' : '🚨 パス未接続'}</span>
                </div>
                <div style="font-size:0.75rem;color:#334155;margin-top:6px;line-height:1.55;">
                    元領域: <code>${esc(p.source_path)}</code> ${p.source_used_bytes ? `(使用 ${fmtBytes(p.source_used_bytes)})` : ''}<br>
                    複製先: <code>${esc(p.mirror_path)}</code> (使用 <strong>${fmtBytes(p.mirror_used_bytes)}</strong> ／ 空き <strong>${fmtBytes(p.mirror_free_bytes)}</strong>)
                </div>
            </div>
        `;
    }).join('');

    // ジョブ設定カード描画 (入力フォーカス中は自動リフレッシュで上書きしない)
    const jGrid = document.getElementById('jobsGrid');
    const actEl = document.activeElement;
    const isEditing = !forceRender && actEl && jGrid.contains(actEl) && (actEl.tagName === 'INPUT' || actEl.tagName === 'SELECT');
    if (!isEditing) {
        jGrid.innerHTML = (live.jobs || []).map(j => {
            const code = j.code;
            const isAct = !!j.effective_active;
            const nextStr = isAct ? ((j.next_run_at || '').slice(0, 16).replace('T', ' ') || '-') : '停止中';
            const lastStr = (j.last_run_at || '').slice(0, 19).replace('T', ' ') || '未実行';
            const lastSt = j.last_status || '';
            const stBadge = lastSt === 'SUCCESS'
                ? '<span class="pill-ok">前回: 成功</span>'
                : (lastSt === 'ERROR' ? '<span class="pill-err">前回: エラー</span>' : '');

            let schedControls = '';
            if (j.schedule_type === 'interval') {
                const iv = Number(j.interval_min || 60);
                const opts = [15, 30, 60, 120, 180, 360].map(m =>
                    `<option value="${m}" ${m === iv ? 'selected' : ''}>${m >= 60 ? (m / 60) + '時間 (' + m + '分)' : m + '分'}おき</option>`
                ).join('') + `<option value="custom" ${![15,30,60,120,180,360].includes(iv) ? 'selected' : ''}>自由入力</option>`;
                schedControls = `
                    <span style="font-size:0.75rem;color:#475569;">周期:</span>
                    <select class="input" style="width:auto;height:28px;padding:2px 6px;font-size:0.77rem;" onchange="onJobIvPreset('${esc(code)}', this.value)">${opts}</select>
                    <input type="number" id="jbIv_${esc(code)}" class="input" min="5" max="10080" value="${iv}" style="width:68px;height:28px;padding:2px 6px;font-size:0.78rem;text-align:right;">
                    <span style="font-size:0.75rem;">分</span>
                    <button type="button" class="btn btn-sm" style="height:28px;padding:0 9px;" onclick="saveJobConfig('${esc(code)}')">💾 保存</button>
                `;
            } else {
                const extraRet = code === 'DB_DUMP_HDD'
                    ? ` <span style="font-size:0.75rem;margin-left:4px;">保持:</span>
                        <input type="number" id="jbRet_${esc(code)}" class="input" min="3" max="3650" value="${j.retention_days || 30}" style="width:58px;height:28px;padding:2px 5px;font-size:0.78rem;text-align:right;">
                        <span style="font-size:0.75rem;">日</span>`
                    : '';
                const extraArc = code === 'FULL_HDD_SYNC'
                    ? ` <label style="font-size:0.74rem;display:inline-flex;align-items:center;gap:3px;margin-left:4px;cursor:pointer;">
                            <input type="checkbox" id="jbArc_${esc(code)}" ${j.include_archive_on_hdd !== false ? 'checked' : ''}> D:過去分も含める
                        </label>`
                    : '';
                schedControls = `
                    <span style="font-size:0.75rem;color:#475569;">毎日:</span>
                    <input type="time" id="jbTime_${esc(code)}" class="input" value="${esc(j.daily_time || '23:00')}" style="width:104px;height:28px;padding:2px 6px;font-size:0.79rem;">
                    ${extraRet}${extraArc}
                    <button type="button" class="btn btn-sm" style="height:28px;padding:0 9px;" onclick="saveJobConfig('${esc(code)}')">💾 保存</button>
                `;
            }

            return `
                <div class="job-card ${isAct ? 'active-sched' : ''}">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap;">
                        <div>
                            <strong style="font-size:0.88rem;color:#0f172a;">${esc(j.label)}</strong>
                            <span class="${isAct ? 'pill-ok' : 'pill-off'}" style="margin-left:4px;">${isAct ? '🟢 ' + esc(j.schedule_text) : '⏸️ 自動OFF'}</span>
                        </div>
                        ${stBadge}
                    </div>
                    <div style="font-size:0.74rem;color:#475569;margin-top:4px;">
                        ${esc(j.description || '')}<br>
                        前回実行: <code>${esc(lastStr)}</code> ／ 次回予定: <code>${esc(nextStr)}</code>
                        ${j.last_summary ? `<br><span style="color:#0f766e;font-weight:700;">➔ ${esc(j.last_summary)}</span>` : ''}
                    </div>
                    <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-top:9px;padding-top:8px;border-top:1px dashed #cbd5e1;">
                        ${isAct
                            ? `<button type="button" class="btn btn-sm btn-danger" style="height:28px;padding:0 9px;" onclick="toggleJob('${esc(code)}', false)">⏸️ 停止</button>`
                            : `<button type="button" class="btn btn-sm btn-primary" style="height:28px;padding:0 9px;" onclick="toggleJob('${esc(code)}', true)">▶️ 開始</button>`}
                        ${schedControls}
                        <button type="button" class="btn btn-sm btn-teal" style="height:28px;padding:0 10px;margin-left:auto;" onclick="triggerJob('${esc(code)}')">⚡ 今すぐ実行</button>
                    </div>
                </div>
            `;
        }).join('');
    }

    // DBダンプ一覧描画
    const dumps = live.db_dumps || [];
    const dBody = document.getElementById('dumpsBody');
    if (dumps.length === 0) {
        dBody.innerHTML = `<tr><td colspan="3" style="text-align:center;color:#64748b;padding:18px;">まだDBダンプがありません。「💾 今すぐDBダンプ保存」を押すと即座に作成・検証されます。</td></tr>`;
    } else {
        dBody.innerHTML = dumps.map(d => {
            const vHtml = d.verified === true
                ? `<span class="pill-ok">✅ 検証OK (${d.toc_entries}項目)</span>`
                : (d.verified === false ? `<span class="pill-err">🚨 破損警告</span>` : `<span class="pill-off">未検証</span>`);
            return `
                <tr>
                    <td>
                        <code style="font-weight:700;font-size:0.76rem;">${esc(d.filename)}</code><br>
                        <span style="font-size:0.71rem;color:#64748b;">${esc((d.created_at || '').slice(0, 19).replace('T', ' '))}</span>
                    </td>
                    <td style="font-weight:700;white-space:nowrap;">${fmtBytes(d.size_bytes)}</td>
                    <td style="white-space:nowrap;">
                        ${vHtml}
                        <button type="button" class="btn btn-sm" style="height:23px;padding:0 6px;font-size:0.7rem;margin-left:4px;" onclick="verifyDump('${esc(d.filename)}')">🔍 再検証</button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    // 実行履歴ログ描画
    const hBody = document.getElementById('historyBody');
    if (hist.length === 0) {
        hBody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:#64748b;padding:18px;">実行履歴はまだありません。</td></tr>`;
    } else {
        hBody.innerHTML = hist.map(h => {
            const st = h.status || '';
            const cls = st === 'SUCCESS' ? 'pill-ok' : (st === 'STOPPED' ? 'pill-warn' : 'pill-err');
            const trg = h.trigger === 'SCHEDULED' ? '自動' : '手動';
            return `
                <tr>
                    <td style="font-family:Consolas,monospace;font-size:0.75rem;color:#475569;">${esc((h.finished_at || '').slice(0, 19).replace('T', ' '))}</td>
                    <td><span class="pill-off">${trg}</span></td>
                    <td>
                        <strong>${esc(h.job_label || h.job_code)}</strong><br>
                        <span style="font-size:0.74rem;color:#334155;">${esc(h.summary || '')}</span>
                    </td>
                    <td style="font-family:Consolas,monospace;">${h.duration_sec || 0}s</td>
                    <td><span class="${cls}">${esc(st)}</span></td>
                </tr>
            `;
        }).join('');
    }
}

async function postBackupCfg(payload) {
    const res = await fetch('ops_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ action: 'update_backup_config' }, payload)),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast('✅ ' + out.message);
        await loadBackup(true);
        setTimeout(() => loadBackup(true), 1300);
    } else {
        alert(out.message || '設定変更に失敗しました');
    }
}

function toggleBackupMaster() {
    postBackupCfg({ master_enabled: !gBkMasterOn });
}

function toggleJob(code, enable) {
    postBackupCfg({ job_code: code, enabled: !!enable });
}

function onJobIvPreset(code, val) {
    const inp = document.getElementById('jbIv_' + code);
    if (!inp) return;
    if (val === 'custom') {
        inp.focus();
        inp.select();
        return;
    }
    inp.value = val;
    saveJobConfig(code);
}

function saveJobConfig(code) {
    const payload = { job_code: code };
    const ivEl = document.getElementById('jbIv_' + code);
    const tmEl = document.getElementById('jbTime_' + code);
    const rtEl = document.getElementById('jbRet_' + code);
    const acEl = document.getElementById('jbArc_' + code);
    if (ivEl) payload.interval_min = parseInt(ivEl.value, 10) || 60;
    if (tmEl) payload.daily_time = tmEl.value || '23:00';
    if (rtEl) payload.retention_days = parseInt(rtEl.value, 10) || 30;
    if (acEl) payload.include_archive_on_hdd = acEl.checked;
    if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
    postBackupCfg(payload);
}

async function triggerJob(code) {
    const res = await fetch('ops_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'trigger_backup_job', job_code: code }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast('🚀 ' + out.message);
        setTimeout(() => loadBackup(true), 1100);
    } else {
        alert(out.message || '起動エラー');
    }
}

async function stopRunningJob() {
    await fetch('ops_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'stop_backup_job' }),
    });
    showToast('⏹️ 安全停止シグナルを送信しました');
    setTimeout(() => loadBackup(true), 1500);
}

async function verifyDump(filename) {
    showToast(`🔍 ${filename} を pg_restore --list で検証中...`);
    const res = await fetch('ops_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'verify_db_dump', filename }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast(`✅ 復元整合性OK！ (TOCオブジェクト数: ${out.toc_entries} 件)`);
        loadBackup(true);
    } else {
        alert(out.message || 'ダンプ検証エラー');
    }
}

loadBackup(true);
setInterval(() => loadBackup(false), 4000);
</script>
</body>
</html>

