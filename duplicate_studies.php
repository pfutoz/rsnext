<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next 重複検査抽出・横並び比較・安全一括整理ツール (duplicate_studies.php)
// - SHA-256 バイナリ一致 / DICOM StudyInstanceUID 一致 / 別患者ID誤撮影残骸 / 同日同種候補
// - 物理削除ではなく「ゴミ箱移動 (is_deleted = TRUE)」＋「所見・担当医の自動統合」により
//   万一の誤操作でも 100% 復元可能 (index.php?trash=1) なクリニック安全設計
// ==============================================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/lib/duplicate_detector.php';

$cfg = rsnext_config();

// JSON API リクエスト処理 (POST アクション / ?api=1 スキャン取得)
$rawBody  = file_get_contents('php://input');
$jsonBody = ($rawBody && empty($_POST)) ? (@json_decode($rawBody, true) ?: []) : [];
$req      = array_merge($_GET, $_POST, $jsonBody);
$action   = trim((string)($req['action'] ?? ''));

if ($action !== '' || !empty($req['api'])) {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $pdo = rsnext_pdo();

        if ($action === 'scan' || (!empty($req['api']) && $action === '')) {
            $scan = rsnext_scan_duplicate_groups($pdo, [
                'type'      => (string)($req['type'] ?? 'all'),
                'cat'       => (string)($req['cat'] ?? 'all'),
                'q'         => (string)($req['q'] ?? ''),
                'date_from' => (string)($req['date_from'] ?? ''),
                'date_to'   => (string)($req['date_to'] ?? ''),
                'limit'     => (int)($req['limit'] ?? 150),
            ]);
            echo json_encode(['status' => 'success'] + $scan, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'merge_and_trash') {
            $keepId   = (int)($req['keep_study_id'] ?? 0);
            $trashIds = is_array($req['trash_study_ids'] ?? null) ? $req['trash_study_ids'] : [];
            $res = rsnext_dup_merge_and_trash($pdo, $keepId, $trashIds);
            echo json_encode(['status' => 'success'] + $res, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'bulk_trash') {
            $items = is_array($req['items'] ?? null) ? $req['items'] : [];
            $res = rsnext_dup_bulk_trash($pdo, $items);
            echo json_encode(['status' => 'success'] + $res, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'ignore_group' || $action === 'unignore_group') {
            $studyIds = is_array($req['study_ids'] ?? null) ? $req['study_ids'] : [];
            $note     = trim((string)($req['note'] ?? ''));
            $sig = rsnext_dup_set_ignore($pdo, $studyIds, $action === 'ignore_group', $note);
            echo json_encode(['status' => 'success', 'group_sig' => $sig], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new RuntimeException('未対応のアクションです: ' . $action);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 画面表示用フィルタ取得
$typeFilter = trim((string)($_GET['type'] ?? 'all')) ?: 'all';
$catFilter  = trim((string)($_GET['cat'] ?? 'all')) ?: 'all';
$q          = trim((string)($_GET['q'] ?? ''));
$dateFrom   = trim((string)($_GET['date_from'] ?? ''));
$dateTo     = trim((string)($_GET['date_to'] ?? ''));
$limit      = max(20, min(500, (int)($_GET['limit'] ?? 150)));

$scanResult = [
    'kpi' => [
        'total_active_groups' => 0,
        'total_redundant_cnt' => 0,
        'reclaimable_bytes'   => 0,
        'reclaimable_human'   => '0 B',
        'auto_safe_groups'    => 0,
        'auto_safe_studies'   => 0,
        'by_type' => [
            'cross_patient'  => 0,
            'exact_hash'     => 0,
            'subset_hash'    => 0,
            'same_day_count' => 0,
            'same_day_exam'  => 0,
            'same_day_cat'   => 0,
            'ignored'        => 0,
        ],
    ],
    'groups'      => [],
    'total_match' => 0,
    'elapsed_ms'  => 0.0,
];
$trashCount = 0;
$quarantineCount = 0;
$dbError = '';

try {
    $pdo = rsnext_pdo();
    $quarantineCount = rsnext_get_pending_quarantine_count($pdo);
    $trashCount = (int)$pdo->query("SELECT COUNT(*) FROM studies WHERE is_deleted = TRUE")->fetchColumn();
    $scanResult = rsnext_scan_duplicate_groups($pdo, [
        'type'      => $typeFilter,
        'cat'       => $catFilter,
        'q'         => $q,
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
        'limit'     => $limit,
    ]);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$kpi    = $scanResult['kpi'];
$groups = $scanResult['groups'];
$karteTpl = (string)($cfg['karte_url_template'] ?? '');

function dup_build_url(array $overrides): string {
    $merged = array_merge($_GET, $overrides);
    foreach ($merged as $k => $v) {
        if ($v === null || $v === '' || ($k === 'type' && $v === 'all') || ($k === 'cat' && $v === 'all')) {
            unset($merged[$k]);
        }
    }
    return 'duplicate_studies.php' . (empty($merged) ? '' : ('?' . http_build_query($merged)));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔍 重複検査抽出・安全整理 - <?= rsnext_h($cfg['system_name'] ?? 'RS-Next') ?></title>
    <link rel="stylesheet" href="assets/rsnext_ui.css">
    <style>
        .dup-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(205px, 1fr));
            gap: 10px;
            margin-bottom: 10px;
        }
        .dup-kpi-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 14px;
            text-decoration: none;
            color: var(--text-main);
            box-shadow: var(--shadow-sm);
            transition: all 0.14s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .dup-kpi-card:hover {
            transform: translateY(-1px);
            border-color: #60a5fa;
            box-shadow: var(--shadow-md);
        }
        .dup-kpi-card.active-kpi {
            border-width: 2px;
            border-color: var(--primary);
            background: #f8fafc;
        }
        .dup-kpi-label {
            font-size: 0.76rem;
            font-weight: 800;
            color: var(--text-sub);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .dup-kpi-val {
            font-size: 1.32rem;
            font-weight: 800;
            margin-top: 3px;
            font-family: Consolas, "Yu Gothic UI", sans-serif;
        }
        .dup-kpi-sub {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .dup-toolbar {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 12px;
            margin-bottom: 8px;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            gap: 7px;
        }
        .dup-filter-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
        }
        .dup-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            align-items: center;
        }
        .dup-pill {
            padding: 3px 10px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: var(--bg-subtle);
            color: var(--text-sub);
            font-size: 0.76rem;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.12s;
        }
        .dup-pill:hover { background: #e2e8f0; color: #0f172a; }
        .dup-pill.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
        }
        .dup-pill .cnt {
            background: rgba(15, 23, 42, 0.1);
            padding: 0 6px;
            border-radius: 999px;
            font-size: 0.7rem;
        }
        .dup-pill.active .cnt {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        .bulk-bar {
            position: sticky;
            top: 42px;
            z-index: 150;
            background: linear-gradient(90deg, #0f172a 0%, #1e293b 100%);
            color: #f8fafc;
            border-radius: 10px;
            padding: 8px 14px;
            margin-bottom: 10px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.22);
        }

        .dup-group-card {
            background: #ffffff;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            margin-bottom: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .dup-group-head {
            padding: 9px 14px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .dup-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.77rem;
            font-weight: 800;
            color: #ffffff;
        }
        .reason-chip {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid #cbd5e1;
            color: #1e293b;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 0.74rem;
            font-weight: 700;
        }
        .timediff-chip {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.73rem;
            font-weight: 800;
        }

        .dup-studies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 10px;
            padding: 10px 12px;
            background: #f8fafc;
        }
        .dup-study-item {
            background: #ffffff;
            border: 2px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 8px;
            transition: border-color 0.14s, background 0.14s;
        }
        .dup-study-item.is-keep {
            border-color: #10b981;
            background: #f0fdf4;
        }
        .dup-study-item.is-trash-checked {
            border-color: #f87171;
            background: #fff5f5;
        }
        .dup-study-item.is-cross {
            border-color: #f59e0b;
            background: #fffbeb;
        }

        .role-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            padding-bottom: 6px;
            border-bottom: 1px dashed #cbd5e1;
        }
        .role-label-keep {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 800;
            color: #065f46;
            cursor: pointer;
        }
        .role-label-trash {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 800;
            color: #b91c1c;
            cursor: pointer;
        }

        .thumb-strip {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }
        .dup-thumb {
            width: 84px;
            height: 62px;
            background: #0f172a;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #94a3b8;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.14s ease, box-shadow 0.14s ease;
        }
        .dup-thumb:hover {
            transform: scale(2.35);
            z-index: 120;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.45);
            border-color: #38bdf8;
        }
        .dup-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .dup-thumb-tag {
            position: absolute;
            bottom: 2px;
            left: 3px;
            background: rgba(15, 23, 42, 0.78);
            color: #e2e8f0;
            font-size: 0.6rem;
            font-weight: 700;
            padding: 0 4px;
            border-radius: 3px;
        }

        .meta-kv-grid {
            display: grid;
            grid-template-columns: 82px 1fr;
            row-gap: 3px;
            column-gap: 6px;
            font-size: 0.78rem;
        }
        .meta-k {
            color: var(--text-muted);
            font-weight: 700;
        }
        .meta-v {
            color: var(--text-main);
            font-weight: 600;
            word-break: break-all;
        }
        .findings-box {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 5px 8px;
            font-size: 0.77rem;
            color: #0f172a;
            max-height: 78px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
        .findings-empty {
            color: #94a3b8;
            font-size: 0.74rem;
            font-style: italic;
        }
        .src-chip {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 0.69rem;
            font-weight: 800;
        }
        .src-legacy { background: #ede9fe; color: #5b21b6; }
        .src-live   { background: #dcfce7; color: #166534; }

        .edit-tabs { display: flex; gap: 6px; margin-bottom: 14px; border-bottom: 1px solid var(--border-light); padding-bottom: 10px; }
        .edit-tab { padding: 6px 14px; border-radius: 7px; border: 1px solid var(--border); background: var(--bg-subtle); font-weight: 700; font-size: 0.83rem; cursor: pointer; }
        .edit-tab.active { background: var(--primary); color: #ffffff; border-color: var(--primary); }

        .toast-pop {
            position: fixed;
            bottom: 22px;
            left: 50%;
            transform: translateX(-50%);
            background: #0f172a;
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 999px;
            font-size: 0.86rem;
            font-weight: 700;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.32);
            z-index: 3000;
            display: none;
        }
    </style>
</head>
<body>

<header class="rs-topbar">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <a href="index.php" class="rs-brand">
            <span class="rs-brand-logo">🏥 RS-Next</span>
            <span class="rs-brand-sub">重複検査抽出・安全整理センター</span>
        </a>
        <?php if ($quarantineCount > 0): ?>
            <a href="ingest_dashboard.php#quarantine" class="rs-quarantine-pill">⚠️ 未紐付け <?= (int)$quarantineCount ?> 件</a>
        <?php endif; ?>
    </div>

    <nav class="rs-nav">
        <a href="index.php" class="rs-nav-link">🖥️ 検査一覧</a>
        <a href="worklist.php" class="rs-nav-link">📋 撮影依頼・MWL</a>
        <a href="modality_studio.php" class="rs-nav-link">🎛️ Modality Studio</a>
        <a href="import_media.php" class="rs-nav-link">💿 外部取込</a>
        <a href="duplicate_studies.php" class="rs-nav-link active">🔍 重複抽出</a>
        <a href="ingest_dashboard.php" class="rs-nav-link">📡 受信監視</a>
        <a href="hardware_monitor.php" class="rs-nav-link">🖥️ ハード監視</a>
        <a href="backup_manager.php" class="rs-nav-link">🛡️ バックアップ</a>
        <a href="clear_cache.php" target="_blank" class="rs-nav-link">🧹 キャッシュ</a>
        <button type="button" class="rs-nav-link" style="cursor:pointer;background:rgba(245,158,11,0.22);border-color:rgba(251,191,36,0.55);color:#fef3c7;" onclick="triggerViewerEmergencyReset()" title="RSViewerが起動しない・ダンマリになった時にクリック">🔄 連携リセット</button>
        <a href="index.php?trash=1" target="_blank" class="rs-nav-link" style="background:rgba(255,255,255,0.14);" title="ゴミ箱へ移動した検査の確認・1クリック復元">♻️ ゴミ箱 (<?= (int)$trashCount ?>)</a>
    </nav>
</header>

<main class="rs-main">

    <?php if ($dbError !== ''): ?>
        <div class="rs-card" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b;padding:10px 14px;margin-bottom:10px;">
            <strong>⚠️ データベースエラー:</strong> <?= rsnext_h($dbError) ?>
        </div>
    <?php endif; ?>

    <!-- KPI サマリーストリップ -->
    <?php
    $cntExact  = (int)($kpi['by_type']['exact_hash'] ?? 0);
    $cntSubset = (int)($kpi['by_type']['subset_hash'] ?? 0);
    $cntCross  = (int)($kpi['by_type']['cross_patient'] ?? 0);
    $cntDayCnt = (int)($kpi['by_type']['same_day_count'] ?? 0);
    $cntDayExm = (int)($kpi['by_type']['same_day_exam'] ?? 0);
    $cntIgn    = (int)($kpi['by_type']['ignored'] ?? 0);
    ?>
    <section class="dup-kpi-grid">
        <a href="<?= rsnext_h(dup_build_url(['type' => 'exact_hash'])) ?>"
           class="dup-kpi-card <?= $typeFilter === 'exact_hash' ? 'active-kpi' : '' ?>">
            <div class="dup-kpi-label">
                <span>🔴 完全一致・包含重複</span>
                <span style="font-size:0.7rem;color:#b91c1c;">最優先</span>
            </div>
            <div class="dup-kpi-val" style="color:<?= ($cntExact + $cntSubset) > 0 ? '#b91c1c' : '#16a34a' ?>;">
                <?= number_format($cntExact + $cntSubset) ?> <small style="font-size:0.8rem;">組</small>
            </div>
            <div class="dup-kpi-sub">完全一致 <?= $cntExact ?>組 ／ 包含・部分一致 <?= $cntSubset ?>組</div>
        </a>

        <a href="<?= rsnext_h(dup_build_url(['type' => 'cross_patient'])) ?>"
           class="dup-kpi-card <?= $typeFilter === 'cross_patient' ? 'active-kpi' : '' ?>"
           style="<?= $cntCross > 0 ? 'background:#fef2f2;border-color:#f87171;' : '' ?>">
            <div class="dup-kpi-label">
                <span>🚨 別患者ID間の同一画像</span>
                <span style="font-size:0.7rem;color:#dc2626;">要確認</span>
            </div>
            <div class="dup-kpi-val" style="color:<?= $cntCross > 0 ? '#dc2626' : '#16a34a' ?>;">
                <?= number_format($cntCross) ?> <small style="font-size:0.8rem;">組</small>
            </div>
            <div class="dup-kpi-sub"><?= $cntCross > 0 ? '誤ID撮影→再送の残骸がないか確認' : '別患者間の画像衝突はありません' ?></div>
        </a>

        <a href="<?= rsnext_h(dup_build_url(['type' => 'same_day_count'])) ?>"
           class="dup-kpi-card <?= $typeFilter === 'same_day_count' ? 'active-kpi' : '' ?>">
            <div class="dup-kpi-label">
                <span>🟠 同日・同患者・同種・同枚数</span>
            </div>
            <div class="dup-kpi-val" style="color:<?= $cntDayCnt > 0 ? '#c2410c' : '#334155' ?>;">
                <?= number_format($cntDayCnt) ?> <small style="font-size:0.8rem;">組</small>
            </div>
            <div class="dup-kpi-sub">再送UID変化 / DICOM+JPEG二重 / 同日再検</div>
        </a>

        <a href="<?= rsnext_h(dup_build_url(['type' => 'same_day_exam'])) ?>"
           class="dup-kpi-card <?= $typeFilter === 'same_day_exam' ? 'active-kpi' : '' ?>">
            <div class="dup-kpi-label">
                <span>🔵 同日・同患者・同種 (枚数違い)</span>
            </div>
            <div class="dup-kpi-val" style="color:#0369a1;">
                <?= number_format($cntDayExm) ?> <small style="font-size:0.8rem;">組</small>
            </div>
            <div class="dup-kpi-sub">途中中断＋全送信 または 負荷前後検査</div>
        </a>

        <div class="dup-kpi-card" style="background:linear-gradient(135deg,#f8fafc 0%,#eff6ff 100%);">
            <div class="dup-kpi-label">
                <span>💾 整理可能な重複容量</span>
                <span style="font-size:0.69rem;color:#64748b;">⚡ <?= number_format((float)$scanResult['elapsed_ms'], 1) ?>ms</span>
            </div>
            <div class="dup-kpi-val" style="color:#1d4ed8;">
                <?= rsnext_h($kpi['reclaimable_human']) ?>
            </div>
            <div class="dup-kpi-sub">
                重複候補 <?= number_format((int)$kpi['total_redundant_cnt']) ?> 検査 ／ 安全自動整理可能 <?= number_format((int)$kpi['auto_safe_studies']) ?> 件
            </div>
        </div>
    </section>

    <!-- 抽出条件 ＆ 検索フィルタパネル -->
    <section class="dup-toolbar">
        <div class="dup-filter-row">
            <div class="dup-pills">
                <?php
                $typeTabs = [
                    'all'            => ['label' => 'すべて (重複候補)',  'cnt' => (int)$kpi['total_active_groups']],
                    'exact_hash'     => ['label' => '🔴 完全一致',        'cnt' => $cntExact],
                    'subset_hash'    => ['label' => '🟣 包含・部分一致',  'cnt' => $cntSubset],
                    'cross_patient'  => ['label' => '🚨 別患者ID一致',    'cnt' => $cntCross],
                    'same_day_count' => ['label' => '🟠 同日・同枚数',    'cnt' => $cntDayCnt],
                    'same_day_exam'  => ['label' => '🔵 同日・枚数違い',  'cnt' => $cntDayExm],
                    'same_day_cat'   => ['label' => '⚪ 同カテゴリ参考',   'cnt' => null],
                    'ignored'        => ['label' => '✅ 除外済み',        'cnt' => $cntIgn],
                ];
                foreach ($typeTabs as $tk => $tmeta):
                ?>
                    <a href="<?= rsnext_h(dup_build_url(['type' => $tk])) ?>"
                       class="dup-pill <?= $typeFilter === $tk ? 'active' : '' ?>">
                        <span><?= rsnext_h($tmeta['label']) ?></span>
                        <?php if ($tmeta['cnt'] !== null): ?>
                            <span class="cnt"><?= (int)$tmeta['cnt'] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="dup-pills">
                <?php
                $catTabs = [
                    'all'       => '全種別',
                    'endoscopy' => '🔬 内視鏡',
                    'xray'      => '🩻 X線・透視',
                    'us'        => '🔊 超音波',
                    'ecg'       => '📈 心電図',
                    'other'     => '💿 他院CD・文書',
                ];
                foreach ($catTabs as $ck => $clabel):
                ?>
                    <a href="<?= rsnext_h(dup_build_url(['cat' => $ck])) ?>"
                       class="dup-pill <?= $catFilter === $ck ? 'active' : '' ?>">
                        <?= rsnext_h($clabel) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="get" action="duplicate_studies.php" class="dup-filter-row" style="padding-top:4px;border-top:1px dashed var(--border-light);">
            <?php if ($typeFilter !== 'all'): ?><input type="hidden" name="type" value="<?= rsnext_h($typeFilter) ?>"><?php endif; ?>
            <?php if ($catFilter !== 'all'): ?><input type="hidden" name="cat" value="<?= rsnext_h($catFilter) ?>"><?php endif; ?>

            <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;flex:1;">
                <input type="text" name="q" value="<?= rsnext_h($q) ?>" class="input" style="width:280px;height:28px;padding:2px 9px;font-size:0.81rem;" placeholder="🔍 患者ID・氏名・カナ・検査名・連番・所見...">
                <span style="font-size:0.76rem;color:var(--text-muted);font-weight:700;">検査日:</span>
                <input type="date" name="date_from" value="<?= rsnext_h($dateFrom) ?>" class="input" style="width:134px;height:28px;padding:2px 6px;font-size:0.79rem;">
                <span style="font-size:0.76rem;color:var(--text-muted);">〜</span>
                <input type="date" name="date_to" value="<?= rsnext_h($dateTo) ?>" class="input" style="width:134px;height:28px;padding:2px 6px;font-size:0.79rem;">
                <select name="limit" class="input" style="width:auto;height:28px;padding:2px 8px;font-size:0.78rem;">
                    <?php foreach ([100, 150, 300, 500] as $limOpt): ?>
                        <option value="<?= $limOpt ?>" <?= $limit === $limOpt ? 'selected' : '' ?>>表示上限 <?= $limOpt ?>組</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary" style="height:28px;">絞り込み</button>
                <?php if ($q !== '' || $dateFrom !== '' || $dateTo !== '' || $typeFilter !== 'all' || $catFilter !== 'all'): ?>
                    <a href="duplicate_studies.php" class="btn btn-sm" style="height:28px;">条件リセット</a>
                <?php endif; ?>
            </div>

            <div style="font-size:0.77rem;color:var(--text-sub);font-weight:700;">
                該当 <strong><?= number_format((int)$scanResult['total_match']) ?></strong> 組
                <?php if ((int)$scanResult['total_match'] > count($groups)): ?>
                    （先頭 <?= count($groups) ?> 組を表示中）
                <?php endif; ?>
            </div>
        </form>
    </section>

    <!-- 一括安全整理バー -->
    <?php if (!empty($groups) && $typeFilter !== 'ignored'): ?>
        <section class="bulk-bar">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <button type="button" class="btn btn-sm" style="background:#059669;border-color:#34d399;color:#fff;" onclick="selectAutoSafeOnly()">
                    ☑️ 安全な完全一致・100%包含のみ自動選択
                </button>
                <button type="button" class="btn btn-sm" style="background:rgba(255,255,255,0.12);border-color:rgba(255,255,255,0.28);color:#f8fafc;" onclick="selectAllRecommendedRedundant()">
                    ☑️ 表示中の整理候補をすべて選択
                </button>
                <button type="button" class="btn btn-sm" style="background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.2);color:#cbd5e1;" onclick="clearAllSelections()">
                    ⬜ 選択解除
                </button>
                <span style="font-size:0.76rem;color:#94a3b8;margin-left:4px;">
                    ※整理操作はすべて<strong style="color:#fde68a;">ゴミ箱への論理移動</strong>（各グループ最低1件を自動保護・後からいつでも復元可）です
                </span>
            </div>

            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span id="selectedSummaryLabel" style="font-size:0.82rem;font-weight:800;color:#93c5fd;">
                    選択中: 0 件 (0 B)
                </span>
                <button type="button" id="btnBulkTrash" class="btn btn-sm"
                        style="background:#dc2626;border-color:#f87171;color:#ffffff;padding:5px 14px;"
                        onclick="executeBulkTrash()" disabled>
                    🗑️ チェックした重複検査をゴミ箱へ一括移動
                </button>
            </div>
        </section>
    <?php endif; ?>

    <!-- 重複検査グループ一覧 -->
    <?php if (empty($groups)): ?>
        <div class="rs-card" style="text-align:center;padding:38px 20px;">
            <div style="font-size:2.2rem;margin-bottom:6px;">✨</div>
            <h3 style="font-size:1.05rem;font-weight:800;color:#0f172a;margin-bottom:4px;">
                現在の条件に該当する重複検査候補はありません
            </h3>
            <p style="font-size:0.83rem;color:var(--text-muted);">
                <?php if ($typeFilter !== 'all' || $catFilter !== 'all' || $q !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                    フィルタ条件を変更するか、<a href="duplicate_studies.php">すべての重複候補を表示</a>してください。
                <?php else: ?>
                    SHA-256ハッシュ・DICOM検査UID・同日同種検査のスキャン結果、重複は検出されませんでした。
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <?php foreach ($groups as $gIdx => $g):
            $gSig     = (string)$g['group_sig'];
            $gType    = (string)$g['type'];
            $tMeta    = $g['type_meta'];
            $isCross  = ($gType === 'cross_patient');
            $isSafe   = !empty($g['auto_clean_safe']);
            $hasConf  = !empty($g['has_findings_conflict']);
            $allSids  = array_map(fn($x) => (int)$x['study_id'], $g['studies']);
            $firstSt  = $g['studies'][0];
            $pId0     = (string)$firstSt['patient_id'];
            $pKanji0  = (string)($firstSt['kanja_kanji'] ?: "ID:{$pId0}");
            $pYomi0   = (string)($firstSt['kanja_yomi'] ?? '');
            $pAge0    = rsnext_calc_age((string)($firstSt['birth_date'] ?? ''), (string)($firstSt['study_date'] ?? ''));
            $pSex0    = (string)($firstSt['sex'] ?? '');
            $karteUrl0 = rsnext_build_karte_url($pId0, $karteTpl);
            $tdSec    = $g['time_diff_sec'];
            $tdLabel  = (string)$g['time_diff_label'];
        ?>
        <article class="dup-group-card" id="groupCard_<?= rsnext_h($gSig) ?>"
                 data-group-sig="<?= rsnext_h($gSig) ?>"
                 data-group-type="<?= rsnext_h($gType) ?>"
                 data-auto-safe="<?= $isSafe ? '1' : '0' ?>"
                 data-all-sids="<?= rsnext_h(json_encode($allSids)) ?>">

            <!-- グループヘッダー -->
            <div class="dup-group-head" style="background:<?= rsnext_h($tMeta['bg']) ?>;border-bottom-color:<?= rsnext_h($tMeta['bd']) ?>;">
                <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                    <span class="dup-type-badge" style="background:<?= rsnext_h($tMeta['color']) ?>;">
                        <?= rsnext_h($tMeta['label']) ?>
                    </span>

                    <?php foreach ($g['reasons'] as $rsn): ?>
                        <span class="reason-chip"><?= rsnext_h($rsn) ?></span>
                    <?php endforeach; ?>

                    <?php if ($tdLabel !== ''): ?>
                        <?php
                        $isCloseTime = ($tdSec !== null && $tdSec <= 180);
                        $tdBg = $isCloseTime ? '#fef3c7' : '#e2e8f0';
                        $tdCol = $isCloseTime ? '#92400e' : '#334155';
                        ?>
                        <span class="timediff-chip" style="background:<?= $tdBg ?>;color:<?= $tdCol ?>;">
                            ⏱️ <?= rsnext_h($tdLabel) ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($hasConf && !$isCross): ?>
                        <span class="timediff-chip" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;">
                            ⚠️ 複数検査に所見あり（所見統合ボタン推奨）
                        </span>
                    <?php elseif ($isSafe): ?>
                        <span class="timediff-chip" style="background:#dcfce7;color:#166534;">
                            🛡️ 安全整理可能
                        </span>
                    <?php endif; ?>

                    <span style="margin-left:4px;font-size:0.84rem;font-weight:800;color:#0f172a;">
                        <?php if (!$isCross): ?>
                            👤 <?= rsnext_h($pKanji0) ?>
                            <?php if ($pYomi0 !== ''): ?><small style="color:#475569;font-weight:600;">(<?= rsnext_h($pYomi0) ?>)</small><?php endif; ?>
                            <a href="<?= rsnext_h($karteUrl0) ?>" target="_blank" style="margin-left:4px;font-family:Consolas,monospace;background:#eff6ff;border:1px solid #93c5fd;color:#1d4ed8;padding:1px 6px;border-radius:4px;text-decoration:none;font-size:0.78rem;" title="電子カルテを開く">ID:<?= rsnext_h($pId0) ?> 📋</a>
                            <a href="index.php?patient_id=<?= rawurlencode($pId0) ?>&all_dates=1" target="_blank" style="margin-left:2px;font-size:0.75rem;color:#0f766e;text-decoration:underline;">全検査履歴↗</a>
                            <?php if ($pAge0 !== '' || $pSex0 !== ''): ?>
                                <small style="color:#475569;margin-left:4px;"><?= rsnext_h($pAge0 !== '' ? "{$pAge0}歳" : '') ?> <?= rsnext_h($pSex0) ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#b91c1c;">⚠️ 異なる患者ID間で同一データが検出されました（各カードのカルテ・患者名を確認してください）</span>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- グループ単位アクション -->
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <?php if (!$isCross && empty($g['is_ignored'])): ?>
                        <button type="button" class="btn btn-sm btn-teal"
                                onclick="mergeAndTrashGroup('<?= rsnext_h($gSig) ?>')"
                                title="「残す」に選んだ検査へ他の重複検査の所見・担当医を統合し、重複側をゴミ箱へ移動します">
                            📝 所見を統合して重複側をゴミ箱へ
                        </button>
                    <?php endif; ?>

                    <?php if (!empty($g['is_ignored'])): ?>
                        <button type="button" class="btn btn-sm"
                                onclick="toggleIgnoreGroup('<?= rsnext_h($gSig) ?>', false)">
                            ↩️ 除外を解除して候補に戻す
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm"
                                onclick="toggleIgnoreGroup('<?= rsnext_h($gSig) ?>', true)"
                                title="負荷前後や経過再検など、意図的な同日複数検査である場合に一覧から除外します">
                            ✅ 同日別検査として除外
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 検査カード横並び比較グリッド -->
            <div class="dup-studies-grid">
                <?php foreach ($g['studies'] as $sIdx => $st):
                    $sid       = (int)$st['study_id'];
                    $renban    = $st['legacy_renban'] ? (int)$st['legacy_renban'] : null;
                    $dispNo    = $renban ?: $sid;
                    $pid       = (string)$st['patient_id'];
                    $kanji     = (string)($st['kanja_kanji'] ?: "ID:{$pid}");
                    $yomi      = (string)($st['kanja_yomi'] ?? '');
                    $karteUrl  = rsnext_build_karte_url($pid, $karteTpl);
                    $kensamei  = (string)$st['kensamei'];
                    $dispKensa = rsnext_friendly_kensamei($kensamei);
                    $modUpper  = strtoupper((string)($st['modality'] ?? ''));
                    $fType     = (string)$st['primary_file_type'];
                    $fCount    = (int)($st['actual_file_count'] ?? $st['file_count'] ?? 0);
                    $fBytes    = (int)($st['actual_bytes'] ?? $st['total_bytes'] ?? 0);
                    $bColor    = (string)($st['badge_color'] ?: ($st['prof_color'] ?: '#2563eb'));
                    $dateSlash = str_replace('-', '/', (string)$st['study_date']);
                    $timeStr   = $st['study_time'] ? substr((string)$st['study_time'], 0, 8) : '--:--:--';
                    $createdAt = substr(str_replace('T', ' ', (string)($st['created_at'] ?? '')), 0, 16);
                    $doctor    = trim((string)($st['doctor'] ?? ''));
                    $dept      = trim((string)($st['department'] ?? ''));
                    $findings  = trim((string)($st['findings'] ?? ''));
                    $comment   = trim((string)($st['comment'] ?? ''));
                    $uidStr    = (string)($st['study_instance_uid'] ?? '');
                    $uidShort  = strlen($uidStr) > 28 ? ('...' . substr($uidStr, -24)) : ($uidStr ?: '—');
                    $thumbs    = $st['sample_thumbs'] ?? ['0001.dcm'];
                    $isKeep    = !empty($st['is_recommended_keep']);
                    $isTomo    = rsnext_is_tomographic_study($modUpper, $kensamei, $fType);
                    $viewerUrl = $fType === 'ECG' ? ("ecg_view.php?study_id={$sid}") : ("dicom_view.php?study_id={$sid}");
                    $autoChk   = (!$isCross && !$isKeep && $isSafe && empty($g['is_ignored']));
                ?>
                <div class="dup-study-item <?= $isCross ? 'is-cross' : ($isKeep ? 'is-keep' : ($autoChk ? 'is-trash-checked' : '')) ?>"
                     id="studyItem_<?= rsnext_h($gSig) ?>_<?= $sid ?>"
                     data-sid="<?= $sid ?>"
                     data-bytes="<?= $fBytes ?>">

                    <div>
                        <!-- 上段ロール選択バー -->
                        <div class="role-bar">
                            <?php if (!$isCross && empty($g['is_ignored'])): ?>
                                <label class="role-label-keep" title="この検査を正本として残す場合はこちらを選択">
                                    <input type="radio"
                                           name="keep_<?= rsnext_h($gSig) ?>"
                                           value="<?= $sid ?>"
                                           <?= $isKeep ? 'checked' : '' ?>
                                           onchange="onKeepRadioChange('<?= rsnext_h($gSig) ?>', <?= $sid ?>)">
                                    <span class="keep-badge-txt">
                                        <?= $isKeep ? '🌟 残す候補 (推奨)' : 'この検査を残す' ?>
                                    </span>
                                </label>

                                <label class="role-label-trash" id="trashWrap_<?= rsnext_h($gSig) ?>_<?= $sid ?>"
                                       style="<?= $isKeep ? 'display:none;' : '' ?>">
                                    <input type="checkbox"
                                           class="dup-trash-chk"
                                           data-group-sig="<?= rsnext_h($gSig) ?>"
                                           data-sid="<?= $sid ?>"
                                           data-bytes="<?= $fBytes ?>"
                                           data-auto-safe="<?= $isSafe ? '1' : '0' ?>"
                                           <?= $autoChk ? 'checked' : '' ?>
                                           onchange="onTrashCheckChange(this)">
                                    <span>🗑️ 整理対象</span>
                                </label>
                            <?php elseif ($isCross): ?>
                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                    <span style="font-size:0.82rem;font-weight:800;color:#92400e;">
                                        👤 <?= rsnext_h($kanji) ?>
                                    </span>
                                    <a href="<?= rsnext_h($karteUrl) ?>" target="_blank"
                                       style="font-family:Consolas,monospace;font-weight:800;background:#ffffff;border:1px solid #f59e0b;color:#1d4ed8;padding:1px 7px;border-radius:4px;text-decoration:none;font-size:0.79rem;">
                                        ID:<?= rsnext_h($pid) ?> 📋カルテ確認
                                    </a>
                                </div>
                                <span class="src-chip <?= $renban ? 'src-legacy' : 'src-live' ?>">
                                    <?= $renban ? "📦 旧RS-Base #{$renban}" : "📡 RS-Next #{$sid}" ?>
                                </span>
                            <?php else: ?>
                                <span style="font-size:0.78rem;font-weight:800;color:#475569;">検査 #<?= $dispNo ?></span>
                            <?php endif; ?>

                            <?php if (!$isCross): ?>
                                <span class="src-chip <?= $renban ? 'src-legacy' : 'src-live' ?>">
                                    <?= $renban ? "📦 旧RS-Base #{$renban} (ID:{$sid})" : "📡 RS-Next着信 (ID:{$sid})" ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- サムネイル横並びプレビュー (先頭・中間・末尾) -->
                        <div class="thumb-strip" style="margin:7px 0;">
                            <?php if (in_array($fType, ['DICOM', 'IMAGE', 'VIDEO'], true) && $fCount > 0): ?>
                                <?php
                                $tagLabels = count($thumbs) === 1
                                    ? ['1枚目']
                                    : (count($thumbs) === 2 ? ['先頭', '末尾'] : ['先頭', '中盤', '末尾']);
                                foreach ($thumbs as $tIdx => $tFn):
                                    $tTag = $tagLabels[$tIdx] ?? ('#' . ($tIdx + 1));
                                ?>
                                    <div class="dup-thumb"
                                         onclick="openDupStudyViewer(<?= $sid ?>, '<?= rsnext_h($fType) ?>', <?= $isTomo ? 'true' : 'false' ?>)"
                                         title="クリックでビューワ起動（ホバーで拡大）">
                                        <img loading="lazy" src="dcm_api.php?study_id=<?= $sid ?>&file=<?= rawurlencode($tFn) ?>&thumb=1" alt="thumb">
                                        <span class="dup-thumb-tag"><?= rsnext_h($tTag) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php elseif ($fType === 'ECG'): ?>
                                <div class="dup-thumb" style="width:140px;background:#fff1f2;border-color:#fda4af;color:#be123c;font-weight:800;font-size:0.78rem;"
                                     onclick="window.open('ecg_view.php?study_id=<?= $sid ?>', '_blank')">
                                    📈 12誘導波形を開く
                                </div>
                            <?php elseif ($fType === 'PDF'): ?>
                                <div class="dup-thumb" style="width:130px;background:#f1f5f9;color:#334155;font-weight:800;font-size:0.78rem;"
                                     onclick="window.open('dicom_view.php?study_id=<?= $sid ?>', '_blank')">
                                    📄 PDF文書を開く
                                </div>
                            <?php else: ?>
                                <div style="padding:8px 10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;color:#b91c1c;font-size:0.76rem;font-weight:800;">
                                    ⚠️ 実ファイル 0 枚（空レコード）
                                </div>
                            <?php endif; ?>

                            <div style="margin-left:auto;text-align:right;">
                                <div>
                                    <span style="display:inline-block;background:<?= rsnext_h($bColor) ?>;color:#fff;font-size:0.75rem;font-weight:800;padding:2px 8px;border-radius:5px;">
                                        <?= rsnext_h($dispKensa) ?>
                                    </span>
                                </div>
                                <div style="font-size:0.8rem;font-weight:800;color:#0f172a;margin-top:3px;font-family:Consolas,monospace;">
                                    <?= number_format($fCount) ?> <?= $fType === 'ECG' ? '波形' : '枚' ?>
                                    <span style="color:#64748b;font-weight:600;">(<?= rsnext_h(rsnext_dup_fmt_bytes($fBytes)) ?>)</span>
                                </div>
                                <div style="font-size:0.69rem;color:#64748b;font-weight:700;">形式: <?= rsnext_h($fType) ?> / <?= rsnext_h($modUpper) ?></div>
                            </div>
                        </div>

                        <!-- 詳細メタデータ比較グリッド -->
                        <div class="meta-kv-grid">
                            <div class="meta-k">検査日時</div>
                            <div class="meta-v" style="font-family:Consolas,monospace;">
                                <strong><?= rsnext_h($dateSlash) ?></strong>
                                <span style="color:#0369a1;font-weight:800;margin-left:4px;"><?= rsnext_h($timeStr) ?></span>
                            </div>

                            <div class="meta-k">DB登録日時</div>
                            <div class="meta-v" style="font-family:Consolas,monospace;font-size:0.74rem;color:#475569;">
                                <?= rsnext_h($createdAt ?: '—') ?>
                                <?php if (!empty($st['station_name']) || !empty($st['model_name'])): ?>
                                    <span style="color:#64748b;">[<?= rsnext_h(trim($st['station_name'] . ' ' . $st['model_name'])) ?>]</span>
                                <?php endif; ?>
                            </div>

                            <div class="meta-k">検査UID</div>
                            <div class="meta-v" style="font-family:Consolas,monospace;font-size:0.72rem;color:#64748b;" title="<?= rsnext_h($uidStr) ?>">
                                <?= rsnext_h($uidShort) ?>
                            </div>

                            <?php if (!empty($st['first_orig_filename'])): ?>
                                <div class="meta-k">元ファイル</div>
                                <div class="meta-v" style="font-family:Consolas,monospace;font-size:0.73rem;color:#334155;">
                                    <?= rsnext_h((string)$st['first_orig_filename']) ?><?= $fCount > 1 ? ' ほか' : '' ?>
                                </div>
                            <?php endif; ?>

                            <div class="meta-k">担当医/科</div>
                            <div class="meta-v">
                                <?= ($doctor !== '' || $dept !== '') ? rsnext_h(trim("{$doctor} {$dept}")) : '<span class="findings-empty">未記載</span>' ?>
                            </div>
                        </div>

                        <!-- 所見・コメント表示ボックス -->
                        <div style="margin-top:6px;">
                            <?php if ($findings !== '' || $comment !== ''): ?>
                                <div class="findings-box"><?php
                                    if ($findings !== '') echo rsnext_h($findings);
                                    if ($comment !== '') echo ($findings !== '' ? "\n" : '') . '【メモ】' . rsnext_h($comment);
                                ?></div>
                            <?php else: ?>
                                <div class="findings-box findings-empty" style="background:#f8fafc;border-style:dashed;">所見・コメント記載なし</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- カード下部アクションボタン -->
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:5px;flex-wrap:wrap;padding-top:6px;border-top:1px solid #e2e8f0;">
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <a href="<?= rsnext_h($viewerUrl) ?>" target="_blank" class="btn btn-sm" title="別タブのWebビューワで全画像を確認">
                                🖥️ Web表示↗
                            </a>
                            <?php if ($isTomo): ?>
                                <button type="button" class="btn btn-sm" style="background:#e0f2fe;border-color:#7dd3fc;color:#0369a1;"
                                        onclick="launchLocalRsViewer(<?= $sid ?>, '', 'HTTP_DICOM', '🌀 ローカル RSViewer を起動しています...')">
                                    🌀 RSViewer
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm" onclick="openStudyEditModal(<?= $sid ?>, <?= $isCross ? "'reassign'" : "'meta'" ?>)">
                                <?= $isCross ? '🔄 ID付替・編集' : '✏️ 編集' ?>
                            </button>
                        </div>

                        <button type="button" class="btn btn-sm btn-danger"
                                onclick="trashSingleStudy(<?= $sid ?>, '#<?= $dispNo ?> (<?= rsnext_h(addslashes($dispKensa)) ?>)')">
                            🗑️ ゴミ箱へ移動
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </article>
        <?php endforeach; ?>
    <?php endif; ?>

</main>

<!-- 検査編集・誤患者ID付け替えモーダル -->
<div class="modal-backdrop" id="studyEditModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="editModalTitle">✏️ 検査情報編集・患者ID付け替え</h3>
            <button type="button" class="btn btn-sm" onclick="closeStudyEditModal()">✕ 閉じる</button>
        </div>
        <div class="modal-body" style="padding:16px 20px;">
            <div class="edit-tabs">
                <button type="button" class="edit-tab active" id="tabBtnMeta" onclick="switchEditTab('meta')">📝 所見・検査情報編集</button>
                <button type="button" class="edit-tab" id="tabBtnReassign" onclick="switchEditTab('reassign')">🔄 誤患者IDの付け替え</button>
            </div>

            <div id="editPaneMeta">
                <input type="hidden" id="editStudyId">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div>
                        <label class="form-label">検査種別名</label>
                        <input type="text" id="editKensamei" class="input">
                    </div>
                    <div>
                        <label class="form-label">施行医 / 担当医</label>
                        <input type="text" id="editDoctor" class="input">
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="form-label">診断・所見テキスト</label>
                    <textarea id="editFindings" class="input" rows="4"></textarea>
                </div>
                <div>
                    <label class="form-label">内部コメント・検査メモ</label>
                    <input type="text" id="editComment" class="input">
                </div>
            </div>

            <div id="editPaneReassign" style="display:none;">
                <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:12px;margin-bottom:14px;font-size:0.84rem;color:#78350f;">
                    <strong>⚠️ 装置での患者ID入力ミス救済:</strong><br>
                    現在の患者ID <strong id="currentPidLabel">-</strong> から正しい患者IDへ検査データを移動します。
                </div>
                <div style="margin-bottom:12px;">
                    <label class="form-label">移動先の正しい患者ID または 患者名検索</label>
                    <input type="text" id="reassignQuery" class="input" placeholder="患者ID または 氏名を入力..." oninput="searchTargetPatient()">
                </div>
                <div id="reassignCandidates" style="max-height:170px;overflow-y:auto;border:1px solid var(--border-light);border-radius:8px;margin-bottom:12px;padding:6px;">
                    <div style="font-size:0.8rem;color:var(--text-muted);padding:6px;">候補がここに表示されます</div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label class="form-label">確定する移動先 患者ID</label>
                        <input type="text" id="reassignTargetPid" class="input" placeholder="数値ID">
                    </div>
                    <div>
                        <label class="form-label">患者氏名（未登録新患の場合のみ）</label>
                        <input type="text" id="reassignTargetName" class="input" placeholder="マスタ登録済なら空欄でOK">
                    </div>
                </div>
                <button type="button" class="btn btn-primary" onclick="executePatientReassign()">🔄 この患者IDへ付け替えを実行</button>
            </div>
        </div>
        <div class="modal-foot" style="padding:12px 20px;border-top:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center;">
            <div id="trashBtnBox"></div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn" onclick="closeStudyEditModal()">キャンセル</button>
                <button type="button" class="btn btn-primary" id="saveMetaBtn" onclick="saveStudyMetadata()">💾 保存する</button>
            </div>
        </div>
    </div>
</div>

<!-- RSViewer 起動ステータス＆ワンクリック緊急リセットドック -->
<div id="rsvRecoveryDock" style="display:none;position:fixed;right:16px;bottom:16px;z-index:2600;background:#0f172a;color:#f8fafc;border:1.5px solid #38bdf8;border-radius:12px;padding:11px 14px;box-shadow:0 10px 28px rgba(0,0,0,0.45);max-width:390px;font-size:0.81rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px;">
        <strong id="rsvDockStatus" style="color:#7dd3fc;">🌀 RSViewer を起動しています...</strong>
        <button type="button" onclick="hideRsvDock()" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:0.95rem;">✕</button>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <button type="button" class="btn btn-sm" style="background:#f59e0b;border-color:#fbbf24;color:#0f172a;font-weight:800;" onclick="triggerViewerEmergencyReset(true)">
            🔄 リセットして強制再起動
        </button>
        <button type="button" id="rsvDockWebBtn" class="btn btn-sm" style="background:#1e293b;border-color:#475569;color:#e2e8f0;" onclick="openFallbackWebViewer()">
            🌐 Webビューワで開く
        </button>
    </div>
</div>

<div class="toast-pop" id="toastPop"></div>

<script>
function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

function fmtBytesJs(bytes) {
    const b = Number(bytes || 0);
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024) return Math.round(b / 1024) + ' KB';
    return b + ' B';
}

function showToast(msg) {
    const el = document.getElementById('toastPop');
    if (!el) return;
    el.textContent = msg;
    el.style.display = 'block';
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => { el.style.display = 'none'; }, 3200);
}

// -----------------------------------------------------------------------------
// 残す検査ラジオ切替 ＆ 一括チェック集計
// -----------------------------------------------------------------------------
function onKeepRadioChange(groupSig, newKeepSid) {
    const card = document.getElementById('groupCard_' + groupSig);
    if (!card) return;
    const isSafe = card.dataset.autoSafe === '1';

    card.querySelectorAll('.dup-study-item').forEach(item => {
        const sid = Number(item.dataset.sid);
        const isKeep = (sid === Number(newKeepSid));
        const trashWrap = document.getElementById(`trashWrap_${groupSig}_${sid}`);
        const chk = item.querySelector('.dup-trash-chk');
        const txt = item.querySelector('.keep-badge-txt');

        if (isKeep) {
            item.classList.add('is-keep');
            item.classList.remove('is-trash-checked');
            if (txt) txt.textContent = '🌟 この検査を残す (選択中)';
            if (trashWrap) trashWrap.style.display = 'none';
            if (chk) chk.checked = false;
        } else {
            item.classList.remove('is-keep');
            if (txt) txt.textContent = 'この検査を残す';
            if (trashWrap) trashWrap.style.display = '';
            if (chk && isSafe) {
                chk.checked = true;
                item.classList.add('is-trash-checked');
            }
        }
    });
    updateBulkSummary();
}

function onTrashCheckChange(chkEl) {
    const groupSig = chkEl.dataset.groupSig;
    const sid = chkEl.dataset.sid;
    const item = document.getElementById(`studyItem_${groupSig}_${sid}`);
    if (item) {
        item.classList.toggle('is-trash-checked', chkEl.checked);
    }
    updateBulkSummary();
}

function updateBulkSummary() {
    const chks = Array.from(document.querySelectorAll('.dup-trash-chk:checked'));
    let totalBytes = 0;
    chks.forEach(c => { totalBytes += Number(c.dataset.bytes || 0); });
    const lbl = document.getElementById('selectedSummaryLabel');
    const btn = document.getElementById('btnBulkTrash');
    if (lbl) {
        lbl.textContent = `選択中: ${chks.length} 件 (${fmtBytesJs(totalBytes)})`;
    }
    if (btn) {
        btn.disabled = chks.length === 0;
    }
}

function selectAutoSafeOnly() {
    document.querySelectorAll('.dup-trash-chk').forEach(chk => {
        const groupSig = chk.dataset.groupSig;
        const sid = Number(chk.dataset.sid);
        const keepRadio = document.querySelector(`input[name="keep_${groupSig}"]:checked`);
        const keepSid = keepRadio ? Number(keepRadio.value) : 0;
        const shouldCheck = (chk.dataset.autoSafe === '1') && (sid !== keepSid);
        chk.checked = shouldCheck;
        const item = document.getElementById(`studyItem_${groupSig}_${sid}`);
        if (item) item.classList.toggle('is-trash-checked', shouldCheck);
    });
    updateBulkSummary();
}

function selectAllRecommendedRedundant() {
    document.querySelectorAll('.dup-trash-chk').forEach(chk => {
        const groupSig = chk.dataset.groupSig;
        const sid = Number(chk.dataset.sid);
        const keepRadio = document.querySelector(`input[name="keep_${groupSig}"]:checked`);
        const keepSid = keepRadio ? Number(keepRadio.value) : 0;
        const shouldCheck = (keepSid > 0 && sid !== keepSid);
        chk.checked = shouldCheck;
        const item = document.getElementById(`studyItem_${groupSig}_${sid}`);
        if (item) item.classList.toggle('is-trash-checked', shouldCheck);
    });
    updateBulkSummary();
}

function clearAllSelections() {
    document.querySelectorAll('.dup-trash-chk').forEach(chk => {
        chk.checked = false;
        const item = document.getElementById(`studyItem_${chk.dataset.groupSig}_${chk.dataset.sid}`);
        if (item) item.classList.remove('is-trash-checked');
    });
    updateBulkSummary();
}

document.addEventListener('DOMContentLoaded', updateBulkSummary);

// -----------------------------------------------------------------------------
// 重複整理アクション (一括ゴミ箱移動 / 所見統合ゴミ箱移動 / 単体ゴミ箱移動 / 除外)
// -----------------------------------------------------------------------------
async function executeBulkTrash() {
    const chks = Array.from(document.querySelectorAll('.dup-trash-chk:checked'));
    if (chks.length === 0) return;

    const items = [];
    for (const chk of chks) {
        const groupSig = chk.dataset.groupSig;
        const trashId = Number(chk.dataset.sid);
        const keepRadio = document.querySelector(`input[name="keep_${groupSig}"]:checked`);
        const keepId = keepRadio ? Number(keepRadio.value) : 0;
        if (keepId > 0 && trashId > 0 && trashId !== keepId) {
            items.push({ trash_id: trashId, keep_id: keepId });
        }
    }

    if (items.length === 0) {
        alert('各グループで「残す検査」が選択されていることを確認してください。');
        return;
    }

    if (!confirm(`選択した重複検査 ${items.length} 件をゴミ箱へ一括移動しますか？\n（※各グループの「残す検査」は確実に保護され、移動した検査も「♻️ ゴミ箱」からいつでも復元できます）`)) {
        return;
    }

    const res = await fetch('duplicate_studies.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'bulk_trash', items }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast(`🗑️ ${out.trashed_count} 件の重複検査をゴミ箱へ移動しました (${out.freed_human})`);
        setTimeout(() => location.reload(), 550);
    } else {
        alert(out.message || '一括整理中にエラーが発生しました');
    }
}

async function mergeAndTrashGroup(groupSig) {
    const card = document.getElementById('groupCard_' + groupSig);
    if (!card) return;
    const keepRadio = document.querySelector(`input[name="keep_${groupSig}"]:checked`);
    if (!keepRadio) {
        alert('残す側の検査を選択してください。');
        return;
    }
    const keepSid = Number(keepRadio.value);
    const allSids = JSON.parse(card.dataset.allSids || '[]').map(Number);
    const trashSids = allSids.filter(id => id !== keepSid);
    if (trashSids.length === 0) return;

    if (!confirm(`検査 ID:${keepSid} を正本として残し、重複側 (${trashSids.length}件) の所見・コメント・担当医を自動統合した上でゴミ箱へ移動します。\nよろしいですか？`)) {
        return;
    }

    const res = await fetch('duplicate_studies.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'merge_and_trash',
            keep_study_id: keepSid,
            trash_study_ids: trashSids,
        }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast(`✅ 検査 ID:${keepSid} へ所見を統合し、${out.trashed_count} 件をゴミ箱へ移動しました`);
        card.style.opacity = '0.35';
        setTimeout(() => location.reload(), 500);
    } else {
        alert(out.message || '統合処理に失敗しました');
    }
}

async function trashSingleStudy(studyId, labelStr) {
    if (!confirm(`検査 ${labelStr} をゴミ箱へ移動しますか？\n（※後から「♻️ ゴミ箱」画面でいつでも復元できます）`)) {
        return;
    }
    const res = await fetch('study_edit_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'soft_delete', study_id: studyId }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast(`🗑️ ${labelStr} をゴミ箱へ移動しました`);
        setTimeout(() => location.reload(), 450);
    } else {
        alert(out.message || 'ゴミ箱移動に失敗しました');
    }
}

async function toggleIgnoreGroup(groupSig, ignore) {
    const card = document.getElementById('groupCard_' + groupSig);
    if (!card) return;
    const allSids = JSON.parse(card.dataset.allSids || '[]').map(Number);

    const res = await fetch('duplicate_studies.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: ignore ? 'ignore_group' : 'unignore_group',
            study_ids: allSids,
            note: ignore ? '同日別検査として確認・除外' : '',
        }),
    });
    const out = await res.json();
    if (out.status === 'success') {
        showToast(ignore ? '✅ 同日別検査（重複ではない）として除外しました' : '↩️ 除外を解除しました');
        card.style.display = 'none';
        setTimeout(() => location.reload(), 400);
    } else {
        alert(out.message || '操作に失敗しました');
    }
}

// -----------------------------------------------------------------------------
// ビューワ起動 ＆ RSViewer 緊急リセット連携
// -----------------------------------------------------------------------------
function openDupStudyViewer(studyId, fileType, isTomo) {
    if (isTomo && (fileType === 'DICOM' || fileType === 'IMAGE')) {
        launchLocalRsViewer(studyId, '', 'HTTP_DICOM', '🌀 断層データ (CT/MRI) をローカル RSViewer で起動しています...');
        return;
    }
    const url = fileType === 'ECG' ? `ecg_view.php?study_id=${studyId}` : `dicom_view.php?study_id=${studyId}`;
    window.open(url, '_blank');
}

let lastRsvContext = { studyId: 0, bundleUrl: '', cacheKey: '', timer: null, token: 0 };

function hideRsvDock() {
    const dock = document.getElementById('rsvRecoveryDock');
    if (dock) dock.style.display = 'none';
}

function openFallbackWebViewer() {
    if (lastRsvContext.studyId > 0) {
        window.open(`dicom_view.php?study_id=${lastRsvContext.studyId}`, '_blank');
    }
    hideRsvDock();
}

function triggerViewerEmergencyReset(relaunchCurrent = false) {
    let protoUrl = 'rsnext-reset://reset';
    if (relaunchCurrent && lastRsvContext.bundleUrl && lastRsvContext.cacheKey) {
        protoUrl += '?b=' + encodeURIComponent(lastRsvContext.bundleUrl) + '&k=' + encodeURIComponent(lastRsvContext.cacheKey);
    }
    const stEl = document.getElementById('rsvDockStatus');
    if (stEl) {
        stEl.style.color = '#fde68a';
        stEl.textContent = '🔄 強制リセット＆常駐再起動を実行中...';
    }
    showToast('🔄 ローカル RSViewer と常駐 watcher のリセット信号を送出しました');
    const a = document.createElement('a');
    a.href = protoUrl;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => a.remove(), 800);
}

function launchLocalRsViewer(studyId, folderPath, launchMode = 'HTTP_DICOM', toastMsg = '') {
    const myToken = ++lastRsvContext.token;
    lastRsvContext.studyId = studyId || 0;
    clearTimeout(lastRsvContext.timer);

    const dock = document.getElementById('rsvRecoveryDock');
    const stEl = document.getElementById('rsvDockStatus');
    if (dock && stEl) {
        dock.style.borderColor = '#38bdf8';
        stEl.style.color = '#7dd3fc';
        stEl.textContent = toastMsg || '🌀 RSViewer を起動しています...';
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
                if (stEl) { stEl.textContent = '⚠️ エラー: ' + (d.message || '不明'); stEl.style.color = '#fca5a5'; }
                return;
            }
            lastRsvContext.bundleUrl = d.bundle_url || '';
            lastRsvContext.cacheKey  = d.cache_key || '';
            for (let i = 0; i < 5; i++) {
                await new Promise(res => setTimeout(res, 700));
                if (myToken !== lastRsvContext.token) return;
                try {
                    const chk = await (await fetch(`index.php?action=check_launch&t=${Date.now()}`)).json();
                    if (chk.consumed) {
                        if (stEl) { stEl.textContent = '✅ 起動完了（出ない時は「リセットして強制再起動」）'; stEl.style.color = '#6ee7b7'; }
                        lastRsvContext.timer = setTimeout(() => { if (myToken === lastRsvContext.token) hideRsvDock(); }, 6000);
                        return;
                    }
                } catch (_) {}
            }
            fetch(`index.php?action=check_launch&cleanup=1&t=${Date.now()}`).catch(() => {});
            if (dock && stEl) {
                dock.style.borderColor = '#f59e0b';
                stEl.style.color = '#fcd34d';
                stEl.textContent = '⚠️ 常駐無応答 — 「リセットして強制再起動」を押してください';
            }
        });
}

// -----------------------------------------------------------------------------
// 所見編集・誤患者ID付け替えモーダル
// -----------------------------------------------------------------------------
function switchEditTab(tab) {
    document.getElementById('tabBtnMeta').classList.toggle('active', tab === 'meta');
    document.getElementById('tabBtnReassign').classList.toggle('active', tab === 'reassign');
    document.getElementById('editPaneMeta').style.display = tab === 'meta' ? '' : 'none';
    document.getElementById('editPaneReassign').style.display = tab === 'reassign' ? '' : 'none';
    document.getElementById('saveMetaBtn').style.display = tab === 'meta' ? '' : 'none';
}

async function openStudyEditModal(studyId, initialTab = 'meta') {
    switchEditTab(initialTab);
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
    document.getElementById('reassignQuery').value = '';
    document.getElementById('reassignTargetPid').value = '';
    document.getElementById('reassignTargetName').value = '';
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
             data-pid="${esc(p.patient_id)}" data-kanji="${esc(p.kanja_kanji)}"
             onclick="document.getElementById('reassignTargetPid').value=this.dataset.pid;document.getElementById('reassignTargetName').value=this.dataset.kanji;">
            <div><strong>ID: ${esc(p.patient_id)}</strong> — ${esc(p.kanja_kanji)} <small style="color:#64748b;">(${esc(p.kanja_yomi)}) ${p.age ? esc(p.age)+'歳' : ''} ${esc(p.sex)}</small></div>
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
</script>
</body>
</html>
