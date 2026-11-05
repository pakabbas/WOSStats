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
        $result = saveNapRule([
            'title' => $_POST['title'] ?? '',
            'body' => $_POST['body'] ?? '',
            'sort_order' => $_POST['sort_order'] ?? 0,
            'is_published' => isset($_POST['is_published']) ? 1 : 0,
        ], $id > 0 ? $id : null);
        if ($result['ok']) {
            header('Location: nap-rules.php?saved=1');
            exit;
        }
        $error = $result['error'] ?? 'Could not save rule.';
        $editId = $id;
    } elseif ($action === 'delete') {
        $result = deleteNapRule((int) ($_POST['id'] ?? 0));
        if ($result['ok']) {
            header('Location: nap-rules.php?deleted=1');
            exit;
        }
        $error = $result['error'] ?? 'Could not delete rule.';
    }
}

if (isset($_GET['saved'])) {
    $message = 'Rule saved.';
}
if (isset($_GET['deleted'])) {
    $message = 'Rule deleted.';
}

$rules = $isAdmin ? loadNapRules(false) : [];
$editing = null;
if ($editId > 0) {
    foreach ($rules as $rule) {
        if ($rule['id'] === $editId) {
            $editing = $rule;
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
    <title>Admin · NAP Rules — <?= h($stateName) ?></title>
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
                    <h1>NAP Rules</h1>
                    <p class="subtitle">Published rules appear on the public NAP Rules page</p>
                </div>
                <a class="btn-secondary" href="../nap-rules.php" target="_blank" rel="noopener">View public page</a>
            </header>

            <?php renderAdminSubnav('nap-rules'); ?>

            <?php if ($message !== null): ?>
                <p class="banner banner-success"><?= h($message) ?></p>
            <?php endif; ?>
            <?php if ($error !== null): ?>
                <p class="banner banner-warning"><?= h($error) ?></p>
            <?php endif; ?>

            <section class="panel">
                <h2><?= $editing ? 'Edit rule' : 'Add rule' ?></h2>
                <form method="post" class="admin-form">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                    <label>
                        <span>Title</span>
                        <input type="text" name="title" required maxlength="255"
                               value="<?= h((string) ($editing['title'] ?? '')) ?>">
                    </label>
                    <label>
                        <span>Rule text</span>
                        <textarea name="body" rows="5" required><?= h((string) ($editing['body'] ?? '')) ?></textarea>
                    </label>
                    <div class="admin-form-row">
                        <label>
                            <span>Sort order</span>
                            <input type="number" name="sort_order" value="<?= (int) ($editing['sort_order'] ?? 0) ?>">
                        </label>
                        <label class="admin-check">
                            <input type="checkbox" name="is_published" value="1"
                                <?= !isset($editing) || !empty($editing['is_published']) ? 'checked' : '' ?>>
                            <span>Published</span>
                        </label>
                    </div>
                    <div class="admin-form-actions">
                        <button type="submit" class="btn-primary"><?= $editing ? 'Update rule' : 'Add rule' ?></button>
                        <?php if ($editing): ?>
                            <a class="btn-secondary" href="nap-rules.php">Cancel edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="panel">
                <h2>All rules</h2>
                <?php if ($rules === []): ?>
                    <p class="chat-empty">No rules yet.</p>
                <?php else: ?>
                    <div class="admin-list">
                        <?php foreach ($rules as $rule): ?>
                            <div class="admin-list-row">
                                <div>
                                    <strong>#<?= (int) $rule['sort_order'] ?> · <?= h($rule['title']) ?></strong>
                                    <span class="muted">
                                        <?= $rule['is_published'] ? 'Published' : 'Draft' ?>
                                        · <?= h(formatDisplayDate($rule['updated_at'] ?: null)) ?>
                                    </span>
                                    <p class="admin-list-preview"><?= h(mb_substr($rule['body'], 0, 140)) ?><?= mb_strlen($rule['body']) > 140 ? '…' : '' ?></p>
                                </div>
                                <div class="admin-list-actions">
                                    <a class="btn-secondary" href="nap-rules.php?edit=<?= (int) $rule['id'] ?>">Edit</a>
                                    <form method="post" onsubmit="return confirm('Delete this rule?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
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
