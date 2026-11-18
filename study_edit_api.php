<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next 検査編集・誤患者ID付け替え・ゴミ箱復元API (study_edit_api.php)
// 旧 RS-Base 本家 Perl CGI (2000.cgi) を完全代替するトランザクション安全API
// ==============================================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

$rawBody = file_get_contents('php://input');
$jsonBody = $rawBody ? (@json_decode($rawBody, true) ?: []) : [];
$req = array_merge($_GET, $_POST, $jsonBody);
$action = (string)($req['action'] ?? '');

try {
    $pdo = rsnext_pdo();

    // ① 検査1件の詳細取得 (get_study)
    if ($action === 'get_study') {
        $studyId = (int)($req['study_id'] ?? 0);
        if ($studyId <= 0) {
            throw new RuntimeException('study_id が不正です');
        }
        $stmt = $pdo->prepare(
            "SELECT s.*, p.kanja_kanji, p.kanja_yomi, p.birth_date, p.sex,
                    mp.device_name, mp.profile_code
             FROM studies s
             LEFT JOIN patients p ON p.patient_id = s.patient_id
             LEFT JOIN modality_profiles mp ON mp.profile_id = s.profile_id
             WHERE s.study_id = ?"
        );
        $stmt->execute([$studyId]);
        $st = $stmt->fetch();
        if (!$st) {
            throw new RuntimeException('該当する検査が見つかりません');
        }

        $fStmt = $pdo->prepare("SELECT * FROM study_files WHERE study_id = ? ORDER BY seq_no ASC");
        $fStmt->execute([$studyId]);
        $st['files'] = $fStmt->fetchAll();
        $st['abs_folder'] = rsnext_resolve_study_dir((string)$st['volume_id'], (string)$st['rel_path']);

        echo json_encode(['status' => 'success', 'study' => $st], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ② 所見・コメント・施行医・検査種別名の更新 (update_study)
    if ($action === 'update_study') {
        $studyId    = (int)($req['study_id'] ?? 0);
        $kensamei   = trim((string)($req['kensamei'] ?? ''));
        $doctor     = trim((string)($req['doctor'] ?? ''));
        $department = trim((string)($req['department'] ?? ''));
        $findings   = trim((string)($req['findings'] ?? ''));
        $comment    = trim((string)($req['comment'] ?? ''));

        if ($studyId <= 0 || $kensamei === '') {
            throw new RuntimeException('検査IDと検査名は必須です');
        }

        $pdo->beginTransaction();
        $oldStmt = $pdo->prepare("SELECT * FROM studies WHERE study_id = ? FOR UPDATE");
        $oldStmt->execute([$studyId]);
        $old = $oldStmt->fetch();
        if (!$old) {
            $pdo->rollBack();
            throw new RuntimeException('対象検査が存在しません');
        }

        $upd = $pdo->prepare(
            "UPDATE studies
             SET kensamei = ?, doctor = ?, department = ?, findings = ?, comment = ?, updated_at = NOW()
             WHERE study_id = ?"
        );
        $upd->execute([$kensamei, $doctor, $department, $findings, $comment, $studyId]);

        rsnext_sync_study_meta_json($pdo, $studyId);
        rsnext_log_event(
            $pdo,
            'INFO',
            'WEB_EDIT',
            'STUDY_METADATA_UPDATED',
            "検査 #{$studyId} のメタデータ・所見を更新しました ({$kensamei})",
            (string)$old['patient_id'],
            $studyId,
            [
                'before_findings' => $old['findings'],
                'after_findings'  => $findings,
                'doctor'          => $doctor,
                'kensamei'        => $kensamei,
            ]
        );
        $pdo->commit();

        echo json_encode(['status' => 'success', 'study_id' => $studyId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ③ 患者検索・ID照会（付け替え前の安全確認用: lookup_patient）
    if ($action === 'lookup_patient') {
        $q = trim((string)($req['query'] ?? $req['patient_id'] ?? ''));
        if ($q === '') {
            echo json_encode(['status' => 'success', 'patients' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $normId = rsnext_normalize_patient_id($q);
        $kanaNorm = rsnext_normalize_kana($q);

        if ($normId !== null) {
            $stmt = $pdo->prepare(
                "SELECT patient_id, kanja_kanji, kanja_yomi, birth_date, sex
                 FROM patients
                 WHERE patient_id = ? OR patient_id LIKE ?
                 ORDER BY (patient_id = ?) DESC, patient_no_int ASC NULLS LAST
                 LIMIT 20"
            );
            $stmt->execute([$normId, $normId . '%', $normId]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT patient_id, kanja_kanji, kanja_yomi, birth_date, sex
                 FROM patients
                 WHERE kanja_kanji ILIKE ? OR kanja_yomi_norm ILIKE ? OR kanja_yomi ILIKE ?
                 ORDER BY updated_at DESC
                 LIMIT 20"
            );
            $stmt->execute(['%' . $q . '%', '%' . $kanaNorm . '%', '%' . $q . '%']);
        }

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'patient_id'   => $r['patient_id'],
                'kanja_kanji'  => $r['kanja_kanji'],
                'kanja_yomi'   => $r['kanja_yomi'],
                'birth_date'   => $r['birth_date'] ?? '',
                'birth_wareki' => rsnext_format_wareki_birth($r['birth_date'] ?? ''),
                'age'          => rsnext_calc_age($r['birth_date'] ?? ''),
                'sex'          => $r['sex'],
            ];
        }
        echo json_encode(['status' => 'success', 'patients' => $rows, 'normalized_id' => $normId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ④ 誤撮影時の患者ID付け替え (reassign_patient)
    if ($action === 'reassign_patient') {
        $studyId = (int)($req['study_id'] ?? 0);
        $rawTargetId = (string)($req['target_patient_id'] ?? '');
        $targetNameIfNew = trim((string)($req['target_patient_name'] ?? ''));

        $targetId = rsnext_normalize_patient_id($rawTargetId);
        if ($studyId <= 0 || $targetId === null) {
            throw new RuntimeException('有効な移動先患者ID（数値）を指定してください');
        }

        $pdo->beginTransaction();
        $stStmt = $pdo->prepare("SELECT * FROM studies WHERE study_id = ? FOR UPDATE");
        $stStmt->execute([$studyId]);
        $study = $stStmt->fetch();
        if (!$study) {
            $pdo->rollBack();
            throw new RuntimeException('対象の検査が存在しません');
        }

        $oldPid = (string)$study['patient_id'];
        if ($oldPid === $targetId) {
            $pdo->rollBack();
            echo json_encode(['status' => 'success', 'message' => '同一患者IDです', 'patient_id' => $targetId], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 移動先患者が未登録の場合でも仮マスタを作成して外部キー整合を担保
        $pCheck = $pdo->prepare("SELECT patient_id, kanja_kanji FROM patients WHERE patient_id = ?");
        $pCheck->execute([$targetId]);
        $pat = $pCheck->fetch();
        if (!$pat) {
            $insP = $pdo->prepare(
                "INSERT INTO patients (patient_id, kanja_kanji, source_channel)
                 VALUES (?, ?, 'MANUAL')"
            );
            $insP->execute([$targetId, $targetNameIfNew !== '' ? $targetNameIfNew : "未登録患者({$targetId})"]);
        }

        $upd = $pdo->prepare("UPDATE studies SET patient_id = ?, updated_at = NOW() WHERE study_id = ?");
        $upd->execute([$targetId, $studyId]);

        rsnext_sync_study_meta_json($pdo, $studyId);
        rsnext_log_event(
            $pdo,
            'WARN',
            'WEB_EDIT',
            'PATIENT_REASSIGNED',
            "検査 #{$studyId} ({$study['kensamei']}) の患者IDを {$oldPid} -> {$targetId} へ付け替えました",
            $targetId,
            $studyId,
            ['old_patient_id' => $oldPid, 'new_patient_id' => $targetId]
        );
        $pdo->commit();

        echo json_encode(['status' => 'success', 'study_id' => $studyId, 'old_patient_id' => $oldPid, 'new_patient_id' => $targetId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑤ ゴミ箱移動（論理削除: soft_delete）および復元（restore_study）
    if ($action === 'soft_delete' || $action === 'restore_study') {
        $studyId = (int)($req['study_id'] ?? 0);
        if ($studyId <= 0) {
            throw new RuntimeException('study_id が不正です');
        }
        $toDelete = ($action === 'soft_delete');

        $pdo->beginTransaction();
        $stStmt = $pdo->prepare("SELECT * FROM studies WHERE study_id = ? FOR UPDATE");
        $stStmt->execute([$studyId]);
        $study = $stStmt->fetch();
        if (!$study) {
            $pdo->rollBack();
            throw new RuntimeException('対象検査が見つかりません');
        }

        if ($toDelete) {
            $pdo->prepare("UPDATE studies SET is_deleted = TRUE, deleted_at = NOW(), updated_at = NOW() WHERE study_id = ?")->execute([$studyId]);
        } else {
            $pdo->prepare("UPDATE studies SET is_deleted = FALSE, deleted_at = NULL, updated_at = NOW() WHERE study_id = ?")->execute([$studyId]);
        }

        rsnext_sync_study_meta_json($pdo, $studyId);
        rsnext_log_event(
            $pdo,
            'WARN',
            'WEB_EDIT',
            $toDelete ? 'STUDY_SOFT_DELETED' : 'STUDY_RESTORED',
            $toDelete ? "検査 #{$studyId} をゴミ箱へ移動しました" : "検査 #{$studyId} をゴミ箱から復元しました",
            (string)$study['patient_id'],
            $studyId
        );
        $pdo->commit();

        echo json_encode(['status' => 'success', 'study_id' => $studyId, 'is_deleted' => $toDelete], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⑥ 患者マスタの手動登録・更新 (upsert_patient)
    if ($action === 'upsert_patient') {
        $normId = rsnext_normalize_patient_id((string)($req['patient_id'] ?? ''));
        if ($normId === null) {
            throw new RuntimeException('有効な数値の患者IDを入力してください');
        }
        $kanji = trim((string)($req['kanja_kanji'] ?? ''));
        $yomi  = trim((string)($req['kanja_yomi'] ?? ''));
        $sex   = trim((string)($req['sex'] ?? ''));
        $birth = trim((string)($req['birth_date'] ?? ''));
        if ($kanji === '') {
            throw new RuntimeException('患者氏名を入力してください');
        }
        $yomiNorm = rsnext_normalize_kana($yomi);
        $birthParam = ($birth !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth)) ? $birth : null;

        $stmt = $pdo->prepare(
            "INSERT INTO patients (patient_id, kanja_kanji, kanja_yomi, kanja_yomi_norm, birth_date, birth_raw, sex, source_channel, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'MANUAL', NOW())
             ON CONFLICT (patient_id) DO UPDATE SET
                 kanja_kanji     = EXCLUDED.kanja_kanji,
                 kanja_yomi      = EXCLUDED.kanja_yomi,
                 kanja_yomi_norm = EXCLUDED.kanja_yomi_norm,
                 birth_date      = COALESCE(EXCLUDED.birth_date, patients.birth_date),
                 sex             = CASE WHEN EXCLUDED.sex <> '' THEN EXCLUDED.sex ELSE patients.sex END,
                 updated_at      = NOW()"
        );
        $stmt->execute([$normId, $kanji, $yomi, $yomiNorm, $birthParam, $birth, $sex]);

        echo json_encode(['status' => 'success', 'patient_id' => $normId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('未対応のアクションです: ' . $action);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

