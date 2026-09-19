<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/player-lookup.php';

$config = loadConfig();
ensureDataFilesExist();

$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$defaultStateId = (string) ($config['state_id'] ?? '');

$queryUid = preg_replace('/\D+/', '', (string) ($_GET['uid'] ?? $_POST['uid'] ?? '')) ?? '';
$queryState = preg_replace('/\D+/', '', (string) ($_GET['state'] ?? $_POST['state'] ?? '')) ?? '';

$error = null;
$result = null;
$stateMismatch = false;
$searched = $queryUid !== '';

if ($searched) {
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
}
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
            <p class="subtitle">Look up any Chief ID · coordinates when available from lookup or local roster</p>
        </header>

        <section class="panel">
            <form class="player-search-form" method="get" action="player-search.php">
                <div class="player-search-fields">
                    <div class="player-search-field">
                        <label for="player-uid">Player ID (Chief ID)</label>
                        <input id="player-uid" name="uid" type="text" inputmode="numeric" pattern="[0-9]*"
                               required maxlength="20" autocomplete="off"
                               value="<?= h($queryUid) ?>" placeholder="e.g. 209143052">
                    </div>
                    <div class="player-search-field">
                        <label for="player-state">State <span class="label-optional">(optional)</span></label>
                        <input id="player-state" name="state" type="text" inputmode="numeric" pattern="[0-9]*"
                               maxlength="8" autocomplete="off"
                               value="<?= h($queryState) ?>"
                               placeholder="Not needed for ID · e.g. <?= h($defaultStateId) ?>">
                    </div>
                </div>
                <p class="muted player-search-note">
                    State is optional for Chief ID lookups. Use it only to verify the player is in a specific state.
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

        <?php if ($result !== null): ?>
            <?php if ($stateMismatch): ?>
                <p class="banner banner-warning">
                    This player is in state <?= h((string) $result['kid']) ?>, not <?= h($queryState) ?>.
                </p>
            <?php endif; ?>

            <section class="panel player-result-panel">
                <div class="player-result-header">
                    <div>
                        <p class="eyebrow">RESULT</p>
                        <h2><?= h((string) ($result['name'] ?? 'Unknown player')) ?></h2>
                        <p class="muted">UID <?= h((string) $result['id']) ?></p>
                    </div>
                    <?php if (!empty($result['alliance_tag'])): ?>
                        <span class="office-badge"><?= h((string) $result['alliance_tag']) ?></span>
                    <?php endif; ?>
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
                                <td><?= h((string) ($result['name'] ?? '—')) ?></td>
                            </tr>
                            <tr>
                                <th scope="row">Chief ID</th>
                                <td><?= h((string) $result['id']) ?></td>
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
                                    <?php if (!empty($result['alliance_tag']) || !empty($result['alliance_id'])): ?>
                                        <?= h((string) ($result['alliance_tag'] ?? '—')) ?>
                                        <?php if (!empty($result['alliance_id'])): ?>
                                            <span class="muted">(<?= h((string) $result['alliance_id']) ?>)</span>
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
        <?php elseif ($searched && $error === null): ?>
            <p class="banner banner-warning">No player found for that ID.</p>
        <?php endif; ?>

        <?php renderSiteFooter(); ?>
    </div>
</body>
</html>
