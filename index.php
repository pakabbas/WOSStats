<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$alliances = loadJson(ALLIANCES_FILE, []);
$history = loadJson(HISTORY_FILE, []);
$napTags = $config['nap_alliances'] ?? [];
$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$lastUpdated = getLastUpdated($alliances);
$totalPower = getTotalStatePower($alliances);
$napCount = count(array_filter($alliances, static fn(array $a): bool => isNapAlliance($a, $napTags)));
$sampleMode = !empty($config['sample_mode']);

usort($alliances, static fn(array $a, array $b): int => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));

$rows = [];
foreach ($alliances as $alliance) {
    $rows[] = [
        'alliance' => $alliance,
        'change24h' => calculatePowerChange($history, $alliance, 24),
        'change7d' => calculatePowerChange($history, $alliance, 24 * 7),
        'is_nap' => isNapAlliance($alliance, $napTags),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($stateName) ?> — Alliance Tracker</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container">
        <header class="page-header">
            <p class="eyebrow">WHITEOUT SURVIVAL</p>
            <h1><?= h(strtoupper($stateName)) ?></h1>
            <?php if ($sampleMode): ?>
                <p class="banner banner-warning">Development mode: displaying sample data (sample_mode enabled)</p>
            <?php endif; ?>
        </header>

        <section class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">State Power</span>
                <span class="stat-value"><?= h(formatPower($totalPower)) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Total Alliances</span>
                <span class="stat-value"><?= count($alliances) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">NAP Alliances</span>
                <span class="stat-value"><?= $napCount ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Last Updated</span>
                <span class="stat-value stat-value-small"><?= h(formatDisplayDate($lastUpdated)) ?></span>
            </div>
        </section>

        <section class="panel">
            <div class="panel-toolbar">
                <div class="filter-group" id="nap-filter">
                    <button type="button" class="filter-btn active" data-filter="all">All</button>
                    <button type="button" class="filter-btn" data-filter="nap">NAP</button>
                    <button type="button" class="filter-btn" data-filter="non-nap">Non-NAP</button>
                </div>
                <input type="search" id="alliance-search" class="search-input" placeholder="Search by name or tag…" aria-label="Search alliances">
            </div>

            <div class="table-wrap">
                <table class="alliance-table" id="alliance-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Alliance</th>
                            <th>Tag</th>
                            <th>Power</th>
                            <th>Members</th>
                            <th>24h Change</th>
                            <th>7d Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="7" class="empty-cell">
                                    No alliance data yet. Run <code>update.php?token=YOUR_TOKEN</code> to fetch data.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $alliance = $row['alliance'];
                                $detailId = $alliance['id'] ?? allianceKey($alliance);
                                ?>
                                <tr
                                    class="<?= $row['is_nap'] ? 'nap-row' : '' ?>"
                                    data-name="<?= h(strtolower((string) ($alliance['name'] ?? ''))) ?>"
                                    data-tag="<?= h(strtolower((string) ($alliance['tag'] ?? ''))) ?>"
                                    data-nap="<?= $row['is_nap'] ? '1' : '0' ?>"
                                >
                                    <td><?= h((string) ($alliance['rank'] ?? '—')) ?></td>
                                    <td>
                                        <a href="alliance.php?id=<?= urlencode((string) $detailId) ?>" class="alliance-link">
                                            <?= h((string) ($alliance['name'] ?? 'Unknown')) ?>
                                            <?php if ($row['is_nap']): ?>
                                                <span class="nap-badge">NAP</span>
                                            <?php endif; ?>
                                        </a>
                                    </td>
                                    <td><?= h((string) ($alliance['tag'] ?? '—')) ?></td>
                                    <td><?= h(formatPower($alliance['power'] ?? null)) ?></td>
                                    <td><?= h(isset($alliance['members']) ? (string) $alliance['members'] : '—') ?></td>
                                    <td class="<?= powerChangeClass($row['change24h']) ?>"><?= h(formatPowerChange($row['change24h'])) ?></td>
                                    <td class="<?= powerChangeClass($row['change7d']) ?>"><?= h(formatPowerChange($row['change7d'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script src="assets/app.js"></script>
</body>
</html>
<?php

function powerChangeClass(?int $change): string
{
    if ($change === null) {
        return '';
    }

    if ($change > 0) {
        return 'positive';
    }

    if ($change < 0) {
        return 'negative';
    }

    return '';
}
