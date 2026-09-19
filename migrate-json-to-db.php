<?php

declare(strict_types=1);

/**
 * One-time / repeatable import of existing JSON data files into MySQL.
 *
 * CLI:  php migrate-json-to-db.php
 * Web:  /migrate-json-to-db.php?token=UPDATE_TOKEN
 *
 * Reads from data/*.json on disk (bypassing DB) and writes into MySQL via saveJson.
 */

require_once __DIR__ . '/functions.php';

header_remove();
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

function migrateReadJsonFile(string $path, mixed $default = []): mixed
{
    if (!is_file($path)) {
        return $default;
    }
    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return $default;
    }
    $data = json_decode($json, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON in ' . $path . ': ' . json_last_error_msg());
    }
    return $data ?? $default;
}

try {
    $config = loadConfig();
    if (PHP_SAPI !== 'cli') {
        $token = (string) ($_GET['token'] ?? '');
        $expected = (string) ($config['update_token'] ?? '');
        if ($expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            echo "403 Forbidden\n";
            exit;
        }
    }

    if (!dbEnabled()) {
        throw new RuntimeException('Add a "db" block to config.json before migrating.');
    }

    dbEnsureSchema();
    echo "Schema OK\n";
    echo 'DB: ' . (dbConfig()['name'] ?? '?') . ' @ ' . (dbConfig()['host'] ?? '?') . "\n\n";

    $jobs = [
        'alliances' => [ALLIANCES_FILE, []],
        'alliance_history' => [HISTORY_FILE, []],
        'players' => [PLAYERS_FILE, []],
        'player_history' => [PLAYERS_HISTORY_FILE, []],
        'roster_history' => [ROSTERS_HISTORY_FILE, []],
        'incidents' => [INCIDENTS_FILE, []],
        'nap_banned' => [NAP_BANNED_FILE, []],
        'player_lookup_cache' => [PLAYER_LOOKUP_CACHE_FILE, []],
    ];

    foreach ($jobs as $label => [$path, $default]) {
        $data = migrateReadJsonFile($path, $default);

        // Never wipe durable lists with an empty JSON file during migrate/deploy.
        if (in_array($label, ['incidents', 'nap_banned'], true) && (!is_array($data) || $data === [])) {
            $existingCount = 0;
            try {
                $existingCount = count(loadJson($path, []));
            } catch (Throwable $e) {
                $existingCount = 0;
            }
            echo "Skipped {$label}: source empty" . ($existingCount > 0 ? " (keeping {$existingCount} DB rows)" : '') . "\n";
            continue;
        }

        saveJson($path, $data);
        $count = is_array($data) ? count($data) : 0;
        echo "Imported {$label}: {$count}\n";
    }

    foreach (TRACKED_ROSTER_ALLIANCES as $aid => $tag) {
        $rosterPath = ROSTERS_DIR . '/' . $aid . '.json';
        $powersPath = ROSTER_POWERS_DIR . '/' . $aid . '.json';
        if (is_file($rosterPath)) {
            $roster = migrateReadJsonFile($rosterPath, []);
            saveJson($rosterPath, $roster);
            $members = is_array($roster['members'] ?? null) ? count($roster['members']) : 0;
            echo "Imported roster {$tag} ({$aid}): {$members} members\n";
        }
        if (is_file($powersPath)) {
            $powers = migrateReadJsonFile($powersPath, []);
            saveJson($powersPath, $powers);
            echo "Imported roster powers {$tag} ({$aid})\n";
        }
    }

    // Verify round-trip counts
    echo "\nVerify from DB:\n";
    echo '  alliances=' . count(loadJson(ALLIANCES_FILE, [])) . "\n";
    echo '  players=' . count(loadJson(PLAYERS_FILE, [])) . "\n";
    echo '  alliance_history=' . count(loadJson(HISTORY_FILE, [])) . "\n";
    echo '  player_history=' . count(loadJson(PLAYERS_HISTORY_FILE, [])) . "\n";
    echo "\nMigration complete.\n";
} catch (Throwable $e) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
