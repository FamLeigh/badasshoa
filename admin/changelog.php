<?php
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/changelog.php';

$flashError = null;

// --- Create ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'create') {
    csrf_check();
    $entry = changelog_add([
        'title'       => $_POST['title']       ?? '',
        'type'        => $_POST['type']        ?? '',
        'description' => $_POST['description'] ?? '',
        'link'        => $_POST['link']        ?? '',
        'at'          => ($_POST['at'] ?? '') ?: date('c'),
    ]);
    if ($entry) {
        audit('changelog.added', ['title' => $entry['title'], 'type' => $entry['type']]);
        flash('success', 'Entry added.');
        redirect('/admin/changelog.php');
    } else {
        $flashError = 'Title and a valid type are required.';
    }
}

// --- Update ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'update') {
    csrf_check();
    $id = (string)($_POST['id'] ?? '');
    $ok = changelog_update($id, [
        'title'       => $_POST['title']       ?? '',
        'type'        => $_POST['type']        ?? '',
        'description' => $_POST['description'] ?? '',
        'link'        => $_POST['link']        ?? '',
        'at'          => ($_POST['at'] ?? '') ?: null,
    ]);
    if ($ok) {
        audit('changelog.updated', ['id' => $id]);
        flash('success', 'Entry updated.');
        redirect('/admin/changelog.php');
    } else {
        $flashError = 'Could not update — entry not found.';
    }
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    $id = (string)($_POST['id'] ?? '');
    if (changelog_delete($id)) {
        audit('changelog.deleted', ['id' => $id]);
        flash('success', 'Entry deleted.');
    } else {
        flash('error', 'Could not delete entry.');
    }
    redirect('/admin/changelog.php');
}

// Filter + listing
$filterType = (string)($_GET['type'] ?? '');
$entries    = changelog_all($filterType ?: null);
$types      = changelog_types();

// Edit target
$editing = null;
if (($_GET['action'] ?? '') === 'edit') {
    $editing = changelog_find((string)($_GET['id'] ?? ''));
}
$creating = ($_GET['action'] ?? '') === 'new';

$page_title = 'Changelog — Admin';
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1180px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Changelog</h1>
            <p class="muted">Public release notes. Visible at <a href="/changelog.php">/changelog.php</a>.</p>
        </div>
        <div class="row" style="gap: var(--sp-2);">
            <a class="btn btn--ghost" href="/changelog.php" target="_blank" rel="noopener">View public →</a>
            <a class="btn btn--primary" href="?action=new">+ New entry</a>
        </div>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($creating || $editing): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title"><?= $editing ? 'Edit entry' : 'New entry' ?></h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/admin/changelog.php">← Back to list</a>
        </div>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="<?= $editing ? 'update' : 'create' ?>">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= e((string)$editing['id']) ?>"><?php endif; ?>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="cl-title">Title</label>
                    <input class="input" id="cl-title" name="title" required value="<?= e((string)($editing['title'] ?? '')) ?>" placeholder="What changed in one sentence">
                </div>
                <div class="field">
                    <label class="field__label" for="cl-type">Type</label>
                    <select class="select" id="cl-type" name="type" required>
                        <?php foreach ($types as $key => $meta):
                            $sel = ($editing && $editing['type'] === $key) ? 'selected' : ''; ?>
                            <option value="<?= e($key) ?>" <?= $sel ?>><?= e($meta['label']) ?> &nbsp;(<?= e($key) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label class="field__label" for="cl-desc">Description</label>
                <textarea class="textarea" id="cl-desc" name="description" rows="4" placeholder="Two or three sentences. Plain text."><?= e((string)($editing['description'] ?? '')) ?></textarea>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="cl-link">Link (optional)</label>
                    <input class="input" id="cl-link" name="link" value="<?= e((string)($editing['link'] ?? '')) ?>" placeholder="/feature or https://…">
                </div>
                <div class="field">
                    <label class="field__label" for="cl-at">When</label>
                    <input class="input" type="datetime-local" id="cl-at" name="at"
                           value="<?= e($editing ? date('Y-m-d\TH:i', strtotime((string)$editing['at'])) : date('Y-m-d\TH:i')) ?>">
                    <div class="field__hint">Leave alone for 'now'.</div>
                </div>
            </div>

            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/admin/changelog.php">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $editing ? 'Save changes' : 'Add entry' ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Filter chips -->
    <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-5); flex-wrap: wrap;">
        <a class="badge <?= $filterType === '' ? 'badge--navy' : '' ?>" href="/admin/changelog.php" style="text-decoration:none; cursor:pointer;">
            All <span class="muted" style="margin-left: 4px;"><?= count(changelog_all()) ?></span>
        </a>
        <?php foreach ($types as $key => $meta):
            $count = count(changelog_all($key));
            $active = $filterType === $key;
        ?>
            <a class="badge <?= $active ? $meta['badge'] : '' ?>" href="?type=<?= e($key) ?>" style="text-decoration:none; cursor:pointer; <?= !$active ? 'opacity: 0.7;' : '' ?>">
                <?= e($meta['label']) ?> <span style="margin-left: 4px;"><?= $count ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$entries): ?>
        <div class="card card--padded center"><p class="muted">No entries<?= $filterType ? ' for this type' : '' ?> yet.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead>
            <tr>
                <th style="width: 130px;">When</th>
                <th style="width: 110px;">Type</th>
                <th>Title</th>
                <th>Link</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $e):
            $meta = $types[$e['type']] ?? null;
        ?>
            <tr>
                <td>
                    <?= e(date('M j, Y', strtotime((string)$e['at']))) ?>
                    <div class="muted" style="font-size: var(--fs-xs);"><?= e(date('g:i A', strtotime((string)$e['at']))) ?></div>
                </td>
                <td>
                    <?php if ($meta): ?>
                        <span class="badge <?= e($meta['badge']) ?>"><?= e($meta['label']) ?></span>
                    <?php else: ?>
                        <span class="badge"><?= e((string)$e['type']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?= e((string)$e['title']) ?></strong>
                    <?php if (!empty($e['description'])): ?>
                        <div class="muted" style="font-size: var(--fs-xs); margin-top: 2px;">
                            <?= e(mb_strimwidth((string)$e['description'], 0, 120, '…')) ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($e['link'])): ?>
                        <a href="<?= e((string)$e['link']) ?>" target="_blank" rel="noopener" style="font-size: var(--fs-xs);"><?= e(mb_strimwidth((string)$e['link'], 0, 32, '…')) ?></a>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right; white-space: nowrap;">
                    <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= e((string)$e['id']) ?>">Edit</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this entry?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="delete">
                        <input type="hidden" name="id" value="<?= e((string)$e['id']) ?>">
                        <button class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
