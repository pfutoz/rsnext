<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * 共通常駐モジュール (https://192.168.1.16/rsbase の watcher.vbs) との連携ハブパスを取得
 */
function rsnext_get_shared_watcher_hub_tmp(): string {
    $cfg = rsnext_config();
    $hub = $cfg['shared_watcher_hub'] ?? [];
    if (isset($hub['enabled']) && !$hub['enabled']) {
        return '';
    }
    $dir = trim((string)($hub['smb_tmp_dir'] ?? '\\\\192.168.1.16\\htdocs\\rsbase\\tmp'));
    return rtrim(str_replace('/', '\\', $dir), '\\');
}

function rsnext_ensure_hub_smb_accessible(string $hubTmpDir): bool {
    if ($hubTmpDir === '') {
        return false;
    }
    if (@is_dir($hubTmpDir)) {
        return true;
    }
    if (preg_match('/^(\\\\\\\\[^\\\\]+\\\\[^\\\\]+)/', $hubTmpDir, $m)) {
        $uncRoot = $m[1];
        @exec('cmd.exe /c "net use \"' . $uncRoot . '\" \"\" /user:rsnext_bridge >nul 2>&1"');
    }
    return @is_dir($hubTmpDir);
}

/**
 * クライアントPC上の常駐 watcher.vbs へトリガー (cdburn_<IP>.txt / trigger_<IP>.txt) を配信
 * - RS-Next 自身の tmp/ と、既存院内PCが監視中の 192.168.1.16/rsbase/tmp/ の両方へ書き込むことで
 *   クライアントPCへ2重常駐させることなく 1つの watcher.vbs を完全共用する
 */
function rsnext_write_client_trigger(string $prefix, string $clientIp, string $line, string $localTmpDir): bool {
    $safeIp = preg_replace('/[^0-9a-fA-F\.\:\_]/', '_', $clientIp);
    $fname  = $prefix . '_' . $safeIp . '.txt';
    $metaFn = $prefix . '_' . $safeIp . '.hubmeta.json';

    $localDir = rtrim(str_replace('/', '\\', $localTmpDir), '\\');
    if (!is_dir($localDir)) {
        @mkdir($localDir, 0777, true);
    }

    $localFp = $localDir . '\\' . $fname;
    $localOk = (@file_put_contents($localFp, $line) !== false);

    $hubDir = rsnext_get_shared_watcher_hub_tmp();
    $hubOk  = false;
    $hubFp  = '';
    if ($hubDir !== '' && strcasecmp($hubDir, $localDir) !== 0 && rsnext_ensure_hub_smb_accessible($hubDir)) {
        $hubFp = $hubDir . '\\' . $fname;
        $hubOk = (@file_put_contents($hubFp, $line) !== false);
    }

    @file_put_contents(
        $localDir . '\\' . $metaFn,
        json_encode(['local_ok' => $localOk, 'hub_ok' => $hubOk, 'hub_fp' => $hubFp, 'ts' => time()], JSON_UNESCAPED_UNICODE)
    );

    return $localOk || $hubOk;
}

/**
 * トリガーファイルが常駐 watcher.vbs に消費されたか確認し、片方で消費済みなら残りも掃除して二重起動を防ぐ
 */
function rsnext_check_client_trigger_consumed(string $prefix, string $clientIp, string $localTmpDir, bool $cleanup = false): bool {
    $safeIp = preg_replace('/[^0-9a-fA-F\.\:\_]/', '_', $clientIp);
    $fname  = $prefix . '_' . $safeIp . '.txt';
    $metaFn = $prefix . '_' . $safeIp . '.hubmeta.json';

    $localDir = rtrim(str_replace('/', '\\', $localTmpDir), '\\');
    $localFp  = $localDir . '\\' . $fname;
    $metaFp   = $localDir . '\\' . $metaFn;

    $meta = is_file($metaFp) ? (@json_decode((string)@file_get_contents($metaFp), true) ?: []) : [];
    $hubOk = !empty($meta['hub_ok']);
    $hubFp = (string)($meta['hub_fp'] ?? '');

    if ($cleanup) {
        if (is_file($localFp)) @unlink($localFp);
        if ($hubFp !== '' && @is_file($hubFp)) @unlink($hubFp);
        if (is_file($metaFp)) @unlink($metaFp);
        return false;
    }

    $localExists = is_file($localFp);
    $hubExists   = ($hubOk && $hubFp !== '') ? @is_file($hubFp) : true;

    // どちらか一方で watcher.vbs にピックアップされたら消費完了とみなし、もう片方の残骸を即削除
    $consumedByLocal = (!empty($meta['local_ok']) && !$localExists);
    $consumedByHub   = ($hubOk && !$hubExists);

    if ($consumedByLocal || $consumedByHub || (!$localExists && !$hubOk)) {
        if ($localExists) @unlink($localFp);
        if ($hubOk && $hubFp !== '' && @is_file($hubFp)) @unlink($hubFp);
        if (is_file($metaFp)) @unlink($metaFp);
        return true;
    }

    return false;
}

/**
 * 検査が断層画像（CT / MRI / PET / NM 等）かどうかを判定
 */
function rsnext_is_tomographic_study(string $modality, string $kensamei = '', string $fileType = 'DICOM'): bool {
    $ft = strtoupper(trim($fileType));
    if (in_array($ft, ['ECG', 'PDF', 'VIDEO'], true)) {
        return false;
    }
    $mod = strtoupper(trim($modality));
    if (in_array($mod, ['CT', 'MR', 'MRI', 'PT', 'PET', 'NM'], true)) {
        return true;
    }
    if ($kensamei !== '' && preg_match('/(?:^|[^A-Za-z])(CT|MRI|MRA|PET)(?:[^A-Za-z]|$)|MR検査|断層/iu', $kensamei)) {
        return true;
    }
    return false;
}

/**
 * 現在のリクエストに対する RS-Next ベースURL (例: http://192.168.1.163/rsnext) を構築
 */
function rsnext_build_http_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = trim((string)($_SERVER['SERVER_ADDR'] ?? '192.168.1.163'));
    }
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/rsnext/index.php'))), '/');
    return "{$scheme}://{$host}{$scriptDir}";
}

/**
 * ゼロSMB・純粋HTTPストリーミング方式のローカル RSViewer 起動トリガー文字列を構築
 */
function rsnext_build_http_dicom_trigger_line(int $studyId, string $folder = ''): string {
    $baseUrl = rsnext_build_http_base_url();
    if ($studyId > 0) {
        $bundleUrl = $baseUrl . '/dcm_api.php?action=dicom_bundle&study_id=' . $studyId;
        $cacheKey  = 'rsnext_study_' . $studyId;
    } else {
        $bundleUrl = $baseUrl . '/dcm_api.php?action=dicom_bundle&folder=' . rawurlencode($folder);
        $cacheKey  = 'rsnext_fld_' . substr(md5(strtolower(str_replace('/', '\\', trim($folder)))), 0, 14);
    }
    return 'HTTP_DICOM|' . $bundleUrl . '|' . $cacheKey;
}


