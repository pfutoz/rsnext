<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next ハードウェア/SNMP監視 ＆ バックアップ管理・モニター統合API (ops_api.php)
// ==============================================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

$rawBody = file_get_contents('php://input');
$jsonBody = ($rawBody && empty($_POST)) ? (@json_decode($rawBody, true) ?: []) : [];
$req = array_merge($_GET, $_POST, $jsonBody);
$action = (string)($req['action'] ?? '');

$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0777, true);
}

try {
    $pdo = rsnext_pdo();
    $cfgFile = __DIR__ . '/config/server_config.json';

    // ① ハードウェア・SNMP・LAN死活ステータス取得 (hw_status)
    if ($action === 'hw_status') {
        if (!empty($req['refresh'])) {
            @file_put_contents($tmpDir . '/hw_force_refresh.flag', date('c'));
            $py = rsnext_find_python();
            $cmd = escapeshellarg($py) . ' -m ingest_service.hw_monitor --refresh-storage-now 2>&1';
            $cwd = getcwd();
            chdir(__DIR__);
            @exec($cmd);
            if ($cwd) chdir($cwd);
        }
        $telFile = $tmpDir . '/hw_telemetry.json';
        $hisFile = $tmpDir . '/hw_history.json';
        $telemetry = is_file($telFile) ? (@json_decode((string)@file_get_contents($telFile), true) ?: null) : null;
        $history = is_file($hisFile) ? (@json_decode((string)@file_get_contents($hisFile), true) ?: ['fast_10s' => [], 'hourly_24h' => []]) : ['fast_10s' => [], 'hourly_24h' => []];

        echo json_encode([
            'status'    => 'success',
            'telemetry' => $telemetry,
            'history'   => $history,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ② 任意ホストまたは自サーバへのSNMP v2c 即時テスト照会 (snmp_test)
    if ($action === 'snmp_test') {
        $host = preg_replace('/[^A-Za-z0-9.\-_]/', '', trim((string)($req['host'] ?? '127.0.0.1'))) ?: '127.0.0.1';
        $port = max(1, min(65535, (int)($req['port'] ?? 161)));
        $comm = trim((string)($req['community'] ?? 'public')) ?: 'public';

        $py = rsnext_find_python();
        $pySnippet = 'import sys, json; from ingest_service.snmp_engine import snmp_probe_device, snmp_get; '
                   . 'h, p, c = sys.argv[1], int(sys.argv[2]), sys.argv[3]; '
                   . 'base = snmp_probe_device(h, p, c, 1.8); '
                   . 'ext = {}; '
                   . "try:\n"
                   . "  ext = snmp_get(h, ['1.3.6.1.2.1.25.3.3.1.2.1','1.3.6.1.4.1.99999.1.1.0','1.3.6.1.4.1.99999.1.4.0','1.3.6.1.4.1.99999.1.7.0'], p, c, 1.5)['values']\n"
                   . "except Exception:\n  pass\n"
                   . 'base["extra_oids"] = ext; print(json.dumps(base, ensure_ascii=False))';

        $cmd = escapeshellarg($py) . ' -c ' . escapeshellarg($pySnippet) . ' '
             . escapeshellarg($host) . ' ' . escapeshellarg((string)$port) . ' ' . escapeshellarg($comm) . ' 2>&1';
        $cwd = getcwd();
        chdir(__DIR__);
        exec($cmd, $outLines, $ret);
        if ($cwd) chdir($cwd);

        $decoded = @json_decode(implode("\n", $outLines), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('SNMPテスト実行エラー: ' . implode(' ', $outLines));
        }
        echo json_encode(['status' => 'success', 'result' => $decoded], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ③ SNMPエージェント設定更新 (update_snmp_agent)
    if ($action === 'update_snmp_agent') {
        $fullCfg = @json_decode((string)@file_get_contents($cfgFile), true);
        if (!is_array($fullCfg)) {
            throw new RuntimeException('server_config.json の読み込みに失敗しました');
        }
        $en = !empty($req['enabled']);
        $port = max(1, min(65535, (int)($req['port'] ?? 161)));
        $comm = trim((string)($req['community'] ?? 'public')) ?: 'public';

        $fullCfg['ops_monitor']['snmp_agent'] = [
            'enabled'   => $en,
            'bind_host' => '0.0.0.0',
            'port'      => $port,
            'community' => $comm,
        ];
        $jsonOut = json_encode($fullCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!rsnext_atomic_write_file($cfgFile, $jsonOut)) {
            throw new RuntimeException('server_config.json の保存に失敗しました');
        }
        @file_put_contents($tmpDir . '/hw_force_refresh.flag', date('c'));
        rsnext_log_event($pdo, 'INFO', 'WEB_ADMIN', 'SNMP_CFG_UPDATE', 'SNMPエージェント設定を更新: ' . ($en ? "有効 (UDP:{$port})" : '無効'));
        echo json_encode(['status' => 'success', 'message' => 'SNMPエージェント設定を反映しました'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ④ カスタムLAN / SNMP監視ターゲット追加・削除 (save_custom_target / delete_custom_target)
    if ($action === 'save_custom_target') {
        $fullCfg = @json_decode((string)@file_get_contents($cfgFile), true);
        if (!is_array($fullCfg)) throw new RuntimeException('設定読込エラー');

        $host = preg_replace('/[^A-Za-z0-9.\-_]/', '', trim((string)($req['host'] ?? '')));
        $name = trim((string)($req['name'] ?? '')) ?: $host;
        $role = trim((string)($req['role'] ?? 'SNMP/ネットワーク機器'));
        $tcpPort = max(0, min(65535, (int)($req['tcp_port'] ?? 0)));
        $useSnmp = !empty($req['snmp']);
        $comm = trim((string)($req['community'] ?? 'public')) ?: 'public';

        if ($host === '') {
            throw new RuntimeException('監視対象のIPアドレスまたはホスト名を入力してください');
        }

        $targets = $fullCfg['ops_monitor']['custom_targets'] ?? [];
        $targets[] = [
            'id'        => 'CST_' . bin2hex(random_bytes(3)),
            'name'      => $name,
            'host'      => $host,
            'role'      => $role,
            'tcp_port'  => $tcpPort,
            'snmp'      => $useSnmp,
            'snmp_port' => 161,
            'community' => $comm,
        ];
        $fullCfg['ops_monitor']['custom_targets'] = array_values($targets);
        rsnext_atomic_write_file($cfgFile, json_encode($fullCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @file_put_contents($tmpDir . '/hw_force_refresh.flag', date('c'));
        echo json_encode(['status' => 'success', 'message' => "監視対象「{$name} ({$host})」を追加しました"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete_custom_target') {
        $fullCfg = @json_decode((string)@file_get_contents($cfgFile), true);
        if (!is_array($fullCfg)) throw new RuntimeException('設定読込エラー');
        $tid = (string)($req['id'] ?? '');
        $targets = array_filter($fullCfg['ops_monitor']['custom_targets'] ?? [], fn($t) => ($t['id'] ?? '') !== $tid);
        $fullCfg['ops_monitor']['custom_targets'] = array_values($targets);
        rsnext_atomic_write_file($cfgFile, json_encode($fullCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @file_put_contents($tmpDir . '/hw_force_refresh.flag', date('c'));
        echo json_encode(['status' => 'success', 'message' => '監視対象を削除しました'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑤ バックアップ管理・ライブモニター状態＆履歴取得 (backup_status)
    if ($action === 'backup_status') {
        $liveFile = $tmpDir . '/backup_live_status.json';
        $histFile = $tmpDir . '/backup_history.json';
        $live = is_file($liveFile) ? (@json_decode((string)@file_get_contents($liveFile), true) ?: null) : null;
        $history = is_file($histFile) ? (@json_decode((string)@file_get_contents($histFile), true) ?: []) : [];

        echo json_encode([
            'status'  => 'success',
            'live'    => $live,
            'history' => array_slice($history, 0, 80),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑥ バックアップジョブ設定更新 (update_backup_config)
    if ($action === 'update_backup_config') {
        $fullCfg = @json_decode((string)@file_get_contents($cfgFile), true);
        if (!is_array($fullCfg)) {
            throw new RuntimeException('server_config.json の読み込みに失敗しました');
        }
        $bm = $fullCfg['backup_manager'] ?? ['master_enabled' => true, 'jobs' => []];
        $msgParts = [];

        if (array_key_exists('master_enabled', $req)) {
            $mOn = (bool)$req['master_enabled'];
            $bm['master_enabled'] = $mOn;
            $msgParts[] = 'バックアップ全体スケジュール: ' . ($mOn ? '有効' : '一時停止');
        }

        $jobCode = trim((string)($req['job_code'] ?? ''));
        if ($jobCode !== '') {
            $jobs = is_array($bm['jobs'] ?? null) ? $bm['jobs'] : [];
            $foundIdx = -1;
            foreach ($jobs as $idx => $j) {
                if (($j['code'] ?? '') === $jobCode) {
                    $foundIdx = $idx;
                    break;
                }
            }
            $entry = ($foundIdx >= 0) ? $jobs[$foundIdx] : ['code' => $jobCode];
            if (array_key_exists('enabled', $req)) {
                $jOn = (bool)$req['enabled'];
                $entry['enabled'] = $jOn;
                if ($jOn && empty($bm['master_enabled'])) {
                    $bm['master_enabled'] = true;
                }
                $msgParts[] = "{$jobCode}: " . ($jOn ? '自動実行ON' : '自動実行OFF');
            }
            if (isset($req['schedule_type']) && in_array($req['schedule_type'], ['interval', 'daily'], true)) {
                $entry['schedule_type'] = $req['schedule_type'];
            }
            if (isset($req['interval_min'])) {
                $ivMin = max(5, min(10080, (int)$req['interval_min']));
                $entry['interval_min'] = $ivMin;
                $msgParts[] = "{$jobCode}: {$ivMin}分間隔";
            }
            if (isset($req['daily_time'])) {
                $dt = trim((string)$req['daily_time']);
                if (!preg_match('/^\d{2}:\d{2}$/', $dt)) {
                    throw new RuntimeException('実行時刻は HH:MM 形式で指定してください');
                }
                $entry['daily_time'] = $dt;
                $msgParts[] = "{$jobCode}: 毎日 {$dt}";
            }
            if (isset($req['retention_days'])) {
                $retDays = max(3, min(3650, (int)$req['retention_days']));
                $entry['retention_days'] = $retDays;
                $msgParts[] = "保持世代: {$retDays}日";
            }
            if (array_key_exists('include_archive_on_hdd', $req)) {
                $entry['include_archive_on_hdd'] = (bool)$req['include_archive_on_hdd'];
            }
            if ($foundIdx >= 0) {
                $jobs[$foundIdx] = $entry;
            } else {
                $jobs[] = $entry;
            }
            $bm['jobs'] = array_values($jobs);
        }

        $fullCfg['backup_manager'] = $bm;
        $jsonOut = json_encode($fullCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!rsnext_atomic_write_file($cfgFile, $jsonOut)) {
            throw new RuntimeException('server_config.json の保存に失敗しました');
        }
        $msg = implode(' ／ ', $msgParts) ?: 'バックアップ設定を保存しました';
        rsnext_log_event($pdo, 'INFO', 'WEB_ADMIN', 'BACKUP_CFG_UPDATE', "バックアップ設定変更: {$msg}");
        echo json_encode(['status' => 'success', 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑦ バックアップ・ミラーリング 手動即時実行 / 安全停止 (trigger_backup_job / stop_backup_job)
    if ($action === 'trigger_backup_job') {
        $jobCode = preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim((string)($req['job_code'] ?? ''))));
        $allowed = ['MIRROR_ACTIVE', 'MIRROR_ARCHIVE', 'DB_DUMP_HDD', 'FULL_HDD_SYNC', 'ALL'];
        if (!in_array($jobCode, $allowed, true)) {
            throw new RuntimeException('無効なバックアップジョブコードです');
        }
        @unlink($tmpDir . '/backup_stop.flag');
        rsnext_atomic_write_file($tmpDir . '/backup_trigger_queue.json', json_encode([
            'job_code'     => $jobCode,
            'requested_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE));
        echo json_encode(['status' => 'success', 'message' => "ジョブ ({$jobCode}) の即時実行を開始しました"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'stop_backup_job') {
        @file_put_contents($tmpDir . '/backup_stop.flag', date('c'));
        echo json_encode(['status' => 'success', 'message' => '実行中のバックアップジョブへ安全停止シグナルを送信しました'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑧ DBダンプファイルの手動整合性テスト (verify_db_dump)
    if ($action === 'verify_db_dump') {
        $fn = basename(trim((string)($req['filename'] ?? '')));
        if ($fn === '' || !str_ends_with($fn, '.dump')) {
            throw new RuntimeException('有効な .dump ファイル名を指定してください');
        }
        $py = rsnext_find_python();
        $cmd = escapeshellarg($py) . ' -m ingest_service.backup_manager --verify-dump ' . escapeshellarg($fn) . ' 2>&1';
        $cwd = getcwd();
        chdir(__DIR__);
        exec($cmd, $outLines, $ret);
        if ($cwd) chdir($cwd);

        $decoded = @json_decode(implode("\n", $outLines), true);
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            throw new RuntimeException($decoded['error'] ?? ('ダンプ検証失敗: ' . implode(' ', $outLines)));
        }
        echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑨ フクダ心電計オーダリング通信テスト待受 (fukuda_order_diag)
    if ($action === 'fukuda_order_diag') {
        $sub = (string)($req['sub'] ?? 'status');
        $port = 60099;
        $logFile = $tmpDir . '/fukuda_order_log.jsonl';

        if ($sub === 'start') {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.4);
            if ($fp) {
                fclose($fp);
            } else {
                $py = rsnext_find_python();
                $script = __DIR__ . '\\ingest_service\\fukuda_order_probe.py';
                $bgCmd = 'cmd.exe /c start "" /B ' . escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . $port . ' > NUL 2>&1';
                pclose(popen($bgCmd, 'r'));
                usleep(600000);
            }
        }

        $listening = false;
        $fp2 = @fsockopen('127.0.0.1', $port, $e2, $s2, 0.4);
        if ($fp2) {
            $listening = true;
            fclose($fp2);
        }

        $lines = is_file($logFile) ? (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $parsed = array_map(fn($ln) => @json_decode($ln, true) ?: ['raw' => $ln], array_slice($lines, -20));

        echo json_encode([
            'status'    => 'success',
            'port'      => $port,
            'listening' => $listening,
            'records'   => $parsed,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('未対応のアクションです: ' . $action);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

