<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$config = loadConfig();
$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$googleClientId = googleOAuthConfig()['client_id'];
$bootUser = currentAppUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#070b12">
    <title>Users — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body class="page-chat page-users<?= $bootUser ? ' users-is-signed-in' : '' ?>">
    <div class="container container-chat">
        <?php renderSiteNav('users'); ?>

        <section
            id="users-app"
            class="chat-layout users-layout"
            data-google-client-id="<?= h($googleClientId) ?>"
            data-boot-signed-in="<?= $bootUser ? '1' : '0' ?>"
        >
            <aside class="users-list-panel">
                <header class="users-list-header">
                    <div class="users-list-header-row">
                        <div>
                            <p class="eyebrow">ACCOUNTS</p>
                            <h1>Users</h1>
                        </div>
                        <button type="button" id="users-signout" class="users-signout-btn" <?= $bootUser ? '' : 'hidden' ?>>
                            Sign out
                        </button>
                    </div>
                    <p class="subtitle"><?= h($stateName) ?> · private DMs</p>
                </header>

                <div id="users-auth-box" class="users-auth-box" <?= $bootUser ? 'hidden' : '' ?>>
                    <p class="muted">Sign in with Google to see members and chat privately.</p>
                    <div id="users-google-btn-host" class="users-google-btn-host" role="presentation"></div>
                    <p id="users-auth-status" class="chat-status" role="status"></p>
                </div>

                <div id="users-signed-in" class="users-signed-in" <?= $bootUser ? '' : 'hidden' ?>>
                    <div class="users-me">
                        <img id="users-me-photo" class="users-avatar" alt=""
                            <?= ($bootUser['photo_url'] ?? null) ? 'src="' . h((string) $bootUser['photo_url']) . '"' : 'hidden' ?>>
                        <div class="users-me-meta">
                            <strong id="users-me-name"><?= h((string) ($bootUser['display_name'] ?? '—')) ?></strong>
                            <span id="users-me-email" class="muted"><?= h((string) ($bootUser['email'] ?? '')) ?></span>
                        </div>
                    </div>

                    <div class="users-list-toolbar">
                        <input
                            id="users-search"
                            class="search-input"
                            type="search"
                            placeholder="Search users…"
                            aria-label="Search users"
                        >
                    </div>

                    <div id="users-list" class="users-list" aria-live="polite">
                        <p class="chat-empty"><?= $bootUser ? 'Loading users…' : 'Sign in to see users.' ?></p>
                    </div>
                </div>
            </aside>

            <div class="chat-main users-chat-main">
                <div id="users-dm-empty" class="users-dm-empty">
                    <h2>Chats</h2>
                    <p class="muted"><?= $bootUser ? 'Pick someone on the left to open a private chat.' : 'Sign in first, then pick a user to message.' ?></p>
                </div>

                <div id="users-dm-panel" class="users-dm-panel" hidden>
                    <header class="users-dm-header">
                        <button type="button" id="users-dm-back" class="chat-link-btn users-dm-back" hidden>← Users</button>
                        <img id="users-dm-photo" class="users-avatar users-avatar-sm" alt="" hidden>
                        <div>
                            <strong id="users-dm-name">—</strong>
                            <p id="users-dm-sub" class="muted">Private message</p>
                        </div>
                    </header>

                    <div id="users-dm-messages" class="chat-messages chat-messages-page" aria-live="polite">
                        <p class="chat-empty">No messages yet. Say hi.</p>
                    </div>

                    <div class="chat-composer-dock">
                        <p id="users-dm-status" class="chat-status chat-status-dock" role="status"></p>

                        <form id="users-dm-composer" class="chat-composer">
                            <div id="users-dm-image-preview" class="chat-image-preview" hidden>
                                <img id="users-dm-image-preview-img" alt="Selected image preview">
                                <div class="chat-image-preview-meta">
                                    <span id="users-dm-image-preview-name"></span>
                                    <button type="button" id="users-dm-image-clear" class="chat-link-btn">Remove</button>
                                </div>
                            </div>

                            <div class="chat-compose-row">
                                <button type="button" id="users-dm-image-btn" class="chat-image-btn" title="Attach image (max 512 KB)" aria-label="Attach image">
                                    <span class="chat-image-btn-label">Image</span>
                                </button>
                                <input id="users-dm-image-input" type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                                <input id="users-dm-text" type="text" maxlength="300" placeholder="Private message…" autocomplete="off" enterkeyhint="send">
                                <button type="submit" id="users-dm-send" class="btn-primary chat-send-btn" title="Send" aria-label="Send">
                                    <span class="chat-send-label">Send</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script type="module" src="<?= assetUrl('assets/users.js') ?>"></script>
</body>
</html>
