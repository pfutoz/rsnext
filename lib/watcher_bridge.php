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

