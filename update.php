<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/collector.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $config = loadConfig();
    $providedToken = $_GET['token'] ?? '';
    $expectedToken = (string) ($config['update_token'] ?? '');

    if ($expectedToken === '' || $expectedToken === 'CHANGE_ME') {
        http_response_code(500);
        echo "Update failed.\n\nSet a secure update_token in config.json before running updates.\n";
        exit;
    }

    if (!hash_equals($expectedToken, (string) $providedToken)) {
        http_response_code(403);
        echo "403 Forbidden\n";
        exit;
    }

    ensureDataFilesExist();

    $stateId = (string) ($config['state_id'] ?? '');
    if ($stateId === '') {
        throw new RuntimeException('state_id is not configured in config.json.');
    }

    $alliances = fetchAlliances($stateId);
    validateAlliances($alliances);

    $existingAlliances = loadJson(ALLIANCES_FILE, []);
    $existingHistory = loadJson(HISTORY_FILE, []);

    saveJson(ALLIANCES_FILE, $alliances);

    $snapshot = [
        'timestamp' => date('Y-m-d H:i:s'),
        'alliances' => array_map(static function (array $alliance): array {
            return [
                'id' => $alliance['id'] ?? null,
                'name' => $alliance['name'] ?? null,
                'tag' => $alliance['tag'] ?? null,
                'rank' => $alliance['rank'] ?? null,
                'power' => $alliance['power'] ?? null,
                'members' => $alliance['members'] ?? null,
            ];
        }, $alliances),
    ];

    $existingHistory[] = $snapshot;
    $existingHistory = pruneHistory($existingHistory);
    saveJson(HISTORY_FILE, $existingHistory);

    echo "Update successful.\n\n";
    echo 'Alliances found: ' . count($alliances) . "\n";
    echo 'Updated: ' . formatDisplayDate($snapshot['timestamp']) . "\n";

    if (!empty($config['sample_mode'])) {
        echo "\nNote: sample_mode is enabled — data came from sample-alliances.json, not a live API.\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "Update failed.\n\n";
    echo "Previous data has NOT been modified.\n\n";
    echo 'Error: ' . $e->getMessage() . "\n";
}
