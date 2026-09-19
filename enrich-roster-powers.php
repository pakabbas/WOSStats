<?php

declare(strict_types=1);

/**
 * Batch-enrich tracked roster powers into data/roster-powers/{aid}.json
 *
 * Usage:
 *   /enrich-roster-powers.php?token=UPDATE_TOKEN&aid=4627000048
 *   /enrich-roster-powers.php?token=UPDATE_TOKEN   (all tracked alliances)
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/collector.php';
require_once __DIR__ . '/player-lookup.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $config = loadConfig();
    $providedToken = (string) ($_GET['token'] ?? '');
    $expectedToken = (string) ($config['update_token'] ?? '');

    if ($expectedToken === '' || $expectedToken === 'CHANGE_ME' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo "403 Forbidden\n";
        exit;
    }

    ensureDataFilesExist();

    $onlyAid = trim((string) ($_GET['aid'] ?? ''));
    $aids = $onlyAid !== ''
        ? [$onlyAid]
        : array_map('strval', array_keys(TRACKED_ROSTER_ALLIANCES));

    $statePlayers = loadJson(PLAYERS_FILE, []);
    $stateById = [];
    if (is_array($statePlayers)) {
        foreach ($statePlayers as $player) {
            if (is_array($player) && !empty($player['id'])) {
                $stateById[(string) $player['id']] = $player;
            }
        }
    }

    foreach ($aids as $allianceId) {
        $allianceId = (string) $allianceId;
        if (!isTrackedRosterAlliance($allianceId)) {
            echo "Skip {$allianceId}: not tracked\n";
            continue;
        }

        $roster = loadAllianceRoster($allianceId);
        $members = $roster['members'] ?? [];
        if (!is_array($members) || $members === []) {
            echo "Skip {$allianceId}: no roster loaded\n";
            continue;
        }

        $tag = TRACKED_ROSTER_ALLIANCES[$allianceId];
        echo "Enriching [{$tag}] {$allianceId} — " . count($members) . " members\n";

        $saved = [];
        $withPower = 0;
        $lookedUp = 0;

        foreach ($members as $index => $member) {
            if (!is_array($member) || empty($member['id'])) {
                continue;
            }
            $uid = (string) $member['id'];
            $entry = [
                'id' => $uid,
                'name' => $member['name'] ?? null,
                'power' => null,
                'stove_lv' => $member['lv'] ?? null,
                'last_jump_pct' => null,
                'fetched_at' => nowUtc(),
                'source' => 'roster',
            ];

            if (isset($stateById[$uid])) {
                $sp = $stateById[$uid];
                if (isset($sp['power'])) {
                    $entry['power'] = (int) $sp['power'];
                    $entry['source'] = 'top200';
                }
                if (isset($sp['last_jump_pct'])) {
                    $entry['last_jump_pct'] = (float) $sp['last_jump_pct'];
                }
                if (isset($sp['stove_lv'])) {
                    $entry['stove_lv'] = (int) $sp['stove_lv'];
                }
                if (!empty($sp['name'])) {
                    $entry['name'] = (string) $sp['name'];
                }
            }

            // Atlas search (needs FREE auth via config api_key / cookie to return power).
            if ($entry['power'] === null) {
                try {
                    $live = lookupPlayerByUid($uid, $config);
                    $lookedUp++;
                    if (is_array($live)) {
                        if (!empty($live['name'])) {
                            $entry['name'] = $live['name'];
                        }
                        if (isset($live['power']) && $live['power'] !== null) {
                            $entry['power'] = (int) $live['power'];
                            $entry['source'] = 'atlas-search';
                        }
                        if (isset($live['stove_lv']) && $live['stove_lv'] !== null) {
                            $entry['stove_lv'] = (int) $live['stove_lv'];
                        }
                        if (isset($live['last_jump_pct']) && $live['last_jump_pct'] !== null) {
                            $entry['last_jump_pct'] = (float) $live['last_jump_pct'];
                        }
                        $entry['fetched_at'] = $live['fetched_at'] ?? nowUtc();
                    }
                    usleep(150000); // ~6.5 req/s soft rate limit
                } catch (Throwable $e) {
                    echo "  lookup fail {$uid}: " . $e->getMessage() . "\n";
                }
            }

            if ($entry['power'] !== null) {
                $withPower++;
            }
            $saved[$uid] = $entry;

            if ((($index + 1) % 20) === 0) {
                echo "  … " . ($index + 1) . "/" . count($members) . "\n";
                saveRosterPowers($allianceId, $saved, 'enrich');
            }
        }

        saveRosterPowers($allianceId, $saved, 'enrich');
        echo "Saved data/roster-powers/{$allianceId}.json — {$withPower}/" . count($saved) . " with power (lookups={$lookedUp})\n\n";
    }

    echo "Done.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Failed: " . $e->getMessage() . "\n";
}
