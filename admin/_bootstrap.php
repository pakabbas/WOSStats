<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/functions.php';

$config = loadConfig();
ensureDataFilesExist();

$stateName = $config['state_name'] ?? ('State ' . ($config['state_id'] ?? ''));
$googleClientId = googleOAuthConfig()['client_id'];
$adminUser = currentAppUser();
$isAdmin = isAppAdmin($adminUser);
$canViewVisits = canViewVisitorTracking($adminUser);

/**
 * @param 'dashboard'|'nap-rules'|'announcements'|'nap-members'|'nap-banned'|'visits' $active
 */
function renderAdminSubnav(string $active): void
{
    global $canViewVisits;

    $items = [
        'dashboard' => ['Admin', 'index.php'],
        'nap-rules' => ['NAP Rules', 'nap-rules.php'],
        'announcements' => ['Announcements', 'announcements.php'],
        'nap-members' => ['NAP Alliances', 'nap-members.php'],
        'nap-banned' => ['NAP Banned', 'nap-banned.php'],
    ];
    if (!empty($canViewVisits)) {
        $items['visits'] = ['Visitors', 'visits.php'];
    }
    ?>
    <nav class="admin-subnav" aria-label="Admin">
        <?php foreach ($items as $key => [$label, $href]): ?>
            <a class="admin-subnav-link <?= $active === $key ? 'is-active' : '' ?>" href="<?= h($href) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
}

function renderAdminAccessGate(string $stateName, string $googleClientId, ?array $adminUser, bool $isAdmin): void
{
    $signedIn = $adminUser !== null;
    ?>
    <header class="page-header">
        <div>
            <p class="eyebrow">RESTRICTED</p>
            <h1>Admin dashboard</h1>
            <p class="subtitle"><?= h($stateName) ?> · administrators only</p>
        </div>
    </header>

    <section class="panel admin-gate" id="admin-gate"
        data-google-client-id="<?= h($googleClientId) ?>"
        data-signed-in="<?= $signedIn ? '1' : '0' ?>"
        data-is-admin="<?= $isAdmin ? '1' : '0' ?>"
    >
        <?php if (!$signedIn): ?>
            <p class="muted">Sign in with the admin Google account, or an admin username, to continue.</p>
            <div id="admin-google-btn-host" class="users-google-btn-host" role="presentation"></div>

            <div class="users-auth-divider" aria-hidden="true"><span>or</span></div>

            <form id="admin-local-login" class="users-local-login" autocomplete="on">
                <label class="users-local-label" for="admin-local-username">Username</label>
                <input id="admin-local-username" name="username" type="text" maxlength="64"
                       autocomplete="username" placeholder="Username" required>

                <label class="users-local-label" for="admin-local-password">Password</label>
                <input id="admin-local-password" name="password" type="password" maxlength="128"
                       autocomplete="current-password" placeholder="Password" required>

                <button type="submit" class="btn-primary users-local-submit">Sign in</button>
            </form>

            <p id="admin-auth-status" class="chat-status" role="status"></p>
            <p class="muted">You can also sign in on <a href="../users.php">Users</a>, then return here.</p>
        <?php elseif (!$isAdmin): ?>
            <p class="banner banner-warning">
                Signed in as <?= h((string) (($adminUser['email'] ?? null) ?: ($adminUser['username'] ?? 'unknown'))) ?>, but this account is not an admin.
            </p>
            <p class="muted">Only the designated admin account can open this dashboard.</p>
            <p><a class="btn-secondary" href="../users.php">Back to Users</a></p>
        <?php endif; ?>
    </section>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script src="<?= assetUrl('assets/admin-auth.js') ?>" defer></script>
    <?php
}
