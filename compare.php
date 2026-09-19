<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$alliances = loadJson(ALLIANCES_FILE, []);
$history = loadJson(HISTORY_FILE, []);
$players = loadJson(PLAYERS_FILE, []);
$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));

$alliances = enrichAlliancesMaxPowerFromPlayers(
    is_array($alliances) ? $alliances : [],
    is_array($players) ? $players : []
);
$players = is_array($players) ? $players : [];

usort($alliances, static fn(array $a, array $b): int => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));

$idA = (string) ($_GET['a'] ?? '');
$idB = (string) ($_GET['b'] ?? '');

$allianceA = findAllianceByRequestId($alliances, $idA);
$allianceB = findAllianceByRequestId($alliances, $idB);

$historyA = $allianceA !== null ? getAllianceHistory($history, allianceKey($allianceA)) : [];
$historyB = $allianceB !== null ? getAllianceHistory($history, allianceKey($allianceB)) : [];

$chartPayload = null;
$summary = null;
$threatA = null;
$threatB = null;
$whalesA = null;
$whalesB = null;

if ($allianceA !== null && $allianceB !== null) {
    $chartPayload = buildCompareChartData($historyA, $historyB, $allianceA, $allianceB);
    $threatA = allianceThreatStanding($allianceA, $alliances);
    $threatB = allianceThreatStanding($allianceB, $alliances);
    $whalesA = allianceWhaleStats($allianceA, $players, 3.0);
    $whalesB = allianceWhaleStats($allianceB, $players, 3.0);
    $summary = [
        'power_diff' => isset($allianceA['power'], $allianceB['power'])
            ? (int) $allianceA['power'] - (int) $allianceB['power']
            : null,
        'rank_diff' => isset($allianceA['rank'], $allianceB['rank'])
            ? (int) $allianceB['rank'] - (int) $allianceA['rank']
            : null,
        'members_diff' => isset($allianceA['members'], $allianceB['members'])
            ? (int) $allianceA['members'] - (int) $allianceB['members']
            : null,
        'growth_a' => powerGrowth($historyA),
        'growth_b' => powerGrowth($historyB),
        'whale_diff' => $whalesA['whale_count'] - $whalesB['whale_count'],
        'threat_score_diff' => isset($threatA['score'], $threatB['score'])
            ? $threatA['score'] - $threatB['score']
            : null,
    ];
}

function findAllianceByRequestId(array $alliances, string $requestedId): ?array
{
    if ($requestedId === '') {
        return null;
    }

    foreach ($alliances as $item) {
        if ((string) ($item['id'] ?? '') === $requestedId || allianceKey($item) === $requestedId) {
            return $item;
        }
    }

    return null;
}

function powerGrowth(array $points): ?int
{
    if (count($points) < 2) {
        return null;
    }

    $first = $points[0]['power'] ?? null;
    $last = $points[count($points) - 1]['power'] ?? null;
    if ($first === null || $last === null) {
        return null;
    }

    return (int) $last - (int) $first;
}

function buildCompareChartData(array $historyA, array $historyB, array $allianceA, array $allianceB): array
{
    $mapA = [];
    foreach ($historyA as $point) {
        $mapA[$point['timestamp']] = $point['power'];
    }

    $mapB = [];
    foreach ($historyB as $point) {
        $mapB[$point['timestamp']] = $point['power'];
    }

    $labels = array_values(array_unique(array_merge(array_keys($mapA), array_keys($mapB))));
    sort($labels);

    $valuesA = [];
    $valuesB = [];
    foreach ($labels as $label) {
        $valuesA[] = $mapA[$label] ?? null;
        $valuesB[] = $mapB[$label] ?? null;
    }

    $labelA = trim(($allianceA['tag'] ?? '') !== '' ? '[' . $allianceA['tag'] . '] ' . ($allianceA['name'] ?? '') : (string) ($allianceA['name'] ?? 'Alliance A'));
    $labelB = trim(($allianceB['tag'] ?? '') !== '' ? '[' . $allianceB['tag'] . '] ' . ($allianceB['name'] ?? '') : (string) ($allianceB['name'] ?? 'Alliance B'));

    return [
        'labels' => array_map(static fn(string $ts): string => formatDisplayDate($ts), $labels),
        'seriesA' => [
            'label' => $labelA,
            'values' => $valuesA,
        ],
        'seriesB' => [
            'label' => $labelB,
            'values' => $valuesB,
        ],
    ];
}

function formatSignedPower(?int $value): string
{
    if ($value === null) {
        return 'N/A';
    }

    $prefix = $value > 0 ? '+' : '';
    return $prefix . formatPower($value);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <title>Compare Alliances — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="container">
        <?php renderSiteNav('compare'); ?>

        <header class="page-header">
            <p class="eyebrow">WHITEOUT SURVIVAL</p>
            <h1>Compare Alliances</h1>
            <p class="subtitle">Pick two alliances to compare power, whales, and threat.</p>
        </header>

        <section class="panel">
            <form method="get" action="compare.php" class="compare-form">
                <label class="compare-field">
                    <span class="stat-label">Alliance A</span>
                    <select name="a" required>
                        <option value="">Select alliance…</option>
                        <?php foreach ($alliances as $alliance): ?>
                            <?php $optId = (string) ($alliance['id'] ?? allianceKey($alliance)); ?>
                            <option value="<?= h($optId) ?>" <?= $optId === $idA ? 'selected' : '' ?>>
                                #<?= h((string) ($alliance['rank'] ?? '—')) ?>
                                [<?= h((string) ($alliance['tag'] ?? '—')) ?>]
                                <?= h((string) ($alliance['name'] ?? 'Unknown')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="compare-field">
                    <span class="stat-label">Alliance B</span>
                    <select name="b" required>
                        <option value="">Select alliance…</option>
                        <?php foreach ($alliances as $alliance): ?>
                            <?php $optId = (string) ($alliance['id'] ?? allianceKey($alliance)); ?>
                            <option value="<?= h($optId) ?>" <?= $optId === $idB ? 'selected' : '' ?>>
                                #<?= h((string) ($alliance['rank'] ?? '—')) ?>
                                [<?= h((string) ($alliance['tag'] ?? '—')) ?>]
                                <?= h((string) ($alliance['name'] ?? 'Unknown')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="compare-actions">
                    <button type="submit" class="btn-primary">Compare</button>
                </div>
            </form>
        </section>

        <?php if ($allianceA !== null && $allianceB !== null && $summary !== null): ?>
            <?php if ($idA === $idB): ?>
                <p class="banner banner-warning">Choose two different alliances to compare.</p>
            <?php else: ?>
                <section class="stats-grid">
                    <div class="stat-card">
                        <span class="stat-label"><?= h((string) ($allianceA['tag'] ?? $allianceA['name'] ?? 'A')) ?> Power</span>
                        <span class="stat-value"><?= h(formatPower($allianceA['power'] ?? null)) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label"><?= h((string) ($allianceB['tag'] ?? $allianceB['name'] ?? 'B')) ?> Power</span>
                        <span class="stat-value"><?= h(formatPower($allianceB['power'] ?? null)) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Power Difference (A−B)</span>
                        <span class="stat-value <?= ($summary['power_diff'] ?? 0) > 0 ? 'positive' : ((($summary['power_diff'] ?? 0) < 0) ? 'negative' : '') ?>">
                            <?= h(formatSignedPower($summary['power_diff'])) ?>
                        </span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Rank Edge (A vs B)</span>
                        <span class="stat-value">
                            <?php if ($summary['rank_diff'] === null): ?>
                                N/A
                            <?php elseif ($summary['rank_diff'] > 0): ?>
                                A is <?= h((string) $summary['rank_diff']) ?> rank(s) higher
                            <?php elseif ($summary['rank_diff'] < 0): ?>
                                B is <?= h((string) abs($summary['rank_diff'])) ?> rank(s) higher
                            <?php else: ?>
                                Same rank
                            <?php endif; ?>
                        </span>
                    </div>
                </section>

                <section class="panel">
                    <h2>Head-to-head</h2>
                    <p class="muted panel-subtitle">
                        Whale = player power ≥ 3× alliance median
                        <?php if (($whalesA['threshold'] ?? null) !== null || ($whalesB['threshold'] ?? null) !== null): ?>
                            · thresholds
                            <?= h((string) ($allianceA['tag'] ?? 'A')) ?> <?= h(formatPower($whalesA['threshold'] ?? null)) ?>,
                            <?= h((string) ($allianceB['tag'] ?? 'B')) ?> <?= h(formatPower($whalesB['threshold'] ?? null)) ?>
                        <?php endif; ?>
                    </p>
                    <div class="table-wrap">
                        <table class="alliance-table compare-metrics-table">
                            <thead>
                                <tr>
                                    <th>Metric</th>
                                    <th><?= h((string) ($allianceA['tag'] ?? $allianceA['name'] ?? 'A')) ?></th>
                                    <th><?= h((string) ($allianceB['tag'] ?? $allianceB['name'] ?? 'B')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td data-label="Metric">State rank</td>
                                    <td data-label="A">#<?= h((string) ($allianceA['rank'] ?? '—')) ?><?= isNapAlliance($allianceA) ? ' · NAP' : '' ?></td>
                                    <td data-label="B">#<?= h((string) ($allianceB['rank'] ?? '—')) ?><?= isNapAlliance($allianceB) ? ' · NAP' : '' ?></td>
                                </tr>
                                <tr>
                                    <td data-label="Metric">Total power</td>
                                    <td data-label="A"><?= h(formatPower($allianceA['power'] ?? null)) ?></td>
                                    <td data-label="B"><?= h(formatPower($allianceB['power'] ?? null)) ?></td>
                                </tr>
                                <tr>
                                    <td data-label="Metric">Members</td>
                                    <td data-label="A"><?= h(isset($allianceA['members']) ? (string) $allianceA['members'] : '—') ?></td>
                                    <td data-label="B"><?= h(isset($allianceB['members']) ? (string) $allianceB['members'] : '—') ?></td>
                                </tr>
                                <tr>
                                    <td data-label="Metric">Top player</td>
                                    <td data-label="A"><?= h(formatPower($allianceA['max_power'] ?? null)) ?></td>
                                    <td data-label="B"><?= h(formatPower($allianceB['max_power'] ?? null)) ?></td>
                                </tr>
                                <tr>
                                    <td data-label="Metric">Median</td>
                                    <td data-label="A"><?= h(formatPower($allianceA['median_power'] ?? null)) ?></td>
                                    <td data-label="B"><?= h(formatPower($allianceB['median_power'] ?? null)) ?></td>
                                </tr>
                                <tr>
                                    <td data-label="Metric">Avg / member</td>
                                    <td data-label="A"><?= h(formatPower(allianceAveragePower($allianceA))) ?></td>
                                    <td data-label="B"><?= h(formatPower(allianceAveragePower($allianceB))) ?></td>
                                </tr>
                                <tr>
                                    <td data-label="Metric">Whales (3× median)</td>
                                    <td data-label="A"><strong><?= h((string) ($whalesA['whale_count'] ?? 0)) ?></strong><?php if (($whalesA['known_count'] ?? 0) > 0): ?> <span class="muted">/ <?= (int) $whalesA['known_count'] ?> known</span><?php endif; ?></td>
                                    <td data-label="B"><strong><?= h((string) ($whalesB['whale_count'] ?? 0)) ?></strong><?php if (($whalesB['known_count'] ?? 0) > 0): ?> <span class="muted">/ <?= (int) $whalesB['known_count'] ?> known</span><?php endif; ?></td>
                                </tr>
                                <tr>
                                    <td data-label="Metric">Threat score</td>
                                    <td data-label="A" class="ratio-cell <?= h(threatRatioClass($threatA['score'] ?? null)) ?>" title="<?= h(formatThreatRatioTooltip($threatA['metrics'] ?? null)) ?>"><?= h(formatTopHeavyRatio($threatA['score'] ?? null)) ?></td>
                                    <td data-label="B" class="ratio-cell <?= h(threatRatioClass($threatB['score'] ?? null)) ?>" title="<?= h(formatThreatRatioTooltip($threatB['metrics'] ?? null)) ?>"><?= h(formatTopHeavyRatio($threatB['score'] ?? null)) ?></td>
                                </tr>
                                <tr>
                                    <td data-label="Metric">Threat rank</td>
                                    <td data-label="A"><?= ($threatA['rank'] ?? null) !== null ? '#' . (int) $threatA['rank'] . ' / ' . (int) $threatA['total'] : '—' ?></td>
                                    <td data-label="B"><?= ($threatB['rank'] ?? null) !== null ? '#' . (int) $threatB['rank'] . ' / ' . (int) $threatB['total'] : '—' ?></td>
                                </tr>
                                <tr>
                                    <td data-label="Metric">Spike (max ÷ median)</td>
                                    <td data-label="A"><?= h(formatTopHeavyRatio(isset($threatA['metrics']['spike']) ? (float) $threatA['metrics']['spike'] : null)) ?></td>
                                    <td data-label="B"><?= h(formatTopHeavyRatio(isset($threatB['metrics']['spike']) ? (float) $threatB['metrics']['spike'] : null)) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <?php if (($whalesA['whales'] ?? []) !== [] || ($whalesB['whales'] ?? []) !== []): ?>
                        <div class="compare-whale-lists">
                            <div>
                                <h3><?= h((string) ($allianceA['tag'] ?? 'A')) ?> whales</h3>
                                <?php if (($whalesA['whales'] ?? []) === []): ?>
                                    <p class="muted">None detected from known players.</p>
                                <?php else: ?>
                                    <ul class="compare-whale-list">
                                        <?php foreach ($whalesA['whales'] as $whale): ?>
                                            <li>
                                                <span><?= h((string) $whale['name']) ?></span>
                                                <strong><?= h(formatPower($whale['power'])) ?></strong>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h3><?= h((string) ($allianceB['tag'] ?? 'B')) ?> whales</h3>
                                <?php if (($whalesB['whales'] ?? []) === []): ?>
                                    <p class="muted">None detected from known players.</p>
                                <?php else: ?>
                                    <ul class="compare-whale-list">
                                        <?php foreach ($whalesB['whales'] as $whale): ?>
                                            <li>
                                                <span><?= h((string) $whale['name']) ?></span>
                                                <strong><?= h(formatPower($whale['power'])) ?></strong>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="panel">
                    <h2>Growth Since First Snapshot</h2>
                    <div class="detail-grid">
                        <div>
                            <span class="stat-label"><?= h((string) ($allianceA['name'] ?? 'Alliance A')) ?></span>
                            <strong class="<?= ($summary['growth_a'] ?? 0) > 0 ? 'positive' : ((($summary['growth_a'] ?? 0) < 0) ? 'negative' : '') ?>">
                                <?= h(formatSignedPower($summary['growth_a'])) ?>
                            </strong>
                        </div>
                        <div>
                            <span class="stat-label"><?= h((string) ($allianceB['name'] ?? 'Alliance B')) ?></span>
                            <strong class="<?= ($summary['growth_b'] ?? 0) > 0 ? 'positive' : ((($summary['growth_b'] ?? 0) < 0) ? 'negative' : '') ?>">
                                <?= h(formatSignedPower($summary['growth_b'])) ?>
                            </strong>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <h2>Power Growth Comparison</h2>
                    <?php if (count($historyA) < 1 && count($historyB) < 1): ?>
                        <p class="muted">No historical snapshots yet. The daily cron will build this chart over time.</p>
                    <?php else: ?>
                        <canvas id="compare-chart" height="140" aria-label="Alliance comparison chart"></canvas>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
        <?php renderSiteFooter(); ?>
    </div>

    <?php if ($chartPayload !== null && $idA !== $idB): ?>
    <script>
        window.compareChartData = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="<?= assetUrl('assets/app.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
