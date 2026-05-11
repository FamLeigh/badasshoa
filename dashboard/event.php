<?php
// Single-event detail view. For recurring events, shows the canonical
// timestamp plus the next ~10 upcoming occurrences. Includes a Print
// button that opens /dashboard/events-print.php?id=N.
require __DIR__ . '/_bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$user      = current_user();
$canManage = role_can_manage(viewing_role());

$stmt = db()->prepare(
    'SELECT e.*, TRIM(CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,""))) AS creator_name
       FROM events e LEFT JOIN users u ON u.id = e.created_by
      WHERE e.id = ? AND e.association_id = ?'
);
$stmt->execute([$id, $assocId]);
$ev = $stmt->fetch();
if (!$ev) { http_response_code(404); die('Event not found.'); }

// Tenants don't see board-only events.
if (!$canManage && $ev['audience'] === 'board') { http_response_code(403); die('Forbidden'); }

$startTs = strtotime((string)$ev['starts_at']);
$endTs   = !empty($ev['ends_at']) ? strtotime((string)$ev['ends_at']) : null;
$sameDay = $endTs && date('Y-m-d', $startTs) === date('Y-m-d', $endTs);
$audClass = match ($ev['audience']) {
    'all'     => 'badge--success',
    'board'   => 'badge--navy',
    default   => 'badge--info',
};

// For recurring events, compute the next batch of occurrences.
$occurrences = [];
if (($ev['recurrence_type'] ?? 'none') !== 'none') {
    $occurrences = expand_events([$ev], false, 90);
    if (count($occurrences) > 12) $occurrences = array_slice($occurrences, 0, 12);
}

$page_title = $ev['title'] . ' — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 900px;">

    <div class="row row--between" style="margin-bottom: var(--sp-4); flex-wrap: wrap; gap: var(--sp-3);">
        <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/events.php">← Back to events</a>
        <div class="row" style="gap: var(--sp-2);">
            <a class="btn btn--ghost" href="/dashboard/events-print.php?id=<?= (int)$ev['id'] ?>" target="_blank" rel="noopener">🖨 Print</a>
            <?php if ($canManage): ?>
                <a class="btn btn--ghost" href="/dashboard/events.php?action=edit&id=<?= (int)$ev['id'] ?>">Edit</a>
            <?php endif; ?>
        </div>
    </div>

    <article class="card card--padded">
        <div class="row" style="gap: var(--sp-4); align-items: center; margin-bottom: var(--sp-4); padding-bottom: var(--sp-3); border-bottom: 1px solid var(--color-border);">
            <div style="text-align:center; min-width: 80px; padding: 6px 12px; border: 2px solid var(--color-navy); border-radius: 8px; background: var(--color-surface);">
                <div style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-text-soft); font-weight: 700;"><?= e(date('M', $startTs)) ?></div>
                <div style="font-size: 32pt; line-height: 1; font-weight: 800; color: var(--color-navy);"><?= e(date('j', $startTs)) ?></div>
                <div style="font-size: var(--fs-xs); color: var(--color-text-soft);"><?= e(date('D', $startTs)) ?></div>
            </div>
            <div style="flex: 1; min-width: 0;">
                <div class="row" style="gap: var(--sp-2); flex-wrap: wrap; margin-bottom: var(--sp-1);">
                    <span class="badge <?= $audClass ?>"><?= e((string)$ev['audience']) ?></span>
                    <?php if (($ev['recurrence_type'] ?? 'none') !== 'none'): ?>
                        <span class="badge" style="background: var(--color-warning-bg); color: var(--color-warning); border: 1px solid rgba(182,130,42,0.25);">↻ <?= e((string)$ev['recurrence_type']) ?></span>
                    <?php endif; ?>
                </div>
                <h1 style="font-size: var(--fs-2xl); margin: 0 0 var(--sp-1);"><?= e((string)$ev['title']) ?></h1>
                <div class="muted" style="font-size: var(--fs-md);">
                    <?= e(date('l, F j, Y · g:i A', $startTs)) ?>
                    <?php if ($endTs): ?>
                        – <?= e(date($sameDay ? 'g:i A' : 'M j, Y g:i A', $endTs)) ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($ev['location'])): ?>
                    <div class="muted" style="font-size: var(--fs-sm); margin-top: var(--sp-1);">📍 <?= e((string)$ev['location']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($ev['description'])): ?>
            <div style="white-space: pre-wrap; line-height: 1.55; font-size: var(--fs-md);"><?= e((string)$ev['description']) ?></div>
        <?php endif; ?>

        <?php if (!empty($ev['recurrence_until'])): ?>
            <p class="muted" style="margin-top: var(--sp-4); font-size: var(--fs-sm);">Repeats until <?= e(date('M j, Y', strtotime((string)$ev['recurrence_until']))) ?>.</p>
        <?php endif; ?>
    </article>

    <?php if ($occurrences): ?>
        <div class="card card--padded" style="margin-top: var(--sp-5);">
            <h2 style="font-size: var(--fs-lg); margin: 0 0 var(--sp-3);">Upcoming occurrences</h2>
            <ul style="margin: 0; padding-left: 1.2em; font-size: var(--fs-sm);">
                <?php foreach ($occurrences as $occ): $oTs = strtotime((string)$occ['starts_at']); ?>
                    <li><?= e(date('D, M j, Y · g:i A', $oTs)) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
