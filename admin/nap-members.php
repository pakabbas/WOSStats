<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$message = null;
$error = null;
$napTags = $config['nap_alliances'] ?? [];

if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $key = trim((string) ($_POST['alliance_key'] ?? ''));
    $alliances = loadJson(ALLIANCES_FILE, []);
    $target = null;
    foreach (is_array($alliances) ? $alliances : [] as $alliance) {
        if (!is_array($alliance)) {
            continue;
        }
        // Match by id first (unique). Tags are case-sensitive (WaS ≠ wAs).
        if ((string) ($alliance['id'] ?? '') === $key) {
            $target = $alliance;
            break;
        }
    }
    if ($target === null) {
        foreach (is_array($alliances) ? $alliances : [] as $alliance) {
            if (!is_array($alliance)) {
                continue;
            }
            if (allianceKey($alliance) === $key || (string) ($alliance['tag'] ?? '') === $key) {
                $target = $alliance;
                break;
            }
        }
    }

    if ($action === 'include' || $action === 'exclude') {
        if ($target === null) {
            $error = 'Alliance not found.';
        } else {
            $result = setNapMembershipOverride($target, $action);
            if ($result['ok']) {
                header('Location: nap-members.php?ok=1');
                exit;
            }
            $error = $result['error'] ?? 'Could not update membership.';
        }
    } elseif ($action === 'clear') {
        $result = clearNapMembershipOverride($key);
        if ($result['ok']) {
            header('Location: nap-members.php?ok=1');
            exit;
        }
        $error = $result['error'] ?? 'Could not clear override.';
    }
}

if (isset($_GET['ok'])) {
    $message = 'NAP membership updated.';
}

$alliances = [];
if ($isAdmin) {
    $alliances = loadJson(ALLIANCES_FILE, []);
    if (!is_array($alliances)) {
        $alliances = [];
    }
    usort($alliances, static fn(array $a, array $b): int => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <meta name="robots" content="noindex,nofollow">
    <title>Admin · NAP Alliances — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body>
    <div class="container">
        <?php renderSiteNav('admin'); ?>

        <?php if (!$isAdmin): ?>
            <?php renderAdminAccessGate($stateName, $googleClientId, $adminUser, $isAdmin); ?>
        <?php else: ?>
            <header class="page-header">
                <div>
                    <p class="eyebrow">ADMIN</p>
                    <h1>NAP Alliances</h1>
                    <p class="subtitle">
                        Default: top <?= (int) NAP_TOP_ALLIANCES ?> by rank.
                        Use Include / Exclude to override.
                    </p>
                </div>
            </header>

            <?php renderAdminSubnav('nap-members'); ?>

            <?php if ($message !== null): ?>
                <p class="banner banner-success"><?= h($message) ?></p>
            <?php endif; ?>
            <?php if ($error !== null): ?>
                <p class="banner banner-warning"><?= h($error) ?></p>
            <?php endif; ?>

            <section class="panel">
                <div class="table-wrap">
                    <table class="alliance-table responsive-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Alliance</th>
                                <th>Tag</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($alliances === []): ?>
                                <tr><td colspan="5" class="empty-cell">No alliances loaded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($alliances as $alliance): ?>
                                    <?php
                                    $key = allianceKey($alliance);
                                    $override = napMembershipModeForAlliance($alliance);
                                    $isNap = isNapAlliance($alliance, is_array($napTags) ? $napTags : []);
                                    if ($override === 'include') {
                                        $status = 'Included (override)';
                                    } elseif ($override === 'exclude') {
                                        $status = 'Excluded (override)';
                                    } elseif ($isNap) {
                                        $status = 'NAP (top ' . NAP_TOP_ALLIANCES . ')';
                                    } else {
                                        $status = 'Non-NAP';
                                    }
                                    ?>
                                    <tr class="<?= $isNap ? 'nap-row' : '' ?>">
                                        <td data-label="Rank"><?= h((string) ($alliance['rank'] ?? '—')) ?></td>
                                        <td data-label="Alliance"><?= h((string) ($alliance['name'] ?? 'Unknown')) ?></td>
                                        <td data-label="Tag"><?= h((string) ($alliance['tag'] ?? '—')) ?></td>
                                        <td data-label="Status"><?= h($status) ?></td>
                                        <td data-label="Actions">
                                            <div class="admin-list-actions">
                                                <?php if ($override !== 'include'): ?>
                                                    <form method="post">
                                                        <input type="hidden" name="action" value="include">
                                                        <input type="hidden" name="alliance_key" value="<?= h($key) ?>">
                                                        <button type="submit" class="btn-secondary">Include</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($override !== 'exclude'): ?>
                                                    <form method="post">
                                                        <input type="hidden" name="action" value="exclude">
                                                        <input type="hidden" name="alliance_key" value="<?= h($key) ?>">
                                                        <button type="submit" class="btn-secondary">Exclude</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($override !== null): ?>
                                                    <form method="post">
                                                        <input type="hidden" name="action" value="clear">
                                                        <input type="hidden" name="alliance_key" value="<?= h($key) ?>">
                                                        <button type="submit" class="btn-secondary">Clear</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php renderSiteFooter(); ?>
    </div>
</body>
</html>
