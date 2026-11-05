<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$items = loadAnnouncements(true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <title>Announcements — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body>
    <div class="container">
        <?php renderSiteNav('announcements'); ?>

        <header class="page-header">
            <div>
                <p class="eyebrow">STATE NEWS</p>
                <h1>Announcements</h1>
                <p class="subtitle"><?= h($stateName) ?> · official updates</p>
            </div>
        </header>

        <section class="panel content-feed">
            <?php if ($items === []): ?>
                <p class="chat-empty">No announcements yet.</p>
            <?php else: ?>
                <div class="content-announce-list">
                    <?php foreach ($items as $item): ?>
                        <article class="content-card">
                            <header class="content-card-header">
                                <h2><?= h($item['title']) ?></h2>
                                <time class="muted"><?= h(formatDisplayDate($item['created_at'] ?: null)) ?></time>
                            </header>
                            <div class="content-body"><?= nl2br(h($item['body'])) ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php renderSiteFooter(); ?>
    </div>
</body>
</html>
