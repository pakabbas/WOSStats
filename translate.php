<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    ? array_merge($_GET, $_POST)
    : $_GET;

$text = trim((string) ($input['q'] ?? $input['text'] ?? ''));
$target = strtolower(trim((string) ($input['tl'] ?? $input['to'] ?? 'en')));

if ($text === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing text']);
    exit;
}

if (mb_strlen($text) > 500) {
    $text = mb_substr($text, 0, 500);
}

$allowed = [
    'en', 'ar', 'zh-cn', 'zh-tw', 'de', 'es', 'fr', 'hi', 'id', 'it',
    'ja', 'ko', 'ms', 'nl', 'pl', 'pt', 'ru', 'th', 'tr', 'uk', 'ur', 'vi',
];

if (!in_array($target, $allowed, true)) {
    $target = 'en';
}

$url = 'https://translate.googleapis.com/translate_a/single?' . http_build_query([
    'client' => 'gtx',
    'sl' => 'auto',
    'tl' => $target,
    'dt' => 't',
    'q' => $text,
]);

$ch = curl_init($url);
if ($ch === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to start translator']);
    exit;
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; WOSTracker/1.0)',
]);

$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($body === false || $code < 200 || $code >= 300) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => $curlError !== '' ? $curlError : ('Translate HTTP ' . $code),
    ]);
    exit;
}

$decoded = json_decode((string) $body, true);
$translated = '';
if (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
    foreach ($decoded[0] as $chunk) {
        if (is_array($chunk) && isset($chunk[0]) && is_string($chunk[0])) {
            $translated .= $chunk[0];
        }
    }
}

$detected = null;
if (is_array($decoded) && isset($decoded[2]) && is_string($decoded[2])) {
    $detected = strtolower($decoded[2]);
}

if ($translated === '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Empty translation']);
    exit;
}

echo json_encode([
    'ok' => true,
    'text' => $text,
    'translated' => $translated,
    'to' => $target,
    'from' => $detected,
], JSON_UNESCAPED_UNICODE);
