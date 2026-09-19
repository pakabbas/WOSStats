<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

const DATA_DIR = __DIR__ . '/data';
const CONFIG_FILE = __DIR__ . '/config.json';
const ALLIANCES_FILE = DATA_DIR . '/alliances.json';
const HISTORY_FILE = DATA_DIR . '/history.json';
const PLAYERS_FILE = DATA_DIR . '/players.json';
const PLAYERS_HISTORY_FILE = DATA_DIR . '/players-history.json';
const ROSTERS_DIR = DATA_DIR . '/rosters';
const ROSTER_IMPORTS_DIR = DATA_DIR . '/roster-imports';
const ROSTER_POWERS_DIR = DATA_DIR . '/roster-powers';
const ROSTERS_HISTORY_FILE = DATA_DIR . '/rosters-history.json';
const PLAYER_LOOKUP_CACHE_FILE = DATA_DIR . '/player-lookup-cache.json';
const INCIDENTS_FILE = DATA_DIR . '/incidents.json';
const INCIDENT_UPLOADS_DIR = __DIR__ . '/uploads/incidents';
const INCIDENT_MAX_PER_DAY = 10;
const INCIDENT_MAX_BYTES = 2 * 1024 * 1024; // 2 MB
const VIDEO_UPLOADS_DIR = __DIR__ . '/uploads/video';
const VIDEO_MAX_BYTES = 100 * 1024 * 1024; // 100 MB
const VIDEO_META_FILE = DATA_DIR . '/video-meta.json';
const HISTORY_RETENTION_DAYS = 90;
const TOP_ALLIANCES_LIMIT = 20;
const TOP_PLAYERS_LIMIT = 200;
const NAP_BANNED_FILE = DATA_DIR . '/nap-banned.json';
const NAP_BANNED_PASSWORD = 'STATE4627';

/** Alliance IDs that keep a member roster / progress table. */
const TRACKED_ROSTER_ALLIANCES = [
    '4627000048' => 'AOC',
    '4627000232' => 'WaS',
];

require_once __DIR__ . '/db.php';

function loadConfig(): array
{
    if (!file_exists(CONFIG_FILE)) {
        throw new RuntimeException('config.json not found. Copy config.example.json to config.json and configure it.');
    }

    $json = file_get_contents(CONFIG_FILE);
    if ($json === false) {
        throw new RuntimeException('Unable to read config.json.');
    }

    $config = json_decode($json, true);
    if (!is_array($config)) {
        throw new RuntimeException('config.json contains invalid JSON.');
    }

    return $config;
}

function loadJson(string $path, mixed $default = []): mixed
{
    if (dbAvailable()) {
        $storeKey = dbStoreKeyForPath($path);
        if ($storeKey !== null) {
            try {
                return dbLoad($storeKey, $default);
            } catch (Throwable $e) {
                throw new RuntimeException('DB load failed for ' . basename($path) . ': ' . $e->getMessage(), 0, $e);
            }
        }
    }

    if (!file_exists($path)) {
        return $default;
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return $default;
    }

    $data = json_decode($json, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON in ' . basename($path) . ': ' . json_last_error_msg());
    }

    return $data ?? $default;
}

function saveJson(string $path, mixed $data): void
{
    if (dbAvailable()) {
        $storeKey = dbStoreKeyForPath($path);
        if ($storeKey !== null) {
            try {
                dbSave($storeKey, $data);
                return;
            } catch (Throwable $e) {
                throw new RuntimeException('DB save failed for ' . basename($path) . ': ' . $e->getMessage(), 0, $e);
            }
        }
    }

    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create directory: ' . $dir);
    }

    $tmpPath = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON for ' . basename($path));
    }

    if (file_put_contents($tmpPath, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to write temporary file: ' . basename($tmpPath));
    }

    if (!rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Failed to replace ' . basename($path));
    }
}

function formatPower(?int $power): string
{
    if ($power === null) {
        return 'N/A';
    }

    if ($power >= 1000000000) {
        return number_format($power / 1000000000, 2, '.', '') . 'B';
    }

    if ($power >= 1000000) {
        return number_format($power / 1000000, 2, '.', '') . 'M';
    }

    if ($power >= 1000) {
        return number_format($power / 1000, 2, '.', '') . 'K';
    }

    return (string) $power;
}

function formatPowerChange(?int $change): string
{
    if ($change === null) {
        return 'N/A';
    }

    if ($change === 0) {
        return '0';
    }

    $formatted = formatPower(abs($change));
    return ($change > 0 ? '+' : '-') . $formatted;
}

function formatRankChange(?int $change): string
{
    if ($change === null) {
        return 'N/A';
    }

    if ($change === 0) {
        return '—';
    }

    if ($change > 0) {
        return '↑ ' . $change;
    }

    return '↓ ' . abs($change);
}

function formatPercentChange(?float $pct): string
{
    if ($pct === null) {
        return '—';
    }

    if (abs($pct) < 0.005) {
        return '0%';
    }

    return ($pct > 0 ? '+' : '') . number_format($pct, 2) . '%';
}

/**
 * Average power per member from total alliance power ÷ members (API fields only).
 */
function allianceAveragePower(array $alliance): ?int
{
    $power = isset($alliance['power']) ? (int) $alliance['power'] : null;
    $members = isset($alliance['members']) ? (int) $alliance['members'] : null;
    if ($power === null || $members === null || $members <= 0) {
        return null;
    }

    return (int) round($power / $members);
}

/**
 * Top-heavy ratio = maxPower / medianPower (API fields only).
 */
function allianceTopHeavyRatio(array $alliance): ?float
{
    $max = isset($alliance['max_power']) ? (int) $alliance['max_power'] : null;
    $median = isset($alliance['median_power']) ? (int) $alliance['median_power'] : null;
    if ($max === null || $median === null || $median <= 0) {
        return null;
    }

    return $max / $median;
}

/**
 * Highest median power in the set — baseline for roster depth on the threat board.
 */
function allianceThreatReferenceMedian(array $alliances): ?int
{
    $reference = null;

    foreach ($alliances as $alliance) {
        $median = isset($alliance['median_power']) ? (int) $alliance['median_power'] : null;
        if ($median === null || $median <= 0) {
            continue;
        }

        if ($reference === null || $median > $reference) {
            $reference = $median;
        }
    }

    return $reference;
}

/**
 * Threat metrics from API max/median/total/members.
 *
 * Spike (max÷median) catches a lone megawhale. Mass (median vs state best median)
 * catches many similar top players (e.g. LUX). Combined score = √spike + mass + 0.25×depth.
 *
 * @return array{spike: float, mass: float, depth: float, score: float}|null
 */
function allianceThreatMetrics(array $alliance, ?int $referenceMedian): ?array
{
    $max = isset($alliance['max_power']) ? (int) $alliance['max_power'] : null;
    $median = isset($alliance['median_power']) ? (int) $alliance['median_power'] : null;
    $avg = allianceAveragePower($alliance);

    if (
        $max === null
        || $median === null
        || $avg === null
        || $median <= 0
        || $avg <= 0
        || $referenceMedian === null
        || $referenceMedian <= 0
    ) {
        return null;
    }

    $spike = $max / $median;
    $mass = $median / $referenceMedian;
    $depth = $median / $avg;
    $score = sqrt($spike) + $mass + (0.25 * $depth);

    return [
        'spike' => $spike,
        'mass' => $mass,
        'depth' => $depth,
        'score' => $score,
    ];
}

function allianceThreatRatio(array $alliance, ?int $referenceMedian): ?float
{
    $metrics = allianceThreatMetrics($alliance, $referenceMedian);

    return $metrics['score'] ?? null;
}

function formatTopHeavyRatio(?float $ratio): string
{
    if ($ratio === null) {
        return '—';
    }

    return number_format($ratio, 2) . '×';
}

function formatThreatRatioTooltip(?array $metrics): string
{
    if ($metrics === null) {
        return 'Threat score unavailable';
    }

    return sprintf(
        'Spike %.2f× (max ÷ median) · Depth %.2f (median vs state) · Score %.2f×',
        $metrics['spike'],
        $metrics['mass'],
        $metrics['score']
    );
}

function threatRatioClass(?float $score): string
{
    if ($score === null) {
        return '';
    }

    if ($score >= 3.2) {
        return 'ratio-cell-high';
    }

    if ($score >= 2.5) {
        return 'ratio-cell-mid';
    }

    return 'ratio-cell-low';
}

/**
 * Known member powers for an alliance (player board tags + tracked roster).
 *
 * @param list<array<string, mixed>> $players
 * @return list<array{name: string, power: int, id: ?string}>
 */
function allianceKnownPlayerPowers(array $alliance, array $players): array
{
    $tag = (string) ($alliance['tag'] ?? '');
    $aid = trim((string) ($alliance['id'] ?? ''));
    $byKey = [];

    $remember = static function (array &$byKey, string $name, int $power, ?string $id): void {
        if ($power <= 0) {
            return;
        }
        $key = $id !== null && $id !== ''
            ? 'id:' . $id
            : 'name:' . normalizePlayerNameKey($name);
        if ($key === 'name:') {
            return;
        }
        if (!isset($byKey[$key]) || $power > $byKey[$key]['power']) {
            $byKey[$key] = [
                'name' => $name !== '' ? $name : ($id ?? 'Unknown'),
                'power' => $power,
                'id' => $id !== null && $id !== '' ? $id : null,
            ];
        }
    };

    foreach ($players as $player) {
        if (!is_array($player)) {
            continue;
        }
        $power = isset($player['power']) ? (int) $player['power'] : 0;
        if ($power <= 0) {
            continue;
        }
        $name = trim((string) ($player['name'] ?? ''));
        $id = trim((string) ($player['id'] ?? ''));
        $pTag = (string) ($player['alliance_tag'] ?? '');
        $pAid = trim((string) ($player['alliance_id'] ?? ''));
        $match = ($tag !== '' && $pTag === $tag)
            || ($aid !== '' && $pAid === $aid)
            || ($tag !== '' && preg_match('/^\[([^\]]+)\]/u', $name, $m) === 1 && $m[1] === $tag);
        if ($match) {
            $remember($byKey, $name, $power, $id !== '' ? $id : null);
        }
    }

    if ($aid !== '' && isTrackedRosterAlliance($aid)) {
        try {
            $roster = loadAllianceRoster($aid);
        } catch (Throwable $e) {
            $roster = [];
        }
        $boardById = [];
        foreach ($players as $player) {
            if (!is_array($player)) {
                continue;
            }
            $pid = trim((string) ($player['id'] ?? ''));
            if ($pid !== '' && isset($player['power'])) {
                $boardById[$pid] = (int) $player['power'];
            }
        }
        try {
            $rosterPowers = loadRosterPowers($aid);
        } catch (Throwable $e) {
            $rosterPowers = [];
        }
        $saved = is_array($rosterPowers['players'] ?? null) ? $rosterPowers['players'] : [];

        foreach ($roster['members'] ?? [] as $member) {
            if (!is_array($member)) {
                continue;
            }
            $uid = trim((string) ($member['id'] ?? $member['uid'] ?? ''));
            $name = trim((string) ($member['name'] ?? ''));
            $power = 0;
            if ($uid !== '' && isset($boardById[$uid])) {
                $power = max($power, $boardById[$uid]);
            }
            if ($uid !== '' && isset($saved[$uid])) {
                $row = $saved[$uid];
                $p = is_array($row) ? (int) ($row['power'] ?? 0) : (int) $row;
                $power = max($power, $p);
            }
            foreach (playerNameMatchKeys($name) as $nameKey) {
                foreach ($players as $player) {
                    if (!is_array($player) || !isset($player['power'])) {
                        continue;
                    }
                    if (in_array($nameKey, playerNameMatchKeys($player['name'] ?? ''), true)) {
                        $power = max($power, (int) $player['power']);
                    }
                }
            }
            $remember($byKey, $name, $power, $uid !== '' ? $uid : null);
        }
    }

    $list = array_values($byKey);
    usort($list, static fn(array $a, array $b): int => $b['power'] <=> $a['power']);

    return $list;
}

/**
 * Best-effort name for an alliance's max_power holder from the player board.
 *
 * @param list<array<string, mixed>> $players
 * @return array{name: ?string, id: ?string}
 */
function resolveAllianceTopPlayer(array $alliance, array $players, int $maxPower): array
{
    $tag = (string) ($alliance['tag'] ?? '');
    $aid = trim((string) ($alliance['id'] ?? ''));
    $fallback = ['name' => null, 'id' => null];

    foreach ($players as $player) {
        if (!is_array($player) || (int) ($player['power'] ?? 0) !== $maxPower) {
            continue;
        }
        $name = trim((string) ($player['name'] ?? ''));
        $id = trim((string) ($player['id'] ?? ''));
        $pTag = (string) ($player['alliance_tag'] ?? $player['abbr'] ?? '');
        $pAid = trim((string) ($player['alliance_id'] ?? $player['aid'] ?? ''));
        $nameTag = (preg_match('/^\[([^\]]+)\]/u', $name, $m) === 1) ? $m[1] : '';
        $match = ($tag !== '' && ($pTag === $tag || $nameTag === $tag))
            || ($aid !== '' && $pAid === $aid);
        if ($match) {
            return [
                'name' => $name !== '' ? $name : null,
                'id' => $id !== '' ? $id : null,
            ];
        }
        if ($fallback['name'] === null && $name !== '') {
            $fallback = [
                'name' => $name,
                'id' => $id !== '' ? $id : null,
            ];
        }
    }

    return $fallback;
}

/**
 * Whale = member power >= multiplier × alliance median power (default 3×).
 *
 * @param list<array<string, mixed>> $players
 * @return array{
 *   median: ?int,
 *   threshold: ?int,
 *   whale_count: int,
 *   known_count: int,
 *   whales: list<array{name: string, power: int, id: ?string}>,
 *   multiplier: float
 * }
 */
function allianceWhaleStats(array $alliance, array $players, float $multiplier = 3.0): array
{
    $median = isset($alliance['median_power']) ? (int) $alliance['median_power'] : null;
    $threshold = ($median !== null && $median > 0)
        ? (int) ceil($median * $multiplier)
        : null;
    $known = allianceKnownPlayerPowers($alliance, $players);
    $whales = [];

    if ($threshold !== null) {
        foreach ($known as $row) {
            if ($row['power'] >= $threshold) {
                $whales[] = $row;
            }
        }

        // Ensure top player counts even if not on the tagged board yet.
        $max = isset($alliance['max_power']) ? (int) $alliance['max_power'] : null;
        if ($max !== null && $max >= $threshold) {
            $already = false;
            foreach ($whales as $whale) {
                if ($whale['power'] === $max) {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                $top = resolveAllianceTopPlayer($alliance, $players, $max);
                $whales[] = [
                    'name' => $top['name'] ?? 'Top player',
                    'power' => $max,
                    'id' => $top['id'] ?? null,
                ];
                usort($whales, static fn(array $a, array $b): int => $b['power'] <=> $a['power']);
            }
        }
    }

    return [
        'median' => $median,
        'threshold' => $threshold,
        'whale_count' => count($whales),
        'known_count' => count($known),
        'whales' => $whales,
        'multiplier' => $multiplier,
    ];
}

/**
 * Threat rank among alliances (1 = highest threat score). Null if unscored.
 *
 * @param list<array<string, mixed>> $alliances
 * @return array{rank: ?int, total: int, metrics: ?array, score: ?float}
 */
function allianceThreatStanding(array $alliance, array $alliances): array
{
    $reference = allianceThreatReferenceMedian($alliances);
    $scored = [];

    foreach ($alliances as $row) {
        if (!is_array($row)) {
            continue;
        }
        $metrics = allianceThreatMetrics($row, $reference);
        if ($metrics === null) {
            continue;
        }
        $scored[] = [
            'id' => (string) ($row['id'] ?? allianceKey($row)),
            'score' => $metrics['score'],
            'metrics' => $metrics,
        ];
    }

    usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

    $targetId = (string) ($alliance['id'] ?? allianceKey($alliance));
    $rank = null;
    $metrics = null;
    $score = null;
    foreach ($scored as $index => $row) {
        if ($row['id'] === $targetId) {
            $rank = $index + 1;
            $metrics = $row['metrics'];
            $score = $row['score'];
            break;
        }
    }

    return [
        'rank' => $rank,
        'total' => count($scored),
        'metrics' => $metrics,
        'score' => $score,
    ];
}

function changeClass(?float $change): string
{
    if ($change === null || abs($change) < 0.00001) {
        return '';
    }

    return $change > 0 ? 'positive' : 'negative';
}

function formatDisplayDate(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return 'Never';
    }

    try {
        // Stored timestamps are timezone-less wall clocks written in UTC.
        $dt = new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return $datetime;
    }

    return $dt->format('d M Y H:i') . ' UTC';
}

function formatDisplayDateOnly(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return 'Never';
    }

    try {
        $dt = new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return $datetime;
    }

    return $dt->format('d M Y');
}

function nowUtc(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function findSnapshotAtOrBefore(array $history, int $targetTimestamp): ?array
{
    $best = null;
    $bestTimestamp = null;

    foreach ($history as $snapshot) {
        if (!isset($snapshot['timestamp'])) {
            continue;
        }

        $snapshotTimestamp = strtotime($snapshot['timestamp']);
        if ($snapshotTimestamp === false || $snapshotTimestamp > $targetTimestamp) {
            continue;
        }

        if ($bestTimestamp === null || $snapshotTimestamp > $bestTimestamp) {
            $best = $snapshot;
            $bestTimestamp = $snapshotTimestamp;
        }
    }

    return $best;
}

function findAllianceInSnapshot(array $snapshot, string $allianceKey): ?array
{
    foreach ($snapshot['alliances'] ?? [] as $alliance) {
        $key = allianceKey($alliance);
        if ($key === $allianceKey) {
            return $alliance;
        }
    }

    return null;
}

function allianceKey(array $alliance): string
{
    if (!empty($alliance['id'])) {
        return (string) $alliance['id'];
    }

    if (!empty($alliance['tag'])) {
        return (string) $alliance['tag'];
    }

    return (string) ($alliance['name'] ?? '');
}

function calculatePowerChange(array $history, array $alliance, int $hours): ?int
{
    $currentPower = $alliance['power'] ?? null;
    if ($currentPower === null) {
        return null;
    }

    $targetTimestamp = time() - ($hours * 3600);
    $snapshot = findSnapshotAtOrBefore($history, $targetTimestamp);
    if ($snapshot === null) {
        return null;
    }

    $pastAlliance = findAllianceInSnapshot($snapshot, allianceKey($alliance));
    if ($pastAlliance === null || !isset($pastAlliance['power'])) {
        return null;
    }

    return (int) $currentPower - (int) $pastAlliance['power'];
}

function calculateRankChange(array $history, array $alliance, int $hours): ?int
{
    $currentRank = $alliance['rank'] ?? null;
    if ($currentRank === null) {
        return null;
    }

    $targetTimestamp = time() - ($hours * 3600);
    $snapshot = findSnapshotAtOrBefore($history, $targetTimestamp);
    if ($snapshot === null) {
        return null;
    }

    $pastAlliance = findAllianceInSnapshot($snapshot, allianceKey($alliance));
    if ($pastAlliance === null || !isset($pastAlliance['rank'])) {
        return null;
    }

    return (int) $pastAlliance['rank'] - (int) $currentRank;
}

function getAllianceHistory(array $history, string $allianceKey): array
{
    $points = [];

    foreach ($history as $snapshot) {
        if (!isset($snapshot['timestamp'])) {
            continue;
        }

        $alliance = findAllianceInSnapshot($snapshot, $allianceKey);
        if ($alliance === null || !isset($alliance['power'])) {
            continue;
        }

        $points[] = [
            'timestamp' => $snapshot['timestamp'],
            'power' => (int) $alliance['power'],
            'rank' => $alliance['rank'] ?? null,
        ];
    }

    usort($points, static fn(array $a, array $b): int => strcmp($a['timestamp'], $b['timestamp']));

    return $points;
}

function pruneHistory(array $history): array
{
    $cutoff = strtotime('-' . HISTORY_RETENTION_DAYS . ' days');
    if ($cutoff === false) {
        return $history;
    }

    return array_values(array_filter($history, static function (array $snapshot) use ($cutoff): bool {
        if (!isset($snapshot['timestamp'])) {
            return false;
        }

        $timestamp = strtotime($snapshot['timestamp']);
        return $timestamp !== false && $timestamp >= $cutoff;
    }));
}

function validateAlliances(array $alliances): void
{
    if ($alliances === []) {
        throw new RuntimeException('Data source returned zero alliances.');
    }

    foreach ($alliances as $index => $alliance) {
        if (!is_array($alliance)) {
            throw new RuntimeException('Alliance entry at index ' . $index . ' is not an object.');
        }

        if (empty($alliance['name']) && empty($alliance['tag'])) {
            throw new RuntimeException('Alliance entry at index ' . $index . ' is missing name and tag.');
        }
    }
}

function normalizeAlliance(array $alliance, int $fallbackRank): array
{
    // Native fields, with WOS Atlas aliases: aid / abbr / totalPower.
    $id = $alliance['id'] ?? $alliance['aid'] ?? null;
    $name = isset($alliance['name']) ? trim((string) $alliance['name']) : '';
    $tag = $alliance['tag'] ?? $alliance['abbr'] ?? null;
    $tag = $tag !== null ? trim((string) $tag) : '';
    $power = $alliance['power'] ?? $alliance['totalPower'] ?? null;
    $medianPower = $alliance['median_power'] ?? $alliance['medianPower'] ?? null;
    $maxPower = $alliance['max_power'] ?? $alliance['maxPower'] ?? null;

    $normalized = [
        'id' => $id !== null ? (string) $id : null,
        'name' => $name !== '' ? $name : null,
        'tag' => $tag !== '' ? $tag : null,
        'rank' => isset($alliance['rank']) ? (int) $alliance['rank'] : $fallbackRank,
        'power' => $power !== null ? (int) $power : null,
        'members' => isset($alliance['members']) ? (int) $alliance['members'] : null,
        'updated_at' => nowUtc(),
    ];

    if ($medianPower !== null && $medianPower !== '') {
        $normalized['median_power'] = (int) $medianPower;
    }
    if ($maxPower !== null && $maxPower !== '') {
        $normalized['max_power'] = (int) $maxPower;
    }

    return $normalized;
}

function normalizePlayer(array $player, int $fallbackRank): array
{
    $id = $player['id'] ?? $player['uid'] ?? null;
    $name = $player['name'] ?? $player['nick_name'] ?? $player['nickname'] ?? null;
    $name = $name !== null ? trim((string) $name) : '';
    $power = $player['power'] ?? null;
    $stove = $player['stove_lv'] ?? $player['stove_level'] ?? $player['furnace'] ?? null;

    $aid = trim((string) ($player['alliance_id'] ?? $player['aid'] ?? ''));
    $abbr = trim((string) ($player['alliance_tag'] ?? $player['abbr'] ?? ''));

    return [
        'id' => $id !== null ? (string) $id : null,
        'name' => $name !== '' ? $name : null,
        'rank' => isset($player['rank']) ? (int) $player['rank'] : $fallbackRank,
        'power' => $power !== null ? (int) $power : null,
        'stove_lv' => $stove !== null ? (int) $stove : null,
        'last_jump_pct' => isset($player['last_jump_pct']) ? (float) $player['last_jump_pct'] : null,
        'alliance_id' => $aid !== '' ? $aid : null,
        'alliance_tag' => $abbr !== '' ? $abbr : null,
        'updated_at' => nowUtc(),
    ];
}

function validatePlayers(array $players): void
{
    if ($players === []) {
        throw new RuntimeException('Player data source returned zero players.');
    }

    foreach ($players as $index => $player) {
        if (!is_array($player)) {
            throw new RuntimeException('Player entry at index ' . $index . ' is not an object.');
        }

        if (empty($player['name']) && empty($player['id'])) {
            throw new RuntimeException('Player entry at index ' . $index . ' is missing name and id.');
        }
    }
}

/**
 * Sort players by power (highest first) and assign ranks 1..n.
 *
 * @param list<array<string, mixed>> $players
 * @return list<array<string, mixed>>
 */
function rankPlayersByPower(array $players): array
{
    $players = array_values(array_filter($players, static fn($row): bool => is_array($row)));

    usort($players, static function (array $a, array $b): int {
        $pa = isset($a['power']) ? (int) $a['power'] : -1;
        $pb = isset($b['power']) ? (int) $b['power'] : -1;
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }
        return normalizePlayerNameKey($a['name'] ?? '') <=> normalizePlayerNameKey($b['name'] ?? '');
    });

    $rank = 1;
    foreach ($players as &$player) {
        $player['rank'] = $rank;
        $rank++;
    }
    unset($player);

    return $players;
}

function limitPlayersToTop(array $players, int $limit = TOP_PLAYERS_LIMIT): array
{
    $ranked = rankPlayersByPower($players);
    if (count($ranked) <= $limit) {
        return $ranked;
    }

    return rankPlayersByPower(array_slice($ranked, 0, $limit));
}

/**
 * Raise alliance max_power when the player board / roster knows a stronger member.
 *
 * @param list<array<string, mixed>> $alliances
 * @param list<array<string, mixed>> $players
 * @return list<array<string, mixed>>
 */
function enrichAlliancesMaxPowerFromPlayers(array $alliances, array $players): array
{
    if ($alliances === [] || $players === []) {
        return $alliances;
    }

    $maxByTag = [];
    $maxByAllianceId = [];
    $powerById = [];
    $powerByName = [];

    foreach ($players as $player) {
        if (!is_array($player)) {
            continue;
        }
        $power = isset($player['power']) ? (int) $player['power'] : 0;
        if ($power <= 0) {
            continue;
        }

        $id = trim((string) ($player['id'] ?? ''));
        if ($id !== '') {
            $powerById[$id] = max($powerById[$id] ?? 0, $power);
        }
        foreach (playerNameMatchKeys($player['name'] ?? '') as $key) {
            $powerByName[$key] = max($powerByName[$key] ?? 0, $power);
        }

        $name = trim((string) ($player['name'] ?? ''));
        if (preg_match('/^\[([^\]]+)\]/u', $name, $m) === 1) {
            $tag = $m[1];
            $maxByTag[$tag] = max($maxByTag[$tag] ?? 0, $power);
        }

        $allianceTag = trim((string) ($player['alliance_tag'] ?? $player['abbr'] ?? ''));
        if ($allianceTag !== '') {
            $maxByTag[$allianceTag] = max($maxByTag[$allianceTag] ?? 0, $power);
        }
        $allianceId = trim((string) ($player['alliance_id'] ?? $player['aid'] ?? ''));
        if ($allianceId !== '') {
            $maxByAllianceId[$allianceId] = max($maxByAllianceId[$allianceId] ?? 0, $power);
        }
    }

    foreach ($alliances as &$alliance) {
        if (!is_array($alliance)) {
            continue;
        }
        $max = isset($alliance['max_power']) ? (int) $alliance['max_power'] : 0;
        $tag = (string) ($alliance['tag'] ?? '');
        $aid = trim((string) ($alliance['id'] ?? ''));

        if ($tag !== '' && isset($maxByTag[$tag])) {
            $max = max($max, $maxByTag[$tag]);
        }
        if ($aid !== '' && isset($maxByAllianceId[$aid])) {
            $max = max($max, $maxByAllianceId[$aid]);
        }

        if ($aid !== '' && isTrackedRosterAlliance($aid)) {
            try {
                $roster = loadAllianceRoster($aid);
            } catch (Throwable $e) {
                $roster = [];
            }
            foreach ($roster['members'] ?? [] as $member) {
                if (!is_array($member)) {
                    continue;
                }
                $uid = trim((string) ($member['id'] ?? $member['uid'] ?? ''));
                if ($uid !== '' && isset($powerById[$uid])) {
                    $max = max($max, $powerById[$uid]);
                }
                foreach (playerNameMatchKeys($member['name'] ?? '') as $key) {
                    if (isset($powerByName[$key])) {
                        $max = max($max, $powerByName[$key]);
                    }
                }
            }

            try {
                $rosterPowers = loadRosterPowers($aid);
            } catch (Throwable $e) {
                $rosterPowers = [];
            }
            foreach ($rosterPowers['players'] ?? [] as $uid => $row) {
                $p = is_array($row) ? (int) ($row['power'] ?? 0) : (int) $row;
                if ($p > $max) {
                    $max = $p;
                }
                $uid = trim((string) $uid);
                if ($uid !== '' && isset($powerById[$uid])) {
                    $max = max($max, $powerById[$uid]);
                }
            }
        }

        if ($max > 0) {
            $alliance['max_power'] = $max;
        }
    }
    unset($alliance);

    return $alliances;
}

/**
 * Keep incoming rows (rank, name, stove, jump, etc.) but never lower power.
 * Match existing players by id first, then by case-insensitive name.
 *
 * @param list<array<string, mixed>> $incoming  Fresh fetch / upload rows
 * @param list<array<string, mixed>> $existing  Current players.json rows
 * @return array{
 *   players: list<array<string, mixed>>,
 *   power_raised: int,
 *   power_kept: int,
 *   unmatched: int
 * }
 */
function applyNeverDecreasePlayerPower(array $incoming, array $existing): array
{
    $byId = [];
    $byName = [];
    foreach ($existing as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id !== '' && !isset($byId[$id])) {
            $byId[$id] = $row;
        }
        $name = strtolower(trim((string) ($row['name'] ?? '')));
        if ($name !== '' && !isset($byName[$name])) {
            $byName[$name] = $row;
        }
    }

    $out = [];
    $raised = 0;
    $kept = 0;
    $unmatched = 0;

    foreach ($incoming as $player) {
        if (!is_array($player)) {
            continue;
        }

        $id = trim((string) ($player['id'] ?? ''));
        $nameKey = strtolower(trim((string) ($player['name'] ?? '')));
        $prev = null;
        if ($id !== '' && isset($byId[$id])) {
            $prev = $byId[$id];
        } elseif ($nameKey !== '' && isset($byName[$nameKey])) {
            $prev = $byName[$nameKey];
        }

        if ($prev === null) {
            $unmatched++;
            $out[] = $player;
            continue;
        }

        $newPower = isset($player['power']) ? (int) $player['power'] : null;
        $oldPower = isset($prev['power']) ? (int) $prev['power'] : null;

        if ($oldPower !== null && ($newPower === null || $newPower < $oldPower)) {
            $player['power'] = $oldPower;
            $kept++;
        } elseif ($oldPower !== null && $newPower !== null && $newPower > $oldPower) {
            $raised++;
        } elseif ($oldPower !== null && $newPower === $oldPower) {
            $kept++;
        }

        // Prefer keeping a known id when the incoming row matched by name only.
        if (($player['id'] ?? null) === null && !empty($prev['id'])) {
            $player['id'] = (string) $prev['id'];
        }

        $out[] = $player;
    }

    return [
        'players' => rankPlayersByPower($out),
        'power_raised' => $raised,
        'power_kept' => $kept,
        'unmatched' => $unmatched,
    ];
}

/** Canonical name key: trim, collapse spaces, lowercase. */
function normalizePlayerNameKey(mixed $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return '';
    }
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    return strtolower($name);
}

/**
 * Match keys for a display name: full name + name without leading [TAG].
 *
 * @return list<string>
 */
function playerNameMatchKeys(mixed $name): array
{
    $full = normalizePlayerNameKey($name);
    if ($full === '') {
        return [];
    }
    $bare = preg_replace('/^\[[^\]]+\]\s*/u', '', $full) ?? $full;
    $bare = trim($bare);
    $keys = [$full];
    if ($bare !== '' && $bare !== $full) {
        $keys[] = $bare;
    }
    return $keys;
}

/**
 * OCR / bot merge: update powers by name, never delete existing players.
 * Power only increases when new > current. New names are appended.
 *
 * @param list<array<string, mixed>> $existing
 * @param mixed $playersPayload
 * @return array{
 *   players: list<array<string, mixed>>,
 *   stats: array<string, int>,
 *   changed: bool
 * }
 */
function mergePlayersPowerByName(array $existing, mixed $playersPayload): array
{
    if (!is_array($playersPayload) || $playersPayload === []) {
        throw new RuntimeException('players must be a non-empty array of {name, power}.');
    }

    if (!array_is_list($playersPayload)) {
        $list = [];
        foreach ($playersPayload as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $keyName = trim((string) $key);
            $rowName = trim((string) ($row['name'] ?? $row['nick_name'] ?? $row['nickname'] ?? ''));
            if ($rowName === '' && $keyName !== '') {
                $row['name'] = $keyName;
            }
            unset($row['id'], $row['uid']);
            $list[] = $row;
        }
        if ($list === []) {
            throw new RuntimeException('players must be a non-empty array of {name, power}.');
        }
        $playersPayload = $list;
    }

    $players = [];
    $byName = [];
    foreach ($existing as $player) {
        if (!is_array($player)) {
            continue;
        }
        $idx = count($players);
        $players[] = $player;
        foreach (playerNameMatchKeys($player['name'] ?? '') as $key) {
            if (!isset($byName[$key])) {
                $byName[$key] = $idx;
            }
        }
    }
    $beforeCount = count($players);

    $stats = [
        'received' => count($playersPayload),
        'matched' => 0,
        'added' => 0,
        'power_increased' => 0,
        'skipped_not_higher' => 0,
        'skipped_no_name' => 0,
        'skipped_no_power' => 0,
        'matched_existing_id' => 0,
    ];
    $timestamp = nowUtc();
    $changed = false;

    foreach ($playersPayload as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rawName = trim((string) ($row['name'] ?? $row['nick_name'] ?? $row['nickname'] ?? ''));
        if ($rawName === '') {
            $stats['skipped_no_name']++;
            continue;
        }
        $displayName = preg_replace('/\s+/u', ' ', $rawName) ?? $rawName;

        $newPower = parsePowerValue($row['power'] ?? null);
        if ($newPower === null) {
            $stats['skipped_no_power']++;
            continue;
        }

        $stove = $row['stove_lv'] ?? $row['stove_level'] ?? $row['furnace'] ?? null;
        $stove = ($stove !== null && $stove !== '') ? (int) $stove : null;
        $jump = isset($row['last_jump_pct']) && $row['last_jump_pct'] !== ''
            ? (float) $row['last_jump_pct']
            : null;

        $matchIdx = null;
        foreach (playerNameMatchKeys($displayName) as $key) {
            if (isset($byName[$key])) {
                $matchIdx = $byName[$key];
                break;
            }
        }

        if ($matchIdx !== null) {
            $stats['matched']++;
            $prev = $players[$matchIdx];
            $oldPower = isset($prev['power']) ? (int) $prev['power'] : null;

            if ($oldPower !== null && $newPower <= $oldPower) {
                $stats['skipped_not_higher']++;
                continue;
            }

            $players[$matchIdx]['power'] = $newPower;
            $players[$matchIdx]['updated_at'] = $timestamp;
            if ($stove !== null) {
                $players[$matchIdx]['stove_lv'] = $stove;
            }
            if ($jump !== null) {
                $players[$matchIdx]['last_jump_pct'] = $jump;
            }
            if (!empty($prev['id'])) {
                $stats['matched_existing_id']++;
            }
            $stats['power_increased']++;
            $changed = true;
            continue;
        }

        $newIdx = count($players);
        $players[] = [
            'id' => null,
            'name' => $displayName,
            'rank' => null,
            'power' => $newPower,
            'stove_lv' => $stove,
            'last_jump_pct' => $jump,
            'updated_at' => $timestamp,
        ];
        foreach (playerNameMatchKeys($displayName) as $key) {
            if (!isset($byName[$key])) {
                $byName[$key] = $newIdx;
            }
        }
        $stats['added']++;
        $stats['power_increased']++;
        $changed = true;
    }

    if (count($players) < $beforeCount) {
        throw new RuntimeException(
            'Refusing to save: merge would shrink board from '
            . $beforeCount . ' to ' . count($players) . ' players.'
        );
    }

    usort($players, static function (array $a, array $b): int {
        $pa = isset($a['power']) ? (int) $a['power'] : -1;
        $pb = isset($b['power']) ? (int) $b['power'] : -1;
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }
        return normalizePlayerNameKey($a['name'] ?? '') <=> normalizePlayerNameKey($b['name'] ?? '');
    });

    if (count($players) > TOP_PLAYERS_LIMIT) {
        $players = array_slice($players, 0, TOP_PLAYERS_LIMIT);
    }

    if (count($players) < $beforeCount && $beforeCount <= TOP_PLAYERS_LIMIT) {
        throw new RuntimeException(
            'Refusing to save: board shrink after rank trim ('
            . $beforeCount . ' → ' . count($players) . ').'
        );
    }

    $rank = 1;
    foreach ($players as &$player) {
        $player['rank'] = $rank;
        $rank++;
    }
    unset($player);

    return [
        'players' => array_values($players),
        'stats' => $stats,
        'changed' => $changed,
    ];
}

/**
 * @return list<string> Absolute paths of video files in the upload folder.
 */
function listStoredVideos(): array
{
    if (!is_dir(VIDEO_UPLOADS_DIR)) {
        return [];
    }

    $files = [];
    foreach (scandir(VIDEO_UPLOADS_DIR) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.htaccess') {
            continue;
        }
        $path = VIDEO_UPLOADS_DIR . '/' . $name;
        if (is_file($path)) {
            $files[] = $path;
        }
    }

    return $files;
}

function currentVideoPublicPath(): ?string
{
    $files = listStoredVideos();
    if ($files === []) {
        return null;
    }

    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return 'uploads/video/' . basename($files[0]);
}

/**
 * Random 6-char OCR-friendly key (avoids 0/O/1/I/L).
 */
function generateVideoOcrKey(int $length = 6): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }

    return $out;
}

/**
 * @return array{ocr_key: string, uploaded_at: string, video_path: ?string}|null
 */
function loadVideoMeta(): ?array
{
    $raw = loadJson(VIDEO_META_FILE, null);
    if (!is_array($raw)) {
        return null;
    }
    $key = strtoupper(trim((string) ($raw['ocr_key'] ?? '')));
    $uploadedAt = trim((string) ($raw['uploaded_at'] ?? ''));
    if ($key === '' || $uploadedAt === '') {
        return null;
    }

    return [
        'ocr_key' => $key,
        'uploaded_at' => $uploadedAt,
        'video_path' => isset($raw['video_path']) ? (string) $raw['video_path'] : null,
    ];
}

/**
 * @param array{ocr_key: string, uploaded_at: string, video_path?: ?string} $meta
 */
function saveVideoMeta(array $meta): void
{
    saveJson(VIDEO_META_FILE, [
        'ocr_key' => strtoupper((string) $meta['ocr_key']),
        'uploaded_at' => (string) $meta['uploaded_at'],
        'video_path' => $meta['video_path'] ?? currentVideoPublicPath(),
    ]);
}

/**
 * Ensure meta exists when a video is present (creates key from filemtime if missing).
 *
 * @return array{ocr_key: string, uploaded_at: string, video_path: ?string}|null
 */
function ensureVideoMeta(): ?array
{
    $path = currentVideoPublicPath();
    if ($path === null) {
        return null;
    }

    $meta = loadVideoMeta();
    if ($meta !== null && ($meta['video_path'] ?? null) === $path) {
        return $meta;
    }

    $abs = VIDEO_UPLOADS_DIR . '/' . basename($path);
    $mtime = is_file($abs) ? (int) filemtime($abs) : time();
    $meta = [
        'ocr_key' => generateVideoOcrKey(6),
        'uploaded_at' => gmdate('Y-m-d H:i:s', $mtime),
        'video_path' => $path,
    ];
    saveVideoMeta($meta);

    return $meta;
}

/**
 * Call after a successful video upload — always issues a fresh OCR key.
 *
 * @return array{ocr_key: string, uploaded_at: string, video_path: ?string}
 */
function recordVideoUploadMeta(string $publicPath): array
{
    $meta = [
        'ocr_key' => generateVideoOcrKey(6),
        'uploaded_at' => gmdate('Y-m-d H:i:s'),
        'video_path' => $publicPath,
    ];
    saveVideoMeta($meta);

    return $meta;
}

/** Public site origin from the current request (e.g. https://wostracker.online). */
function sitePublicBaseUrl(): string
{
    $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = $forwarded === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

/** Absolute URL to the current uploaded leaderboard video, or null if none. */
function currentVideoAbsoluteUrl(): ?string
{
    $path = currentVideoPublicPath();
    if ($path === null) {
        return null;
    }
    return rtrim(sitePublicBaseUrl(), '/') . '/' . ltrim($path, '/');
}

/** Parse power values that may include commas or spaces from OCR (e.g. "77,855,639"). */
function parsePowerValue(mixed $power): ?int
{
    if ($power === null || $power === '') {
        return null;
    }
    if (is_int($power)) {
        return $power;
    }
    if (is_float($power)) {
        return (int) round($power);
    }
    $digits = preg_replace('/[^\d]/', '', (string) $power) ?? '';
    if ($digits === '') {
        return null;
    }
    return (int) $digits;
}

function formatJumpPct(?float $value): string
{
    if ($value === null) {
        return 'N/A';
    }

    return number_format($value, 2) . '%';
}

/** Top N alliances by rank are treated as NAP. */
const NAP_TOP_ALLIANCES = 8;

function isNapAlliance(array $alliance, array $napTags = []): bool
{
    $rank = isset($alliance['rank']) ? (int) $alliance['rank'] : 0;

    return $rank >= 1 && $rank <= NAP_TOP_ALLIANCES;
}

function getLastUpdated(array $alliances): ?string
{
    $latest = null;

    foreach ($alliances as $alliance) {
        if (empty($alliance['updated_at'])) {
            continue;
        }

        if ($latest === null || $alliance['updated_at'] > $latest) {
            $latest = $alliance['updated_at'];
        }
    }

    return $latest;
}

function getTotalStatePower(array $alliances): int
{
    $total = 0;

    foreach ($alliances as $alliance) {
        if (isset($alliance['power'])) {
            $total += (int) $alliance['power'];
        }
    }

    return $total;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function assetUrl(string $path): string
{
    $fullPath = __DIR__ . '/' . ltrim($path, '/');
    $version = is_file($fullPath) ? (string) filemtime($fullPath) : (string) time();
    return h($path) . '?v=' . h($version);
}

/** Favicon + theme icons for every page head. */
function renderFaviconTags(): void
{
    ?>
    <link rel="icon" href="<?= assetUrl('assets/favicon.svg') ?>" type="image/svg+xml">
    <link rel="icon" href="<?= assetUrl('assets/favicon.png') ?>" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= assetUrl('assets/favicon.png') ?>">
    <?php
}

function limitAlliancesToTop(array $alliances, int $limit = TOP_ALLIANCES_LIMIT): array
{
    usort($alliances, static fn(array $a, array $b): int => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));

    $top = array_slice($alliances, 0, $limit);
    $rank = 1;
    foreach ($top as &$alliance) {
        $alliance['rank'] = $rank;
        $rank++;
    }
    unset($alliance);

    return $top;
}

function renderSiteFooter(): void
{
    ?>
    <footer class="site-footer">
        <p>
            System Developed by <strong>Abbas</strong> (Ben D0ver from AOC)
            ·
            <a href="https://wa.me/923052848987" target="_blank" rel="noopener noreferrer">WhatsApp</a>
        </p>
    </footer>
    <?php
}

/**
 * Shared top navigation. Live Chat and Report open in a new tab.
 *
 * @param 'alliances'|'players'|'compare'|'threat'|'alliance'|'chat'|'incidents'|'nap-banned'|'users'|null $active
 */
function renderSiteNav(?string $active = null): void
{
    ?>
    <nav class="site-nav" aria-label="Primary">
        <a class="site-brand" href="index.php">
            <span class="site-brand-mark">4627</span>
            <span class="site-brand-text">
                <strong>State Tracker</strong>
                <small>Whiteout Survival</small>
            </span>
        </a>

        <div class="site-nav-primary" aria-label="Quick links">
            <a class="nav-link <?= $active === 'alliances' || $active === 'alliance' ? 'is-active' : '' ?>" href="index.php">Alliances</a>
            <a class="nav-link <?= $active === 'players' ? 'is-active' : '' ?>" href="players.php">Players</a>
            <a
                class="nav-link nav-link-chat <?= $active === 'chat' ? 'is-active' : '' ?>"
                href="chat.php"
                target="_blank"
                rel="noopener noreferrer"
            >Live Chat</a>
            <a
                class="nav-link nav-link-report <?= $active === 'incidents' ? 'is-active' : '' ?>"
                href="incidents.php"
                target="_blank"
                rel="noopener noreferrer"
            >Report</a>
        </div>

        <div class="site-nav-links site-nav-desktop" aria-label="Desktop links">
            <a class="nav-link <?= $active === 'alliances' || $active === 'alliance' ? 'is-active' : '' ?>" href="index.php">Alliances</a>
            <a class="nav-link <?= $active === 'players' ? 'is-active' : '' ?>" href="players.php">Players</a>
            <a class="nav-link <?= $active === 'compare' ? 'is-active' : '' ?>" href="compare.php">Compare</a>
            <a class="nav-link <?= $active === 'threat' ? 'is-active' : '' ?>" href="threat-board.php">Threat Metric</a>
            <a
                class="nav-link nav-link-chat <?= $active === 'chat' ? 'is-active' : '' ?>"
                href="chat.php"
                target="_blank"
                rel="noopener noreferrer"
            >Live Chat</a>
            <a class="nav-link <?= $active === 'users' ? 'is-active' : '' ?>" href="users.php">Users</a>
            <a
                class="nav-link nav-link-report <?= $active === 'incidents' ? 'is-active' : '' ?>"
                href="incidents.php"
                target="_blank"
                rel="noopener noreferrer"
            >Report</a>
            <a class="nav-link nav-link-nap-banned <?= $active === 'nap-banned' ? 'is-active' : '' ?>" href="nap-banned.php">NAP Banned</a>
        </div>

        <button
            type="button"
            class="nav-hamburger"
            id="nav-hamburger"
            aria-label="Open menu"
            aria-controls="nav-drawer"
            aria-expanded="false"
        >
            <span></span><span></span><span></span>
        </button>
    </nav>

    <div class="nav-drawer" id="nav-drawer" hidden>
        <div class="nav-drawer-backdrop" data-nav-close tabindex="-1"></div>
        <aside class="nav-drawer-panel" role="dialog" aria-modal="true" aria-label="Site menu">
            <header class="nav-drawer-header">
                <div>
                    <p class="eyebrow">STATE 4627</p>
                    <strong>Menu</strong>
                </div>
                <button type="button" class="incident-modal-close" data-nav-close aria-label="Close menu">&times;</button>
            </header>
            <nav class="nav-drawer-links" aria-label="All pages">
                <a class="nav-drawer-link <?= $active === 'alliances' || $active === 'alliance' ? 'is-active' : '' ?>" href="index.php">Alliances</a>
                <a class="nav-drawer-link <?= $active === 'players' ? 'is-active' : '' ?>" href="players.php">Players</a>
                <a class="nav-drawer-link <?= $active === 'compare' ? 'is-active' : '' ?>" href="compare.php">Compare</a>
                <a class="nav-drawer-link <?= $active === 'threat' ? 'is-active' : '' ?>" href="threat-board.php">Threat Metric</a>
                <a class="nav-drawer-link <?= $active === 'chat' ? 'is-active' : '' ?>" href="chat.php" target="_blank" rel="noopener noreferrer">Live Chat</a>
                <a class="nav-drawer-link <?= $active === 'users' ? 'is-active' : '' ?>" href="users.php">Users</a>
                <a class="nav-drawer-link <?= $active === 'incidents' ? 'is-active' : '' ?>" href="incidents.php" target="_blank" rel="noopener noreferrer">Report</a>
                <a class="nav-drawer-link nav-drawer-link-banned <?= $active === 'nap-banned' ? 'is-active' : '' ?>" href="nap-banned.php">NAP Banned</a>
            </nav>
        </aside>
    </div>
    <script src="<?= assetUrl('assets/nav.js') ?>" defer></script>

    <div class="welcome-modal" id="welcome-modal" hidden role="dialog" aria-modal="true" aria-labelledby="welcome-title">
        <div class="welcome-modal-backdrop" data-welcome-dismiss tabindex="-1"></div>
        <div class="welcome-modal-panel" role="document">
            <div class="welcome-modal-glow" aria-hidden="true"></div>
            <header class="welcome-modal-header">
                <span class="welcome-badge">STATE 4627</span>
                <h2 id="welcome-title">Welcome to State Tracker</h2>
                <p class="welcome-lead">
                    This project is <strong>only for State 4627</strong> — built to help the kingdom stay organized.
                </p>
            </header>

            <ul class="welcome-features">
                <li>
                    <span class="welcome-feature-icon" aria-hidden="true">◆</span>
                    <div>
                        <strong>Track progress</strong>
                        <span>Alliance power, ranks, and history over time</span>
                    </div>
                </li>
                <li>
                    <span class="welcome-feature-icon" aria-hidden="true">◆</span>
                    <div>
                        <strong>Track players</strong>
                        <span>Top 200 rankings, search by ID, coords &amp; more</span>
                    </div>
                </li>
                <li>
                    <span class="welcome-feature-icon" aria-hidden="true">◆</span>
                    <div>
                        <strong>Live chat</strong>
                        <span>Talk with state mates in real time</span>
                    </div>
                </li>
                <li>
                    <span class="welcome-feature-icon" aria-hidden="true">◆</span>
                    <div>
                        <strong>Report incidents</strong>
                        <span>Log attacks, NAP issues, and evidence</span>
                    </div>
                </li>
                <li>
                    <span class="welcome-feature-icon welcome-feature-icon-more" aria-hidden="true">+</span>
                    <div>
                        <strong>And much more</strong>
                        <span>Compare alliances, roster tools, and ongoing updates</span>
                    </div>
                </li>
            </ul>

            <div class="welcome-developer">
                <p class="welcome-dev-label">Developed by</p>
                <p class="welcome-dev-name">Abbas <span>(Ben D0ver from AOC)</span></p>
            </div>

            <div class="welcome-actions">
                <a
                    class="btn-whatsapp"
                    href="https://wa.me/923052848987"
                    target="_blank"
                    rel="noopener noreferrer"
                >WhatsApp Abbas</a>
                <button type="button" class="btn-primary" data-welcome-dismiss>Got it — let's go</button>
            </div>
        </div>
    </div>
    <script src="<?= assetUrl('assets/welcome.js') ?>" defer></script>
    <?php
}

function ensureDataFilesExist(): void
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0755, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('Unable to create data directory.');
    }

    foreach ([ROSTERS_DIR, ROSTER_IMPORTS_DIR, ROSTER_POWERS_DIR] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create directory: ' . $dir);
        }
    }

    if (dbAvailable()) {
        // Schema ensured inside dbAvailable().
    } else {
        if (!file_exists(ALLIANCES_FILE)) {
            saveJson(ALLIANCES_FILE, []);
        }
        if (!file_exists(HISTORY_FILE)) {
            saveJson(HISTORY_FILE, []);
        }
        if (!file_exists(PLAYERS_FILE)) {
            saveJson(PLAYERS_FILE, []);
        }
        if (!file_exists(PLAYERS_HISTORY_FILE)) {
            saveJson(PLAYERS_HISTORY_FILE, []);
        }
        if (!file_exists(ROSTERS_HISTORY_FILE)) {
            saveJson(ROSTERS_HISTORY_FILE, []);
        }
        if (!file_exists(INCIDENTS_FILE)) {
            saveJson(INCIDENTS_FILE, []);
        }
        if (!file_exists(NAP_BANNED_FILE)) {
            saveJson(NAP_BANNED_FILE, []);
        }
    }

    if (!is_dir(INCIDENT_UPLOADS_DIR) && !mkdir(INCIDENT_UPLOADS_DIR, 0755, true) && !is_dir(INCIDENT_UPLOADS_DIR)) {
        throw new RuntimeException('Unable to create incident uploads directory.');
    }
}

function isTrackedRosterAlliance(string $allianceId): bool
{
    return isset(TRACKED_ROSTER_ALLIANCES[$allianceId]);
}

function rosterFilePath(string $allianceId): string
{
    return ROSTERS_DIR . '/' . $allianceId . '.json';
}

function normalizeRosterMember(array $member): ?array
{
    $id = $member['uid'] ?? $member['id'] ?? null;
    $name = $member['nick_name'] ?? $member['name'] ?? $member['nickname'] ?? null;
    $name = $name !== null ? trim((string) $name) : '';

    if ($id === null && $name === '') {
        return null;
    }

    $lv = $member['lv'] ?? $member['stove_lv'] ?? $member['furnace'] ?? null;
    $x = $member['x'] ?? null;
    $y = $member['y'] ?? null;

    return [
        'id' => $id !== null ? (string) $id : null,
        'name' => $name !== '' ? $name : null,
        'lv' => $lv !== null ? (int) $lv : null,
        'office' => isset($member['office']) && $member['office'] !== null && $member['office'] !== ''
            ? (string) $member['office']
            : null,
        'x' => $x !== null ? (int) $x : null,
        'y' => $y !== null ? (int) $y : null,
        'abbr' => isset($member['abbr']) ? (string) $member['abbr'] : null,
    ];
}

function loadAllianceRoster(string $allianceId): array
{
    $data = loadJson(rosterFilePath($allianceId), []);
    if (!is_array($data)) {
        return [];
    }

    if (isset($data['members']) && is_array($data['members'])) {
        return $data;
    }

    return [];
}

function getRosterHistory(array $rostersHistory, string $allianceId): array
{
    $series = $rostersHistory[$allianceId] ?? [];
    if (!is_array($series)) {
        return [];
    }

    usort($series, static fn(array $a, array $b): int => strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? '')));

    return $series;
}

function findRosterMemberInSnapshot(?array $snapshot, string $memberId): ?array
{
    if ($snapshot === null || $memberId === '') {
        return null;
    }

    foreach ($snapshot['members'] ?? [] as $member) {
        if (!is_array($member)) {
            continue;
        }
        if ((string) ($member['id'] ?? '') === $memberId) {
            return $member;
        }
    }

    return null;
}

function findRosterSnapshotNear(array $history, int $hoursAgo): ?array
{
    if ($history === []) {
        return null;
    }

    $target = time() - ($hoursAgo * 3600);
    $best = null;
    $bestDelta = null;

    foreach ($history as $snapshot) {
        if (!isset($snapshot['timestamp'])) {
            continue;
        }
        $ts = strtotime((string) $snapshot['timestamp']);
        if ($ts === false) {
            continue;
        }
        $delta = abs($ts - $target);
        if ($bestDelta === null || $delta < $bestDelta) {
            $best = $snapshot;
            $bestDelta = $delta;
        }
    }

    // Ignore matches that are wildly off the requested window.
    if ($bestDelta !== null && $bestDelta > max(6 * 3600, (int) ($hoursAgo * 3600 * 0.5))) {
        return null;
    }

    return $best;
}

function calculateMemberLevelChange(array $history, array $member, int $hours): ?int
{
    $memberId = (string) ($member['id'] ?? '');
    $currentLv = $member['lv'] ?? null;
    if ($memberId === '' || $currentLv === null) {
        return null;
    }

    $past = findRosterSnapshotNear($history, $hours);
    $pastMember = findRosterMemberInSnapshot($past, $memberId);
    if ($pastMember === null || !isset($pastMember['lv'])) {
        return null;
    }

    return (int) $currentLv - (int) $pastMember['lv'];
}

function rosterPowersFilePath(string $allianceId): string
{
    return ROSTER_POWERS_DIR . '/' . $allianceId . '.json';
}

/**
 * @return array{aid?: string, updated_at?: string, players?: array<string, array<string, mixed>>}
 */
function loadRosterPowers(string $allianceId): array
{
    $data = loadJson(rosterPowersFilePath($allianceId), []);
    return is_array($data) ? $data : [];
}

/**
 * @param array<string, array<string, mixed>> $playersByUid
 */
function saveRosterPowers(string $allianceId, array $playersByUid, string $source = 'enrich'): void
{
    $existing = loadRosterPowers($allianceId);
    $merged = [];
    if (isset($existing['players']) && is_array($existing['players'])) {
        $merged = $existing['players'];
    }

    foreach ($playersByUid as $uid => $player) {
        if (!is_array($player)) {
            continue;
        }
        $uid = (string) $uid;
        $prev = isset($merged[$uid]) && is_array($merged[$uid]) ? $merged[$uid] : [];
        $merged[$uid] = [
            'id' => $uid,
            'name' => $player['name'] ?? ($prev['name'] ?? null),
            'power' => $player['power'] ?? ($prev['power'] ?? null),
            'stove_lv' => $player['stove_lv'] ?? ($prev['stove_lv'] ?? null),
            'last_jump_pct' => $player['last_jump_pct'] ?? ($prev['last_jump_pct'] ?? null),
            'fetched_at' => $player['fetched_at'] ?? nowUtc(),
            'source' => $player['source'] ?? $source,
        ];
    }

    saveJson(rosterPowersFilePath($allianceId), [
        'aid' => $allianceId,
        'tag' => TRACKED_ROSTER_ALLIANCES[$allianceId] ?? null,
        'updated_at' => nowUtc(),
        'player_count' => count($merged),
        'players' => $merged,
    ]);
}

/**
 * Enrich roster rows with optional state power-board data (when UID matches).
 *
 * @return list<array<string, mixed>>
 */
function buildRosterProgressRows(
    array $roster,
    array $history,
    array $statePlayers = [],
    array $playersHistory = [],
    array $rosterPowers = []
): array {
    $powerById = [];
    foreach ($statePlayers as $player) {
        if (!is_array($player) || empty($player['id'])) {
            continue;
        }
        $powerById[(string) $player['id']] = $player;
    }

    $savedPowers = [];
    if (isset($rosterPowers['players']) && is_array($rosterPowers['players'])) {
        $savedPowers = $rosterPowers['players'];
    }

    $rows = [];
    foreach ($roster['members'] ?? [] as $member) {
        if (!is_array($member)) {
            continue;
        }

        $id = (string) ($member['id'] ?? '');
        $statePlayer = $id !== '' ? ($powerById[$id] ?? null) : null;
        $saved = $id !== '' && isset($savedPowers[$id]) && is_array($savedPowers[$id])
            ? $savedPowers[$id]
            : null;

        $power = null;
        if (isset($saved['power']) && $saved['power'] !== null && $saved['power'] !== '') {
            $power = (int) $saved['power'];
        } elseif (isset($statePlayer['power'])) {
            $power = (int) $statePlayer['power'];
        }

        $jump = null;
        if (isset($saved['last_jump_pct']) && $saved['last_jump_pct'] !== null && $saved['last_jump_pct'] !== '') {
            $jump = (float) $saved['last_jump_pct'];
        } elseif (isset($statePlayer['last_jump_pct'])) {
            $jump = (float) $statePlayer['last_jump_pct'];
        }

        $stove = $member['lv'] ?? null;
        if (isset($saved['stove_lv']) && $saved['stove_lv'] !== null && $saved['stove_lv'] !== '') {
            $stove = (int) $saved['stove_lv'];
            $member['lv'] = $stove;
        }

        $rows[] = [
            'member' => $member,
            'lv_change_24h' => calculateMemberLevelChange($history, $member, 24),
            'lv_change_7d' => calculateMemberLevelChange($history, $member, 24 * 7),
            'power' => $power,
            'power_change_24h' => $power !== null
                ? calculatePlayerPowerChange($playersHistory, $id, $power, 24)
                : null,
            'last_jump_pct' => $jump,
            'state_rank' => isset($statePlayer['rank']) ? (int) $statePlayer['rank'] : null,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $powerA = $a['power'] ?? -1;
        $powerB = $b['power'] ?? -1;
        if ($powerA !== $powerB && ($powerA >= 0 || $powerB >= 0)) {
            return $powerB <=> $powerA;
        }
        $lvA = $a['member']['lv'] ?? -1;
        $lvB = $b['member']['lv'] ?? -1;
        if ($lvA !== $lvB) {
            return $lvB <=> $lvA;
        }
        return strcasecmp((string) ($a['member']['name'] ?? ''), (string) ($b['member']['name'] ?? ''));
    });

    return $rows;
}

function calculatePlayerPowerChange(array $playersHistory, string $playerId, int $currentPower, int $hours): ?int
{
    if ($playerId === '' || $playersHistory === []) {
        return null;
    }

    $target = time() - ($hours * 3600);
    $bestPower = null;
    $bestDelta = null;

    foreach ($playersHistory as $snapshot) {
        if (!is_array($snapshot) || empty($snapshot['timestamp']) || empty($snapshot['players']) || !is_array($snapshot['players'])) {
            continue;
        }
        $ts = strtotime((string) $snapshot['timestamp']);
        if ($ts === false) {
            continue;
        }
        $delta = abs($ts - $target);
        if ($bestDelta !== null && $delta >= $bestDelta) {
            continue;
        }

        foreach ($snapshot['players'] as $player) {
            if (!is_array($player) || (string) ($player['id'] ?? '') !== $playerId || !isset($player['power'])) {
                continue;
            }
            $bestPower = (int) $player['power'];
            $bestDelta = $delta;
            break;
        }
    }

    if ($bestPower === null || $bestDelta === null || $bestDelta > max(6 * 3600, (int) ($hours * 3600 * 0.5))) {
        return null;
    }

    return $currentPower - $bestPower;
}

/**
 * Rank improvement is positive (moved up the board).
 */
function calculatePlayerRankChange(array $playersHistory, string $playerId, int $currentRank, int $hours): ?int
{
    if ($playerId === '' || $playersHistory === []) {
        return null;
    }

    $target = time() - ($hours * 3600);
    $bestRank = null;
    $bestDelta = null;

    foreach ($playersHistory as $snapshot) {
        if (!is_array($snapshot) || empty($snapshot['timestamp']) || empty($snapshot['players']) || !is_array($snapshot['players'])) {
            continue;
        }
        $ts = strtotime((string) $snapshot['timestamp']);
        if ($ts === false) {
            continue;
        }
        $delta = abs($ts - $target);
        if ($bestDelta !== null && $delta >= $bestDelta) {
            continue;
        }

        foreach ($snapshot['players'] as $player) {
            if (!is_array($player) || (string) ($player['id'] ?? '') !== $playerId || !isset($player['rank'])) {
                continue;
            }
            $bestRank = (int) $player['rank'];
            $bestDelta = $delta;
            break;
        }
    }

    if ($bestRank === null || $bestDelta === null || $bestDelta > max(6 * 3600, (int) ($hours * 3600 * 0.5))) {
        return null;
    }

    return $bestRank - $currentRank;
}

function calculatePlayerPowerChangePct(array $playersHistory, string $playerId, int $currentPower, int $hours): ?float
{
    $change = calculatePlayerPowerChange($playersHistory, $playerId, $currentPower, $hours);
    if ($change === null) {
        return null;
    }

    $pastPower = $currentPower - $change;
    if ($pastPower <= 0) {
        return null;
    }

    return ($change / $pastPower) * 100.0;
}

function formatLevelChange(?int $change): string
{
    if ($change === null) {
        return '—';
    }
    if ($change === 0) {
        return '0';
    }
    return ($change > 0 ? '+' : '') . $change;
}

/**
 * @return array<string, string>
 */
function incidentCategories(): array
{
    return [
        'attack' => 'Attack / raid',
        'nap_violation' => 'NAP rule violation',
        'other' => 'Other state incident',
    ];
}

function countIncidentsToday(array $incidents): int
{
    $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    $count = 0;
    foreach ($incidents as $incident) {
        if (!is_array($incident) || empty($incident['created_at'])) {
            continue;
        }
        $created = (string) $incident['created_at'];
        if (str_starts_with($created, $today)) {
            $count++;
        }
    }
    return $count;
}

/**
 * @return array{ok: bool, error?: string, incident?: array<string, mixed>}
 */
function submitIncidentReport(array $post, ?array $file): array
{
    ensureDataFilesExist();

    $categories = incidentCategories();
    $nick = trim((string) ($post['nick'] ?? ''));
    $nick = preg_replace('/[<>]/', '', $nick) ?? '';
    $nick = preg_replace('/\s+/', ' ', $nick) ?? '';
    $nick = mb_substr($nick, 0, 20);

    $category = (string) ($post['category'] ?? '');
    $title = trim((string) ($post['title'] ?? ''));
    $title = preg_replace('/[<>]/', '', $title) ?? '';
    $title = preg_replace('/\s+/', ' ', $title) ?? '';
    $title = mb_substr($title, 0, 80);

    $details = trim((string) ($post['details'] ?? ''));
    $details = preg_replace("/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/", '', $details) ?? '';
    $details = mb_substr($details, 0, 1000);

    if (mb_strlen($nick) < 2) {
        return ['ok' => false, 'error' => 'Nick must be 2–20 characters.'];
    }
    if (!isset($categories[$category])) {
        return ['ok' => false, 'error' => 'Choose a valid incident type.'];
    }
    if ($title === '') {
        return ['ok' => false, 'error' => 'Add a short title.'];
    }
    if ($details === '') {
        return ['ok' => false, 'error' => 'Describe what happened.'];
    }

    $lockPath = INCIDENTS_FILE . '.lock';
    $lock = fopen($lockPath, 'c+');
    if ($lock === false) {
        return ['ok' => false, 'error' => 'Could not lock incident store.'];
    }
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        return ['ok' => false, 'error' => 'Could not lock incident store.'];
    }

    try {
        $incidents = loadJson(INCIDENTS_FILE, []);
        if (!is_array($incidents)) {
            $incidents = [];
        }

        $todayCount = countIncidentsToday($incidents);
        if ($todayCount >= INCIDENT_MAX_PER_DAY) {
            return [
                'ok' => false,
                'error' => 'Daily report limit reached (' . INCIDENT_MAX_PER_DAY . ' for the whole state today). Try again tomorrow (UTC).',
            ];
        }

        $attachment = null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $saved = storeIncidentAttachment($file);
            if (!$saved['ok']) {
                return $saved;
            }
            $attachment = $saved['path'];
        }

        $incident = [
            'id' => bin2hex(random_bytes(8)),
            'nick' => $nick,
            'category' => $category,
            'title' => $title,
            'details' => $details,
            'attachment' => $attachment,
            'created_at' => nowUtc(),
        ];

        $incidents[] = $incident;
        if (count($incidents) > 200) {
            $incidents = array_slice($incidents, -200);
        }

        // Prefer append/upsert so an empty load or deploy never wipes the table.
        if (dbAvailable()) {
            dbUpsertIncident($incident);
        } else {
            // File mode: refuse to replace a non-empty store with a smaller wipe risk after empty load.
            if (is_file(INCIDENTS_FILE)) {
                $existingRaw = file_get_contents(INCIDENTS_FILE);
                $existingData = is_string($existingRaw) ? json_decode($existingRaw, true) : null;
                if (is_array($existingData) && $existingData !== [] && $incidents === []) {
                    throw new RuntimeException('Refusing to wipe incidents.json.');
                }
            }
            saveJson(INCIDENTS_FILE, array_values($incidents));
        }

        return ['ok' => true, 'incident' => $incident];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * @param array<string, mixed> $file from $_FILES['attachment']
 * @return array{ok: bool, error?: string, path?: string}
 */
function storeIncidentAttachment(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed. Try another image.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if ($size <= 0 || $size > INCIDENT_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Screenshot must be 2 MB or smaller.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($map[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, WEBP, or GIF screenshots are allowed.'];
    }

    if (!is_dir(INCIDENT_UPLOADS_DIR) && !mkdir(INCIDENT_UPLOADS_DIR, 0755, true) && !is_dir(INCIDENT_UPLOADS_DIR)) {
        return ['ok' => false, 'error' => 'Upload folder missing on server.'];
    }

    $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $map[$mime];
    $dest = INCIDENT_UPLOADS_DIR . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'Could not save screenshot.'];
    }
    @chmod($dest, 0644);

    return ['ok' => true, 'path' => 'uploads/incidents/' . $name];
}

/**
 * @return list<array<string, mixed>>
 */
function loadIncidentsNewestFirst(int $limit = 50): array
{
    $incidents = loadJson(INCIDENTS_FILE, []);
    if (!is_array($incidents)) {
        return [];
    }
    usort($incidents, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    return array_slice($incidents, 0, $limit);
}

/**
 * @return list<array<string, mixed>>
 */
function loadNapBannedPlayers(): array
{
    $list = loadJson(NAP_BANNED_FILE, []);
    if (!is_array($list)) {
        return [];
    }

    $clean = [];
    foreach ($list as $entry) {
        if (is_array($entry)) {
            $clean[] = $entry;
        }
    }

    usort($clean, static fn(array $a, array $b): int => strcmp((string) ($b['added_at'] ?? ''), (string) ($a['added_at'] ?? '')));

    return $clean;
}

function verifyNapBannedPassword(string $password): bool
{
    return hash_equals(NAP_BANNED_PASSWORD, $password);
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: bool, error?: string, entry?: array<string, mixed>}
 */
function addNapBannedPlayer(array $input): array
{
    $password = (string) ($input['password'] ?? '');
    if (!verifyNapBannedPassword($password)) {
        return ['ok' => false, 'error' => 'Wrong password.'];
    }

    $name = trim((string) ($input['name'] ?? ''));
    $playerId = preg_replace('/\D+/', '', (string) ($input['player_id'] ?? '')) ?? '';
    $reason = trim((string) ($input['reason'] ?? ''));

    if ($name === '' && $playerId === '') {
        return ['ok' => false, 'error' => 'Enter a player name or ID.'];
    }

    // If only ID was provided, try Atlas lookup to fill the name.
    if ($name === '' && $playerId !== '') {
        try {
            require_once __DIR__ . '/player-lookup.php';
            $config = loadConfig();
            $lookup = lookupPlayerByUid($playerId, $config);
            if (is_array($lookup)) {
                $lookup = enrichPlayerLookupWithLocalData($lookup);
                if (!empty($lookup['name'])) {
                    $name = trim((string) $lookup['name']);
                }
                // Prefer canonical Atlas uid when available.
                if (!empty($lookup['id'])) {
                    $playerId = preg_replace('/\D+/', '', (string) $lookup['id']) ?? $playerId;
                }
            }
        } catch (Throwable $e) {
            // Keep ID-only save if lookup fails.
        }
    }

    if (mb_strlen($name) > 40) {
        return ['ok' => false, 'error' => 'Name is too long.'];
    }
    if (mb_strlen($reason) > 200) {
        return ['ok' => false, 'error' => 'Reason is too long.'];
    }

    $list = loadNapBannedPlayers();
    foreach ($list as $existing) {
        $existingId = preg_replace('/\D+/', '', (string) ($existing['player_id'] ?? '')) ?? '';
        if ($playerId !== '' && $existingId !== '' && $existingId === $playerId) {
            return ['ok' => false, 'error' => 'That player ID is already on the NAP banned list.'];
        }
        if ($name !== '' && strcasecmp((string) ($existing['name'] ?? ''), $name) === 0 && $playerId === '') {
            return ['ok' => false, 'error' => 'That player name is already on the NAP banned list.'];
        }
    }

    $entry = [
        'id' => bin2hex(random_bytes(8)),
        'name' => $name !== '' ? $name : null,
        'player_id' => $playerId !== '' ? $playerId : null,
        'reason' => $reason !== '' ? $reason : null,
        'added_at' => nowUtc(),
    ];

    $list[] = $entry;
    saveJson(NAP_BANNED_FILE, array_values($list));

    return ['ok' => true, 'entry' => $entry];
}

/**
 * @return array{ok: bool, error?: string}
 */
function removeNapBannedPlayer(string $entryId, string $password): array
{
    if (!verifyNapBannedPassword($password)) {
        return ['ok' => false, 'error' => 'Wrong password.'];
    }

    $entryId = trim($entryId);
    if ($entryId === '') {
        return ['ok' => false, 'error' => 'Missing entry id.'];
    }

    $list = loadNapBannedPlayers();
    $next = [];
    $removed = false;
    foreach ($list as $entry) {
        if ((string) ($entry['id'] ?? '') === $entryId) {
            $removed = true;
            continue;
        }
        $next[] = $entry;
    }

    if (!$removed) {
        return ['ok' => false, 'error' => 'Entry not found.'];
    }

    saveJson(NAP_BANNED_FILE, array_values($next));

    return ['ok' => true];
}

function googleOAuthConfig(): array
{
    $config = loadConfig();
    $g = is_array($config['google_oauth'] ?? null) ? $config['google_oauth'] : [];

    return [
        'client_id' => trim((string) ($g['client_id'] ?? '')),
        'client_secret' => trim((string) ($g['client_secret'] ?? '')),
    ];
}

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('wos_user_sess');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * @return array{id: string, email: ?string, display_name: string, photo_url: ?string}|null
 */
function currentAppUser(): ?array
{
    startAppSession();
    $id = trim((string) ($_SESSION['app_user_id'] ?? ''));
    if ($id === '') {
        return null;
    }

    return findAppUserById($id);
}

function requireAppUser(): array
{
    $user = currentAppUser();
    if ($user === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Sign in required.']);
        exit;
    }

    return $user;
}

/**
 * @return array{id: string, email: ?string, display_name: string, photo_url: ?string}|null
 */
function findAppUserById(string $id): ?array
{
    $id = trim($id);
    if ($id === '' || !dbAvailable()) {
        return null;
    }
    dbEnsureSchema();
    $stmt = db()->prepare('SELECT id, email, display_name, photo_url FROM app_users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (string) $row['id'],
        'email' => $row['email'] !== null ? (string) $row['email'] : null,
        'display_name' => (string) ($row['display_name'] ?? 'User'),
        'photo_url' => $row['photo_url'] !== null ? (string) $row['photo_url'] : null,
    ];
}

/**
 * Verify a Google ID token for our OAuth web client.
 *
 * @return array{sub: string, email: ?string, name: ?string, picture: ?string}|null
 */
function verifyGoogleIdToken(string $idToken, string $clientId): ?array
{
    $idToken = trim($idToken);
    $clientId = trim($clientId);
    if ($idToken === '' || $clientId === '') {
        return null;
    }

    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) {
            return null;
        }
    } else {
        $raw = @file_get_contents($url);
        if ($raw === false) {
            return null;
        }
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    $aud = (string) ($data['aud'] ?? '');
    if ($aud !== $clientId) {
        return null;
    }
    if (($data['email_verified'] ?? 'true') === 'false' || ($data['email_verified'] ?? true) === false) {
        // Allow missing email_verified, block explicit false.
        if (array_key_exists('email_verified', $data) && ($data['email_verified'] === false || $data['email_verified'] === 'false')) {
            return null;
        }
    }

    $sub = trim((string) ($data['sub'] ?? ''));
    if ($sub === '') {
        return null;
    }

    return [
        'sub' => $sub,
        'email' => isset($data['email']) ? trim((string) $data['email']) : null,
        'name' => isset($data['name']) ? trim((string) $data['name']) : null,
        'picture' => isset($data['picture']) ? trim((string) $data['picture']) : null,
    ];
}

/**
 * @param array{sub: string, email: ?string, name: ?string, picture: ?string} $google
 * @return array{id: string, email: ?string, display_name: string, photo_url: ?string}
 */
function upsertAppUserFromGoogle(array $google): array
{
    if (!dbAvailable()) {
        throw new RuntimeException('Database unavailable.');
    }
    dbEnsureSchema();

    $id = $google['sub'];
    $email = $google['email'] !== null && $google['email'] !== '' ? $google['email'] : null;
    $name = trim((string) ($google['name'] ?? ''));
    if ($name === '') {
        $name = $email !== null ? explode('@', $email)[0] : 'User';
    }
    $name = mb_substr($name, 0, 120);
    $photo = $google['picture'] !== null && $google['picture'] !== '' ? mb_substr($google['picture'], 0, 500) : null;
    $now = gmdate('Y-m-d H:i:s');

    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO app_users (id, email, display_name, photo_url, created_at, last_seen_at)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           email = VALUES(email),
           display_name = VALUES(display_name),
           photo_url = VALUES(photo_url),
           last_seen_at = VALUES(last_seen_at)'
    );
    $stmt->execute([$id, $email, $name, $photo, $now, $now]);

    return [
        'id' => $id,
        'email' => $email,
        'display_name' => $name,
        'photo_url' => $photo,
    ];
}

function loginAppUser(array $user): void
{
    startAppSession();
    session_regenerate_id(true);
    $_SESSION['app_user_id'] = $user['id'];
}

function logoutAppUser(): void
{
    startAppSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
    }
    session_destroy();
}

function privateThreadId(string $a, string $b): string
{
    return $a < $b ? $a . '_' . $b : $b . '_' . $a;
}

/**
 * @return list<array{id: string, email: ?string, display_name: string, photo_url: ?string, last_seen_at: ?string}>
 */
function listAppUsers(?string $excludeId = null): array
{
    if (!dbAvailable()) {
        return [];
    }
    dbEnsureSchema();
    $sql = 'SELECT id, email, display_name, photo_url, last_seen_at FROM app_users';
    $params = [];
    if ($excludeId !== null && $excludeId !== '') {
        $sql .= ' WHERE id <> ?';
        $params[] = $excludeId;
    }
    $sql .= ' ORDER BY display_name ASC, id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = [
            'id' => (string) $row['id'],
            'email' => $row['email'] !== null ? (string) $row['email'] : null,
            'display_name' => (string) ($row['display_name'] ?? 'User'),
            'photo_url' => $row['photo_url'] !== null ? (string) $row['photo_url'] : null,
            'last_seen_at' => $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null,
        ];
    }

    return $out;
}

/**
 * @return list<array{id: int, from_user_id: string, to_user_id: string, body: ?string, image_url: ?string, created_at: string, from_name: string}>
 */
function listPrivateMessages(string $meId, string $peerId, int $limit = 120): array
{
    if (!dbAvailable()) {
        return [];
    }
    dbEnsureSchema();
    $limit = max(1, min(200, $limit));
    $threadId = privateThreadId($meId, $peerId);
    $stmt = db()->prepare(
        'SELECT m.id, m.from_user_id, m.to_user_id, m.body, m.image_url, m.created_at, u.display_name AS from_name
         FROM private_messages m
         LEFT JOIN app_users u ON u.id = m.from_user_id
         WHERE m.thread_id = ?
         ORDER BY m.id DESC
         LIMIT ' . (int) $limit
    );
    $stmt->execute([$threadId]);
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'from_user_id' => (string) $row['from_user_id'],
            'to_user_id' => (string) $row['to_user_id'],
            'body' => $row['body'] !== null ? (string) $row['body'] : null,
            'image_url' => $row['image_url'] !== null ? (string) $row['image_url'] : null,
            'created_at' => (string) $row['created_at'],
            'from_name' => (string) ($row['from_name'] ?? 'User'),
        ];
    }

    return $out;
}

/**
 * @return array{ok: bool, error?: string, message?: array<string, mixed>}
 */
function sendPrivateMessage(string $fromId, string $toId, string $text, ?string $imageUrl): array
{
    $text = trim($text);
    if (mb_strlen($text) > 300) {
        return ['ok' => false, 'error' => 'Message too long.'];
    }
    if ($text === '' && ($imageUrl === null || $imageUrl === '')) {
        return ['ok' => false, 'error' => 'Empty message.'];
    }
    if ($fromId === $toId) {
        return ['ok' => false, 'error' => 'Cannot message yourself.'];
    }
    if (findAppUserById($toId) === null) {
        return ['ok' => false, 'error' => 'User not found.'];
    }
    if (!dbAvailable()) {
        return ['ok' => false, 'error' => 'Database unavailable.'];
    }
    dbEnsureSchema();

    if ($imageUrl !== null && $imageUrl !== '') {
        if (!str_starts_with($imageUrl, 'https://') && !str_starts_with($imageUrl, 'http://') && !str_starts_with($imageUrl, 'uploads/')) {
            return ['ok' => false, 'error' => 'Invalid image URL.'];
        }
        $imageUrl = mb_substr($imageUrl, 0, 500);
    } else {
        $imageUrl = null;
    }

    $threadId = privateThreadId($fromId, $toId);
    $now = gmdate('Y-m-d H:i:s');
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO private_messages (thread_id, from_user_id, to_user_id, body, image_url, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $threadId,
        $fromId,
        $toId,
        $text !== '' ? $text : null,
        $imageUrl,
        $now,
    ]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE app_users SET last_seen_at = ? WHERE id = ?')->execute([$now, $fromId]);

    $me = findAppUserById($fromId);

    return [
        'ok' => true,
        'message' => [
            'id' => $id,
            'from_user_id' => $fromId,
            'to_user_id' => $toId,
            'body' => $text !== '' ? $text : null,
            'image_url' => $imageUrl,
            'created_at' => $now,
            'from_name' => $me['display_name'] ?? 'User',
        ],
    ];
}

/**
 * Same action as run.php / cron: call update.php with the configured token.
 *
 * @return array{ok: bool, http_code: int, output: string, error: ?string}
 */
function runAllianceDataUpdate(?string $userAgent = null): array
{
    $config = loadConfig();
    $expectedToken = (string) ($config['update_token'] ?? '');
    $logFile = DATA_DIR . '/cron-update.log';

    if ($expectedToken === '' || $expectedToken === 'CHANGE_ME') {
        $error = 'Set a secure update_token in config.json before running updates.';
        @file_put_contents($logFile, gmdate('Y-m-d H:i:s') . ' UTC  FAILED: ' . $error . "\n", FILE_APPEND);

        return ['ok' => false, 'http_code' => 0, 'output' => '', 'error' => $error];
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'wostracker.online');
    $url = $scheme . '://' . $host . '/update.php?token=' . rawurlencode($expectedToken);

    $startedAt = gmdate('Y-m-d H:i:s') . ' UTC';
    @file_put_contents($logFile, $startedAt . "  starting update (runAllianceDataUpdate)\n", FILE_APPEND);

    if (!function_exists('curl_init')) {
        $error = 'PHP curl extension is required to run updates.';
        @file_put_contents($logFile, gmdate('Y-m-d H:i:s') . " UTC  FAILED: curl missing\n", FILE_APPEND);

        return ['ok' => false, 'http_code' => 0, 'output' => '', 'error' => $error];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => $userAgent !== null && $userAgent !== '' ? $userAgent : 'WOSTracker-run',
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        $error = 'Update request failed: ' . ($curlError !== '' ? $curlError : 'unknown error');
        @file_put_contents($logFile, gmdate('Y-m-d H:i:s') . ' UTC  FAILED: ' . $error . "\n", FILE_APPEND);

        return ['ok' => false, 'http_code' => $httpCode, 'output' => '', 'error' => $error];
    }

    $output = trim((string) $body);
    $ok = $httpCode >= 200 && $httpCode < 300 && str_starts_with($output, 'Update successful');
    $statusLine = $ok ? ('OK: ' . $output) : ('FAILED HTTP ' . $httpCode . ': ' . $output);
    @file_put_contents($logFile, gmdate('Y-m-d H:i:s') . ' UTC  ' . $statusLine . "\n", FILE_APPEND);

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'output' => $output,
        'error' => $ok ? null : ($output !== '' ? $output : ('Update failed (HTTP ' . $httpCode . ').')),
    ];
}


