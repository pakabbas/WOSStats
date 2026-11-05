<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$report = $canViewVisits ? getSiteVisitReport() : [
    'total_hits' => 0,
    'unique_visitors' => 0,
    'total_duration_seconds' => 0,
    'avg_duration_seconds' => 0,
    'last_visit' => null,
    'storage' => '',
    'visitors' => [],
    'pages' => [],
];
$visitors = is_array($report['visitors'] ?? null) ? $report['visitors'] : [];
$pages = is_array($report['pages'] ?? null) ? $report['pages'] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <meta name="robots" content="noindex,nofollow">
    <title>Visitor Tracking — Admin — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body class="page-visits-admin">
    <div class="container visits-desk">
        <?php renderSiteNav('admin'); ?>

        <?php if (!$isAdmin): ?>
            <?php renderAdminAccessGate($stateName, $googleClientId, $adminUser, $isAdmin); ?>
        <?php elseif (!$canViewVisits): ?>
            <header class="page-header">
                <div>
                    <p class="eyebrow">RESTRICTED</p>
                    <h1>Visitor tracking</h1>
                    <p class="subtitle"><?= h($stateName) ?></p>
                </div>
            </header>
            <?php renderAdminSubnav('dashboard'); ?>
            <section class="panel">
                <p class="banner banner-warning">This page is not available for your admin account.</p>
                <p><a class="btn-secondary" href="index.php">Back to Admin</a></p>
            </section>
        <?php else: ?>
            <header class="page-header visits-desk-header">
                <div>
                    <p class="eyebrow">ADMIN</p>
                    <h1>Visitor tracking</h1>
                    <p class="subtitle">
                        Whole-site analytics · <?= h((string) ($report['storage'] ?? '')) ?>
                    </p>
                </div>
            </header>

            <?php renderAdminSubnav('visits'); ?>

            <section class="visits-summary" aria-label="Visit summary">
                <div class="visits-summary-card">
                    <span class="visits-summary-label">Total visits</span>
                    <span class="visits-summary-value"><?= number_format((int) ($report['total_hits'] ?? 0)) ?></span>
                </div>
                <div class="visits-summary-card">
                    <span class="visits-summary-label">Unique visitors</span>
                    <span class="visits-summary-value"><?= number_format((int) ($report['unique_visitors'] ?? 0)) ?></span>
                </div>
                <div class="visits-summary-card">
                    <span class="visits-summary-label">Avg time spent</span>
                    <span class="visits-summary-value"><?= h(formatVisitDuration((int) ($report['avg_duration_seconds'] ?? 0))) ?></span>
                </div>
                <div class="visits-summary-card">
                    <span class="visits-summary-label">Total time spent</span>
                    <span class="visits-summary-value"><?= h(formatVisitDuration((int) ($report['total_duration_seconds'] ?? 0))) ?></span>
                </div>
                <div class="visits-summary-card visits-summary-card-wide">
                    <span class="visits-summary-label">Last visit</span>
                    <span class="visits-summary-value visits-summary-value-date">
                        <?= h(!empty($report['last_visit']) ? formatDisplayDate((string) $report['last_visit']) : 'Never') ?>
                    </span>
                </div>
            </section>

            <section class="panel visits-panel">
                <div class="visits-panel-head">
                    <h2>Visitors</h2>
                    <p class="visits-panel-note">
                        Time spent = last activity − first activity.
                        Location is approximate (ip-api.com), cached per visitor.
                    </p>
                </div>
                <?php if ($visitors === []): ?>
                    <p class="chat-empty">No visitors recorded yet.</p>
                <?php else: ?>
                    <div class="visits-table-shell">
                        <table class="visits-desk-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="visits-col-ip">IP address</th>
                                    <th scope="col">Location</th>
                                    <th scope="col">First activity</th>
                                    <th scope="col">Last activity</th>
                                    <th scope="col" class="is-num">Time spent</th>
                                    <th scope="col" class="is-num">Hits</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($visitors as $row): ?>
                                    <?php
                                    $ipFull = trim((string) (($row['ip_address'] ?? '') !== ''
                                        ? $row['ip_address']
                                        : ($row['visitor_label'] ?? '')));
                                    if ($ipFull === '') {
                                        $ipFull = 'Unknown IP';
                                    }
                                    ?>
                                    <tr>
                                        <td class="visits-col-ip">
                                            <code class="visits-ip" title="<?= h($ipFull) ?>"><?= h($ipFull) ?></code>
                                        </td>
                                        <td class="visits-location"><?= h((string) ($row['location'] ?? 'Unknown')) ?></td>
                                        <td class="visits-datetime">
                                            <?= h(!empty($row['first_seen']) ? formatDisplayDate((string) $row['first_seen']) : '—') ?>
                                        </td>
                                        <td class="visits-datetime">
                                            <?= h(!empty($row['last_seen']) ? formatDisplayDate((string) $row['last_seen']) : '—') ?>
                                        </td>
                                        <td class="is-num visits-duration">
                                            <?= h(formatVisitDuration((int) ($row['duration_seconds'] ?? 0))) ?>
                                        </td>
                                        <td class="is-num visits-hits"><?= number_format((int) ($row['hits'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel visits-panel">
                <div class="visits-panel-head">
                    <h2>By page</h2>
                </div>
                <?php if ($pages === []): ?>
                    <p class="chat-empty">No page hits yet.</p>
                <?php else: ?>
                    <div class="visits-table-shell visits-table-shell-compact">
                        <table class="visits-desk-table visits-desk-table-pages">
                            <thead>
                                <tr>
                                    <th scope="col">Page</th>
                                    <th scope="col" class="is-num">Total visits</th>
                                    <th scope="col" class="is-num">Unique visitors</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pages as $page): ?>
                                    <tr>
                                        <td><code class="visits-page-key"><?= h((string) ($page['page_key'] ?? '')) ?></code></td>
                                        <td class="is-num visits-hits"><?= number_format((int) ($page['total'] ?? 0)) ?></td>
                                        <td class="is-num visits-hits"><?= number_format((int) ($page['unique'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php renderSiteFooter(); ?>
    </div>
</body>
</html>
