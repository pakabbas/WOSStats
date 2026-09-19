<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/collector.php';
require_once __DIR__ . '/player-lookup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const PLAYER_LOOKUP_CACHE_TTL = 6 * 3600; // 6 hours
const PLAYER_LOOKUP_BATCH_MAX = 20;

try {
    ensureDataFilesExist();
    $config = loadConfig();

    $allianceId = (string) ($_GET['aid'] ?? $_POST['aid'] ?? '');
    if ($allianceId === '' || !isTrackedRosterAlliance($allianceId)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $uidsRaw = $_GET['uids'] ?? $_POST['uids'] ?? '';
    if (is_array($uidsRaw)) {
        $uids = $uidsRaw;
    } else {
        $uids = preg_split('/\s*,\s*/', trim((string) $uidsRaw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    $uids = array_values(array_unique(array_map(static fn($uid): string => preg_replace('/\D+/', '', (string) $uid) ?? '', $uids)));
    $uids = array_values(array_filter($uids, static fn(string $uid): bool => $uid !== ''));

    if ($uids === []) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No UIDs provided']);
        exit;
    }

    if (count($uids) > PLAYER_LOOKUP_BATCH_MAX) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Max ' . PLAYER_LOOKUP_BATCH_MAX . ' UIDs per request']);
        exit;
    }

    // Only enrich UIDs that belong to this alliance roster.
    $roster = loadAllianceRoster($allianceId);
    $allowed = [];
    foreach ($roster['members'] ?? [] as $member) {
        if (!empty($member['id'])) {
            $allowed[(string) $member['id']] = true;
        }
    }

    $uids = array_values(array_filter($uids, static fn(string $uid): bool => isset($allowed[$uid])));
    if ($uids === []) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'UIDs not in roster']);
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

    foreach ($uids as $uid) {
        $entry = isset($cache[$uid]) && is_array($cache[$uid]) ? $cache[$uid] : null;
        $fetchedAt = isset($entry['fetched_at']) ? strtotime((string) $entry['fetched_at']) : false;
        $fresh = $entry !== null && $fetchedAt !== false && ($now - $fetchedAt) < PLAYER_LOOKUP_CACHE_TTL;

        if (!$fresh) {
            $live = lookupPlayerByUid($uid, $config);
            if ($live !== null) {
                $entry = mergeLookupCacheEntry($entry, $live);
                $cache[$uid] = $entry;
                $fetched++;
            } elseif ($entry === null) {
                $entry = [
                    'id' => $uid,
                    'name' => null,
                    'power' => null,
                    'stove_lv' => null,
                    'last_jump_pct' => null,
                    'fetched_at' => nowUtc(),
                    'viewer_tier' => null,
                ];
                $cache[$uid] = $entry;
                $fetched++;
            } else {
                // Keep stale cache if live call failed / returned empty stats.
                $cached++;
            }
        } else {
            $cached++;
        }

        $players[$uid] = [
            'id' => $uid,
            'name' => $entry['name'] ?? null,
            'power' => isset($entry['power']) ? (int) $entry['power'] : null,
            'stove_lv' => isset($entry['stove_lv']) ? (int) $entry['stove_lv'] : null,
            'last_jump_pct' => isset($entry['last_jump_pct']) ? (float) $entry['last_jump_pct'] : null,
            'power_change_24h' => calculateCachedPowerChange($entry, 24),
            'power_change_7d' => calculateCachedPowerChange($entry, 24 * 7),
            'fetched_at' => $entry['fetched_at'] ?? null,
            'viewer_tier' => $entry['viewer_tier'] ?? null,
        ];
    }

    saveJson(PLAYER_LOOKUP_CACHE_FILE, $cache);

    echo json_encode([
        'ok' => true,
        'aid' => $allianceId,
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
function mergeLookupCacheEntry(?array $existing, array $live): array
{
    $history = [];
    if (isset($existing['history']) && is_array($existing['history'])) {
        $history = $existing['history'];
    }

    $power = $live['power'] ?? null;
    $stove = $live['stove_lv'] ?? null;
    if ($power !== null || $stove !== null) {
        $history[] = [
            't' => $live['fetched_at'] ?? nowUtc(),
            'power' => $power,
            'stove_lv' => $stove,
        ];
        if (count($history) > 60) {
            $history = array_slice($history, -60);
        }
    }

    return [
        'id' => $live['id'] ?? ($existing['id'] ?? null),
        'name' => $live['name'] ?? ($existing['name'] ?? null),
        'power' => $power ?? ($existing['power'] ?? null),
        'stove_lv' => $stove ?? ($existing['stove_lv'] ?? null),
        'last_jump_pct' => $live['last_jump_pct'] ?? ($existing['last_jump_pct'] ?? null),
        'fetched_at' => $live['fetched_at'] ?? nowUtc(),
        'viewer_tier' => $live['viewer_tier'] ?? ($existing['viewer_tier'] ?? null),
        'history' => $history,
    ];
}

/**
 * @param array<string, mixed> $entry
 */
function calculateCachedPowerChange(array $entry, int $hours): ?int
{
    $current = $entry['power'] ?? null;
    $history = $entry['history'] ?? [];
    if ($current === null || !is_array($history) || $history === []) {
        return null;
    }

    $target = time() - ($hours * 3600);
    $best = null;
    $bestDelta = null;

    foreach ($history as $point) {
        if (!is_array($point) || !isset($point['t'], $point['power'])) {
            continue;
        }
        $ts = strtotime((string) $point['t']);
        if ($ts === false) {
            continue;
        }
        $delta = abs($ts - $target);
        if ($bestDelta === null || $delta < $bestDelta) {
            $best = $point;
            $bestDelta = $delta;
        }
    }

    if ($best === null || $bestDelta === null || $bestDelta > max(6 * 3600, (int) ($hours * 3600 * 0.5))) {
        return null;
    }

    return (int) $current - (int) $best['power'];
}
