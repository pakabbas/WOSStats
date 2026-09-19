<?php

declare(strict_types=1);

/**
 * Bot-facing page: current leaderboard video + OCR verification key.
 * No nav, no upload UI — used by OCR / vision bots.
 */

require_once __DIR__ . '/functions.php';

$videoPath = currentVideoPublicPath();
$videoMeta = ensureVideoMeta();
$ocrKey = $videoMeta['ocr_key'] ?? null;

$videoUrl = $videoPath !== null
    ? $videoPath . '?t=' . (string) (is_file(VIDEO_UPLOADS_DIR . '/' . basename($videoPath))
        ? (int) filemtime(VIDEO_UPLOADS_DIR . '/' . basename($videoPath))
        : time())
    : null;

$ext = $videoPath !== null ? strtolower(pathinfo($videoPath, PATHINFO_EXTENSION)) : '';
$mimeByExt = [
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'mov' => 'video/quicktime',
    'm4v' => 'video/x-m4v',
    'ogv' => 'video/ogg',
];
$mime = $mimeByExt[$ext] ?? 'video/mp4';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#000000">
    <title>Today's Video</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body {
            margin: 0;
            width: 100%;
            height: 100%;
            background: #000;
            color: #e8eef7;
            font-family: "Segoe UI", system-ui, sans-serif;
        }
        body {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            justify-content: flex-start;
            min-height: 100vh;
            min-height: 100dvh;
        }
        .ocr-key-bar {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 0.65rem 1rem;
            background: #fff;
            color: #000;
            border-bottom: 3px solid #000;
            z-index: 2;
        }
        .ocr-key-label {
            font-size: clamp(0.85rem, 2.5vw, 1.1rem);
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .ocr-key-value {
            font-family: "Consolas", "Courier New", monospace;
            font-size: clamp(1.6rem, 6vw, 2.6rem);
            font-weight: 800;
            letter-spacing: 0.28em;
            line-height: 1;
            user-select: all;
        }
        .stage {
            flex: 1;
            min-height: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
        }
        video {
            display: block;
            width: 100%;
            height: 100%;
            max-width: 100vw;
            max-height: calc(100vh - 4.5rem);
            max-height: calc(100dvh - 4.5rem);
            object-fit: contain;
            background: #000;
        }
        .empty {
            margin: 0;
            padding: 1.5rem;
            text-align: center;
            color: #8b9bb3;
            font-size: 1rem;
        }
    </style>
</head>
<body>
    <?php if ($ocrKey !== null): ?>
        <div class="ocr-key-bar" id="ocr-key-bar">
            <span class="ocr-key-label">OCR KEY</span>
            <span class="ocr-key-value" id="ocr-key"><?= h($ocrKey) ?></span>
        </div>
    <?php endif; ?>

    <div class="stage">
        <?php if ($videoUrl !== null): ?>
            <video
                id="todays-video"
                controls
                playsinline
                autoplay
                muted
                preload="auto"
                src="<?= h($videoUrl) ?>"
            >
                <source src="<?= h($videoUrl) ?>" type="<?= h($mime) ?>">
                Video not supported.
            </video>
        <?php else: ?>
            <p class="empty">No video uploaded yet.</p>
        <?php endif; ?>
    </div>
</body>
</html>
