<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next 撮影依頼・DICOM MWL ワークリスト管理 API (worklist_api.php)
// - ワンクリック複数検査一括オーダー発行 (ISO標準 2.25 UUID StudyInstanceUID 自動採番)
// - 検査室ボード用リアルタイム一覧・ステータス更新・CSV Outbox 同期
// - 実パケット DICOM C-FIND (MWL SCU -> SCP) 疎通テストブリッジ
// ==============================================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

$rawBody = file_get_contents('php://input');
$jsonBody = ($rawBody && empty($_POST)) ? (@json_decode($rawBody, true) ?: []) : [];
$req = array_merge($_GET, $_POST, $jsonBody);
$action = (string)($req['action'] ?? '');

/**
 * DICOM PS3.5 B.2 準拠のグローバル一意 StudyInstanceUID (2.25.<128bit整数>, 最大44文字) を生成
 */
function rsnext_generate_dicom_uid(): string {
    $bytes = random_bytes(16);
    // 16バイトを10進数文字列へ変換 (BCMath不要の純PHP実装)
    $dec = '0';
    for ($i = 0; $i < 16; $i++) {
        $byte = ord($bytes[$i]);
        // dec = dec * 256 + byte
        $carry = $byte;
        $out = '';
        for ($j = strlen($dec) - 1; $j >= 0; $j--) {
            $val = ((int)$dec[$j]) * 256 + $carry;
            $out = ($val % 10) . $out;
            $carry = intdiv($val, 10);
        }
        while ($carry > 0) {
            $out = ($carry % 10) . $out;
            $carry = intdiv($carry, 10);
        }
        $dec = ltrim($out, '0') ?: '0';
    }
    return '2.25.' . $dec;
}

function rsnext_trigger_orders_csv_export(): void {
    $py = rsnext_find_python();
    $cwd = getcwd();
    chdir(__DIR__);
    @exec(escapeshellarg($py) . ' -m ingest_service.mwl_scp export_csv 2>&1');
    if ($cwd) chdir($cwd);
}

try {
    $pdo = rsnext_pdo();

    // ① ワークリスト画面初期データ＆リアルタイムポーリング取得 (list_worklist)
    if ($action === 'list_worklist') {
        $schedDate = trim((string)($req['date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedDate)) {
            $schedDate = date('Y-m-d');
        }
        $roomGroup = trim((string)($req['room_group'] ?? 'all'));
        $showCompleted = !isset($req['show_completed']) || !empty($req['show_completed']);

        $presets = $pdo->query("SELECT * FROM order_presets WHERE is_enabled = TRUE ORDER BY sort_order ASC, preset_id ASC")->fetchAll();

        $where = ["o.scheduled_date = ?"];
        $params = [$schedDate];
        if ($roomGroup !== '' && $roomGroup !== 'all') {
            $where[] = "o.room_group = ?";
            $params[] = $roomGroup;
        }
        if (!$showCompleted) {
            $where[] = "o.status IN ('SCHEDULED', 'IN_PROGRESS')";
        } else {
            $where[] = "o.status <> 'CANCELLED'";
        }

        $sql = "SELECT o.*,
                       p.kanja_kanji, p.kanja_yomi, p.birth_date, p.sex,
                       mp.device_name, mp.icon_emoji, mp.badge_color, mp.mwl_config,
                       s.primary_file_type AS linked_file_type
                FROM modality_orders o
                JOIN patients p ON p.patient_id = o.patient_id
                LEFT JOIN modality_profiles mp ON mp.profile_id = o.profile_id
                LEFT JOIN studies s ON s.study_id = o.linked_study_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY
                    CASE o.status
                        WHEN 'IN_PROGRESS' THEN 1
                        WHEN 'SCHEDULED'   THEN 2
                        WHEN 'COMPLETED'   THEN 3
                        ELSE 4
                    END ASC,
                    o.order_id DESC
                LIMIT 300";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $orders = [];
        foreach ($st->fetchAll() as $r) {
            $r['age'] = rsnext_calc_age((string)($r['birth_date'] ?? ''), (string)$r['scheduled_date']);
            $r['birth_wareki'] = rsnext_format_wareki_birth((string)($r['birth_date'] ?? ''));
            $r['mwl_config'] = is_string($r['mwl_config']) ? (json_decode($r['mwl_config'], true) ?: []) : ($r['mwl_config'] ?? []);
            $orders[] = $r;
        }

        // 当日KPI集計
        $kpiSt = $pdo->prepare(
            "SELECT status, COUNT(*) AS cnt
             FROM modality_orders
             WHERE scheduled_date = ?
             GROUP BY status"
        );
        $kpiSt->execute([$schedDate]);
        $kpis = ['SCHEDULED' => 0, 'IN_PROGRESS' => 0, 'COMPLETED' => 0, 'CANCELLED' => 0];
        foreach ($kpiSt->fetchAll() as $kr) {
            $kpis[(string)$kr['status']] = (int)$kr['cnt'];
        }

        // MALL3等で直近登録・更新された来院患者クイックリスト
        $recentPatients = $pdo->query(
            "SELECT patient_id, kanja_kanji, kanja_yomi, birth_date, sex, source_channel
             FROM patients
             ORDER BY updated_at DESC
             LIMIT 10"
        )->fetchAll();
        foreach ($recentPatients as &$rp) {
            $rp['age'] = rsnext_calc_age((string)($rp['birth_date'] ?? ''));
        }
        unset($rp);

        echo json_encode([
            'status'          => 'success',
            'date'            => $schedDate,
            'presets'         => $presets,
            'orders'          => $orders,
            'kpis'            => $kpis,
            'recent_patients' => $recentPatients,
            'dicom_port'      => (int)(rsnext_config()['ingest']['dicom_scp']['port'] ?? 11104),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ② 撮影依頼（1件または複数プリセット同時）発行 (create_orders)
    if ($action === 'create_orders') {
        $rawPid = (string)($req['patient_id'] ?? '');
        $normPid = rsnext_normalize_patient_id($rawPid);
        if ($normPid === null) {
            throw new RuntimeException('有効な数値の患者IDを指定してください');
        }

        $presetCodes = is_array($req['preset_codes'] ?? null) ? $req['preset_codes'] : [];
        $customModality = strtoupper(trim((string)($req['custom_modality'] ?? '')));
        $customKensamei = trim((string)($req['custom_kensamei'] ?? ''));
        if (empty($presetCodes) && $customKensamei === '') {
            throw new RuntimeException('検査プリセットを1つ以上選択するか、検査名を入力してください');
        }

        $schedDate = trim((string)($req['scheduled_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedDate)) {
            $schedDate = date('Y-m-d');
        }
        $schedTime = trim((string)($req['scheduled_time'] ?? date('H:i:s')));
        if (preg_match('/^\d{2}:\d{2}$/', $schedTime)) {
            $schedTime .= ':00';
        } elseif (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $schedTime)) {
            $schedTime = date('H:i:s');
        }

        $doctor  = trim((string)($req['requesting_doctor'] ?? ''));
        $dept    = trim((string)($req['requesting_dept'] ?? ''));
        $comment = trim((string)($req['order_comment'] ?? ''));

        $pdo->beginTransaction();

        // 患者存在チェック（未登録IDなら手動名または仮名で自動作成）
        $pChk = $pdo->prepare("SELECT patient_id, kanja_kanji FROM patients WHERE patient_id = ?");
        $pChk->execute([$normPid]);
        $pat = $pChk->fetch();
        if (!$pat) {
            $fallbackName = trim((string)($req['patient_name_if_new'] ?? '')) ?: "未登録患者({$normPid})";
            $pdo->prepare("INSERT INTO patients (patient_id, kanja_kanji, source_channel) VALUES (?, ?, 'ORDER_AUTO')")
                ->execute([$normPid, $fallbackName]);
        }

        // プロファイル辞書
        $profMap = [];
        foreach ($pdo->query("SELECT profile_id, profile_code, mwl_config FROM modality_profiles")->fetchAll() as $pr) {
            $pr['mwl_config'] = is_string($pr['mwl_config']) ? (json_decode($pr['mwl_config'], true) ?: []) : [];
            $profMap[$pr['profile_code']] = $pr;
        }

        $itemsToInsert = [];
        if (!empty($presetCodes)) {
            $inPlaceholders = implode(',', array_fill(0, count($presetCodes), '?'));
            $prSt = $pdo->prepare("SELECT * FROM order_presets WHERE preset_code IN ({$inPlaceholders}) ORDER BY sort_order ASC");
            $prSt->execute(array_values($presetCodes));
            foreach ($prSt->fetchAll() as $pr) {
                $prof = $profMap[$pr['target_profile']] ?? null;
                $itemsToInsert[] = [
                    'profile_id'     => $prof ? (int)$prof['profile_id'] : null,
                    'modality'       => (string)$pr['modality'],
                    'scheduled_aet'  => $prof ? (string)($prof['mwl_config']['scheduled_aet'] ?? '') : '',
                    'preset_code'    => (string)$pr['preset_code'],
                    'kensamei'       => (string)$pr['kensamei'],
                    'procedure_desc' => (string)$pr['procedure_desc'],
                    'body_part'      => (string)$pr['body_part'],
                    'protocol_code'  => (string)$pr['protocol_code'],
                    'room_group'     => (string)$pr['room_group'],
                ];
            }
        }
        if ($customKensamei !== '') {
            $mod = $customModality !== '' ? $customModality : 'OT';
            $rg = match ($mod) {
                'ES' => 'endoscopy',
                'DX', 'CR', 'RF' => 'xray',
                'US' => 'us',
                'ECG' => 'ecg',
                default => 'other',
            };
            $itemsToInsert[] = [
                'profile_id'     => null,
                'modality'       => $mod,
                'scheduled_aet'  => '',
                'preset_code'    => 'CUSTOM',
                'kensamei'       => $customKensamei,
                'procedure_desc' => $customKensamei,
                'body_part'      => '',
                'protocol_code'  => '',
                'room_group'     => $rg,
            ];
        }

        $createdIds = [];
        $ymdShort = str_replace('-', '', substr($schedDate, 2)); // YYMMDD (6桁)

        $insStmt = $pdo->prepare(
            "INSERT INTO modality_orders
             (accession_number, study_instance_uid, patient_id, profile_id, modality,
              scheduled_aet, scheduled_date, scheduled_time, preset_code, kensamei,
              procedure_desc, body_part, protocol_code, room_group,
              requesting_doctor, requesting_dept, order_comment, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'SCHEDULED')
             RETURNING order_id"
        );

        foreach ($itemsToInsert as $item) {
            $seqVal = (int)$pdo->query("SELECT nextval('modality_orders_order_id_seq')")->fetchColumn();
            $accNo = sprintf('RS%s%06d', $ymdShort, $seqVal % 1000000); // 14文字 (<=16文字 DICOM SH準拠)
            $stUid = rsnext_generate_dicom_uid();

            $insWithId = $pdo->prepare(
                "INSERT INTO modality_orders
                 (order_id, accession_number, study_instance_uid, patient_id, profile_id, modality,
                  scheduled_aet, scheduled_date, scheduled_time, preset_code, kensamei,
                  procedure_desc, body_part, protocol_code, room_group,
                  requesting_doctor, requesting_dept, order_comment, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'SCHEDULED')"
            );
            $insWithId->execute([
                $seqVal,
                $accNo,
                $stUid,
                $normPid,
                $item['profile_id'],
                $item['modality'],
                $item['scheduled_aet'],
                $schedDate,
                $schedTime,
                $item['preset_code'],
                $item['kensamei'],
                $item['procedure_desc'],
                $item['body_part'],
                $item['protocol_code'],
                $item['room_group'],
                $doctor,
                $dept,
                $comment,
            ]);
            $createdIds[] = $seqVal;
        }

        $labels = implode(' / ', array_column($itemsToInsert, 'procedure_desc'));
        rsnext_log_event(
            $pdo,
            'INFO',
            'WORKLIST',
            'ORDER_CREATED',
            "撮影依頼発行: 患者ID {$normPid} -> {$labels} (" . count($createdIds) . "件)",
            $normPid,
            null,
            ['order_ids' => $createdIds]
        );

        $pdo->commit();
        rsnext_trigger_orders_csv_export();

        echo json_encode([
            'status'     => 'success',
            'patient_id' => $normPid,
            'order_ids'  => $createdIds,
            'count'      => count($createdIds),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ③ オーダーステータス手動変更 (update_order_status)
    if ($action === 'update_order_status') {
        $orderId = (int)($req['order_id'] ?? 0);
        $newStatus = strtoupper(trim((string)($req['status'] ?? '')));
        if ($orderId <= 0 || !in_array($newStatus, ['SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'], true)) {
            throw new RuntimeException('パラメータが不正です');
        }

        $stmt = $pdo->prepare(
            "UPDATE modality_orders
             SET status = ?,
                 started_at   = CASE WHEN ? = 'IN_PROGRESS' THEN COALESCE(started_at, NOW()) ELSE started_at END,
                 completed_at = CASE WHEN ? = 'COMPLETED'   THEN COALESCE(completed_at, NOW()) ELSE completed_at END,
                 updated_at   = NOW()
             WHERE order_id = ?
             RETURNING patient_id, kensamei"
        );
        $stmt->execute([$newStatus, $newStatus, $newStatus, $orderId]);
        $r = $stmt->fetch();
        if ($r) {
            rsnext_log_event($pdo, 'INFO', 'WORKLIST', 'ORDER_STATUS_CHANGED', "オーダー #{$orderId} ({$r['kensamei']}) を {$newStatus} へ変更", (string)$r['patient_id']);
            rsnext_trigger_orders_csv_export();
        }
        echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ④ 実パケット DICOM MWL C-FIND 疎通テスト (test_mwl_cfind)
    if ($action === 'test_mwl_cfind') {
        $mod = trim((string)($req['modality'] ?? ''));
        $pid = trim((string)($req['patient_id'] ?? ''));
        $dt  = str_replace('-', '', trim((string)($req['sched_date'] ?? '')));

        $py = rsnext_find_python();
        $cmd = escapeshellarg($py) . ' -m ingest_service.mwl_scp test_find '
             . escapeshellarg($mod) . ' ' . escapeshellarg($pid) . ' ' . escapeshellarg($dt) . ' 2>&1';
        $cwd = getcwd();
        chdir(__DIR__);
        exec($cmd, $outLines, $ret);
        if ($cwd) chdir($cwd);

        $decoded = @json_decode(implode("\n", $outLines), true);
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            throw new RuntimeException($decoded['message'] ?? ('MWL C-FIND 通信エラー（常駐サービスが起動しているか確認してください）: ' . implode(' ', $outLines)));
        }
        echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('未対応のアクションです: ' . $action);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

