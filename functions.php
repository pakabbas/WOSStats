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
const SVS_OPPONENT_KID = '4642';
const SVS_OPPONENT_CACHE_FILE = DATA_DIR . '/svs-4642.json';
const SVS_OPPONENT_CACHE_TTL = 6 * 3600; // refresh Atlas scout data every 6h
const SVS_ARENA_CACHE_FILE = DATA_DIR . '/svs-arena-4642.json';
const SVS_ARENA_CACHE_TTL = 6 * 3600;
const SVS_ARENA_SCOUT_LIMIT = 12;
const SITE_VISITS_FILE = DATA_DIR . '/site-visits.json';
const SITE_GEO_CACHE_FILE = DATA_DIR . '/visitor-geo-cache.json';
/** @deprecated Use SITE_VISITS_FILE */
const SVS_VISITS_FILE = SITE_VISITS_FILE;
const SVS_VISIT_PAGE_KEY = 'state-4642';

/** Only this Google account (from users.php / app_users) can open the admin dashboard. */
const APP_ADMIN_EMAIL = 'pakabbas52@gmail.com';

/** Local username accounts that can open the admin dashboard (manually provisioned). */
const APP_ADMIN_USERNAMES = [
    'disney',
];

/** Admin usernames that must not see visitor tracking. */
const APP_ADMIN_VISITS_DENIED_USERNAMES = [
    'disney',
];

/** Alliance IDs that keep a member roster / progress table. */
/** Roster rows older than this show "updated X ago". */
const ROSTER_STALE_DISPLAY_SECONDS = 48 * 3600;
/** Members not confirmed (live list or /players/{uid}) for this long are dropped from the roster. */
const ROSTER_MEMBER_DROP_SECONDS = 14 * 24 * 3600;
/** Non-top-20 members refreshed via /players/{uid} per collector run, per alliance. */
const ROSTER_REFRESH_PER_RUN = 15;

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

/**
 * Atlas stove/furnace level → player-facing label.
 * API keeps climbing past 30: +5 per Crystal tier (31–35 = C1.1–C1.5, 36–40 = C2.1–C2.5, …).
 *
 * @return array{raw: int, label: string, short: string, is_crystal: bool, crystal_tier: ?int, crystal_step: ?int}
 */
function parseFurnaceLevel(int $level): array
{
    if ($level <= 30) {
        return [
            'raw' => $level,
            'label' => 'Furnace ' . $level,
            'short' => 'FC' . $level,
            'is_crystal' => false,
            'crystal_tier' => null,
            'crystal_step' => null,
        ];
    }

    $past = $level - 30;
    $tier = (int) ceil($past / 5);
    $step = (($past - 1) % 5) + 1;

    return [
        'raw' => $level,
        'label' => 'Crystal ' . $tier . '.' . $step,
        'short' => 'C' . $tier . '.' . $step,
        'is_crystal' => true,
        'crystal_tier' => $tier,
        'crystal_step' => $step,
    ];
}

/** Human label for Atlas stove_lv / furnace_lv (e.g. 40 → "Crystal 2.5", 33 → "Crystal 1.3"). */
function formatFurnaceLevel(?int $level, bool $short = false): string
{
    if ($level === null || $level <= 0) {
        return '—';
    }

    $info = parseFurnaceLevel($level);
    return $short ? $info['short'] : $info['label'];
}

/** Chart/axis label: "C2.5 (40)" for crystal, "FC30" below. */
function formatFurnaceChartLabel(?int $level): string
{
    if ($level === null || $level <= 0) {
        return '—';
    }

    $info = parseFurnaceLevel($level);
    if ($info['is_crystal']) {
        return $info['short'] . ' (' . $info['raw'] . ')';
    }

    return $info['short'];
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
 * Per-alliance strength from the players board (top 200): sum of the 10 strongest
 * players' power and the number of whales (>= THREAT_WHALE_MIN_POWER).
 *
 * @return array{top10: int, whales: int, board_count: int}
 */
const THREAT_TOP_N = 10;
const THREAT_WHALE_MIN_POWER = 60000000;

function allianceBoardStrength(array $alliance): array
{
    static $byKey = null;
    if ($byKey === null) {
        $byKey = [];
        try {
            $players = loadJson(PLAYERS_FILE, []);
        } catch (Throwable $e) {
            $players = [];
        }
        $grouped = [];
        foreach (is_array($players) ? $players : [] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $power = isset($p['power']) ? (int) $p['power'] : 0;
            if ($power <= 0) {
                continue;
            }
            $aid = trim((string) ($p['alliance_id'] ?? ''));
            $tag = trim((string) ($p['alliance_tag'] ?? '')); // case-sensitive: WaS != wAs, B2K != b2k
            $key = $aid !== '' ? 'id:' . $aid : ($tag !== '' ? 'tag:' . $tag : null);
            if ($key === null) {
                continue;
            }
            $grouped[$key][] = $power;
            if ($aid !== '' && $tag !== '') {
                $grouped['tagfallback:' . $tag][] = $power;
            }
        }
        foreach ($grouped as $key => $powers) {
            rsort($powers);
            $byKey[$key] = [
                'top10' => array_sum(array_slice($powers, 0, THREAT_TOP_N)),
                'whales' => count(array_filter($powers, static fn(int $v): bool => $v >= THREAT_WHALE_MIN_POWER)),
                'board_count' => count($powers),
            ];
        }
    }

    $empty = ['top10' => 0, 'whales' => 0, 'board_count' => 0];
    $id = trim((string) ($alliance['id'] ?? ''));
    $tag = trim((string) ($alliance['tag'] ?? ''));
    if ($id !== '' && isset($byKey['id:' . $id])) {
        return $byKey['id:' . $id];
    }
    if ($tag !== '' && isset($byKey['tag:' . $tag])) {
        return $byKey['tag:' . $tag];
    }
    if ($tag !== '' && isset($byKey['tagfallback:' . $tag])) {
        return $byKey['tagfallback:' . $tag];
    }

    return $empty;
}

/**
 * Threat metrics.
 *
 * Ranking score = combined power of the alliance's 10 strongest players on the
 * top-200 board (real fighting core; LUX/MaD rank above small top-heavy alliances).
 * Whales = members on the board at >= 60M. The old lopsidedness score
 * (√spike + mass + 0.25×depth) is kept as 'legacy_score' for information only.
 *
 * @return array{spike: ?float, mass: ?float, depth: ?float, legacy_score: ?float, top10: int, whales: int, board_count: int, score: float}|null
 */
function allianceThreatMetrics(array $alliance, ?int $referenceMedian): ?array
{
    $max = isset($alliance['max_power']) ? (int) $alliance['max_power'] : null;
    $median = isset($alliance['median_power']) ? (int) $alliance['median_power'] : null;
    $avg = allianceAveragePower($alliance);

    $spike = null;
    $mass = null;
    $depth = null;
    $legacy = null;
    if (
        $max !== null && $median !== null && $avg !== null
        && $median > 0 && $avg > 0
        && $referenceMedian !== null && $referenceMedian > 0
    ) {
        $spike = $max / $median;
        $mass = $median / $referenceMedian;
        $depth = $median / $avg;
        $legacy = sqrt($spike) + $mass + (0.25 * $depth);
    }

    $strength = allianceBoardStrength($alliance);
    if ($strength['board_count'] === 0 && $legacy === null) {
        return null;
    }

    return [
        'spike' => $spike,
        'mass' => $mass,
        'depth' => $depth,
        'legacy_score' => $legacy,
        'top10' => $strength['top10'],
        'whales' => $strength['whales'],
        'board_count' => $strength['board_count'],
        'score' => (float) $strength['top10'],
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

    $parts = [
        'Top 10 players power ' . formatPower((int) ($metrics['top10'] ?? 0)),
        'Whales ≥60M: ' . (int) ($metrics['whales'] ?? 0),
    ];

    return implode(' · ', $parts);
}

/**
 * Threat score display (combined power of the 10 strongest board players).
 */
function formatThreatScore(?float $score): string
{
    if ($score === null) {
        return '—';
    }

    return formatPower((int) round($score));
}

function threatRatioClass(?float $score): string
{
    if ($score === null) {
        return '';
    }

    if ($score >= 800000000) {
        return 'ratio-cell-high';
    }

    if ($score >= 400000000) {
        return 'ratio-cell-mid';
    }

    return '';
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
 * List alliance IDs that have a saved roster JSON file.
 *
 * @return list<string>
 */
function listStoredRosterAllianceIds(): array
{
    if (!is_dir(ROSTERS_DIR)) {
        return [];
    }

    $ids = [];
    foreach (glob(ROSTERS_DIR . '/*.json') ?: [] as $file) {
        $id = basename((string) $file, '.json');
        if ($id !== '') {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * Build uid → alliance map from local roster files.
 *
 * @return array<string, array{alliance_id: string, alliance_tag: ?string}>
 */
function buildPlayerAllianceLookupFromRosters(): array
{
    $lookup = [];

    foreach (listStoredRosterAllianceIds() as $allianceId) {
        $roster = loadAllianceRoster($allianceId);
        $fallbackTag = trim((string) ($roster['tag'] ?? TRACKED_ROSTER_ALLIANCES[$allianceId] ?? ''));

        foreach ($roster['members'] ?? [] as $member) {
            if (!is_array($member)) {
                continue;
            }
            $uid = trim((string) ($member['id'] ?? $member['uid'] ?? ''));
            if ($uid === '') {
                continue;
            }
            $tag = trim((string) ($member['abbr'] ?? $fallbackTag));
            $lookup[$uid] = [
                'alliance_id' => (string) $allianceId,
                'alliance_tag' => $tag !== '' ? $tag : null,
            ];
        }
    }

    return $lookup;
}

/**
 * Apply a uid → alliance map onto players (does not overwrite existing non-empty tags).
 *
 * @param list<array<string, mixed>> $players
 * @param array<string, array{alliance_id?: ?string, alliance_tag?: ?string}> $lookup
 * @return list<array<string, mixed>>
 */
function applyPlayerAllianceLookup(array $players, array $lookup): array
{
    if ($lookup === []) {
        return $players;
    }

    foreach ($players as &$player) {
        if (!is_array($player)) {
            continue;
        }
        $id = trim((string) ($player['id'] ?? ''));
        if ($id === '' || !isset($lookup[$id])) {
            continue;
        }

        $info = $lookup[$id];
        if (empty($player['alliance_tag']) && !empty($info['alliance_tag'])) {
            $player['alliance_tag'] = (string) $info['alliance_tag'];
        }
        if (empty($player['alliance_id']) && !empty($info['alliance_id'])) {
            $player['alliance_id'] = (string) $info['alliance_id'];
        }
    }
    unset($player);

    return $players;
}

/**
 * Fill missing alliance_tag / alliance_id from local rosters and unique max_power matches.
 *
 * @param list<array<string, mixed>> $players
 * @param list<array<string, mixed>> $alliances
 * @param array<string, array{alliance_id?: ?string, alliance_tag?: ?string}> $extraLookup
 * @return list<array<string, mixed>>
 */
function enrichPlayersWithAllianceInfo(array $players, array $alliances = [], array $extraLookup = []): array
{
    $lookup = buildPlayerAllianceLookupFromRosters();
    if ($extraLookup !== []) {
        $lookup = array_merge($lookup, $extraLookup);
    }
    $players = applyPlayerAllianceLookup($players, $lookup);

    // Unique max_power → alliance (tags each alliance's top player when power matches).
    $powerToAlliance = [];
    foreach ($alliances as $alliance) {
        if (!is_array($alliance) || !isset($alliance['max_power'])) {
            continue;
        }
        $maxPower = (int) $alliance['max_power'];
        if (isset($powerToAlliance[$maxPower])) {
            $powerToAlliance[$maxPower] = false; // ambiguous
            continue;
        }
        $powerToAlliance[$maxPower] = $alliance;
    }

    foreach ($players as &$player) {
        if (!is_array($player) || !empty($player['alliance_tag']) || !isset($player['power'])) {
            continue;
        }
        $match = $powerToAlliance[(int) $player['power']] ?? null;
        if (!is_array($match)) {
            continue;
        }
        $tag = trim((string) ($match['tag'] ?? ''));
        if ($tag !== '') {
            $player['alliance_tag'] = $tag;
        }
        if (empty($player['alliance_id']) && !empty($match['id'])) {
            $player['alliance_id'] = (string) $match['id'];
        }
    }
    unset($player);

    return $players;
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

        // Keep known alliance fields when the leaderboard payload omits them.
        if (empty($player['alliance_tag']) && !empty($prev['alliance_tag'])) {
            $player['alliance_tag'] = (string) $prev['alliance_tag'];
        }
        if (empty($player['alliance_id']) && !empty($prev['alliance_id'])) {
            $player['alliance_id'] = (string) $prev['alliance_id'];
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

/** Top N alliances by rank are treated as NAP (unless admin overrides). */
const NAP_TOP_ALLIANCES = 7;

function isNapAlliance(array $alliance, array $napTags = []): bool
{
    $mode = napMembershipModeForAlliance($alliance);
    if ($mode === 'exclude') {
        return false;
    }
    if ($mode === 'include') {
        return true;
    }

    $rank = isset($alliance['rank']) ? (int) $alliance['rank'] : 0;
    if ($rank >= 1 && $rank <= NAP_TOP_ALLIANCES) {
        return true;
    }

    $tag = trim((string) ($alliance['tag'] ?? ''));
    if ($tag !== '' && $napTags !== []) {
        foreach ($napTags as $napTag) {
            if (trim((string) $napTag) === $tag) {
                return true;
            }
        }
    }

    return false;
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

/** Relative prefix back to site root when the current script lives in a subfolder (e.g. admin/). */
function webRootPrefix(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
        return '';
    }
    $segments = array_values(array_filter(explode('/', trim($dir, '/')), static fn(string $s): bool => $s !== ''));
    if ($segments === []) {
        return '';
    }

    return str_repeat('../', count($segments));
}

function assetUrl(string $path): string
{
    $fullPath = __DIR__ . '/' . ltrim($path, '/');
    $version = is_file($fullPath) ? (string) filemtime($fullPath) : (string) time();
    return h(webRootPrefix() . ltrim($path, '/')) . '?v=' . h($version);
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

function renderWhatsAppIconLink(string $extraClass = ''): void
{
    $class = trim('wa-icon-link ' . $extraClass);
    ?>
    <a
        class="<?= h($class) ?>"
        href="https://wa.me/923052848987"
        target="_blank"
        rel="noopener noreferrer"
        title="WhatsApp Abbas"
        aria-label="WhatsApp Abbas"
    >
        <svg class="wa-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M17.47 14.38c-.3-.15-1.77-.87-2.04-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.95 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.48-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.2-.24-.58-.5-.5-.67-.5h-.57c-.2 0-.52.07-.8.37-.27.3-1.05 1.02-1.05 2.5s1.08 2.9 1.23 3.1c.15.2 2.12 3.24 5.14 4.54.72.31 1.28.5 1.72.64.72.23 1.38.2 1.9.12.58-.09 1.77-.72 2.02-1.42.25-.7.25-1.3.17-1.42-.07-.12-.27-.2-.57-.35ZM12.04 2C6.5 2 2 6.5 2 12.04c0 1.78.47 3.5 1.36 5.02L2 22l5.07-1.33A10 10 0 0 0 12.04 22C17.55 22 22 17.55 22 12.04 22 6.5 17.55 2 12.04 2Z"/>
        </svg>
    </a>
    <?php
}

function renderSiteFooter(): void
{
    ?>
    <footer class="site-footer">
        <p>
            System Developed by <strong>Abbas</strong> (Ben D0ver from AOC)
            ·
            <?php renderWhatsAppIconLink(); ?>
        </p>
    </footer>
    <?php
}

/**
 * Shared top navigation. Live Chat and Report open in a new tab.
 *
 * @param 'alliances'|'players'|'compare'|'threat'|'alliance'|'chat'|'incidents'|'nap-banned'|'users'|'nap-rules'|'announcements'|'admin'|null $active
 */
function renderSiteNav(?string $active = null): void
{
    // Silent site-wide analytics (admin-only reporting). Never break page render.
    try {
        recordSiteVisit();
    } catch (Throwable $e) {
        // ignore
    }

    $showAdmin = isAppAdmin();
    $base = webRootPrefix();
    $fabAccountHref = $showAdmin ? ($base . 'admin/') : ($base . 'users.php');
    $fabAccountLabel = $showAdmin ? 'Admin' : 'Sign In';
    ?>
    <nav class="site-nav" aria-label="Primary">
        <a class="site-brand" href="<?= h($base) ?>index.php">
            <span class="site-brand-mark">4627</span>
            <span class="site-brand-text">
                <strong>State Tracker</strong>
                <small>Whiteout Survival</small>
            </span>
        </a>

        <div class="site-nav-primary" aria-label="Quick links">
            <a class="nav-link <?= $active === 'alliances' || $active === 'alliance' ? 'is-active' : '' ?>" href="<?= h($base) ?>index.php">Alliances</a>
            <a class="nav-link <?= $active === 'players' ? 'is-active' : '' ?>" href="<?= h($base) ?>players.php">Players</a>
            <a class="nav-link nav-link-svs <?= $active === 'svs-4642' ? 'is-active' : '' ?>" href="<?= h($base) ?>state-4642.php">SvS 4642</a>
            <a
                class="nav-link nav-link-chat <?= $active === 'chat' ? 'is-active' : '' ?>"
                href="<?= h($base) ?>chat.php"
                target="_blank"
                rel="noopener noreferrer"
            >Live Chat</a>
            <a
                class="nav-link nav-link-report <?= $active === 'incidents' ? 'is-active' : '' ?>"
                href="<?= h($base) ?>incidents.php"
                target="_blank"
                rel="noopener noreferrer"
            >Report</a>
        </div>

        <div class="site-nav-links site-nav-desktop" aria-label="Desktop links">
            <?php if ($showAdmin): ?>
                <a class="nav-link nav-link-admin-btn <?= $active === 'admin' ? 'is-active' : '' ?>" href="<?= h($base) ?>admin/">Admin</a>
            <?php endif; ?>
            <a class="nav-link <?= $active === 'alliances' || $active === 'alliance' ? 'is-active' : '' ?>" href="<?= h($base) ?>index.php">Alliances</a>
            <a class="nav-link <?= $active === 'players' ? 'is-active' : '' ?>" href="<?= h($base) ?>players.php">Players</a>
            <a class="nav-link <?= $active === 'nap-rules' ? 'is-active' : '' ?>" href="<?= h($base) ?>nap-rules.php">Rules</a>
            <a class="nav-link <?= $active === 'announcements' ? 'is-active' : '' ?>" href="<?= h($base) ?>announcements.php">Announcements</a>
            <a class="nav-link nav-link-svs <?= $active === 'svs-4642' ? 'is-active' : '' ?>" href="<?= h($base) ?>state-4642.php">SvS 4642</a>
            <div class="nav-more <?= ($active === 'compare' || $active === 'threat' || $active === 'svs-4642') ? 'has-active' : '' ?>">
                <button
                    type="button"
                    class="nav-link nav-more-toggle <?= ($active === 'compare' || $active === 'threat') ? 'is-active' : '' ?>"
                    id="nav-more-toggle"
                    aria-expanded="false"
                    aria-controls="nav-more-menu"
                >More</button>
                <div class="nav-more-menu" id="nav-more-menu" hidden>
                    <a class="nav-more-item <?= $active === 'compare' ? 'is-active' : '' ?>" href="<?= h($base) ?>compare.php">Compare</a>
                    <a class="nav-more-item <?= $active === 'threat' ? 'is-active' : '' ?>" href="<?= h($base) ?>threat-board.php">Threat Metric</a>
                    <a class="nav-more-item <?= $active === 'svs-4642' ? 'is-active' : '' ?>" href="<?= h($base) ?>state-4642.php">State 4642</a>
                </div>
            </div>
            <a
                class="nav-link nav-link-chat <?= $active === 'chat' ? 'is-active' : '' ?>"
                href="<?= h($base) ?>chat.php"
                target="_blank"
                rel="noopener noreferrer"
            >Live Chat</a>
            <a
                class="nav-link nav-link-report <?= $active === 'incidents' ? 'is-active' : '' ?>"
                href="<?= h($base) ?>incidents.php"
                target="_blank"
                rel="noopener noreferrer"
            >Report</a>
            <a class="nav-link nav-link-nap-banned <?= $active === 'nap-banned' ? 'is-active' : '' ?>" href="<?= h($base) ?>nap-banned.php">NAP Banned</a>
            <a
                class="nav-link nav-link-admin-icon <?= $active === 'users' ? 'is-active' : '' ?>"
                href="<?= h($base) ?>users.php"
                title="Users"
                aria-label="Users"
            >
                <svg class="nav-admin-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                    <path fill="currentColor" d="M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12Zm0 2.25c-3.6 0-6.75 1.8-6.75 4.05V20h13.5v-1.7c0-2.25-3.15-4.05-6.75-4.05Z"/>
                </svg>
            </a>
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
                <?php if ($showAdmin): ?>
                    <a class="nav-drawer-link nav-drawer-link-admin <?= $active === 'admin' ? 'is-active' : '' ?>" href="<?= h($base) ?>admin/">Admin</a>
                <?php endif; ?>
                <a class="nav-drawer-link <?= $active === 'alliances' || $active === 'alliance' ? 'is-active' : '' ?>" href="<?= h($base) ?>index.php">Alliances</a>
                <a class="nav-drawer-link <?= $active === 'players' ? 'is-active' : '' ?>" href="<?= h($base) ?>players.php">Players</a>
                <a class="nav-drawer-link <?= $active === 'nap-rules' ? 'is-active' : '' ?>" href="<?= h($base) ?>nap-rules.php">Rules</a>
                <a class="nav-drawer-link <?= $active === 'announcements' ? 'is-active' : '' ?>" href="<?= h($base) ?>announcements.php">Announcements</a>
                <p class="nav-drawer-section">More</p>
                <a class="nav-drawer-link <?= $active === 'svs-4642' ? 'is-active' : '' ?>" href="<?= h($base) ?>state-4642.php">State 4642 · SvS</a>
                <a class="nav-drawer-link <?= $active === 'compare' ? 'is-active' : '' ?>" href="<?= h($base) ?>compare.php">Compare</a>
                <a class="nav-drawer-link <?= $active === 'threat' ? 'is-active' : '' ?>" href="<?= h($base) ?>threat-board.php">Threat Metric</a>
                <a class="nav-drawer-link <?= $active === 'chat' ? 'is-active' : '' ?>" href="<?= h($base) ?>chat.php" target="_blank" rel="noopener noreferrer">Live Chat</a>
                <a class="nav-drawer-link <?= $active === 'incidents' ? 'is-active' : '' ?>" href="<?= h($base) ?>incidents.php" target="_blank" rel="noopener noreferrer">Report</a>
                <a class="nav-drawer-link nav-drawer-link-banned <?= $active === 'nap-banned' ? 'is-active' : '' ?>" href="<?= h($base) ?>nap-banned.php">NAP Banned</a>
                <a class="nav-drawer-link <?= $active === 'users' ? 'is-active' : '' ?>" href="<?= h($base) ?>users.php">Users</a>
            </nav>
        </aside>
    </div>
    <script src="<?= assetUrl('assets/nav.js') ?>" defer></script>

    <?php if ($active !== 'chat'): ?>
    <div class="mobile-fab" id="mobile-fab">
        <div class="mobile-fab-backdrop" data-fab-close tabindex="-1"></div>
        <div class="mobile-fab-actions" id="mobile-fab-actions">
            <a class="mobile-fab-action" href="<?= h($base) ?>nap-rules.php" style="--fab-i: 3">
                <span class="mobile-fab-label">NAP Rules</span>
                <span class="mobile-fab-circle mobile-fab-circle-rules" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M7 3h10a2 2 0 0 1 2 2v16l-7-3-7 3V5a2 2 0 0 1 2-2Zm2 4v2h6V7H9Zm0 4v2h6v-2H9Zm0 4v2h4v-2H9Z"/></svg>
                </span>
            </a>
            <a class="mobile-fab-action" href="<?= h($base) ?>announcements.php" style="--fab-i: 2">
                <span class="mobile-fab-label">Announcements</span>
                <span class="mobile-fab-circle mobile-fab-circle-ann" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M4 5h12v10H6l-2 2V5Zm14-1h2v16h-2V4ZM6 17h10v2H6v-2Z"/></svg>
                </span>
            </a>
            <a class="mobile-fab-action" href="<?= h($fabAccountHref) ?>" style="--fab-i: 1">
                <span class="mobile-fab-label"><?= h($fabAccountLabel) ?></span>
                <span class="mobile-fab-circle mobile-fab-circle-auth" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12Zm0 2.25c-3.6 0-6.75 1.8-6.75 4.05V20h13.5v-1.7c0-2.25-3.15-4.05-6.75-4.05Z"/></svg>
                </span>
            </a>
        </div>
        <button
            type="button"
            class="mobile-fab-main"
            id="mobile-fab-toggle"
            aria-expanded="false"
            aria-controls="mobile-fab-actions"
            aria-label="Open quick options"
        >
            <svg class="mobile-fab-icon mobile-fab-icon-menu" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
                <path fill="currentColor" d="M4 7.5A1.5 1.5 0 0 1 5.5 6h13a1.5 1.5 0 0 1 0 3h-13A1.5 1.5 0 0 1 4 7.5Zm0 4.5A1.5 1.5 0 0 1 5.5 10.5h13a1.5 1.5 0 0 1 0 3h-13A1.5 1.5 0 0 1 4 12Zm0 4.5A1.5 1.5 0 0 1 5.5 15h13a1.5 1.5 0 0 1 0 3h-13A1.5 1.5 0 0 1 4 16.5Z"/>
            </svg>
            <svg class="mobile-fab-icon mobile-fab-icon-close" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
                <path fill="currentColor" d="M6.7 6.7a1 1 0 0 1 1.4 0L12 10.6l3.9-3.9a1 1 0 1 1 1.4 1.4L13.4 12l3.9 3.9a1 1 0 1 1-1.4 1.4L12 13.4l-3.9 3.9a1 1 0 0 1-1.4-1.4L10.6 12 6.7 8.1a1 1 0 0 1 0-1.4Z"/>
            </svg>
        </button>
    </div>
    <?php endif; ?>

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
        if (!file_exists(SITE_VISITS_FILE)) {
            saveJson(SITE_VISITS_FILE, ['pages' => [], 'visitors' => []]);
        }
    }

    if (!file_exists(SITE_VISITS_FILE)) {
        // Prefer migrating older SvS-only JSON if present.
        $legacy = DATA_DIR . '/svs-visits.json';
        if (is_file($legacy)) {
            $legacyData = loadJson($legacy, ['pages' => []]);
            if (!isset($legacyData['visitors']) || !is_array($legacyData['visitors'])) {
                $legacyData['visitors'] = [];
            }
            saveJson(SITE_VISITS_FILE, $legacyData);
        } else {
            saveJson(SITE_VISITS_FILE, ['pages' => [], 'visitors' => []]);
        }
    }

    if (!file_exists(SITE_GEO_CACHE_FILE) && !dbAvailable()) {
        saveJson(SITE_GEO_CACHE_FILE, ['entries' => []]);
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
        'power' => isset($member['power']) && $member['power'] !== null && $member['power'] !== ''
            ? (int) $member['power']
            : null,
        'seen_at' => !empty($member['seen_at']) ? (string) $member['seen_at'] : null,
    ];
}

/**
 * Compact "X ago" label for a UTC datetime string.
 */
function formatTimeAgo(?string $datetime): string
{
    $ts = $datetime !== null && $datetime !== '' ? strtotime($datetime . ' UTC') : false;
    if ($ts === false) {
        return 'unknown';
    }
    $diff = max(0, time() - $ts);
    if ($diff < 3600) {
        return max(1, intdiv($diff, 60)) . 'm ago';
    }
    if ($diff < 86400) {
        return intdiv($diff, 3600) . 'h ago';
    }

    return intdiv($diff, 86400) . 'd ago';
}

function rosterTimestamp(mixed $value): ?int
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    $ts = strtotime($value . ' UTC');

    return $ts === false ? null : $ts;
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
            'alliance_id' => $player['alliance_id'] ?? ($prev['alliance_id'] ?? null),
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

        // Freshest wins: live roster value (seen_at) > roster-powers only when newer (fetched_at) > state top 200.
        // Legacy import rows carry a placeholder seen_at until a refresh confirms them.
        $seenTs = empty($member['unconfirmed']) ? rosterTimestamp($member['seen_at'] ?? null) : null;
        $savedTs = rosterTimestamp($saved['fetched_at'] ?? null);
        $savedIsNewer = $savedTs !== null && ($seenTs === null || $savedTs > $seenTs);
        $updatedTs = null;

        $power = null;
        $memberPower = isset($member['power']) && $member['power'] !== null && $member['power'] !== '' ? (int) $member['power'] : null;
        $savedPower = isset($saved['power']) && $saved['power'] !== null && $saved['power'] !== '' ? (int) $saved['power'] : null;
        if ($savedPower !== null && ($savedIsNewer || $memberPower === null)) {
            $power = $savedPower;
            $updatedTs = $savedTs;
        } elseif ($memberPower !== null) {
            $power = $memberPower;
            $updatedTs = $seenTs;
        } elseif (isset($statePlayer['power'])) {
            $power = (int) $statePlayer['power'];
        }

        $jump = null;
        if (isset($saved['last_jump_pct']) && $saved['last_jump_pct'] !== null && $saved['last_jump_pct'] !== '') {
            $jump = (float) $saved['last_jump_pct'];
        } elseif (isset($statePlayer['last_jump_pct'])) {
            $jump = (float) $statePlayer['last_jump_pct'];
        }

        if ($savedIsNewer && isset($saved['stove_lv']) && $saved['stove_lv'] !== null && $saved['stove_lv'] !== '') {
            $member['lv'] = (int) $saved['stove_lv'];
        }
        $freshest = max($seenTs ?? 0, $savedTs ?? 0, $updatedTs ?? 0);
        $updatedAt = $freshest > 0 ? gmdate('Y-m-d H:i:s', $freshest) : null;

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
            'updated_at' => $updatedAt,
            'is_stale' => $updatedAt === null || (time() - $freshest) > ROSTER_STALE_DISPLAY_SECONDS,
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

/**
 * Find a NAP-banned entry by player UID / FID and/or name.
 * Ban list entries may store either Atlas uid or fid.
 *
 * @param string|list<string>|null $playerIds
 * @return array<string, mixed>|null
 */
function findNapBannedPlayer(string|array|null $playerIds = null, ?string $playerName = null): ?array
{
    $ids = [];
    if (is_array($playerIds)) {
        foreach ($playerIds as $id) {
            $clean = preg_replace('/\D+/', '', (string) $id) ?? '';
            if ($clean !== '') {
                $ids[$clean] = true;
            }
        }
    } else {
        $clean = preg_replace('/\D+/', '', (string) $playerIds) ?? '';
        if ($clean !== '') {
            $ids[$clean] = true;
        }
    }

    $playerName = trim((string) $playerName);
    $nameKey = $playerName !== '' ? mb_strtolower($playerName) : '';

    foreach (loadNapBannedPlayers() as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entryId = preg_replace('/\D+/', '', (string) ($entry['player_id'] ?? '')) ?? '';
        if ($entryId !== '' && isset($ids[$entryId])) {
            return $entry;
        }
        $entryName = trim((string) ($entry['name'] ?? ''));
        if ($nameKey !== '' && $entryName !== '' && mb_strtolower($entryName) === $nameKey) {
            return $entry;
        }
    }

    return null;
}

/**
 * Resolve alliance display name from local alliances board by id or tag.
 */
function resolveAllianceName(?string $allianceId = null, ?string $allianceTag = null): ?string
{
    $allianceId = trim((string) $allianceId);
    $allianceTag = trim((string) $allianceTag);
    if ($allianceId === '' && $allianceTag === '') {
        return null;
    }

    $alliances = loadJson(ALLIANCES_FILE, []);
    if (!is_array($alliances)) {
        return null;
    }

    foreach ($alliances as $alliance) {
        if (!is_array($alliance)) {
            continue;
        }
        $id = trim((string) ($alliance['id'] ?? ''));
        $tag = trim((string) ($alliance['tag'] ?? ''));
        if ($allianceId !== '' && $id !== '' && $id === $allianceId) {
            $name = trim((string) ($alliance['name'] ?? ''));
            return $name !== '' ? $name : ($tag !== '' ? $tag : null);
        }
        if ($allianceTag !== '' && $tag !== '' && strcasecmp($tag, $allianceTag) === 0) {
            $name = trim((string) ($alliance['name'] ?? ''));
            return $name !== '' ? $name : $tag;
        }
    }

    return null;
}

/**
 * Password for NAP banned admin actions from config.json key
 * "nap_banned_password". Empty/missing => fail closed (deny).
 */
function napBannedPassword(): string
{
    try {
        $config = loadConfig();
    } catch (Throwable $e) {
        return '';
    }

    return trim((string) ($config['nap_banned_password'] ?? ''));
}

function verifyNapBannedPassword(string $password): bool
{
    $expected = napBannedPassword();
    if ($expected === '') {
        return false; // fail closed when unset
    }

    return hash_equals($expected, $password);
}

/**
 * Refresh current alliance + coordinates for NAP-banned players (max once/day).
 * Saves live fields onto each nap-banned entry when Atlas lookup succeeds.
 *
 * @param list<array<string, mixed>> $list
 * @return array{
 *   list: list<array<string, mixed>>,
 *   refreshed: int,
 *   cached: int,
 *   failed: int,
 *   skipped: int
 * }
 */
function enrichNapBannedPlayersLiveInfo(array $list, array $config, int $ttlSeconds = 86400): array
{
    require_once __DIR__ . '/player-lookup.php';

    $now = time();
    $refreshed = 0;
    $cached = 0;
    $failed = 0;
    $skipped = 0;
    $changed = false;

    foreach ($list as &$entry) {
        if (!is_array($entry)) {
            continue;
        }

        $playerId = preg_replace('/\D+/', '', (string) ($entry['player_id'] ?? '')) ?? '';
        if ($playerId === '') {
            $skipped++;
            continue;
        }

        $fetchedAt = isset($entry['live_fetched_at']) ? strtotime((string) $entry['live_fetched_at']) : false;
        // live_detail_v=2 includes /v1/players/{uid} players[] coord merge.
        $fresh = $fetchedAt !== false
            && ($now - $fetchedAt) < $ttlSeconds
            && !empty($entry['live_detail'])
            && (int) ($entry['live_detail_v'] ?? 0) >= 2
            && (
                array_key_exists('alliance_tag', $entry)
                || array_key_exists('x', $entry)
                || array_key_exists('y', $entry)
            );

        if ($fresh) {
            $cached++;
            // Keep alliance_name in sync with local alliances board when possible.
            if (empty($entry['alliance_name'])) {
                $resolved = resolveAllianceName(
                    isset($entry['alliance_id']) ? (string) $entry['alliance_id'] : null,
                    isset($entry['alliance_tag']) ? (string) $entry['alliance_tag'] : null
                );
                if ($resolved !== null) {
                    $entry['alliance_name'] = $resolved;
                    $changed = true;
                }
            }
            continue;
        }

        try {
            $live = lookupPlayerByUid($playerId, $config);
            if (!is_array($live)) {
                $failed++;
                continue;
            }
            $live = enrichPlayerLookupWithLocalData($live);

            // If search resolved a different Atlas uid (FID → uid), detail-fetch that uid too.
            $resolvedUid = trim((string) ($live['id'] ?? ''));
            if ($resolvedUid !== '' && $resolvedUid !== $playerId) {
                $detailOnly = lookupPlayerByUid($resolvedUid, $config);
                if (is_array($detailOnly)) {
                    foreach (['x', 'y', 'alliance_id', 'alliance_tag', 'power', 'stove_lv', 'name', 'fid', 'kid', 'lv'] as $key) {
                        if (($detailOnly[$key] ?? null) !== null && $detailOnly[$key] !== '') {
                            $live[$key] = $detailOnly[$key];
                        }
                    }
                    if (($live['x'] ?? null) !== null && ($live['y'] ?? null) !== null) {
                        $live['coord_source'] = $detailOnly['coord_source'] ?? 'atlas';
                    }
                }
            }

            $live = enrichPlayerLookupWithLocalData($live);

            $tag = trim((string) ($live['alliance_tag'] ?? ''));
            $aid = trim((string) ($live['alliance_id'] ?? ''));
            $allianceName = resolveAllianceName(
                $aid !== '' ? $aid : null,
                $tag !== '' ? $tag : null
            );

            $entry['live_uid'] = isset($live['id']) && $live['id'] !== '' ? (string) $live['id'] : null;
            $entry['live_fid'] = isset($live['fid']) && $live['fid'] !== '' ? (string) $live['fid'] : null;
            $entry['live_name'] = isset($live['name']) && $live['name'] !== '' ? (string) $live['name'] : null;
            $entry['alliance_id'] = $aid !== '' ? $aid : null;
            $entry['alliance_tag'] = $tag !== '' ? $tag : null;
            $entry['alliance_name'] = $allianceName;
            $entry['x'] = isset($live['x']) ? (int) $live['x'] : null;
            $entry['y'] = isset($live['y']) ? (int) $live['y'] : null;
            $entry['live_fetched_at'] = nowUtc();
            $entry['live_detail'] = true;
            // Bump so older incomplete caches re-fetch once after coord-fix deploys.
            $entry['live_detail_v'] = 2;
            // If the ban entry had no name, fill from live lookup.
            if (empty($entry['name']) && !empty($live['name'])) {
                $entry['name'] = (string) $live['name'];
            }

            $changed = true;
            $refreshed++;
        } catch (Throwable $e) {
            $failed++;
        }
    }
    unset($entry);

    if ($changed) {
        saveJson(NAP_BANNED_FILE, array_values($list));
    }

    return [
        'list' => array_values($list),
        'refreshed' => $refreshed,
        'cached' => $cached,
        'failed' => $failed,
        'skipped' => $skipped,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: bool, error?: string, entry?: array<string, mixed>}
 */
function addNapBannedPlayer(array $input, bool $skipPassword = false): array
{
    if (!$skipPassword) {
        $password = (string) ($input['password'] ?? '');
        if (!verifyNapBannedPassword($password)) {
            return ['ok' => false, 'error' => 'Wrong password.'];
        }
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
function removeNapBannedPlayer(string $entryId, string $password = '', bool $skipPassword = false): array
{
    if (!$skipPassword && !verifyNapBannedPassword($password)) {
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

function isAppAdmin(?array $user = null): bool
{
    if ($user === null) {
        $user = currentAppUser();
    }
    if ($user === null) {
        return false;
    }
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    if ($email !== '' && $email === strtolower(APP_ADMIN_EMAIL)) {
        return true;
    }

    $username = normalizeAppUsername((string) ($user['username'] ?? ''));
    if ($username === '') {
        return false;
    }

    foreach (APP_ADMIN_USERNAMES as $adminUsername) {
        if ($username === normalizeAppUsername((string) $adminUsername)) {
            return true;
        }
    }

    return false;
}

/** Whether this admin may open the visitor tracking page. */
function canViewVisitorTracking(?array $user = null): bool
{
    if ($user === null) {
        $user = currentAppUser();
    }
    if ($user === null || !isAppAdmin($user)) {
        return false;
    }

    $username = normalizeAppUsername((string) ($user['username'] ?? ''));
    if ($username === '') {
        return true;
    }

    foreach (APP_ADMIN_VISITS_DENIED_USERNAMES as $denied) {
        if ($username === normalizeAppUsername((string) $denied)) {
            return false;
        }
    }

    return true;
}

/**
 * @return array{id: string, email: ?string, display_name: string, photo_url: ?string}
 */
function requireAppAdminPage(): array
{
    $user = currentAppUser();
    if ($user === null) {
        return [];
    }
    if (!isAppAdmin($user)) {
        http_response_code(403);
    }

    return $user;
}

/**
 * @return list<array{alliance_key: string, alliance_id: ?string, alliance_tag: ?string, alliance_name: ?string, mode: string, updated_at: string}>
 */
function loadNapMembershipOverrides(): array
{
    if (!dbAvailable()) {
        return [];
    }
    dbEnsureSchema();
    $rows = db()->query(
        'SELECT alliance_key, alliance_id, alliance_tag, alliance_name, mode, updated_at
         FROM nap_membership ORDER BY alliance_tag ASC, alliance_key ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $mode = strtolower(trim((string) ($row['mode'] ?? '')));
        if ($mode !== 'include' && $mode !== 'exclude') {
            continue;
        }
        $out[] = [
            'alliance_key' => (string) $row['alliance_key'],
            'alliance_id' => $row['alliance_id'] !== null ? (string) $row['alliance_id'] : null,
            'alliance_tag' => $row['alliance_tag'] !== null ? (string) $row['alliance_tag'] : null,
            'alliance_name' => $row['alliance_name'] !== null ? (string) $row['alliance_name'] : null,
            'mode' => $mode,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $out;
}

function napMembershipModeForAlliance(array $alliance): ?string
{
    $id = trim((string) ($alliance['id'] ?? ''));
    $tag = trim((string) ($alliance['tag'] ?? ''));
    $key = trim(allianceKey($alliance));

    // Prefer exact alliance id (unique). Tags like WaS vs wAs must stay case-sensitive.
    foreach (loadNapMembershipOverrides() as $row) {
        $rowId = trim((string) ($row['alliance_id'] ?? ''));
        $rowKey = trim((string) ($row['alliance_key'] ?? ''));
        if ($id !== '' && ($rowId === $id || $rowKey === $id)) {
            return $row['mode'];
        }
        if ($key !== '' && $rowKey === $key) {
            return $row['mode'];
        }
    }

    foreach (loadNapMembershipOverrides() as $row) {
        $rowTag = trim((string) ($row['alliance_tag'] ?? ''));
        if ($tag !== '' && $rowTag !== '' && $rowTag === $tag) {
            return $row['mode'];
        }
    }

    return null;
}

/**
 * @return array{ok: bool, error?: string}
 */
function setNapMembershipOverride(array $alliance, string $mode): array
{
    $mode = strtolower(trim($mode));
    if ($mode !== 'include' && $mode !== 'exclude') {
        return ['ok' => false, 'error' => 'Mode must be include or exclude.'];
    }
    if (!dbAvailable()) {
        return ['ok' => false, 'error' => 'Database unavailable.'];
    }
    dbEnsureSchema();

    $key = allianceKey($alliance);
    if ($key === '') {
        return ['ok' => false, 'error' => 'Alliance key missing.'];
    }

    $stmt = db()->prepare(
        'INSERT INTO nap_membership (alliance_key, alliance_id, alliance_tag, alliance_name, mode, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           alliance_id = VALUES(alliance_id),
           alliance_tag = VALUES(alliance_tag),
           alliance_name = VALUES(alliance_name),
           mode = VALUES(mode),
           updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        $key,
        isset($alliance['id']) ? (string) $alliance['id'] : null,
        isset($alliance['tag']) ? (string) $alliance['tag'] : null,
        isset($alliance['name']) ? (string) $alliance['name'] : null,
        $mode,
        gmdate('Y-m-d H:i:s'),
    ]);

    return ['ok' => true];
}

/**
 * @return array{ok: bool, error?: string}
 */
function clearNapMembershipOverride(string $allianceKey): array
{
    $allianceKey = trim($allianceKey);
    if ($allianceKey === '') {
        return ['ok' => false, 'error' => 'Alliance key required.'];
    }
    if (!dbAvailable()) {
        return ['ok' => false, 'error' => 'Database unavailable.'];
    }
    dbEnsureSchema();
    $stmt = db()->prepare('DELETE FROM nap_membership WHERE alliance_key = ?');
    $stmt->execute([$allianceKey]);

    return ['ok' => true];
}

/**
 * @return list<array{id: int, sort_order: int, title: string, body: string, is_published: bool, created_at: string, updated_at: string}>
 */
function loadNapRules(bool $publishedOnly = false): array
{
    if (!dbAvailable()) {
        return [];
    }
    dbEnsureSchema();
    $sql = 'SELECT id, sort_order, title, body, is_published, created_at, updated_at FROM nap_rules';
    if ($publishedOnly) {
        $sql .= ' WHERE is_published = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'id' => (int) $row['id'],
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'is_published' => !empty($row['is_published']),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @return array{ok: bool, error?: string, id?: int}
 */
function saveNapRule(array $input, ?int $id = null): array
{
    if (!dbAvailable()) {
        return ['ok' => false, 'error' => 'Database unavailable.'];
    }
    dbEnsureSchema();

    $title = trim((string) ($input['title'] ?? ''));
    $body = trim((string) ($input['body'] ?? ''));
    $sort = (int) ($input['sort_order'] ?? 0);
    $published = !empty($input['is_published']) ? 1 : 0;
    if ($title === '') {
        return ['ok' => false, 'error' => 'Title is required.'];
    }
    if ($body === '') {
        return ['ok' => false, 'error' => 'Rule text is required.'];
    }

    $now = gmdate('Y-m-d H:i:s');
    $pdo = db();
    if ($id !== null && $id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE nap_rules SET sort_order = ?, title = ?, body = ?, is_published = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$sort, $title, $body, $published, $now, $id]);
        return ['ok' => true, 'id' => $id];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO nap_rules (sort_order, title, body, is_published, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$sort, $title, $body, $published, $now, $now]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

/**
 * @return array{ok: bool, error?: string}
 */
function deleteNapRule(int $id): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid rule id.'];
    }
    if (!dbAvailable()) {
        return ['ok' => false, 'error' => 'Database unavailable.'];
    }
    dbEnsureSchema();
    db()->prepare('DELETE FROM nap_rules WHERE id = ?')->execute([$id]);

    return ['ok' => true];
}

/**
 * @return list<array{id: int, title: string, body: string, is_published: bool, created_at: string, updated_at: string}>
 */
function loadAnnouncements(bool $publishedOnly = false): array
{
    if (!dbAvailable()) {
        return [];
    }
    dbEnsureSchema();
    $sql = 'SELECT id, title, body, is_published, created_at, updated_at FROM announcements';
    if ($publishedOnly) {
        $sql .= ' WHERE is_published = 1';
    }
    $sql .= ' ORDER BY created_at DESC, id DESC';
    $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'id' => (int) $row['id'],
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'is_published' => !empty($row['is_published']),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @return array{ok: bool, error?: string, id?: int}
 */
function saveAnnouncement(array $input, ?int $id = null): array
{
    if (!dbAvailable()) {
        return ['ok' => false, 'error' => 'Database unavailable.'];
    }
    dbEnsureSchema();

    $title = trim((string) ($input['title'] ?? ''));
    $body = trim((string) ($input['body'] ?? ''));
    $published = !empty($input['is_published']) ? 1 : 0;
    if ($title === '') {
        return ['ok' => false, 'error' => 'Title is required.'];
    }
    if ($body === '') {
        return ['ok' => false, 'error' => 'Announcement text is required.'];
    }

    $now = gmdate('Y-m-d H:i:s');
    $pdo = db();
    if ($id !== null && $id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE announcements SET title = ?, body = ?, is_published = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$title, $body, $published, $now, $id]);
        return ['ok' => true, 'id' => $id];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO announcements (title, body, is_published, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$title, $body, $published, $now, $now]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

/**
 * @return array{ok: bool, error?: string}
 */
function deleteAnnouncement(int $id): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid announcement id.'];
    }
    if (!dbAvailable()) {
        return ['ok' => false, 'error' => 'Database unavailable.'];
    }
    dbEnsureSchema();
    db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);

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
    $stmt = db()->prepare('SELECT id, email, username, display_name, photo_url FROM app_users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (string) $row['id'],
        'email' => $row['email'] !== null ? (string) $row['email'] : null,
        'username' => $row['username'] !== null ? (string) $row['username'] : null,
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
           photo_url = VALUES(photo_url),
           last_seen_at = VALUES(last_seen_at)'
    );
    $stmt->execute([$id, $email, $name, $photo, $now, $now]);

    $user = findAppUserById($id);
    if ($user !== null) {
        return $user;
    }

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

/**
 * Normalize a local login username (case-insensitive unique).
 */
function normalizeAppUsername(string $username): string
{
    $username = trim($username);
    $username = preg_replace('/\s+/u', '', $username) ?? $username;

    return mb_strtolower($username);
}

/**
 * Create or update a manually provisioned username/password account (no public signup).
 *
 * @return array{ok: bool, error?: string, user?: array{id: string, email: ?string, display_name: string, photo_url: ?string, username: ?string}}
 */
function upsertLocalAppUser(string $username, string $password, ?string $displayName = null): array
{
    $rawUsername = trim($username);
    $usernameKey = normalizeAppUsername($rawUsername);
    if ($usernameKey === '' || mb_strlen($usernameKey) < 2) {
        return ['ok' => false, 'error' => 'Username is too short.'];
    }
    if (mb_strlen($usernameKey) > 64) {
        return ['ok' => false, 'error' => 'Username is too long.'];
    }
    if (!preg_match('/^[a-z0-9._-]+$/i', $usernameKey)) {
        return ['ok' => false, 'error' => 'Username may only use letters, numbers, dot, underscore, or hyphen.'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }
    if (!dbAvailable()) {
        return ['ok' => false, 'error' => 'Database unavailable.'];
    }

    dbEnsureSchema();
    $display = trim((string) $displayName);
    if ($display === '') {
        $display = $rawUsername;
    }
    $display = mb_substr($display, 0, 120);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        return ['ok' => false, 'error' => 'Could not hash password.'];
    }

    $now = gmdate('Y-m-d H:i:s');
    $pdo = db();

    $existing = findAppUserByUsername($usernameKey);
    if ($existing !== null) {
        $stmt = $pdo->prepare(
            'UPDATE app_users
             SET username = ?, password_hash = ?, display_name = ?, last_seen_at = ?
             WHERE id = ?'
        );
        $stmt->execute([$usernameKey, $hash, $display, $now, $existing['id']]);
        $user = findAppUserById($existing['id']);
        if ($user === null) {
            return ['ok' => false, 'error' => 'Could not update user.'];
        }
        $user['username'] = $usernameKey;

        return ['ok' => true, 'user' => $user];
    }

    $id = 'local_' . preg_replace('/[^a-z0-9._-]+/i', '_', $usernameKey);
    $id = mb_substr($id, 0, 64);
    // Avoid colliding with a Google sub that somehow matches.
    $clash = findAppUserById($id);
    if ($clash !== null && empty($clash['username'])) {
        $id = mb_substr('local_' . $usernameKey . '_' . bin2hex(random_bytes(3)), 0, 64);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO app_users (id, email, username, password_hash, display_name, photo_url, created_at, last_seen_at)
         VALUES (?, NULL, ?, ?, ?, NULL, ?, ?)'
    );
    $stmt->execute([$id, $usernameKey, $hash, $display, $now, $now]);

    $user = findAppUserById($id);
    if ($user === null) {
        return ['ok' => false, 'error' => 'Could not create user.'];
    }
    $user['username'] = $usernameKey;

    return ['ok' => true, 'user' => $user];
}

/**
 * @return array{id: string, email: ?string, display_name: string, photo_url: ?string, username: ?string}|null
 */
function findAppUserByUsername(string $username): ?array
{
    $username = normalizeAppUsername($username);
    if ($username === '' || !dbAvailable()) {
        return null;
    }
    dbEnsureSchema();
    $stmt = db()->prepare(
        'SELECT id, email, username, display_name, photo_url
         FROM app_users
         WHERE username = ?
         LIMIT 1'
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (string) $row['id'],
        'email' => $row['email'] !== null ? (string) $row['email'] : null,
        'username' => $row['username'] !== null ? (string) $row['username'] : null,
        'display_name' => (string) ($row['display_name'] ?? 'User'),
        'photo_url' => $row['photo_url'] !== null ? (string) $row['photo_url'] : null,
    ];
}

/**
 * Verify a local username/password login.
 *
 * @return array{ok: bool, error?: string, user?: array{id: string, email: ?string, display_name: string, photo_url: ?string, username: ?string}}
 */
function loginLocalAppUser(string $username, string $password): array
{
    $username = normalizeAppUsername($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'Enter username and password.'];
    }
    if (!dbAvailable()) {
        return ['ok' => false, 'error' => 'Database unavailable.'];
    }

    dbEnsureSchema();
    $stmt = db()->prepare(
        'SELECT id, email, username, password_hash, display_name, photo_url
         FROM app_users
         WHERE username = ?
         LIMIT 1'
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || empty($row['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }
    if (!password_verify($password, (string) $row['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    $now = gmdate('Y-m-d H:i:s');
    db()->prepare('UPDATE app_users SET last_seen_at = ? WHERE id = ?')->execute([$now, $row['id']]);

    $user = [
        'id' => (string) $row['id'],
        'email' => $row['email'] !== null ? (string) $row['email'] : null,
        'username' => $row['username'] !== null ? (string) $row['username'] : null,
        'display_name' => (string) ($row['display_name'] ?? 'User'),
        'photo_url' => $row['photo_url'] !== null ? (string) $row['photo_url'] : null,
    ];

    return ['ok' => true, 'user' => $user];
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
function listAppUsers(?string $excludeId = null, bool $includeEmail = false): array
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
            'email' => $includeEmail && $row['email'] !== null ? (string) $row['email'] : null,
            'display_name' => (string) ($row['display_name'] ?? 'User'),
            'photo_url' => $row['photo_url'] !== null ? (string) $row['photo_url'] : null,
            'last_seen_at' => $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null,
        ];
    }

    return $out;
}

/**
 * @return array{ok: bool, error?: string, user?: array{id: string, email: ?string, display_name: string, photo_url: ?string}}
 */
function updateAppUserDisplayName(string $userId, string $displayName): array
{
    $userId = trim($userId);
    $name = trim($displayName);
    if ($userId === '') {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    if ($name === '') {
        return ['ok' => false, 'error' => 'Name cannot be empty.'];
    }
    if (mb_strlen($name) < 2) {
        return ['ok' => false, 'error' => 'Name is too short.'];
    }
    if (mb_strlen($name) > 120) {
        return ['ok' => false, 'error' => 'Name is too long.'];
    }
    if (!dbAvailable()) {
        return ['ok' => false, 'error' => 'Database unavailable.'];
    }
    dbEnsureSchema();

    $stmt = db()->prepare('UPDATE app_users SET display_name = ? WHERE id = ?');
    $stmt->execute([mb_substr($name, 0, 120), $userId]);

    $user = findAppUserById($userId);
    if ($user === null) {
        return ['ok' => false, 'error' => 'User not found.'];
    }

    return ['ok' => true, 'user' => $user];
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

/**
 * Load cached SvS opponent scout payload (alliances + players), refreshing from Atlas v2 when stale
 * (/servers/{kid}/alliances + power.rank/furnace.rank boards + top-20 members per alliance).
 *
 * @return array{
 *   kid: string,
 *   fetched_at: string,
 *   alliances: list<array<string, mixed>>,
 *   players: list<array<string, mixed>>,
 *   source: string,
 *   error: ?string
 * }
 */
function loadSvsOpponentState(string $kid = SVS_OPPONENT_KID, bool $forceRefresh = false): array
{
    $cache = loadJson(SVS_OPPONENT_CACHE_FILE, null);
    $fresh = false;
    if (is_array($cache) && !$forceRefresh) {
        $fetchedAt = strtotime((string) ($cache['fetched_at'] ?? '')) ?: 0;
        if ($fetchedAt > 0 && (time() - $fetchedAt) < SVS_OPPONENT_CACHE_TTL) {
            $fresh = true;
        }
    }

    if ($fresh && is_array($cache)) {
        return [
            'kid' => (string) ($cache['kid'] ?? $kid),
            'fetched_at' => (string) ($cache['fetched_at'] ?? ''),
            'alliances' => is_array($cache['alliances'] ?? null) ? $cache['alliances'] : [],
            'players' => is_array($cache['players'] ?? null) ? $cache['players'] : [],
            'source' => 'cache',
            'error' => null,
        ];
    }

    try {
        require_once __DIR__ . '/collector.php';
        require_once __DIR__ . '/player-lookup.php';

        $config = loadConfig();
        $alliances = fetchAlliances($kid);
        $players = rankPlayersByPower(fetchPlayers($kid));
        $alliances = enrichAlliancesMaxPowerFromPlayers($alliances, $players);

        // Atlas v2: boards/members carry alliance_tag, and /members is capped at the top 20.
        // 1) Match unique max_power → alliance tag.
        // 2) Detail-lookup top whales for reliable tags (SvS scout value).
        $maxPowerToTag = [];
        foreach ($alliances as $alliance) {
            if (!is_array($alliance)) {
                continue;
            }
            $max = isset($alliance['max_power']) ? (int) $alliance['max_power'] : 0;
            $tag = trim((string) ($alliance['tag'] ?? ''));
            $aid = trim((string) ($alliance['id'] ?? ''));
            if ($max <= 0 || $tag === '') {
                continue;
            }
            if (!isset($maxPowerToTag[$max])) {
                $maxPowerToTag[$max] = ['alliance_tag' => $tag, 'alliance_id' => $aid !== '' ? $aid : null];
            } else {
                $maxPowerToTag[$max] = null; // ambiguous
            }
        }
        foreach ($players as &$player) {
            if (!is_array($player)) {
                continue;
            }
            if (!empty($player['alliance_tag'])) {
                continue;
            }
            $power = isset($player['power']) ? (int) $player['power'] : 0;
            if ($power > 0 && isset($maxPowerToTag[$power]) && is_array($maxPowerToTag[$power])) {
                $player['alliance_tag'] = $maxPowerToTag[$power]['alliance_tag'];
                if (!empty($maxPowerToTag[$power]['alliance_id'])) {
                    $player['alliance_id'] = $maxPowerToTag[$power]['alliance_id'];
                }
            }
        }
        unset($player);

        $taggedViaDetail = 0;
        foreach (array_slice($players, 0, 20) as $idx => $player) {
            if (!is_array($player)) {
                continue;
            }
            if (!empty($players[$idx]['alliance_tag'])) {
                continue;
            }
            $uid = trim((string) ($player['id'] ?? ''));
            if ($uid === '' || preg_match('/^\d+$/', $uid) !== 1) {
                continue;
            }
            try {
                $detail = lookupPlayerByUid($uid, $config);
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($detail)) {
                continue;
            }
            $tag = trim((string) ($detail['alliance_tag'] ?? ''));
            $aid = trim((string) ($detail['alliance_id'] ?? ''));
            if ($tag !== '') {
                $players[$idx]['alliance_tag'] = $tag;
                $taggedViaDetail++;
            }
            if ($aid !== '') {
                $players[$idx]['alliance_id'] = $aid;
            }
        }
        $alliances = enrichAlliancesMaxPowerFromPlayers($alliances, $players);

        $payload = [
            'kid' => $kid,
            'fetched_at' => nowUtc(),
            'alliances' => $alliances,
            'players' => $players,
            'tagged_via_detail' => $taggedViaDetail,
        ];
        saveJson(SVS_OPPONENT_CACHE_FILE, $payload);

        return [
            'kid' => $kid,
            'fetched_at' => $payload['fetched_at'],
            'alliances' => $alliances,
            'players' => $players,
            'source' => 'live',
            'error' => null,
        ];
    } catch (Throwable $e) {
        if (is_array($cache) && is_array($cache['players'] ?? null) && $cache['players'] !== []) {
            return [
                'kid' => (string) ($cache['kid'] ?? $kid),
                'fetched_at' => (string) ($cache['fetched_at'] ?? ''),
                'alliances' => is_array($cache['alliances'] ?? null) ? $cache['alliances'] : [],
                'players' => $cache['players'],
                'source' => 'stale-cache',
                'error' => $e->getMessage(),
            ];
        }

        return [
            'kid' => $kid,
            'fetched_at' => '',
            'alliances' => [],
            'players' => [],
            'source' => 'error',
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Aggregate SvS-relevant metrics for one state from alliance + player boards.
 *
 * @param list<array<string, mixed>> $alliances
 * @param list<array<string, mixed>> $players
 * @return array<string, mixed>
 */
function buildSvsStateProfile(string $kid, array $alliances, array $players): array
{
    $players = rankPlayersByPower($players);
    $alliances = enrichAlliancesMaxPowerFromPlayers($alliances, $players);
    usort($alliances, static fn(array $a, array $b): int => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));

    $powers = [];
    $furnaces = [];
    foreach ($players as $player) {
        if (!is_array($player)) {
            continue;
        }
        if (isset($player['power'])) {
            $powers[] = (int) $player['power'];
        }
        if (isset($player['stove_lv'])) {
            $furnaces[] = (int) $player['stove_lv'];
        }
    }

    $sumTop = static function (array $arr, int $n): int {
        return (int) array_sum(array_slice($arr, 0, min($n, count($arr))));
    };
    $medianOf = static function (array $arr): ?int {
        $c = count($arr);
        if ($c === 0) {
            return null;
        }
        $sorted = $arr;
        sort($sorted);
        $mid = intdiv($c, 2);
        return $c % 2 === 1 ? $sorted[$mid] : (int) round(($sorted[$mid - 1] + $sorted[$mid]) / 2);
    };
    $countAtLeast = static function (array $arr, int $threshold): int {
        $n = 0;
        foreach ($arr as $v) {
            if ((int) $v >= $threshold) {
                $n++;
            }
        }
        return $n;
    };

    $furnaceBuckets = [];
    foreach ($furnaces as $lv) {
        $key = (string) $lv;
        $furnaceBuckets[$key] = ($furnaceBuckets[$key] ?? 0) + 1;
    }
    ksort($furnaceBuckets, SORT_NUMERIC);

    $top100Powers = array_slice($powers, 0, min(100, count($powers)));
    $top100Furnaces = array_slice($furnaces, 0, min(100, count($furnaces)));
    $top100Sum = $sumTop($powers, 100);

    $allyPower = 0;
    $allyMembers = 0;
    foreach ($alliances as $alliance) {
        if (!is_array($alliance)) {
            continue;
        }
        $allyPower += (int) ($alliance['power'] ?? 0);
        $allyMembers += (int) ($alliance['members'] ?? 0);
    }

    $topAlliancePowers = [];
    foreach ($alliances as $alliance) {
        if (!is_array($alliance)) {
            continue;
        }
        $topAlliancePowers[] = (int) ($alliance['power'] ?? 0);
    }

    $topPlayers = [];
    foreach (array_slice($players, 0, 25) as $player) {
        if (!is_array($player)) {
            continue;
        }
        $topPlayers[] = [
            'rank' => $player['rank'] ?? null,
            'id' => $player['id'] ?? null,
            'name' => $player['name'] ?? null,
            'power' => isset($player['power']) ? (int) $player['power'] : null,
            'stove_lv' => isset($player['stove_lv']) ? (int) $player['stove_lv'] : null,
            'alliance_tag' => $player['alliance_tag'] ?? null,
            'last_jump_pct' => $player['last_jump_pct'] ?? null,
        ];
    }

    $topAlliances = [];
    foreach (array_slice($alliances, 0, 10) as $alliance) {
        if (!is_array($alliance)) {
            continue;
        }
        $topAlliances[] = [
            'rank' => $alliance['rank'] ?? null,
            'id' => $alliance['id'] ?? null,
            'tag' => $alliance['tag'] ?? null,
            'name' => $alliance['name'] ?? null,
            'power' => isset($alliance['power']) ? (int) $alliance['power'] : null,
            'members' => isset($alliance['members']) ? (int) $alliance['members'] : null,
            'max_power' => isset($alliance['max_power']) ? (int) $alliance['max_power'] : null,
            'median_power' => isset($alliance['median_power']) ? (int) $alliance['median_power'] : null,
            'avg_power' => allianceAveragePower($alliance),
        ];
    }

    return [
        'kid' => $kid,
        'alliance_count' => count($alliances),
        'player_count' => count($players),
        'state_alliance_power' => $allyPower,
        'state_members' => $allyMembers,
        'top1' => $powers[0] ?? null,
        'top5_sum' => $sumTop($powers, 5),
        'top10_sum' => $sumTop($powers, 10),
        'top20_sum' => $sumTop($powers, 20),
        'top50_sum' => $sumTop($powers, 50),
        'top100_sum' => $top100Sum,
        'top150_sum' => $sumTop($powers, 150),
        'top200_sum' => $sumTop($powers, 200),
        'avg_top100' => $top100Powers !== [] ? (int) round(array_sum($top100Powers) / count($top100Powers)) : null,
        'median_top100' => $medianOf($top100Powers),
        'min_top100' => $top100Powers !== [] ? (int) min($top100Powers) : null,
        'top10_share_of_100' => $top100Sum > 0 ? round($sumTop($powers, 10) / $top100Sum, 4) : null,
        'top20_share_of_100' => $top100Sum > 0 ? round($sumTop($powers, 20) / $top100Sum, 4) : null,
        'whales_100m' => $countAtLeast($powers, 100_000_000),
        'whales_80m' => $countAtLeast($powers, 80_000_000),
        'whales_60m' => $countAtLeast($powers, 60_000_000),
        'whales_50m' => $countAtLeast($powers, 50_000_000),
        'fc30_plus' => $countAtLeast($furnaces, 30),
        'fc31_plus' => $countAtLeast($furnaces, 31),
        'avg_furnace_top100' => $top100Furnaces !== []
            ? round(array_sum($top100Furnaces) / count($top100Furnaces), 2)
            : null,
        'furnace_buckets' => $furnaceBuckets,
        'top2_alliance_power' => $sumTop($topAlliancePowers, 2),
        'top3_alliance_power' => $sumTop($topAlliancePowers, 3),
        'top5_alliance_power' => $sumTop($topAlliancePowers, 5),
        'top_alliances' => $topAlliances,
        'top_players' => $topPlayers,
        'alliances' => $alliances,
        'players' => $players,
    ];
}

/**
 * Narrative + verdict insights comparing our state vs SvS opponent.
 *
 * @param array<string, mixed> $us
 * @param array<string, mixed> $them
 * @return array{verdict: string, edge: string, bullets: list<string>}
 */
function buildSvsInsights(array $us, array $them): array
{
    $diff = static function (?int $a, ?int $b): ?int {
        if ($a === null || $b === null) {
            return null;
        }
        return $a - $b;
    };
    $pct = static function (?int $a, ?int $b): ?float {
        if ($a === null || $b === null || $b === 0) {
            return null;
        }
        return round((($a - $b) / $b) * 100, 2);
    };

    $top100Diff = $diff($us['top100_sum'] ?? null, $them['top100_sum'] ?? null);
    $top100Pct = $pct($us['top100_sum'] ?? null, $them['top100_sum'] ?? null);
    $top5Diff = $diff($us['top5_sum'] ?? null, $them['top5_sum'] ?? null);
    $top10Diff = $diff($us['top10_sum'] ?? null, $them['top10_sum'] ?? null);
    $floorDiff = $diff($us['min_top100'] ?? null, $them['min_top100'] ?? null);
    $fcDiff = null;
    if (isset($us['avg_furnace_top100'], $them['avg_furnace_top100'])) {
        $fcDiff = round((float) $us['avg_furnace_top100'] - (float) $them['avg_furnace_top100'], 2);
    }

    $score = 0;
    if ($top100Diff !== null) {
        $score += $top100Diff > 0 ? 2 : ($top100Diff < 0 ? -2 : 0);
    }
    if ($top5Diff !== null) {
        $score += $top5Diff > 0 ? 1 : ($top5Diff < 0 ? -1 : 0);
    }
    if ($top10Diff !== null) {
        $score += $top10Diff > 0 ? 1 : ($top10Diff < 0 ? -1 : 0);
    }
    if ($floorDiff !== null) {
        $score += $floorDiff > 0 ? 1 : ($floorDiff < 0 ? -1 : 0);
    }
    if (($us['whales_80m'] ?? 0) !== ($them['whales_80m'] ?? 0)) {
        $score += (($us['whales_80m'] ?? 0) > ($them['whales_80m'] ?? 0)) ? 1 : -1;
    }

    if ($score >= 3) {
        $verdict = 'Slight edge to 4627 on paper';
        $edge = 'us';
    } elseif ($score <= -3) {
        $verdict = 'Slight edge to 4642 on paper';
        $edge = 'them';
    } else {
        $verdict = 'Even matchup — coordination decides it';
        $edge = 'even';
    }

    $bullets = [];
    if ($top100Diff !== null && $top100Pct !== null) {
        if ($top100Diff >= 0) {
            $bullets[] = 'Top 100 power (primary SvS matchmaking signal per Reddit/wiki): 4627 leads by '
                . formatPower($top100Diff) . ' (' . number_format(abs($top100Pct), 2) . '%).';
        } else {
            $bullets[] = 'Top 100 power (primary SvS matchmaking signal per Reddit/wiki): 4642 leads by '
                . formatPower(abs($top100Diff)) . ' (' . number_format(abs($top100Pct), 2) . '%).';
        }
    }
    if ($top5Diff !== null) {
        $bullets[] = $top5Diff >= 0
            ? 'Whale tip (top 5): 4627 is ahead by ' . formatPower($top5Diff) . ' — better solo/rally breakers.'
            : 'Whale tip (top 5): 4642 is ahead by ' . formatPower(abs($top5Diff)) . ' — expect harder castle holds.';
    }
    $bullets[] = 'Rally-lead density (≥80M): 4627 has ' . (int) ($us['whales_80m'] ?? 0)
        . ', 4642 has ' . (int) ($them['whales_80m'] ?? 0)
        . '. Reddit SvS guides stress scouting whale count for multi-alliance counter-rallies.';
    if ($floorDiff !== null) {
        $bullets[] = $floorDiff >= 0
            ? 'Top-100 floor is higher for 4627 (' . formatPower($us['min_top100'] ?? null)
                . ' vs ' . formatPower($them['min_top100'] ?? null) . ') — deeper mid pack.'
            : 'Top-100 floor favors 4642 (' . formatPower($them['min_top100'] ?? null)
                . ' vs ' . formatPower($us['min_top100'] ?? null) . ').';
    }
    if ($fcDiff !== null) {
        $bullets[] = 'Avg Atlas furnace scale (top 100): 4627 ' . number_format((float) ($us['avg_furnace_top100'] ?? 0), 2)
            . ' vs 4642 ' . number_format((float) ($them['avg_furnace_top100'] ?? 0), 2)
            . ' (Δ ' . ($fcDiff >= 0 ? '+' : '') . $fcDiff . '). Past 30 = Crystal (33→C1.3, 40→C2.5).';
    }
    $bullets[] = 'Battle structure: top-3 alliance power is '
        . formatPower($us['top3_alliance_power'] ?? null) . ' (4627) vs '
        . formatPower($them['top3_alliance_power'] ?? null)
        . ' (4642). Strong states often run 2–4 alliances for castle + counter-rallies + turrets.';
    $bullets[] = 'Concentration: top 10 share of top 100 is '
        . number_format(((float) ($us['top10_share_of_100'] ?? 0)) * 100, 1) . '% (4627) vs '
        . number_format(((float) ($them['top10_share_of_100'] ?? 0)) * 100, 1)
        . '% (4642). Higher share = more whale-dependent.';

    return [
        'verdict' => $verdict,
        'edge' => $edge,
        'bullets' => $bullets,
        'top100_diff' => $top100Diff,
        'top100_pct' => $top100Pct,
        'top5_diff' => $top5Diff,
        'top10_diff' => $top10Diff,
        'floor_diff' => $floorDiff,
    ];
}

/**
 * Proxy investment score from Atlas arena team snapshot.
 * Not true in-game hero power — weighted stars/training/exclusive/skills/gear levels.
 *
 * @param array<string, mixed> $arena
 * @return array{
 *   score: int,
 *   hero_count: int,
 *   avg_star: ?float,
 *   avg_hero_lv: ?float,
 *   avg_gear_slv: ?float,
 *   max_gear_slv: ?int,
 *   exclusive_count: int,
 *   full_skills: int,
 *   gear_pieces: int,
 *   gear_detailed: int,
 *   status: ?string,
 *   stale: bool,
 *   observed_at: ?string,
 *   observed_every_days: ?int,
 *   investment_withheld: bool,
 *   heroes: list<array<string, mixed>>
 * }
 */
function summarizeArenaInvestment(array $arena): array
{
    $heroesRaw = is_array($arena['heroes'] ?? null) ? $arena['heroes'] : [];
    $stars = [];
    $levels = [];
    $gearSlvs = [];
    $exclusiveCount = 0;
    $fullSkills = 0;
    $gearPieces = 0;
    $gearDetailed = 0;
    $score = 0;
    $heroesOut = [];

    foreach ($heroesRaw as $hero) {
        if (!is_array($hero)) {
            continue;
        }
        $star = isset($hero['star']) ? (int) $hero['star'] : 0;
        $lv = isset($hero['lv']) ? (int) $hero['lv'] : 0;
        $training = isset($hero['trainingLv']) ? (int) $hero['trainingLv'] : 0;
        $exclusive = isset($hero['exclusiveElv']) ? (int) $hero['exclusiveElv'] : 0;
        $skills = is_array($hero['skillLv'] ?? null) ? $hero['skillLv'] : [];
        $skillSum = 0;
        foreach ($skills as $sk) {
            $skillSum += (int) $sk;
        }
        if ($exclusive > 0) {
            $exclusiveCount++;
        }
        if ($skillSum >= 15) {
            $fullSkills++;
        }
        $stars[] = $star;
        $levels[] = $lv;

        $heroGearScore = 0;
        $heroMaxSlv = 0;
        $gear = is_array($hero['gear'] ?? null) ? $hero['gear'] : [];
        foreach ($gear as $piece) {
            if (!is_array($piece)) {
                continue;
            }
            $gearPieces++;
            $slv = isset($piece['slv']) ? (int) $piece['slv'] : null;
            $rlv = isset($piece['rlv']) ? (int) $piece['rlv'] : null;
            if ($slv !== null || $rlv !== null || isset($piece['exp'])) {
                $gearDetailed++;
            }
            if ($slv !== null) {
                $gearSlvs[] = $slv;
                $heroMaxSlv = max($heroMaxSlv, $slv);
                $heroGearScore += $slv;
            }
            if ($rlv !== null) {
                $heroGearScore += $rlv * 2;
            }
        }

        $heroScore = ($star * 10) + $lv + $training + ($exclusive * 8) + ($skillSum * 3) + $heroGearScore;
        $score += $heroScore;
        $heroesOut[] = [
            'pos' => $hero['pos'] ?? null,
            'hero_id' => $hero['heroId'] ?? null,
            'star' => $star,
            'lv' => $lv,
            'training_lv' => $training > 0 ? $training : null,
            'exclusive_elv' => $exclusive > 0 ? $exclusive : null,
            'skills' => $skills,
            'max_gear_slv' => $heroMaxSlv > 0 ? $heroMaxSlv : null,
            'score' => $heroScore,
        ];
    }

    return [
        'score' => $score,
        'hero_count' => count($heroesOut),
        'avg_star' => $stars !== [] ? round(array_sum($stars) / count($stars), 2) : null,
        'avg_hero_lv' => $levels !== [] ? round(array_sum($levels) / count($levels), 1) : null,
        'avg_gear_slv' => $gearSlvs !== [] ? round(array_sum($gearSlvs) / count($gearSlvs), 1) : null,
        'max_gear_slv' => $gearSlvs !== [] ? (int) max($gearSlvs) : null,
        'exclusive_count' => $exclusiveCount,
        'full_skills' => $fullSkills,
        'gear_pieces' => $gearPieces,
        'gear_detailed' => $gearDetailed,
        'status' => isset($arena['status']) ? (string) $arena['status'] : null,
        'stale' => !empty($arena['stale']),
        'observed_at' => isset($arena['observedAt']) ? (string) $arena['observedAt'] : null,
        'observed_every_days' => isset($arena['observedEveryDays']) ? (int) $arena['observedEveryDays'] : null,
        'investment_withheld' => !empty($arena['investmentWithheld']),
        'heroes' => $heroesOut,
    ];
}

/**
 * @param list<array<string, mixed>> $players
 * @return list<array<string, mixed>>
 */
function fetchArenaScoutRows(array $players, array $config, int $limit = SVS_ARENA_SCOUT_LIMIT): array
{
    // WOS Atlas v1 /players/{uid}/arena is retired (401) and v2 has no arena/hero endpoint
    // (/players/{uid}/arena → 404). Return no rows so the SvS page shows "No Arena snapshots"
    // instead of firing 24 failing requests per refresh. Re-enable if a source appears.
    if (empty($config['arena_scout_source'])) {
        return [];
    }

    require_once __DIR__ . '/player-lookup.php';

    $rows = [];
    $n = 0;
    foreach ($players as $player) {
        if ($n >= $limit) {
            break;
        }
        if (!is_array($player)) {
            continue;
        }
        $uid = trim((string) ($player['id'] ?? ''));
        if ($uid === '' || preg_match('/^\d+$/', $uid) !== 1) {
            continue;
        }
        $n++;
        $row = [
            'rank' => $player['rank'] ?? $n,
            'id' => $uid,
            'name' => $player['name'] ?? null,
            'power' => isset($player['power']) ? (int) $player['power'] : null,
            'alliance_tag' => $player['alliance_tag'] ?? null,
            'stove_lv' => isset($player['stove_lv']) ? (int) $player['stove_lv'] : null,
            'arena' => null,
            'error' => null,
        ];
        try {
            $arena = httpGetJsonAbsolute(
                'https://api.wosatlas.com/v1/players/' . rawurlencode($uid) . '/arena',
                $config
            );
            $row['arena'] = summarizeArenaInvestment($arena);
        } catch (Throwable $e) {
            $row['error'] = $e->getMessage();
        }
        $rows[] = $row;
        usleep(80000);
    }

    return $rows;
}

/**
 * Aggregate arena scout rows for one side.
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function aggregateArenaScout(array $rows): array
{
    $scores = [];
    $stars = [];
    $gear = [];
    $exclusives = [];
    $fullSkills = [];
    $ok = 0;
    $stale = 0;
    $withheld = 0;

    foreach ($rows as $row) {
        $arena = is_array($row['arena'] ?? null) ? $row['arena'] : null;
        if ($arena === null) {
            continue;
        }
        $ok++;
        if (!empty($arena['stale'])) {
            $stale++;
        }
        if (!empty($arena['investment_withheld'])) {
            $withheld++;
        }
        $scores[] = (int) ($arena['score'] ?? 0);
        if (($arena['avg_star'] ?? null) !== null) {
            $stars[] = (float) $arena['avg_star'];
        }
        if (($arena['avg_gear_slv'] ?? null) !== null) {
            $gear[] = (float) $arena['avg_gear_slv'];
        }
        $exclusives[] = (int) ($arena['exclusive_count'] ?? 0);
        $fullSkills[] = (int) ($arena['full_skills'] ?? 0);
    }

    $avg = static function (array $vals): ?float {
        return $vals !== [] ? round(array_sum($vals) / count($vals), 2) : null;
    };
    $median = static function (array $vals): ?float {
        if ($vals === []) {
            return null;
        }
        sort($vals);
        $c = count($vals);
        $m = intdiv($c, 2);
        return $c % 2 ? (float) $vals[$m] : round(($vals[$m - 1] + $vals[$m]) / 2, 2);
    };

    return [
        'scouted' => count($rows),
        'with_arena' => $ok,
        'stale' => $stale,
        'withheld' => $withheld,
        'avg_score' => $avg($scores),
        'median_score' => $median($scores),
        'top_score' => $scores !== [] ? (int) max($scores) : null,
        'avg_star' => $avg($stars),
        'avg_gear_slv' => $avg($gear),
        'avg_exclusives' => $avg($exclusives),
        'avg_full_skills' => $avg($fullSkills),
        'players' => $rows,
    ];
}

/** Client IP for visit tracking (supports common proxy headers). */
function visitorClientIp(): string
{
    $candidates = [
        (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
        (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ];
    foreach ($candidates as $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            continue;
        }
        // X-Forwarded-For may be a list; use the first hop.
        if (str_contains($raw, ',')) {
            $raw = trim(explode(',', $raw, 2)[0]);
        }
        if (filter_var($raw, FILTER_VALIDATE_IP)) {
            return $raw;
        }
    }

    return '0.0.0.0';
}

/** Stable visitor key derived from IP (not shown on public pages). */
function visitorKeyFromIp(?string $ip = null): string
{
    $ip = $ip ?? visitorClientIp();
    $salt = 'wostracker-site-visits-v1';
    try {
        $cfg = loadConfig();
        $token = trim((string) ($cfg['update_token'] ?? ''));
        if ($token !== '') {
            $salt .= '|' . $token;
        }
    } catch (Throwable $e) {
        // keep default salt
    }

    return hash('sha256', $salt . '|' . $ip);
}

/** Page key from current PHP script (e.g. index, state-4642, admin/visits). */
function currentVisitPageKey(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $script = trim($script, '/');
    if ($script === '') {
        return 'unknown';
    }
    if (str_ends_with(strtolower($script), '.php')) {
        $script = substr($script, 0, -4);
    }
    // Drop leading path segments above the app root when hosted in a subfolder.
    $parts = explode('/', $script);
    $keep = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }
        $keep[] = $part;
    }
    if ($keep === []) {
        return 'unknown';
    }
    // Prefer last 2 segments so admin/visits stays distinct from visits.
    if (count($keep) > 2) {
        $keep = array_slice($keep, -2);
    }
    $key = implode('/', $keep);
    if (strlen($key) > 64) {
        $key = substr($key, 0, 64);
    }

    return $key !== '' ? $key : 'unknown';
}

/** Human-readable duration (e.g. 2h 15m). */
function formatVisitDuration(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $minutes = intdiv($seconds, 60);
    $secs = $seconds % 60;
    if ($minutes < 60) {
        return $secs > 0 ? ($minutes . 'm ' . $secs . 's') : ($minutes . 'm');
    }
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    if ($hours < 48) {
        return $mins > 0 ? ($hours . 'h ' . $mins . 'm') : ($hours . 'h');
    }
    $days = intdiv($hours, 24);
    $remHours = $hours % 24;

    return $remHours > 0 ? ($days . 'd ' . $remHours . 'h') : ($days . 'd');
}

/**
 * Build "City, Region, Country" label from geo parts.
 *
 * @param array{country?: ?string, region?: ?string, city?: ?string} $geo
 */
function formatVisitorGeoLabel(array $geo): string
{
    $parts = [];
    foreach (['city', 'region', 'country'] as $field) {
        $val = trim((string) ($geo[$field] ?? ''));
        if ($val !== '' && !in_array($val, $parts, true)) {
            $parts[] = $val;
        }
    }

    return $parts !== [] ? implode(', ', $parts) : 'Unknown';
}

/**
 * Resolve approximate location for a visitor IP via ip-api.com (cached per visitor key).
 *
 * @return array{country: ?string, region: ?string, city: ?string, geo_label: string, status: string, cached: bool}
 */
function resolveVisitorGeo(string $visitorKey, ?string $ip = null): array
{
    $ip = $ip ?? visitorClientIp();
    $now = nowUtc();
    $fallback = [
        'country' => null,
        'region' => null,
        'city' => null,
        'geo_label' => 'Unknown',
        'status' => 'unknown',
        'cached' => false,
    ];

    try {
        // Cache hit (DB).
        if (dbAvailable()) {
            $pdo = db();
            $stmt = $pdo->prepare(
                'SELECT country, region, city, geo_label, status FROM visitor_geo_cache WHERE visitor_key = ? LIMIT 1'
            );
            $stmt->execute([$visitorKey]);
            $cached = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($cached)) {
                return [
                    'country' => $cached['country'] !== null ? (string) $cached['country'] : null,
                    'region' => $cached['region'] !== null ? (string) $cached['region'] : null,
                    'city' => $cached['city'] !== null ? (string) $cached['city'] : null,
                    'geo_label' => (string) ($cached['geo_label'] ?: 'Unknown'),
                    'status' => (string) ($cached['status'] ?? 'ok'),
                    'cached' => true,
                ];
            }
        } else {
            $cache = loadJson(SITE_GEO_CACHE_FILE, ['entries' => []]);
            $entries = is_array($cache['entries'] ?? null) ? $cache['entries'] : [];
            if (isset($entries[$visitorKey]) && is_array($entries[$visitorKey])) {
                $cached = $entries[$visitorKey];

                return [
                    'country' => isset($cached['country']) ? (string) $cached['country'] : null,
                    'region' => isset($cached['region']) ? (string) $cached['region'] : null,
                    'city' => isset($cached['city']) ? (string) $cached['city'] : null,
                    'geo_label' => (string) ($cached['geo_label'] ?? formatVisitorGeoLabel($cached)),
                    'status' => (string) ($cached['status'] ?? 'ok'),
                    'cached' => true,
                ];
            }
        }

        // Private / local addresses — never call the API.
        if (
            $ip === '' ||
            $ip === '0.0.0.0' ||
            !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        ) {
            $geo = [
                'country' => null,
                'region' => null,
                'city' => null,
                'geo_label' => 'Local / private network',
                'status' => 'private',
                'cached' => false,
            ];
            persistVisitorGeoCache($visitorKey, $ip, $geo, $now);

            return $geo;
        }

        // Free tier of ip-api.com is HTTP-only and rate-limited (~45/min).
        $url = 'http://ip-api.com/json/' . rawurlencode($ip)
            . '?fields=status,message,country,regionName,city,query';
        $payload = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_USERAGENT => 'WOSTracker-geo/1.0',
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 3,
                    'header' => "User-Agent: WOSTracker-geo/1.0\r\n",
                ],
            ]);
            $raw = @file_get_contents($url, false, $ctx);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }

        if (!is_array($payload) || ($payload['status'] ?? '') !== 'success') {
            $geo = [
                'country' => null,
                'region' => null,
                'city' => null,
                'geo_label' => 'Lookup unavailable',
                'status' => 'fail',
                'cached' => false,
            ];
            // Cache failures briefly-as-permanent to avoid hammering the free API.
            persistVisitorGeoCache($visitorKey, $ip, $geo, $now);

            return $geo;
        }

        $geo = [
            'country' => trim((string) ($payload['country'] ?? '')) ?: null,
            'region' => trim((string) ($payload['regionName'] ?? '')) ?: null,
            'city' => trim((string) ($payload['city'] ?? '')) ?: null,
            'geo_label' => '',
            'status' => 'ok',
            'cached' => false,
        ];
        $geo['geo_label'] = formatVisitorGeoLabel($geo);
        persistVisitorGeoCache($visitorKey, $ip, $geo, $now);

        return $geo;
    } catch (Throwable $e) {
        return $fallback;
    }
}

/**
 * @param array{country: ?string, region: ?string, city: ?string, geo_label: string, status: string} $geo
 */
function persistVisitorGeoCache(string $visitorKey, string $ip, array $geo, string $now): void
{
    $ipHash = hash('sha256', $ip);
    $country = $geo['country'] ?? null;
    $region = $geo['region'] ?? null;
    $city = $geo['city'] ?? null;
    $label = (string) ($geo['geo_label'] ?? 'Unknown');
    $status = (string) ($geo['status'] ?? 'ok');

    if (dbAvailable()) {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO visitor_geo_cache
                (visitor_key, ip_hash, country, region, city, geo_label, status, looked_up_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                ip_hash = VALUES(ip_hash),
                country = VALUES(country),
                region = VALUES(region),
                city = VALUES(city),
                geo_label = VALUES(geo_label),
                status = VALUES(status),
                looked_up_at = VALUES(looked_up_at)'
        );
        $stmt->execute([$visitorKey, $ipHash, $country, $region, $city, $label, $status, $now]);
        return;
    }

    $cache = loadJson(SITE_GEO_CACHE_FILE, ['entries' => []]);
    if (!isset($cache['entries']) || !is_array($cache['entries'])) {
        $cache['entries'] = [];
    }
    $cache['entries'][$visitorKey] = [
        'ip_hash' => $ipHash,
        'country' => $country,
        'region' => $region,
        'city' => $city,
        'geo_label' => $label,
        'status' => $status,
        'looked_up_at' => $now,
    ];
    $cache['updated_at'] = $now;
    saveJson(SITE_GEO_CACHE_FILE, $cache);
}

/**
 * Record one site page hit + update visitor first/last activity.
 * Silent on failure so tracking never breaks public pages.
 *
 * @return array{ok: bool}
 */
function recordSiteVisit(?string $pageKey = null): array
{
    if (PHP_SAPI === 'cli') {
        return ['ok' => false];
    }

    $pageKey = trim((string) ($pageKey ?? currentVisitPageKey()));
    if ($pageKey === '') {
        $pageKey = 'unknown';
    }
    $ip = visitorClientIp();
    $visitorKey = visitorKeyFromIp($ip);
    $now = nowUtc();
    $geo = resolveVisitorGeo($visitorKey, $ip);

    try {
        if (dbAvailable()) {
            $pdo = db();
            $hit = $pdo->prepare(
                'INSERT INTO page_visits (page_key, visitor_key, visited_at) VALUES (?, ?, ?)'
            );
            $hit->execute([$pageKey, $visitorKey, $now]);

            $upsert = $pdo->prepare(
                'INSERT INTO visitor_activity
                    (visitor_key, first_seen, last_seen, hit_count, ip_address,
                     country, region, city, geo_label, geo_looked_up_at)
                 VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    last_seen = VALUES(last_seen),
                    hit_count = hit_count + 1,
                    ip_address = VALUES(ip_address),
                    country = COALESCE(VALUES(country), country),
                    region = COALESCE(VALUES(region), region),
                    city = COALESCE(VALUES(city), city),
                    geo_label = COALESCE(VALUES(geo_label), geo_label),
                    geo_looked_up_at = COALESCE(geo_looked_up_at, VALUES(geo_looked_up_at))'
            );
            $upsert->execute([
                $visitorKey,
                $now,
                $now,
                $ip,
                $geo['country'],
                $geo['region'],
                $geo['city'],
                $geo['geo_label'],
                $now,
            ]);

            return ['ok' => true];
        }

        $store = loadJson(SITE_VISITS_FILE, ['pages' => [], 'visitors' => []]);
        if (!is_array($store)) {
            $store = ['pages' => [], 'visitors' => []];
        }
        if (!isset($store['pages']) || !is_array($store['pages'])) {
            $store['pages'] = [];
        }
        if (!isset($store['visitors']) || !is_array($store['visitors'])) {
            $store['visitors'] = [];
        }

        $page = is_array($store['pages'][$pageKey] ?? null) ? $store['pages'][$pageKey] : [];
        $pageVisitors = is_array($page['visitors'] ?? null) ? $page['visitors'] : [];
        $pageVisitors[$visitorKey] = (int) ($pageVisitors[$visitorKey] ?? 0) + 1;
        $page['visitors'] = $pageVisitors;
        $page['total'] = (int) ($page['total'] ?? 0) + 1;
        $page['unique'] = count($pageVisitors);
        $page['last_visit'] = $now;
        $store['pages'][$pageKey] = $page;

        $visitor = is_array($store['visitors'][$visitorKey] ?? null) ? $store['visitors'][$visitorKey] : [];
        if (empty($visitor['first_seen'])) {
            $visitor['first_seen'] = $now;
        }
        $visitor['last_seen'] = $now;
        $visitor['hits'] = (int) ($visitor['hits'] ?? 0) + 1;
        $visitor['ip_address'] = $ip;
        if (empty($visitor['geo_label'])) {
            $visitor['country'] = $geo['country'];
            $visitor['region'] = $geo['region'];
            $visitor['city'] = $geo['city'];
            $visitor['geo_label'] = $geo['geo_label'];
            $visitor['geo_looked_up_at'] = $now;
        }
        $store['visitors'][$visitorKey] = $visitor;
        $store['updated_at'] = $now;
        saveJson(SITE_VISITS_FILE, $store);

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false];
    }
}

/** @deprecated Use recordSiteVisit() */
function recordPageVisit(string $pageKey = SVS_VISIT_PAGE_KEY): array
{
    return recordSiteVisit($pageKey);
}

/**
 * @return array{total: int, unique: int, last_visit: ?string}
 */
function getPageVisitStats(string $pageKey = SVS_VISIT_PAGE_KEY): array
{
    $pageKey = trim($pageKey) !== '' ? trim($pageKey) : SVS_VISIT_PAGE_KEY;

    try {
        if (dbAvailable()) {
            $pdo = db();
            $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM page_visits WHERE page_key = ?');
            $totalStmt->execute([$pageKey]);
            $total = (int) $totalStmt->fetchColumn();

            $uniqueStmt = $pdo->prepare('SELECT COUNT(DISTINCT visitor_key) FROM page_visits WHERE page_key = ?');
            $uniqueStmt->execute([$pageKey]);
            $unique = (int) $uniqueStmt->fetchColumn();

            $lastStmt = $pdo->prepare('SELECT MAX(visited_at) FROM page_visits WHERE page_key = ?');
            $lastStmt->execute([$pageKey]);
            $last = $lastStmt->fetchColumn();

            return [
                'total' => $total,
                'unique' => $unique,
                'last_visit' => $last !== false && $last !== null && $last !== '' ? (string) $last : null,
            ];
        }

        $store = loadJson(SITE_VISITS_FILE, ['pages' => [], 'visitors' => []]);
        $page = is_array($store['pages'][$pageKey] ?? null) ? $store['pages'][$pageKey] : [];
        $visitors = is_array($page['visitors'] ?? null) ? $page['visitors'] : [];

        return [
            'total' => (int) ($page['total'] ?? 0),
            'unique' => isset($page['unique']) ? (int) $page['unique'] : count($visitors),
            'last_visit' => isset($page['last_visit']) ? (string) $page['last_visit'] : null,
        ];
    } catch (Throwable $e) {
        return ['total' => 0, 'unique' => 0, 'last_visit' => null];
    }
}

/**
 * Full admin report: site totals, average/total time spent, per-visitor rows.
 *
 * @return array{
 *   total_hits: int,
 *   unique_visitors: int,
 *   total_duration_seconds: int,
 *   avg_duration_seconds: int,
 *   last_visit: ?string,
 *   storage: string,
 *   visitors: list<array{
 *     visitor_key: string,
 *     visitor_label: string,
 *     ip_address: string,
 *     location: string,
 *     country: ?string,
 *     region: ?string,
 *     city: ?string,
 *     first_seen: string,
 *     last_seen: string,
 *     duration_seconds: int,
 *     hits: int
 *   }>,
 *   pages: list<array{page_key: string, total: int, unique: int}>
 * }
 */
function getSiteVisitReport(): array
{
    $empty = [
        'total_hits' => 0,
        'unique_visitors' => 0,
        'total_duration_seconds' => 0,
        'avg_duration_seconds' => 0,
        'last_visit' => null,
        'storage' => dbAvailable() ? 'MySQL' : 'JSON',
        'visitors' => [],
        'pages' => [],
    ];

    try {
        $visitorRows = [];

        if (dbAvailable()) {
            $pdo = db();
            $totalHits = (int) $pdo->query('SELECT COUNT(*) FROM page_visits')->fetchColumn();
            $lastVisit = $pdo->query('SELECT MAX(visited_at) FROM page_visits')->fetchColumn();
            $lastVisit = $lastVisit !== false && $lastVisit !== null && $lastVisit !== ''
                ? (string) $lastVisit
                : null;

            $stmt = $pdo->query(
                'SELECT visitor_key, ip_address, first_seen, last_seen, hit_count,
                        country, region, city, geo_label
                 FROM visitor_activity
                 ORDER BY last_seen DESC'
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $first = (string) ($row['first_seen'] ?? '');
                $last = (string) ($row['last_seen'] ?? '');
                $firstTs = strtotime($first) ?: 0;
                $lastTs = strtotime($last) ?: 0;
                $key = (string) ($row['visitor_key'] ?? '');
                $ipAddress = trim((string) ($row['ip_address'] ?? ''));
                $location = trim((string) ($row['geo_label'] ?? ''));
                if ($location === '') {
                    $location = formatVisitorGeoLabel([
                        'country' => $row['country'] ?? null,
                        'region' => $row['region'] ?? null,
                        'city' => $row['city'] ?? null,
                    ]);
                }
                $visitorRows[] = [
                    'visitor_key' => $key,
                    'ip_address' => $ipAddress,
                    'visitor_label' => $ipAddress !== '' ? $ipAddress : 'Unknown IP',
                    'location' => $location,
                    'country' => $row['country'] !== null ? (string) $row['country'] : null,
                    'region' => $row['region'] !== null ? (string) $row['region'] : null,
                    'city' => $row['city'] !== null ? (string) $row['city'] : null,
                    'first_seen' => $first,
                    'last_seen' => $last,
                    'duration_seconds' => max(0, $lastTs - $firstTs),
                    'hits' => (int) ($row['hit_count'] ?? 0),
                ];
            }

            $pageStmt = $pdo->query(
                'SELECT page_key,
                        COUNT(*) AS total,
                        COUNT(DISTINCT visitor_key) AS unique_visitors
                 FROM page_visits
                 GROUP BY page_key
                 ORDER BY total DESC'
            );
            $pages = [];
            foreach ($pageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pages[] = [
                    'page_key' => (string) ($row['page_key'] ?? ''),
                    'total' => (int) ($row['total'] ?? 0),
                    'unique' => (int) ($row['unique_visitors'] ?? 0),
                ];
            }

            $storage = 'MySQL (page_visits + visitor_activity)';
        } else {
            $store = loadJson(SITE_VISITS_FILE, ['pages' => [], 'visitors' => []]);
            $visitors = is_array($store['visitors'] ?? null) ? $store['visitors'] : [];
            $pagesRaw = is_array($store['pages'] ?? null) ? $store['pages'] : [];

            $totalHits = 0;
            $lastVisit = null;
            $lastVisitTs = 0;
            foreach ($pagesRaw as $pageKey => $page) {
                if (!is_array($page)) {
                    continue;
                }
                $totalHits += (int) ($page['total'] ?? 0);
                $pageLast = (string) ($page['last_visit'] ?? '');
                $ts = $pageLast !== '' ? (strtotime($pageLast) ?: 0) : 0;
                if ($ts > $lastVisitTs) {
                    $lastVisitTs = $ts;
                    $lastVisit = $pageLast;
                }
            }

            foreach ($visitors as $key => $visitor) {
                if (!is_array($visitor)) {
                    continue;
                }
                $first = (string) ($visitor['first_seen'] ?? '');
                $last = (string) ($visitor['last_seen'] ?? $first);
                $firstTs = strtotime($first) ?: 0;
                $lastTs = strtotime($last) ?: 0;
                $keyStr = (string) $key;
                $ipAddress = trim((string) ($visitor['ip_address'] ?? ''));
                $location = trim((string) ($visitor['geo_label'] ?? ''));
                if ($location === '') {
                    $location = formatVisitorGeoLabel([
                        'country' => $visitor['country'] ?? null,
                        'region' => $visitor['region'] ?? null,
                        'city' => $visitor['city'] ?? null,
                    ]);
                }
                $visitorRows[] = [
                    'visitor_key' => $keyStr,
                    'ip_address' => $ipAddress,
                    'visitor_label' => $ipAddress !== '' ? $ipAddress : 'Unknown IP',
                    'location' => $location,
                    'country' => isset($visitor['country']) ? (string) $visitor['country'] : null,
                    'region' => isset($visitor['region']) ? (string) $visitor['region'] : null,
                    'city' => isset($visitor['city']) ? (string) $visitor['city'] : null,
                    'first_seen' => $first,
                    'last_seen' => $last,
                    'duration_seconds' => max(0, $lastTs - $firstTs),
                    'hits' => (int) ($visitor['hits'] ?? 0),
                ];
            }
            usort(
                $visitorRows,
                static fn(array $a, array $b): int => strcmp((string) $b['last_seen'], (string) $a['last_seen'])
            );

            $pages = [];
            foreach ($pagesRaw as $pageKey => $page) {
                if (!is_array($page)) {
                    continue;
                }
                $pageVisitors = is_array($page['visitors'] ?? null) ? $page['visitors'] : [];
                $pages[] = [
                    'page_key' => (string) $pageKey,
                    'total' => (int) ($page['total'] ?? 0),
                    'unique' => isset($page['unique']) ? (int) $page['unique'] : count($pageVisitors),
                ];
            }
            usort($pages, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);

            $storage = 'JSON file (data/site-visits.json)';
        }

        $unique = count($visitorRows);
        $totalDuration = 0;
        foreach ($visitorRows as $row) {
            $totalDuration += (int) ($row['duration_seconds'] ?? 0);
        }
        $avgDuration = $unique > 0 ? (int) round($totalDuration / $unique) : 0;

        return [
            'total_hits' => $totalHits ?? 0,
            'unique_visitors' => $unique,
            'total_duration_seconds' => $totalDuration,
            'avg_duration_seconds' => $avgDuration,
            'last_visit' => $lastVisit,
            'storage' => $storage,
            'visitors' => $visitorRows,
            'pages' => $pages,
        ];
    } catch (Throwable $e) {
        return $empty;
    }
}

/**
 * Load cached Arena/gear scout for SvS page (top N each side).
 *
 * @param list<array<string, mixed>> $ourPlayers
 * @param list<array<string, mixed>> $theirPlayers
 * @return array{
 *   fetched_at: string,
 *   source: string,
 *   error: ?string,
 *   us: array<string, mixed>,
 *   them: array<string, mixed>
 * }
 */
function loadSvsArenaComparison(
    array $ourPlayers,
    array $theirPlayers,
    bool $forceRefresh = false,
    int $limit = SVS_ARENA_SCOUT_LIMIT
): array {
    $cache = loadJson(SVS_ARENA_CACHE_FILE, null);
    $fresh = false;
    if (is_array($cache) && !$forceRefresh) {
        $fetchedAt = strtotime((string) ($cache['fetched_at'] ?? '')) ?: 0;
        if ($fetchedAt > 0 && (time() - $fetchedAt) < SVS_ARENA_CACHE_TTL) {
            $fresh = true;
        }
    }

    if ($fresh && is_array($cache)) {
        return [
            'fetched_at' => (string) ($cache['fetched_at'] ?? ''),
            'source' => 'cache',
            'error' => null,
            'us' => is_array($cache['us'] ?? null) ? $cache['us'] : aggregateArenaScout([]),
            'them' => is_array($cache['them'] ?? null) ? $cache['them'] : aggregateArenaScout([]),
        ];
    }

    try {
        $config = loadConfig();
        $ourRows = fetchArenaScoutRows(rankPlayersByPower($ourPlayers), $config, $limit);
        $theirRows = fetchArenaScoutRows(rankPlayersByPower($theirPlayers), $config, $limit);
        $payload = [
            'fetched_at' => nowUtc(),
            'limit' => $limit,
            'us' => aggregateArenaScout($ourRows),
            'them' => aggregateArenaScout($theirRows),
        ];
        saveJson(SVS_ARENA_CACHE_FILE, $payload);

        return [
            'fetched_at' => $payload['fetched_at'],
            'source' => 'live',
            'error' => null,
            'us' => $payload['us'],
            'them' => $payload['them'],
        ];
    } catch (Throwable $e) {
        if (is_array($cache) && is_array($cache['us'] ?? null)) {
            return [
                'fetched_at' => (string) ($cache['fetched_at'] ?? ''),
                'source' => 'stale-cache',
                'error' => $e->getMessage(),
                'us' => $cache['us'],
                'them' => is_array($cache['them'] ?? null) ? $cache['them'] : aggregateArenaScout([]),
            ];
        }

        return [
            'fetched_at' => '',
            'source' => 'error',
            'error' => $e->getMessage(),
            'us' => aggregateArenaScout([]),
            'them' => aggregateArenaScout([]),
        ];
    }
}


