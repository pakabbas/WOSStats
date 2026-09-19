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

$action = (string) ($input['action'] ?? 'login');

try {
    if ($action === 'logout') {
        logoutAppUser();
        echo json_encode(['ok' => true]);
        exit;
    }

    $oauth = googleOAuthConfig();
    $clientId = $oauth['client_id'];
    if ($clientId === '') {
        throw new RuntimeException('Google client_id is not configured in config.json.');
    }

    $idToken = trim((string) ($input['id_token'] ?? $input['credential'] ?? ''));
    if ($idToken === '') {
        throw new RuntimeException('Missing Google ID token.');
    }

    $google = verifyGoogleIdToken($idToken, $clientId);
    if ($google === null) {
        throw new RuntimeException('Google token invalid or client_id mismatch.');
    }

    $user = upsertAppUserFromGoogle($google);
    loginAppUser($user);

    echo json_encode(['ok' => true, 'user' => $user]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
