<?php

declare(strict_types=1);

/**
 * Shared Atlas player lookup helpers (used by player-enrich + batch enrich + search page).
 */

require_once __DIR__ . '/functions.php';

const ATLAS_LOGIN_URL = 'https://api.wosatlas.com/v1/auth/login';
const ATLAS_COOKIE_FILE = DATA_DIR . '/atlas-cookies.txt';

/**
 * @return array<string, mixed>|null
 */
function lookupPlayerByUid(string $uid, array $config): ?array
{
    ensureAtlasSession($config);

    $url = 'https://api.wosatlas.com/v1/players/search?playerName=' . rawurlencode($uid);
    $payload = httpGetJsonAbsolute($url, $config);

    // Anonymous response with credentials configured → refresh login once and retry.
    $tier = strtoupper((string) ($payload['viewerTier'] ?? ''));
    if ($tier === 'ANONYMOUS' && atlasCredentialsConfigured($config)) {
        atlasLogin($config, true);
        $payload = httpGetJsonAbsolute($url, $config);
    }

    $players = $payload['players'] ?? [];
    if (!is_array($players) || $players === []) {
        return [
            'id' => $uid,
            'fid' => null,
            'name' => null,
            'power' => null,
            'stove_lv' => null,
            'last_jump_pct' => null,
            'kid' => null,
            'alliance_id' => null,
            'alliance_tag' => null,
            'x' => null,
            'y' => null,
            'lv' => null,
            'fetched_at' => nowUtc(),
            'viewer_tier' => $payload['viewerTier'] ?? null,
            'id_outcome' => $payload['idOutcome'] ?? null,
            'source' => 'atlas',
        ];
    }

    $match = null;
    foreach ($players as $player) {
        if (!is_array($player)) {
            continue;
        }
        if ((string) ($player['uid'] ?? '') === $uid || (string) ($player['fid'] ?? '') === $uid) {
            $match = $player;
            break;
        }
    }
    if ($match === null && is_array($players[0] ?? null)) {
        $match = $players[0];
    }
    if ($match === null) {
        return null;
    }

    return normalizeAtlasPlayerMatch($match, $uid, $payload);
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
    if (!atlasCredentialsConfigured($config)) {
        return;
    }

    $cookieFile = atlasCookiePath();
    if (!is_file($cookieFile) || filesize($cookieFile) < 32) {
        atlasLogin($config, true);
        return;
    }

    // Refresh every 20 days (cookies are issued for ~30 days).
    if (filemtime($cookieFile) < (time() - (20 * 24 * 3600))) {
        atlasLogin($config, true);
    }
}

/**
 * Login to WOS Atlas and store session cookies for API calls.
 */
function atlasLogin(array $config, bool $force = false): void
{
    if (!atlasCredentialsConfigured($config)) {
        throw new RuntimeException('Atlas credentials not configured (atlas_email / atlas_password).');
    }

    $cookieFile = atlasCookiePath();
    $dir = dirname($cookieFile);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create data directory for Atlas cookies.');
    }

    if ($force && is_file($cookieFile)) {
        @unlink($cookieFile);
    }

    $payload = json_encode([
        'email' => trim((string) $config['atlas_email']),
        'password' => (string) $config['atlas_password'],
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode Atlas login payload.');
    }

    $ch = curl_init(ATLAS_LOGIN_URL);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize Atlas login client.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Origin: https://wosatlas.com',
            'Referer: https://wosatlas.com/',
        ],
        CURLOPT_USERAGENT => 'WhiteoutStateTracker/1.0',
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Atlas login failed: ' . ($curlError ?: 'unknown error'));
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Atlas login HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 200));
    }

    $rawHeaders = substr((string) $response, 0, $headerSize);
    $cookies = atlasParseSetCookieHeaders($rawHeaders);
    if ($cookies === []) {
        throw new RuntimeException('Atlas login did not return session cookies.');
    }

    atlasWriteCookieFile($cookieFile, $cookies);
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
    $apiKey = trim((string) ($config['api_key'] ?? ''));
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize HTTP client.');
    }

    $headers = [
        'Accept: application/json',
        'Origin: https://wosatlas.com',
        'Referer: https://wosatlas.com/',
    ];

    $cookieHeader = atlasCookieHeaderFromFile();
    if ($cookieHeader !== '') {
        $headers[] = 'Cookie: ' . $cookieHeader;
    } elseif ($apiKey !== '') {
        if (str_contains($apiKey, '=')) {
            $headers[] = 'Cookie: ' . $apiKey;
        } else {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            $headers[] = 'X-API-Key: ' . $apiKey;
        }
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'WhiteoutStateTracker/1.0',
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Player lookup failed: ' . ($curlError ?: 'unknown error'));
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Player lookup HTTP ' . $httpCode);
    }

    $payload = json_decode($response, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Player lookup returned invalid JSON.');
    }

    return $payload;
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
