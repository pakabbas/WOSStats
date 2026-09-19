<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$message = null;
$error = null;
$openModal = null;
$oldAdd = [
    'player_id' => '',
    'name' => '',
    'reason' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['nap_banned_action'])) {
    $action = (string) $_POST['nap_banned_action'];
    if ($action === 'add') {
        $oldAdd = [
            'player_id' => (string) ($_POST['player_id'] ?? ''),
            'name' => (string) ($_POST['name'] ?? ''),
            'reason' => (string) ($_POST['reason'] ?? ''),
        ];
        $result = addNapBannedPlayer($_POST);
        if ($result['ok']) {
            $message = 'Player added to the NAP banned list.';
            $oldAdd = ['player_id' => '', 'name' => '', 'reason' => ''];
        } else {
            $error = $result['error'] ?? 'Could not add player.';
            $openModal = 'add';
        }
    } elseif ($action === 'remove') {
        $result = removeNapBannedPlayer((string) ($_POST['entry_id'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            $message = 'Player removed from the NAP banned list.';
        } else {
            $error = $result['error'] ?? 'Could not remove player.';
            $openModal = 'remove';
        }
    }
}

$napBanned = loadNapBannedPlayers();
$removeEntryId = $openModal === 'remove' ? (string) ($_POST['entry_id'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <title>NAP Banned — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body<?= $openModal !== null ? ' class="nap-banned-modal-open"' : '' ?>>
    <div class="container">
        <?php renderSiteNav('nap-banned'); ?>

        <header class="page-header page-header-with-action">
            <div>
                <p class="eyebrow">NAP ENFORCEMENT</p>
                <h1>NAP Banned</h1>
                <p class="subtitle">
                    <?= h($stateName) ?> · <?= count($napBanned) ?> player<?= count($napBanned) === 1 ? '' : 's' ?>
                </p>
            </div>
            <button type="button" class="btn-primary" id="nap-banned-open-add" data-open-add>
                Add New
            </button>
        </header>

        <?php if ($message !== null): ?>
            <p class="banner banner-success"><?= h($message) ?></p>
        <?php endif; ?>
        <?php if ($error !== null && $openModal === null): ?>
            <p class="banner banner-warning"><?= h($error) ?></p>
        <?php endif; ?>

        <section class="panel">
            <div class="nap-banned-simple-list" id="nap-banned-list">
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
                            <button
                                type="button"
                                class="btn-secondary nap-banned-remove-btn"
                                data-open-remove
                                data-entry-id="<?= h($entryId) ?>"
                                data-entry-name="<?= h($entryName) ?>"
                            >
                                Remove
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <?php renderSiteFooter(); ?>
    </div>

    <div
        class="nap-banned-modal"
        id="nap-banned-add-modal"
        <?= $openModal === 'add' ? '' : 'hidden' ?>
        role="dialog"
        aria-modal="true"
        aria-labelledby="nap-banned-add-title"
    >
        <div class="nap-banned-backdrop" data-close-add tabindex="-1"></div>
        <div class="nap-banned-panel" role="document">
            <header class="nap-banned-header">
                <div>
                    <h2 id="nap-banned-add-title">Add banned player</h2>
                    <p class="muted">Password required to add</p>
                </div>
                <button type="button" class="incident-modal-close" data-close-add aria-label="Close">&times;</button>
            </header>

            <?php if ($error !== null && $openModal === 'add'): ?>
                <p class="banner banner-warning"><?= h($error) ?></p>
            <?php endif; ?>

            <form method="post" action="nap-banned.php" class="nap-banned-add-form" id="nap-banned-add-form">
                <input type="hidden" name="nap_banned_action" value="add">

                <label for="nap-banned-id">Player ID <span class="label-optional">(optional — lookup fills name)</span></label>
                <div class="nap-banned-id-row">
                    <input id="nap-banned-id" name="player_id" type="text" inputmode="numeric" maxlength="20"
                           placeholder="Chief / player ID" value="<?= h($oldAdd['player_id']) ?>">
                    <button type="button" class="btn-secondary" id="nap-banned-lookup">Lookup</button>
                </div>
                <p id="nap-banned-lookup-status" class="nap-banned-lookup-status" hidden></p>
                <div id="nap-banned-lookup-preview" class="nap-banned-lookup-preview" hidden></div>

                <label for="nap-banned-name">Player name <span class="label-optional">(name only is fine)</span></label>
                <input id="nap-banned-name" name="name" type="text" maxlength="40"
                       placeholder="e.g. SomePlayer" value="<?= h($oldAdd['name']) ?>">

                <label for="nap-banned-reason">Reason <span class="label-optional">(optional)</span></label>
                <input id="nap-banned-reason" name="reason" type="text" maxlength="200"
                       placeholder="Why they are banned from NAP" value="<?= h($oldAdd['reason']) ?>">

                <label for="nap-banned-password">Password</label>
                <input id="nap-banned-password" name="password" type="password" required
                       autocomplete="current-password" placeholder="Required to add">

                <button type="submit" class="btn-primary">Add to list</button>
            </form>
        </div>
    </div>

    <div
        class="nap-banned-modal"
        id="nap-banned-remove-modal"
        <?= $openModal === 'remove' ? '' : 'hidden' ?>
        role="dialog"
        aria-modal="true"
        aria-labelledby="nap-banned-remove-title"
    >
        <div class="nap-banned-backdrop" data-close-remove tabindex="-1"></div>
        <div class="nap-banned-panel nap-banned-panel-sm" role="document">
            <header class="nap-banned-header">
                <div>
                    <h2 id="nap-banned-remove-title">Remove player</h2>
                    <p class="muted" id="nap-banned-remove-subtitle">Enter password to confirm</p>
                </div>
                <button type="button" class="incident-modal-close" data-close-remove aria-label="Close">&times;</button>
            </header>

            <?php if ($error !== null && $openModal === 'remove'): ?>
                <p class="banner banner-warning"><?= h($error) ?></p>
            <?php endif; ?>

            <form method="post" action="nap-banned.php" class="nap-banned-remove-modal-form" id="nap-banned-remove-form">
                <input type="hidden" name="nap_banned_action" value="remove">
                <input type="hidden" name="entry_id" id="nap-banned-remove-entry-id" value="<?= h($removeEntryId) ?>">

                <p class="nap-banned-remove-target" id="nap-banned-remove-target"></p>

                <label for="nap-banned-remove-password">Password</label>
                <input id="nap-banned-remove-password" name="password" type="password" required
                       autocomplete="current-password" placeholder="Password">

                <div class="nap-banned-modal-actions">
                    <button type="button" class="btn-secondary" data-close-remove>Cancel</button>
                    <button type="submit" class="btn-primary">Remove</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= assetUrl('assets/nap-banned.js') ?>"></script>
</body>
</html>
