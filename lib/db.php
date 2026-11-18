<?php
declare(strict_types=1);

function rsnext_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $path = dirname(__DIR__) . '/config/server_config.json';
        $raw = @file_get_contents($path);
        $cfg = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return $cfg;
}

function rsnext_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = rsnext_config();
        $db = $cfg['db'] ?? [];
        $host = $db['host'] ?? '127.0.0.1';
        $port = (int)($db['port'] ?? 5432);
        $dbname = $db['dbname'] ?? 'rsnext_db';
        $user = $db['user'] ?? 'postgres';
        $pass = $db['password'] ?? 'postgres';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};connect_timeout=3";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET client_encoding TO 'UTF8'");
    }
    return $pdo;
}

function rsnext_log_event(
    PDO $pdo,
    string $level,
    string $channel,
    string $eventType,
    string $summary,
    string $patientId = '',
    ?int $studyId = null,
    array $detail = []
): void {
    $detail['client_ip'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt = $pdo->prepare(
        "INSERT INTO ingest_logs (level, channel, event_type, patient_id, study_id, summary, detail)
         VALUES (?, ?, ?, ?, ?, ?, ?::jsonb)"
    );
    $stmt->execute([
        $level,
        $channel,
        $eventType,
        $patientId,
        $studyId,
        $summary,
        json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function rsnext_get_pending_quarantine_count(PDO $pdo): int {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM quarantine_queue WHERE status = 'PENDING'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

