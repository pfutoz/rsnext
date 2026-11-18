<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/watcher_bridge.php';

function rsnext_normalize_patient_id(string $raw): ?string {
    $s = trim(mb_convert_kana($raw, 'as', 'UTF-8'));
    if ($s === '') {
        return null;
    }
    if (strlen($s) >= 12 && str_starts_with($s, '999999') && ctype_digit(substr($s, 6))) {
        $s = (string)((int)substr($s, 6));
    }
    if (preg_match('/^0*([0-9]+)\([A-Za-z]+\)$/', $s, $m)) {
        $s = $m[1];
    }
    if (!preg_match('/^[0-9]+$/', $s)) {
        return null;
    }
    $trimmed = ltrim($s, '0');
    if ($trimmed === '' || strlen($trimmed) > 10) {
        return null;
    }
    return $trimmed;
}

function rsnext_normalize_kana(string $text): string {
    if ($text === '') {
        return '';
    }
    $kana = mb_convert_kana($text, 'KVC', 'UTF-8');
    $kana = preg_replace('/[\s　]+/u', '', $kana) ?? '';
    return trim($kana);
}

function rsnext_format_wareki_birth(?string $birthStr): string {
    if (!$birthStr) {
        return '';
    }
    $clean = str_replace(['-', '.'], '/', trim($birthStr));
    $parts = explode('/', $clean);
    if (count($parts) < 3) {
        return $birthStr;
    }
    $y = (int)$parts[0];
    $m = (int)$parts[1];
    $d = (int)$parts[2];
    if ($y <= 0 || $m <= 0 || $d <= 0) {
        return $birthStr;
    }
    $ymd = sprintf('%04d%02d%02d', $y, $m, $d);
    $era = '';
    $ey = 0;
    if ($ymd >= '20190501') {
        $era = '令'; $ey = $y - 2018;
    } elseif ($ymd >= '19890108') {
        $era = '平'; $ey = $y - 1988;
    } elseif ($ymd >= '19261225') {
        $era = '昭'; $ey = $y - 1925;
    } elseif ($ymd >= '19120730') {
        $era = '大'; $ey = $y - 1911;
    } elseif ($ymd >= '18680125') {
        $era = '明'; $ey = $y - 1867;
    }
    $eraLabel = $era ? ($ey === 1 ? "{$era}元" : "{$era}{$ey}") : '';
    $fmt = sprintf('%04d/%02d/%02d', $y, $m, $d);
    return $eraLabel ? "{$fmt} ({$eraLabel})" : $fmt;
}

function rsnext_calc_age(?string $birthStr, ?string $refDateStr = null): string {
    if (!$birthStr) {
        return '';
    }
    try {
        $b = new DateTime(str_replace('/', '-', trim($birthStr)));
        $ref = $refDateStr ? new DateTime(str_replace('/', '-', trim($refDateStr))) : new DateTime();
        return (string)$b->diff($ref)->y;
    } catch (Throwable $e) {
        return '';
    }
}

function rsnext_friendly_kensamei(string $kensamei): string {
    if ($kensamei === 'ES_General') return '内視鏡';
    if ($kensamei === 'US_Adult_Abd_') return '超音波 (US)';
    return $kensamei;
}

function rsnext_build_karte_url(string $patientId, string $karteTpl = ''): string {
    $pid = trim($patientId);
    // 9始まりの6桁ID (900001〜999999) は おのカルテ (source=ono) の5桁番号へ自動逆変換
    if (strlen($pid) === 6 && str_starts_with($pid, '9') && ctype_digit($pid)) {
        $onoNo = ltrim(substr($pid, 1), '0') ?: '0';
        return 'http://192.168.1.16/karteview/karteview.php?patient_no=' . rawurlencode($onoNo) . '&source=ono&do_search=1';
    }
    if ($karteTpl !== '' && strpos($karteTpl, '{patient_id}') !== false) {
        return str_replace('{patient_id}', rawurlencode($pid), $karteTpl);
    }
    return 'http://192.168.1.16/karteview/karteview.php?patient_no=' . rawurlencode($pid) . '&source=new&do_search=1';
}

function rsnext_h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

