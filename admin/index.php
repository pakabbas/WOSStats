<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$rulesCount = count(loadNapRules(false));
$annCount = count(loadAnnouncements(false));
$overrideCount = count(loadNapMembershipOverrides());
$bannedCount = count(loadNapBannedPlayers());
$siteVisits = $canViewVisits ? getSiteVisitReport() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <meta name="robots" content="noindex,nofollow">
    <title>Admin — <?= h($stateName) ?></title>
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
                    <h1>Dashboard</h1>
                    <p class="subtitle">
                        <?= h((string) ($adminUser['email'] ?? '')) ?> · <?= h($stateName) ?>
                    </p>
                </div>
            </header>

            <?php renderAdminSubnav('dashboard'); ?>

            <section class="stats-grid">
                <div class="stat-card">
                    <span class="stat-label">NAP Rules</span>
                    <span class="stat-value"><?= $rulesCount ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Announcements</span>
                    <span class="stat-value"><?= $annCount ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">NAP overrides</span>
                    <span class="stat-value"><?= $overrideCount ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">NAP Banned</span>
                    <span class="stat-value"><?= $bannedCount ?></span>
                </div>
                <?php if ($canViewVisits && is_array($siteVisits)): ?>
                    <div class="stat-card">
                        <span class="stat-label">Site visits</span>
                        <span class="stat-value"><?= number_format((int) ($siteVisits['total_hits'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Unique visitors</span>
                        <span class="stat-value"><?= number_format((int) ($siteVisits['unique_visitors'] ?? 0)) ?></span>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel">
                <h2>Manage</h2>
                <div class="admin-card-grid">
                    <a class="admin-card" href="nap-rules.php">
                        <strong>NAP Rules</strong>
                        <span class="muted">Create and edit public NAP rules</span>
                    </a>
                    <a class="admin-card" href="announcements.php">
                        <strong>Announcements</strong>
                        <span class="muted">Post and manage state announcements</span>
                    </a>
                    <a class="admin-card" href="nap-members.php">
                        <strong>NAP Alliances</strong>
                        <span class="muted">Include or exclude alliances from NAP</span>
                    </a>
                    <a class="admin-card" href="nap-banned.php">
                        <strong>NAP Banned</strong>
                        <span class="muted">Add or remove banned players</span>
                    </a>
                    <?php if ($canViewVisits): ?>
                        <a class="admin-card" href="visits.php">
                            <strong>Visitors</strong>
                            <span class="muted">Site visits, time spent, and approximate location</span>
                        </a>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php renderSiteFooter(); ?>
    </div>
</body>
</html>
