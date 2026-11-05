<?php

declare(strict_types=1);

/**
 * WOS Atlas v2 client (public website API, anonymous GETs only).
 *
 * Replaces the retired developer API (https://api.wosatlas.com/v1/..., now 401 INVALID_API_KEY).
 * Base: https://wosatlas.com/api/v1
 *
 * Rules baked in:
 * - Anonymous GETs only. No login, no cookies, no Turnstile session, no api_key.
 * - Endpoints that answer 403 session_required (/search, /players/{id}/changes,
 *   /players/{id}/stats/*, /servers/{kid}/changes, any ?cursor=) are NOT called.
 * - Every list is capped at 20 items with next:null; there is no paging.
 * - Responses are file-cached under data/atlas-v2/cache; 404s are negative-cached.
 * - Every player record that passes through is added to a local index
 *   (data/atlas-v2/player-index.json) so FID (alt_id) → uid and name search can be
 *   answered locally.
 *
 * Adapters return the OLD payload shapes so collector.php / player-lookup.php keep working:
 * - atlasV2AlliancesLeaderboard()  ≈ old /v1/alliances/leaderboard  → ['entries' => [...]]
 * - atlasV2PowerLeaderboard()      ≈ old /v1/power/leaderboard      → ['entries' => [...]]
 * - atlasV2AllianceMembersPayload()≈ old /v1/alliances/{aid}/members → ['members' => [...], 'abbr', 'name', 'leaderName']
 * - atlasV2PlayerNormalized()      ≈ old /v1/players/search + /v1/players/{uid} → normalizeAtlasPlayerMatch() shape
 */

if (!defined('DATA_DIR')) {
    require_once __DIR__ . '/functions.php';
}

const ATLAS_V2_BASE = 'https://wosatlas.com/api/v1';
const ATLAS_V2_WEB = 'https://wosatlas.com';
const ATLAS_V2_DIR = DATA_DIR . '/atlas-v2';
const ATLAS_V2_CACHE_DIR = ATLAS_V2_DIR . '/cache';
const ATLAS_V2_INDEX_FILE = ATLAS_V2_DIR . '/player-index.json';
const ATLAS_V2_USER_AGENT = 'WOSTracker/2.0 (+https://wostracker.online)';
const ATLAS_V2_LIST_CAP = 20;

/** Cache TTLs (seconds). Boards refresh roughly hourly upstream; Cache-Control is max-age=60. */
const ATLAS_V2_TTL_BOARD = 3600;
const ATLAS_V2_TTL_ALLIANCES = 3600;
const ATLAS_V2_TTL_MEMBERS = 3600;
const ATLAS_V2_TTL_PLAYER = 6 * 3600;
const ATLAS_V2_TTL_NOT_FOUND = 12 * 3600;
/** Serve stale cache up to this age when upstream fails. */
const ATLAS_V2_STALE_MAX = 7 * 24 * 3600;
/** Max uncached upstream calls per PHP request (protects page loads + upstream). */
const ATLAS_V2_MAX_LIVE_CALLS = 60;
/** Pause between uncached calls (microseconds). */
const ATLAS_V2_PAUSE_US = 150000;

/** Board names the API accepts for /servers/{kid}/boards/{board}. */
const ATLAS_V2_BOARDS = ['alliance.rank', 'power.rank', 'furnace.rank', 'kill.rank'];

/**
 * Cached GET against the v2 API.
 *
 * @return array{status: int, body: ?array, cached: bool, stale: bool, tier: ?string, fetched_at: string}
 * @throws RuntimeException on network / 5xx / 401 / 403 errors when no usable cache exists
 */
function atlasV2Request(string $path, int $ttl): array
{
    $path = '/' . ltrim($path, '/');
    $cacheFile = ATLAS_V2_CACHE_DIR . '/' . sha1($path) . '.json';
    $cached = atlasV2ReadCache($cacheFile);
    $now = time();

    if ($cached !== null) {
        $age = $now - (int) ($cached['ts'] ?? 0);
        $maxAge = ((int) ($cached['status'] ?? 0) === 404) ? ATLAS_V2_TTL_NOT_FOUND : $ttl;
        if ($age >= 0 && $age < $maxAge) {
            return atlasV2Result($cached, true, false);
        }
    }

    static $liveCalls = 0;
    if ($liveCalls >= ATLAS_V2_MAX_LIVE_CALLS) {
        if ($cached !== null) {
            return atlasV2Result($cached, true, true);
        }
        throw new RuntimeException('Atlas v2 live-call budget exhausted for this request.');
    }
    if ($liveCalls > 0) {
        usleep(ATLAS_V2_PAUSE_US);
    }
    $liveCalls++;

    try {
        $live = atlasV2HttpGet(ATLAS_V2_BASE . $path);
    } catch (Throwable $e) {
        if ($cached !== null && ($now - (int) ($cached['ts'] ?? 0)) < ATLAS_V2_STALE_MAX) {
            return atlasV2Result($cached, true, true);
        }
        throw $e;
    }

    $status = $live['status'];
    if ($status === 200 || $status === 404) {
        $record = [
            'path' => $path,
            'ts' => $now,
            'status' => $status,
            'tier' => $live['tier'],
            'body' => $status === 200 ? $live['body'] : null,
        ];
        atlasV2WriteCache($cacheFile, $record);
        if ($status === 200 && is_array($live['body'])) {
            atlasV2IndexFromPayload($path, $live['body']);
        }

        return atlasV2Result($record, false, false);
    }

    // 401/403/429/5xx: fall back to stale cache if we have it.
    if ($cached !== null && ($now - (int) ($cached['ts'] ?? 0)) < ATLAS_V2_STALE_MAX) {
        return atlasV2Result($cached, true, true);
    }

    $err = is_array($live['body']) ? (string) ($live['body']['error'] ?? '') : '';
    throw new RuntimeException('Atlas v2 HTTP ' . $status . ($err !== '' ? ' (' . $err . ')' : '') . ' for ' . $path);
}

/**
 * Convenience: body of a 200, null for 404. Throws otherwise.
 */
function atlasV2Get(string $path, int $ttl): ?array
{
    $res = atlasV2Request($path, $ttl);

    return $res['status'] === 200 ? $res['body'] : null;
}

/**
 * @return array{status: int, body: ?array, tier: ?string}
 */
function atlasV2HttpGet(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP curl extension is required.');
    }

    $lastError = '';
    // Retry transient TLS (curl 35) / connect / timeout failures.
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize HTTP client.');
        }
        $tier = null;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => ATLAS_V2_USER_AGENT,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$tier): int {
                if (stripos($line, 'x-tier:') === 0) {
                    $tier = trim(substr($line, 7));
                }
                return strlen($line);
            },
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastError = curl_error($ch);
        curl_close($ch);

        if ($response !== false && $status > 0) {
            if ($status >= 500 && $attempt < 3) {
                usleep(400000 * $attempt);
                continue;
            }
            $body = json_decode((string) $response, true);

            return ['status' => $status, 'body' => is_array($body) ? $body : null, 'tier' => $tier];
        }
        if (!in_array($errno, [6, 7, 28, 35, 52, 56], true) || $attempt === 3) {
            break;
        }
        usleep(400000 * $attempt);
    }

    throw new RuntimeException('Atlas v2 request failed: ' . ($lastError !== '' ? $lastError : 'unknown error'));
}

function atlasV2Result(array $record, bool $cached, bool $stale): array
{
    return [
        'status' => (int) ($record['status'] ?? 0),
        'body' => is_array($record['body'] ?? null) ? $record['body'] : null,
        'cached' => $cached,
        'stale' => $stale,
        'tier' => $record['tier'] ?? null,
        'fetched_at' => gmdate('Y-m-d H:i:s', (int) ($record['ts'] ?? time())),
    ];
}

function atlasV2ReadCache(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);

    return is_array($data) ? $data : null;
}

function atlasV2WriteCache(string $file, array $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return; // cache is best-effort
    }
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        @rename($tmp, $file);
    }
}

/* ---------------------------------------------------------------------------
 * Local player index (uid → name/alt_id/alliance/power/kid; alt_id → uid)
 * ------------------------------------------------------------------------- */

function atlasV2LoadIndex(): array
{
    $data = atlasV2ReadCache(ATLAS_V2_INDEX_FILE);
    if (!is_array($data)) {
        return ['players' => [], 'alt' => []];
    }
    $data['players'] = is_array($data['players'] ?? null) ? $data['players'] : [];
    $data['alt'] = is_array($data['alt'] ?? null) ? $data['alt'] : [];

    return $data;
}

/**
 * @param list<array<string, mixed>> $rows v2 player-ish rows (detail or member rows)
 */
function atlasV2IndexPlayers(array $rows): void
{
    if ($rows === []) {
        return;
    }
    $index = atlasV2LoadIndex();
    $changed = false;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $uid = trim((string) ($row['uid'] ?? ''));
        if ($uid === '' || preg_match('/^\d+$/', $uid) !== 1) {
            continue;
        }
        $alt = trim((string) ($row['alt_id'] ?? ''));
        $index['players'][$uid] = [
            'uid' => $uid,
            'alt_id' => $alt !== '' ? $alt : ($index['players'][$uid]['alt_id'] ?? null),
            'name' => isset($row['name']) ? trim((string) $row['name']) : ($index['players'][$uid]['name'] ?? null),
            'power' => isset($row['power']) ? (int) $row['power'] : ($index['players'][$uid]['power'] ?? null),
            'hq_level' => isset($row['hq_level']) ? (int) $row['hq_level'] : ($index['players'][$uid]['hq_level'] ?? null),
            'alliance_id' => $row['alliance_id'] ?? null,
            'alliance_tag' => $row['alliance_tag'] ?? null,
            'kid' => (string) ($row['current_server_id'] ?? $row['home_server_id'] ?? ($index['players'][$uid]['kid'] ?? '')),
            'seen_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($alt !== '') {
            $index['alt'][$alt] = $uid;
        }
        $changed = true;
    }
    if ($changed) {
        atlasV2WriteCache(ATLAS_V2_INDEX_FILE, $index);
    }
}

function atlasV2IndexFromPayload(string $path, array $body): void
{
    if (preg_match('#^/players/\d+$#', $path) === 1 && isset($body['uid'])) {
        $row = $body;
        if (is_array($body['position'] ?? null)) {
            $row['current_server_id'] = $body['current_server_id'] ?? $body['position']['map_server_id'] ?? null;
        }
        atlasV2IndexPlayers([$row]);
    } elseif (preg_match('#^/alliances/\d+/members$#', $path) === 1 && is_array($body['items'] ?? null)) {
        atlasV2IndexPlayers($body['items']);
    }
}

/** Resolve an in-game FID (Atlas alt_id) to the Atlas uid from the local index. */
function atlasV2ResolveAltId(string $altId): ?string
{
    $altId = trim($altId);
    if ($altId === '') {
        return null;
    }
    $index = atlasV2LoadIndex();
    $uid = $index['alt'][$altId] ?? null;

    return is_string($uid) && $uid !== '' ? $uid : null;
}

/**
 * Local name search over the index (substring, case-insensitive).
 *
 * @return list<array<string, mixed>>
 */
function atlasV2SearchIndexByName(string $name, int $limit = 40): array
{
    $needle = mb_strtolower(trim($name));
    if ($needle === '') {
        return [];
    }
    $out = [];
    foreach (atlasV2LoadIndex()['players'] as $row) {
        $n = mb_strtolower((string) ($row['name'] ?? ''));
        if ($n !== '' && mb_strpos($n, $needle) !== false) {
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
    }

    return $out;
}

/** Link-out to WOS Atlas' own (Turnstile-protected) search, opened by the visitor's browser. */
function atlasV2WebSearchUrl(string $query): string
{
    return ATLAS_V2_WEB . '/?q=' . rawurlencode(trim($query));
}

function atlasV2WebPlayerUrl(string $uid): string
{
    return ATLAS_V2_WEB . '/players/' . rawurlencode($uid);
}

function atlasV2WebAllianceUrl(string $aid): string
{
    return ATLAS_V2_WEB . '/alliances/' . rawurlencode($aid);
}

/* ---------------------------------------------------------------------------
 * Raw endpoint wrappers
 * ------------------------------------------------------------------------- */

function atlasV2ServerAlliances(string $kid): array
{
    $body = atlasV2Get('/servers/' . rawurlencode($kid) . '/alliances', ATLAS_V2_TTL_ALLIANCES);

    return is_array($body['items'] ?? null) ? $body['items'] : [];
}

/**
 * @return array{board: string, observed_at: ?string, items: list<array<string, mixed>>}
 */
function atlasV2Board(string $kid, string $board): array
{
    if (!in_array($board, ATLAS_V2_BOARDS, true)) {
        throw new InvalidArgumentException('Unknown Atlas board: ' . $board);
    }
    $body = atlasV2Get('/servers/' . rawurlencode($kid) . '/boards/' . $board, ATLAS_V2_TTL_BOARD);

    return [
        'board' => $board,
        'observed_at' => isset($body['observed_at']) ? (string) $body['observed_at'] : null,
        'items' => is_array($body['items'] ?? null) ? $body['items'] : [],
    ];
}

function atlasV2Alliance(string $aid): ?array
{
    return atlasV2Get('/alliances/' . rawurlencode($aid), ATLAS_V2_TTL_ALLIANCES);
}

/** Top 20 members by power (the API caps the list at 20). */
function atlasV2AllianceMembers(string $aid): array
{
    $body = atlasV2Get('/alliances/' . rawurlencode($aid) . '/members', ATLAS_V2_TTL_MEMBERS);

    return is_array($body['items'] ?? null) ? $body['items'] : [];
}

function atlasV2Player(string $uid): ?array
{
    if (preg_match('/^\d+$/', $uid) !== 1) {
        return null;
    }

    return atlasV2Get('/players/' . $uid, ATLAS_V2_TTL_PLAYER);
}

/* ---------------------------------------------------------------------------
 * Old-shape adapters
 * ------------------------------------------------------------------------- */

/**
 * Old: GET api.wosatlas.com/v1/alliances/leaderboard?limit=20&kid={kid} → {entries:[{aid,abbr,name,totalPower,...}]}
 * New: GET /servers/{kid}/alliances (already sorted by power, = alliance.rank board order).
 *
 * Returned rows are already in normalizeAlliance() field names.
 */
function atlasV2AlliancesLeaderboard(string $kid): array
{
    $entries = [];
    $rank = 1;
    foreach (atlasV2ServerAlliances($kid) as $a) {
        if (!is_array($a) || !empty($a['dissolved_at'])) {
            continue;
        }
        $held = is_array($a['held_members'] ?? null) ? $a['held_members'] : [];
        $entries[] = [
            'id' => (string) ($a['alliance_id'] ?? ''),
            'name' => $a['name'] ?? null,
            'tag' => $a['tag'] ?? null,
            'rank' => $rank++,
            'power' => isset($a['power']) ? (int) $a['power'] : null,
            'members' => isset($a['member_count']) ? (int) $a['member_count'] : null,
            'median_power' => isset($held['power_median']) ? (int) $held['power_median'] : null,
            'max_power' => isset($held['power_max']) ? (int) $held['power_max'] : null,
            // extra (ignored by normalizeAlliance, available to new UI):
            'max_members' => $a['max_members'] ?? null,
            'leader_uid' => $a['leader_uid'] ?? null,
            'leader_name' => $a['leader_name'] ?? null,
            'alliance_level' => $a['alliance_level'] ?? null,
            'last_seen_at' => $a['last_seen_at'] ?? null,
        ];
    }

    return ['entries' => $entries, 'source' => 'atlas-v2'];
}

/**
 * Old: GET api.wosatlas.com/v1/power/leaderboard?limit=200&kid={kid} → {entries:[{uid,nick_name,power,stove_lv,last_jump_pct,aid,abbr}]}
 * New (no 200-row board exists): union of
 *   - /servers/{kid}/boards/power.rank   (top 20 chiefs)
 *   - /servers/{kid}/boards/furnace.rank (top 20 furnace, for stove_lv)
 *   - /alliances/{aid}/members for the top 20 alliances (top 20 members each, with power/hq_level/tag)
 * = 22 calls, ~400 unique chiefs, sorted by power. Exact only down to the weakest
 * "20th member" of the biggest alliances; see report.
 *
 * Returned rows are already in normalizePlayer() field names.
 */
function atlasV2PowerLeaderboard(string $kid, int $limit = 200, bool $includeMembers = true): array
{
    /** @var array<string, array<string, mixed>> $byUid */
    $byUid = [];

    $power = atlasV2Board($kid, 'power.rank');
    foreach ($power['items'] as $it) {
        $uid = trim((string) ($it['subject_id'] ?? ''));
        if ($uid === '') {
            continue;
        }
        $byUid[$uid] = [
            'id' => $uid,
            'name' => $it['name'] ?? null,
            'power' => isset($it['value']) ? (int) $it['value'] : null,
            'stove_lv' => null,
            'alliance_id' => null,
            'alliance_tag' => $it['alliance_tag'] ?? null,
        ];
    }

    try {
        foreach (atlasV2Board($kid, 'furnace.rank')['items'] as $it) {
            $uid = trim((string) ($it['subject_id'] ?? ''));
            if ($uid !== '' && isset($byUid[$uid]) && isset($it['value'])) {
                $byUid[$uid]['stove_lv'] = (int) $it['value'];
            }
        }
    } catch (Throwable $e) {
        // furnace board is optional
    }

    if ($includeMembers) {
        foreach (atlasV2ServerAlliances($kid) as $a) {
            $aid = trim((string) ($a['alliance_id'] ?? ''));
            if ($aid === '') {
                continue;
            }
            try {
                $members = atlasV2AllianceMembers($aid);
            } catch (Throwable $e) {
                continue;
            }
            foreach ($members as $m) {
                $uid = trim((string) ($m['uid'] ?? ''));
                if ($uid === '') {
                    continue;
                }
                // Skip chiefs who are visiting from another state.
                $home = (string) ($m['current_server_id'] ?? $m['home_server_id'] ?? $kid);
                if ($home !== '' && $home !== $kid) {
                    continue;
                }
                $row = $byUid[$uid] ?? ['id' => $uid];
                $row['name'] = $m['name'] ?? ($row['name'] ?? null);
                $row['power'] = max((int) ($row['power'] ?? 0), (int) ($m['power'] ?? 0)) ?: null;
                $row['stove_lv'] = isset($m['hq_level']) ? (int) $m['hq_level'] : ($row['stove_lv'] ?? null);
                $row['alliance_id'] = $m['alliance_id'] ?? $aid;
                $row['alliance_tag'] = $m['alliance_tag'] ?? ($a['tag'] ?? ($row['alliance_tag'] ?? null));
                $byUid[$uid] = $row;
            }
        }
    }

    $rows = array_values($byUid);
    usort($rows, static fn(array $x, array $y): int => ((int) ($y['power'] ?? 0)) <=> ((int) ($x['power'] ?? 0)));
    $rows = array_slice($rows, 0, $limit);
    foreach ($rows as $i => &$row) {
        $row['rank'] = $i + 1;
        $row['last_jump_pct'] = null; // not available anonymously (needs /changes)
    }
    unset($row);

    return ['entries' => $rows, 'observed_at' => $power['observed_at'], 'source' => 'atlas-v2'];
}

/**
 * Old: GET api.wosatlas.com/v1/alliances/{aid}/members → {abbr,name,leaderName,members:[{uid,nick_name,lv,abbr,x,y,office}]}
 * New: /alliances/{aid} + /alliances/{aid}/members (TOP 20 ONLY).
 * 'partial' => true tells callers to MERGE into the stored roster instead of replacing it.
 */
function atlasV2AllianceMembersPayload(string $aid): ?array
{
    $alliance = atlasV2Alliance($aid);
    if ($alliance === null) {
        return null;
    }
    $members = [];
    foreach (atlasV2AllianceMembers($aid) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $role = is_array($m['role'] ?? null) ? ($m['role']['role'] ?? null) : null;
        $members[] = [
            'uid' => (string) ($m['uid'] ?? ''),
            'nick_name' => $m['name'] ?? null,
            'lv' => $m['hq_level'] ?? null,
            'abbr' => $m['alliance_tag'] ?? ($alliance['tag'] ?? null),
            'x' => $m['x'] ?? null,
            'y' => $m['y'] ?? null,
            'office' => $role,
            'power' => $m['power'] ?? null,
            'fid' => $m['alt_id'] ?? null,
            'kid' => $m['current_server_id'] ?? $m['home_server_id'] ?? null,
        ];
    }

    $total = (int) ($alliance['member_count'] ?? count($members));

    return [
        'aid' => $aid,
        'abbr' => $alliance['tag'] ?? null,
        'name' => $alliance['name'] ?? null,
        'leaderName' => $alliance['leader_name'] ?? null,
        'leaderUid' => $alliance['leader_uid'] ?? null,
        'memberCount' => $total,
        'members' => $members,
        'partial' => count($members) < $total,
        'source' => 'atlas-v2',
    ];
}

/**
 * Old: /v1/players/search?playerName={uid|fid} (+ /v1/players/{uid}) → normalizeAtlasPlayerMatch() shape.
 * New: /players/{uid}. If 404 and the id is a known FID (alt_id) in the local index, retry with the uid.
 *
 * @return array<string, mixed>|null null = not found anywhere
 */
function atlasV2PlayerNormalized(string $id): ?array
{
    $id = preg_replace('/\D+/', '', $id) ?? '';
    if ($id === '') {
        return null;
    }

    $detail = atlasV2Player($id);
    $resolvedVia = 'uid';
    if ($detail === null) {
        $uid = atlasV2ResolveAltId($id);
        if ($uid !== null && $uid !== $id) {
            $detail = atlasV2Player($uid);
            $resolvedVia = 'fid-index';
        }
    }
    if ($detail === null) {
        return null;
    }

    $pos = is_array($detail['position'] ?? null) ? $detail['position'] : [];
    $x = $pos['x'] ?? null;
    $y = $pos['y'] ?? null;
    $coordStale = !empty($pos['gone_at']);
    $kid = $detail['current_server_id'] ?? $pos['map_server_id'] ?? $detail['home_server_id'] ?? null;
    $step = is_array($detail['last_power_step'] ?? null) ? $detail['last_power_step'] : [];

    return [
        'id' => (string) ($detail['uid'] ?? $id),
        'fid' => isset($detail['alt_id']) && $detail['alt_id'] !== '' ? (string) $detail['alt_id'] : null,
        'name' => isset($detail['name']) ? trim((string) $detail['name']) : null,
        'power' => isset($detail['power']) ? (int) $detail['power'] : null,
        'stove_lv' => isset($detail['hq_level']) ? (int) $detail['hq_level'] : null,
        // Old last_jump_pct semantics differ; expose v2 step separately (percent, signed).
        'last_jump_pct' => null,
        'last_power_step_pct' => isset($step['pct']) ? (float) $step['pct'] : null,
        'last_power_step_at' => $step['at'] ?? null,
        'kid' => $kid !== null && $kid !== '' ? (string) $kid : null,
        'home_kid' => isset($detail['home_server_id']) ? (string) $detail['home_server_id'] : null,
        'alliance_id' => !empty($detail['alliance_id']) ? (string) $detail['alliance_id'] : null,
        'alliance_tag' => !empty($detail['alliance_tag']) ? (string) $detail['alliance_tag'] : null,
        'alliance_name' => !empty($detail['alliance_name']) ? (string) $detail['alliance_name'] : null,
        'x' => $x !== null ? (int) $x : null,
        'y' => $y !== null ? (int) $y : null,
        'coord_stale' => $coordStale,
        'lv' => isset($detail['hq_level']) ? (int) $detail['hq_level'] : null,
        'last_seen_at' => $detail['last_seen_at'] ?? null,
        'fetched_at' => nowUtc(),
        'viewer_tier' => 'ANONYMOUS-V2',
        'id_outcome' => $resolvedVia,
        'source' => 'atlas-v2',
        'coord_source' => ($x !== null && $y !== null) ? 'atlas' : null,
        'atlas_url' => atlasV2WebPlayerUrl((string) ($detail['uid'] ?? $id)),
    ];
}

/** True when config still points at the retired v1 API (or opts in explicitly). */
function atlasV2Enabled(array $config): bool
{
    if (($config['data_source'] ?? '') === 'atlas_v2') {
        return true;
    }
    $api = (string) ($config['api_url'] ?? '');

    return $api === '' || str_contains($api, 'api.wosatlas.com');
}
