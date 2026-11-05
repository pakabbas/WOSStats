<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$rules = loadNapRules(true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <title>Rules — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body>
    <div class="container">
        <?php renderSiteNav('nap-rules'); ?>

        <header class="page-header">
            <div>
                <p class="eyebrow">STATE POLICY</p>
                <h1>Rules</h1>
                <p class="subtitle"><?= h($stateName) ?> · official non-aggression rules</p>
            </div>
        </header>

        <section class="panel content-feed">
            <?php if ($rules === []): ?>
                <p class="chat-empty">No NAP rules published yet.</p>
            <?php else: ?>
                <ol class="content-rule-list">
                    <?php foreach ($rules as $rule): ?>
                        <li class="content-card">
                            <h2><?= h($rule['title']) ?></h2>
                            <div class="content-body"><?= nl2br(h($rule['body'])) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <?php renderSiteFooter(); ?>
    </div>
</body>
</html>
