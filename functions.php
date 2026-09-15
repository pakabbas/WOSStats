<?php

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const CONFIG_FILE = __DIR__ . '/config.json';
const ALLIANCES_FILE = DATA_DIR . '/alliances.json';
const HISTORY_FILE = DATA_DIR . '/history.json';
const HISTORY_RETENTION_DAYS = 90;

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

    if ($power >= 1_000_000_000) {
        return rtrim(rtrim(number_format($power / 1_000_000_000, 1, '.', ''), '0'), '.') . 'B';
    }

    if ($power >= 1_000_000) {
        return rtrim(rtrim(number_format($power / 1_000_000, 1, '.', ''), '0'), '.') . 'M';
    }

    if ($power >= 1_000) {
        return rtrim(rtrim(number_format($power / 1_000, 1, '.', ''), '0'), '.') . 'K';
    }

    return (string) $power;
}

function formatPowerChange(?int $change): string
{
    if ($change === null) {
        return 'N/A';
    }

    if ($change === 0) {
        return '—';
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

function formatDisplayDate(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return 'Never';
    }

    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return $datetime;
    }

    return date('d M Y H:i', $timestamp);
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
    return [
        'id' => isset($alliance['id']) ? (string) $alliance['id'] : null,
        'name' => isset($alliance['name']) ? (string) $alliance['name'] : null,
        'tag' => isset($alliance['tag']) ? (string) $alliance['tag'] : null,
        'rank' => isset($alliance['rank']) ? (int) $alliance['rank'] : $fallbackRank,
        'power' => isset($alliance['power']) ? (int) $alliance['power'] : null,
        'members' => isset($alliance['members']) ? (int) $alliance['members'] : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function isNapAlliance(array $alliance, array $napTags): bool
{
    $tag = strtoupper((string) ($alliance['tag'] ?? ''));
    $napTags = array_map(static fn(string $tag): string => strtoupper($tag), $napTags);

    return $tag !== '' && in_array($tag, $napTags, true);
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

function ensureDataFilesExist(): void
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0755, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('Unable to create data directory.');
    }

    if (!file_exists(ALLIANCES_FILE)) {
        saveJson(ALLIANCES_FILE, []);
    }

    if (!file_exists(HISTORY_FILE)) {
        saveJson(HISTORY_FILE, []);
    }
}
