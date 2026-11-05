<?php

declare(strict_types=1);

/**
 * Runtime Atlas alliance lookup for top-board player UIDs.
 * Does not modify players / Upload / updatePower — read-only + cache.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/player-lookup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const PLAYER_ALLIANCE_LOOKUP_TTL = 24 * 3600; // once per day
const PLAYER_ALLIANCE_LOOKUP_BATCH_MAX = 25;

try {
    ensureDataFilesExist();
    $config = loadConfig();

    $uidsRaw = $_GET['uids'] ?? $_POST['uids'] ?? '';
    if (is_array($uidsRaw)) {
        $uids = $uidsRaw;
    } else {
        $uids = preg_split('/\s*,\s*/', trim((string) $uidsRaw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    $uids = array_values(array_unique(array_map(
        static fn($uid): string => preg_replace('/\D+/', '', (string) $uid) ?? '',
        $uids
    )));
    $uids = array_values(array_filter($uids, static fn(string $uid): bool => $uid !== ''));

    if ($uids === []) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No UIDs provided']);
        exit;
    }

    if (count($uids) > PLAYER_ALLIANCE_LOOKUP_BATCH_MAX) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Max ' . PLAYER_ALLIANCE_LOOKUP_BATCH_MAX . ' UIDs per request']);
        exit;
    }

    // Only allow UIDs that are on the tracked top players board.
    $board = loadJson(PLAYERS_FILE, []);
    $allowed = [];
    if (is_array($board)) {
        foreach ($board as $player) {
            if (!is_array($player)) {
                continue;
            }
            $id = trim((string) ($player['id'] ?? ''));
            if ($id !== '') {
                $allowed[$id] = true;
            }
        }
    }

    $uids = array_values(array_filter($uids, static fn(string $uid): bool => isset($allowed[$uid])));
    if ($uids === []) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'UIDs not on players board']);
        exit;
    }

    $cache = loadJson(PLAYER_LOOKUP_CACHE_FILE, []);
    if (!is_array($cache)) {
        $cache = [];
    }

    $now = time();
    $players = [];
    $fetched = 0;
    $cached = 0;
    $cacheDirty = false;
    /** @var array<string, array{alliance_id: ?string, alliance_tag: ?string}> $persistBoard */
    $persistBoard = [];

    foreach ($uids as $uid) {
        $entry = isset($cache[$uid]) && is_array($cache[$uid]) ? $cache[$uid] : null;
        $fetchedAt = isset($entry['fetched_at']) ? strtotime((string) $entry['fetched_at']) : false;
        // Fresh if we already resolved alliance fields for this UID within TTL
        // (tag may be null when the chief has no alliance).
        $fresh = $entry !== null
            && $fetchedAt !== false
            && ($now - $fetchedAt) < PLAYER_ALLIANCE_LOOKUP_TTL
            && array_key_exists('alliance_tag', $entry);

        if (!$fresh) {
            try {
                $live = lookupPlayerByUid($uid, $config);
            } catch (Throwable $e) {
                $live = null;
            }

            if ($live !== null) {
                $entry = mergeAllianceLookupCacheEntry($entry, $live);
                $cache[$uid] = $entry;
                $cacheDirty = true;
                $fetched++;
            } elseif ($entry === null) {
                $entry = [
                    'id' => $uid,
                    'alliance_id' => null,
                    'alliance_tag' => null,
                    'fetched_at' => nowUtc(),
                ];
                $cache[$uid] = $entry;
                $cacheDirty = true;
                $fetched++;
            } else {
                // Keep stale cache if live call failed; still serve it.
                $cached++;
            }
        } else {
            $cached++;
        }

        $tag = isset($entry['alliance_tag']) ? trim((string) $entry['alliance_tag']) : '';
        $aid = isset($entry['alliance_id']) ? trim((string) $entry['alliance_id']) : '';

        $players[$uid] = [
            'id' => $uid,
            'alliance_id' => $aid !== '' ? $aid : null,
            'alliance_tag' => $tag !== '' ? $tag : null,
            'fetched_at' => $entry['fetched_at'] ?? null,
            'cached' => $fresh,
        ];

        // Persist onto players board (MySQL) so tags survive and are reused next load.
        $persistBoard[$uid] = [
            'alliance_id' => $aid !== '' ? $aid : null,
            'alliance_tag' => $tag !== '' ? $tag : null,
        ];
    }

    if ($cacheDirty) {
        saveJson(PLAYER_LOOKUP_CACHE_FILE, $cache);
    }

    if ($persistBoard !== []) {
        persistPlayerAllianceTags($persistBoard);
    }

    echo json_encode([
        'ok' => true,
        'ttl_hours' => (int) (PLAYER_ALLIANCE_LOOKUP_TTL / 3600),
        'fetched' => $fetched,
        'cached' => $cached,
        'players' => $players,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

/**
 * @param array<string, mixed>|null $existing
 * @param array<string, mixed> $live
 * @return array<string, mixed>
 */
function mergeAllianceLookupCacheEntry(?array $existing, array $live): array
{
    $out = is_array($existing) ? $existing : [];
    $out['id'] = $live['id'] ?? ($out['id'] ?? null);
    if (($live['name'] ?? null) !== null) {
        $out['name'] = $live['name'];
    }
    if (array_key_exists('alliance_id', $live)) {
        $out['alliance_id'] = $live['alliance_id'];
    }
    if (array_key_exists('alliance_tag', $live)) {
        $out['alliance_tag'] = $live['alliance_tag'];
    }
    $out['fetched_at'] = $live['fetched_at'] ?? nowUtc();
    $out['viewer_tier'] = $live['viewer_tier'] ?? ($out['viewer_tier'] ?? null);

    return $out;
}

/**
 * Write alliance_id / alliance_tag onto the players board without touching power/rank.
 *
 * @param array<string, array{alliance_id: ?string, alliance_tag: ?string}> $byUid
 */
function persistPlayerAllianceTags(array $byUid): void
{
    if ($byUid === []) {
        return;
    }

    $board = loadJson(PLAYERS_FILE, []);
    if (!is_array($board) || $board === []) {
        return;
    }

    $changed = false;
    foreach ($board as &$player) {
        if (!is_array($player)) {
            continue;
        }
        $id = trim((string) ($player['id'] ?? ''));
        if ($id === '' || !isset($byUid[$id])) {
            continue;
        }

        $info = $byUid[$id];
        $newId = $info['alliance_id'] ?? null;
        $newTag = $info['alliance_tag'] ?? null;
        $oldId = isset($player['alliance_id']) ? (string) $player['alliance_id'] : null;
        $oldTag = isset($player['alliance_tag']) ? (string) $player['alliance_tag'] : null;

        $nextId = $newId !== null && $newId !== '' ? (string) $newId : null;
        $nextTag = $newTag !== null && $newTag !== '' ? (string) $newTag : null;

        if ($oldId !== $nextId || $oldTag !== $nextTag) {
            $player['alliance_id'] = $nextId;
            $player['alliance_tag'] = $nextTag;
            $changed = true;
        }
    }
    unset($player);

    if ($changed) {
        saveJson(PLAYERS_FILE, $board);
    }
}
