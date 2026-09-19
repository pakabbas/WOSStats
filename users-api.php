<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

try {
    if ($method === 'GET' && ($action === 'me' || $action === '')) {
        $user = currentAppUser();
        echo json_encode([
            'ok' => true,
            'user' => $user,
            'google_client_id' => googleOAuthConfig()['client_id'],
        ]);
        exit;
    }

    if ($method === 'GET' && $action === 'list') {
        $me = requireAppUser();
        echo json_encode(['ok' => true, 'users' => listAppUsers($me['id'])]);
        exit;
    }

    if ($method === 'GET' && $action === 'messages') {
        $me = requireAppUser();
        $peer = trim((string) ($_GET['peer'] ?? ''));
        if ($peer === '') {
            throw new RuntimeException('Missing peer.');
        }
        $afterId = isset($_GET['after_id']) ? (int) $_GET['after_id'] : 0;
        $messages = listPrivateMessages($me['id'], $peer, 120);
        if ($afterId > 0) {
            $messages = array_values(array_filter($messages, static fn(array $m): bool => (int) $m['id'] > $afterId));
        }
        echo json_encode(['ok' => true, 'messages' => $messages]);
        exit;
    }

    if ($method === 'POST' && $action === 'send') {
        $me = requireAppUser();
        $raw = file_get_contents('php://input');
        $input = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($input)) {
            $input = $_POST;
        }
        $peer = trim((string) ($input['peer'] ?? ''));
        $text = (string) ($input['text'] ?? '');
        $imageUrl = isset($input['image_url']) ? trim((string) $input['image_url']) : null;
        $result = sendPrivateMessage($me['id'], $peer, $text, $imageUrl);
        if (!$result['ok']) {
            http_response_code(400);
        }
        echo json_encode($result);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    $code = str_contains($e->getMessage(), 'Sign in') ? 401 : 400;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
