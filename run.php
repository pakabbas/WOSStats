<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$wantJson = isset($_GET['format']) && strtolower((string) $_GET['format']) === 'json';

$result = runAllianceDataUpdate('WOSTracker-run.php');
$ok = $result['ok'];
$output = $result['output'];
$error = $result['error'];
$httpCode = $result['http_code'];

if ($wantJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($ok ? 200 : 500);
    echo json_encode([
        'ok' => $ok,
        'http_code' => $httpCode,
        'output' => $output,
        'error' => $error,
        'message' => $ok ? ($output !== '' ? $output : 'Update successful.') : ($error ?? 'Update failed.'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#070b12">
    <title>Run Update — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body>
    <div class="container">
        <?php renderSiteNav(null); ?>

        <header class="page-header page-header-with-action">
            <div>
                <p class="eyebrow">DATA REFRESH</p>
                <h1>Run update</h1>
                <p class="subtitle">Same action as the scheduled cron job</p>
            </div>
            <a class="btn-secondary" href="index.php">← Back to alliances</a>
        </header>

        <?php if ($ok): ?>
            <p class="banner banner-success">Update successful.</p>
        <?php elseif ($error !== null): ?>
            <p class="banner banner-warning"><?= h($error) ?></p>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-toolbar">
                <h2 class="panel-title-inline">Result</h2>
                <a class="btn-secondary" href="run.php">Run again</a>
            </div>
            <pre class="run-update-output"><?= h($ok ? $output : ($error ?? 'No output')) ?></pre>
        </section>
    </div>
</body>
</html>
