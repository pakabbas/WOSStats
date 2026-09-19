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
        <?php renderFaviconTags(); ?>
        <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
    </head>
    <body>
        <div class="container">
            <?php renderSiteNav(); ?>
            <div class="panel">
                <h1>Alliance not found</h1>
                <p><a href="index.php">Back to dashboard</a></p>
            </div>
            <?php renderSiteFooter(); ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$allianceKey = allianceKey($alliance);
$detailId = (string) ($alliance['id'] ?? $allianceKey);
$powerHistory = getAllianceHistory($history, $allianceKey);
$rankChange6h = calculateRankChange($history, $alliance, 6);
$rankChange7d = calculateRankChange($history, $alliance, 24 * 7);
$isNap = isNapAlliance($alliance, $config['nap_alliances'] ?? []);
$chartLabels = array_map(static fn(array $point): string => formatDisplayDate($point['timestamp']), $powerHistory);
$chartValues = array_map(static fn(array $point): int => $point['power'], $powerHistory);
$growthSinceStart = null;
if (count($powerHistory) >= 2) {
    $growthSinceStart = (int) $powerHistory[count($powerHistory) - 1]['power'] - (int) $powerHistory[0]['power'];
}

$showRoster = isTrackedRosterAlliance($detailId);
$roster = [];
$rosterRows = [];
$rosterUpdated = null;
if ($showRoster) {
    $roster = loadAllianceRoster($detailId);
    $rostersHistory = loadJson(ROSTERS_HISTORY_FILE, []);
    $rosterHistory = getRosterHistory(is_array($rostersHistory) ? $rostersHistory : [], $detailId);
    $statePlayers = loadJson(PLAYERS_FILE, []);
    $playersHistory = loadJson(PLAYERS_HISTORY_FILE, []);
    $rosterPowers = loadRosterPowers($detailId);
    $rosterRows = buildRosterProgressRows(
        $roster,
        $rosterHistory,
        is_array($statePlayers) ? $statePlayers : [],
        is_array($playersHistory) ? $playersHistory : [],
        $rosterPowers
    );
    $rosterUpdated = $roster['updated_at'] ?? null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <title><?= h((string) ($alliance['name'] ?? 'Alliance')) ?> — Details</title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="container">
        <?php renderSiteNav('alliance'); ?>

        <header class="page-header">
            <p class="eyebrow">ALLIANCE DETAILS</p>
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
                <span class="stat-value stat-value-small"><?= h(formatDisplayDateOnly($alliance['updated_at'] ?? null)) ?></span>
            </div>
        </section>

        <section class="panel">
            <h2>Rank Change</h2>
            <div class="detail-grid">
                <div><span class="stat-label">6 hours</span><strong><?= h(formatRankChange($rankChange6h)) ?></strong></div>
                <div><span class="stat-label">7 days</span><strong><?= h(formatRankChange($rankChange7d)) ?></strong></div>
                <div>
                    <span class="stat-label">Growth since first snapshot</span>
                    <strong class="<?= ($growthSinceStart ?? 0) > 0 ? 'positive' : ((($growthSinceStart ?? 0) < 0) ? 'negative' : '') ?>">
                        <?php if ($growthSinceStart === null): ?>
                            N/A
                        <?php else: ?>
                            <?= $growthSinceStart > 0 ? '+' : '' ?><?= h(formatPower($growthSinceStart)) ?>
                        <?php endif; ?>
                    </strong>
                </div>
                <div>
                    <span class="stat-label">Snapshots recorded</span>
                    <strong><?= count($powerHistory) ?></strong>
                </div>
            </div>
        </section>

        <section class="panel">
            <h2>Power Growth</h2>
            <?php if ($powerHistory === []): ?>
                <p class="muted">Not enough historical data yet. Daily updates will build this chart over time.</p>
            <?php else: ?>
                <canvas id="power-chart" height="120" aria-label="Alliance power history chart"></canvas>
            <?php endif; ?>
        </section>

        <?php if ($showRoster): ?>
        <section class="panel">
            <div class="panel-toolbar">
                <div>
                    <h2 class="panel-title-inline">Member Progress</h2>
                    <p class="muted panel-subtitle">
                        Furnace + power (from state top 200 when available)<?= $rosterUpdated ? ' · roster ' . h(formatDisplayDate($rosterUpdated)) : '' ?>
                    </p>
                </div>
                <input type="search" id="roster-search" class="search-input" placeholder="Search name or UID…" aria-label="Search members">
            </div>

            <?php if ($rosterRows === []): ?>
                <p class="muted">No member roster loaded yet. Run an update after placing the alliance members dump.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="alliance-table responsive-table" id="roster-table"
                           data-aid="<?= h($detailId) ?>"
                           data-page-size="20">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Player</th>
                                <th>Furnace</th>
                                <th>24h LV</th>
                                <th>7d LV</th>
                                <th>Power</th>
                                <th>Power 24h</th>
                                <th>Jump</th>
                                <th>Coords</th>
                                <th>UID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rosterRows as $index => $row): ?>
                                <?php
                                $member = $row['member'];
                                $lvChange24 = $row['lv_change_24h'];
                                $lvChange7d = $row['lv_change_7d'];
                                $powerChange24 = $row['power_change_24h'] ?? null;
                                $coords = (isset($member['x'], $member['y']))
                                    ? ($member['x'] . ', ' . $member['y'])
                                    : '—';
                                $uid = (string) ($member['id'] ?? '');
                                ?>
                                <tr
                                    class="roster-row"
                                    data-name="<?= h(strtolower((string) ($member['name'] ?? ''))) ?>"
                                    data-id="<?= h(strtolower($uid)) ?>"
                                    data-uid="<?= h($uid) ?>"
                                    data-index="<?= $index ?>"
                                    <?= $index >= 20 ? 'hidden' : '' ?>
                                >
                                    <td data-label="#"><?= $index + 1 ?></td>
                                    <td data-label="Player">
                                        <span class="roster-name"><?= h((string) ($member['name'] ?? 'Unknown')) ?></span>
                                        <?php if (!empty($member['office'])): ?>
                                            <span class="office-badge"><?= h((string) $member['office']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Furnace"><?= h(isset($member['lv']) ? (string) $member['lv'] : '—') ?></td>
                                    <td data-label="24h LV" class="<?= ($lvChange24 ?? 0) > 0 ? 'positive' : ((($lvChange24 ?? 0) < 0) ? 'negative' : '') ?>">
                                        <?= h(formatLevelChange($lvChange24)) ?>
                                    </td>
                                    <td data-label="7d LV" class="<?= ($lvChange7d ?? 0) > 0 ? 'positive' : ((($lvChange7d ?? 0) < 0) ? 'negative' : '') ?>">
                                        <?= h(formatLevelChange($lvChange7d)) ?>
                                    </td>
                                    <td data-label="Power"><?= h(formatPower($row['power'] ?? null)) ?></td>
                                    <td data-label="Power 24h" class="<?= ($powerChange24 ?? 0) > 0 ? 'positive' : ((($powerChange24 ?? 0) < 0) ? 'negative' : '') ?>">
                                        <?= h(formatPowerChange($powerChange24)) ?>
                                    </td>
                                    <td data-label="Jump"><?= h(formatJumpPct(isset($row['last_jump_pct']) ? (float) $row['last_jump_pct'] : null)) ?></td>
                                    <td data-label="Coords" class="muted-cell"><?= h($coords) ?></td>
                                    <td data-label="UID" class="muted-cell"><?= h($uid !== '' ? $uid : '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="roster-actions">
                    <?php if (count($rosterRows) > 20): ?>
                        <button type="button" id="roster-see-more" class="btn-primary">
                            See more (<?= count($rosterRows) - 20 ?> left)
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Compare</h2>
            <p class="muted">Compare this alliance’s growth against another alliance in the state.</p>
            <p><a class="btn-primary" href="compare.php?a=<?= urlencode((string) $detailId) ?>">Compare with another alliance</a></p>
        </section>
        <?php renderSiteFooter(); ?>
    </div>

    <?php if ($powerHistory !== []): ?>
    <script>
        window.allianceChartData = {
            labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
            values: <?= json_encode($chartValues, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <?php endif; ?>
    <script src="<?= assetUrl('assets/app.js') ?>"></script>
    <?php if ($showRoster && $rosterRows !== []): ?>
    <script src="<?= assetUrl('assets/roster.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
