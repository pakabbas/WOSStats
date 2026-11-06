<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? '4627'));
$ourKid = (string) ($config['state_id'] ?? '4627');
$oppKid = SVS_OPPONENT_KID;

$forceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1' && isAppAdmin();

$ourAlliances = loadJson(ALLIANCES_FILE, []);
$ourPlayers = loadJson(PLAYERS_FILE, []);
$ourAlliances = is_array($ourAlliances) ? $ourAlliances : [];
$ourPlayers = is_array($ourPlayers) ? $ourPlayers : [];

$opponent = loadSvsOpponentState($oppKid, $forceRefresh);
$us = buildSvsStateProfile($ourKid, $ourAlliances, $ourPlayers);
$them = buildSvsStateProfile(
    $oppKid,
    is_array($opponent['alliances'] ?? null) ? $opponent['alliances'] : [],
    is_array($opponent['players'] ?? null) ? $opponent['players'] : []
);
$insights = buildSvsInsights($us, $them);

$arenaCmp = loadSvsArenaComparison(
    $ourPlayers,
    is_array($opponent['players'] ?? null) ? $opponent['players'] : [],
    $forceRefresh,
    SVS_ARENA_SCOUT_LIMIT
);
$arenaUs = is_array($arenaCmp['us'] ?? null) ? $arenaCmp['us'] : [];
$arenaThem = is_array($arenaCmp['them'] ?? null) ? $arenaCmp['them'] : [];
$arenaUsPlayers = is_array($arenaUs['players'] ?? null) ? $arenaUs['players'] : [];
$arenaThemPlayers = is_array($arenaThem['players'] ?? null) ? $arenaThem['players'] : [];
$arenaH2H = [];
$arenaPairs = max(count($arenaUsPlayers), count($arenaThemPlayers));
for ($i = 0; $i < $arenaPairs; $i++) {
    $a = $arenaUsPlayers[$i] ?? null;
    $b = $arenaThemPlayers[$i] ?? null;
    $as = is_array($a['arena'] ?? null) ? (int) ($a['arena']['score'] ?? 0) : null;
    $bs = is_array($b['arena'] ?? null) ? (int) ($b['arena']['score'] ?? 0) : null;
    $arenaH2H[] = [
        'rank' => $i + 1,
        'us' => $a,
        'them' => $b,
        'diff' => ($as !== null && $bs !== null) ? $as - $bs : null,
        'winner' => ($as !== null && $bs !== null)
            ? ($as > $bs ? 'us' : ($bs > $as ? 'them' : 'tie'))
            : 'tie',
    ];
}
$arenaScoreDiff = null;
if (($arenaUs['avg_score'] ?? null) !== null && ($arenaThem['avg_score'] ?? null) !== null) {
    $arenaScoreDiff = (float) $arenaUs['avg_score'] - (float) $arenaThem['avg_score'];
}

$ourUpdated = getLastUpdated($ourAlliances);
$oppUpdated = (string) ($opponent['fetched_at'] ?? '');
$oppSource = (string) ($opponent['source'] ?? '');
$oppError = $opponent['error'] ?? null;

$metricRows = [
    ['label' => 'Top 100 power (matchmaking)', 'key' => 'top100_sum', 'hint' => 'Reddit/wiki: primary SvS matchmaking signal'],
    ['label' => 'Top 150 power', 'key' => 'top150_sum', 'hint' => 'Dev AMA also cited top ~150'],
    ['label' => 'Top 200 power', 'key' => 'top200_sum', 'hint' => 'Full tracked board'],
    ['label' => 'Top 1 whale', 'key' => 'top1', 'hint' => 'Strongest solo / garrison threat'],
    ['label' => 'Top 5 whales', 'key' => 'top5_sum', 'hint' => 'Castle break / hold core'],
    ['label' => 'Top 10 power', 'key' => 'top10_sum', 'hint' => 'Primary rally-lead pool'],
    ['label' => 'Top 20 power', 'key' => 'top20_sum', 'hint' => 'Expanded battle leads'],
    ['label' => 'Top 50 power', 'key' => 'top50_sum', 'hint' => 'Depth behind whales'],
    ['label' => 'Avg top 100', 'key' => 'avg_top100', 'hint' => null],
    ['label' => 'Median top 100', 'key' => 'median_top100', 'hint' => null],
    ['label' => 'Top 100 floor', 'key' => 'min_top100', 'hint' => 'Weakest player still in top 100'],
    ['label' => 'Tracked alliance power', 'key' => 'state_alliance_power', 'hint' => 'Sum of top alliances board'],
    ['label' => 'Tracked alliance members', 'key' => 'state_members', 'hint' => null, 'format' => 'int'],
    ['label' => 'Top 2 alliances', 'key' => 'top2_alliance_power', 'hint' => 'Minimum SvS battle pair'],
    ['label' => 'Top 3 alliances', 'key' => 'top3_alliance_power', 'hint' => 'Castle + counters + turrets'],
    ['label' => 'Top 5 alliances', 'key' => 'top5_alliance_power', 'hint' => null],
];

$countRows = [
    ['label' => 'Players ≥ 100M', 'key' => 'whales_100m'],
    ['label' => 'Players ≥ 80M (rally leads)', 'key' => 'whales_80m'],
    ['label' => 'Players ≥ 60M', 'key' => 'whales_60m'],
    ['label' => 'Players ≥ 50M', 'key' => 'whales_50m'],
    ['label' => 'Furnace ≥ 30 (board)', 'key' => 'fc30_plus'],
    ['label' => 'Crystal+ (≥31 API / C1.1+)', 'key' => 'fc31_plus'],
];

$chartPayload = [
    'labels' => ['Top 1', 'Top 5', 'Top 10', 'Top 20', 'Top 50', 'Top 100'],
    'us' => [
        (int) ($us['top1'] ?? 0),
        (int) ($us['top5_sum'] ?? 0),
        (int) ($us['top10_sum'] ?? 0),
        (int) ($us['top20_sum'] ?? 0),
        (int) ($us['top50_sum'] ?? 0),
        (int) ($us['top100_sum'] ?? 0),
    ],
    'them' => [
        (int) ($them['top1'] ?? 0),
        (int) ($them['top5_sum'] ?? 0),
        (int) ($them['top10_sum'] ?? 0),
        (int) ($them['top20_sum'] ?? 0),
        (int) ($them['top50_sum'] ?? 0),
        (int) ($them['top100_sum'] ?? 0),
    ],
];

$furnaceLabels = array_values(array_unique(array_merge(
    array_keys($us['furnace_buckets'] ?? []),
    array_keys($them['furnace_buckets'] ?? [])
)));
sort($furnaceLabels, SORT_NUMERIC);
$furnaceChart = [
    'labels' => array_map(
        static fn(string $lv): string => formatFurnaceChartLabel((int) $lv),
        $furnaceLabels
    ),
    'raw' => array_map(static fn(string $lv): int => (int) $lv, $furnaceLabels),
    'us' => array_map(static fn(string $lv): int => (int) (($us['furnace_buckets'][$lv] ?? 0)), $furnaceLabels),
    'them' => array_map(static fn(string $lv): int => (int) (($them['furnace_buckets'][$lv] ?? 0)), $furnaceLabels),
];

$h2hUsPlayers = is_array($us['players'] ?? null) ? $us['players'] : [];
$h2hThemPlayers = is_array($them['players'] ?? null) ? $them['players'] : [];
$headToHead = [];
$h2hPageSize = 15;
$h2hMax = min(100, max(count($h2hUsPlayers), count($h2hThemPlayers)));
for ($i = 0; $i < $h2hMax; $i++) {
    $a = $h2hUsPlayers[$i] ?? null;
    $b = $h2hThemPlayers[$i] ?? null;
    if (!is_array($a) && !is_array($b)) {
        continue;
    }
    $ap = isset($a['power']) ? (int) $a['power'] : null;
    $bp = isset($b['power']) ? (int) $b['power'] : null;
    $headToHead[] = [
        'rank' => $i + 1,
        'us' => is_array($a) ? [
            'rank' => $a['rank'] ?? ($i + 1),
            'id' => $a['id'] ?? null,
            'name' => $a['name'] ?? null,
            'power' => $ap,
            'stove_lv' => isset($a['stove_lv']) ? (int) $a['stove_lv'] : null,
            'alliance_tag' => $a['alliance_tag'] ?? null,
        ] : null,
        'them' => is_array($b) ? [
            'rank' => $b['rank'] ?? ($i + 1),
            'id' => $b['id'] ?? null,
            'name' => $b['name'] ?? null,
            'power' => $bp,
            'stove_lv' => isset($b['stove_lv']) ? (int) $b['stove_lv'] : null,
            'alliance_tag' => $b['alliance_tag'] ?? null,
        ] : null,
        'diff' => ($ap !== null && $bp !== null) ? $ap - $bp : null,
    ];
}
$h2hTotal = count($headToHead);

function svsFormatMetric(mixed $value, string $format = 'power'): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    if ($format === 'int') {
        return number_format((int) $value);
    }
    if ($format === 'pct') {
        return number_format(((float) $value) * 100, 1) . '%';
    }
    return formatPower((int) $value);
}

function svsDiffClass(?int $diff): string
{
    if ($diff === null || $diff === 0) {
        return '';
    }
    return $diff > 0 ? 'positive' : 'negative';
}

function svsEdgeBadge(string $edge): string
{
    return match ($edge) {
        'us' => 'svs-edge-us',
        'them' => 'svs-edge-them',
        default => 'svs-edge-even',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <title>State 4642 — SvS Scout — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="container">
        <?php renderSiteNav('svs-4642'); ?>

        <header class="page-header svs-header">
            <p class="eyebrow">STATE VS STATE · COMPETITOR SCOUT</p>
            <h1>State 4642</h1>
            <p class="subtitle">
                Full comparison of <strong>4627</strong> vs <strong>4642</strong> using live leaderboards.
                Metrics prioritized from community SvS scouting (top 100/150 power, whale density, furnace depth, battle-alliance structure).
            </p>
        </header>

        <?php if ($oppError): ?>
            <p class="banner banner-warning">Opponent refresh warning: <?= h((string) $oppError) ?>. Showing <?= h($oppSource === 'stale-cache' ? 'cached' : 'partial') ?> data.</p>
        <?php endif; ?>

        <section class="svs-verdict panel <?= h(svsEdgeBadge((string) ($insights['edge'] ?? 'even'))) ?>">
            <div class="svs-verdict-main">
                <p class="stat-label">Matchup read</p>
                <h2><?= h((string) ($insights['verdict'] ?? 'Even')) ?></h2>
                <p class="muted">
                    Top 100 Δ
                    <strong class="<?= svsDiffClass($insights['top100_diff'] ?? null) ?>">
                        <?= h(($insights['top100_diff'] ?? null) === null ? '—' : (($insights['top100_diff'] >= 0 ? '+' : '') . formatPower($insights['top100_diff']))) ?>
                    </strong>
                    <?php if (($insights['top100_pct'] ?? null) !== null): ?>
                        <span class="muted">(<?= h(number_format((float) $insights['top100_pct'], 2)) ?>%)</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="svs-verdict-meta">
                <div>
                    <span class="stat-label">4627 board</span>
                    <span class="stat-value-sm"><?= h($ourUpdated ? formatDisplayDate($ourUpdated) : '—') ?></span>
                </div>
                <div>
                    <span class="stat-label">4642 scout</span>
                    <span class="stat-value-sm">
                        <?= h($oppUpdated !== '' ? formatDisplayDate($oppUpdated) : '—') ?>
                        <span class="muted">· <?= h($oppSource !== '' ? $oppSource : 'n/a') ?></span>
                    </span>
                </div>
                <?php if (isAppAdmin()): ?>
                    <a class="btn-secondary" href="state-4642.php?refresh=1">Refresh 4642 now</a>
                <?php endif; ?>
            </div>
        </section>

        <section class="stats-grid svs-hero-stats">
            <div class="stat-card">
                <span class="stat-label">4627 Top 100</span>
                <span class="stat-value"><?= h(formatPower($us['top100_sum'] ?? null)) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">4642 Top 100</span>
                <span class="stat-value"><?= h(formatPower($them['top100_sum'] ?? null)) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Top 5 whales (4627)</span>
                <span class="stat-value"><?= h(formatPower($us['top5_sum'] ?? null)) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Top 5 whales (4642)</span>
                <span class="stat-value"><?= h(formatPower($them['top5_sum'] ?? null)) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">≥80M rally leads</span>
                <span class="stat-value"><?= (int) ($us['whales_80m'] ?? 0) ?> <span class="muted">vs</span> <?= (int) ($them['whales_80m'] ?? 0) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Avg furnace scale (top 100)</span>
                <span class="stat-value"><?= h(isset($us['avg_furnace_top100']) ? number_format((float) $us['avg_furnace_top100'], 2) : '—') ?> <span class="muted">vs</span> <?= h(isset($them['avg_furnace_top100']) ? number_format((float) $them['avg_furnace_top100'], 2) : '—') ?></span>
                <span class="muted svs-furnace-note">31–34 = 30.1–30.4 · 35+ = Crystal</span>
            </div>
        </section>

        <section class="panel">
            <h2>Insights</h2>
            <p class="muted panel-subtitle">What community SvS scouts prioritize, applied to this matchup.</p>
            <ul class="svs-insight-list">
                <?php foreach (($insights['bullets'] ?? []) as $bullet): ?>
                    <li><?= h((string) $bullet) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="panel svs-h2h-panel">
            <div class="svs-h2h-heading">
                <div>
                    <h2>Head-to-head whales</h2>
                    <p class="muted panel-subtitle">
                        Same board rank compared. Green = we lead that slot, red = they lead.
                        Showing <span id="svs-h2h-visible-count"><?= (int) min($h2hPageSize, $h2hTotal) ?></span>
                        of <?= (int) $h2hTotal ?>.
                    </p>
                </div>
                <div class="svs-h2h-legend" aria-hidden="true">
                    <span class="svs-h2h-chip svs-h2h-chip-us">We lead</span>
                    <span class="svs-h2h-chip svs-h2h-chip-them">They lead</span>
                    <span class="svs-h2h-chip svs-h2h-chip-tie">Even</span>
                </div>
            </div>

            <div class="svs-h2h-colheads" aria-hidden="true">
                <span class="svs-h2h-colhead svs-h2h-colhead-us">Our state · 4627</span>
                <span class="svs-h2h-colhead svs-h2h-colhead-mid">Gap</span>
                <span class="svs-h2h-colhead svs-h2h-colhead-them">Enemy · 4642</span>
            </div>

            <div class="svs-h2h-list" id="svs-h2h-list" data-page-size="<?= (int) $h2hPageSize ?>" data-total="<?= (int) $h2hTotal ?>">
                <?php foreach ($headToHead as $row): ?>
                    <?php
                    $a = is_array($row['us'] ?? null) ? $row['us'] : [];
                    $b = is_array($row['them'] ?? null) ? $row['them'] : [];
                    $ap = isset($a['power']) ? (int) $a['power'] : null;
                    $bp = isset($b['power']) ? (int) $b['power'] : null;
                    $diff = $row['diff'] ?? null;
                    $winner = 'tie';
                    if ($diff !== null) {
                        if ($diff > 0) {
                            $winner = 'us';
                        } elseif ($diff < 0) {
                            $winner = 'them';
                        }
                    }
                    $maxPair = max($ap ?? 0, $bp ?? 0, 1);
                    $usBar = $ap !== null ? round(($ap / $maxPair) * 100) : 0;
                    $themBar = $bp !== null ? round(($bp / $maxPair) * 100) : 0;
                    $winnerLabel = match ($winner) {
                        'us' => '4627 wins slot',
                        'them' => '4642 wins slot',
                        default => 'Even slot',
                    };
                    $rankNum = (int) $row['rank'];
                    $isHidden = $rankNum > $h2hPageSize;
                    ?>
                    <article
                        class="svs-duel is-lead-<?= h($winner) ?><?= $isHidden ? ' is-h2h-collapsed' : '' ?>"
                        data-h2h-rank="<?= $rankNum ?>"
                        <?= $isHidden ? 'hidden' : '' ?>
                    >
                        <div class="svs-duel-rank" title="Board rank #<?= (int) $row['rank'] ?>">
                            <span class="svs-duel-rank-num">#<?= (int) $row['rank'] ?></span>
                        </div>

                        <div class="svs-duel-side svs-side-us <?= $winner === 'us' ? 'is-winner' : '' ?>">
                            <div class="svs-duel-state">Us · 4627</div>
                            <div class="svs-duel-meta">
                                <?php if (!empty($a['alliance_tag'])): ?>
                                    <span class="svs-duel-tag">[<?= h((string) $a['alliance_tag']) ?>]</span>
                                <?php else: ?>
                                    <span class="svs-duel-tag is-empty">[—]</span>
                                <?php endif; ?>
                                <span class="svs-duel-fc"><?= h(isset($a['stove_lv']) ? formatFurnaceLevel((int) $a['stove_lv'], true) : '—') ?></span>
                            </div>
                            <div class="svs-duel-name"><?= h((string) ($a['name'] ?? '—')) ?></div>
                            <div class="svs-duel-power"><?= h(formatPower($ap)) ?></div>
                            <div class="svs-duel-bar" aria-hidden="true">
                                <span style="width: <?= (int) $usBar ?>%"></span>
                            </div>
                        </div>

                        <div class="svs-duel-vs" aria-label="<?= h($winnerLabel) ?>">
                            <span class="svs-duel-mobile-rank">#<?= (int) $row['rank'] ?></span>
                            <div class="svs-duel-gap-block">
                                <?php if ($diff === null): ?>
                                    <span class="svs-duel-gap-label">n/a</span>
                                    <strong class="svs-duel-gap">—</strong>
                                <?php elseif ($diff === 0): ?>
                                    <span class="svs-duel-gap-label">even</span>
                                    <strong class="svs-duel-gap">0</strong>
                                <?php elseif ($winner === 'us'): ?>
                                    <span class="svs-duel-gap-label">we lead</span>
                                    <strong class="svs-duel-gap positive">+<?= h(formatPower(abs($diff))) ?></strong>
                                <?php else: ?>
                                    <span class="svs-duel-gap-label">they lead</span>
                                    <strong class="svs-duel-gap negative">−<?= h(formatPower(abs($diff))) ?></strong>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="svs-duel-side svs-side-them <?= $winner === 'them' ? 'is-winner' : '' ?>">
                            <div class="svs-duel-state">Enemy · 4642</div>
                            <div class="svs-duel-meta">
                                <?php if (!empty($b['alliance_tag'])): ?>
                                    <span class="svs-duel-tag">[<?= h((string) $b['alliance_tag']) ?>]</span>
                                <?php else: ?>
                                    <span class="svs-duel-tag is-empty">[—]</span>
                                <?php endif; ?>
                                <span class="svs-duel-fc"><?= h(isset($b['stove_lv']) ? formatFurnaceLevel((int) $b['stove_lv'], true) : '—') ?></span>
                            </div>
                            <div class="svs-duel-name"><?= h((string) ($b['name'] ?? '—')) ?></div>
                            <div class="svs-duel-power"><?= h(formatPower($bp)) ?></div>
                            <div class="svs-duel-bar" aria-hidden="true">
                                <span style="width: <?= (int) $themBar ?>%"></span>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($h2hTotal > $h2hPageSize): ?>
                <div class="svs-h2h-more-wrap">
                    <button
                        type="button"
                        class="btn-secondary svs-h2h-more-btn"
                        id="svs-h2h-more"
                        data-step="<?= (int) $h2hPageSize ?>"
                        data-shown="<?= (int) min($h2hPageSize, $h2hTotal) ?>"
                        data-total="<?= (int) $h2hTotal ?>"
                    >Show more (+<?= (int) $h2hPageSize ?>)</button>
                    <p class="muted svs-h2h-more-meta" id="svs-h2h-more-meta">
                        Next up to rank <?= (int) min($h2hPageSize * 2, $h2hTotal) ?> · max top 100
                    </p>
                </div>
            <?php endif; ?>
        </section>

        <section class="svs-charts">
            <div class="panel">
                <h2>Power brackets</h2>
                <p class="muted panel-subtitle">Cumulative power by board depth — closer lines mean a tighter fight.</p>
                <canvas id="svs-power-chart" height="220" aria-label="Power bracket comparison chart"></canvas>
            </div>
            <div class="panel">
                <h2>Furnace / Crystal distribution</h2>
                <p class="muted panel-subtitle">
                    Furnace level on the tracked board. After 30 come 30.1–30.4, then Crystal 1 (35),
                    Crystal 1.1–1.4, Crystal 2 (40), and so on.
                </p>
                <canvas id="svs-furnace-chart" height="220" aria-label="Furnace and Crystal distribution chart"></canvas>
                <p class="muted svs-furnace-legend">
                    Labels use Crystal / 30.x notation after furnace 30 (stored level in parentheses).
                </p>
            </div>
        </section>

        <section class="panel">
            <h2>Metric comparison</h2>
            <div class="table-wrap">
                <table class="alliance-table compare-metrics-table svs-metrics-table responsive-table">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>4627</th>
                            <th>4642</th>
                            <th>Δ (4627−4642)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($metricRows as $row): ?>
                            <?php
                            $format = $row['format'] ?? 'power';
                            $a = $us[$row['key']] ?? null;
                            $b = $them[$row['key']] ?? null;
                            $d = (is_numeric($a) && is_numeric($b)) ? ((int) $a - (int) $b) : null;
                            ?>
                            <tr>
                                <td data-label="Metric">
                                    <?= h($row['label']) ?>
                                    <?php if (!empty($row['hint'])): ?>
                                        <div class="muted svs-hint"><?= h($row['hint']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="4627"><?= h(svsFormatMetric($a, $format)) ?></td>
                                <td data-label="4642"><?= h(svsFormatMetric($b, $format)) ?></td>
                                <td data-label="Δ" class="<?= svsDiffClass($d) ?>">
                                    <?php if ($d === null): ?>
                                        —
                                    <?php elseif ($format === 'int'): ?>
                                        <?= ($d > 0 ? '+' : '') . number_format($d) ?>
                                    <?php else: ?>
                                        <?= ($d > 0 ? '+' : ($d < 0 ? '−' : '')) . ($d === 0 ? '0' : formatPower(abs($d))) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($countRows as $row): ?>
                            <?php
                            $a = (int) ($us[$row['key']] ?? 0);
                            $b = (int) ($them[$row['key']] ?? 0);
                            $d = $a - $b;
                            ?>
                            <tr>
                                <td data-label="Metric"><?= h($row['label']) ?></td>
                                <td data-label="4627"><?= number_format($a) ?></td>
                                <td data-label="4642"><?= number_format($b) ?></td>
                                <td data-label="Δ" class="<?= svsDiffClass($d) ?>"><?= ($d > 0 ? '+' : '') . number_format($d) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td data-label="Metric">Top 10 share of top 100</td>
                            <td data-label="4627"><?= h(svsFormatMetric($us['top10_share_of_100'] ?? null, 'pct')) ?></td>
                            <td data-label="4642"><?= h(svsFormatMetric($them['top10_share_of_100'] ?? null, 'pct')) ?></td>
                            <td data-label="Δ">—</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="svs-two-col">
            <div class="panel">
                <h2>4627 top alliances</h2>
                <div class="table-wrap">
                    <table class="alliance-table responsive-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Alliance</th>
                                <th>Power</th>
                                <th>Members</th>
                                <th>Max</th>
                                <th>Median</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($us['top_alliances'] ?? []) as $alliance): ?>
                                <tr>
                                    <td data-label="#"><?= h((string) ($alliance['rank'] ?? '—')) ?></td>
                                    <td data-label="Alliance">
                                        <strong>[<?= h((string) ($alliance['tag'] ?? '—')) ?>]</strong>
                                        <?= h((string) ($alliance['name'] ?? '')) ?>
                                    </td>
                                    <td data-label="Power"><?= h(formatPower($alliance['power'] ?? null)) ?></td>
                                    <td data-label="Members"><?= h(isset($alliance['members']) ? (string) $alliance['members'] : '—') ?></td>
                                    <td data-label="Max"><?= h(formatPower($alliance['max_power'] ?? null)) ?></td>
                                    <td data-label="Median"><?= h(formatPower($alliance['median_power'] ?? null)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="panel">
                <h2>4642 top alliances</h2>
                <div class="table-wrap">
                    <table class="alliance-table responsive-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Alliance</th>
                                <th>Power</th>
                                <th>Members</th>
                                <th>Max</th>
                                <th>Median</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($them['top_alliances'] ?? []) as $alliance): ?>
                                <tr>
                                    <td data-label="#"><?= h((string) ($alliance['rank'] ?? '—')) ?></td>
                                    <td data-label="Alliance">
                                        <strong>[<?= h((string) ($alliance['tag'] ?? '—')) ?>]</strong>
                                        <?= h((string) ($alliance['name'] ?? '')) ?>
                                    </td>
                                    <td data-label="Power"><?= h(formatPower($alliance['power'] ?? null)) ?></td>
                                    <td data-label="Members"><?= h(isset($alliance['members']) ? (string) $alliance['members'] : '—') ?></td>
                                    <td data-label="Max"><?= h(formatPower($alliance['max_power'] ?? null)) ?></td>
                                    <td data-label="Median"><?= h(formatPower($alliance['median_power'] ?? null)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="panel svs-arena-panel">
            <div class="svs-h2h-heading">
                <div>
                    <h2>Arena / gear / hero proxies</h2>
                    <p class="muted panel-subtitle">
                        Atlas Arena team snapshots for top <?= (int) SVS_ARENA_SCOUT_LIMIT ?> whales each side.
                        Score is a proxy from stars, training, exclusive, skills, and gear levels — not true in-game hero power. Pets are not available via API.
                    </p>
                </div>
                <div class="svs-verdict-meta">
                    <div>
                        <span class="stat-label">Arena scout</span>
                        <span class="stat-value-sm">
                            <?= h(($arenaCmp['fetched_at'] ?? '') !== '' ? formatDisplayDate((string) $arenaCmp['fetched_at']) : '—') ?>
                            <span class="muted">· <?= h((string) ($arenaCmp['source'] ?? 'n/a')) ?></span>
                        </span>
                    </div>
                </div>
            </div>

            <?php if (!empty($arenaCmp['error'])): ?>
                <p class="banner banner-warning">Arena refresh warning: <?= h((string) $arenaCmp['error']) ?></p>
            <?php endif; ?>

            <?php if ((int) ($arenaUs['with_arena'] ?? 0) === 0 && (int) ($arenaThem['with_arena'] ?? 0) === 0): ?>
                <p class="muted">No Arena snapshots loaded yet. An admin can force refresh with <code>?refresh=1</code>.</p>
            <?php else: ?>
                <section class="stats-grid svs-hero-stats svs-arena-stats">
                    <div class="stat-card">
                        <span class="stat-label">Avg investment score</span>
                        <span class="stat-value">
                            <?= h(isset($arenaUs['avg_score']) ? number_format((float) $arenaUs['avg_score'], 0) : '—') ?>
                            <span class="muted">vs</span>
                            <?= h(isset($arenaThem['avg_score']) ? number_format((float) $arenaThem['avg_score'], 0) : '—') ?>
                        </span>
                        <?php if ($arenaScoreDiff !== null): ?>
                            <span class="muted <?= $arenaScoreDiff > 0 ? 'positive' : ($arenaScoreDiff < 0 ? 'negative' : '') ?>">
                                Δ <?= ($arenaScoreDiff > 0 ? '+' : '') . number_format($arenaScoreDiff, 0) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Median score</span>
                        <span class="stat-value">
                            <?= h(isset($arenaUs['median_score']) ? number_format((float) $arenaUs['median_score'], 0) : '—') ?>
                            <span class="muted">vs</span>
                            <?= h(isset($arenaThem['median_score']) ? number_format((float) $arenaThem['median_score'], 0) : '—') ?>
                        </span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Avg hero stars</span>
                        <span class="stat-value">
                            <?= h(isset($arenaUs['avg_star']) ? number_format((float) $arenaUs['avg_star'], 1) : '—') ?>
                            <span class="muted">vs</span>
                            <?= h(isset($arenaThem['avg_star']) ? number_format((float) $arenaThem['avg_star'], 1) : '—') ?>
                        </span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Avg gear level (slv)</span>
                        <span class="stat-value">
                            <?= h(isset($arenaUs['avg_gear_slv']) ? number_format((float) $arenaUs['avg_gear_slv'], 1) : '—') ?>
                            <span class="muted">vs</span>
                            <?= h(isset($arenaThem['avg_gear_slv']) ? number_format((float) $arenaThem['avg_gear_slv'], 1) : '—') ?>
                        </span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Avg exclusives / team</span>
                        <span class="stat-value">
                            <?= h(isset($arenaUs['avg_exclusives']) ? number_format((float) $arenaUs['avg_exclusives'], 1) : '—') ?>
                            <span class="muted">vs</span>
                            <?= h(isset($arenaThem['avg_exclusives']) ? number_format((float) $arenaThem['avg_exclusives'], 1) : '—') ?>
                        </span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Coverage</span>
                        <span class="stat-value">
                            <?= (int) ($arenaUs['with_arena'] ?? 0) ?>/<?= (int) ($arenaUs['scouted'] ?? 0) ?>
                            <span class="muted">vs</span>
                            <?= (int) ($arenaThem['with_arena'] ?? 0) ?>/<?= (int) ($arenaThem['scouted'] ?? 0) ?>
                        </span>
                    </div>
                </section>

                <div class="table-wrap">
                    <table class="alliance-table compare-metrics-table svs-metrics-table responsive-table">
                        <thead>
                            <tr>
                                <th>Arena metric (top <?= (int) SVS_ARENA_SCOUT_LIMIT ?>)</th>
                                <th>4627</th>
                                <th>4642</th>
                                <th>Δ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $arenaMetricDefs = [
                                ['Avg investment score', 'avg_score', 'num0'],
                                ['Median investment score', 'median_score', 'num0'],
                                ['Best single score', 'top_score', 'num0'],
                                ['Avg hero stars', 'avg_star', 'num1'],
                                ['Avg gear slv', 'avg_gear_slv', 'num1'],
                                ['Avg exclusives / team', 'avg_exclusives', 'num1'],
                                ['Avg maxed skill heroes', 'avg_full_skills', 'num1'],
                            ];
                            foreach ($arenaMetricDefs as [$label, $key, $fmt]):
                                $a = $arenaUs[$key] ?? null;
                                $b = $arenaThem[$key] ?? null;
                                $d = (is_numeric($a) && is_numeric($b)) ? ((float) $a - (float) $b) : null;
                                $fmtVal = static function ($v) use ($fmt): string {
                                    if ($v === null || $v === '') {
                                        return '—';
                                    }
                                    return $fmt === 'num1' ? number_format((float) $v, 1) : number_format((float) $v, 0);
                                };
                                ?>
                                <tr>
                                    <td data-label="Metric"><?= h($label) ?></td>
                                    <td data-label="4627"><?= h($fmtVal($a)) ?></td>
                                    <td data-label="4642"><?= h($fmtVal($b)) ?></td>
                                    <td data-label="Δ" class="<?= $d === null ? '' : ($d > 0 ? 'positive' : ($d < 0 ? 'negative' : '')) ?>">
                                        <?php if ($d === null): ?>
                                            —
                                        <?php else: ?>
                                            <?= ($d > 0 ? '+' : '') . ($fmt === 'num1' ? number_format($d, 1) : number_format($d, 0)) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <h3 class="svs-arena-subhead">Rank vs rank · investment score</h3>
                <p class="muted panel-subtitle">Same board rank. Higher score ≈ denser stars / exclusives / gear on the Arena team.</p>

                <div class="svs-h2h-list svs-arena-list">
                    <?php foreach ($arenaH2H as $row): ?>
                        <?php
                        $a = is_array($row['us'] ?? null) ? $row['us'] : [];
                        $b = is_array($row['them'] ?? null) ? $row['them'] : [];
                        $aa = is_array($a['arena'] ?? null) ? $a['arena'] : [];
                        $ba = is_array($b['arena'] ?? null) ? $b['arena'] : [];
                        $as = isset($aa['score']) ? (int) $aa['score'] : null;
                        $bs = isset($ba['score']) ? (int) $ba['score'] : null;
                        $winner = (string) ($row['winner'] ?? 'tie');
                        $diff = $row['diff'] ?? null;
                        $maxScore = max($as ?? 0, $bs ?? 0, 1);
                        $usBar = $as !== null ? (int) round(($as / $maxScore) * 100) : 0;
                        $themBar = $bs !== null ? (int) round(($bs / $maxScore) * 100) : 0;
                        ?>
                        <article class="svs-duel is-lead-<?= h($winner) ?>">
                            <div class="svs-duel-rank">
                                <span class="svs-duel-rank-num">#<?= (int) $row['rank'] ?></span>
                            </div>

                            <div class="svs-duel-side svs-side-us <?= $winner === 'us' ? 'is-winner' : '' ?>">
                                <div class="svs-duel-state">Us · 4627</div>
                                <div class="svs-duel-meta">
                                    <?php if (!empty($a['alliance_tag'])): ?>
                                        <span class="svs-duel-tag">[<?= h((string) $a['alliance_tag']) ?>]</span>
                                    <?php endif; ?>
                                    <span class="svs-duel-fc">
                                        ★ <?= h(isset($aa['avg_star']) ? number_format((float) $aa['avg_star'], 1) : '—') ?>
                                        · gear <?= h(isset($aa['avg_gear_slv']) ? number_format((float) $aa['avg_gear_slv'], 0) : '—') ?>
                                        · excl <?= (int) ($aa['exclusive_count'] ?? 0) ?>
                                    </span>
                                </div>
                                <div class="svs-duel-name"><?= h((string) ($a['name'] ?? '—')) ?></div>
                                <div class="svs-duel-power"><?= h($as !== null ? number_format($as) : '—') ?> <span class="muted svs-score-unit">inv</span></div>
                                <div class="svs-duel-bar" aria-hidden="true"><span style="width: <?= $usBar ?>%"></span></div>
                            </div>

                            <div class="svs-duel-vs" aria-label="<?= h($winner === 'us' ? '4627 ahead' : ($winner === 'them' ? '4642 ahead' : 'Even')) ?>">
                                <span class="svs-duel-mobile-rank">#<?= (int) $row['rank'] ?></span>
                                <div class="svs-duel-gap-block">
                                    <?php if ($diff === null): ?>
                                        <span class="svs-duel-gap-label">n/a</span>
                                        <strong class="svs-duel-gap">—</strong>
                                    <?php elseif ($diff === 0): ?>
                                        <span class="svs-duel-gap-label">even</span>
                                        <strong class="svs-duel-gap">0</strong>
                                    <?php elseif ($winner === 'us'): ?>
                                        <span class="svs-duel-gap-label">we lead</span>
                                        <strong class="svs-duel-gap positive">+<?= number_format(abs((int) $diff)) ?></strong>
                                    <?php else: ?>
                                        <span class="svs-duel-gap-label">they lead</span>
                                        <strong class="svs-duel-gap negative">−<?= number_format(abs((int) $diff)) ?></strong>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="svs-duel-side svs-side-them <?= $winner === 'them' ? 'is-winner' : '' ?>">
                                <div class="svs-duel-state">Enemy · 4642</div>
                                <div class="svs-duel-meta">
                                    <?php if (!empty($b['alliance_tag'])): ?>
                                        <span class="svs-duel-tag">[<?= h((string) $b['alliance_tag']) ?>]</span>
                                    <?php endif; ?>
                                    <span class="svs-duel-fc">
                                        ★ <?= h(isset($ba['avg_star']) ? number_format((float) $ba['avg_star'], 1) : '—') ?>
                                        · gear <?= h(isset($ba['avg_gear_slv']) ? number_format((float) $ba['avg_gear_slv'], 0) : '—') ?>
                                        · excl <?= (int) ($ba['exclusive_count'] ?? 0) ?>
                                    </span>
                                </div>
                                <div class="svs-duel-name"><?= h((string) ($b['name'] ?? '—')) ?></div>
                                <div class="svs-duel-power"><?= h($bs !== null ? number_format($bs) : '—') ?> <span class="muted svs-score-unit">inv</span></div>
                                <div class="svs-duel-bar" aria-hidden="true"><span style="width: <?= $themBar ?>%"></span></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel svs-notes">
            <h2>How to use this for battle day</h2>
            <ul class="svs-insight-list">
                <li>Identify 4642’s ≥80M names above — those are the likely castle / turret rally leads.</li>
                <li>Compare their top 2–3 alliances ([L0L], [IMM], [HOT] currently) vs our MaD / LUX / RRV depth for simultaneous counter-rally capacity.</li>
                <li>Use Arena investment scores below as a gear/hero-density proxy when raw power looks close.</li>
                <li>Top 100 edge is small either way; Reddit consensus is that activity + multi-alliance timing usually decides close SvS, not raw power alone.</li>
                <li>4642 + Arena scout data refresh about every 6 hours (admins can force refresh).</li>
            </ul>
        </section>
    </div>

    <script>
        window.SVS_CHARTS = <?= json_encode([
            'power' => $chartPayload,
            'furnace' => $furnaceChart,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
    </script>
    <script>
        (function () {
            const data = window.SVS_CHARTS || {};
            const power = data.power || {};
            const furnace = data.furnace || {};
            const fmt = (v) => {
                const n = Number(v) || 0;
                if (n >= 1e9) return (n / 1e9).toFixed(2) + 'B';
                if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
                if (n >= 1e3) return (n / 1e3).toFixed(1) + 'K';
                return String(n);
            };
            const common = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: '#c5d3e6' } },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => (ctx.dataset.label || '') + ': ' + fmt(ctx.parsed.y)
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: '#8b9bb3' }, grid: { color: 'rgba(125,168,210,0.08)' } },
                    y: {
                        ticks: { color: '#8b9bb3', callback: (v) => fmt(v) },
                        grid: { color: 'rgba(125,168,210,0.08)' }
                    }
                }
            };

            const powerEl = document.getElementById('svs-power-chart');
            if (powerEl && window.Chart) {
                new Chart(powerEl, {
                    type: 'bar',
                    data: {
                        labels: power.labels || [],
                        datasets: [
                            { label: '4627', data: power.us || [], backgroundColor: 'rgba(94,200,255,0.7)' },
                            { label: '4642', data: power.them || [], backgroundColor: 'rgba(255,107,122,0.65)' }
                        ]
                    },
                    options: common
                });
            }

            const furnaceEl = document.getElementById('svs-furnace-chart');
            if (furnaceEl && window.Chart) {
                new Chart(furnaceEl, {
                    type: 'bar',
                    data: {
                        labels: furnace.labels || [],
                        datasets: [
                            { label: '4627', data: furnace.us || [], backgroundColor: 'rgba(61,214,165,0.7)' },
                            { label: '4642', data: furnace.them || [], backgroundColor: 'rgba(245,178,66,0.7)' }
                        ]
                    },
                    options: {
                        ...common,
                        plugins: {
                            ...common.plugins,
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => (ctx.dataset.label || '') + ': ' + ctx.parsed.y
                                }
                            }
                        },
                        scales: {
                            ...common.scales,
                            y: {
                                ticks: { color: '#8b9bb3', precision: 0 },
                                grid: { color: 'rgba(125,168,210,0.08)' },
                                beginAtZero: true
                            }
                        }
                    }
                });
            }
        })();

        (function () {
            const btn = document.getElementById('svs-h2h-more');
            const list = document.getElementById('svs-h2h-list');
            const visibleCount = document.getElementById('svs-h2h-visible-count');
            const meta = document.getElementById('svs-h2h-more-meta');
            if (!btn || !list) return;

            const step = Math.max(1, parseInt(btn.getAttribute('data-step') || '15', 10) || 15);
            const total = Math.max(0, parseInt(btn.getAttribute('data-total') || '0', 10) || 0);
            let shown = Math.max(0, parseInt(btn.getAttribute('data-shown') || String(step), 10) || step);

            const sync = () => {
                if (visibleCount) visibleCount.textContent = String(shown);
                btn.setAttribute('data-shown', String(shown));
                if (shown >= total) {
                    btn.hidden = true;
                    if (meta) meta.textContent = 'Showing all ' + total + ' ranks';
                    return;
                }
                const next = Math.min(shown + step, total);
                btn.hidden = false;
                btn.textContent = 'Show more (+' + Math.min(step, total - shown) + ')';
                if (meta) meta.textContent = 'Next up to rank ' + next + ' · max top 100';
            };

            btn.addEventListener('click', () => {
                const next = Math.min(shown + step, total);
                list.querySelectorAll('.svs-duel[data-h2h-rank]').forEach((el) => {
                    const rank = parseInt(el.getAttribute('data-h2h-rank') || '0', 10) || 0;
                    if (rank > shown && rank <= next) {
                        el.hidden = false;
                        el.classList.remove('is-h2h-collapsed');
                    }
                });
                shown = next;
                sync();
            });

            sync();
        })();
    </script>
</body>
</html>
