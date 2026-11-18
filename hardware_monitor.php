<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next ハードウェア・物理SSD(RAIDXpert2)・院内LAN・SNMP統合監視画面 (hardware_monitor.php)
// ==============================================================================

require_once __DIR__ . '/lib/bootstrap.php';
$cfg = rsnext_config();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🖥️ ハードウェア・SNMP監視 - RS-Next</title>
    <link rel="stylesheet" href="assets/rsnext_ui.css">
    <style>
        .health-banner {
            border-radius: 12px;
            padding: 12px 18px;
            margin-bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            font-weight: 800;
        }
        .hb-OK { background: linear-gradient(90deg, #065f46, #0d9488); color: #ffffff; }
        .hb-WARN { background: linear-gradient(90deg, #b45309, #d97706); color: #ffffff; }
        .hb-CRITICAL { background: linear-gradient(90deg, #991b1b, #dc2626); color: #ffffff; }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .kpi-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 15px;
            box-shadow: var(--shadow-sm);
        }
        .kpi-label { font-size: 0.74rem; color: var(--text-muted); font-weight: 800; }
        .kpi-val { font-size: 1.4rem; font-weight: 900; color: #0f172a; margin-top: 2px; line-height: 1.2; }
        .kpi-sub { font-size: 0.73rem; color: #64748b; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .mini-bar { height: 7px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin-top: 6px; }
        .mini-bar > div { height: 100%; border-radius: 999px; transition: width 0.3s ease; }

        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: 14px;
            margin-bottom: 14px;
        }
        .svg-chart-box {
            width: 100%;
            height: 145px;
            background: #0f172a;
            border-radius: 10px;
            padding: 8px;
            position: relative;
        }

        .bay-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(295px, 1fr));
            gap: 10px;
        }
        .bay-card {
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            background: #f8fafc;
            position: relative;
        }
        .bay-card.healthy { border-color: #99f6e4; background: #f0fdfa; }
        .bay-card.fault { border-color: #fca5a5; background: #fef2f2; }

        .pill-ok { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; }
        .pill-warn { background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; }
        .pill-err { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; }

        .two-col {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }
        @media (max-width: 1040px) {
            .two-col { grid-template-columns: 1fr; }
        }
        table.ops-tbl { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        table.ops-tbl th { background: #f1f5f9; padding: 6px 8px; text-align: left; font-size: 0.73rem; color: #334155; border-bottom: 1.5px solid var(--border); }
        table.ops-tbl td { padding: 6px 8px; border-bottom: 1px solid #edf2f7; vertical-align: middle; }
    </style>
</head>
<body>

<header class="rs-topbar">
    <a href="index.php" class="rs-brand">
        <span class="rs-brand-logo">🏥 RS-Next</span>
        <span class="rs-brand-sub">ハードウェア・SNMP統合監視</span>
    </a>
    <nav class="rs-nav">
        <a href="index.php" class="rs-nav-link">🖥️ 検査一覧</a>
        <a href="worklist.php" class="rs-nav-link">📋 撮影依頼・MWL</a>
        <a href="modality_studio.php" class="rs-nav-link">🎛️ Modality Studio</a>
        <a href="ingest_dashboard.php" class="rs-nav-link">📡 受信監視</a>
        <a href="hardware_monitor.php" class="rs-nav-link active">🖥️ ハード監視</a>
        <a href="backup_manager.php" class="rs-nav-link">🛡️ バックアップ</a>
    </nav>
</header>

<main class="rs-main">

    <!-- 総合ヘルスバナー -->
    <div id="healthBanner" class="health-banner hb-OK">
        <div>
            <span id="hbTitle" style="font-size:1rem;">🟢 ハードウェア・ストレージ状態を取得中...</span>
            <div id="hbSub" style="font-size:0.77rem;font-weight:600;opacity:0.92;margin-top:2px;"></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <button type="button" class="btn btn-sm" onclick="loadHw(true)">🔄 物理ドライブ・LAN即時再スキャン</button>
            <a href="backup_manager.php" class="btn btn-sm btn-primary">🛡️ バックアップ管理へ ➔</a>
        </div>
    </div>

    <!-- KPI 5タイル -->
    <section class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">⚡ CPU 負荷率</div>
            <div class="kpi-val" id="kpiCpu">- %</div>
            <div class="mini-bar"><div id="barCpu" style="width:0%;background:#2563eb;"></div></div>
            <div class="kpi-sub" id="subCpu">-</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">🧠 物理メモリ (RAM)</div>
            <div class="kpi-val" id="kpiRam">- %</div>
            <div class="mini-bar"><div id="barRam" style="width:0%;background:#0d9488;"></div></div>
            <div class="kpi-sub" id="subRam">-</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">🌐 ネットワーク通信量</div>
            <div class="kpi-val" id="kpiNet" style="font-size:1.15rem;">- Mbps</div>
            <div class="kpi-sub" id="subNet" style="margin-top:9px;">-</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">💽 物理ドライブ (RAIDXpert2 / NVMe)</div>
            <div class="kpi-val" id="kpiDisks">- / -</div>
            <div class="kpi-sub" id="subDisks" style="margin-top:9px;">-</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">⏱️ OS連続稼働 ＆ SNMPエージェント</div>
            <div class="kpi-val" id="kpiUptime" style="font-size:1.15rem;">-</div>
            <div class="kpi-sub" id="subSnmp" style="margin-top:9px;">-</div>
        </div>
    </section>

    <!-- リアルタイム推移グラフ -->
    <section class="rs-card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
            <h2 style="font-size:0.98rem;font-weight:800;color:var(--primary);">📈 リアルタイム負荷・ネットワーク推移グラフ</h2>
            <div style="display:flex;gap:6px;">
                <button type="button" class="btn btn-sm btn-primary" id="btnSpanFast" onclick="setChartSpan('fast_10s')">直近10分 (10秒更新)</button>
                <button type="button" class="btn btn-sm" id="btnSpan24h" onclick="setChartSpan('hourly_24h')">24時間推移 (1分粒度)</button>
            </div>
        </div>
        <div class="chart-grid" style="margin-bottom:0;">
            <div>
                <div style="font-size:0.76rem;font-weight:700;color:#334155;margin-bottom:4px;">
                    <span style="color:#38bdf8;">■ CPU使用率 (%)</span>　<span style="color:#34d399;">■ メモリ使用率 (%)</span>
                </div>
                <div class="svg-chart-box" id="chartCpuRam"></div>
            </div>
            <div>
                <div style="font-size:0.76rem;font-weight:700;color:#334155;margin-bottom:4px;">
                    <span style="color:#fbbf24;">■ 受信 RX (Mbps)</span>　<span style="color:#f472b6;">■ 送信 TX (Mbps)</span>
                </div>
                <div class="svg-chart-box" id="chartNet"></div>
            </div>
        </div>
    </section>

    <!-- 物理SSD/HDD ベイ監視盤 (AMD RAIDXpert2) -->
    <section class="rs-card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
            <h2 style="font-size:0.98rem;font-weight:800;color:#0f766e;">💽 物理ストレージベイ ＆ AMD RAIDXpert2 ステータス（NVMe SSD×5本 ＋ 8TB HDD×1本）</h2>
            <span id="storageSourceBadge" style="font-size:0.74rem;color:#64748b;font-weight:700;"></span>
        </div>
        <div id="physicalBays" class="bay-grid"></div>
    </section>

    <!-- 論理ドライブ容量 ＆ 常駐サービスポート -->
    <div class="two-col">
        <section class="rs-card" style="margin-bottom:0;">
            <h2 style="font-size:0.96rem;font-weight:800;color:var(--primary);margin-bottom:8px;">💾 論理ドライブ残量・NTFSボリューム使用率 (C:〜H:)</h2>
            <div id="volumesList" style="display:grid;gap:8px;"></div>
        </section>

        <section class="rs-card" style="margin-bottom:0;">
            <h2 style="font-size:0.96rem;font-weight:800;color:var(--primary);margin-bottom:8px;">🔌 RS-Next 常駐サービス ＆ 待受ポート死活</h2>
            <table class="ops-tbl">
                <thead>
                    <tr><th>サービス名称</th><th>ポート</th><th>状態</th></tr>
                </thead>
                <tbody id="servicesBody"></tbody>
            </table>

            <h3 style="font-size:0.86rem;font-weight:800;color:#334155;margin:14px 0 6px;">🖧 物理ネットワークアダプタ (NIC)</h3>
            <div id="nicsBox" style="font-size:0.78rem;color:#334155;"></div>
        </section>
    </div>

    <!-- 院内LAN死活・SNMPポーリング ＆ 内蔵SNMPエージェント設定 -->
    <div class="two-col">
        <section class="rs-card" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
                <h2 style="font-size:0.96rem;font-weight:800;color:#1d4ed8;">🏥 院内カルテサーバ・医療機器・SNMP機器 死活レーダー</h2>
            </div>
            <div style="overflow-x:auto;">
                <table class="ops-tbl">
                    <thead>
                        <tr>
                            <th>機器・サーバ名</th>
                            <th>IP / 方式</th>
                            <th>状態 / 応答速度</th>
                            <th>SNMP情報 / 操作</th>
                        </tr>
                    </thead>
                    <tbody id="lanNodesBody"></tbody>
                </table>
            </div>

            <!-- カスタムSNMP/Ping機器追加フォーム -->
            <div style="margin-top:10px;padding:10px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;">
                <div style="font-size:0.77rem;font-weight:800;color:#334155;margin-bottom:6px;">➕ ルータ・NAS・UPS・スイッチ等の監視ターゲットを追加（Ping / TCP / SNMP v2c 対応）</div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                    <input type="text" id="addName" class="input" placeholder="名称 (例: 院内ルータ / UPS)" style="width:155px;height:28px;font-size:0.78rem;">
                    <input type="text" id="addHost" class="input" placeholder="IP (例: 192.168.1.1)" style="width:135px;height:28px;font-size:0.78rem;">
                    <input type="number" id="addPort" class="input" placeholder="TCP port (任意)" style="width:105px;height:28px;font-size:0.78rem;">
                    <label style="font-size:0.76rem;display:flex;align-items:center;gap:3px;cursor:pointer;">
                        <input type="checkbox" id="addSnmp" checked> SNMP取得
                    </label>
                    <input type="text" id="addComm" class="input" value="public" placeholder="Community" style="width:82px;height:28px;font-size:0.78rem;">
                    <button type="button" class="btn btn-sm btn-primary" style="height:28px;" onclick="addCustomTarget()">追加</button>
                </div>
            </div>
        </section>

        <section class="rs-card" style="margin-bottom:0;">
            <h2 style="font-size:0.96rem;font-weight:800;color:#0f766e;margin-bottom:8px;">📡 内蔵 SNMP v2c エージェント管理 (外部監視連携)</h2>
            <p style="font-size:0.76rem;color:#475569;margin-bottom:8px;">
                RS-Nextサーバ自身が標準 <strong>SNMP v1/v2c (UDP 161)</strong> で応答します。Zabbix・PRTG・NAS等のSNMPマネージャからCPU・RAM・各SSD空き容量・物理ディスク異常をそのまま監視できます。
            </p>
            <div style="padding:10px;background:#f0fdfa;border:1px solid #99f6e4;border-radius:8px;margin-bottom:10px;">
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                    <label style="font-size:0.8rem;font-weight:800;display:flex;align-items:center;gap:4px;cursor:pointer;">
                        <input type="checkbox" id="snmpEn" checked> SNMP有効
                    </label>
                    <span style="font-size:0.76rem;">UDP Port:</span>
                    <input type="number" id="snmpPort" class="input" value="161" style="width:72px;height:28px;font-size:0.78rem;">
                    <span style="font-size:0.76rem;">Community:</span>
                    <input type="text" id="snmpComm" class="input" value="public" style="width:95px;height:28px;font-size:0.78rem;">
                    <button type="button" class="btn btn-sm btn-teal" style="height:28px;" onclick="saveSnmpConfig()">💾 保存</button>
                    <button type="button" class="btn btn-sm" style="height:28px;" onclick="testSnmpHost('127.0.0.1')">🔍 自己応答テスト</button>
                </div>
                <div id="snmpTestOut" style="display:none;margin-top:8px;padding:8px;background:#0f172a;color:#e2e8f0;border-radius:6px;font-family:Consolas,monospace;font-size:0.73rem;white-space:pre-wrap;"></div>
            </div>

            <div style="font-size:0.74rem;color:#334155;">
                <strong>主要公開 OID 一覧 (MIB-2 / RS-Next Enterprise MIB):</strong>
                <table class="ops-tbl" style="margin-top:4px;font-size:0.72rem;">
                    <tr><td><code>1.3.6.1.2.1.1.1.0</code></td><td>sysDescr (OS・CPU・RAM・物理ディスク要約)</td></tr>
                    <tr><td><code>1.3.6.1.2.1.1.3.0</code></td><td>sysUpTime (OS連続稼働時間)</td></tr>
                    <tr><td><code>1.3.6.1.2.1.25.3.3.1.2.1</code></td><td>hrProcessorLoad (CPU使用率 %)</td></tr>
                    <tr><td><code>1.3.6.1.4.1.99999.1.1.0</code></td><td>rsnextOverallHealth (0=正常 / 1=注意 / 2=異常)</td></tr>
                    <tr><td><code>1.3.6.1.4.1.99999.1.4.0</code></td><td>現役 E: ドライブ空き容量 (GB)</td></tr>
                    <tr><td><code>1.3.6.1.4.1.99999.1.7.0 / 8.0</code></td><td>正常ディスク本数 / 異常ディスク本数</td></tr>
                </table>
            </div>
        </section>
    </div>

    <!-- Windows ハードウェア・ディスク イベントログ -->
    <section class="rs-card">
        <h2 style="font-size:0.96rem;font-weight:800;color:#b45309;margin-bottom:8px;">🛡️ Windows システムイベントログ ハードウェア・ディスク異常監視（直近7日間）</h2>
        <div id="hwEventsBox"></div>
    </section>

</main>

<div class="rs-toast" id="rsToast"></div>

<script>
let gChartSpan = 'fast_10s';
let gHistory = { fast_10s: [], hourly_24h: [] };
let gSnmpInitialized = false;

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

function setChartSpan(span) {
    gChartSpan = span;
    document.getElementById('btnSpanFast').className = span === 'fast_10s' ? 'btn btn-sm btn-primary' : 'btn btn-sm';
    document.getElementById('btnSpan24h').className = span === 'hourly_24h' ? 'btn btn-sm btn-primary' : 'btn btn-sm';
    renderCharts();
}

function buildDualSvgChart(points, keyA, keyB, colorA, colorB, fixedMax = null) {
    const w = 520, h = 128, padL = 34, padR = 10, padT = 10, padB = 20;
    const plotW = w - padL - padR;
    const plotH = h - padT - padB;
    if (!points || points.length === 0) {
        return `<div style="color:#94a3b8;font-size:0.78rem;display:flex;align-items:center;justify-content:center;height:100%;">データ収集中...</div>`;
    }
    let maxVal = fixedMax || 10;
    if (!fixedMax) {
        for (const p of points) {
            maxVal = Math.max(maxVal, Number(p[keyA] || 0), Number(p[keyB] || 0));
        }
        maxVal = Math.ceil(maxVal * 1.2);
    }

    const toXY = (idx, val) => {
        const x = padL + (points.length === 1 ? plotW : (idx / (points.length - 1)) * plotW);
        const y = padT + plotH - Math.min(1, Math.max(0, val / maxVal)) * plotH;
        return `${x.toFixed(1)},${y.toFixed(1)}`;
    };

    const polyA = points.map((p, i) => toXY(i, Number(p[keyA] || 0))).join(' ');
    const polyB = points.map((p, i) => toXY(i, Number(p[keyB] || 0))).join(' ');
    const tFirst = points[0].t || '';
    const tLast = points[points.length - 1].t || '';
    const lastA = points[points.length - 1][keyA] ?? 0;
    const lastB = points[points.length - 1][keyB] ?? 0;

    return `
    <svg viewBox="0 0 ${w} ${h}" width="100%" height="100%" preserveAspectRatio="none">
        <line x1="${padL}" y1="${padT}" x2="${w - padR}" y2="${padT}" stroke="#1e293b" stroke-width="1"/>
        <line x1="${padL}" y1="${padT + plotH / 2}" x2="${w - padR}" y2="${padT + plotH / 2}" stroke="#1e293b" stroke-width="1"/>
        <line x1="${padL}" y1="${padT + plotH}" x2="${w - padR}" y2="${padT + plotH}" stroke="#334155" stroke-width="1"/>
        <text x="3" y="${padT + 8}" fill="#94a3b8" font-size="10">${maxVal}</text>
        <text x="3" y="${padT + plotH / 2 + 4}" fill="#94a3b8" font-size="10">${Math.round(maxVal / 2)}</text>
        <text x="12" y="${padT + plotH}" fill="#94a3b8" font-size="10">0</text>
        <polyline fill="none" stroke="${colorA}" stroke-width="2.2" points="${polyA}"/>
        <polyline fill="none" stroke="${colorB}" stroke-width="2.0" stroke-dasharray="4,2" points="${polyB}"/>
        <text x="${padL}" y="${h - 4}" fill="#94a3b8" font-size="10">${esc(tFirst)}</text>
        <text x="${w - padR}" y="${h - 4}" fill="#cbd5e1" font-size="10" text-anchor="end">現在 (${lastA} / ${lastB}) ${esc(tLast)}</text>
    </svg>`;
}

function renderCharts() {
    const pts = gHistory[gChartSpan] || [];
    document.getElementById('chartCpuRam').innerHTML = buildDualSvgChart(pts, 'cpu', 'ram', '#38bdf8', '#34d399', 100);
    document.getElementById('chartNet').innerHTML = buildDualSvgChart(pts, 'rx', 'tx', '#fbbf24', '#f472b6', null);
}

async function loadHw(forceRefresh = false) {
    const res = await fetch('ops_api.php?action=hw_status' + (forceRefresh ? '&refresh=1' : ''));
    const data = await res.json();
    if (data.status !== 'success' || !data.telemetry) return;

    const tel = data.telemetry;
    gHistory = data.history || { fast_10s: [], hourly_24h: [] };
    renderCharts();

    // バナー更新
    const hb = document.getElementById('healthBanner');
    const st = tel.overall_status || 'OK';
    hb.className = 'health-banner hb-' + st;
    const sys = tel.system || {};
    document.getElementById('hbTitle').textContent =
        st === 'OK'
            ? `🟢 ALL SYSTEMS HEALTHY — ${sys.hostname || ''} (${sys.os_name || ''}) 全ハードウェア正常稼働中`
            : (st === 'WARN' ? `⚠️ 注意アラートあり — 状態を確認してください` : `🚨 重大ハードウェア警告 — 直ちに確認してください！`);
    document.getElementById('hbSub').textContent =
        (tel.alerts && tel.alerts.length > 0)
            ? tel.alerts.join(' ／ ')
            : `マザーボード: ${sys.motherboard || '-'} ／ CPU: ${sys.cpu_name || '-'} ／ 最終計測: ${(tel.updated_at || '').slice(11, 19)}`;

    // KPI更新
    const cpu = Number(tel.cpu_percent || 0);
    document.getElementById('kpiCpu').textContent = `${cpu.toFixed(1)} %`;
    document.getElementById('barCpu').style.width = `${Math.min(100, cpu)}%`;
    document.getElementById('barCpu').style.background = cpu > 85 ? '#dc2626' : '#2563eb';
    document.getElementById('subCpu').textContent = `${sys.cpu_name || ''} (${sys.cpu_logical_cores || '-'} スレッド)`;

    const mem = tel.memory || {};
    const ramPct = Number(mem.percent || 0);
    document.getElementById('kpiRam').textContent = `${ramPct.toFixed(1)} %`;
    document.getElementById('barRam').style.width = `${Math.min(100, ramPct)}%`;
    document.getElementById('subRam').textContent = `使用中 ${mem.used_gb || 0} GB / 搭載 ${mem.total_gb || 0} GB (空き ${mem.avail_gb || 0} GB)`;

    const net = tel.network || {};
    document.getElementById('kpiNet').textContent = `⬇ ${net.rx_mbps || 0} / ⬆ ${net.tx_mbps || 0} Mbps`;
    const mainNic = (net.adapters && net.adapters[0]) ? net.adapters[0] : null;
    document.getElementById('subNet').textContent = mainNic ? `${mainNic.name} (${mainNic.speed_mbps} Mbps)` : 'NIC情報取得済';

    const disks = (tel.storage && tel.storage.disks) ? tel.storage.disks : [];
    const okDisks = disks.filter(d => d.healthy).length;
    const kpiDisksEl = document.getElementById('kpiDisks');
    kpiDisksEl.textContent = `${okDisks} / ${disks.length} 正常`;
    kpiDisksEl.style.color = okDisks === disks.length ? '#16a34a' : '#dc2626';
    document.getElementById('subDisks').textContent = `検出エンジン: ${(tel.storage && tel.storage.source) || '-'}`;

    document.getElementById('kpiUptime').textContent = sys.uptime_str || '-';
    const sa = tel.snmp_agent || {};
    document.getElementById('subSnmp').textContent = sa.active
        ? `🟢 SNMP UDP:${sa.port} 稼働中 (応答 ${sa.requests_served || 0} 回)`
        : (sa.enabled ? `⚠️ SNMP待受エラー: ${sa.last_error || '権限確認'}` : '⏸️ SNMPエージェント無効');

    if (!gSnmpInitialized && sa.port) {
        gSnmpInitialized = true;
        document.getElementById('snmpEn').checked = !!sa.enabled;
        document.getElementById('snmpPort').value = sa.port || 161;
        document.getElementById('snmpComm').value = sa.community || 'public';
    }

    // 物理ドライブベイ描画 (RAIDXpert2 物理Slot <-> Array member_slots 正確紐付け ＆ OS起動ドライブ明示)
    document.getElementById('storageSourceBadge').textContent = `コントローラ情報源: ${(tel.storage && tel.storage.source) || '-'}`;
    const arrays = (tel.storage && tel.storage.arrays) ? tel.storage.arrays : [];
    const volMap = {};
    (tel.volumes || []).forEach(v => { if (v.drive) volMap[String(v.drive).toUpperCase()] = v; });

    document.getElementById('physicalBays').innerHTML = disks.map(d => {
        let cand = null;
        if (arrays.length > 0) {
            cand = arrays.find(a => Array.isArray(a.member_slots) && a.member_slots.includes(d.slot)) || null;
        }
        const drv = String(d.drive || (cand && cand.drive) || '').toUpperCase();
        const vInfo = drv ? (volMap[drv] || null) : null;
        const isBoot = Boolean(d.is_boot || (cand && cand.is_boot) || drv === 'C:');
        const arrId = d.array_id || (cand && cand.array_id) || null;
        const arrState = d.array_state || (cand && cand.state) || '';
        const arrName = d.array_name || (cand && cand.name) || '';
        const volLabel = d.volume_label || (vInfo && vInfo.label) || '';
        const volRole = d.volume_role || (vInfo && vInfo.role) || '';

        let mappedLine = '';
        if (drv || arrId) {
            const parts = [];
            if (arrId) parts.push(`Array #${arrId}`);
            if (drv) {
                const lblStr = (volLabel && volLabel !== drv) ? ` [${volLabel}]` : '';
                parts.push(`${isBoot ? '🪟 ' : '💿 '}${drv}${lblStr}`);
            }
            if (arrName && arrName !== volLabel) parts.push(`(${arrName})`);
            else if (volRole) parts.push(`— ${volRole}`);
            if (arrState) parts.push(`[${arrState}]`);
            mappedLine = parts.join(' ');
        }

        const bootBadge = isBoot
            ? ` <span style="background:#1d4ed8;color:#ffffff;padding:1px 7px;border-radius:999px;font-size:0.68rem;font-weight:800;margin-left:4px;">🪟 OS起動ドライブ (${esc(drv || 'C:')})</span>`
            : (drv ? ` <span style="background:#e0f2fe;color:#0369a1;padding:1px 7px;border-radius:999px;font-size:0.68rem;font-weight:800;margin-left:4px;">${esc(drv)}</span>` : '');

        return `
            <div class="bay-card ${d.healthy ? 'healthy' : 'fault'}" style="${isBoot ? 'border-color:#60a5fa;background:#eff6ff;' : ''}">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap;">
                    <strong style="font-size:0.86rem;color:#0f172a;">💽 Physical Slot #${d.slot}: ${esc(d.model)}${bootBadge}</strong>
                    <span class="${d.healthy ? 'pill-ok' : 'pill-err'}">${d.healthy ? '🟢 ' + esc(d.state) : '🚨 ' + esc(d.state)}</span>
                </div>
                <div style="font-size:0.75rem;color:#334155;margin-top:5px;line-height:1.5;">
                    容量: <strong>${esc(d.size)}</strong> ／ バス: <code>${esc(d.bus)} (${esc(d.link_speed)})</code><br>
                    S/N: <code>${esc(d.serial)}</code> ／ FW: <code>${esc(d.firmware)}</code> ／ SMART監視: <strong>${esc(d.smart_poll)}</strong>
                    ${mappedLine ? `<br><span style="color:${isBoot ? '#1d4ed8' : '#0f766e'};font-weight:800;">➔ ${esc(mappedLine)}</span>` : ''}
                </div>
            </div>
        `;
    }).join('');

    // 論理ドライブ描画
    document.getElementById('volumesList').innerHTML = (tel.volumes || []).map(v => {
        const barCol = v.status === 'CRITICAL' ? '#dc2626' : (v.status === 'WARN' ? '#d97706' : v.color);
        const isBootVol = Boolean(v.is_boot || String(v.drive).toUpperCase() === 'C:');
        const icon = isBootVol ? '🪟' : (String(v.drive).toUpperCase() === 'H:' ? '🗄️' : '💿');
        return `
            <div style="padding:9px 12px;border:1px solid ${isBootVol ? '#93c5fd' : 'var(--border-light)'};border-radius:9px;background:${isBootVol ? '#eff6ff' : '#f8fafc'};">
                <div style="display:flex;justify-content:space-between;font-size:0.83rem;font-weight:800;gap:8px;flex-wrap:wrap;">
                    <span>${icon} <strong>${esc(v.drive)}</strong> [${esc(v.label)}] — <span style="color:${esc(v.color)};">${esc(v.role)}</span></span>
                    <span>使用率 ${v.used_percent}% (空き <strong>${v.free_gb.toLocaleString()} GB</strong> / ${v.total_gb.toLocaleString()} GB)</span>
                </div>
                <div class="mini-bar"><div style="width:${v.used_percent}%;background:${barCol};"></div></div>
            </div>
        `;
    }).join('');

    // サービス死活
    document.getElementById('servicesBody').innerHTML = (tel.services || []).map(s => `
        <tr>
            <td style="font-weight:700;">${esc(s.name)}</td>
            <td><code>${esc(s.endpoint)}</code></td>
            <td><span class="${s.ok ? 'pill-ok' : 'pill-err'}">${s.ok ? '● 正常応答' : '🚨 停止/応答なし'}</span></td>
        </tr>
    `).join('');

    // NIC一覧
    document.getElementById('nicsBox').innerHTML = (net.adapters || []).map(n => `
        <div style="padding:7px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:5px;">
            <strong>${esc(n.name)}</strong> — リンク速度: <strong>${n.speed_mbps} Mbps</strong> ／ MAC: <code>${esc(n.mac)}</code><br>
            通信: ⬇ ${n.rx_mbps} Mbps / ⬆ ${n.tx_mbps} Mbps ／ パケットエラー: <strong>${n.rx_errors + n.tx_errors}</strong>
        </div>
    `).join('');

    // LAN ＆ SNMPノード
    document.getElementById('lanNodesBody').innerHTML = (tel.lan_nodes || []).map(n => {
        const sn = n.snmp;
        let snmpHtml = `<button type="button" class="btn btn-sm" style="height:24px;padding:0 7px;font-size:0.72rem;" onclick="testSnmpHost('${esc(n.host)}')">🔍 SNMP照会</button>`;
        if (sn && sn.snmp_ok) {
            snmpHtml = `<div style="font-size:0.72rem;color:#0f766e;"><strong>${esc(sn.sys_name || 'SNMP OK')}</strong> (${esc(sn.sys_uptime_str)})<br><span style="color:#64748b;">${esc((sn.sys_descr || '').slice(0, 58))}</span></div>`;
        }
        const delBtn = n.is_custom
            ? ` <button type="button" class="btn btn-sm btn-danger" style="height:24px;padding:0 6px;font-size:0.7rem;" onclick="deleteCustomTarget('${esc(n.id)}')">✕</button>`
            : '';
        return `
            <tr>
                <td>
                    <strong>${esc(n.name)}</strong><br>
                    <span style="font-size:0.71rem;color:#64748b;">${esc(n.role)}</span>
                </td>
                <td><code>${esc(n.host)}</code><br><span style="font-size:0.7rem;color:#64748b;">${esc(n.method)}</span></td>
                <td>
                    ${n.alive
                        ? `<span class="pill-ok">🟢 Online (${n.rtt_ms != null ? n.rtt_ms + ' ms' : '<1 ms'})</span>`
                        : `<span class="pill-warn">○ 応答なし (電源OFF/閉域)</span>`}
                </td>
                <td>${snmpHtml}${delBtn}</td>
            </tr>
        `;
    }).join('');

    // Windows ハードウェアイベントログ
    const evs = tel.hw_events || [];
    const evBox = document.getElementById('hwEventsBox');
    if (evs.length === 0) {
        evBox.innerHTML = `<div style="padding:12px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;color:#166534;font-weight:700;font-size:0.83rem;">✅ 直近7日間、ディスク不良セクタ・NVMeタイムアウト・RAID劣化・電源異常イベントは 0 件です。</div>`;
    } else {
        evBox.innerHTML = `<table class="ops-tbl"><thead><tr><th>発生日時</th><th>レベル</th><th>ソース</th><th>ID</th><th>メッセージ</th></tr></thead><tbody>` +
            evs.map(e => `<tr><td style="white-space:nowrap;"><code>${esc(e.Time)}</code></td><td><span class="pill-warn">${esc(e.Level)}</span></td><td><code>${esc(e.Provider)}</code></td><td>${esc(e.Id)}</td><td>${esc(e.Msg)}</td></tr>`).join('') +
            `</tbody></table>`;
    }
}

async function saveSnmpConfig() {
    const enabled = document.getElementById('snmpEn').checked;
    const port = parseInt(document.getElementById('snmpPort').value, 10) || 161;
    const community = document.getElementById('snmpComm').value.trim() || 'public';
    const res = await fetch('ops_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_snmp_agent', enabled, port, community }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast('✅ ' + out.message);
        setTimeout(() => loadHw(true), 1200);
    } else {
        alert(out.message || '保存エラー');
    }
}

async function testSnmpHost(host) {
    const port = parseInt(document.getElementById('snmpPort').value, 10) || 161;
    const community = document.getElementById('snmpComm').value.trim() || 'public';
    const box = document.getElementById('snmpTestOut');
    box.style.display = 'block';
    box.textContent = `🔄 SNMP v2c GetRequest 送信中 -> ${host}:${port} (community=${community}) ...`;
    const res = await fetch('ops_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'snmp_test', host, port, community }),
    });
    const out = await res.json();
    if (out.status === 'success' && out.result) {
        const r = out.result;
        if (r.snmp_ok) {
            box.textContent =
                `✅ SNMP応答成功 (${r.rtt_ms} ms) [${host}:${port}]\n` +
                `  sysName     : ${r.sys_name}\n` +
                `  sysDescr    : ${r.sys_descr}\n` +
                `  sysUpTime   : ${r.sys_uptime_str}\n` +
                `  sysLocation : ${r.sys_location}` +
                (r.extra_oids && Object.keys(r.extra_oids).length
                    ? `\n  Enterprise  : ` + JSON.stringify(r.extra_oids)
                    : '');
        } else {
            box.textContent = `⚠️ ${host}:${port} からSNMP応答がありませんでした (${r.error})\n※相手機器側でSNMP機能がOFFになっている可能性があります。`;
        }
    } else {
        box.textContent = 'エラー: ' + (out.message || '通信失敗');
    }
}

async function addCustomTarget() {
    const name = document.getElementById('addName').value.trim();
    const host = document.getElementById('addHost').value.trim();
    const tcp_port = parseInt(document.getElementById('addPort').value, 10) || 0;
    const snmp = document.getElementById('addSnmp').checked;
    const community = document.getElementById('addComm').value.trim() || 'public';
    if (!host) {
        alert('監視するIPアドレスを入力してください');
        return;
    }
    const res = await fetch('ops_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_custom_target', name, host, tcp_port, snmp, community }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        document.getElementById('addName').value = '';
        document.getElementById('addHost').value = '';
        document.getElementById('addPort').value = '';
        showToast('✅ ' + out.message);
        loadHw(true);
    } else {
        alert(out.message || '追加エラー');
    }
}

async function deleteCustomTarget(id) {
    if (!confirm('このカスタム監視ターゲットを削除しますか？')) return;
    await fetch('ops_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_custom_target', id }),
    });
    showToast('🗑️ 削除しました');
    loadHw(true);
}

loadHw(false);
setInterval(() => loadHw(false), 10000);
</script>
</body>
</html>

