<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$alliances = loadJson(ALLIANCES_FILE, []);
$players = loadJson(PLAYERS_FILE, []);
$napTags = $config['nap_alliances'] ?? [];
$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$alliances = enrichAlliancesMaxPowerFromPlayers(
    is_array($alliances) ? $alliances : [],
    is_array($players) ? $players : []
);
$lastUpdated = getLastUpdated($alliances);
$sampleMode = !empty($config['sample_mode']);

usort($alliances, static fn(array $a, array $b): int => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));

$showMedianPower = false;
$showMaxPower = false;
foreach ($alliances as $alliance) {
    if (isset($alliance['median_power']) && $alliance['median_power'] !== null && $alliance['median_power'] !== '') {
        $showMedianPower = true;
    }
    if (isset($alliance['max_power']) && $alliance['max_power'] !== null && $alliance['max_power'] !== '') {
        $showMaxPower = true;
    }
}

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

$referenceMedian = allianceThreatReferenceMedian($alliances);

$rows = [];
foreach ($alliances as $alliance) {
    $metrics = allianceThreatMetrics($alliance, $referenceMedian);
    $rows[] = [
        'alliance' => $alliance,
        'is_nap' => isNapAlliance($alliance, $napTags),
        'avg_power' => allianceAveragePower($alliance),
        'threat_metrics' => $metrics,
        'threat_ratio' => $metrics['score'] ?? null,
    ];
}

usort($rows, static function (array $a, array $b): int {
    $scoreA = $a['threat_ratio'];
    $scoreB = $b['threat_ratio'];

    if ($scoreA === null && $scoreB === null) {
        return ($a['alliance']['rank'] ?? PHP_INT_MAX) <=> ($b['alliance']['rank'] ?? PHP_INT_MAX);
    }
    if ($scoreA === null) {
        return 1;
    }
    if ($scoreB === null) {
        return -1;
    }
    if ($scoreB !== $scoreA) {
        return $scoreB <=> $scoreA;
    }

    return ($a['alliance']['rank'] ?? PHP_INT_MAX) <=> ($b['alliance']['rank'] ?? PHP_INT_MAX);
});

$tableColspan = 9;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <title>Threat Board — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body>
    <div class="container">
        <?php renderSiteNav('threat'); ?>

        <header class="page-header">
            <div>
                <p class="eyebrow">WHITEOUT SURVIVAL</p>
                <h1>Threat Board</h1>
                <p class="subtitle"><?= h($stateName) ?> · Size, depth, and top-heavy shape</p>
                <?php if ($sampleMode): ?>
                    <p class="banner banner-warning">Development mode: displaying sample data (sample_mode enabled)</p>
                <?php endif; ?>
            </div>
        </header>

        <section class="stats-grid stats-grid-compact">
            <div class="stat-card">
                <span class="stat-label">Alliances</span>
                <span class="stat-value"><?= count($alliances) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Last Updated</span>
                <span class="stat-value stat-value-small"><?= h(formatDisplayDateOnly($lastUpdated)) ?></span>
            </div>
        </section>

        <section class="panel">
            <div class="panel-toolbar">
                <div class="filter-group" id="nap-filter">
                    <button type="button" class="filter-btn active" data-filter="all">All</button>
                    <button type="button" class="filter-btn" data-filter="nap">NAP</button>
                    <button type="button" class="filter-btn" data-filter="non-nap">Non-NAP</button>
                </div>
                <input type="search" id="threat-search" class="search-input" placeholder="Search by name or tag…" aria-label="Search alliances">
            </div>

            <?php if ($showThreatBoard): ?>
                <p class="muted panel-subtitle threat-board-note">
                    Ratio = threat score: √spike + roster depth + upper mass · spike = max ÷ median (one whale); depth = strong median vs state (many whales, e.g. LUX).
                </p>
            <?php endif; ?>

            <div class="table-wrap">
                <table class="alliance-table responsive-table threat-table" id="threat-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Alliance</th>
                            <th>Tag</th>
                            <th>Power</th>
                            <th title="Strongest member power">Top player</th>
                            <th title="Median member power">Median</th>
                            <th title="Total power ÷ members">Avg / member</th>
                            <th class="ratio-col" title="Threat score — sorted highest first">Ratio</th>
                            <th>Members</th>
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
                                $metrics = $row['threat_metrics'];
                                $ratio = $row['threat_ratio'];
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
                                    <td data-label="Top player"><?= h(formatPower($alliance['max_power'] ?? null)) ?></td>
                                    <td data-label="Median"><?= h(formatPower($alliance['median_power'] ?? null)) ?></td>
                                    <td data-label="Avg / member"><?= h(formatPower($row['avg_power'])) ?></td>
                                    <td data-label="Ratio" class="ratio-cell <?= h(threatRatioClass($ratio)) ?>" title="<?= h(formatThreatRatioTooltip($metrics)) ?>"><?= h(formatTopHeavyRatio($ratio)) ?></td>
                                    <td data-label="Members"><?= h(isset($alliance['members']) ? (string) $alliance['members'] : '—') ?></td>
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
