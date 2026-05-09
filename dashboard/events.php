<?php
require __DIR__ . '/_bootstrap.php';

$user      = current_user();
$canManage = (ROLE_RANK[$user['role']] ?? 0) >= ROLE_RANK['board_member'];
$flashError = null;

// --- Add ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $title       = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $location    = trim((string)($_POST['location'] ?? ''));
    $starts      = trim((string)($_POST['starts_at'] ?? ''));
    $ends        = trim((string)($_POST['ends_at'] ?? ''));
    $audience    = $_POST['audience'] ?? 'members';
    if (!in_array($audience, ['all','members','board'], true)) $audience = 'members';

    $startsTs = $starts !== '' ? strtotime($starts) : 0;
    $endsTs   = $ends   !== '' ? strtotime($ends)   : 0;

    if ($title === '')                           $flashError = 'Title is required.';
    elseif ($startsTs === 0 || $startsTs === false) $flashError = 'A valid start date/time is required.';
    elseif ($endsTs && $endsTs < $startsTs)      $flashError = 'End must be after start.';
    else {
        $startsSql = date('Y-m-d H:i:s', $startsTs);
        $endsSql   = $endsTs ? date('Y-m-d H:i:s', $endsTs) : null;
        $stmt = db()->prepare(
            'INSERT INTO events (association_id, title, description, location, starts_at, ends_at, audience, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$assocId, $title, $description ?: null, $location ?: null, $startsSql, $endsSql, $audience, (int)$user['id']]);
        $newId = (int)db()->lastInsertId();
        audit('event.created', ['title' => $title, 'audience' => $audience], $newId, 'event');
        flash('success', "Event \"$title\" added.");
        redirect('/dashboard/events.php');
    }
}

// --- Edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $eid         = (int)($_POST['id'] ?? 0);
    $title       = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $location    = trim((string)($_POST['location'] ?? ''));
    $starts      = trim((string)($_POST['starts_at'] ?? ''));
    $ends        = trim((string)($_POST['ends_at'] ?? ''));
    $audience    = $_POST['audience'] ?? 'members';
    if (!in_array($audience, ['all','members','board'], true)) $audience = 'members';

    $check = db()->prepare('SELECT 1 FROM events WHERE id = ? AND association_id = ?');
    $check->execute([$eid, $assocId]);
    if (!$check->fetchColumn()) { http_response_code(404); die('Event not found'); }

    $startsTs = $starts !== '' ? strtotime($starts) : 0;
    $endsTs   = $ends   !== '' ? strtotime($ends)   : 0;
    if ($title === '')                           $flashError = 'Title is required.';
    elseif ($startsTs === 0 || $startsTs === false) $flashError = 'A valid start date/time is required.';
    elseif ($endsTs && $endsTs < $startsTs)      $flashError = 'End must be after start.';
    else {
        db()->prepare(
            'UPDATE events SET title = ?, description = ?, location = ?, starts_at = ?, ends_at = ?, audience = ?
             WHERE id = ? AND association_id = ?'
        )->execute([
            $title, $description ?: null, $location ?: null,
            date('Y-m-d H:i:s', $startsTs),
            $endsTs ? date('Y-m-d H:i:s', $endsTs) : null,
            $audience, $eid, $assocId,
        ]);
        audit('event.edited', ['title' => $title], $eid, 'event');
        flash('success', "Event updated.");
        redirect('/dashboard/events.php');
    }
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $eid = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT title FROM events WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $row = $stmt->fetch();
    if ($row) {
        db()->prepare('DELETE FROM events WHERE id = ? AND association_id = ?')->execute([$eid, $assocId]);
        audit('event.deleted', ['title' => $row['title']], $eid, 'event');
        flash('success', "Event \"{$row['title']}\" deleted.");
    }
    redirect('/dashboard/events.php');
}

// Filter: upcoming vs past
$showPast = isset($_GET['past']);
$audienceFilter = $_GET['audience'] ?? '';

// Tenants only see what they're allowed to see
$userRank = ROLE_RANK[$user['role']] ?? 0;
$allowedAudiences = ['all', 'members'];
if ($userRank >= ROLE_RANK['board_member']) {
    $allowedAudiences[] = 'board';
}
$placeholders = implode(',', array_fill(0, count($allowedAudiences), '?'));

$timeCondition = $showPast ? 'starts_at < NOW()' : 'starts_at >= NOW()';
$orderBy       = $showPast ? 'starts_at DESC' : 'starts_at ASC';

$sql = "SELECT * FROM events WHERE association_id = ? AND audience IN ($placeholders) AND $timeCondition";
$params = array_merge([$assocId], $allowedAudiences);
if (in_array($audienceFilter, ['all','members','board'], true)) {
    $sql .= ' AND audience = ?';
    $params[] = $audienceFilter;
}
$sql .= " ORDER BY $orderBy LIMIT 100";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Edit target
$editEvent = null;
if (($_GET['action'] ?? '') === 'edit' && $canManage) {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM events WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editEvent = $stmt->fetch() ?: null;
}
$showCreate = ($_GET['action'] ?? '') === 'new' && $canManage;

$page_title = 'Events — ' . $association['name'];
require __DIR__ . '/../includes/header.php';

function event_form_card(?array $editing, string $assocSlug): void {
    $isEdit = $editing !== null;
    $vals   = $editing ?? ['title'=>'','description'=>'','location'=>'','starts_at'=>'','ends_at'=>'','audience'=>'members','id'=>0];
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title"><?= $isEdit ? 'Edit event' : 'New event' ?></h3>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="<?= $isEdit ? 'edit' : 'add' ?>">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$vals['id'] ?>"><?php endif; ?>

            <div class="field">
                <label class="field__label" for="ev-t">Title</label>
                <input class="input" id="ev-t" name="title" required maxlength="255" value="<?= e((string)$vals['title']) ?>" placeholder="Quarterly board meeting">
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ev-s">Starts at</label>
                    <input class="input" type="datetime-local" id="ev-s" name="starts_at" required value="<?= $vals['starts_at'] ? e(date('Y-m-d\TH:i', strtotime((string)$vals['starts_at']))) : '' ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="ev-e">Ends at (optional)</label>
                    <input class="input" type="datetime-local" id="ev-e" name="ends_at" value="<?= $vals['ends_at'] ? e(date('Y-m-d\TH:i', strtotime((string)$vals['ends_at']))) : '' ?>">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ev-l">Location</label>
                    <input class="input" id="ev-l" name="location" maxlength="255" value="<?= e((string)$vals['location']) ?>" placeholder="Clubhouse">
                </div>
                <div class="field">
                    <label class="field__label" for="ev-a">Audience</label>
                    <select class="select" id="ev-a" name="audience">
                        <option value="all"     <?= $vals['audience']==='all'     ?'selected':'' ?>>Public — visible on landing</option>
                        <option value="members" <?= $vals['audience']==='members' ?'selected':'' ?>>Members — signed-in residents only</option>
                        <option value="board"   <?= $vals['audience']==='board'   ?'selected':'' ?>>Board only</option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="ev-d">Description</label>
                <textarea class="textarea" id="ev-d" name="description" rows="4"><?= e((string)$vals['description']) ?></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/events.php">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Add event' ?></button>
            </div>
        </form>
    </div>
    <?php
}
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1100px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Events</h1>
            <p class="muted">Board meetings, social gatherings, work parties. Public events also appear on
                <a href="/<?= e((string)$association['subdomain']) ?>/" target="_blank" rel="noopener">your community landing</a>.</p>
        </div>
        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="?action=new">+ New event</a>
        <?php endif; ?>
    </div>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>

    <?php if ($showCreate || $editEvent) event_form_card($editEvent, (string)$association['subdomain']); ?>

    <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-5); flex-wrap: wrap;">
        <a class="badge <?= !$showPast ? 'badge--orange' : '' ?>" href="?<?= $audienceFilter ? 'audience=' . e($audienceFilter) : '' ?>" style="text-decoration:none; <?= $showPast ? 'opacity: 0.6;' : '' ?>">Upcoming</a>
        <a class="badge <?= $showPast ? 'badge--navy' : '' ?>" href="?past=1<?= $audienceFilter ? '&audience=' . e($audienceFilter) : '' ?>" style="text-decoration:none; <?= !$showPast ? 'opacity: 0.6;' : '' ?>">Past</a>
        <span class="muted" style="font-size: var(--fs-xs); align-self:center; margin-left: var(--sp-3);">Audience:</span>
        <?php foreach (['all'=>'Public','members'=>'Members','board'=>'Board'] as $val => $lbl):
            $href = '?' . ($showPast ? 'past=1&' : '') . 'audience=' . $val;
            $active = $audienceFilter === $val;
        ?>
            <a class="badge <?= $active ? 'badge--info' : '' ?>" href="<?= e($href) ?>" style="text-decoration:none; <?= !$active ? 'opacity: 0.7;' : '' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
        <?php if ($audienceFilter): ?>
            <a class="muted" href="?<?= $showPast ? 'past=1' : '' ?>" style="font-size: var(--fs-xs); margin-left: var(--sp-2);">clear</a>
        <?php endif; ?>
    </div>

    <?php if (!$rows): ?>
        <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
            <p class="muted">No <?= $showPast ? 'past' : 'upcoming' ?> events.</p>
            <?php if ($canManage && !$showPast): ?>
                <p style="margin-top: var(--sp-4);"><a class="btn btn--primary" href="?action=new">Add your first event</a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="stack-lg">
    <?php foreach ($rows as $ev):
        $startTs = strtotime((string)$ev['starts_at']);
        $endTs   = !empty($ev['ends_at']) ? strtotime((string)$ev['ends_at']) : null;
        $sameDay = $endTs && date('Y-m-d', $startTs) === date('Y-m-d', $endTs);
        $audClass = match ($ev['audience']) {
            'all'     => 'badge--success',
            'board'   => 'badge--navy',
            default   => 'badge--info',
        };
    ?>
    <article class="card card--padded">
        <div class="row row--between" style="align-items:flex-start; margin-bottom: var(--sp-3);">
            <div style="flex: 1; min-width: 0;">
                <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-2); flex-wrap: wrap;">
                    <span class="badge <?= $audClass ?>"><?= e((string)$ev['audience']) ?></span>
                    <span class="muted" style="font-size: var(--fs-sm);">
                        <?= e(date('D, M j · g:i A', $startTs)) ?>
                        <?php if ($endTs): ?>
                            – <?= e(date($sameDay ? 'g:i A' : 'M j, g:i A', $endTs)) ?>
                        <?php endif; ?>
                    </span>
                    <?php if (!empty($ev['location'])): ?>
                        <span class="muted" style="font-size: var(--fs-sm);">&middot; <?= e((string)$ev['location']) ?></span>
                    <?php endif; ?>
                </div>
                <h3 style="font-size: var(--fs-lg); margin: 0;"><?= e((string)$ev['title']) ?></h3>
            </div>
            <?php if ($canManage): ?>
            <div class="row" style="gap: var(--sp-2);">
                <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$ev['id'] ?>">Edit</a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this event?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
                    <button class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);" type="submit">Delete</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($ev['description'])): ?>
            <p style="margin: 0; white-space: pre-wrap; color: var(--color-text-soft);"><?= e((string)$ev['description']) ?></p>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
