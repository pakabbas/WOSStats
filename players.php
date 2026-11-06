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

$allianceTagCounts = [];
$rows = [];
foreach ($players as $player) {
    $id = (string) ($player['id'] ?? '');
    $power = isset($player['power']) ? (int) $player['power'] : null;
    $tag = trim((string) ($player['alliance_tag'] ?? ''));
    if ($tag !== '') {
        $allianceTagCounts[$tag] = ($allianceTagCounts[$tag] ?? 0) + 1;
    }

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

uksort(
    $allianceTagCounts,
    static fn(string $a, string $b): int => strcasecmp($a, $b)
);
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
                <div class="player-filter-bar">
                    <button type="button" class="refresh-data-btn" id="refresh-alliances" title="Refresh data (same as run.php)">
                        Refresh
                    </button>
                    <span id="refresh-alliances-status" class="refresh-data-status" role="status" aria-live="polite"></span>
                    <label class="player-filter-label" for="player-alliance-filter">Alliance</label>
                    <select id="player-alliance-filter" class="search-input player-alliance-filter" aria-label="Filter by alliance">
                        <option value="">All alliances</option>
                        <option value="__none__">No alliance / unknown</option>
                        <?php foreach ($allianceTagCounts as $tag => $count): ?>
                            <option value="<?= h(strtolower($tag)) ?>"><?= h($tag) ?> (<?= (int) $count ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <input type="search" id="player-search" class="search-input" placeholder="Filter by name, alliance, or ID…" aria-label="Filter players">
                    <span id="player-filter-count" class="player-filter-count" aria-live="polite"></span>
                </div>
                <a class="btn-secondary" href="player-search.php">Search Player by ID</a>
            </div>

            <p class="banner banner-warning">Players data update takes approx 24 hours — power here may lag behind in-game.</p>

            <div class="table-wrap">
                <table class="alliance-table responsive-table" id="player-table">
                    <thead>
                        <tr>
                            <th aria-sort="none"><button type="button" class="th-sort" data-key="rank" data-type="num" data-default="asc">Rank</button></th>
                            <th aria-sort="none"><button type="button" class="th-sort" data-key="name" data-type="text" data-default="asc">Player</button></th>
                            <th aria-sort="none"><button type="button" class="th-sort" data-key="tag" data-type="text" data-default="asc">Alliance</button></th>
                            <th aria-sort="none"><button type="button" class="th-sort" data-key="power" data-type="num" data-default="desc">Power</button></th>
                            <th aria-sort="none"><button type="button" class="th-sort" data-key="change24" data-type="num" data-default="desc">24h</button></th>
                            <th aria-sort="none"><button type="button" class="th-sort" data-key="pct24" data-type="num" data-default="desc">24h %</button></th>
                            <th aria-sort="none"><button type="button" class="th-sort" data-key="furnace" data-type="num" data-default="desc">Furnace</button></th>
                            <th aria-sort="none"><button type="button" class="th-sort" data-key="jump" data-type="num" data-default="desc">Jump</button></th>
                            <th aria-sort="none"><button type="button" class="th-sort" data-key="id" data-type="num" data-default="asc">UID</button></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="9" class="empty-cell">
                                    No player data yet. Run <code>update.php?token=YOUR_TOKEN</code> to fetch top 200.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $player = $row['player'];
                                $powerChange24 = $row['power_change_24h'];
                                $powerPct24 = $row['power_pct_24h'];
                                $playerId = trim((string) ($player['id'] ?? ''));
                                $savedTag = trim((string) ($player['alliance_tag'] ?? ''));
                                $savedAid = trim((string) ($player['alliance_id'] ?? ''));
                                $needsLookup = $savedTag === '';
                                $powerVal = isset($player['power']) ? (int) $player['power'] : null;
                                $furnaceVal = $player['stove_lv'] ?? null;
                                $jumpVal = $player['last_jump_pct'] ?? null;
                                ?>
                                <tr
                                    data-name="<?= h(strtolower((string) ($player['name'] ?? ''))) ?>"
                                    data-id="<?= h(strtolower($playerId)) ?>"
                                    data-rank="<?= h((string) ($player['rank'] ?? '')) ?>"
                                    data-tag="<?= h(strtolower($savedTag)) ?>"
                                    data-tag-label="<?= h($savedTag) ?>"
                                    data-power="<?= $powerVal !== null ? h((string) $powerVal) : '' ?>"
                                    data-change24="<?= $powerChange24 !== null ? h((string) $powerChange24) : '' ?>"
                                    data-pct24="<?= $powerPct24 !== null ? h((string) $powerPct24) : '' ?>"
                                    data-furnace="<?= $furnaceVal !== null && $furnaceVal !== '' ? h((string) $furnaceVal) : '' ?>"
                                    data-jump="<?= $jumpVal !== null && $jumpVal !== '' ? h((string) $jumpVal) : '' ?>"
                                >
                                    <td data-label="Rank"><?= h((string) ($player['rank'] ?? '—')) ?></td>
                                    <td data-label="Player"><?= h((string) ($player['name'] ?? 'Unknown')) ?></td>
                                    <td
                                        data-label="Alliance"
                                        class="player-alliance-cell<?= $needsLookup ? ' is-pending' : '' ?>"
                                        data-uid="<?= h($playerId) ?>"
                                        data-needs-lookup="<?= $needsLookup ? '1' : '0' ?>"
                                    >
                                        <?php if ($savedTag !== '' && $savedAid !== ''): ?>
                                            <a href="alliance.php?id=<?= urlencode($savedAid) ?>" class="alliance-link"><?= h($savedTag) ?></a>
                                        <?php elseif ($savedTag !== ''): ?>
                                            <?= h($savedTag) ?>
                                        <?php else: ?>
                                            <span class="muted-cell player-alliance-pending">…</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Power"><?= h(formatPower($player['power'] ?? null)) ?></td>
                                    <td data-label="24h" class="<?= changeClass($powerChange24 !== null ? (float) $powerChange24 : null) ?>">
                                        <?= h(formatPowerChange($powerChange24)) ?>
                                    </td>
                                    <td data-label="24h %" class="<?= changeClass($powerPct24) ?>">
                                        <?= h(formatPercentChange($powerPct24)) ?>
                                    </td>
                                    <td data-label="Furnace"><?= h(isset($player['stove_lv']) ? formatFurnaceLevel((int) $player['stove_lv']) : '—') ?></td>
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
