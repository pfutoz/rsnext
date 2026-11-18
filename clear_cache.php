<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next キャッシュ管理・強制クリアツール (clear_cache.php)
// - サーバー側派生キャッシュ (thumbs / adhoc_dcm / series_meta / hu16 / mpr / video_mp4 / ecg / tray_snaps / tmp) の可視化＆安全パージ
// - ※検査原本データ (storage_data/vol01/studies 等) や PostgreSQL DB には一切手を触れません
// - クライアントPC側の RSViewer ローカルキャッシュ (C:\RSBASE\dicom_cache) クリアトリガーにも対応
// ==============================================================================

require_once __DIR__ . '/lib/bootstrap.php';

$cfg = rsnext_config();
$cacheRoot = rtrim(rsnext_cache_root(), '\\/');
$tmpRoot   = __DIR__ . '\\tmp';

$categories = [
    'thumbs' => [
        'label' => '🖼️ サムネイル・プレビューキャッシュ',
        'desc'  => '一覧・フィルムストリップ高速表示用のJPEGキャッシュ (cache/thumbs)',
        'dir'   => $cacheRoot . '\\thumbs',
    ],
    'adhoc_dcm' => [
        'label' => '🔬 単発DICOM変換・一時ZIPキャッシュ',
        'desc'  => '表示時オンデマンド変換画像および一時バンドル (cache/adhoc_dcm)',
        'dir'   => $cacheRoot . '\\adhoc_dcm',
    ],
    'ct_mpr' => [
        'label' => '🌀 CT/MRI シリーズ解析・16bit・MPRキャッシュ',
        'desc'  => 'マルチシリーズ構造JSON・16bit HU・MPR再構成バイナリ (series_meta / hu16 / mpr)',
        'dirs'  => [
            $cacheRoot . '\\series_meta',
            $cacheRoot . '\\hu16',
            $cacheRoot . '\\mpr',
        ],
    ],
    'video_ecg' => [
        'label' => '🎬 動画MP4変換・📈 心電図・🖨️ 印刷スナップショット',
        'desc'  => 'AVI/Cine動画のMP4変換キャッシュ・心電図キャッシュ・トレイ画像 (video_mp4 / ecg / tray_snaps)',
        'dirs'  => [
            $cacheRoot . '\\video_mp4',
            $cacheRoot . '\\ecg',
            $cacheRoot . '\\tray_snaps',
        ],
    ],
    'cd_build' => [
        'label' => '💿 CD作成一時ビルドデータ',
        'desc'  => '紹介・患者渡しCD作成時の一時ZIPステージング (tmp/cd_build)',
        'dir'   => $tmpRoot . '\\cd_build',
    ],
    'triggers' => [
        'label' => '⚡ 未消費の起動トリガー残骸',
        'desc'  => 'クライアント未応答等で残った trigger_*.txt / cdburn_*.txt (tmp/)',
        'mode'  => 'triggers',
    ],
];

function rsnext_dir_stats(string $dir): array {
    if (!is_dir($dir)) return ['count' => 0, 'bytes' => 0];
    $count = 0;
    $bytes = 0;
    try {
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $fi) {
            if (!$fi->isFile()) continue;
            if ($fi->getFilename() === '.gitkeep') continue;
            $count++;
            $bytes += ($fi->getSize() ?: 0);
        }
    } catch (Throwable $e) {
    }
    return ['count' => $count, 'bytes' => $bytes];
}

function rsnext_purge_dir_contents(string $dir): array {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
        return ['count' => 0, 'bytes' => 0, 'errors' => 0];
    }
    $count = 0;
    $bytes = 0;
    $errors = 0;
    try {
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rii as $fi) {
            $fp = $fi->getPathname();
            if ($fi->isDir()) {
                @rmdir($fp);
            } else {
                if ($fi->getFilename() === '.gitkeep') continue;
                $sz = @$fi->getSize() ?: 0;
                if (@unlink($fp)) {
                    $count++;
                    $bytes += $sz;
                } else {
                    $errors++;
                }
            }
        }
    } catch (Throwable $e) {
        $errors++;
    }
    return ['count' => $count, 'bytes' => $bytes, 'errors' => $errors];
}

function rsnext_trigger_stats(string $tmpRoot): array {
    $files = array_merge(
        glob($tmpRoot . '\\trigger_*.*') ?: [],
        glob($tmpRoot . '\\cdburn_*.*') ?: []
    );
    $count = 0;
    $bytes = 0;
    foreach ($files as $f) {
        if (is_file($f)) {
            $count++;
            $bytes += (@filesize($f) ?: 0);
        }
    }
    return ['count' => $count, 'bytes' => $bytes];
}

function rsnext_purge_triggers(string $tmpRoot): array {
    $files = array_merge(
        glob($tmpRoot . '\\trigger_*.*') ?: [],
        glob($tmpRoot . '\\cdburn_*.*') ?: []
    );
    $count = 0;
    $bytes = 0;
    $errors = 0;
    foreach ($files as $f) {
        if (is_file($f)) {
            $sz = @filesize($f) ?: 0;
            if (@unlink($f)) {
                $count++;
                $bytes += $sz;
            } else {
                $errors++;
            }
        }
    }
    return ['count' => $count, 'bytes' => $bytes, 'errors' => $errors];
}

function rsnext_collect_all_stats(array $categories, string $tmpRoot): array {
    $out = [];
    $totalCount = 0;
    $totalBytes = 0;
    foreach ($categories as $key => $meta) {
        if (($meta['mode'] ?? '') === 'triggers') {
            $st = rsnext_trigger_stats($tmpRoot);
        } elseif (!empty($meta['dirs'])) {
            $st = ['count' => 0, 'bytes' => 0];
            foreach ($meta['dirs'] as $d) {
                $sub = rsnext_dir_stats($d);
                $st['count'] += $sub['count'];
                $st['bytes'] += $sub['bytes'];
            }
        } else {
            $st = rsnext_dir_stats((string)$meta['dir']);
        }
        $out[$key] = $st;
        $totalCount += $st['count'];
        $totalBytes += $st['bytes'];
    }
    return ['items' => $out, 'total_count' => $totalCount, 'total_bytes' => $totalBytes];
}

function rsnext_fmt_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));

if ($action === 'clear') {
    header('Content-Type: application/json; charset=UTF-8');
    $target = trim((string)($_POST['target'] ?? $_GET['target'] ?? 'all'));
    $deleted = 0;
    $freed = 0;
    $errors = 0;

    foreach ($categories as $key => $meta) {
        if ($target !== 'all' && $target !== $key) continue;
        if (($meta['mode'] ?? '') === 'triggers') {
            $r = rsnext_purge_triggers($tmpRoot);
        } elseif (!empty($meta['dirs'])) {
            $r = ['count' => 0, 'bytes' => 0, 'errors' => 0];
            foreach ($meta['dirs'] as $d) {
                $sub = rsnext_purge_dir_contents($d);
                $r['count']  += $sub['count'];
                $r['bytes']  += $sub['bytes'];
                $r['errors'] += $sub['errors'];
            }
        } else {
            $r = rsnext_purge_dir_contents((string)$meta['dir']);
        }
        $deleted += $r['count'];
        $freed   += $r['bytes'];
        $errors  += $r['errors'];
    }

    $after = rsnext_collect_all_stats($categories, $tmpRoot);
    echo json_encode([
        'status'        => 'success',
        'deleted_count' => $deleted,
        'freed_bytes'   => $freed,
        'freed_human'   => rsnext_fmt_bytes($freed),
        'errors'        => $errors,
        'stats'         => $after,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'clear_client_cache') {
    header('Content-Type: application/json; charset=UTF-8');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if ($clientIp === '::1') $clientIp = '127.0.0.1';
    $ok = rsnext_write_client_trigger('trigger', $clientIp, 'CLEAR_CACHE|all', $tmpRoot);
    echo json_encode([
        'status'  => $ok ? 'success' : 'error',
        'message' => $ok
            ? 'このPC (IP: ' . $clientIp . ') のローカルRSViewerキャッシュ (C:\\RSBASE\\dicom_cache) 消去指示を送信しました'
            : 'トリガー送信に失敗しました',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stats = rsnext_collect_all_stats($categories, $tmpRoot);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🧹 キャッシュ管理・強制クリア - <?= rsnext_h($cfg['system_name'] ?? 'RS-Next') ?></title>
    <link rel="stylesheet" href="assets/rsnext_ui.css">
    <style>
        .cache-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 12px; margin-top: 14px; }
        .cache-card { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; display: flex; flex-direction: column; justify-content: space-between; gap: 10px; }
        .cache-stat { font-family: Consolas, monospace; font-size: 1.05rem; font-weight: 800; color: #0f172a; }
    </style>
</head>
<body>
<header class="rs-topbar">
    <div style="display:flex;align-items:center;gap:14px;">
        <a href="index.php" class="rs-brand">
            <span class="rs-brand-logo">🏥 RS-Next</span>
            <span class="rs-brand-sub">キャッシュ管理・不整合クリア</span>
        </a>
    </div>
    <nav class="rs-nav">
        <a href="index.php" class="rs-nav-link">🖥️ 検査一覧へ戻る</a>
        <a href="client_logs.php" class="rs-nav-link">🖥️ 常駐端末ログ</a>
        <a href="hardware_monitor.php" class="rs-nav-link">📊 ハード監視</a>
    </nav>
</header>

<main class="rs-main" style="max-width:1120px;margin:0 auto;padding:16px;">
    <div class="rs-card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;padding:16px 20px;">
        <div>
            <h2 style="font-size:1.15rem;margin-bottom:4px;">🧹 サーバー＆クライアント一時キャッシュ管理</h2>
            <p style="font-size:0.83rem;color:var(--text-sub);">
                サムネイル表示不整合・変換やり直し・未消費トリガー整理を行うための専用ツールです。<br>
                <strong style="color:#0f766e;">※検査原本DICOMデータ（<code>storage_data/vol01/studies</code>）およびPostgreSQLデータベースは一切削除されません。</strong>
            </p>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <div style="text-align:right;margin-right:6px;">
                <div style="font-size:0.74rem;color:var(--text-muted);font-weight:700;">現在のサーバー派生キャッシュ合計</div>
                <div style="font-size:1.25rem;font-weight:800;color:#1d4ed8;" id="totalSummary">
                    <?= number_format($stats['total_count']) ?> ファイル（<?= rsnext_h(rsnext_fmt_bytes($stats['total_bytes'])) ?>）
                </div>
            </div>
            <button type="button" class="btn btn-danger" onclick="clearServerCache('all', 'サーバー全派生キャッシュ')">
                🧹 サーバー全キャッシュを一括クリア
            </button>
            <button type="button" class="btn btn-teal" onclick="clearClientDicomCache()">
                💻 このPCのRSViewerキャッシュをクリア
            </button>
        </div>
    </div>

    <div class="cache-grid">
        <?php foreach ($categories as $key => $meta):
            $st = $stats['items'][$key] ?? ['count' => 0, 'bytes' => 0];
        ?>
        <div class="cache-card">
            <div>
                <div style="font-weight:800;font-size:0.93rem;margin-bottom:4px;"><?= rsnext_h($meta['label']) ?></div>
                <div style="font-size:0.78rem;color:var(--text-muted);line-height:1.45;"><?= rsnext_h($meta['desc']) ?></div>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px dashed var(--border-light);padding-top:10px;">
                <span class="cache-stat" id="stat_<?= rsnext_h($key) ?>">
                    <?= number_format($st['count']) ?> 件 / <?= rsnext_h(rsnext_fmt_bytes($st['bytes'])) ?>
                </span>
                <button type="button" class="btn btn-sm" onclick="clearServerCache('<?= rsnext_h($key) ?>', '<?= rsnext_h(addslashes($meta['label'])) ?>')">
                    個別クリア
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</main>

<div class="rs-toast" id="rsToast"></div>

<script>
function showToast(msg) {
    const t = document.getElementById('rsToast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3800);
}

function fmtBytes(b) {
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
    if (b >= 1048576) return (b / 1048576).toFixed(2) + ' MB';
    if (b >= 1024) return (b / 1024).toFixed(1) + ' KB';
    return b + ' B';
}

async function clearServerCache(target, label) {
    if (target === 'all' && !confirm('サーバー上の全派生キャッシュ（サムネイル・一時変換画像・未消費トリガー等）を一括削除しますか？\n※検査原本DICOMデータは削除されません。')) {
        return;
    }
    const fd = new URLSearchParams({ action: 'clear', target });
    const res = await fetch('clear_cache.php', { method: 'POST', body: fd });
    const out = await res.json();
    if (out.status === 'success') {
        showToast(`✅ ${label} をクリアしました（削除: ${out.deleted_count}件 / ${out.freed_human} 解放）`);
        if (out.stats) {
            document.getElementById('totalSummary').textContent =
                `${out.stats.total_count.toLocaleString()} ファイル（${fmtBytes(out.stats.total_bytes)}）`;
            Object.entries(out.stats.items || {}).forEach(([k, v]) => {
                const el = document.getElementById('stat_' + k);
                if (el) el.textContent = `${v.count.toLocaleString()} 件 / ${fmtBytes(v.bytes)}`;
            });
        }
    } else {
        alert(out.message || 'キャッシュクリア中にエラーが発生しました');
    }
}

async function clearClientDicomCache() {
    const fd = new URLSearchParams({ action: 'clear_client_cache' });
    const res = await fetch('clear_cache.php', { method: 'POST', body: fd });
    const out = await res.json();
    if (out.status === 'success') {
        showToast('💻 ' + out.message);
    } else {
        alert(out.message || '送信エラー');
    }
}
</script>
</body>
</html>
