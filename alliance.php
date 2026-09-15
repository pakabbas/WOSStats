<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$alliances = loadJson(ALLIANCES_FILE, []);
$history = loadJson(HISTORY_FILE, []);
$requestedId = (string) ($_GET['id'] ?? '');

$alliance = null;
foreach ($alliances as $item) {
    if ((string) ($item['id'] ?? '') === $requestedId || allianceKey($item) === $requestedId) {
        $alliance = $item;
        break;
    }
}

if ($alliance === null) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Alliance Not Found</title>
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>
        <div class="container">
            <div class="panel">
                <h1>Alliance not found</h1>
                <p><a href="index.php">Back to dashboard</a></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$allianceKey = allianceKey($alliance);
$powerHistory = getAllianceHistory($history, $allianceKey);
$rankChange24h = calculateRankChange($history, $alliance, 24);
$rankChange7d = calculateRankChange($history, $alliance, 24 * 7);
$isNap = isNapAlliance($alliance, $config['nap_alliances'] ?? []);
$chartLabels = array_map(static fn(array $point): string => formatDisplayDate($point['timestamp']), $powerHistory);
$chartValues = array_map(static fn(array $point): int => $point['power'], $powerHistory);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h((string) ($alliance['name'] ?? 'Alliance')) ?> — Details</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
</head>
<body>
    <div class="container">
        <p class="back-link"><a href="index.php">← Back to dashboard</a></p>

        <header class="page-header">
            <h1>
                <?= h((string) ($alliance['name'] ?? 'Unknown Alliance')) ?>
                <?php if ($isNap): ?>
                    <span class="nap-badge">NAP</span>
                <?php endif; ?>
            </h1>
            <p class="subtitle">Tag: <strong>[<?= h((string) ($alliance['tag'] ?? '—')) ?>]</strong></p>
        </header>

        <section class="stats-grid stats-grid-compact">
            <div class="stat-card">
                <span class="stat-label">Current Rank</span>
                <span class="stat-value">#<?= h((string) ($alliance['rank'] ?? '—')) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Current Power</span>
                <span class="stat-value"><?= h(formatPower($alliance['power'] ?? null)) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Members</span>
                <span class="stat-value"><?= h(isset($alliance['members']) ? (string) $alliance['members'] : '—') ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Last Updated</span>
                <span class="stat-value stat-value-small"><?= h(formatDisplayDate($alliance['updated_at'] ?? null)) ?></span>
            </div>
        </section>

        <section class="panel">
            <h2>Rank Change</h2>
            <div class="detail-grid">
                <div><span class="stat-label">24 hours</span><strong><?= h(formatRankChange($rankChange24h)) ?></strong></div>
                <div><span class="stat-label">7 days</span><strong><?= h(formatRankChange($rankChange7d)) ?></strong></div>
            </div>
        </section>

        <section class="panel">
            <h2>Power History</h2>
            <?php if ($powerHistory === []): ?>
                <p class="muted">Not enough historical data yet. Run updates over time to build a chart.</p>
            <?php else: ?>
                <canvas id="power-chart" height="120" aria-label="Alliance power history chart"></canvas>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($powerHistory !== []): ?>
    <script>
        window.allianceChartData = {
            labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
            values: <?= json_encode($chartValues, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="assets/app.js"></script>
    <?php endif; ?>
</body>
</html>
