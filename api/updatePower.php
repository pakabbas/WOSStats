<?php

declare(strict_types=1);

/**
 * Merge top-200 player powers from OCR/bot (name-based).
 *
 * SAFETY:
 * - Never replaces / wipes the board
 * - Never shrinks player count
 * - Match by NAME; power rises only when new > current
 * - New names are appended only
 *
 * GET  ?token=... → status
 * POST { token, players: [{ name, power }, ...] } → merge
 */

require_once dirname(__DIR__) . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Update-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * @return array<string, mixed>
 */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function requestUpdateToken(array $payload): string
{
    $headerToken = '';
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m) === 1) {
        $headerToken = $m[1];
    }
    $xToken = (string) ($_SERVER['HTTP_X_UPDATE_TOKEN'] ?? '');

    return (string) (
        $payload['token']
        ?? $_POST['token']
        ?? $_GET['token']
        ?? ($headerToken !== '' ? $headerToken : null)
        ?? ($xToken !== '' ? $xToken : null)
        ?? ''
    );
}

try {
    ensureDataFilesExist();
    $config = loadConfig();
    $expectedToken = (string) ($config['update_token'] ?? '');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $payload = $method === 'POST' ? readJsonBody() : [];
    $token = requestUpdateToken($payload);

    if ($expectedToken === '' || $expectedToken === 'CHANGE_ME') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server update_token is not configured.']);
        exit;
    }

    if (!hash_equals($expectedToken, $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    if ($method === 'GET') {
        $meta = ensureVideoMeta();
        echo json_encode([
            'ok' => true,
            'action' => 'status',
            'mode' => 'merge',
            'never_deletes' => true,
            'match_by' => 'name',
            'max_players' => TOP_PLAYERS_LIMIT,
            'video_url' => currentVideoAbsoluteUrl(),
            'video_path' => currentVideoPublicPath(),
            'has_video' => currentVideoAbsoluteUrl() !== null,
            'ocr_key' => $meta['ocr_key'] ?? null,
            'uploaded_at' => $meta['uploaded_at'] ?? null,
            'players_count' => count(loadJson(PLAYERS_FILE, [])),
            'upload_page' => rtrim(sitePublicBaseUrl(), '/') . '/Upload.php',
            'todays_video_page' => rtrim(sitePublicBaseUrl(), '/') . '/TodaysVideo.php',
            'last_upload_api' => rtrim(sitePublicBaseUrl(), '/') . '/LastUpload.php',
            'post_url' => rtrim(sitePublicBaseUrl(), '/') . '/api/updatePower.php',
            'required_fields' => ['name', 'power'],
            'optional_fields' => ['rank', 'stove_lv', 'last_jump_pct'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Use GET (status/video) or POST (update players).']);
        exit;
    }

    $existing = loadJson(PLAYERS_FILE, []);
    if (!is_array($existing)) {
        $existing = [];
    }
    $existing = array_values(array_filter($existing, 'is_array'));
    $existingCount = count($existing);

    $merge = mergePlayersPowerByName($existing, $payload['players'] ?? null);
    $players = $merge['players'];
    $stats = $merge['stats'];
    $changed = $merge['changed'];

    if ($players === []) {
        throw new RuntimeException('Merge produced an empty players board.');
    }

    if (count($players) < $existingCount) {
        throw new RuntimeException(
            'Refusing to save: would delete players ('
            . $existingCount . ' → ' . count($players) . '). Merge-only API.'
        );
    }

    validatePlayers($players);

    $timestamp = nowUtc();
    saveJson(PLAYERS_FILE, $players);

    $saved = loadJson(PLAYERS_FILE, []);
    $savedCount = is_array($saved) ? count($saved) : 0;
    if ($savedCount < $existingCount) {
        saveJson(PLAYERS_FILE, $existing);
        throw new RuntimeException(
            'Save verification failed (board shrank). Restored previous players.json.'
        );
    }

    $historyWritten = false;
    if ($changed) {
        $history = loadJson(PLAYERS_HISTORY_FILE, []);
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = [
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
            'source' => 'api/updatePower',
            'match_by' => 'name',
            'mode' => 'merge',
        ];
        saveJson(PLAYERS_HISTORY_FILE, pruneHistory($history));
        $historyWritten = true;
    }

    echo json_encode([
        'ok' => true,
        'action' => 'merge',
        'mode' => 'merge',
        'never_deletes' => true,
        'match_by' => 'name',
        'received' => $stats['received'],
        'matched' => $stats['matched'],
        'added' => $stats['added'],
        'power_increased' => $stats['power_increased'],
        'skipped_not_higher' => $stats['skipped_not_higher'],
        'skipped_no_name' => $stats['skipped_no_name'],
        'skipped_no_power' => $stats['skipped_no_power'],
        'matched_existing_id' => $stats['matched_existing_id'],
        'players_before' => $existingCount,
        'players_after' => count($players),
        'history_written' => $historyWritten,
        'max_players' => TOP_PLAYERS_LIMIT,
        'file' => 'data/players.json',
        'updated_at' => $timestamp,
        'video_url' => currentVideoAbsoluteUrl(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
