<?php
require __DIR__ . '/_bootstrap.php';

$user      = current_user();
$canManage = role_can_manage(viewing_role());
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
    $recurType   = $_POST['recurrence_type'] ?? 'none';
    $recurUntil  = trim((string)($_POST['recurrence_until'] ?? ''));
    if (!in_array($audience, ['all','members','board'], true)) $audience = 'members';
    if (!in_array($recurType, ['none','daily','weekly','biweekly','monthly'], true)) $recurType = 'none';
    if ($recurUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $recurUntil)) $recurUntil = '';
    $recurUntilSql = ($recurType !== 'none' && $recurUntil !== '') ? $recurUntil : null;

    $startsTs = $starts !== '' ? strtotime($starts) : 0;
    $endsTs   = $ends   !== '' ? strtotime($ends)   : 0;

    if ($title === '')                           $flashError = 'Title is required.';
    elseif ($startsTs === 0 || $startsTs === false) $flashError = 'A valid start date/time is required.';
    elseif ($endsTs && $endsTs < $startsTs)      $flashError = 'End must be after start.';
    else {
        $startsSql = date('Y-m-d H:i:s', $startsTs);
        $endsSql   = $endsTs ? date('Y-m-d H:i:s', $endsTs) : null;
        $stmt = db()->prepare(
            'INSERT INTO events (association_id, title, description, location, starts_at, ends_at, audience, recurrence_type, recurrence_until, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$assocId, $title, $description ?: null, $location ?: null, $startsSql, $endsSql, $audience, $recurType, $recurUntilSql, (int)$user['id']]);
        $newId = (int)db()->lastInsertId();
        audit('event.created', ['title' => $title, 'audience' => $audience, 'recurrence' => $recurType], $newId, 'event');
        flash('success', "Event \"$title\" added" . ($recurType !== 'none' ? " (repeats {$recurType})." : '.'));
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
    $recurType   = $_POST['recurrence_type'] ?? 'none';
    $recurUntil  = trim((string)($_POST['recurrence_until'] ?? ''));
    if (!in_array($audience, ['all','members','board'], true)) $audience = 'members';
    if (!in_array($recurType, ['none','daily','weekly','biweekly','monthly'], true)) $recurType = 'none';
    if ($recurUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $recurUntil)) $recurUntil = '';
    $recurUntilSql = ($recurType !== 'none' && $recurUntil !== '') ? $recurUntil : null;

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
            'UPDATE events SET title = ?, description = ?, location = ?, starts_at = ?, ends_at = ?, audience = ?,
                                recurrence_type = ?, recurrence_until = ?
             WHERE id = ? AND association_id = ?'
        )->execute([
            $title, $description ?: null, $location ?: null,
            date('Y-m-d H:i:s', $startsTs),
            $endsTs ? date('Y-m-d H:i:s', $endsTs) : null,
            $audience, $recurType, $recurUntilSql, $eid, $assocId,
        ]);
        audit('event.edited', ['title' => $title, 'recurrence' => $recurType], $eid, 'event');
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

// Tenants only see what they're allowed to see (uses viewing_role so view-as
// homeowner correctly hides board-only events).
$allowedAudiences = ['all', 'members'];
if (role_can_manage(viewing_role())) {
    $allowedAudiences[] = 'board';
}
$placeholders = implode(',', array_fill(0, count($allowedAudiences), '?'));

// Fetch raw rows; the expand_events() helper handles past/upcoming filtering
// in PHP because recurring series have a single starts_at but many
// occurrences, so SQL date filters can't narrow them correctly.
// Prune obviously-stale data with a coarse SQL filter first to keep volume low.
$pruneSql = $showPast
    ? "(recurrence_type = 'none' AND starts_at < NOW())
       OR (recurrence_type <> 'none' AND COALESCE(recurrence_until, NOW() - INTERVAL 1 DAY) < CURDATE())"
    : "(recurrence_type = 'none' AND starts_at >= NOW())
       OR (recurrence_type <> 'none' AND (recurrence_until IS NULL OR recurrence_until >= CURDATE()))";

$sql = "SELECT * FROM events
         WHERE association_id = ? AND audience IN ($placeholders) AND ($pruneSql)";
$params = array_merge([$assocId], $allowedAudiences);
if (in_array($audienceFilter, ['all','members','board'], true)) {
    $sql .= ' AND audience = ?';
    $params[] = $audienceFilter;
}
$sql .= " LIMIT 200"; // raw rows; occurrences are computed below
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rawRows = $stmt->fetchAll();
$rows = expand_events($rawRows, $showPast, 90);
if (count($rows) > 100) $rows = array_slice($rows, 0, 100);

// Active building locations for the location picker autocomplete.
$locStmt = db()->prepare('SELECT name FROM locations WHERE association_id = ? AND is_active = 1 ORDER BY sort_order, name');
$locStmt->execute([$assocId]);
$activeLocations = array_column($locStmt->fetchAll(), 'name');

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

function event_form_card(?array $editing, string $assocSlug, array $activeLocations = []): void {
    $isEdit = $editing !== null;
    $vals   = $editing ?? [
        'title'=>'', 'description'=>'', 'location'=>'',
        'starts_at'=>'', 'ends_at'=>'', 'audience'=>'members',
        'recurrence_type'=>'none', 'recurrence_until'=>'', 'id'=>0,
    ];
    $vals['recurrence_type']  ??= 'none';
    $vals['recurrence_until'] ??= '';
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
                    <input class="input" id="ev-l" name="location" maxlength="255" value="<?= e((string)$vals['location']) ?>" placeholder="Clubhouse" list="ev-locations">
                    <?php if (!empty($activeLocations)): ?>
                    <datalist id="ev-locations">
                        <?php foreach ($activeLocations as $locName): ?>
                            <option value="<?= e((string)$locName) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <?php endif; ?>
                    <div class="field__hint"><a href="/dashboard/locations.php">Manage locations →</a></div>
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
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ev-r">Repeats</label>
                    <select class="select" id="ev-r" name="recurrence_type">
                        <?php foreach (['none'=>'No (one-time event)','daily'=>'Daily','weekly'=>'Weekly (same day)','biweekly'=>'Every 2 weeks','monthly'=>'Monthly (same day-of-month)'] as $v=>$lbl): ?>
                            <option value="<?= e($v) ?>" <?= $vals['recurrence_type']===$v?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field__hint">Recurring events show every occurrence on the listing and the public landing.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="ev-ru">Repeats until (optional)</label>
                    <input class="input" type="date" id="ev-ru" name="recurrence_until" value="<?= e((string)$vals['recurrence_until']) ?>">
                    <div class="field__hint">Leave blank for ongoing — the next 90 days are always shown.</div>
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

    <?php if ($showCreate || $editEvent) event_form_card($editEvent, (string)$association['subdomain'], $activeLocations); ?>

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
        <span class="muted" style="font-size: var(--fs-xs); align-self:center; margin-left: var(--sp-3);">Print:</span>
        <a class="badge" href="/dashboard/events-print.php?range=day"   target="_blank" rel="noopener" style="text-decoration:none;">Today</a>
        <a class="badge" href="/dashboard/events-print.php?range=week"  target="_blank" rel="noopener" style="text-decoration:none;">This week</a>
        <a class="badge" href="/dashboard/events-print.php?range=month" target="_blank" rel="noopener" style="text-decoration:none;">This month</a>
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
    <article class="card card--padded ev-row" style="display:flex; gap: var(--sp-4); align-items: flex-start;">
        <a href="/dashboard/event.php?id=<?= (int)$ev['id'] ?>" class="ev-date" style="flex: 0 0 72px; text-align:center; padding: 6px 10px; border: 2px solid var(--color-navy); border-radius: 8px; background: var(--color-surface); text-decoration: none; color: inherit;">
            <div style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-text-soft); font-weight: 700;"><?= e(date('M', $startTs)) ?></div>
            <div style="font-size: 22pt; line-height: 1; font-weight: 800; color: var(--color-navy); margin: 2px 0;"><?= e(date('j', $startTs)) ?></div>
            <div style="font-size: var(--fs-xs); color: var(--color-text-soft);"><?= e(date('D', $startTs)) ?></div>
        </a>
        <div style="flex: 1; min-width: 0;">
            <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-2); flex-wrap: wrap;">
                <span class="badge <?= $audClass ?>"><?= e((string)$ev['audience']) ?></span>
                <?php if (($ev['recurrence_type'] ?? 'none') !== 'none'): ?>
                    <span class="badge" style="background: var(--color-surface); color: var(--color-text-soft); font-size: var(--fs-xs);">
                        ↻ <?= e((string)$ev['recurrence_type']) ?>
                    </span>
                <?php endif; ?>
                <span class="muted" style="font-size: var(--fs-sm);">
                    <?= e(date('g:i A', $startTs)) ?>
                    <?php if ($endTs): ?>
                        – <?= e(date($sameDay ? 'g:i A' : 'M j, g:i A', $endTs)) ?>
                    <?php endif; ?>
                </span>
                <?php if (!empty($ev['location'])): ?>
                    <span class="muted" style="font-size: var(--fs-sm);">&middot; <?= e((string)$ev['location']) ?></span>
                <?php endif; ?>
            </div>
            <h3 style="font-size: var(--fs-lg); margin: 0;"><a href="/dashboard/event.php?id=<?= (int)$ev['id'] ?>" style="color: inherit; text-decoration: none;"><?= e((string)$ev['title']) ?></a></h3>
            <?php if (!empty($ev['description'])): ?>
                <p class="muted" style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm); white-space: pre-wrap;"><?= e(mb_strimwidth((string)$ev['description'], 0, 200, '…')) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($canManage): ?>
        <div class="row" style="gap: var(--sp-2); flex: 0 0 auto;">
            <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$ev['id'] ?>">Edit</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this event?');">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="delete">
                <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
                <button class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);" type="submit">Delete</button>
            </form>
        </div>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
