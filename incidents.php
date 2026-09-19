<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$categories = incidentCategories();
$formError = null;
$formSuccess = false;
$old = [
    'nick' => '',
    'category' => 'attack',
    'title' => '',
    'details' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $old['nick'] = (string) ($_POST['nick'] ?? '');
    $old['category'] = (string) ($_POST['category'] ?? 'attack');
    $old['title'] = (string) ($_POST['title'] ?? '');
    $old['details'] = (string) ($_POST['details'] ?? '');

    $file = isset($_FILES['attachment']) && is_array($_FILES['attachment']) ? $_FILES['attachment'] : null;
    $result = submitIncidentReport($_POST, $file);
    if ($result['ok']) {
        $formSuccess = true;
        $old = [
            'nick' => $old['nick'],
            'category' => 'attack',
            'title' => '',
            'details' => '',
        ];
    } else {
        $formError = $result['error'] ?? 'Could not submit report.';
    }
}

$incidents = loadIncidentsNewestFirst(200);
$allIncidents = loadJson(INCIDENTS_FILE, []);
$todayCount = countIncidentsToday(is_array($allIncidents) ? $allIncidents : []);
$remainingToday = max(0, INCIDENT_MAX_PER_DAY - $todayCount);
$openModal = $formError !== null || (isset($_GET['report']) && $_GET['report'] === '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#070b12">
    <title>Incidents — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body class="page-incidents"<?= $openModal ? ' data-open-report="1"' : '' ?>>
    <div class="container container-incidents">
        <?php renderSiteNav('incidents'); ?>

        <header class="incident-page-header">
            <div class="incident-page-heading">
                <p class="eyebrow">WHITEOUT SURVIVAL</p>
                <h1>All reports</h1>
                <p class="subtitle">
                    <?= h($stateName) ?> · <?= count($incidents) ?> report<?= count($incidents) === 1 ? '' : 's' ?>
                    · <?= (int) $todayCount ?> / <?= INCIDENT_MAX_PER_DAY ?> used today (UTC)
                </p>
            </div>
            <?php if ($remainingToday <= 0): ?>
                <button type="button" class="btn-primary" disabled title="Daily limit reached">Daily limit reached</button>
            <?php else: ?>
                <button type="button" class="btn-primary" id="incident-open-report">Report an incident</button>
            <?php endif; ?>
        </header>

        <?php if ($formSuccess): ?>
            <p class="banner banner-success">Report submitted. Thank you.</p>
        <?php endif; ?>
        <?php if ($remainingToday <= 0 && $formError === null): ?>
            <p class="banner banner-warning">Daily limit reached. New reports open again after 00:00 UTC.</p>
        <?php endif; ?>

        <section class="incident-main" aria-label="All incident reports">
            <div class="incident-feed" id="incident-feed">
                <?php if ($incidents === []): ?>
                    <div class="incident-empty">
                        <p class="chat-empty">No incidents reported yet.</p>
                        <?php if ($remainingToday > 0): ?>
                            <button type="button" class="btn-secondary" data-open-report>Be the first to report</button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($incidents as $incident): ?>
                        <?php
                        $catKey = (string) ($incident['category'] ?? '');
                        $catLabel = $categories[$catKey] ?? 'Incident';
                        $attachment = (string) ($incident['attachment'] ?? '');
                        ?>
                        <article class="incident-card">
                            <div class="incident-card-meta">
                                <span class="incident-type incident-type-<?= h($catKey) ?>"><?= h($catLabel) ?></span>
                                <time><?= h(formatDisplayDate($incident['created_at'] ?? null)) ?></time>
                            </div>
                            <h3><?= h((string) ($incident['title'] ?? 'Untitled')) ?></h3>
                            <p class="incident-nick">by <strong><?= h((string) ($incident['nick'] ?? 'Anon')) ?></strong></p>
                            <p class="incident-details"><?= nl2br(h((string) ($incident['details'] ?? ''))) ?></p>
                            <?php if ($attachment !== '' && is_file(__DIR__ . '/' . $attachment)): ?>
                                <a class="incident-shot" href="<?= h($attachment) ?>" target="_blank" rel="noopener noreferrer">
                                    <img src="<?= h($attachment) ?>" alt="Incident screenshot" loading="lazy">
                                </a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <p class="incident-page-credit">
            Built by <strong>Abbas</strong>
            · <a href="https://wa.me/923052848987" target="_blank" rel="noopener noreferrer">WhatsApp</a>
        </p>
    </div>

    <?php if ($remainingToday > 0): ?>
        <div
            class="incident-modal"
            id="incident-modal"
            hidden
            role="dialog"
            aria-modal="true"
            aria-labelledby="incident-modal-title"
        >
            <div class="incident-modal-backdrop" data-close-report tabindex="-1"></div>
            <div class="incident-modal-panel" role="document">
                <header class="incident-modal-header">
                    <div>
                        <h2 id="incident-modal-title">Report an incident</h2>
                        <p class="muted"><?= (int) $remainingToday ?> report<?= $remainingToday === 1 ? '' : 's' ?> left today · paste screenshot with Ctrl+V</p>
                    </div>
                    <button type="button" class="incident-modal-close" data-close-report aria-label="Close">&times;</button>
                </header>

                <?php if ($formError !== null): ?>
                    <p class="banner banner-warning"><?= h($formError) ?></p>
                <?php endif; ?>

                <form class="incident-form" method="post" enctype="multipart/form-data" action="incidents.php" id="incident-form">
                    <label for="incident-nick">Your nick</label>
                    <input id="incident-nick" name="nick" type="text" maxlength="20" required
                           value="<?= h($old['nick']) ?>" placeholder="e.g. BenD0ver" autocomplete="nickname">

                    <label for="incident-category">Type</label>
                    <select id="incident-category" name="category" required>
                        <?php foreach ($categories as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $old['category'] === $key ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="incident-title">Short title</label>
                    <input id="incident-title" name="title" type="text" maxlength="80" required
                           value="<?= h($old['title']) ?>" placeholder="e.g. Rally on AOC tile">

                    <label for="incident-details">What happened</label>
                    <textarea id="incident-details" name="details" rows="5" maxlength="1000" required
                              placeholder="Who / where / when (UTC) / what rule was broken… You can paste images from clipboard too"><?= h($old['details']) ?></textarea>

                    <label for="incident-attachment">Screenshot (optional)</label>
                    <input id="incident-attachment" name="attachment" type="file"
                           accept="image/jpeg,image/png,image/webp,image/gif">
                    <p id="incident-paste-hint" class="incident-paste-hint">Tip: Ctrl+V / Cmd+V to paste a screenshot.</p>
                    <div id="incident-paste-preview" class="incident-paste-preview" hidden>
                        <img id="incident-paste-preview-img" alt="Screenshot preview">
                        <div class="incident-paste-preview-meta">
                            <span id="incident-paste-preview-name"></span>
                            <button type="button" id="incident-paste-clear" class="incident-paste-clear">Remove</button>
                        </div>
                    </div>

                    <div class="incident-form-actions">
                        <button type="button" class="btn-secondary" data-close-report>Cancel</button>
                        <button type="submit" class="btn-primary">Submit report</button>
                    </div>
                </form>
            </div>
        </div>
        <script src="<?= assetUrl('assets/incidents.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
