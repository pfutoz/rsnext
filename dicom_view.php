<?php
declare(strict_types=1);
// ==============================================================================
// RS-Next 高性能ダイレクトDICOM・画像・PDFビューワー (dicom_view.php)
// - study_id 指定による UTF-8 DB 自動解決 ＆ folder 直接指定の両対応
// - PDF検査レポート（骨密度・ABI・紹介状等）の場合は専用PDFリーダーを自動表示
// ==============================================================================

require_once __DIR__ . '/lib/bootstrap.php';

$study_id  = (int)($_GET['study_id'] ?? 0);
$folder    = (string)($_GET['folder'] ?? '');
$pat_id    = (string)($_GET['id'] ?? '');
$pat_name  = (string)($_GET['name'] ?? '');
$exam_name = (string)($_GET['kensa'] ?? '');
$exam_date = (string)($_GET['date'] ?? '');
$sindan    = (string)($_GET['sindan'] ?? '');
$mode      = (string)($_GET['mode'] ?? 'standalone');
$primary_type = 'DICOM';
$pdf_filename = '0001.pdf';

if ($study_id > 0) {
    try {
        $pdo = rsnext_pdo();
        $stmt = $pdo->prepare(
            "SELECT s.*, p.kanja_kanji
             FROM studies s
             LEFT JOIN patients p ON p.patient_id = s.patient_id
             WHERE s.study_id = ?"
        );
        $stmt->execute([$study_id]);
        $st = $stmt->fetch();
        if ($st) {
            $folder       = rsnext_resolve_study_dir((string)$st['volume_id'], (string)$st['rel_path']);
            $pat_id       = $pat_id !== '' ? $pat_id : (string)$st['patient_id'];
            $pat_name     = $pat_name !== '' ? $pat_name : (string)($st['kanja_kanji'] ?? '');
            $exam_name    = $exam_name !== '' ? $exam_name : (string)$st['kensamei'];
            $t_part       = $st['study_time'] ? substr((string)$st['study_time'], 0, 5) : '';
            $exam_date    = $exam_date !== '' ? $exam_date : trim(str_replace('-', '/', (string)$st['study_date']) . ' ' . $t_part);
            $sindan       = $sindan !== '' ? $sindan : (string)($st['findings'] ?? '');
            $primary_type = (string)($st['primary_file_type'] ?? 'DICOM');
            if ($primary_type === 'PDF') {
                $fSt = $pdo->prepare("SELECT filename FROM study_files WHERE study_id = ? ORDER BY seq_no ASC LIMIT 1");
                $fSt->execute([$study_id]);
                $fn = $fSt->fetchColumn();
                if ($fn) $pdf_filename = (string)$fn;
            }
        }
    } catch (Throwable $e) {
    }
}

$display_date = preg_replace('/^(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}\s+\d{1,2}:\d{2})(?::\d{2})?.*$/', '$1', trim($exam_date));

// PDF検査レポート専用ビューワ
if ($primary_type === 'PDF' || ($folder !== '' && is_dir($folder) && count(glob(rtrim($folder, '\\/') . '/*.pdf') ?: []) > 0 && count(glob(rtrim($folder, '\\/') . '/*.{dcm,DCM,jpg,JPG,png,PNG}', GLOB_BRACE) ?: []) === 0)) {
    if ($study_id <= 0 && $folder !== '') {
        $pdfs = glob(rtrim($folder, '\\/') . '/*.pdf') ?: [];
        if (!empty($pdfs)) $pdf_filename = basename($pdfs[0]);
    }
    $pdf_url = 'dcm_api.php?action=pdf'
             . ($study_id > 0 ? '&study_id=' . $study_id : '&folder=' . rawurlencode($folder))
             . '&file=' . rawurlencode($pdf_filename);
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <title>PDF Report Viewer - <?= rsnext_h($pat_name ?: $pat_id) ?></title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { width: 100vw; height: 100vh; display: flex; flex-direction: column; background: #0b1120; color: #f8fafc; font-family: "Yu Gothic UI", Meiryo, sans-serif; overflow: hidden; }
            .bar { height: 46px; background: #0f172a; border-bottom: 1px solid #334155; display: flex; align-items: center; justify-content: space-between; padding: 0 14px; gap: 12px; }
            .meta { display: flex; align-items: center; gap: 10px; font-size: 0.88rem; }
            .pill { background: #1e3a8a; color: #bfdbfe; padding: 2px 8px; border-radius: 5px; font-family: monospace; font-weight: 700; }
            .kensa { background: #4c1d95; color: #ede9fe; padding: 2px 8px; border-radius: 5px; font-size: 0.78rem; font-weight: 700; }
            .btn { background: #1e293b; color: #f1f5f9; border: 1px solid #475569; padding: 5px 12px; border-radius: 6px; text-decoration: none; font-size: 0.82rem; font-weight: 700; cursor: pointer; }
            .btn-close { background: #991b1b; border-color: #dc2626; }
            iframe { flex: 1; width: 100%; border: none; background: #1e293b; }
        </style>
    </head>
    <body>
        <div class="bar">
            <div class="meta">
                <span class="pill">ID: <?= rsnext_h($pat_id) ?></span>
                <strong><?= rsnext_h($pat_name) ?></strong>
                <span class="kensa">📄 <?= rsnext_h($exam_name) ?></span>
                <span style="color:#94a3b8;font-size:0.8rem;"><?= rsnext_h($display_date) ?></span>
                <?php if ($sindan !== ''): ?>
                    <span style="color:#fcd34d;font-size:0.8rem;">💬 <?= rsnext_h($sindan) ?></span>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;">
                <a class="btn" href="<?= rsnext_h($pdf_url) ?>" target="_blank">↗ PDF原票 / 印刷</a>
                <button class="btn btn-close" onclick="closePdfViewer()" title="タブを閉じて一覧に戻る [Escキー]">✕ 閉じる</button>
            </div>
        </div>
        <iframe src="<?= rsnext_h($pdf_url) ?>"></iframe>
        <script>
        function closePdfViewer() {
            if (window.parent && window.parent !== window) {
                try {
                    if (typeof window.parent.closeViewerModal === 'function') {
                        window.parent.closeViewerModal();
                        return;
                    }
                } catch (_) {}
                window.parent.postMessage({ action: 'closeDicom' }, '*');
                return;
            }
            window.close();
            setTimeout(() => {
                if (!window.closed) {
                    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1 && window.history.length > 1) {
                        window.history.back();
                    } else {
                        window.location.href = 'index.php';
                    }
                }
            }, 150);
        }
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closePdfViewer();
        });
        </script>
    </body>
    </html>
    <?php
    exit;
}

$is_endoscopy_hint = (bool)preg_match('/内視鏡|胃|大腸|十二指腸|食道|直腸|結腸|咽頭|喉頭|気管支|GF|CF|IF|BF|ES|カメラ|スコープ|ESD|EMR|ERCP|ポリペク|生検|IEE|NBI|LCI/iu', $exam_name);
if (preg_match('/エコー|超音波|US|XP|CR|CT|MR|RF|透視|レントゲン|マンモ/iu', $exam_name)) {
    $is_endoscopy_hint = false;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DICOM Viewer - <?= htmlspecialchars($pat_name ? "{$pat_name} ({$pat_id})" : "RS-Next", ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --bg-base: #07090e;
            --bg-panel: #0f172a;
            --bg-panel-hover: #1e293b;
            --border-color: #334155;
            --accent-blue: #38bdf8;
            --accent-glow: rgba(56, 189, 248, 0.4);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            user-select: none;
            -webkit-user-select: none;
        }

        html, body {
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            background-color: var(--bg-base);
            color: var(--text-main);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            flex-direction: column;
        }

        /* --------------------------------------------------------------------------
           上部ヘッダーツールバー
        -------------------------------------------------------------------------- */
        .dcm-header {
            height: 38px;
            background: linear-gradient(180deg, #111827 0%, #0b0f19 100%);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 8px;
            z-index: 100;
            flex-shrink: 0;
            gap: 6px;
        }

        .dcm-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex-shrink: 1;
            min-width: 0;
        }

        .badge-id {
            background: #1e3a8a;
            color: #bfdbfe;
            padding: 1px 6px;
            border-radius: 4px;
            font-weight: 700;
            font-family: monospace;
            font-size: 0.8rem;
            border: 1px solid #3b82f6;
            white-space: nowrap;
        }

        .patient-name {
            font-weight: 700;
            color: #ffffff;
            font-size: 0.88rem;
            white-space: nowrap;
        }

        .exam-badge {
            background: #065f46;
            color: #a7f3d0;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 600;
            border: 1px solid #10b981;
            white-space: nowrap;
        }

        .exam-date, .exam-sindan {
            color: var(--text-muted);
            font-size: 0.75rem;
            white-space: nowrap;
        }

        /* コマ送り・ナビゲーション */
        .dcm-slice-nav {
            display: flex;
            align-items: center;
            background: #1e293b;
            border: 1px solid #475569;
            border-radius: 5px;
            overflow: hidden;
            height: 28px;
            flex-shrink: 0;
        }

        .nav-btn {
            background: transparent;
            border: none;
            color: var(--text-main);
            padding: 0 7px;
            height: 100%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
            font-size: 0.75rem;
        }

        .nav-btn:hover:not(:disabled) {
            background: #334155;
            color: var(--accent-blue);
        }

        .nav-btn:disabled {
            color: var(--text-dim);
            cursor: not-allowed;
        }

        .slice-label {
            padding: 0 6px;
            font-family: monospace;
            font-size: 0.78rem;
            font-weight: 600;
            color: #e2e8f0;
            white-space: nowrap;
        }

        /* 画面分割レイアウト切替ボタングループ */
        .layout-group {
            display: flex;
            align-items: center;
            background: #111827;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            overflow: hidden;
            padding: 1px;
            gap: 1px;
            flex-shrink: 0;
        }

        .layout-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 3px 6px;
            font-size: 0.75rem;
            border-radius: 3px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 2px;
            transition: all 0.15s;
            font-weight: 500;
            white-space: nowrap;
        }

        .layout-btn:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.05);
        }

        .layout-btn.active {
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
        }

        /* マウスモード切替ボタングループ */
        .tool-group {
            display: flex;
            align-items: center;
            background: #111827;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            overflow: hidden;
            padding: 1px;
            gap: 1px;
            flex-shrink: 0;
        }

        .mode-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 3px 6px;
            font-size: 0.75rem;
            border-radius: 3px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 3px;
            transition: all 0.15s;
            font-weight: 500;
            white-space: nowrap;
        }

        .mode-btn:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.05);
        }

        .mode-btn.active {
            background: #0284c7;
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
        }

        /* ツールアクションボタン */
        .dcm-actions {
            display: flex;
            align-items: center;
            gap: 3px;
            flex-shrink: 0;
        }

        .action-btn {
            background: #1e293b;
            border: 1px solid #475569;
            color: #e2e8f0;
            padding: 3px 7px;
            font-size: 0.76rem;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 3px;
            transition: all 0.15s;
            height: 28px;
            white-space: nowrap;
        }

        .action-btn:hover {
            background: #334155;
            color: #ffffff;
            border-color: #64748b;
        }

        .action-btn.active-toggle {
            background: #b45309;
            border-color: #f59e0b;
            color: #fef3c7;
        }

        .action-btn.active {
            background: #0284c7;
            border-color: #38bdf8;
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
        }

        .action-btn.btn-close {
            background: #991b1b;
            border-color: #dc2626;
            color: #fef2f2;
            font-weight: 700;
        }
        .action-btn.btn-close:hover {
            background: #b91c1c;
        }

        /* --------------------------------------------------------------------------
           メインワークスペース (ステージ ＋ 右側縦型サムネイル)
        -------------------------------------------------------------------------- */
        .dcm-workspace {
            display: flex;
            flex: 1;
            min-height: 0;
            position: relative;
            overflow: hidden;
        }

        /* CT/MRI マルチシリーズ・16bitウィンドウ・MPR断面サブツールバー */
        .ct-subbar {
            min-height: 36px;
            background: #0b1120;
            border-bottom: 1px solid #1e293b;
            display: none;
            align-items: center;
            justify-content: space-between;
            padding: 3px 10px;
            gap: 10px;
            flex-shrink: 0;
            overflow-x: auto;
            z-index: 95;
        }
        .ct-subbar-group {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }
        .ct-label {
            font-size: 0.72rem;
            color: #94a3b8;
            font-weight: 700;
            margin-right: 2px;
            white-space: nowrap;
        }
        .ser-pill, .wl-pill, .mpr-pill {
            background: #1e293b;
            border: 1px solid #334155;
            color: #cbd5e1;
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.74rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.14s;
        }
        .ser-pill:hover, .wl-pill:hover, .mpr-pill:hover {
            background: #334155;
            color: #fff;
        }
        .ser-pill.active {
            background: #0284c7;
            border-color: #38bdf8;
            color: #fff;
            font-weight: 700;
        }
        .wl-pill.active {
            background: #0d9488;
            border-color: #2dd4bf;
            color: #fff;
            font-weight: 700;
        }
        .mpr-pill.active {
            background: #7c3aed;
            border-color: #a78bfa;
            color: #fff;
            font-weight: 700;
        }

        .dcm-hu-canvas, .dcm-slot-video {
            display: block;
            user-select: none;
            -webkit-user-drag: none;
        }

        /* 断面スライススクラバー & シネ再生ドック */
        .tomo-dock, .video-dock {
            position: absolute;
            bottom: 12px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(15, 23, 42, 0.92);
            border: 1px solid rgba(56, 189, 248, 0.35);
            backdrop-filter: blur(8px);
            border-radius: 10px;
            padding: 6px 14px;
            display: none;
            align-items: center;
            gap: 10px;
            z-index: 60;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.65);
            min-width: 480px;
            max-width: 82%;
        }
        .tomo-slider, .video-slider {
            flex: 1;
            min-width: 200px;
            accent-color: #38bdf8;
            cursor: pointer;
        }
        .dock-mini-btn {
            background: #1e293b;
            border: 1px solid #475569;
            color: #f1f5f9;
            padding: 3px 9px;
            border-radius: 5px;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }
        .dock-mini-btn:hover { background: #334155; }
        .dock-mini-btn.active { background: #0284c7; border-color: #38bdf8; color: #fff; }
        .thumb-vid-badge {
            position: absolute;
            bottom: 4px;
            right: 4px;
            background: rgba(13, 148, 136, 0.92);
            color: #fff;
            font-size: 0.66rem;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 4px;
            pointer-events: none;
        }

        /* メイン画像ステージ (CSS Grid による分割表示) */
        .dcm-stage {
            flex: 1;
            height: 100%;
            position: relative;
            background: #030712;
            overflow: hidden;
            cursor: crosshair;
            display: grid;
            gap: 2px;
        }

        /* 1面: 1x1 */
        .dcm-stage.layout-1 {
            grid-template-columns: 1fr;
            grid-template-rows: 1fr;
        }
        .dcm-stage.layout-1 .dcm-viewport[data-slot="1"],
        .dcm-stage.layout-1 .dcm-viewport[data-slot="2"],
        .dcm-stage.layout-1 .dcm-viewport[data-slot="3"] {
            display: none;
        }

        /* 2面: 1行2列 (1x2 左右並び) */
        .dcm-stage.layout-2 {
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr;
        }
        .dcm-stage.layout-2 .dcm-viewport[data-slot="2"],
        .dcm-stage.layout-2 .dcm-viewport[data-slot="3"] {
            display: none;
        }

        /* 4面: 2行2列 (2x2 田の字並び) */
        .dcm-stage.layout-4 {
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
        }

        .dcm-viewport {
            position: relative;
            overflow: hidden;
            background: radial-gradient(circle at center, #0f172a 0%, #05070c 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            width: 100%;
            height: 100%;
        }

        .dcm-viewport .dcm-img-wrapper {
            position: absolute;
            left: 0;
            top: 0;
            transform-origin: 0 0;
            will-change: transform;
            pointer-events: none;
        }

        .dcm-viewport .dcm-slot-img {
            display: block;
            max-width: none;
            pointer-events: none;
            transition: filter 0.05s ease-out;
        }

        .slot-badge {
            display: none !important;
        }

        .slot-empty-msg {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #64748b;
            font-size: 0.9rem;
            display: none;
            pointer-events: none;
            font-family: sans-serif;
        }
        .dcm-viewport.is-empty .slot-empty-msg {
            display: block;
        }
        .dcm-viewport.is-empty .slot-badge {
            display: none;
        }
        .dcm-viewport.is-empty .dcm-slot-img {
            display: none;
        }

        /* 浮遊ナビゲーション大型矢印 (左右) */
        .float-nav-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 44px;
            height: 72px;
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.2s;
            z-index: 40;
            backdrop-filter: blur(4px);
        }

        .float-nav-btn:hover:not(:disabled) {
            background: rgba(14, 165, 233, 0.7);
            color: #ffffff;
            border-color: #38bdf8;
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.5);
        }

        .float-nav-btn:disabled {
            opacity: 0.15;
            cursor: not-allowed;
        }

        .float-prev { left: 16px; }
        .float-next { right: 16px; }

        /* 左下HUD (パラメータ表示) */
        .dcm-hud {
            position: absolute;
            bottom: 12px;
            left: 12px;
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(6px);
            padding: 6px 12px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.75rem;
            color: var(--text-muted);
            pointer-events: none;
            z-index: 30;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .dcm-hud span strong {
            color: var(--accent-blue);
        }

        /* 操作ヘルプガイド (右下フローティング) */
        .mouse-guide {
            position: absolute;
            bottom: 12px;
            right: 12px;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(4px);
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            color: var(--text-dim);
            pointer-events: none;
            z-index: 30;
            line-height: 1.4;
        }

        /* ローディングオーバーレイ */
        .dcm-loader {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(7, 9, 14, 0.65);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 12px;
            z-index: 50;
            pointer-events: none; /* 下部ドックやツールバーへのクリック入力を遮断しない */
        }

        .dcm-spinner {
            width: 44px;
            height: 44px;
            border: 4px solid rgba(56, 189, 248, 0.2);
            border-top: 4px solid var(--accent-blue);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* --------------------------------------------------------------------------
           右側・縦型サムネイルパネル (横長画面最適化)
        -------------------------------------------------------------------------- */
        .dcm-sidebar {
            width: 150px;
            background: var(--bg-panel);
            border-left: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            z-index: 45;
            flex-shrink: 0;
            transition: width 0.2s ease;
            height: 100%;
        }

        .dcm-sidebar.collapsed {
            width: 0;
            overflow: hidden;
            border-left: none;
        }

        .sidebar-header {
            height: 36px;
            padding: 0 10px;
            background: #111827;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-muted);
            flex-shrink: 0;
        }

        .sidebar-header .toggle-btn {
            background: transparent;
            border: none;
            color: var(--text-dim);
            cursor: pointer;
            font-size: 0.9rem;
            padding: 2px 4px;
            border-radius: 3px;
        }
        .sidebar-header .toggle-btn:hover {
            color: var(--text-main);
            background: #1e293b;
        }

        .filmstrip-list {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        /* スクロールバーのカスタマイズ */
        .filmstrip-list::-webkit-scrollbar {
            width: 6px;
        }
        .filmstrip-list::-webkit-scrollbar-track {
            background: #0b0f19;
        }
        .filmstrip-list::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 3px;
        }
        .filmstrip-list::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }

        /* 📸 サムネイルカード: flex-shrink:0 で枚数が多くても絶対に潰れない */
        .thumb-card {
            width: 100%;
            height: 125px;
            min-height: 125px;
            flex-shrink: 0;
            background: #020617;
            border: 2px solid #334155;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
            cursor: pointer;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3px;
            box-sizing: border-box;
        }

        .thumb-card:hover {
            border-color: #64748b;
            transform: translateY(-1px);
        }

        .thumb-card.active {
            border-color: var(--accent-blue);
            box-shadow: 0 0 12px var(--accent-glow);
            background: #0b1329;
        }

        /* 🖼️ 画像縦横比(アスペクト比)を完全維持 & はみ出しトリミング防止 */
        .thumb-card img {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
            display: block;
            border-radius: 3px;
        }

        .thumb-badge {
            position: absolute;
            bottom: 3px;
            right: 4px;
            background: rgba(0, 0, 0, 0.75);
            color: #f1f5f9;
            font-family: monospace;
            font-size: 0.68rem;
            padding: 1px 5px;
            border-radius: 3px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            font-weight: 600;
        }

        /* 🖨️ 印刷トレイボタン (サムネイル上) */
        .thumb-tray-btn {
            position: absolute;
            top: 4px;
            right: 4px;
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid #10b981;
            color: #34d399;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            cursor: pointer;
            z-index: 10;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
        }
        .thumb-tray-btn:hover {
            background: #059669;
            color: #ffffff;
            transform: scale(1.08);
        }
        .thumb-tray-btn.in-tray {
            background: #10b981;
            color: #ffffff;
            border-color: #34d399;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.7);
        }
        .thumb-card.in-tray {
            border-color: #10b981 !important;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.4);
        }

        /* サイドバートグル復帰ボタン (ステージ右端) */
        .sidebar-reopen-btn {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 48px;
            background: #1e293b;
            border: 1px solid var(--border-color);
            border-right: none;
            color: var(--text-muted);
            border-radius: 4px 0 0 4px;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            z-index: 44;
        }
        .sidebar-reopen-btn:hover {
            background: #334155;
            color: #ffffff;
        }

        /* --------------------------------------------------------------------------
           画面下部・内視鏡病変強調ドック (IEE: Image-Enhanced Endoscopy)
        -------------------------------------------------------------------------- */
        .iee-dock {
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: 4px;
            background: rgba(15, 23, 42, 0.92);
            border: 1px solid rgba(56, 189, 248, 0.35);
            border-bottom: none;
            border-radius: 12px 12px 0 0;
            backdrop-filter: blur(8px);
            padding: 2px 8px;
            box-shadow: 0 -2px 12px rgba(0, 0, 0, 0.6);
            z-index: 60; /* ローダー(z-index:50)やHUDより前面に常時配置しクリック不能化を防止 */
            user-select: none;
        }

        .iee-label {
            font-size: 0.72rem;
            color: #7dd3fc;
            font-weight: 700;
            padding: 0 3px;
            display: flex;
            align-items: center;
            gap: 2px;
            white-space: nowrap;
        }

        .iee-btn {
            background: #1e293b;
            border: 1px solid #334155;
            color: #cbd5e1;
            padding: 2px 7px;
            font-size: 0.72rem;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 3px;
            font-weight: 600;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .iee-btn:hover {
            color: #ffffff;
            background: #334155;
            border-color: #64748b;
            transform: translateY(-1px);
        }

        .iee-btn.active {
            background: #0284c7;
            border-color: #38bdf8;
            color: #ffffff;
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.5);
            font-weight: 700;
        }

        @media (max-width: 960px) {
            .mouse-guide {
                display: none;
            }
        }

        /* 範囲指定・クリップボードコピー用 オーバーレイ */
        .crop-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 70;
            cursor: crosshair;
            user-select: none;
        }

        .crop-guide-pill {
            position: absolute;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid var(--accent-blue);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.7);
            color: #ffffff;
            padding: 8px 18px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 14px;
            pointer-events: auto;
            z-index: 75;
        }

        .crop-cancel-btn {
            background: rgba(239, 68, 68, 0.85);
            border: none;
            color: #ffffff;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s;
        }
        .crop-cancel-btn:hover {
            background: #dc2626;
        }

        .crop-box {
            position: absolute;
            border: 2px dashed #38bdf8;
            background: rgba(56, 189, 248, 0.15);
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.45);
            pointer-events: none;
            z-index: 72;
        }

        .crop-dim-badge {
            position: absolute;
            bottom: -26px;
            right: 0;
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid var(--accent-blue);
            color: #7dd3fc;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-family: monospace;
            white-space: nowrap;
        }

        /* キャプチャ画像プレビュー / フォールバックモーダル */
        .copy-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.78);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .copy-modal-card {
            background: #0f172a;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            max-width: 90vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.8);
            overflow: hidden;
        }

        .copy-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 16px;
            background: #1e293b;
            border-bottom: 1px solid var(--border-color);
        }

        .copy-modal-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #e2e8f0;
        }

        .copy-modal-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.1rem;
            cursor: pointer;
        }
        .copy-modal-close:hover {
            color: #ffffff;
        }

        .copy-modal-body {
            padding: 14px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .copy-modal-msg {
            font-size: 0.85rem;
            color: #94a3b8;
            margin: 0;
            text-align: center;
            line-height: 1.5;
        }

        .copy-preview-wrap {
            max-width: 80vw;
            max-height: 60vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000000;
            border: 1px solid #334155;
            border-radius: 6px;
            overflow: hidden;
        }

        .copy-preview-img {
            max-width: 100%;
            max-height: 60vh;
            object-fit: contain;
            display: block;
        }

        .copy-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 10px 16px;
            background: #1e293b;
            border-top: 1px solid var(--border-color);
        }

        /* トースト通知 (ドック上部に浮遊表示) */
        #dcmToast {
            position: fixed;
            bottom: 60px;
            left: 50%;
            transform: translateX(-50%) translateY(50px);
            background: rgba(15, 23, 42, 0.95);
            color: #ffffff;
            border: 1px solid var(--accent-blue);
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 0.85rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.6);
            opacity: 0;
            transition: all 0.25s ease;
            z-index: 999;
            pointer-events: none;
        }
        #dcmToast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
    </style>
</head>
<body>

    <!-- ----------------------------------------------------------------------
         上部ヘッダー
    ----------------------------------------------------------------------- -->
    <header class="dcm-header">
        <div class="dcm-meta">
            <span class="badge-id">ID: <?= htmlspecialchars($pat_id ?: '----', ENT_QUOTES, 'UTF-8') ?></span>
            <span class="patient-name"><?= htmlspecialchars($pat_name ?: '患者未指定', ENT_QUOTES, 'UTF-8') ?> 様</span>
            <?php if ($exam_name): ?>
                <span class="exam-badge"><?= htmlspecialchars($exam_name, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <span class="exam-date"><?= htmlspecialchars($display_date, ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($sindan): ?>
                <span class="exam-sindan" title="<?= htmlspecialchars($sindan, ENT_QUOTES, 'UTF-8') ?>">
                    [<?= htmlspecialchars(mb_strimwidth($sindan, 0, 20, '...'), ENT_QUOTES, 'UTF-8') ?>]
                </span>
            <?php endif; ?>
        </div>

        <!-- スライス移動ナビ -->
        <div class="dcm-slice-nav">
            <button type="button" class="nav-btn" id="navPrevBtn" title="前コマ [←キー / ホイール上]">◀</button>
            <span class="slice-label" id="sliceNavLabel">0 / 0</span>
            <button type="button" class="nav-btn" id="navNextBtn" title="次コマ [→キー / ホイール下]">▶</button>
        </div>

        <!-- 画面分割レイアウト切替 -->
        <div class="layout-group" id="layoutGroup">
            <button type="button" class="layout-btn active" data-layout="1" title="1画面表示 [1キー]">
                1面
            </button>
            <button type="button" class="layout-btn" data-layout="2" title="2画面横並び表示 [2キー]">
                2面
            </button>
            <button type="button" class="layout-btn" data-layout="4" title="4画面田の字表示 [4キー]">
                4面
            </button>
        </div>

        <!-- マウス操作モード切替 (左ドラッグの動作) -->
        <div class="tool-group">
            <button type="button" class="mode-btn active" id="modeWwWl" title="左ドラッグ: 輝度(WL)・コントラスト(WW)調整">
                🌓 階調
            </button>
            <button type="button" class="mode-btn" id="modePan" title="左ドラッグ: 画像の平行移動 (パン)">
                ✋ 移動
            </button>
            <button type="button" class="mode-btn" id="modeZoom" title="左ドラッグ: 拡大・縮小 (ズーム)">
                🔍 ズーム
            </button>
            <button type="button" class="mode-btn" id="modeRuler" title="左ドラッグ: 実寸距離計測 (mm) [Mキー]">
                📏 計測
            </button>
        </div>

        
        <!-- アクションツール -->
        <div class="dcm-actions">
            <button type="button" class="action-btn active" id="btnFit" title="全体フィット: 画像全体を枠内に収める [Fキー]">
                ⏹️全体
            </button>
            <button type="button" class="action-btn" id="btnOlympusFit" style="<?= $is_endoscopy_hint ? '' : 'display:none;' ?>" title="オリンパスフィット: 左側文字情報を除外し、円形観察領域を中心に画面一杯に拡大表示 [Oキー / ダブルクリック]">
                ⏹️O社
            </button>
            <button type="button" class="action-btn" id="btnOneToOne" title="原寸大 (1:1) で表示">
                1:1
            </button>
            <button type="button" class="action-btn" id="btnInvert" title="白黒反転 [Iキー]">
                🌓 反転
            </button>
            <button type="button" class="action-btn" id="btnReset" title="位置・ズーム・階調をリセット [Rキー]">
                🔄 リセット
            </button>
            <button type="button" class="action-btn" id="btnCopy" title="クリップボードにコピー (画面全体または範囲指定ドラッグ) [Cキー / Ctrl+C]">
                📋 コピー
            </button>
            <button type="button" class="action-btn" id="btnAddToTray" title="現在表示中の画像を2×4印刷トレイに追加 [Pキー]" style="background: rgba(16, 185, 129, 0.15); border-color: #10b981; color: #6ee7b7; font-weight: 600;">
                ➕ トレイ追加
            </button>
            <button type="button" class="action-btn" id="btnOpenPrintTray" title="2×4画像印刷画面を開く" style="background: #065f46; border-color: #34d399; color: #ffffff; font-weight: 700;">
                🖨️ 2×4印刷 (<span id="trayCountBadge">0</span>/8)
            </button>
            <button type="button" class="action-btn" id="btnDownloadZip" title="JPEG一括ZIPダウンロード">
                📦
            </button>
            <button type="button" class="action-btn" id="btnDirectLaunch" title="外部DICOM専用ビューワを直接起動">
                🚀
            </button>
            <?php if ($mode === 'modal'): ?>
                <button type="button" class="action-btn" onclick="window.parent.postMessage({ action: 'openViewerNewTab' }, '*')" title="新しいタブで開く">
                    ↗
                </button>
                <button type="button" class="action-btn" id="btnPopout" title="新しいウィンドウで大画面表示">
                    🪟
                </button>
                <button type="button" class="action-btn btn-close" id="btnCloseModal" title="閉じる [Escキー]">
                    ✕ 閉じる
                </button>
            <?php else: ?>
                <button type="button" class="action-btn" id="btnPopout" title="独立ウィンドウで開く（マルチモニタ比較用）">
                    🪟
                </button>
                <a href="index.php" class="action-btn" style="text-decoration:none; box-sizing:border-box;" title="本日の検査一覧（ホーム）へ移動">
                    🏠 本日一覧
                </a>
                <button type="button" class="action-btn btn-close" id="btnCloseModal" title="このビューワタブを閉じて一覧に戻る [Escキー]">
                    ✕ 閉じる
                </button>
            <?php endif; ?>
        </div>
    </header>

    <!-- CT / MRI マルチシリーズ・16bit HUウィンドウ・MPR断面サブツールバー -->
    <div class="ct-subbar" id="ctMriSubbar">
        <div class="ct-subbar-group" id="seriesBarGroup">
            <span class="ct-label">📚 シリーズ:</span>
            <div id="seriesPillsBox" style="display:flex;gap:4px;"></div>
        </div>
        <div class="ct-subbar-group" id="wlPresetsGroup" style="display:none;">
            <span class="ct-label">🪟 CT/MR階調:</span>
            <button type="button" class="wl-pill active" data-wl="auto" data-ww="auto" onclick="applyHuPreset('auto','auto',this)">既定</button>
            <button type="button" class="wl-pill" data-wl="40" data-ww="350" onclick="applyHuPreset(40,350,this)">腹部・軟部</button>
            <button type="button" class="wl-pill" data-wl="-600" data-ww="1500" onclick="applyHuPreset(-600,1500,this)">肺野</button>
            <button type="button" class="wl-pill" data-wl="60" data-ww="160" onclick="applyHuPreset(60,160,this)">肝・造影</button>
            <button type="button" class="wl-pill" data-wl="400" data-ww="1800" onclick="applyHuPreset(400,1800,this)">骨条件</button>
            <button type="button" class="wl-pill" data-wl="40" data-ww="80" onclick="applyHuPreset(40,80,this)">脳実質</button>
        </div>
        <div class="ct-subbar-group" id="mprGroup" style="display:none;">
            <span class="ct-label">🧊 断面(MPR):</span>
            <button type="button" class="mpr-pill active" data-plane="native" onclick="switchMprPlane('native')">元断面 (Axial)</button>
            <button type="button" class="mpr-pill" data-plane="coronal" onclick="switchMprPlane('coronal')">冠状断 (Coronal)</button>
            <button type="button" class="mpr-pill" data-plane="sagittal" onclick="switchMprPlane('sagittal')">矢状断 (Sagittal)</button>
        </div>
        <div class="ct-subbar-group" style="margin-left:auto;">
            <button type="button" class="wl-pill" style="background:#0284c7;border-color:#38bdf8;color:#ffffff;font-weight:800;" onclick="document.getElementById('btnDirectLaunch').click()" title="クライアントPCのローカルRSViewerで高速閲覧（共有フォルダ不要）">🚀 RSViewer (PCアプリ) で開く</button>
        </div>
    </div>

    <!-- ----------------------------------------------------------------------
         メインワークスペース
    ----------------------------------------------------------------------- -->
    <main class="dcm-workspace">
        <!-- 画像ステージ -->
        <div class="dcm-stage layout-1" id="dcmImageStage">
            <!-- スロット 0 (主画面 / 1面時: 2D画像・16bit HU Canvas・動画 統合ビューポート) -->
            <div class="dcm-viewport" data-slot="0">
                <div class="dcm-img-wrapper" id="dcmImgWrapper">
                    <img class="dcm-slot-img" id="dcmMainImage" src="" alt="DICOM Main" draggable="false">
                    <canvas class="dcm-hu-canvas" id="dcmHuCanvas" style="display:none;"></canvas>
                    <video class="dcm-slot-video" id="dcmMainVideo" playsinline preload="metadata" style="display:none;"></video>
                </div>
                <svg id="dcmRulerSvg" style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:25;"></svg>
                <div class="slot-badge" id="badgeSlot0">#1</div>
                <div class="slot-empty-msg">(画像なし)</div>
            </div>

            <!-- スロット 1 (2面・4面時) -->
            <div class="dcm-viewport" data-slot="1">
                <div class="dcm-img-wrapper">
                    <img class="dcm-slot-img" src="" alt="" draggable="false">
                </div>
                <div class="slot-badge" id="badgeSlot1">#2</div>
                <div class="slot-empty-msg">(画像なし)</div>
            </div>

            <!-- スロット 2 (4面時) -->
            <div class="dcm-viewport" data-slot="2">
                <div class="dcm-img-wrapper">
                    <img class="dcm-slot-img" src="" alt="" draggable="false">
                </div>
                <div class="slot-badge" id="badgeSlot2">#3</div>
                <div class="slot-empty-msg">(画像なし)</div>
            </div>

            <!-- スロット 3 (4面時) -->
            <div class="dcm-viewport" data-slot="3">
                <div class="dcm-img-wrapper">
                    <img class="dcm-slot-img" src="" alt="" draggable="false">
                </div>
                <div class="slot-badge" id="badgeSlot3">#4</div>
                <div class="slot-empty-msg">(画像なし)</div>
            </div>

            <!-- 左右大型フローティング矢印 -->
            <button type="button" class="float-nav-btn float-prev" id="floatPrevBtn" title="前コマ [←]">◀</button>
            <button type="button" class="float-nav-btn float-next" id="floatNextBtn" title="次コマ [→]">▶</button>

            <!-- 左下HUD -->
            <div class="dcm-hud" id="dcmHud">
                <span>ファイル: <strong id="hudFilename">--</strong></span>
                <span>解像度: <strong id="hudResolution">--</strong></span>
                <span>倍率: <strong id="hudZoom">100%</strong></span>
                <span>階調: <strong id="hudWwWl">C:100% B:100%</strong></span>
                <span id="hudHuWrap" style="display:none;">画素値: <strong id="hudHuVal" style="color:#38bdf8;">-- HU</strong></span>
                <span id="hudLocWrap" style="display:none;">位置: <strong id="hudSliceLoc">--</strong></span>
            </div>

            <!-- 右下マウス操作ガイド -->
            <div class="mouse-guide">
                🖱️ <strong>標準マウス操作</strong><br>
                ・左ドラッグ: 階調(WW/WL)<br>
                ・中ドラッグ: 平行移動<br>
                ・右ドラッグ: ズーム<br>
                ・ホイール: コマ送り
            </div>

            <!-- ローディング表示 -->
            <div class="dcm-loader" id="dcmLoader">
                <div class="dcm-spinner"></div>
                <div style="font-size:0.85rem; color:#94a3b8;">DICOM画像を読み込み中...</div>
            </div>

            <!-- 画面下部 内視鏡病変強調ドック (IEE) -->
            <div class="iee-dock" id="ieeDock" style="<?= $is_endoscopy_hint ? '' : 'display:none;' ?>" title="内視鏡 病巣・微細血管強調モード">
                <span class="iee-label">🔬 病巣強調:</span>
                <button type="button" class="iee-btn active" data-mode="normal" title="通常表示 (白色光)">
                    🌈 通常
                </button>
                <button type="button" class="iee-btn" data-mode="lci" title="発赤強調 (LCI風): 早期胃癌・ポリープ・炎症の境界を赤と白の対比で極限まで浮き彫りにする">
                    🔴 発赤(LCI)
                </button>
                <button type="button" class="iee-btn" data-mode="txi" title="自然強調 (TXI風): 明るさを保ったまま凹凸テクスチャと微小な色調変化(発赤/淡い変化)を強調">
                    🔆 自然(TXI)
                </button>
                <button type="button" class="iee-btn" data-mode="pale" title="褪色強調 (Pale/SSA/P): 見逃しやすい白っぽい平坦病変・粘液付着・鋸歯状病変の境界を強調">
                    ⚪ 褪色(SSA/P)
                </button>
                <button type="button" class="iee-btn" data-mode="nbi" title="血管強調 (NBI風): 青緑色光効果で微細血管や腺管ピットパターンを茶褐色に際立たせる">
                    🟢 血管(NBI)
                </button>
                <button type="button" class="iee-btn" data-mode="ihb" title="血流マップ (IHb): ヘモグロビン濃度を強調し充血・病巣部をサーモグラフィ風に可視化">
                    📊 血流マップ
                </button>
                <button type="button" class="iee-btn" data-mode="texture" title="凹凸・構造強調: 粘膜のひだ・境界・段差の輪郭をシャープに強調">
                    🏔️ 構造強調
                </button>
                <button type="button" class="iee-btn" data-mode="indigo" title="色素散布シミュレーション: インジゴカルミンを散布したかのように凹部の段差を強調">
                    🔵 色素散布
                </button>
            </div>

            <!-- 画面下部 断面スライススクラバー & シネ再生ドック (CT/MRI・連続透視用) -->
            <div class="tomo-dock" id="tomoScrubDock">
                <button type="button" class="dock-mini-btn" id="btnCineToggle" onclick="toggleCinePlay()" title="連続シネ再生 / 停止 [Spaceキー]">▶ シネ再生</button>
                <select id="cineFpsSelect" class="dock-mini-btn" onchange="onCineFpsChange()" title="シネ再生フレームレート">
                    <option value="6">6 fps</option>
                    <option value="12" selected>12 fps</option>
                    <option value="20">20 fps</option>
                    <option value="30">30 fps</option>
                </select>
                <input type="range" class="tomo-slider" id="tomoScrubRange" min="0" max="0" value="0" oninput="onTomoScrubInput(this.value)">
                <span id="tomoScrubLabel" style="font-family:monospace;font-size:0.78rem;color:#e2e8f0;min-width:68px;text-align:right;">1 / 1</span>
                <button type="button" class="dock-mini-btn" id="btnPlayCineMp4" style="display:none;background:#0d9488;border-color:#2dd4bf;" onclick="switchToCineMp4Mode()" title="マルチフレームDICOMを動画プレイヤーで滑らか再生">🎬 動画モード</button>
            </div>

            <!-- 画面下部 臨床動画プレイヤードック (MP4 / AVI / MOV / MPG / マルチフレーム動画用) -->
            <div class="video-dock" id="videoControlDock">
                <button type="button" class="dock-mini-btn" id="btnVidPlay" onclick="toggleVideoPlay()" title="再生 / 一時停止 [Spaceキー]">▶ 再生</button>
                <button type="button" class="dock-mini-btn" onclick="stepVideoFrame(-1)" title="1コマ戻る [一時停止中 ← または , キー]">◀1コマ</button>
                <button type="button" class="dock-mini-btn" onclick="stepVideoFrame(1)" title="1コマ進む [一時停止中 → または . キー]">1コマ▶</button>
                <input type="range" class="video-slider" id="videoSeekRange" min="0" max="1000" value="0" oninput="onVideoSeekInput(this.value)">
                <span id="videoTimeLabel" style="font-family:monospace;font-size:0.76rem;color:#e2e8f0;min-width:96px;text-align:center;">00:00.0 / 00:00.0</span>
                <select id="videoRateSelect" class="dock-mini-btn" onchange="setVideoRate(this.value)" title="再生速度">
                    <option value="0.25">0.25x</option>
                    <option value="0.5">0.5x</option>
                    <option value="1" selected>1.0x</option>
                    <option value="1.5">1.5x</option>
                    <option value="2">2.0x</option>
                </select>
                <button type="button" class="dock-mini-btn active" id="btnVidLoop" onclick="toggleVideoLoop()" title="ループ再生">🔁</button>
            </div>

            <!-- サイドバー再オープンボタン -->
            <button type="button" class="sidebar-reopen-btn" id="sidebarReopenBtn" title="サムネイル一覧を開く">◀</button>

            <!-- 範囲指定・クリップボードコピー用 オーバーレイ -->
            <div class="crop-overlay" id="dcmCropOverlay" style="display: none;">
                <div class="crop-guide-pill" id="cropGuidePill">
                    <span>✂️ コピーしたい領域をドラッグ (クリックのみで全体コピー)</span>
                    <button type="button" class="crop-cancel-btn" id="cropCancelBtn" title="キャンセル [Esc]">✕</button>
                </div>
                <div class="crop-box" id="dcmCropBox" style="display: none;">
                    <span class="crop-dim-badge" id="cropDimBadge">0 × 0</span>
                </div>
            </div>
        </div>

        <!-- 右側・縦型サムネイルパネル -->
        <aside class="dcm-sidebar" id="dcmSidebar">
            <div class="sidebar-header">
                <span>サムネイル (<span id="thumbTotalCount">0</span>)</span>
                <button type="button" class="toggle-btn" id="btnToggleSidebar" title="パネルを閉じる">▶</button>
            </div>
            <div class="filmstrip-list" id="dcmFilmstripList">
                <!-- サムネイルカードが動的生成されます -->
            </div>
        </aside>
    </main>

    <!-- SVGフィルター定義 (内視鏡画像強調用: GPUハードウェアアクセラレーション) -->
    <!-- 注意: display:none はブラウザ(Chrome/Edge)でレンダリングツリーから除外されフィルター解除やキャッシュ無効化に不具合を起こすため、不可視かつレンダリングツリーに残るスタイルを使用 -->
    <svg style="position: absolute; width: 0; height: 0; overflow: hidden; pointer-events: none;" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <!-- ① LCI風・発赤強調 (赤色分離度最大化) -->
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

            <!-- ② 疑似NBI風・微細血管/ピット強調 (青緑光再マッピング) -->
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

            <!-- ③ ヘモグロビン血流マップ (IHb: 充血・新生血管サーモグラフィ) -->
            <filter id="iee-ihb" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="
                    1.80 -0.80 -0.10 0 0.10
                   -0.40  1.40 -0.10 0 -0.05
                   -0.80 -0.20  1.80 0 -0.10
                    0     0     0    1 0" />
            </filter>

            <!-- ④ 凹凸・構造強調 (Texture / Edge Sharpening) -->
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
            <!-- ⑥ TXI風・自然強調 (明るさを維持した凹凸構造＋微細色差強調) -->
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

            <!-- ⑦ 褪色・平坦病変強調 (Pale / SSA/P: 白っぽい平坦病変・鋸歯状病変の境界抽出) -->
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
    <div id="dcmToast">メッセージ</div>

    <!-- コピーフォールバック / プレビューモーダル -->
    <div class="copy-modal" id="copyFallbackModal" style="display: none;">
        <div class="copy-modal-card">
            <div class="copy-modal-header">
                <span class="copy-modal-title" id="copyModalTitle">📋 画像キャプチャ完了</span>
                <button type="button" class="copy-modal-close" id="copyModalCloseBtn">✕</button>
            </div>
            <div class="copy-modal-body">
                <p id="copyModalMsg" class="copy-modal-msg"></p>
                <div class="copy-preview-wrap">
                    <img id="copyPreviewImg" class="copy-preview-img" alt="Capture Preview">
                </div>
            </div>
            <div class="copy-modal-footer">
                <button type="button" class="action-btn" id="copyModalDownloadBtn">💾 PNG再保存</button>
                <button type="button" class="action-btn" id="copyModalOkBtn" style="background:#0284c7; border-color:#38bdf8; font-weight:700;">OK (閉じる)</button>
            </div>
        </div>
    </div>

    <!-- ----------------------------------------------------------------------
         JavaScript制御ロジック
    ----------------------------------------------------------------------- -->
    <script>
        // 設定 & パラメータ
        const STUDY_ID    = <?= (int)$study_id ?>;
        const FOLDER_PATH = <?= json_encode($folder, JSON_UNESCAPED_UNICODE) ?>;
        const PAT_ID      = <?= json_encode($pat_id, JSON_UNESCAPED_UNICODE) ?>;
        const PAT_NAME    = <?= json_encode($pat_name, JSON_UNESCAPED_UNICODE) ?>;
        const EXAM_NAME   = <?= json_encode($exam_name, JSON_UNESCAPED_UNICODE) ?>;
        const EXAM_DATE   = <?= json_encode($exam_date, JSON_UNESCAPED_UNICODE) ?>;
        const EXAM_SINDAN = <?= json_encode($sindan, JSON_UNESCAPED_UNICODE) ?>;
        const IS_MODAL    = <?= json_encode($mode === 'modal') ?>;
        const IS_ENDOSCOPY_HINT = <?= json_encode($is_endoscopy_hint) ?>;

        // DOM要素
        const stage = document.getElementById('dcmImageStage');
        const viewports = Array.from(stage.querySelectorAll('.dcm-viewport'));
        const loader = document.getElementById('dcmLoader');
        const filmstrip = document.getElementById('dcmFilmstripList');
        const sliceNavLabel = document.getElementById('sliceNavLabel');
        const thumbTotalCount = document.getElementById('thumbTotalCount');
        const navPrevBtn = document.getElementById('navPrevBtn');
        const navNextBtn = document.getElementById('navNextBtn');
        const floatPrevBtn = document.getElementById('floatPrevBtn');
        const floatNextBtn = document.getElementById('floatNextBtn');
        const toast = document.getElementById('dcmToast');

        const hudFilename = document.getElementById('hudFilename');
        const hudResolution = document.getElementById('hudResolution');
        const hudZoom = document.getElementById('hudZoom');
        const hudWwWl = document.getElementById('hudWwWl');

        const modeWwWlBtn = document.getElementById('modeWwWl');
        const modePanBtn = document.getElementById('modePan');
        const modeZoomBtn = document.getElementById('modeZoom');
        const modeRulerBtn = document.getElementById('modeRuler');

        const dcmHuCanvas = document.getElementById('dcmHuCanvas');
        const huCtx = dcmHuCanvas ? dcmHuCanvas.getContext('2d') : null;
        const dcmMainVideo = document.getElementById('dcmMainVideo');
        const dcmRulerSvg = document.getElementById('dcmRulerSvg');
        const ctMriSubbar = document.getElementById('ctMriSubbar');
        const seriesPillsBox = document.getElementById('seriesPillsBox');
        const wlPresetsGroup = document.getElementById('wlPresetsGroup');
        const mprGroup = document.getElementById('mprGroup');
        const tomoScrubDock = document.getElementById('tomoScrubDock');
        const tomoScrubRange = document.getElementById('tomoScrubRange');
        const tomoScrubLabel = document.getElementById('tomoScrubLabel');
        const btnCineToggle = document.getElementById('btnCineToggle');
        const cineFpsSelect = document.getElementById('cineFpsSelect');
        const btnPlayCineMp4 = document.getElementById('btnPlayCineMp4');
        const videoControlDock = document.getElementById('videoControlDock');
        const btnVidPlay = document.getElementById('btnVidPlay');
        const videoSeekRange = document.getElementById('videoSeekRange');
        const videoTimeLabel = document.getElementById('videoTimeLabel');
        const btnVidLoop = document.getElementById('btnVidLoop');
        const hudHuWrap = document.getElementById('hudHuWrap');
        const hudHuVal = document.getElementById('hudHuVal');
        const hudLocWrap = document.getElementById('hudLocWrap');
        const hudSliceLoc = document.getElementById('hudSliceLoc');

        const btnFit = document.getElementById('btnFit');
        const btnOlympusFit = document.getElementById('btnOlympusFit');
        const btnOneToOne = document.getElementById('btnOneToOne');
        const btnInvert = document.getElementById('btnInvert');
        const btnReset = document.getElementById('btnReset');
        const btnCopy = document.getElementById('btnCopy');
        const btnAddToTray = document.getElementById('btnAddToTray');
        const btnOpenPrintTray = document.getElementById('btnOpenPrintTray');
        const trayCountBadge = document.getElementById('trayCountBadge');
        const btnDownloadZip = document.getElementById('btnDownloadZip');
        const btnDirectLaunch = document.getElementById('btnDirectLaunch');
        const btnPopout = document.getElementById('btnPopout');
        const btnCloseModal = document.getElementById('btnCloseModal');

        const dcmCropOverlay = document.getElementById('dcmCropOverlay');
        const dcmCropBox = document.getElementById('dcmCropBox');
        const cropDimBadge = document.getElementById('cropDimBadge');
        const cropCancelBtn = document.getElementById('cropCancelBtn');

        const copyFallbackModal = document.getElementById('copyFallbackModal');
        const copyModalTitle = document.getElementById('copyModalTitle');
        const copyModalMsg = document.getElementById('copyModalMsg');
        const copyPreviewImg = document.getElementById('copyPreviewImg');
        const copyModalCloseBtn = document.getElementById('copyModalCloseBtn');
        const copyModalDownloadBtn = document.getElementById('copyModalDownloadBtn');
        const copyModalOkBtn = document.getElementById('copyModalOkBtn');

        const sidebar = document.getElementById('dcmSidebar');
        const btnToggleSidebar = document.getElementById('btnToggleSidebar');
        const sidebarReopenBtn = document.getElementById('sidebarReopenBtn');
        const ieeDock = document.getElementById('ieeDock');

        // ビューワ内部ステート
        let allFolderFiles = [];
        let fileMapByName = {};
        let seriesList = [];
        let activeSeriesIdx = -1; // -1: 全ファイル, 0..N: 指定Series
        let isCrossSectional = false;

        let dcmFiles = [];
        let currentIndex = 0;
        let currentLayout = 1; // 1, 2, or 4
        let currentSliceRequestId = 0; // 高速コマ送り時の競合・非同期順序崩れ防止用ID
        let zoom = 1.0;
        let panX = 0;
        let panY = 0;
        let contrast = 100;    // 8bitモード時コントラスト (100%基準)
        let brightness = 100;  // 8bitモード時輝度 (100%基準)
        let isInverted = false;
        let activeMouseTool = 'ww_wl'; // 'ww_wl', 'pan', 'zoom', 'ruler'
        let activeIeeMode = 'normal';  // 'normal', 'lci', 'txi', 'pale', 'nbi', 'ihb', 'texture', 'indigo'
        let isEndoscopyDetected = IS_ENDOSCOPY_HINT; // 内視鏡判定ステート
        let isOlympusFitMode = false;  // O社内視鏡フィットモードフラグ
        let isCropMode = false;        // 範囲指定コピーモードフラグ

        // 16bit CT/MRI HU レンダリング & MPR断面ステート
        let activeStageKind = 'image'; // 'image' | 'hu16' | 'video'
        let huBuffer = null;           // Int16Array
        let huWidth = 0;
        let huHeight = 0;
        let huDefaultWl = 40;
        let huDefaultWw = 350;
        let huWl = 40;
        let huWw = 350;
        let huAutoPreset = true;       // 'auto' = スライス/シリーズ既定タグを使用
        let huPsRowMm = 0;
        let huPsColMm = 0;
        let startHuWl = 40;
        let startHuWw = 350;

        let mprPlane = 'native';       // 'native' | 'coronal' | 'sagittal'
        let mprMaxSlices = 0;
        let mprCurrentIdx = 0;

        // 距離計測 (mm) ルーラーステート
        let rulerLine = null;          // { x1, y1, x2, y2 } (画像ピクセル座標)
        let isRulerDrawing = false;

        // シネ連続再生ステート
        let cineTimer = null;
        let activeVideoFps = 30.0;

        // 内視鏡向けUI（下部病巣強調ドック & O社フィットボタン）の動的表示切替
        function setEndoscopyUIVisibility(isEndo) {
            isEndoscopyDetected = isEndo;
            if (ieeDock) {
                ieeDock.style.display = isEndo ? 'flex' : 'none';
            }
            if (btnOlympusFit) {
                btnOlympusFit.style.display = isEndo ? 'inline-flex' : 'none';
            }
        }

        // 画像色成分サンプリングによる内視鏡(カラー)自動判定 (XP/CR/CT等のグレースケール除外)
        function detectEndoscopyFromImage(img) {
            if (IS_ENDOSCOPY_HINT) {
                setEndoscopyUIVisibility(true);
                return;
            }
            try {
                const sampleCanvas = document.createElement('canvas');
                sampleCanvas.width = 40;
                sampleCanvas.height = 40;
                const sCtx = sampleCanvas.getContext('2d', { willReadFrequently: true });
                sCtx.drawImage(img, 0, 0, 40, 40);
                const pData = sCtx.getImageData(0, 0, 40, 40).data;
                let colorVariance = 0;
                for (let i = 0; i < pData.length; i += 4) {
                    const r = pData[i];
                    const g = pData[i + 1];
                    const b = pData[i + 2];
                    colorVariance += Math.abs(r - g) + Math.abs(g - b);
                    if (colorVariance > 400) {
                        setEndoscopyUIVisibility(true);
                        return;
                    }
                }
                setEndoscopyUIVisibility(false);
            } catch (err) {
                setEndoscopyUIVisibility(IS_ENDOSCOPY_HINT);
            }
        }
        let isCropDragging = false;
        let cropStartX = 0;
        let cropStartY = 0;
        let lastCaptureBlob = null;
        let lastCaptureFilename = '';

        // ドラッグ制御用
        let isDragging = false;
        let dragButton = 0; // 0: Left, 1: Middle, 2: Right
        let startMouseX = 0;
        let startMouseY = 0;
        let startPanX = 0;
        let startPanY = 0;
        let startZoom = 1.0;
        let startContrast = 100;
        let startBrightness = 100;

        // トースト表示
        function showToast(msg) {
            toast.textContent = msg;
            toast.className = 'show';
            setTimeout(() => { toast.className = ''; }, 2500);
        }

        // --------------------------------------------------------------------------
        // 初期化: DICOM・シリーズ・動画リスト取得
        // --------------------------------------------------------------------------
        async function initViewer() {
            setEndoscopyUIVisibility(IS_ENDOSCOPY_HINT);
            if (!FOLDER_PATH) {
                alert("フォルダパスが指定されていません");
                return;
            }

            loader.style.display = 'flex';

            try {
                const res = await fetch(`dcm_api.php?action=list&folder=${encodeURIComponent(FOLDER_PATH)}`);
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    console.error("Non-JSON API response:", text);
                    alert("一覧の取得エラー: サーバー応答がJSON形式ではありません。\n" + text.slice(0, 150));
                    loader.style.display = 'none';
                    return;
                }

                if (!res.ok || data.status !== 'success' || !data.files || data.files.length === 0) {
                    alert("表示可能な画像・動画が見つかりませんでした: " + (data.message || 'ファイルが0件です'));
                    loader.style.display = 'none';
                    return;
                }

                allFolderFiles = data.files;
                fileMapByName = {};
                allFolderFiles.forEach(f => { fileMapByName[f.filename] = f; });
                seriesList = Array.isArray(data.series) ? data.series : [];
                isCrossSectional = Boolean(data.is_cross_sectional);

                if (seriesList.length > 0 && (seriesList.length > 1 || isCrossSectional)) {
                    ctMriSubbar.style.display = 'flex';
                    renderSeriesBar();
                    // Scout (1-2枚) 以外の最初の本シリーズを初期表示に自動選択
                    let initSer = 0;
                    const mainIdx = seriesList.findIndex(s => s.slice_count > 2);
                    if (mainIdx >= 0) initSer = mainIdx;
                    selectSeries(initSer, false);
                } else {
                    ctMriSubbar.style.display = 'none';
                    dcmFiles = allFolderFiles;
                    thumbTotalCount.textContent = dcmFiles.length;
                    renderFilmstrip();
                    loadSlice(0, true);
                }

            } catch (err) {
                alert("読み込み通信エラー: " + err.message);
                loader.style.display = 'none';
            }
        }

        function renderSeriesBar() {
            if (!seriesPillsBox) return;
            seriesPillsBox.innerHTML = seriesList.map((s, idx) => {
                const planeTag = s.plane && s.plane !== '2D' ? `[${s.plane.slice(0,3)}]` : '';
                return `<button type="button" class="ser-pill ${idx === activeSeriesIdx ? 'active' : ''}"
                            onclick="selectSeries(${idx}, true)"
                            title="${s.modality} / ${s.series_desc} (${s.slice_count}枚)">
                            S${s.series_number}: ${s.series_desc} <small style="opacity:0.85;">(${s.slice_count}) ${planeTag}</small>
                        </button>`;
            }).join('');
        }

        function selectSeries(serIdx, preserveZ = false) {
            stopCinePlay();
            if (serIdx < 0 || serIdx >= seriesList.length) return;

            // シリーズ切替前の解剖学的Z座標 (slice_location) を記録（ChestAbd ⇔ Lung 同期用）
            let prevLoc = null;
            if (preserveZ && mprPlane === 'native' && dcmFiles[currentIndex] && dcmFiles[currentIndex].slice_location !== null && dcmFiles[currentIndex].slice_location !== undefined) {
                prevLoc = dcmFiles[currentIndex].slice_location;
            }

            activeSeriesIdx = serIdx;
            mprPlane = 'native';
            updateMprPillsUI();
            renderSeriesBar();

            const ser = seriesList[serIdx];
            dcmFiles = (ser.filenames || []).map(fn => fileMapByName[fn]).filter(Boolean);
            if (dcmFiles.length === 0) dcmFiles = allFolderFiles;
            thumbTotalCount.textContent = dcmFiles.length;

            // シリーズのモダリティ・16bit属性に応じてプリセットバー・MPRバーを更新
            const has16Bit = Boolean(ser.is_16bit || ser.modality === 'CT' || ser.modality === 'MR');
            if (wlPresetsGroup) wlPresetsGroup.style.display = has16Bit ? 'flex' : 'none';
            if (mprGroup) mprGroup.style.display = ser.can_mpr ? 'flex' : 'none';

            // 既定(auto)プリセットに戻してシリーズの適切な初期WL/WWを適用（例: Lungシリーズなら自動で肺野WL:-600/1500タグが効く！）
            huAutoPreset = true;
            document.querySelectorAll('.wl-pill').forEach(b => b.classList.toggle('active', b.dataset.wl === 'auto'));

            renderFilmstrip();

            let targetSlice = 0;
            if (prevLoc !== null && dcmFiles.length > 1) {
                let bestDist = Infinity;
                dcmFiles.forEach((f, idx) => {
                    if (f.slice_location !== null && f.slice_location !== undefined) {
                        const d = Math.abs(f.slice_location - prevLoc);
                        if (d < bestDist) {
                            bestDist = d;
                            targetSlice = idx;
                        }
                    }
                });
            }
            loadSlice(targetSlice, true);
        }

        function applyHuPreset(wl, ww, btnEl) {
            document.querySelectorAll('.wl-pill').forEach(b => b.classList.remove('active'));
            if (btnEl) btnEl.classList.add('active');
            if (wl === 'auto') {
                huAutoPreset = true;
                huWl = huDefaultWl;
                huWw = huDefaultWw;
            } else {
                huAutoPreset = false;
                huWl = Number(wl);
                huWw = Number(ww);
            }
            if (activeStageKind === 'hu16' && huBuffer) {
                renderHuBufferToCanvas();
            }
        }

        function updateMprPillsUI() {
            document.querySelectorAll('.mpr-pill').forEach(b => {
                b.classList.toggle('active', b.dataset.plane === mprPlane);
            });
        }

        function switchMprPlane(plane) {
            stopCinePlay();
            if (activeSeriesIdx < 0 || !seriesList[activeSeriesIdx]?.can_mpr) return;
            const ser = seriesList[activeSeriesIdx];
            mprPlane = plane;
            updateMprPillsUI();
            if (currentLayout !== 1) {
                currentLayout = 1;
                stage.className = 'dcm-stage layout-1';
                document.querySelectorAll('.layout-btn').forEach(b => b.classList.toggle('active', b.dataset.layout === '1'));
            }
            if (plane === 'native') {
                loadSlice(currentIndex, true);
                showToast('🧊 元断面 (Axial) 表示に戻りました');
            } else {
                mprMaxSlices = plane === 'coronal' ? (ser.rows || 512) : (ser.cols || 512);
                mprCurrentIdx = Math.floor(mprMaxSlices / 2); // 身体中央から開始
                loadMprSlice(mprCurrentIdx, true);
                showToast(plane === 'coronal' ? '🧊 冠状断 (Coronal) MPR再構成表示' : '🧊 矢状断 (Sagittal) MPR再構成表示');
            }
        }

        // サムネイルバーの生成 (縦並び)
        function renderFilmstrip() {
            filmstrip.innerHTML = '';
            dcmFiles.forEach((file, index) => {
                const inTray = isFileInTray(file.filename);
                const card = document.createElement('div');
                card.className = 'thumb-card' + (index === currentIndex ? ' active' : '') + (inTray ? ' in-tray' : '');
                card.dataset.index = index;
                card.dataset.filename = file.filename;

                const img = document.createElement('img');
                img.loading = 'lazy';
                img.src = `dcm_api.php?action=image&folder=${encodeURIComponent(FOLDER_PATH)}&file=${encodeURIComponent(file.filename)}&thumb=1`;
                img.alt = file.filename;

                const badge = document.createElement('span');
                badge.className = 'thumb-badge';
                badge.textContent = '#' + (index + 1);

                const trayBtn = document.createElement('button');
                trayBtn.type = 'button';
                trayBtn.className = 'thumb-tray-btn' + (inTray ? ' in-tray' : '');
                trayBtn.title = inTray ? '印刷トレイから除外' : '印刷トレイに追加 (2×4印刷用)';
                trayBtn.textContent = inTray ? '✓' : '＋';
                trayBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    toggleTrayItem(file.filename, index);
                });

                card.appendChild(img);
                card.appendChild(badge);
                if (file.media_type === 'video' || file.media_type === 'video_dicom' || file.media_type === 'cine_dicom') {
                    const vTag = document.createElement('span');
                    vTag.className = 'thumb-vid-badge';
                    vTag.textContent = file.media_type === 'cine_dicom' ? `🎬${file.num_frames}f` : '🎬動画';
                    card.appendChild(vTag);
                }
                card.appendChild(trayBtn);

                card.addEventListener('click', () => {
                    if (mprPlane !== 'native') {
                        mprPlane = 'native';
                        updateMprPillsUI();
                    }
                    loadSlice(index, false);
                });

                filmstrip.appendChild(card);
            });
            updateTrayUI();
        }

        function getActiveSlotDimensions() {
            if (activeStageKind === 'hu16' && dcmHuCanvas && dcmHuCanvas.width > 0) {
                return { w: dcmHuCanvas.width, h: dcmHuCanvas.height };
            }
            if (activeStageKind === 'video' && dcmMainVideo && dcmMainVideo.videoWidth > 0) {
                return { w: dcmMainVideo.videoWidth, h: dcmMainVideo.videoHeight };
            }
            const vp0 = viewports[0];
            const img0 = vp0 ? vp0.querySelector('.dcm-slot-img') : null;
            if (img0 && img0.naturalWidth > 0) {
                return { w: img0.naturalWidth, h: img0.naturalHeight };
            }
            return null;
        }

        function parseAndApplyRs16Buffer(buf, autoFit) {
            const dv = new DataView(buf, 0, 24);
            const magic = String.fromCharCode(dv.getUint8(0), dv.getUint8(1), dv.getUint8(2), dv.getUint8(3));
            if (magic !== 'RS16') throw new Error('Invalid RS16 header');
            huWidth = dv.getUint16(4, true);
            huHeight = dv.getUint16(6, true);
            huDefaultWl = dv.getInt16(8, true);
            huDefaultWw = Math.max(1, dv.getUint16(10, true));
            huPsRowMm = dv.getFloat32(16, true);
            huPsColMm = dv.getFloat32(20, true);
            huBuffer = new Int16Array(buf, 24, huWidth * huHeight);

            if (huAutoPreset) {
                huWl = huDefaultWl;
                huWw = huDefaultWw;
            }
            dcmHuCanvas.width = huWidth;
            dcmHuCanvas.height = huHeight;
            renderHuBufferToCanvas();
            if (hudResolution) hudResolution.textContent = `${huWidth} x ${huHeight} (16bit)`;
            if (hudHuWrap) hudHuWrap.style.display = 'inline';
            if (autoFit) {
                fitToWindow(true);
            } else {
                updateTransform();
            }
            renderRulerOverlay();
        }

        function renderHuBufferToCanvas() {
            if (!huCtx || !huBuffer || huWidth <= 0 || huHeight <= 0) return;
            const imgData = huCtx.createImageData(huWidth, huHeight);
            const dst = imgData.data;
            const ww = Math.max(1, huWw);
            const low = huWl - ww * 0.5;
            const scale = 255.0 / ww;
            const inv = isInverted;
            const len = huBuffer.length;

            for (let i = 0; i < len; i++) {
                let v = (huBuffer[i] - low) * scale;
                if (v <= 0) v = 0;
                else if (v >= 255) v = 255;
                const g = inv ? (255 - (v | 0)) : (v | 0);
                const p = i << 2;
                dst[p] = g;
                dst[p + 1] = g;
                dst[p + 2] = g;
                dst[p + 3] = 255;
            }
            huCtx.putImageData(imgData, 0, 0);
            if (hudWwWl) {
                hudWwWl.textContent = `WL:${Math.round(huWl)} WW:${Math.round(huWw)}${inv ? ' [反転]' : ''}`;
            }
        }

        async function loadMprSlice(idx, autoFit = false) {
            if (activeSeriesIdx < 0 || !seriesList[activeSeriesIdx]) return;
            const ser = seriesList[activeSeriesIdx];
            mprCurrentIdx = Math.max(0, Math.min(mprMaxSlices - 1, idx));
            const thisReq = ++currentSliceRequestId;

            sliceNavLabel.textContent = `${mprCurrentIdx + 1} / ${mprMaxSlices}`;
            navPrevBtn.disabled = (mprCurrentIdx <= 0);
            navNextBtn.disabled = (mprCurrentIdx >= mprMaxSlices - 1);
            floatPrevBtn.disabled = (mprCurrentIdx <= 0);
            floatNextBtn.disabled = (mprCurrentIdx >= mprMaxSlices - 1);

            if (tomoScrubDock) {
                tomoScrubDock.style.display = 'flex';
                tomoScrubRange.max = Math.max(0, mprMaxSlices - 1);
                tomoScrubRange.value = mprCurrentIdx;
                tomoScrubLabel.textContent = `${mprCurrentIdx + 1} / ${mprMaxSlices}`;
                if (btnPlayCineMp4) btnPlayCineMp4.style.display = 'none';
            }
            if (videoControlDock) videoControlDock.style.display = 'none';
            setEndoscopyUIVisibility(false);

            const vp0 = viewports[0];
            const img0 = vp0.querySelector('.dcm-slot-img');
            if (img0) img0.style.display = 'none';
            if (dcmMainVideo) { dcmMainVideo.pause(); dcmMainVideo.style.display = 'none'; }
            if (dcmHuCanvas) dcmHuCanvas.style.display = 'block';
            activeStageKind = 'hu16';

            if (hudFilename) hudFilename.textContent = `MPR_${mprPlane.toUpperCase()}_${mprCurrentIdx + 1}`;
            if (hudLocWrap) {
                hudLocWrap.style.display = 'inline';
                hudSliceLoc.textContent = `${mprPlane === 'coronal' ? 'Coronal Y' : 'Sagittal X'}: ${mprCurrentIdx + 1}/${mprMaxSlices}`;
            }

            if (autoFit) loader.style.display = 'flex';
            try {
                const url = `dcm_api.php?action=mpr_slice&folder=${encodeURIComponent(FOLDER_PATH)}&series_uid=${encodeURIComponent(ser.series_uid)}&plane=${encodeURIComponent(mprPlane)}&index=${mprCurrentIdx}`;
                const res = await fetch(url);
                if (!res.ok) throw new Error('MPR取得失敗');
                const buf = await res.arrayBuffer();
                if (thisReq !== currentSliceRequestId) return;
                parseAndApplyRs16Buffer(buf, autoFit);
            } catch (err) {
                if (thisReq === currentSliceRequestId) showToast('⚠️ MPR断面再構成エラー');
            } finally {
                if (thisReq === currentSliceRequestId) loader.style.display = 'none';
            }
        }

        // 指定スライスの画像読込
        // ナビラベル & ボタン状態更新
        function updateNavUI() {
            if (currentLayout === 1) {
                sliceNavLabel.textContent = `${currentIndex + 1} / ${dcmFiles.length}`;
            } else {
                const endIdx = Math.min(currentIndex + currentLayout, dcmFiles.length);
                sliceNavLabel.textContent = `${currentIndex + 1}-${endIdx} / ${dcmFiles.length}`;
            }
            navPrevBtn.disabled = (currentIndex === 0);
            navNextBtn.disabled = (currentIndex >= dcmFiles.length - 1);
            floatPrevBtn.disabled = (currentIndex === 0);
            floatNextBtn.disabled = (currentIndex >= dcmFiles.length - 1);
        }

        // サムネイルハイライト更新
        function updateFilmstripHighlight() {
            const cards = filmstrip.querySelectorAll('.thumb-card');
            cards.forEach((c, idx) => {
                const isVisible = (idx >= currentIndex && idx < currentIndex + currentLayout);
                c.classList.toggle('active', isVisible);
                if (idx === currentIndex) {
                    c.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        }

        // 画面レイアウト切替 (1面, 2面, 4面)
        function setLayout(layout) {
            currentLayout = layout;
            stage.className = `dcm-stage layout-${layout}`;
            document.querySelectorAll('.layout-btn').forEach(btn => {
                btn.classList.toggle('active', parseInt(btn.dataset.layout, 10) === layout);
            });
            updateNavUI();
            updateFilmstripHighlight();
            loadSlice(currentIndex, true);
            showToast(`🖥️ ${layout}画面表示に切り替えました`);
        }

        // 指定スライス・動画・16bit断面の読込
        function loadSlice(index, autoFit = false) {
            if (index < 0 || index >= dcmFiles.length) return;
            currentIndex = index;

            const thisRequestId = ++currentSliceRequestId;

            updateNavUI();
            updateFilmstripHighlight();
            updateTrayUI();
            rulerLine = null;
            renderRulerOverlay();

            const file0 = dcmFiles[currentIndex];
            if (hudFilename) hudFilename.textContent = file0.filename;

            if (file0.slice_location !== null && file0.slice_location !== undefined) {
                if (hudLocWrap) hudLocWrap.style.display = 'inline';
                if (hudSliceLoc) hudSliceLoc.textContent = `${file0.slice_location} mm`;
            } else {
                if (hudLocWrap) hudLocWrap.style.display = 'none';
            }

            const vp0 = viewports[0];
            const img0 = vp0.querySelector('.dcm-slot-img');

            // (A) 動画ファイル または カプセル化動画DICOM の場合 (1面表示)
            if (currentLayout === 1 && (file0.media_type === 'video' || file0.media_type === 'video_dicom')) {
                stopCinePlay();
                activeStageKind = 'video';
                setEndoscopyUIVisibility(false);
                if (tomoScrubDock) tomoScrubDock.style.display = 'none';
                if (videoControlDock) videoControlDock.style.display = 'flex';
                if (hudHuWrap) hudHuWrap.style.display = 'none';
                if (img0) img0.style.display = 'none';
                if (dcmHuCanvas) dcmHuCanvas.style.display = 'none';
                vp0.classList.remove('is-empty');

                activeVideoFps = file0.fps || 30.0;
                const vidAction = file0.media_type === 'video_dicom' ? 'cine_mp4' : 'video';
                const vidUrl = `dcm_api.php?action=${vidAction}&folder=${encodeURIComponent(FOLDER_PATH)}&file=${encodeURIComponent(file0.filename)}`;
                dcmMainVideo.style.display = 'block';
                dcmMainVideo.loop = btnVidLoop.classList.contains('active');
                loader.style.display = 'flex';

                dcmMainVideo.onloadedmetadata = () => {
                    if (thisRequestId !== currentSliceRequestId) return;
                    loader.style.display = 'none';
                    if (hudResolution) hudResolution.textContent = `${dcmMainVideo.videoWidth} x ${dcmMainVideo.videoHeight} (${activeVideoFps}fps)`;
                    fitToWindow(true);
                    dcmMainVideo.play().catch(() => {});
                };
                dcmMainVideo.onerror = () => {
                    if (thisRequestId !== currentSliceRequestId) return;
                    loader.style.display = 'none';
                    showToast('⚠️ 動画の読み込みに失敗しました');
                };
                dcmMainVideo.src = vidUrl;
                return;
            }

            // 動画以外に切り替わったら動画を停止・非表示
            if (dcmMainVideo && dcmMainVideo.style.display !== 'none') {
                dcmMainVideo.pause();
                dcmMainVideo.removeAttribute('src');
                dcmMainVideo.style.display = 'none';
            }
            if (videoControlDock) videoControlDock.style.display = 'none';

            // 断面スクラバードックの表示制御 (CT/MR、または非内視鏡で4枚以上の連続スライス、またはマルチフレームDICOM)
            const showTomoScrub = (isCrossSectional || (!IS_ENDOSCOPY_HINT && dcmFiles.length >= 4) || file0.media_type === 'cine_dicom');
            if (tomoScrubDock) {
                tomoScrubDock.style.display = showTomoScrub ? 'flex' : 'none';
                if (showTomoScrub) {
                    tomoScrubRange.max = Math.max(0, dcmFiles.length - 1);
                    tomoScrubRange.value = currentIndex;
                    tomoScrubLabel.textContent = `${currentIndex + 1} / ${dcmFiles.length}`;
                    if (btnPlayCineMp4) {
                        btnPlayCineMp4.style.display = (file0.media_type === 'cine_dicom') ? 'inline-flex' : 'none';
                    }
                }
            }

            // (B) 1面表示 かつ 16bit CT/MRI グレースケールスライスの場合 -> 高速 16bit HU Canvas モード
            const useHu16 = (currentLayout === 1 && (file0.is_16bit || file0.modality === 'CT' || file0.modality === 'MR') && file0.media_type === 'dicom');
            if (useHu16) {
                activeStageKind = 'hu16';
                setEndoscopyUIVisibility(false);
                if (wlPresetsGroup) wlPresetsGroup.style.display = 'flex';
                if (img0) img0.style.display = 'none';
                if (dcmHuCanvas) dcmHuCanvas.style.display = 'block';
                vp0.classList.remove('is-empty');
                const badge0 = vp0.querySelector('.slot-badge');
                if (badge0) badge0.textContent = '#' + (currentIndex + 1);

                if (autoFit) loader.style.display = 'flex';
                fetch(`dcm_api.php?action=slice_16bit&folder=${encodeURIComponent(FOLDER_PATH)}&file=${encodeURIComponent(file0.filename)}`)
                    .then(r => {
                        if (!r.ok) throw new Error('16bit HTTP ' + r.status);
                        return r.arrayBuffer();
                    })
                    .then(buf => {
                        if (thisRequestId !== currentSliceRequestId) return;
                        loader.style.display = 'none';
                        parseAndApplyRs16Buffer(buf, autoFit);
                    })
                    .catch(() => {
                        if (thisRequestId !== currentSliceRequestId) return;
                        // フォールバックとして通常JPEG表示へ切り替え
                        loadStandardJpegSlots(thisRequestId, autoFit);
                    });
                return;
            }

            // (C) 標準 2D 画像 / 内視鏡 / 2面・4面比較表示モード
            activeStageKind = 'image';
            if (dcmHuCanvas) dcmHuCanvas.style.display = 'none';
            if (hudHuWrap) hudHuWrap.style.display = 'none';
            if (img0) img0.style.display = 'block';
            if (file0.pixel_spacing && file0.pixel_spacing.length >= 2) {
                huPsRowMm = file0.pixel_spacing[0];
                huPsColMm = file0.pixel_spacing[1];
            } else {
                huPsRowMm = 0;
                huPsColMm = 0;
            }
            loadStandardJpegSlots(thisRequestId, autoFit);
        }

        function loadStandardJpegSlots(thisRequestId, autoFit) {
            loader.style.display = 'flex';
            let pendingCount = 0;
            for (let s = 0; s < 4; s++) {
                const vp = viewports[s];
                if (!vp) continue;
                if (s >= currentLayout) continue;
                const fileIdx = currentIndex + s;
                const img = vp.querySelector('.dcm-slot-img');
                const badge = vp.querySelector('.slot-badge');

                if (fileIdx < dcmFiles.length) {
                    vp.classList.remove('is-empty');
                    if (badge) badge.textContent = '#' + (fileIdx + 1);
                    const fileObj = dcmFiles[fileIdx];
                    let imgUrl = `dcm_api.php?action=image&folder=${encodeURIComponent(FOLDER_PATH)}&file=${encodeURIComponent(fileObj.filename)}`;
                    if (!huAutoPreset && (fileObj.is_16bit || fileObj.modality === 'CT' || fileObj.modality === 'MR')) {
                        imgUrl += `&wl=${encodeURIComponent(huWl)}&ww=${encodeURIComponent(huWw)}`;
                    }

                    if (s === 0) {
                        pendingCount++;
                        const tempImg = new Image();
                        tempImg.onload = () => {
                            if (thisRequestId !== currentSliceRequestId) return;
                            img.style.display = 'block';
                            img.src = imgUrl;
                            if (hudResolution) hudResolution.textContent = `${tempImg.naturalWidth} x ${tempImg.naturalHeight}`;
                            if (isCrossSectional) {
                                setEndoscopyUIVisibility(false);
                            } else {
                                detectEndoscopyFromImage(tempImg);
                            }
                            pendingCount--;
                            if (pendingCount <= 0) loader.style.display = 'none';
                            if (autoFit) {
                                applyActiveFit();
                            } else {
                                updateTransform();
                            }
                            updateFilters();
                        };
                        tempImg.onerror = () => {
                            if (thisRequestId !== currentSliceRequestId) return;
                            pendingCount--;
                            if (pendingCount <= 0) loader.style.display = 'none';
                            showToast("⚠️ 画像の読み込みに失敗しました");
                        };
                        tempImg.src = imgUrl;
                    } else {
                        pendingCount++;
                        const onSlotLoaded = () => {
                            if (thisRequestId !== currentSliceRequestId) return;
                            pendingCount--;
                            if (pendingCount <= 0) loader.style.display = 'none';
                            updateFilters();
                        };
                        img.onload = onSlotLoaded;
                        img.onerror = onSlotLoaded;
                        img.src = imgUrl;
                    }
                } else {
                    vp.classList.add('is-empty');
                    img.src = '';
                }
            }
            if (pendingCount === 0) loader.style.display = 'none';
        }

        // マルチフレームシネDICOM (RF透視10fps / USシネ等) をMP4動画プレイヤーへ切替
        function switchToCineMp4Mode() {
            const file0 = dcmFiles[currentIndex];
            if (!file0) return;
            stopCinePlay();
            activeStageKind = 'video';
            if (tomoScrubDock) tomoScrubDock.style.display = 'none';
            if (videoControlDock) videoControlDock.style.display = 'flex';
            const vp0 = viewports[0];
            const img0 = vp0.querySelector('.dcm-slot-img');
            if (img0) img0.style.display = 'none';
            if (dcmHuCanvas) dcmHuCanvas.style.display = 'none';
            dcmMainVideo.style.display = 'block';
            dcmMainVideo.loop = true;
            activeVideoFps = file0.frame_time_ms ? Math.round(1000 / file0.frame_time_ms) : 10;
            loader.style.display = 'flex';
            dcmMainVideo.onloadedmetadata = () => {
                loader.style.display = 'none';
                fitToWindow(true);
                dcmMainVideo.play().catch(() => {});
            };
            dcmMainVideo.src = `dcm_api.php?action=cine_mp4&folder=${encodeURIComponent(FOLDER_PATH)}&file=${encodeURIComponent(file0.filename)}`;
            showToast('🎬 マルチフレームDICOMをシネ動画として再生中');
        }

        // 断面スクラバー & シネ再生制御
        function onTomoScrubInput(val) {
            const idx = parseInt(val, 10) || 0;
            if (mprPlane !== 'native') {
                loadMprSlice(idx, false);
            } else {
                loadSlice(idx, false);
            }
        }

        function toggleCinePlay() {
            if (cineTimer) {
                stopCinePlay();
            } else {
                startCinePlay();
            }
        }

        function startCinePlay() {
            stopCinePlay();
            const fps = parseInt(cineFpsSelect ? cineFpsSelect.value : '12', 10) || 12;
            if (btnCineToggle) {
                btnCineToggle.classList.add('active');
                btnCineToggle.textContent = '⏸ 停止';
            }
            cineTimer = setInterval(() => {
                if (mprPlane !== 'native') {
                    const next = (mprCurrentIdx + 1) % Math.max(1, mprMaxSlices);
                    loadMprSlice(next, false);
                } else {
                    const next = (currentIndex + 1) % Math.max(1, dcmFiles.length);
                    loadSlice(next, false);
                }
            }, Math.round(1000 / fps));
        }

        function stopCinePlay() {
            if (cineTimer) {
                clearInterval(cineTimer);
                cineTimer = null;
            }
            if (btnCineToggle) {
                btnCineToggle.classList.remove('active');
                btnCineToggle.textContent = '▶ シネ再生';
            }
        }

        function onCineFpsChange() {
            if (cineTimer) startCinePlay();
        }

        // 臨床動画プレイヤー制御 (再生・1コマ送り・速度・シーク)
        function formatVidTime(sec) {
            if (!Number.isFinite(sec) || sec < 0) return '00:00.0';
            const m = Math.floor(sec / 60);
            const s = (sec % 60).toFixed(1).padStart(4, '0');
            return `${String(m).padStart(2, '0')}:${s}`;
        }

        if (dcmMainVideo) {
            dcmMainVideo.addEventListener('timeupdate', () => {
                const dur = dcmMainVideo.duration || 0;
                const cur = dcmMainVideo.currentTime || 0;
                if (dur > 0 && videoSeekRange) {
                    videoSeekRange.value = Math.round((cur / dur) * 1000);
                }
                if (videoTimeLabel) {
                    videoTimeLabel.textContent = `${formatVidTime(cur)} / ${formatVidTime(dur)}`;
                }
            });
            dcmMainVideo.addEventListener('play', () => {
                if (btnVidPlay) { btnVidPlay.textContent = '⏸ 一時停止'; btnVidPlay.classList.add('active'); }
            });
            dcmMainVideo.addEventListener('pause', () => {
                if (btnVidPlay) { btnVidPlay.textContent = '▶ 再生'; btnVidPlay.classList.remove('active'); }
            });
        }

        function toggleVideoPlay() {
            if (!dcmMainVideo) return;
            if (dcmMainVideo.paused) dcmMainVideo.play().catch(() => {});
            else dcmMainVideo.pause();
        }

        function stepVideoFrame(dir) {
            if (!dcmMainVideo) return;
            dcmMainVideo.pause();
            const dt = 1.0 / Math.max(1.0, activeVideoFps || 30.0);
            dcmMainVideo.currentTime = Math.max(0, Math.min(dcmMainVideo.duration || 0, dcmMainVideo.currentTime + dir * dt));
        }

        function onVideoSeekInput(val) {
            if (!dcmMainVideo || !dcmMainVideo.duration) return;
            dcmMainVideo.currentTime = (Number(val) / 1000.0) * dcmMainVideo.duration;
        }

        function setVideoRate(rate) {
            if (dcmMainVideo) dcmMainVideo.playbackRate = Number(rate) || 1.0;
        }

        function toggleVideoLoop() {
            if (!dcmMainVideo || !btnVidLoop) return;
            dcmMainVideo.loop = !dcmMainVideo.loop;
            btnVidLoop.classList.toggle('active', dcmMainVideo.loop);
        }

        // 距離計測ルーラー描画 (SVGオーバーレイ)
        function renderRulerOverlay() {
            if (!dcmRulerSvg) return;
            if (!rulerLine) {
                dcmRulerSvg.innerHTML = '';
                return;
            }
            const sx1 = panX + rulerLine.x1 * zoom;
            const sy1 = panY + rulerLine.y1 * zoom;
            const sx2 = panX + rulerLine.x2 * zoom;
            const sy2 = panY + rulerLine.y2 * zoom;
            const dxPx = rulerLine.x2 - rulerLine.x1;
            const dyPx = rulerLine.y2 - rulerLine.y1;

            let labelTxt = '';
            if (huPsRowMm > 0 && huPsColMm > 0) {
                const distMm = Math.hypot(dxPx * huPsColMm, dyPx * huPsRowMm);
                labelTxt = `${distMm.toFixed(1)} mm`;
            } else {
                labelTxt = `${Math.hypot(dxPx, dyPx).toFixed(1)} px`;
            }

            const mx = (sx1 + sx2) / 2;
            const my = (sy1 + sy2) / 2 - 10;
            dcmRulerSvg.innerHTML = `
                <line x1="${sx1}" y1="${sy1}" x2="${sx2}" y2="${sy2}" stroke="#22d3ee" stroke-width="2" stroke-dasharray="4,2"/>
                <circle cx="${sx1}" cy="${sy1}" r="4" fill="#22d3ee"/>
                <circle cx="${sx2}" cy="${sy2}" r="4" fill="#22d3ee"/>
                <rect x="${mx - 36}" y="${my - 12}" width="72" height="20" rx="4" fill="rgba(15,23,42,0.9)" stroke="#22d3ee"/>
                <text x="${mx}" y="${my + 2}" fill="#67e8f9" font-size="12" font-family="monospace" font-weight="bold" text-anchor="middle">${labelTxt}</text>
            `;
        }

        // 変形 (ズーム・パン) 反映 (全スロット & ルーラー完全同期)
        function updateTransform() {
            viewports.forEach(vp => {
                const wrapper = vp.querySelector('.dcm-img-wrapper');
                if (wrapper) {
                    wrapper.style.transform = `translate(${panX}px, ${panY}px) scale(${zoom})`;
                }
            });
            hudZoom.textContent = Math.round(zoom * 100) + '%';
            renderRulerOverlay();
        }

        // 階調 (WW/WL) 反映 (全スロット完全同期)
        // 画面下部 内視鏡病変強調モード切替 (同一モード再クリックで通常へトグル復帰可能)
        function setIeeMode(mode) {
            // すでにアクティブな強調モードを再度クリックした場合は通常表示にトグル復帰
            if (activeIeeMode === mode && mode !== 'normal') {
                mode = 'normal';
            }

            activeIeeMode = mode;
            document.querySelectorAll('.iee-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.mode === mode);
            });
            updateFilters();

            const names = {
                'normal': '🌈 通常表示 (白色光)',
                'lci': '🔴 発赤強調 (LCI風: 早期病変・炎症拾い上げ)',
                'txi': '🔆 自然強調 (TXI風: 凹凸＋微細色差・明るさ維持)',
                'pale': '⚪ 褪色強調 (Pale/SSA/P: 白っぽい平坦病変・鋸歯状病変)',
                'nbi': '🟢 血管・ピット強調 (NBI風: 微細血管・腺管質的診断)',
                'ihb': '📊 血流マップ (ヘモグロビン濃度強調)',
                'texture': '🏔️ 凹凸・構造強調 (テクスチャ・境界シャープ化)',
                'indigo': '🔵 色素散布シミュレーション (インジゴカルミン風)'
            };
            showToast(`${names[mode] || mode} に切り替えました`);
        }

        // 階調 (WW/WL) & 内視鏡強調 (IEE) 反映 (全スロット完全同期)
        function updateFilters() {
            let filterParts = [];
            if (contrast !== 100) filterParts.push(`contrast(${contrast}%)`);
            if (brightness !== 100) filterParts.push(`brightness(${brightness}%)`);
            if (isInverted) filterParts.push('invert(100%)');

            if (activeIeeMode !== 'normal') {
                filterParts.push(`url(#iee-${activeIeeMode})`);
                if (activeIeeMode === 'lci') {
                    filterParts.push('saturate(135%)');
                } else if (activeIeeMode === 'txi') {
                    filterParts.push('saturate(120%)');
                } else if (activeIeeMode === 'pale') {
                    filterParts.push('saturate(115%)');
                } else if (activeIeeMode === 'nbi') {
                    filterParts.push('saturate(125%)');
                }
            }

            const filterStr = filterParts.length > 0 ? filterParts.join(' ') : 'none';

            viewports.forEach(vp => {
                const img = vp.querySelector('.dcm-slot-img');
                if (img) {
                    if (activeIeeMode === 'normal' && (img.style.filter.indexOf('url(') !== -1 || filterStr === 'none')) {
                        img.style.filter = 'none';
                        void img.offsetHeight;
                    }
                    img.style.filter = filterStr;
                }
            });
            if (dcmMainVideo) {
                dcmMainVideo.style.filter = filterStr;
            }
            if (activeStageKind === 'hu16' && huBuffer) {
                renderHuBufferToCanvas();
                return;
            }
            const ieeTag = (activeIeeMode !== 'normal') ? ` [${activeIeeMode.toUpperCase()}]` : '';
            if (hudWwWl) {
                hudWwWl.textContent = `C:${Math.round(contrast)}% B:${Math.round(brightness)}%${ieeTag}`;
            }
        }

        // --------------------------------------------------------------------------
        // 標準全体フィット (2D画像・16bit CT/MRI・MPR・動画 全共通)
        // --------------------------------------------------------------------------
        function fitToWindow(silent = false) {
            const dims = getActiveSlotDimensions();
            if (!dims || !dims.w || !dims.h) return;
            const vp0 = viewports[0];

            const slotW = vp0.clientWidth || (stage.clientWidth / (currentLayout === 1 ? 1 : 2));
            const slotH = vp0.clientHeight || (stage.clientHeight / (currentLayout === 4 ? 2 : 1));
            const imgW = dims.w;
            const imgH = dims.h;

            const scaleX = slotW / imgW;
            const scaleY = slotH / imgH;
            // CT/MRI等の512x512断面画像は画面高さに合わせて見やすく拡大フィット、一般大型画像は上限1.0〜最適倍率
            const maxAutoScale = (activeStageKind === 'hu16' || isCrossSectional) ? 2.5 : 1.0;
            zoom = Math.min(scaleX, scaleY, maxAutoScale);

            panX = (slotW - imgW * zoom) / 2;
            panY = (slotH - imgH * zoom) / 2;

            isOlympusFitMode = false;
            if (btnFit) btnFit.classList.add('active');
            if (btnOlympusFit) btnOlympusFit.classList.remove('active');

            updateTransform();
            if (!silent) showToast("⏹️ 全体フィット");
        }

        // --------------------------------------------------------------------------
        // O社 (オリンパス) 内視鏡専用フィット
        // 左側の患者・スコープ情報テキスト領域 (左約35%) を除外し、
        // 円形観察視野 (右側約65%) を画面中央いっぱいに拡大フィット表示する
        // --------------------------------------------------------------------------
        function olympusFit() {
            const vp0 = viewports[0];
            const img0 = vp0.querySelector('.dcm-slot-img');
            if (!img0 || !img0.naturalWidth || !img0.naturalHeight) return;

            const slotW = vp0.clientWidth || (stage.clientWidth / (currentLayout === 1 ? 1 : 2));
            const slotH = vp0.clientHeight || (stage.clientHeight / (currentLayout === 4 ? 2 : 1));
            const imgW = img0.naturalWidth;
            const imgH = img0.naturalHeight;

            // オリンパス内視鏡の円形観察視野ジオメトリ:
            // X範囲: 0.35 * imgW 〜 1.0 * imgW (幅: 約 0.65 * imgW)
            // Y範囲: 0.05 * imgH 〜 0.95 * imgH (高さ: 約 0.90 * imgH)
            // 中心点: X = 0.675 * imgW, Y = 0.50 * imgH
            const circleW = imgW * 0.65;
            const circleH = imgH * 0.90;
            const centerX = imgW * 0.675;
            const centerY = imgH * 0.50;

            const scaleX = slotW / circleW;
            const scaleY = slotH / circleH;
            zoom = Math.min(scaleX, scaleY);

            // 円形観察視野の中心 (centerX, centerY) がスロットの中央 (slotW/2, slotH/2) に来るようパンを決定
            panX = (slotW / 2) - (centerX * zoom);
            panY = (slotH / 2) - (centerY * zoom);

            isOlympusFitMode = true;
            if (btnFit) btnFit.classList.remove('active');
            if (btnOlympusFit) btnOlympusFit.classList.add('active');

            updateTransform();
            showToast("⏹️ O社フィット");
        }

        function applyActiveFit() {
            if (isOlympusFitMode) {
                olympusFit();
            } else {
                fitToWindow();
            }
        }

        // 原寸 (1:1)
        function oneToOne() {
            const dims = getActiveSlotDimensions();
            if (!dims) return;
            const vp0 = viewports[0];

            zoom = 1.0;
            const slotW = vp0.clientWidth || (stage.clientWidth / (currentLayout === 1 ? 1 : 2));
            const slotH = vp0.clientHeight || (stage.clientHeight / (currentLayout === 4 ? 2 : 1));

            panX = (slotW - dims.w) / 2;
            panY = (slotH - dims.h) / 2;

            if (btnFit) btnFit.classList.remove('active');
            if (btnOlympusFit) btnOlympusFit.classList.remove('active');

            updateTransform();
            showToast("1:1 原寸表示");
        }

        // リセット
        function resetView() {
            contrast = 100;
            brightness = 100;
            isInverted = false;
            activeIeeMode = 'normal';
            rulerLine = null;
            huAutoPreset = true;
            huWl = huDefaultWl;
            huWw = huDefaultWw;
            document.querySelectorAll('.wl-pill').forEach(b => b.classList.toggle('active', b.dataset.wl === 'auto'));
            document.querySelectorAll('.iee-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.mode === 'normal');
            });
            btnInvert.classList.remove('active-toggle');
            updateFilters();
            fitToWindow(true);
            showToast("🔄 表示・計測をリセットしました");
        }

        // --------------------------------------------------------------------------
        // PACS標準マウス操作の実装 (16bit HUドラッグ・画素値表示・mm距離計測対応)
        // --------------------------------------------------------------------------
        stage.addEventListener('contextmenu', e => e.preventDefault());

        // ドック操作がステージのドラッグ・ホイールへ伝播しないよう遮断
        [tomoScrubDock, videoControlDock].forEach(dk => {
            if (!dk) return;
            ['mousedown', 'mouseup', 'click', 'dblclick', 'wheel'].forEach(evName => {
                dk.addEventListener(evName, ev => ev.stopPropagation());
            });
        });

        stage.addEventListener('mousedown', e => {
            if (e.target.closest('.tomo-dock') || e.target.closest('.video-dock') || e.target.closest('.float-nav-btn') || e.target.closest('.sidebar-reopen-btn')) {
                return;
            }
            if (e.button === 0 && activeMouseTool === 'ruler') {
                const stRect = viewports[0].getBoundingClientRect();
                const ix = (e.clientX - stRect.left - panX) / zoom;
                const iy = (e.clientY - stRect.top - panY) / zoom;
                isRulerDrawing = true;
                rulerLine = { x1: ix, y1: iy, x2: ix, y2: iy };
                renderRulerOverlay();
                return;
            }

            isDragging = true;
            dragButton = e.button;
            startMouseX = e.clientX;
            startMouseY = e.clientY;
            startPanX = panX;
            startPanY = panY;
            startZoom = zoom;
            startContrast = contrast;
            startBrightness = brightness;
            startHuWl = huWl;
            startHuWw = huWw;

            if (dragButton === 1 || activeMouseTool === 'pan') {
                stage.style.cursor = 'grabbing';
            } else if (dragButton === 2 || activeMouseTool === 'zoom') {
                stage.style.cursor = 'ns-resize';
            } else {
                stage.style.cursor = 'move';
            }
        });

        window.addEventListener('mousemove', e => {
            // カーソル下の 16bit CT/MRI 画素値 (HU) リアルタイム表示
            if (activeStageKind === 'hu16' && huBuffer && hudHuVal) {
                const stRect = viewports[0].getBoundingClientRect();
                const ix = Math.floor((e.clientX - stRect.left - panX) / zoom);
                const iy = Math.floor((e.clientY - stRect.top - panY) / zoom);
                if (ix >= 0 && ix < huWidth && iy >= 0 && iy < huHeight) {
                    const val = huBuffer[iy * huWidth + ix];
                    hudHuVal.textContent = `${val >= 0 ? '+' + val : val} HU`;
                } else {
                    hudHuVal.textContent = '-- HU';
                }
            }

            if (isRulerDrawing && rulerLine) {
                const stRect = viewports[0].getBoundingClientRect();
                rulerLine.x2 = (e.clientX - stRect.left - panX) / zoom;
                rulerLine.y2 = (e.clientY - stRect.top - panY) / zoom;
                renderRulerOverlay();
                return;
            }

            if (!isDragging) return;

            const dx = e.clientX - startMouseX;
            const dy = e.clientY - startMouseY;

            if (dragButton === 1 || (dragButton === 0 && activeMouseTool === 'pan')) {
                panX = startPanX + dx;
                panY = startPanY + dy;
                if (Math.hypot(dx, dy) > 2) {
                    isOlympusFitMode = false;
                    if (btnFit) btnFit.classList.remove('active');
                    if (btnOlympusFit) btnOlympusFit.classList.remove('active');
                }
                updateTransform();
                return;
            }

            if (dragButton === 2 || (dragButton === 0 && activeMouseTool === 'zoom')) {
                const zoomFactor = Math.exp(-dy * 0.008);
                const newZoom = Math.min(Math.max(startZoom * zoomFactor, 0.05), 12.0);
                const vp0 = viewports[0];
                const slotW = vp0.clientWidth || 400;
                const slotH = vp0.clientHeight || 400;
                const centerX = slotW / 2;
                const centerY = slotH / 2;

                panX = centerX - (centerX - startPanX) * (newZoom / startZoom);
                panY = centerY - (centerY - startPanY) * (newZoom / startZoom);
                zoom = newZoom;
                if (Math.abs(dy) > 2) {
                    isOlympusFitMode = false;
                    if (btnFit) btnFit.classList.remove('active');
                    if (btnOlympusFit) btnOlympusFit.classList.remove('active');
                }
                updateTransform();
                return;
            }

            if (dragButton === 0 && activeMouseTool === 'ww_wl') {
                if (activeStageKind === 'hu16' && huBuffer) {
                    // 16bit CT/MRI ダイレクトHUウィンドウ調整 (水平: WW, 垂直: WL)
                    huAutoPreset = false;
                    document.querySelectorAll('.wl-pill').forEach(b => b.classList.remove('active'));
                    const sens = Math.max(1.5, startHuWw / 350.0 * 2.5);
                    huWw = Math.max(1, Math.min(10000, Math.round(startHuWw + dx * sens)));
                    huWl = Math.max(-2000, Math.min(6000, Math.round(startHuWl - dy * sens)));
                    renderHuBufferToCanvas();
                } else {
                    contrast = Math.max(10, Math.min(400, startContrast + dx * 0.6));
                    brightness = Math.max(10, Math.min(300, startBrightness - dy * 0.5));
                    updateFilters();
                }
            }
        });

        window.addEventListener('mouseup', () => {
            if (isRulerDrawing) {
                isRulerDrawing = false;
                return;
            }
            if (isDragging) {
                isDragging = false;
                stage.style.cursor = 'crosshair';
            }
        });

        // 中央ホイール回転: スライス / MPR断面 / 停止中動画フレーム送り
        stage.addEventListener('wheel', e => {
            e.preventDefault();
            if (activeStageKind === 'video' && dcmMainVideo && dcmMainVideo.paused) {
                stepVideoFrame(e.deltaY > 0 ? 1 : -1);
                return;
            }
            if (mprPlane !== 'native') {
                if (e.deltaY > 0 && mprCurrentIdx < mprMaxSlices - 1) loadMprSlice(mprCurrentIdx + 1, false);
                else if (e.deltaY < 0 && mprCurrentIdx > 0) loadMprSlice(mprCurrentIdx - 1, false);
                return;
            }
            if (e.deltaY > 0) {
                if (currentIndex < dcmFiles.length - 1) loadSlice(currentIndex + 1);
            } else if (e.deltaY < 0) {
                if (currentIndex > 0) loadSlice(currentIndex - 1);
            }
        }, { passive: false });

        // ダブルクリック: O社フィット ⇔ 全体フィット トグル (内視鏡時のみ。非内視鏡時は全体フィット)
        stage.addEventListener('dblclick', () => {
            if (isEndoscopyDetected && !isOlympusFitMode) {
                olympusFit();
            } else {
                fitToWindow();
            }
        });

        // --------------------------------------------------------------------------
        // ショートカットキー対応
        // --------------------------------------------------------------------------
        window.addEventListener('keydown', e => {
            if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT')) return;
            if (e.key === ' ') {
                e.preventDefault();
                if (activeStageKind === 'video') toggleVideoPlay();
                else if (dcmFiles.length > 1 || mprPlane !== 'native') toggleCinePlay();
                return;
            }
            if (e.key === ',') {
                if (activeStageKind === 'video') { e.preventDefault(); stepVideoFrame(-1); return; }
            }
            if (e.key === '.') {
                if (activeStageKind === 'video') { e.preventDefault(); stepVideoFrame(1); return; }
            }
            if (e.key === 'm' || e.key === 'M') {
                setMouseMode(activeMouseTool === 'ruler' ? 'ww_wl' : 'ruler');
                return;
            }
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown' || e.key === 'PageDown') {
                if (activeStageKind === 'video' && dcmMainVideo && dcmMainVideo.paused) { stepVideoFrame(1); return; }
                if (mprPlane !== 'native') { if (mprCurrentIdx < mprMaxSlices - 1) loadMprSlice(mprCurrentIdx + 1, false); return; }
                if (currentIndex < dcmFiles.length - 1) loadSlice(currentIndex + 1);
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp' || e.key === 'PageUp') {
                if (activeStageKind === 'video' && dcmMainVideo && dcmMainVideo.paused) { stepVideoFrame(-1); return; }
                if (mprPlane !== 'native') { if (mprCurrentIdx > 0) loadMprSlice(mprCurrentIdx - 1, false); return; }
                if (currentIndex > 0) loadSlice(currentIndex - 1);
            } else if (e.key === 'Home') {
                loadSlice(0);
            } else if (e.key === 'End') {
                loadSlice(dcmFiles.length - 1);
            } else if (e.key === 'f' || e.key === 'F') {
                fitToWindow();
            } else if (e.key === 'o' || e.key === 'O') {
                if (isEndoscopyDetected) {
                    olympusFit();
                }
            } else if (e.key === '1') {
                setLayout(1);
            } else if (e.key === '2') {
                setLayout(2);
            } else if (e.key === '4') {
                setLayout(4);
            } else if (e.key === 'r' || e.key === 'R') {
                resetView();
            } else if (e.key === 'p' || e.key === 'P') {
                if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) return;
                e.preventDefault();
                toggleCurrentSliceTray();
            } else if ((e.ctrlKey || e.metaKey) && (e.key === 'c' || e.key === 'C')) {
                e.preventDefault();
                copyEntireImage();
            } else if (!e.ctrlKey && !e.metaKey && (e.key === 'c' || e.key === 'C')) {
                if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) return;
                e.preventDefault();
                toggleCropMode();
            } else if (e.key === '0' || e.key === 'n' || e.key === 'N') {
                setIeeMode('normal');
            } else if (e.key === 'i' || e.key === 'I') {
                btnInvert.click();
            } else if (e.key === 'Escape') {
                if (isCropMode) {
                    cancelCropMode();
                    return;
                }
                if (copyFallbackModal && copyFallbackModal.style.display !== 'none') {
                    closeCopyFallbackModal();
                    return;
                }
                closeViewer();
            }
        });

        // --------------------------------------------------------------------------
        // UIボタンイベントリスナー
        // --------------------------------------------------------------------------
        document.querySelectorAll('.layout-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const l = parseInt(btn.dataset.layout, 10);
                setLayout(l);
            });
        });

        if (ieeDock) {
            // ドック内のクリックやドラッグが画像ステージ(WW/WLドラッグ・ダブルクリック拡大・ホイールコマ送り)へ誤伝播するのを完全に遮断
            ieeDock.addEventListener('mousedown', e => e.stopPropagation());
            ieeDock.addEventListener('mouseup', e => e.stopPropagation());
            ieeDock.addEventListener('click', e => e.stopPropagation());
            ieeDock.addEventListener('dblclick', e => e.stopPropagation());
            ieeDock.addEventListener('wheel', e => e.stopPropagation());
        }

        document.querySelectorAll('.iee-btn').forEach(btn => {
            btn.addEventListener('click', e => {
                e.stopPropagation();
                setIeeMode(btn.dataset.mode);
            });
        });
        navPrevBtn.addEventListener('click', () => {
            if (mprPlane !== 'native') { if (mprCurrentIdx > 0) loadMprSlice(mprCurrentIdx - 1, false); return; }
            if (currentIndex > 0) loadSlice(currentIndex - 1);
        });
        navNextBtn.addEventListener('click', () => {
            if (mprPlane !== 'native') { if (mprCurrentIdx < mprMaxSlices - 1) loadMprSlice(mprCurrentIdx + 1, false); return; }
            if (currentIndex < dcmFiles.length - 1) loadSlice(currentIndex + 1);
        });
        floatPrevBtn.addEventListener('click', () => {
            if (mprPlane !== 'native') { if (mprCurrentIdx > 0) loadMprSlice(mprCurrentIdx - 1, false); return; }
            if (currentIndex > 0) loadSlice(currentIndex - 1);
        });
        floatNextBtn.addEventListener('click', () => {
            if (mprPlane !== 'native') { if (mprCurrentIdx < mprMaxSlices - 1) loadMprSlice(mprCurrentIdx + 1, false); return; }
            if (currentIndex < dcmFiles.length - 1) loadSlice(currentIndex + 1);
        });

        // マウスモードボタングループ
        function setMouseMode(mode) {
            activeMouseTool = mode;
            modeWwWlBtn.classList.toggle('active', mode === 'ww_wl');
            modePanBtn.classList.toggle('active', mode === 'pan');
            modeZoomBtn.classList.toggle('active', mode === 'zoom');
            if (modeRulerBtn) modeRulerBtn.classList.toggle('active', mode === 'ruler');
            const labels = {
                'ww_wl': '左ドラッグ: 階調 (WW/WL)',
                'pan': '左ドラッグ: 平行移動 (パン)',
                'zoom': '左ドラッグ: ズーム',
                'ruler': '📏 左ドラッグ: 実寸距離計測 (mm)'
            };
            showToast(labels[mode] || mode);
        }
        modeWwWlBtn.addEventListener('click', () => setMouseMode('ww_wl'));
        modePanBtn.addEventListener('click', () => setMouseMode('pan'));
        modeZoomBtn.addEventListener('click', () => setMouseMode('zoom'));
        if (modeRulerBtn) modeRulerBtn.addEventListener('click', () => setMouseMode('ruler'));

        btnFit.addEventListener('click', fitToWindow);
        if (btnOlympusFit) btnOlympusFit.addEventListener('click', olympusFit);
        btnOneToOne.addEventListener('click', oneToOne);
        btnReset.addEventListener('click', resetView);
        if (btnAddToTray) btnAddToTray.addEventListener('click', toggleCurrentSliceTray);
        if (btnOpenPrintTray) btnOpenPrintTray.addEventListener('click', openPrintView);

        // --------------------------------------------------------------------------
        // 画像クリップボードコピー & 範囲切り出しロジック
        // --------------------------------------------------------------------------
        const MAT_LCI = [
            1.45, -0.30, -0.15, 0,  0.02,
           -0.20,  1.25, -0.05, 0, -0.02,
           -0.10, -0.20,  1.30, 0,  0.00,
            0,     0,     0,    1,  0
        ];
        const MAT_TXI = [
            1.20, -0.12, -0.06, 0,  0.02,
           -0.06,  1.14, -0.05, 0,  0.01,
           -0.05, -0.06,  1.15, 0,  0.01,
            0,     0,     0,    1,  0
        ];
        const KERNEL_TXI = [
           -0.2, -0.3, -0.2,
           -0.3,  3.0, -0.3,
           -0.2, -0.3, -0.2
        ];
        const MAT_PALE = [
            0.78,  0.18,  0.06, 0, -0.02,
           -0.12,  1.22, -0.04, 0,  0.03,
            0.06, -0.16,  1.38, 0,  0.05,
            0,     0,     0,    1,  0
        ];
        const KERNEL_PALE = [
           -0.25, -0.4, -0.25,
           -0.4,   3.6, -0.4,
           -0.25, -0.4, -0.25
        ];
        const MAT_NBI = [
            0.35,  0.15,  0.05, 0, -0.05,
            0.10,  1.25,  0.15, 0,  0.00,
           -0.10,  0.10,  1.60, 0,  0.05,
            0,     0,     0,    1,  0
        ];
        const MAT_IHB = [
            1.80, -0.60, -0.20, 0,  0.05,
           -0.40,  1.40, -0.10, 0, -0.05,
           -0.80, -0.20,  1.80, 0, -0.10,
            0,     0,     0,    1,  0
        ];
        const KERNEL_TEXTURE = [
            -0.3, -0.6, -0.3,
            -0.6,  4.6, -0.6,
            -0.3, -0.6, -0.3
        ];
        const MAT_INDIGO = [
            0.85,  0.00,  0.00, 0, -0.05,
            0.00,  0.90,  0.00, 0, -0.02,
            0.10,  0.20,  1.30, 0,  0.10,
            0,     0,     0,    1,  0
        ];
        const KERNEL_INDIGO = [
            -0.2, -0.3, -0.2,
            -0.3,  3.0, -0.3,
            -0.2, -0.3, -0.2
        ];

        function applyColorMatrix(imageData, m) {
            const data = imageData.data;
            const len = data.length;
            const m0 = m[0], m1 = m[1], m2 = m[2], m4 = m[4] * 255;
            const m5 = m[5], m6 = m[6], m7 = m[7], m9 = m[9] * 255;
            const m10 = m[10], m11 = m[11], m12 = m[12], m14 = m[14] * 255;

            for (let i = 0; i < len; i += 4) {
                const r = data[i];
                const g = data[i + 1];
                const b = data[i + 2];

                const nr = m0 * r + m1 * g + m2 * b + m4;
                const ng = m5 * r + m6 * g + m7 * b + m9;
                const nb = m10 * r + m11 * g + m12 * b + m14;

                data[i]     = nr < 0 ? 0 : (nr > 255 ? 255 : nr);
                data[i + 1] = ng < 0 ? 0 : (ng > 255 ? 255 : ng);
                data[i + 2] = nb < 0 ? 0 : (nb > 255 ? 255 : nb);
            }
        }

        function applyKernel3x3(imageData, kernel) {
            const w = imageData.width;
            const h = imageData.height;
            const src = new Uint8ClampedArray(imageData.data);
            const dst = imageData.data;
            const [k0, k1, k2, k3, k4, k5, k6, k7, k8] = kernel;

            for (let y = 0; y < h; y++) {
                const ym1 = y > 0 ? y - 1 : 0;
                const yp1 = y < h - 1 ? y + 1 : h - 1;
                const rowSrcY   = y * w * 4;
                const rowSrcYm1 = ym1 * w * 4;
                const rowSrcYp1 = yp1 * w * 4;

                for (let x = 0; x < w; x++) {
                    const xm1 = x > 0 ? x - 1 : 0;
                    const xp1 = x < w - 1 ? x + 1 : w - 1;
                    const idx = (y * w + x) * 4;

                    const idx0 = rowSrcYm1 + xm1 * 4;
                    const idx1 = rowSrcYm1 + x * 4;
                    const idx2 = rowSrcYm1 + xp1 * 4;
                    const idx3 = rowSrcY + xm1 * 4;
                    const idx4 = rowSrcY + x * 4;
                    const idx5 = rowSrcY + xp1 * 4;
                    const idx6 = rowSrcYp1 + xm1 * 4;
                    const idx7 = rowSrcYp1 + x * 4;
                    const idx8 = rowSrcYp1 + xp1 * 4;

                    for (let c = 0; c < 3; c++) {
                        const val = (
                            k0 * src[idx0 + c] + k1 * src[idx1 + c] + k2 * src[idx2 + c] +
                            k3 * src[idx3 + c] + k4 * src[idx4 + c] + k5 * src[idx5 + c] +
                            k6 * src[idx6 + c] + k7 * src[idx7 + c] + k8 * src[idx8 + c]
                        );
                        dst[idx + c] = val < 0 ? 0 : (val > 255 ? 255 : val);
                    }
                }
            }
        }

        function applyIeeFilterToImageData(imageData, mode) {
            if (mode === 'lci') {
                applyColorMatrix(imageData, MAT_LCI);
            } else if (mode === 'txi') {
                applyColorMatrix(imageData, MAT_TXI);
                applyKernel3x3(imageData, KERNEL_TXI);
            } else if (mode === 'pale') {
                applyColorMatrix(imageData, MAT_PALE);
                applyKernel3x3(imageData, KERNEL_PALE);
            } else if (mode === 'nbi') {
                applyColorMatrix(imageData, MAT_NBI);
            } else if (mode === 'ihb') {
                applyColorMatrix(imageData, MAT_IHB);
            } else if (mode === 'texture') {
                applyKernel3x3(imageData, KERNEL_TEXTURE);
            } else if (mode === 'indigo') {
                applyColorMatrix(imageData, MAT_INDIGO);
                applyKernel3x3(imageData, KERNEL_INDIGO);
            }
        }

        function toggleCropMode() {
            if (isCropMode) {
                cancelCropMode();
            } else {
                startCropMode();
            }
        }

        function startCropMode() {
            isCropMode = true;
            btnCopy.classList.add('active');
            dcmCropOverlay.style.display = 'block';
            dcmCropBox.style.display = 'none';
            showToast("✂️ コピー領域をドラッグ、またはクリックで全体コピー");
        }

        function cancelCropMode() {
            isCropMode = false;
            isCropDragging = false;
            if (btnCopy) btnCopy.classList.remove('active');
            if (dcmCropOverlay) dcmCropOverlay.style.display = 'none';
            if (dcmCropBox) dcmCropBox.style.display = 'none';
        }

        function copyEntireImage() {
            executeCopy(null);
        }

        // 単一画像キャプチャ (原寸解像度 & フィルタ完全反映)
        function captureSingleImage(img, cropRect) {
            if (!img || !img.naturalWidth || !img.naturalHeight) return null;
            const nw = img.naturalWidth;
            const nh = img.naturalHeight;

            let sx = 0, sy = 0, sw = nw, sh = nh;
            if (cropRect && cropRect.width >= 10 && cropRect.height >= 10) {
                // ステージ座標から画像原寸座標への逆写像
                const rawX = (cropRect.x - panX) / zoom;
                const rawY = (cropRect.y - panY) / zoom;
                const rawW = cropRect.width / zoom;
                const rawH = cropRect.height / zoom;

                // 画像領域との交差クリッピング
                const clampLeft = Math.max(0, rawX);
                const clampTop = Math.max(0, rawY);
                const clampRight = Math.min(nw, rawX + rawW);
                const clampBottom = Math.min(nh, rawY + rawH);

                if (clampRight <= clampLeft || clampBottom <= clampTop) {
                    // 画像外ドラッグ時は全体コピー
                    showToast("⚠️ 選択範囲が画像外だったため画像全体をコピーします");
                    sx = 0; sy = 0; sw = nw; sh = nh;
                } else {
                    sx = Math.round(clampLeft);
                    sy = Math.round(clampTop);
                    sw = Math.round(clampRight - clampLeft);
                    sh = Math.round(clampBottom - clampTop);
                }
            }

            const canvas = document.createElement('canvas');
            canvas.width = sw;
            canvas.height = sh;
            const ctx = canvas.getContext('2d');

            // 階調 (WW/WL) および白黒反転フィルタ
            let filterParts = [];
            if (contrast !== 100) filterParts.push(`contrast(${contrast}%)`);
            if (brightness !== 100) filterParts.push(`brightness(${brightness}%)`);
            if (isInverted) filterParts.push('invert(100%)');
            if (activeIeeMode === 'lci') filterParts.push('saturate(135%)');
            if (activeIeeMode === 'nbi') filterParts.push('saturate(125%)');

            ctx.filter = filterParts.length > 0 ? filterParts.join(' ') : 'none';
            ctx.drawImage(img, sx, sy, sw, sh, 0, 0, sw, sh);

            // 内視鏡病変強調モード (IEE) のピクセル演算
            if (activeIeeMode !== 'normal') {
                const imgData = ctx.getImageData(0, 0, sw, sh);
                applyIeeFilterToImageData(imgData, activeIeeMode);
                ctx.putImageData(imgData, 0, 0);
            }

            return canvas;
        }

        // 複数画面レイアウトキャプチャ (2画面・4画面比較画像)
        function captureMultiLayoutImage(cropRect) {
            const stageW = stage.clientWidth;
            const stageH = stage.clientHeight;
            if (stageW <= 0 || stageH <= 0) return null;

            const compCanvas = document.createElement('canvas');
            compCanvas.width = stageW;
            compCanvas.height = stageH;
            const ctx = compCanvas.getContext('2d');

            ctx.fillStyle = '#05070c';
            ctx.fillRect(0, 0, stageW, stageH);

            const stRect = stage.getBoundingClientRect();

            for (let s = 0; s < currentLayout; s++) {
                const vp = viewports[s];
                if (!vp) continue;
                const vpRect = vp.getBoundingClientRect();
                const vx = vpRect.left - stRect.left;
                const vy = vpRect.top - stRect.top;
                const vw = vpRect.width;
                const vh = vpRect.height;

                ctx.save();
                ctx.beginPath();
                ctx.rect(vx, vy, vw, vh);
                ctx.clip();

                ctx.fillStyle = '#0b0f19';
                ctx.fillRect(vx, vy, vw, vh);

                const img = vp.querySelector('.dcm-slot-img');
                const fileIdx = currentIndex + s;

                if (img && img.naturalWidth && !vp.classList.contains('is-empty')) {
                    const singleCanvas = captureSingleImage(img, null);
                    if (singleCanvas) {
                        const destX = vx + panX;
                        const destY = vy + panY;
                        const destW = img.naturalWidth * zoom;
                        const destH = img.naturalHeight * zoom;
                        ctx.drawImage(singleCanvas, destX, destY, destW, destH);
                    }
                } else {
                    ctx.fillStyle = '#64748b';
                    ctx.font = '14px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText('(画像なし)', vx + vw / 2, vy + vh / 2);
                }

                ctx.strokeStyle = 'rgba(255, 255, 255, 0.12)';
                ctx.lineWidth = 1;
                ctx.strokeRect(vx, vy, vw, vh);

                ctx.restore();
            }

            if (cropRect && cropRect.width >= 10 && cropRect.height >= 10) {
                const cropCanvas = document.createElement('canvas');
                cropCanvas.width = cropRect.width;
                cropCanvas.height = cropRect.height;
                const cropCtx = cropCanvas.getContext('2d');
                cropCtx.drawImage(compCanvas, cropRect.x, cropRect.y, cropRect.width, cropRect.height, 0, 0, cropRect.width, cropRect.height);
                return cropCanvas;
            }

            return compCanvas;
        }

        // 16bit Canvas または Video 要素からの直接キャプチャ
        function captureActiveElementCanvas(cropRect) {
            let srcEl = null;
            let nw = 0, nh = 0;
            if (activeStageKind === 'hu16' && dcmHuCanvas && dcmHuCanvas.width > 0) {
                srcEl = dcmHuCanvas;
                nw = dcmHuCanvas.width;
                nh = dcmHuCanvas.height;
            } else if (activeStageKind === 'video' && dcmMainVideo && dcmMainVideo.videoWidth > 0) {
                srcEl = dcmMainVideo;
                nw = dcmMainVideo.videoWidth;
                nh = dcmMainVideo.videoHeight;
            }
            if (!srcEl || !nw || !nh) return null;

            let sx = 0, sy = 0, sw = nw, sh = nh;
            if (cropRect && cropRect.width >= 10 && cropRect.height >= 10) {
                const rawX = (cropRect.x - panX) / zoom;
                const rawY = (cropRect.y - panY) / zoom;
                const rawW = cropRect.width / zoom;
                const rawH = cropRect.height / zoom;
                const cl = Math.max(0, rawX), ct = Math.max(0, rawY);
                const cr = Math.min(nw, rawX + rawW), cb = Math.min(nh, rawY + rawH);
                if (cr > cl && cb > ct) {
                    sx = Math.round(cl); sy = Math.round(ct);
                    sw = Math.round(cr - cl); sh = Math.round(cb - ct);
                }
            }
            const c = document.createElement('canvas');
            c.width = sw;
            c.height = sh;
            const cx = c.getContext('2d');
            cx.drawImage(srcEl, sx, sy, sw, sh, 0, 0, sw, sh);
            return c;
        }

        function executeCopy(cropRect) {
            let canvas = null;
            const isFull = !cropRect;
            if (currentLayout === 1) {
                if (activeStageKind === 'hu16' || activeStageKind === 'video') {
                    canvas = captureActiveElementCanvas(cropRect);
                } else {
                    const vp0 = viewports[0];
                    const img0 = vp0 ? vp0.querySelector('.dcm-slot-img') : null;
                    if (!img0 || !img0.naturalWidth) {
                        showToast("⚠️ コピー対象の画像がありません");
                        return;
                    }
                    canvas = captureSingleImage(img0, cropRect);
                }
            } else {
                canvas = captureMultiLayoutImage(cropRect);
            }

            if (canvas) {
                copyCanvasToClipboard(canvas, isFull);
            }
        }

        function getCaptureFilename(isFullImage) {
            const pad = (n) => String(n).padStart(2, '0');
            const now = new Date();
            const ts = `${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
            const idPart = PAT_ID ? `_${PAT_ID}` : '';
            const slicePart = `_${currentIndex + 1}`;
            const ieePart = (activeIeeMode !== 'normal') ? `_${activeIeeMode}` : '';
            const cropPart = isFullImage ? '' : '_crop';
            return `dicom_capture${idPart}${slicePart}${ieePart}${cropPart}_${ts}.png`;
        }

        function copyCanvasToClipboard(canvas, isFullImage) {
            if (!canvas) {
                showToast("⚠️ コピー対象画像が生成できませんでした");
                return;
            }

            const filename = getCaptureFilename(isFullImage);
            const dataUrl = canvas.toDataURL('image/png');

            canvas.toBlob(async blob => {
                if (!blob) {
                    showToast("⚠️ Blob生成に失敗しました");
                    return;
                }

                lastCaptureBlob = blob;
                lastCaptureFilename = filename;

                let writeOk = false;
                if (navigator.clipboard && typeof navigator.clipboard.write === 'function' && window.ClipboardItem) {
                    try {
                        const item = new ClipboardItem({ 'image/png': blob });
                        await navigator.clipboard.write([item]);
                        writeOk = true;
                    } catch (err) {
                        console.warn("Clipboard API blocked (HTTP LAN / security restrictions):", err);
                        writeOk = false;
                    }
                }

                if (writeOk) {
                    showToast("📋 クリップボードに画像をコピーしました！");
                } else {
                    // LAN環境・非HTTPSでのフォールバック (自動ダウンロード + プレビューモーダル)
                    triggerBlobDownload(blob, filename);
                    openCopyFallbackModal(dataUrl, filename);
                    showToast("💾 画像を保存しました (右クリックでコピーも可能)");
                }
            }, 'image/png');
        }

        function triggerBlobDownload(blob, filename) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 5000);
        }

        function openCopyFallbackModal(dataUrl, filename) {
            copyModalTitle.textContent = "📋 画像キャプチャ完了";
            copyModalMsg.innerHTML = "セキュリティ制限 (非HTTPS環境) のため、クリップボードへの直接書き込みが制限されました。<br><strong>画像を自動保存 (ダウンロード)</strong> しました。<br>または下の画像を右クリックして<strong>「画像をコピー」</strong>してください。";
            copyPreviewImg.src = dataUrl;
            copyFallbackModal.style.display = 'flex';
        }

        function closeCopyFallbackModal() {
            copyFallbackModal.style.display = 'none';
        }

        // 範囲指定オーバーレイのドラッグイベント
        dcmCropOverlay.addEventListener('mousedown', e => {
            if (e.target.closest('#cropCancelBtn')) return;
            e.preventDefault();
            e.stopPropagation();

            const stRect = stage.getBoundingClientRect();
            isCropDragging = true;
            cropStartX = e.clientX - stRect.left;
            cropStartY = e.clientY - stRect.top;

            dcmCropBox.style.left = cropStartX + 'px';
            dcmCropBox.style.top = cropStartY + 'px';
            dcmCropBox.style.width = '0px';
            dcmCropBox.style.height = '0px';
            dcmCropBox.style.display = 'block';
            cropDimBadge.textContent = '0 × 0';
        });

        dcmCropOverlay.addEventListener('mousemove', e => {
            if (!isCropDragging) return;
            e.preventDefault();
            e.stopPropagation();

            const stRect = stage.getBoundingClientRect();
            const curX = Math.max(0, Math.min(stRect.width, e.clientX - stRect.left));
            const curY = Math.max(0, Math.min(stRect.height, e.clientY - stRect.top));

            const x = Math.min(cropStartX, curX);
            const y = Math.min(cropStartY, curY);
            const w = Math.abs(curX - cropStartX);
            const h = Math.abs(curY - cropStartY);

            dcmCropBox.style.left = x + 'px';
            dcmCropBox.style.top = y + 'px';
            dcmCropBox.style.width = w + 'px';
            dcmCropBox.style.height = h + 'px';

            if (currentLayout === 1) {
                const natW = Math.round(w / zoom);
                const natH = Math.round(h / zoom);
                cropDimBadge.textContent = `${w} × ${h} px (原寸: ${natW} × ${natH})`;
            } else {
                cropDimBadge.textContent = `${w} × ${h} px`;
            }
        });

        dcmCropOverlay.addEventListener('mouseup', e => {
            if (!isCropDragging) return;
            e.preventDefault();
            e.stopPropagation();
            isCropDragging = false;

            const stRect = stage.getBoundingClientRect();
            const curX = Math.max(0, Math.min(stRect.width, e.clientX - stRect.left));
            const curY = Math.max(0, Math.min(stRect.height, e.clientY - stRect.top));

            const x = Math.min(cropStartX, curX);
            const y = Math.min(cropStartY, curY);
            const w = Math.abs(curX - cropStartX);
            const h = Math.abs(curY - cropStartY);

            cancelCropMode();

            if (w < 10 || h < 10) {
                // ドラッグ距離が小さい場合は画面全体コピー
                executeCopy(null);
            } else {
                // 範囲指定コピー
                executeCopy({ x, y, width: w, height: h });
            }
        });

        cropCancelBtn.addEventListener('click', e => {
            e.stopPropagation();
            cancelCropMode();
            showToast("コピーをキャンセルしました");
        });

        btnCopy.addEventListener('click', () => {
            toggleCropMode();
        });

        copyModalCloseBtn.addEventListener('click', closeCopyFallbackModal);
        copyModalOkBtn.addEventListener('click', closeCopyFallbackModal);
        copyModalDownloadBtn.addEventListener('click', () => {
            if (lastCaptureBlob) {
                triggerBlobDownload(lastCaptureBlob, lastCaptureFilename || 'dicom_capture.png');
            }
        });
        copyFallbackModal.addEventListener('click', e => {
            if (e.target === copyFallbackModal) closeCopyFallbackModal();
        });

        // 白黒反転
        btnInvert.addEventListener('click', () => {
            isInverted = !isInverted;
            btnInvert.classList.toggle('active-toggle', isInverted);
            updateFilters();
            showToast(isInverted ? "🌓 白黒反転 有効" : "🌓 白黒反転 解除");
        });

        // サイドバー開閉
        btnToggleSidebar.addEventListener('click', () => {
            sidebar.classList.add('collapsed');
            sidebarReopenBtn.style.display = 'flex';
        });
        sidebarReopenBtn.addEventListener('click', () => {
            sidebar.classList.remove('collapsed');
            sidebarReopenBtn.style.display = 'none';
        });

        // 一括ZIP
        btnDownloadZip.addEventListener('click', () => {
            if (!FOLDER_PATH) return;
            showToast("📦 一括ZIPを生成中...");
            window.location.href = `dcm_api.php?action=zip&folder=${encodeURIComponent(FOLDER_PATH)}`;
        });

        // 外部ビューワ直接起動（ゼロSMB・HTTPキャッシュ方式でクライアントの RSViewer を起動）
        btnDirectLaunch.addEventListener('click', () => {
            if (!STUDY_ID && !FOLDER_PATH) return;
            const formData = new URLSearchParams();
            formData.append('action', 'launch');
            if (STUDY_ID > 0) formData.append('study_id', String(STUDY_ID));
            if (FOLDER_PATH) formData.append('folder', FOLDER_PATH);
            formData.append('launch_mode', 'HTTP_DICOM');

            fetch('index.php', { method: 'POST', body: formData })
                .then(r => r.text())
                .then(text => {
                    let d;
                    try { d = JSON.parse(text); } catch(err) { throw new Error(text.slice(0, 100)); }
                    if (d.status === 'success') {
                        showToast("🚀 ローカル RSViewer を起動しています（共有フォルダ不要）...");
                    } else {
                        alert("起動エラー: " + d.message);
                    }
                })
                .catch(e => alert("通信エラー: " + e.message));
        });

        // 別窓化 (ポップアウト)
        if (btnPopout) {
            btnPopout.addEventListener('click', () => {
                const params = new URLSearchParams(window.location.search);
                params.set('mode', 'standalone');
                const win = window.open(`dicom_view.php?${params.toString()}`, '_blank', 'width=1600,height=900,menubar=no,toolbar=no');
                if (win) {
                    closeViewer();
                }
            });
        }

        // クローズ処理（モーダル内・別タブ・直接遷移の全パターン対応）
        function closeViewer() {
            if (window.parent && window.parent !== window) {
                try {
                    if (typeof window.parent.closeViewerModal === 'function') {
                        window.parent.closeViewerModal();
                        return;
                    }
                } catch (_) {}
                window.parent.postMessage({ action: 'closeDicom' }, '*');
                return;
            }
            window.close();
            setTimeout(() => {
                if (!window.closed) {
                    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1 && window.history.length > 1) {
                        window.history.back();
                    } else {
                        window.location.href = 'index.php';
                    }
                }
            }, 150);
        }
        if (btnCloseModal) {
            btnCloseModal.addEventListener('click', closeViewer);
        }

        // リサイズ時の自動追従
        window.addEventListener('resize', () => {
            applyActiveFit();
        });

        // --------------------------------------------------------------------------
        // 🖨️ 2×4 印刷トレイ管理 (患者ID別独立キー & 正規化座標連携)
        // --------------------------------------------------------------------------
        const PRINT_TRAY_PREFIX = 'rsbase_print_tray_p_';
        const LEGACY_PRINT_TRAY_KEY = 'rsbase_print_tray';
        const PRINT_TRAY_TTL_MS = 24 * 60 * 60 * 1000; // 24時間経過した古い一時トレイを自動整理

        function normalizeTrayPatientId(rawId) {
            if (rawId === null || rawId === undefined) return '';
            const s = String(rawId).trim();
            if (!s || s === '00000') return '';
            return s.replace(/^0+([0-9]+)$/, '$1');
        }

        const NORM_PAT_ID = normalizeTrayPatientId(PAT_ID);
        const CURRENT_TRAY_KEY = NORM_PAT_ID ? `${PRINT_TRAY_PREFIX}${NORM_PAT_ID}` : '';

        // 旧共通キーの安全な移行・破棄 ＆ 期限切れトレイ掃除
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

                const keysToRemove = [];
                for (let i = 0; i < localStorage.length; i++) {
                    const k = localStorage.key(i);
                    if (k && k.indexOf(PRINT_TRAY_PREFIX) === 0) {
                        try {
                            const obj = JSON.parse(localStorage.getItem(k));
                            if (!obj || (obj.updatedAt && (now - obj.updatedAt) > PRINT_TRAY_TTL_MS)) {
                                keysToRemove.push(k);
                            }
                        } catch (_) {
                            keysToRemove.push(k);
                        }
                    }
                }
                keysToRemove.forEach(k => localStorage.removeItem(k));
            } catch (err) {
                console.warn("Print tray cleanup warning:", err);
            }
        })();

        function getPrintTray() {
            if (!CURRENT_TRAY_KEY) {
                return { patientId: '', patientName: PAT_NAME || '', items: [] };
            }
            try {
                const raw = localStorage.getItem(CURRENT_TRAY_KEY);
                if (raw) {
                    const parsed = JSON.parse(raw);
                    if (parsed && normalizeTrayPatientId(parsed.patientId) === NORM_PAT_ID && Array.isArray(parsed.items)) {
                        parsed.items = parsed.items.filter(it => !it.patientId || normalizeTrayPatientId(it.patientId) === NORM_PAT_ID);
                        return parsed;
                    }
                }
            } catch (err) {
                console.warn("Print tray parse error:", err);
            }
            return {
                patientId: NORM_PAT_ID,
                patientName: PAT_NAME || '',
                items: []
            };
        }

        function savePrintTray(tray) {
            if (!CURRENT_TRAY_KEY) return;
            try {
                tray.patientId = NORM_PAT_ID;
                tray.patientName = PAT_NAME || tray.patientName || '';
                tray.updatedAt = Date.now();
                if (Array.isArray(tray.items)) {
                    tray.items.forEach(it => { it.patientId = NORM_PAT_ID; });
                }
                localStorage.setItem(CURRENT_TRAY_KEY, JSON.stringify(tray));
                updateTrayUI();
            } catch (err) {
                console.error("Print tray save error:", err);
            }
        }

        // 現在のビューワ表示状態から、印刷枠サイズに依存しない正規化パン(%)＆相対倍率を算出
        function computeNormalizedPrintViewTransform() {
            // 標準全体フィット または O社フィット状態（手動で病変ズーム・パンをしていない初期状態）の場合は、
            // print_view.php 側のデフォルト自動配置（オリンパス内視鏡なら高精度O社フィット、それ以外は全体配置）に委ねる
            const isPresetFit = isOlympusFitMode || (btnFit && btnFit.classList.contains('active'));
            if (isPresetFit) {
                return { fitMode: 'auto', panPctX: 0, panPctY: 0, zoom: 1.0 };
            }

            const vp0 = viewports[0];
            const img0 = vp0 ? vp0.querySelector('.dcm-slot-img') : null;
            if (!vp0 || !img0 || !img0.naturalWidth || !img0.naturalHeight) {
                return { fitMode: 'auto', panPctX: 0, panPctY: 0, zoom: 1.0 };
            }

            const slotW = vp0.clientWidth || stage.clientWidth || 1200;
            const slotH = vp0.clientHeight || stage.clientHeight || 800;
            const imgW = img0.naturalWidth;
            const imgH = img0.naturalHeight;

            // 現在ビューポート中央に見えている元画像上の正規化座標 (0.0〜1.0)
            const curZoom = zoom || 1.0;
            const focusX = Math.max(0, Math.min(1, ((slotW / 2) - panX) / (curZoom * imgW)));
            const focusY = Math.max(0, Math.min(1, ((slotH / 2) - panY) / (curZoom * imgH)));

            // 全体contain基準に対する相対ズーム倍率
            const baseFit = Math.min(slotW / imgW, slotH / imgH);
            const relZoom = Math.max(0.5, Math.min(5.0, Math.round((curZoom / baseFit) * 100) / 100));

            const panPctX = Math.round((0.5 - focusX) * relZoom * 100 * 100) / 100;
            const panPctY = Math.round((0.5 - focusY) * relZoom * 100 * 100) / 100;

            return { fitMode: 'custom', panPctX, panPctY, zoom: relZoom };
        }

        function isFileInTray(filename) {
            if (!filename) return false;
            const tray = getPrintTray();
            if (!tray || !tray.items || tray.items.length === 0) return false;
            return tray.items.some(item => item.file === filename && (item.folder === FOLDER_PATH || !item.folder));
        }

        function toggleTrayItem(filename, index) {
            toggleThumbnailTray(filename, index);
        }

        function toggleThumbnailTray(filename, index) {
            if (!filename) return;
            if (!CURRENT_TRAY_KEY) {
                alert("患者IDが特定できないため印刷トレイに追加できません。一覧画面から患者検査を開いてください。");
                return;
            }
            let tray = getPrintTray();
            if (!Array.isArray(tray.items)) tray.items = [];

            const existingIdx = tray.items.findIndex(it => it.file === filename && (it.folder === FOLDER_PATH || !it.folder));

            if (existingIdx >= 0) {
                tray.items.splice(existingIdx, 1);
                savePrintTray(tray);
                showToast(`🗑️ #${index + 1} (${filename}) を印刷トレイから除外しました (${tray.items.length}/8)`);
            } else {
                if (tray.items.length >= 8) {
                    alert("印刷トレイの上限（最大8枚）に達しています。\n2×4印刷画面で枠を整理するか、不要な画像を削除してください。");
                    return;
                }

                const cleanDate = (EXAM_DATE || '').split(' ')[0] || '';
                const cap = `[${EXAM_NAME || '検査'}] ${cleanDate} (${filename})`;

                tray.items.push({
                    id: `${FOLDER_PATH}_${filename}_${Date.now()}`,
                    patientId: NORM_PAT_ID,
                    patientName: PAT_NAME,
                    folder: FOLDER_PATH,
                    file: filename,
                    examName: EXAM_NAME,
                    examDate: EXAM_DATE,
                    caption: cap,
                    fitMode: 'auto',
                    panPctX: 0,
                    panPctY: 0,
                    zoom: 1.0,
                    ieeMode: 'normal',
                    contrast: 100,
                    brightness: 100,
                    invert: false
                });
                savePrintTray(tray);
                showToast(`🖨️ #${index + 1} (${filename}) を印刷トレイに追加しました (${tray.items.length}/8)`);
            }
        }

        // 表示中スライス（または動画フレーム・MPR/16bit CT断面）を2×4印刷トレイに追加
        async function toggleCurrentSliceTray() {
            if (!dcmFiles || !dcmFiles[currentIndex]) return;
            if (!CURRENT_TRAY_KEY) {
                alert("患者IDが特定できないため印刷トレイに追加できません。一覧画面から患者検査を開いてください。");
                return;
            }

            let tray = getPrintTray();
            if (!Array.isArray(tray.items)) tray.items = [];
            if (tray.items.length >= 8) {
                alert("印刷トレイの上限（最大8枚）に達しています。\n2×4印刷画面で枠を整理するか、不要な画像を削除してください。");
                return;
            }

            // 動画フレーム または 16bit CT/MPR断面のスナップショット追加
            if (activeStageKind === 'video' || activeStageKind === 'hu16') {
                const snapCanvas = captureActiveElementCanvas(null);
                if (!snapCanvas) {
                    showToast("⚠️ トレイ用スナップショットを生成できませんでした");
                    return;
                }
                const blob = await new Promise(res => snapCanvas.toBlob(res, 'image/jpeg', 0.92));
                if (!blob) return;
                const fd = new FormData();
                fd.append('snap_blob', blob, 'snap.jpg');
                try {
                    const r = await fetch('dcm_api.php?action=save_tray_snapshot', { method: 'POST', body: fd });
                    const out = await r.json();
                    if (out.status !== 'success' || !out.snap_url) throw new Error('保存エラー');
                    const cleanDate = (EXAM_DATE || '').split(' ')[0] || '';
                    let detailTag = '';
                    if (activeStageKind === 'video') {
                        detailTag = ` [動画 ${formatVidTime(dcmMainVideo.currentTime || 0)}]`;
                    } else if (mprPlane !== 'native') {
                        detailTag = ` [MPR ${mprPlane.toUpperCase()} #${mprCurrentIdx + 1} WL:${Math.round(huWl)}/WW:${Math.round(huWw)}]`;
                    } else {
                        detailTag = ` [#${currentIndex + 1} WL:${Math.round(huWl)}/WW:${Math.round(huWw)}]`;
                    }
                    const fname = dcmFiles[currentIndex].filename;
                    tray.items.push({
                        id: `${FOLDER_PATH}_${fname}_${Date.now()}`,
                        patientId: NORM_PAT_ID,
                        patientName: PAT_NAME,
                        folder: FOLDER_PATH,
                        file: fname,
                        snapshotUrl: out.snap_url,
                        examName: EXAM_NAME,
                        examDate: EXAM_DATE,
                        caption: `[${EXAM_NAME || '検査'}] ${cleanDate}${detailTag}`,
                        fitMode: 'auto',
                        panPctX: 0,
                        panPctY: 0,
                        zoom: 1.0,
                        ieeMode: 'normal',
                        contrast: 100,
                        brightness: 100,
                        invert: false
                    });
                    savePrintTray(tray);
                    showToast(`🖨️ ${detailTag} を2×4印刷トレイに追加しました (${tray.items.length}/8)`);
                } catch (e) {
                    alert('印刷トレイへのスナップショット登録に失敗しました: ' + e.message);
                }
                return;
            }

            const filename = dcmFiles[currentIndex].filename;
            const existingInstances = tray.items.filter(it => it.file === filename && !it.snapshotUrl && (it.folder === FOLDER_PATH || !it.folder));

            const normTf = computeNormalizedPrintViewTransform();
            const cleanDate = (EXAM_DATE || '').split(' ')[0] || '';
            let modeTag = '';
            if (activeIeeMode === 'lci') modeTag = ' 【LCI発赤強調】';
            else if (activeIeeMode === 'nbi') modeTag = ' 【NBI血管強調】';
            else if (activeIeeMode === 'txi') modeTag = ' 【TXI自然強調】';
            else if (activeIeeMode === 'pale') modeTag = ' 【褪色強調】';
            else if (activeIeeMode === 'ihb') modeTag = ' 【血流マップ】';
            else if (activeIeeMode === 'texture') modeTag = ' 【構造強調】';
            else if (activeIeeMode === 'indigo') modeTag = ' 【色素散布】';
            else if (isInverted) modeTag = ' 【白黒反転】';

            let zoomTag = (normTf.fitMode === 'custom' && normTf.zoom >= 1.25) ? ' [拡大]' : '';
            const cap = `[${EXAM_NAME || '検査'}] ${cleanDate} (${filename})${modeTag}${zoomTag}`;

            // すでに同一画像がトレイにある場合
            if (existingInstances.length > 0) {
                const isDiffState = existingInstances.every(it =>
                    (it.ieeMode || 'normal') !== activeIeeMode ||
                    Math.abs((it.zoom || 1.0) - normTf.zoom) > 0.2 ||
                    !!it.invert !== isInverted
                );

                if (!isDiffState) {
                    const addMore = confirm(`この画像（${filename}）は既にトレイに登録されています。\n同一画像の別枠（一部拡大比較用）としてもう1枚追加しますか？\n\n・[OK]: 別枠として追加\n・[キャンセル]: トレイから削除`);
                    if (!addMore) {
                        tray.items = tray.items.filter(it => !(it.file === filename && (it.folder === FOLDER_PATH || !it.folder)));
                        savePrintTray(tray);
                        showToast(`🗑️ #${currentIndex + 1} (${filename}) を印刷トレイから除外しました (${tray.items.length}/8)`);
                        return;
                    }
                }
            }

            if (tray.items.length >= 8) {
                alert("印刷トレイの上限（最大8枚）に達しています。\n2×4印刷画面で枠を整理するか、不要な画像を削除してください。");
                return;
            }

            tray.items.push({
                id: `${FOLDER_PATH}_${filename}_${Date.now()}`,
                patientId: NORM_PAT_ID,
                patientName: PAT_NAME,
                folder: FOLDER_PATH,
                file: filename,
                examName: EXAM_NAME,
                examDate: EXAM_DATE,
                caption: cap,
                fitMode: normTf.fitMode || 'auto',
                panPctX: normTf.panPctX,
                panPctY: normTf.panPctY,
                zoom: normTf.zoom,
                ieeMode: activeIeeMode || 'normal',
                contrast: contrast || 100,
                brightness: brightness || 100,
                invert: isInverted
            });

            savePrintTray(tray);
            const countStr = existingInstances.length > 0 ? ` (同一画像 ${existingInstances.length + 1}枚目)` : '';
            showToast(`🖨️ #${currentIndex + 1} (${filename}${modeTag}${zoomTag}) をトレイに追加しました${countStr} (${tray.items.length}/8)`);
        }

        function updateTrayUI() {
            const tray = getPrintTray();
            const count = (tray && tray.items) ? tray.items.length : 0;

            if (trayCountBadge) {
                trayCountBadge.textContent = count;
            }

            if (btnAddToTray && dcmFiles && dcmFiles[currentIndex]) {
                const currentFile = dcmFiles[currentIndex].filename;
                const matches = (tray && tray.items) ? tray.items.filter(it => it.file === currentFile && (it.folder === FOLDER_PATH || !it.folder)) : [];
                if (matches.length > 0) {
                    btnAddToTray.textContent = `➕ 別枠追加 (${matches.length}枚中)`;
                    btnAddToTray.style.background = "#059669";
                    btnAddToTray.style.borderColor = "#34d399";
                    btnAddToTray.style.color = "#ffffff";
                    btnAddToTray.title = `現在表示中の画像を同一画像の比較別枠として印刷トレイに追加 [Pキー]`;
                } else {
                    btnAddToTray.textContent = "➕ トレイ追加";
                    btnAddToTray.style.background = "rgba(16, 185, 129, 0.15)";
                    btnAddToTray.style.borderColor = "#10b981";
                    btnAddToTray.style.color = "#6ee7b7";
                    btnAddToTray.title = `現在表示中の画像を2×4印刷トレイに追加 [Pキー]`;
                }
            }

            document.querySelectorAll('.thumb-card').forEach(card => {
                const fname = card.dataset.filename;
                if (!fname) return;
                const matches = (tray && tray.items) ? tray.items.filter(it => it.file === fname && (it.folder === FOLDER_PATH || !it.folder)) : [];
                const inTray = matches.length > 0;
                card.classList.toggle('in-tray', inTray);
                const btn = card.querySelector('.thumb-tray-btn');
                if (btn) {
                    btn.classList.toggle('in-tray', inTray);
                    btn.textContent = inTray ? (matches.length > 1 ? `✓${matches.length}` : '✓') : '＋';
                    btn.title = inTray ? `印刷トレイから除外 (現在${matches.length}枚登録)` : '印刷トレイに追加 (2×4印刷用)';
                }
            });
        }

        function openPrintView() {
            const pId = PAT_ID || '';
            const pName = PAT_NAME || '';
            const url = `print_view.php?patient_id=${encodeURIComponent(pId)}&name=${encodeURIComponent(pName)}`;
            window.open(url, '_blank', 'width=1400,height=950,menubar=no,toolbar=no,scrollbars=yes,resizable=yes');
        }

        // 別タブや別ウィンドウでの同一患者トレイ変更を自動同期
        window.addEventListener('storage', e => {
            if (CURRENT_TRAY_KEY && e.key === CURRENT_TRAY_KEY) {
                updateTrayUI();
            }
        });

        // 起動開始
        initViewer();
    </script>
</body>
</html>
