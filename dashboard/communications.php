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
    if (!in_array($type, ['general','emergency','event','maintenance'], true)) $type = 'general';
    if (!in_array($aud,  ['all','owners','renters','board'], true))           $aud = 'all';

    if ($title === '' || $body === '') {
        $flashError = 'Title and body are required.';
    } else {
        $stmt = db()->prepare(
            'INSERT INTO announcements (association_id, author_id, title, body, type, audience, send_email)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$assocId, (int)$user['id'], $title, $body, $type, $aud, isset($_POST['send_email']) ? 1 : 0]);
        $newId = (int)db()->lastInsertId();
        audit('announcement.posted', ['title' => $title, 'type' => $type, 'audience' => $aud], $newId, 'announcement');
        flash('success', "Posted &ldquo;$title&rdquo;.");
        redirect('/dashboard/communications.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    if (!$canPost) { http_response_code(403); die('Forbidden'); }
    $id = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM announcements WHERE id = ? AND association_id = ?')->execute([$id, $assocId]);
    audit('announcement.deleted', [], $id, 'announcement');
    flash('success', 'Announcement deleted.');
    redirect('/dashboard/communications.php');
}

$qType = $_GET['type'] ?? '';
$qAud  = $_GET['audience'] ?? '';
$sql = 'SELECT a.*, CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS author
        FROM announcements a LEFT JOIN users u ON u.id = a.author_id
        WHERE a.association_id = ?';
$params = [$assocId];
if (in_array($qType, ['general','emergency','event','maintenance'], true)) { $sql .= ' AND a.type = ?'; $params[] = $qType; }
if (in_array($qAud,  ['all','owners','renters','board'], true))           { $sql .= ' AND a.audience = ?'; $params[] = $qAud; }
$sql .= ' ORDER BY a.published_at DESC LIMIT 100';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$showNew = ($_GET['action'] ?? '') === 'new' && $canPost;
$page_title = 'Communications — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1180px;">
    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Communications</h1>
            <p class="muted">Announcements, alerts, events, maintenance notices.</p>
        </div>
        <?php if ($canPost): ?>
            <a class="btn btn--primary" href="?action=new">+ Post announcement</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showNew): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title">New announcement</h3>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="post">
            <div class="field"><label class="field__label" for="atitle">Title</label><input class="input" id="atitle" name="title" required></div>
            <div class="field"><label class="field__label" for="abody">Body</label><textarea class="textarea" id="abody" name="body" rows="6" required></textarea></div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="atype">Type</label>
                    <select class="select" id="atype" name="type">
                        <option value="general">General</option>
                        <option value="event">Event</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="emergency">Emergency</option>
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
            <label style="display:flex; align-items:center; gap: var(--sp-2); font-size: var(--fs-sm);">
                <input type="checkbox" name="send_email"> Also email this to the audience (queues for next mail run)
            </label>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/communications.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Publish</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <form method="get" class="row" style="margin-bottom: var(--sp-4);">
        <select class="select" name="type" style="max-width: 200px;">
            <option value="">All types</option>
            <?php foreach (['general','event','maintenance','emergency'] as $t): ?>
                <option value="<?= e($t) ?>" <?= $qType===$t?'selected':'' ?>><?= e(ucfirst($t)) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="select" name="audience" style="max-width: 200px;">
            <option value="">All audiences</option>
            <?php foreach (['all','owners','renters','board'] as $a): ?>
                <option value="<?= e($a) ?>" <?= $qAud===$a?'selected':'' ?>><?= e(ucfirst($a)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn--ghost" type="submit">Filter</button>
    </form>

    <?php if (!$rows): ?>
        <div class="card card--padded center"><p class="muted">No announcements yet.</p></div>
    <?php else: ?>
        <div class="stack-lg">
        <?php foreach ($rows as $a):
            $typeClass = $a['type'] === 'emergency' ? 'badge--error'
                       : ($a['type'] === 'event' ? 'badge--info'
                       : ($a['type'] === 'maintenance' ? 'badge--warning' : 'badge--orange'));
        ?>
        <article class="card card--padded">
            <div class="row row--between" style="margin-bottom: var(--sp-3);">
                <div class="row" style="gap: var(--sp-2);">
                    <span class="badge <?= $typeClass ?>"><?= e($a['type']) ?></span>
                    <span class="badge"><?= e($a['audience']) ?></span>
                    <span class="muted" style="font-size: var(--fs-xs);"><?= e(date('M j, Y', strtotime($a['published_at']))) ?> &middot; <?= e(trim((string)$a['author']) ?: 'Unknown') ?></span>
                </div>
                <?php if ($canPost): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete announcement?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn--ghost" type="submit">Delete</button>
                    </form>
                <?php endif; ?>
            </div>
            <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-2);"><?= e($a['title']) ?></h2>
            <p style="margin: 0; white-space: pre-wrap;"><?= e($a['body']) ?></p>
        </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
