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

    $timestamp = nowUtc();

    $alliances = fetchAlliances($stateId);
    validateAlliances($alliances);

    $players = fetchPlayers($stateId);
    $powerRaised = 0;
    $powerKept = 0;
    if ($players !== []) {
        validatePlayers($players);
        $existingPlayers = loadJson(PLAYERS_FILE, []);
        if (!is_array($existingPlayers)) {
            $existingPlayers = [];
        }
        $floored = applyNeverDecreasePlayerPower($players, $existingPlayers);
        $players = $floored['players'];
        $powerRaised = $floored['power_raised'];
        $powerKept = $floored['power_kept'];
        validatePlayers($players);
        $alliances = enrichAlliancesMaxPowerFromPlayers($alliances, $players);
    }

    $existingHistory = loadJson(HISTORY_FILE, []);
    $existingPlayersHistory = loadJson(PLAYERS_HISTORY_FILE, []);

    saveJson(ALLIANCES_FILE, $alliances);

    $allianceSnapshot = [
        'timestamp' => $timestamp,
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

    $existingHistory[] = $allianceSnapshot;
    saveJson(HISTORY_FILE, pruneHistory($existingHistory));

    if ($players !== []) {
        saveJson(PLAYERS_FILE, $players);

        $playerSnapshot = [
            'timestamp' => $timestamp,
            'players' => array_map(static function (array $player): array {
                return [
                    'id' => $player['id'] ?? null,
                    'name' => $player['name'] ?? null,
                    'rank' => $player['rank'] ?? null,
                    'power' => $player['power'] ?? null,
                    'stove_lv' => $player['stove_lv'] ?? null,
                    'last_jump_pct' => $player['last_jump_pct'] ?? null,
                ];
            }, $players),
            'source' => 'update.php',
            'power_policy' => 'never_decrease',
        ];

        $existingPlayersHistory[] = $playerSnapshot;
        saveJson(PLAYERS_HISTORY_FILE, pruneHistory($existingPlayersHistory));
    }

    $rosterCounts = updateTrackedRosters();

    echo "Update successful.\n\n";
    echo 'Alliances found: ' . count($alliances) . "\n";
    echo 'Players found: ' . count($players) . "\n";
    if ($players !== []) {
        echo 'Player power raised: ' . $powerRaised . "\n";
        echo 'Player power kept (not lowered): ' . $powerKept . "\n";
    }
    if ($rosterCounts !== []) {
        foreach ($rosterCounts as $aid => $count) {
            $tag = TRACKED_ROSTER_ALLIANCES[$aid] ?? $aid;
            echo 'Roster [' . $tag . ']: ' . $count . " members\n";
        }
    }
    echo 'Updated: ' . formatDisplayDate($timestamp) . "\n";

    if (!empty($config['sample_mode'])) {
        echo "\nNote: sample_mode is enabled — data came from sample-alliances.json, not a live API.\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "Update failed.\n\n";
    echo "Previous data has NOT been modified.\n\n";
    echo 'Error: ' . $e->getMessage() . "\n";
}
