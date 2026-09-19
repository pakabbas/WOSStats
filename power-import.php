<?php

declare(strict_types=1);

/**
 * Import roster powers JSON (from Atlas console export while signed in).
 *
 * POST JSON:
 *   { "aid": "4627000048", "token": "...", "players": { "2091…": { "power": 123, ... } } }
 *
 * Also serves a console script for signed-in Atlas users:
 *   /power-import.php?aid=4627000048&token=UPDATE_TOKEN&script=1
 */

require_once __DIR__ . '/functions.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === 'https://wosatlas.com') {
    header('Access-Control-Allow-Origin: https://wosatlas.com');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    ensureDataFilesExist();
    $config = loadConfig();
    $expectedToken = (string) ($config['update_token'] ?? '');

    if (isset($_GET['script'])) {
        $token = (string) ($_GET['token'] ?? '');
        $aid = (string) ($_GET['aid'] ?? '4627000048');
        if ($expectedToken === '' || $expectedToken === 'CHANGE_ME' || !hash_equals($expectedToken, $token)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 Forbidden\n";
            exit;
        }
        if (!isTrackedRosterAlliance($aid)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Invalid alliance\n";
            exit;
        }

        $roster = loadAllianceRoster($aid);
        $uids = [];
        foreach ($roster['members'] ?? [] as $member) {
            if (!empty($member['id'])) {
                $uids[] = (string) $member['id'];
            }
        }

        header('Content-Type: text/javascript; charset=utf-8');
        header('Content-Disposition: inline; filename="atlas-power-export.js"');

        $uidsJson = json_encode($uids, JSON_UNESCAPED_UNICODE);
        $endpoint = json_encode(
            'https://state4627.btkdeals.com/power-import.php',
            JSON_UNESCAPED_UNICODE
        );
        $aidJson = json_encode($aid, JSON_UNESCAPED_UNICODE);
        $tokenJson = json_encode($token, JSON_UNESCAPED_UNICODE);

        echo <<<JS
/* Paste this into the browser console on https://wosatlas.com while signed in */
(async () => {
  const uids = {$uidsJson};
  const endpoint = {$endpoint};
  const aid = {$aidJson};
  const token = {$tokenJson};
  const players = {};
  console.log('Fetching power for', uids.length, 'players…');
  for (let i = 0; i < uids.length; i++) {
    const uid = uids[i];
    try {
      const res = await fetch('https://api.wosatlas.com/v1/players/search?playerName=' + encodeURIComponent(uid), {
        credentials: 'include',
        headers: { 'Accept': 'application/json' },
      });
      const data = await res.json();
      const p = (data.players || []).find(x => String(x.uid) === String(uid)) || (data.players || [])[0] || {};
      players[uid] = {
        id: String(uid),
        name: p.nick_name || p.nickName || null,
        power: p.current_power ?? p.currentPower ?? p.power ?? null,
        stove_lv: p.stove_lv ?? p.furnace_lv ?? p.stoveLv ?? p.furnaceLv ?? null,
        last_jump_pct: p.last_jump_pct ?? p.lastJumpPct ?? null,
        fetched_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
        source: 'atlas-console',
        viewer_tier: data.viewerTier || null,
      };
    } catch (err) {
      console.warn('fail', uid, err);
      players[uid] = { id: String(uid), power: null, source: 'error' };
    }
    if ((i + 1) % 10 === 0) console.log(i + 1, '/', uids.length);
    await new Promise(r => setTimeout(r, 180));
  }
  const withPower = Object.values(players).filter(p => p.power != null).length;
  console.log('Done. With power:', withPower, '/', uids.length, '— uploading…');
  const upload = await fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, aid, players }),
  });
  const result = await upload.json();
  console.log(result);
  alert(result.ok ? ('Saved ' + result.with_power + '/' + result.total + ' powers') : ('Failed: ' + (result.error || upload.status)));
})();
JS;
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = [];
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $token = (string) ($payload['token'] ?? $_POST['token'] ?? $_GET['token'] ?? '');
    $aid = (string) ($payload['aid'] ?? $_POST['aid'] ?? $_GET['aid'] ?? '');
    $players = $payload['players'] ?? [];

    header('Content-Type: application/json; charset=utf-8');

    if ($expectedToken === '' || $expectedToken === 'CHANGE_ME' || !hash_equals($expectedToken, $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
    if ($aid === '' || !isTrackedRosterAlliance($aid)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid alliance']);
        exit;
    }
    if (!is_array($players) || $players === []) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No players payload']);
        exit;
    }

    $normalized = [];
    $withPower = 0;
    foreach ($players as $uid => $player) {
        if (!is_array($player)) {
            continue;
        }
        $uid = (string) ($player['id'] ?? $uid);
        $uid = preg_replace('/\D+/', '', $uid) ?? '';
        if ($uid === '') {
            continue;
        }
        $power = $player['power'] ?? null;
        $entry = [
            'id' => $uid,
            'name' => isset($player['name']) ? (string) $player['name'] : null,
            'power' => $power !== null && $power !== '' ? (int) $power : null,
            'stove_lv' => isset($player['stove_lv']) && $player['stove_lv'] !== null && $player['stove_lv'] !== ''
                ? (int) $player['stove_lv'] : null,
            'last_jump_pct' => isset($player['last_jump_pct']) && $player['last_jump_pct'] !== null && $player['last_jump_pct'] !== ''
                ? (float) $player['last_jump_pct'] : null,
            'fetched_at' => nowUtc(),
            'source' => (string) ($player['source'] ?? 'import'),
        ];
        if ($entry['power'] !== null) {
            $withPower++;
        }
        $normalized[$uid] = $entry;
    }

    saveRosterPowers($aid, $normalized, 'import');

    echo json_encode([
        'ok' => true,
        'aid' => $aid,
        'total' => count($normalized),
        'with_power' => $withPower,
        'file' => 'data/roster-powers/' . $aid . '.json',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
