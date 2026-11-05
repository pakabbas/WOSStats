<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/player-lookup.php';

$config = loadConfig();
ensureDataFilesExist();

$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$defaultStateId = (string) ($config['state_id'] ?? '');

$queryUid = preg_replace('/\D+/', '', (string) ($_GET['uid'] ?? $_POST['uid'] ?? '')) ?? '';
$queryName = trim((string) ($_GET['name'] ?? $_POST['name'] ?? ''));
$queryState = preg_replace('/\D+/', '', (string) ($_GET['state'] ?? $_POST['state'] ?? '')) ?? '';

// Friendly: digits typed in the name box count as a Chief ID.
if ($queryUid === '' && $queryName !== '' && preg_match('/^\d{5,}$/', $queryName) === 1) {
    $queryUid = $queryName;
    $queryName = '';
}

$error = null;
$result = null;
$matches = [];
$stateMismatch = false;
$napBannedEntry = null;
$allianceName = null;
$searched = $queryUid !== '' || $queryName !== '';
$searchMode = $queryUid !== '' ? 'uid' : ($queryName !== '' ? 'name' : null);

if ($searched) {
    if ($searchMode === 'uid') {
        if (strlen($queryUid) < 5) {
            $error = 'Enter a valid Chief / player ID.';
        } else {
            try {
                $lookup = lookupPlayerByUid($queryUid, $config);
                if ($lookup === null) {
                    $error = 'No player found for that ID.';
                } else {
                    $result = enrichPlayerLookupWithLocalData($lookup);
                    $resultKid = (string) ($result['kid'] ?? '');
                    if ($queryState !== '' && $resultKid !== '' && $resultKid !== $queryState) {
                        $stateMismatch = true;
                    }
                    if (($result['name'] ?? null) === null
                        && ($result['power'] ?? null) === null
                        && ($result['kid'] ?? null) === null
                        && ($result['x'] ?? null) === null
                    ) {
                        $error = 'No player found for that ID.';
                        $result = null;
                    }
                }
            } catch (Throwable $e) {
                $error = 'Lookup failed. Try again in a moment.';
            }
        }
    } else {
        if (mb_strlen($queryName) < 2) {
            $error = 'Enter at least 2 characters of a player name.';
        } else {
            try {
                $preferKid = $queryState !== '' ? $queryState : $defaultStateId;
                $matches = searchPlayersByName($queryName, $config, $preferKid, 40);

                // If state filter is set, keep preferred-state hits first but still show others.
                if ($queryState !== '' && $matches !== []) {
                    $inState = [];
                    $outState = [];
                    foreach ($matches as $row) {
                        if ((string) ($row['kid'] ?? '') === $queryState) {
                            $inState[] = $row;
                        } else {
                            $outState[] = $row;
                        }
                    }
                    $matches = array_merge($inState, $outState);
                }

                if ($matches === []) {
                    $error = 'No players found for that name.';
                } else {
                    $needle = mb_strtolower($queryName);
                    $preferKid = $queryState !== '' ? $queryState : $defaultStateId;
                    $autoOpen = null;

                    if (count($matches) === 1) {
                        $autoOpen = $matches[0];
                    } else {
                        $exactAll = [];
                        $exactPreferred = [];
                        foreach ($matches as $row) {
                            if (mb_strtolower(trim((string) ($row['name'] ?? ''))) !== $needle) {
                                continue;
                            }
                            $exactAll[] = $row;
                            if ($preferKid !== '' && (string) ($row['kid'] ?? '') === $preferKid) {
                                $exactPreferred[] = $row;
                            }
                        }
                        if (count($exactPreferred) === 1) {
                            $autoOpen = $exactPreferred[0];
                        } elseif ($preferKid === '' && count($exactAll) === 1) {
                            $autoOpen = $exactAll[0];
                        }
                    }

                    if ($autoOpen !== null) {
                        $autoId = trim((string) ($autoOpen['id'] ?? ''));
                        if ($autoId !== '') {
                            $lookup = lookupPlayerByUid($autoId, $config);
                            $result = enrichPlayerLookupWithLocalData($lookup ?? $autoOpen);
                            $matches = [];
                        }
                    }
                }
            } catch (Throwable $e) {
                $error = 'Name search failed. Try again in a moment.';
            }
        }
    }
}

if ($result !== null) {
    $resultKid = (string) ($result['kid'] ?? '');
    if ($queryState !== '' && $resultKid !== '' && $resultKid !== $queryState) {
        $stateMismatch = true;
    }
    $napBannedEntry = findNapBannedPlayer(
        [
            (string) ($result['id'] ?? ''),
            (string) ($result['fid'] ?? ''),
            $queryUid,
        ],
        isset($result['name']) ? (string) $result['name'] : null
    );
    $allianceName = resolveAllianceName(
        isset($result['alliance_id']) ? (string) $result['alliance_id'] : null,
        isset($result['alliance_tag']) ? (string) $result['alliance_tag'] : null
    );
}

$isNapBanned = is_array($napBannedEntry);
$allianceTag = $result !== null ? trim((string) ($result['alliance_tag'] ?? '')) : '';
$allianceId = $result !== null ? trim((string) ($result['alliance_id'] ?? '')) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <title>Player Search — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body>
    <div class="container">
        <?php renderSiteNav('players'); ?>

        <header class="page-header">
            <p class="eyebrow">WHITEOUT SURVIVAL</p>
            <h1>Player search</h1>
            <p class="subtitle">Search by name or Chief ID · alliance &amp; coords when available</p>
        </header>

        <section class="panel">
            <form class="player-search-form" method="get" action="player-search.php">
                <div class="player-search-fields player-search-fields-3">
                    <div class="player-search-field">
                        <label for="player-name">Player name</label>
                        <input id="player-name" name="name" type="text"
                               maxlength="40" autocomplete="off"
                               value="<?= h($queryName) ?>" placeholder="e.g. Plutomoon">
                    </div>
                    <div class="player-search-field">
                        <label for="player-uid">Player ID <span class="label-optional">(optional)</span></label>
                        <input id="player-uid" name="uid" type="text" inputmode="numeric" pattern="[0-9]*"
                               maxlength="20" autocomplete="off"
                               value="<?= h($queryUid) ?>" placeholder="e.g. 209143052">
                    </div>
                    <div class="player-search-field">
                        <label for="player-state">State <span class="label-optional">(optional)</span></label>
                        <input id="player-state" name="state" type="text" inputmode="numeric" pattern="[0-9]*"
                               maxlength="8" autocomplete="off"
                               value="<?= h($queryState !== '' ? $queryState : '') ?>"
                               placeholder="Prefer <?= h($defaultStateId) ?>">
                    </div>
                </div>
                <p class="muted player-search-note">
                    Use a name for a friendly search (players this site has seen), or a Chief ID for an exact lookup.
                    State prioritizes matches from that kingdom (default suggestion: <?= h($defaultStateId) ?>).
                </p>
                <div class="player-search-actions">
                    <button type="submit" class="btn-primary">Search player</button>
                    <a class="btn-secondary" href="players.php">Back to Top 200</a>
                </div>
            </form>
        </section>

        <?php if ($error !== null): ?>
            <p class="banner banner-warning"><?= h($error) ?></p>
        <?php endif; ?>

        <?php if ($searchMode === 'name' && $queryName !== ''): ?>
            <p class="muted player-search-note">
                Name search only covers players this site has already seen (top board, tracked rosters,
                and earlier WOS Atlas lookups). Not here?
                <a href="<?= h(atlasV2WebSearchUrl($queryName)) ?>" target="_blank" rel="noopener">Search “<?= h($queryName) ?>” on WOS Atlas</a>,
                then paste the Chief ID above.
            </p>
        <?php elseif ($searchMode === 'uid' && $result === null && $queryUid !== ''): ?>
            <p class="muted player-search-note">
                WOS Atlas looks players up by its own UID; some in-game IDs are only known to it as an alternate ID.
                <a href="<?= h(atlasV2WebSearchUrl($queryUid)) ?>" target="_blank" rel="noopener">Try this ID on WOS Atlas</a>.
            </p>
        <?php endif; ?>

        <?php if ($matches !== []): ?>
            <section class="panel">
                <div class="player-result-header">
                    <div>
                        <p class="eyebrow">MATCHES</p>
                        <h2><?= count($matches) ?> players for “<?= h($queryName) ?>”</h2>
                        <p class="muted">
                            <?= $queryState !== '' ? 'Prioritizing state ' . h($queryState) : 'Prioritizing state ' . h($defaultStateId) ?>
                            · tap a row to open the full profile
                        </p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="alliance-table responsive-table" id="player-name-results">
                        <thead>
                            <tr>
                                <th>Player</th>
                                <th>State</th>
                                <th>Alliance</th>
                                <th>Power</th>
                                <th>UID</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($matches as $row): ?>
                                <?php
                                $rowId = trim((string) ($row['id'] ?? ''));
                                $rowName = (string) ($row['name'] ?? 'Unknown');
                                $rowKid = (string) ($row['kid'] ?? '—');
                                $rowTag = trim((string) ($row['alliance_tag'] ?? ''));
                                $rowAid = trim((string) ($row['alliance_id'] ?? ''));
                                $isPreferred = $defaultStateId !== '' && $rowKid === $defaultStateId;
                                $href = 'player-search.php?uid=' . urlencode($rowId);
                                if ($queryState !== '') {
                                    $href .= '&state=' . urlencode($queryState);
                                }
                                ?>
                                <tr class="<?= $isPreferred ? 'player-match-preferred' : '' ?>">
                                    <td data-label="Player">
                                        <a href="<?= h($href) ?>" class="alliance-link"><?= h($rowName) ?></a>
                                        <?php if ($isPreferred): ?>
                                            <span class="office-badge">This state</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="State"><?= h($rowKid) ?></td>
                                    <td data-label="Alliance">
                                        <?php if ($rowTag !== '' && $rowAid !== ''): ?>
                                            <a href="alliance.php?id=<?= urlencode($rowAid) ?>" class="alliance-link"><?= h($rowTag) ?></a>
                                        <?php elseif ($rowTag !== ''): ?>
                                            <?= h($rowTag) ?>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Power"><?= h(formatPower($row['power'] ?? null)) ?></td>
                                    <td data-label="UID" class="muted-cell"><?= h($rowId !== '' ? $rowId : '—') ?></td>
                                    <td data-label="">
                                        <?php if ($rowId !== ''): ?>
                                            <a class="btn-secondary player-match-open" href="<?= h($href) ?>">Open</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($result !== null): ?>
            <?php if ($isNapBanned): ?>
                <p class="banner banner-danger">
                    <strong>NAP BANNED</strong>
                    — this player is on the state NAP banned list.
                    <?php if (!empty($napBannedEntry['reason'])): ?>
                        Reason: <?= h((string) $napBannedEntry['reason']) ?>
                    <?php endif; ?>
                    <a class="banner-danger-link" href="nap-banned.php">View list</a>
                </p>
            <?php endif; ?>
            <?php if ($stateMismatch): ?>
                <p class="banner banner-warning">
                    This player is in state <?= h((string) $result['kid']) ?>, not <?= h($queryState) ?>.
                </p>
            <?php endif; ?>

            <section class="panel player-result-panel<?= $isNapBanned ? ' player-result-danger' : '' ?>">
                <div class="player-result-header">
                    <div>
                        <p class="eyebrow"><?= $isNapBanned ? 'NAP BANNED PLAYER' : 'RESULT' ?></p>
                        <h2><?= h((string) ($result['name'] ?? 'Unknown player')) ?></h2>
                        <p class="player-result-meta">
                            <?php if ($allianceTag !== '' || $allianceName !== null): ?>
                                <span class="player-result-alliance">
                                    <?php if ($allianceId !== ''): ?>
                                        <a href="alliance.php?id=<?= urlencode($allianceId) ?>" class="alliance-link">
                                            <?= h($allianceTag !== '' ? $allianceTag : (string) $allianceName) ?>
                                        </a>
                                    <?php else: ?>
                                        <strong><?= h($allianceTag !== '' ? $allianceTag : (string) $allianceName) ?></strong>
                                    <?php endif; ?>
                                    <?php if ($allianceName !== null && $allianceTag !== '' && strcasecmp($allianceName, $allianceTag) !== 0): ?>
                                        <span class="muted">· <?= h($allianceName) ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="muted">·</span>
                            <?php endif; ?>
                            <span class="muted">UID <?= h((string) $result['id']) ?></span>
                        </p>
                    </div>
                    <div class="player-result-badges">
                        <?php if ($isNapBanned): ?>
                            <span class="danger-badge">NAP BANNED</span>
                        <?php endif; ?>
                        <?php if ($allianceTag !== ''): ?>
                            <span class="office-badge<?= $isNapBanned ? ' office-badge-danger' : '' ?>"><?= h($allianceTag) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stats-grid stats-grid-compact player-result-stats">
                    <div class="stat-card">
                        <span class="stat-label">State</span>
                        <span class="stat-value"><?= h((string) ($result['kid'] ?? '—')) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Coordinates</span>
                        <span class="stat-value stat-value-small">
                            <?php if (isset($result['x'], $result['y'])): ?>
                                X <?= h((string) $result['x']) ?> · Y <?= h((string) $result['y']) ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Power</span>
                        <span class="stat-value"><?= h(formatPower($result['power'] ?? null)) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Furnace</span>
                        <span class="stat-value"><?= h(isset($result['stove_lv']) ? (string) $result['stove_lv'] : '—') ?></span>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="alliance-table">
                        <tbody>
                            <tr>
                                <th scope="row">Player</th>
                                <td>
                                    <?= h((string) ($result['name'] ?? '—')) ?>
                                    <?php if ($allianceTag !== '' || $allianceName !== null): ?>
                                        <span class="muted">
                                            ·
                                            <?= h($allianceTag !== '' ? $allianceTag : '') ?>
                                            <?php if ($allianceName !== null && ($allianceTag === '' || strcasecmp($allianceName, $allianceTag) !== 0)): ?>
                                                <?= $allianceTag !== '' ? ' · ' : '' ?><?= h($allianceName) ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Chief ID</th>
                                <td>
                                    <?= h((string) $result['id']) ?>
                                    <?php if ($allianceTag !== '' || $allianceName !== null): ?>
                                        <span class="muted">
                                            ·
                                            <?= h($allianceTag !== '' ? $allianceTag : (string) $allianceName) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($result['fid'])): ?>
                            <tr>
                                <th scope="row">FID</th>
                                <td><?= h((string) $result['fid']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th scope="row">State (kid)</th>
                                <td><?= h((string) ($result['kid'] ?? '—')) ?></td>
                            </tr>
                            <tr>
                                <th scope="row">Coordinates</th>
                                <td>
                                    <?php if (isset($result['x'], $result['y'])): ?>
                                        <?= h((string) $result['x']) ?> , <?= h((string) $result['y']) ?>
                                    <?php else: ?>
                                        <span class="muted">Not available — no coords for this lookup</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Alliance</th>
                                <td>
                                    <?php if ($allianceTag !== '' || $allianceName !== null || $allianceId !== ''): ?>
                                        <?php if ($allianceId !== ''): ?>
                                            <a href="alliance.php?id=<?= urlencode($allianceId) ?>" class="alliance-link">
                                                <?= h($allianceTag !== '' ? $allianceTag : ($allianceName ?? $allianceId)) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= h($allianceTag !== '' ? $allianceTag : (string) $allianceName) ?>
                                        <?php endif; ?>
                                        <?php if ($allianceName !== null && $allianceTag !== '' && strcasecmp($allianceName, $allianceTag) !== 0): ?>
                                            <span class="muted">· <?= h($allianceName) ?></span>
                                        <?php endif; ?>
                                        <?php if ($allianceId !== ''): ?>
                                            <span class="muted">(<?= h($allianceId) ?>)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Power</th>
                                <td>
                                    <?= h(formatPower($result['power'] ?? null)) ?>
                                    <?php if (($result['power_source'] ?? '') === 'state_top200'): ?>
                                        <span class="muted">(from state top 200)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Furnace / LV</th>
                                <td>
                                    <?= h(isset($result['stove_lv']) ? (string) $result['stove_lv'] : '—') ?>
                                    <?php if (isset($result['lv'])): ?>
                                        <span class="muted">· roster LV <?= h((string) $result['lv']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">State rank</th>
                                <td><?= h(isset($result['rank']) ? '#' . $result['rank'] : '—') ?></td>
                            </tr>
                            <tr>
                                <th scope="row">Jump</th>
                                <td><?= h(formatJumpPct(isset($result['last_jump_pct']) ? (float) $result['last_jump_pct'] : null)) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php renderSiteFooter(); ?>
    </div>
</body>
</html>
