<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$message = null;
$error = null;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $result = saveAnnouncement([
            'title' => $_POST['title'] ?? '',
            'body' => $_POST['body'] ?? '',
            'is_published' => isset($_POST['is_published']) ? 1 : 0,
        ], $id > 0 ? $id : null);
        if ($result['ok']) {
            header('Location: announcements.php?saved=1');
            exit;
        }
        $error = $result['error'] ?? 'Could not save announcement.';
        $editId = $id;
    } elseif ($action === 'delete') {
        $result = deleteAnnouncement((int) ($_POST['id'] ?? 0));
        if ($result['ok']) {
            header('Location: announcements.php?deleted=1');
            exit;
        }
        $error = $result['error'] ?? 'Could not delete announcement.';
    }
}

if (isset($_GET['saved'])) {
    $message = 'Announcement saved.';
}
if (isset($_GET['deleted'])) {
    $message = 'Announcement deleted.';
}

$items = $isAdmin ? loadAnnouncements(false) : [];
$editing = null;
if ($editId > 0) {
    foreach ($items as $item) {
        if ($item['id'] === $editId) {
            $editing = $item;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#070b12">
    <meta name="robots" content="noindex,nofollow">
    <title>Admin · Announcements — <?= h($stateName) ?></title>
    <?php renderFaviconTags(); ?>
    <link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
</head>
<body>
    <div class="container">
        <?php renderSiteNav('admin'); ?>

        <?php if (!$isAdmin): ?>
            <?php renderAdminAccessGate($stateName, $googleClientId, $adminUser, $isAdmin); ?>
        <?php else: ?>
            <header class="page-header page-header-with-action">
                <div>
                    <p class="eyebrow">ADMIN</p>
                    <h1>Announcements</h1>
                    <p class="subtitle">Published posts appear on the public Announcements page</p>
                </div>
                <a class="btn-secondary" href="../announcements.php" target="_blank" rel="noopener">View public page</a>
            </header>

            <?php renderAdminSubnav('announcements'); ?>

            <?php if ($message !== null): ?>
                <p class="banner banner-success"><?= h($message) ?></p>
            <?php endif; ?>
            <?php if ($error !== null): ?>
                <p class="banner banner-warning"><?= h($error) ?></p>
            <?php endif; ?>

            <section class="panel">
                <h2><?= $editing ? 'Edit announcement' : 'New announcement' ?></h2>
                <form method="post" class="admin-form">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                    <label>
                        <span>Title</span>
                        <input type="text" name="title" required maxlength="255"
                               value="<?= h((string) ($editing['title'] ?? '')) ?>">
                    </label>
                    <label>
                        <span>Body</span>
                        <textarea name="body" rows="6" required><?= h((string) ($editing['body'] ?? '')) ?></textarea>
                    </label>
                    <label class="admin-check">
                        <input type="checkbox" name="is_published" value="1"
                            <?= !isset($editing) || !empty($editing['is_published']) ? 'checked' : '' ?>>
                        <span>Published</span>
                    </label>
                    <div class="admin-form-actions">
                        <button type="submit" class="btn-primary"><?= $editing ? 'Update' : 'Publish' ?></button>
                        <?php if ($editing): ?>
                            <a class="btn-secondary" href="announcements.php">Cancel edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="panel">
                <h2>All announcements</h2>
                <?php if ($items === []): ?>
                    <p class="chat-empty">No announcements yet.</p>
                <?php else: ?>
                    <div class="admin-list">
                        <?php foreach ($items as $item): ?>
                            <div class="admin-list-row">
                                <div>
                                    <strong><?= h($item['title']) ?></strong>
                                    <span class="muted">
                                        <?= $item['is_published'] ? 'Published' : 'Draft' ?>
                                        · <?= h(formatDisplayDate($item['created_at'] ?: null)) ?>
                                    </span>
                                    <p class="admin-list-preview"><?= h(mb_substr($item['body'], 0, 160)) ?><?= mb_strlen($item['body']) > 160 ? '…' : '' ?></p>
                                </div>
                                <div class="admin-list-actions">
                                    <a class="btn-secondary" href="announcements.php?edit=<?= (int) $item['id'] ?>">Edit</a>
                                    <form method="post" onsubmit="return confirm('Delete this announcement?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                        <button type="submit" class="btn-secondary">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php renderSiteFooter(); ?>
    </div>
</body>
</html>
