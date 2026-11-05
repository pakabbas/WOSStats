<?php

declare(strict_types=1);

/**
 * Manually provision a username/password app user (CLI only).
 *
 * Usage:
 *   php scripts/create-local-user.php Disney 'YOUR_PASSWORD'
 *   php scripts/create-local-user.php Disney 'YOUR_PASSWORD' 'Disney'
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/functions.php';

$username = (string) ($argv[1] ?? '');
$password = (string) ($argv[2] ?? '');
$display = (string) ($argv[3] ?? $username);

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php scripts/create-local-user.php <username> <password> [display_name]\n");
    exit(1);
}

$result = upsertLocalAppUser($username, $password, $display);
if (!$result['ok']) {
    fwrite(STDERR, ($result['error'] ?? 'Failed') . "\n");
    exit(1);
}

$user = $result['user'];
echo "OK id=" . ($user['id'] ?? '') . " username=" . ($user['username'] ?? '') . " name=" . ($user['display_name'] ?? '') . "\n";
