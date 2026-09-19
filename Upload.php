<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

const VIDEO_MIME_MAP = [
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
    'video/quicktime' => 'mov',
    'video/x-m4v' => 'm4v',
    'video/ogg' => 'ogv',
];

function deleteAllStoredVideos(): void
{
    foreach (listStoredVideos() as $path) {
        @unlink($path);
    }
}

/**
 * @param array<string, mixed> $file from $_FILES['video']
 * @return array{ok: bool, error?: string, path?: string}
 */
function storeUploadedVideo(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Choose a video first.'];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'Video is too large for the server. Try a smaller file (max 100 MB).'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed. Please try again.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if ($size <= 0 || $size > VIDEO_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Video must be 100 MB or smaller.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    if (!isset(VIDEO_MIME_MAP[$mime])) {
        return ['ok' => false, 'error' => 'Only MP4, WebM, MOV, or M4V videos are allowed.'];
    }

    if (!is_dir(VIDEO_UPLOADS_DIR) && !mkdir(VIDEO_UPLOADS_DIR, 0755, true) && !is_dir(VIDEO_UPLOADS_DIR)) {
        return ['ok' => false, 'error' => 'Upload folder missing on server.'];
    }

    // Replace policy: wipe previous videos before saving the new one.
    deleteAllStoredVideos();

    $name = 'video-' . date('Ymd-His') . '.' . VIDEO_MIME_MAP[$mime];
    $dest = VIDEO_UPLOADS_DIR . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'Could not save video.'];
    }
    @chmod($dest, 0644);

    $publicPath = 'uploads/video/' . $name;
    recordVideoUploadMeta($publicPath);

    return ['ok' => true, 'path' => $publicPath];
}

$message = null;
$messageType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = storeUploadedVideo($_FILES['video'] ?? []);
    if ($result['ok']) {
        $message = 'Video uploaded. Previous videos were removed.';
        $messageType = 'ok';
    } else {
        $message = $result['error'] ?? 'Upload failed.';
        $messageType = 'error';
    }
}

$currentVideo = currentVideoPublicPath();
$maxMb = (int) (VIDEO_MAX_BYTES / 1024 / 1024);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#070b12">
    <meta name="robots" content="noindex, nofollow">
    <title>Upload Video</title>
    <?php renderFaviconTags(); ?>
    <style>
        :root {
            --bg: #070b12;
            --surface: rgba(16, 24, 38, 0.95);
            --text: #e8eef7;
            --muted: #8b9bb3;
            --border: rgba(125, 168, 210, 0.2);
            --accent: #5ec8ff;
            --accent-soft: rgba(94, 200, 255, 0.14);
            --ok: #3dd6a5;
            --ok-soft: rgba(61, 214, 165, 0.16);
            --err: #ff6b7a;
            --err-soft: rgba(255, 107, 122, 0.16);
            --radius: 16px;
            --font: "Segoe UI", system-ui, -apple-system, sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; }

        html { color-scheme: dark; }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            font-family: var(--font);
            color: var(--text);
            line-height: 1.5;
            background:
                radial-gradient(900px 500px at 10% -10%, rgba(46, 140, 190, 0.22), transparent 55%),
                radial-gradient(700px 400px at 90% 0%, rgba(61, 214, 165, 0.1), transparent 50%),
                #070b12;
            padding: max(1rem, env(safe-area-inset-top)) 1rem max(1.5rem, env(safe-area-inset-bottom));
            -webkit-tap-highlight-color: transparent;
        }

        .wrap {
            width: 100%;
            max-width: 420px;
            margin: 0 auto;
        }

        h1 {
            margin: 0 0 0.35rem;
            font-size: clamp(1.45rem, 5vw, 1.75rem);
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .lead {
            margin: 0 0 1.25rem;
            color: var(--muted);
            font-size: 0.95rem;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.15rem;
            box-shadow: 0 18px 50px rgba(0, 0, 0, 0.35);
        }

        .banner {
            margin: 0 0 1rem;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .banner-ok {
            background: var(--ok-soft);
            color: var(--ok);
            border: 1px solid rgba(61, 214, 165, 0.35);
        }

        .banner-error {
            background: var(--err-soft);
            color: var(--err);
            border: 1px solid rgba(255, 107, 122, 0.35);
        }

        .drop {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            min-height: 11rem;
            padding: 1.25rem;
            border: 2px dashed rgba(94, 200, 255, 0.35);
            border-radius: 14px;
            background: var(--accent-soft);
            text-align: center;
            cursor: pointer;
            user-select: none;
            transition: border-color 0.15s ease, background 0.15s ease;
        }

        .drop:active,
        .drop.is-drag {
            border-color: var(--accent);
            background: rgba(94, 200, 255, 0.22);
        }

        .drop-icon {
            width: 3.25rem;
            height: 3.25rem;
            border-radius: 50%;
            background: rgba(94, 200, 255, 0.2);
            border: 2px solid rgba(94, 200, 255, 0.45);
            position: relative;
        }

        .drop-icon::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 54%;
            transform: translate(-50%, -50%);
            border-style: solid;
            border-width: 0.55rem 0 0.55rem 0.9rem;
            border-color: transparent transparent transparent var(--accent);
        }

        .drop-title {
            font-size: 1.1rem;
            font-weight: 700;
        }

        .drop-hint {
            color: var(--muted);
            font-size: 0.88rem;
        }

        .file-name {
            margin: 0.85rem 0 0;
            padding: 0.7rem 0.85rem;
            border-radius: 10px;
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border);
            font-size: 0.9rem;
            word-break: break-all;
            display: none;
        }

        .file-name.is-visible { display: block; }

        .actions {
            margin-top: 1rem;
            display: grid;
            gap: 0.65rem;
        }

        .btn {
            appearance: none;
            width: 100%;
            min-height: 3.15rem;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            border: 1px solid transparent;
            font: inherit;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
        }

        .btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3eb4ea, #2a9fd6);
            color: #041018;
            border-color: rgba(142, 220, 255, 0.35);
        }

        .btn-ghost {
            background: transparent;
            color: var(--muted);
            border-color: var(--border);
        }

        .note {
            margin: 1rem 0 0;
            color: var(--muted);
            font-size: 0.82rem;
            text-align: center;
        }

        .current {
            margin-top: 1.25rem;
        }

        .current h2 {
            margin: 0 0 0.65rem;
            font-size: 1rem;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .current video {
            width: 100%;
            max-height: 55vh;
            border-radius: 12px;
            background: #000;
            border: 1px solid var(--border);
        }

        .empty-current {
            margin: 0;
            color: var(--muted);
            font-size: 0.92rem;
        }

        #video-input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
        }

        .busy .btn-primary {
            pointer-events: none;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Upload video</h1>
        <p class="lead">Tap to pick a video. Uploading a new one deletes the previous.</p>

        <?php if ($message !== null): ?>
            <p class="banner <?= $messageType === 'ok' ? 'banner-ok' : 'banner-error' ?>" role="status">
                <?= h($message) ?>
            </p>
        <?php endif; ?>

        <div class="card">
            <form method="post" enctype="multipart/form-data" id="upload-form" class="upload-form">
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= VIDEO_MAX_BYTES ?>">

                <label class="drop" id="drop-zone" for="video-input">
                    <span class="drop-icon" aria-hidden="true"></span>
                    <span class="drop-title">Tap to choose video</span>
                    <span class="drop-hint">MP4 · WebM · MOV · up to <?= $maxMb ?> MB</span>
                </label>
                <input
                    id="video-input"
                    type="file"
                    name="video"
                    accept="video/*,.mp4,.webm,.mov,.m4v,.ogv"
                    required
                >

                <p class="file-name" id="file-name" aria-live="polite"></p>

                <div class="actions">
                    <button type="submit" class="btn btn-primary" id="submit-btn" disabled>Upload &amp; replace</button>
                    <button type="button" class="btn btn-ghost" id="clear-btn" hidden>Clear selection</button>
                </div>
            </form>
            <p class="note">Only one video is kept. The old file is removed automatically.</p>
        </div>

        <section class="current" aria-label="Current video">
            <h2>Current video</h2>
            <?php if ($currentVideo !== null): ?>
                <video controls playsinline preload="metadata" src="<?= h($currentVideo) ?>?t=<?= time() ?>">
                    Your browser cannot play this video.
                </video>
            <?php else: ?>
                <p class="empty-current">No video uploaded yet.</p>
            <?php endif; ?>
        </section>
    </div>

    <script>
        (function () {
            const form = document.getElementById('upload-form');
            const input = document.getElementById('video-input');
            const drop = document.getElementById('drop-zone');
            const fileName = document.getElementById('file-name');
            const submitBtn = document.getElementById('submit-btn');
            const clearBtn = document.getElementById('clear-btn');

            function formatSize(bytes) {
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            }

            function syncSelection() {
                const file = input.files && input.files[0];
                if (!file) {
                    fileName.textContent = '';
                    fileName.classList.remove('is-visible');
                    submitBtn.disabled = true;
                    clearBtn.hidden = true;
                    return;
                }
                fileName.textContent = file.name + ' · ' + formatSize(file.size);
                fileName.classList.add('is-visible');
                submitBtn.disabled = false;
                clearBtn.hidden = false;
            }

            input.addEventListener('change', syncSelection);

            clearBtn.addEventListener('click', function () {
                input.value = '';
                syncSelection();
            });

            form.addEventListener('submit', function () {
                if (!input.files || !input.files[0]) return;
                submitBtn.disabled = true;
                submitBtn.textContent = 'Uploading…';
                form.classList.add('busy');
            });

            ;['dragenter', 'dragover'].forEach(function (evt) {
                drop.addEventListener(evt, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    drop.classList.add('is-drag');
                });
            });

            ;['dragleave', 'drop'].forEach(function (evt) {
                drop.addEventListener(evt, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    drop.classList.remove('is-drag');
                });
            });

            drop.addEventListener('drop', function (e) {
                const files = e.dataTransfer && e.dataTransfer.files;
                if (!files || !files.length) return;
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                input.files = dt.files;
                syncSelection();
            });
        })();
    </script>
</body>
</html>
