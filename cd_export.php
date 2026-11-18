<?php
// ==============================================================================
// RS-Base 患者別・任意検査 CDデータ作成システム (cd_export.php)
// - 任意の患者1名の任意の検査（内視鏡・超音波・XP・心電図）を選択してCD用パッケージを生成
// - 専用ソフト不要でダブルクリック閲覧できるオフライン高機能HTMLビューワ同梱
// - 他院PACS取込対応の原本DICOM + DICOMDIR (IHE PDI準拠) 同梱ON/OFF対応
// - クライアントPC光学ドライブへのダイレクトCD-R書き込み (watcher.vbs + Windows IMAPI2) 対応
// - ZIP一括ダウンロード ＆ 指定フォルダ直接書き出し (File System Access API) 両対応
// - データベース完全参照専用 (Read-Only SELECTのみ) 厳守
// ==============================================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/lib/bootstrap.php';

$tmp_root       = __DIR__ . '/tmp';
$dcm_cache_dir  = $tmp_root . '/dcm_cache/';
$ecg_cache_dir  = $tmp_root . '/ecg_cache/';
$cd_build_root  = $tmp_root . '/cd_build/';
$cd_demo_dir    = $tmp_root . '/cd_demo_assets/';

foreach ([$tmp_root, $dcm_cache_dir, $ecg_cache_dir, $cd_build_root] as $dir) {
    if (!file_exists($dir)) {
        @mkdir($dir, 0777, true);
    }
}

// ==============================================================================
// 共通ユーティリティ関数
// ==============================================================================
function cd_find_python_bin() {
    $candidates = [
        'C:\\Users\\owner\\AppData\\Local\\Python\\bin\\python.exe',
        'C:\\Users\\owner\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe',
        'C:\\Users\\Owner\\AppData\\Local\\Programs\\Python\\Python313\\python.exe',
        'C:\\Users\\owner\\AppData\\Local\\Programs\\Python\\Python313\\python.exe',
        'C:\\Program Files\\Python314\\python.exe',
        'C:\\Program Files\\Python313\\python.exe',
        'C:\\Python314\\python.exe',
        'C:\\Python313\\python.exe'
    ];
    foreach ($candidates as $cand) {
        if (file_exists($cand)) {
            return $cand;
        }
    }
    $where = @shell_exec('where python 2>NUL');
    if ($where) {
        foreach (explode("\n", trim($where)) as $line) {
            $line = trim($line);
            if (!empty($line) && stripos($line, 'WindowsApps') === false && file_exists($line)) {
                return $line;
            }
        }
    }
    return 'python';
}

function cd_rrmdir($dir) {
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    if (!$items) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($p)) {
            cd_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

function cd_purge_old_builds($cd_build_root, $max_age_sec = 7200) {
    if (!is_dir($cd_build_root)) return;
    $now = time();
    $entries = @scandir($cd_build_root);
    if (!$entries) return;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') continue;
        $full = rtrim($cd_build_root, '\\/') . DIRECTORY_SEPARATOR . $entry;
        $mtime = @filemtime($full) ?: $now;
        if (($now - $mtime) > $max_age_sec) {
            if (is_dir($full)) {
                cd_rrmdir($full);
            } else {
                @unlink($full);
            }
        }
    }
}

function cd_validate_job_dir($cd_build_root, $job_id) {
    if (!preg_match('/^cd_[0-9]{8}_[a-f0-9]{12}$/', $job_id)) {
        return null;
    }
    $dir = rtrim($cd_build_root, '\\/') . DIRECTORY_SEPARATOR . $job_id;
    if (!is_dir($dir)) {
        return null;
    }
    return $dir;
}

function cd_format_wareki_birth($birth_str) {
    if (empty($birth_str)) return '';
    $clean = str_replace(['-', '.'], '/', trim($birth_str));
    $parts = explode('/', $clean);
    if (count($parts) < 3) return $birth_str;
    $y = (int)$parts[0];
    $m = (int)$parts[1];
    $d = (int)$parts[2];
    if ($y <= 0 || $m <= 0 || $d <= 0) return $birth_str;
    $ymd = sprintf('%04d%02d%02d', $y, $m, $d);

    $era = '';
    $era_year = 0;
    if ($ymd >= '20190501') {
        $era = '令'; $era_year = $y - 2018;
    } elseif ($ymd >= '19890108') {
        $era = '平'; $era_year = $y - 1988;
    } elseif ($ymd >= '19261225') {
        $era = '昭'; $era_year = $y - 1925;
    } elseif ($ymd >= '19120730') {
        $era = '大'; $era_year = $y - 1911;
    } elseif ($ymd >= '18680125') {
        $era = '明'; $era_year = $y - 1867;
    }
    $era_str = $era ? ($era_year === 1 ? "{$era}元" : "{$era}{$era_year}") : '';
    $formatted = sprintf('%04d/%02d/%02d', $y, $m, $d);
    return $era_str ? "{$formatted} ({$era_str})" : $formatted;
}

function cd_classify_exam($raw_kensamei, $unc_folder) {
    $is_ecg = false;
    $category = 'other';
    $display = $raw_kensamei;
    $dicom_modality = 'OT';

    if (stripos($raw_kensamei, '心電図') !== false || stripos($raw_kensamei, 'ecg') !== false || stripos($unc_folder, '.ecg') !== false) {
        $is_ecg = true;
        $category = 'ecg';
        $display = ($raw_kensamei !== '') ? $raw_kensamei : '心電図';
        $dicom_modality = 'ECG';
    } elseif ($raw_kensamei === 'ES_General' || preg_match('/内視鏡|胃|大腸|十二指腸|食道|直腸|結腸|咽頭|喉頭|気管支|GF|CF|IF|BF|ES|カメラ|スコープ|ESD|EMR|ERCP|生検/iu', $raw_kensamei)) {
        $category = 'endoscopy';
        $display = ($raw_kensamei === 'ES_General') ? '内視鏡' : $raw_kensamei;
        $dicom_modality = 'ES';
    } elseif ($raw_kensamei === 'US_Adult_Abd_' || preg_match('/エコー|超音波|US|Adult_Abd/iu', $raw_kensamei)) {
        $category = 'us';
        $display = ($raw_kensamei === 'US_Adult_Abd_') ? '超音波 (US)' : $raw_kensamei;
        $dicom_modality = 'US';
    } elseif (preg_match('/XP|CR|DR|胸部|レントゲン|単純|RF|透視|マンモ|腰椎|腹部|骨/iu', $raw_kensamei)) {
        $category = 'xray';
        $dicom_modality = 'CR';
    }

    return [
        'is_ecg'         => $is_ecg,
        'category'       => $category,
        'display_name'   => $display,
        'dicom_modality' => $dicom_modality
    ];
}

function cd_extract_dcm_jpeg($dcm_path, $dcm_cache_dir) {
    $mtime = @filemtime($dcm_path) ?: time();
    $cache_file = $dcm_cache_dir . md5($dcm_path . '_' . $mtime . '_full') . '.jpg';

    if (file_exists($cache_file) && @filesize($cache_file) > 100) {
        $cached = @file_get_contents($cache_file);
        if ($cached !== false && substr($cached, 0, 2) === "\xFF\xD8") {
            return $cached;
        }
    }

    // 1. 埋め込みJPEGの高速抽出
    $handle = @fopen($dcm_path, 'rb');
    if ($handle) {
        $content = stream_get_contents($handle);
        fclose($handle);
        $soi = strpos($content, "\xFF\xD8\xFF");
        if ($soi !== false) {
            $eoi = strrpos($content, "\xFF\xD9");
            if ($eoi !== false && $eoi > $soi) {
                $raw_jpeg = substr($content, $soi, $eoi - $soi + 2);
                @file_put_contents($cache_file, $raw_jpeg);
                return $raw_jpeg;
            }
        }
    }

    // 2. 非圧縮DICOM (US/XP等) Pythonレンダリング
    $py_script = __DIR__ . '/dcm_render.py';
    if (file_exists($py_script)) {
        $py_bin = cd_find_python_bin();
        $temp_out = $cache_file . '.' . uniqid('cd_', true) . '.jpg';
        $cmd = escapeshellarg($py_bin) . ' ' . escapeshellarg($py_script) . ' ' . escapeshellarg($dcm_path) . ' ' . escapeshellarg($temp_out) . ' 2>&1';
        exec($cmd, $out, $ret);
        if ($ret === 0 && file_exists($temp_out) && filesize($temp_out) > 100) {
            @rename($temp_out, $cache_file);
            return @file_get_contents($cache_file);
        }
        if (file_exists($temp_out)) {
            @unlink($temp_out);
        }
    }
    return null;
}

function cd_make_thumbnail_bytes($jpeg_bytes, $max_w = 220) {
    if (!function_exists('imagecreatefromstring')) {
        return $jpeg_bytes;
    }
    $img = @imagecreatefromstring($jpeg_bytes);
    if ($img === false) {
        return $jpeg_bytes;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= $max_w) {
        imagedestroy($img);
        return $jpeg_bytes;
    }
    $tw = $max_w;
    $th = max(1, (int)round($h * ($tw / max(1, $w))));
    $thumb = imagecreatetruecolor($tw, $th);
    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
    ob_start();
    imagejpeg($thumb, null, 82);
    $out = ob_get_clean();
    imagedestroy($thumb);
    imagedestroy($img);
    return $out ?: $jpeg_bytes;
}

function cd_parse_ecg_json($ecg_path, $ecg_cache_dir) {
    if (is_dir($ecg_path)) {
        $cands = glob(rtrim($ecg_path, '\\/') . '/*.{ECG,ecg,001}', GLOB_BRACE);
        if (!empty($cands)) {
            $ecg_path = $cands[0];
        }
    }
    if (!is_file($ecg_path)) {
        return [null, null];
    }

    $mtime = @filemtime($ecg_path) ?: time();
    $cache_file = $ecg_cache_dir . md5($ecg_path . '_' . $mtime) . '.json';
    if (file_exists($cache_file)) {
        $raw = @file_get_contents($cache_file);
        $decoded = @json_decode($raw, true);
        if ($decoded && isset($decoded['data']['leads'])) {
            return [$decoded['data'], $ecg_path];
        }
    }

    $py_script = __DIR__ . '/ecg_render.py';
    if (!file_exists($py_script)) {
        return [null, $ecg_path];
    }
    $py_bin = cd_find_python_bin();
    $cmd = escapeshellarg($py_bin) . ' ' . escapeshellarg($py_script) . ' ' . escapeshellarg($ecg_path) . ' --json 2>&1';
    exec($cmd, $lines, $ret);
    if ($ret === 0 && !empty($lines)) {
        $parsed = @json_decode(implode("\n", $lines), true);
        if ($parsed && isset($parsed['leads'])) {
            return [$parsed, $ecg_path];
        }
    }
    return [null, $ecg_path];
}

/**
 * テスト環境用デモデータの自動生成（本番DB未接続環境でも全工程をテスト可能にする）
 */
function cd_ensure_demo_assets($cd_demo_dir) {
    $endo_dir = $cd_demo_dir . 'demo_endo';
    $us_dir   = $cd_demo_dir . 'demo_us';
    $xp_dir   = $cd_demo_dir . 'demo_xp';

    if (is_file($endo_dir . '/001.dcm') && is_file($us_dir . '/001.dcm') && is_file($xp_dir . '/001.dcm')) {
        return [$endo_dir, $us_dir, $xp_dir];
    }

    @mkdir($endo_dir, 0777, true);
    @mkdir($us_dir, 0777, true);
    @mkdir($xp_dir, 0777, true);

    $py_bin = cd_find_python_bin();
    $gen_py = $cd_demo_dir . 'gen_demo_dcm.py';
    $py_code = <<<'PY'
import os, sys, math
import numpy as np
from PIL import Image, ImageDraw
import pydicom
from pydicom.dataset import Dataset, FileMetaDataset
from pydicom.uid import ExplicitVRLittleEndian, SecondaryCaptureImageStorage, generate_uid

base = sys.argv[1]

def save_rgb_dcm(path, img, modality, desc, inst_no, study_uid, series_uid):
    arr = np.asarray(img.convert("RGB"), dtype=np.uint8)
    fm = FileMetaDataset()
    fm.MediaStorageSOPClassUID = SecondaryCaptureImageStorage
    fm.MediaStorageSOPInstanceUID = generate_uid()
    fm.TransferSyntaxUID = ExplicitVRLittleEndian
    ds = Dataset()
    ds.file_meta = fm
    ds.is_little_endian = True
    ds.is_implicit_VR = False
    ds.PatientID = "60934"
    ds.PatientName = "YOKOTA^KAZUHIDE"
    ds.StudyDate = "20261025"
    ds.StudyTime = "093000"
    ds.StudyID = "190852"
    ds.StudyDescription = desc
    ds.Modality = modality
    ds.StudyInstanceUID = study_uid
    ds.SeriesInstanceUID = series_uid
    ds.SeriesNumber = 1
    ds.InstanceNumber = inst_no
    ds.SOPClassUID = fm.MediaStorageSOPClassUID
    ds.SOPInstanceUID = fm.MediaStorageSOPInstanceUID
    ds.Rows, ds.Columns, _ = arr.shape
    ds.SamplesPerPixel = 3
    ds.PhotometricInterpretation = "RGB"
    ds.PlanarConfiguration = 0
    ds.BitsAllocated = 8
    ds.BitsStored = 8
    ds.HighBit = 7
    ds.PixelRepresentation = 0
    ds.PixelData = arr.tobytes()
    ds.preamble = b"\x00" * 128
    ds.save_as(path, enforce_file_format=True)

# 1. Endoscopy 4 frames
st1, se1 = generate_uid(), generate_uid()
for i in range(1, 5):
    im = Image.new("RGB", (640, 480), (12, 14, 20))
    dr = ImageDraw.Draw(im)
    dr.text((24, 30), f"ID: 60934", fill=(220, 230, 245))
    dr.text((24, 58), f"2026/10/25", fill=(180, 200, 225))
    dr.text((24, 86), f"ES #{i:02d}/04", fill=(120, 210, 255))
    cx, cy, r = 390, 240, 195
    for rad in range(r, 10, -4):
        ratio = rad / r
        rc = int(215 - 55 * (1 - ratio) + (i * 6) % 20)
        gc = int(115 - 35 * (1 - ratio))
        bc = int(105 - 30 * (1 - ratio))
        dr.ellipse((cx - rad, cy - rad, cx + rad, cy + rad), fill=(min(255, rc), max(40, gc), max(40, bc)))
    dr.ellipse((cx - 40 + i*8, cy - 25, cx + 10 + i*8, cy + 18), fill=(175, 55, 58))
    save_rgb_dcm(os.path.join(base, "demo_endo", f"{i:03d}.dcm"), im, "ES", "Endoscopy", i, st1, se1)

# 2. Ultrasound 2 frames
st2, se2 = generate_uid(), generate_uid()
for i in range(1, 3):
    im = Image.new("RGB", (640, 480), (8, 12, 18))
    dr = ImageDraw.Draw(im)
    dr.pieslice((120, -40, 520, 430), 48, 132, fill=(58, 66, 76), outline=(110, 130, 150))
    dr.ellipse((280 + i*15, 190, 355 + i*15, 245), fill=(22, 26, 32), outline=(165, 180, 195))
    dr.text((22, 22), f"US ABD  60934  #{i}", fill=(180, 225, 255))
    save_rgb_dcm(os.path.join(base, "demo_us", f"{i:03d}.dcm"), im, "US", "Ultrasound", i, st2, se2)

# 3. Chest XP 1 frame
st3, se3 = generate_uid(), generate_uid()
im = Image.new("RGB", (560, 560), (18, 20, 24))
dr = ImageDraw.Draw(im)
dr.rounded_rectangle((80, 60, 480, 500), radius=40, fill=(175, 182, 192))
dr.ellipse((125, 105, 260, 400), fill=(42, 48, 56))
dr.ellipse((300, 105, 435, 400), fill=(42, 48, 56))
dr.ellipse((235, 210, 345, 385), fill=(205, 210, 218))
dr.text((24, 24), "CR CHEST PA  ID:60934", fill=(235, 240, 250))
save_rgb_dcm(os.path.join(base, "demo_xp", "001.dcm"), im, "CR", "Chest XP", 1, st3, se3)
PY;
    @file_put_contents($gen_py, $py_code);
    $cmd = escapeshellarg($py_bin) . ' ' . escapeshellarg($gen_py) . ' ' . escapeshellarg($cd_demo_dir) . ' 2>&1';
    exec($cmd, $o, $r);
    return [$endo_dir, $us_dir, $xp_dir];
}

function cd_generate_demo_ecg_data() {
    $sr = 500;
    $n = 2500; // 5.0 sec
    $leadI = [];
    $leadII = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $i / $sr;
        $phase = fmod($t, 0.88); // ~68 bpm
        $v = 0.0;
        // P wave
        $v += 0.11 * exp(-pow(($phase - 0.18) / 0.032, 2));
        // Q wave
        $v -= 0.09 * exp(-pow(($phase - 0.31) / 0.010, 2));
        // R wave
        $v += 1.05 * exp(-pow(($phase - 0.335) / 0.014, 2));
        // S wave
        $v -= 0.22 * exp(-pow(($phase - 0.362) / 0.013, 2));
        // T wave
        $v += 0.28 * exp(-pow(($phase - 0.56) / 0.055, 2));

        $leadI[]  = round($v * 0.65, 4);
        $leadII[] = round($v * 1.05, 4);
    }
    $leads = [
        'I'   => $leadI,
        'II'  => $leadII,
        'III' => [],
        'aVR' => [],
        'aVL' => [],
        'aVF' => [],
        'V1'  => [], 'V2' => [], 'V3' => [], 'V4' => [], 'V5' => [], 'V6' => []
    ];
    $vScales = ['V1' => -0.45, 'V2' => -0.15, 'V3' => 0.45, 'V4' => 0.95, 'V5' => 1.15, 'V6' => 0.85];
    for ($i = 0; $i < $n; $i++) {
        $v1 = $leadI[$i];
        $v2 = $leadII[$i];
        $leads['III'][] = round($v2 - $v1, 4);
        $leads['aVR'][] = round(-($v1 + $v2) / 2.0, 4);
        $leads['aVL'][] = round(($v1 - $v2) / 2.0, 4);
        $leads['aVF'][] = round(($v2 - $v1) / 2.0, 4);
        foreach ($vScales as $vn => $sc) {
            $leads[$vn][] = round($v2 * $sc, 4);
        }
    }
    return [
        'device' => 'Fukuda Denshi FCP-8800 (Sample)',
        'date'   => '2026/10/19 10:15',
        'sample_rate_hz' => 500,
        'duration_sec'   => 5.0,
        'total_samples_per_lead' => $n,
        'measurements' => [
            'hr'       => 68,
            'rr_sec'   => 0.882,
            'pr_sec'   => 0.156,
            'qrs_sec'  => 0.088,
            'qt_sec'   => 0.384,
            'qtc_b'    => 0.409,
            'qtc_f'    => 0.400,
            'axis_deg' => 52
        ],
        'leads' => $leads
    ];
}

// ==============================================================================
// API アクション処理
// ==============================================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ① 患者横断検索 (AJAX, RS-Next UTF-8 pg_trgm 高速検索)
if ($action === 'search_patient') {
    ob_start();
    header('Content-Type: application/json; charset=UTF-8');
    $query = trim((string)($_POST['query'] ?? $_GET['query'] ?? ''));
    if ($query === '') {
        ob_clean();
        echo json_encode(['status' => 'success', 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = rsnext_pdo();
        $normId = rsnext_normalize_patient_id($query);
        $yomiNorm = rsnext_normalize_kana($query);

        $sql = "SELECT p.patient_id AS id, p.kanja_kanji, p.kanja_yomi,
                       TO_CHAR(MAX(s.study_date), 'YYYY/MM/DD') AS latest_date,
                       COUNT(s.study_id) AS exam_count
                FROM patients p
                LEFT JOIN studies s ON s.patient_id = p.patient_id AND NOT s.is_deleted
                WHERE p.patient_id = ?
                   OR p.patient_id LIKE ?
                   OR p.kanja_kanji ILIKE ?
                   OR p.kanja_yomi_norm ILIKE ?
                   OR p.kanja_yomi ILIKE ?
                GROUP BY p.patient_id, p.kanja_kanji, p.kanja_yomi
                ORDER BY (p.patient_id = ?) DESC, latest_date DESC NULLS LAST, p.patient_no_int ASC NULLS LAST
                LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $idEq = $normId ?? $query;
        $stmt->execute([
            $idEq,
            '%' . $idEq . '%',
            '%' . $query . '%',
            '%' . ($yomiNorm ?: $query) . '%',
            '%' . $query . '%',
            $idEq,
        ]);

        $patients = [];
        foreach ($stmt->fetchAll() as $r) {
            $patients[] = [
                'id'          => (string)$r['id'],
                'kanja_kanji' => (string)$r['kanja_kanji'],
                'kanja_yomi'  => (string)$r['kanja_yomi'],
                'latest_date' => (string)($r['latest_date'] ?? ''),
                'exam_count'  => (int)$r['exam_count'],
            ];
        }
        ob_clean();
        echo json_encode(['status' => 'success', 'data' => $patients], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'DB検索エラー: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ② CDパッケージビルド開始 (init_job)
if ($action === 'init_job') {
    ob_start();
    header('Content-Type: application/json; charset=UTF-8');
    cd_purge_old_builds($cd_build_root, 7200);

    $raw_payload = file_get_contents('php://input');
    $payload = @json_decode($raw_payload, true);
    if (!$payload || empty($payload['patient'])) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'リクエストパラメータが不正です'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $job_id = 'cd_' . date('Ymd') . '_' . bin2hex(random_bytes(6));
    $job_dir = $cd_build_root . $job_id;
    @mkdir($job_dir . '/viewer_data/images', 0777, true);

    $state = [
        'job_id'     => $job_id,
        'created_at' => date('Y/m/d H:i'),
        'patient'    => $payload['patient'],
        'options'    => [
            'include_dicom'    => !empty($payload['options']['include_dicom']),
            'include_findings' => !empty($payload['options']['include_findings']),
            'issuer'           => isset($payload['options']['issuer'])
                                  ? trim((string)$payload['options']['issuer'])
                                  : 'おの肛門科胃腸科内視鏡クリニック',
            'note'             => trim((string)($payload['options']['note'] ?? ''))
        ],
        'viewer_exams'  => [],
        'dicom_studies' => []
    ];
    file_put_contents($job_dir . '/_job_state.json', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    ob_clean();
    echo json_encode(['status' => 'success', 'job_id' => $job_id], JSON_UNESCAPED_UNICODE);
    exit;
}

// ③ 検査1件分の変換・パッケージ格納 (process_exam)
if ($action === 'process_exam') {
    @set_time_limit(180);
    ob_start();
    header('Content-Type: application/json; charset=UTF-8');

    $payload = @json_decode(file_get_contents('php://input'), true);
    $job_id = $payload['job_id'] ?? '';
    $job_dir = cd_validate_job_dir($cd_build_root, $job_id);
    if (!$job_dir || !is_file($job_dir . '/_job_state.json')) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'ビルドジョブが見つかりません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $state = json_decode(file_get_contents($job_dir . '/_job_state.json'), true);
    $exam = $payload['exam'] ?? [];
    $exam_index = (int)($payload['exam_index'] ?? (count($state['viewer_exams']) + 1));

    $renban   = (string)($exam['renban'] ?? $exam_index);
    $kensamei = (string)($exam['kensamei'] ?? '検査');
    $category = (string)($exam['category'] ?? 'other');
    $mod_code = (string)($exam['dicom_modality'] ?? 'OT');
    $date     = (string)($exam['date'] ?? '');
    $time     = (string)($exam['time'] ?? '');
    $doctor   = (string)($exam['doctor'] ?? '');
    $sindan   = $state['options']['include_findings'] ? (string)($exam['sindan'] ?? '') : '';
    $folder   = rtrim(str_replace('/', '\\', trim((string)($exam['folder'] ?? ''))), '\\');
    $is_ecg   = !empty($exam['is_ecg']);
    $is_demo  = !empty($exam['is_demo']);

    if ($is_ecg) {
        $ecg_data = null;
        $resolved_ecg_file = null;
        if ($is_demo && ($folder === '' || !file_exists($folder))) {
            $ecg_data = cd_generate_demo_ecg_data();
        } else {
            list($ecg_data, $resolved_ecg_file) = cd_parse_ecg_json($folder, $ecg_cache_dir);
        }

        if (!$ecg_data) {
            ob_clean();
            echo json_encode([
                'status'  => 'warning',
                'message' => "心電図ファイルの解析をスキップしました ({$kensamei} {$date})",
                'added'   => 0
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $state['viewer_exams'][] = [
            'id_key'   => 'ex_' . $exam_index . '_' . $renban,
            'renban'   => $renban,
            'type'     => 'ecg',
            'category' => 'ecg',
            'kensamei' => $kensamei,
            'date'     => $date,
            'time'     => $time,
            'doctor'   => $doctor,
            'sindan'   => $sindan,
            'ecg_data' => $ecg_data
        ];

        $state['dicom_studies'][] = [
            'renban'        => $renban,
            'date'          => $date,
            'time'          => $time,
            'modality_code' => 'ECG',
            'description'   => $kensamei,
            'dcm_files'     => [],
            'ecg_files'     => $resolved_ecg_file ? [$resolved_ecg_file] : []
        ];

        file_put_contents($job_dir . '/_job_state.json', json_encode($state, JSON_UNESCAPED_UNICODE));
        ob_clean();
        echo json_encode(['status' => 'success', 'type' => 'ecg', 'added' => 1], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 画像系検査 (DICOM等)
    if (empty($folder) || !is_dir($folder)) {
        ob_clean();
        echo json_encode([
            'status'  => 'warning',
            'message' => "検査フォルダにアクセスできませんでした: {$date} {$kensamei}",
            'added'   => 0
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $candidates = [];
    $dh = @opendir($folder);
    if ($dh) {
        while (($f = readdir($dh)) !== false) {
            if ($f === '.' || $f === '..' || strtolower($f) === 'study_meta.json') continue;
            $fp = $folder . '\\' . $f;
            if (is_dir($fp)) continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if ($ext === 'dcm' || $ext === '' || $ext === 'jpg' || $ext === 'jpeg' || $ext === 'png') {
                $candidates[] = $f;
            }
        }
        closedir($dh);
    }
    usort($candidates, 'strnatcasecmp');

    $rel_exam_dir = sprintf('ex_%02d_%s', $exam_index, preg_replace('/[^0-9A-Za-z]/', '', $renban) ?: $exam_index);
    $abs_exam_dir = $job_dir . '/viewer_data/images/' . $rel_exam_dir;
    $abs_thumb_dir = $abs_exam_dir . '/thumbs';
    @mkdir($abs_thumb_dir, 0777, true);

    $viewer_images = [];
    $dcm_sources = [];
    $img_seq = 0;

    foreach ($candidates as $fname) {
        $src_path = $folder . '\\' . $fname;
        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        $jpeg_bytes = null;

        if ($ext === 'jpg' || $ext === 'jpeg') {
            $jpeg_bytes = @file_get_contents($src_path);
        } elseif ($ext === 'png' && function_exists('imagecreatefrompng')) {
            $im = @imagecreatefrompng($src_path);
            if ($im) {
                ob_start();
                imagejpeg($im, null, 90);
                $jpeg_bytes = ob_get_clean();
                imagedestroy($im);
            }
        } else {
            $jpeg_bytes = cd_extract_dcm_jpeg($src_path, $dcm_cache_dir);
            $dcm_sources[] = $src_path;
        }

        if ($jpeg_bytes && strlen($jpeg_bytes) > 100) {
            $img_seq++;
            $out_jpg_name = sprintf('%03d.jpg', $img_seq);
            file_put_contents($abs_exam_dir . '/' . $out_jpg_name, $jpeg_bytes);
            $thumb_bytes = cd_make_thumbnail_bytes($jpeg_bytes, 220);
            file_put_contents($abs_thumb_dir . '/' . $out_jpg_name, $thumb_bytes);

            $viewer_images[] = [
                'name'  => sprintf('#%03d (%s)', $img_seq, $fname),
                'src'   => 'viewer_data/images/' . $rel_exam_dir . '/' . $out_jpg_name,
                'thumb' => 'viewer_data/images/' . $rel_exam_dir . '/thumbs/' . $out_jpg_name
            ];
        }
    }

    $state['viewer_exams'][] = [
        'id_key'   => 'ex_' . $exam_index . '_' . $renban,
        'renban'   => $renban,
        'type'     => 'image',
        'category' => $category,
        'kensamei' => $kensamei,
        'date'     => $date,
        'time'     => $time,
        'doctor'   => $doctor,
        'sindan'   => $sindan,
        'images'   => $viewer_images
    ];

    $state['dicom_studies'][] = [
        'renban'        => $renban,
        'date'          => $date,
        'time'          => $time,
        'modality_code' => $mod_code,
        'description'   => $kensamei,
        'dcm_files'     => $dcm_sources,
        'ecg_files'     => []
    ];

    file_put_contents($job_dir . '/_job_state.json', json_encode($state, JSON_UNESCAPED_UNICODE));
    ob_clean();
    echo json_encode([
        'status' => 'success',
        'type'   => 'image',
        'added'  => count($viewer_images)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ④ パッケージ最終生成 (HTMLビューワ配置・DICOMDIR生成・README作成)
if ($action === 'finalize_job') {
    @set_time_limit(240);
    ob_start();
    header('Content-Type: application/json; charset=UTF-8');

    $payload = @json_decode(file_get_contents('php://input'), true);
    $job_id = $payload['job_id'] ?? '';
    $job_dir = cd_validate_job_dir($cd_build_root, $job_id);
    if (!$job_dir || !is_file($job_dir . '/_job_state.json')) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'ジョブ状態ファイルが見つかりません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $state = json_decode(file_get_contents($job_dir . '/_job_state.json'), true);
    $patient = $state['patient'];
    $options = $state['options'];
    $dicom_stats = null;

    // 1. 原本DICOM + DICOMDIR 同梱処理
    if (!empty($options['include_dicom']) && !empty($state['dicom_studies'])) {
        $manifest_file = $job_dir . '/_dicom_manifest.json';
        $dcm_manifest = [
            'out_root' => $job_dir,
            'patient'  => [
                'id'   => $patient['id'] ?? '',
                'name' => $patient['name'] ?? '',
                'sex'  => $patient['sex'] ?? ''
            ],
            'studies'  => $state['dicom_studies']
        ];
        file_put_contents($manifest_file, json_encode($dcm_manifest, JSON_UNESCAPED_UNICODE));

        $py_bin = cd_find_python_bin();
        $py_script = __DIR__ . '/cd_dicomdir.py';
        if (file_exists($py_script)) {
            $cmd = escapeshellarg($py_bin) . ' ' . escapeshellarg($py_script) . ' ' . escapeshellarg($manifest_file) . ' 2>&1';
            exec($cmd, $py_out, $py_ret);
            $parsed_py = @json_decode(implode("\n", $py_out), true);
            if ($parsed_py) {
                $dicom_stats = $parsed_py;
            }
        }
        @unlink($manifest_file);
    }

    // 2. オフラインビューワ用 patient_data.js の出力（内部パス情報は一切出力しない）
    $cd_js_payload = [
        'patient' => [
            'id'    => (string)($patient['id'] ?? ''),
            'name'  => (string)($patient['name'] ?? ''),
            'yomi'  => (string)($patient['yomi'] ?? ''),
            'sex'   => (string)($patient['sex'] ?? ''),
            'age'   => (string)($patient['age'] ?? ''),
            'birth' => (string)($patient['birth'] ?? '')
        ],
        'meta' => [
            'created_at'       => date('Y/m/d'),
            'issuer'           => (string)($options['issuer'] ?? ''),
            'note'             => (string)($options['note'] ?? ''),
            'include_findings' => !empty($options['include_findings']),
            'include_dicom'    => !empty($options['include_dicom'])
        ],
        'exams' => $state['viewer_exams']
    ];
    $js_content = "// RS-Base Portable Medical Viewer Data (Offline Ready)\r\n"
                . "window.RS_CD_DATA = " . json_encode($cd_js_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\r\n";
    file_put_contents($job_dir . '/viewer_data/patient_data.js', $js_content);

    // 3. 閲覧を開く.html & index.html の配置
    $template_path = __DIR__ . '/cd_viewer_template.html';
    $viewer_html = file_exists($template_path) ? file_get_contents($template_path) : '<html><body>Viewer template missing</body></html>';
    file_put_contents($job_dir . '/閲覧を開く.html', $viewer_html);

    $redirect_html = <<<'HTML'
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0; url=閲覧を開く.html">
    <title>医療法人小野会 オリジナルビューワー</title>
    <style>
        body { font-family: "Yu Gothic UI", "Meiryo", sans-serif; background: #0b1325; color: #f8fafc; display: flex; flex-direction: column; gap: 14px; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .sub { font-size: 0.88rem; color: #93c5fd; letter-spacing: 0.06em; }
        a { background: linear-gradient(135deg, #0284c7, #1d4ed8); color: #fff; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: bold; font-size: 1.08rem; box-shadow: 0 4px 16px rgba(2, 132, 199, 0.4); }
    </style>
</head>
<body>
    <div class="sub">🏥 医療法人小野会 おの肛門科胃腸科内視鏡クリニック</div>
    <a href="閲覧を開く.html">💿 医療法人小野会 オリジナルビューワーを開く</a>
</body>
</html>
HTML;
    file_put_contents($job_dir . '/index.html', $redirect_html);

    // 4. autorun.inf (Windowsエクスプローラー表示補助)
    $autorun = "[autorun]\r\nlabel=Onokai_Medical_Viewer\r\n";
    file_put_contents($job_dir . '/autorun.inf', $autorun);

    // 5. README_閲覧方法.txt (UTF-8 BOM付きでWindowsメモ帳文字化け完全防止)
    $readme_lines = [
        "======================================================================",
        "  医療検査データ ディスク 閲覧のご案内",
        "  【医療法人小野会 オリジナルビューワー 収録】",
        "======================================================================",
        "",
        "【かんたん閲覧手順（専用ソフトのインストールは不要です）】",
        "  1. このディスク内にある「閲覧を開く.html」をダブルクリックしてください。",
        "  2. お使いのWebブラウザ（Microsoft Edge / Google Chrome / Safari 等）が",
        "     立ち上がり、「医療法人小野会 オリジナルビューワー」にて検査画像・",
        "     内視鏡強調表示・12誘導心電図波形をすぐにご覧いただけます。",
        "",
        "----------------------------------------------------------------------",
        "■ 対象患者様情報",
        "  患者ID   : " . ($patient['id'] ?? '-'),
        "  お名前   : " . ($patient['name'] ?? '-') . " 様",
        "  作成日   : " . date('Y年m月d日'),
    ];
    if (!empty($options['issuer'])) {
        $readme_lines[] = "  作成施設 : " . $options['issuer'];
    }
    if (!empty($options['note'])) {
        $readme_lines[] = "  ご案内   : " . $options['note'];
    }
    $readme_lines[] = "";
    $readme_lines[] = "■ 収録検査一覧 (" . count($state['viewer_exams']) . " 件)";
    foreach ($state['viewer_exams'] as $idx => $vex) {
        $cnt = ($vex['type'] === 'ecg') ? '12誘導心電図波形' : (count($vex['images'] ?? []) . ' 枚');
        $num = $idx + 1;
        $readme_lines[] = "  {$num}. [{$vex['date']}] {$vex['kensamei']} ({$cnt})";
    }
    if (!empty($options['include_dicom'])) {
        $readme_lines[] = "";
        $readme_lines[] = "■ 医療機関・PACSご担当者様へ";
        $readme_lines[] = "  ルート直下に DICOM Part 10 / PDI 準拠の「DICOMDIR」および原本DICOMデータ";
        $readme_lines[] = "  を収録しております。各種PACS・DICOMビューワからのメディア取込に対応しています。";
    }
    $readme_lines[] = "======================================================================";
    file_put_contents($job_dir . '/README_閲覧方法.txt', "\xEF\xBB\xBF" . implode("\r\n", $readme_lines) . "\r\n");

    // 内部作業用ステートの削除（CDに含めない）
    @unlink($job_dir . '/_job_state.json');

    // ファイル一覧・合計サイズ集計
    $total_files = 0;
    $total_bytes = 0;
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($job_dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $fileInfo) {
        if ($fileInfo->isFile()) {
            $total_files++;
            $total_bytes += $fileInfo->getSize();
        }
    }

    ob_clean();
    echo json_encode([
        'status'      => 'success',
        'job_id'      => $job_id,
        'total_files' => $total_files,
        'total_bytes' => $total_bytes,
        'preview_url' => 'tmp/cd_build/' . rawurlencode($job_id) . '/' . rawurlencode('閲覧を開く.html'),
        'zip_url'     => 'cd_export.php?action=download_zip&job_id=' . rawurlencode($job_id)
                       . '&pat_id=' . rawurlencode((string)($patient['id'] ?? ''))
                       . '&pat_name=' . rawurlencode((string)($patient['name'] ?? '')),
        'dicom_stats' => $dicom_stats
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ⑤ 直接書き出し用ファイルマニフェスト取得 (action=manifest)
if ($action === 'manifest') {
    ob_start();
    header('Content-Type: application/json; charset=UTF-8');
    $job_id = $_GET['job_id'] ?? '';
    $job_dir = cd_validate_job_dir($cd_build_root, $job_id);
    if (!$job_dir) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'ビルドデータが見つかりません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $files = [];
    $base_len = strlen(rtrim($job_dir, '\\/')) + 1;
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($job_dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $fi) {
        if ($fi->isFile()) {
            $rel = str_replace('\\', '/', substr($fi->getPathname(), $base_len));
            if (str_starts_with(basename($rel), '_')) continue;
            $files[] = [
                'path' => $rel,
                'size' => $fi->getSize()
            ];
        }
    }
    ob_clean();
    echo json_encode(['status' => 'success', 'files' => $files], JSON_UNESCAPED_UNICODE);
    exit;
}

// ⑥ 直接書き出し用個別ファイル配信 (action=get_file)
if ($action === 'get_file') {
    $job_id = $_GET['job_id'] ?? '';
    $rel_path = str_replace('\\', '/', $_GET['path'] ?? '');
    $job_dir = cd_validate_job_dir($cd_build_root, $job_id);
    if (!$job_dir || $rel_path === '' || strpos($rel_path, '..') !== false) {
        http_response_code(400);
        exit('Invalid path');
    }
    $real_root = realpath($job_dir);
    $target = realpath($job_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel_path));
    if (!$real_root || !$target || !str_starts_with($target, $real_root) || !is_file($target)) {
        http_response_code(404);
        exit('File not found');
    }
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($target));
    readfile($target);
    exit;
}

function cd_ensure_job_zip($cd_build_root, $job_id, $job_dir) {
    $zip_path = $cd_build_root . $job_id . '.zip';
    if (!is_file($zip_path)) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $base_len = strlen(rtrim($job_dir, '\\/')) + 1;
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($job_dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $fi) {
            if ($fi->isFile()) {
                $rel = str_replace('\\', '/', substr($fi->getPathname(), $base_len));
                if (str_starts_with(basename($rel), '_')) continue;
                $zip->addFile($fi->getPathname(), $rel);
            }
        }
        $zip->close();
    }
    return is_file($zip_path) ? $zip_path : null;
}

function cd_get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if ($ip === '::1') {
        $ip = '127.0.0.1';
    }
    return preg_replace('/[^0-9a-fA-F.:]/', '', $ip) ?: '127.0.0.1';
}

function cd_get_base_http_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $http_host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_ADDR'] ?? '192.168.1.16');
    $script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/rsnext/cd_export.php')), '/');
    return $scheme . '://' . $http_host . $script_dir;
}

// ⑦ ZIP一括ダウンロード配信 (action=download_zip)
if ($action === 'download_zip') {
    @set_time_limit(240);
    $job_id = $_GET['job_id'] ?? '';
    $job_dir = cd_validate_job_dir($cd_build_root, $job_id);
    if (!$job_dir) {
        http_response_code(404);
        exit('指定されたCDパッケージの有効期限が切れているか存在しません。再度作成してください。');
    }

    $pat_id = preg_replace('/[^0-9A-Za-z_-]/', '', $_GET['pat_id'] ?? 'patient');
    $raw_pat_name = trim($_GET['pat_name'] ?? '');
    $safe_name = preg_replace('/[\\\\\/:*?"<>|\s]+/u', '_', $raw_pat_name);
    $download_name = 'CD_' . ($pat_id ?: 'ID') . ($safe_name ? '_' . $safe_name : '') . '_' . date('Ymd') . '.zip';

    $zip_path = cd_ensure_job_zip($cd_build_root, $job_id, $job_dir);
    if (!$zip_path) {
        http_response_code(500);
        exit('ZIPファイルの作成に失敗しました');
    }

    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode($download_name) . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
    header('Content-Length: ' . filesize($zip_path));
    header('Pragma: no-cache');
    readfile($zip_path);
    exit;
}

// ⑧ クライアントPC光学ドライブへのダイレクトCD-R書き込みトリガー送信 (action=trigger_cd_burn)
if ($action === 'trigger_cd_burn') {
    @set_time_limit(120);
    ob_start();
    header('Content-Type: application/json; charset=UTF-8');

    $payload = @json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $job_id  = $payload['job_id'] ?? '';
    $job_dir = cd_validate_job_dir($cd_build_root, $job_id);
    if (!$job_dir) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'ビルド済みCDデータが見つかりません。再度作成してください。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // クライアントからのHTTP取得が即座に完了するよう、あらかじめZIPを生成しておく
    cd_ensure_job_zip($cd_build_root, $job_id, $job_dir);

    $client_ip = cd_get_client_ip();
    $base_url  = cd_get_base_http_url();
    $http_host = $_SERVER['HTTP_HOST'] ?? '';

    $pat_id_raw   = trim((string)($payload['pat_id'] ?? ''));
    $pat_name_raw = trim((string)($payload['pat_name'] ?? ''));

    $zip_url = $base_url . '/cd_export.php?action=download_zip&job_id=' . rawurlencode($job_id)
             . '&pat_id=' . rawurlencode($pat_id_raw);

    $is_local_req = ($client_ip === '127.0.0.1')
                 || (stripos($http_host, 'localhost') !== false)
                 || (stripos($http_host, '127.0.0.1') !== false);
    // リモート端末の場合は高速・認証不要な HTTP ($zip_url) 経由で即座にパッケージ取得させる
    $source_dir = $is_local_req ? str_replace('/', '\\', $job_dir) : '';

    $safe_id = preg_replace('/[^A-Za-z0-9]/', '', $pat_id_raw);
    $vol_name = $safe_id !== '' ? substr('MED_CD_' . $safe_id, 0, 16) : ('MED_CD_' . date('md'));

    $pat_label_raw = ($pat_id_raw !== '' ? "ID:{$pat_id_raw} " : '') . ($pat_name_raw !== '' ? "{$pat_name_raw} 様" : '');
    $pat_label_enc = rawurlencode($pat_label_raw);

    // 放置された古いトリガーファイル (24時間以上経過) の清掃
    if (mt_rand(1, 20) === 1) {
        $now = time();
        $old_triggers = array_merge(
            glob($tmp_root . '/trigger_*.txt') ?: [],
            glob($tmp_root . '/cdburn_*.txt') ?: [],
            glob($tmp_root . '/*.hubmeta.json') ?: []
        );
        foreach ($old_triggers as $ot) {
            if (($now - @filemtime($ot)) > 86400) {
                @unlink($ot);
            }
        }
    }

    // 192.168.1.16/rsbase の既存常駐 watcher.vbs と完全共用するためローカル＋共通ハブ両方へ配信
    $trigger_line = implode('|', ['CDBURN', $zip_url, $source_dir, $vol_name, $pat_label_enc]);
    $written = rsnext_write_client_trigger('cdburn', $client_ip, $trigger_line, $tmp_root);

    $launcher_url = 'cd_export.php?action=download_burn_launcher&job_id=' . rawurlencode($job_id)
                  . '&pat_id=' . rawurlencode($pat_id_raw)
                  . '&pat_name=' . rawurlencode($pat_name_raw);

    ob_clean();
    if ($written === false) {
        echo json_encode([
            'status'       => 'error',
            'message'      => 'トリガーファイルの書き込みに失敗しました',
            'launcher_url' => $launcher_url
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'status'       => 'success',
            'client_ip'    => $client_ip,
            'vol_name'     => $vol_name,
            'launcher_url' => $launcher_url
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ⑨ 常駐スクリプト (192.168.1.16/rsbase 共通 watcher.vbs) によるCD書込トリガー消費確認 (action=check_trigger)
if ($action === 'check_trigger') {
    ob_start();
    header('Content-Type: application/json; charset=UTF-8');

    $client_ip = cd_get_client_ip();
    $cleanup = !empty($_GET['cleanup']) || !empty($_POST['cleanup']);
    $consumed = rsnext_check_client_trigger_consumed('cdburn', $client_ip, $tmp_root, $cleanup);

    ob_clean();
    echo json_encode([
        'status'    => 'success',
        'consumed'  => $consumed,
        'client_ip' => $client_ip
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ⑨-b 共有フォルダ未設定PC向け HTTPポーリング取得 (action=poll_cdburn)
if ($action === 'poll_cdburn') {
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $client_ip = cd_get_client_ip();
    $burn_file = $tmp_root . '/cdburn_' . $client_ip . '.txt';
    if (is_file($burn_file)) {
        $line = trim((string)@file_get_contents($burn_file));
        @unlink($burn_file);
        echo $line;
        exit;
    }

    $viewer_file = $tmp_root . '/trigger_' . $client_ip . '.txt';
    if (is_file($viewer_file)) {
        $line = trim((string)@file_get_contents($viewer_file));
        @unlink($viewer_file);
        echo $line;
        exit;
    }

    echo '';
    exit;
}

// ⑩ PC連携ツール未導入PC用 CD書き込み起動ファイル (.cmd) 配信 (action=download_burn_launcher)
if ($action === 'download_burn_launcher') {
    $job_id  = $_GET['job_id'] ?? '';
    $job_dir = cd_validate_job_dir($cd_build_root, $job_id);
    if (!$job_dir) {
        http_response_code(404);
        exit('指定されたCDパッケージの有効期限が切れているか存在しません。再度作成してください。');
    }

    cd_ensure_job_zip($cd_build_root, $job_id, $job_dir);

    $base_url     = cd_get_base_http_url();
    $pat_id_raw   = trim((string)($_GET['pat_id'] ?? ''));
    $pat_name_raw = trim((string)($_GET['pat_name'] ?? ''));

    $ps1_url = $base_url . '/cd_burn.ps1?v=' . (@filemtime(__DIR__ . '/cd_burn.ps1') ?: time());
    $zip_url = $base_url . '/cd_export.php?action=download_zip&job_id=' . rawurlencode($job_id)
             . '&pat_id=' . rawurlencode($pat_id_raw);

    $safe_id  = preg_replace('/[^A-Za-z0-9]/', '', $pat_id_raw);
    $vol_name = $safe_id !== '' ? substr('MED_CD_' . $safe_id, 0, 16) : ('MED_CD_' . date('md'));
    $pat_label_raw = ($pat_id_raw !== '' ? "ID:{$pat_id_raw} " : '') . ($pat_name_raw !== '' ? "{$pat_name_raw} 様" : '');
    $pat_label_enc = rawurlencode($pat_label_raw);

    // バッチファイル内で % が環境変数展開されないよう %% にエスケープ
    $ps1_url_bat       = str_replace('%', '%%', $ps1_url);
    $zip_url_bat       = str_replace('%', '%%', $zip_url);
    $pat_label_enc_bat = str_replace('%', '%%', $pat_label_enc);

    $cmd_lines = [
        '@echo off',
        'setlocal',
        'chcp 932 >nul',
        'title RS-Base 検査CD ダイレクト書き込み起動中...',
        'echo ========================================================',
        'echo   RS-Base 検査CD ダイレクト書き込みツールを起動中...',
        'echo ========================================================',
        'set "PS1_TMP=%TEMP%\\rsbase_cd_burn.ps1"',
        'set "PS1_URL=' . $ps1_url_bat . '"',
        'powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference=\'SilentlyContinue\'; (New-Object System.Net.WebClient).DownloadFile($env:PS1_URL, $env:PS1_TMP)"',
        'if not exist "%PS1_TMP%" (',
        '    echo [エラー] サーバーから書き込みツールの取得に失敗しました。',
        '    pause',
        '    exit /b 1',
        ')',
        'start "" powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -STA -File "%PS1_TMP%" -ZipUrl "' . $zip_url_bat . '" -VolumeName "' . $vol_name . '" -PatientLabel "' . $pat_label_enc_bat . '"',
        'exit /b 0'
    ];
    $cmd_cp932 = mb_convert_encoding(implode("\r\n", $cmd_lines) . "\r\n", 'CP932', 'UTF-8');
    $dl_name = 'CD書き込み起動_' . ($safe_id ?: date('Ymd')) . '.cmd';

    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($dl_name) . '"; filename*=UTF-8\'\'' . rawurlencode($dl_name));
    header('Content-Length: ' . strlen($cmd_cp932));
    header('Pragma: no-cache');
    echo $cmd_cp932;
    exit;
}

// ==============================================================================
// 画面初期表示: 患者情報 & 全検査一覧の取得 (完全Read-Only)
// ==============================================================================
$raw_patient_id = $_GET['patient_id'] ?? $_GET['patient_no'] ?? $_GET['id'] ?? '';
$patient_id     = preg_replace('/^0+([0-9]+)$/', '$1', trim($raw_patient_id));
$is_demo_mode   = isset($_GET['demo']) && $_GET['demo'] === '1';

$patient_info = null;
$exam_list    = [];
$db_error     = '';

if ($is_demo_mode) {
    list($endo_dir, $us_dir, $xp_dir) = cd_ensure_demo_assets($cd_demo_dir);
    $patient_info = [
        'id'           => '60934',
        'kanja_kanji'  => '横田　和秀',
        'kanja_yomi'   => 'ヨコタ カズヒデ',
        'sex'          => '男',
        'age'          => '64',
        'birth'        => '1954/03/12',
        'birth_wareki' => cd_format_wareki_birth('1954/03/12')
    ];
    $exam_list = [
        [
            'renban'         => '194298',
            'kensamei'       => '心電図',
            'raw_kensamei'   => '心電図(D)',
            'category'       => 'ecg',
            'dicom_modality' => 'ECG',
            'is_ecg'         => true,
            'date'           => '2026/10/19',
            'time'           => '10:15',
            'doctor'         => '小野内科',
            'sindan'         => '正常洞調律 (心拍数 68/分)',
            'folder'         => '',
            'is_demo'        => true
        ],
        [
            'renban'         => '190852',
            'kensamei'       => '内視鏡',
            'raw_kensamei'   => 'ES_General',
            'category'       => 'endoscopy',
            'dicom_modality' => 'ES',
            'is_ecg'         => false,
            'date'           => '2026/10/25',
            'time'           => '09:30',
            'doctor'         => '小野内科',
            'sindan'         => '胃体部小彎 瘢痕期(S2) 経過良好',
            'folder'         => $endo_dir,
            'is_demo'        => true
        ],
        [
            'renban'         => '173061',
            'kensamei'       => '超音波 (US)',
            'raw_kensamei'   => 'US_Adult_Abd_',
            'category'       => 'us',
            'dicom_modality' => 'US',
            'is_ecg'         => false,
            'date'           => '2026/05/28',
            'time'           => '11:05',
            'doctor'         => '小野内科',
            'sindan'         => '胆嚢ポリープ(2mm)・脂肪肝軽度',
            'folder'         => $us_dir,
            'is_demo'        => true
        ],
        [
            'renban'         => '158849',
            'kensamei'       => '胸部XP',
            'raw_kensamei'   => '胸部(D)',
            'category'       => 'xray',
            'dicom_modality' => 'CR',
            'is_ecg'         => false,
            'date'           => '2025/12/19',
            'time'           => '14:20',
            'doctor'         => '小野内科',
            'sindan'         => '心拡大なし・肺野異常影なし',
            'folder'         => $xp_dir,
            'is_demo'        => true
        ]
    ];
} elseif (!empty($patient_id)) {
    try {
        $pdo = rsnext_pdo();
        $pSt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
        $pSt->execute([$patient_id]);
        $pRow = $pSt->fetch();
        if ($pRow) {
            $birth = $pRow['birth_date'] ? str_replace('-', '/', (string)$pRow['birth_date']) : '';
            $patient_info = [
                'id'           => (string)$pRow['patient_id'],
                'kanja_kanji'  => (string)$pRow['kanja_kanji'],
                'kanja_yomi'   => (string)$pRow['kanja_yomi'],
                'sex'          => (string)$pRow['sex'],
                'age'          => rsnext_calc_age((string)($pRow['birth_date'] ?? '')),
                'birth'        => $birth,
                'birth_wareki' => cd_format_wareki_birth($birth),
            ];
        }

        $sSt = $pdo->prepare(
            "SELECT * FROM studies
             WHERE patient_id = ? AND NOT is_deleted
               AND primary_file_type IN ('DICOM', 'ECG', 'IMAGE')
             ORDER BY study_date DESC, study_time DESC NULLS LAST, study_id DESC"
        );
        $sSt->execute([$patient_id]);
        foreach ($sSt->fetchAll() as $r) {
            $absDir = rsnext_resolve_study_dir((string)$r['volume_id'], (string)$r['rel_path']);
            $isEcg = ((string)$r['primary_file_type'] === 'ECG' || (string)$r['display_category'] === 'ecg');
            $targetPath = $isEcg ? ($absDir . '\\0001.ECG') : $absDir;
            $kensa = (string)$r['kensamei'];
            $cls = cd_classify_exam($kensa, $targetPath);
            $timeDisp = $r['study_time'] ? substr((string)$r['study_time'], 0, 5) : '';

            $exam_list[] = [
                'renban'         => (string)($r['legacy_renban'] ?: $r['study_id']),
                'kensamei'       => $cls['display_name'],
                'raw_kensamei'   => $kensa,
                'category'       => (string)($r['display_category'] ?: $cls['category']),
                'dicom_modality' => (string)($r['modality'] ?: $cls['dicom_modality']),
                'is_ecg'         => $isEcg,
                'date'           => str_replace('-', '/', (string)$r['study_date']),
                'time'           => $timeDisp,
                'doctor'         => (string)($r['doctor'] ?? ''),
                'sindan'         => (string)($r['findings'] ?? ''),
                'folder'         => $targetPath,
                'is_demo'        => false,
            ];
        }
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💿 患者別 検査CD作成システム - RS-Next</title>
    <style>
        :root {
            --primary: #1d4ed8;
            --primary-dark: #1e3a8a;
            --accent: #0284c7;
            --teal: #0d9488;
            --green: #059669;
            --bg-page: #e2e8f0;
            --bg-card: #ffffff;
            --text-main: #0f172a;
            --text-sub: #334155;
            --text-muted: #64748b;
            --border: #cbd5e1;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "Segoe UI", "Yu Gothic UI", "Meiryo", -apple-system, sans-serif;
            background: var(--bg-page);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ヘッダー（深緑→グリーングラデーション：v2.2新バージョン識別色） */
        .top-header {
            background: linear-gradient(90deg, #064e3b 0%, #15803d 55%, #16a34a 100%);
            color: #ffffff;
            padding: 7px 16px;
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
            gap: 12px;
            flex-wrap: wrap;
        }

        .header-left h1 {
            font-size: 1.08rem;
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
        }
        .nav-pill:hover {
            background: rgba(255, 255, 255, 0.26);
            color: #ffffff;
        }

        /* 患者検索バー */
        .search-bar-wrap {
            position: relative;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pat-search-input {
            width: 290px;
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.4);
            background: rgba(255, 255, 255, 0.96);
            color: #0f172a;
            font-size: 0.84rem;
            font-weight: 600;
            outline: none;
        }
        .pat-search-input:focus {
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.45);
        }

        .pat-search-btn {
            background: #0284c7;
            color: #fff;
            border: 1px solid #93c5fd;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
        }
        .pat-search-btn:hover { background: #0369a1; }

        .search-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            width: 420px;
            max-width: 92vw;
            max-height: 380px;
            overflow-y: auto;
            background: #ffffff;
            color: #0f172a;
            border: 1px solid #94a3b8;
            border-radius: 8px;
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.32);
            z-index: 300;
            display: none;
        }
        .cand-row {
            padding: 9px 12px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: background 0.12s;
        }
        .cand-row:hover { background: #eff6ff; }
        .cand-id {
            font-family: Consolas, monospace;
            font-weight: 700;
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 0.8rem;
        }

        /* メイングリッド */
        .main-wrap {
            max-width: 1520px;
            width: 100%;
            margin: 0 auto;
            padding: 9px 14px 18px;
            display: grid;
            grid-template-columns: 1fr 390px;
            gap: 12px;
            align-items: start;
        }
        @media (max-width: 1080px) {
            .main-wrap { grid-template-columns: 1fr; }
        }

        .panel-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }

        /* 患者バナー */
        .patient-banner {
            padding: 9px 14px;
            background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .pat-main-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .pat-id-big {
            font-family: Consolas, monospace;
            font-size: 0.95rem;
            font-weight: 700;
            background: #1e40af;
            color: #ffffff;
            padding: 4px 10px;
            border-radius: 6px;
        }

        .pat-name-big {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
        }

        .pat-yomi-small {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .pat-sub-pills {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            font-size: 0.82rem;
            color: var(--text-sub);
        }
        .pat-sub-pills span {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 3px 9px;
            border-radius: 999px;
            font-weight: 600;
        }

        /* 検査選択ツールバー */
        .exam-toolbar {
            padding: 10px 16px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .filter-pills, .quick-btns {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .pill-btn {
            background: #ffffff;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.12s;
        }
        .pill-btn:hover { background: #f1f5f9; color: #0f172a; }
        .pill-btn.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }

        /* 検査テーブル */
        .exam-table-wrap {
            max-height: calc(100vh - 250px);
            min-height: 320px;
            overflow-y: auto;
        }

        table.exam-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.84rem;
        }
        table.exam-table thead th {
            position: sticky;
            top: 0;
            background: #f1f5f9;
            color: #334155;
            font-weight: 700;
            padding: 9px 10px;
            border-bottom: 2px solid #cbd5e1;
            text-align: left;
            z-index: 10;
        }
        table.exam-table tbody tr {
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: background 0.1s;
        }
        table.exam-table tbody tr:hover {
            background: #f8fafc;
        }
        table.exam-table tbody tr.selected-row {
            background: #eff6ff;
        }
        table.exam-table td {
            padding: 8px 10px;
            vertical-align: middle;
        }

        .exam-chk {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        .cat-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 5px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #ffffff;
            background: #64748b;
        }
        .cat-endoscopy { background: #059669; }
        .cat-us        { background: #0284c7; }
        .cat-xray      { background: #7c3aed; }
        .cat-ecg       { background: #e11d48; }

        .peek-btn {
            background: #f1f5f9;
            color: #0369a1;
            border: 1px solid #bae6fd;
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.74rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .peek-btn:hover {
            background: #0284c7;
            color: #ffffff;
        }

        /* 右カラム: CD出力設定 & 実行パネル（ノートPC画面高さでもプログレスバーが常に見える縦圧縮設計） */
        .config-card {
            padding: 11px 14px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: sticky;
            top: 8px;
        }

        .config-title {
            font-size: 0.93rem;
            font-weight: 700;
            color: var(--primary-dark);
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .selected-counter {
            background: #dbeafe;
            color: #1e40af;
            font-size: 0.78rem;
            padding: 2px 9px;
            border-radius: 999px;
            font-weight: 700;
        }

        .opt-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 7px;
            padding: 7px 10px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .opt-row {
            display: flex;
            align-items: flex-start;
            gap: 7px;
            font-size: 0.8rem;
            cursor: pointer;
            line-height: 1.32;
        }
        .opt-row input[type="checkbox"] {
            width: 15px;
            height: 15px;
            margin-top: 2px;
            accent-color: var(--primary);
            flex-shrink: 0;
        }
        .opt-sub {
            display: block;
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 1px;
        }

        .field-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .field-group label {
            font-size: 0.74rem;
            font-weight: 700;
            color: var(--text-sub);
        }
        .field-group input {
            padding: 5px 9px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 0.8rem;
            outline: none;
        }
        .field-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
        }

        .action-stack {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .action-sub-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
        }
        .action-sub-grid.single-col {
            grid-template-columns: 1fr;
        }

        .btn-burn-cd {
            background: linear-gradient(135deg, #ea580c 0%, #dc2626 100%);
            color: #ffffff;
            border: none;
            padding: 9px 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1px;
            box-shadow: 0 3px 10px rgba(220, 38, 38, 0.3);
            transition: all 0.15s;
        }
        .btn-burn-cd:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 5px 14px rgba(220, 38, 38, 0.4);
        }
        .btn-burn-sub {
            font-size: 0.68rem;
            font-weight: 600;
            color: #ffedd5;
        }

        .btn-primary-cd {
            background: linear-gradient(135deg, #0284c7 0%, #1d4ed8 100%);
            color: #ffffff;
            border: none;
            padding: 8px 8px;
            border-radius: 7px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            box-shadow: 0 2px 6px rgba(29, 78, 216, 0.2);
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-primary-cd:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(29, 78, 216, 0.3);
        }
        .btn-burn-cd:disabled, .btn-primary-cd:disabled, .btn-zip-cd:disabled, .btn-preview-cd:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .btn-zip-cd {
            background: linear-gradient(135deg, #059669 0%, #0d9488 100%);
            color: #ffffff;
            border: none;
            padding: 8px 8px;
            border-radius: 7px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            box-shadow: 0 2px 6px rgba(5, 150, 105, 0.22);
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-zip-cd:hover:not(:disabled) {
            transform: translateY(-1px);
        }

        .btn-preview-cd {
            background: #ffffff;
            color: #1e40af;
            border: 1.5px solid #93c5fd;
            padding: 6px 10px;
            border-radius: 7px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            transition: all 0.15s;
        }
        .btn-preview-cd:hover:not(:disabled) {
            background: #eff6ff;
            border-color: #3b82f6;
        }

        /* 進捗・完了ボックス */
        .progress-card {
            display: none;
            background: #0f172a;
            color: #f8fafc;
            border-radius: 7px;
            padding: 8px 11px;
            font-size: 0.78rem;
            flex-direction: column;
            gap: 5px;
        }
        .prog-bar-outer {
            width: 100%;
            height: 8px;
            background: #334155;
            border-radius: 999px;
            overflow: hidden;
        }
        .prog-bar-inner {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #38bdf8, #10b981);
            transition: width 0.2s;
        }

        .result-box {
            display: none;
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 7px;
            padding: 8px 11px;
            font-size: 0.76rem;
            color: #14532d;
            line-height: 1.45;
        }

        .burn-tips {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 7px;
            padding: 7px 10px;
            font-size: 0.72rem;
            color: #78350f;
            line-height: 1.42;
        }
        .burn-tips strong {
            display: block;
            margin-bottom: 3px;
            color: #92400e;
        }

        /* ヘッダー＆設定欄の「かんたん手順」トグルボタン */
        .help-pill-btn {
            font-size: 0.78rem;
            color: #78350f;
            background: linear-gradient(135deg, #fef08a 0%, #fde047 100%);
            border: 1px solid #facc15;
            padding: 4px 11px;
            border-radius: 999px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.16);
            transition: all 0.15s;
        }
        .help-pill-btn:hover {
            background: linear-gradient(135deg, #fef9c3 0%, #facc15 100%);
            transform: translateY(-1px);
        }
        .help-pill-btn.active {
            background: #ffffff;
            color: #065f46;
            border-color: #ffffff;
            box-shadow: 0 0 0 2px rgba(250, 204, 21, 0.75);
        }

        .mini-help-trigger {
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fde047;
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 0.72rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.12s;
        }
        .mini-help-trigger:hover {
            background: #fef08a;
            color: #713f12;
        }

        /* 開いたまま操作できるフローティング「かんたん書き込みガイド」 */
        .quick-help-float {
            position: fixed;
            left: 16px;
            bottom: 16px;
            width: 382px;
            max-width: calc(100vw - 24px);
            background: #ffffff;
            border: 2px solid #059669;
            border-radius: 12px;
            box-shadow: 0 14px 36px rgba(15, 23, 42, 0.26), 0 4px 12px rgba(5, 150, 105, 0.16);
            z-index: 950;
            display: none;
            flex-direction: column;
            overflow: hidden;
            user-select: none;
        }
        .quick-help-float.open {
            display: flex;
        }
        .quick-help-float.minimized .qh-body {
            display: none;
        }
        .quick-help-float.minimized {
            width: auto;
            min-width: 250px;
        }

        .qh-header {
            background: linear-gradient(90deg, #065f46 0%, #059669 100%);
            color: #ffffff;
            padding: 8px 11px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            cursor: move;
        }
        .qh-title {
            font-size: 0.86rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .qh-sub-badge {
            font-size: 0.67rem;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.2);
            padding: 1px 6px;
            border-radius: 999px;
        }
        .qh-ctrl {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .qh-icon-btn {
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 5px;
            width: 24px;
            height: 22px;
            font-size: 0.76rem;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }
        .qh-icon-btn:hover {
            background: rgba(255, 255, 255, 0.32);
        }

        .qh-body {
            padding: 10px 12px 11px;
            display: flex;
            flex-direction: column;
            gap: 7px;
            background: #f8fafc;
            max-height: calc(100vh - 110px);
            overflow-y: auto;
            user-select: text;
        }
        .qh-lead {
            font-size: 0.74rem;
            color: #065f46;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 6px;
            padding: 5px 8px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
        }

        .qh-steps {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .qh-step {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            padding: 7px 9px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            transition: all 0.18s;
        }
        .qh-step.is-done {
            background: #f0fdf4;
            border-color: #86efac;
        }
        .qh-step.is-active {
            background: #fffbeb;
            border-color: #f59e0b;
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.18);
        }
        .qh-num {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #cbd5e1;
            color: #1e293b;
            font-size: 0.76rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .qh-step.is-done .qh-num {
            background: #16a34a;
            color: #ffffff;
        }
        .qh-step.is-active .qh-num {
            background: #ea580c;
            color: #ffffff;
        }
        .qh-step-main {
            flex: 1;
            min-width: 0;
        }
        .qh-step-head {
            font-size: 0.82rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.3;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
        }
        .qh-step-tag {
            font-size: 0.67rem;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .qh-step.is-done .qh-step-tag {
            background: #dcfce7;
            color: #15803d;
        }
        .qh-step.is-active .qh-step-tag {
            background: #ffedd5;
            color: #c2410c;
        }
        .qh-step-desc {
            font-size: 0.74rem;
            color: #475569;
            line-height: 1.4;
            margin-top: 2px;
        }

        .qh-faq {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            padding: 5px 9px;
            font-size: 0.73rem;
            color: #334155;
        }
        .qh-faq summary {
            cursor: pointer;
            font-weight: 700;
            color: #1e40af;
            outline: none;
        }
        .qh-faq-body {
            margin-top: 5px;
            padding-top: 5px;
            border-top: 1px dashed #e2e8f0;
            line-height: 1.48;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .qh-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.71rem;
            color: #64748b;
            padding-top: 1px;
        }
        .qh-footer a {
            color: #1d4ed8;
            font-weight: 700;
            text-decoration: none;
        }
        .qh-footer a:hover {
            text-decoration: underline;
        }

        .empty-welcome {
            padding: 48px 24px;
            text-align: center;
            color: var(--text-muted);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
        }

        .demo-alert {
            margin: 12px 16px 0;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.83rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .demo-alert.warn {
            background: #fff7ed;
            border: 1px solid #fdba74;
            color: #9a3412;
        }
        .demo-alert.info {
            background: #ecfeff;
            border: 1px solid #67e8f9;
            color: #155e75;
        }
        .demo-btn {
            background: #0284c7;
            color: #fff;
            text-decoration: none;
            padding: 5px 12px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-left">
            <h1><a href="index.php" style="color:inherit; text-decoration:none;" title="本日の検査一覧（ホーム）へ移動">💿 患者別 検査CD作成システム</a></h1>
            <button type="button" id="btnHeaderQuickHelp" class="help-pill-btn" onclick="toggleQuickHelp()" title="ヘルプを開いたまま確認しながらCD書き込みができます">❓ かんたん手順</button>
            <a href="index.php" class="nav-pill" title="本日の検査一覧（ホーム）へ戻ります">🏠 本日の検査一覧へ</a>
            <a href="client_logs.php" target="_blank" class="nav-pill" title="クライアントPC連携の稼働状況・エラーログを確認します">📋 連携ログ</a>
            <?php if (!empty($patient_info['id']) && !$is_demo_mode): ?>
                <a href="index.php?patient_id=<?= urlencode($patient_info['id']) ?>" class="nav-pill" title="この患者の全検査履歴を表示します">👤 患者履歴一覧</a>
                <a href="canvas_view.php?patient_id=<?= urlencode($patient_info['id']) ?>" target="_blank" class="nav-pill">🗺️ タイムライン</a>
                <a href="print_view.php?patient_id=<?= urlencode($patient_info['id']) ?>" target="_blank" class="nav-pill">🖨️ 2×4印刷</a>
            <?php endif; ?>
        </div>

        <!-- 任意の患者1名を素早く検索・切替 -->
        <div class="search-bar-wrap">
            <input type="text" id="patSearchInput" class="pat-search-input" placeholder="🔍 患者ID・漢字氏名・カナで患者を検索..." autocomplete="off">
            <button type="button" id="patSearchBtn" class="pat-search-btn">検索</button>
            <div id="searchDropdown" class="search-dropdown"></div>
        </div>
    </header>

    <?php if (!empty($db_error)): ?>
        <div class="demo-alert warn" style="max-width:1488px; width:calc(100% - 32px); margin:12px auto 0;">
            <div>
                <strong>⚠️ データベース未接続環境:</strong> 本番RS-Base DB (<code><?= htmlspecialchars($host) ?></code>) に接続できませんでした（テスト環境では想定内です）。
            </div>
            <a href="cd_export.php?demo=1" class="demo-btn">🧪 サンプル患者データでCD作成・閲覧を試す</a>
        </div>
    <?php elseif ($is_demo_mode): ?>
        <div class="demo-alert info" style="max-width:1488px; width:calc(100% - 32px); margin:12px auto 0;">
            <div>
                <strong>🧪 テスト用サンプル患者モード表示中:</strong> テスト用の内視鏡・エコー・胸部XP・12誘導心電図データを使って、CDパッケージ生成・オフラインHTMLビューワ・DICOMDIR出力を一通りお試しいただけます。
            </div>
            <a href="cd_export.php" class="demo-btn" style="background:#475569;">通常モードへ戻す</a>
        </div>
    <?php endif; ?>

    <div class="main-wrap">
        <!-- 左カラム: 患者情報 ＆ 任意の検査チェック選択 -->
        <section class="panel-card">
            <?php if ($patient_info): ?>
                <div class="patient-banner">
                    <div class="pat-main-info">
                        <span class="pat-id-big">ID: <?= htmlspecialchars($patient_info['id'], ENT_QUOTES, 'UTF-8') ?></span>
                        <div>
                            <div class="pat-name-big"><?= htmlspecialchars($patient_info['kanja_kanji'] ?: '氏名未登録', ENT_QUOTES, 'UTF-8') ?> 様</div>
                            <?php if (!empty($patient_info['kanja_yomi'])): ?>
                                <div class="pat-yomi-small"><?= htmlspecialchars($patient_info['kanja_yomi'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="pat-sub-pills">
                        <?php if (!empty($patient_info['sex'])): ?>
                            <span>性別: <?= htmlspecialchars($patient_info['sex'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($patient_info['age'])): ?>
                            <span>年齢: <?= htmlspecialchars($patient_info['age'], ENT_QUOTES, 'UTF-8') ?>歳</span>
                        <?php endif; ?>
                        <?php if (!empty($patient_info['birth_wareki'])): ?>
                            <span>🎂 <?= htmlspecialchars($patient_info['birth_wareki'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <span>全検査履歴: <?= count($exam_list) ?> 件</span>
                    </div>
                </div>

                <div class="exam-toolbar">
                    <div class="filter-pills" id="modFilterPills">
                        <button type="button" class="pill-btn active" data-cat="all">すべて (<?= count($exam_list) ?>)</button>
                        <button type="button" class="pill-btn" data-cat="endoscopy">内視鏡</button>
                        <button type="button" class="pill-btn" data-cat="us">超音波</button>
                        <button type="button" class="pill-btn" data-cat="xray">XP・画像</button>
                        <button type="button" class="pill-btn" data-cat="ecg">心電図</button>
                    </div>
                    <div class="quick-btns">
                        <button type="button" class="pill-btn" onclick="selectVisibleExams(true)">☑️ 表示中を全選択</button>
                        <button type="button" class="pill-btn" onclick="selectRecentMonths(12)">📅 直近1年を選択</button>
                        <button type="button" class="pill-btn" onclick="selectAllExams(false)">解除</button>
                    </div>
                </div>

                <div class="exam-table-wrap">
                    <?php if (count($exam_list) > 0): ?>
                        <table class="exam-table" id="examTable">
                            <thead>
                                <tr>
                                    <th style="width:44px; text-align:center;">
                                        <input type="checkbox" id="masterChk" class="exam-chk" title="表示中の検査を全選択/解除">
                                    </th>
                                    <th style="width:125px;">検査日時</th>
                                    <th style="width:140px;">検査種別</th>
                                    <th style="width:110px;">施行医</th>
                                    <th>診断・所見</th>
                                    <th style="width:80px; text-align:center;">事前確認</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($exam_list as $idx => $ex): ?>
                                    <?php
                                        $cat_cls = 'cat-' . $ex['category'];
                                        if ($ex['is_ecg']) {
                                            $peek_url = 'ecg_view.php?file=' . urlencode($ex['folder'])
                                                      . '&id=' . urlencode($patient_info['id'])
                                                      . '&name=' . urlencode($patient_info['kanja_kanji'])
                                                      . '&date=' . urlencode($ex['date']);
                                        } else {
                                            $peek_url = 'dicom_view.php?folder=' . urlencode($ex['folder'])
                                                      . '&id=' . urlencode($patient_info['id'])
                                                      . '&name=' . urlencode($patient_info['kanja_kanji'])
                                                      . '&kensa=' . urlencode($ex['kensamei'])
                                                      . '&date=' . urlencode($ex['date'])
                                                      . '&sindan=' . urlencode($ex['sindan']);
                                        }
                                    ?>
                                    <tr class="exam-row" data-idx="<?= $idx ?>" data-cat="<?= htmlspecialchars($ex['category'], ENT_QUOTES, 'UTF-8') ?>" data-date="<?= htmlspecialchars($ex['date'], ENT_QUOTES, 'UTF-8') ?>">
                                        <td style="text-align:center;" onclick="event.stopPropagation();">
                                            <input type="checkbox" class="exam-chk row-chk" data-idx="<?= $idx ?>">
                                        </td>
                                        <td>
                                            <div style="font-weight:700; font-family:Consolas,monospace;"><?= htmlspecialchars($ex['date'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php if (!empty($ex['time'])): ?>
                                                <div style="font-size:0.74rem; color:#64748b;">🕒 <?= htmlspecialchars($ex['time'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="cat-badge <?= htmlspecialchars($cat_cls, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= $ex['is_ecg'] ? '📈 ' : '🖼️ ' ?><?= htmlspecialchars($ex['kensamei'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td style="color:#334155; font-size:0.8rem;">
                                            <?= htmlspecialchars($ex['doctor'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td style="color:#1e293b;">
                                            <?= htmlspecialchars($ex['sindan'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td style="text-align:center;" onclick="event.stopPropagation();">
                                            <?php if (!$ex['is_demo'] || !$ex['is_ecg']): ?>
                                                <a href="<?= htmlspecialchars($peek_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="peek-btn">👁️ 閲覧</a>
                                            <?php else: ?>
                                                <span style="font-size:0.73rem; color:#64748b;">サンプル</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-welcome">
                            <div style="font-size:2rem;">📂</div>
                            <div>この患者様の出力可能な検査データは登録されていません。</div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-welcome">
                    <div style="font-size:2.8rem;">💿</div>
                    <div style="font-size:1.1rem; font-weight:700; color:#0f172a;">CDに入れたい患者様を1名選択してください</div>
                    <div style="max-width:480px; line-height:1.6; font-size:0.88rem;">
                        右上の検索欄から<strong>患者ID・漢字氏名・フリガナ</strong>で検索するか、<a href="index.php" style="color:#1d4ed8; font-weight:700;">検査一覧画面 (index.php)</a> の患者欄にある「💿」ボタンから直接開くことができます。
                    </div>
                    <a href="cd_export.php?demo=1" class="demo-btn" style="margin-top:6px;">🧪 サンプル患者データで動作を試す</a>
                </div>
            <?php endif; ?>
        </section>

        <!-- 右カラム: CD収録設定 ＆ 書き出しアクション -->
        <aside class="panel-card config-card">
            <div class="config-title">
                <span style="display:flex; align-items:center; gap:6px;">
                    ⚙️ CDパッケージ設定
                    <button type="button" id="btnPanelQuickHelp" class="mini-help-trigger" onclick="toggleQuickHelp(true)" title="手順ガイドを開いたまま操作できます">❓ 手順を見る</button>
                </span>
                <span class="selected-counter" id="selectedCountBadge">選択中: 0 件</span>
            </div>

            <div class="opt-box">
                <!-- オリジナルビューワー収録は標準搭載のため非表示 -->
                <label class="opt-row">
                    <input type="checkbox" id="optIncludeDicom" checked>
                    <span>
                        <strong>原本DICOM・生データを同梱 (DICOMDIR付)</strong>
                        <span class="opt-sub">紹介先PACS取込用の標準 <code>DICOMDIR</code> を自動生成（ご本人渡し等で軽くする場合はOFF可）。</span>
                    </span>
                </label>

                <!-- 診断・所見テキストはCD内非表示（OFF固定）とし、オプション自体も非表示化 -->
                <label class="opt-row" style="display:none;" aria-hidden="true">
                    <input type="checkbox" id="optIncludeFindings">
                    <span>診断・所見テキストをCD内に表示する</span>
                </label>
            </div>

            <div class="field-group">
                <label for="optIssuer">🏥 作成元・医療機関名（デフォルト設定済・変更可）</label>
                <input type="text" id="optIssuer" value="おの肛門科胃腸科内視鏡クリニック" placeholder="おの肛門科胃腸科内視鏡クリニック">
            </div>

            <div class="field-group">
                <label for="optNote">💬 添付メモ・ご案内文（任意）</label>
                <input type="text" id="optNote" placeholder="例: 内視鏡および心電図データをご提供いたします">
            </div>

            <!-- Windows 10/11 以外の端末アクセス時の案内バナー -->
            <div id="nonWinNotice" style="display:none; background:#eff6ff; border:1.5px solid #60a5fa; border-radius:7px; padding:8px 10px; font-size:0.74rem; color:#1e3a8a; line-height:1.45;">
                <strong style="display:block; margin-bottom:2px; color:#1d4ed8;">💻 お使いの端末（<span id="detectedOsLabel">Windows 10/11 以外</span>）での出力について</strong>
                CD-Rへの書き込み・フォルダ保存機能は <b>Windows 10 / 11 専用</b> です。<br>
                この端末では <b>「📦 ZIPでダウンロード」</b> のみご利用いただけます（プレビューはそのまま閲覧可能）。
            </div>

            <div class="action-stack" id="actionStack">
                <button type="button" id="btnBurnDisc" class="btn-burn-cd" disabled onclick="runCdExport('burn')">
                    <span>💿 CD-Rへ書き込む</span>
                    <span class="btn-burn-sub">このPCのCD/DVDドライブで書き込みます（Windows 10/11）</span>
                </button>
                <div class="action-sub-grid" id="actionSubGrid">
                    <button type="button" id="btnZipDownload" class="btn-zip-cd" disabled onclick="runCdExport('zip')">
                        📦 ZIPでダウンロード
                    </button>
                    <button type="button" id="btnDirectWrite" class="btn-primary-cd" disabled onclick="runCdExport('direct')">
                        📁 フォルダ・USBへ保存
                    </button>
                </div>
                <button type="button" id="btnPreviewCd" class="btn-preview-cd" disabled onclick="runCdExport('preview')">
                    👁️ ビューワをプレビュー
                </button>
            </div>

            <!-- リアルタイム進捗表示 -->
            <div class="progress-card" id="progressCard">
                <div style="display:flex; justify-content:space-between; font-weight:700;">
                    <span id="progTitle">準備中...</span>
                    <span id="progPct">0%</span>
                </div>
                <div class="prog-bar-outer">
                    <div class="prog-bar-inner" id="progBar"></div>
                </div>
                <div id="progDetail" style="color:#94a3b8; font-size:0.75rem;">待機中</div>
            </div>

            <!-- 完了サマリー & 容量ゲージ -->
            <div class="result-box" id="resultBox"></div>

            <!-- かんたんCD焼き込み手順（Windows 10/11 向け / その他OS向けに自動切替） -->
            <div class="burn-tips" id="burnTipsBox">
                <strong>💡 CD-R作成は ①検査選択 ➔ ②空CD-R挿入 ➔ ③赤いボタン の3操作だけ！</strong>
                <a href="javascript:void(0)" onclick="toggleQuickHelp(true)" style="color:#1d4ed8; font-weight:700;">❓ かんたん手順ガイドを開きながら作業する</a>
                ／ 初回のみ小窓が開かないPCは <a href="RSBaseSetup.exe" download style="color:#1d4ed8; font-weight:700;">📥 PC連携セットアップ</a>（<a href="watcher.zip" download style="color:#64748b;">ZIP</a>）を実行。
            </div>
        </aside>
    </div>

    <!-- 開いたまま確認しながら書き込み操作ができる「超かんたん手順ガイド」（ドラッグ移動・最小化対応） -->
    <div id="cdQuickHelp" class="quick-help-float" role="region" aria-label="CD-Rかんたん書き込み手順ガイド">
        <div class="qh-header" id="qhDragHandle" title="ドラッグして好きな位置へ移動できます">
            <div class="qh-title">
                <span>❓ CD-R かんたん手順</span>
                <span class="qh-sub-badge">開いたまま操作OK</span>
            </div>
            <div class="qh-ctrl" onmousedown="event.stopPropagation();" ontouchstart="event.stopPropagation();">
                <button type="button" class="qh-icon-btn" onclick="resetQuickHelpPos()" title="左下の定位置に戻す">↺</button>
                <button type="button" class="qh-icon-btn" id="qhMinBtn" onclick="minimizeQuickHelp()" title="小さくたたむ / 広げる">＿</button>
                <button type="button" class="qh-icon-btn" onclick="toggleQuickHelp(false)" title="ガイドを閉じる">✕</button>
            </div>
        </div>
        <div class="qh-body" id="qhBodyContent">
            <div class="qh-lead">
                <span id="qhLeadText">👇 この画面を見ながらそのままボタンを押せます</span>
            </div>

            <!-- Windows 10/11 標準の4ステップ -->
            <div class="qh-steps" id="qhWinSteps">
                <div class="qh-step" id="qhStep1">
                    <span class="qh-num">1</span>
                    <div class="qh-step-main">
                        <div class="qh-step-head">
                            <span>左の表で検査にチェック ☑️</span>
                            <span class="qh-step-tag" id="qhTag1"></span>
                        </div>
                        <div class="qh-step-desc" id="qhDesc1">CDに入れたい検査を選びます（最新日の検査は自動で ☑️ 済み）。</div>
                    </div>
                </div>

                <div class="qh-step" id="qhStep2">
                    <span class="qh-num">2</span>
                    <div class="qh-step-main">
                        <div class="qh-step-head">
                            <span>空のCD-Rをドライブに入れる 💿</span>
                            <span class="qh-step-tag" id="qhTag2"></span>
                        </div>
                        <div class="qh-step-desc">未使用の <b>CD-R</b>（容量が多い時はDVD-R）をPCに入れます。</div>
                    </div>
                </div>

                <div class="qh-step" id="qhStep3">
                    <span class="qh-num">3</span>
                    <div class="qh-step-main">
                        <div class="qh-step-head">
                            <span>右の赤い「💿 CD-Rへ書き込む」を押す</span>
                            <span class="qh-step-tag" id="qhTag3"></span>
                        </div>
                        <div class="qh-step-desc">数秒待つと、画面中央に<b>「検査CD 書き込みツール」小窓</b>が開きます。</div>
                    </div>
                </div>

                <div class="qh-step" id="qhStep4">
                    <span class="qh-num">4</span>
                    <div class="qh-step-main">
                        <div class="qh-step-head">
                            <span>小窓の「💿 CD-Rへ書き込み開始」を押す</span>
                            <span class="qh-step-tag" id="qhTag4"></span>
                        </div>
                        <div class="qh-step-desc">青いボタンを押すだけ！完了すると<b>自動でトレイが開きます</b> ✨</div>
                    </div>
                </div>
            </div>

            <!-- Windows 10/11 以外の端末向け3ステップ -->
            <div class="qh-steps" id="qhOtherOsSteps" style="display:none;">
                <div class="qh-step is-active">
                    <span class="qh-num">1</span>
                    <div class="qh-step-main">
                        <div class="qh-step-head"><span>左の表で検査にチェック ☑️</span></div>
                        <div class="qh-step-desc">出力したい検査を選びます。</div>
                    </div>
                </div>
                <div class="qh-step">
                    <span class="qh-num">2</span>
                    <div class="qh-step-main">
                        <div class="qh-step-head"><span>右の「📦 ZIPでダウンロード」を押す</span></div>
                        <div class="qh-step-desc">この端末ではZIP保存で一括ダウンロードします。</div>
                    </div>
                </div>
                <div class="qh-step">
                    <span class="qh-num">3</span>
                    <div class="qh-step-main">
                        <div class="qh-step-head"><span>解凍してCD-RやUSBへコピー</span></div>
                        <div class="qh-step-desc">展開した中身すべて（<code>閲覧を開く.html</code> 等）をメディアへ書き込みます。</div>
                    </div>
                </div>
            </div>

            <details class="qh-faq">
                <summary>💡 困ったとき・ご本人渡しの場合（クリックで表示）</summary>
                <div class="qh-faq-body">
                    <div>・<b>書き込み小窓が開かない時:</b> 右下に表示される青い<b>「① PC連携セットアップ」</b>（急ぎの時はオレンジの<b>「② 今回だけ書き込む」</b>）を押してください。</div>
                    <div>・<b>患者様ご本人渡しで軽くしたい時:</b> 右上の「原本DICOM同梱」のチェックを外せます（<b>紹介先病院宛てはONのまま</b>にしてください）。</div>
                    <div>・<b>CDドライブがないPC・USB渡し:</b> 右の「📁 フォルダ・USBへ保存」または「📦 ZIPでダウンロード」が使えます。</div>
                </div>
            </details>

            <div class="qh-footer">
                <span>※上部バーをドラッグして移動できます</span>
                <a href="help.html#sec10" target="_blank" rel="noopener">📖 詳しい図解ヘルプ ↗</a>
            </div>
        </div>
    </div>

    <script>
    const PATIENT_INFO = <?= json_encode($patient_info, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const EXAM_LIST    = <?= json_encode($exam_list, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

    // クライアントOS判定（Windows 10 / 11 のみダイレクト書込＆インストーラー対応）
    function detectClientOs() {
        const params = new URLSearchParams(window.location.search);
        const simOs = (params.get('sim_os') || '').toLowerCase();
        if (simOs === 'mac')  return { isWin10Or11: false, label: 'macOS' };
        if (simOs === 'ipad') return { isWin10Or11: false, label: 'iPadOS' };
        if (simOs === 'win7') return { isWin10Or11: false, label: '旧Windows' };

        const ua = navigator.userAgent || '';
        const isIpadDesktopMode = /Macintosh/i.test(ua) && navigator.maxTouchPoints > 1;
        if (isIpadDesktopMode || /iPad/i.test(ua)) return { isWin10Or11: false, label: 'iPad' };
        if (/iPhone|iPod/i.test(ua)) return { isWin10Or11: false, label: 'iPhone' };
        if (/Android/i.test(ua)) return { isWin10Or11: false, label: 'Android' };
        if (/Macintosh|Mac OS X/i.test(ua)) return { isWin10Or11: false, label: 'Mac' };
        if (/Windows NT 10\.0/i.test(ua)) return { isWin10Or11: true, label: 'Windows 10/11' };
        if (/Windows NT/i.test(ua)) return { isWin10Or11: false, label: 'Windows 8.1以前' };
        if (/Linux|CrOS/i.test(ua)) return { isWin10Or11: false, label: 'Linux / ChromeOS' };
        return { isWin10Or11: false, label: 'Windows 10/11 以外の端末' };
    }
    const CLIENT_OS = detectClientOs();

    const selectedIndices = new Set();
    let currentFilterCat = 'all';
    let lastBuiltSignature = '';
    let lastBuiltResult = null;

    // 医療機関名のローカル保存復元（デフォルト: おの肛門科胃腸科内視鏡クリニック）
    const DEFAULT_ISSUER = 'おの肛門科胃腸科内視鏡クリニック';
    const ISSUER_KEY = 'rsbase_cd_issuer_v2';
    document.addEventListener('DOMContentLoaded', () => {
        // Windows 10/11 以外の端末では「ZIP一括ダウンロード」のみに案内・ボタンを最適化
        if (!CLIENT_OS.isWin10Or11) {
            const nonWinNotice = document.getElementById('nonWinNotice');
            const osLbl = document.getElementById('detectedOsLabel');
            const btnBurn = document.getElementById('btnBurnDisc');
            const btnDirect = document.getElementById('btnDirectWrite');
            const subGrid = document.getElementById('actionSubGrid');
            const burnTips = document.getElementById('burnTipsBox');

            if (osLbl) osLbl.textContent = CLIENT_OS.label;
            if (nonWinNotice) nonWinNotice.style.display = 'block';
            if (btnBurn) btnBurn.style.display = 'none';
            if (btnDirect) btnDirect.style.display = 'none';
            if (subGrid) subGrid.classList.add('single-col');
            if (burnTips) {
                burnTips.innerHTML = `
                    <strong>📦 ZIPダウンロード後のディスク作成手順（${escHtml(CLIENT_OS.label)}）</strong>
                    ① <b>「📦 ZIPでダウンロード」</b> ➔ ② 解凍 ➔ ③ 中身（<code>閲覧を開く.html</code> 等）をCD-R/USBへコピー
                `;
            }
            const qhWin = document.getElementById('qhWinSteps');
            const qhOther = document.getElementById('qhOtherOsSteps');
            if (qhWin) qhWin.style.display = 'none';
            if (qhOther) qhOther.style.display = 'flex';
        }

        const savedIssuer = localStorage.getItem(ISSUER_KEY);
        const issuerInput = document.getElementById('optIssuer');
        if (issuerInput) {
            issuerInput.value = (savedIssuer !== null && savedIssuer.trim() !== '') ? savedIssuer : DEFAULT_ISSUER;
            issuerInput.addEventListener('change', () => {
                localStorage.setItem(ISSUER_KEY, issuerInput.value.trim());
            });
        }

        // 初期状態: 最新日の検査を自動選択しておくとすぐ使えて便利（1件以上ある場合）
        if (EXAM_LIST && EXAM_LIST.length > 0) {
            const latestDate = EXAM_LIST[0].date;
            EXAM_LIST.forEach((ex, idx) => {
                if (ex.date === latestDate) {
                    selectedIndices.add(idx);
                }
            });
            syncCheckboxesUI();
        } else {
            updateQuickHelpStep();
        }

        setupTableEvents();
        setupPatientSearch();
        initQuickHelpPanel();
    });

    function setupTableEvents() {
        document.querySelectorAll('.exam-row').forEach(row => {
            row.addEventListener('click', () => {
                const idx = Number(row.getAttribute('data-idx'));
                if (selectedIndices.has(idx)) selectedIndices.delete(idx);
                else selectedIndices.add(idx);
                syncCheckboxesUI();
            });
        });

        document.querySelectorAll('.row-chk').forEach(chk => {
            chk.addEventListener('change', () => {
                const idx = Number(chk.getAttribute('data-idx'));
                if (chk.checked) selectedIndices.add(idx);
                else selectedIndices.delete(idx);
                syncCheckboxesUI();
            });
        });

        const master = document.getElementById('masterChk');
        if (master) {
            master.addEventListener('change', () => {
                selectVisibleExams(master.checked);
            });
        }

        document.querySelectorAll('#modFilterPills .pill-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                currentFilterCat = btn.getAttribute('data-cat');
                document.querySelectorAll('#modFilterPills .pill-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                applyModalityFilter();
            });
        });
    }

    function applyModalityFilter() {
        document.querySelectorAll('.exam-row').forEach(row => {
            const cat = row.getAttribute('data-cat');
            const visible = (currentFilterCat === 'all' || cat === currentFilterCat);
            row.style.display = visible ? '' : 'none';
        });
        syncCheckboxesUI();
    }

    function selectVisibleExams(flag) {
        document.querySelectorAll('.exam-row').forEach(row => {
            if (row.style.display === 'none') return;
            const idx = Number(row.getAttribute('data-idx'));
            if (flag) selectedIndices.add(idx);
            else selectedIndices.delete(idx);
        });
        syncCheckboxesUI();
    }

    function selectAllExams(flag) {
        if (!flag) selectedIndices.clear();
        else EXAM_LIST.forEach((_, idx) => selectedIndices.add(idx));
        syncCheckboxesUI();
    }

    function selectRecentMonths(months) {
        const cutoff = new Date();
        cutoff.setMonth(cutoff.getMonth() - months);
        EXAM_LIST.forEach((ex, idx) => {
            const dStr = (ex.date || '').replace(/\./g, '/').replace(/-/g, '/');
            const d = new Date(dStr);
            if (!isNaN(d.getTime()) && d >= cutoff) {
                selectedIndices.add(idx);
            }
        });
        syncCheckboxesUI();
    }

    function syncCheckboxesUI() {
        let visibleCount = 0;
        let visibleChecked = 0;

        document.querySelectorAll('.exam-row').forEach(row => {
            const idx = Number(row.getAttribute('data-idx'));
            const checked = selectedIndices.has(idx);
            row.classList.toggle('selected-row', checked);
            const chk = row.querySelector('.row-chk');
            if (chk) chk.checked = checked;

            if (row.style.display !== 'none') {
                visibleCount++;
                if (checked) visibleChecked++;
            }
        });

        const master = document.getElementById('masterChk');
        if (master) {
            master.checked = (visibleCount > 0 && visibleChecked === visibleCount);
            master.indeterminate = (visibleChecked > 0 && visibleChecked < visibleCount);
        }

        const totalSel = selectedIndices.size;
        document.getElementById('selectedCountBadge').textContent = `選択中: ${totalSel} 件`;

        const disabled = (!PATIENT_INFO || totalSel === 0);
        document.getElementById('btnBurnDisc').disabled = disabled;
        document.getElementById('btnDirectWrite').disabled = disabled;
        document.getElementById('btnZipDownload').disabled = disabled;
        document.getElementById('btnPreviewCd').disabled = disabled;
        updateQuickHelpStep();
    }

    // ------------------------------------------------------------------
    // 患者検索ドロップダウン
    // ------------------------------------------------------------------
    function setupPatientSearch() {
        const input = document.getElementById('patSearchInput');
        const btn   = document.getElementById('patSearchBtn');
        const drop  = document.getElementById('searchDropdown');
        let timer = null;

        async function doSearch() {
            const q = input.value.trim();
            if (!q) {
                drop.style.display = 'none';
                return;
            }
            drop.style.display = 'block';
            drop.innerHTML = '<div style="padding:14px; color:#64748b; font-size:0.82rem;">検索中...</div>';

            try {
                const fd = new URLSearchParams();
                fd.append('action', 'search_patient');
                fd.append('query', q);
                const res = await fetch('cd_export.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status !== 'success') {
                    drop.innerHTML = `<div style="padding:14px; color:#b91c1c; font-size:0.8rem;">${escHtml(json.message || '検索エラー')}</div>`;
                    return;
                }
                if (!json.data || json.data.length === 0) {
                    drop.innerHTML = '<div style="padding:14px; color:#64748b; font-size:0.82rem;">該当する患者様が見つかりません</div>';
                    return;
                }
                drop.innerHTML = '';
                json.data.forEach(p => {
                    const div = document.createElement('div');
                    div.className = 'cand-row';
                    div.innerHTML = `
                        <div style="display:flex; align-items:center; gap:9px;">
                            <span class="cand-id">${escHtml(p.id)}</span>
                            <div>
                                <div style="font-weight:700; font-size:0.86rem;">${escHtml(p.kanja_kanji)}</div>
                                <div style="font-size:0.72rem; color:#64748b;">${escHtml(p.kanja_yomi)}</div>
                            </div>
                        </div>
                        <div style="text-align:right; font-size:0.75rem; color:#475569;">
                            <div>最新: ${escHtml(p.latest_date)}</div>
                            <div style="color:#0f766e; font-weight:700;">計 ${p.exam_count} 件</div>
                        </div>
                    `;
                    div.onclick = () => {
                        window.location.href = 'cd_export.php?patient_id=' + encodeURIComponent(p.id);
                    };
                    drop.appendChild(div);
                });
            } catch (err) {
                drop.innerHTML = `<div style="padding:14px; color:#b91c1c; font-size:0.8rem;">通信エラー: ${escHtml(err.message)}</div>`;
            }
        }

        btn.addEventListener('click', doSearch);
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
        });
        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(doSearch, 300);
        });
        document.addEventListener('click', e => {
            if (!e.target.closest('.search-bar-wrap')) {
                drop.style.display = 'none';
            }
        });
    }

    // ------------------------------------------------------------------
    // CDパッケージ ステップビルド & 出力処理
    // ------------------------------------------------------------------
    function getBuildOptions() {
        const issuer = document.getElementById('optIssuer').value.trim();
        const findingsEl = document.getElementById('optIncludeFindings');
        localStorage.setItem(ISSUER_KEY, issuer);
        return {
            include_dicom: document.getElementById('optIncludeDicom').checked,
            include_findings: findingsEl ? Boolean(findingsEl.checked) : false,
            issuer: issuer,
            note: document.getElementById('optNote').value.trim()
        };
    }

    function computeSignature(chosenIdxArr, opts) {
        return JSON.stringify({
            pat: PATIENT_INFO ? PATIENT_INFO.id : '',
            exams: chosenIdxArr,
            opts: opts
        });
    }

    function setButtonsBusy(busy) {
        document.getElementById('btnBurnDisc').disabled = busy;
        document.getElementById('btnDirectWrite').disabled = busy;
        document.getElementById('btnZipDownload').disabled = busy;
        document.getElementById('btnPreviewCd').disabled = busy;
    }

    function setProgress(pct, title, detail) {
        const card = document.getElementById('progressCard');
        const wasHidden = (card.style.display !== 'flex');
        card.style.display = 'flex';
        document.getElementById('progPct').textContent = Math.round(pct) + '%';
        document.getElementById('progBar').style.width = Math.min(100, Math.max(0, pct)) + '%';
        if (title) document.getElementById('progTitle').textContent = title;
        if (detail) document.getElementById('progDetail').textContent = detail;
        if (wasHidden) {
            try { card.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch (_) {}
        }
    }

    async function ensureCdPackageBuilt() {
        const chosenIdxArr = Array.from(selectedIndices).sort((a, b) => a - b);
        const opts = getBuildOptions();
        const sig = computeSignature(chosenIdxArr, opts);

        if (lastBuiltResult && lastBuiltSignature === sig) {
            return lastBuiltResult;
        }

        document.getElementById('resultBox').style.display = 'none';
        setProgress(3, 'CDパッケージを初期化中...', '作業領域を準備しています');

        // 1. ジョブ初期化
        const initRes = await fetch('cd_export.php?action=init_job', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                patient: {
                    id: PATIENT_INFO.id,
                    name: PATIENT_INFO.kanja_kanji,
                    yomi: PATIENT_INFO.kanja_yomi,
                    sex: PATIENT_INFO.sex,
                    age: PATIENT_INFO.age,
                    birth: PATIENT_INFO.birth_wareki || PATIENT_INFO.birth
                },
                options: opts
            })
        });
        const initJson = await initRes.json();
        if (initJson.status !== 'success') {
            throw new Error(initJson.message || 'ジョブ初期化に失敗しました');
        }
        const jobId = initJson.job_id;

        // 2. 選択された各検査を1件ずつ変換・格納
        const total = chosenIdxArr.length;
        const warnings = [];
        for (let i = 0; i < total; i++) {
            const ex = EXAM_LIST[chosenIdxArr[i]];
            const basePct = 5 + (i / total) * 75;
            setProgress(basePct, `検査データ変換中 (${i + 1} / ${total} 件)...`, `${ex.date} ${ex.kensamei} を処理しています`);

            const procRes = await fetch('cd_export.php?action=process_exam', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    job_id: jobId,
                    exam_index: i + 1,
                    exam: ex
                })
            });
            const procJson = await procRes.json();
            if (procJson.status === 'warning') {
                warnings.push(procJson.message);
            } else if (procJson.status !== 'success') {
                throw new Error(procJson.message || '検査データの処理中にエラーが発生しました');
            }
        }

        // 3. 最終パッケージ化 (HTMLビューワ + DICOMDIR + README)
        setProgress(85, '仕上げ処理中...', opts.include_dicom ? 'オフラインHTMLビューワとDICOMDIRを生成しています...' : 'オフラインHTMLビューワを生成しています...');
        const finRes = await fetch('cd_export.php?action=finalize_job', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ job_id: jobId })
        });
        const finJson = await finRes.json();
        if (finJson.status !== 'success') {
            throw new Error(finJson.message || '最終パッケージ生成に失敗しました');
        }

        finJson.warnings = warnings;
        lastBuiltSignature = sig;
        lastBuiltResult = finJson;
        setProgress(100, 'CDデータの構築完了', '完了しました');
        renderResultSummary(finJson);
        return finJson;
    }

    function renderResultSummary(res, extraHtml = '') {
        const mb = (res.total_bytes / (1024 * 1024)).toFixed(2);
        const cdPct = Math.min(100, (res.total_bytes / (700 * 1024 * 1024)) * 100).toFixed(1);
        const overCd = res.total_bytes > 680 * 1024 * 1024;

        let dicomLine = '';
        if (res.dicom_stats) {
            const dCnt = res.dicom_stats.dicom_added || 0;
            const eCnt = res.dicom_stats.ecg_copied || 0;
            const hasDir = res.dicom_stats.dicomdir_created ? 'DICOMDIR生成済' : 'DICOMなし';
            dicomLine = `<br>🏥 原本同梱: <strong>DICOM ${dCnt}枚 (${hasDir})</strong>` + (eCnt > 0 ? ` / <strong>心電図原本 ${eCnt}件</strong>` : '');
        }

        let warnLine = '';
        if (res.warnings && res.warnings.length > 0) {
            warnLine = `<div style="margin-top:6px; color:#9a3412; font-size:0.75rem;">⚠️ ${res.warnings.map(escHtml).join('<br>')}</div>`;
        }

        const box = document.getElementById('resultBox');
        box.style.display = 'block';
        box.innerHTML = `
            <strong>✅ CD用パッケージ作成完了</strong><br>
            📁 総ファイル数: <strong>${res.total_files} 個</strong><br>
            💿 合計サイズ: <strong style="color:${overCd ? '#b91c1c' : '#15803d'};">${mb} MB</strong> / 700 MB (${cdPct}% ${overCd ? '⚠️DVD-R推奨' : 'CD-R余裕あり'})
            ${dicomLine}
            ${extraHtml}
            ${warnLine}
        `;
    }

    async function runCdExport(mode) {
        if (selectedIndices.size === 0) return;

        // Windows 10/11 以外の端末からの書込系リクエストはZIPダウンロードへ安全誘導
        if (!CLIENT_OS.isWin10Or11 && (mode === 'burn' || mode === 'direct')) {
            const okZip = confirm(
                `お使いの端末（${CLIENT_OS.label}）では、CD-R書き込み・フォルダ保存はご利用いただけません（Windows 10 / 11 専用）。\n\n` +
                `「📦 ZIPでダウンロード」を実行しますか？`
            );
            if (!okZip) return;
            mode = 'zip';
        }

        // 直接書き出しモードの場合は、ビルド前にフォルダ選択ダイアログを開く（ユーザージェスチャ維持のため！）
        let targetDirHandle = null;
        if (mode === 'direct') {
            if (!('showDirectoryPicker' in window)) {
                const fallbackOk = confirm(
                    "現在の接続（HTTP）またはブラウザ環境では、フォルダへの直接保存が制限されています。\n" +
                    "（※CD-Rへ焼く場合は「💿 CD-Rへ書き込む」がHTTPのままご利用いただけます）\n\n" +
                    "代わりに「📦 ZIPでダウンロード」を実行しますか？"
                );
                if (fallbackOk) {
                    mode = 'zip';
                } else {
                    return;
                }
            } else {
                try {
                    targetDirHandle = await window.showDirectoryPicker({ mode: 'readwrite' });
                } catch (pickErr) {
                    if (pickErr.name === 'AbortError') return;
                    alert('書き込み先フォルダの選択に失敗しました: ' + pickErr.message);
                    return;
                }
            }
        }

        setButtonsBusy(true);
        if (mode === 'burn') updateQuickHelpStep('building');
        try {
            const built = await ensureCdPackageBuilt();

            if (mode === 'burn') {
                await triggerClientCdBurn(built);
            } else if (mode === 'preview') {
                window.open(built.preview_url, '_blank');
                updateQuickHelpStep();
            } else if (mode === 'zip') {
                window.location.href = built.zip_url;
                updateQuickHelpStep();
            } else if (mode === 'direct' && targetDirHandle) {
                await writePackageToDirectory(built.job_id, targetDirHandle, built);
                updateQuickHelpStep();
            }
        } catch (err) {
            updateQuickHelpStep();
            alert('エラーが発生しました: ' + (err.message || err));
        } finally {
            setButtonsBusy(false);
        }
    }

    async function triggerClientCdBurn(built) {
        setProgress(96, 'CD書き込み画面を起動中...', 'PC連携ツールと通信しています');

        const trigRes = await fetch('cd_export.php?action=trigger_cd_burn', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                job_id: built.job_id,
                pat_id: PATIENT_INFO ? PATIENT_INFO.id : '',
                pat_name: PATIENT_INFO ? PATIENT_INFO.kanja_kanji : ''
            })
        });
        const trigJson = await trigRes.json();
        const launcherUrl = trigJson.launcher_url || (
            'cd_export.php?action=download_burn_launcher&job_id=' + encodeURIComponent(built.job_id)
            + '&pat_id=' + encodeURIComponent(PATIENT_INFO ? PATIENT_INFO.id : '')
            + '&pat_name=' + encodeURIComponent(PATIENT_INFO ? PATIENT_INFO.kanja_kanji : '')
        );

        if (trigJson.status !== 'success') {
            throw new Error(trigJson.message || 'CD書き込みトリガーの送信に失敗しました');
        }

        // watcher.vbs v2.2+ (cdburn_<IP>.txt 監視) がトリガーファイルを消費したか最大約11秒確認
        // ※高速PCでは1〜2回目(0.5〜1.0秒)で即座に抜け、低速PCでのSMB再接続・WMI競合でも1クリックで確実に起動させる
        const maxPolls = 22;
        let consumed = false;
        for (let i = 0; i < maxPolls; i++) {
            await new Promise(r => setTimeout(r, 500));
            if (i === 5) {
                setProgress(98, 'CD書き込み画面を起動中...', 'PC側の常駐ツール応答を待っています（低速端末では数秒かかります）');
            }
            const isLast = (i === maxPolls - 1);
            try {
                const chkRes = await fetch('cd_export.php?action=check_trigger' + (isLast ? '&cleanup=1' : ''));
                const chkJson = await chkRes.json();
                if (chkJson && chkJson.consumed) {
                    consumed = true;
                    break;
                }
            } catch (_) {}
        }

        if (consumed) {
            setProgress(100, '💿 CD書き込み画面を起動しました', '表示されたウィンドウから空のCD-Rへ書き込んでください');
            updateQuickHelpStep('launched');
            renderResultSummary(
                built,
                `<div style="margin-top:8px; padding:9px 10px; background:#dcfce7; border:1px solid #86efac; border-radius:6px; color:#14532d;">
                    <strong>💿 CD書き込み画面を起動しました</strong><br>
                    開いた「<strong>RS-Base 検査CD 書き込みツール</strong>」ウィンドウで、空のCD-Rを入れて書き込みを開始してください（完了後に自動でトレイが開きます）。
                    <div style="margin-top:6px; font-size:0.74rem; color:#166534;">
                        ※画面が開かない場合:
                        <a href="${escHtml(launcherUrl)}" style="color:#1d4ed8; font-weight:700;">💿 書き込み画面を手動で開く (.cmd)</a> ／
                        <a href="RSBaseSetup.exe" download style="color:#1d4ed8; font-weight:700;">📥 PC連携セットアップ (RSBaseSetup.exe)</a>
                    </div>
                </div>`
            );
        } else {
            setProgress(100, 'CDデータの準備完了', '下のボタンからPC連携設定、または書き込み画面を起動できます');
            updateQuickHelpStep('manual');
            renderResultSummary(
                built,
                `<div style="margin-top:8px; padding:10px 11px; background:#fff7ed; border:1px solid #fdba74; border-radius:6px; color:#7c2d12;">
                    <strong>⚠️ PC連携ツールが未設定、または旧バージョンの <code>watcher.vbs</code> です</strong><br>
                    ①でセットアップすると次回からボタン1つで開き、今後の更新も自動化されます（②なら設定せず今回だけ書き込めます）：
                    <div style="margin-top:8px; display:flex; flex-direction:column; gap:6px;">
                        <a href="RSBaseSetup.exe" download style="display:block; text-align:center; background:linear-gradient(135deg, #1d4ed8, #2563eb); color:#fff; text-decoration:none; padding:8px 10px; border-radius:6px; font-weight:700; font-size:0.82rem; box-shadow:0 2px 6px rgba(37,99,235,0.25);">
                            📥 ① PC連携ツールをセットアップ・更新 (RSBaseSetup.exe)
                        </a>
                        <a href="${escHtml(launcherUrl)}" style="display:block; text-align:center; background:#ea580c; color:#fff; text-decoration:none; padding:8px 10px; border-radius:6px; font-weight:700; font-size:0.8rem;">
                            💿 ② セットアップせず今回だけ書き込む (.cmd)
                        </a>
                    </div>
                    <div style="margin-top:6px; font-size:0.72rem; color:#9a3412; line-height:1.5;">
                        ※初回のみ警告画面が出た場合は<b>［保存］➔［詳細情報］➔［実行］</b>で進めてください。<br>
                        ※うまく動作しない場合: <a href="client_logs.php" target="_blank" style="color:#0369a1; font-weight:700;">📋 連携ログ確認</a> ／ <a href="RSBaseUninstall.exe" download style="color:#b91c1c; font-weight:700;">🗑️ アンインストール専用ツール (RSBaseUninstall.exe)</a>
                    </div>
                </div>`
            );
        }
    }

    async function writePackageToDirectory(jobId, rootHandle, builtInfo) {
        setProgress(5, '書き込みファイル一覧を取得中...', '');
        const mRes = await fetch('cd_export.php?action=manifest&job_id=' + encodeURIComponent(jobId));
        const mJson = await mRes.json();
        if (mJson.status !== 'success' || !Array.isArray(mJson.files)) {
            throw new Error(mJson.message || 'マニフェスト取得に失敗しました');
        }

        const files = mJson.files;
        const total = files.length;
        const dirCache = new Map();
        dirCache.set('', rootHandle);

        async function getSubDirHandle(relDir) {
            if (dirCache.has(relDir)) return dirCache.get(relDir);
            const parts = relDir.split('/').filter(Boolean);
            let cur = rootHandle;
            let acc = '';
            for (const p of parts) {
                acc = acc ? (acc + '/' + p) : p;
                if (dirCache.has(acc)) {
                    cur = dirCache.get(acc);
                } else {
                    cur = await cur.getDirectoryHandle(p, { create: true });
                    dirCache.set(acc, cur);
                }
            }
            return cur;
        }

        for (let i = 0; i < total; i++) {
            const item = files[i];
            const pct = Math.round(((i + 1) / total) * 100);
            setProgress(pct, `指定先へ直接書き出し中 (${i + 1} / ${total})...`, item.path);

            const slashIdx = item.path.lastIndexOf('/');
            const dirPart = slashIdx >= 0 ? item.path.slice(0, slashIdx) : '';
            const fileName = slashIdx >= 0 ? item.path.slice(slashIdx + 1) : item.path;

            const parentH = await getSubDirHandle(dirPart);
            const fRes = await fetch(`cd_export.php?action=get_file&job_id=${encodeURIComponent(jobId)}&path=${encodeURIComponent(item.path)}`);
            if (!fRes.ok) throw new Error(`ファイル取得失敗: ${item.path}`);
            const blob = await fRes.blob();

            const fh = await parentH.getFileHandle(fileName, { create: true });
            const writable = await fh.createWritable();
            await writable.write(blob);
            await writable.close();
        }

        renderResultSummary(
            builtInfo,
            `<div style="margin-top:6px; padding-top:6px; border-top:1px dashed #86efac;">
                🎉 保存先「<strong>📁 ${escHtml(rootHandle.name)}</strong>」へ全 ${total} ファイルを直接書き出しました！<br>
                <span style="font-size:0.75rem;">※CDドライブへ書き出した場合は、エクスプローラーで「ディスクに書き込む」を実行してください。</span>
            </div>`
        );
    }

    // ------------------------------------------------------------------
    // 開いたまま確認しながら書き込み操作ができる「かんたん手順ガイド」制御
    // ------------------------------------------------------------------
    const QUICK_HELP_OPEN_KEY = 'rsbase_cd_quick_help_open_v1';

    function initQuickHelpPanel() {
        const panel = document.getElementById('cdQuickHelp');
        const handle = document.getElementById('qhDragHandle');
        if (!panel || !handle) return;

        const params = new URLSearchParams(window.location.search);
        const savedOpen = localStorage.getItem(QUICK_HELP_OPEN_KEY);
        if (params.get('help') === '1' || savedOpen === '1') {
            toggleQuickHelp(true);
        }

        // マウス・タッチドラッグ移動（画面外へはみ出さないクランプ付き）
        let dragging = false;
        let startX = 0, startY = 0, origLeft = 0, origTop = 0;

        function startDrag(clientX, clientY) {
            const rect = panel.getBoundingClientRect();
            dragging = true;
            startX = clientX;
            startY = clientY;
            origLeft = rect.left;
            origTop = rect.top;
            panel.style.left = rect.left + 'px';
            panel.style.top = rect.top + 'px';
            panel.style.bottom = 'auto';
            panel.style.right = 'auto';
        }

        function moveDrag(clientX, clientY) {
            if (!dragging) return;
            const dx = clientX - startX;
            const dy = clientY - startY;
            const maxLeft = Math.max(8, window.innerWidth - panel.offsetWidth - 8);
            const maxTop  = Math.max(8, window.innerHeight - panel.offsetHeight - 8);
            const nextLeft = Math.min(maxLeft, Math.max(8, origLeft + dx));
            const nextTop  = Math.min(maxTop, Math.max(8, origTop + dy));
            panel.style.left = nextLeft + 'px';
            panel.style.top  = nextTop + 'px';
        }

        function endDrag() {
            dragging = false;
        }

        handle.addEventListener('mousedown', e => {
            if (e.button !== 0) return;
            startDrag(e.clientX, e.clientY);
            e.preventDefault();
        });
        window.addEventListener('mousemove', e => moveDrag(e.clientX, e.clientY));
        window.addEventListener('mouseup', endDrag);

        handle.addEventListener('touchstart', e => {
            if (!e.touches || e.touches.length !== 1) return;
            startDrag(e.touches[0].clientX, e.touches[0].clientY);
        }, { passive: true });
        window.addEventListener('touchmove', e => {
            if (!dragging || !e.touches || e.touches.length !== 1) return;
            moveDrag(e.touches[0].clientX, e.touches[0].clientY);
        }, { passive: true });
        window.addEventListener('touchend', endDrag);
    }

    function toggleQuickHelp(forceState) {
        const panel = document.getElementById('cdQuickHelp');
        const hdrBtn = document.getElementById('btnHeaderQuickHelp');
        const pnlBtn = document.getElementById('btnPanelQuickHelp');
        if (!panel) return;

        const nextOpen = (typeof forceState === 'boolean') ? forceState : !panel.classList.contains('open');
        panel.classList.toggle('open', nextOpen);
        if (nextOpen) {
            panel.classList.remove('minimized');
            const minBtn = document.getElementById('qhMinBtn');
            if (minBtn) minBtn.textContent = '＿';
        }

        if (hdrBtn) {
            hdrBtn.classList.toggle('active', nextOpen);
            hdrBtn.textContent = nextOpen ? '❓ かんたん手順（表示中）' : '❓ かんたん手順';
        }
        if (pnlBtn) {
            pnlBtn.textContent = nextOpen ? '❓ 手順を閉じる' : '❓ 手順を見る';
        }
        try { localStorage.setItem(QUICK_HELP_OPEN_KEY, nextOpen ? '1' : '0'); } catch (_) {}
        updateQuickHelpStep();
    }

    function minimizeQuickHelp() {
        const panel = document.getElementById('cdQuickHelp');
        const minBtn = document.getElementById('qhMinBtn');
        if (!panel) return;
        const isMin = panel.classList.toggle('minimized');
        if (minBtn) minBtn.textContent = isMin ? '□' : '＿';
    }

    function resetQuickHelpPos() {
        const panel = document.getElementById('cdQuickHelp');
        if (!panel) return;
        panel.classList.remove('minimized');
        const minBtn = document.getElementById('qhMinBtn');
        if (minBtn) minBtn.textContent = '＿';
        panel.style.top = 'auto';
        panel.style.right = 'auto';
        panel.style.left = '16px';
        panel.style.bottom = '16px';
    }

    function updateQuickHelpStep(phase = '') {
        if (!CLIENT_OS.isWin10Or11) return;
        const s1 = document.getElementById('qhStep1');
        const s2 = document.getElementById('qhStep2');
        const s3 = document.getElementById('qhStep3');
        const s4 = document.getElementById('qhStep4');
        const t1 = document.getElementById('qhTag1');
        const t2 = document.getElementById('qhTag2');
        const t3 = document.getElementById('qhTag3');
        const t4 = document.getElementById('qhTag4');
        const d1 = document.getElementById('qhDesc1');
        const lead = document.getElementById('qhLeadText');
        if (!s1 || !s2 || !s3 || !s4) return;

        [s1, s2, s3, s4].forEach(el => el.classList.remove('is-done', 'is-active'));
        [t1, t2, t3, t4].forEach(el => { if (el) el.textContent = ''; });

        if (!PATIENT_INFO) {
            s1.classList.add('is-active');
            if (t1) t1.textContent = 'まず患者検索';
            if (d1) d1.textContent = '右上の検索ボックスから患者IDまたは氏名で検索してください。';
            if (lead) lead.textContent = '🔍 まずは右上で患者様を検索してください';
            return;
        }

        if (d1) d1.textContent = 'CDに入れたい検査を選びます（最新日の検査は自動で ☑️ 済み）。';
        const selCount = selectedIndices.size;

        if (selCount === 0) {
            s1.classList.add('is-active');
            if (t1) t1.textContent = 'いまここ';
            if (lead) lead.textContent = '☑️ 左の一覧で検査にチェックを入れてください';
            return;
        }

        // 1件以上選択済み
        s1.classList.add('is-done');
        if (t1) t1.textContent = `✓ ${selCount}件選択中`;

        if (phase === 'building') {
            s2.classList.add('is-done');
            s3.classList.add('is-active');
            if (t3) t3.textContent = '⏳ 起動中...';
            if (lead) lead.textContent = '⏳ CDデータを作成して書き込み画面を開いています...';
        } else if (phase === 'launched') {
            s2.classList.add('is-done');
            s3.classList.add('is-done');
            if (t3) t3.textContent = '✓ 起動済';
            s4.classList.add('is-active');
            if (t4) t4.textContent = '👉 次はここ！';
            if (lead) lead.textContent = '💿 開いた小窓の「CD-Rへ書き込み開始」を押してください！';
        } else if (phase === 'manual') {
            s2.classList.add('is-done');
            s3.classList.add('is-active');
            if (t3) t3.textContent = '右下を確認';
            if (lead) lead.textContent = '👇 右下の案内から「①セットアップ」または「②今回だけ書込」を押してください';
        } else {
            s2.classList.add('is-active');
            s3.classList.add('is-active');
            if (t2) t2.textContent = '準備';
            if (t3) t3.textContent = '👉 押すだけ';
            if (lead) lead.textContent = '👇 空のCD-Rを入れて、右の「💿 CD-Rへ書き込む」を押してください';
        }
    }

    function escHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }
    </script>
</body>
</html>
