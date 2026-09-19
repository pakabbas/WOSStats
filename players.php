<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$players = loadJson(PLAYERS_FILE, []);
$playersHistory = loadJson(PLAYERS_HISTORY_FILE, []);
$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$lastUpdated = getLastUpdated($players);

$players = rankPlayersByPower(is_array($players) ? $players : []);

$rows = [];
foreach ($players as $player) {
    $id = (string) ($player['id'] ?? '');
    $power = isset($player['power']) ? (int) $player['power'] : null;

    $powerChange24 = ($id !== '' && $power !== null)
        ? calculatePlayerPowerChange($playersHistory, $id, $power, 24)
        : null;
    $powerPct24 = ($id !== '' && $power !== null)
        ? calculatePlayerPowerChangePct($playersHistory, $id, $power, 24)
        : null;

    $rows[] = [
        'player' => $player,
        'power_change_24h' => $powerChange24,
        'power_pct_24h' => $powerPct24,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <title>Top Players — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body>
    <div class="container">
        <?php renderSiteNav('players'); ?>

        <header class="page-header page-header-with-action">
            <div>
                <p class="eyebrow">WHITEOUT SURVIVAL</p>
                <h1>Top 200 Players</h1>
                <p class="subtitle"><?= h($stateName) ?> · Power ranking · 24h stats need ~1 day of updates</p>
            </div>
            <a class="btn-primary" href="player-search.php">Search Player by ID</a>
        </header>

        <section class="stats-grid stats-grid-compact">
            <div class="stat-card">
                <span class="stat-label">Players Tracked</span>
                <span class="stat-value"><?= count($players) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">#1 Power</span>
                <span class="stat-value"><?= h(formatPower($players[0]['power'] ?? null)) ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Last Updated</span>
                <span class="stat-value stat-value-small"><?= h(formatDisplayDateOnly($lastUpdated)) ?></span>
            </div>
        </section>

        <section class="panel">
            <div class="panel-toolbar">
                <input type="search" id="player-search" class="search-input" placeholder="Filter this list by name or ID…" aria-label="Filter players">
                <a class="btn-secondary" href="player-search.php">Search Player by ID</a>
            </div>

            <p class="banner banner-warning">Players data update takes approx 24 hours — power here may lag behind in-game.</p>

            <div class="table-wrap">
                <table class="alliance-table responsive-table" id="player-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Player</th>
                            <th>Power</th>
                            <th>24h</th>
                            <th>24h %</th>
                            <th>Furnace</th>
                            <th>Jump</th>
                            <th>UID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="8" class="empty-cell">
                                    No player data yet. Run <code>update.php?token=YOUR_TOKEN</code> to fetch top 200.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $player = $row['player'];
                                $powerChange24 = $row['power_change_24h'];
                                $powerPct24 = $row['power_pct_24h'];
                                ?>
                                <tr
                                    data-name="<?= h(strtolower((string) ($player['name'] ?? ''))) ?>"
                                    data-id="<?= h(strtolower((string) ($player['id'] ?? ''))) ?>"
                                >
                                    <td data-label="Rank"><?= h((string) ($player['rank'] ?? '—')) ?></td>
                                    <td data-label="Player"><?= h((string) ($player['name'] ?? 'Unknown')) ?></td>
                                    <td data-label="Power"><?= h(formatPower($player['power'] ?? null)) ?></td>
                                    <td data-label="24h" class="<?= changeClass($powerChange24 !== null ? (float) $powerChange24 : null) ?>">
                                        <?= h(formatPowerChange($powerChange24)) ?>
                                    </td>
                                    <td data-label="24h %" class="<?= changeClass($powerPct24) ?>">
                                        <?= h(formatPercentChange($powerPct24)) ?>
                                    </td>
                                    <td data-label="Furnace"><?= h(isset($player['stove_lv']) ? (string) $player['stove_lv'] : '—') ?></td>
                                    <td data-label="Jump"><?= h(formatJumpPct(isset($player['last_jump_pct']) ? (float) $player['last_jump_pct'] : null)) ?></td>
                                    <td data-label="UID" class="muted-cell"><?= h((string) ($player['id'] ?? '—')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php renderSiteFooter(); ?>
    </div>

    <script src="<?= assetUrl('assets/app.js') ?>"></script>
</body>
</html>
