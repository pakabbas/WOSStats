<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$message = null;
$error = null;
$oldAdd = [
    'player_id' => '',
    'name' => '',
    'reason' => '',
];

if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['nap_banned_action'] ?? '');
    if ($action === 'add') {
        $oldAdd = [
            'player_id' => (string) ($_POST['player_id'] ?? ''),
            'name' => (string) ($_POST['name'] ?? ''),
            'reason' => (string) ($_POST['reason'] ?? ''),
        ];
        $result = addNapBannedPlayer($_POST, true);
        if ($result['ok']) {
            header('Location: nap-banned.php?saved=1');
            exit;
        }
        $error = $result['error'] ?? 'Could not add player.';
    } elseif ($action === 'remove') {
        $result = removeNapBannedPlayer((string) ($_POST['entry_id'] ?? ''), '', true);
        if ($result['ok']) {
            header('Location: nap-banned.php?deleted=1');
            exit;
        }
        $error = $result['error'] ?? 'Could not remove player.';
    }
}

if (isset($_GET['saved'])) {
    $message = 'Player added to the NAP banned list.';
}
if (isset($_GET['deleted'])) {
    $message = 'Player removed from the NAP banned list.';
}

$napBanned = $isAdmin ? loadNapBannedPlayers() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <meta name="robots" content="noindex,nofollow">
    <title>Admin · NAP Banned — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body>
    <div class="container">
        <?php renderSiteNav('admin'); ?>

        <?php if (!$isAdmin): ?>
            <?php renderAdminAccessGate($stateName, $googleClientId, $adminUser, $isAdmin); ?>
        <?php else: ?>
            <header class="page-header page-header-with-action">
                <div>
                    <p class="eyebrow">ADMIN</p>
                    <h1>NAP Banned</h1>
                    <p class="subtitle">
                        <?= count($napBanned) ?> player<?= count($napBanned) === 1 ? '' : 's' ?>
                        · no password needed here
                    </p>
                </div>
                <a class="btn-secondary" href="../nap-banned.php" target="_blank" rel="noopener">View public page</a>
            </header>

            <?php renderAdminSubnav('nap-banned'); ?>

            <?php if ($message !== null): ?>
                <p class="banner banner-success"><?= h($message) ?></p>
            <?php endif; ?>
            <?php if ($error !== null): ?>
                <p class="banner banner-warning"><?= h($error) ?></p>
            <?php endif; ?>

            <section class="panel">
                <h2>Add banned player</h2>
                <form method="post" class="admin-form" id="nap-banned-add-form">
                    <input type="hidden" name="nap_banned_action" value="add">

                    <label for="nap-banned-id">
                        <span>Player ID <span class="muted">(optional — lookup fills name)</span></span>
                    </label>
                    <div class="nap-banned-id-row">
                        <input id="nap-banned-id" name="player_id" type="text" inputmode="numeric" maxlength="20"
                               placeholder="Chief / player ID" value="<?= h($oldAdd['player_id']) ?>">
                        <button type="button" class="btn-secondary" id="nap-banned-lookup"
                                data-lookup-url="../player-info.php"
                                data-found-message="Player found — review and add.">Lookup</button>
                    </div>
                    <p id="nap-banned-lookup-status" class="nap-banned-lookup-status" hidden></p>
                    <div id="nap-banned-lookup-preview" class="nap-banned-lookup-preview" hidden></div>

                    <label for="nap-banned-name">
                        <span>Player name</span>
                        <input id="nap-banned-name" name="name" type="text" maxlength="40"
                               placeholder="e.g. SomePlayer" value="<?= h($oldAdd['name']) ?>">
                    </label>

                    <label for="nap-banned-reason">
                        <span>Reason <span class="muted">(optional)</span></span>
                        <input id="nap-banned-reason" name="reason" type="text" maxlength="200"
                               placeholder="Why they are banned from NAP" value="<?= h($oldAdd['reason']) ?>">
                    </label>

                    <div class="admin-form-actions">
                        <button type="submit" class="btn-primary">Add to list</button>
                    </div>
                </form>
            </section>

            <section class="panel">
                <h2>Banned players</h2>
                <div class="nap-banned-simple-list">
                    <?php if ($napBanned === []): ?>
                        <p class="chat-empty">No banned players yet.</p>
                    <?php else: ?>
                        <?php foreach ($napBanned as $entry): ?>
                            <?php
                            $entryId = (string) ($entry['id'] ?? '');
                            $entryName = (string) ($entry['name'] ?? 'Unknown');
                            $metaParts = [];
                            if (!empty($entry['player_id'])) {
                                $metaParts[] = 'ID ' . (string) $entry['player_id'];
                            }
                            $metaParts[] = formatDisplayDate($entry['added_at'] ?? null);
                            ?>
                            <div class="nap-banned-row">
                                <div class="nap-banned-row-main">
                                    <strong><?= h($entryName) ?></strong>
                                    <span class="muted"><?= h(implode(' · ', $metaParts)) ?></span>
                                    <?php if (!empty($entry['reason'])): ?>
                                        <span class="nap-banned-reason"><?= h((string) $entry['reason']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <form method="post" onsubmit="return confirm('Remove <?= h($entryName) ?> from the NAP banned list?');">
                                    <input type="hidden" name="nap_banned_action" value="remove">
                                    <input type="hidden" name="entry_id" value="<?= h($entryId) ?>">
                                    <button type="submit" class="btn-secondary">Remove</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php renderSiteFooter(); ?>
    </div>

    <?php if ($isAdmin): ?>
        <script src="<?= assetUrl('assets/nap-banned.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
