<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function rsnext_resolve_study_dir(string $volumeId, string $relPath): string {
    $cfg = rsnext_config();
    $vols = $cfg['storage']['volumes'] ?? [];
    $vol = $vols[$volumeId] ?? reset($vols);
    $base = rtrim(str_replace('/', '\\', (string)($vol['path'] ?? '')), '\\');
    $rel = ltrim(str_replace('/', '\\', $relPath), '\\');
    return $base . '\\' . $rel;
}

function rsnext_resolve_study_smb(string $volumeId, string $relPath): string {
    $cfg = rsnext_config();
    $vols = $cfg['storage']['volumes'] ?? [];
    $vol = $vols[$volumeId] ?? reset($vols);
    $smb = rtrim(str_replace('/', '\\', (string)($vol['smb_share'] ?? '')), '\\');
    if ($smb === '') {
        return rsnext_resolve_study_dir($volumeId, $relPath);
    }
    $rel = ltrim(str_replace('/', '\\', $relPath), '\\');
    return $smb . '\\' . $rel;
}

function rsnext_cache_root(): string {
    $cfg = rsnext_config();
    $root = (string)($cfg['storage']['cache_root'] ?? (dirname(__DIR__) . '/storage_data/cache'));
    return rtrim(str_replace('/', '\\', $root), '\\');
}

function rsnext_quarantine_root(): string {
    $cfg = rsnext_config();
    $root = (string)($cfg['storage']['quarantine_root'] ?? (dirname(__DIR__) . '/storage_data/quarantine'));
    return rtrim(str_replace('/', '\\', $root), '\\');
}

function rsnext_atomic_write_file(string $path, string $bytes): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $tmp = $path . '.tmp_' . uniqid('', true);
    if (@file_put_contents($tmp, $bytes) !== false) {
        if (@rename($tmp, $path)) {
            return true;
        }
        @unlink($tmp);
    }
    return false;
}

function rsnext_sync_study_meta_json(PDO $pdo, int $studyId): void {
    $stmt = $pdo->prepare(
        "SELECT s.*, mp.profile_code
         FROM studies s
         LEFT JOIN modality_profiles mp ON mp.profile_id = s.profile_id
         WHERE s.study_id = ?"
    );
    $stmt->execute([$studyId]);
    $st = $stmt->fetch();
    if (!$st) {
        return;
    }

    $dir = rsnext_resolve_study_dir((string)$st['volume_id'], (string)$st['rel_path']);
    if (!is_dir($dir)) {
        return;
    }

    $fStmt = $pdo->prepare(
        "SELECT seq_no, filename, orig_filename, file_kind, sop_instance_uid,
                sha256_hash, file_bytes, width, height, transfer_syntax
         FROM study_files
         WHERE study_id = ?
         ORDER BY seq_no ASC"
    );
    $fStmt->execute([$studyId]);
    $files = $fStmt->fetchAll();

    $meta = [
        'schema_version'     => 1,
        'study_id'           => (int)$st['study_id'],
        'legacy_renban'      => $st['legacy_renban'] !== null ? (int)$st['legacy_renban'] : null,
        'patient_id'         => (string)$st['patient_id'],
        'study_date'         => (string)($st['study_date'] ?? ''),
        'study_time'         => (string)($st['study_time'] ?? ''),
        'modality'           => (string)$st['modality'],
        'kensamei'           => (string)$st['kensamei'],
        'display_category'   => (string)$st['display_category'],
        'badge_color'        => (string)$st['badge_color'],
        'profile_code'       => (string)($st['profile_code'] ?? ''),
        'station_name'       => (string)$st['station_name'],
        'manufacturer'       => (string)$st['manufacturer'],
        'model_name'         => (string)$st['model_name'],
        'doctor'             => (string)$st['doctor'],
        'department'         => (string)$st['department'],
        'findings'           => (string)$st['findings'],
        'comment'            => (string)$st['comment'],
        'primary_file_type'  => (string)$st['primary_file_type'],
        'study_instance_uid' => (string)($st['study_instance_uid'] ?? ''),
        'is_deleted'         => (bool)$st['is_deleted'],
        'files'              => $files,
        'updated_at'         => date('c'),
    ];

    rsnext_atomic_write_file(
        $dir . '\\study_meta.json',
        json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

function rsnext_find_python(): string {
    $candidates = [
        'C:\\Users\\Owner\\AppData\\Local\\Programs\\Python\\Python313\\python.exe',
        'C:\\Users\\owner\\AppData\\Local\\Programs\\Python\\Python313\\python.exe',
        'C:\\Users\\owner\\AppData\\Local\\Python\\bin\\python.exe',
        'C:\\Users\\owner\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe',
        'C:\\Program Files\\Python313\\python.exe',
        'C:\\Program Files\\Python314\\python.exe',
        'C:\\Python313\\python.exe',
    ];
    foreach ($candidates as $cand) {
        if (is_file($cand)) {
            return $cand;
        }
    }
    $where = @shell_exec('where python 2>NUL');
    if ($where) {
        foreach (explode("\n", trim($where)) as $line) {
            $line = trim($line);
            if ($line !== '' && stripos($line, 'WindowsApps') === false && is_file($line)) {
                return $line;
            }
        }
    }
    return 'python';
}

