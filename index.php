<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$alliances = loadJson(ALLIANCES_FILE, []);
$history = loadJson(HISTORY_FILE, []);
$players = loadJson(PLAYERS_FILE, []);
$napTags = $config['nap_alliances'] ?? [];
$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$alliances = enrichAlliancesMaxPowerFromPlayers(
    is_array($alliances) ? $alliances : [],
    is_array($players) ? $players : []
);
$lastUpdated = getLastUpdated($alliances);
$totalPower = getTotalStatePower($alliances);
$napCount = count(array_filter($alliances, static fn(array $a): bool => isNapAlliance($a, $napTags)));
$sampleMode = !empty($config['sample_mode']);
$napBanned = loadNapBannedPlayers();

usort($alliances, static fn(array $a, array $b): int => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));

$allianceBarChart = null;
$topForChart = array_slice($alliances, 0, 10);
$labels = [];
$topPlayerValues = [];
$medianValues = [];
$hasChartPoints = false;
foreach ($topForChart as $alliance) {
    $tag = trim((string) ($alliance['tag'] ?? ''));
    $name = trim((string) ($alliance['name'] ?? ''));
    $labels[] = $tag !== '' ? $tag : ($name !== '' ? $name : ('#' . ($alliance['rank'] ?? '?')));
    $top = isset($alliance['max_power']) ? (int) $alliance['max_power'] : null;
    $median = isset($alliance['median_power']) ? (int) $alliance['median_power'] : null;
    $topPlayerValues[] = $top;
    $medianValues[] = $median;
    if ($top !== null || $median !== null) {
        $hasChartPoints = true;
    }
}
if ($hasChartPoints) {
    $allianceBarChart = [
        'labels' => $labels,
        'topPlayer' => $topPlayerValues,
        'median' => $medianValues,
    ];
}

$rows = [];
foreach ($alliances as $alliance) {
    $rows[] = [
        'alliance' => $alliance,
        'change1d' => calculatePowerChange($history, $alliance, 24),
        'change3d' => calculatePowerChange($history, $alliance, 24 * 3),
        'is_nap' => isNapAlliance($alliance, $napTags),
    ];
}

$tableColspan = 8;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <title><?= h($stateName) ?> — Alliance Tracker</title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body>
    <div class="container">
        <?php renderSiteNav('alliances'); ?>

        <header class="page-header page-header-with-action">
            <div>
                <p class="eyebrow">WHITEOUT SURVIVAL</p>
                <h1><?= h(strtoupper($stateName)) ?></h1>
                <p class="subtitle">Alliance power ranking · NAP tracking</p>
                <?php if ($sampleMode): ?>
                    <p class="banner banner-warning">Development mode: displaying sample data (sample_mode enabled)</p>
                <?php endif; ?>
            </div>
            <a class="btn-nap-banned" href="nap-banned.php">
                NAP Banned list
                <?php if ($napBanned !== []): ?>
                    <span class="btn-nap-banned-count"><?= count($napBanned) ?></span>
                <?php endif; ?>
            </a>
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
                <span class="stat-value stat-value-small"><?= h(formatDisplayDateOnly($lastUpdated)) ?></span>
            </div>
        </section>

        <section class="panel">
            <div class="panel-toolbar">
                <div class="filter-group" id="nap-filter">
                    <button type="button" class="refresh-data-btn" id="refresh-alliances" title="Refresh alliance data (same as run.php)">
                        Refresh
                    </button>
                    <button type="button" class="filter-btn active" data-filter="all">All</button>
                    <button type="button" class="filter-btn" data-filter="nap">NAP</button>
                    <button type="button" class="filter-btn" data-filter="non-nap">Non-NAP</button>
                </div>
                <span id="refresh-alliances-status" class="refresh-data-status" role="status" aria-live="polite"></span>
                <input type="search" id="alliance-search" class="search-input" placeholder="Search by name or tag…" aria-label="Search alliances">
            </div>

            <div class="table-wrap">
                <table class="alliance-table responsive-table" id="alliance-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Alliance</th>
                            <th>Tag</th>
                            <th>Power</th>
                            <th title="Median member power">Median Power</th>
                            <th>Total Members</th>
                            <th>1d Change</th>
                            <th>3d Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="<?= $tableColspan ?>" class="empty-cell">
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
                                    <td data-label="Rank"><?= h((string) ($alliance['rank'] ?? '—')) ?></td>
                                    <td data-label="Alliance">
                                        <a href="alliance.php?id=<?= urlencode((string) $detailId) ?>" class="alliance-link">
                                            <?= h((string) ($alliance['name'] ?? 'Unknown')) ?>
                                            <?php if ($row['is_nap']): ?>
                                                <span class="nap-badge">NAP</span>
                                            <?php endif; ?>
                                        </a>
                                    </td>
                                    <td data-label="Tag"><?= h((string) ($alliance['tag'] ?? '—')) ?></td>
                                    <td data-label="Power"><?= h(formatPower($alliance['power'] ?? null)) ?></td>
                                    <td data-label="Median Power"><?= h(formatPower($alliance['median_power'] ?? null)) ?></td>
                                    <td data-label="Total Members"><?= h(isset($alliance['members']) ? (string) $alliance['members'] : '—') ?></td>
                                    <td data-label="1d" class="<?= powerChangeClass($row['change1d']) ?>"><?= h(formatPowerChange($row['change1d'])) ?></td>
                                    <td data-label="3d" class="<?= powerChangeClass($row['change3d']) ?>"><?= h(formatPowerChange($row['change3d'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($allianceBarChart !== null): ?>
        <section class="panel">
            <h2>Top 10 — player power vs median</h2>
            <p class="muted panel-subtitle">Top player and median member power (not calculated locally).</p>
            <div class="chart-wrap">
                <canvas id="alliance-bar-chart" height="157" aria-label="Top 10 alliances top player and median power bar chart"></canvas>
            </div>
        </section>
        <?php endif; ?>

        <?php renderSiteFooter(); ?>
    </div>

    <?php if ($allianceBarChart !== null): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        window.allianceBarChartData = <?= json_encode($allianceBarChart, JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <?php endif; ?>
    <script src="<?= assetUrl('assets/app.js') ?>"></script>
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
