<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next 高速ダイレクトDICOM・16bit断面(CT/MRI)・MPR・動画・PDFストリーミングAPI (dcm_api.php)
// - 着信時先行生成キャッシュ (cache/thumbs/{study_id}/) 即時配信 (<2ms)
// - 8bit Baseline JPEG安全判定抽出 + JPEG Lossless(16bit CT/MRI)/J2K/RLE 完全対応
// - マルチシリーズ解析 (inspect_folder) / 16bit HUバイナリ配信 (slice_16bit) / MPR 3断面再構成 (mpr_slice)
// - 動画 Byte-Range (206 Partial Content) ストリーミング & AVI/MPG/マルチフレームDICOM 自動MP4変換
// - 胃がん検診モード 一発フォルダ保存 (save_folder) & サブフォルダ付ZIP (zip) & 2×4トレイスナップショット対応
// ==============================================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/lib/bootstrap.php';

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && ($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        while (ob_get_level() > 0) @ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(500);
        }
        echo json_encode(['status' => 'error', 'message' => 'API内部エラー: ' . $err['message']], JSON_UNESCAPED_UNICODE);
    }
});

ob_start();

$cfg = rsnext_config();
$cache_root = rsnext_cache_root();
$adhoc_cache_dir = $cache_root . '\\adhoc_dcm\\';
if (!is_dir($adhoc_cache_dir)) {
    @mkdir($adhoc_cache_dir, 0777, true);
}

$study_id = (int)($_GET['study_id'] ?? $_POST['study_id'] ?? 0);
$raw_folder = (string)($_GET['folder'] ?? $_POST['folder'] ?? '');
$folder = rtrim(trim(str_replace('/', '\\', $raw_folder)), '\\');

if ($study_id > 0 && $folder === '') {
    try {
        $pdo = rsnext_pdo();
        $st = $pdo->prepare("SELECT volume_id, rel_path FROM studies WHERE study_id = ?");
        $st->execute([$study_id]);
        $row = $st->fetch();
        if ($row) {
            $folder = rsnext_resolve_study_dir((string)$row['volume_id'], (string)$row['rel_path']);
        }
    } catch (Throwable $e) {
    }
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

// 0. 2×4印刷トレイ用キャプチャ画像（動画フレーム・CT任意WL/MPR断面）の保存・配信
if ($action === 'save_tray_snapshot') {
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json; charset=UTF-8');
    $snap_dir = $cache_root . '\\tray_snaps\\';
    if (!is_dir($snap_dir)) @mkdir($snap_dir, 0777, true);

    // 48時間経過した古いスナップショットを掃除
    foreach (glob($snap_dir . 'snap_*.jpg') ?: [] as $old) {
        if ((time() - (@filemtime($old) ?: 0)) > 172800) @unlink($old);
    }

    $snap_name = 'snap_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $dst = $snap_dir . $snap_name;

    if (!empty($_FILES['snap_blob']['tmp_name']) && is_uploaded_file($_FILES['snap_blob']['tmp_name'])) {
        $raw = @file_get_contents($_FILES['snap_blob']['tmp_name']);
        if ($raw !== false && function_exists('imagecreatefromstring')) {
            $im = @imagecreatefromstring($raw);
            if ($im !== false) {
                imagejpeg($im, $dst, 92);
                imagedestroy($im);
                echo json_encode([
                    'status'   => 'success',
                    'snap_id'  => $snap_name,
                    'snap_url' => 'dcm_api.php?action=tray_snap&snap=' . rawurlencode($snap_name),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'スナップショット画像の保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'tray_snap') {
    while (ob_get_level() > 0) @ob_end_clean();
    $snap = basename((string)($_GET['snap'] ?? ''));
    $fp = $cache_root . '\\tray_snaps\\' . $snap;
    if ($snap === '' || !str_starts_with($snap, 'snap_') || !is_file($fp)) {
        http_response_code(404);
        exit('Snapshot not found');
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($fp));
    header('Cache-Control: public, max-age=86400');
    readfile($fp);
    exit;
}

// ① ファイル一覧 & マルチシリーズ・断面構造取得 (action=list)
if ($action === 'list') {
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json; charset=UTF-8');

    if ($folder === '' || !is_dir($folder)) {
        echo json_encode(['status' => 'error', 'message' => '検査フォルダが見つかりません: ' . $folder], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // フォルダ内のファイル数と最終更新時刻からシリーズ解析キャッシュキーを生成
    $file_sig_count = 0;
    $max_mtime = @filemtime($folder) ?: 0;
    $has_any_dicom = false;
    $dh = @opendir($folder);
    $video_exts = ['mp4', 'webm', 'mov', 'm4v', 'avi', 'mpg', 'mpeg', 'wmv', 'mkv', 'mts', 'ogv'];
    $quick_files = [];

    if ($dh) {
        while (($f = readdir($dh)) !== false) {
            $low = strtolower($f);
            if ($f === '.' || $f === '..' || $low === 'study_meta.json' || $low === 'thumbs.db' || $low === 'desktop.ini' || $low === 'dicomdir') {
                continue;
            }
            $fp = $folder . '\\' . $f;
            if (!is_file($fp)) continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, ['dcm', '', 'jpg', 'jpeg', 'png', 'webp', 'bmp', 'pdf'] + $video_exts, true) || in_array($ext, $video_exts, true)) {
                $mt = @filemtime($fp) ?: 0;
                if ($mt > $max_mtime) $max_mtime = $mt;
                $file_sig_count++;
                if ($ext === 'dcm' || $ext === '') {
                    $has_any_dicom = true;
                }
                $mtype = in_array($ext, $video_exts, true) ? 'video' : ($ext === 'pdf' ? 'pdf' : (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'bmp'], true) ? 'image' : 'dicom'));
                $quick_files[] = [
                    'filename'   => $f,
                    'ext'        => $ext,
                    'size'       => @filesize($fp) ?: 0,
                    'mtime'      => $mt,
                    'media_type' => $mtype,
                    'num_frames' => 1,
                ];
            }
        }
        closedir($dh);
    }

    $series_cache_dir = $cache_root . '\\series_meta\\';
    if (!is_dir($series_cache_dir)) @mkdir($series_cache_dir, 0777, true);
    $meta_cache_file = $series_cache_dir . md5($folder . '|' . $file_sig_count . '|' . $max_mtime . '_v2') . '.json';

    if (!is_file($meta_cache_file) && ($has_any_dicom || count($quick_files) > 0)) {
        $py = rsnext_find_python();
        $helper = __DIR__ . '/dcm_volume_helper.py';
        if (is_file($helper)) {
            @set_time_limit(120);
            $cmd = escapeshellarg($py) . ' ' . escapeshellarg($helper) . ' inspect_folder ' . escapeshellarg($folder) . ' ' . escapeshellarg($meta_cache_file) . ' 2>&1';
            exec($cmd, $out, $ret);
        }
    }

    if (is_file($meta_cache_file)) {
        $parsed = @json_decode((string)@file_get_contents($meta_cache_file), true);
        if (is_array($parsed) && ($parsed['status'] ?? '') === 'success') {
            $parsed['study_id'] = $study_id ?: null;
            $parsed['folder'] = $folder;
            echo json_encode($parsed, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    usort($quick_files, fn($a, $b) => strnatcasecmp($a['filename'], $b['filename']));
    echo json_encode([
        'status'             => 'success',
        'study_id'           => $study_id ?: null,
        'folder'             => $folder,
        'total'              => count($quick_files),
        'is_cross_sectional' => false,
        'has_video'          => count(array_filter($quick_files, fn($x) => $x['media_type'] === 'video')) > 0,
        'series_count'       => 0,
        'series'             => [],
        'files'              => $quick_files,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ①-B 16bit CT/MRI HU スライスバイナリ配信 (action=slice_16bit)
if ($action === 'slice_16bit') {
    while (ob_get_level() > 0) @ob_end_clean();
    $file = basename((string)($_GET['file'] ?? ''));
    $frame = max(0, (int)($_GET['frame'] ?? 0));
    $full_path = $folder . '\\' . $file;
    if ($folder === '' || $file === '' || !is_file($full_path)) {
        http_response_code(404);
        exit('Slice not found');
    }

    $hu_dir = $cache_root . '\\hu16\\';
    if (!is_dir($hu_dir)) @mkdir($hu_dir, 0777, true);
    $mtime = @filemtime($full_path) ?: 0;
    $bin_cache = $hu_dir . md5($full_path . '|' . $mtime . '|f' . $frame) . '.bin';

    if (!is_file($bin_cache) || filesize($bin_cache) < 28) {
        $py = rsnext_find_python();
        $helper = __DIR__ . '/dcm_volume_helper.py';
        $cmd = escapeshellarg($py) . ' ' . escapeshellarg($helper) . ' slice_16bit ' . escapeshellarg($full_path) . ' ' . escapeshellarg($bin_cache) . ' ' . $frame . ' 2>&1';
        exec($cmd, $out, $ret);
        if ($ret !== 0 || !is_file($bin_cache)) {
            http_response_code(500);
            exit('16bit decode failed');
        }
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($bin_cache));
    header('Cache-Control: public, max-age=86400');
    readfile($bin_cache);
    exit;
}

// ①-C MPR 3断面再構成 (Coronal / Sagittal / Axial) 16bit HU配信 (action=mpr_slice)
if ($action === 'mpr_slice') {
    while (ob_get_level() > 0) @ob_end_clean();
    @set_time_limit(120);
    $series_uid = trim((string)($_GET['series_uid'] ?? ''));
    $plane = strtolower(trim((string)($_GET['plane'] ?? 'coronal')));
    if (!in_array($plane, ['coronal', 'sagittal', 'axial'], true)) {
        $plane = 'coronal';
    }
    $slice_idx = max(0, (int)($_GET['index'] ?? 0));
    if ($folder === '' || !is_dir($folder)) {
        http_response_code(404);
        exit('Folder not found');
    }

    $mpr_dir = $cache_root . '\\mpr\\';
    if (!is_dir($mpr_dir)) @mkdir($mpr_dir, 0777, true);
    $slice_bin = $mpr_dir . 'sl_' . md5($folder . '|' . $series_uid . '|' . $plane . '|' . $slice_idx) . '.bin';

    if (!is_file($slice_bin) || filesize($slice_bin) < 28) {
        $py = rsnext_find_python();
        $helper = __DIR__ . '/dcm_volume_helper.py';
        $cmd = escapeshellarg($py) . ' ' . escapeshellarg($helper) . ' mpr_slice '
             . escapeshellarg($folder) . ' '
             . escapeshellarg($series_uid) . ' '
             . escapeshellarg($plane) . ' '
             . $slice_idx . ' '
             . escapeshellarg($mpr_dir) . ' '
             . escapeshellarg($slice_bin) . ' 2>&1';
        exec($cmd, $out, $ret);
        if ($ret !== 0 || !is_file($slice_bin)) {
            http_response_code(500);
            exit('MPR reconstruction failed: ' . implode(' ', $out));
        }
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($slice_bin));
    header('Cache-Control: public, max-age=3600');
    readfile($slice_bin);
    exit;
}

// ①-D 動画 & マルチフレームシネDICOM HTTP Byte-Range (206 Partial Content) ストリーミング
if ($action === 'video' || $action === 'cine_mp4') {
    while (ob_get_level() > 0) @ob_end_clean();
    @set_time_limit(300);
    $file = basename((string)($_GET['file'] ?? ''));
    $full_path = $folder . '\\' . $file;
    if ($folder === '' || $file === '' || !is_file($full_path)) {
        http_response_code(404);
        exit('Media file not found');
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $stream_path = $full_path;
    $mime = 'video/mp4';

    if ($action === 'video' && in_array($ext, ['mp4', 'm4v', 'webm', 'ogv'], true)) {
        if ($ext === 'webm') $mime = 'video/webm';
        elseif ($ext === 'ogv') $mime = 'video/ogg';
    } else {
        // AVI / MPG / MOV / WMV または マルチフレーム・カプセル化動画DICOM -> H.264 MP4 キャッシュ変換
        $vid_cache_dir = $cache_root . '\\video\\';
        if (!is_dir($vid_cache_dir)) @mkdir($vid_cache_dir, 0777, true);
        $mtime = @filemtime($full_path) ?: 0;
        $mp4_cache = $vid_cache_dir . md5($full_path . '|' . $mtime) . '.mp4';
        if (!is_file($mp4_cache) || filesize($mp4_cache) < 256) {
            $py = rsnext_find_python();
            $helper = __DIR__ . '/dcm_volume_helper.py';
            $cmd = escapeshellarg($py) . ' ' . escapeshellarg($helper) . ' ensure_mp4 ' . escapeshellarg($full_path) . ' ' . escapeshellarg($mp4_cache) . ' 2>&1';
            exec($cmd, $out, $ret);
            if ($ret !== 0 || !is_file($mp4_cache)) {
                // MOVの場合はそのまま直接配信を試みるフォールバック
                if ($ext === 'mov') {
                    $stream_path = $full_path;
                    $mime = 'video/quicktime';
                } else {
                    http_response_code(500);
                    exit('Video transcode failed');
                }
            } else {
                $stream_path = $mp4_cache;
            }
        } else {
            $stream_path = $mp4_cache;
        }
    }

    rsnext_stream_file_with_range($stream_path, $mime);
    exit;
}

// ② 胃がん検診 一発フォルダ保存設定 (action=jpeg_direct_config)
$jpeg_cfg_file = __DIR__ . '/tmp/jpeg_export_config.json';

function rsnext_get_jpeg_base_dir(string $cfg_file, array $cfg): string {
    if (is_file($cfg_file)) {
        $j = @json_decode((string)@file_get_contents($cfg_file), true);
        if (is_array($j) && !empty($j['base_dir'])) {
            return rtrim(str_replace('/', '\\', trim((string)$j['base_dir'])), '\\');
        }
    }
    return rtrim(str_replace('/', '\\', (string)($cfg['jpeg_direct_export_default_dir'] ?? 'C:\\Users\\Owner\\Desktop\\jpeg')), '\\');
}

function rsnext_validate_jpeg_dir(string $base_dir): array {
    $norm = rtrim(str_replace('/', '\\', trim($base_dir)), '\\');
    if ($norm === '' || strlen($norm) < 3) {
        return [false, '保存先親フォルダを指定してください。'];
    }
    if (!preg_match('/^([A-Za-z]:\\\\|\\\\\\\\[^\\\\]+\\\\[^\\\\]+)/', $norm)) {
        return [false, '保存先はドライブ名（例: C:\\Users\\Owner\\Desktop\\jpeg）またはUNC共有パスで指定してください。'];
    }
    if (strpos($norm, '..') !== false || preg_match('/[\*\?"<>\|]/', substr($norm, 2))) {
        return [false, '保存先パスに使用できない文字が含まれています。'];
    }
    $lower = strtolower($norm);
    foreach (['c:\\windows', 'c:\\program files', 'c:\\program files (x86)', 'c:\\apache24'] as $forb) {
        if ($lower === $forb || str_starts_with($lower, $forb . '\\')) {
            return [false, 'システム保護フォルダ配下へは保存できません。'];
        }
    }
    return [true, $norm];
}

if ($action === 'jpeg_direct_config') {
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json; charset=UTF-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['base_dir'])) {
        [$ok, $val] = rsnext_validate_jpeg_dir((string)$_POST['base_dir']);
        if (!$ok) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $val], JSON_UNESCAPED_UNICODE);
            exit;
        }
        rsnext_atomic_write_file($jpeg_cfg_file, json_encode(['base_dir' => $val, 'updated_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success', 'base_dir' => $val], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['status' => 'success', 'base_dir' => rsnext_get_jpeg_base_dir($jpeg_cfg_file, $cfg)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ③ 胃がん検診 一発フォルダ保存 (action=save_folder)
if ($action === 'save_folder') {
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json; charset=UTF-8');
    @set_time_limit(180);
    $t0 = microtime(true);

    if ($folder === '' || !is_dir($folder)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '検査フォルダにアクセスできません: ' . $folder], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw_base = trim((string)($_POST['base_dir'] ?? $_GET['base_dir'] ?? ''));
    if ($raw_base === '') {
        $raw_base = rsnext_get_jpeg_base_dir($jpeg_cfg_file, $cfg);
    }
    [$ok, $valid_base] = rsnext_validate_jpeg_dir($raw_base);
    if (!$ok) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $valid_base], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw_sub = trim((string)($_POST['subfolder'] ?? $_GET['subfolder'] ?? ''));
    $safe_sub = trim((string)preg_replace('/[\\\\\/:\*\?"<>|\x00-\x1F]+/u', '_', $raw_sub), " .");
    if ($safe_sub === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '保存するサブフォルダ名を入力してください。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $output_dir = $valid_base . '\\' . $safe_sub;
    $allow_overwrite = !empty($_POST['overwrite']) && (string)$_POST['overwrite'] === '1';

    if (is_dir($output_dir) && !$allow_overwrite) {
        $existing = glob($output_dir . '\\*.{jpg,JPG,jpeg,JPEG}', GLOB_BRACE) ?: [];
        if (count($existing) > 0) {
            echo json_encode([
                'status'         => 'exists',
                'existing_count' => count($existing),
                'output_dir'     => $output_dir,
                'subfolder'      => $safe_sub,
                'message'        => "既に同名フォルダ「{$safe_sub}」（画像 " . count($existing) . " 枚）が存在します。上書きしますか？",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if (!is_dir($output_dir) && !@mkdir($output_dir, 0777, true) && !is_dir($output_dir)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '出力先フォルダを作成できませんでした: ' . $output_dir], JSON_UNESCAPED_UNICODE);
        exit;
    }

    rsnext_atomic_write_file($jpeg_cfg_file, json_encode(['base_dir' => $valid_base, 'updated_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    if ($allow_overwrite) {
        foreach (glob($output_dir . '\\*.{jpg,JPG,jpeg,JPEG}', GLOB_BRACE) ?: [] as $old_f) {
            @unlink($old_f);
        }
    }

    $entries = @scandir($folder) ?: [];
    $src_files = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..' || strtolower($e) === 'study_meta.json') continue;
        $fp = $folder . '\\' . $e;
        if (!is_file($fp)) continue;
        $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
        if (in_array($ext, ['dcm', '', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            $src_files[] = $e;
        }
    }
    usort($src_files, 'strnatcasecmp');

    $saved_count = 0;
    foreach ($src_files as $idx => $fname) {
        $bytes = rsnext_get_viewable_jpeg($folder . '\\' . $fname, false, $study_id, $idx + 1, $cache_root, $adhoc_cache_dir);
        if ($bytes !== null) {
            $stem = pathinfo($fname, PATHINFO_FILENAME) ?: sprintf('%04d', $idx + 1);
            if (@file_put_contents($output_dir . '\\' . $stem . '.jpg', $bytes) !== false) {
                $saved_count++;
            }
        }
    }

    if ($saved_count === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'JPEG出力可能な画像が見つかりませんでした。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'status'      => 'success',
        'saved_count' => $saved_count,
        'output_dir'  => $output_dir,
        'base_dir'    => $valid_base,
        'subfolder'   => $safe_sub,
        'elapsed_ms'  => (int)round((microtime(true) - $t0) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ④ サブフォルダ付きJPEG一括ZIP出力 (action=zip)
if ($action === 'zip') {
    while (ob_get_level() > 0) @ob_end_clean();
    @set_time_limit(180);

    if ($folder === '' || !is_dir($folder)) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '検査フォルダが見つかりません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw_sub = trim((string)($_GET['subfolder'] ?? $_POST['subfolder'] ?? ''));
    $safe_sub = trim((string)preg_replace('/[\\\\\/:\*\?"<>|\x00-\x1F]+/u', '_', $raw_sub), " .");
    $zip_filename = $safe_sub !== '' ? ($safe_sub . '.zip') : (basename($folder) . '_JPEG.zip');
    $prefix = $safe_sub !== '' ? ($safe_sub . '/') : '';

    $tmp_zip = $adhoc_cache_dir . 'dl_' . uniqid('', true) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '一時ZIPの作成に失敗しました'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $entries = @scandir($folder) ?: [];
    $src_files = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..' || strtolower($e) === 'study_meta.json') continue;
        $fp = $folder . '\\' . $e;
        if (!is_file($fp)) continue;
        $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
        if (in_array($ext, ['dcm', '', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            $src_files[] = $e;
        }
    }
    usort($src_files, 'strnatcasecmp');

    $added = 0;
    foreach ($src_files as $idx => $fname) {
        $bytes = rsnext_get_viewable_jpeg($folder . '\\' . $fname, false, $study_id, $idx + 1, $cache_root, $adhoc_cache_dir);
        if ($bytes !== null) {
            $stem = pathinfo($fname, PATHINFO_FILENAME) ?: sprintf('%04d', $idx + 1);
            $zip->addFromString($prefix . $stem . '.jpg', $bytes);
            $added++;
        }
    }
    $zip->close();

    if ($added === 0) {
        @unlink($tmp_zip);
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => '変換可能な画像が見つかりませんでした'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ascii_name = preg_replace('/[^\x20-\x7E]/', '_', $zip_filename);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '_', (string)$ascii_name) . '"; filename*=UTF-8\'\'' . rawurlencode($zip_filename));
    header('Content-Length: ' . filesize($tmp_zip));
    header('X-Jpeg-Count: ' . $added);
    readfile($tmp_zip);
    @unlink($tmp_zip);
    exit;
}

// ⑤ PDFレポート直接配信 (action=pdf)
if ($action === 'pdf') {
    while (ob_get_level() > 0) @ob_end_clean();
    $file = basename((string)($_GET['file'] ?? '0001.pdf'));
    $full_path = $folder . '\\' . $file;
    if ($folder === '' || !is_file($full_path)) {
        http_response_code(404);
        exit('PDF file not found');
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($full_path));
    header('Content-Disposition: inline; filename="' . rawurlencode($file) . '"');
    readfile($full_path);
    exit;
}

// ⑥ 単一画像・サムネイル高速ストリーミング (既定アクション)
$file = basename((string)($_GET['file'] ?? ''));
$is_thumb = isset($_GET['thumb']) && $_GET['thumb'] === '1';
$custom_wl = isset($_GET['wl']) && $_GET['wl'] !== '' ? (string)(float)$_GET['wl'] : '';
$custom_ww = isset($_GET['ww']) && $_GET['ww'] !== '' ? (string)(float)$_GET['ww'] : '';
$frame_idx = isset($_GET['frame']) && $_GET['frame'] !== '' ? max(0, (int)$_GET['frame']) : 0;

if ($folder === '' || $file === '') {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code(400);
    echo 'Missing folder or file parameter';
    exit;
}

$full_path = $folder . '\\' . $file;
if (!is_file($full_path)) {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code(404);
    echo 'File not found';
    exit;
}

$seq_guess = 0;
if (preg_match('/^0*([1-9][0-9]*)\./', $file, $m)) {
    $seq_guess = (int)$m[1];
}

$jpeg_bytes = rsnext_get_viewable_jpeg(
    $full_path,
    $is_thumb,
    $study_id,
    $seq_guess,
    $cache_root,
    $adhoc_cache_dir,
    $custom_wl,
    $custom_ww,
    $frame_idx
);

while (ob_get_level() > 0) @ob_end_clean();
if ($jpeg_bytes !== null) {
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($jpeg_bytes));
    header('Cache-Control: public, max-age=86400');
    echo $jpeg_bytes;
    exit;
}

header('Content-Type: image/svg+xml');
echo '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200" viewBox="0 0 300 200"><rect fill="#0f172a" width="100%" height="100%"/><text fill="#94a3b8" x="50%" y="50%" text-anchor="middle" font-size="14">プレビュー非対応形式</text></svg>';
exit;


function rsnext_stream_file_with_range(string $path, string $mime): void {
    $size = @filesize($path);
    if ($size === false) {
        http_response_code(404);
        return;
    }
    $fp = @fopen($path, 'rb');
    if (!$fp) {
        http_response_code(500);
        return;
    }

    $start = 0;
    $end = $size - 1;
    $status = 200;

    $range_hdr = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range_hdr !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $range_hdr, $m)) {
        $r_start = $m[1];
        $r_end = $m[2];
        if ($r_start === '' && $r_end !== '') {
            $start = max(0, $size - (int)$r_end);
        } else {
            $start = (int)$r_start;
            if ($r_end !== '') {
                $end = min($size - 1, (int)$r_end);
            }
        }
        if ($start > $end || $start >= $size) {
            fclose($fp);
            header("Content-Range: bytes */{$size}");
            http_response_code(416);
            return;
        }
        $status = 206;
    }

    $length = $end - $start + 1;
    http_response_code($status);
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    if ($status === 206) {
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }
    header('Cache-Control: public, max-age=86400');

    if ($start > 0) {
        fseek($fp, $start);
    }
    $remaining = $length;
    $chunk_sz = 262144;
    while ($remaining > 0 && !feof($fp)) {
        $read_len = min($chunk_sz, $remaining);
        $buf = fread($fp, $read_len);
        if ($buf === false || $buf === '') break;
        echo $buf;
        $remaining -= strlen($buf);
        if (connection_aborted()) break;
    }
    fclose($fp);
}


function rsnext_is_browser_safe_jpeg(string $jpg): bool {
    $len = strlen($jpg);
    if ($len < 16 || substr($jpg, 0, 3) !== "\xFF\xD8\xFF") {
        return false;
    }
    $i = 2;
    while ($i < $len - 4) {
        if (ord($jpg[$i]) !== 0xFF) {
            $i++;
            continue;
        }
        while ($i < $len && ord($jpg[$i]) === 0xFF) {
            $i++;
        }
        if ($i >= $len) break;
        $marker = ord($jpg[$i]);
        $i++;
        if ($marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
            continue;
        }
        if ($marker === 0xDA) {
            break;
        }
        if ($i + 2 > $len) break;
        $seg_len = (ord($jpg[$i]) << 8) | ord($jpg[$i + 1]);
        if ($seg_len < 2 || $i + $seg_len > $len) break;
        // 8bit Baseline (0xC0) or Progressive (0xC2) ONLY. Reject 12/16bit Lossless (0xC3) & JPEG-LS (0xF7)!
        if ($marker === 0xC0 || $marker === 0xC2) {
            $precision = ($i + 2 < $len) ? ord($jpg[$i + 2]) : 0;
            return $precision === 8;
        }
        if (in_array($marker, [0xC1, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF, 0xF7], true)) {
            return false;
        }
        $i += $seg_len;
    }
    return false;
}


function rsnext_get_viewable_jpeg(
    string $src_path,
    bool $is_thumb,
    int $study_id,
    int $seq_no,
    string $cache_root,
    string $adhoc_cache_dir,
    string $custom_wl = '',
    string $custom_ww = '',
    int $frame_idx = 0
): ?string {
    $force_nocache = isset($_GET['nocache']) && $_GET['nocache'] === '1';
    $has_custom_params = ($custom_wl !== '' || $custom_ww !== '' || $frame_idx > 0);

    // 1. Ingest時に先行生成済みのキャッシュ (cache/thumbs/{study_id}/{seq:04d}_thumb.jpg or _view.jpg)
    if ($study_id > 0 && $seq_no > 0 && !$force_nocache && !$has_custom_params) {
        $pre_file = sprintf(
            '%s\\thumbs\\%d\\%04d_%s.jpg',
            $cache_root,
            $study_id,
            $seq_no,
            $is_thumb ? 'thumb' : 'view'
        );
        if (is_file($pre_file) && @filesize($pre_file) > 100) {
            $b = @file_get_contents($pre_file);
            if ($b !== false && rsnext_is_browser_safe_jpeg($b)) {
                return $b;
            }
        }
    }

    $mtime = @filemtime($src_path) ?: time();
    $param_key = ($custom_wl !== '' || $custom_ww !== '') ? "_wl{$custom_wl}_ww{$custom_ww}" : '';
    if ($frame_idx > 0) $param_key .= "_fr{$frame_idx}";
    $cache_file = $adhoc_cache_dir . md5($src_path . '_' . $mtime . ($is_thumb ? '_t' : '_f') . $param_key . '_v2') . '.jpg';
    if (!$force_nocache && is_file($cache_file) && @filesize($cache_file) > 100) {
        $b = @file_get_contents($cache_file);
        if ($b !== false && rsnext_is_browser_safe_jpeg($b)) {
            return $b;
        }
    }

    $ext = strtolower(pathinfo($src_path, PATHINFO_EXTENSION));

    // 2. 標準画像 (JPG / PNG / WebP / BMP)
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'bmp'], true)) {
        if (($ext === 'jpg' || $ext === 'jpeg') && !$is_thumb) {
            $b = @file_get_contents($src_path);
            return ($b !== false && strlen($b) > 50) ? $b : null;
        }
        $raw = @file_get_contents($src_path);
        if ($raw !== false && function_exists('imagecreatefromstring')) {
            $im = @imagecreatefromstring($raw);
            if ($im !== false) {
                $w = imagesx($im);
                $h = imagesy($im);
                if ($is_thumb && ($w > 220 || $h > 220)) {
                    $ratio = min(220 / max(1, $w), 220 / max(1, $h));
                    $tw = max(1, (int)round($w * $ratio));
                    $th = max(1, (int)round($h * $ratio));
                    $dst = imagecreatetruecolor($tw, $th);
                    imagecopyresampled($dst, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
                    imagedestroy($im);
                    $im = $dst;
                }
                ob_start();
                imagejpeg($im, null, $is_thumb ? 82 : 90);
                $jpg = ob_get_clean();
                imagedestroy($im);
                if ($jpg) {
                    rsnext_atomic_write_file($cache_file, $jpg);
                    return $jpg;
                }
            }
        }
    }

    // 3. DICOM 内蔵 8bit Baseline JPEG のダイレクト高速抽出 (~5-20ms)
    //    (※ 16bit Lossless JPEG SOF3 や カスタムWL/フレーム指定時はスキップし Python dcm_render.py へ渡す)
    if (!$has_custom_params && !in_array($ext, ['mp4', 'webm', 'mov', 'm4v', 'avi', 'mpg', 'mpeg', 'wmv', 'mkv', 'mts', 'ogv'], true)) {
        $fh = @fopen($src_path, 'rb');
        if ($fh) {
            $content = stream_get_contents($fh);
            fclose($fh);
            if ($content !== false) {
                $soi = strpos($content, "\xFF\xD8\xFF");
                if ($soi !== false) {
                    $eoi = strpos($content, "\xFF\xD9", $soi + 4);
                    if ($eoi !== false && $eoi > $soi) {
                        $raw_jpg = substr($content, $soi, $eoi - $soi + 2);
                        if (rsnext_is_browser_safe_jpeg($raw_jpg)) {
                            if ($is_thumb && function_exists('imagecreatefromstring')) {
                                $im = @imagecreatefromstring($raw_jpg);
                                if ($im !== false) {
                                    $w = imagesx($im);
                                    $h = imagesy($im);
                                    $tw = 200;
                                    $th = max(1, (int)round($h * ($tw / max(1, $w))));
                                    $th_im = imagecreatetruecolor($tw, $th);
                                    imagecopyresampled($th_im, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
                                    ob_start();
                                    imagejpeg($th_im, null, 80);
                                    $t_jpg = ob_get_clean();
                                    imagedestroy($th_im);
                                    imagedestroy($im);
                                    if ($t_jpg) {
                                        rsnext_atomic_write_file($cache_file, $t_jpg);
                                        return $t_jpg;
                                    }
                                }
                            }
                            rsnext_atomic_write_file($cache_file, $raw_jpg);
                            return $raw_jpg;
                        }
                    }
                }
            }
        }
    }

    // 4. 16bit Lossless/非圧縮DICOM・カスタムWL・マルチフレーム・動画サムネイル Python dcm_render.py
    $py_script = __DIR__ . '/dcm_render.py';
    if (is_file($py_script)) {
        $py = rsnext_find_python();
        $tmp_out = $cache_file . '.py_' . uniqid('', true) . '.jpg';
        $mode_arg = $is_thumb ? 'thumb' : 'full';
        $wl_arg = $custom_wl !== '' ? $custom_wl : 'auto';
        $ww_arg = $custom_ww !== '' ? $custom_ww : 'auto';
        $cmd = escapeshellarg($py) . ' ' . escapeshellarg($py_script)
             . ' ' . escapeshellarg($src_path)
             . ' ' . escapeshellarg($tmp_out)
             . ' ' . escapeshellarg($mode_arg)
             . ' ' . escapeshellarg($wl_arg)
             . ' ' . escapeshellarg($ww_arg)
             . ' ' . $frame_idx . ' 2>&1';
        exec($cmd, $out, $ret);
        if ($ret === 0 && is_file($tmp_out) && filesize($tmp_out) > 100) {
            @rename($tmp_out, $cache_file);
            $res = @file_get_contents($cache_file);
            return $res !== false ? $res : null;
        }
        if (is_file($tmp_out)) @unlink($tmp_out);
    }

    return null;
}
