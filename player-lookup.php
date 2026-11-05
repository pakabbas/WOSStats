<?php

declare(strict_types=1);

/**
 * Shared Atlas player lookup helpers (used by player-enrich + batch enrich + search page).
 *
 * Migrated to the public WOS Atlas v2 API (atlas_v2.php). The v1 developer API
 * (api.wosatlas.com/v1, login + cookies + api_key) is retired: every call returns
 * 401 INVALID_API_KEY. No login, cookies or Turnstile sessions are used any more.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/atlas_v2.php';

/** @deprecated v1 login is retired (401). Kept only so old references do not fatal. */
const ATLAS_LOGIN_URL = 'https://api.wosatlas.com/v1/auth/login';
const ATLAS_COOKIE_FILE = DATA_DIR . '/atlas-cookies.txt';

/**
 * Search Atlas (and local top board) by player name.
 *
 * @return list<array<string, mixed>>
 */
function searchPlayersByName(string $name, array $config, ?string $preferKid = null, int $limit = 40): array
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) < 2) {
        return [];
    }

    // v2 name search (/search?q=) needs a Turnstile browser session → not callable server-side.
    // Search the local v2 player index (every player/member payload we have fetched) instead,
    // then local top board + tracked rosters below. UI links out to WOS Atlas search.
    /** @var array<string, array<string, mixed>> $byUid */
    $byUid = [];
    foreach (atlasV2SearchIndexByName($name, max($limit, 40)) as $row) {
        $id = (string) ($row['uid'] ?? '');
        if ($id === '') {
            continue;
        }
        $byUid[$id] = [
            'id' => $id,
            'fid' => $row['alt_id'] ?? null,
            'name' => $row['name'] ?? null,
            'power' => isset($row['power']) ? (int) $row['power'] : null,
            'stove_lv' => isset($row['hq_level']) ? (int) $row['hq_level'] : null,
            'last_jump_pct' => null,
            'kid' => !empty($row['kid']) ? (string) $row['kid'] : null,
            'alliance_id' => $row['alliance_id'] ?? null,
            'alliance_tag' => $row['alliance_tag'] ?? null,
            'x' => null,
            'y' => null,
            'lv' => isset($row['hq_level']) ? (int) $row['hq_level'] : null,
            'rank' => null,
            'fetched_at' => $row['seen_at'] ?? nowUtc(),
            'match_source' => 'atlas-index',
            'source' => 'atlas-v2',
        ];
    }

    // Tracked alliance rosters (stored locally).
    foreach (listStoredRosterAllianceIds() as $rosterAid) {
        $roster = loadAllianceRoster((string) $rosterAid);
        foreach ($roster['members'] ?? [] as $member) {
            if (!is_array($member)) {
                continue;
            }
            $mid = trim((string) ($member['id'] ?? ''));
            $mname = trim((string) ($member['name'] ?? ''));
            if ($mid === '' || $mname === '' || isset($byUid[$mid]) || mb_stripos($mname, $name) === false) {
                continue;
            }
            $byUid[$mid] = [
                'id' => $mid,
                'fid' => null,
                'name' => $mname,
                'power' => null,
                'stove_lv' => isset($member['lv']) ? (int) $member['lv'] : null,
                'last_jump_pct' => null,
                'kid' => $preferKid !== null && $preferKid !== '' ? $preferKid : null,
                'alliance_id' => (string) $rosterAid,
                'alliance_tag' => $member['abbr'] ?? ($roster['tag'] ?? null),
                'x' => isset($member['x']) ? (int) $member['x'] : null,
                'y' => isset($member['y']) ? (int) $member['y'] : null,
                'lv' => isset($member['lv']) ? (int) $member['lv'] : null,
                'rank' => null,
                'fetched_at' => $roster['updated_at'] ?? nowUtc(),
                'match_source' => 'roster',
                'source' => 'local',
            ];
        }
    }

    // Also include local top-board name matches (fast, state-local).
    $local = loadJson(PLAYERS_FILE, []);
    if (is_array($local)) {
        $needle = mb_strtolower($name);
        foreach ($local as $row) {
            if (!is_array($row)) {
                continue;
            }
            $localName = trim((string) ($row['name'] ?? ''));
            $localId = trim((string) ($row['id'] ?? ''));
            if ($localName === '' || $localId === '') {
                continue;
            }
            if (mb_stripos($localName, $name) === false && mb_strtolower($localName) !== $needle) {
                continue;
            }
            if (isset($byUid[$localId])) {
                $merged = $byUid[$localId];
                if (($merged['alliance_tag'] ?? null) === null && !empty($row['alliance_tag'])) {
                    $merged['alliance_tag'] = (string) $row['alliance_tag'];
                }
                if (($merged['alliance_id'] ?? null) === null && !empty($row['alliance_id'])) {
                    $merged['alliance_id'] = (string) $row['alliance_id'];
                }
                if (($merged['rank'] ?? null) === null && isset($row['rank'])) {
                    $merged['rank'] = (int) $row['rank'];
                }
                $merged['match_source'] = 'atlas+local';
                $byUid[$localId] = $merged;
                continue;
            }

            $byUid[$localId] = [
                'id' => $localId,
                'fid' => null,
                'name' => $localName,
                'power' => isset($row['power']) ? (int) $row['power'] : null,
                'stove_lv' => isset($row['stove_lv']) ? (int) $row['stove_lv'] : null,
                'last_jump_pct' => isset($row['last_jump_pct']) ? (float) $row['last_jump_pct'] : null,
                'kid' => $preferKid !== null && $preferKid !== '' ? $preferKid : null,
                'alliance_id' => !empty($row['alliance_id']) ? (string) $row['alliance_id'] : null,
                'alliance_tag' => !empty($row['alliance_tag']) ? (string) $row['alliance_tag'] : null,
                'x' => null,
                'y' => null,
                'lv' => isset($row['stove_lv']) ? (int) $row['stove_lv'] : null,
                'rank' => isset($row['rank']) ? (int) $row['rank'] : null,
                'fetched_at' => nowUtc(),
                'match_source' => 'local',
                'source' => 'local',
            ];
        }
    }

    $preferKid = $preferKid !== null ? trim($preferKid) : '';
    $needle = mb_strtolower($name);
    $list = array_values($byUid);

    usort($list, static function (array $a, array $b) use ($preferKid, $needle): int {
        $aName = mb_strtolower(trim((string) ($a['name'] ?? '')));
        $bName = mb_strtolower(trim((string) ($b['name'] ?? '')));
        $aExact = $aName === $needle ? 0 : 1;
        $bExact = $bName === $needle ? 0 : 1;
        if ($aExact !== $bExact) {
            return $aExact <=> $bExact;
        }

        $aKid = trim((string) ($a['kid'] ?? ''));
        $bKid = trim((string) ($b['kid'] ?? ''));
        if ($preferKid !== '') {
            $aPref = $aKid === $preferKid ? 0 : 1;
            $bPref = $bKid === $preferKid ? 0 : 1;
            if ($aPref !== $bPref) {
                return $aPref <=> $bPref;
            }
        }

        $aStarts = str_starts_with($aName, $needle) ? 0 : 1;
        $bStarts = str_starts_with($bName, $needle) ? 0 : 1;
        if ($aStarts !== $bStarts) {
            return $aStarts <=> $bStarts;
        }

        $pa = isset($a['power']) ? (int) $a['power'] : -1;
        $pb = isset($b['power']) ? (int) $b['power'] : -1;
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }

        return $aName <=> $bName;
    });

    if ($limit > 0 && count($list) > $limit) {
        $list = array_slice($list, 0, $limit);
    }

    return $list;
}

/**
 * @return array<string, mixed>|null
 */
function lookupPlayerByUid(string $uid, array $config): ?array
{
    // v2: GET /players/{uid}. Accepts an in-game FID (Atlas alt_id) when the local index
    // has seen it (members lists / earlier lookups); otherwise FIDs return null (404).
    return atlasV2PlayerNormalized($uid);
}

/**
 * Merge /v1/players/{uid} detail (coords, alliance, etc.) onto a search result.
 *
 * @param array<string, mixed> $player
 * @return array<string, mixed>
 */
function enrichAtlasPlayerFromDetail(array $player, array $config): array
{
    // v2 /players/{uid} already includes coords + alliance; nothing extra to merge.
    if (($player['source'] ?? '') === 'atlas-v2') {
        return $player;
    }
    $uid = trim((string) ($player['id'] ?? ''));
    if ($uid === '' || preg_match('/^\d+$/', $uid) !== 1) {
        return $player;
    }
    try {
        $detail = atlasV2PlayerNormalized($uid);
    } catch (Throwable $e) {
        return $player;
    }
    if ($detail === null) {
        return $player;
    }
    foreach (['x', 'y', 'alliance_id', 'alliance_tag', 'power', 'stove_lv', 'name', 'fid', 'kid', 'lv'] as $key) {
        if (($detail[$key] ?? null) !== null && $detail[$key] !== '') {
            $player[$key] = $detail[$key];
        }
    }
    if (($player['x'] ?? null) !== null && ($player['y'] ?? null) !== null) {
        $player['coord_source'] = 'atlas';
    }

    return $player;
}

/**
 * @param array<string, mixed> $match
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function normalizeAtlasPlayerMatch(array $match, string $fallbackUid, array $payload = []): array
{
    $power = $match['current_power'] ?? $match['currentPower'] ?? $match['power'] ?? null;
    $stove = $match['stove_lv'] ?? $match['furnace_lv'] ?? $match['stoveLv'] ?? $match['furnaceLv'] ?? null;
    $name = $match['nick_name'] ?? $match['nickName'] ?? $match['name'] ?? null;
    $kid = $match['kid'] ?? $match['stateKid'] ?? $payload['stateKid'] ?? null;
    $aid = $match['aid'] ?? $match['alliance_id'] ?? null;
    $abbr = $match['abbr'] ?? $match['alliance_tag'] ?? null;
    $x = $match['x'] ?? null;
    $y = $match['y'] ?? null;
    $lv = $match['lv'] ?? null;
    $uid = $match['uid'] ?? $fallbackUid;
    $fid = $match['fid'] ?? null;

    return [
        'id' => $uid !== null ? (string) $uid : $fallbackUid,
        'fid' => $fid !== null && $fid !== '' ? (string) $fid : null,
        'name' => $name !== null ? trim((string) $name) : null,
        'power' => $power !== null ? (int) $power : null,
        'stove_lv' => $stove !== null ? (int) $stove : null,
        'last_jump_pct' => isset($match['last_jump_pct']) ? (float) $match['last_jump_pct']
            : (isset($match['lastJumpPct']) ? (float) $match['lastJumpPct'] : null),
        'kid' => $kid !== null && $kid !== '' ? (string) $kid : null,
        'alliance_id' => $aid !== null && $aid !== '' ? (string) $aid : null,
        'alliance_tag' => $abbr !== null && $abbr !== '' ? (string) $abbr : null,
        'x' => $x !== null && $x !== '' ? (int) $x : null,
        'y' => $y !== null && $y !== '' ? (int) $y : null,
        'lv' => $lv !== null && $lv !== '' ? (int) $lv : null,
        'fetched_at' => nowUtc(),
        'viewer_tier' => $payload['viewerTier'] ?? null,
        'id_outcome' => $payload['idOutcome'] ?? null,
        'source' => 'atlas',
        'coord_source' => ($x !== null && $y !== null) ? 'atlas' : null,
    ];
}

/**
 * Fill gaps from local top-200 + tracked rosters (power / furnace / coords).
 *
 * @param array<string, mixed> $player
 * @return array<string, mixed>
 */
function enrichPlayerLookupWithLocalData(array $player): array
{
    $uid = (string) ($player['id'] ?? '');
    if ($uid === '') {
        return $player;
    }

    $localPlayers = loadJson(PLAYERS_FILE, []);
    if (is_array($localPlayers)) {
        foreach ($localPlayers as $entry) {
            if (!is_array($entry) || (string) ($entry['id'] ?? '') !== $uid) {
                continue;
            }
            if (($player['name'] ?? null) === null && !empty($entry['name'])) {
                $player['name'] = (string) $entry['name'];
            }
            if (($player['power'] ?? null) === null && isset($entry['power'])) {
                $player['power'] = (int) $entry['power'];
                $player['power_source'] = 'state_top200';
            }
            if (($player['stove_lv'] ?? null) === null && isset($entry['stove_lv'])) {
                $player['stove_lv'] = (int) $entry['stove_lv'];
            }
            if (($player['last_jump_pct'] ?? null) === null && isset($entry['last_jump_pct'])) {
                $player['last_jump_pct'] = (float) $entry['last_jump_pct'];
            }
            if (($player['rank'] ?? null) === null && isset($entry['rank'])) {
                $player['rank'] = (int) $entry['rank'];
            }
            break;
        }
    }

    if (($player['x'] ?? null) === null || ($player['y'] ?? null) === null) {
        foreach (array_keys(TRACKED_ROSTER_ALLIANCES) as $allianceId) {
            $roster = loadAllianceRoster((string) $allianceId);
            foreach ($roster['members'] ?? [] as $member) {
                if (!is_array($member) || (string) ($member['id'] ?? '') !== $uid) {
                    continue;
                }
                if (($player['x'] ?? null) === null && isset($member['x'])) {
                    $player['x'] = (int) $member['x'];
                }
                if (($player['y'] ?? null) === null && isset($member['y'])) {
                    $player['y'] = (int) $member['y'];
                }
                if (($player['alliance_tag'] ?? null) === null) {
                    $player['alliance_tag'] = (string) ($member['abbr'] ?? TRACKED_ROSTER_ALLIANCES[$allianceId] ?? '');
                    if ($player['alliance_tag'] === '') {
                        $player['alliance_tag'] = null;
                    }
                }
                if (($player['alliance_id'] ?? null) === null) {
                    $player['alliance_id'] = (string) $allianceId;
                }
                if (($player['lv'] ?? null) === null && isset($member['lv'])) {
                    $player['lv'] = (int) $member['lv'];
                }
                if (($player['x'] ?? null) !== null && ($player['y'] ?? null) !== null) {
                    $player['coord_source'] = 'roster';
                }
                break 2;
            }
        }
    }

    return $player;
}

function atlasCredentialsConfigured(array $config): bool
{
    $email = trim((string) ($config['atlas_email'] ?? ''));
    $password = (string) ($config['atlas_password'] ?? '');

    return $email !== '' && $password !== '';
}

function atlasCookiePath(): string
{
    return ATLAS_COOKIE_FILE;
}

/**
 * Ensure a logged-in Atlas cookie jar exists (FREE tier for coords/power).
 */
function ensureAtlasSession(array $config): void
{
    // v1 login/cookie sessions are retired. v2 is anonymous; never log in or send cookies.
}

/**
 * Login to WOS Atlas and store session cookies for API calls.
 */
function atlasLogin(array $config, bool $force = false): void
{
    throw new RuntimeException('WOS Atlas v1 login is retired; the v2 API is anonymous.');
}

/**
 * @return array<string, string> name => value
 */
function atlasParseSetCookieHeaders(string $rawHeaders): array
{
    $cookies = [];
    foreach (preg_split("/\r\n|\n|\r/", $rawHeaders) ?: [] as $line) {
        if (!preg_match('/^set-cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) {
            continue;
        }
        $name = trim($m[1]);
        $value = trim($m[2]);
        if ($name !== '' && $value !== '') {
            $cookies[$name] = $value;
        }
    }

    return $cookies;
}

/**
 * @param array<string, string> $cookies
 */
function atlasWriteCookieFile(string $cookieFile, array $cookies): void
{
    $expires = time() + (30 * 24 * 3600);
    $lines = [
        '# Netscape HTTP Cookie File',
        '# Generated by WOSTracker Atlas login',
        '',
    ];

    foreach ($cookies as $name => $value) {
        // HttpOnly marker + domain, flag, path, secure, expires, name, value
        $lines[] = sprintf(
            "#HttpOnly_api.wosatlas.com\tFALSE\t/\tTRUE\t%d\t%s\t%s",
            $expires,
            $name,
            $value
        );
    }

    $tmp = $cookieFile . '.tmp';
    if (file_put_contents($tmp, implode("\n", $lines) . "\n") === false) {
        throw new RuntimeException('Unable to write Atlas cookie file.');
    }
    if (!rename($tmp, $cookieFile)) {
        @unlink($cookieFile);
        if (!rename($tmp, $cookieFile)) {
            throw new RuntimeException('Unable to finalize Atlas cookie file.');
        }
    }
}

/**
 * Absolute URL GET (does not append kid=).
 * Uses Atlas cookie jar when present, plus optional api_key cookie/token.
 *
 * @return array<string, mixed>
 */
function httpGetJsonAbsolute(string $url, array $config): array
{
    // Generic JSON GET. No Atlas cookies / api_key are sent any more.
    $res = atlasV2HttpGet($url);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException('Player lookup HTTP ' . $res['status']);
    }
    if (!is_array($res['body'])) {
        throw new RuntimeException('Player lookup returned invalid JSON.');
    }

    return $res['body'];
}

function atlasCookieHeaderFromFile(): string
{
    $cookieFile = atlasCookiePath();
    if (!is_file($cookieFile)) {
        return '';
    }

    $parts = [];
    foreach (file($cookieFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '# Netscape') || str_starts_with($line, '# Generated')) {
            continue;
        }
        // Accept both "#HttpOnly_domain\t..." and "domain\t..."
        $line = preg_replace('/^#HttpOnly_/', '', $line) ?? $line;
        if (str_starts_with($line, '#')) {
            continue;
        }
        $cols = explode("\t", $line);
        if (count($cols) < 7) {
            continue;
        }
        $name = $cols[5];
        $value = $cols[6];
        if ($name !== '' && $value !== '') {
            $parts[] = $name . '=' . $value;
        }
    }

    return implode('; ', $parts);
}

/**
 * Fetch Atlas /alliances/{aid}/members for each alliance and build a uid → tag map.
 * Best-effort: skips alliances that fail so one timeout does not abort the update.
 *
 * @param list<array<string, mixed>> $alliances
 * @return array{lookup: array<string, array{alliance_id: string, alliance_tag: ?string}>, fetched: int, failed: int}
 */
function fetchAtlasAllianceMemberLookup(array $alliances, array $config): array
{
    // v2 /alliances/{aid}/members → TOP 20 members only per alliance.
    $lookup = [];
    $fetched = 0;
    $failed = 0;

    foreach ($alliances as $alliance) {
        if (!is_array($alliance)) {
            continue;
        }
        $aid = trim((string) ($alliance['id'] ?? $alliance['aid'] ?? ''));
        if ($aid === '') {
            continue;
        }
        $fallbackTag = trim((string) ($alliance['tag'] ?? $alliance['abbr'] ?? ''));
        try {
            $members = atlasV2AllianceMembers($aid);
            $fetched++;
            foreach ($members as $member) {
                $uid = trim((string) ($member['uid'] ?? ''));
                if ($uid === '') {
                    continue;
                }
                $tag = trim((string) ($member['alliance_tag'] ?? $fallbackTag));
                $lookup[$uid] = [
                    'alliance_id' => $aid,
                    'alliance_tag' => $tag !== '' ? $tag : null,
                ];
            }
        } catch (Throwable $e) {
            $failed++;
        }
    }

    return ['lookup' => $lookup, 'fetched' => $fetched, 'failed' => $failed];
}
