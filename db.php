<?php

declare(strict_types=1);

/**
 * MySQL storage for WOSTracker.
 * When config.json has a working "db" block, JSON app data is read/written here instead of files.
 */

function dbConfig(): ?array
{
    static $cached = false;
    static $config = null;

    if ($cached) {
        return $config;
    }
    $cached = true;

    try {
        $all = loadConfig();
    } catch (Throwable $e) {
        return null;
    }

    $db = $all['db'] ?? null;
    if (!is_array($db)) {
        return null;
    }

    $host = trim((string) ($db['host'] ?? ''));
    $name = trim((string) ($db['name'] ?? ''));
    $user = trim((string) ($db['user'] ?? ''));
    if ($host === '' || $name === '' || $user === '') {
        return null;
    }

    $config = [
        'host' => $host,
        'port' => (int) ($db['port'] ?? 3306),
        'name' => $name,
        'user' => $user,
        'pass' => (string) ($db['pass'] ?? ''),
        'charset' => (string) ($db['charset'] ?? 'utf8mb4'),
    ];

    return $config;
}

function dbEnabled(): bool
{
    return dbConfig() !== null;
}

/**
 * True when DB is configured AND a connection succeeds (caches result per request).
 */
function dbAvailable(): bool
{
    static $state = null;
    if ($state !== null) {
        return $state;
    }
    if (!dbEnabled()) {
        $state = false;
        return false;
    }
    try {
        dbEnsureSchema();
        $state = true;
    } catch (Throwable $e) {
        $state = false;
    }
    return $state;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = dbConfig();
    if ($cfg === null) {
        throw new RuntimeException('Database is not configured in config.json (db block).');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['name'],
        $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function dbEnsureSchema(): void
{
    static $done = false;
    if ($done || !dbEnabled()) {
        return;
    }
    $done = true;

    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS alliances (
  id VARCHAR(32) NOT NULL PRIMARY KEY,
  name VARCHAR(191) NULL,
  tag VARCHAR(64) NULL,
  rank_num INT NULL,
  power BIGINT NULL,
  members INT NULL,
  median_power BIGINT NULL,
  max_power BIGINT NULL,
  updated_at VARCHAR(32) NULL,
  INDEX idx_alliances_rank (rank_num),
  INDEX idx_alliances_tag (tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS players (
  row_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id VARCHAR(32) NULL,
  name VARCHAR(191) NOT NULL,
  rank_num INT NULL,
  power BIGINT NULL,
  stove_lv INT NULL,
  last_jump_pct DOUBLE NULL,
  alliance_id VARCHAR(32) NULL,
  alliance_tag VARCHAR(64) NULL,
  updated_at VARCHAR(32) NULL,
  UNIQUE KEY uq_players_name (name),
  INDEX idx_players_id (id),
  INDEX idx_players_rank (rank_num),
  INDEX idx_players_power (power)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alliance_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  captured_at VARCHAR(32) NOT NULL,
  payload LONGTEXT NOT NULL,
  INDEX idx_alliance_history_at (captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  captured_at VARCHAR(32) NOT NULL,
  source VARCHAR(64) NULL,
  match_by VARCHAR(32) NULL,
  mode VARCHAR(32) NULL,
  payload LONGTEXT NOT NULL,
  INDEX idx_player_history_at (captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roster_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payload LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rosters (
  alliance_id VARCHAR(32) NOT NULL PRIMARY KEY,
  tag VARCHAR(64) NULL,
  name VARCHAR(191) NULL,
  leader_name VARCHAR(191) NULL,
  member_count INT NULL,
  source VARCHAR(64) NULL,
  updated_at VARCHAR(32) NULL,
  payload LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roster_powers (
  alliance_id VARCHAR(32) NOT NULL PRIMARY KEY,
  updated_at VARCHAR(32) NULL,
  payload LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nap_banned (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  player_id VARCHAR(32) NULL,
  player_name VARCHAR(191) NULL,
  reason VARCHAR(255) NULL,
  added_at VARCHAR(32) NULL,
  added_by VARCHAR(191) NULL,
  payload LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incidents (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  created_at VARCHAR(32) NULL,
  nick VARCHAR(64) NULL,
  category VARCHAR(64) NULL,
  title VARCHAR(255) NULL,
  details TEXT NULL,
  attachment VARCHAR(255) NULL,
  payload LONGTEXT NULL,
  INDEX idx_incidents_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_lookup_cache (
  cache_key VARCHAR(64) NOT NULL PRIMARY KEY,
  payload LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kv_store (
  store_key VARCHAR(64) NOT NULL PRIMARY KEY,
  payload LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_users (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  email VARCHAR(191) NULL,
  display_name VARCHAR(120) NOT NULL,
  photo_url VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  INDEX idx_app_users_email (email),
  INDEX idx_app_users_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS private_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  thread_id VARCHAR(140) NOT NULL,
  from_user_id VARCHAR(64) NOT NULL,
  to_user_id VARCHAR(64) NOT NULL,
  body TEXT NULL,
  image_url VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_pm_thread (thread_id, id),
  INDEX idx_pm_from (from_user_id),
  INDEX idx_pm_to (to_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $pdo = db();
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }

    // Existing installs: add alliance columns if missing.
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM players')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('alliance_id', $cols, true)) {
            $pdo->exec('ALTER TABLE players ADD COLUMN alliance_id VARCHAR(32) NULL AFTER last_jump_pct');
        }
        if (!in_array('alliance_tag', $cols, true)) {
            $pdo->exec('ALTER TABLE players ADD COLUMN alliance_tag VARCHAR(64) NULL AFTER alliance_id');
        }
    } catch (Throwable $e) {
        // Ignore migration errors on locked/shared hosts; enrichment still works from [TAG] names.
    }
}

/**
 * Map a known JSON file path to a DB store key, or null to use the filesystem.
 */
function dbStoreKeyForPath(string $path): ?string
{
    $normalized = str_replace('\\', '/', $path);
    $base = str_replace('\\', '/', DATA_DIR);

    if ($normalized === str_replace('\\', '/', ALLIANCES_FILE) || str_ends_with($normalized, '/alliances.json')) {
        return 'alliances';
    }
    if ($normalized === str_replace('\\', '/', HISTORY_FILE) || str_ends_with($normalized, '/history.json')) {
        return 'alliance_history';
    }
    if ($normalized === str_replace('\\', '/', PLAYERS_FILE) || str_ends_with($normalized, '/players.json')) {
        return 'players';
    }
    if ($normalized === str_replace('\\', '/', PLAYERS_HISTORY_FILE) || str_ends_with($normalized, '/players-history.json')) {
        return 'player_history';
    }
    if ($normalized === str_replace('\\', '/', ROSTERS_HISTORY_FILE) || str_ends_with($normalized, '/rosters-history.json')) {
        return 'roster_history';
    }
    if ($normalized === str_replace('\\', '/', INCIDENTS_FILE) || str_ends_with($normalized, '/incidents.json')) {
        return 'incidents';
    }
    if ($normalized === str_replace('\\', '/', NAP_BANNED_FILE) || str_ends_with($normalized, '/nap-banned.json')) {
        return 'nap_banned';
    }
    if ($normalized === str_replace('\\', '/', PLAYER_LOOKUP_CACHE_FILE) || str_ends_with($normalized, '/player-lookup-cache.json')) {
        return 'player_lookup_cache';
    }

    if (preg_match('#/rosters/([0-9]+)\.json$#', $normalized, $m) === 1) {
        return 'roster:' . $m[1];
    }
    if (preg_match('#/roster-powers/([0-9]+)\.json$#', $normalized, $m) === 1) {
        return 'roster_powers:' . $m[1];
    }

    // Keep sample / imports / cookies on disk.
    unset($base);
    return null;
}

function dbDecodePayload(?string $json, mixed $default = []): mixed
{
    if ($json === null || $json === '') {
        return $default;
    }
    $data = json_decode($json, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON payload in database: ' . json_last_error_msg());
    }
    return $data ?? $default;
}

function dbEncodePayload(mixed $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON for database storage.');
    }
    return $json;
}

function dbLoad(string $storeKey, mixed $default = []): mixed
{
    dbEnsureSchema();
    $pdo = db();

    switch ($storeKey) {
        case 'alliances':
            $rows = $pdo->query('SELECT id, name, tag, rank_num AS `rank`, power, members, median_power, max_power, updated_at FROM alliances ORDER BY rank_num IS NULL, rank_num ASC')->fetchAll();
            return $rows === [] ? $default : array_map('dbNormalizeAllianceRow', $rows);

        case 'players':
            $rows = $pdo->query('SELECT id, name, rank_num AS `rank`, power, stove_lv, last_jump_pct, alliance_id, alliance_tag, updated_at FROM players ORDER BY power DESC, rank_num IS NULL, rank_num ASC')->fetchAll();
            return $rows === [] ? $default : array_map('dbNormalizePlayerRow', $rows);

        case 'alliance_history':
            $rows = $pdo->query('SELECT captured_at AS timestamp, payload FROM alliance_history ORDER BY captured_at ASC, id ASC')->fetchAll();
            $out = [];
            foreach ($rows as $row) {
                $payload = dbDecodePayload($row['payload'] ?? null, []);
                if (!is_array($payload)) {
                    continue;
                }
                if (!isset($payload['timestamp'])) {
                    $payload['timestamp'] = $row['timestamp'];
                }
                $out[] = $payload;
            }
            return $out === [] ? $default : $out;

        case 'player_history':
            $rows = $pdo->query('SELECT captured_at AS timestamp, source, match_by, mode, payload FROM player_history ORDER BY captured_at ASC, id ASC')->fetchAll();
            $out = [];
            foreach ($rows as $row) {
                $payload = dbDecodePayload($row['payload'] ?? null, []);
                if (!is_array($payload)) {
                    continue;
                }
                if (!isset($payload['timestamp'])) {
                    $payload['timestamp'] = $row['timestamp'];
                }
                if (!empty($row['source']) && !isset($payload['source'])) {
                    $payload['source'] = $row['source'];
                }
                if (!empty($row['match_by']) && !isset($payload['match_by'])) {
                    $payload['match_by'] = $row['match_by'];
                }
                if (!empty($row['mode']) && !isset($payload['mode'])) {
                    $payload['mode'] = $row['mode'];
                }
                $out[] = $payload;
            }
            return $out === [] ? $default : $out;

        case 'roster_history':
            $stmt = $pdo->query('SELECT payload FROM roster_history ORDER BY id DESC LIMIT 1');
            $row = $stmt->fetch();
            return $row ? dbDecodePayload($row['payload'] ?? null, $default) : $default;

        case 'incidents':
            $rows = $pdo->query('SELECT payload FROM incidents ORDER BY created_at DESC, id DESC')->fetchAll();
            $out = [];
            foreach ($rows as $row) {
                $payload = dbDecodePayload($row['payload'] ?? null, null);
                if (is_array($payload)) {
                    $out[] = $payload;
                }
            }
            return $out === [] ? $default : $out;

        case 'nap_banned':
            $rows = $pdo->query('SELECT payload FROM nap_banned ORDER BY added_at DESC, id DESC')->fetchAll();
            $out = [];
            foreach ($rows as $row) {
                $payload = dbDecodePayload($row['payload'] ?? null, null);
                if (is_array($payload)) {
                    $out[] = $payload;
                }
            }
            return $out === [] ? $default : $out;

        case 'player_lookup_cache':
            $stmt = $pdo->prepare('SELECT payload FROM player_lookup_cache WHERE cache_key = ? LIMIT 1');
            $stmt->execute(['root']);
            $row = $stmt->fetch();
            return $row ? dbDecodePayload($row['payload'] ?? null, $default) : $default;
    }

    if (str_starts_with($storeKey, 'roster:')) {
        $aid = substr($storeKey, 7);
        $stmt = $pdo->prepare('SELECT payload FROM rosters WHERE alliance_id = ? LIMIT 1');
        $stmt->execute([$aid]);
        $row = $stmt->fetch();
        return $row ? dbDecodePayload($row['payload'] ?? null, $default) : $default;
    }

    if (str_starts_with($storeKey, 'roster_powers:')) {
        $aid = substr($storeKey, 14);
        $stmt = $pdo->prepare('SELECT payload FROM roster_powers WHERE alliance_id = ? LIMIT 1');
        $stmt->execute([$aid]);
        $row = $stmt->fetch();
        return $row ? dbDecodePayload($row['payload'] ?? null, $default) : $default;
    }

    return $default;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function dbNormalizeAllianceRow(array $row): array
{
    $out = [
        'id' => $row['id'] !== null ? (string) $row['id'] : null,
        'name' => $row['name'] !== null ? (string) $row['name'] : null,
        'tag' => $row['tag'] !== null ? (string) $row['tag'] : null,
        'rank' => $row['rank'] !== null ? (int) $row['rank'] : null,
        'power' => $row['power'] !== null ? (int) $row['power'] : null,
        'members' => $row['members'] !== null ? (int) $row['members'] : null,
        'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
    ];
    if ($row['median_power'] !== null && $row['median_power'] !== '') {
        $out['median_power'] = (int) $row['median_power'];
    }
    if ($row['max_power'] !== null && $row['max_power'] !== '') {
        $out['max_power'] = (int) $row['max_power'];
    }
    return $out;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function dbNormalizePlayerRow(array $row): array
{
    $out = [
        'id' => $row['id'] !== null && $row['id'] !== '' ? (string) $row['id'] : null,
        'name' => (string) ($row['name'] ?? ''),
        'rank' => $row['rank'] !== null ? (int) $row['rank'] : null,
        'power' => $row['power'] !== null ? (int) $row['power'] : null,
        'stove_lv' => $row['stove_lv'] !== null ? (int) $row['stove_lv'] : null,
        'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
    ];
    if ($row['last_jump_pct'] !== null && $row['last_jump_pct'] !== '') {
        $out['last_jump_pct'] = (float) $row['last_jump_pct'];
    }
    if (!empty($row['alliance_id'])) {
        $out['alliance_id'] = (string) $row['alliance_id'];
    }
    if (!empty($row['alliance_tag'])) {
        $out['alliance_tag'] = (string) $row['alliance_tag'];
    }
    return $out;
}

function dbSave(string $storeKey, mixed $data): void
{
    dbEnsureSchema();
    $pdo = db();

    switch ($storeKey) {
        case 'alliances':
            if (!is_array($data)) {
                throw new RuntimeException('alliances payload must be an array.');
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec('DELETE FROM alliances');
                $stmt = $pdo->prepare(
                    'INSERT INTO alliances (id, name, tag, rank_num, power, members, median_power, max_power, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($data as $alliance) {
                    if (!is_array($alliance)) {
                        continue;
                    }
                    $id = (string) ($alliance['id'] ?? $alliance['tag'] ?? $alliance['name'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    $stmt->execute([
                        $id,
                        $alliance['name'] ?? null,
                        $alliance['tag'] ?? null,
                        isset($alliance['rank']) ? (int) $alliance['rank'] : null,
                        isset($alliance['power']) ? (int) $alliance['power'] : null,
                        isset($alliance['members']) ? (int) $alliance['members'] : null,
                        isset($alliance['median_power']) ? (int) $alliance['median_power'] : null,
                        isset($alliance['max_power']) ? (int) $alliance['max_power'] : null,
                        $alliance['updated_at'] ?? null,
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            return;

        case 'players':
            if (!is_array($data)) {
                throw new RuntimeException('players payload must be an array.');
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec('DELETE FROM players');
                $stmt = $pdo->prepare(
                    'INSERT INTO players (id, name, rank_num, power, stove_lv, last_jump_pct, alliance_id, alliance_tag, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $seenNames = [];
                foreach ($data as $player) {
                    if (!is_array($player)) {
                        continue;
                    }
                    $name = trim((string) ($player['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $nameKey = mb_strtolower($name);
                    if (isset($seenNames[$nameKey])) {
                        continue;
                    }
                    $seenNames[$nameKey] = true;

                    $allianceTag = trim((string) ($player['alliance_tag'] ?? ''));
                    if ($allianceTag === '' && preg_match('/^\[([^\]]+)\]/u', $name, $m) === 1) {
                        $allianceTag = $m[1];
                    }
                    $allianceId = trim((string) ($player['alliance_id'] ?? ''));

                    $stmt->execute([
                        isset($player['id']) && $player['id'] !== '' ? (string) $player['id'] : null,
                        $name,
                        isset($player['rank']) ? (int) $player['rank'] : null,
                        isset($player['power']) ? (int) $player['power'] : null,
                        isset($player['stove_lv']) ? (int) $player['stove_lv'] : null,
                        isset($player['last_jump_pct']) ? (float) $player['last_jump_pct'] : null,
                        $allianceId !== '' ? $allianceId : null,
                        $allianceTag !== '' ? $allianceTag : null,
                        $player['updated_at'] ?? null,
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            return;

        case 'alliance_history':
            if (!is_array($data)) {
                throw new RuntimeException('alliance history payload must be an array.');
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec('DELETE FROM alliance_history');
                $stmt = $pdo->prepare('INSERT INTO alliance_history (captured_at, payload) VALUES (?, ?)');
                foreach ($data as $snapshot) {
                    if (!is_array($snapshot)) {
                        continue;
                    }
                    $ts = (string) ($snapshot['timestamp'] ?? nowUtc());
                    $stmt->execute([$ts, dbEncodePayload($snapshot)]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            return;

        case 'player_history':
            if (!is_array($data)) {
                throw new RuntimeException('player history payload must be an array.');
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec('DELETE FROM player_history');
                $stmt = $pdo->prepare(
                    'INSERT INTO player_history (captured_at, source, match_by, mode, payload) VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($data as $snapshot) {
                    if (!is_array($snapshot)) {
                        continue;
                    }
                    $stmt->execute([
                        (string) ($snapshot['timestamp'] ?? nowUtc()),
                        $snapshot['source'] ?? null,
                        $snapshot['match_by'] ?? null,
                        $snapshot['mode'] ?? null,
                        dbEncodePayload($snapshot),
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            return;

        case 'roster_history':
            $pdo->beginTransaction();
            try {
                $pdo->exec('DELETE FROM roster_history');
                $stmt = $pdo->prepare('INSERT INTO roster_history (payload) VALUES (?)');
                $stmt->execute([dbEncodePayload($data)]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            return;

        case 'incidents':
            if (!is_array($data)) {
                throw new RuntimeException('incidents payload must be an array.');
            }

            // Never wipe the incidents table with an empty payload (deploy/migrate hazard).
            if ($data === []) {
                return;
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO incidents (id, created_at, nick, category, title, details, attachment, payload)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       created_at=VALUES(created_at),
                       nick=VALUES(nick),
                       category=VALUES(category),
                       title=VALUES(title),
                       details=VALUES(details),
                       attachment=VALUES(attachment),
                       payload=VALUES(payload)'
                );
                foreach ($data as $incident) {
                    if (!is_array($incident)) {
                        continue;
                    }
                    $id = (string) ($incident['id'] ?? '');
                    if ($id === '') {
                        $id = bin2hex(random_bytes(8));
                        $incident['id'] = $id;
                    }
                    $stmt->execute([
                        $id,
                        $incident['created_at'] ?? null,
                        $incident['nick'] ?? null,
                        $incident['category'] ?? null,
                        $incident['title'] ?? null,
                        $incident['details'] ?? null,
                        $incident['attachment'] ?? null,
                        dbEncodePayload($incident),
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            return;

        case 'nap_banned':
            if (!is_array($data)) {
                throw new RuntimeException('nap_banned payload must be an array.');
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec('DELETE FROM nap_banned');
                $stmt = $pdo->prepare(
                    'INSERT INTO nap_banned (id, player_id, player_name, reason, added_at, added_by, payload)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($data as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $id = (string) ($entry['id'] ?? '');
                    if ($id === '') {
                        $id = bin2hex(random_bytes(8));
                    }
                    $stmt->execute([
                        $id,
                        $entry['player_id'] ?? $entry['uid'] ?? null,
                        $entry['player_name'] ?? $entry['name'] ?? null,
                        $entry['reason'] ?? null,
                        $entry['added_at'] ?? null,
                        $entry['added_by'] ?? null,
                        dbEncodePayload($entry),
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            return;

        case 'player_lookup_cache':
            $stmt = $pdo->prepare(
                'INSERT INTO player_lookup_cache (cache_key, payload) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE payload = VALUES(payload)'
            );
            $stmt->execute(['root', dbEncodePayload($data)]);
            return;
    }

    if (str_starts_with($storeKey, 'roster:')) {
        $aid = substr($storeKey, 7);
        if (!is_array($data)) {
            throw new RuntimeException('roster payload must be an array.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO rosters (alliance_id, tag, name, leader_name, member_count, source, updated_at, payload)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE tag=VALUES(tag), name=VALUES(name), leader_name=VALUES(leader_name),
               member_count=VALUES(member_count), source=VALUES(source), updated_at=VALUES(updated_at), payload=VALUES(payload)'
        );
        $stmt->execute([
            $aid,
            $data['tag'] ?? null,
            $data['name'] ?? null,
            $data['leader_name'] ?? null,
            isset($data['member_count']) ? (int) $data['member_count'] : (is_array($data['members'] ?? null) ? count($data['members']) : null),
            $data['source'] ?? null,
            $data['updated_at'] ?? null,
            dbEncodePayload($data),
        ]);
        return;
    }

    if (str_starts_with($storeKey, 'roster_powers:')) {
        $aid = substr($storeKey, 14);
        $stmt = $pdo->prepare(
            'INSERT INTO roster_powers (alliance_id, updated_at, payload) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at), payload=VALUES(payload)'
        );
        $updatedAt = is_array($data) ? ($data['updated_at'] ?? nowUtc()) : nowUtc();
        $stmt->execute([$aid, $updatedAt, dbEncodePayload($data)]);
        return;
    }

    throw new RuntimeException('Unknown database store key: ' . $storeKey);
}

/**
 * Append/upsert a single incident — never deletes other rows.
 *
 * @param array<string, mixed> $incident
 */
function dbUpsertIncident(array $incident): void
{
    dbEnsureSchema();
    $id = (string) ($incident['id'] ?? '');
    if ($id === '') {
        $id = bin2hex(random_bytes(8));
        $incident['id'] = $id;
    }

    $stmt = db()->prepare(
        'INSERT INTO incidents (id, created_at, nick, category, title, details, attachment, payload)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           created_at=VALUES(created_at),
           nick=VALUES(nick),
           category=VALUES(category),
           title=VALUES(title),
           details=VALUES(details),
           attachment=VALUES(attachment),
           payload=VALUES(payload)'
    );
    $stmt->execute([
        $id,
        $incident['created_at'] ?? null,
        $incident['nick'] ?? null,
        $incident['category'] ?? null,
        $incident['title'] ?? null,
        $incident['details'] ?? null,
        $incident['attachment'] ?? null,
        dbEncodePayload($incident),
    ]);
}
