<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next 12誘導心電図 JSON配信API (ecg_api.php)
// - 着信時に先行生成済みの cache/ecg_json/{study_id}/0001.json を <2ms で即時返却
// - 未キャッシュファイル指定時は ecg_render.py でリアルタイム復号＆キャッシュ保存
// ==============================================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

$study_id = (int)($_GET['study_id'] ?? $_POST['study_id'] ?? 0);
$seq_no   = max(1, (int)($_GET['seq'] ?? $_POST['seq'] ?? 1));
$raw_file = (string)($_GET['file'] ?? $_POST['file'] ?? '');
$file     = str_replace('/', '\\', trim($raw_file));
$nocache  = isset($_GET['nocache']) && $_GET['nocache'] === '1';

$cache_root = rsnext_cache_root();

// 1. study_id による先行生成キャッシュ直撃チェック
if ($study_id > 0) {
    $pre_json = sprintf('%s\\ecg_json\\%d\\%04d.json', $cache_root, $study_id, $seq_no);
    if (!$nocache && is_file($pre_json) && @filesize($pre_json) > 100) {
        $raw = @file_get_contents($pre_json);
        if ($raw !== false) {
            echo $raw;
            exit;
        }
    }

    if ($file === '') {
        try {
            $pdo = rsnext_pdo();
            $st = $pdo->prepare("SELECT volume_id, rel_path FROM studies WHERE study_id = ?");
            $st->execute([$study_id]);
            $row = $st->fetch();
            if ($row) {
                $dir = rsnext_resolve_study_dir((string)$row['volume_id'], (string)$row['rel_path']);
                $file = $dir;
                foreach (['ECG', 'MWF', 'NKC', 'ecg', 'mwf', 'nkc', '001'] as $extTry) {
                    $cand = sprintf('%s\\%04d.%s', $dir, $seq_no, $extTry);
                    if (is_file($cand)) {
                        $file = $cand;
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }
}

if ($file === '') {
    echo json_encode(['status' => 'error', 'message' => '心電図ファイルまたは study_id が指定されていません'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (is_dir($file)) {
    $cands = glob(rtrim($file, '\\/') . '\\*.{ECG,ecg,001,MWF,mwf,NKC,nkc}', GLOB_BRACE) ?: [];
    if (!empty($cands)) {
        $file = $cands[0];
    }
}

if (!is_file($file)) {
    echo json_encode(['status' => 'error', 'message' => '心電図ファイルが見つかりません: ' . $file], JSON_UNESCAPED_UNICODE);
    exit;
}

$adhoc_dir = $cache_root . '\\adhoc_ecg\\';
if (!is_dir($adhoc_dir)) @mkdir($adhoc_dir, 0777, true);
$mtime = @filemtime($file) ?: time();
$cache_file = ($study_id > 0)
    ? sprintf('%s\\ecg_json\\%d\\%04d.json', $cache_root, $study_id, $seq_no)
    : ($adhoc_dir . md5($file . '_' . $mtime) . '.json');

if (!$nocache && is_file($cache_file) && @filesize($cache_file) > 100) {
    $raw = @file_get_contents($cache_file);
    $chk = $raw ? @json_decode($raw, true) : null;
    if (is_array($chk) && ($chk['status'] ?? '') === 'success' && isset($chk['data']['leads'])) {
        echo $raw;
        exit;
    }
}

$py_script = __DIR__ . '/ecg_render.py';
$py = rsnext_find_python();
$cmd = escapeshellarg($py) . ' ' . escapeshellarg($py_script) . ' ' . escapeshellarg($file) . ' --json 2>&1';
exec($cmd, $lines, $ret);

if ($ret !== 0 || empty($lines)) {
    echo json_encode(['status' => 'error', 'message' => '心電図バイナリの解析に失敗しました', 'debug' => $lines], JSON_UNESCAPED_UNICODE);
    exit;
}

$parsed = @json_decode(implode("\n", $lines), true);
if (!is_array($parsed) || !isset($parsed['leads'])) {
    echo json_encode(['status' => 'error', 'message' => '心電図JSONフォーマットが不正です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$resp = json_encode(['status' => 'success', 'file' => $file, 'data' => $parsed], JSON_UNESCAPED_UNICODE);
rsnext_atomic_write_file($cache_file, $resp);
echo $resp;

