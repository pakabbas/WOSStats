<?php

declare(strict_types=1);

/**
 * Chat image upload (multipart or JSON base64).
 * Max 512 KB. Saved under uploads/chat/.
 */

require_once __DIR__ . '/functions.php';

const CHAT_UPLOADS_DIR = __DIR__ . '/uploads/chat';
const CHAT_IMAGE_MAX_BYTES = 512 * 1024;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only.']);
    exit;
}

try {
    if (!is_dir(CHAT_UPLOADS_DIR) && !mkdir(CHAT_UPLOADS_DIR, 0755, true) && !is_dir(CHAT_UPLOADS_DIR)) {
        throw new RuntimeException('Upload folder missing.');
    }

    $mimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $binary = null;
    $mime = '';

    if (isset($_FILES['image']) && is_array($_FILES['image'])) {
        $file = $_FILES['image'];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('Image is too large (max 512 KB).');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload.');
        }
        if ($size <= 0 || $size > CHAT_IMAGE_MAX_BYTES) {
            throw new RuntimeException('Image must be 512 KB or smaller.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        if (!isset($mimeMap[$mime])) {
            throw new RuntimeException('Only JPG, PNG, WebP, or GIF allowed.');
        }
        $binary = file_get_contents($tmp);
    } else {
        $raw = file_get_contents('php://input');
        $payload = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $dataUrl = is_array($payload) ? (string) ($payload['image'] ?? $payload['data'] ?? '') : '';
        if ($dataUrl === '' || !preg_match('#^data:(image/(?:jpeg|png|webp|gif));base64,(.+)$#s', $dataUrl, $m)) {
            throw new RuntimeException('No image provided.');
        }
        $mime = $m[1];
        if (!isset($mimeMap[$mime])) {
            throw new RuntimeException('Only JPG, PNG, WebP, or GIF allowed.');
        }
        $binary = base64_decode($m[2], true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Invalid image data.');
        }
        if (strlen($binary) > CHAT_IMAGE_MAX_BYTES) {
            throw new RuntimeException('Image must be 512 KB or smaller.');
        }
    }

    if (!is_string($binary) || $binary === '') {
        throw new RuntimeException('Empty image.');
    }

    $name = 'chat-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $mimeMap[$mime];
    $dest = CHAT_UPLOADS_DIR . '/' . $name;
    if (file_put_contents($dest, $binary) === false) {
        throw new RuntimeException('Could not save image.');
    }
    @chmod($dest, 0644);

    $path = 'uploads/chat/' . $name;
    echo json_encode([
        'ok' => true,
        'path' => $path,
        'url' => rtrim(sitePublicBaseUrl(), '/') . '/' . $path,
        'bytes' => strlen($binary),
        'mime' => $mime,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
