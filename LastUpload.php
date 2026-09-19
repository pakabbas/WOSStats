<?php

declare(strict_types=1);

/**
 * Public JSON API for OCR bots:
 * returns the current OCR key + last video upload datetime.
 */

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET only.']);
    exit;
}

$meta = ensureVideoMeta();
$path = currentVideoPublicPath();

if ($meta === null || $path === null) {
    echo json_encode([
        'ok' => true,
        'has_video' => false,
        'ocr_key' => null,
        'uploaded_at' => null,
        'uploaded_at_utc' => null,
        'video_path' => null,
        'video_url' => null,
        'todays_video_page' => rtrim(sitePublicBaseUrl(), '/') . '/TodaysVideo.php',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadedAt = $meta['uploaded_at'];
// Treat stored value as UTC wall-clock (no timezone suffix).
$uploadedAtUtc = preg_match('/Z$|[+-]\d{2}:?\d{2}$/', $uploadedAt) === 1
    ? $uploadedAt
    : $uploadedAt . ' UTC';

echo json_encode([
    'ok' => true,
    'has_video' => true,
    'ocr_key' => $meta['ocr_key'],
    'uploaded_at' => $uploadedAt,
    'uploaded_at_utc' => $uploadedAtUtc,
    'video_path' => $path,
    'video_url' => currentVideoAbsoluteUrl(),
    'todays_video_page' => rtrim(sitePublicBaseUrl(), '/') . '/TodaysVideo.php',
], JSON_UNESCAPED_UNICODE);
