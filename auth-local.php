<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($input)) {
    $input = $_POST;
}

try {
    $username = (string) ($input['username'] ?? '');
    $password = (string) ($input['password'] ?? '');
    $result = loginLocalAppUser($username, $password);
    if (!$result['ok'] || !isset($result['user'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Login failed.']);
        exit;
    }

    loginAppUser($result['user']);
    echo json_encode([
        'ok' => true,
        'user' => $result['user'],
        'is_admin' => isAppAdmin($result['user']),
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
