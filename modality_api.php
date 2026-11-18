<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next Modality Studio・外部メディア取込・受信/隔離ダッシュボード統合API (modality_api.php)
// ==============================================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/lib/bootstrap.php';

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

// 隔離ファイルのサムネイル/画像プレビュー配信 (JSON以外のバイナリレスポンス)
if ($action === 'quarantine_preview') {
    $qid = (int)($_GET['quarantine_id'] ?? 0);
    if ($qid <= 0) {
        http_response_code(400);
        exit('Invalid quarantine_id');
    }
    $pdo = rsnext_pdo();
    $st = $pdo->prepare("SELECT quarantine_rel FROM quarantine_queue WHERE quarantine_id = ?");
    $st->execute([$qid]);
    $row = $st->fetch();
    if (!$row) {
        http_response_code(404);
        exit('Not found');
    }
    $qdir = rsnext_quarantine_root() . '\\' . str_replace('/', '\\', (string)$row['quarantine_rel']);
    $files = is_dir($qdir) ? (glob($qdir . '\\*') ?: []) : [];
    $target = '';
    foreach ($files as $f) {
        if (is_file($f)) {
            $target = $f;
            break;
        }
    }
    if ($target === '') {
        http_response_code(404);
        exit('Empty quarantine folder');
    }

    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg'], true)) {
        header('Content-Type: image/jpeg');
        readfile($target);
        exit;
    }

    // DICOM 等は dcm_render.py でオンデマンドサムネイル変換
    $tmp_jpg = dirname(__DIR__) . '\\rsnext\\tmp\\qprev_' . $qid . '.jpg';
    if (!is_dir(dirname($tmp_jpg))) @mkdir(dirname($tmp_jpg), 0777, true);
    if (!is_file($tmp_jpg)) {
        $py = rsnext_find_python();
        $script = __DIR__ . '/dcm_render.py';
        $cmd = escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($target) . ' ' . escapeshellarg($tmp_jpg) . ' thumb 2>&1';
        exec($cmd, $o, $ret);
    }
    if (is_file($tmp_jpg) && filesize($tmp_jpg) > 100) {
        header('Content-Type: image/jpeg');
        readfile($tmp_jpg);
        exit;
    }

    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="220" height="150" viewBox="0 0 220 150"><rect fill="#1e293b" width="100%" height="100%" rx="8"/><text fill="#cbd5e1" x="50%" y="52%" text-anchor="middle" font-family="sans-serif" font-size="13">' . rsnext_h(strtoupper($ext ?: 'DATA')) . ' データ</text></svg>';
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

$rawBody = file_get_contents('php://input');
$jsonBody = ($rawBody && empty($_POST) && empty($_FILES)) ? (@json_decode($rawBody, true) ?: []) : [];
$req = array_merge($_GET, $_POST, $jsonBody);
$action = (string)($req['action'] ?? $action);

try {
    $pdo = rsnext_pdo();
    $cfg = rsnext_config();

    // ① Modality Studio 一覧 & 未登録検出デバイス取得 (list_studio)
    if ($action === 'list_studio') {
        $profiles = $pdo->query("SELECT * FROM modality_profiles ORDER BY priority_order ASC, profile_id ASC")->fetchAll();
        foreach ($profiles as &$p) {
            $p['match_rules'] = is_string($p['match_rules']) ? (json_decode($p['match_rules'], true) ?: []) : $p['match_rules'];
            $p['viewer_options'] = is_string($p['viewer_options']) ? (json_decode($p['viewer_options'], true) ?: []) : $p['viewer_options'];
        }
        unset($p);

        $discovered = $pdo->query(
            "SELECT d.*, mp.device_name AS linked_device_name
             FROM discovered_devices d
             LEFT JOIN modality_profiles mp ON mp.profile_id = d.linked_profile_id
             WHERE NOT d.is_dismissed
             ORDER BY (d.linked_profile_id IS NULL) DESC, d.last_seen_at DESC
             LIMIT 50"
        )->fetchAll();

        echo json_encode([
            'status'     => 'success',
            'profiles'   => $profiles,
            'discovered' => $discovered,
            'ingest_cfg' => $cfg['ingest'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ② プロファイル新規作成・更新 (save_profile)
    if ($action === 'save_profile') {
        $profileId       = (int)($req['profile_id'] ?? 0);
        $profileCode     = strtoupper(trim((string)preg_replace('/[^A-Za-z0-9_]/', '_', (string)($req['profile_code'] ?? ''))));
        $deviceName      = trim((string)($req['device_name'] ?? ''));
        $room            = trim((string)($req['room_or_location'] ?? ''));
        $protocol        = trim((string)($req['protocol'] ?? 'DICOM_SCP'));
        $modalityCode    = strtoupper(trim((string)($req['modality_code'] ?? 'OT')));
        $displayCat      = trim((string)($req['display_category'] ?? 'other'));
        $defaultKensamei = trim((string)($req['default_kensamei'] ?? '検査画像'));
        $strategy        = trim((string)($req['kensamei_from_tag'] ?? 'FIXED'));
        $iconEmoji       = trim((string)($req['icon_emoji'] ?? '🏥')) ?: '🏥';
        $badgeColor      = trim((string)($req['badge_color'] ?? '#2563eb')) ?: '#2563eb';
        $priorityOrder   = (int)($req['priority_order'] ?? 100);
        $unitCount       = max(1, (int)($req['unit_count'] ?? 1));
        $isEnabled       = isset($req['is_enabled']) ? (bool)$req['is_enabled'] : true;
        $notes           = trim((string)($req['notes'] ?? ''));
        $matchRules      = is_array($req['match_rules'] ?? null) ? $req['match_rules'] : [];
        $viewerOptions   = is_array($req['viewer_options'] ?? null) ? $req['viewer_options'] : [];

        if ($deviceName === '' || $defaultKensamei === '') {
            throw new RuntimeException('装置名と既定の検査名は必須です');
        }
        if ($profileCode === '') {
            $profileCode = 'MOD_' . date('YmdHis');
        }

        // 空値のルールキーは除去してクリーンに保存
        $cleanRules = [];
        foreach ($matchRules as $k => $v) {
            if ($v !== '' && $v !== null && $v !== 0 && $v !== '0') {
                $cleanRules[$k] = $v;
            }
        }

        if ($profileId > 0) {
            $stmt = $pdo->prepare(
                "UPDATE modality_profiles
                 SET profile_code = ?, device_name = ?, room_or_location = ?, protocol = ?,
                     modality_code = ?, display_category = ?, default_kensamei = ?, kensamei_from_tag = ?,
                     icon_emoji = ?, badge_color = ?, priority_order = ?, is_enabled = ?, unit_count = ?,
                     match_rules = ?::jsonb, viewer_options = ?::jsonb, notes = ?, updated_at = NOW()
                 WHERE profile_id = ?"
            );
            $stmt->execute([
                $profileCode, $deviceName, $room, $protocol,
                $modalityCode, $displayCat, $defaultKensamei, $strategy,
                $iconEmoji, $badgeColor, $priorityOrder, $isEnabled ? 'true' : 'false', $unitCount,
                json_encode($cleanRules, JSON_UNESCAPED_UNICODE),
                json_encode($viewerOptions, JSON_UNESCAPED_UNICODE),
                $notes, $profileId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO modality_profiles
                 (profile_code, device_name, room_or_location, protocol, modality_code, display_category,
                  default_kensamei, kensamei_from_tag, icon_emoji, badge_color, priority_order, is_enabled,
                  unit_count, match_rules, viewer_options, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?)
                 RETURNING profile_id"
            );
            $stmt->execute([
                $profileCode, $deviceName, $room, $protocol, $modalityCode, $displayCat,
                $defaultKensamei, $strategy, $iconEmoji, $badgeColor, $priorityOrder, $isEnabled ? 'true' : 'false',
                $unitCount,
                json_encode($cleanRules, JSON_UNESCAPED_UNICODE),
                json_encode($viewerOptions, JSON_UNESCAPED_UNICODE),
                $notes,
            ]);
            $profileId = (int)$stmt->fetchColumn();
        }

        // 自動検出レーダーからの1クリック昇格時は discovery_id を紐付け
        $discoveryId = (int)($req['discovery_id'] ?? 0);
        if ($discoveryId > 0) {
            $pdo->prepare("UPDATE discovered_devices SET linked_profile_id = ? WHERE discovery_id = ?")->execute([$profileId, $discoveryId]);
        }

        rsnext_log_event($pdo, 'INFO', 'MODALITY_STUDIO', 'PROFILE_SAVED', "モダリティプロファイル「{$deviceName}」({$profileCode}) を保存しました");
        echo json_encode(['status' => 'success', 'profile_id' => $profileId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ③ プロファイル有効/無効トグル & 削除 & 検出通知非表示
    if ($action === 'toggle_profile') {
        $pid = (int)($req['profile_id'] ?? 0);
        $en  = !empty($req['is_enabled']);
        $pdo->prepare("UPDATE modality_profiles SET is_enabled = ?, updated_at = NOW() WHERE profile_id = ?")->execute([$en ? 'true' : 'false', $pid]);
        echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete_profile') {
        $pid = (int)($req['profile_id'] ?? 0);
        $pdo->prepare("DELETE FROM modality_profiles WHERE profile_id = ? AND profile_code <> 'GENERIC_EXTERNAL_MEDIA'")->execute([$pid]);
        echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'dismiss_discovered') {
        $did = (int)($req['discovery_id'] ?? 0);
        $pdo->prepare("UPDATE discovered_devices SET is_dismissed = TRUE WHERE discovery_id = ?")->execute([$did]);
        echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ④ Modality Studio D&D 振分シミュレータ (simulate_drop)
    if ($action === 'simulate_drop') {
        if (empty($_FILES['sample_file']['tmp_name']) || !is_uploaded_file($_FILES['sample_file']['tmp_name'])) {
            throw new RuntimeException('シミュレーション対象のファイルを選択またはドロップしてください');
        }
        $origName = (string)($_FILES['sample_file']['name'] ?? 'sample.dcm');
        $tmpDir = __DIR__ . '/tmp/sim_drop';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

        $ext = pathinfo($origName, PATHINFO_EXTENSION);
        $tmpFile = $tmpDir . '/sim_' . uniqid('', true) . ($ext ? '.' . $ext : '');
        move_uploaded_file($_FILES['sample_file']['tmp_name'], $tmpFile);

        $metaFile = $tmpFile . '.meta.json';
        file_put_contents($metaFile, json_encode([
            'orig_filename'    => $origName,
            'source_protocol'  => (string)($req['source_protocol'] ?? 'SIMULATOR'),
            'source_ip'        => (string)($req['source_ip'] ?? ''),
            'calling_ae_title' => (string)($req['calling_ae_title'] ?? ''),
            'watch_subfolder'  => (string)($req['watch_subfolder'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));

        $py = rsnext_find_python();
        $cmd = escapeshellarg($py) . ' -m ingest_service.study_filer simulate ' . escapeshellarg($tmpFile) . ' --meta-json ' . escapeshellarg($metaFile) . ' 2>&1';
        $cwd = getcwd();
        chdir(__DIR__);
        exec($cmd, $outLines, $ret);
        if ($cwd) chdir($cwd);

        @unlink($tmpFile);
        @unlink($metaFile);

        $decoded = @json_decode(implode("\n", $outLines), true);
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            throw new RuntimeException('シミュレーション解析エラー: ' . implode(' ', $outLines));
        }
        echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑤-A 紹介CD (DICOMDIR + サンプルスライス) 高速事前解析 (inspect_cd)
    if ($action === 'inspect_cd') {
        $tmpDir = __DIR__ . '/tmp/cd_inspect_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        @mkdir($tmpDir, 0777, true);

        $dicomdirSaved = '';
        if (!empty($_FILES['dicomdir']['tmp_name']) && is_uploaded_file($_FILES['dicomdir']['tmp_name'])) {
            $dicomdirSaved = $tmpDir . '/DICOMDIR';
            move_uploaded_file($_FILES['dicomdir']['tmp_name'], $dicomdirSaved);
        }

        $samplesPayload = [];
        if (!empty($_FILES['samples']['tmp_name'])) {
            $sNames = (array)$_FILES['samples']['name'];
            $sTmps  = (array)$_FILES['samples']['tmp_name'];
            $sErrs  = (array)$_FILES['samples']['error'];
            $sRels  = (array)($_POST['sample_rel_paths'] ?? []);
            foreach ($sTmps as $idx => $sTmp) {
                if (($sErrs[$idx] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($sTmp)) {
                    continue;
                }
                $origN = basename((string)($sNames[$idx] ?? "sample_{$idx}"));
                $ext = pathinfo($origN, PATHINFO_EXTENSION);
                $dst = $tmpDir . '/smp_' . sprintf('%03d', $idx) . ($ext ? '.' . $ext : '');
                if (move_uploaded_file($sTmp, $dst)) {
                    $samplesPayload[] = [
                        'path'          => $dst,
                        'rel_path'      => (string)($sRels[$idx] ?? $origN),
                        'orig_filename' => $origN,
                    ];
                }
            }
        }

        $inJson  = $tmpDir . '/_in.json';
        $outJson = $tmpDir . '/_out.json';
        file_put_contents($inJson, json_encode([
            'dicomdir_path' => $dicomdirSaved,
            'sample_files'  => $samplesPayload,
        ], JSON_UNESCAPED_UNICODE));

        $py = rsnext_find_python();
        $helper = __DIR__ . '/dcm_volume_helper.py';
        $cmd = escapeshellarg($py) . ' ' . escapeshellarg($helper) . ' inspect_cd ' . escapeshellarg($inJson) . ' ' . escapeshellarg($outJson) . ' 2>&1';
        exec($cmd, $outLines, $ret);

        $resData = is_file($outJson) ? (@json_decode((string)@file_get_contents($outJson), true) ?: null) : null;
        foreach (glob($tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);

        if (!is_array($resData) || ($resData['status'] ?? '') !== 'success') {
            throw new RuntimeException('CD解析エラー: ' . implode(' ', $outLines));
        }
        echo json_encode($resData, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑤-B 大容量動画・メディアファイルのチャンク分割アップロード取込 (upload_chunk)
    if ($action === 'upload_chunk') {
        @set_time_limit(300);
        $rawPid = (string)($req['patient_id'] ?? '');
        $normPid = rsnext_normalize_patient_id($rawPid);
        if ($normPid === null) {
            throw new RuntimeException('取込先の有効な患者IDを指定してください');
        }
        $uploadId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($req['upload_id'] ?? ''));
        $chunkIdx = (int)($req['chunk_index'] ?? 0);
        $totalChunks = max(1, (int)($req['total_chunks'] ?? 1));
        $origName = basename((string)($req['orig_filename'] ?? 'video.mp4'));
        if ($uploadId === '' || empty($_FILES['chunk']['tmp_name']) || !is_uploaded_file($_FILES['chunk']['tmp_name'])) {
            throw new RuntimeException('チャンクデータが不正です');
        }

        $chunkDir = __DIR__ . '/storage_data/inbox/drop/chunks_' . $uploadId;
        if (!is_dir($chunkDir)) @mkdir($chunkDir, 0777, true);
        $partFile = $chunkDir . '/assembled.part';

        $inStream = fopen($_FILES['chunk']['tmp_name'], 'rb');
        $outStream = fopen($partFile, $chunkIdx === 0 ? 'wb' : 'ab');
        if (!$inStream || !$outStream) {
            throw new RuntimeException('一時ファイルの書き込みに失敗しました');
        }
        stream_copy_to_stream($inStream, $outStream);
        fclose($inStream);
        fclose($outStream);

        if ($chunkIdx < $totalChunks - 1) {
            echo json_encode([
                'status'       => 'chunk_ok',
                'chunk_index'  => $chunkIdx,
                'total_chunks' => $totalChunks,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 最終チャンク到達 -> 実拡張子でリネームして study_filer へ投入
        $ext = pathinfo($origName, PATHINFO_EXTENSION);
        $finalTmp = $chunkDir . '/0001' . ($ext ? '.' . $ext : '.mp4');
        @rename($partFile, $finalTmp);

        $studyOverrides = [];
        if (!empty($req['study_overrides'])) {
            $parsedOv = is_array($req['study_overrides']) ? $req['study_overrides'] : @json_decode((string)$req['study_overrides'], true);
            if (is_array($parsedOv)) $studyOverrides = $parsedOv;
        }

        $manifestPath = $chunkDir . '/_manifest.json';
        file_put_contents($manifestPath, json_encode([
            'patient_id'              => $normPid,
            'kensamei'                => trim((string)($req['kensamei'] ?? '')),
            'modality'                => trim((string)($req['modality'] ?? '')),
            'study_date'              => trim((string)($req['study_date'] ?? '')),
            'force_study_date'        => !empty($req['force_study_date']),
            'doctor'                  => trim((string)($req['doctor'] ?? '')),
            'findings'                => trim((string)($req['findings'] ?? '')),
            'group_single_study'      => !empty($req['group_single_study']),
            'force_non_dicom_study_id'=> !empty($req['force_non_dicom_study_id']) ? (int)$req['force_non_dicom_study_id'] : null,
            'study_overrides'         => $studyOverrides,
            'source_protocol'         => 'HTTP_API',
            'source_ip'               => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'remove_source'           => true,
            'files'                   => [['path' => $finalTmp, 'orig_filename' => $origName]],
        ], JSON_UNESCAPED_UNICODE));

        $py = rsnext_find_python();
        $cmd = escapeshellarg($py) . ' -m ingest_service.study_filer batch_import ' . escapeshellarg($manifestPath) . ' 2>&1';
        $cwd = getcwd();
        chdir(__DIR__);
        exec($cmd, $outLines, $ret);
        if ($cwd) chdir($cwd);

        foreach (glob($chunkDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($chunkDir);

        $decoded = @json_decode(implode("\n", $outLines), true);
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            throw new RuntimeException('動画取込処理中にエラーが発生しました: ' . implode(' ', $outLines));
        }

        $okCount = 0;
        $dupCount = 0;
        $lastStudyId = null;
        foreach ($decoded['results'] ?? [] as $r) {
            if (($r['status'] ?? '') === 'success') {
                $okCount++;
                $lastStudyId = $r['study_id'] ?? $lastStudyId;
            } elseif (($r['status'] ?? '') === 'duplicate') {
                $dupCount++;
                $lastStudyId = $r['study_id'] ?? $lastStudyId;
            }
        }

        echo json_encode([
            'status'             => 'success',
            'imported_count'     => $okCount,
            'duplicate_count'    => $dupCount,
            'study_id'           => $lastStudyId,
            'study_ids'          => $decoded['study_ids'] ?? ($lastStudyId ? [$lastStudyId] : []),
            'non_dicom_study_id' => $decoded['non_dicom_study_id'] ?? null,
            'patient_id'         => $normPid,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑤ 他院紹介CD・動画・デジカメ写真・PDFレポート ドラッグ＆ドロップ一括取込 (import_files)
    if ($action === 'import_files') {
        @set_time_limit(300);
        $rawPid = (string)($req['patient_id'] ?? '');
        $normPid = rsnext_normalize_patient_id($rawPid);
        if ($normPid === null) {
            throw new RuntimeException('取込先の有効な患者IDを指定してください');
        }
        if (empty($_FILES['files']['tmp_name'])) {
            throw new RuntimeException('取り込むファイルが選択されていません');
        }

        $dropDir = __DIR__ . '/storage_data/inbox/drop/batch_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        @mkdir($dropDir, 0777, true);

        $names    = (array)$_FILES['files']['name'];
        $tmps     = (array)$_FILES['files']['tmp_name'];
        $errs     = (array)$_FILES['files']['error'];
        $relPaths = (array)($_POST['rel_paths'] ?? []);

        $manifestFiles = [];
        foreach ($tmps as $idx => $tmpPath) {
            if (($errs[$idx] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmpPath)) {
                continue;
            }
            $origName = basename((string)($names[$idx] ?? "file_{$idx}"));
            $relLabel = trim((string)($relPaths[$idx] ?? $origName));
            $lowBase  = strtolower($origName);
            if ($lowBase === 'dicomdir' || $lowBase === 'autorun.inf' || str_ends_with($lowBase, '.html') || str_ends_with($lowBase, '.htm') || str_ends_with($lowBase, '.js') || str_ends_with($lowBase, '.exe') || str_ends_with($lowBase, '.dll')) {
                continue;
            }
            $ext = pathinfo($origName, PATHINFO_EXTENSION);
            $saved = $dropDir . '/' . sprintf('%05d', $idx + 1) . ($ext ? '.' . $ext : '');
            if (move_uploaded_file($tmpPath, $saved)) {
                $manifestFiles[] = [
                    'path'          => $saved,
                    'orig_filename' => $relLabel !== '' ? str_replace('\\', '/', $relLabel) : $origName,
                ];
            }
        }

        if (empty($manifestFiles)) {
            @rmdir($dropDir);
            throw new RuntimeException('取り込み対象となるDICOM・動画・画像・PDFファイルが見つかりませんでした');
        }

        $studyOverrides = [];
        if (!empty($req['study_overrides'])) {
            $parsedOv = is_array($req['study_overrides']) ? $req['study_overrides'] : @json_decode((string)$req['study_overrides'], true);
            if (is_array($parsedOv)) $studyOverrides = $parsedOv;
        }

        $manifestPath = $dropDir . '/_manifest.json';
        file_put_contents($manifestPath, json_encode([
            'patient_id'               => $normPid,
            'kensamei'                 => trim((string)($req['kensamei'] ?? '')),
            'modality'                 => trim((string)($req['modality'] ?? '')),
            'study_date'               => trim((string)($req['study_date'] ?? '')),
            'force_study_date'         => !empty($req['force_study_date']),
            'doctor'                   => trim((string)($req['doctor'] ?? '')),
            'findings'                 => trim((string)($req['findings'] ?? '')),
            'group_single_study'       => !empty($req['group_single_study']),
            'force_non_dicom_study_id' => !empty($req['force_non_dicom_study_id']) ? (int)$req['force_non_dicom_study_id'] : null,
            'study_overrides'          => $studyOverrides,
            'source_protocol'          => 'HTTP_API',
            'source_ip'                => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'remove_source'            => true,
            'files'                    => $manifestFiles,
        ], JSON_UNESCAPED_UNICODE));

        $py = rsnext_find_python();
        $cmd = escapeshellarg($py) . ' -m ingest_service.study_filer batch_import ' . escapeshellarg($manifestPath) . ' 2>&1';
        $cwd = getcwd();
        chdir(__DIR__);
        exec($cmd, $outLines, $ret);
        if ($cwd) chdir($cwd);

        foreach (glob($dropDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dropDir);

        $decoded = @json_decode(implode("\n", $outLines), true);
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            throw new RuntimeException('取込処理中にエラーが発生しました: ' . implode(' ', $outLines));
        }

        $okCount = 0;
        $dupCount = 0;
        $lastStudyId = null;
        foreach ($decoded['results'] ?? [] as $r) {
            if (($r['status'] ?? '') === 'success') {
                $okCount++;
                $lastStudyId = $r['study_id'] ?? $lastStudyId;
            } elseif (($r['status'] ?? '') === 'duplicate') {
                $dupCount++;
                $lastStudyId = $r['study_id'] ?? $lastStudyId;
            }
        }

        echo json_encode([
            'status'             => 'success',
            'imported_count'     => $okCount,
            'duplicate_count'    => $dupCount,
            'study_id'           => $lastStudyId,
            'study_ids'          => $decoded['study_ids'] ?? ($lastStudyId ? [$lastStudyId] : []),
            'non_dicom_study_id' => $decoded['non_dicom_study_id'] ?? null,
            'patient_id'         => $normPid,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑥ 受信モニター・ストレージ残量・隔離キュー状態取得 (dashboard_status)
    if ($action === 'dashboard_status') {
        $hbFile = __DIR__ . '/tmp/ingest_service_status.json';
        $hb = is_file($hbFile) ? (@json_decode((string)@file_get_contents($hbFile), true) ?: null) : null;
        if ($hb && !empty($hb['updated_at'])) {
            $ageSec = time() - (strtotime((string)$hb['updated_at']) ?: 0);
            $hb['is_alive'] = ($hb['state'] ?? '') === 'RUNNING' && $ageSec < 20;
            $hb['age_sec'] = $ageSec;
        }

        $volumesInfo = [];
        foreach (($cfg['storage']['volumes'] ?? []) as $vid => $vcfg) {
            $p = (string)($vcfg['path'] ?? '');
            $free = is_dir($p) ? (@disk_free_space($p) ?: 0) : 0;
            $total = is_dir($p) ? (@disk_total_space($p) ?: 0) : 0;
            $cntStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(total_bytes), 0) AS bytes FROM studies WHERE volume_id = ? AND NOT is_deleted");
            $cntStmt->execute([$vid]);
            $vStat = $cntStmt->fetch();
            $volumesInfo[] = [
                'volume_id'    => $vid,
                'label'        => $vcfg['label'] ?? $vid,
                'path'         => $p,
                'mirror_path'  => $vcfg['mirror_path'] ?? '',
                'study_count'  => (int)($vStat['cnt'] ?? 0),
                'stored_bytes' => (int)($vStat['bytes'] ?? 0),
                'disk_free'    => $free,
                'disk_total'   => $total,
            ];
        }

        $quarantine = $pdo->query(
            "SELECT * FROM quarantine_queue WHERE status = 'PENDING' ORDER BY received_at DESC LIMIT 100"
        )->fetchAll();

        $logs = $pdo->query(
            "SELECT * FROM ingest_logs ORDER BY log_id DESC LIMIT 80"
        )->fetchAll();

        $totals = [
            'patients'   => (int)$pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn(),
            'studies'    => (int)$pdo->query("SELECT COUNT(*) FROM studies WHERE NOT is_deleted")->fetchColumn(),
            'files'      => (int)$pdo->query("SELECT COUNT(*) FROM study_files")->fetchColumn(),
            'quarantine' => count($quarantine),
        ];

        $wmFile = __DIR__ . '/tmp/mall3_sql_watermark.json';
        $mall3Wm = is_file($wmFile) ? (@json_decode((string)@file_get_contents($wmFile), true) ?: []) : [];

        // 最新の server_config.json から各カルテサーバの設定 (パスワード除外) を取得
        $liveCfgRaw = @json_decode((string)@file_get_contents(__DIR__ . '/config/server_config.json'), true) ?: $cfg;
        $m3CfgRaw = $liveCfgRaw['ingest']['mall3_sql'] ?? [];
        $defaultIv = (int)($m3CfgRaw['poll_interval_sec'] ?? 60);
        $masterEnabled = isset($m3CfgRaw['enabled']) ? (bool)$m3CfgRaw['enabled'] : true;
        $mall3Servers = [];
        foreach (($m3CfgRaw['sources'] ?? []) as $src) {
            $code = (string)($src['code'] ?? '');
            if ($code === '') continue;
            $wmEntry = $mall3Wm['sources'][$code] ?? [];
            $srcEn = isset($src['enabled']) ? (bool)$src['enabled'] : true;
            $srcIv = max(5, (int)($src['poll_interval_sec'] ?? $defaultIv));
            $mall3Servers[] = [
                'code'              => $code,
                'label'             => (string)($src['label'] ?? $code),
                'host'              => (string)($src['host'] ?? ''),
                'prefix_nine'       => !empty($src['prefix_nine']),
                'enabled'           => $srcEn,
                'effective_active'  => $masterEnabled && $srcEn,
                'poll_interval_sec' => $srcIv,
                'last_watermark'    => (string)($wmEntry['last_watermark'] ?? ''),
                'last_sync_at'      => (string)($wmEntry['last_sync_at'] ?? ''),
                'next_poll_at'      => ($masterEnabled && $srcEn) ? (string)($wmEntry['next_poll_at'] ?? '') : '',
                'last_scanned'      => (int)($wmEntry['last_scanned'] ?? 0),
                'last_changed'      => (int)($wmEntry['last_changed'] ?? 0),
                'last_elapsed_ms'   => (int)($wmEntry['last_elapsed_ms'] ?? 0),
                'last_error'        => (string)($wmEntry['last_error'] ?? ''),
            ];
        }

        $migFile = __DIR__ . '/tmp/migration_progress.json';
        $migProg = is_file($migFile) ? (@json_decode((string)@file_get_contents($migFile), true) ?: null) : null;
        if ($migProg && ($migProg['state'] ?? '') === 'RUNNING' && !empty($migProg['updated_at'])) {
            $migAge = time() - (strtotime((string)$migProg['updated_at']) ?: 0);
            if ($migAge > 180) {
                $migProg['state'] = 'IDLE';
            }
        }

        echo json_encode([
            'status'             => 'success',
            'heartbeat'          => $hb,
            'volumes'            => $volumesInfo,
            'totals'             => $totals,
            'mall3_master_on'    => $masterEnabled,
            'mall3_servers'      => $mall3Servers,
            'mall3_watermarks'   => $mall3Wm,
            'migration_progress' => $migProg,
            'quarantine'         => $quarantine,
            'logs'               => $logs,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑥-a MALL3 カルテサーバ別の巡回秒数・巡回開始/停止のリアルタイム更新 (update_mall3_poller)
    if ($action === 'update_mall3_poller') {
        $cfgFile = __DIR__ . '/config/server_config.json';
        $fullCfg = @json_decode((string)@file_get_contents($cfgFile), true);
        if (!is_array($fullCfg)) {
            throw new RuntimeException('server_config.json の読み込みに失敗しました');
        }
        if (!isset($fullCfg['ingest']['mall3_sql']) || !is_array($fullCfg['ingest']['mall3_sql'])) {
            throw new RuntimeException('mall3_sql 設定ブロックが存在しません');
        }

        $summaryParts = [];
        if (array_key_exists('master_enabled', $req)) {
            $mOn = (bool)$req['master_enabled'];
            $fullCfg['ingest']['mall3_sql']['enabled'] = $mOn;
            $summaryParts[] = '全体巡回: ' . ($mOn ? '有効 (開始)' : '一時停止');
        }

        $targetCode = trim((string)($req['source_code'] ?? ''));
        if ($targetCode !== '' && !empty($fullCfg['ingest']['mall3_sql']['sources'])) {
            $found = false;
            foreach ($fullCfg['ingest']['mall3_sql']['sources'] as &$srcRef) {
                if (($srcRef['code'] ?? '') === $targetCode) {
                    $found = true;
                    $lbl = (string)($srcRef['label'] ?? $targetCode);
                    if (array_key_exists('enabled', $req)) {
                        $sOn = (bool)$req['enabled'];
                        $srcRef['enabled'] = $sOn;
                        // 個別サーバを「開始」にした際、全体スイッチがOFFなら自動でONへ復帰させる
                        if ($sOn && empty($fullCfg['ingest']['mall3_sql']['enabled'])) {
                            $fullCfg['ingest']['mall3_sql']['enabled'] = true;
                        }
                        $summaryParts[] = "{$lbl}: " . ($sOn ? '巡回開始' : '巡回停止');
                    }
                    if (array_key_exists('poll_interval_sec', $req)) {
                        $iv = (int)$req['poll_interval_sec'];
                        if ($iv < 5 || $iv > 86400) {
                            throw new RuntimeException('巡回秒数は 5秒 〜 86400秒 (24時間) の範囲で指定してください');
                        }
                        $srcRef['poll_interval_sec'] = $iv;
                        $summaryParts[] = "{$lbl}: 巡回間隔 {$iv}秒";
                    }
                    break;
                }
            }
            unset($srcRef);
            if (!$found) {
                throw new RuntimeException("対象カルテサーバ ({$targetCode}) が見つかりません");
            }
        }

        $jsonOut = json_encode($fullCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!rsnext_atomic_write_file($cfgFile, $jsonOut)) {
            throw new RuntimeException('server_config.json の保存に失敗しました');
        }

        $msg = implode(' ／ ', $summaryParts) ?: 'MALL3巡回設定を更新しました';
        rsnext_log_event($pdo, 'INFO', 'WEB_ADMIN', 'MALL3_CFG_UPDATE', "MALL3巡回設定変更: {$msg}");

        echo json_encode(['status' => 'success', 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑥-b MALL3 患者マスタ 今すぐ手動増分同期 (sync_mall3_now: 全件または単体サーバ指定)
    if ($action === 'sync_mall3_now') {
        $srcCode = preg_replace('/[^A-Za-z0-9_]/', '', trim((string)($req['source_code'] ?? '')));
        $py = rsnext_find_python();
        $cmd = escapeshellarg($py) . ' -m ingest_service.mall3_sql_poller'
             . ($srcCode !== '' ? (' --source ' . escapeshellarg($srcCode)) : '')
             . ' 2>&1';
        $cwd = getcwd();
        chdir(__DIR__);
        exec($cmd, $outLines, $ret);
        if ($cwd) chdir($cwd);
        $decoded = @json_decode(implode("\n", $outLines), true);
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            throw new RuntimeException('MALL3同期エラー: ' . implode(' ', $outLines));
        }
        echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑥-c 旧RS-Base バックグラウンド移行の開始 / 一時停止 / 差分同期 (control_migration)
    if ($action === 'control_migration') {
        $cmdType = (string)($req['cmd'] ?? 'start_full');
        $tmpDir = __DIR__ . '/tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
        $stopFlag = $tmpDir . '/migration_stop.flag';
        $progFile = $tmpDir . '/migration_progress.json';

        if ($cmdType === 'stop') {
            @file_put_contents($stopFlag, date('c'));
            echo json_encode(['status' => 'success', 'message' => '現在の検査コピー完了後に安全停止します...'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (is_file($progFile)) {
            $curP = @json_decode((string)@file_get_contents($progFile), true) ?: [];
            $age = !empty($curP['updated_at']) ? (time() - (strtotime((string)$curP['updated_at']) ?: 0)) : 9999;
            if (($curP['state'] ?? '') === 'RUNNING' && $age < 180) {
                throw new RuntimeException('すでにバックグラウンド移行ジョブが実行中です');
            }
        }
        if (is_file($stopFlag)) @unlink($stopFlag);

        $py = rsnext_find_python();
        $script = __DIR__ . '\\migration\\migrate_from_rsbase.py';
        $logOut = $tmpDir . '\\bg_migration.log';
        $flags = ($cmdType === 'delta')
            ? '--delta --thumbs --skip-patients'
            : '--recent --thumbs --skip-patients';

        $bgCmd = 'cmd.exe /c start "" /B ' . escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . $flags . ' > ' . escapeshellarg($logOut) . ' 2>&1';
        pclose(popen($bgCmd, 'r'));

        rsnext_log_event($pdo, 'INFO', 'WEB_ADMIN', 'BG_MIGRATION_START', "Web画面からバックグラウンドデータ移行 ({$cmdType}) を開始しました");
        echo json_encode(['status' => 'success', 'message' => 'バックグラウンド移行を開始しました'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑦ 隔離データの患者紐付け救済 (rescue_quarantine) & 破棄 (discard_quarantine)
    if ($action === 'rescue_quarantine') {
        $qid = (int)($req['quarantine_id'] ?? 0);
        $pid = trim((string)($req['patient_id'] ?? ''));
        $kensa = trim((string)($req['kensamei'] ?? ''));
        $doc = trim((string)($req['doctor'] ?? ''));
        if ($qid <= 0 || $pid === '') {
            throw new RuntimeException('隔離IDと救済先の患者IDを指定してください');
        }

        $py = rsnext_find_python();
        $cmd = escapeshellarg($py) . ' -m ingest_service.study_filer rescue '
             . escapeshellarg((string)$qid) . ' ' . escapeshellarg($pid) . ' '
             . escapeshellarg($kensa) . ' ' . escapeshellarg($doc) . ' 2>&1';
        $cwd = getcwd();
        chdir(__DIR__);
        exec($cmd, $outLines, $ret);
        if ($cwd) chdir($cwd);

        $decoded = @json_decode(implode("\n", $outLines), true);
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            throw new RuntimeException($decoded['message'] ?? ('救済エラー: ' . implode(' ', $outLines)));
        }
        echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'discard_quarantine') {
        $qid = (int)($req['quarantine_id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM quarantine_queue WHERE quarantine_id = ?");
        $st->execute([$qid]);
        $q = $st->fetch();
        if ($q) {
            $qdir = rsnext_quarantine_root() . '\\' . str_replace('/', '\\', (string)$q['quarantine_rel']);
            if (is_dir($qdir)) {
                foreach (glob($qdir . '\\*') ?: [] as $f) {
                    if (is_file($f)) @unlink($f);
                }
                @rmdir($qdir);
            }
            $pdo->prepare("UPDATE quarantine_queue SET status = 'DISCARDED', resolved_at = NOW() WHERE quarantine_id = ?")->execute([$qid]);
            rsnext_log_event($pdo, 'WARN', 'WEB_ADMIN', 'QUARANTINE_DISCARDED', "隔離データ #{$qid} を不要データとして破棄しました");
        }
        echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('未対応のアクションです: ' . $action);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

