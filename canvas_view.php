<?php
declare(strict_types=1);
// ==============================================================================
// RS-Next 検査タイムライン・インタラクティブキャンバス (canvas_view.php)
// - 電子カルテ連携パラメータ完全互換・UTF-8 PostgreSQL (rsnext_db) 対応
// ==============================================================================

require_once __DIR__ . '/lib/bootstrap.php';

@header('X-Frame-Options: ALLOWALL');
@header('Content-Security-Policy: frame-ancestors *');

$tmp_dir = __DIR__ . '/tmp/';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'launch') {
    header('Content-Type: application/json; charset=UTF-8');
    $studyId = (int)($_POST['study_id'] ?? 0);
    $folder  = trim((string)($_POST['folder'] ?? ''));
    $mode    = strtoupper(trim((string)($_POST['launch_mode'] ?? 'HTTP_DICOM')));
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if ($client_ip === '::1') $client_ip = '127.0.0.1';

    if ($mode === 'HTTP_DICOM' || $mode === 'NORMAL') {
        if ($studyId <= 0 && $folder === '') {
            echo json_encode(['status' => 'error', 'message' => '検査IDまたはフォルダパスが空です'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $triggerLine = rsnext_build_http_dicom_trigger_line($studyId, $folder);
    } else {
        if ($folder === '') {
            echo json_encode(['status' => 'error', 'message' => 'フォルダパスが空です'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $triggerLine = $mode . '|' . $folder;
    }

    $ok = rsnext_write_client_trigger('trigger', $client_ip, $triggerLine, __DIR__ . '/tmp');
    echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => 'RSViewer を起動しています（共有フォルダ不要）'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw_patient_id = (string)($_GET['patient_no'] ?? $_GET['patient_id'] ?? $_GET['id'] ?? '');
$patient_id = rsnext_normalize_patient_id($raw_patient_id) ?? trim($raw_patient_id);

$raw_modality = strtoupper(trim((string)($_GET['modality'] ?? $_GET['type'] ?? $_GET['kensa'] ?? 'ALL')));
$modality_map = [
    'ES' => 'endoscopy', 'ENDOSCOPY' => 'endoscopy', '内視鏡' => 'endoscopy', '胃カメラ' => 'endoscopy',
    'ECG' => 'ecg', '心電図' => 'ecg',
    'US' => 'us', 'ECHO' => 'us', '超音波' => 'us', 'エコー' => 'us',
    'XP' => 'xray', 'XRAY' => 'xray', 'CR' => 'xray', 'DR' => 'xray', 'DX' => 'xray', 'RF' => 'xray', '胸部' => 'xray', 'レントゲン' => 'xray',
    'OTHER' => 'other', 'その他' => 'other',
    'ALL' => 'all', '全' => 'all', '全て' => 'all',
];
$initial_category = $modality_map[$raw_modality] ?? 'all';

$display_mode = strtolower(trim((string)($_GET['mode'] ?? 'full')));
$is_embed = in_array($display_mode, ['embed', 'minimal', 'modal'], true);

$raw_layout = strtolower(trim((string)($_GET['layout'] ?? 'swimlanes')));
$layout_map = [
    'proportional' => 'proportional', 'calendar' => 'proportional', 'time' => 'proportional',
    'swimlanes' => 'swimlanes', 'lane' => 'swimlanes', 'lanes' => 'swimlanes',
    'sequential' => 'sequential', 'flow' => 'sequential', 'order' => 'sequential',
];
$initial_layout = $layout_map[$raw_layout] ?? 'swimlanes';

$raw_limit = strtolower(trim((string)($_GET['limit'] ?? '5')));
$initial_limit = ($raw_limit === 'all' || (int)$raw_limit <= 0) ? 0 : (int)$raw_limit;
$today_str = date('Y/m/d');

$patient_info = null;
$exam_records = [];
$db_error     = '';
$is_demo      = false;
$is_not_found = false;

if ($patient_id !== '') {
    try {
        $pdo = rsnext_pdo();
        $pSt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
        $pSt->execute([$patient_id]);
        $pRow = $pSt->fetch();
        $birthStr = $pRow ? (string)($pRow['birth_date'] ?? '') : '';

        if ($pRow) {
            $patient_info = [
                'id'          => (string)$pRow['patient_id'],
                'kanja_kanji' => (string)$pRow['kanja_kanji'],
                'kanja_yomi'  => (string)$pRow['kanja_yomi'],
                'sex'         => (string)$pRow['sex'],
                'age'         => rsnext_calc_age($birthStr) ?: '-',
                'birth'       => str_replace('-', '/', $birthStr),
            ];
        }

        $sSt = $pdo->prepare(
            "SELECT * FROM studies
             WHERE patient_id = ? AND NOT is_deleted
             ORDER BY study_date ASC, study_time ASC NULLS LAST, study_id ASC"
        );
        $sSt->execute([$patient_id]);
        $rows = $sSt->fetchAll();

        $colorMap = [
            'endoscopy' => '4',
            'ecg'       => '1',
            'us'        => '5',
            'xray'      => '6',
            'other'     => '6',
        ];

        foreach ($rows as $r) {
            $cat = (string)($r['display_category'] ?: 'other');
            $isEcg = ($cat === 'ecg' || (string)$r['primary_file_type'] === 'ECG');
            $absDir = rsnext_resolve_study_dir((string)$r['volume_id'], (string)$r['rel_path']);
            $firstFn = trim((string)($r['first_filename'] ?: '0001.ECG'));
            $targetPath = $isEcg ? ($absDir . '\\' . $firstFn) : $absDir;
            $dateSlash = str_replace('-', '/', (string)$r['study_date']);
            $ageExam = $birthStr !== '' ? (rsnext_calc_age($birthStr, (string)$r['study_date']) ?: '-') : '-';

            $exam_records[] = [
                'study_id'     => (int)$r['study_id'],
                'renban'       => (string)($r['legacy_renban'] ?: $r['study_id']),
                'id'           => (string)$r['patient_id'],
                'kensamei'     => rsnext_friendly_kensamei((string)$r['kensamei']),
                'raw_kensamei' => (string)$r['kensamei'],
                'category'     => $cat,
                'color_num'    => $colorMap[$cat] ?? '6',
                'date'         => $dateSlash,
                'age'          => $ageExam,
                'age_at_exam'  => $ageExam,
                'doctor'       => (string)($r['doctor'] ?? ''),
                'sindan1'      => (string)($r['findings'] ?? ''),
                'folder'       => $targetPath,
                'raw_folder'   => $targetPath,
                'is_ecg'       => $isEcg,
                'is_tomo'      => rsnext_is_tomographic_study((string)($r['modality'] ?? ''), (string)$r['kensamei'], (string)($r['primary_file_type'] ?? 'DICOM')),
            ];
        }
    } catch (Throwable $e) {
        $db_error = 'DB照会エラー: ' . $e->getMessage();
    }
}

if ($patient_id === '') {
    $is_not_found = true;
    $patient_info = ['id' => '-', 'kanja_kanji' => '患者IDを指定してください', 'kanja_yomi' => '', 'sex' => '-', 'age' => '-', 'birth' => ''];
} elseif ($patient_info === null || empty($exam_records)) {
    if ($patient_info === null) {
        $is_not_found = true;
        $patient_info = ['id' => $patient_id, 'kanja_kanji' => '該当患者データなし', 'kanja_yomi' => '', 'sex' => '-', 'age' => '-', 'birth' => ''];
    }
}

$standalone_url = 'canvas_view.php?patient_no=' . urlencode($patient_id)
    . '&modality=' . urlencode($raw_modality)
    . '&layout=' . urlencode($initial_layout);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🏥 RS-Next 検査タイムライン・キャンバス (JSON Canvas)</title>
    <style>
        :root {
            --bg-canvas: #090d16;
            --grid-dot: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --panel-bg: rgba(15, 23, 42, 0.92);
            --card-bg: #1e293b;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --accent-purple: #8b5cf6;
            --accent-cyan: #06b6d4;
            --accent-yellow: #f59e0b;
        }

        [data-theme="light"] {
            --bg-canvas: #f1f5f9;
            --grid-dot: #cbd5e1;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #cbd5e1;
            --panel-bg: rgba(255, 255, 255, 0.92);
            --card-bg: #ffffff;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-canvas);
            color: var(--text-main);
            overflow: hidden;
            width: 100vw;
            height: 100vh;
            user-select: none;
        }

        /* トップナビバー */
        .top-navbar {
            height: 52px;
            background: linear-gradient(90deg, #070d18 0%, #0f172a 45%, #1e293b 100%);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 100;
            position: relative;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-brand {
            font-size: 0.96rem;
            font-weight: 700;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: -0.01em;
        }

        .nav-badge-spec {
            font-size: 0.68rem;
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.4);
            padding: 2px 7px;
            border-radius: 9999px;
            font-weight: 600;
        }

        .patient-quick-badge {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.82rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .patient-quick-badge:hover {
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(59, 130, 246, 0.5);
        }

        /* 👤 患者情報ホバーポップアップ */
        .patient-hover-popover {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            z-index: 1000;
            min-width: 200px;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(59, 130, 246, 0.4);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.7);
            border-radius: 8px;
            padding: 10px 14px;
            display: none;
            flex-direction: column;
            gap: 6px;
            pointer-events: none;
            animation: patPopFadeIn 0.18s ease-out;
        }
        .patient-quick-badge:hover .patient-hover-popover {
            display: flex;
        }
        @keyframes patPopFadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .pat-id-tag {
            font-family: monospace;
            background: #2563eb;
            color: #fff;
            padding: 1px 6px;
            border-radius: 4px;
            font-weight: 700;
        }

        .nav-center {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* 検査種別絞り込みピル群 */
        .filter-pills-bar {
            display: flex;
            align-items: center;
            gap: 5px;
            background: rgba(15, 23, 42, 0.65);
            padding: 3px 8px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .filter-pill {
            background: transparent;
            color: #94a3b8;
            border: 1px solid transparent;
            padding: 3px 10px;
            border-radius: 14px;
            font-size: 0.76rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
            user-select: none;
        }
        .filter-pill:hover {
            color: #f8fafc;
            background: rgba(255, 255, 255, 0.08);
        }
        .filter-pill.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #3b82f6;
            box-shadow: 0 1px 4px rgba(37, 99, 235, 0.4);
        }
        .pill-count {
            font-size: 0.68rem;
            background: rgba(0, 0, 0, 0.3);
            padding: 0 5px;
            border-radius: 10px;
            font-family: monospace;
        }

        /* レイアウトモード切替グループ */
        .layout-mode-group {
            display: flex;
            align-items: center;
            background: rgba(15, 23, 42, 0.7);
            border-radius: 6px;
            padding: 2px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            gap: 2px;
        }
        .btn-layout-mode {
            background: transparent;
            color: #94a3b8;
            border: none;
            padding: 4px 10px;
            border-radius: 5px;
            font-size: 0.74rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-layout-mode:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
        }
        .btn-layout-mode.active {
            background: #3b82f6;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ツールバーボタン */
        .btn-tool {
            background: rgba(255, 255, 255, 0.08);
            color: #e2e8f0;
            border: 1px solid rgba(255, 255, 255, 0.18);
            padding: 5px 11px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s;
        }
        .btn-tool:hover {
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
            border-color: #94a3b8;
        }

        .btn-tool-primary {
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
            border-color: #60a5fa;
            color: #ffffff;
        }
        .btn-tool-primary:hover {
            background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%);
        }

        .btn-tool-amber {
            background: rgba(245, 158, 11, 0.15);
            border-color: rgba(245, 158, 11, 0.4);
            color: #fcd34d;
        }
        .btn-tool-amber:hover {
            background: rgba(245, 158, 11, 0.25);
            color: #fff;
        }

        /* 無限キャンバスコンテナ */
        #canvas-viewport {
            width: 100vw;
            height: calc(100vh - 52px);
            position: relative;
            overflow: hidden;
            cursor: grab;
            background-color: var(--bg-canvas);
            background-image: radial-gradient(var(--grid-dot) 1.2px, transparent 1.2px);
            background-size: 24px 24px;
        }
        #canvas-viewport.panning {
            cursor: grabbing;
        }

        /* 座標変換されるワールド空間 */
        #canvas-world {
            position: absolute;
            left: 0;
            top: 0;
            width: 0;
            height: 0;
            transform-origin: 0 0;
            will-change: transform;
        }

        /* SVG接続線レイヤー */
        #canvas-svg-layer {
            position: absolute;
            left: 0;
            top: 0;
            overflow: visible;
            pointer-events: none;
            z-index: 1;
        }

        .canvas-edge {
            fill: none;
            stroke: #475569;
            stroke-width: 2.2;
            transition: stroke 0.15s, stroke-width 0.15s;
        }
        .canvas-edge:hover {
            stroke: #60a5fa;
            stroke-width: 3.5;
        }
        .canvas-edge-label-bg {
            fill: #0f172a;
            stroke: #334155;
            rx: 4;
            ry: 4;
        }
        .canvas-edge-label-text {
            fill: #cbd5e1;
            font-size: 11px;
            font-family: sans-serif;
            text-anchor: middle;
            dominant-baseline: central;
            font-weight: 500;
        }

        /* ノードレイヤー */
        #canvas-nodes-layer {
            position: absolute;
            left: 0;
            top: 0;
            z-index: 2;
        }

        /* キャンバスノード共通カード */
        .canvas-node {
            position: absolute;
            border-radius: 10px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.35);
            transition: box-shadow 0.15s, border-color 0.15s;
            cursor: default;
            user-select: text;
        }
        .canvas-node:hover {
            border-color: #60a5fa;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
        }
        .canvas-node.dragging {
            box-shadow: 0 16px 36px rgba(37, 99, 235, 0.4);
            border-color: #3b82f6;
            cursor: move;
        }

        /* ⭐ 本日受診・検査カード特別強調スタイル */
        .canvas-node.is-today-exam {
            border: 3px solid #f59e0b !important;
            box-shadow: 0 0 25px rgba(245, 158, 11, 0.55), 0 10px 24px rgba(0, 0, 0, 0.4) !important;
            z-index: 10;
        }
        .canvas-node.is-today-exam:hover {
            border-color: #fbbf24 !important;
            box-shadow: 0 0 35px rgba(245, 158, 11, 0.75), 0 14px 32px rgba(0, 0, 0, 0.5) !important;
        }
        .badge-today-pulse {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            box-shadow: 0 2px 6px rgba(217, 119, 6, 0.5);
            animation: pulse-glow-today 2.2s infinite ease-in-out;
        }
        @keyframes pulse-glow-today {
            0%, 100% { transform: scale(1); filter: drop-shadow(0 0 2px rgba(245, 158, 11, 0.6)); }
            50% { transform: scale(1.06); filter: drop-shadow(0 0 8px rgba(245, 158, 11, 0.9)); }
        }
        .btn-expand-history {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(139, 92, 246, 0.2);
            color: #c4b5fd;
            border: 1px solid rgba(139, 92, 246, 0.5);
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-expand-history:hover {
            background: rgba(139, 92, 246, 0.4);
            color: #ffffff;
            border-color: #a78bfa;
            box-shadow: 0 2px 8px rgba(139, 92, 246, 0.35);
        }

        /* スイムレーン・グループノード (JSON Canvas type: group) */
        .canvas-group-node {
            position: absolute;
            border-radius: 14px;
            background: rgba(30, 41, 59, 0.3);
            border: 2px dashed rgba(71, 85, 105, 0.6);
            pointer-events: none;
            z-index: 0;
        }
        .canvas-group-label {
            position: absolute;
            top: 10px;
            left: 14px;
            font-size: 0.86rem;
            font-weight: 700;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* 患者メインカード */
        .patient-card {
            background: linear-gradient(145deg, #0b1528 0%, #1e293b 100%);
            border: 1.5px solid #3b82f6;
            padding: 16px 18px;
            color: #ffffff;
        }
        .patient-card .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            cursor: move;
        }
        .patient-card .card-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: #ffffff;
        }
        .patient-card .card-sub {
            font-size: 0.78rem;
            color: #94a3b8;
            margin-bottom: 12px;
        }
        .pat-stat-row {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }
        .pat-stat-chip {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.72rem;
            color: #cbd5e1;
        }

        /* 検査ノードカード */
        .exam-card {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .exam-card-header {
            padding: 8px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: move;
            font-size: 0.8rem;
            font-weight: 700;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .header-endoscopy { background: linear-gradient(90deg, #064e3b 0%, #047857 100%); color: #a7f3d0; }
        .header-ecg       { background: linear-gradient(90deg, #831843 0%, #be185d 100%); color: #fbcfe8; }
        .header-us        { background: linear-gradient(90deg, #164e63 0%, #0891b2 100%); color: #a5f3fc; }
        .header-xray      { background: linear-gradient(90deg, #3b0764 0%, #7e22ce 100%); color: #e9d5ff; }
        .header-other     { background: linear-gradient(90deg, #1e293b 0%, #334155 100%); color: #cbd5e1; }

        .exam-header-right {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-left: auto;
        }
        .exam-date-badge {
            font-family: monospace;
            background: rgba(0, 0, 0, 0.35);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.74rem;
            color: #ffffff;
            white-space: nowrap;
        }
        .exam-age-badge {
            background: rgba(59, 130, 246, 0.25);
            border: 1px solid rgba(147, 197, 253, 0.4);
            color: #bae6fd;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 700;
            white-space: nowrap;
        }
        [data-theme="light"] .exam-age-badge {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #1d4ed8;
        }

        .exam-card-body {
            padding: 10px 12px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .exam-sindan-box {
            font-size: 0.8rem;
            color: #f1f5f9;
            background: rgba(0,0,0,0.22);
            padding: 6px 8px;
            border-radius: 5px;
            border-left: 3px solid #3b82f6;
            min-height: 38px;
            line-height: 1.4;
        }

        /* 検査カード内の年齢・所見メタ */
        .exam-meta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            font-size: 0.72rem;
            margin-bottom: 3px;
        }
        .exam-age-chip {
            background: rgba(59, 130, 246, 0.18);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.35);
            padding: 1px 7px;
            border-radius: 4px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        [data-theme="light"] .exam-age-chip {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }
        .exam-doctor-chip {
            background: rgba(255, 255, 255, 0.06);
            color: #cbd5e1;
            padding: 1px 6px;
            border-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .exam-folder-hint {
            font-size: 0.7rem;
            color: #64748b;
            font-family: monospace;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .exam-card-footer {
            padding: 8px 12px;
            background: rgba(0, 0, 0, 0.25);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
        }

        /* カードフッターの別タブ閲覧ボタン */
        .btn-view-tab {
            padding: 3px 9px;
            border-radius: 4px;
            font-size: 0.74rem;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
            cursor: pointer;
        }
        .btn-view-dcm {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff !important;
            border: 1px solid #38bdf8;
        }
        .btn-view-dcm:hover {
            background: linear-gradient(135deg, #0369a1 0%, #075985 100%);
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(2, 132, 199, 0.5);
        }
        .btn-view-ecg {
            background: linear-gradient(135deg, #059669 0%, #0d9488 100%);
            color: #ffffff !important;
            border: 1px solid #34d399;
        }
        .btn-view-ecg:hover {
            background: linear-gradient(135deg, #047857 0%, #0f766e 100%);
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(5, 150, 105, 0.5);
        }

        .btn-jpeg-sm {
            background: #ea580c;
            color: #ffffff;
            border: none;
            padding: 3px 7px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-jpeg-sm:hover { background: #c2410c; }

        .btn-copy-sm {
            background: rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-copy-sm:hover { background: rgba(255, 255, 255, 0.2); color: #fff; }

        /* 付箋メモカード (JSON Canvas type: text) */
        .sticky-card {
            background: #fef3c7;
            color: #78350f;
            border: 1px solid #fde68a;
            box-shadow: 0 8px 18px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
        }
        [data-theme="dark"] .sticky-card {
            background: #451a03;
            color: #fef3c7;
            border-color: #92400e;
        }
        .sticky-header {
            padding: 6px 10px;
            border-bottom: 1px dashed rgba(0,0,0,0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.72rem;
            font-weight: 700;
            cursor: move;
        }
        .sticky-textarea {
            width: 100%;
            height: 100%;
            border: none;
            background: transparent;
            color: inherit;
            font-family: inherit;
            font-size: 0.84rem;
            line-height: 1.45;
            padding: 10px;
            outline: none;
            resize: none;
        }

        /* 画面右下のHUDフローティングコントローラー */
        .hud-controls {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 6px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
            backdrop-filter: blur(8px);
            z-index: 100;
        }
        .hud-btn {
            width: 34px;
            height: 34px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 700;
            transition: all 0.15s;
        }
        .hud-btn:hover {
            background: #2563eb;
            border-color: #3b82f6;
        }
        .hud-zoom-val {
            font-size: 0.68rem;
            text-align: center;
            color: #94a3b8;
            font-family: monospace;
            padding: 2px 0;
        }

        /* 実時間タイムラインの年・月ルーラー */
        #canvas-ruler-layer {
            position: absolute;
            left: 0;
            top: 0;
            pointer-events: none;
            z-index: 1;
        }
        .time-guide-line {
            position: absolute;
            top: 155px;
            width: 1px;
            border-left: 1.5px dashed rgba(100, 116, 139, 0.45);
            pointer-events: none;
        }
        .time-guide-label {
            position: absolute;
            top: 120px;
            transform: translateX(-50%);
            background: rgba(15, 23, 42, 0.88);
            border: 1px solid #334155;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.72rem;
            font-weight: 700;
            color: #60a5fa;
            white-space: nowrap;
            backdrop-filter: blur(4px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.35);
        }
        [data-theme="light"] .time-guide-label {
            background: rgba(255, 255, 255, 0.92);
            border-color: #cbd5e1;
            color: #2563eb;
        }

        /* トースト通知 */
        #toast {
            visibility: hidden;
            min-width: 260px;
            background-color: #0f172a;
            color: #fff;
            text-align: center;
            border-radius: 8px;
            padding: 10px 16px;
            position: fixed;
            z-index: 9999;
            left: 50%;
            bottom: 30px;
            transform: translateX(-50%);
            font-size: 0.84rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
            border: 1px solid #3b82f6;
            transition: all 0.3s;
        }
        #toast.show {
            visibility: visible;
            bottom: 40px;
        }
        #toast.toast-warning {
            background-color: #7c2d12;
            border-color: #f97316;
            color: #ffedd5;
            box-shadow: 0 8px 24px rgba(249, 115, 22, 0.45);
        }

        /* モーダルダイアログ */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(4px);
            z-index: 500;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 12px;
            width: 720px;
            max-width: 90vw;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
        }
        .modal-header {
            padding: 14px 18px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
        }
        .modal-body {
            padding: 16px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-body pre {
            background: #070d18;
            color: #a5f3fc;
            padding: 12px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.78rem;
            overflow-x: auto;
            white-space: pre-wrap;
        }
        .modal-footer {
            padding: 12px 18px;
            background: #1e293b;
            border-top: 1px solid #334155;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        /* 埋め込みモード (mode=embed / minimal) スタイル */
        body.mode-embed .top-navbar {
            height: 40px;
            padding: 0 10px;
            background: #0b1120;
            border-bottom: 1px solid rgba(59, 130, 246, 0.35);
        }
        body.mode-embed #canvas-viewport {
            height: calc(100vh - 40px);
        }
        body.mode-embed .btn-tool-extra {
            display: none !important;
        }
        body.mode-embed .nav-brand {
            font-size: 0.85rem;
        }
        body.mode-embed .nav-left {
            gap: 8px;
        }
        body.mode-embed .filter-pill {
            padding: 3px 8px;
            font-size: 0.76rem;
        }
        body.mode-embed .btn-layout-mode {
            padding: 3px 7px;
            font-size: 0.74rem;
        }
        .btn-open-standalone {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: rgba(59, 130, 246, 0.25);
            border: 1px solid rgba(96, 165, 250, 0.45);
            color: #bae6fd;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.76rem;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s ease;
        }
        .btn-open-standalone:hover {
            background: rgba(59, 130, 246, 0.5);
            border-color: #60a5fa;
            color: #ffffff;
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.4);
        }

        /* 案内通知カード (該当検査なし・患者未登録) */
        .notice-card {
            background: #1e293b;
            border: 1px solid #f97316;
            border-radius: 10px;
            box-shadow: 0 6px 20px rgba(249, 115, 22, 0.25);
            overflow: hidden;
            color: #f8fafc;
        }
    </style>
</head>
<body data-theme="dark"<?= $is_embed ? ' class="mode-embed"' : '' ?>>

    <!-- トップナビバー -->
    <header class="top-navbar">
        <div class="nav-left">
            <span class="nav-brand">
                <span>🗺️</span>
                <span>RS-Base 検査キャンバス</span>
            </span>

            <?php if ($patient_info): ?>
                <div class="patient-quick-badge">
                    <span class="pat-id-tag"><?= htmlspecialchars($patient_info['id']) ?></span>
                    <strong style="color:#f8fafc; font-weight:700;"><?= htmlspecialchars($patient_info['kanja_kanji']) ?></strong>
                    <div class="patient-hover-popover">
                        <div style="font-size:0.75rem; color:#94a3b8;"><?= htmlspecialchars($patient_info['kanja_yomi']) ?></div>
                        <div style="font-size:0.85rem; color:#f8fafc; font-weight:600;">
                            <span style="color:#60a5fa;"><?= htmlspecialchars($patient_info['age']) ?>歳</span> (<?= htmlspecialchars($patient_info['sex']) ?>)
                        </div>
                        <div style="font-size:0.75rem; color:#6ee7b7; border-top:1px solid rgba(255,255,255,0.1); padding-top:4px; margin-top:2px;">
                            📋 検査件数: <strong><?= count($exam_records) ?>件</strong>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- 検査種別絞り込みピル群（中央） -->
        <div class="nav-center">
            <div class="filter-pills-bar" id="categoryFilterBar"></div>
            <button type="button" id="btnExpandAllExams" class="btn-expand-history" onclick="toggleExpandAllExams()" style="display:none;" title="過去のすべての検査結果を展開表示します">
                <span>⏪</span> <span id="expandAllBtnLabel">過去全件を展開</span>
            </button>
        </div>

        <div class="nav-right">
            <!-- レイアウトモード切替グループ -->
            <div class="layout-mode-group">
                <button type="button" class="btn-layout-mode" id="btnModeProportional" onclick="switchLayoutMode('proportional')" title="検査年月日を実時間間隔で横軸に配置するカレンダータイムライン">
                    <span>📅 実時間</span>
                </button>
                <button type="button" class="btn-layout-mode active" id="btnModeSwimlanes" onclick="switchLayoutMode('swimlanes')" title="検査種別ごとのレーンに分けたスイムレーン表示">
                    <span>🔄 種別レーン</span>
                </button>
                <button type="button" class="btn-layout-mode" id="btnModeSequential" onclick="switchLayoutMode('sequential')" title="全検査を均等間隔の日付順フローで整列">
                    <span>⏳ 順序フロー</span>
                </button>
            </div>

            <button type="button" class="btn-tool btn-tool-amber btn-tool-extra" onclick="addStickyNote()" title="キャンバス上にフリー付箋メモを追加">
                <span>➕</span> 付箋
            </button>
            <button type="button" class="btn-tool btn-tool-primary btn-tool-extra" onclick="exportJsonCanvas()" title="Obsidian等で開ける標準 .canvas ファイルを出力">
                <span>💾</span> .canvas 保存
            </button>
            <button type="button" class="btn-tool btn-tool-extra" onclick="showJsonModal()" title="JSON Canvas仕様のRAWデータを確認・コピー">
                <span>📄</span> JSON
            </button>
            <button type="button" class="btn-tool btn-tool-extra" onclick="toggleTheme()" title="明暗テーマ切り替え">
                <span id="theme-icon">☀️</span>
            </button>

            <?php if ($is_embed): ?>
                <a href="<?= htmlspecialchars($standalone_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn-open-standalone" title="フル画面ビューワーを別タブで展開">
                    <span>↗ 別窓</span>
                </a>
                <?php if ($display_mode === 'modal'): ?>
                    <button type="button" class="btn-open-standalone" style="background:#991b1b; border-color:#dc2626; color:#fef2f2; cursor:pointer;" onclick="window.parent.postMessage({ action: 'closeCanvas' }, '*')" title="閉じる [Escキー]">
                        <span>✕ 閉じる</span>
                    </button>
                <?php endif; ?>
            <?php else: ?>
                <a href="index.php" class="btn-open-standalone" title="本日の検査一覧（ホーム）へ移動">
                    <span>🏠 本日一覧へ</span>
                </a>
            <?php endif; ?>
        </div>
    </header>

    <!-- 無限キャンバスビューポート -->
    <div id="canvas-viewport">
        <div id="canvas-world">
            <!-- 実時間カレンダールーラーレイヤー -->
            <div id="canvas-ruler-layer"></div>

            <!-- SVG接続エッジレイヤー -->
            <svg id="canvas-svg-layer" width="10000" height="10000">
                <defs>
                    <marker id="arrowhead" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
                        <polygon points="0 0, 10 3.5, 0 7" fill="#64748b" />
                    </marker>
                    <marker id="arrowhead-active" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
                        <polygon points="0 0, 10 3.5, 0 7" fill="#3b82f6" />
                    </marker>
                </defs>
                <g id="svg-edges-group"></g>
            </svg>

            <!-- スイムレーン・グループレイヤー -->
            <div id="canvas-groups-layer"></div>

            <!-- ノードレイヤー -->
            <div id="canvas-nodes-layer"></div>
        </div>
    </div>

    <!-- HUDフローティングコントローラー (ズーム・全体表示) -->
    <div class="hud-controls">
        <button class="hud-btn" onclick="zoomIn()" title="ズームイン (+)">＋</button>
        <button class="hud-btn" onclick="zoomOut()" title="ズームアウト (-)">－</button>
        <div class="hud-zoom-val" id="hudZoomVal">100%</div>
        <button class="hud-btn" onclick="resetZoom()" title="等倍表示 (100%)" style="font-size:0.75rem;">1:1</button>
        <button class="hud-btn" onclick="fitToView()" title="すべてのノードを画面内に収める (Fit)">⛶</button>
    </div>

    <!-- JSONプレビュー用モーダル -->
    <div id="jsonModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <span>📄 JSON Canvas (v1.0 Spec) プレビュー</span>
                <button type="button" onclick="closeJsonModal()" style="background:none; border:none; color:#cbd5e1; font-size:1.2rem; cursor:pointer;">✕</button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.8rem; color:#94a3b8; margin-bottom:10px;">
                    Obsidianの <code>.canvas</code> ファイルとしてそのままインポート可能なJSON構造です。
                </p>
                <pre id="jsonPreviewContent"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-tool" onclick="copyJsonToClipboard()">📋 JSONをコピー</button>
                <button type="button" class="btn-tool btn-tool-primary" onclick="exportJsonCanvas()">💾 .canvas ファイルとしてダウンロード</button>
                <button type="button" class="btn-tool" onclick="closeJsonModal()">閉じる</button>
            </div>
        </div>
    </div>

    <!-- トースト通知 -->
    <div id="toast"></div>

    <script>
        // ==============================================================================
        // キャンバス状態管理・初期データ展開
        // ==============================================================================
        const PATIENT_INFO = <?= json_encode($patient_info, JSON_UNESCAPED_UNICODE) ?>;
        const EXAM_RECORDS = <?= json_encode($exam_records, JSON_UNESCAPED_UNICODE) ?>;

        // 外部連携API パラメータ
        const INITIAL_CATEGORY     = <?= json_encode($initial_category, JSON_HEX_TAG) ?>;
        const INITIAL_MODALITY_RAW = <?= json_encode($raw_modality, JSON_HEX_TAG) ?>;
        const INITIAL_LAYOUT       = <?= json_encode($initial_layout, JSON_HEX_TAG) ?>;
        const IS_EMBED_MODE        = <?= $is_embed ? 'true' : 'false' ?>;
        const IS_NOT_FOUND         = <?= !empty($is_not_found) ? 'true' : 'false' ?>;
        const INITIAL_LIMIT        = <?= (int)$initial_limit ?>;
        const TODAY_STR            = <?= json_encode($today_str) ?>;

        // JSON Canvas Spec データモデル
        let canvasData = {
            nodes: [],
            edges: []
        };

        // ビューポート座標・ズーム状態
        let panX = 120;
        let panY = 100;
        let scale = 0.85;

        let isPanning = false;
        let startPanMouseX = 0;
        let startPanMouseY = 0;

        let isDraggingNode = false;
        let draggedNodeId = null;
        let dragOffset = { x: 0, y: 0 };

        const viewport = document.getElementById('canvas-viewport');
        const world = document.getElementById('canvas-world');
        const nodesLayer = document.getElementById('canvas-nodes-layer');
        const groupsLayer = document.getElementById('canvas-groups-layer');
        const svgEdgesGroup = document.getElementById('svg-edges-group');
        const hudZoomVal = document.getElementById('hudZoomVal');

        // ==============================================================================
        // カテゴリ定義 & 状態管理
        // ==============================================================================
        let selectedCategory = INITIAL_CATEGORY || 'all';
        let currentLayoutMode = INITIAL_LAYOUT || 'swimlanes';

        function appendEmptyNoticeNode(x = 440, y = 200) {
            let title = "該当検査なし";
            let msg = "";
            if (IS_NOT_FOUND) {
                title = "患者データ未登録";
                msg = `指定された患者ID [${escapeHtml(PATIENT_INFO?.id || '')}] の検査データは登録されていません。`;
            } else {
                const catLabels = { endoscopy: '内視鏡 (ES)', ecg: '心電図 (ECG)', us: '超音波 (US)', xray: '胸部XP (XP)', other: 'その他', all: '全検査' };
                const catName = catLabels[selectedCategory] || selectedCategory;
                msg = `この患者には、指定された検査種別「${catName}」のデータがありません（全検査数: ${EXAM_RECORDS.length}件）。`;
            }
            canvasData.nodes.push({
                id: "node_empty_notice",
                type: "text",
                x: x,
                y: y,
                width: 360,
                height: 180,
                color: "2",
                category: "notice",
                data: {
                    title: title,
                    message: msg
                }
            });
        }

        function initFilterPills() {
            const bar = document.getElementById('categoryFilterBar');
            if (!bar) return;

            const counts = { all: EXAM_RECORDS.length };
            EXAM_RECORDS.forEach(e => {
                const cat = e.category || 'other';
                counts[cat] = (counts[cat] || 0) + 1;
            });

            const defs = [
                { id: 'all',       label: '全検査', icon: '🌐' },
                { id: 'endoscopy', label: '内視鏡', icon: '🔬' },
                { id: 'ecg',       label: '心電図', icon: '❤️' },
                { id: 'us',        label: '超音波', icon: '📡' },
                { id: 'xray',      label: '胸部XP', icon: '🩻' },
                { id: 'other',     label: 'その他', icon: '📋' }
            ];

            bar.innerHTML = '';
            defs.forEach(def => {
                const count = counts[def.id] || 0;
                if (def.id !== 'all' && count === 0) return;

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'filter-pill' + (def.id === selectedCategory ? ' active' : '');
                btn.dataset.cat = def.id;
                btn.onclick = () => setCategoryFilter(def.id);
                btn.innerHTML = `<span>${def.icon} ${def.label}</span><span class="pill-count">${count}</span>`;
                bar.appendChild(btn);
            });
        }

        function setCategoryFilter(catId) {
            selectedCategory = catId;
            document.querySelectorAll('.filter-pill').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.cat === catId);
            });
            applyCurrentLayout();
            fitToView();

            const activePill = document.querySelector(`.filter-pill[data-cat="${catId}"]`);
            const label = activePill ? activePill.innerText.trim() : catId;
            showToast(`🔍 絞り込み: ${label}`);
        }

        function switchLayoutMode(mode) {
            currentLayoutMode = mode;
            document.getElementById('btnModeProportional')?.classList.toggle('active', mode === 'proportional');
            document.getElementById('btnModeSwimlanes')?.classList.toggle('active', mode === 'swimlanes');
            document.getElementById('btnModeSequential')?.classList.toggle('active', mode === 'sequential');

            applyCurrentLayout();
            fitToView();

            if (mode === 'proportional') {
                showToast("📅 実時間カレンダータイムライン（年月スケール比例）");
            } else if (mode === 'swimlanes') {
                showToast("🔄 検査種別スイムレーン");
            } else {
                showToast("⏳ 順序フロー（等間隔）");
            }
        }

                let currentLimit = INITIAL_LIMIT;
        let isExpandedAll = (INITIAL_LIMIT === 0);

        function getVisibleExams() {
            let filtered = [];
            if (selectedCategory === 'all') {
                filtered = [...EXAM_RECORDS];
            } else {
                filtered = EXAM_RECORDS.filter(e => (e.category || 'other') === selectedCategory);
            }

            // 日付昇順（過去 ➔ 最新）にソート
            filtered.sort((a, b) => (a.date || '').localeCompare(b.date || ''));

            // 最新N件制限の適用（全件展開中でない場合）
            if (!isExpandedAll && currentLimit > 0 && filtered.length > currentLimit) {
                return filtered.slice(-currentLimit);
            }
            return filtered;
        }

        function toggleExpandAllExams() {
            isExpandedAll = !isExpandedAll;
            applyCurrentLayout();
            fitToView();
            if (isExpandedAll) {
                showToast("⏪ 過去のすべての検査履歴を展開しました");
            } else {
                showToast(`⏩ 直近 ${currentLimit} 件に絞り込みました`);
            }
        }

        function updateExpandButtonUI() {
            const btn = document.getElementById('btnExpandAllExams');
            if (!btn) return;

            let totalInCat = 0;
            if (selectedCategory === 'all') {
                totalInCat = EXAM_RECORDS.length;
            } else {
                totalInCat = EXAM_RECORDS.filter(e => (e.category || 'other') === selectedCategory).length;
            }

            if (currentLimit > 0 && totalInCat > currentLimit) {
                btn.style.display = 'inline-flex';
                if (isExpandedAll) {
                    btn.innerHTML = `<span>⏩</span> <span>直近${currentLimit}件に絞る</span>`;
                    btn.title = `過去の検査を折りたたみ、直近${currentLimit}件のみ表示します`;
                } else {
                    const hiddenCount = totalInCat - currentLimit;
                    btn.innerHTML = `<span>⏪</span> <span>過去全件を展開 (+${hiddenCount}件)</span>`;
                    btn.title = `全${totalInCat}件中 最新${currentLimit}件を表示中。クリックで過去の全検査を展開します`;
                }
            } else {
                btn.style.display = 'none';
            }
        }

        function applyCurrentLayout() {
            const visible = getVisibleExams();
            if (currentLayoutMode === 'proportional') {
                layoutProportionalTimeline(visible);
            } else if (currentLayoutMode === 'swimlanes') {
                layoutSwimlanes(visible);
            } else {
                layoutSequential(visible);
            }
            renderAll();
            updateExpandButtonUI();
        }

        // ==============================================================================
        // ① 実時間カレンダータイムライン (年月目盛ルーラー & マルチトラック衝突回避)
        // ==============================================================================
        function layoutProportionalTimeline(visibleExams) {
            canvasData.nodes = [];
            canvasData.edges = [];

            const rulerLayer = document.getElementById('canvas-ruler-layer');
            if (rulerLayer) rulerLayer.innerHTML = '';

            // 患者カード
            const patNode = {
                id: "node_patient",
                type: "text",
                x: 60,
                y: 180,
                width: 320,
                height: 200,
                color: "5",
                category: "patient",
                data: PATIENT_INFO
            };
            canvasData.nodes.push(patNode);

            if (visibleExams.length === 0) {
                appendEmptyNoticeNode(440, 180);
                return;
            }

            // 日付昇順ソート
            const sorted = [...visibleExams].sort((a, b) => {
                const da = new Date((a.date || '').replace(/\//g, '-')).getTime() || 0;
                const db = new Date((b.date || '').replace(/\//g, '-')).getTime() || 0;
                return da - db;
            });

            const timestamps = sorted.map(e => new Date((e.date || '').replace(/\//g, '-')).getTime()).filter(t => !isNaN(t) && t > 0);
            const minTs = timestamps.length > 0 ? Math.min(...timestamps) : Date.now();
            const maxTs = timestamps.length > 0 ? Math.max(...timestamps) : minTs;
            const totalDays = Math.max(1, (maxTs - minTs) / (1000 * 60 * 60 * 24));

            const CARD_WIDTH = 295;
            const CARD_HEIGHT = 195;
            const X_START = 460;
            const BASE_Y = 120;
            const TRACK_GAP_Y = 230;

            // スケール計算: 日数に応じた適切なピクセル/日
            let pxPerDay = 3600 / Math.max(totalDays, 180);
            pxPerDay = Math.max(0.65, Math.min(25.0, pxPerDay));

            // 実時間ルーラー描画
            if (rulerLayer && timestamps.length > 0) {
                const minYear = new Date(minTs).getFullYear();
                const maxYear = new Date(maxTs).getFullYear();

                if (minYear === maxYear || totalDays < 365) {
                    // 1年以内: 月単位ルーラー
                    const startM = new Date(minTs).getMonth();
                    const endM = totalDays > 30 ? new Date(maxTs).getMonth() + 1 : startM + 1;
                    for (let m = startM; m <= endM; m++) {
                        const mTs = new Date(minYear, m, 1).getTime();
                        const mDays = (mTs - minTs) / (1000 * 60 * 60 * 24);
                        const mX = Math.round(X_START + mDays * pxPerDay);
                        const line = document.createElement('div');
                        line.className = 'time-guide-line';
                        line.style.left = mX + 'px';
                        line.innerHTML = `<span class="time-guide-label">📅 ${minYear}/${m + 1}月</span>`;
                        rulerLayer.appendChild(line);
                    }
                } else {
                    // 複数年: 年単位ルーラー
                    for (let y = minYear; y <= maxYear + 1; y++) {
                        const yrTs = new Date(y, 0, 1).getTime();
                        const yrDays = (yrTs - minTs) / (1000 * 60 * 60 * 24);
                        const yrX = Math.round(X_START + yrDays * pxPerDay);
                        const line = document.createElement('div');
                        line.className = 'time-guide-line';
                        line.style.left = yrX + 'px';
                        line.innerHTML = `<span class="time-guide-label">📅 ${y}年</span>`;
                        rulerLayer.appendChild(line);
                    }
                }
            }

            // カード配置（Y軸マルチトラック割り当てによる衝突回避）
            const trackLastEndX = [];
            const examNodes = [];

            sorted.forEach((exam, idx) => {
                const eTs = new Date((exam.date || '').replace(/\//g, '-')).getTime() || minTs;
                const days = (eTs - minTs) / (1000 * 60 * 60 * 24);
                const idealX = Math.round(X_START + days * pxPerDay);

                // 衝突しない最初のトラックを探す (30pxのマージン)
                let track = 0;
                while (track < trackLastEndX.length && idealX < trackLastEndX[track] + 30) {
                    track++;
                }
                trackLastEndX[track] = idealX + CARD_WIDTH;

                const nodeX = idealX;
                const nodeY = BASE_Y + track * TRACK_GAP_Y;
                const nodeId = "exam_" + exam.renban + "_" + idx;

                const nodeObj = {
                    id: nodeId,
                    type: "text",
                    x: nodeX,
                    y: nodeY,
                    width: CARD_WIDTH,
                    height: CARD_HEIGHT,
                    color: exam.color_num || "6",
                    category: exam.category || 'other',
                    data: exam
                };
                canvasData.nodes.push(nodeObj);
                examNodes.push(nodeObj);
            });

            // エッジ接続 (時系列順)
            let prevNodeId = "node_patient";
            let prevDate = null;

            examNodes.forEach((node, i) => {
                const dateDiff = prevDate ? calculateDateDiff(prevDate, node.data.date) : "";
                canvasData.edges.push({
                    id: "edge_" + prevNodeId + "_to_" + node.id,
                    fromNode: prevNodeId,
                    fromSide: "right",
                    fromEnd: "none",
                    toNode: node.id,
                    toSide: "left",
                    toEnd: "arrow",
                    color: node.color || "6",
                    label: dateDiff || undefined
                });
                prevNodeId = node.id;
                prevDate = node.data.date;
            });
        }

        // ==============================================================================
        // ② スイムレーン形式 (検査種別ごとの並行レーン)
        // ==============================================================================
        function layoutSwimlanes(visibleExams) {
            canvasData.nodes = [];
            canvasData.edges = [];

            const rulerLayer = document.getElementById('canvas-ruler-layer');
            if (rulerLayer) rulerLayer.innerHTML = '';

            const patNode = {
                id: "node_patient",
                type: "text",
                x: 60,
                y: 180,
                width: 320,
                height: 200,
                color: "5",
                category: "patient",
                data: PATIENT_INFO
            };
            canvasData.nodes.push(patNode);

            if (visibleExams.length === 0) {
                appendEmptyNoticeNode(440, 180);
                return;
            }

            const categoryTracks = {
                endoscopy: { y: 60,  color: "4" },
                ecg:       { y: 340, color: "1" },
                us:        { y: 620, color: "5" },
                xray:      { y: 900, color: "6" },
                other:     { y: 1180, color: "2" }
            };

            const CARD_WIDTH = 295;
            const CARD_HEIGHT = 195;
            const X_START = 460;
            const X_GAP = 345;
            const Y_ROW_GAP = 240;

            const isSingleCategory = (selectedCategory !== 'all');
            const COLS_PER_ROW = isSingleCategory ? 3 : 4;

            const catCounters = {};
            const lastNodePerCat = {};

            const sorted = [...visibleExams].sort((a, b) => (a.date || '').localeCompare(b.date || ''));

            sorted.forEach((exam, idx) => {
                const cat = exam.category || 'other';
                if (!catCounters[cat]) catCounters[cat] = 0;

                const track = categoryTracks[cat] || categoryTracks.other;
                const countInCat = catCounters[cat];

                let nodeX, nodeY;
                if (isSingleCategory) {
                    // 単一カテゴリ絞り込み時は縦スペースを活用して1行3件で折り返す
                    const col = countInCat % COLS_PER_ROW;
                    const row = Math.floor(countInCat / COLS_PER_ROW);
                    nodeX = X_START + col * X_GAP;
                    nodeY = 80 + row * Y_ROW_GAP;
                } else {
                    // 全検査（ALL）スイムレーン時
                    const col = countInCat % COLS_PER_ROW;
                    const row = Math.floor(countInCat / COLS_PER_ROW);
                    nodeX = X_START + col * X_GAP;
                    nodeY = track.y + row * (CARD_HEIGHT + 35);
                }

                const nodeId = "exam_" + exam.renban + "_" + idx;

                const nodeObj = {
                    id: nodeId,
                    type: "text",
                    x: nodeX,
                    y: nodeY,
                    width: CARD_WIDTH,
                    height: CARD_HEIGHT,
                    color: exam.color_num || "6",
                    category: cat,
                    data: exam
                };
                canvasData.nodes.push(nodeObj);

                if (lastNodePerCat[cat]) {
                    const prevNode = canvasData.nodes.find(n => n.id === lastNodePerCat[cat]);
                    const isNewRow = Math.floor(countInCat / COLS_PER_ROW) > Math.floor((countInCat - 1) / COLS_PER_ROW);
                    canvasData.edges.push({
                        id: "edge_" + lastNodePerCat[cat] + "_to_" + nodeId,
                        fromNode: lastNodePerCat[cat],
                        fromSide: isNewRow ? "bottom" : "right",
                        fromEnd: "none",
                        toNode: nodeId,
                        toSide: isNewRow ? "top" : "left",
                        toEnd: "arrow",
                        color: exam.color_num || "6",
                        label: calculateDateDiff(prevNode?.data?.date, exam.date)
                    });
                } else {
                    canvasData.edges.push({
                        id: "edge_pat_to_" + nodeId,
                        fromNode: "node_patient",
                        fromSide: "right",
                        fromEnd: "none",
                        toNode: nodeId,
                        toSide: "left",
                        toEnd: "arrow",
                        color: "5"
                    });
                }

                lastNodePerCat[cat] = nodeId;
                catCounters[cat]++;
            });
        }

        // ==============================================================================
        // ③ 順序フロー形式 (全検査を日付順に等間隔配置)
        // ==============================================================================
        function layoutSequential(visibleExams) {
            canvasData.nodes = [];
            canvasData.edges = [];

            const rulerLayer = document.getElementById('canvas-ruler-layer');
            if (rulerLayer) rulerLayer.innerHTML = '';

            const patNode = {
                id: "node_patient",
                type: "text",
                x: 60,
                y: 200,
                width: 320,
                height: 200,
                color: "5",
                category: "patient",
                data: PATIENT_INFO
            };
            canvasData.nodes.push(patNode);

            if (visibleExams.length === 0) {
                appendEmptyNoticeNode(440, 200);
                return;
            }

            const CARD_WIDTH = 295;
            const CARD_HEIGHT = 195;
            const X_START = 460;
            const GAP = 360;
            const nodeY = 200;

            const sorted = [...visibleExams].sort((a, b) => (a.date || '').localeCompare(b.date || ''));

            let prevId = "node_patient";
            let prevDate = null;

            sorted.forEach((exam, idx) => {
                const nodeX = X_START + idx * GAP;
                const nodeId = "exam_" + exam.renban + "_" + idx;

                const nodeObj = {
                    id: nodeId,
                    type: "text",
                    x: nodeX,
                    y: nodeY,
                    width: CARD_WIDTH,
                    height: CARD_HEIGHT,
                    color: exam.color_num || "6",
                    category: exam.category || 'other',
                    data: exam
                };
                canvasData.nodes.push(nodeObj);

                const dateDiff = prevDate ? calculateDateDiff(prevDate, exam.date) : "";
                canvasData.edges.push({
                    id: "edge_" + prevId + "_to_" + nodeId,
                    fromNode: prevId,
                    fromSide: "right",
                    fromEnd: "none",
                    toNode: nodeId,
                    toSide: "left",
                    toEnd: "arrow",
                    color: exam.color_num || "6",
                    label: dateDiff || undefined
                });

                prevId = nodeId;
                prevDate = exam.date;
            });
        }

        function calculateDateDiff(d1, d2) {
            if (!d1 || !d2) return "";
            try {
                const t1 = new Date(d1.replace(/\//g, '-')).getTime();
                const t2 = new Date(d2.replace(/\//g, '-')).getTime();
                const diffDays = Math.round((t2 - t1) / (1000 * 60 * 60 * 24));
                if (diffDays <= 0) return "";
                if (diffDays < 30) return diffDays + "日後";
                if (diffDays < 365) return Math.round(diffDays / 30) + "ヶ月後";
                const years = (diffDays / 365).toFixed(1);
                return years.replace('.0', '') + "年後フォロー";
            } catch (e) {
                return "";
            }
        }

        // ==============================================================================
        // レンダリング (DOMノード & SVGエッジ)
        // ==============================================================================
        function renderAll() {
            renderNodes();
            renderEdges();
            updateTransform();
        }

        function renderNodes() {
            nodesLayer.innerHTML = '';

            canvasData.nodes.forEach(node => {
                const el = document.createElement('div');
                el.className = 'canvas-node';
                el.id = 'dom_' + node.id;
                el.style.left = node.x + 'px';
                el.style.top = node.y + 'px';
                el.style.width = node.width + 'px';
                el.style.height = node.height + 'px';

                if (node.category === 'patient') {
                    // 患者カルテメインカード
                    el.classList.add('patient-card');
                    el.innerHTML = `
                        <div class="card-header" onmousedown="startNodeDrag(event, '${node.id}')">
                            <span class="pat-id-tag">${escapeHtml(node.data.id)}</span>
                            <span style="font-size:0.75rem; color:#93c5fd;">患者カルテノード</span>
                        </div>
                        <div class="card-name">${escapeHtml(node.data.kanja_kanji)}</div>
                        <div class="card-sub">${escapeHtml(node.data.kanja_yomi)} (${escapeHtml(node.data.age)}歳 / ${escapeHtml(node.data.sex)})</div>
                        <div class="pat-stat-row">
                            <span class="pat-stat-chip">📊 検査全件: ${EXAM_RECORDS.length}回</span>
                            <span class="pat-stat-chip">📅 ${EXAM_RECORDS[0]?.date || ''} 〜 ${EXAM_RECORDS[EXAM_RECORDS.length - 1]?.date || ''}</span>
                        </div>
                    `;
                } else if (node.type === 'sticky') {
                    // 付箋メモ
                    el.classList.add('sticky-card');
                    el.innerHTML = `
                        <div class="sticky-header" onmousedown="startNodeDrag(event, '${node.id}')">
                            <span>📝 臨床付箋メモ</span>
                            <button onclick="deleteNode('${node.id}')" style="background:none;border:none;cursor:pointer;color:inherit;font-size:0.8rem;">✕</button>
                        </div>
                        <textarea class="sticky-textarea" oninput="updateStickyText('${node.id}', this.value)" placeholder="メモを入力...">${escapeHtml(node.text || '')}</textarea>
                    `;
                } else if (node.category === 'notice') {
                    // 案内通知カード (該当検査なし・患者未登録)
                    el.classList.add('notice-card');
                    el.innerHTML = `
                        <div class="card-header" style="background:rgba(234, 88, 12, 0.25); border-bottom:1px solid rgba(234, 88, 12, 0.45); padding:10px 14px; font-weight:700; color:#fdba74; display:flex; align-items:center; gap:6px;">
                            <span>⚠️</span> <span>${escapeHtml(node.data.title)}</span>
                        </div>
                        <div style="padding:14px; font-size:0.86rem; color:#e2e8f0; line-height:1.6;">
                            ${escapeHtml(node.data.message)}
                            <div style="margin-top:14px;">
                                <button type="button" onclick="setCategoryFilter('all')" class="btn-tool btn-tool-primary" style="font-size:0.78rem; padding:4px 10px;">🌐 全検査を表示する</button>
                            </div>
                        </div>
                    `;
                } else {
                    // 検査ノード
                    el.classList.add('exam-card');
                    const exam = node.data;

                    // ⭐ 本日受診・検査カードの判定
                    const isTodayExam = Boolean(exam.date && TODAY_STR && exam.date.replace(/[-\/]/g, '') === TODAY_STR.replace(/[-\/]/g, ''));
                    if (isTodayExam) {
                        el.classList.add('is-today-exam');
                    }
                    const todayBadge = isTodayExam ? '<span class="badge-today-pulse">⭐ 本日</span> ' : '';
                    const headerClass = 'header-' + (exam.category || 'other');

                    // 各種ビューワー起動URL生成
                    const pName = PATIENT_INFO?.kanja_kanji || '';
                    const pId = PATIENT_INFO?.id || '';
                    const kensaName = exam.kensamei || '';
                    const examDate = exam.date || '';
                    const fld = exam.folder || '';

                    const dcmUrl = exam.study_id
                        ? `dicom_view.php?study_id=${encodeURIComponent(exam.study_id)}`
                        : `dicom_view.php?folder=${encodeURIComponent(fld)}&name=${encodeURIComponent(pName)}&id=${encodeURIComponent(pId)}&kensa=${encodeURIComponent(kensaName)}&date=${encodeURIComponent(examDate)}`;
                    const ecgUrl = exam.study_id
                        ? `ecg_view.php?study_id=${encodeURIComponent(exam.study_id)}`
                        : `ecg_view.php?folder=${encodeURIComponent(fld)}&file=${encodeURIComponent(fld)}&name=${encodeURIComponent(pName)}&id=${encodeURIComponent(pId)}&kensa=${encodeURIComponent(kensaName)}&date=${encodeURIComponent(examDate)}`;

                    const isEcg = exam.is_ecg || exam.category === 'ecg' || (exam.kensamei && exam.kensamei.indexOf('心電図') !== -1);
                    const isTomo = Boolean(exam.is_tomo);
                    const viewerBtn = isEcg 
                        ? `<a href="${ecgUrl}" target="_blank" class="btn-view-tab btn-view-ecg" title="心電図Webビューワーを別タブで起動">📈 心電図 ↗</a>`
                        : (isTomo
                            ? `<button type="button" onclick="triggerLaunch('${escapeJs(fld)}', 'HTTP_DICOM', ${exam.study_id || 0})" class="btn-view-tab btn-view-dcm" style="cursor:pointer;border:none;" title="ローカルPCの RSViewer で断層画像を開く（共有フォルダ不要）">🌀 RSViewer起動</button> <a href="${dcmUrl}" target="_blank" class="btn-view-tab" style="padding:3px 7px;background:rgba(255,255,255,0.08);color:#cbd5e1;" title="Webビューワで簡易表示">↗</a>`
                            : `<a href="${dcmUrl}" target="_blank" class="btn-view-tab btn-view-dcm" title="DICOM Webビューワーを別タブで起動">👁️ DICOM ↗</a>`);

                    // 検査実施時の年齢（「当時」表記なし） & 担当医
                    const ageVal = exam.age_at_exam || exam.age || '';
                    const docDisplay = exam.doctor ? `<span class="exam-doctor-chip" title="担当医師">👨‍⚕️ ${escapeHtml(exam.doctor)}</span>` : '';

                    el.innerHTML = `
                        <div class="exam-card-header ${headerClass}" onmousedown="startNodeDrag(event, '${node.id}')">
                            <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:145px;" title="${escapeHtml(exam.kensamei)}">
                                ${todayBadge}${getCategoryIcon(exam.category)} ${escapeHtml(exam.kensamei)}
                            </span>
                            <div class="exam-header-right">
                                <span class="exam-date-badge">${escapeHtml(exam.date)}</span>
                                ${ageVal ? `<span class="exam-age-badge" title="検査時の年齢">${escapeHtml(ageVal)}歳</span>` : ''}
                            </div>
                        </div>
                        <div class="exam-card-body">
                            ${docDisplay ? `<div class="exam-meta-row">${docDisplay}</div>` : ''}
                            <div class="exam-sindan-box" title="${escapeHtml(exam.sindan1 || '')}">
                                <strong>所見:</strong> ${escapeHtml(exam.sindan1 || '特記所見なし')}
                            </div>
                            <div class="exam-folder-hint" title="${escapeHtml(exam.folder)}">
                                📁 ${escapeHtml(exam.folder)}
                            </div>
                        </div>
                        <div class="exam-card-footer">
                            ${viewerBtn}
                        </div>
                    `;
                }

                nodesLayer.appendChild(el);
            });
        }

        function renderEdges() {
            svgEdgesGroup.innerHTML = '';

            const nodeMap = {};
            canvasData.nodes.forEach(n => nodeMap[n.id] = n);

            canvasData.edges.forEach(edge => {
                const fromNode = nodeMap[edge.fromNode];
                const toNode = nodeMap[edge.toNode];
                if (!fromNode || !toNode) return;

                const startX = fromNode.x + fromNode.width;
                const startY = fromNode.y + fromNode.height / 2;
                const endX   = toNode.x;
                const endY   = toNode.y + toNode.height / 2;

                const dx = Math.max(80, Math.abs(endX - startX) * 0.45);
                const cp1x = startX + dx;
                const cp1y = startY;
                const cp2x = endX - dx;
                const cp2y = endY;

                const pathData = `M ${startX} ${startY} C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${endX} ${endY}`;

                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', pathData);
                path.setAttribute('class', 'canvas-edge');
                path.setAttribute('marker-end', 'url(#arrowhead)');
                svgEdgesGroup.appendChild(path);

                if (edge.label) {
                    const midX = (startX + endX) / 2;
                    const midY = (startY + endY) / 2;

                    const labelGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');

                    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    text.setAttribute('x', midX);
                    text.setAttribute('y', midY);
                    text.setAttribute('class', 'canvas-edge-label-text');
                    text.textContent = edge.label;

                    const bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                    const textWidth = Math.max(60, edge.label.length * 11 + 14);
                    bg.setAttribute('x', midX - textWidth / 2);
                    bg.setAttribute('y', midY - 11);
                    bg.setAttribute('width', textWidth);
                    bg.setAttribute('height', 20);
                    bg.setAttribute('class', 'canvas-edge-label-bg');

                    labelGroup.appendChild(bg);
                    labelGroup.appendChild(text);
                    svgEdgesGroup.appendChild(labelGroup);
                }
            });
        }

        function getCategoryIcon(cat) {
            switch(cat) {
                case 'endoscopy': return '🔬';
                case 'ecg':       return '❤️';
                case 'us':        return '📡';
                case 'xray':      return '🩻';
                default:          return '📋';
            }
        }

        // ==============================================================================
        // パン (移動) & ズーム (拡大縮小) インタラクション
        // ==============================================================================
        function updateTransform() {
            world.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
            hudZoomVal.textContent = Math.round(scale * 100) + '%';
        }

        viewport.addEventListener('mousedown', e => {
            if (e.target === viewport || e.target === world || e.target.tagName === 'svg' || e.target.id === 'svg-edges-group' || e.target.id === 'canvas-ruler-layer' || e.target.classList.contains('time-guide-line')) {
                isPanning = true;
                viewport.classList.add('panning');
                startPanMouseX = e.clientX - panX;
                startPanMouseY = e.clientY - panY;
            }
        });

        window.addEventListener('mousemove', e => {
            if (isPanning) {
                panX = e.clientX - startPanMouseX;
                panY = e.clientY - startPanMouseY;
                updateTransform();
            } else if (isDraggingNode && draggedNodeId) {
                const node = canvasData.nodes.find(n => n.id === draggedNodeId);
                if (node) {
                    node.x = (e.clientX - panX) / scale - dragOffset.x;
                    node.y = (e.clientY - panY) / scale - dragOffset.y;

                    const dom = document.getElementById('dom_' + node.id);
                    if (dom) {
                        dom.style.left = node.x + 'px';
                        dom.style.top = node.y + 'px';
                    }
                    renderEdges();
                }
            }
        });

        window.addEventListener('mouseup', () => {
            if (isPanning) {
                isPanning = false;
                viewport.classList.remove('panning');
            }
            if (isDraggingNode) {
                isDraggingNode = false;
                if (draggedNodeId) {
                    const dom = document.getElementById('dom_' + draggedNodeId);
                    if (dom) dom.classList.remove('dragging');
                    draggedNodeId = null;
                }
            }
        });

        viewport.addEventListener('wheel', e => {
            e.preventDefault();
            const zoomFactor = e.deltaY < 0 ? 1.08 : 0.92;
            const newScale = Math.min(Math.max(scale * zoomFactor, 0.15), 3.0);

            const mouseX = e.clientX;
            const mouseY = e.clientY;

            panX = mouseX - (mouseX - panX) * (newScale / scale);
            panY = mouseY - (mouseY - panY) * (newScale / scale);
            scale = newScale;

            updateTransform();
        }, { passive: false });

        function zoomIn() {
            scale = Math.min(scale * 1.18, 3.0);
            updateTransform();
        }
        function zoomOut() {
            scale = Math.max(scale * 0.85, 0.15);
            updateTransform();
        }
        function resetZoom() {
            scale = 1.0;
            updateTransform();
        }
        function fitToView() {
            if (canvasData.nodes.length === 0) return;
            let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            canvasData.nodes.forEach(n => {
                minX = Math.min(minX, n.x);
                minY = Math.min(minY, n.y);
                maxX = Math.max(maxX, n.x + n.width);
                maxY = Math.max(maxY, n.y + n.height);
            });

            const margin = 80;
            const w = maxX - minX + margin * 2;
            const h = maxY - minY + margin * 2;
            const vpW = viewport.clientWidth;
            const vpH = viewport.clientHeight;

            scale = Math.min(Math.max(Math.min(vpW / w, vpH / h), 0.20), 1.1);
            panX = (vpW - (maxX + minX) * scale) / 2;
            panY = (vpH - (maxY + minY) * scale) / 2;
            updateTransform();
        }

        // ==============================================================================
        // ノードドラッグ操作
        // ==============================================================================
        function startNodeDrag(e, nodeId) {
            e.stopPropagation();
            isDraggingNode = true;
            draggedNodeId = nodeId;

            const node = canvasData.nodes.find(n => n.id === nodeId);
            if (node) {
                const mouseWorldX = (e.clientX - panX) / scale;
                const mouseWorldY = (e.clientY - panY) / scale;
                dragOffset.x = mouseWorldX - node.x;
                dragOffset.y = mouseWorldY - node.y;

                const dom = document.getElementById('dom_' + nodeId);
                if (dom) dom.classList.add('dragging');
            }
        }

        // ==============================================================================
        // 付箋メモ機能
        // ==============================================================================
        let stickyCount = 1;
        function addStickyNote() {
            const centerX = (viewport.clientWidth / 2 - panX) / scale;
            const centerY = (viewport.clientHeight / 2 - panY) / scale;

            const newId = "sticky_" + Date.now();
            const noteNode = {
                id: newId,
                type: "sticky",
                x: centerX - 120,
                y: centerY - 80,
                width: 240,
                height: 160,
                color: "3",
                text: "【医師メモ " + stickyCount++ + "】\n・次回フォロー予定:\n・要確認部位:",
                category: "sticky"
            };

            canvasData.nodes.push(noteNode);
            renderAll();
            updateExpandButtonUI();
            showToast("📝 付箋メモを追加しました（ドラッグで移動可能）");
        }

        function updateStickyText(nodeId, newText) {
            const node = canvasData.nodes.find(n => n.id === nodeId);
            if (node) node.text = newText;
        }

        function deleteNode(nodeId) {
            canvasData.nodes = canvasData.nodes.filter(n => n.id !== nodeId);
            canvasData.edges = canvasData.edges.filter(e => e.fromNode !== nodeId && e.toNode !== nodeId);
            renderAll();
            updateExpandButtonUI();
        }

        function autoLayoutSwimlanes() {
            switchLayoutMode('swimlanes');
        }
        function autoLayoutTimeline() {
            switchLayoutMode('proportional');
        }

        // ==============================================================================
        // JSON Canvas Specification (v1.0) エクスポート
        // ==============================================================================
        function generateJsonCanvasSpec() {
            const specNodes = canvasData.nodes.map(n => {
                let markdownText = "";
                if (n.category === 'patient') {
                    markdownText = `## 🏥 患者: ${n.data.kanja_kanji} 様\n- **ID**: ${n.data.id}\n- **よみ**: ${n.data.kanja_yomi}\n- **属性**: ${n.data.age}歳 / ${n.data.sex}\n- **総検査数**: ${EXAM_RECORDS.length}件`;
                } else if (n.type === 'sticky') {
                    markdownText = n.text || "";
                } else {
                    const e = n.data;
                    const ageTxt = e.age_at_exam ? `\n- **実施時年齢**: ${e.age_at_exam}歳` : (e.age ? `\n- **実施時年齢**: ${e.age}歳` : '');
                    const docTxt = e.doctor ? `\n- **担当医**: ${e.doctor}` : '';
                    markdownText = `### ${getCategoryIcon(e.category)} ${e.kensamei} (${e.date})${ageTxt}${docTxt}\n**所見**: ${e.sindan1 || '特記なし'}\n\n- 連番: \`${e.renban}\`\n- パス: \`${e.folder}\``;
                }

                return {
                    id: n.id,
                    type: "text",
                    x: Math.round(n.x),
                    y: Math.round(n.y),
                    width: Math.round(n.width),
                    height: Math.round(n.height),
                    color: n.color || "6",
                    text: markdownText
                };
            });

            const specEdges = canvasData.edges.map(e => ({
                id: e.id,
                fromNode: e.fromNode,
                fromSide: e.fromSide || "right",
                fromEnd: e.fromEnd || "none",
                toNode: e.toNode,
                toSide: e.toSide || "left",
                toEnd: e.toEnd || "arrow",
                color: e.color || "6",
                label: e.label || undefined
            }));

            return {
                nodes: specNodes,
                edges: specEdges
            };
        }

        function exportJsonCanvas() {
            const spec = generateJsonCanvasSpec();
            const jsonStr = JSON.stringify(spec, null, 2);
            const blob = new Blob([jsonStr], { type: "application/json" });
            const url = URL.createObjectURL(blob);

            const a = document.createElement('a');
            const safeName = (PATIENT_INFO?.kanja_kanji || 'patient').replace(/[\s　]+/g, '_');
            a.download = `${PATIENT_INFO?.id || '000'}_${safeName}_timeline.canvas`;
            a.href = url;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            showToast("💾 Obsidian対応の .canvas ファイルをダウンロードしました");
        }

        function showJsonModal() {
            const spec = generateJsonCanvasSpec();
            document.getElementById('jsonPreviewContent').textContent = JSON.stringify(spec, null, 2);
            document.getElementById('jsonModal').style.display = 'flex';
        }

        function closeJsonModal() {
            document.getElementById('jsonModal').style.display = 'none';
        }

        function copyJsonToClipboard() {
            const spec = generateJsonCanvasSpec();
            navigator.clipboard.writeText(JSON.stringify(spec, null, 2)).then(() => {
                showToast("📋 JSONをクリップボードにコピーしました");
            });
        }

        // ==============================================================================
        // ビューワ連携・トリガー非同期送信
        // ==============================================================================
        function triggerLaunch(folder, launchMode, studyId = 0) {
            showToast(`🚀 RSViewer を起動しています...`);

            const formData = new FormData();
            formData.append('action', 'launch');
            formData.append('study_id', String(studyId || 0));
            formData.append('folder', folder || '');
            formData.append('launch_mode', launchMode || 'HTTP_DICOM');

            fetch('canvas_view.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(`✅ ${data.message}`);
                } else {
                    showToast(`⚠️ エラー: ${data.message}`);
                }
            })
            .catch(err => {
                showToast(`❌ 通信エラー: ${err.message}`);
            });
        }

        function copyPath(text) {
            navigator.clipboard.writeText(text).then(() => {
                showToast("📁 DICOMフォルダパスをコピーしました");
            }).catch(() => {
                showToast("⚠️ コピーに失敗しました");
            });
        }

        function showToast(msg, duration = 3000, type = 'info') {
            const toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.className = 'show' + (type === 'warning' ? ' toast-warning' : '');
            clearTimeout(toast._timer);
            toast._timer = setTimeout(() => {
                toast.className = toast.className.replace(/\bshow\b/, '').replace(/\btoast-warning\b/, '').trim();
            }, duration);
        }

        function toggleTheme() {
            const body = document.body;
            const isDark = body.getAttribute('data-theme') === 'dark';
            body.setAttribute('data-theme', isDark ? 'light' : 'dark');
            document.getElementById('theme-icon').textContent = isDark ? '🌙' : '☀️';
            showToast(isDark ? '☀️ 明るいモードに変更しました' : '🌙 ダークモードに変更しました');
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m]);
        }
        function escapeJs(str) {
            if (!str) return '';
            return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
        }

        // Escキー押下でモーダル閉じる通知を親ウィンドウに送信
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ action: 'closeCanvas' }, '*');
                }
            }
        });

        // ==============================================================================
        // 初期化実行 (ピル生成、指定種別・レイアウト適用、画面フィット)
        // ==============================================================================
        let effectiveCategory = INITIAL_CATEGORY;
        const initialCounts = { all: EXAM_RECORDS.length };
        EXAM_RECORDS.forEach(e => {
            const cat = e.category || 'other';
            initialCounts[cat] = (initialCounts[cat] || 0) + 1;
        });

        let fallbackNotice = null;
        if (effectiveCategory !== 'all' && (!initialCounts[effectiveCategory] || initialCounts[effectiveCategory] === 0)) {
            fallbackNotice = `指定された検査種別 (${INITIAL_MODALITY_RAW}) の記録はありませんでした（全件を表示します）`;
            effectiveCategory = 'all';
        }

        selectedCategory = effectiveCategory;
        initFilterPills();
        switchLayoutMode(INITIAL_LAYOUT);
        setTimeout(() => {
            fitToView();
            if (fallbackNotice) {
                showToast(fallbackNotice, 4000, 'warning');
            } else if (INITIAL_CATEGORY !== 'all') {
                const catLabels = { endoscopy: '内視鏡', ecg: '心電図', us: '超音波', xray: '胸部XP', other: 'その他' };
                showToast(`🔍 検査種別 [${catLabels[INITIAL_CATEGORY] || INITIAL_CATEGORY}] で絞り込み表示中 (${initialCounts[INITIAL_CATEGORY] || 0}件)`, 3000);
            }
        }, 120);
    </script>
</body>
</html>
