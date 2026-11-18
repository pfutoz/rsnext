<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next メイン検査一覧 ＆ 統合クリニカルワークスペース (index.php)
// - 完全 UTF-8 PostgreSQL (rsnext_db) ＋ pg_trgm 高速横断検索
// - ブラウザ完結 DICOM / 12誘導心電図 / PDF モーダル＆別タブ閲覧
// - 所見編集・誤患者ID付け替え・ゴミ箱復元モーダル（旧 2000.cgi 完全置換）
// - 胃がん検診モード限定（?mode=jpeg）年齢クリック一発フォルダ保存＆ZIP出力
// ==============================================================================

require_once __DIR__ . '/lib/bootstrap.php';

$cfg = rsnext_config();

// 共通常駐モジュール (watcher.vbs) 併用時の非同期起動トリガーAPI（ゼロSMB HTTP_DICOM完全対応）
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'launch') {
    header('Content-Type: application/json; charset=UTF-8');
    $studyId    = (int)($_POST['study_id'] ?? 0);
    $folder     = trim((string)($_POST['folder'] ?? ''));
    $launchMode = strtoupper(trim((string)($_POST['launch_mode'] ?? 'HTTP_DICOM')));

    $bundleUrlOut = '';
    $cacheKeyOut  = '';
    if ($launchMode === 'HTTP_DICOM' || $launchMode === 'NORMAL') {
        if ($studyId <= 0 && $folder === '') {
            echo json_encode(['status' => 'error', 'message' => '検査IDまたはフォルダパスが空です'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $triggerLine = rsnext_build_http_dicom_trigger_line($studyId, $folder);
        $parts = explode('|', $triggerLine, 3);
        $bundleUrlOut = $parts[1] ?? '';
        $cacheKeyOut  = $parts[2] ?? '';
    } else {
        if ($folder === '' && $studyId > 0) {
            try {
                $pdo = rsnext_pdo();
                $st = $pdo->prepare("SELECT volume_id, rel_path FROM studies WHERE study_id = ?");
                $st->execute([$studyId]);
                $r = $st->fetch();
                if ($r) {
                    $folder = rsnext_resolve_study_smb((string)$r['volume_id'], (string)$r['rel_path']);
                }
            } catch (Throwable $e) {
            }
        }
        if ($folder === '') {
            echo json_encode(['status' => 'error', 'message' => 'フォルダパスが空です'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $triggerLine = $launchMode . '|' . $folder;
    }

    $tmpDir = __DIR__ . '/tmp';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if ($clientIp === '::1') $clientIp = '127.0.0.1';
    $ok = rsnext_write_client_trigger('trigger', $clientIp, $triggerLine, $tmpDir);
    echo json_encode([
        'status'     => $ok ? 'success' : 'error',
        'mode'       => $launchMode,
        'bundle_url' => $bundleUrlOut,
        'cache_key'  => $cacheKeyOut,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['action'] ?? '') === 'check_launch') {
    header('Content-Type: application/json; charset=UTF-8');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if ($clientIp === '::1') $clientIp = '127.0.0.1';
    $cleanup = !empty($_GET['cleanup']);
    $consumed = rsnext_check_client_trigger_consumed('trigger', $clientIp, __DIR__ . '/tmp', $cleanup);
    echo json_encode(['status' => 'success', 'consumed' => $consumed], JSON_UNESCAPED_UNICODE);
    exit;
}

$enableLegacyLocal = !empty($cfg['enable_legacy_local_viewer']);
$mode = (string)($_GET['mode'] ?? 'web'); // 'web' | 'jpeg' | 'custom'
if (!in_array($mode, ['web', 'jpeg', 'custom'], true)) {
    $mode = 'web';
}

$q          = trim((string)($_GET['q'] ?? ''));
$rawPatId   = trim((string)($_GET['patient_id'] ?? ''));
$patientId  = $rawPatId !== '' ? (rsnext_normalize_patient_id($rawPatId) ?? $rawPatId) : '';
$dateParam  = trim((string)($_GET['date'] ?? ''));
$sortMode   = trim((string)($_GET['sort'] ?? 'date')); // 'date' | 'arrived'
$allDates   = !empty($_GET['all_dates']) || $sortMode === 'arrived' || $patientId !== '' || $q !== '';
$showTrash  = !empty($_GET['trash']);
$catFilter  = trim((string)($_GET['cat'] ?? 'all'));

if ($dateParam === '' && !$allDates && !$showTrash) {
    $dateParam = date('Y-m-d');
}

$prevDate = $dateParam ? date('Y-m-d', strtotime($dateParam . ' -1 day')) : date('Y-m-d', strtotime('-1 day'));
$nextDate = $dateParam ? date('Y-m-d', strtotime($dateParam . ' +1 day')) : date('Y-m-d');

$studies = [];
$catCounts = ['all' => 0, 'endoscopy' => 0, 'xray' => 0, 'us' => 0, 'ecg' => 0, 'other' => 0];
$selectedPatient = null;
$quarantineCount = 0;
$dbError = '';

try {
    $pdo = rsnext_pdo();
    $quarantineCount = rsnext_get_pending_quarantine_count($pdo);

    // 当日検査が0件かつ初期アクセス（パラメータ未指定）の場合は、直近の検査が存在する日を自動ガイドできるよう最新日も取得
    $latestStudyDate = (string)($pdo->query("SELECT TO_CHAR(MAX(study_date), 'YYYY-MM-DD') FROM studies WHERE NOT is_deleted")->fetchColumn() ?: '');

    if ($patientId !== '') {
        $pSt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
        $pSt->execute([$patientId]);
        $selectedPatient = $pSt->fetch() ?: null;
    }

    $baseWhere = [];
    $baseParams = [];

    if ($showTrash) {
        $baseWhere[] = "s.is_deleted = TRUE";
    } else {
        $baseWhere[] = "s.is_deleted = FALSE";
    }

    if ($patientId !== '') {
        $baseWhere[] = "s.patient_id = ?";
        $baseParams[] = $patientId;
    } elseif ($q !== '') {
        $normQId = rsnext_normalize_patient_id($q);
        $kanaQ = rsnext_normalize_kana($q);
        if ($normQId !== null && ctype_digit($q)) {
            $baseWhere[] = "(s.patient_id = ? OR s.patient_id LIKE ? OR CAST(s.legacy_renban AS TEXT) = ? OR CAST(s.study_id AS TEXT) = ?)";
            array_push($baseParams, $normQId, $normQId . '%', $normQId, $normQId);
        } else {
            $baseWhere[] = "(p.kanja_kanji ILIKE ? OR p.kanja_yomi_norm ILIKE ? OR p.kanja_yomi ILIKE ? OR s.kensamei ILIKE ? OR s.findings ILIKE ? OR s.patient_id LIKE ?)";
            array_push($baseParams, '%' . $q . '%', '%' . ($kanaQ ?: $q) . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
    } elseif (!$allDates && $dateParam !== '') {
        $baseWhere[] = "s.study_date = ?";
        $baseParams[] = $dateParam;
    }

    // カテゴリ別件数を集計 (フィルタタブに件数をリアルタイム表示)
    $cntSql = "SELECT s.display_category, COUNT(*) AS cnt
               FROM studies s
               LEFT JOIN patients p ON p.patient_id = s.patient_id
               WHERE " . implode(' AND ', $baseWhere) . "
               GROUP BY s.display_category";
    $cntStmt = $pdo->prepare($cntSql);
    $cntStmt->execute($baseParams);
    foreach ($cntStmt->fetchAll() as $cr) {
        $ck = (string)$cr['display_category'];
        $cv = (int)$cr['cnt'];
        $catCounts['all'] += $cv;
        if (isset($catCounts[$ck])) {
            $catCounts[$ck] += $cv;
        } else {
            $catCounts['other'] += $cv;
        }
    }

    $where = $baseWhere;
    $params = $baseParams;
    if ($catFilter !== '' && $catFilter !== 'all') {
        $where[] = "s.display_category = ?";
        $params[] = $catFilter;
    }

    $whereSql = implode(' AND ', $where);
    $orderSql = ($sortMode === 'arrived')
        ? "s.created_at DESC, s.study_id DESC"
        : "s.study_date DESC, s.study_time DESC NULLS LAST, s.study_id DESC";
    $sql = "SELECT s.*,
                   p.kanja_kanji, p.kanja_yomi, p.kanja_yomi_norm, p.birth_date, p.sex,
                   mp.icon_emoji, mp.badge_color AS prof_color,
                   sf1.filename AS first_filename
            FROM studies s
            LEFT JOIN patients p ON p.patient_id = s.patient_id
            LEFT JOIN modality_profiles mp ON mp.profile_id = s.profile_id
            LEFT JOIN LATERAL (
                SELECT filename FROM study_files WHERE study_id = s.study_id ORDER BY seq_no ASC LIMIT 1
            ) sf1 ON TRUE
            WHERE {$whereSql}
            ORDER BY {$orderSql}
            LIMIT 600";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $studies = $stmt->fetchAll();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    $latestStudyDate = '';
}

$karteTpl = (string)($cfg['karte_url_template'] ?? 'http://192.168.1.16/karteview/karteview.php?patient_no={patient_id}&source=new&do_search=1');

function build_query_url(array $overrides): string {
    $merged = array_merge($_GET, $overrides);
    foreach ($merged as $k => $v) {
        if ($v === null || $v === '') unset($merged[$k]);
    }
    return 'index.php' . (empty($merged) ? '' : ('?' . http_build_query($merged)));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= rsnext_h($cfg['system_name'] ?? 'RS-Next 医用画像ファイリングシステム') ?></title>
    <link rel="stylesheet" href="assets/rsnext_ui.css">
    <style>
        .jpeg-banner {
            background: linear-gradient(90deg, #0f766e 0%, #0d9488 60%, #14b8a6 100%);
            color: #ffffff;
            padding: 5px 12px;
            border-radius: 7px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 0.82rem;
            box-shadow: 0 2px 8px rgba(13, 148, 136, 0.2);
        }
        .filter-panel {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 5px 10px;
            margin-bottom: 6px;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
        }
        .pill-group {
            display: flex;
            flex-wrap: wrap;
            gap: 3px;
            align-items: center;
        }
        .pill-btn {
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: var(--bg-subtle);
            color: var(--text-sub);
            font-size: 0.75rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            line-height: 1.35;
            transition: all 0.12s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .pill-btn:hover { background: var(--primary-soft); border-color: #93c5fd; color: var(--primary); }
        .pill-btn.active { background: var(--primary); border-color: var(--primary); color: #ffffff; }
        .pill-cnt {
            font-size: 0.69rem;
            padding: 0 5px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.08);
            font-family: Consolas, monospace;
        }
        .pill-btn.active .pill-cnt { background: rgba(255, 255, 255, 0.25); color: #ffffff; }

        .patient-banner {
            background: linear-gradient(135deg, #eff6ff 0%, #f0fdfa 100%);
            border: 1px solid #93c5fd;
            border-radius: 8px;
            padding: 5px 12px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        .pat-banner-line { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
        .pat-banner-name { font-size: 0.98rem; font-weight: 800; color: #0f172a; }
        .pat-banner-meta { font-size: 0.79rem; color: #334155; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

        /* 検査テーブル (1行約30pxの高密度スリム設計) */
        .study-table-wrap {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow-x: auto;
            box-shadow: var(--shadow-sm);
        }
        table.study-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.81rem;
            line-height: 1.25;
        }
        table.study-table th {
            background: #f1f5f9;
            color: #334155;
            font-weight: 800;
            font-size: 0.74rem;
            padding: 4px 7px;
            border-bottom: 1.5px solid var(--border);
            text-align: left;
            white-space: nowrap;
            user-select: none;
            position: sticky;
            top: 0;
            z-index: 20;
        }
        table.study-table th.sortable { cursor: pointer; }
        table.study-table th.sortable:hover { color: var(--primary); background: #eff6ff; }
        table.study-table td {
            padding: 2px 7px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
            white-space: nowrap;
            height: 29px;
        }
        table.study-table tbody tr:nth-child(even) td {
            background: #fcfdff;
        }
        table.study-table tbody tr:hover td {
            background: #eff6ff;
        }
        .thumb-cell {
            width: 44px;
            padding-left: 6px !important;
            padding-right: 4px !important;
        }
        .thumb-box {
            width: 38px;
            height: 25px;
            border-radius: 4px;
            background: #0f172a;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 1px solid #cbd5e1;
            position: relative;
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }
        .thumb-box:hover {
            transform: scale(3.4);
            transform-origin: left center;
            z-index: 150;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.45);
            border-color: #38bdf8;
        }
        .thumb-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .renban-btn {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #334155;
            font-family: Consolas, monospace;
            font-size: 0.74rem;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
            cursor: pointer;
            line-height: 1.25;
        }
        .renban-btn:hover { background: #dbeafe; border-color: #60a5fa; color: #1e40af; }

        .kensa-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #ffffff;
            font-weight: 800;
            font-size: 0.76rem;
            padding: 2px 8px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            line-height: 1.25;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.14);
            transition: filter 0.1s;
        }
        .kensa-badge:hover { filter: brightness(1.08); }

        .slice-count {
            font-size: 0.71rem;
            color: var(--text-muted);
            font-weight: 700;
            margin-left: 4px;
        }
        .dt-inline {
            font-family: Consolas, monospace;
            font-size: 0.79rem;
            font-weight: 700;
            color: #0f172a;
        }
        .dt-time {
            color: #64748b;
            font-weight: 600;
            margin-left: 4px;
            font-size: 0.75rem;
        }
        .karte-link {
            font-family: Consolas, monospace;
            font-weight: 800;
            font-size: 0.81rem;
            color: #1d4ed8;
            text-decoration: none;
            padding: 1px 6px;
            border-radius: 4px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
        }
        .karte-link:hover { background: #dbeafe; }

        .pat-link {
            text-decoration: none;
            color: var(--text-main);
            font-weight: 800;
            font-size: 0.85rem;
        }
        .pat-link:hover { color: var(--primary); text-decoration: underline; }
        .pat-yomi {
            display: inline;
            font-size: 0.71rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-left: 5px;
        }

        .findings-cell {
            max-width: 440px;
            width: 100%;
        }
        .findings-inline-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
        }
        .findings-one-line {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 400px;
            font-size: 0.79rem;
            color: #0f172a;
        }
        .doc-chip {
            font-size: 0.7rem;
            color: #475569;
            background: #f1f5f9;
            padding: 0 5px;
            border-radius: 3px;
            margin-right: 4px;
            font-weight: 700;
        }

        .age-jpeg-btn {
            background: linear-gradient(135deg, #0d9488, #059669);
            color: #ffffff;
            border: none;
            padding: 1px 7px;
            border-radius: 4px;
            font-weight: 800;
            font-size: 0.76rem;
            cursor: pointer;
        }
        .age-jpeg-btn:hover { filter: brightness(1.08); }

        .mini-icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 4px;
            border: 1px solid var(--border-light);
            background: var(--bg-subtle);
            text-decoration: none;
            font-size: 0.74rem;
            cursor: pointer;
            flex-shrink: 0;
        }
        .mini-icon-btn:hover { background: #e0f2fe; border-color: #7dd3fc; }

        /* フルスクリーンビューワモーダル */
        .viewer-overlay {
            position: fixed;
            inset: 0;
            background: #000000;
            z-index: 2200;
            display: none;
            flex-direction: column;
        }
        .viewer-overlay.open { display: flex; }
        .viewer-overlay iframe { width: 100%; height: 100%; border: none; }

        /* タブ切替 (編集モーダル内) */
        .edit-tabs { display: flex; gap: 6px; margin-bottom: 14px; border-bottom: 1px solid var(--border-light); padding-bottom: 10px; }
        .edit-tab { padding: 6px 14px; border-radius: 7px; border: 1px solid var(--border); background: var(--bg-subtle); font-weight: 700; font-size: 0.83rem; cursor: pointer; }
        .edit-tab.active { background: var(--primary); color: #ffffff; border-color: var(--primary); }
    </style>
</head>
<body>

<header class="rs-topbar">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <a href="index.php" class="rs-brand">
            <span class="rs-brand-logo">🏥 RS-Next</span>
            <span class="rs-brand-sub"><?= rsnext_h($cfg['clinic_name'] ?? '') ?></span>
        </a>

        <?php if ($quarantineCount > 0): ?>
            <a href="ingest_dashboard.php#quarantine" class="rs-quarantine-pill">
                ⚠️ 未紐付け画像 <?= (int)$quarantineCount ?> 件（救済する）
            </a>
        <?php endif; ?>
    </div>

    <nav class="rs-nav">
        <a href="<?= rsnext_h(build_query_url(['mode' => 'web'])) ?>" class="rs-nav-link <?= $mode === 'web' ? 'active' : '' ?>">🖥️ Webビューワ標準</a>
        <a href="<?= rsnext_h(build_query_url(['mode' => 'jpeg'])) ?>" class="rs-nav-link <?= $mode === 'jpeg' ? 'active' : '' ?>" style="<?= $mode === 'jpeg' ? 'background:#0d9488;border-color:#5eead4;' : '' ?>">📷 胃がん検診JPEG</a>
        <?php if ($enableLegacyLocal): ?>
            <a href="<?= rsnext_h(build_query_url(['mode' => 'custom'])) ?>" class="rs-nav-link <?= $mode === 'custom' ? 'active' : '' ?>">⚡ PC直起動</a>
        <?php endif; ?>
        <span style="opacity:0.3;">|</span>
        <a href="worklist.php<?= $patientId !== '' ? '?patient_id=' . rawurlencode($patientId) : '' ?>" class="rs-nav-link" style="background:rgba(16,185,129,0.22);border-color:rgba(52,211,153,0.5);">📋 撮影依頼・MWL</a>
        <a href="modality_studio.php" class="rs-nav-link">🎛️ Modality Studio</a>
        <a href="import_media.php<?= $patientId !== '' ? '?patient_id=' . rawurlencode($patientId) : '' ?>" class="rs-nav-link">💿 外部取込</a>
        <a href="ingest_dashboard.php" class="rs-nav-link">📡 受信監視</a>
        <a href="hardware_monitor.php" class="rs-nav-link">🖥️ ハード監視</a>
        <a href="backup_manager.php" class="rs-nav-link">🛡️ バックアップ</a>
        <a href="cd_export.php<?= $patientId !== '' ? '?patient_id=' . rawurlencode($patientId) : '' ?>" class="rs-nav-link">💿 CD作成</a>
        <a href="clear_cache.php" target="_blank" class="rs-nav-link" title="サーバー＆クライアント一時キャッシュ管理・強制クリア">🧹 キャッシュ</a>
        <button type="button" class="rs-nav-link" style="cursor:pointer;background:rgba(245,158,11,0.22);border-color:rgba(251,191,36,0.55);color:#fef3c7;" onclick="triggerViewerEmergencyReset()" title="RSViewerが起動しない・ダンマリになった時にクリック（残存プロセス強制終了＆常駐watcher再起動）">🔄 連携リセット</button>
        <a href="client_logs.php" target="_blank" class="rs-nav-link" title="192.168.1.16/rsbase 共通常駐モジュール稼働状況">🖥️ 常駐端末</a>
        <a href="仕様書/index.html" target="_blank" class="rs-nav-link">📚 仕様書</a>
    </nav>
</header>

<main class="rs-main">

    <?php if ($dbError !== ''): ?>
        <div class="rs-card" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b;padding:8px 14px;margin-bottom:6px;">
            <strong>⚠️ データベース接続エラー:</strong> <?= rsnext_h($dbError) ?>
        </div>
    <?php endif; ?>

    <?php if ($mode === 'jpeg'): ?>
        <div class="jpeg-banner">
            <div>
                <strong>📷 胃がん検診 DICOM ➔ JPEG 一発フォルダ保存モード有効中</strong>
                <span style="font-size:0.78rem;opacity:0.92;margin-left:8px;">各行の「📷 年齢」または検査名バッジをクリックすると指定フォルダ直下へ直接JPEG出力します。</span>
            </div>
            <div style="display:flex;gap:6px;">
                <button type="button" class="btn btn-sm" style="padding:2px 8px;" onclick="openJpegConfigOnly()">⚙️ 保存先親フォルダ設定</button>
                <a href="<?= rsnext_h(build_query_url(['mode' => 'web'])) ?>" class="btn btn-sm" style="padding:2px 8px;">通常モードへ戻す</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- 検索・フィルタパネル (縦圧縮・高密度レイアウト) -->
    <section class="filter-panel">
        <div class="filter-row">
            <form method="get" action="index.php" style="display:flex;flex-wrap:wrap;gap:5px;align-items:center;flex:1;">
                <input type="hidden" name="mode" value="<?= rsnext_h($mode) ?>">
                <?php if ($catFilter !== 'all'): ?>
                    <input type="hidden" name="cat" value="<?= rsnext_h($catFilter) ?>">
                <?php endif; ?>

                <div style="display:flex;align-items:center;gap:3px;">
                    <a href="<?= rsnext_h(build_query_url(['date' => $prevDate, 'all_dates' => null, 'sort' => null, 'patient_id' => null])) ?>" class="btn btn-sm" style="padding:2px 7px;" title="前日">◀</a>
                    <input type="date" name="date" value="<?= rsnext_h($dateParam) ?>" class="input" style="width:136px;height:26px;padding:2px 7px;font-size:0.8rem;" onchange="this.form.submit()">
                    <a href="<?= rsnext_h(build_query_url(['date' => $nextDate, 'all_dates' => null, 'sort' => null, 'patient_id' => null])) ?>" class="btn btn-sm" style="padding:2px 7px;" title="翌日">▶</a>
                    <a href="<?= rsnext_h(build_query_url(['date' => date('Y-m-d'), 'all_dates' => null, 'sort' => null, 'q' => null, 'patient_id' => null, 'trash' => null])) ?>" class="btn btn-sm <?= (!$allDates && $dateParam === date('Y-m-d')) ? 'btn-primary' : '' ?>" style="padding:2px 8px;">本日</a>
                    <a href="<?= rsnext_h(build_query_url(['all_dates' => '1', 'sort' => null, 'date' => null, 'trash' => null])) ?>" class="btn btn-sm <?= ($allDates && $sortMode !== 'arrived' && !$showTrash && $patientId === '') ? 'btn-primary' : '' ?>" style="padding:2px 8px;">全期間</a>
                    <a href="<?= rsnext_h(build_query_url(['all_dates' => '1', 'sort' => 'arrived', 'date' => null, 'trash' => null])) ?>" class="btn btn-sm <?= ($sortMode === 'arrived' && !$showTrash) ? 'btn-teal' : '' ?>" style="padding:2px 8px;" title="過去の検査を再送した場合や他院CD取込など、サーバに届いた新しい順に表示">🆕 着信順</a>
                </div>

                <div style="display:flex;align-items:center;gap:4px;flex:1;min-width:220px;max-width:440px;">
                    <input type="text" name="q" value="<?= rsnext_h($q) ?>" class="input" style="height:26px;padding:2px 9px;font-size:0.81rem;" placeholder="🔍 ID・氏名・カナ・検査名・所見で全期間検索...">
                    <button type="submit" class="btn btn-sm btn-primary" style="height:26px;padding:0 10px;">検索</button>
                    <?php if ($q !== '' || $patientId !== '' || $showTrash): ?>
                        <a href="index.php?mode=<?= rsnext_h($mode) ?>" class="btn btn-sm" style="height:26px;padding:0 8px;">クリア</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="pill-group">
                <?php
                $cats = [
                    'all'       => ['label' => 'すべて',         'tip' => '全モダリティ表示'],
                    'endoscopy' => ['label' => '🔬 内視鏡',      'tip' => 'フジ・オリンパス内視鏡・胃がん検診'],
                    'xray'      => ['label' => '🩻 X線・透視',   'tip' => '単純レントゲン (DX/CR) ・ X線透視 (RF)'],
                    'us'        => ['label' => '🔊 超音波',      'tip' => 'エコー検査 (US)'],
                    'ecg'       => ['label' => '📈 心電図',      'tip' => 'フクダ電子 12誘導心電図'],
                    'other'     => ['label' => '💿 他院CD・文書', 'tip' => '他院紹介CD取込画像・スキャンPDF文書・その他'],
                ];
                foreach ($cats as $ck => $cinfo):
                    $cNum = (int)($catCounts[$ck] ?? 0);
                ?>
                    <a href="<?= rsnext_h(build_query_url(['cat' => $ck === 'all' ? null : $ck, 'trash' => null])) ?>"
                       title="<?= rsnext_h($cinfo['tip']) ?>"
                       class="pill-btn <?= (!$showTrash && $catFilter === $ck) ? 'active' : '' ?>">
                        <span><?= rsnext_h($cinfo['label']) ?></span>
                        <span class="pill-cnt"><?= $cNum ?></span>
                    </a>
                <?php endforeach; ?>
                <a href="<?= rsnext_h(build_query_url(['trash' => $showTrash ? null : '1', 'all_dates' => '1'])) ?>"
                   class="pill-btn <?= $showTrash ? 'active' : '' ?>" style="<?= $showTrash ? 'background:#dc2626;border-color:#dc2626;' : '' ?>">🗑️ ゴミ箱</a>
            </div>
        </div>

        <!-- 五十音クイックフィルタ + 件数表示 -->
        <div class="filter-row" style="border-top:1px dashed var(--border-light);padding-top:3px;">
            <div class="pill-group" id="kanaFilterBar">
                <span style="font-size:0.73rem;color:var(--text-muted);font-weight:700;margin-right:2px;">カナ:</span>
                <button type="button" class="pill-btn active" style="padding:1px 7px;font-size:0.72rem;" onclick="filterByKanaRow('ALL', this)">全</button>
                <?php foreach (['ア','カ','サ','タ','ナ','ハ','マ','ヤ','ラ','ワ'] as $kr): ?>
                    <button type="button" class="pill-btn" style="padding:1px 7px;font-size:0.72rem;" onclick="filterByKanaRow('<?= $kr ?>', this)"><?= $kr ?></button>
                <?php endforeach; ?>
            </div>
            <div style="font-size:0.76rem;color:var(--text-muted);font-weight:700;">
                表示: <strong id="visibleCountLabel" style="color:var(--text-main);"><?= count($studies) ?></strong> 件
                <?php if (!$allDates && count($studies) === 0 && !empty($latestStudyDate) && $latestStudyDate !== $dateParam): ?>
                    <a href="<?= rsnext_h(build_query_url(['date' => $latestStudyDate])) ?>" style="margin-left:6px;color:var(--primary);">➔ 最新検査日 (<?= rsnext_h($latestStudyDate) ?>)</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- 患者絞り込み時の1行スリムサマリーバー -->
    <?php if ($patientId !== ''): ?>
        <?php
        $pKanji = $selectedPatient['kanja_kanji'] ?? ($studies[0]['kanja_kanji'] ?? "ID: {$patientId}");
        $pYomi  = $selectedPatient['kanja_yomi'] ?? ($studies[0]['kanja_yomi'] ?? '');
        $pBirth = (string)($selectedPatient['birth_date'] ?? ($studies[0]['birth_date'] ?? ''));
        $pSex   = $selectedPatient['sex'] ?? ($studies[0]['sex'] ?? '');
        $pAge   = rsnext_calc_age($pBirth);
        ?>
        <section class="patient-banner">
            <div class="pat-banner-line">
                <span class="pat-banner-name">👤 <?= rsnext_h($pKanji) ?></span>
                <?php if ($pYomi !== ''): ?>
                    <span style="font-size:0.79rem;font-weight:600;color:#475569;">(<?= rsnext_h($pYomi) ?>)</span>
                <?php endif; ?>
                <div class="pat-banner-meta">
                    <span>ID: <strong><?= rsnext_h($patientId) ?></strong></span>
                    <?php if ($pBirth !== ''): ?>
                        <span><?= rsnext_h(rsnext_format_wareki_birth($pBirth)) ?> (<strong><?= rsnext_h($pAge) ?>歳</strong>)</span>
                    <?php endif; ?>
                    <?php if ($pSex !== ''): ?>
                        <span><?= rsnext_h($pSex) ?></span>
                    <?php endif; ?>
                    <span>履歴 <strong><?= count($studies) ?></strong> 件</span>
                </div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:5px;">
                <a href="worklist.php?patient_id=<?= rawurlencode($patientId) ?>" class="btn btn-sm btn-purple">📋 撮影依頼</a>
                <a href="print_view.php?patient_id=<?= rawurlencode($patientId) ?>" target="_blank" class="btn btn-sm btn-teal">🖨️ 2×4印刷</a>
                <a href="cd_export.php?patient_id=<?= rawurlencode($patientId) ?>" target="_blank" class="btn btn-sm btn-primary">💿 CD作成</a>
                <a href="canvas_view.php?patient_id=<?= rawurlencode($patientId) ?>&limit=all" target="_blank" class="btn btn-sm">🗺️ タイムライン</a>
                <a href="import_media.php?patient_id=<?= rawurlencode($patientId) ?>" class="btn btn-sm">📥 画像追加</a>
                <a href="index.php?mode=<?= rsnext_h($mode) ?>" class="btn btn-sm">✕ 解除</a>
            </div>
        </section>
    <?php endif; ?>

    <!-- メイン検査一覧テーブル (1行スリム高密度表示) -->
    <section class="study-table-wrap">
        <table class="study-table" id="studyTable">
            <thead>
                <tr>
                    <th class="thumb-cell">画像</th>
                    <th style="width:76px;">連番</th>
                    <th class="sortable" onclick="sortStudyTable('kensa')" style="width:168px;">検査種別 ⇅</th>
                    <th class="sortable" onclick="sortStudyTable('date')" style="width:136px;">検査日時 ⇅</th>
                    <th class="sortable" onclick="sortStudyTable('pid')" style="width:82px;">患者ID ⇅</th>
                    <th class="sortable" onclick="sortStudyTable('yomi')" style="width:230px;">患者氏名 ⇅</th>
                    <th style="width:82px;">年齢/性</th>
                    <th>診断所見・施行医</th>
                    <th style="width:102px;">出力連携</th>
                </tr>
            </thead>
            <tbody id="studyTableBody">
            <?php if (empty($studies)): ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:28px 16px;color:var(--text-muted);">
                        該当する検査データはありません。
                        <div style="margin-top:8px;display:flex;justify-content:center;gap:8px;">
                            <a href="<?= rsnext_h(build_query_url(['all_dates' => '1', 'date' => null])) ?>" class="btn btn-sm">全期間の検査を表示</a>
                            <a href="import_media.php" class="btn btn-sm">外部CD・画像を取り込む</a>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($studies as $st):
                    $sid       = (int)$st['study_id'];
                    $dispNo    = $st['legacy_renban'] ? (int)$st['legacy_renban'] : $sid;
                    $pid       = (string)$st['patient_id'];
                    $kanji     = (string)($st['kanja_kanji'] ?: "ID:{$pid}");
                    $yomi      = (string)($st['kanja_yomi'] ?? '');
                    $yomiNorm  = (string)($st['kanja_yomi_norm'] ?: rsnext_normalize_kana($yomi));
                    $birthStr  = (string)($st['birth_date'] ?? '');
                    $age       = rsnext_calc_age($birthStr, (string)$st['study_date']);
                    $sex       = (string)($st['sex'] ?? '');
                    $kensamei  = (string)$st['kensamei'];
                    $dispKensa = rsnext_friendly_kensamei($kensamei);
                    $catKey    = (string)($st['display_category'] ?? 'other');
                    $modUpper  = strtoupper((string)($st['modality'] ?? ''));
                    $defaultEmoji = $st['primary_file_type'] === 'ECG' || $catKey === 'ecg'
                        ? '📈'
                        : ($st['primary_file_type'] === 'VIDEO'
                            ? '🎬'
                            : ($modUpper === 'CT'
                                ? '🌀'
                                : ($modUpper === 'MR'
                                    ? '🧲'
                                    : ($catKey === 'xray' ? '🩻' : ($catKey === 'us' ? '🔊' : ($st['primary_file_type'] === 'PDF' ? '📄' : '🔬'))))));
                    $emoji     = (string)(($st['icon_emoji'] && $catKey !== 'other' && !in_array($modUpper, ['CT', 'MR'], true) && $st['primary_file_type'] !== 'VIDEO') ? $st['icon_emoji'] : $defaultEmoji);
                    $bColor    = (string)($st['badge_color'] ?: ($st['prof_color'] ?: '#2563eb'));
                    $fType     = (string)$st['primary_file_type'];
                    $fCount    = (int)$st['file_count'];
                    $firstFile = (string)($st['first_filename'] ?: '0001.dcm');
                    $dateSlash = str_replace('-', '/', (string)$st['study_date']);
                    $timeShort = $st['study_time'] ? substr((string)$st['study_time'], 0, 5) : '';
                    $findings  = trim((string)($st['findings'] ?? ''));
                    $findingsOneLine = trim((string)preg_replace('/\s+/u', ' ', $findings));
                    $doctor    = trim((string)($st['doctor'] ?? ''));
                    $hoverTip  = trim(($doctor !== '' ? "【担当医】{$doctor}\n" : '') . $findings);
                    $karteUrl  = rsnext_build_karte_url($pid, $karteTpl);
                    $smbPath   = $enableLegacyLocal ? rsnext_resolve_study_smb((string)$st['volume_id'], (string)$st['rel_path']) : '';
                    $isTomo    = rsnext_is_tomographic_study($modUpper, $kensamei, $fType);
                    $openTip   = $isTomo ? 'クリックでクライアントPCの RSViewer で断層画像を開きます（サーバ共有不要）' : '';
                ?>
                <tr class="study-row"
                    data-pid="<?= rsnext_h($pid) ?>"
                    data-yomi="<?= rsnext_h($yomiNorm) ?>"
                    data-kensa="<?= rsnext_h($dispKensa) ?>"
                    data-date="<?= rsnext_h($dateSlash . ' ' . $timeShort) ?>">

                    <!-- サムネイル (ホバーで拡大プレビュー) -->
                    <td class="thumb-cell">
                        <div class="thumb-box" style="position:relative;" <?= $openTip !== '' ? ('title="' . rsnext_h($openTip) . '"') : '' ?> onclick="handleExamOpen(<?= $sid ?>, '<?= rsnext_h($fType) ?>', '<?= rsnext_h(addslashes($pid)) ?>', '<?= rsnext_h(addslashes($kanji)) ?>', '<?= rsnext_h(addslashes($smbPath)) ?>', <?= $isTomo ? 'true' : 'false' ?>)">
                            <?php if ($fType === 'DICOM' || $fType === 'IMAGE' || $fType === 'VIDEO'): ?>
                                <img loading="lazy" src="dcm_api.php?study_id=<?= $sid ?>&file=<?= rawurlencode($firstFile) ?>&thumb=1" alt="thumb">
                                <?php if ($fType === 'VIDEO'): ?>
                                    <span style="position:absolute;bottom:2px;right:2px;background:rgba(13,148,136,0.9);color:#fff;font-size:0.62rem;font-weight:800;padding:0 4px;border-radius:3px;">▶動画</span>
                                <?php elseif ($isTomo): ?>
                                    <span style="position:absolute;bottom:2px;right:2px;background:rgba(2,132,199,0.92);color:#fff;font-size:0.6rem;font-weight:800;padding:0 4px;border-radius:3px;">RSView</span>
                                <?php endif; ?>
                            <?php elseif ($fType === 'ECG'): ?>
                                <span style="font-size:0.95rem;" title="12誘導心電図">📈</span>
                            <?php else: ?>
                                <span style="font-size:0.95rem;" title="PDF文書">📄</span>
                            <?php endif; ?>
                        </div>
                    </td>

                    <!-- ① 連番 -->
                    <td>
                        <button type="button" class="renban-btn" onclick="openStudyEditModal(<?= $sid ?>)" title="所見編集・患者ID付け替え・ゴミ箱操作">
                            #<?= $dispNo ?>
                        </button>
                    </td>

                    <!-- ② 検査名バッジ (1行) -->
                    <td>
                        <button type="button" class="kensa-badge" style="background:<?= rsnext_h($bColor) ?>;"
                                <?= $openTip !== '' ? ('title="' . rsnext_h($openTip) . '"') : '' ?>
                                onclick="handleExamOpen(<?= $sid ?>, '<?= rsnext_h($fType) ?>', '<?= rsnext_h(addslashes($pid)) ?>', '<?= rsnext_h(addslashes($kanji)) ?>', '<?= rsnext_h(addslashes($smbPath)) ?>', <?= $isTomo ? 'true' : 'false' ?>)">
                            <span><?= rsnext_h($emoji) ?></span>
                            <span><?= rsnext_h($dispKensa) ?></span>
                        </button><span class="slice-count"><?= $fType === 'ECG' ? '12誘導' : ($fType === 'VIDEO' ? ($fCount . '本') : ($fCount . '枚')) ?></span>
                    </td>

                    <!-- ③ 検査日時 (1行化) -->
                    <td>
                        <span class="dt-inline"><?= rsnext_h($dateSlash) ?></span><?php if ($timeShort !== ''): ?><span class="dt-time"><?= rsnext_h($timeShort) ?></span><?php endif; ?>
                    </td>

                    <!-- ④ 患者ID (カルテ連携) -->
                    <td>
                        <a href="<?= rsnext_h($karteUrl) ?>" target="_blank" class="karte-link" title="電子カルテを開く"><?= rsnext_h($pid) ?></a>
                    </td>

                    <!-- ⑤ 患者氏名 + カナ (1行横並び) -->
                    <td>
                        <a href="<?= rsnext_h(build_query_url(['patient_id' => $pid, 'q' => null, 'date' => null, 'all_dates' => '1'])) ?>" class="pat-link"><?= rsnext_h($kanji) ?></a><?php if ($yomi !== ''): ?><span class="pat-yomi"><?= rsnext_h($yomi) ?></span><?php endif; ?>
                    </td>

                    <!-- ⑥ 年齢 / 性別 (1行) -->
                    <td>
                        <?php if ($mode === 'jpeg' && ($fType === 'DICOM' || $fType === 'IMAGE')): ?>
                            <button type="button" class="age-jpeg-btn"
                                    onclick="openOneShotJpegModal(<?= $sid ?>, '<?= rsnext_h(addslashes($pid)) ?>', '<?= rsnext_h(addslashes($kanji)) ?>')">
                                📷 <?= rsnext_h($age !== '' ? "{$age}歳" : '保存') ?>
                            </button><span style="font-size:0.75rem;margin-left:3px;"><?= rsnext_h($sex) ?></span>
                        <?php else: ?>
                            <span style="font-weight:700;"><?= rsnext_h($age !== '' ? "{$age}歳" : '-') ?></span><span style="color:var(--text-muted);font-size:0.76rem;margin-left:3px;"><?= rsnext_h($sex) ?></span>
                        <?php endif; ?>
                    </td>

                    <!-- ⑦ 所見・施行医 (1行省略＋ホバー全文表示) -->
                    <td class="findings-cell" <?= $hoverTip !== '' ? ('title="' . rsnext_h($hoverTip) . '"') : '' ?>>
                        <div class="findings-inline-wrap">
                            <div class="findings-one-line">
                                <?php if ($doctor !== ''): ?><span class="doc-chip"><?= rsnext_h($doctor) ?></span><?php endif; ?>
                                <?php if ($findingsOneLine !== ''): ?>
                                    <span><?= rsnext_h($findingsOneLine) ?></span>
                                <?php elseif ($doctor === ''): ?>
                                    <span style="color:#cbd5e1;font-size:0.74rem;">—</span>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="mini-icon-btn" onclick="openStudyEditModal(<?= $sid ?>)" title="所見・情報編集">✏️</button>
                        </div>
                    </td>

                    <!-- ⑧ クイック出力連携 -->
                    <td>
                        <div style="display:flex;gap:3px;align-items:center;">
                            <a href="<?= $fType === 'ECG' ? ('ecg_view.php?study_id=' . $sid) : ('dicom_view.php?study_id=' . $sid) ?>"
                               target="_blank" class="mini-icon-btn" title="別タブでビューワを開く">↗</a>
                            <a href="print_view.php?patient_id=<?= rawurlencode($pid) ?>" target="_blank" class="mini-icon-btn" title="2×4 A4印刷">🖨️</a>
                            <a href="cd_export.php?patient_id=<?= rawurlencode($pid) ?>" target="_blank" class="mini-icon-btn" title="検査CD作成 (共通常駐モジュール対応)">💿</a>
                            <?php if ($fType === 'DICOM' || $fType === 'IMAGE'): ?>
                                <a href="dcm_api.php?action=zip&study_id=<?= $sid ?>&subfolder=<?= rawurlencode($pid . '_' . str_replace('/', '', $dateSlash)) ?>"
                                   class="mini-icon-btn" title="JPEG ZIP一括ダウンロード">💾</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>

<!-- ① フルスクリーン Webビューワ モーダル -->
<div class="viewer-overlay" id="viewerOverlay">
    <iframe id="viewerIframe" src="about:blank" allowfullscreen></iframe>
</div>

<!-- ② 検査編集・誤患者ID付け替え・ゴミ箱モーダル (旧 2000.cgi 置換) -->
<div class="modal-backdrop" id="studyEditModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="editModalTitle">✏️ 検査情報編集・患者ID付け替え</h3>
            <button type="button" class="btn btn-sm" onclick="closeStudyEditModal()">✕ 閉じる</button>
        </div>
        <div class="modal-body">
            <div class="edit-tabs">
                <button type="button" class="edit-tab active" id="tabBtnMeta" onclick="switchEditTab('meta')">📝 所見・検査情報編集</button>
                <button type="button" class="edit-tab" id="tabBtnReassign" onclick="switchEditTab('reassign')">🔄 誤患者IDの付け替え</button>
            </div>

            <!-- タブ1: 所見編集 -->
            <div id="editPaneMeta">
                <input type="hidden" id="editStudyId">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div>
                        <label class="form-label">検査種別名</label>
                        <input type="text" id="editKensamei" class="input">
                    </div>
                    <div>
                        <label class="form-label">施行医 / 担当医</label>
                        <input type="text" id="editDoctor" class="input" placeholder="例: 小野院長">
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="form-label">診断・所見テキスト（一覧およびCD出力・印刷へ反映）</label>
                    <textarea id="editFindings" class="input" rows="4" placeholder="所見を入力してください..."></textarea>
                </div>
                <div>
                    <label class="form-label">内部コメント・検査メモ</label>
                    <input type="text" id="editComment" class="input" placeholder="スタッフ申し送り等">
                </div>
            </div>

            <!-- タブ2: 患者ID付け替え -->
            <div id="editPaneReassign" style="display:none;">
                <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:12px;margin-bottom:14px;font-size:0.84rem;color:#78350f;">
                    <strong>⚠️ 装置での患者ID入力ミス救済:</strong><br>
                    現在の患者ID <strong id="currentPidLabel">-</strong> から別の正しい患者IDへ検査データを安全に移動します（<code>study_meta.json</code> と監査ログも自動更新されます）。
                </div>
                <div style="margin-bottom:12px;">
                    <label class="form-label">移動先の正しい患者ID または 患者名検索</label>
                    <div style="display:flex;gap:8px;">
                        <input type="text" id="reassignQuery" class="input" placeholder="患者ID（例: 70755）または氏名を入力..." oninput="searchTargetPatient()">
                    </div>
                </div>
                <div id="reassignCandidates" style="max-height:180px;overflow-y:auto;border:1px solid var(--border-light);border-radius:8px;margin-bottom:12px;padding:6px;">
                    <div style="font-size:0.8rem;color:var(--text-muted);padding:6px;">移動先の患者IDまたは氏名を入力すると候補が表示されます</div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div>
                        <label class="form-label">確定する移動先 患者ID</label>
                        <input type="text" id="reassignTargetPid" class="input" placeholder="数値ID">
                    </div>
                    <div>
                        <label class="form-label">患者氏名（未登録新患の場合のみ）</label>
                        <input type="text" id="reassignTargetName" class="input" placeholder="マスタ登録済なら空欄でOK">
                    </div>
                </div>
                <div style="margin-top:14px;text-align:right;">
                    <button type="button" class="btn btn-purple" onclick="executePatientReassign()">🔄 この患者IDへ検査を付け替える</button>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <div id="trashBtnBox"></div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn" onclick="closeStudyEditModal()">キャンセル</button>
                <button type="button" class="btn btn-primary" id="saveMetaBtn" onclick="saveStudyMetadata()">💾 所見・変更を保存</button>
            </div>
        </div>
    </div>
</div>

<!-- ③ 胃がん検診 JPEG一発フォルダ保存モーダル -->
<div class="modal-backdrop" id="jpegExportModal">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-head">
            <h3>📷 胃がん検診 JPEG一発フォルダ保存</h3>
            <button type="button" class="btn btn-sm" onclick="closeJpegModal()">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="jpegStudyId">
            <div style="margin-bottom:12px;">
                <label class="form-label">保存先 親フォルダ（次回以降も自動記憶）</label>
                <input type="text" id="jpegBaseDir" class="input" placeholder="C:\Users\Owner\Desktop\jpeg">
            </div>
            <div id="jpegSubfolderGroup">
                <label class="form-label">作成するサブフォルダ名（受診番号・患者ID等 ➔ Enterで即保存）</label>
                <input type="text" id="jpegSubfolder" class="input" style="font-size:1.05rem;font-weight:700;" onkeydown="if(event.key==='Enter'){event.preventDefault();executeOneShotJpeg(false);}">
            </div>
            <div id="jpegStatusMsg" style="margin-top:10px;font-size:0.84rem;color:var(--teal);font-weight:700;"></div>
        </div>
        <div class="modal-foot">
            <span></span>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn" onclick="closeJpegModal()">閉じる</button>
                <button type="button" class="btn btn-teal" id="jpegExecBtn" onclick="executeOneShotJpeg(false)">📷 JPEG一発出力 (Enter)</button>
            </div>
        </div>
    </div>
</div>

<div class="rs-toast" id="rsToast"></div>

<!-- RSViewer 起動ステータス ＆ ダンマリ時ワンクリック復旧ドック -->
<div id="rsvRecoveryDock" style="display:none;position:fixed;right:18px;bottom:18px;z-index:2600;background:#0f172a;color:#f8fafc;border:1px solid #38bdf8;border-radius:10px;padding:10px 14px;box-shadow:0 10px 28px rgba(0,0,0,0.45);min-width:340px;max-width:480px;font-size:0.82rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;">
        <strong id="rsvDockStatus" style="color:#7dd3fc;">🌀 RSViewer を起動しています...</strong>
        <button type="button" onclick="hideRsvDock()" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:0.95rem;line-height:1;">✕</button>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
        <button type="button" id="rsvDockResetBtn" onclick="relaunchViaResetProtocol()" style="background:#d97706;color:#fff;border:1px solid #fbbf24;border-radius:6px;padding:4px 10px;font-size:0.78rem;font-weight:800;cursor:pointer;" title="無反応・ダンマリ時にクリック：裏のRSViewerを強制リセットし常駐を再起動して直接開きます">
            🔄 リセットして強制再起動
        </button>
        <button type="button" id="rsvDockWebBtn" onclick="openFallbackWebViewer()" style="background:#1e293b;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:4px 10px;font-size:0.78rem;font-weight:700;cursor:pointer;" title="ブラウザ内蔵のWebビューワで別タブ表示します">
            🌐 Webビューワで開く
        </button>
        <a href="RSBaseSetup.exe" style="color:#94a3b8;font-size:0.73rem;text-decoration:underline;margin-left:auto;" title="未導入PC用インストーラ">初期導入</a>
    </div>
</div>

<script>
const CURRENT_MODE = <?= json_encode($mode) ?>;
let lastRsvContext = { studyId: 0, bundleUrl: '', cacheKey: '', timer: null, token: 0 };

function showToast(msg) {
    const t = document.getElementById('rsToast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3200);
}

function hideRsvDock() {
    const dock = document.getElementById('rsvRecoveryDock');
    if (dock) dock.style.display = 'none';
    if (lastRsvContext.timer) {
        clearTimeout(lastRsvContext.timer);
        lastRsvContext.timer = null;
    }
}

function triggerViewerEmergencyReset() {
    fetch(`index.php?action=check_launch&cleanup=1&t=${Date.now()}`).catch(() => {});
    showToast('🔄 RSViewer・常駐連携の強制リセットを起動しています...');
    window.location.href = 'rsnext-reset://reset?t=' + Date.now();
}

function relaunchViaResetProtocol() {
    fetch(`index.php?action=check_launch&cleanup=1&t=${Date.now()}`).catch(() => {});
    const stEl = document.getElementById('rsvDockStatus');
    if (stEl) {
        stEl.textContent = '🔄 強制リセット＆直接起動を実行中...';
        stEl.style.color = '#fcd34d';
    }
    if (lastRsvContext.bundleUrl) {
        const u = 'rsnext-reset://relaunch?url=' + encodeURIComponent(lastRsvContext.bundleUrl)
                + '&key=' + encodeURIComponent(lastRsvContext.cacheKey || '')
                + '&t=' + Date.now();
        window.location.href = u;
    } else {
        window.location.href = 'rsnext-reset://reset?t=' + Date.now();
    }
    if (lastRsvContext.timer) clearTimeout(lastRsvContext.timer);
    lastRsvContext.timer = setTimeout(hideRsvDock, 6000);
}

function openFallbackWebViewer() {
    if (lastRsvContext.studyId > 0) {
        window.open(`dicom_view.php?study_id=${lastRsvContext.studyId}`, '_blank');
        hideRsvDock();
    }
}

function launchLocalRsViewer(studyId, folderPath, launchMode, toastMsg) {
    const myToken = ++lastRsvContext.token;
    if (lastRsvContext.timer) clearTimeout(lastRsvContext.timer);
    lastRsvContext.studyId = studyId || 0;
    lastRsvContext.bundleUrl = '';
    lastRsvContext.cacheKey = '';

    const dock = document.getElementById('rsvRecoveryDock');
    const stEl = document.getElementById('rsvDockStatus');
    const webBtn = document.getElementById('rsvDockWebBtn');
    if (dock && stEl) {
        dock.style.borderColor = '#38bdf8';
        stEl.style.color = '#7dd3fc';
        stEl.textContent = toastMsg || '🌀 RSViewer を起動しています...';
        if (webBtn) webBtn.style.display = studyId > 0 ? '' : 'none';
        dock.style.display = 'block';
    }

    const fd = new URLSearchParams({
        action: 'launch',
        study_id: String(studyId || 0),
        folder: folderPath || '',
        launch_mode: launchMode || 'HTTP_DICOM'
    });
    fetch('index.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(async d => {
            if (myToken !== lastRsvContext.token) return;
            if (d.status !== 'success') {
                if (stEl) {
                    stEl.textContent = '⚠️ トリガーエラー: ' + (d.message || '不明');
                    stEl.style.color = '#fca5a5';
                }
                return;
            }
            lastRsvContext.bundleUrl = d.bundle_url || '';
            lastRsvContext.cacheKey  = d.cache_key || '';

            let pickedUp = false;
            for (let i = 0; i < 5; i++) {
                await new Promise(res => setTimeout(res, 700));
                if (myToken !== lastRsvContext.token) return;
                try {
                    const chk = await (await fetch(`index.php?action=check_launch&t=${Date.now()}`)).json();
                    if (chk.consumed) {
                        pickedUp = true;
                        if (stEl) {
                            stEl.textContent = '✅ 起動指示完了（出ない時は下の「リセット再起動」）';
                            stEl.style.color = '#6ee7b7';
                        }
                        lastRsvContext.timer = setTimeout(() => {
                            if (myToken === lastRsvContext.token) hideRsvDock();
                        }, 6500);
                        break;
                    }
                } catch (_) {}
            }
            if (!pickedUp && myToken === lastRsvContext.token) {
                fetch(`index.php?action=check_launch&cleanup=1&t=${Date.now()}`).catch(() => {});
                if (dock && stEl) {
                    dock.style.borderColor = '#f59e0b';
                    stEl.style.color = '#fcd34d';
                    stEl.textContent = '⚠️ 常駐ツール無応答 — 「リセットして強制再起動」を押してください';
                }
            }
        })
        .catch(e => {
            if (stEl) {
                stEl.textContent = '⚠️ 通信エラー: ' + e.message;
                stEl.style.color = '#fca5a5';
            }
        });
}

// ビューワ起動制御（断層データはローカルRSViewer直起動・その他は別タブWebビューワ）
function handleExamOpen(studyId, fileType, patId, patName, smbPath, isTomo = false) {
    if (CURRENT_MODE === 'jpeg' && (fileType === 'DICOM' || fileType === 'IMAGE')) {
        openOneShotJpegModal(studyId, patId, patName);
        return;
    }
    if (CURRENT_MODE === 'custom') {
        if (fileType === 'ECG' && smbPath) {
            launchLocalRsViewer(studyId, smbPath, 'ECG', '⚡ 心電図ビューワを起動しています...');
            return;
        }
        if (fileType === 'DICOM' || fileType === 'IMAGE') {
            launchLocalRsViewer(studyId, '', 'HTTP_DICOM', '🚀 ローカル RSViewer を起動しています（共有フォルダ不要）...');
            return;
        }
    }
    if (isTomo && (fileType === 'DICOM' || fileType === 'IMAGE')) {
        launchLocalRsViewer(studyId, '', 'HTTP_DICOM', '🌀 断層データ (CT/MRI) をローカル RSViewer で起動しています...');
        return;
    }
    const url = (fileType === 'ECG')
        ? `ecg_view.php?study_id=${studyId}`
        : `dicom_view.php?study_id=${studyId}`;
    const win = window.open(url, '_blank');
    if (!win) {
        window.location.href = url;
    }
}

window.closeViewerModal = function() {
    document.getElementById('viewerOverlay').classList.remove('open');
    document.getElementById('viewerIframe').src = 'about:blank';
};

window.addEventListener('message', (e) => {
    const act = e.data && e.data.action;
    if (act === 'closeDicom' || act === 'closeECG' || act === 'closeViewer') {
        window.closeViewerModal();
    } else if (act === 'openViewerNewTab') {
        const iframe = document.getElementById('viewerIframe');
        if (iframe && iframe.src && iframe.src !== 'about:blank') {
            const cleanUrl = iframe.src.replace(/([?&])mode=modal(&|$)/, '$1').replace(/[?&]$/, '');
            window.open(cleanUrl, '_blank');
            window.closeViewerModal();
        }
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        if (document.getElementById('viewerOverlay').classList.contains('open')) {
            window.closeViewerModal();
        }
    }
});

// 五十音フィルタ & クライアントソート
const KANA_MAP = {
    'ア': /^[アイウエオァィゥェォヴ]/u,
    'カ': /^[カキクケコガギグゲゴ]/u,
    'サ': /^[サシスセソザジズゼゾ]/u,
    'タ': /^[タチツテトダヂヅデドッ]/u,
    'ナ': /^[ナニヌネノ]/u,
    'ハ': /^[ハヒフヘホバビブベボパピプペポ]/u,
    'マ': /^[マミムメモ]/u,
    'ヤ': /^[ヤユヨャュョ]/u,
    'ラ': /^[ラリルレロ]/u,
    'ワ': /^[ワヲンヮ]/u,
};

function filterByKanaRow(rowKey, btnEl) {
    document.querySelectorAll('#kanaFilterBar .pill-btn').forEach(b => b.classList.remove('active'));
    btnEl.classList.add('active');
    const re = KANA_MAP[rowKey] || null;
    let count = 0;
    document.querySelectorAll('#studyTableBody tr.study-row').forEach(tr => {
        const yomi = tr.dataset.yomi || '';
        const ok = !re || re.test(yomi);
        tr.style.display = ok ? '' : 'none';
        if (ok) count++;
    });
    document.getElementById('visibleCountLabel').textContent = String(count);
}

let sortDir = 1;
function sortStudyTable(key) {
    sortDir = -sortDir;
    const tbody = document.getElementById('studyTableBody');
    const rows = Array.from(tbody.querySelectorAll('tr.study-row'));
    rows.sort((a, b) => {
        if (key === 'pid') {
            return ((parseInt(a.dataset.pid, 10) || 0) - (parseInt(b.dataset.pid, 10) || 0)) * sortDir;
        }
        const va = a.dataset[key] || '';
        const vb = b.dataset[key] || '';
        return va.localeCompare(vb, 'ja') * sortDir;
    });
    rows.forEach(r => tbody.appendChild(r));
}

// 検査編集・患者ID付け替えモーダル
function switchEditTab(tab) {
    document.getElementById('tabBtnMeta').classList.toggle('active', tab === 'meta');
    document.getElementById('tabBtnReassign').classList.toggle('active', tab === 'reassign');
    document.getElementById('editPaneMeta').style.display = tab === 'meta' ? '' : 'none';
    document.getElementById('editPaneReassign').style.display = tab === 'reassign' ? '' : 'none';
    document.getElementById('saveMetaBtn').style.display = tab === 'meta' ? '' : 'none';
}

async function openStudyEditModal(studyId) {
    switchEditTab('meta');
    const res = await fetch(`study_edit_api.php?action=get_study&study_id=${studyId}`);
    const data = await res.json();
    if (data.status !== 'success') {
        alert(data.message || '検査情報取得エラー');
        return;
    }
    const st = data.study;
    document.getElementById('editStudyId').value = st.study_id;
    document.getElementById('editModalTitle').textContent = `✏️ 検査 #${st.legacy_renban || st.study_id} 編集 (${st.kanja_kanji || st.patient_id})`;
    document.getElementById('editKensamei').value = st.kensamei || '';
    document.getElementById('editDoctor').value = st.doctor || '';
    document.getElementById('editFindings').value = st.findings || '';
    document.getElementById('editComment').value = st.comment || '';
    document.getElementById('currentPidLabel').textContent = `${st.patient_id} (${st.kanja_kanji || ''})`;
    document.getElementById('reassignTargetPid').value = '';
    document.getElementById('reassignTargetName').value = '';

    const trashBox = document.getElementById('trashBtnBox');
    if (st.is_deleted) {
        trashBox.innerHTML = `<button type="button" class="btn btn-teal btn-sm" onclick="toggleTrashStudy(${st.study_id}, false)">♻️ ゴミ箱から復元する</button>`;
    } else {
        trashBox.innerHTML = `<button type="button" class="btn btn-danger btn-sm" onclick="toggleTrashStudy(${st.study_id}, true)">🗑️ ゴミ箱へ移動</button>`;
    }
    document.getElementById('studyEditModal').classList.add('open');
}

function closeStudyEditModal() {
    document.getElementById('studyEditModal').classList.remove('open');
}

async function saveStudyMetadata() {
    const payload = {
        action: 'update_study',
        study_id: document.getElementById('editStudyId').value,
        kensamei: document.getElementById('editKensamei').value,
        doctor: document.getElementById('editDoctor').value,
        findings: document.getElementById('editFindings').value,
        comment: document.getElementById('editComment').value,
    };
    const res = await fetch('study_edit_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast('✅ 所見・検査情報を保存しました');
        setTimeout(() => location.reload(), 400);
    } else {
        alert(out.message || '保存に失敗しました');
    }
}

async function searchTargetPatient() {
    const q = document.getElementById('reassignQuery').value.trim();
    const box = document.getElementById('reassignCandidates');
    if (!q) {
        box.innerHTML = '<div style="font-size:0.8rem;color:#64748b;padding:6px;">移動先の患者IDまたは氏名を入力してください</div>';
        return;
    }
    const res = await fetch(`study_edit_api.php?action=lookup_patient&query=${encodeURIComponent(q)}`);
    const data = await res.json();
    if (data.normalized_id) {
        document.getElementById('reassignTargetPid').value = data.normalized_id;
    }
    if (!data.patients || data.patients.length === 0) {
        box.innerHTML = '<div style="font-size:0.8rem;color:#b45309;padding:6px;">マスタ未登録のIDです（そのままID指定して新規枠として付け替え可能）</div>';
        return;
    }
    box.innerHTML = data.patients.map(p => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 8px;border-bottom:1px solid #f1f5f9;cursor:pointer;"
             onclick="document.getElementById('reassignTargetPid').value='${p.patient_id}';document.getElementById('reassignTargetName').value='${p.kanja_kanji}';">
            <div><strong>ID: ${p.patient_id}</strong> — ${p.kanja_kanji} <small style="color:#64748b;">(${p.kanja_yomi}) ${p.age ? p.age+'歳' : ''} ${p.sex}</small></div>
            <span class="btn btn-sm">選択</span>
        </div>
    `).join('');
}

async function executePatientReassign() {
    const sid = document.getElementById('editStudyId').value;
    const targetPid = document.getElementById('reassignTargetPid').value.trim();
    const targetName = document.getElementById('reassignTargetName').value.trim();
    if (!targetPid) {
        alert('移動先の患者IDを入力または選択してください');
        return;
    }
    if (!confirm(`この検査データを 患者ID: ${targetPid} へ付け替えます。よろしいですか？`)) return;

    const res = await fetch('study_edit_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reassign_patient', study_id: sid, target_patient_id: targetPid, target_patient_name: targetName }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast(`✅ 患者ID ${out.new_patient_id} へ付け替えました`);
        setTimeout(() => location.reload(), 500);
    } else {
        alert(out.message || '付け替えに失敗しました');
    }
}

async function toggleTrashStudy(studyId, toTrash) {
    const msg = toTrash ? 'この検査をゴミ箱へ移動しますか？（後からいつでも復元できます）' : 'この検査をゴミ箱から復元しますか？';
    if (!confirm(msg)) return;
    const res = await fetch('study_edit_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: toTrash ? 'soft_delete' : 'restore_study', study_id: studyId }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast(toTrash ? '🗑️ ゴミ箱へ移動しました' : '♻️ 検査を復元しました');
        setTimeout(() => location.reload(), 400);
    }
}

// 胃がん検診 JPEG一発保存
let jpegConfigOnlyMode = false;

async function openOneShotJpegModal(studyId, patId, patName) {
    jpegConfigOnlyMode = false;
    document.getElementById('jpegStudyId').value = studyId;
    document.getElementById('jpegSubfolderGroup').style.display = '';
    document.getElementById('jpegExecBtn').textContent = '📷 JPEG一発出力 (Enter)';
    document.getElementById('jpegStatusMsg').textContent = `対象: ID ${patId} ${patName} 様`;

    const res = await fetch('dcm_api.php?action=jpeg_direct_config');
    const cfg = await res.json();
    if (cfg.base_dir) document.getElementById('jpegBaseDir').value = cfg.base_dir;
    document.getElementById('jpegSubfolder').value = patId;
    document.getElementById('jpegExportModal').classList.add('open');
    setTimeout(() => {
        const inp = document.getElementById('jpegSubfolder');
        inp.focus();
        inp.select();
    }, 60);
}

async function openJpegConfigOnly() {
    jpegConfigOnlyMode = true;
    document.getElementById('jpegSubfolderGroup').style.display = 'none';
    document.getElementById('jpegExecBtn').textContent = '💾 親フォルダ設定を保存';
    document.getElementById('jpegStatusMsg').textContent = '';
    const res = await fetch('dcm_api.php?action=jpeg_direct_config');
    const cfg = await res.json();
    if (cfg.base_dir) document.getElementById('jpegBaseDir').value = cfg.base_dir;
    document.getElementById('jpegExportModal').classList.add('open');
}

function closeJpegModal() {
    document.getElementById('jpegExportModal').classList.remove('open');
}

async function executeOneShotJpeg(overwrite = false) {
    const baseDir = document.getElementById('jpegBaseDir').value.trim();
    if (jpegConfigOnlyMode) {
        const fd = new URLSearchParams({ base_dir: baseDir });
        const r = await fetch('dcm_api.php?action=jpeg_direct_config', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.status === 'success') {
            showToast('✅ 保存先親フォルダを更新しました');
            closeJpegModal();
        } else {
            alert(j.message || '設定エラー');
        }
        return;
    }

    const studyId = document.getElementById('jpegStudyId').value;
    const sub = document.getElementById('jpegSubfolder').value.trim();
    const fd = new URLSearchParams({
        action: 'save_folder',
        study_id: studyId,
        base_dir: baseDir,
        subfolder: sub,
        overwrite: overwrite ? '1' : '0',
    });
    const res = await fetch('dcm_api.php', { method: 'POST', body: fd });
    const out = await res.json();
    if (out.status === 'exists') {
        if (confirm(out.message)) {
            executeOneShotJpeg(true);
        }
        return;
    }
    if (out.status === 'success') {
        showToast(`📷 ${out.saved_count}枚のJPEGを出力しました (${out.output_dir})`);
        closeJpegModal();
    } else {
        alert(out.message || 'JPEG出力に失敗しました');
    }
}
</script>
</body>
</html>

