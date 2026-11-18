<?php
// ==============================================================================
// RS-Base クライアントPC連携 稼働状況・エラーログ収集＆管理ツール (client_logs.php)
// - RSBaseSetup.exe / RSBaseUninstall.exe / watcher.vbs / cd_burn.ps1 からHTTPで自動集約
// - 端末別の死活状態・環境チェック（SMB共有/RSViewer/心電図/CD書込/IP不一致）を一覧表示
// - 共有フォルダ未接続環境向けのHTTPトリガー配信フォールバック (action=poll_trigger) も兼任
// ==============================================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo');

@header('X-Frame-Options: ALLOWALL');
@header('Content-Security-Policy: frame-ancestors *');

$tmp_root  = __DIR__ . '/tmp';
$log_dir   = $tmp_root . '/client_logs';
$events_fp = $log_dir . '/events.jsonl';
$nodes_fp  = $log_dir . '/nodes.json';

if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0777, true);
}
$htaccess_fp = $log_dir . '/.htaccess';
if (!file_exists($htaccess_fp)) {
    @file_put_contents($htaccess_fp, "Require all denied\n");
}

/**
 * 文字列を安全にUTF-8へ正規化し、制御文字を整理する
 */
function normalize_utf8_text($val, $max_len = 4000) {
    if ($val === null) return '';
    $str = (string)$val;
    if ($str === '') return '';
    if (!mb_check_encoding($str, 'UTF-8')) {
        $str = @mb_convert_encoding($str, 'UTF-8', 'SJIS-win,CP932,EUC-JP,UTF-8');
    }
    $str = str_replace(["\r\n", "\r"], "\n", $str);
    // 改行・タブ以外の制御文字を除去
    $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str);
    if (mb_strlen($str, 'UTF-8') > $max_len) {
        $str = mb_substr($str, 0, $max_len, 'UTF-8') . '...(省略)';
    }
    return trim($str);
}

/**
 * 真偽値フラグ ("1"/"0"/"true"/"false"/"") を三値 (true / false / null) に変換
 */
function parse_tri_bool($val) {
    if ($val === null || $val === '') return null;
    if (is_bool($val)) return $val;
    $s = strtolower(trim((string)$val));
    if ($s === '1' || $s === 'true' || $s === 'yes' || $s === 'ok') return true;
    if ($s === '0' || $s === 'false' || $s === 'no' || $s === 'ng') return false;
    return null;
}

/**
 * 端末一覧 (nodes.json) の読み込み
 */
function load_client_nodes($nodes_fp) {
    if (!is_file($nodes_fp)) return [];
    $raw = @file_get_contents($nodes_fp);
    if (!$raw) return [];
    $data = @json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * 端末一覧 (nodes.json) の排他保存
 */
function save_client_nodes($nodes_fp, array $nodes) {
    // 最大200台までに制限（古い順に整理）
    if (count($nodes) > 200) {
        uasort($nodes, function ($a, $b) {
            return ($b['last_seen_epoch'] ?? 0) <=> ($a['last_seen_epoch'] ?? 0);
        });
        $nodes = array_slice($nodes, 0, 200, true);
    }
    $json = json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    @file_put_contents($nodes_fp, $json, LOCK_EX);
}

/**
 * イベントログ (events.jsonl) への追記と自動ローテーション
 */
function append_client_event($events_fp, array $entry) {
    // 1.5MBを超えたら .1 にローテーション（ディスク肥大化防止）
    if (is_file($events_fp) && (@filesize($events_fp) ?: 0) > 1572864) {
        @copy($events_fp, $events_fp . '.1');
        // 直近500行だけ残して切り詰める
        $lines = @file($events_fp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $tail  = array_slice($lines, -500);
        @file_put_contents($events_fp, implode("\n", $tail) . (count($tail) ? "\n" : ''), LOCK_EX);
    }
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($events_fp, $line, FILE_APPEND | LOCK_EX);
}

/**
 * イベントログの読み込み（新しい順）
 */
function load_client_events($events_fp, $limit = 500) {
    $all_lines = [];
    if (is_file($events_fp . '.1')) {
        $old_lines = @file($events_fp . '.1', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($old_lines)) {
            $all_lines = array_merge($all_lines, $old_lines);
        }
    }
    if (is_file($events_fp)) {
        $cur_lines = @file($events_fp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($cur_lines)) {
            $all_lines = array_merge($all_lines, $cur_lines);
        }
    }
    if (empty($all_lines)) return [];

    $slice = array_slice($all_lines, -$limit);
    $slice = array_reverse($slice);
    $events = [];
    foreach ($slice as $ln) {
        $obj = @json_decode($ln, true);
        if (is_array($obj)) {
            $events[] = $obj;
        }
    }
    return $events;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if ($remote_ip === '::1') $remote_ip = '127.0.0.1';

// ==============================================================================
// API 1: HTTPトリガーポーリング (SMB共有フォルダ未接続PC向けのフォールバック配信)
// ==============================================================================
if ($action === 'poll_trigger') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $safe_ip = preg_replace('/[^0-9a-fA-F\.\:\_]/', '_', $remote_ip);
    $candidates = [];
    $candidates[] = $tmp_root . '/cdburn_' . $safe_ip . '.txt';
    if ($safe_ip === '127.0.0.1') {
        $candidates[] = $tmp_root . '/cdburn___1.txt';
    }
    $candidates[] = $tmp_root . '/trigger_' . $safe_ip . '.txt';
    if ($safe_ip === '127.0.0.1') {
        $candidates[] = $tmp_root . '/trigger___1.txt';
    }

    foreach ($candidates as $fp) {
        if (is_file($fp)) {
            $content = trim((string)@file_get_contents($fp));
            @unlink($fp);
            // 共有ハブ側に書かれた同一トリガー残骸も掃除して二重発火を防止
            $metaFp = preg_replace('/\.txt$/', '.hubmeta.json', $fp);
            if ($metaFp && is_file($metaFp)) {
                $meta = @json_decode((string)@file_get_contents($metaFp), true);
                if (!empty($meta['hub_fp']) && @is_file((string)$meta['hub_fp'])) {
                    @unlink((string)$meta['hub_fp']);
                }
                @unlink($metaFp);
            }
            if ($content !== '') {
                // cdburn_ ファイルでプレフィックスが無い場合は CDBURN| を補完
                if (strpos(basename($fp), 'cdburn_') === 0 && strpos($content, 'CDBURN|') !== 0) {
                    $content = 'CDBURN|' . $content;
                }
                echo $content;
                exit;
            }
        }
    }
    echo '';
    exit;
}

// ==============================================================================
// API 2: クライアントからのログ・死活・環境診断レポート受信 (action=report)
// ==============================================================================
if ($action === 'report') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $input = $_POST;
    if (empty($input)) {
        $raw_body = @file_get_contents('php://input');
        if ($raw_body) {
            $json_in = @json_decode($raw_body, true);
            if (is_array($json_in)) {
                $input = $json_in;
            } else {
                parse_str($raw_body, $parsed_in);
                if (is_array($parsed_in) && !empty($parsed_in)) {
                    $input = $parsed_in;
                }
            }
        }
    }
    if (empty($input)) {
        $input = $_GET;
    }

    $hostname    = strtoupper(normalize_utf8_text($input['hostname'] ?? '', 80));
    $username    = normalize_utf8_text($input['username'] ?? '', 80);
    $client_ips  = normalize_utf8_text($input['client_ips'] ?? '', 200);
    $version     = normalize_utf8_text($input['version'] ?? '', 32);
    $source      = normalize_utf8_text($input['source'] ?? 'client', 40);
    $level       = strtoupper(normalize_utf8_text($input['level'] ?? 'INFO', 16));
    if (!in_array($level, ['INFO', 'WARN', 'ERROR'], true)) {
        $level = 'INFO';
    }
    $event       = strtoupper(normalize_utf8_text($input['event'] ?? 'LOG', 60));
    $message     = normalize_utf8_text($input['message'] ?? '', 500);
    $detail      = normalize_utf8_text($input['detail'] ?? '', 12000);
    $install_dir = normalize_utf8_text($input['install_dir'] ?? '', 260);
    $os_info     = normalize_utf8_text($input['os_info'] ?? '', 160);
    $client_ts   = normalize_utf8_text($input['client_ts'] ?? '', 40);
    $record_ev   = !isset($input['record_event']) || (string)$input['record_event'] !== '0';

    $now_epoch = time();
    $now_str   = date('Y-m-d H:i:s', $now_epoch);

    // クライアント自己認識IPとサーバー側REMOTE_ADDRの整合チェック（ローカルテスト127.0.0.1は除外）
    $ip_mismatch = false;
    if ($client_ips !== '' && $remote_ip !== '127.0.0.1' && $remote_ip !== 'unknown') {
        $ip_list = array_map('trim', explode(',', $client_ips));
        if (!in_array($remote_ip, $ip_list, true)) {
            $ip_mismatch = true;
        }
    }

    // 端末ステータスの更新
    $node_key = ($hostname !== '' ? $hostname : 'IP_' . $remote_ip);
    $nodes = load_client_nodes($nodes_fp);
    $prev  = $nodes[$node_key] ?? [];

    $smb_ok       = parse_tri_bool($input['smb_ok'] ?? null);
    $rsview_ok    = parse_tri_bool($input['rsview_ok'] ?? null);
    $ecg_ok       = parse_tri_bool($input['ecg_ok'] ?? null);
    $jpeg_tool_ok = parse_tri_bool($input['jpeg_tool_ok'] ?? null);
    $cdburn_ok    = parse_tri_bool($input['cdburn_ok'] ?? null);
    $wsh_ok       = parse_tri_bool($input['wsh_ok'] ?? null);

    $node_status = $prev['status'] ?? 'active';
    if (strpos($event, 'UNINSTALL') === 0) {
        $node_status = 'uninstalled';
    } elseif ($event === 'INSTALL_FAIL') {
        $node_status = 'error';
    } elseif (in_array($event, ['STARTUP', 'HEARTBEAT', 'INSTALL_OK', 'TRIGGER_OK', 'SELF_UPDATE_OK'], true)) {
        $node_status = 'active';
    }

    $last_error    = $prev['last_error'] ?? '';
    $last_error_ts = $prev['last_error_ts'] ?? '';
    if ($level === 'ERROR' || $level === 'WARN') {
        $last_error    = ($message !== '' ? $message : $event);
        $last_error_ts = $now_str;
    } elseif ($event === 'INSTALL_OK' || $event === 'UNINSTALL_OK') {
        // 再セットアップ成功またはアンインストール完了時は旧エラー表示をリセット
        $last_error    = '';
        $last_error_ts = '';
    }

    $nodes[$node_key] = [
        'node_key'        => $node_key,
        'hostname'        => $hostname !== '' ? $hostname : ($prev['hostname'] ?? ''),
        'username'        => $username !== '' ? $username : ($prev['username'] ?? ''),
        'remote_ip'       => $remote_ip,
        'client_ips'      => $client_ips !== '' ? $client_ips : ($prev['client_ips'] ?? ''),
        'ip_mismatch'     => $ip_mismatch,
        'version'         => $version !== '' ? $version : ($prev['version'] ?? ''),
        'install_dir'     => $install_dir !== '' ? $install_dir : ($prev['install_dir'] ?? ''),
        'os_info'         => $os_info !== '' ? $os_info : ($prev['os_info'] ?? ''),
        'status'          => $node_status,
        'smb_ok'          => $smb_ok !== null ? $smb_ok : ($prev['smb_ok'] ?? null),
        'rsview_ok'       => $rsview_ok !== null ? $rsview_ok : ($prev['rsview_ok'] ?? null),
        'ecg_ok'          => $ecg_ok !== null ? $ecg_ok : ($prev['ecg_ok'] ?? null),
        'jpeg_tool_ok'    => $jpeg_tool_ok !== null ? $jpeg_tool_ok : ($prev['jpeg_tool_ok'] ?? null),
        'cdburn_ok'       => $cdburn_ok !== null ? $cdburn_ok : ($prev['cdburn_ok'] ?? null),
        'wsh_ok'          => $wsh_ok !== null ? $wsh_ok : ($prev['wsh_ok'] ?? null),
        'last_seen'       => $now_str,
        'last_seen_epoch' => $now_epoch,
        'last_event'      => $event,
        'last_error'      => $last_error,
        'last_error_ts'   => $last_error_ts,
    ];
    save_client_nodes($nodes_fp, $nodes);

    if ($record_ev) {
        $entry = [
            'id'         => date('Ymd_His', $now_epoch) . '_' . substr(md5(uniqid('', true)), 0, 6),
            'ts'         => $now_str,
            'client_ts'  => $client_ts,
            'remote_ip'  => $remote_ip,
            'client_ips' => $client_ips,
            'hostname'   => $hostname,
            'username'   => $username,
            'version'    => $version,
            'source'     => $source,
            'level'      => $level,
            'event'      => $event,
            'message'    => $message,
            'detail'     => $detail,
        ];
        append_client_event($events_fp, $entry);
    }

    echo json_encode([
        'status'      => 'ok',
        'remote_ip'   => $remote_ip,
        'ip_mismatch' => $ip_mismatch,
        'server_time' => $now_str,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==============================================================================
// API 2-b: アクセス元PC自身の常駐アプリバージョン照会 (action=my_status)
// ==============================================================================
if ($action === 'my_status') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $latest_ver = @file_exists(__DIR__ . '/watcher_version.txt')
        ? trim((string)@file_get_contents(__DIR__ . '/watcher_version.txt'))
        : '2.2.3';
    $nodes = load_client_nodes($nodes_fp);
    $local_host = strtoupper((string)@gethostname());
    $matched = null;
    $best_epoch = -1;

    foreach ($nodes as $nd) {
        $nd_ip  = (string)($nd['remote_ip'] ?? '');
        $nd_ips = array_filter(array_map('trim', explode(',', (string)($nd['client_ips'] ?? ''))));
        $nd_host = strtoupper((string)($nd['hostname'] ?? ''));

        $is_match = ($nd_ip === $remote_ip) || in_array($remote_ip, $nd_ips, true);
        if (!$is_match && $remote_ip === '127.0.0.1' && $local_host !== '' && $nd_host === $local_host) {
            $is_match = true;
        }
        if ($is_match) {
            $ep = (int)($nd['last_seen_epoch'] ?? 0);
            if ($ep > $best_epoch) {
                $best_epoch = $ep;
                $matched = $nd;
            }
        }
    }

    if ($matched && ($matched['status'] ?? 'active') !== 'uninstalled' && !empty($matched['version'])) {
        $inst_ver = (string)$matched['version'];
        echo json_encode([
            'status'            => 'ok',
            'remote_ip'         => $remote_ip,
            'latest_version'    => $latest_ver,
            'found'             => true,
            'installed_version' => $inst_ver,
            'is_latest'         => ($inst_ver === $latest_ver),
            'node_status'       => (string)($matched['status'] ?? 'active'),
            'hostname'          => (string)($matched['hostname'] ?? ''),
            'last_seen'         => (string)($matched['last_seen'] ?? ''),
            'age_sec'           => max(0, time() - (int)($matched['last_seen_epoch'] ?? time())),
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'status'            => 'ok',
            'remote_ip'         => $remote_ip,
            'latest_version'    => $latest_ver,
            'found'             => false,
            'installed_version' => '',
            'is_latest'         => false,
            'node_status'       => $matched ? (string)($matched['status'] ?? '') : 'none',
            'hostname'          => $matched ? (string)($matched['hostname'] ?? '') : '',
            'last_seen'         => $matched ? (string)($matched['last_seen'] ?? '') : '',
            'age_sec'           => null,
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ==============================================================================
// API 3: ログのエクスポート (JSON / CSV)
// ==============================================================================
if ($action === 'export') {
    $fmt = strtolower($_GET['format'] ?? 'json');
    $nodes  = load_client_nodes($nodes_fp);
    $events = load_client_events($events_fp, 2000);
    $stamp  = date('Ymd_His');

    if ($fmt === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="rsbase_client_logs_' . $stamp . '.csv"');
        echo "\xEF\xBB\xBF"; // Excel用 UTF-8 BOM
        $out = fopen('php://output', 'w');
        fputcsv($out, ['受信日時', 'レベル', 'PC名', 'ユーザー', 'サーバー認識IP', 'PC側IP', 'バージョン', '送信元', 'イベント種別', 'メッセージ', '詳細情報']);
        foreach ($events as $ev) {
            fputcsv($out, [
                $ev['ts'] ?? '',
                $ev['level'] ?? '',
                $ev['hostname'] ?? '',
                $ev['username'] ?? '',
                $ev['remote_ip'] ?? '',
                $ev['client_ips'] ?? '',
                $ev['version'] ?? '',
                $ev['source'] ?? '',
                $ev['event'] ?? '',
                $ev['message'] ?? '',
                $ev['detail'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rsbase_client_diag_' . $stamp . '.json"');
    echo json_encode([
        'exported_at' => date('Y-m-d H:i:s'),
        'nodes'       => array_values($nodes),
        'events'      => $events,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ==============================================================================
// API 4: 管理操作（ログ消去 / 端末登録の削除）
// ==============================================================================
$flash_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'clear_logs') {
        @unlink($events_fp);
        @unlink($events_fp . '.1');
        $flash_msg = 'クライアント動作ログ履歴をクリアしました（端末ステータス一覧は保持されています）。';
    } elseif ($action === 'remove_node') {
        $rm_key = $_POST['node_key'] ?? '';
        $nodes = load_client_nodes($nodes_fp);
        if ($rm_key !== '' && isset($nodes[$rm_key])) {
            unset($nodes[$rm_key]);
            save_client_nodes($nodes_fp, $nodes);
            $flash_msg = '端末「' . htmlspecialchars($rm_key, ENT_QUOTES, 'UTF-8') . '」を一覧から削除しました。';
        }
    }
}

// ==============================================================================
// GUI表示用データの集計
// ==============================================================================
$nodes = load_client_nodes($nodes_fp);
uasort($nodes, function ($a, $b) {
    return ($b['last_seen_epoch'] ?? 0) <=> ($a['last_seen_epoch'] ?? 0);
});
$events = load_client_events($events_fp, 500);

$now_epoch = time();
$online_count = 0;
$issue_node_count = 0;
foreach ($nodes as $nd) {
    $st = $nd['status'] ?? 'active';
    $age = $now_epoch - ($nd['last_seen_epoch'] ?? 0);
    if ($st === 'active' && $age <= 900) {
        $online_count++;
    }
    if (
        $st === 'error' ||
        !empty($nd['ip_mismatch']) ||
        ($nd['smb_ok'] === false) ||
        ($nd['wsh_ok'] === false) ||
        !empty($nd['last_error'])
    ) {
        $issue_node_count++;
    }
}

$err_warn_count = 0;
foreach ($events as $ev) {
    $lv = $ev['level'] ?? 'INFO';
    if ($lv === 'ERROR' || $lv === 'WARN') {
        $err_warn_count++;
    }
}

$server_ver = @file_exists(__DIR__ . '/watcher_version.txt')
    ? trim((string)@file_get_contents(__DIR__ . '/watcher_version.txt'))
    : '2.2.3';

function render_chk_badge($label, $val, $warn_only = false) {
    if ($val === true) {
        return '<span class="env-badge env-ok" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ': 正常">✓ ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    if ($val === false) {
        $cls = $warn_only ? 'env-warn' : 'env-ng';
        return '<span class="env-badge ' . $cls . '" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ': 未検出またはアクセス不可">✕ ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    return '<span class="env-badge env-unk" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ': 未確認">- ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>クライアント連携 稼働状況・エラーログ管理 - RS-Base改</title>
    <style>
        :root {
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --border: #cbd5e1;
            --text-main: #0f172a;
            --text-sub: #475569;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Kaku Gothic ProN", "Yu Gothic UI", "Meiryo", sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            line-height: 1.5;
            min-height: 100vh;
        }
        /* ヘッダー（v2.2新バージョン識別グリーングラデーション） */
        .top-header {
            background: linear-gradient(90deg, #064e3b 0%, #15803d 55%, #16a34a 100%);
            color: #ffffff;
            padding: 10px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 2px 10px rgba(6, 78, 59, 0.25);
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .header-left h1 {
            font-size: 1.06rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .nav-pill {
            font-size: 0.78rem;
            color: #ecfdf5;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.32);
            padding: 4px 10px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .nav-pill:hover {
            background: rgba(255, 255, 255, 0.28);
            color: #ffffff;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-tool-setup {
            background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
            color: #ffffff;
            border: 1px solid #93c5fd;
            padding: 5px 11px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
        }
        .btn-tool-setup:hover { background: linear-gradient(135deg, #0369a1 0%, #1d4ed8 100%); }
        .btn-tool-uninst {
            background: rgba(153, 27, 27, 0.72);
            color: #fee2e2;
            border: 1px solid #fca5a5;
            padding: 5px 11px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-tool-uninst:hover { background: rgba(185, 28, 28, 0.92); color: #ffffff; }

        .main-wrap {
            max-width: 1400px;
            margin: 0 auto;
            padding: 16px 18px 40px;
        }
        .flash-banner {
            background: #ecfdf5;
            border: 1px solid #6ee7b7;
            border-left: 4px solid #059669;
            color: #065f46;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 14px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        /* KPI サマリー */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 16px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
            border-left: 4px solid #16a34a;
        }
        .kpi-card.kpi-warn { border-left-color: #d97706; }
        .kpi-card.kpi-err  { border-left-color: #dc2626; }
        .kpi-card.kpi-info { border-left-color: #0284c7; }
        .kpi-label { font-size: 0.76rem; color: var(--text-sub); font-weight: 600; }
        .kpi-val { font-size: 1.45rem; font-weight: 800; color: var(--text-main); margin-top: 2px; }
        .kpi-sub { font-size: 0.75rem; color: #64748b; margin-top: 2px; }

        /* セクションカード */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px 18px;
            margin-bottom: 18px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
        }
        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-sm {
            background: #f8fafc;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-sm:hover { background: #e2e8f0; color: #0f172a; }
        .btn-sm-danger {
            background: #fef2f2;
            color: #b91c1c;
            border-color: #fecaca;
        }
        .btn-sm-danger:hover { background: #fee2e2; }

        /* テーブル共通 */
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }
        th {
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
            text-align: left;
            padding: 8px 10px;
            border-bottom: 2px solid #cbd5e1;
            white-space: nowrap;
        }
        td {
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        tr:hover td { background: #f8fafc; }

        /* ステータス・環境バッジ */
        .st-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .st-online  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .st-idle    { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
        .st-uninst  { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .st-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        .env-wrap { display: flex; flex-wrap: wrap; gap: 4px; }
        .env-badge {
            font-size: 0.7rem;
            padding: 1px 6px;
            border-radius: 4px;
            font-weight: 600;
            white-space: nowrap;
        }
        .env-ok   { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .env-warn { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .env-ng   { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .env-unk  { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; }

        .lv-badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 700;
            font-family: Consolas, monospace;
        }
        .lv-INFO  { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .lv-WARN  { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .lv-ERROR { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

        /* フィルタバー */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .flt-tab {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
        }
        .flt-tab.active {
            background: #15803d;
            color: #ffffff;
            border-color: #15803d;
        }
        .flt-search {
            padding: 5px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 0.8rem;
            min-width: 240px;
            outline: none;
        }
        .flt-search:focus { border-color: #16a34a; box-shadow: 0 0 0 2px rgba(22,163,74,0.18); }

        .detail-box {
            margin-top: 4px;
            background: #0f172a;
            color: #e2e8f0;
            padding: 7px 10px;
            border-radius: 6px;
            font-family: Consolas, "Courier New", monospace;
            font-size: 0.75rem;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 220px;
            overflow-y: auto;
        }
        .empty-state {
            text-align: center;
            padding: 28px 16px;
            color: #64748b;
            font-size: 0.86rem;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px dashed #cbd5e1;
        }
        .guide-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 0.8rem;
            color: #14532d;
            margin-top: 14px;
        }
    </style>
</head>
<body>
    <header class="top-header">
        <div class="header-left">
            <h1>
                <a href="index.php" style="color:inherit; text-decoration:none;" title="本日の検査一覧へ戻る">
                    📋 クライアントPC連携 稼働状況・エラーログ管理
                </a>
            </h1>
            <a href="index.php" class="nav-pill">🏠 本日の検査一覧へ</a>
            <a href="clear_cache.php" class="nav-pill">🧹 キャッシュ管理</a>
            <a href="help.html#sec4" target="_blank" class="nav-pill">📖 ヘルプ</a>
        </div>
        <div class="header-right">
            <a href="RSBaseSetup.exe" download class="btn-tool-setup" title="PC連携ツールのインストール・最新版への修復更新ツール">
                📥 PC連携セットアップ (RSBaseSetup.exe)
            </a>
            <a href="RSBaseUninstall.exe" download class="btn-tool-uninst" title="PC連携を安全に停止・自動起動解除・削除するアンインストール専用ツール">
                🗑️ アンインストール専用 (RSBaseUninstall.exe)
            </a>
        </div>
    </header>

    <main class="main-wrap">
        <?php if ($flash_msg !== ''): ?>
            <div class="flash-banner">✅ <?= $flash_msg ?></div>
        <?php endif; ?>

        <!-- KPI サマリー -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">登録クライアント端末数</div>
                <div class="kpi-val"><?= count($nodes) ?> 台 <span style="font-size:0.9rem; font-weight:600; color:#15803d;">(稼働中: <?= $online_count ?>台)</span></div>
                <div class="kpi-sub">起動時および10分周期の自動応答で更新</div>
            </div>
            <div class="kpi-card <?= $issue_node_count > 0 ? 'kpi-warn' : '' ?>">
                <div class="kpi-label">要確認・環境注意の端末</div>
                <div class="kpi-val" style="color:<?= $issue_node_count > 0 ? '#b45309' : '#0f172a' ?>;"><?= $issue_node_count ?> 台</div>
                <div class="kpi-sub">SMB未接続・IP不一致・WSH無効・直近エラー等</div>
            </div>
            <div class="kpi-card <?= $err_warn_count > 0 ? 'kpi-err' : '' ?>">
                <div class="kpi-label">エラー / 警告ログ件数</div>
                <div class="kpi-val" style="color:<?= $err_warn_count > 0 ? '#dc2626' : '#0f172a' ?>;"><?= $err_warn_count ?> 件 <span style="font-size:0.84rem; font-weight:500; color:#64748b;">/ 全<?= count($events) ?>件</span></div>
                <div class="kpi-sub">ビューワ不在・パス到達不可・CD書込エラー等を自動記録</div>
            </div>
            <div class="kpi-card kpi-info">
                <div class="kpi-label">配信中クライアント最新版</div>
                <div class="kpi-val">v<?= htmlspecialchars($server_ver, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="kpi-sub">各端末のローカルログ保存先: <code>C:\RSBASE\logs\client.log</code></div>
            </div>
        </div>

        <!-- 1. 端末別ステータス・環境診断一覧 -->
        <section class="section-card">
            <div class="section-head">
                <div class="section-title">
                    💻 端末別 連携ステータス・環境診断一覧
                </div>
                <div class="toolbar-actions">
                    <a href="client_logs.php" class="btn-sm">🔄 最新状態に更新</a>
                    <a href="client_logs.php?action=export&format=csv" class="btn-sm">📥 CSV出力</a>
                    <a href="client_logs.php?action=export&format=json" class="btn-sm">📥 診断JSON出力</a>
                </div>
            </div>

            <?php if (empty($nodes)): ?>
                <div class="empty-state">
                    まだクライアント端末からの応答ログがありません。<br>
                    各Windows端末で <strong>RSBaseSetup.exe</strong> を実行するか、常駐済みの <code>watcher.vbs</code> が v<?= htmlspecialchars($server_ver, ENT_QUOTES, 'UTF-8') ?> に自動更新されるとここに端末情報が表示されます。
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>PC名 / ユーザー</th>
                                <th>稼働状態</th>
                                <th>IPアドレス整合</th>
                                <th>Ver / 配置先</th>
                                <th>端末環境チェック（原因切り分け）</th>
                                <th>最終通信 / 直近の警告・エラー</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($nodes as $nkey => $nd):
                                $st = $nd['status'] ?? 'active';
                                $age = $now_epoch - ($nd['last_seen_epoch'] ?? 0);
                                if ($st === 'uninstalled') {
                                    $st_html = '<span class="st-badge st-uninst">⚪ 解除済</span>';
                                } elseif ($st === 'error') {
                                    $st_html = '<span class="st-badge st-error">❌ 導入エラー</span>';
                                } elseif ($age <= 900) {
                                    $st_html = '<span class="st-badge st-online">🟢 常駐稼働中</span>';
                                } else {
                                    $mins = max(1, (int)floor($age / 60));
                                    $age_lbl = $mins >= 60 ? ((int)floor($mins / 60) . '時間前') : ($mins . '分前');
                                    $st_html = '<span class="st-badge st-idle">🟡 応答なし (' . $age_lbl . ')</span>';
                                }
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight:700; color:#0f172a;"><?= htmlspecialchars($nd['hostname'] ?: '(不明PC)', ENT_QUOTES, 'UTF-8') ?></div>
                                    <div style="font-size:0.74rem; color:#64748b;">👤 <?= htmlspecialchars($nd['username'] ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($nd['os_info'])): ?>
                                        <div style="font-size:0.7rem; color:#94a3b8;"><?= htmlspecialchars($nd['os_info'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $st_html ?></td>
                                <td>
                                    <div style="font-family:Consolas,monospace; font-weight:700;"><?= htmlspecialchars($nd['remote_ip'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($nd['client_ips']) && $nd['client_ips'] !== ($nd['remote_ip'] ?? '')): ?>
                                        <div style="font-size:0.72rem; color:#475569;">PC認識: <code><?= htmlspecialchars($nd['client_ips'], ENT_QUOTES, 'UTF-8') ?></code></div>
                                    <?php endif; ?>
                                    <?php if (!empty($nd['ip_mismatch'])): ?>
                                        <div style="margin-top:3px;"><span class="env-badge env-ng">⚠️ IP不一致 (VPN/別NIC確認)</span></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight:700;">v<?= htmlspecialchars($nd['version'] ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
                                    <div style="font-size:0.72rem; color:#64748b; font-family:Consolas,monospace;"><?= htmlspecialchars($nd['install_dir'] ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td>
                                    <div class="env-wrap">
                                        <?= render_chk_badge('WSH実行', $nd['wsh_ok'] ?? null, false) ?>
                                        <?= render_chk_badge('SMB共有', $nd['smb_ok'] ?? null, true) ?>
                                        <?= render_chk_badge('RSViewer', $nd['rsview_ok'] ?? null, true) ?>
                                        <?= render_chk_badge('心電図FEV', $nd['ecg_ok'] ?? null, true) ?>
                                        <?= render_chk_badge('CD書込', $nd['cdburn_ok'] ?? null, true) ?>
                                        <?= render_chk_badge('胃検診Py', $nd['jpeg_tool_ok'] ?? null, true) ?>
                                    </div>
                                    <?php if (($nd['smb_ok'] ?? null) === false): ?>
                                        <div style="font-size:0.72rem; color:#b45309; margin-top:3px;">
                                            ※共有フォルダ未接続のため自動的にHTTP経由でトリガーを受信中
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:0.76rem; color:#334155;">
                                        <?= htmlspecialchars($nd['last_seen'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                        <span style="color:#64748b;">(<?= htmlspecialchars($nd['last_event'] ?? '', ENT_QUOTES, 'UTF-8') ?>)</span>
                                    </div>
                                    <?php if (!empty($nd['last_error'])): ?>
                                        <div style="margin-top:4px; padding:4px 7px; background:#fef2f2; border:1px solid #fecaca; border-radius:5px; color:#b91c1c; font-size:0.75rem; font-weight:600;">
                                            ⚠️ <?= htmlspecialchars($nd['last_error'], ENT_QUOTES, 'UTF-8') ?>
                                            <span style="font-weight:400; color:#991b1b;">(<?= htmlspecialchars($nd['last_error_ts'] ?? '', ENT_QUOTES, 'UTF-8') ?>)</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" action="client_logs.php" onsubmit="return confirm('この端末の登録表示を一覧から削除しますか？（稼働中の場合は次回応答時に再登録されます）');">
                                        <input type="hidden" name="action" value="remove_node">
                                        <input type="hidden" name="node_key" value="<?= htmlspecialchars($nkey, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn-sm" title="一覧から削除">✕ 削除</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="guide-box">
                <strong>💡 動作しない原因の見分け方：</strong>
                <span style="margin-left:6px;">
                    ① <strong>「✕ WSH実行」</strong>→ Windowsの設定またはセキュリティソフトでVBScript実行が無効化されています ／
                    ② <strong>「✕ RSViewer / 心電図FEV」</strong>→ そのPCに <code>C:\RSBASE\RSB_public_html\...</code> がインストールされていません（Web内蔵ビューワをご利用ください） ／
                    ③ <strong>「✕ SMB共有」</strong>→ <code>\\192.168.1.16\htdocs</code> へのファイル共有が未接続です（CD書き込み等はHTTP経由で自動代替されますが、RSViewer本体が共有データを読む場合は資格情報登録が必要です）。
                </span>
            </div>
        </section>

        <!-- 2. 時系列イベント・エラーログ一覧 -->
        <section class="section-card">
            <div class="section-head">
                <div class="section-title">
                    📜 クライアント動作・エラーログ履歴（最新500件）
                </div>
                <div class="toolbar-actions">
                    <form method="POST" action="client_logs.php" onsubmit="return confirm('記録されている動作・エラーログ履歴をすべて消去しますか？');">
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="submit" class="btn-sm btn-sm-danger">🧹 ログ履歴をクリア</button>
                    </form>
                </div>
            </div>

            <div class="filter-bar">
                <button type="button" class="flt-tab active" data-cat="all">すべて表示</button>
                <button type="button" class="flt-tab" data-cat="err">❌ エラー・警告のみ</button>
                <button type="button" class="flt-tab" data-cat="setup">⚙️ セットアップ・解除</button>
                <button type="button" class="flt-tab" data-cat="trigger">🖱️ ビューワ・CD書込実行</button>
                <button type="button" class="flt-tab" data-cat="lifecycle">🚀 起動・自動更新</button>
                <input type="text" id="logSearchInput" class="flt-search" placeholder="🔍 PC名・IP・エラー内容で絞り込み...">
            </div>

            <?php if (empty($events)): ?>
                <div class="empty-state">
                    記録されたイベントログはまだありません。
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table id="eventsTable">
                        <thead>
                            <tr>
                                <th style="width:150px;">受信日時</th>
                                <th style="width:76px;">レベル</th>
                                <th style="width:175px;">PC名 / IP</th>
                                <th style="width:120px;">送信元 / Ver</th>
                                <th style="width:170px;">イベント</th>
                                <th>内容・診断詳細</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $ev):
                                $lv  = $ev['level'] ?? 'INFO';
                                $evn = $ev['event'] ?? '';
                                $src = $ev['source'] ?? '';
                                $cat = 'other';
                                if (strpos($evn, 'INSTALL') !== false || strpos($evn, 'UNINSTALL') !== false || $src === 'setup' || $src === 'uninstall') {
                                    $cat = 'setup';
                                } elseif (strpos($evn, 'TRIGGER') !== false || strpos($evn, 'VIEWER') !== false || strpos($evn, 'CDBURN') !== false || strpos($evn, 'TARGET') !== false || strpos($evn, 'LAUNCH') !== false || $src === 'cd_burn') {
                                    $cat = 'trigger';
                                } elseif (strpos($evn, 'STARTUP') !== false || strpos($evn, 'UPDATE') !== false || strpos($evn, 'SMB') !== false) {
                                    $cat = 'lifecycle';
                                }
                            ?>
                            <tr class="ev-row"
                                data-level="<?= htmlspecialchars($lv, ENT_QUOTES, 'UTF-8') ?>"
                                data-cat="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>">
                                <td style="white-space:nowrap; font-family:Consolas,monospace; color:#334155;">
                                    <?= htmlspecialchars($ev['ts'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <span class="lv-badge lv-<?= htmlspecialchars($lv, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lv, ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <div style="font-weight:700;"><?= htmlspecialchars($ev['hostname'] ?: '(不明PC)', ENT_QUOTES, 'UTF-8') ?></div>
                                    <div style="font-size:0.73rem; color:#64748b; font-family:Consolas,monospace;">
                                        <?= htmlspecialchars($ev['remote_ip'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($ev['username'])): ?>
                                            (<?= htmlspecialchars($ev['username'], ENT_QUOTES, 'UTF-8') ?>)
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:600;"><?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($ev['version'])): ?>
                                        <div style="font-size:0.72rem; color:#64748b;">v<?= htmlspecialchars($ev['version'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-family:Consolas,monospace; font-weight:700; color:#1e293b;">
                                    <?= htmlspecialchars($evn, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <div style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($ev['message'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($ev['detail'])): ?>
                                        <?php if (mb_strlen($ev['detail'], 'UTF-8') > 180): ?>
                                            <details style="margin-top:4px;">
                                                <summary style="cursor:pointer; font-size:0.75rem; color:#0284c7; font-weight:600;">詳細情報・診断ログを表示</summary>
                                                <div class="detail-box"><?= htmlspecialchars($ev['detail'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </details>
                                        <?php else: ?>
                                            <div class="detail-box"><?= htmlspecialchars($ev['detail'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script>
    (function() {
        const tabs = document.querySelectorAll('.flt-tab');
        const searchInput = document.getElementById('logSearchInput');
        const rows = document.querySelectorAll('.ev-row');
        let activeCat = 'all';

        function applyFilters() {
            const q = (searchInput ? searchInput.value : '').trim().toLowerCase();
            rows.forEach(row => {
                const lv  = row.getAttribute('data-level') || '';
                const cat = row.getAttribute('data-cat') || '';
                let catMatch = true;
                if (activeCat === 'err') {
                    catMatch = (lv === 'ERROR' || lv === 'WARN');
                } else if (activeCat !== 'all') {
                    catMatch = (cat === activeCat);
                }
                let textMatch = true;
                if (q !== '') {
                    textMatch = row.textContent.toLowerCase().indexOf(q) !== -1;
                }
                row.style.display = (catMatch && textMatch) ? '' : 'none';
            });
        }

        tabs.forEach(btn => {
            btn.addEventListener('click', () => {
                tabs.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                activeCat = btn.getAttribute('data-cat') || 'all';
                applyFilters();
            });
        });
        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }
    })();
    </script>
</body>
</html>

