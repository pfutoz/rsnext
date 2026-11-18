<?php
declare(strict_types=1);

// ==============================================================================
// RS-Next 重複検査抽出・スマート比較・安全整理エンジン (lib/duplicate_detector.php)
// - SHA-256 バイナリ指紋一致 (完全一致 / 100%包含部分一致 / 別患者ID間の誤撮影一致)
// - DICOM StudyInstanceUID 一致 (多部位X線の同一受付は誤検知しないインテリジェント判定)
// - 同日・同患者・同種検査の重複候補 (同枚数 / 枚数違い / 時刻差秒数解析)
// - ゴミ箱論理削除 (is_deleted = TRUE) ＆ 所見・施行医マージ ＆ 除外グループ管理
// ==============================================================================

require_once __DIR__ . '/bootstrap.php';

function rsnext_ensure_dup_schema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $flagFile = dirname(__DIR__) . '/tmp/.dup_schema_v2.ok';
    if (is_file($flagFile)) {
        return;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS duplicate_study_ignores (
                group_sig   VARCHAR(64) PRIMARY KEY,
                study_ids   BIGINT[] NOT NULL,
                note        TEXT NOT NULL DEFAULT '',
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_study_files_sid_seq ON study_files(study_id, seq_no)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_study_files_sha256_sid ON study_files(sha256_hash, study_id)");
        $tmpDir = dirname($flagFile);
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
        @file_put_contents($flagFile, date('c'));
    } catch (Throwable $e) {
        // 読み取り専用接続等の場合はスキップ
    }
}

/**
 * 検査種別名の正規化（同日・同患者の同一種別判定用。胸部と腹部・腰椎等の別部位は混ぜない）
 */
function rsnext_norm_kensamei_for_dup(string $kensamei, string $modality = '', string $displayCategory = ''): string {
    $s = trim(mb_convert_kana($kensamei, 'asKV', 'UTF-8'));
    // 末尾・途中の (D), (d), アンダースコア, 空白を除去
    $s = preg_replace('/\([Dd]\)/u', '', $s) ?? $s;
    $s = preg_replace('/[\s_]+/u', '', $s) ?? $s;
    $sLower = mb_strtolower($s, 'UTF-8');

    if (in_array($sLower, ['esgeneral', '内視鏡', '上部内視鏡', '上部消化管内視鏡', '胃カメラ', 'gif'], true)) {
        return 'ENDO_UPPER';
    }
    if (in_array($sLower, ['大腸内視鏡', '下部消化管内視鏡', '大腸カメラ', 'cf', 'tcs'], true)) {
        return 'ENDO_LOWER';
    }
    if (in_array($sLower, ['usadultabd', '腹部エコー', '腹部超音波', '腹部超音波検査'], true)) {
        return 'US_ABD';
    }
    if (in_array($sLower, ['uscarotid', '頸動脈エコー', '頸動脈超音波', '頸動脈超音波検査'], true)) {
        return 'US_CAROTID';
    }
    if (in_array($sLower, ['心電図', '12誘導心電図', '安静時12誘導心電図', '安静時心電図', 'ecg'], true)) {
        return 'ECG_12';
    }

    $modUpper = strtoupper(trim($modality));
    if ($modUpper === 'CT' || preg_match('/CT/i', $s)) {
        if (in_array($sLower, ['ct', '他院紹介ct', '外部取込画像'], true)) {
            return 'CT_GENERIC';
        }
        return 'CT_' . $sLower;
    }
    if (in_array($modUpper, ['MR', 'MRI'], true) || preg_match('/MRI|MRA|MR検査/iu', $s)) {
        if (in_array($sLower, ['mr', 'mri', '他院紹介mri', '外部取込画像'], true)) {
            return 'MR_GENERIC';
        }
        return 'MR_' . $sLower;
    }

    return $sLower !== '' ? $sLower : ($modUpper ?: ($displayCategory ?: 'other'));
}

/**
 * 2つの正規化検査名が「同日重複候補」として互換か判定
 * （例: スカウトのみ 'CT_GENERIC' と 本編 'CT_胸部ct' は同一CT検査の分割取込候補としてマッチさせる）
 */
function rsnext_is_compatible_norm_kensa(string $na, string $nb): bool {
    if ($na === $nb) {
        return true;
    }
    if (($na === 'CT_GENERIC' && str_starts_with($nb, 'CT_')) || ($nb === 'CT_GENERIC' && str_starts_with($na, 'CT_'))) {
        return true;
    }
    if (($na === 'MR_GENERIC' && str_starts_with($nb, 'MR_')) || ($nb === 'MR_GENERIC' && str_starts_with($na, 'MR_'))) {
        return true;
    }
    // 胃がん検診日に FTP自動着信 'ENDO_UPPER' (ES_General) と '胃がん検診' が両方作られたケース
    if (($na === 'ENDO_UPPER' && $nb === '胃がん検診') || ($nb === 'ENDO_UPPER' && $na === '胃がん検診')) {
        return true;
    }
    return false;
}

function rsnext_dup_type_priority(string $type): int {
    return match ($type) {
        'cross_patient'  => 100,
        'exact_hash'     => 90,
        'subset_hash'    => 80,
        'same_day_count' => 60,
        'same_day_exam'  => 40,
        'same_day_cat'   => 20,
        default          => 10,
    };
}

function rsnext_dup_type_meta(string $type): array {
    return match ($type) {
        'cross_patient' => [
            'label' => '🚨 別患者ID間の同一画像',
            'short' => '別患者ID一致',
            'color' => '#dc2626',
            'bg'    => '#fef2f2',
            'bd'    => '#fca5a5',
            'desc'  => '同一の画像ハッシュ(SHA-256)または検査UIDが異なる患者IDに登録されています（誤ID撮影後に再送した残骸の可能性大）',
        ],
        'exact_hash' => [
            'label' => '🔴 完全一致重複 (SHA-256 / UID)',
            'short' => '完全一致',
            'color' => '#b91c1c',
            'bg'    => '#fff1f2',
            'bd'    => '#fda4af',
            'desc'  => '全画像のSHA-256ハッシュまたはDICOM検査UIDが完全一致している同一患者の重複検査です',
        ],
        'subset_hash' => [
            'label' => '🟣 包含・部分バイナリ一致',
            'short' => '包含・部分一致',
            'color' => '#7e22ce',
            'bg'    => '#faf5ff',
            'bd'    => '#d8b4fe',
            'desc'  => '片方の検査画像がもう片方に含まれている（通信中断後の再送・抜粋重複など）同一バイナリ検査です',
        ],
        'same_day_count' => [
            'label' => '🟠 同日・同患者・同種・同枚数',
            'short' => '同日・同枚数',
            'color' => '#c2410c',
            'bg'    => '#fff7ed',
            'bd'    => '#fdba74',
            'desc'  => '同一患者・同一検査日・同一検査種別で画像枚数も同じ検査です（再出力によるUID変化、DICOM＋JPEG二重登録、または同日再検）',
        ],
        'same_day_exam' => [
            'label' => '🔵 同日・同患者・同種 (枚数違い)',
            'short' => '同日・枚数違い',
            'color' => '#0369a1',
            'bg'    => '#f0f9ff',
            'bd'    => '#7dd3fc',
            'desc'  => '同一患者・同一検査日・同一検査種別で枚数が異なる複数検査です（途中送信＋全枚数再送、または前後比較検査）',
        ],
        default => [
            'label' => '⚪ 同日・同カテゴリ複数検査 (参考)',
            'short' => '同カテゴリ参考',
            'color' => '#475569',
            'bg'    => '#f8fafc',
            'bd'    => '#cbd5e1',
            'desc'  => '同一患者・同一日に同じモダリティカテゴリで複数部位（例: 胸部＋腹部X線など）が登録されている参考一覧です',
        ],
    };
}

function rsnext_dup_fmt_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}

function rsnext_dup_pg_bigint_array(array $ids): string {
    $clean = [];
    foreach ($ids as $id) {
        $v = (int)$id;
        if ($v > 0) {
            $clean[$v] = $v;
        }
    }
    return '{' . implode(',', $clean) . '}';
}

/**
 * 検査を「残す候補」として評価するスコアを算出
 */
function rsnext_calc_keep_score(array $st): int {
    $actualFiles = (int)($st['actual_file_count'] ?? $st['file_count'] ?? 0);
    if ($actualFiles <= 0) {
        return -100000;
    }
    $score = 0;
    $findings = trim((string)($st['findings'] ?? ''));
    $comment  = trim((string)($st['comment'] ?? ''));
    $doctor   = trim((string)($st['doctor'] ?? ''));

    if ($findings !== '') {
        $score += 10000 + min(2000, mb_strlen($findings, 'UTF-8') * 10);
    }
    if ($comment !== '') {
        $score += 2500 + min(500, mb_strlen($comment, 'UTF-8') * 5);
    }
    if ($doctor !== '') {
        $score += 1000;
    }

    // 実ファイル枚数が多い側（完全な検査）を優先
    $score += min(8000, $actualFiles * 40);

    // JPEG変換コピーよりもDICOM/ECG原本形式を優先
    $ft = strtoupper((string)($st['primary_file_type'] ?? ''));
    if ($ft === 'DICOM' || $ft === 'ECG') {
        $score += 250;
    } elseif ($ft === 'VIDEO' || $ft === 'PDF') {
        $score += 150;
    }

    // StudyInstanceUID がある側を優先
    if (!empty($st['study_instance_uid'])) {
        $score += 80;
    }

    // 旧RS-Base連番が紐付いている正本を優先
    if (!empty($st['legacy_renban'])) {
        $score += 60;
    }

    return $score;
}

/**
 * 重複検査グループを高速抽出して構造化データを返す
 *
 * $opts = [
 *   'type'              => 'all' | 'exact_hash' | 'subset_hash' | 'cross_patient' | 'same_day_count' | 'same_day_exam' | 'same_day_cat' | 'ignored',
 *   'cat'               => 'all' | 'endoscopy' | 'xray' | 'us' | 'ecg' | 'other',
 *   'q'                 => string,
 *   'date_from'         => 'YYYY-MM-DD' | '',
 *   'date_to'           => 'YYYY-MM-DD' | '',
 *   'limit'             => int (default 150),
 * ]
 */
function rsnext_scan_duplicate_groups(PDO $pdo, array $opts = []): array {
    $t0 = microtime(true);
    rsnext_ensure_dup_schema($pdo);

    $typeFilter = trim((string)($opts['type'] ?? 'all')) ?: 'all';
    $catFilter  = trim((string)($opts['cat'] ?? 'all')) ?: 'all';
    $q          = trim((string)($opts['q'] ?? ''));
    $dateFrom   = trim((string)($opts['date_from'] ?? ''));
    $dateTo     = trim((string)($opts['date_to'] ?? ''));
    $limit      = max(10, min(500, (int)($opts['limit'] ?? 150)));

    $includeBroadCat = ($typeFilter === 'same_day_cat');

    // 除外済みグループ署名の取得
    $ignoredMap = [];
    try {
        $igRows = $pdo->query("SELECT group_sig, note, created_at FROM duplicate_study_ignores")->fetchAll();
        foreach ($igRows as $ir) {
            $ignoredMap[(string)$ir['group_sig']] = [
                'note'       => (string)($ir['note'] ?? ''),
                'created_at' => (string)($ir['created_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
    }

    // Union-Find & ペア証拠コレクション
    $parent = [];
    $edges = []; // "sidA:sidB" => ['type' => ..., 'reasons' => [...], 'is_100_subset' => bool]

    $ufFind = function (int $x) use (&$parent): int {
        if (!isset($parent[$x])) {
            $parent[$x] = $x;
            return $x;
        }
        $root = $x;
        while ($parent[$root] !== $root) {
            $root = $parent[$root];
        }
        while ($parent[$x] !== $root) {
            $nxt = $parent[$x];
            $parent[$x] = $root;
            $x = $nxt;
        }
        return $root;
    };

    $addEdge = function (int $a, int $b, string $type, string $reason, bool $isFullSubset = false) use (&$parent, &$edges, $ufFind): void {
        if ($a === $b || $a <= 0 || $b <= 0) {
            return;
        }
        $s1 = min($a, $b);
        $s2 = max($a, $b);
        $ra = $ufFind($s1);
        $rb = $ufFind($s2);
        if ($ra !== $rb) {
            $parent[$rb] = $ra;
        }
        $ek = $s1 . ':' . $s2;
        if (!isset($edges[$ek])) {
            $edges[$ek] = [
                'sid_a'          => $s1,
                'sid_b'          => $s2,
                'type'           => $type,
                'reasons'        => [$reason],
                'is_full_subset' => $isFullSubset,
            ];
        } else {
            if (rsnext_dup_type_priority($type) > rsnext_dup_type_priority($edges[$ek]['type'])) {
                $edges[$ek]['type'] = $type;
            }
            if (!in_array($reason, $edges[$ek]['reasons'], true)) {
                $edges[$ek]['reasons'][] = $reason;
            }
            if ($isFullSubset) {
                $edges[$ek]['is_full_subset'] = true;
            }
        }
    };

    // -------------------------------------------------------------------------
    // Step 1: SHA-256 ファイルハッシュ重複ペア抽出 (idx_study_files_sha256 使用)
    // -------------------------------------------------------------------------
    $hashSql = "
        WITH dup_hashes AS (
            SELECT (sha256_hash COLLATE \"C\") AS sha256_c
            FROM study_files
            GROUP BY (sha256_hash COLLATE \"C\")
            HAVING COUNT(*) > 1
        ),
        cand_sf AS (
            SELECT sf.study_id, sf.sha256_hash, s.patient_id
            FROM dup_hashes dh
            JOIN study_files sf ON sf.sha256_hash = dh.sha256_c
            JOIN studies s ON s.study_id = sf.study_id AND NOT s.is_deleted
            WHERE sf.sha256_hash <> ''
        ),
        multi_study_hashes AS (
            SELECT sha256_hash
            FROM cand_sf
            GROUP BY sha256_hash
            HAVING COUNT(DISTINCT study_id) > 1
        ),
        active_hits AS (
            SELECT DISTINCT c.study_id, c.patient_id, c.sha256_hash
            FROM cand_sf c
            JOIN multi_study_hashes mh ON mh.sha256_hash = c.sha256_hash
        ),
        pair_shared AS (
            SELECT a.study_id AS sid_a,
                   b.study_id AS sid_b,
                   a.patient_id AS pid_a,
                   b.patient_id AS pid_b,
                   COUNT(*) AS shared_cnt
            FROM active_hits a
            JOIN active_hits b ON a.sha256_hash = b.sha256_hash AND a.study_id < b.study_id
            GROUP BY a.study_id, b.study_id, a.patient_id, b.patient_id
        ),
        involved_sids AS (
            SELECT sid_a AS study_id FROM pair_shared
            UNION
            SELECT sid_b AS study_id FROM pair_shared
        ),
        study_totals AS (
            SELECT sf.study_id,
                   COUNT(*) AS total_files,
                   COUNT(DISTINCT sf.sha256_hash) AS distinct_hashes
            FROM involved_sids iv
            JOIN study_files sf ON sf.study_id = iv.study_id
            GROUP BY sf.study_id
        )
        SELECT ps.sid_a, ps.sid_b, ps.pid_a, ps.pid_b, ps.shared_cnt,
               COALESCE(ta.total_files, 0) AS total_a,
               COALESCE(ta.distinct_hashes, 0) AS dist_a,
               COALESCE(tb.total_files, 0) AS total_b,
               COALESCE(tb.distinct_hashes, 0) AS dist_b
        FROM pair_shared ps
        LEFT JOIN study_totals ta ON ta.study_id = ps.sid_a
        LEFT JOIN study_totals tb ON tb.study_id = ps.sid_b
        ORDER BY (ps.pid_a <> ps.pid_b) DESC, ps.shared_cnt DESC
        LIMIT 4000
    ";
    foreach ($pdo->query($hashSql)->fetchAll() as $hr) {
        $sidA   = (int)$hr['sid_a'];
        $sidB   = (int)$hr['sid_b'];
        $pidA   = (string)$hr['pid_a'];
        $pidB   = (string)$hr['pid_b'];
        $shared = (int)$hr['shared_cnt'];
        $totA   = (int)$hr['total_a'];
        $totB   = (int)$hr['total_b'];
        $distA  = (int)$hr['dist_a'];
        $distB  = (int)$hr['dist_b'];

        if ($pidA !== $pidB) {
            $addEdge($sidA, $sidB, 'cross_patient', "別患者ID間で {$shared} 枚の画像SHA-256が完全一致");
        } elseif ($distA > 0 && $shared === $distA && $shared === $distB && $totA === $totB) {
            $addEdge($sidA, $sidB, 'exact_hash', "全 {$totA} 枚の画像SHA-256が100%完全一致", true);
        } elseif ($distA > 0 && $distB > 0 && ($shared === $distA || $shared === $distB)) {
            $minC = min($totA, $totB);
            $maxC = max($totA, $totB);
            $msg = ($totA === $totB)
                ? "ユニーク画像SHA-256 ({$shared}種) が完全一致"
                : "包含重複: 片方の全 {$minC} 枚がもう片方 ({$maxC}枚) に100%包含";
            $addEdge($sidA, $sidB, 'subset_hash', $msg, true);
        } else {
            $addEdge($sidA, $sidB, 'subset_hash', "一部画像SHA-256一致: {$shared} 枚が共通 ({$totA}枚 / {$totB}枚)", false);
        }
    }

    // -------------------------------------------------------------------------
    // Step 2: DICOM StudyInstanceUID 重複ペア抽出 (idx_studies_uid 使用)
    // ※ X線(DX/CR/RF)等で同一受付内に「胸部＋腹部」など別部位を撮影したケースは除外
    // -------------------------------------------------------------------------
    $uidSqlWithKey = "
        WITH dup_uids AS (
            SELECT study_instance_uid
            FROM studies
            WHERE NOT is_deleted
              AND study_instance_uid IS NOT NULL
              AND study_instance_uid <> ''
            GROUP BY study_instance_uid
            HAVING COUNT(*) > 1
        )
        SELECT s.study_instance_uid, s.study_id, s.patient_id, s.modality, s.kensamei, s.display_category
        FROM dup_uids u
        JOIN studies s ON s.study_instance_uid = u.study_instance_uid AND NOT s.is_deleted
        ORDER BY s.study_instance_uid, s.study_id ASC
    ";
    $uidBuckets = [];
    foreach ($pdo->query($uidSqlWithKey)->fetchAll() as $ur) {
        $uidBuckets[(string)$ur['study_instance_uid']][] = $ur;
    }
    foreach ($uidBuckets as $uid => $list) {
        $n = count($list);
        if ($n < 2 || $n > 40) {
            continue;
        }
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $list[$i];
                $b = $list[$j];
                $sidA = (int)$a['study_id'];
                $sidB = (int)$b['study_id'];
                if ((string)$a['patient_id'] !== (string)$b['patient_id']) {
                    $addEdge($sidA, $sidB, 'cross_patient', '同一DICOM StudyInstanceUID が異なる患者IDに登録');
                } else {
                    $modA = strtoupper((string)$a['modality']);
                    $modB = strtoupper((string)$b['modality']);
                    $isTomo = in_array($modA, ['CT', 'MR', 'MRI', 'PT', 'PET', 'NM'], true)
                           || in_array($modB, ['CT', 'MR', 'MRI', 'PT', 'PET', 'NM'], true);
                    $nkA = rsnext_norm_kensamei_for_dup((string)$a['kensamei'], $modA, (string)$a['display_category']);
                    $nkB = rsnext_norm_kensamei_for_dup((string)$b['kensamei'], $modB, (string)$b['display_category']);
                    if ($isTomo || rsnext_is_compatible_norm_kensa($nkA, $nkB)) {
                        $addEdge($sidA, $sidB, 'exact_hash', '同一DICOM StudyInstanceUID 一致', true);
                    } elseif ($includeBroadCat) {
                        $addEdge($sidA, $sidB, 'same_day_cat', '同一受付UID・別部位撮影 (参考)');
                    }
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Step 3: 同日・同患者・同種検査候補の抽出 (idx_studies_patient 使用)
    // -------------------------------------------------------------------------
    $sameDaySql = "
        WITH multi_day AS (
            SELECT patient_id, study_date
            FROM studies
            WHERE NOT is_deleted
            GROUP BY patient_id, study_date
            HAVING COUNT(*) > 1
        )
        SELECT s.study_id, s.patient_id, s.study_date, s.study_time,
               s.modality, s.kensamei, s.display_category, s.file_count
        FROM multi_day md
        JOIN studies s ON s.patient_id = md.patient_id
                      AND s.study_date = md.study_date
                      AND NOT s.is_deleted
        ORDER BY s.study_date DESC, s.patient_id, s.study_id ASC
    ";
    $dayBuckets = [];
    foreach ($pdo->query($sameDaySql)->fetchAll() as $dr) {
        $dk = $dr['patient_id'] . '|' . $dr['study_date'];
        $dr['_norm_kensa'] = rsnext_norm_kensamei_for_dup(
            (string)$dr['kensamei'],
            (string)$dr['modality'],
            (string)$dr['display_category']
        );
        $dayBuckets[$dk][] = $dr;
    }

    foreach ($dayBuckets as $dk => $items) {
        $n = count($items);
        if ($n < 2 || $n > 30) {
            continue;
        }
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $items[$i];
                $b = $items[$j];
                $sidA = (int)$a['study_id'];
                $sidB = (int)$b['study_id'];
                $fcA  = (int)$a['file_count'];
                $fcB  = (int)$b['file_count'];

                if (rsnext_is_compatible_norm_kensa((string)$a['_norm_kensa'], (string)$b['_norm_kensa'])) {
                    if ($fcA > 0 && $fcA === $fcB) {
                        $addEdge($sidA, $sidB, 'same_day_count', "同日・同種検査・同枚数 ({$fcA}枚)");
                    } else {
                        $addEdge($sidA, $sidB, 'same_day_exam', "同日・同種検査で枚数違い ({$fcA}枚 vs {$fcB}枚)");
                    }
                } elseif ($includeBroadCat && (string)$a['display_category'] === (string)$b['display_category']) {
                    $addEdge($sidA, $sidB, 'same_day_cat', '同日・同カテゴリ別部位検査 (参考)');
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Step 4: 連結成分グループ構築 ＆ メタデータ一括取得
    // -------------------------------------------------------------------------
    $compStudies = [];
    foreach (array_keys($parent) as $sid) {
        $root = $ufFind((int)$sid);
        $compStudies[$root][] = (int)$sid;
    }

    $compEdges = [];
    foreach ($edges as $e) {
        $root = $ufFind((int)$e['sid_a']);
        $compEdges[$root][] = $e;
    }

    $allMatchedSids = [];
    foreach ($compStudies as $root => $sids) {
        if (count($sids) < 2) {
            unset($compStudies[$root]);
            continue;
        }
        foreach ($sids as $sid) {
            $allMatchedSids[$sid] = $sid;
        }
    }

    $kpi = [
        'total_active_groups'  => 0,
        'total_redundant_cnt'  => 0,
        'reclaimable_bytes'    => 0,
        'reclaimable_human'    => '0 B',
        'auto_safe_groups'     => 0,
        'auto_safe_studies'    => 0,
        'by_type'              => [
            'cross_patient'  => 0,
            'exact_hash'     => 0,
            'subset_hash'    => 0,
            'same_day_count' => 0,
            'same_day_exam'  => 0,
            'same_day_cat'   => 0,
            'ignored'        => 0,
        ],
    ];

    if (empty($allMatchedSids)) {
        return [
            'kpi'          => $kpi,
            'groups'       => [],
            'total_match'  => 0,
            'elapsed_ms'   => round((microtime(true) - $t0) * 1000, 1),
        ];
    }

    // 対象 study_id 群の詳細情報を1クエリで一括取得
    $pgArr = rsnext_dup_pg_bigint_array($allMatchedSids);
    $detailStmt = $pdo->prepare("
        SELECT s.*,
               p.kanja_kanji, p.kanja_yomi, p.kanja_yomi_norm, p.birth_date, p.sex,
               mp.device_name, mp.profile_code, mp.icon_emoji, mp.badge_color AS prof_color,
               COALESCE(fc.actual_cnt, s.file_count) AS actual_file_count,
               COALESCE(fc.actual_bytes, s.total_bytes) AS actual_bytes
        FROM studies s
        LEFT JOIN patients p ON p.patient_id = s.patient_id
        LEFT JOIN modality_profiles mp ON mp.profile_id = s.profile_id
        LEFT JOIN LATERAL (
            SELECT COUNT(*) AS actual_cnt, COALESCE(SUM(file_bytes), 0) AS actual_bytes
            FROM study_files sf
            WHERE sf.study_id = s.study_id
        ) fc ON TRUE
        WHERE s.study_id = ANY(?::bigint[])
          AND NOT s.is_deleted
    ");
    $detailStmt->execute([$pgArr]);

    $studyMap = [];
    foreach ($detailStmt->fetchAll() as $row) {
        $studyMap[(int)$row['study_id']] = $row;
    }

    $qNormId = $q !== '' ? rsnext_normalize_patient_id($q) : null;
    $qKana   = $q !== '' ? rsnext_normalize_kana($q) : '';
    $qLower  = $q !== '' ? mb_strtolower($q, 'UTF-8') : '';

    $filteredGroups = [];

    foreach ($compStudies as $root => $sids) {
        $groupStudies = [];
        foreach ($sids as $sid) {
            if (isset($studyMap[$sid])) {
                $groupStudies[] = $studyMap[$sid];
            }
        }
        if (count($groupStudies) < 2) {
            continue;
        }

        $sortedSids = array_map(fn($r) => (int)$r['study_id'], $groupStudies);
        sort($sortedSids, SORT_NUMERIC);
        $groupSig = md5(implode(',', $sortedSids));
        $isIgnored = isset($ignoredMap[$groupSig]);

        // グループ代表種別と理由一覧を集約
        $gEdges = $compEdges[$root] ?? [];
        $bestType = 'same_day_exam';
        $bestPrio = -1;
        $reasons = [];
        $allEdgesFullSubsetOrExact = !empty($gEdges);

        foreach ($gEdges as $ge) {
            $pr = rsnext_dup_type_priority((string)$ge['type']);
            if ($pr > $bestPrio) {
                $bestPrio = $pr;
                $bestType = (string)$ge['type'];
            }
            foreach ($ge['reasons'] as $rsn) {
                if (!in_array($rsn, $reasons, true)) {
                    $reasons[] = $rsn;
                }
            }
            if (empty($ge['is_full_subset']) && $ge['type'] !== 'exact_hash') {
                $allEdgesFullSubsetOrExact = false;
            }
        }

        // 3件以上のグループで「同日・同種検査で枚数違い (A vs B)」が複数ペア分並ぶ場合は1行に集約
        $diffCntReasons = array_filter($reasons, fn($r) => str_starts_with($r, '同日・同種検査で枚数違い'));
        if (count($diffCntReasons) >= 2) {
            $reasons = array_values(array_filter($reasons, fn($r) => !str_starts_with($r, '同日・同種検査で枚数違い')));
            $fcList = array_map(fn($gs) => ((int)($gs['actual_file_count'] ?? $gs['file_count'] ?? 0)) . '枚', $groupStudies);
            $reasons[] = '同日・同種検査で枚数違い (' . implode(' / ', $fcList) . ')';
        }

        // 各検査にスコアを付与し、推奨残す検査(1件)を決定
        foreach ($groupStudies as &$gs) {
            $gs['_keep_score'] = rsnext_calc_keep_score($gs);
        }
        unset($gs);

        usort($groupStudies, function (array $a, array $b): int {
            if ($a['_keep_score'] !== $b['_keep_score']) {
                return $b['_keep_score'] <=> $a['_keep_score'];
            }
            // 同スコアなら古い study_id を正本として優先
            return ((int)$a['study_id']) <=> ((int)$b['study_id']);
        });

        $keepSid = (int)$groupStudies[0]['study_id'];
        $keepFindings = trim((string)($groupStudies[0]['findings'] ?? ''));
        $keepComment  = trim((string)($groupStudies[0]['comment'] ?? ''));

        $redundantBytes = 0;
        $redundantSids  = [];
        $hasUnmergedFindingsOnRedundant = false;
        $distinctNonEmptyFindings = [];
        $timestampsSec = [];

        foreach ($groupStudies as $idx => &$gs) {
            $sid = (int)$gs['study_id'];
            $gs['is_recommended_keep'] = ($idx === 0 && $bestType !== 'cross_patient');
            $fTxt = trim((string)($gs['findings'] ?? ''));
            $cTxt = trim((string)($gs['comment'] ?? ''));
            if ($fTxt !== '') {
                $distinctNonEmptyFindings[$fTxt] = true;
            }
            if ($idx > 0) {
                $redundantSids[] = $sid;
                $redundantBytes += (int)($gs['actual_bytes'] ?? $gs['total_bytes'] ?? 0);
                if ($fTxt !== '' && $fTxt !== $keepFindings && mb_strpos($keepFindings, $fTxt, 0, 'UTF-8') === false) {
                    $hasUnmergedFindingsOnRedundant = true;
                }
                if ($cTxt !== '' && $cTxt !== $keepComment && mb_strpos($keepComment, $cTxt, 0, 'UTF-8') === false) {
                    $hasUnmergedFindingsOnRedundant = true;
                }
            }
            if (!empty($gs['study_date']) && !empty($gs['study_time'])) {
                $ts = @strtotime($gs['study_date'] . ' ' . $gs['study_time']);
                if ($ts !== false) {
                    $timestampsSec[] = $ts;
                }
            }
        }
        unset($gs);

        // 撮影時刻差の計算（同日再検 vs 数秒差の二重送信を見分ける補助指標）
        $timeDiffLabel = '';
        $timeDiffSec = null;
        if (count($timestampsSec) >= 2) {
            sort($timestampsSec, SORT_NUMERIC);
            $diff = $timestampsSec[count($timestampsSec) - 1] - $timestampsSec[0];
            $timeDiffSec = $diff;
            if ($diff === 0) {
                $timeDiffLabel = '同時刻 (0秒差)';
            } elseif ($diff < 60) {
                $timeDiffLabel = "時刻差 {$diff}秒";
            } elseif ($diff < 3600) {
                $timeDiffLabel = '時刻差 ' . round($diff / 60) . '分';
            } else {
                $timeDiffLabel = '時刻差 ' . round($diff / 3600, 1) . '時間';
            }
        }

        // 安全自動選択フラグ: 同一患者 かつ 完全一致または100%包含 かつ 削除側に固有の所見が無い
        $autoCleanSafe = !$isIgnored
            && ($bestType === 'exact_hash' || ($bestType === 'subset_hash' && $allEdgesFullSubsetOrExact))
            && !$hasUnmergedFindingsOnRedundant;

        // KPI集計（検索フィルタ前の全体件数として集計。ただし same_day_cat 参考表示は別枠）
        if ($isIgnored) {
            $kpi['by_type']['ignored']++;
        } else {
            $kpi['by_type'][$bestType] = ($kpi['by_type'][$bestType] ?? 0) + 1;
            if ($bestType !== 'same_day_cat') {
                $kpi['total_active_groups']++;
                $kpi['total_redundant_cnt'] += count($redundantSids);
                $kpi['reclaimable_bytes']   += $redundantBytes;
                if ($autoCleanSafe) {
                    $kpi['auto_safe_groups']++;
                    $kpi['auto_safe_studies'] += count($redundantSids);
                }
            }
        }

        // 画面表示フィルタ判定
        if ($typeFilter === 'ignored') {
            if (!$isIgnored) continue;
        } else {
            if ($isIgnored) continue;
            if ($typeFilter === 'all') {
                if ($bestType === 'same_day_cat') continue;
            } elseif ($bestType !== $typeFilter) {
                continue;
            }
        }

        if ($catFilter !== '' && $catFilter !== 'all') {
            $catMatch = false;
            foreach ($groupStudies as $gs) {
                if ((string)$gs['display_category'] === $catFilter) {
                    $catMatch = true;
                    break;
                }
            }
            if (!$catMatch) continue;
        }

        if ($dateFrom !== '' || $dateTo !== '') {
            $dateMatch = false;
            foreach ($groupStudies as $gs) {
                $sd = (string)($gs['study_date'] ?? '');
                if ($dateFrom !== '' && $sd < $dateFrom) continue;
                if ($dateTo !== '' && $sd > $dateTo) continue;
                $dateMatch = true;
                break;
            }
            if (!$dateMatch) continue;
        }

        if ($q !== '') {
            $qMatch = false;
            foreach ($groupStudies as $gs) {
                $pid = (string)$gs['patient_id'];
                if ($qNormId !== null && ($pid === $qNormId || str_starts_with($pid, $qNormId))) {
                    $qMatch = true;
                    break;
                }
                if ((string)$gs['study_id'] === $q || (string)($gs['legacy_renban'] ?? '') === $q) {
                    $qMatch = true;
                    break;
                }
                $hay = mb_strtolower(
                    implode(' ', [
                        $pid,
                        (string)($gs['kanja_kanji'] ?? ''),
                        (string)($gs['kanja_yomi'] ?? ''),
                        (string)($gs['kanja_yomi_norm'] ?? ''),
                        (string)($gs['kensamei'] ?? ''),
                        (string)($gs['findings'] ?? ''),
                        (string)($gs['doctor'] ?? ''),
                    ]),
                    'UTF-8'
                );
                if (str_contains($hay, $qLower) || ($qKana !== '' && str_contains((string)($gs['kanja_yomi_norm'] ?? ''), $qKana))) {
                    $qMatch = true;
                    break;
                }
            }
            if (!$qMatch) continue;
        }

        $latestDate = '';
        foreach ($groupStudies as $gs) {
            if ((string)$gs['study_date'] > $latestDate) {
                $latestDate = (string)$gs['study_date'];
            }
        }

        $filteredGroups[] = [
            'group_sig'             => $groupSig,
            'type'                  => $bestType,
            'type_prio'             => $bestPrio,
            'type_meta'             => rsnext_dup_type_meta($bestType),
            'reasons'               => $reasons,
            'time_diff_label'       => $timeDiffLabel,
            'time_diff_sec'         => $timeDiffSec,
            'latest_study_date'     => $latestDate,
            'keep_study_id'         => $keepSid,
            'redundant_study_ids'   => $redundantSids,
            'reclaimable_bytes'     => $redundantBytes,
            'reclaimable_human'     => rsnext_dup_fmt_bytes($redundantBytes),
            'has_findings_conflict' => $hasUnmergedFindingsOnRedundant || count($distinctNonEmptyFindings) > 1,
            'auto_clean_safe'       => $autoCleanSafe,
            'is_ignored'            => $isIgnored,
            'ignore_info'           => $ignoredMap[$groupSig] ?? null,
            'studies'               => $groupStudies,
        ];
    }

    $kpi['reclaimable_human'] = rsnext_dup_fmt_bytes((int)$kpi['reclaimable_bytes']);

    // 重要度順 ➔ 新しい検査日順でソート
    usort($filteredGroups, function (array $a, array $b): int {
        if ($a['type_prio'] !== $b['type_prio']) {
            return $b['type_prio'] <=> $a['type_prio'];
        }
        return strcmp((string)$b['latest_study_date'], (string)$a['latest_study_date']);
    });

    $totalMatch = count($filteredGroups);
    $pagedGroups = array_slice($filteredGroups, 0, $limit);

    // 表示分グループの先頭・中間・末尾サムネイルファイル名を1クエリで取得
    $pageSids = [];
    foreach ($pagedGroups as $g) {
        foreach ($g['studies'] as $st) {
            $pageSids[(int)$st['study_id']] = (int)$st['study_id'];
        }
    }

    if (!empty($pageSids)) {
        $thumbMap = [];
        $origNameMap = [];
        $tStmt = $pdo->prepare("
            SELECT sf.study_id, sf.seq_no, sf.filename, sf.orig_filename
            FROM study_files sf
            JOIN studies s ON s.study_id = sf.study_id
            WHERE sf.study_id = ANY(?::bigint[])
              AND (
                  sf.seq_no = 1
                  OR sf.seq_no = GREATEST(1, (s.file_count + 1) / 2)
                  OR sf.seq_no = s.file_count
              )
            ORDER BY sf.study_id ASC, sf.seq_no ASC
        ");
        $tStmt->execute([rsnext_dup_pg_bigint_array($pageSids)]);
        foreach ($tStmt->fetchAll() as $tr) {
            $sid = (int)$tr['study_id'];
            $fn  = (string)$tr['filename'];
            if (!isset($thumbMap[$sid]) || !in_array($fn, $thumbMap[$sid], true)) {
                $thumbMap[$sid][] = $fn;
            }
            if (!isset($origNameMap[$sid]) && !empty($tr['orig_filename'])) {
                $origNameMap[$sid] = (string)$tr['orig_filename'];
            }
        }

        foreach ($pagedGroups as &$g) {
            foreach ($g['studies'] as &$st) {
                $sid = (int)$st['study_id'];
                $st['sample_thumbs']       = $thumbMap[$sid] ?? ['0001.dcm'];
                $st['first_orig_filename'] = $origNameMap[$sid] ?? '';
            }
            unset($st);
        }
        unset($g);
    }

    return [
        'kpi'         => $kpi,
        'groups'      => $pagedGroups,
        'total_match' => $totalMatch,
        'elapsed_ms'  => round((microtime(true) - $t0) * 1000, 1),
    ];
}

/**
 * 所見・コメント・施行医を残す側へ安全マージした上で、重複側の検査をゴミ箱へ移動
 */
function rsnext_dup_merge_and_trash(PDO $pdo, int $keepStudyId, array $trashStudyIds): array {
    $trashClean = [];
    foreach ($trashStudyIds as $tid) {
        $v = (int)$tid;
        if ($v > 0 && $v !== $keepStudyId) {
            $trashClean[$v] = $v;
        }
    }
    if ($keepStudyId <= 0 || empty($trashClean)) {
        throw new RuntimeException('残す検査IDとゴミ箱へ移動する検査IDを指定してください');
    }

    $allLockIds = array_merge([$keepStudyId], array_values($trashClean));
    sort($allLockIds, SORT_NUMERIC);

    $pdo->beginTransaction();
    try {
        $lockStmt = $pdo->prepare("SELECT * FROM studies WHERE study_id = ANY(?::bigint[]) FOR UPDATE");
        $lockStmt->execute([rsnext_dup_pg_bigint_array($allLockIds)]);
        $locked = [];
        foreach ($lockStmt->fetchAll() as $r) {
            $locked[(int)$r['study_id']] = $r;
        }

        if (!isset($locked[$keepStudyId]) || !empty($locked[$keepStudyId]['is_deleted'])) {
            throw new RuntimeException("残す対象の検査 #{$keepStudyId} が見つからないか既に削除されています");
        }

        $keep = $locked[$keepStudyId];
        $mergedFindings = trim((string)($keep['findings'] ?? ''));
        $mergedComment  = trim((string)($keep['comment'] ?? ''));
        $mergedDoctor   = trim((string)($keep['doctor'] ?? ''));
        $mergedDept     = trim((string)($keep['department'] ?? ''));

        $trashedDone = [];
        foreach ($trashClean as $tid) {
            if (!isset($locked[$tid]) || !empty($locked[$tid]['is_deleted'])) {
                continue;
            }
            $tr = $locked[$tid];
            $tf = trim((string)($tr['findings'] ?? ''));
            $tc = trim((string)($tr['comment'] ?? ''));
            $td = trim((string)($tr['doctor'] ?? ''));
            $tdp = trim((string)($tr['department'] ?? ''));

            if ($tf !== '' && $tf !== $mergedFindings && ($mergedFindings === '' || mb_strpos($mergedFindings, $tf, 0, 'UTF-8') === false)) {
                $mergedFindings = $mergedFindings === '' ? $tf : ($mergedFindings . "\n" . $tf);
            }
            if ($tc !== '' && $tc !== $mergedComment && ($mergedComment === '' || mb_strpos($mergedComment, $tc, 0, 'UTF-8') === false)) {
                $mergedComment = $mergedComment === '' ? $tc : ($mergedComment . ' / ' . $tc);
            }
            if ($mergedDoctor === '' && $td !== '') {
                $mergedDoctor = $td;
            }
            if ($mergedDept === '' && $tdp !== '') {
                $mergedDept = $tdp;
            }

            $pdo->prepare("UPDATE studies SET is_deleted = TRUE, deleted_at = NOW(), updated_at = NOW() WHERE study_id = ?")
                ->execute([$tid]);
            $trashedDone[] = $tid;
        }

        if (empty($trashedDone)) {
            $pdo->rollBack();
            throw new RuntimeException('移動対象の重複検査は既に整理済みです');
        }

        $pdo->prepare("
            UPDATE studies
            SET findings = ?, comment = ?, doctor = ?, department = ?, updated_at = NOW()
            WHERE study_id = ?
        ")->execute([$mergedFindings, $mergedComment, $mergedDoctor, $mergedDept, $keepStudyId]);

        rsnext_sync_study_meta_json($pdo, $keepStudyId);
        foreach ($trashedDone as $tid) {
            rsnext_sync_study_meta_json($pdo, $tid);
        }

        rsnext_log_event(
            $pdo,
            'WARN',
            'WEB_DUP_CLEAN',
            'DUPLICATE_MERGED_AND_TRASHED',
            "重複検査整理: 検査 #{$keepStudyId} を残し、重複検査 (" . implode(', #', $trashedDone) . ") の所見を統合してゴミ箱へ移動しました",
            (string)$keep['patient_id'],
            $keepStudyId,
            [
                'kept_study_id'     => $keepStudyId,
                'trashed_study_ids' => $trashedDone,
                'before_findings'   => $keep['findings'],
                'merged_findings'   => $mergedFindings,
            ]
        );

        $pdo->commit();
        return [
            'kept_study_id'     => $keepStudyId,
            'trashed_study_ids' => $trashedDone,
            'trashed_count'     => count($trashedDone),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * 選択された重複検査群を一括でゴミ箱へ移動
 * 安全ガード: $keepGuardPairs = [['keep_id' => 10, 'trash_id' => 11], ...] が渡された場合、
 * 必ずペアの残す側 (keep_id) がアクティブ(未削除)であり、同時に削除リストに含まれていないことを検証する！
 */
function rsnext_dup_bulk_trash(PDO $pdo, array $items): array {
    // $items: [['trash_id' => int, 'keep_id' => int], ...]
    $trashToKeep = [];
    foreach ($items as $it) {
        $tid = (int)($it['trash_id'] ?? 0);
        $kid = (int)($it['keep_id'] ?? 0);
        if ($tid > 0 && $kid > 0 && $tid !== $kid) {
            $trashToKeep[$tid] = $kid;
        }
    }
    if (empty($trashToKeep)) {
        throw new RuntimeException('一括整理する検査が選択されていません');
    }

    // 残す側の study_id が同時に trash 側に含まれていないか検証（グループ全滅防止ガード！）
    foreach ($trashToKeep as $tid => $kid) {
        if (isset($trashToKeep[$kid])) {
            throw new RuntimeException("安全保護ガード発動: 検査 #{$tid} の保持先である検査 #{$kid} も同時に削除選択されているため中断しました。各グループにつき最低1件は残してください。");
        }
    }

    $allIds = array_unique(array_merge(array_keys($trashToKeep), array_values($trashToKeep)));
    sort($allIds, SORT_NUMERIC);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT study_id, patient_id, kensamei, total_bytes, is_deleted FROM studies WHERE study_id = ANY(?::bigint[]) FOR UPDATE");
        $stmt->execute([rsnext_dup_pg_bigint_array($allIds)]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[(int)$r['study_id']] = $r;
        }

        $trashedIds = [];
        $freedBytes = 0;

        $upd = $pdo->prepare("UPDATE studies SET is_deleted = TRUE, deleted_at = NOW(), updated_at = NOW() WHERE study_id = ?");
        foreach ($trashToKeep as $tid => $kid) {
            if (empty($rows[$kid]) || !empty($rows[$kid]['is_deleted'])) {
                // 保持側が存在しない、または既にゴミ箱にある場合は安全のためスキップ
                continue;
            }
            if (empty($rows[$tid]) || !empty($rows[$tid]['is_deleted'])) {
                continue;
            }
            $upd->execute([$tid]);
            $trashedIds[] = $tid;
            $freedBytes += (int)($rows[$tid]['total_bytes'] ?? 0);
        }

        foreach ($trashedIds as $tid) {
            rsnext_sync_study_meta_json($pdo, $tid);
        }

        if (!empty($trashedIds)) {
            rsnext_log_event(
                $pdo,
                'WARN',
                'WEB_DUP_CLEAN',
                'DUPLICATE_BULK_TRASHED',
                '重複検査一括整理: ' . count($trashedIds) . ' 件の重複検査をゴミ箱へ移動しました (' . rsnext_dup_fmt_bytes($freedBytes) . ')',
                '',
                $trashedIds[0],
                [
                    'trashed_study_ids' => $trashedIds,
                    'trash_to_keep_map' => $trashToKeep,
                    'freed_bytes'       => $freedBytes,
                ]
            );
        }

        $pdo->commit();
        return [
            'trashed_count'     => count($trashedIds),
            'trashed_study_ids' => $trashedIds,
            'freed_bytes'       => $freedBytes,
            'freed_human'       => rsnext_dup_fmt_bytes($freedBytes),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * グループを「重複ではない（意図的な同日別検査）」として除外登録 / 解除
 */
function rsnext_dup_set_ignore(PDO $pdo, array $studyIds, bool $ignore, string $note = ''): string {
    rsnext_ensure_dup_schema($pdo);
    $clean = [];
    foreach ($studyIds as $id) {
        $v = (int)$id;
        if ($v > 0) {
            $clean[$v] = $v;
        }
    }
    if (count($clean) < 2) {
        throw new RuntimeException('対象グループの検査IDが不足しています');
    }
    $sorted = array_values($clean);
    sort($sorted, SORT_NUMERIC);
    $sig = md5(implode(',', $sorted));

    if ($ignore) {
        $stmt = $pdo->prepare("
            INSERT INTO duplicate_study_ignores (group_sig, study_ids, note, created_at)
            VALUES (?, ?::bigint[], ?, NOW())
            ON CONFLICT (group_sig) DO UPDATE SET note = EXCLUDED.note, created_at = NOW()
        ");
        $stmt->execute([$sig, rsnext_dup_pg_bigint_array($sorted), $note]);
        rsnext_log_event(
            $pdo,
            'INFO',
            'WEB_DUP_CLEAN',
            'DUPLICATE_GROUP_IGNORED',
            '重複候補グループ (#' . implode(', #', $sorted) . ') を「同日別検査（重複ではない）」として除外しました',
            '',
            $sorted[0],
            ['group_sig' => $sig, 'study_ids' => $sorted, 'note' => $note]
        );
    } else {
        $pdo->prepare("DELETE FROM duplicate_study_ignores WHERE group_sig = ?")->execute([$sig]);
    }
    return $sig;
}
