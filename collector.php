<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/atlas_v2.php';

/**
 * Fetch alliance data for a Whiteout Survival state.
 *
 * Data source priority:
 * 1. sample_mode in config (development only — clearly marked sample data)
 * 2. api_url in config (HTTP JSON source, e.g. WOS Atlas leaderboard)
 *
 * Supported response shapes:
 * - { "alliances": [ ... ] } (native tracker format)
 * - { "entries": [ ... ] } (WOS Atlas: aid/abbr/totalPower)
 * - a bare JSON array of alliance objects
 */
function fetchAlliances(string $stateId): array
{
    $config = loadConfig();

    if (!empty($config['sample_mode'])) {
        return fetchSampleAlliances($stateId);
    }

    $apiUrl = trim((string) ($config['api_url'] ?? ''));
    if (atlasV2Enabled($config)) {
        // Retired v1 developer API (401 INVALID_API_KEY) → public v2 /servers/{kid}/alliances.
        $payload = atlasV2AlliancesLeaderboard($stateId);
    } elseif ($apiUrl === '') {
        throw new RuntimeException(
            'No alliance data source configured. Set api_url in config.json, or enable sample_mode.'
        );
    } else {
        $payload = httpGetJson($apiUrl, $stateId, $config);
    }
    $rawAlliances = extractListFromPayload($payload, ['alliances', 'entries']);
    $alliances = [];
    $rank = 1;

    foreach ($rawAlliances as $alliance) {
        if (!is_array($alliance)) {
            continue;
        }

        $normalized = normalizeAlliance($alliance, $rank);
        if ($normalized['name'] === null && $normalized['tag'] === null) {
            continue;
        }

        $normalized['rank'] = $rank;
        $normalized['updated_at'] = nowUtc();
        $alliances[] = $normalized;
        $rank++;
    }

    return limitAlliancesToTop($alliances);
}

/**
 * Fetch top player power rankings for a state (JSON-based).
 * Uses players_api_url, defaulting to WOS Atlas /power/leaderboard.
 */
function fetchPlayers(string $stateId): array
{
    $config = loadConfig();

    if (!empty($config['sample_mode'])) {
        return [];
    }

    $apiUrl = trim((string) ($config['players_api_url'] ?? ''));
    if (atlasV2Enabled($config) && ($apiUrl === '' || str_contains($apiUrl, 'api.wosatlas.com'))) {
        // v2 has no 200-row board: power.rank (20) + top-20 members of the top-20 alliances.
        // Callers must MERGE this into the existing board (see update.php).
        $payload = atlasV2PowerLeaderboard($stateId, TOP_PLAYERS_LIMIT);
    } elseif ($apiUrl === '') {
        return [];
    } else {
        $payload = httpGetJson($apiUrl, $stateId, $config);
    }
    $rawPlayers = extractListFromPayload($payload, ['players', 'entries']);
    $players = [];
    $rank = 1;

    foreach ($rawPlayers as $player) {
        if (!is_array($player)) {
            continue;
        }

        $normalized = normalizePlayer($player, $rank);
        if ($normalized['name'] === null && $normalized['id'] === null) {
            continue;
        }

        $normalized['rank'] = isset($normalized['rank']) ? (int) $normalized['rank'] : $rank;
        $normalized['updated_at'] = nowUtc();
        $players[] = $normalized;
        $rank++;
    }

    return limitPlayersToTop($players);
}

function fetchSampleAlliances(string $stateId): array
{
    $sampleFile = DATA_DIR . '/sample-alliances.json';
    if (!file_exists($sampleFile)) {
        throw new RuntimeException('Sample data file not found: data/sample-alliances.json');
    }

    $data = loadJson($sampleFile, []);
    if (!is_array($data)) {
        throw new RuntimeException('Sample data file contains invalid JSON.');
    }

    $alliances = [];
    $rank = 1;

    foreach ($data as $alliance) {
        if (!is_array($alliance)) {
            continue;
        }

        $normalized = normalizeAlliance($alliance, $rank);
        $normalized['updated_at'] = nowUtc();
        $alliances[] = $normalized;
        $rank++;
    }

    return limitAlliancesToTop($alliances);
}

function httpGetJson(string $apiUrl, string $stateId, array $config): array
{
    $url = buildApiUrl($apiUrl, $stateId);
    $apiKey = trim((string) ($config['api_key'] ?? ''));

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize HTTP client.');
    }

    $headers = ['Accept: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'WhiteoutStateTracker/1.0',
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('API request failed: ' . ($curlError ?: 'unknown error'));
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('API returned HTTP ' . $httpCode . ' for ' . $url);
    }

    $payload = json_decode($response, true);
    if (!is_array($payload)) {
        throw new RuntimeException('API returned invalid JSON.');
    }

    return $payload;
}

function buildApiUrl(string $apiUrl, string $stateId): string
{
    $encoded = rawurlencode($stateId);

    if (str_contains($apiUrl, '{state_id}')) {
        return str_replace('{state_id}', $encoded, $apiUrl);
    }

    if (preg_match('/[?&](?:kid|state_id)=/', $apiUrl) === 1) {
        return $apiUrl;
    }

    $separator = str_contains($apiUrl, '?') ? '&' : '?';
    return $apiUrl . $separator . 'kid=' . $encoded;
}

/**
 * Fetch / import roster for tracked alliances (AOC, WaS).
 * Atlas v2 gives the top 20 members only; merge them onto the stored roster / local dump.
 *
 * @return array{aid: string, tag: string, name: ?string, member_count: int, members: list<array>, updated_at: string, source: string}|null
 */
function fetchAllianceRoster(string $allianceId): ?array
{
    if (!isTrackedRosterAlliance($allianceId)) {
        return null;
    }

    $config = loadConfig();
    $tag = TRACKED_ROSTER_ALLIANCES[$allianceId];
    $payload = null;
    $source = 'import';

    // v2 returns only the TOP 20 members by power, so live data is merged onto the
    // last stored roster (or the import dump) instead of replacing it.
    $live = null;
    try {
        $live = atlasV2AllianceMembersPayload($allianceId);
        if (!is_array($live) || empty($live['members'])) {
            $live = null;
        }
    } catch (Throwable $e) {
        $live = null; // Fall through to stored roster / import dumps.
    }

    $base = loadAllianceRoster($allianceId);
    if (!empty($base['members'])) {
        $payload = $base;
        $source = preg_replace('/^atlas-v2(-top20\+)?/', '', (string) ($base['source'] ?? 'stored')) ?: 'stored';
    } else {
        $payload = loadRosterImportPayload($allianceId, $tag);
        $source = 'import';
    }

    if ($live !== null) {
        $payload = mergeLiveRosterMembers(is_array($payload) ? $payload : [], $live);
        $source = ($live['partial'] ?? false) ? 'atlas-v2-top20+' . $source : 'atlas-v2';
    }

    if ($payload === null) {
        return null;
    }

    $members = [];
    foreach ($payload['members'] ?? [] as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $normalized = normalizeRosterMember($raw);
        if ($normalized !== null) {
            $members[] = $normalized;
        }
    }

    if ($members === []) {
        return null;
    }

    usort($members, static function (array $a, array $b): int {
        $lvA = $a['lv'] ?? -1;
        $lvB = $b['lv'] ?? -1;
        if ($lvA !== $lvB) {
            return $lvB <=> $lvA;
        }
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return [
        'aid' => $allianceId,
        'tag' => (string) ($payload['abbr'] ?? $tag),
        'name' => isset($payload['name']) ? (string) $payload['name'] : null,
        'leader_name' => isset($payload['leaderName']) ? (string) $payload['leaderName'] : null,
        'member_count' => count($members),
        'members' => $members,
        'updated_at' => nowUtc(),
        'source' => $source,
    ];
}

/**
 * Merge a partial live member list (top 20) onto a stored/import roster payload, keyed by uid.
 * Members missing from the live list are kept as-is (v2 cannot tell us who left).
 *
 * @param array<string, mixed> $base
 * @param array<string, mixed> $live
 * @return array<string, mixed>
 */
function mergeLiveRosterMembers(array $base, array $live): array
{
    $byId = [];
    foreach ($base['members'] ?? [] as $m) {
        if (!is_array($m)) {
            continue;
        }
        $id = (string) ($m['uid'] ?? $m['id'] ?? '');
        if ($id !== '') {
            $byId[$id] = $m;
        }
    }
    foreach ($live['members'] ?? [] as $m) {
        $id = (string) ($m['uid'] ?? '');
        if ($id === '') {
            continue;
        }
        $prev = $byId[$id] ?? [];
        // normalizeRosterMember reads uid/id, nick_name/name, lv, office, x, y, abbr.
        unset($prev['id'], $prev['name']);
        $byId[$id] = array_merge($prev, array_filter($m, static fn($v): bool => $v !== null));
    }
    $base['members'] = array_values($byId);
    foreach (['abbr', 'name', 'leaderName'] as $k) {
        if (!empty($live[$k])) {
            $base[$k] = $live[$k];
        }
    }

    return $base;
}

function loadRosterImportPayload(string $allianceId, string $tag): ?array
{
    $candidates = [
        ROSTER_IMPORTS_DIR . '/' . $allianceId . '.json',
        __DIR__ . '/' . $tag . '.json',
        __DIR__ . '/' . strtoupper($tag) . '.json',
    ];

    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $data = loadJson($path, null);
        if (!is_array($data) || empty($data['members']) || !is_array($data['members'])) {
            continue;
        }
        return $data;
    }

    return null;
}

/**
 * Persist current roster + history snapshot for every tracked alliance.
 *
 * @return array<string, int> map of aid => member count saved
 */
function updateTrackedRosters(): array
{
    ensureDataFilesExist();

    $historyStore = loadJson(ROSTERS_HISTORY_FILE, []);
    if (!is_array($historyStore)) {
        $historyStore = [];
    }

    $saved = [];
    $timestamp = nowUtc();

    foreach (array_keys(TRACKED_ROSTER_ALLIANCES) as $allianceId) {
        $allianceId = (string) $allianceId;
        $roster = fetchAllianceRoster($allianceId);
        if ($roster === null) {
            continue;
        }

        saveJson(rosterFilePath($allianceId), $roster);

        if (!isset($historyStore[$allianceId]) || !is_array($historyStore[$allianceId])) {
            $historyStore[$allianceId] = [];
        }

        $historyStore[$allianceId][] = [
            'timestamp' => $timestamp,
            'members' => array_map(static function (array $member): array {
                return [
                    'id' => $member['id'] ?? null,
                    'name' => $member['name'] ?? null,
                    'lv' => $member['lv'] ?? null,
                    'office' => $member['office'] ?? null,
                    'x' => $member['x'] ?? null,
                    'y' => $member['y'] ?? null,
                ];
            }, $roster['members']),
        ];

        $historyStore[$allianceId] = pruneHistory($historyStore[$allianceId]);
        $saved[$allianceId] = count($roster['members']);
    }

    saveJson(ROSTERS_HISTORY_FILE, $historyStore);

    return $saved;
}

/**
 * @param list<string> $keys
 */
function extractListFromPayload(array $payload, array $keys): array
{
    foreach ($keys as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }

    if (array_is_list($payload)) {
        return $payload;
    }

    throw new RuntimeException('API response missing list keys: ' . implode(', ', $keys));
}
