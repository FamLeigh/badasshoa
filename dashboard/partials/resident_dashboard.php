<?php
// Resident/owner dashboard partial.
// Included from dashboard/index.php — shares its variable scope.
// Available: $residentOpenConcerns, $residentOpenWOs, $residentAnnouncements,
//            $upcomingEvents, $assocId, $user, $association, $stats
$hasUrgent = $residentOpenConcerns > 0 || $residentOpenWOs > 0;
?>

<style>
    /* Urgent actions banner */
    .res-urgent {
        display: flex; flex-wrap: wrap; gap: var(--sp-3); align-items: stretch;
        padding: var(--sp-4); margin-bottom: var(--sp-6);
        background: #fff8f0; border: 1px solid #f9ddc0;
        border-left: 4px solid var(--color-orange); border-radius: var(--r-md);
    }
    .res-urgent__icon { font-size: 1.6rem; line-height: 1; padding-top: 2px; }
    .res-urgent__body { flex: 1 1 200px; }
    .res-urgent__title { font-weight: 700; color: var(--color-orange); margin: 0 0 var(--sp-2); font-size: var(--fs-sm); text-transform: uppercase; letter-spacing: 0.05em; }
    .res-urgent__items { display: flex; flex-wrap: wrap; gap: var(--sp-2); }

    /* Shortcut card grid */
    .res-shortcuts {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: var(--sp-4);
        margin-bottom: var(--sp-7);
    }
    @media (max-width: 900px) { .res-shortcuts { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 480px) { .res-shortcuts { grid-template-columns: repeat(2, 1fr); } }

    .res-shortcut {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: var(--sp-2); padding: var(--sp-5) var(--sp-3);
        text-align: center; text-decoration: none; color: inherit;
        border: 1px solid var(--color-border); border-radius: var(--r-lg);
        background: var(--color-surface-2);
        transition: transform 120ms ease, box-shadow 120ms ease, border-color 120ms ease;
        cursor: pointer;
        min-height: 130px;
    }
    .res-shortcut:hover:not(.res-shortcut--disabled) {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(15,31,61,0.10);
        border-color: var(--color-navy);
    }
    .res-shortcut--disabled { opacity: 0.55; cursor: default; }
    .res-shortcut__icon { font-size: 2rem; line-height: 1; }
    .res-shortcut__label { font-weight: 700; font-size: var(--fs-sm); color: var(--color-navy); }
    .res-shortcut__sub { font-size: var(--fs-xs); color: var(--color-text-soft); }

    /* Two-column content split */
    .res-split {
        display: grid;
        grid-template-columns: 3fr 2fr;
        gap: var(--sp-5);
        align-items: start;
    }
    @media (max-width: 860px) { .res-split { grid-template-columns: 1fr; } }
</style>

<?php if ($hasUrgent): ?>
<div class="res-urgent">
    <div class="res-urgent__icon">⚠️</div>
    <div class="res-urgent__body">
        <div class="res-urgent__title">Needs your attention</div>
        <div class="res-urgent__items">
            <?php if ($residentOpenConcerns > 0): ?>
            <a href="/dashboard/concerns.php" class="btn btn--ghost" style="font-size: var(--fs-sm); padding: var(--sp-1) var(--sp-3);">
                💬 <?= $residentOpenConcerns ?> open request<?= $residentOpenConcerns !== 1 ? 's' : '' ?>
            </a>
            <?php endif; ?>
            <?php if ($residentOpenWOs > 0): ?>
            <div class="badge badge--warning" style="font-size: var(--fs-sm); padding: var(--sp-1) var(--sp-3);">
                🔧 <?= $residentOpenWOs ?> maintenance item<?= $residentOpenWOs !== 1 ? 's' : '' ?> in progress for your unit
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="res-shortcuts">
    <a class="res-shortcut" href="/dashboard/concerns.php?action=submit">
        <div class="res-shortcut__icon">📝</div>
        <div class="res-shortcut__label">Submit Request</div>
        <div class="res-shortcut__sub">Questions, feedback, or issues</div>
    </a>

    <div class="res-shortcut res-shortcut--disabled" title="Coming soon">
        <div class="res-shortcut__icon">💳</div>
        <div class="res-shortcut__label">Pay Dues</div>
        <div class="res-shortcut__sub"><span class="badge" style="background:var(--color-surface);color:var(--color-text-soft);font-size:10px;">Coming soon</span></div>
    </div>

    <a class="res-shortcut" href="/dashboard/documents.php">
        <div class="res-shortcut__icon">📁</div>
        <div class="res-shortcut__label">My Documents</div>
        <div class="res-shortcut__sub">Rules, notices &amp; files</div>
    </a>

    <div class="res-shortcut res-shortcut--disabled" title="Coming soon">
        <div class="res-shortcut__icon">🏊</div>
        <div class="res-shortcut__label">Book Amenity</div>
        <div class="res-shortcut__sub"><span class="badge" style="background:var(--color-surface);color:var(--color-text-soft);font-size:10px;">Coming soon</span></div>
    </div>
</div>

<div class="res-split">

    <div class="card card--padded">
        <div class="card__head">
            <h2 class="card__title">Announcements</h2>
            <a href="/dashboard/communications.php" class="muted" style="font-size: var(--fs-sm);">View all →</a>
        </div>
        <?php if (!$residentAnnouncements): ?>
            <p class="muted">No announcements yet.</p>
        <?php else:
            $dashAnnColors = ann_type_colors($assocId);
        ?>
            <div class="dash-list">
            <?php foreach ($residentAnnouncements as $a):
                $aTs = strtotime((string)$a['published_at']);
                $typeBadgeStyle = ann_badge_style((string)$a['type'], $dashAnnColors);
            ?>
                <a class="dash-row" href="/dashboard/communications.php?id=<?= (int)$a['id'] ?>">
                    <div class="dash-date">
                        <div class="m"><?= e(udate('M', $aTs)) ?></div>
                        <div class="d"><?= e(udate('j', $aTs)) ?></div>
                    </div>
                    <div class="dash-body">
                        <div class="row" style="gap: var(--sp-2); margin-bottom: 2px; flex-wrap: wrap;">
                            <span class="badge" style="font-size: var(--fs-xs); <?= $typeBadgeStyle ?>"><?= e(ann_type_label((string)$a['type'])) ?></span>
                            <span class="muted" style="font-size: var(--fs-xs);"><?= e(udate('g:i A', $aTs)) ?></span>
                        </div>
                        <strong><?= e($a['title']) ?></strong>
                        <p class="muted" style="margin: 2px 0 0; font-size: var(--fs-sm);"><?= e(mb_strimwidth(strip_tags($a['body']), 0, 120, '…')) ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card card--padded">
        <div class="card__head">
            <h2 class="card__title">Upcoming events</h2>
            <a href="/dashboard/events.php" class="muted" style="font-size: var(--fs-sm);">View all →</a>
        </div>
        <?php if (!$upcomingEvents): ?>
            <p class="muted">No upcoming events.</p>
        <?php else: ?>
            <div class="dash-list">
            <?php foreach ($upcomingEvents as $ev):
                $startTs = strtotime((string)$ev['starts_at']);
                $audClass = match ($ev['audience']) {
                    'all'    => 'badge--success',
                    'board'  => 'badge--navy',
                    default  => 'badge--info',
                };
            ?>
                <a class="dash-row" href="/dashboard/event.php?id=<?= (int)$ev['id'] ?>">
                    <div class="dash-date">
                        <div class="m"><?= e(udate('M', $startTs)) ?></div>
                        <div class="d"><?= e(udate('j', $startTs)) ?></div>
                        <div class="dow"><?= e(udate('D', $startTs)) ?></div>
                    </div>
                    <div class="dash-body">
                        <div class="row" style="gap: var(--sp-2); margin-bottom: 2px; flex-wrap: wrap;">
                            <?php if (($ev['recurrence_type'] ?? 'none') !== 'none'): ?>
                                <span class="badge" style="background:var(--color-surface);color:var(--color-text-soft);font-size:var(--fs-xs);">↻ <?= e((string)$ev['recurrence_type']) ?></span>
                            <?php endif; ?>
                            <span class="muted" style="font-size: var(--fs-xs);"><?= e(udate('g:i A', $startTs)) ?></span>
                        </div>
                        <strong><?= e((string)$ev['title']) ?></strong>
                        <?php if (!empty($ev['location'])): ?>
                            <p class="muted" style="margin: 2px 0 0; font-size: var(--fs-sm);">📍 <?= e((string)$ev['location']) ?></p>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>
