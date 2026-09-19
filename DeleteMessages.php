<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#070b12">
    <title>Delete Messages — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body>
    <div class="container container-delete-messages">
        <?php renderSiteNav(); ?>

        <header class="page-header">
            <div>
                <p class="eyebrow">CHAT ADMIN</p>
                <h1>Delete messages</h1>
                <p class="subtitle"><?= h($stateName) ?> · newest first · tap Delete to remove instantly</p>
            </div>
        </header>

        <p id="dm-status" class="chat-status" role="status">Connecting…</p>

        <div id="dm-list" class="dm-list" aria-live="polite">
            <p class="chat-empty">Loading messages…</p>
        </div>
    </div>

    <script type="module" src="<?= assetUrl('assets/delete-messages.js') ?>"></script>
</body>
</html>
