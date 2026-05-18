<?php
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$canPost = role_can_manage(viewing_role());
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'post') {
    csrf_check();
    if (!$canPost) { http_response_code(403); die('Forbidden'); }
    $title = trim((string)($_POST['title'] ?? ''));
    $body  = trim((string)($_POST['body'] ?? ''));
    $type  = $_POST['type'] ?? 'general';
    $aud   = $_POST['audience'] ?? 'all';
    if (!array_key_exists($type, ann_types())) $type = 'general';
    if (!in_array($aud,  ['all','owners','renters','board'], true))           $aud = 'all';

    // Scheduling: start date defaults to now (post immediately). Duration is
    // a preset that maps to an expires_at. "never" = NULL expires_at so the
    // announcement stays up indefinitely (today's behavior).
    $startsIn = trim((string)($_POST['starts_at'] ?? ''));
    $startsTs = $startsIn !== '' ? strtotime($startsIn) : false;
    $startsSql = ($startsTs && $startsTs > 0) ? date('Y-m-d H:i:s', $startsTs) : date('Y-m-d H:i:s');

    $duration = $_POST['duration'] ?? '1w';
    $expiresSql = null;
    switch ($duration) {
        case '1d':    $expiresSql = date('Y-m-d H:i:s', $startsTs ?: time())  + 0; $expiresSql = date('Y-m-d H:i:s', ($startsTs ?: time()) + 1  * 86400); break;
        case '1w':    $expiresSql = date('Y-m-d H:i:s', ($startsTs ?: time()) + 7  * 86400); break;
        case '2w':    $expiresSql = date('Y-m-d H:i:s', ($startsTs ?: time()) + 14 * 86400); break;
        case '1m':    $expiresSql = date('Y-m-d H:i:s', ($startsTs ?: time()) + 30 * 86400); break;
        case '3m':    $expiresSql = date('Y-m-d H:i:s', ($startsTs ?: time()) + 90 * 86400); break;
        case 'custom':
            $exIn = trim((string)($_POST['expires_at'] ?? ''));
            $exTs = $exIn !== '' ? strtotime($exIn) : false;
            $expiresSql = ($exTs && $exTs > 0) ? date('Y-m-d H:i:s', $exTs) : null;
            break;
        case 'never':
        default:
            $expiresSql = null;
            break;
    }

    $imgPath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowedImg = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        $imgExt = strtolower(pathinfo((string)$_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!isset($allowedImg[$imgExt])) {
            $flashError = 'Image must be JPG, PNG, WEBP, or GIF.';
        } elseif ($_FILES['image']['size'] > 8 * 1024 * 1024) {
            $flashError = 'Image must be under 8 MB.';
        } else {
            $relDir = "uploads/$assocId/announcements";
            ensure_dir(storage_path($relDir));
            $fname = uuid_filename((string)$_FILES['image']['name']);
            if (move_uploaded_file($_FILES['image']['tmp_name'], storage_path("$relDir/$fname"))) {
                $imgPath = "$relDir/$fname";
            } else {
                $flashError = 'Could not save image.';
            }
        }
    }

    if ($title === '' || $body === '') {
        $flashError = $flashError ?? 'Title and body are required.';
    }
    if (!$flashError) {
        $stmt = db()->prepare(
            'INSERT INTO announcements (association_id, author_id, title, body, type, audience, send_email, published_at, expires_at, image_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$assocId, (int)$user['id'], $title, $body, $type, $aud, isset($_POST['send_email']) ? 1 : 0, $startsSql, $expiresSql, $imgPath]);
        $newId = (int)db()->lastInsertId();
        audit('announcement.posted', ['title' => $title, 'type' => $type, 'audience' => $aud, 'starts_at' => $startsSql, 'expires_at' => $expiresSql], $newId, 'announcement');
        flash('success', "Posted &ldquo;$title&rdquo;.");
        redirect('/dashboard/communications.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    if (!$canPost) { http_response_code(403); die('Forbidden'); }
    $id = (int)($_POST['id'] ?? 0);
    $delRow = db()->prepare('SELECT image_path FROM announcements WHERE id = ? AND association_id = ?');
    $delRow->execute([$id, $assocId]);
    $delData = $delRow->fetch();
    if ($delData && !empty($delData['image_path'])) {
        $abs = storage_path((string)$delData['image_path']);
        if (is_file($abs)) @unlink($abs);
    }
    db()->prepare('DELETE FROM announcements WHERE id = ? AND association_id = ?')->execute([$id, $assocId]);
    audit('announcement.deleted', [], $id, 'announcement');
    flash('success', 'Announcement deleted.');
    redirect('/dashboard/communications.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit') {
    csrf_check();
    if (!$canPost) { http_response_code(403); die('Forbidden'); }
    $id    = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $body  = trim((string)($_POST['body'] ?? ''));
    $type  = $_POST['type'] ?? 'general';
    $aud   = $_POST['audience'] ?? 'all';
    if (!array_key_exists($type, ann_types())) $type = 'general';
    if (!in_array($aud,  ['all','owners','renters','board'], true)) $aud = 'all';

    $neverExpires = isset($_POST['never_expires']);
    $expiresRaw   = trim((string)($_POST['expires_at'] ?? ''));
    $expiresSql   = null;
    if (!$neverExpires && $expiresRaw !== '') {
        $exTs = strtotime($expiresRaw);
        if ($exTs && $exTs > 0) $expiresSql = date('Y-m-d H:i:s', $exTs);
    }

    if ($title === '') {
        $flashError = 'Title is required.';
    } elseif ($body === '') {
        $flashError = 'Body is required.';
    } else {
        $chk = db()->prepare('SELECT image_path, published_at FROM announcements WHERE id = ? AND association_id = ?');
        $chk->execute([$id, $assocId]);
        $existing = $chk->fetch();
        if (!$existing) { http_response_code(404); die('Not found'); }
        $currentImg = $existing['image_path'] ?? null;
        $newImg = $currentImg;

        if (isset($_POST['remove_image']) && $currentImg) {
            $abs = storage_path((string)$currentImg);
            if (is_file($abs)) @unlink($abs);
            $newImg = null;
        }
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $allowedImg = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
            $imgExt = strtolower(pathinfo((string)$_FILES['image']['name'], PATHINFO_EXTENSION));
            if (isset($allowedImg[$imgExt]) && $_FILES['image']['size'] <= 8 * 1024 * 1024) {
                $relDir = "uploads/$assocId/announcements";
                ensure_dir(storage_path($relDir));
                $fname = uuid_filename((string)$_FILES['image']['name']);
                if (move_uploaded_file($_FILES['image']['tmp_name'], storage_path("$relDir/$fname"))) {
                    if ($newImg) { $abs = storage_path((string)$newImg); if (is_file($abs)) @unlink($abs); }
                    $newImg = "$relDir/$fname";
                }
            }
        }

        $pubRaw = trim((string)($_POST['published_at'] ?? ''));
        $pubSql = null;
        if ($pubRaw !== '') {
            $pubTs = strtotime($pubRaw);
            if ($pubTs && $pubTs > 0) $pubSql = date('Y-m-d H:i:s', $pubTs);
        }
        if (!$pubSql) $pubSql = (string)$existing['published_at'] ?? date('Y-m-d H:i:s');

        db()->prepare(
            'UPDATE announcements SET title = ?, body = ?, type = ?, audience = ?, published_at = ?, expires_at = ?, image_path = ? WHERE id = ? AND association_id = ?'
        )->execute([$title, $body, $type, $aud, $pubSql, $expiresSql, $newImg, $id, $assocId]);
        audit('announcement.edited', ['title' => $title], $id, 'announcement');
        flash('success', 'Announcement updated.');
        redirect('/dashboard/communications.php?id=' . $id);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'end_now') {
    csrf_check();
    if (!$canPost) { http_response_code(403); die('Forbidden'); }
    $id = (int)($_POST['id'] ?? 0);
    db()->prepare(
        'UPDATE announcements SET expires_at = NOW() WHERE id = ? AND association_id = ?'
    )->execute([$id, $assocId]);
    audit('announcement.ended', [], $id, 'announcement');
    flash('success', 'Announcement ended.');
    redirect('/dashboard/communications.php?id=' . $id);
}

$qType   = $_GET['type']   ?? '';
$qAud    = $_GET['audience'] ?? '';
$qPeriod = $_GET['period']   ?? '';
if (!in_array($qPeriod, ['today','week','month'], true)) $qPeriod = '';

// Managers can flip a "Show expired / scheduled" toggle; members never see
// either (expired = past expires_at, scheduled = future published_at).
$showAll      = $canPost && isset($_GET['show_all']);
$showArchived = $canPost && isset($_GET['archived']);

// Compute period window (UTC — all DB timestamps are UTC, CLAUDE.md).
$periodLabel = '';
$periodStart = $periodEnd = null;
if ($qPeriod !== '') {
    $todayStart = strtotime(date('Y-m-d') . ' 00:00:00');
    if ($qPeriod === 'today') {
        $periodStart = $todayStart;
        $periodEnd   = $todayStart + 86400 - 1;
        $periodLabel = 'Today — ' . udate('l, F j, Y', $todayStart);
    } elseif ($qPeriod === 'week') {
        $dow         = (int)date('w', $todayStart);
        $periodStart = $todayStart - $dow * 86400;
        $periodEnd   = $periodStart + 7 * 86400 - 1;
        $periodLabel = 'Week of ' . udate('M j', $periodStart) . ' – ' . udate('M j, Y', $periodEnd);
    } elseif ($qPeriod === 'month') {
        $periodStart = strtotime(date('Y-m-01') . ' 00:00:00');
        $periodEnd   = strtotime(date('Y-m-t') . ' 23:59:59');
        $periodLabel = udate('F Y', $periodStart);
    }
}

$sql = 'SELECT a.*, CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS author
        FROM announcements a LEFT JOIN users u ON u.id = a.author_id
        WHERE a.association_id = ?';
$params = [$assocId];
if (array_key_exists($qType, ann_types())) { $sql .= ' AND a.type = ?'; $params[] = $qType; }
if (in_array($qAud,  ['all','owners','renters','board'], true))           { $sql .= ' AND a.audience = ?'; $params[] = $qAud; }
if ($periodStart !== null) {
    $sql .= ' AND a.published_at >= ? AND a.published_at <= ?';
    $params[] = date('Y-m-d H:i:s', $periodStart);
    $params[] = date('Y-m-d H:i:s', $periodEnd);
}
if ($showArchived) {
    $sql .= ' AND a.expires_at IS NOT NULL AND a.expires_at < NOW()';
} elseif (!$showAll) {
    $sql .= ' AND a.published_at <= NOW() AND (a.expires_at IS NULL OR a.expires_at >= NOW())';
}
$sql .= ' ORDER BY a.published_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$annColors = ann_type_colors($assocId);

// Detail mode: ?id=N renders a single announcement with full body + print link.
$detailId = (int)($_GET['id'] ?? 0);
$detail = null;
if ($detailId > 0) {
    $dStmt = db()->prepare(
        'SELECT a.*, CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS author
           FROM announcements a LEFT JOIN users u ON u.id = a.author_id
          WHERE a.id = ? AND a.association_id = ?'
    );
    $dStmt->execute([$detailId, $assocId]);
    $detail = $dStmt->fetch() ?: null;
}

$showNew = ($_GET['action'] ?? '') === 'new' && $canPost;
$page_title = 'Announcements — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1180px;">

    <?php if ($detail): /* ---------- DETAIL VIEW ---------- */
        $startTs = strtotime((string)$detail['published_at']);
        $expTs   = !empty($detail['expires_at']) ? strtotime((string)$detail['expires_at']) : null;
        $typeBadgeStyle = ann_badge_style((string)$detail['type'], $annColors);
    ?>
        <div class="row row--between" style="margin-bottom: var(--sp-4); flex-wrap: wrap; gap: var(--sp-3);">
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/communications.php">← Back to announcements</a>
            <div class="row" style="gap: var(--sp-2);">
                <a class="btn btn--ghost" href="/dashboard/announcement-print.php?id=<?= (int)$detail['id'] ?>" target="_blank" rel="noopener">🖨 Print</a>
                <?php if ($canPost): ?>
                    <a class="btn btn--ghost" href="?id=<?= (int)$detail['id'] ?>&action=edit">Edit</a>
                <?php endif; ?>
                <?php if ($canPost && ($expTs === null || $expTs > time())): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('End this announcement now? It will disappear from the feed immediately.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="end_now">
                        <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                        <button class="btn btn--ghost" type="submit">End now</button>
                    </form>
                <?php endif; ?>
                <?php if ($canPost): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this announcement?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                        <button class="btn btn--ghost" type="submit" style="color: var(--color-error);">Delete</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <article class="card card--padded" style="margin-bottom: var(--sp-6);">
            <!-- Date-first header with calendar icon -->
            <div class="row" style="gap: var(--sp-4); align-items: center; margin-bottom: var(--sp-4); padding-bottom: var(--sp-3); border-bottom: 1px solid var(--color-border);">
                <div style="text-align:center; min-width: 70px; padding: 6px 10px; border: 2px solid var(--color-navy); border-radius: 8px; background: var(--color-surface);">
                    <div style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-text-soft); font-weight: 700;"><?= e(udate('M', $startTs)) ?></div>
                    <div style="font-size: 26pt; line-height: 1; font-weight: 800; color: var(--color-navy);"><?= e(udate('j', $startTs)) ?></div>
                    <div style="font-size: var(--fs-xs); color: var(--color-text-soft);"><?= e(udate('Y', $startTs)) ?></div>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-1); flex-wrap: wrap;">
                        <span class="badge" style="<?= $typeBadgeStyle ?>"><?= e(ann_type_label((string)$detail['type'])) ?></span>
                        <span class="badge"><?= e($detail['audience']) ?></span>
                        <?php if ($expTs && $expTs < time()): ?>
                            <span class="badge" style="background:#e8e8e8; color:#666;">⌛ expired</span>
                        <?php elseif (time() < $startTs): ?>
                            <span class="badge badge--info">⏳ scheduled</span>
                        <?php endif; ?>
                    </div>
                    <h1 style="font-size: var(--fs-2xl); margin: 0;"><?= e((string)$detail['title']) ?></h1>
                    <div class="muted" style="font-size: var(--fs-sm); margin-top: var(--sp-1);">
                        <?= e(udate('l, F j, Y · g:i A', $startTs)) ?>
                        · by <?= e(trim((string)$detail['author']) ?: 'Unknown') ?>
                        <?php if ($expTs): ?> · expires <?= e(udate('M j, Y', $expTs)) ?><?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($detail['image_path'])): ?>
                <img src="/announcement-image.php?id=<?= (int)$detail['id'] ?>"
                     alt=""
                     style="width: 100%; max-height: 360px; object-fit: cover; border-radius: var(--r-md); margin-bottom: var(--sp-4); display: block;">
            <?php endif; ?>
            <div style="white-space: pre-wrap; line-height: 1.55; font-size: var(--fs-md);"><?= e((string)$detail['body']) ?></div>
        </article>

    <?php if ($canPost && ($_GET['action'] ?? '') === 'edit'): ?>
    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title">Edit announcement</h3>
        <form method="post" enctype="multipart/form-data" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="edit">
            <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
            <div class="field">
                <label class="field__label" for="etitle">Title</label>
                <input class="input" id="etitle" name="title" required value="<?= e((string)$detail['title']) ?>">
            </div>
            <div class="field">
                <label class="field__label" for="ebody">Body</label>
                <textarea class="textarea" id="ebody" name="body" rows="8" required><?= e((string)$detail['body']) ?></textarea>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="etype">Type</label>
                    <select class="select" id="etype" name="type">
                        <?php
                        $curGroup = '';
                        foreach (ann_types() as $v => $meta):
                            if ($meta['group'] !== $curGroup) {
                                if ($curGroup !== '') echo '</optgroup>';
                                echo '<optgroup label="' . e($meta['group']) . '">';
                                $curGroup = $meta['group'];
                            }
                        ?>
                            <option value="<?= e($v) ?>" <?= $detail['type']===$v?'selected':'' ?>><?= e($meta['emoji'] . ' ' . $meta['label']) ?></option>
                        <?php endforeach; if ($curGroup !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="eaud">Audience</label>
                    <select class="select" id="eaud" name="audience">
                        <?php foreach (['all'=>'Everyone','owners'=>'Owners only','renters'=>'Renters only','board'=>'Board only'] as $v=>$l): ?>
                            <option value="<?= e($v) ?>" <?= $detail['audience']===$v?'selected':'' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="epub">Post date</label>
                    <input class="input" type="datetime-local" id="epub" name="published_at"
                           value="<?= e(date('Y-m-d\TH:i', strtotime((string)$detail['published_at']))) ?>">
                    <div class="field__hint">Changes when the announcement appears in the feed and on cards.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="eexpires">Expires at</label>
                    <input class="input" type="datetime-local" id="eexpires" name="expires_at"
                           value="<?= !empty($detail['expires_at']) ? e(date('Y-m-d\TH:i', strtotime((string)$detail['expires_at']))) : '' ?>">
                    <label style="display:flex; align-items:center; gap: var(--sp-2); margin-top: var(--sp-2); font-size: var(--fs-sm);">
                        <input type="checkbox" name="never_expires" id="enever" <?= empty($detail['expires_at']) ? 'checked' : '' ?>>
                        Never expires
                    </label>
                </div>
            </div>
            <div class="field">
                <label class="field__label">Image (optional)</label>
                <?php if (!empty($detail['image_path'])): ?>
                    <div style="margin-bottom: var(--sp-2);">
                        <img src="/announcement-image.php?id=<?= (int)$detail['id'] ?>" alt="" style="max-height: 120px; border-radius: var(--r-md); display: block;">
                    </div>
                    <label style="display:flex; align-items:center; gap: var(--sp-2); font-size: var(--fs-sm); margin-bottom: var(--sp-2);">
                        <input type="checkbox" name="remove_image"> Remove current image
                    </label>
                <?php endif; ?>
                <input class="input" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
                <div class="field__hint">JPG, PNG, WEBP, GIF · max 8 MB. <?= !empty($detail['image_path']) ? 'Upload a new file to replace the current one.' : '' ?></div>
            </div>
            <div class="row" style="justify-content: flex-end; gap: var(--sp-2);">
                <a class="btn btn--ghost" href="?id=<?= (int)$detail['id'] ?>">Cancel</a>
                <button class="btn btn--primary" type="submit">Save changes</button>
            </div>
        </form>
    </div>
    <script>
    (function () {
        var cb  = document.getElementById('enever');
        var inp = document.getElementById('eexpires');
        if (!cb || !inp) return;
        function sync() { inp.disabled = cb.checked; }
        cb.addEventListener('change', sync);
        sync();
    })();
    </script>
    <?php endif; ?>

    <?php else: /* ---------- LISTING VIEW ---------- */ ?>

    <div class="row row--between" style="margin-bottom: var(--sp-4); flex-wrap: wrap; gap: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Announcements</h1>
            <?php if ($periodLabel !== ''): ?>
                <p class="muted" style="margin: var(--sp-1) 0 0;"><?= e($periodLabel) ?> &middot; <?= count($rows) ?> announcement<?= count($rows) === 1 ? '' : 's' ?></p>
            <?php else: ?>
                <p class="muted" style="margin: var(--sp-1) 0 0;">Announcements, alerts, events, maintenance notices.</p>
            <?php endif; ?>
        </div>
        <?php if ($canPost): ?>
            <div class="row" style="gap: var(--sp-2);">
                <?php if (!$showArchived): ?>
                    <a class="btn btn--primary" href="?action=new">+ Post</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php
    // Build a clean query string for filter links (no action/id bleed-through).
    $filterBase = array_filter(['type' => $qType, 'audience' => $qAud, 'show_all' => ($showAll ? '1' : '')], fn($v) => $v !== '');
    $periodLink = function(string $p) use ($filterBase): string {
        $q = array_filter(array_merge($filterBase, ['period' => $p]), fn($v) => $v !== '');
        return '/dashboard/communications.php' . ($q ? '?' . http_build_query($q) : '');
    };
    $archiveLink = '/dashboard/communications.php?archived=1'
        . ($qType  !== '' ? '&type='     . urlencode($qType)  : '')
        . ($qAud   !== '' ? '&audience=' . urlencode($qAud)   : '');
    $printParams = array_filter(['period' => $qPeriod, 'type' => $qType, 'audience' => $qAud], fn($v) => $v !== '');
    $printUrl = '/dashboard/announcements-print.php' . ($printParams ? '?' . http_build_query($printParams) : '');
    $activeStyle = 'background: var(--color-navy); color: #fff; border-color: var(--color-navy);';
    ?>
    <div class="row" style="margin-bottom: var(--sp-4); gap: var(--sp-2); flex-wrap: wrap; align-items: center;">
        <?php if (!$showArchived): ?>
        <a class="btn btn--ghost" href="<?= e($periodLink('')) ?>" style="<?= $qPeriod === '' ? $activeStyle : '' ?>">All</a>
        <a class="btn btn--ghost" href="<?= e($periodLink('today')) ?>" style="<?= $qPeriod === 'today' ? $activeStyle : '' ?>">Today</a>
        <a class="btn btn--ghost" href="<?= e($periodLink('week')) ?>"  style="<?= $qPeriod === 'week'  ? $activeStyle : '' ?>">This week</a>
        <a class="btn btn--ghost" href="<?= e($periodLink('month')) ?>" style="<?= $qPeriod === 'month' ? $activeStyle : '' ?>">This month</a>
        <?php if ($canPost): ?>
        <a class="btn btn--ghost" href="<?= e($archiveLink) ?>">📦 Archived</a>
        <?php endif; ?>
        <?php else: ?>
        <a class="btn btn--ghost" href="/dashboard/communications.php<?= ($qType !== '' || $qAud !== '') ? '?'.http_build_query(array_filter(['type'=>$qType,'audience'=>$qAud])) : '' ?>">← Live</a>
        <span style="font-weight: 700; color: var(--color-navy);">Archived announcements</span>
        <?php endif; ?>
        <div style="flex: 1;"></div>
        <a class="btn btn--ghost" href="<?= e($printUrl) ?>" target="_blank" rel="noopener" title="Print current view">🖨 Print</a>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showNew): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title">New announcement</h3>
        <form method="post" enctype="multipart/form-data" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="post">
            <div class="field"><label class="field__label" for="atitle">Title</label><input class="input" id="atitle" name="title" required></div>
            <div class="field"><label class="field__label" for="abody">Body</label><textarea class="textarea" id="abody" name="body" rows="6" required></textarea></div>
            <div class="field">
                <label class="field__label">Image (optional)</label>
                <input class="input" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
                <div class="field__hint">JPG, PNG, WEBP, GIF · max 8 MB. Displayed on the TV board and announcement detail page.</div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="atype">Type</label>
                    <select class="select" id="atype" name="type">
                        <?php
                        $curGroup = '';
                        foreach (ann_types() as $v => $meta):
                            if ($meta['group'] !== $curGroup) {
                                if ($curGroup !== '') echo '</optgroup>';
                                echo '<optgroup label="' . e($meta['group']) . '">';
                                $curGroup = $meta['group'];
                            }
                        ?>
                            <option value="<?= e($v) ?>"><?= e($meta['emoji'] . ' ' . $meta['label']) ?></option>
                        <?php endforeach; if ($curGroup !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="aaud">Audience</label>
                    <select class="select" id="aaud" name="audience">
                        <option value="all">Everyone</option>
                        <option value="owners">Owners only</option>
                        <option value="renters">Renters only</option>
                        <option value="board">Board only</option>
                    </select>
                </div>
            </div>
            <fieldset style="border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); margin-bottom: var(--sp-4);">
                <legend style="padding: 0 var(--sp-2); color: var(--color-text-soft); font-size: var(--fs-sm);">Schedule</legend>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="astart">Start posting at</label>
                        <input class="input" type="datetime-local" id="astart" name="starts_at" value="<?= e(date('Y-m-d\TH:i')) ?>">
                        <div class="field__hint">Members see the announcement starting at this time. Defaults to now.</div>
                    </div>
                    <div class="field">
                        <label class="field__label" for="aduration">How long should it stay posted?</label>
                        <select class="select" id="aduration" name="duration" data-duration>
                            <option value="1d">1 day</option>
                            <option value="1w" selected>1 week</option>
                            <option value="2w">2 weeks</option>
                            <option value="1m">1 month</option>
                            <option value="3m">3 months</option>
                            <option value="custom">Until a specific date…</option>
                            <option value="never">Never expires</option>
                        </select>
                    </div>
                </div>
                <div class="field" id="custom-expires-field" style="display:none;">
                    <label class="field__label" for="aexpires">Expires at (custom)</label>
                    <input class="input" type="datetime-local" id="aexpires" name="expires_at">
                </div>
            </fieldset>
            <label style="display:flex; align-items:center; gap: var(--sp-2); font-size: var(--fs-sm);">
                <input type="checkbox" name="send_email"> Also email this to the audience (queues for next mail run)
            </label>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/communications.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Publish</button>
            </div>
            <script>
                (function () {
                    var sel = document.querySelector('[data-duration]');
                    var box = document.getElementById('custom-expires-field');
                    if (!sel || !box) return;
                    function sync() { box.style.display = sel.value === 'custom' ? '' : 'none'; }
                    sel.addEventListener('change', sync);
                    sync();
                })();
            </script>
        </form>
    </div>
    <?php endif; ?>

    <form method="get" class="row" style="margin-bottom: var(--sp-4);">
        <?php if ($qPeriod !== ''): ?>
            <input type="hidden" name="period" value="<?= e($qPeriod) ?>">
        <?php endif; ?>
        <select class="select" name="type" style="max-width: 200px;">
            <option value="">All types</option>
            <?php foreach (ann_types() as $v => $meta): ?>
                <option value="<?= e($v) ?>" <?= $qType===$v?'selected':'' ?>><?= e($meta['emoji'] . ' ' . $meta['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="select" name="audience" style="max-width: 200px;">
            <option value="">All audiences</option>
            <?php foreach (['all','owners','renters','board'] as $a): ?>
                <option value="<?= e($a) ?>" <?= $qAud===$a?'selected':'' ?>><?= e(ucfirst($a)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn--ghost" type="submit">Filter</button>
        <?php if ($qType !== '' || $qAud !== ''): ?>
            <a class="muted" href="/dashboard/communications.php<?= $qPeriod !== '' ? '?period='.urlencode($qPeriod) : '' ?>" style="font-size: var(--fs-sm); align-self: center;">clear</a>
        <?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <div class="card card--padded center"><p class="muted">No announcements yet.</p></div>
    <?php else: ?>
        <style>
            .ann-card { display:flex; gap: var(--sp-4); align-items:flex-start; padding: var(--sp-4); border: 1px solid var(--color-border); border-radius: var(--r-md); background: var(--color-surface-2); margin-bottom: var(--sp-3); text-decoration: none; color: inherit; transition: transform 120ms ease, box-shadow 120ms ease; }
            .ann-card:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(15,31,61,0.08); }
            .ann-date { flex: 0 0 64px; text-align:center; padding: 5px 8px; border: 2px solid var(--color-navy); border-radius: 6px; background: #fff; }
            .ann-date .m { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-text-soft); font-weight: 700; }
            .ann-date .d { font-size: 22pt; line-height: 1; font-weight: 800; color: var(--color-navy); margin: 2px 0; }
            .ann-date .y { font-size: 8pt; color: var(--color-text-soft); }
            .ann-body { flex: 1 1 auto; min-width: 0; }
            .ann-thumb { flex: 0 0 72px; width: 72px; height: 72px; border-radius: var(--r-md); object-fit: cover; display: block; }
        </style>
        <?php
        $nowTs = time();
        foreach ($rows as $a):
            $typeBadgeStyle = ann_badge_style((string)$a['type'], $annColors);
            $startTs = strtotime((string)$a['published_at']);
            $expTs   = !empty($a['expires_at']) ? strtotime((string)$a['expires_at']) : null;
            $isScheduled = $startTs > $nowTs;
            $isExpired   = $expTs !== null && $expTs < $nowTs;
        ?>
        <a class="ann-card" href="?id=<?= (int)$a['id'] ?>" style="<?= $isExpired ? 'opacity: 0.55;' : ($isScheduled ? 'border-left: 3px solid var(--color-info);' : '') ?>">
            <div class="ann-date">
                <div class="m"><?= e(udate('M', $startTs)) ?></div>
                <div class="d"><?= e(udate('j', $startTs)) ?></div>
                <div class="y"><?= e(udate('Y', $startTs)) ?></div>
            </div>
            <div class="ann-body">
                <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-1); flex-wrap: wrap;">
                    <span class="badge" style="<?= $typeBadgeStyle ?>"><?= e(ann_type_label((string)$a['type'])) ?></span>
                    <span class="badge"><?= e($a['audience']) ?></span>
                    <?php if ($isScheduled): ?>
                        <span class="badge badge--info" title="Scheduled — not yet visible to members">⏳ scheduled</span>
                    <?php elseif ($isExpired): ?>
                        <span class="badge" style="background: #e8e8e8; color: #666;" title="Expired">⌛ expired</span>
                    <?php elseif ($expTs !== null): ?>
                        <span class="muted" style="font-size: var(--fs-xs);" title="Expires <?= e(udate('M j, Y g:i A', $expTs)) ?>">expires <?= e(udate('M j', $expTs)) ?></span>
                    <?php endif; ?>
                    <span class="muted" style="font-size: var(--fs-xs);"><?= e(udate('g:i A', $startTs)) ?> &middot; <?= e(trim((string)$a['author']) ?: 'Unknown') ?></span>
                </div>
                <h2 style="font-size: var(--fs-lg); margin: 0 0 var(--sp-1);"><?= e($a['title']) ?></h2>
                <p class="muted" style="margin: 0; font-size: var(--fs-sm);"><?= e(mb_strimwidth(strip_tags($a['body']), 0, 200, '…')) ?></p>
            </div>
            <?php if (!empty($a['image_path'])): ?>
                <img class="ann-thumb" src="/announcement-image.php?id=<?= (int)$a['id'] ?>" alt="" loading="lazy">
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; /* end listing branch */ ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
