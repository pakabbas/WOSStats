<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/player-lookup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    ensureDataFilesExist();
    $config = loadConfig();

    $uid = preg_replace('/\D+/', '', (string) ($_GET['uid'] ?? $_POST['uid'] ?? '')) ?? '';
    if ($uid === '' || strlen($uid) < 5) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Enter a valid player ID.']);
        exit;
    }

    $lookup = lookupPlayerByUid($uid, $config);
    if ($lookup === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'No player found for that ID.']);
        exit;
    }

    $player = enrichPlayerLookupWithLocalData($lookup);
    if (($player['name'] ?? null) === null
        && ($player['kid'] ?? null) === null
        && ($player['power'] ?? null) === null
        && ($player['x'] ?? null) === null
    ) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'No player found for that ID.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'player' => [
            'id' => $player['id'] ?? $uid,
            'fid' => $player['fid'] ?? null,
            'name' => $player['name'] ?? null,
            'kid' => $player['kid'] ?? null,
            'power' => $player['power'] ?? null,
            'stove_lv' => $player['stove_lv'] ?? null,
            'alliance_tag' => $player['alliance_tag'] ?? null,
            'x' => $player['x'] ?? null,
            'y' => $player['y'] ?? null,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Lookup failed. Try again.']);
}
