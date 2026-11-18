<?php
declare(strict_types=1);
// ==============================================================================
// RS-Next 2×4 検査横断画像比較・印刷システム (print_view.php)
// - UTF-8 PostgreSQL (rsnext_db) & マルチSSDボリューム完全対応
// - オリンパス内視鏡 自動O社フィット & IEE特殊光強調 & 同一画像複製比較
// ==============================================================================

require_once __DIR__ . '/lib/bootstrap.php';

$raw_patient_id = (string)($_GET['patient_id'] ?? $_GET['id'] ?? '');
$patient_id     = rsnext_normalize_patient_id($raw_patient_id) ?? trim($raw_patient_id);
$passed_name    = (string)($_GET['name'] ?? '');

$patient_info = null;
$exam_list    = [];
$db_error     = '';

if ($patient_id !== '') {
    try {
        $pdo = rsnext_pdo();

        $stmtPat = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
        $stmtPat->execute([$patient_id]);
        $patRow = $stmtPat->fetch();

        if ($patRow) {
            $birthStr = (string)($patRow['birth_date'] ?? '');
            $patient_info = [
                'id'           => (string)$patRow['patient_id'],
                'kanja_kanji'  => (string)$patRow['kanja_kanji'],
                'kanja_yomi'   => (string)$patRow['kanja_yomi'],
                'sex'          => (string)$patRow['sex'],
                'age'          => rsnext_calc_age($birthStr),
                'birth'        => str_replace('-', '/', $birthStr),
                'birth_wareki' => rsnext_format_wareki_birth($birthStr),
            ];
        }

        $stmtExams = $pdo->prepare(
            "SELECT s.*, mp.icon_emoji
             FROM studies s
             LEFT JOIN modality_profiles mp ON mp.profile_id = s.profile_id
             WHERE s.patient_id = ? AND NOT s.is_deleted
               AND s.primary_file_type IN ('DICOM', 'IMAGE')
             ORDER BY s.study_date DESC, s.study_time DESC NULLS LAST, s.study_id DESC"
        );
        $stmtExams->execute([$patient_id]);
        $rows = $stmtExams->fetchAll();

        $catLabels = [
            'endoscopy' => ['label' => '内視鏡', 'color' => '#10b981'],
            'us'        => ['label' => '超音波', 'color' => '#0284c7'],
            'xray'      => ['label' => 'XP/透視', 'color' => '#8b5cf6'],
            'other'     => ['label' => '一般画像', 'color' => '#64748b'],
        ];

        foreach ($rows as $r) {
            $cat = (string)($r['display_category'] ?: 'other');
            $cInfo = $catLabels[$cat] ?? $catLabels['other'];
            $absDir = rsnext_resolve_study_dir((string)$r['volume_id'], (string)$r['rel_path']);
            $cleanDate = str_replace('-', '/', (string)$r['study_date']);

            $exam_list[] = [
                'renban'      => (string)($r['legacy_renban'] ?: $r['study_id']),
                'study_id'    => (int)$r['study_id'],
                'kensamei'    => (string)$r['kensamei'],
                'category'    => $cat,
                'cat_label'   => $cInfo['label'],
                'badge_color' => (string)($r['badge_color'] ?: $cInfo['color']),
                'date'        => $cleanDate,
                'raw_date'    => $cleanDate,
                'folder'      => $absDir,
                'sindan'      => (string)($r['findings'] ?? ''),
                'doctor'      => (string)($r['doctor'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }
}

if (!$patient_info) {
    $patient_info = [
        'id'           => $patient_id ?: '00000',
        'kanja_kanji'  => $passed_name ?: '患者未指定',
        'kanja_yomi'   => '',
        'sex'          => '',
        'age'          => '',
        'birth'        => '',
        'birth_wareki' => '',
    ];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2×4 画像印刷 - <?= htmlspecialchars($patient_info['kanja_kanji'] ? "{$patient_info['kanja_kanji']} 様" : "RS-Next", ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --bg-workspace: #090d16;
            --bg-toolbar: #0f172a;
            --border-color: #334155;
            --accent-green: #10b981;
            --accent-blue: #38bdf8;
            --sheet-width: 210mm;
            --sheet-height: 297mm;
            --sheet-zoom: 1;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            width: 100%;
            height: 100%;
            background-color: var(--bg-workspace);
            color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", "Hiragino Kaku Gothic ProN", "Meiryo", sans-serif;
            overflow-x: auto;
            overflow-y: auto;
        }

        /* --------------------------------------------------------------------------
           上部固定ツールバー (画面操作専用・印刷時は非表示)
        -------------------------------------------------------------------------- */
        .workspace-toolbar {
            position: sticky;
            top: 0;
            left: 0;
            right: 0;
            min-height: 48px;
            background: linear-gradient(180deg, #111827 0%, #0b0f19 100%);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 6px 12px;
            padding: 5px 16px;
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
        }

        .toolbar-left, .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toolbar-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            font-weight: 700;
            color: #e2e8f0;
        }

        .tool-btn {
            background: #1e293b;
            color: #f1f5f9;
            border: 1px solid var(--border-color);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .tool-btn:hover {
            background: #334155;
            border-color: #64748b;
        }

        .tool-btn-primary {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            border-color: #10b981;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.35);
        }

        .tool-btn-primary:hover {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.5);
            transform: translateY(-1px);
        }

        /* 全8枠に画像が揃った際の左上「A4印刷」ボタン強調アニメーション */
        .tool-btn-primary.is-ready-8 {
            background: linear-gradient(135deg, #10b981 0%, #059669 55%, #047857 100%);
            border: 1.5px solid #fde047;
            color: #ffffff;
            animation: printBtnReadyPulse 1.55s cubic-bezier(0.4, 0, 0.2, 1) infinite;
            z-index: 5;
        }

        .tool-btn-primary.is-ready-8::after {
            content: '';
            position: absolute;
            top: -20%;
            bottom: -20%;
            left: 0;
            width: 55%;
            background: linear-gradient(115deg, transparent 15%, rgba(255, 255, 255, 0.52) 50%, transparent 85%);
            transform: translateX(-160%);
            animation: printBtnReadySheen 2.3s ease-in-out infinite;
            pointer-events: none;
        }

        .tool-btn-primary.is-ready-8 .print-btn-icon {
            display: inline-block;
            animation: printBtnIconPop 1.55s ease-in-out infinite;
        }

        @keyframes printBtnReadyPulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(253, 224, 71, 0.8), 0 0 14px rgba(16, 185, 129, 0.72);
            }
            50% {
                transform: scale(1.055);
                box-shadow: 0 0 0 7px rgba(253, 224, 71, 0), 0 0 26px rgba(52, 211, 153, 0.98);
            }
        }

        @keyframes printBtnReadySheen {
            0% { transform: translateX(-160%); }
            55%, 100% { transform: translateX(280%); }
        }

        @keyframes printBtnIconPop {
            0%, 100% { transform: scale(1) rotate(0deg); }
            35% { transform: scale(1.18) rotate(-6deg); }
            65% { transform: scale(1.12) rotate(4deg); }
        }

        .tool-btn-danger {
            background: rgba(239, 68, 68, 0.15);
            border-color: #ef4444;
            color: #fca5a5;
        }

        .tool-btn-danger:hover {
            background: #dc2626;
            color: #ffffff;
        }

        .scale-control {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 0.8rem;
            color: #cbd5e1;
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid var(--border-color);
            padding: 4px 10px;
            border-radius: 6px;
        }

        .scale-control input[type="range"] {
            width: 180px;
            min-width: 180px;
            flex-shrink: 0;
            cursor: pointer;
            accent-color: var(--accent-green);
        }

        .scale-fit-btn {
            background: #1e293b;
            color: #cbd5e1;
            border: 1px solid #475569;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 700;
            cursor: pointer;
            line-height: 1.3;
            transition: all 0.15s;
            white-space: nowrap;
        }

        .scale-fit-btn:hover {
            background: #334155;
            color: #ffffff;
            border-color: #64748b;
        }

        .scale-fit-btn.is-active {
            background: rgba(16, 185, 129, 0.22);
            border-color: var(--accent-green);
            color: #6ee7b7;
        }

        /* --------------------------------------------------------------------------
           メインワークスペース（用紙表示ステージ）
        -------------------------------------------------------------------------- */
        .workspace-stage {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 10px 16px;
            min-height: calc(100vh - 48px);
            transition: padding-right 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @media (min-width: 960px) {
            .workspace-stage.drawer-open {
                padding-right: 456px;
            }
        }

        .sheet-zoom-wrapper {
            position: relative;
            flex-shrink: 0;
            margin: auto;
        }

        /* --------------------------------------------------------------------------
           A4縦 印刷シートコンテナ
        -------------------------------------------------------------------------- */
        .print-sheet {
            width: var(--sheet-width);
            height: var(--sheet-height);
            background-color: #ffffff;
            color: #0f172a;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            padding: 5mm; /* 用紙余白 最小限 (5mm) */
            display: flex;
            flex-direction: column;
            transform-origin: top left;
            transform: scale(var(--sheet-zoom));
            position: relative;
            flex-shrink: 0;
        }

        /* 患者情報ヘッダー (最上部) */
        .sheet-header {
            height: 20px;
            min-height: 20px;
            border-bottom: 1.5px solid #0f172a;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 4px 2px;
            font-size: 11px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 3px;
            line-height: 1;
            flex-shrink: 0;
        }

        .pat-main-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pat-id-tag {
            font-family: monospace;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .pat-name-text {
            font-size: 12.5px;
            font-weight: 700;
        }

        .pat-sub-info {
            font-size: 10.5px;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sheet-meta-info {
            font-size: 10px;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* --------------------------------------------------------------------------
           2×4 画像グリッド (横2列 × 縦4行 ＝ 8枠)
        -------------------------------------------------------------------------- */
        .sheet-grid {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(4, 1fr);
            gap: 3.5px; /* 紙面余白を詰めて画像を最大化 */
            min-height: 0;
        }

        /* 各画像セル枠（枠上オーバーレイパネルを表示するため overflow: visible） */
        .grid-cell {
            border: 1px solid #94a3b8;
            border-radius: 2px;
            background: #000000;
            display: flex;
            flex-direction: column;
            overflow: visible;
            position: relative;
            min-height: 0;
            transition: border-color 0.12s, box-shadow 0.12s;
        }

        .grid-cell:hover {
            z-index: 40;
            border-color: #38bdf8;
            box-shadow: 0 0 0 1.5px rgba(56, 189, 248, 0.72);
        }

        /* 画像表示ビューポート (ドラッグ＆ズーム領域・枠内クリップ専用) */
        .cell-viewport {
            flex: 1;
            min-height: 0;
            position: relative;
            overflow: hidden;
            background: #000000;
            border-radius: 1.5px 1.5px 0 0;
            cursor: grab;
            user-select: none;
            -webkit-user-select: none;
        }

        .cell-viewport.grabbing {
            cursor: grabbing;
        }

        /* 元画像を一切トリミング(object-fit:cover)せず、100%全画素を保持したまま枠内配置 */
        .cell-viewport img {
            position: absolute;
            top: 50%;
            left: 50%;
            width: auto;
            height: 100%;
            max-width: none;
            max-height: none;
            display: block;
            pointer-events: none;
            transform-origin: center center;
            transform: translate(-50%, -50%) scale(1);
            will-change: transform;
        }

        /* 枠の上辺外側にオーバーレイする最終配置調整パネル (画像本体に一切被らない・印刷時非表示) */
        .cell-tools {
            position: absolute;
            bottom: calc(100% - 1px);
            right: -1px;
            display: flex;
            align-items: center;
            gap: 4px;
            background: rgba(15, 23, 42, 0.96);
            backdrop-filter: blur(6px);
            padding: 5px 7px;
            border-radius: 6px 6px 0 6px;
            border: 1.5px solid #38bdf8;
            box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.55);
            opacity: 0;
            pointer-events: none;
            transform: scale(var(--tool-inv-scale, 1));
            transform-origin: bottom right;
            transition: opacity 0.14s ease-in-out;
            z-index: 45;
            white-space: nowrap;
        }

        /* 画像から上辺パネルへマウスを移動する際のホバー途切れ防止ブリッジ */
        .cell-tools::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: -9px;
            height: 11px;
        }

        .grid-cell:hover .cell-tools {
            opacity: 1;
            pointer-events: auto;
        }

        .cell-tool-slot-tag {
            font-family: monospace;
            font-size: 0.78rem;
            font-weight: 800;
            color: #38bdf8;
            padding: 0 5px 0 1px;
            border-right: 1px solid #334155;
            margin-right: 1px;
        }

        .cell-tool-btn {
            background: rgba(30, 41, 59, 0.92);
            border: 1px solid rgba(148, 163, 184, 0.28);
            color: #f8fafc;
            font-size: 0.84rem;
            font-weight: 700;
            padding: 4px 7px;
            min-width: 26px;
            min-height: 26px;
            border-radius: 4px;
            cursor: pointer;
            line-height: 1.1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            transition: all 0.12s;
        }

        .cell-tool-btn:hover {
            background: #334155;
            border-color: #7dd3fc;
            color: #ffffff;
        }

        .cell-tool-btn.btn-del:hover {
            background: #ef4444;
            border-color: #f87171;
            color: #ffffff;
        }

        .cell-tool-btn.btn-highlight {
            color: #7dd3fc;
            border-color: rgba(56, 189, 248, 0.45);
            font-weight: 800;
        }

        /* オリンパス内視鏡画像専用：O社フィット ⇔ 標準全体配置 1クリック切替ボタン */
        .cell-tool-btn.btn-olympus-toggle {
            font-weight: 800;
            padding: 4px 7px;
            letter-spacing: -0.01em;
        }

        .cell-tool-btn.btn-olympus-toggle.is-olympus {
            background: rgba(16, 185, 129, 0.26);
            border-color: #10b981;
            color: #6ee7b7;
        }

        .cell-tool-btn.btn-olympus-toggle.is-olympus:hover {
            background: #059669;
            border-color: #34d399;
            color: #ffffff;
        }

        .cell-tool-btn.btn-olympus-toggle.is-full {
            background: rgba(2, 132, 199, 0.26);
            border-color: #38bdf8;
            color: #bae6fd;
        }

        .cell-tool-btn.btn-olympus-toggle.is-full:hover {
            background: #0284c7;
            border-color: #7dd3fc;
            color: #ffffff;
        }

        /* 枠番号バッジ (画面操作用) */
        .cell-badge {
            position: absolute;
            top: 3px;
            left: 3px;
            background: rgba(0, 0, 0, 0.75);
            color: #e2e8f0;
            font-family: monospace;
            font-size: 9.5px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            pointer-events: none;
            z-index: 9;
        }

        /* 病巣強調モード表示バッジ (DICOMビューワ側で指定された場合のみ表示) */
        .cell-iee-badge {
            position: absolute;
            bottom: 4px;
            right: 4px;
            background: rgba(15, 23, 42, 0.88);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #f8fafc;
            font-size: 9px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
            z-index: 8;
            pointer-events: none;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.6);
        }

        /* 空き枠表示 */
        .cell-empty {
            flex: 1;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            color: #94a3b8;
            cursor: pointer;
            border: 1.5px dashed #cbd5e1;
            margin: 2px;
            border-radius: 3px;
            transition: all 0.15s;
        }

        .cell-empty:hover {
            background: #f1f5f9;
            border-color: #0284c7;
            color: #0284c7;
        }

        .cell-empty-icon {
            font-size: 1.5rem;
            opacity: 0.7;
        }

        .cell-empty-text {
            font-size: 11px;
            font-weight: 600;
        }

        /* 画像下部キャプション枠 (検査名・撮影日時・所見メモ) */
        .cell-caption {
            height: 17px;
            min-height: 17px;
            background: #ffffff;
            border-top: 1px solid #94a3b8;
            padding: 0 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 9.5px;
            font-weight: 600;
            color: #0f172a;
            flex-shrink: 0;
            line-height: 1;
            overflow: hidden;
            white-space: nowrap;
        }

        .caption-text {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            outline: none;
            cursor: text;
        }

        .caption-text:focus {
            background: #fef08a;
            border-radius: 2px;
        }

        /* --------------------------------------------------------------------------
           他検査画像ピッカー・スライドドロワー (画面専用・印刷時非表示)
        -------------------------------------------------------------------------- */
        .picker-drawer {
            position: fixed;
            top: 48px;
            right: 0;
            bottom: 0;
            width: 440px;
            background: #0f172a;
            border-left: 1px solid var(--border-color);
            box-shadow: -6px 0 20px rgba(0, 0, 0, 0.5);
            display: flex;
            flex-direction: column;
            z-index: 90;
            transform: translateX(100%);
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
        }

        .picker-drawer.open {
            transform: translateX(0);
        }

        .drawer-header {
            padding: 10px 14px;
            background: #111827;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .drawer-header h3 {
            font-size: 0.92rem;
            color: #f1f5f9;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .drawer-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .drawer-close-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 1.1rem;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
            line-height: 1;
        }

        .drawer-close-btn:hover {
            color: #ffffff;
            background: #1e293b;
        }

        .drawer-subtext {
            padding: 8px 14px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            font-size: 0.78rem;
            color: #94a3b8;
            flex-shrink: 0;
        }

        .drawer-body {
            flex: 1 1 0px;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        /* 視認性の高いカスタムスクロールバー */
        .drawer-body::-webkit-scrollbar {
            width: 8px;
        }
        .drawer-body::-webkit-scrollbar-track {
            background: #0f172a;
        }
        .drawer-body::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 4px;
        }
        .drawer-body::-webkit-scrollbar-thumb:hover {
            background: #64748b;
        }

        .exam-item-card {
            background: #1e293b;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
            flex-shrink: 0; /* 画像が多くても他の検査名カードが潰れない */
            display: flex;
            flex-direction: column;
            transition: border-color 0.15s;
        }

        .exam-item-card:hover {
            border-color: #475569;
        }

        .exam-item-card.is-open {
            border-color: #38bdf8;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .exam-item-header {
            padding: 9px 12px;
            background: rgba(255, 255, 255, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
            flex-shrink: 0; /* ヘッダーが絶対に潰れない */
            min-height: 38px;
            transition: background 0.15s;
        }

        .exam-item-header:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .exam-item-card.is-open .exam-item-header {
            background: rgba(56, 189, 248, 0.08);
        }

        .exam-item-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #f1f5f9;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .exam-item-badge {
            font-size: 0.68rem;
            padding: 2px 6px;
            border-radius: 3px;
            color: #ffffff;
            font-weight: 700;
            flex-shrink: 0;
            line-height: 1.2;
        }

        .exam-item-date {
            font-size: 0.75rem;
            color: #94a3b8;
            font-family: monospace;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .exam-count-badge {
            font-size: 0.7rem;
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.12);
            padding: 1px 5px;
            border-radius: 3px;
            font-family: monospace;
        }

        .exam-toggle-icon {
            font-size: 0.72rem;
            color: #94a3b8;
            display: inline-block;
            transition: transform 0.18s ease;
        }

        .exam-thumb-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            padding: 10px;
            background: #0b0f19;
            border-top: 1px solid var(--border-color);
        }

        .picker-thumb-card {
            position: relative;
            aspect-ratio: 4 / 3;
            background: #020617;
            border: 1px solid #334155;
            border-radius: 4px;
            overflow: hidden;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .picker-thumb-card:hover {
            border-color: var(--accent-blue);
            transform: scale(1.03);
            z-index: 5;
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.4);
        }

        .picker-thumb-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .picker-thumb-badge {
            position: absolute;
            bottom: 2px;
            right: 2px;
            background: rgba(0, 0, 0, 0.75);
            color: #ffffff;
            font-size: 9px;
            padding: 0 4px;
            border-radius: 2px;
            font-family: monospace;
        }

        /* トースト通知 */
        #printToast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(30px);
            background: rgba(15, 23, 42, 0.95);
            color: #f8fafc;
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            opacity: 0;
            transition: all 0.25s ease;
            pointer-events: none;
            z-index: 200;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        #printToast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        /* --------------------------------------------------------------------------
           🖨️ A4縦 厳密印刷CSS (@media print)
           ※ Paged Mediaでは親が height:auto だと height:100% が 0px に潰れるため、
             A4印刷可能高 (100vh / 287mm) をシート〜グリッド〜各セルまで明示的に確定させる
        -------------------------------------------------------------------------- */
        @media print {
            @page {
                size: A4 portrait;
                margin: 4.5mm; /* 枠外余白を最小限にして画像を最大化 */
            }

            html, body {
                width: 100% !important;
                height: auto !important;
                background: #ffffff !important;
                color: #000000 !important;
                overflow: visible !important;
                padding: 0 !important;
                margin: 0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .no-print {
                display: none !important;
            }

            .workspace-stage {
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                height: 287mm !important;
                height: calc(100vh - 0.5mm) !important;
                min-height: 0 !important;
                display: block !important;
                overflow: hidden !important;
            }

            .sheet-zoom-wrapper {
                width: 100% !important;
                height: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                display: block !important;
            }

            .print-sheet {
                width: 100% !important;
                height: 100% !important;
                min-height: 0 !important;
                max-height: none !important;
                padding: 0 !important;
                margin: 0 !important;
                display: flex !important;
                flex-direction: column !important;
                box-shadow: none !important;
                border: none !important;
                transform: none !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            .sheet-header {
                height: 18px !important;
                min-height: 18px !important;
                flex: 0 0 18px !important;
                border-bottom: 1.5px solid #000000 !important;
                color: #000000 !important;
                margin-bottom: 2.5px !important;
            }

            .sheet-grid {
                flex: 1 1 0px !important;
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                grid-template-rows: repeat(4, minmax(0, 1fr)) !important;
                gap: 2.5px !important;
                width: 100% !important;
                height: calc(100% - 21px) !important;
                min-height: 0 !important;
            }

            .grid-cell {
                display: flex !important;
                flex-direction: column !important;
                width: 100% !important;
                height: 100% !important;
                min-height: 0 !important;
                border: 1px solid #475569 !important;
                background-color: #000000 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                box-shadow: none !important;
                overflow: hidden !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            .cell-viewport {
                flex: 1 1 0px !important;
                width: 100% !important;
                height: calc(100% - 16px) !important;
                min-height: 0 !important;
                position: relative !important;
                overflow: hidden !important;
                background-color: #000000 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .cell-viewport img {
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
                will-change: auto !important;
            }

            .cell-caption {
                height: 16px !important;
                min-height: 16px !important;
                flex: 0 0 16px !important;
                background-color: #ffffff !important;
                border-top: 1px solid #475569 !important;
                color: #000000 !important;
                font-size: 9px !important;
            }

            .cell-badge, .cell-tools, .iee-popover {
                display: none !important;
            }

            .cell-iee-badge {
                border: 1px solid #000000 !important;
                background: rgba(255, 255, 255, 0.85) !important;
                color: #000000 !important;
                box-shadow: none !important;
            }

            .cell-empty {
                flex: 1 1 0px !important;
                height: 100% !important;
                border: 1px dashed #cbd5e1 !important;
                background: #ffffff !important;
            }

            .cell-empty-icon, .cell-empty-text {
                opacity: 0.3 !important;
            }
        }
    </style>
</head>
<body>

    <!-- ----------------------------------------------------------------------
         画面上部ツールバー (印刷時は自動非表示)
    ----------------------------------------------------------------------- -->
    <header class="workspace-toolbar no-print">
        <div class="toolbar-left">
            <button type="button" class="tool-btn tool-btn-primary" id="btnPrintRun" title="A4縦 印刷プレビューを実行 [Ctrl+P]">
                <span class="print-btn-icon">🖨️</span> <strong>印刷する (A4縦)</strong>
            </button>
            <button type="button" class="tool-btn" id="btnToggleDrawer" title="過去の検査画像一覧から選んで追加">
                📂 検査横断ピッカー
            </button>
            <button type="button" class="tool-btn" id="btnResetAllSlots" title="全枠のドラッグ位置・ズームをリセット">
                🔄 全枠リセット
            </button>
            <button type="button" class="tool-btn tool-btn-danger" id="btnClearTray" title="トレイの画像をすべてクリア">
                🗑️ トレイ全消去
            </button>
        </div>

        <div class="toolbar-right">
            <div class="scale-control" id="scaleControlBox" title="画面プレビュー表示倍率（ドラッグまたはホイールで無段階調整）">
                <span>🔍 表示:</span>
                <input type="range" id="zoomSheetRange" min="0.35" max="1.50" step="0.01" value="0.75">
                <span id="zoomSheetVal" style="font-family:monospace; min-width:40px; text-align:right; font-weight:700; color:#f8fafc;">75%</span>
                <button type="button" class="scale-fit-btn is-active" id="btnZoomFit" title="2×4シート全体（全8枠）が1画面に収まる倍率に自動調整">全体</button>
                <button type="button" class="scale-fit-btn" id="btnZoom100" title="実寸 (100%) 表示に切り替え">100%</button>
            </div>
            <a href="index.php" class="tool-btn" style="text-decoration:none; box-sizing:border-box;" title="本日の検査一覧（ホーム）へ移動">
                🏠 本日一覧へ
            </a>
            <button type="button" class="tool-btn" id="btnClosePage" title="閉じる">
                ✕ 閉じる
            </button>
        </div>
    </header>

    <!-- ----------------------------------------------------------------------
         用紙ステージ
    ----------------------------------------------------------------------- -->
    <main class="workspace-stage" id="workspaceStage">
        <div class="sheet-zoom-wrapper" id="sheetZoomWrapper">
            <div class="print-sheet" id="printSheet">
                <!-- 最上部: 患者情報ヘッダー -->
                <div class="sheet-header">
                    <div class="pat-main-info">
                        <span class="pat-id-tag">ID: <?= htmlspecialchars($patient_info['id'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="pat-name-text"><?= htmlspecialchars($patient_info['kanja_kanji'], ENT_QUOTES, 'UTF-8') ?> 様</span>
                        <?php if (!empty($patient_info['kanja_yomi'])): ?>
                            <span style="font-size:10px; color:#475569;">(<?= htmlspecialchars($patient_info['kanja_yomi'], ENT_QUOTES, 'UTF-8') ?>)</span>
                        <?php endif; ?>
                    </div>

                    <div class="pat-sub-info">
                        <?php if (!empty($patient_info['birth_wareki'])): ?>
                            <span>生年月日: <?= htmlspecialchars($patient_info['birth_wareki'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($patient_info['age'])): ?>
                            <span>(<?= htmlspecialchars($patient_info['age'], ENT_QUOTES, 'UTF-8') ?>歳)</span>
                        <?php endif; ?>
                        <?php if (!empty($patient_info['sex'])): ?>
                            <span><?= htmlspecialchars($patient_info['sex'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="sheet-meta-info">
                        <span>印刷日: <?= date('Y/m/d H:i') ?></span>
                        <strong style="color:#0f172a; font-weight:700;">RS-Base 2×4 印刷</strong>
                    </div>
                </div>

                <!-- 2×4 グリッド (8スロット) -->
                <div class="sheet-grid" id="sheetGrid">
                    <!-- JavaScriptで8つのスロットが動的生成されます -->
                </div>
            </div>
        </div>
    </main>

    <!-- ----------------------------------------------------------------------
         他検査画像ピッカー (スライドイン・ドロワー)
    ----------------------------------------------------------------------- -->
    <aside class="picker-drawer no-print" id="pickerDrawer">
        <div class="drawer-header">
            <h3>📂 検査横断 画像ピッカー</h3>
            <div class="drawer-header-actions">
                <button type="button" class="tool-btn" id="btnCollapseAllExams" style="padding:2px 8px; font-size:0.75rem;" title="すべての検査枠を折りたたむ">全閉</button>
                <button type="button" class="drawer-close-btn" id="btnDrawerClose" title="閉じる">✕</button>
            </div>
        </div>
        <div class="drawer-subtext">
            <span>過去の検査をクリックしてサムネイルを表示し、クリックで印刷枠（空きスロット）に追加できます。</span>
        </div>
        <div class="drawer-body" id="drawerBody">
            <!-- 検査カードが動的挿入されます -->
        </div>
    </aside>

    <!-- SVGフィルター定義 (内視鏡画像強調用: GPUハードウェアアクセラレーション) -->
    <svg style="position: absolute; width: 0; height: 0; overflow: hidden; pointer-events: none;" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <!-- ① LCI風・発赤強調 -->
            <filter id="iee-lci" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    1.45 -0.25 -0.15 0 0.04
                   -0.10  1.15 -0.10 0 0.00
                   -0.15 -0.15  1.20 0 0.00
                    0     0     0    1 0" />
                <feComponentTransfer>
                    <feFuncR type="linear" slope="1.15" intercept="-0.04" />
                    <feFuncG type="linear" slope="1.05" intercept="-0.02" />
                    <feFuncB type="linear" slope="1.05" intercept="-0.02" />
                </feComponentTransfer>
            </filter>

            <!-- ② 疑似NBI風・微細血管/ピット強調 -->
            <filter id="iee-nbi" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    0.20  0.65  0.25  0 -0.05
                    0.00  0.75  0.35  0  0.00
                    0.00  0.20  0.85  0  0.05
                    0     0     0     1  0" />
                <feComponentTransfer>
                    <feFuncR type="linear" slope="1.25" intercept="-0.06" />
                    <feFuncG type="linear" slope="1.10" intercept="0.00" />
                    <feFuncB type="linear" slope="1.10" intercept="0.00" />
                </feComponentTransfer>
            </filter>

            <!-- ③ ヘモグロビン血流マップ (IHb) -->
            <filter id="iee-ihb" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    1.80 -0.80 -0.10 0 0.10
                   -0.40  1.40 -0.10 0 -0.05
                   -0.80 -0.20  1.80 0 -0.10
                    0     0     0    1 0" />
            </filter>

            <!-- ④ 凹凸・構造強調 (Texture) -->
            <filter id="iee-texture" color-interpolation-filters="sRGB">
                <feConvolveMatrix order="3" kernelMatrix="
                   -0.3 -0.6 -0.3
                   -0.6  4.6 -0.6
                   -0.3 -0.6 -0.3" preserveAlpha="true" />
            </filter>

            <!-- ⑤ 色素散布シミュレーション (インジゴカルミン風) -->
            <filter id="iee-indigo" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    0.85  0.00  0.00 0 -0.05
                    0.00  0.90  0.00 0 -0.02
                    0.10  0.20  1.30 0  0.10
                    0     0     0    1  0" />
                <feConvolveMatrix order="3" kernelMatrix="
                   -0.2 -0.3 -0.2
                   -0.3  3.0 -0.3
                   -0.2 -0.3 -0.2" preserveAlpha="true" />
            </filter>

            <!-- ⑥ TXI風・自然強調 -->
            <filter id="iee-txi" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    1.20 -0.12 -0.06 0  0.02
                   -0.06  1.14 -0.05 0  0.01
                   -0.05 -0.06  1.15 0  0.01
                    0     0     0    1  0" />
                <feConvolveMatrix order="3" kernelMatrix="
                   -0.2 -0.3 -0.2
                   -0.3  3.0 -0.3
                   -0.2 -0.3 -0.2" preserveAlpha="true" />
                <feComponentTransfer>
                    <feFuncR type="linear" slope="1.06" intercept="0.01" />
                    <feFuncG type="linear" slope="1.04" intercept="0.01" />
                    <feFuncB type="linear" slope="1.04" intercept="0.01" />
                </feComponentTransfer>
            </filter>

            <!-- ⑦ 褪色・平坦病変強調 (Pale) -->
            <filter id="iee-pale" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    0.78  0.18  0.06 0 -0.02
                   -0.12  1.22 -0.04 0  0.03
                    0.06 -0.16  1.38 0  0.05
                    0     0     0    1  0" />
                <feConvolveMatrix order="3" kernelMatrix="
                   -0.25 -0.4 -0.25
                   -0.4   3.6 -0.4
                   -0.25 -0.4 -0.25" preserveAlpha="true" />
                <feComponentTransfer>
                    <feFuncR type="linear" slope="0.95" intercept="0.02" />
                    <feFuncG type="linear" slope="1.10" intercept="-0.02" />
                    <feFuncB type="linear" slope="1.15" intercept="-0.03" />
                </feComponentTransfer>
            </filter>
        </defs>
    </svg>

    <!-- トースト通知 -->
    <div id="printToast" class="no-print">メッセージ</div>

    <!-- ----------------------------------------------------------------------
         JavaScriptロジック
    ----------------------------------------------------------------------- -->
    <script>
        const PATIENT_INFO = <?= json_encode($patient_info, JSON_UNESCAPED_UNICODE) ?>;
        const EXAM_LIST    = <?= json_encode($exam_list, JSON_UNESCAPED_UNICODE) ?>;
        const PRINT_TRAY_PREFIX = 'rsbase_print_tray_p_';
        const LEGACY_PRINT_TRAY_KEY = 'rsbase_print_tray';
        const PRINT_TRAY_TTL_MS = 24 * 60 * 60 * 1000;

        function normalizeTrayPatientId(rawId) {
            if (rawId === null || rawId === undefined) return '';
            const s = String(rawId).trim();
            if (!s || s === '00000') return '';
            return s.replace(/^0+([0-9]+)$/, '$1');
        }

        function normalizeFolderKey(rawFolder) {
            if (!rawFolder) return '';
            return String(rawFolder).trim().replace(/\//g, '\\').replace(/\\+$/, '').toLowerCase();
        }

        const NORM_PAT_ID = normalizeTrayPatientId(PATIENT_INFO && PATIENT_INFO.id);
        const CURRENT_TRAY_KEY = NORM_PAT_ID ? `${PRINT_TRAY_PREFIX}${NORM_PAT_ID}` : '';

        // DBから取得した当該患者の全検査フォルダ一覧（他患者画像の混入を物理的に遮断するホワイトリスト）
        const VALID_PATIENT_FOLDERS = new Set(
            Array.isArray(EXAM_LIST)
                ? EXAM_LIST.map(ex => normalizeFolderKey(ex.folder)).filter(Boolean)
                : []
        );

        // 旧共通キーの安全な患者別分離移行 ＆ 24時間経過トレイの自動清掃
        (function cleanupAndMigratePrintTrays() {
            try {
                const now = Date.now();
                const legacyRaw = localStorage.getItem(LEGACY_PRINT_TRAY_KEY);
                if (legacyRaw) {
                    try {
                        const legacyParsed = JSON.parse(legacyRaw);
                        const legId = normalizeTrayPatientId(legacyParsed && legacyParsed.patientId);
                        if (legId && Array.isArray(legacyParsed.items) && legacyParsed.items.length > 0) {
                            const targetKey = `${PRINT_TRAY_PREFIX}${legId}`;
                            if (!localStorage.getItem(targetKey)) {
                                const safeItems = legacyParsed.items
                                    .filter(it => !it.patientId || normalizeTrayPatientId(it.patientId) === legId)
                                    .map(it => ({
                                        ...it,
                                        patientId: legId,
                                        panPctX: Number.isFinite(it.panPctX) ? it.panPctX : 0,
                                        panPctY: Number.isFinite(it.panPctY) ? it.panPctY : 0,
                                        zoom: 1.0
                                    }));
                                localStorage.setItem(targetKey, JSON.stringify({
                                    patientId: legId,
                                    patientName: legacyParsed.patientName || '',
                                    updatedAt: legacyParsed.updatedAt || now,
                                    items: safeItems
                                }));
                            }
                        }
                    } catch (_) {}
                    localStorage.removeItem(LEGACY_PRINT_TRAY_KEY);
                }

                const expiredKeys = [];
                for (let i = 0; i < localStorage.length; i++) {
                    const k = localStorage.key(i);
                    if (k && k.indexOf(PRINT_TRAY_PREFIX) === 0) {
                        try {
                            const obj = JSON.parse(localStorage.getItem(k));
                            if (!obj || (obj.updatedAt && (now - obj.updatedAt) > PRINT_TRAY_TTL_MS)) {
                                expiredKeys.push(k);
                            }
                        } catch (_) {
                            expiredKeys.push(k);
                        }
                    }
                }
                expiredKeys.forEach(k => localStorage.removeItem(k));
            } catch (err) {
                console.warn("Print tray cleanup warning:", err);
            }
        })();

        const workspaceStage = document.getElementById('workspaceStage');
        const sheetZoomWrapper = document.getElementById('sheetZoomWrapper');
        const sheetGrid = document.getElementById('sheetGrid');
        const printSheet = document.getElementById('printSheet');
        const pickerDrawer = document.getElementById('pickerDrawer');
        const btnToggleDrawer = document.getElementById('btnToggleDrawer');
        const btnDrawerClose = document.getElementById('btnDrawerClose');
        const drawerBody = document.getElementById('drawerBody');
        const scaleControlBox = document.getElementById('scaleControlBox');
        const zoomSheetRange = document.getElementById('zoomSheetRange');
        const zoomSheetVal = document.getElementById('zoomSheetVal');
        const btnZoomFit = document.getElementById('btnZoomFit');
        const btnZoom100 = document.getElementById('btnZoom100');
        const btnPrintRun = document.getElementById('btnPrintRun');
        const btnResetAllSlots = document.getElementById('btnResetAllSlots');
        const btnClearTray = document.getElementById('btnClearTray');
        const btnClosePage = document.getElementById('btnClosePage');
        const toast = document.getElementById('printToast');

        // 8スロット分の内部データ (インデックス 0〜7)
        let slots = Array(8).fill(null);
        let activeTargetSlotIndex = null;
        let currentSheetZoom = 1.0;
        let isAutoFitZoom = true;

        // トースト表示ヘルパー
        function showToast(msg) {
            toast.textContent = msg;
            toast.className = 'show';
            setTimeout(() => { toast.className = ''; }, 2200);
        }

        // 当該患者の画像かどうかを厳密判定（患者ID一致 ＋ 検査フォルダホワイトリスト照合）
        function isItemOwnedByCurrentPatient(item) {
            if (!item || !NORM_PAT_ID) return false;
            if (item.patientId && normalizeTrayPatientId(item.patientId) !== NORM_PAT_ID) {
                return false;
            }
            if (VALID_PATIENT_FOLDERS.size > 0 && item.folder) {
                if (!VALID_PATIENT_FOLDERS.has(normalizeFolderKey(item.folder))) {
                    return false;
                }
            }
            return true;
        }

        // LocalStorageから当該患者専用の印刷トレイ読み込み
        function loadTrayFromStorage() {
            slots = Array(8).fill(null);
            if (!CURRENT_TRAY_KEY) {
                return;
            }
            try {
                const raw = localStorage.getItem(CURRENT_TRAY_KEY);
                if (raw) {
                    const parsed = JSON.parse(raw);
                    if (parsed && normalizeTrayPatientId(parsed.patientId) === NORM_PAT_ID && Array.isArray(parsed.items)) {
                        const verifiedItems = parsed.items.filter(isItemOwnedByCurrentPatient);
                        verifiedItems.slice(0, 8).forEach((item, idx) => {
                            const px = Number.isFinite(item.panPctX) ? item.panPctX : 0;
                            const py = Number.isFinite(item.panPctY) ? item.panPctY : 0;
                            const zVal = (Number.isFinite(item.zoom) && item.zoom >= 0.5 && item.zoom <= 5.0) ? item.zoom : 1.0;
                            // fitMode未設定の既存アイテムで手動パン・ズームがない場合は 'auto' 扱い（オリンパスなら自動でO社フィット適用）
                            let resolvedFitMode = item.fitMode;
                            if (!resolvedFitMode) {
                                resolvedFitMode = (Math.abs(px) < 0.01 && Math.abs(py) < 0.01 && Math.abs(zVal - 1.0) < 0.005) ? 'auto' : 'custom';
                            }
                            slots[idx] = {
                                id: item.id || `slot_${idx}_${Date.now()}`,
                                patientId: NORM_PAT_ID,
                                folder: item.folder || '',
                                file: item.file || '',
                                snapshotUrl: item.snapshotUrl || '',
                                examName: item.examName || '',
                                examDate: item.examDate || '',
                                caption: item.caption || `[${item.examName || '検査'}] ${item.examDate || ''} (${item.file || ''})`,
                                fitMode: resolvedFitMode,
                                isOlympus: !!item.isOlympus,
                                clipLeftPct: (Number.isFinite(item.clipLeftPct) && item.clipLeftPct >= 0) ? item.clipLeftPct : 0,
                                panPctX: px,
                                panPctY: py,
                                zoom: zVal,
                                ieeMode: item.ieeMode || 'normal',
                                contrast: item.contrast || 100,
                                brightness: item.brightness || 100,
                                invert: !!item.invert
                            };
                        });
                        if (verifiedItems.length !== parsed.items.length) {
                            saveTrayToStorage();
                        }
                        return;
                    }
                }
            } catch (err) {
                console.warn("Tray load error:", err);
            }
        }

        // LocalStorageへ現在のスロット状態を保存（当該患者キーのみ更新）
        let saveTrayTimer = null;
        function scheduleSaveTray() {
            if (saveTrayTimer) clearTimeout(saveTrayTimer);
            saveTrayTimer = setTimeout(() => {
                saveTrayTimer = null;
                saveTrayToStorage();
            }, 60);
        }

        function saveTrayToStorage() {
            if (!CURRENT_TRAY_KEY) return;
            try {
                const items = slots.filter(s => s !== null).map(s => ({
                    ...s,
                    patientId: NORM_PAT_ID
                }));
                const tray = {
                    patientId: NORM_PAT_ID,
                    patientName: PATIENT_INFO.kanja_kanji,
                    updatedAt: Date.now(),
                    items: items
                };
                localStorage.setItem(CURRENT_TRAY_KEY, JSON.stringify(tray));
            } catch (err) {
                console.error("Tray save error:", err);
            }
        }

        // ----------------------------------------------------------------------
        // オリンパス内視鏡画像の自動判定 ＆ O社フィット最適ジオメトリ算出
        // ----------------------------------------------------------------------
        const NON_ENDO_EXAM_RE = /エコー|超音波|US|XP|X線|CR|DR|CT|MR|MRI|RF|透視|レントゲン|胸部|腹部単純|骨塩|マンモ|心電図|ECG/iu;
        const OLYMPUS_GEO_CACHE = new Map();
        const olyDetectCanvas = document.createElement('canvas');
        olyDetectCanvas.width = 120;
        olyDetectCanvas.height = 90;
        const olyDetectCtx = olyDetectCanvas.getContext('2d', { willReadFrequently: true });

        function analyzeOlympusImage(img, item) {
            if (!img || !img.naturalWidth || !img.naturalHeight) {
                return { isOlympus: false };
            }
            const cacheKey = `${normalizeFolderKey(item && item.folder)}|${(item && item.file) || img.src}`;
            if (OLYMPUS_GEO_CACHE.has(cacheKey)) {
                return OLYMPUS_GEO_CACHE.get(cacheKey);
            }

            const examName = (item && item.examName) ? String(item.examName) : '';
            if (examName && NON_ENDO_EXAM_RE.test(examName)) {
                const neg = { isOlympus: false };
                OLYMPUS_GEO_CACHE.set(cacheKey, neg);
                return neg;
            }

            const nw = img.naturalWidth;
            const nh = img.naturalHeight;
            const ar = nw / nh;
            // オリンパス内視鏡キャプチャ (4:3 / 5:4 / 16:9) 以外のアスペクト比（正方形・縦長XP等）は除外
            if (ar < 1.18 || ar > 2.10) {
                const neg = { isOlympus: false };
                OLYMPUS_GEO_CACHE.set(cacheKey, neg);
                return neg;
            }

            try {
                const W = 120;
                const H = 90;
                olyDetectCtx.clearRect(0, 0, W, H);
                olyDetectCtx.drawImage(img, 0, 0, W, H);
                const data = olyDetectCtx.getImageData(0, 0, W, H).data;

                // 有彩（粘膜色）画素マップを構築：背景黒・白文字・モノクロエコー/XPは chroma <= 18 で完全に除外される
                const cMask = new Uint8Array(W * H);
                for (let p = 0, idx = 0; p < W * H; p++, idx += 4) {
                    const r = data[idx];
                    const g = data[idx + 1];
                    const b = data[idx + 2];
                    const maxC = r > g ? (r > b ? r : b) : (g > b ? g : b);
                    const minC = r < g ? (r < b ? r : b) : (g < b ? g : b);
                    if (maxC > 32 && (maxC - minC) > 18) {
                        cMask[p] = 1;
                    }
                }

                const yTop = 7;   // ~8%
                const yBot = 83;  // ~92%
                const ySpan = yBot - yTop;

                // 左側文字領域 (x: 0〜24%), 文字と観察視野の間の黒帯ギャップ (x: 17.5%〜24%), 右側観察視野中心 (x: 42%〜88%)
                let sumL24 = 0;
                let sumGap = 0;
                let sumRmid = 0;
                const colChroma = new Float32Array(W);

                for (let x = 0; x < W; x++) {
                    let colCnt = 0;
                    for (let y = yTop; y < yBot; y++) {
                        colCnt += cMask[y * W + x];
                    }
                    colChroma[x] = colCnt / ySpan;
                    if (x < 29) sumL24 += colCnt;
                    if (x >= 21 && x < 29) sumGap += colCnt;
                    if (x >= 50 && x < 106) sumRmid += colCnt;
                }

                const l24Ratio = sumL24 / (29 * ySpan);
                const gapRatio = sumGap / (8 * ySpan);
                const rmidRatio = sumRmid / (56 * ySpan);

                // x >= 17.5% (col 21以降、左端の微小アイコンを除外) で内視鏡八角形視野の左右端を検出
                let xFirst = -1;
                let xLast = -1;
                for (let x = 21; x < W; x++) {
                    if (colChroma[x] >= 0.05) {
                        if (xFirst === -1) xFirst = x;
                        xLast = x;
                    }
                }

                // 判定条件：左24%が黒背景＋白文字のみ、境界ギャップが黒、右側に内視鏡粘膜色があり、観察視野開始が24%以降
                const isOlympus = (l24Ratio < 0.045) && (gapRatio < 0.025) && (rmidRatio >= 0.08) && (xFirst >= 29);
                if (!isOlympus) {
                    const neg = { isOlympus: false };
                    OLYMPUS_GEO_CACHE.set(cacheKey, neg);
                    return neg;
                }

                const ax0 = xFirst / W;
                const ax1 = (xLast + 1) / W;

                // 観察視野内の全行(0〜H)から上下端を検出
                const xSpan = Math.max(1, xLast - xFirst + 1);
                let yFirst = -1;
                let yLast = -1;
                for (let y = 0; y < H; y++) {
                    let rowCnt = 0;
                    const rowOff = y * W;
                    for (let x = xFirst; x <= xLast; x++) {
                        rowCnt += cMask[rowOff + x];
                    }
                    if ((rowCnt / xSpan) >= 0.04) {
                        if (yFirst === -1) yFirst = y;
                        yLast = y;
                    }
                }

                const ay0 = yFirst >= 0 ? (yFirst / H) : 0.05;
                const ay1 = yLast >= 0 ? ((yLast + 1) / H) : 0.95;

                // 暗い管腔ショットでも片寄らないようハードウェア八角形最小範囲と統合し、1.2%の安全マージンを確保
                const boxX0 = Math.max(0.22, Math.min(ax0, 0.425) - 0.012);
                const boxX1 = Math.min(1.00, Math.max(ax1, 0.920) + 0.012);
                const boxY0 = Math.max(0.00, Math.min(ay0, 0.100) - 0.012);
                const boxY1 = Math.min(1.00, Math.max(ay1, 0.900) + 0.012);
                // 左側の白文字情報領域のみを黒背景上でクリーンにマスクする左クリップ率(%)
                const clipLeftPct = Math.max(0, Math.round((boxX0 - 0.015) * 1000) / 10);

                const result = {
                    isOlympus: true,
                    boxX0,
                    boxX1,
                    boxY0,
                    boxY1,
                    clipLeftPct
                };
                OLYMPUS_GEO_CACHE.set(cacheKey, result);
                return result;
            } catch (err) {
                console.warn("Olympus image detection skipped:", err);
                return { isOlympus: false };
            }
        }

        // 2×4枠の実寸に対してオリンパス観察視野を上下左右一切欠けさせず中央最大化する変換値を算出
        function computeOlympusSlotFit(img, viewport, geo) {
            if (!img || !img.naturalWidth || !img.naturalHeight || !geo || !geo.isOlympus) {
                return { panPctX: 0, panPctY: 0, zoom: 1.0, clipLeftPct: 0 };
            }
            const nw = img.naturalWidth;
            const nh = img.naturalHeight;
            const imgAspect = nw / nh;
            const vw = (viewport && viewport.clientWidth) ? viewport.clientWidth : 376;
            const vh = (viewport && viewport.clientHeight) ? viewport.clientHeight : 246;
            const slotAspect = vw / vh;

            const baseW = (imgAspect >= slotAspect) ? vw : (vh * imgAspect);
            const baseH = (imgAspect >= slotAspect) ? (vw / imgAspect) : vh;

            const wNorm = Math.max(0.2, geo.boxX1 - geo.boxX0);
            const hNorm = Math.max(0.2, geo.boxY1 - geo.boxY0);
            const cx = (geo.boxX0 + geo.boxX1) / 2;
            const cy = (geo.boxY0 + geo.boxY1) / 2;

            const regW = baseW * wNorm;
            const regH = baseH * hNorm;
            const rawZoom = Math.min(vw / regW, vh / regH);
            // 端数切り捨てで丸め、0.5pxたりとも観察視野が枠外へ切れないことを保証
            const zoom = Math.max(0.5, Math.min(5.0, Math.floor(rawZoom * 100) / 100));
            const panPctX = Math.round((0.5 - cx) * zoom * 100 * 100) / 100;
            const panPctY = Math.round((0.5 - cy) * zoom * 100 * 100) / 100;

            return {
                panPctX,
                panPctY,
                zoom,
                clipLeftPct: geo.clipLeftPct || 0
            };
        }

        // 調整パネル内の「⭕ O社⇄ / 🖼️ 全体⇄」1クリック切替ボタン表示同期
        function syncOlympusToggleButton(btnEl, item) {
            if (!btnEl) return;
            if (!item || !item.isOlympus) {
                btnEl.style.display = 'none';
                return;
            }
            btnEl.style.display = 'inline-flex';
            const inOlympusMode = (item.fitMode === 'olympus') || (item.fitMode === 'custom' && (item.clipLeftPct || 0) > 0);
            if (inOlympusMode) {
                btnEl.classList.add('is-olympus');
                btnEl.classList.remove('is-full');
                btnEl.textContent = '⭕ O社⇄';
                btnEl.title = '現在：O社フィット配置（1クリックで標準の全体配置へ切替）';
            } else {
                btnEl.classList.remove('is-olympus');
                btnEl.classList.add('is-full');
                btnEl.textContent = '🖼️ 全体⇄';
                btnEl.title = '現在：標準の全体配置（1クリックでO社フィット配置へ切替）';
            }
        }

        // スロットを当該画像のデフォルト配置（オリンパス画像はO社フィット、その他は全体配置）へ初期化
        function resetSlotToDefault(item, imgEl, viewportEl, olyBtnEl) {
            if (!item) return;
            const geo = (imgEl && imgEl.naturalWidth > 0) ? analyzeOlympusImage(imgEl, item) : null;
            if (geo && geo.isOlympus) {
                item.isOlympus = true;
                const fit = computeOlympusSlotFit(imgEl, viewportEl, geo);
                item.fitMode = 'olympus';
                item.panPctX = fit.panPctX;
                item.panPctY = fit.panPctY;
                item.zoom = fit.zoom;
                item.clipLeftPct = fit.clipLeftPct;
            } else if (item.isOlympus && (!imgEl || !imgEl.naturalWidth)) {
                item.fitMode = 'auto';
                item.panPctX = 0;
                item.panPctY = 0;
                item.zoom = 1.0;
                item.clipLeftPct = 0;
            } else {
                item.isOlympus = false;
                item.fitMode = 'full';
                item.panPctX = 0;
                item.panPctY = 0;
                item.zoom = 1.0;
                item.clipLeftPct = 0;
            }
            if (imgEl) {
                updateImageTransform(imgEl, item.panPctX, item.panPctY, item.zoom, item.clipLeftPct);
            }
            if (olyBtnEl) {
                syncOlympusToggleButton(olyBtnEl, item);
            }
        }

        // CSSフィルター文字列生成 (DICOMビューワ側で付与された設定がある場合のみ反映)
        function getFilterString(item) {
            if (!item) return 'none';
            let filterParts = [];
            if (item.contrast && item.contrast !== 100) filterParts.push(`contrast(${item.contrast}%)`);
            if (item.brightness && item.brightness !== 100) filterParts.push(`brightness(${item.brightness}%)`);
            if (item.invert) filterParts.push('invert(100%)');

            if (item.ieeMode && item.ieeMode !== 'normal') {
                filterParts.push(`url(#iee-${item.ieeMode})`);
                if (item.ieeMode === 'lci') filterParts.push('saturate(135%)');
                else if (item.ieeMode === 'txi') filterParts.push('saturate(120%)');
                else if (item.ieeMode === 'pale') filterParts.push('saturate(115%)');
                else if (item.ieeMode === 'nbi') filterParts.push('saturate(125%)');
            }
            return filterParts.length > 0 ? filterParts.join(' ') : 'none';
        }

        function getIeeLabel(mode, invert) {
            if (invert) return '🌓 反転';
            const map = {
                'lci': '🔴 LCI',
                'nbi': '🟢 NBI',
                'txi': '🔆 TXI',
                'pale': '⚪ 褪色',
                'ihb': '📊 IHb',
                'texture': '🏔️ 構造',
                'indigo': '🔵 色素'
            };
            return map[mode] || '';
        }

        // 元画像の縦横比に応じ、内部トリミングを一切発生させない基準軸（横100% または 縦100%）を設定
        function fitImgBaseAxis(img, viewport) {
            if (!img || !img.naturalWidth || !img.naturalHeight) return;
            const imgAspect = img.naturalWidth / img.naturalHeight;
            const vw = (viewport && viewport.clientWidth) ? viewport.clientWidth : 376;
            const vh = (viewport && viewport.clientHeight) ? viewport.clientHeight : 246;
            const slotAspect = vw / vh;

            if (imgAspect >= slotAspect) {
                // 16:9 ワイド内視鏡画像など：横幅100%を基準にして右端切れ＆内部クリップを完全防止
                img.style.width = '100%';
                img.style.height = 'auto';
            } else {
                // 4:3 (オリンパス等) / 5:4 / 正方形 / 縦長XP：高さ100%を基準にして全画素を保持
                img.style.width = 'auto';
                img.style.height = '100%';
            }
        }

        // 2×4 グリッドの描画
        function renderGrid() {
            sheetGrid.innerHTML = '';

            for (let i = 0; i < 8; i++) {
                const cell = document.createElement('div');
                cell.className = 'grid-cell';
                cell.dataset.slotIndex = i;

                const item = slots[i];

                if (item) {
                    // 画像ありスロット
                    const viewport = document.createElement('div');
                    viewport.className = 'cell-viewport';
                    viewport.dataset.slotIndex = i;

                    const img = document.createElement('img');
                    img.alt = item.file;
                    img.draggable = false;

                    // 初期トランスフォーム & フィルター適用
                    updateImageTransform(img, item.panPctX, item.panPctY, item.zoom, item.clipLeftPct);
                    img.style.filter = getFilterString(item);

                    // 枠番号バッジ
                    const badge = document.createElement('div');
                    badge.className = 'cell-badge no-print';
                    badge.textContent = `#${i + 1}`;

                    // 病巣強調バッジ (DICOMビューワ側で指定された場合のみ表示)
                    const ieeTag = getIeeLabel(item.ieeMode, item.invert);
                    if (ieeTag) {
                        const ieeBadge = document.createElement('div');
                        ieeBadge.className = 'cell-iee-badge';
                        ieeBadge.textContent = ieeTag;
                        viewport.appendChild(ieeBadge);
                    }

                    // 枠の上辺外側にオーバーレイする最終配置調整パネル（オリンパス画像は1クリック切替ボタン付き）
                    const tools = document.createElement('div');
                    tools.className = 'cell-tools no-print';

                    tools.innerHTML = `
                        <span class="cell-tool-slot-tag">#${i + 1}</span>
                        <button type="button" class="cell-tool-btn btn-olympus-toggle" data-act="toggleOlympus" style="display:none;"></button>
                        <button type="button" class="cell-tool-btn" data-act="prev" title="前の枠と入れ替え">◀</button>
                        <button type="button" class="cell-tool-btn" data-act="next" title="次の枠と入れ替え">▶</button>
                        <button type="button" class="cell-tool-btn btn-highlight" data-act="duplicate" title="この枠を空き枠に複製（全体像と拡大像の対比用）">📋 複製</button>
                        <button type="button" class="cell-tool-btn" data-act="zoomIn" title="ズーム拡大 [+]">＋</button>
                        <button type="button" class="cell-tool-btn" data-act="zoomOut" title="ズーム縮小 [-]">－</button>
                        <button type="button" class="cell-tool-btn" data-act="reset" title="初期配置にリセット（オリンパス画像はO社フィット、その他は全体配置）">↺</button>
                        <button type="button" class="cell-tool-btn btn-del" data-act="delete" title="この枠から画像を削除">✕</button>
                    `;

                    const olyBtn = tools.querySelector('[data-act="toggleOlympus"]');
                    syncOlympusToggleButton(olyBtn, item);

                    tools.addEventListener('click', e => {
                        const btn = e.target.closest('.cell-tool-btn');
                        if (!btn) return;
                        e.stopPropagation();
                        handleCellToolAction(i, btn.dataset.act, cell);
                    });

                    viewport.appendChild(img);
                    viewport.appendChild(badge);

                    // 直感ドラッグ＆ズーム（無欠損パン＆ズーム操作）バインド
                    bindPanZoomEvents(viewport, img, item, olyBtn);

                    // キャプションバー (検査名・撮影日時・所見編集)
                    const captionBar = document.createElement('div');
                    captionBar.className = 'cell-caption';

                    const captionText = document.createElement('div');
                    captionText.className = 'caption-text';
                    captionText.contentEditable = 'true';
                    captionText.spellcheck = false;
                    captionText.textContent = item.caption;
                    captionText.title = "クリックして所見やコメントを直接編集できます";

                    captionText.addEventListener('blur', () => {
                        item.caption = captionText.textContent;
                        saveTrayToStorage();
                    });

                    captionBar.appendChild(captionText);

                    // ツールバーは画像内(.cell-viewport)ではなく枠(.grid-cell)の上辺外側に配置
                    cell.appendChild(tools);
                    cell.appendChild(viewport);
                    cell.appendChild(captionBar);

                    // DOMへ追加して viewport の実寸 (clientWidth/clientHeight) を確定させてから画像をロード＆自動フィット計測
                    sheetGrid.appendChild(cell);

                    let imgInitialized = false;
                    const onImgReady = () => {
                        if (imgInitialized || !img.naturalWidth || !img.naturalHeight) return;
                        imgInitialized = true;
                        fitImgBaseAxis(img, viewport);

                        const geo = analyzeOlympusImage(img, item);
                        const prevMode = item.fitMode;
                        const prevIsOly = item.isOlympus;
                        item.isOlympus = !!geo.isOlympus;

                        if (geo.isOlympus) {
                            if (item.fitMode === 'auto' || item.fitMode === 'olympus') {
                                const fit = computeOlympusSlotFit(img, viewport, geo);
                                item.fitMode = 'olympus';
                                item.panPctX = fit.panPctX;
                                item.panPctY = fit.panPctY;
                                item.zoom = fit.zoom;
                                item.clipLeftPct = fit.clipLeftPct;
                            }
                        } else {
                            item.clipLeftPct = 0;
                            if (item.fitMode === 'auto' || item.fitMode === 'olympus') {
                                item.fitMode = 'full';
                                item.panPctX = 0;
                                item.panPctY = 0;
                                item.zoom = 1.0;
                            }
                        }

                        updateImageTransform(img, item.panPctX, item.panPctY, item.zoom, item.clipLeftPct);
                        syncOlympusToggleButton(olyBtn, item);

                        if (prevMode !== item.fitMode || prevIsOly !== item.isOlympus) {
                            scheduleSaveTray();
                        }
                    };

                    img.addEventListener('load', onImgReady);
                    img.src = item.snapshotUrl
                        ? item.snapshotUrl
                        : `dcm_api.php?action=image&folder=${encodeURIComponent(item.folder)}&file=${encodeURIComponent(item.file)}`;
                    if (img.complete && img.naturalWidth > 0) {
                        onImgReady();
                    }

                } else {
                    // 空きスロット
                    const empty = document.createElement('div');
                    empty.className = 'cell-empty';
                    empty.innerHTML = `
                        <div class="cell-badge no-print" style="position:static; align-self:flex-start; margin-left:4px;">#${i + 1}</div>
                        <div class="cell-empty-icon">➕</div>
                        <div class="cell-empty-text">画像を追加</div>
                    `;

                    empty.addEventListener('click', () => {
                        activeTargetSlotIndex = i;
                        openPickerDrawer();
                    });

                    cell.appendChild(empty);
                    sheetGrid.appendChild(cell);
                }
            }

            updatePrintReadyState();
        }

        // 全8枠に画像が配置されたら左上「印刷する (A4縦)」ボタンをアニメーション強調表示
        let prevAllSlotsFilled = false;
        function updatePrintReadyState() {
            if (!btnPrintRun) return;
            const filledCount = slots.reduce((acc, s) => acc + (s !== null ? 1 : 0), 0);
            const isAllFilled = (filledCount === 8);
            btnPrintRun.classList.toggle('is-ready-8', isAllFilled);
            btnPrintRun.title = isAllFilled
                ? '全8枠の配置が完了しました！クリックしてA4縦印刷プレビューを開きます [Ctrl+P]'
                : `A4縦 印刷プレビューを実行 (${filledCount}/8枠配置中) [Ctrl+P]`;
            prevAllSlotsFilled = isAllFilled;
        }

        // ズーム変更時に現在の表示中心点がズレないようパン(%)を比例補正
        function applySlotZoom(item, img, newZoom) {
            const oldZoom = item.zoom || 1.0;
            const clampedZoom = Math.max(0.5, Math.min(5.0, Math.round(newZoom * 100) / 100));
            if (oldZoom > 0) {
                const ratio = clampedZoom / oldZoom;
                item.panPctX = Math.round(((item.panPctX || 0) * ratio) * 100) / 100;
                item.panPctY = Math.round(((item.panPctY || 0) * ratio) * 100) / 100;
            }
            item.zoom = clampedZoom;
            item.fitMode = 'custom';
            if (img) {
                updateImageTransform(img, item.panPctX, item.panPctY, item.zoom, item.clipLeftPct);
            }
        }

        // 直感ドラッグ＆ズーム (元画像100%保持・無欠損パン＆ズーム)
        function bindPanZoomEvents(viewport, img, item, olyBtn) {
            let isDragging = false;
            let startX = 0;
            let startY = 0;
            let initPanPctX = 0;
            let initPanPctY = 0;

            const onMouseMove = (e) => {
                if (!isDragging) return;
                const scale = currentSheetZoom || 1.0;
                const dxUnscaled = (e.clientX - startX) / scale;
                const dyUnscaled = (e.clientY - startY) / scale;
                const baseW = img.offsetWidth || viewport.clientWidth || 376;
                const baseH = img.offsetHeight || viewport.clientHeight || 246;
                item.panPctX = Math.round((initPanPctX + (dxUnscaled / baseW) * 100) * 100) / 100;
                item.panPctY = Math.round((initPanPctY + (dyUnscaled / baseH) * 100) * 100) / 100;
                if (Math.hypot(dxUnscaled, dyUnscaled) > 1.5) {
                    item.fitMode = 'custom';
                }
                updateImageTransform(img, item.panPctX, item.panPctY, item.zoom, item.clipLeftPct);
            };

            const onMouseUp = () => {
                if (!isDragging) return;
                isDragging = false;
                viewport.classList.remove('grabbing');
                window.removeEventListener('mousemove', onMouseMove);
                window.removeEventListener('mouseup', onMouseUp);
                syncOlympusToggleButton(olyBtn, item);
                saveTrayToStorage();
            };

            viewport.addEventListener('mousedown', e => {
                if (e.button !== 0) return; // 左ドラッグのみ
                if (e.target.closest('.cell-tools')) return;
                e.preventDefault();
                isDragging = true;
                viewport.classList.add('grabbing');
                startX = e.clientX;
                startY = e.clientY;
                initPanPctX = item.panPctX || 0;
                initPanPctY = item.panPctY || 0;
                window.addEventListener('mousemove', onMouseMove);
                window.addEventListener('mouseup', onMouseUp);
            });

            // ホイールで拡大縮小 (0.5x 〜 5.0x、中心維持ズーム)
            viewport.addEventListener('wheel', e => {
                e.preventDefault();
                const delta = e.deltaY < 0 ? 0.1 : -0.1;
                applySlotZoom(item, img, (item.zoom || 1.0) + delta);
                syncOlympusToggleButton(olyBtn, item);
                saveTrayToStorage();
            }, { passive: false });

            // ダブルクリックで初期配置（オリンパス画像はO社フィット、その他は全体配置）にリセット
            viewport.addEventListener('dblclick', e => {
                if (e.target.closest('.cell-tools')) return;
                resetSlotToDefault(item, img, viewport, olyBtn);
                saveTrayToStorage();
                const modeLabel = item.isOlympus ? 'O社フィット' : '全体配置';
                showToast(`🔄 枠 #${parseInt(viewport.dataset.slotIndex, 10) + 1} を初期状態（${modeLabel}）にリセットしました`);
            });
        }

        function updateImageTransform(img, panPctX, panPctY, z, clipLeftPct = 0) {
            const px = Number.isFinite(panPctX) ? panPctX : 0;
            const py = Number.isFinite(panPctY) ? panPctY : 0;
            const zoomVal = (Number.isFinite(z) && z > 0) ? z : 1.0;
            img.style.transform = `translate(${(-50 + px).toFixed(3)}%, ${(-50 + py).toFixed(3)}%) scale(${zoomVal})`;
            img.style.clipPath = (Number.isFinite(clipLeftPct) && clipLeftPct > 0)
                ? `inset(0px 0px 0px ${clipLeftPct}%)`
                : 'none';
        }

        // 個別枠のボタン操作（切替・＋・－・↺ はDOM再構築せず即座に反映してパネル表示を維持）
        function handleCellToolAction(index, action, cellEl) {
            const item = slots[index];
            if (!item) return;
            const viewportEl = cellEl ? cellEl.querySelector('.cell-viewport') : null;
            const imgEl = viewportEl ? viewportEl.querySelector('img') : null;
            const olyBtnEl = cellEl ? cellEl.querySelector('[data-act="toggleOlympus"]') : null;

            if (action === 'delete') {
                slots[index] = null;
                saveTrayToStorage();
                renderGrid();
                showToast(`🗑️ 枠 #${index + 1} を削除しました`);
            } else if (action === 'toggleOlympus') {
                if (!imgEl || !imgEl.naturalWidth) return;
                const geo = analyzeOlympusImage(imgEl, item);
                if (!geo.isOlympus) return;
                item.isOlympus = true;

                const currentlyOlympus = (item.fitMode === 'olympus') || (item.fitMode === 'custom' && (item.clipLeftPct || 0) > 0);
                if (currentlyOlympus) {
                    // 1クリックで「その他のデフォルト配置（標準全体配置）」へ切替
                    item.fitMode = 'full';
                    item.panPctX = 0;
                    item.panPctY = 0;
                    item.zoom = 1.0;
                    item.clipLeftPct = 0;
                    updateImageTransform(imgEl, 0, 0, 1.0, 0);
                    syncOlympusToggleButton(olyBtnEl, item);
                    saveTrayToStorage();
                    showToast(`🖼️ 枠 #${index + 1} を標準の全体配置に切り替えました`);
                } else {
                    // 1クリックで「O社フィット」へ切替
                    const fit = computeOlympusSlotFit(imgEl, viewportEl, geo);
                    item.fitMode = 'olympus';
                    item.panPctX = fit.panPctX;
                    item.panPctY = fit.panPctY;
                    item.zoom = fit.zoom;
                    item.clipLeftPct = fit.clipLeftPct;
                    updateImageTransform(imgEl, item.panPctX, item.panPctY, item.zoom, item.clipLeftPct);
                    syncOlympusToggleButton(olyBtnEl, item);
                    saveTrayToStorage();
                    showToast(`⭕ 枠 #${index + 1} をO社フィット配置に切り替えました`);
                }
            } else if (action === 'reset') {
                if (imgEl) {
                    resetSlotToDefault(item, imgEl, viewportEl, olyBtnEl);
                } else {
                    item.fitMode = 'auto';
                    item.panPctX = 0;
                    item.panPctY = 0;
                    item.zoom = 1.0;
                    item.clipLeftPct = 0;
                    renderGrid();
                }
                saveTrayToStorage();
                const modeLabel = item.isOlympus ? 'O社フィット' : '全体配置';
                showToast(`🔄 枠 #${index + 1} を初期状態（${modeLabel}）にリセットしました`);
            } else if (action === 'zoomIn') {
                applySlotZoom(item, imgEl, (item.zoom || 1.0) + 0.15);
                syncOlympusToggleButton(olyBtnEl, item);
                saveTrayToStorage();
            } else if (action === 'zoomOut') {
                applySlotZoom(item, imgEl, (item.zoom || 1.0) - 0.15);
                syncOlympusToggleButton(olyBtnEl, item);
                saveTrayToStorage();
            } else if (action === 'prev') {
                if (index > 0) {
                    const temp = slots[index - 1];
                    slots[index - 1] = slots[index];
                    slots[index] = temp;
                    saveTrayToStorage();
                    renderGrid();
                }
            } else if (action === 'next') {
                if (index < 7) {
                    const temp = slots[index + 1];
                    slots[index + 1] = slots[index];
                    slots[index] = temp;
                    saveTrayToStorage();
                    renderGrid();
                }
            } else if (action === 'duplicate') {
                duplicateSlot(index);
            }
        }

        // 同一枠の複製（全体像と一部拡大像の対比レイアウト用）
        function duplicateSlot(index) {
            const item = slots[index];
            if (!item) return;

            const emptyIdx = slots.findIndex(s => s === null);
            if (emptyIdx === -1) {
                alert("全8枠がすべて埋まっています。\n不要な枠を削除して空きを作ってから複製してください。");
                return;
            }

            const cap = item.caption.includes('[拡大]') ? item.caption : (item.caption + ' [拡大]');
            const oldZ = item.zoom || 1.0;
            const newZ = Math.min(5.0, Math.round(Math.max(oldZ * 1.25, 1.4) * 100) / 100);
            const ratio = oldZ > 0 ? (newZ / oldZ) : 1.0;

            slots[emptyIdx] = {
                id: `${item.folder}_${item.file}_${Date.now()}`,
                patientId: NORM_PAT_ID,
                folder: item.folder,
                file: item.file,
                examName: item.examName,
                examDate: item.examDate,
                caption: cap,
                fitMode: 'custom',
                isOlympus: !!item.isOlympus,
                clipLeftPct: item.clipLeftPct || 0,
                panPctX: Math.round(((item.panPctX || 0) * ratio) * 100) / 100,
                panPctY: Math.round(((item.panPctY || 0) * ratio) * 100) / 100,
                zoom: newZ,
                ieeMode: item.ieeMode || 'normal',
                contrast: item.contrast || 100,
                brightness: item.brightness || 100,
                invert: !!item.invert
            };

            const wasFullBefore = prevAllSlotsFilled;
            saveTrayToStorage();
            renderGrid();
            if (!wasFullBefore && prevAllSlotsFilled) {
                showToast(`🖨️ 枠 #${emptyIdx + 1} に複製し全8枠が揃いました！左上の「印刷する (A4縦)」から印刷できます`);
            } else {
                showToast(`枠 #${index + 1} を 枠 #${emptyIdx + 1} に複製しました`);
            }
        }

        // ----------------------------------------------------------------------
        // 検査横断画像ピッカー・ドロワー制御
        // ----------------------------------------------------------------------
        function openPickerDrawer() {
            pickerDrawer.classList.add('open');
            if (workspaceStage) workspaceStage.classList.add('drawer-open');
            if (drawerBody.children.length === 0) {
                renderExamListInDrawer();
            }
            if (isAutoFitZoom) {
                fitSheetToViewport();
            }
        }

        function closePickerDrawer() {
            pickerDrawer.classList.remove('open');
            if (workspaceStage) workspaceStage.classList.remove('drawer-open');
            activeTargetSlotIndex = null;
            if (isAutoFitZoom) {
                fitSheetToViewport();
            }
        }

        btnToggleDrawer.addEventListener('click', () => {
            if (pickerDrawer.classList.contains('open')) {
                closePickerDrawer();
            } else {
                openPickerDrawer();
            }
        });
        btnDrawerClose.addEventListener('click', closePickerDrawer);

        const btnCollapseAllExams = document.getElementById('btnCollapseAllExams');
        if (btnCollapseAllExams) {
            btnCollapseAllExams.addEventListener('click', () => {
                document.querySelectorAll('.exam-item-card').forEach(c => {
                    c.classList.remove('is-open');
                    const g = c.querySelector('.exam-thumb-grid');
                    if (g) g.style.display = 'none';
                    const icon = c.querySelector('.exam-toggle-icon');
                    if (icon) icon.textContent = '▼';
                });
            });
        }

        // ドロワー内に検査リストを展開
        function renderExamListInDrawer() {
            drawerBody.innerHTML = '';

            if (!EXAM_LIST || EXAM_LIST.length === 0) {
                drawerBody.innerHTML = `<div style="color:#94a3b8; text-align:center; padding:30px;">利用可能な過去検査データがありません</div>`;
                return;
            }

            EXAM_LIST.forEach((exam, eIdx) => {
                const card = document.createElement('div');
                card.className = 'exam-item-card';

                const header = document.createElement('div');
                header.className = 'exam-item-header';
                header.innerHTML = `
                    <div class="exam-item-title">
                        <span class="exam-item-badge" style="background:${exam.badge_color};">${exam.cat_label}</span>
                        <span>${escapeHtml(exam.kensamei)}</span>
                    </div>
                    <div class="exam-item-date">
                        <span class="exam-count-badge" id="examCount_${eIdx}"></span>
                        <span>${escapeHtml(exam.date)}</span>
                        <span class="exam-toggle-icon">▼</span>
                    </div>
                `;

                const grid = document.createElement('div');
                grid.className = 'exam-thumb-grid';
                grid.style.display = 'none'; // クリックで展開

                let loaded = false;

                header.addEventListener('click', async () => {
                    const isOpening = (grid.style.display === 'none');
                    grid.style.display = isOpening ? 'grid' : 'none';
                    card.classList.toggle('is-open', isOpening);
                    const iconEl = header.querySelector('.exam-toggle-icon');
                    if (iconEl) iconEl.textContent = isOpening ? '▲' : '▼';

                    if (isOpening && !loaded) {
                        loaded = true;
                        grid.innerHTML = `<div style="grid-column:1/-1; color:#94a3b8; text-align:center; padding:10px;">画像一覧を取得中...</div>`;
                        try {
                            const res = await fetch(`dcm_api.php?action=list&folder=${encodeURIComponent(exam.folder)}`);
                            const data = await res.json();
                            if (data.status === 'success' && data.files && data.files.length > 0) {
                                grid.innerHTML = '';
                                const countEl = header.querySelector(`#examCount_${eIdx}`);
                                if (countEl) countEl.textContent = `${data.files.length}枚`;

                                data.files.forEach((f, fIdx) => {
                                    const thumbCard = document.createElement('div');
                                    thumbCard.className = 'picker-thumb-card';
                                    thumbCard.title = `${f.filename} (クリックで追加)`;

                                    const img = document.createElement('img');
                                    img.loading = 'lazy';
                                    img.src = `dcm_api.php?action=image&folder=${encodeURIComponent(exam.folder)}&file=${encodeURIComponent(f.filename)}&thumb=1`;

                                    const badge = document.createElement('span');
                                    badge.className = 'picker-thumb-badge';
                                    badge.textContent = `#${fIdx + 1}`;

                                    thumbCard.appendChild(img);
                                    thumbCard.appendChild(badge);

                                    thumbCard.addEventListener('click', () => {
                                        pickImageToSlot(exam, f.filename);
                                    });

                                    grid.appendChild(thumbCard);
                                });
                            } else {
                                grid.innerHTML = `<div style="grid-column:1/-1; color:#ef4444; text-align:center; padding:10px;">画像が見つかりませんでした</div>`;
                            }
                        } catch (err) {
                            grid.innerHTML = `<div style="grid-column:1/-1; color:#ef4444; text-align:center; padding:10px;">通信エラー: ${escapeHtml(err.message)}</div>`;
                        }
                    }
                });

                card.appendChild(header);
                card.appendChild(grid);
                drawerBody.appendChild(card);
            });
        }

        // ピッカーからスロットへの画像配置
        function pickImageToSlot(exam, filename) {
            let targetIdx = activeTargetSlotIndex;

            // ターゲットが指定されていない場合、最初の空きスロットを探す
            if (targetIdx === null || targetIdx < 0 || targetIdx >= 8) {
                targetIdx = slots.findIndex(s => s === null);
            }

            if (targetIdx === -1) {
                alert("全8枠がすべて埋まっています。\n空き枠を作るか、削除してから追加してください。");
                return;
            }

            const cleanDate = (exam.date || '').split(' ')[0] || '';
            const caption = `[${exam.kensamei}] ${cleanDate} (${filename})`;

            slots[targetIdx] = {
                id: `${exam.folder}_${filename}_${Date.now()}`,
                patientId: NORM_PAT_ID,
                folder: exam.folder,
                file: filename,
                examName: exam.kensamei,
                examDate: exam.date,
                caption: caption,
                fitMode: 'auto',
                isOlympus: false,
                clipLeftPct: 0,
                panPctX: 0,
                panPctY: 0,
                zoom: 1.0,
                ieeMode: 'normal',
                contrast: 100,
                brightness: 100,
                invert: false
            };

            const wasFullBefore = prevAllSlotsFilled;
            saveTrayToStorage();
            renderGrid();
            if (!wasFullBefore && prevAllSlotsFilled) {
                showToast(`🖨️ 全8枠の配置が完了しました！左上の「印刷する (A4縦)」から印刷できます`);
            } else {
                showToast(`枠 #${targetIdx + 1} に ${filename} を追加しました`);
            }

            activeTargetSlotIndex = null;
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, m => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[m]);
        }

        // ----------------------------------------------------------------------
        // ツールバー操作
        // ----------------------------------------------------------------------
        // 印刷実行 (Ctrl+P / 印刷ボタン)
        btnPrintRun.addEventListener('click', () => {
            window.print();
        });

        // 全枠リセット（オリンパス内視鏡画像はO社フィット、その他は標準全体配置に初期化）
        btnResetAllSlots.addEventListener('click', () => {
            if (!confirm("すべての画像の配置位置・ズーム倍率を初期状態（オリンパス画像はO社フィット、その他は全体配置）にリセットしますか？")) return;
            slots.forEach(item => {
                if (item) {
                    item.fitMode = 'auto';
                    item.clipLeftPct = 0;
                    item.panPctX = 0;
                    item.panPctY = 0;
                    item.zoom = 1.0;
                }
            });
            saveTrayToStorage();
            renderGrid();
            showToast("全枠の配置・倍率を初期状態にリセットしました");
        });

        // トレイ全消去
        btnClearTray.addEventListener('click', () => {
            if (!confirm("印刷トレイ内のすべての画像をクリアしますか？")) return;
            slots = Array(8).fill(null);
            saveTrayToStorage();
            renderGrid();
            showToast("印刷トレイを全消去しました");
        });

        // ----------------------------------------------------------------------
        // プレビュー表示倍率制御（初期表示：2×4シート全体フィット）
        // ----------------------------------------------------------------------
        function getUnscaledSheetSize() {
            // offsetWidth / offsetHeight は CSS transform(scale) の影響を受けない実寸(px)を返す
            const w = printSheet.offsetWidth || 794;
            const h = printSheet.offsetHeight || 1123;
            return { w, h };
        }

        function applySheetZoom(val, autoFit = false) {
            const minZ = parseFloat(zoomSheetRange.min) || 0.35;
            const maxZ = parseFloat(zoomSheetRange.max) || 1.50;
            const clamped = Math.max(minZ, Math.min(maxZ, val));
            currentSheetZoom = clamped;
            isAutoFitZoom = autoFit;

            document.documentElement.style.setProperty('--sheet-zoom', clamped);
            // シート縮小表示時でも枠上パネルのボタンが小さくなりすぎないよう緩やかに逆スケール補正（1列幅に綺麗に収まる最大1.14倍）
            const invScale = clamped < 1.0 ? Math.min(1.14, Math.round((1 / Math.sqrt(clamped)) * 100) / 100) : 1.0;
            document.documentElement.style.setProperty('--tool-inv-scale', invScale);

            zoomSheetRange.value = clamped.toFixed(2);
            zoomSheetVal.textContent = `${Math.round(clamped * 100)}%`;

            const { w, h } = getUnscaledSheetSize();
            if (sheetZoomWrapper) {
                sheetZoomWrapper.style.width  = `${Math.ceil(w * clamped)}px`;
                sheetZoomWrapper.style.height = `${Math.ceil(h * clamped)}px`;
            }

            if (btnZoomFit) {
                btnZoomFit.classList.toggle('is-active', autoFit);
            }
            if (btnZoom100) {
                btnZoom100.classList.toggle('is-active', !autoFit && Math.abs(clamped - 1.0) < 0.005);
            }
        }

        function fitSheetToViewport() {
            const toolbarEl = document.querySelector('.workspace-toolbar');
            const toolbarH = toolbarEl ? toolbarEl.offsetHeight : 48;
            const drawerOpen = pickerDrawer && pickerDrawer.classList.contains('open') && window.innerWidth >= 960;
            const drawerW = drawerOpen ? 440 : 0;

            // 上下ステージ余白(10px×2=20px)＋安全マージン(6px)を差し引き、スクロールなしで2×4全体が収まる倍率を算出
            const availH = Math.max(240, window.innerHeight - toolbarH - 26);
            const availW = Math.max(300, window.innerWidth - drawerW - 36);

            const { w, h } = getUnscaledSheetSize();
            const rawZoom = Math.min(availW / w, availH / h);
            const fitZoom = Math.floor(rawZoom * 100) / 100;
            applySheetZoom(fitZoom, true);
        }

        zoomSheetRange.addEventListener('input', e => {
            const val = parseFloat(e.target.value);
            if (!isNaN(val)) {
                applySheetZoom(val, false);
            }
        });

        if (scaleControlBox) {
            scaleControlBox.addEventListener('wheel', e => {
                e.preventDefault();
                const delta = e.deltaY < 0 ? 0.03 : -0.03;
                applySheetZoom(Math.round((currentSheetZoom + delta) * 100) / 100, false);
            }, { passive: false });
        }

        if (btnZoomFit) {
            btnZoomFit.addEventListener('click', () => {
                fitSheetToViewport();
                window.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
            });
        }

        if (btnZoom100) {
            btnZoom100.addEventListener('click', () => {
                applySheetZoom(1.0, false);
            });
        }

        window.addEventListener('resize', () => {
            if (isAutoFitZoom) {
                fitSheetToViewport();
            }
        });

        // 閉じる
        btnClosePage.addEventListener('click', () => {
            window.close();
            setTimeout(() => {
                if (!window.closed) {
                    window.location.href = 'index.php';
                }
            }, 120);
        });

        // ショートカットキー (印刷 Ctrl+P)
        window.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P')) {
                e.preventDefault();
                window.print();
            } else if (e.key === 'Escape') {
                if (pickerDrawer.classList.contains('open')) {
                    closePickerDrawer();
                }
            }
        });

        // 別タブでの同一患者トレイ変更監視
        window.addEventListener('storage', e => {
            if (CURRENT_TRAY_KEY && e.key === CURRENT_TRAY_KEY) {
                loadTrayFromStorage();
                renderGrid();
            }
        });

        // 初期化（2×4シート全体が1画面に収まる倍率で初期表示）
        loadTrayFromStorage();
        renderGrid();
        fitSheetToViewport();
        requestAnimationFrame(() => {
            if (isAutoFitZoom) fitSheetToViewport();
        });
    </script>
</body>
</html>
