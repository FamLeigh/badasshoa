<?php
// Resident/owner dashboard partial.
// Included from dashboard/index.php — shares its variable scope.
// Available: $residentOpenConcerns, $residentOpenWOs, $residentAnnouncements,
//            $residentEmergencyAnn, $residentRecentConcerns, $residentUnit,
//            $residentBoardContacts, $upcomingEvents, $mkListings,
//            $assocId, $user, $association, $stats
$hasMaintenance = $residentOpenWOs > 0;
$newCutoff      = strtotime('-7 days');
$concernLabels  = [
    'new'         => 'New',
    'in_progress' => 'In progress',
    'waiting'     => 'Waiting',
    'resolved'    => 'Resolved',
    'closed'      => 'Closed',
];
$concernBadge = function (string $s): string {
    return match ($s) {
        'resolved', 'closed' => 'badge--success',
        'in_progress'        => 'badge--info',
        'waiting'            => 'badge--warning',
        default              => 'badge--navy',
    };
};
?>

<style>
    /* Top help search */
    .res-help {
        display: flex; gap: var(--sp-2); align-items: center;
        max-width: 560px; margin: 0 0 var(--sp-5);
    }
    .res-help input {
        flex: 1; padding: var(--sp-2) var(--sp-3);
        border: 1px solid var(--color-border); border-radius: var(--r-md);
        font-size: var(--fs-sm); background: var(--color-surface);
    }
    .res-help input:focus { outline: 2px solid var(--color-navy); outline-offset: 0; }
    .res-help button {
        padding: var(--sp-2) var(--sp-4);
        background: var(--color-navy); color: #fff;
        border: 0; border-radius: var(--r-md); cursor: pointer;
        font-size: var(--fs-sm); font-weight: 600;
    }

    /* Emergency lane */
    .res-emergency {
        display: flex; gap: var(--sp-3); align-items: flex-start;
        padding: var(--sp-4) var(--sp-5); margin-bottom: var(--sp-5);
        background: #fff0ec; border: 1px solid #f4a896;
        border-left: 6px solid #c53824; border-radius: var(--r-md);
    }
    .res-emergency__icon { font-size: 1.8rem; line-height: 1; }
    .res-emergency__label {
        text-transform: uppercase; letter-spacing: 0.06em;
        font-size: var(--fs-xs); font-weight: 800; color: #c53824;
        margin: 0 0 var(--sp-1);
    }
    .res-emergency__title { margin: 0 0 var(--sp-1); font-size: var(--fs-lg); font-weight: 700; color: var(--color-navy); }
    .res-emergency__body { color: var(--color-text); font-size: var(--fs-sm); margin: 0 0 var(--sp-2); }

    /* Maintenance update banner */
    .res-urgent {
        display: flex; flex-wrap: wrap; gap: var(--sp-3); align-items: stretch;
        padding: var(--sp-4); margin-bottom: var(--sp-5);
        background: #fff8f0; border: 1px solid #f9ddc0;
        border-left: 4px solid var(--color-orange); border-radius: var(--r-md);
    }
    .res-urgent__icon { font-size: 1.6rem; line-height: 1; padding-top: 2px; }
    .res-urgent__body { flex: 1 1 200px; }
    .res-urgent__title { font-weight: 700; color: var(--color-orange); margin: 0 0 var(--sp-2); font-size: var(--fs-sm); text-transform: uppercase; letter-spacing: 0.05em; }
    .res-urgent__items { display: flex; flex-wrap: wrap; gap: var(--sp-2); }

    /* My unit ribbon */
    .res-myunit {
        display: flex; flex-wrap: wrap; align-items: center; gap: var(--sp-4);
        padding: var(--sp-3) var(--sp-5); margin-bottom: var(--sp-5);
        background: var(--color-surface-2); border: 1px solid var(--color-border);
        border-radius: var(--r-md);
    }
    .res-myunit__num {
        font-family: var(--ff-display, inherit);
        font-size: var(--fs-xl); font-weight: 700; color: var(--color-navy);
    }
    .res-myunit__num small { color: var(--color-text-soft); font-size: var(--fs-xs); font-weight: 500; text-transform: uppercase; letter-spacing: 0.06em; margin-right: var(--sp-2); }
    .res-myunit__facts { display: flex; gap: var(--sp-3); flex-wrap: wrap; color: var(--color-text-soft); font-size: var(--fs-sm); }
    .res-myunit__facts span:not(:last-child)::after { content: " ·"; margin-left: var(--sp-1); }

    /* Shortcut tile grid */
    .res-shortcuts {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: var(--sp-4);
        margin-bottom: var(--sp-6);
    }
    @media (max-width: 900px) { .res-shortcuts { grid-template-columns: repeat(2, 1fr); } }

    .res-shortcut {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: var(--sp-2); padding: var(--sp-5) var(--sp-3);
        text-align: center; text-decoration: none; color: inherit;
        border: 1px solid var(--color-border); border-radius: var(--r-lg);
        background: var(--color-surface-2);
        transition: transform 120ms ease, box-shadow 120ms ease, border-color 120ms ease;
        min-height: 130px;
    }
    .res-shortcut:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(15,31,61,0.10);
        border-color: var(--color-navy);
    }
    .res-shortcut__icon { font-size: 2rem; line-height: 1; }
    .res-shortcut__label { font-weight: 700; font-size: var(--fs-sm); color: var(--color-navy); }
    .res-shortcut__sub { font-size: var(--fs-xs); color: var(--color-text-soft); }

    /* Two-column content split */
    .res-split {
        display: grid;
        grid-template-columns: 3fr 2fr;
        gap: var(--sp-5);
        align-items: start;
        margin-bottom: var(--sp-6);
    }
    @media (max-width: 860px) { .res-split { grid-template-columns: 1fr; } }

    /* Announcement row enhancements: colored left border + "New" pill */
    .res-ann-row { border-left: 3px solid transparent; padding-left: var(--sp-3); }
    .res-new-pill {
        display: inline-block; padding: 1px 6px; border-radius: 999px;
        background: var(--color-orange); color: #fff;
        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
    }

    /* Event row with thumb */
    .res-ev-thumb {
        width: 56px; height: 56px; border-radius: var(--r-sm);
        background: var(--color-surface) center/cover no-repeat;
        flex: 0 0 56px;
    }

    /* Marketplace grid */
    .res-mk-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: var(--sp-4);
        margin-bottom: var(--sp-6);
    }
    @media (max-width: 900px) { .res-mk-grid { grid-template-columns: repeat(2, 1fr); } }
    .res-mk-card {
        display: block; text-decoration: none; color: inherit;
        border: 1px solid var(--color-border); border-radius: var(--r-md);
        overflow: hidden; background: var(--color-surface-2);
        transition: transform 120ms ease, box-shadow 120ms ease;
    }
    .res-mk-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(15,31,61,0.10); }
    .res-mk-card__img {
        aspect-ratio: 4/3; background: var(--color-surface) center/cover no-repeat;
    }
    .res-mk-card__noimg {
        aspect-ratio: 4/3; background: var(--color-surface);
        display: flex; align-items: center; justify-content: center;
        color: var(--color-text-soft); font-size: 2rem;
    }
    .res-mk-card__body { padding: var(--sp-3); }
    .res-mk-card__title { font-weight: 700; color: var(--color-navy); font-size: var(--fs-sm); margin: 0 0 4px; }

    /* Recent activity */
    .res-activity { margin-bottom: var(--sp-6); }
    .res-activity__item { display: flex; align-items: center; justify-content: space-between; gap: var(--sp-3); padding: var(--sp-2) 0; border-bottom: 1px solid var(--color-border); }
    .res-activity__item:last-child { border-bottom: 0; }

    /* Reach your board footer */
    .res-board {
        margin-top: var(--sp-7); padding: var(--sp-5);
        background: var(--color-navy); color: #fff;
        border-radius: var(--r-md);
    }
    .res-board__title { font-size: var(--fs-sm); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; opacity: 0.8; margin: 0 0 var(--sp-3); }
    .res-board__row { display: flex; flex-wrap: wrap; gap: var(--sp-4); align-items: center; }
    .res-board__person { display: flex; flex-direction: column; gap: 2px; min-width: 180px; }
    .res-board__name { font-weight: 700; }
    .res-board__meta { font-size: var(--fs-xs); opacity: 0.85; }
    .res-board__meta a { color: #fff; text-decoration: underline; }
    .res-board__all {
        margin-left: auto; color: #fff; font-size: var(--fs-sm); font-weight: 600;
        background: rgba(255,255,255,0.12); padding: var(--sp-2) var(--sp-4);
        border-radius: var(--r-md); text-decoration: none;
    }
    .res-board__all:hover { background: rgba(255,255,255,0.22); }
</style>

<!-- Help search -->
<form class="res-help" action="/dashboard/help.php" method="get" role="search" aria-label="Search help">
    <input type="search" name="q" placeholder="Search help — &ldquo;guest pass&rdquo;, &ldquo;pool hours&rdquo;, &ldquo;dues&rdquo;…" autocomplete="off">
    <button type="submit">Help</button>
</form>

<?php if ($residentEmergencyAnn):
    $emTs = strtotime((string)$residentEmergencyAnn['published_at']);
?>
<div class="res-emergency" role="alert">
    <div class="res-emergency__icon">🚨</div>
    <div style="flex:1;">
        <div class="res-emergency__label">Emergency notice · <?= e(udate('M j, g:i A', $emTs)) ?></div>
        <h2 class="res-emergency__title"><?= e($residentEmergencyAnn['title']) ?></h2>
        <p class="res-emergency__body"><?= e(mb_strimwidth(strip_tags($residentEmergencyAnn['body']), 0, 280, '…')) ?></p>
        <a class="btn btn--primary" style="font-size: var(--fs-sm); padding: var(--sp-2) var(--sp-3);"
           href="/dashboard/communications.php?id=<?= (int)$residentEmergencyAnn['id'] ?>">Read full notice</a>
    </div>
</div>
<?php endif; ?>

<?php if ($hasMaintenance): ?>
<div class="res-urgent">
    <div class="res-urgent__icon">🔧</div>
    <div class="res-urgent__body">
        <div class="res-urgent__title">Maintenance update</div>
        <div class="res-urgent__items">
            <div class="badge badge--warning" style="font-size: var(--fs-sm); padding: var(--sp-1) var(--sp-3);">
                <?= $residentOpenWOs ?> maintenance item<?= $residentOpenWOs !== 1 ? 's' : '' ?> in progress for your unit
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($user['unit_number'])): ?>
<div class="res-myunit">
    <div class="res-myunit__num">
        <small>Your unit</small><?= e((string)$user['unit_number']) ?>
    </div>
    <div class="res-myunit__facts">
        <span><?= !empty($user['is_owner']) ? 'Owner' : 'Renter' ?></span>
        <?php if ($residentUnit && !empty($residentUnit['type'])): ?>
            <span><?= e(ucfirst(str_replace('_',' ',(string)$residentUnit['type']))) ?></span>
        <?php endif; ?>
        <?php if ($residentUnit && !empty($residentUnit['bedrooms'])): ?>
            <span><?= (int)$residentUnit['bedrooms'] ?> bd</span>
        <?php endif; ?>
        <?php if ($residentUnit && !empty($residentUnit['baths'])): ?>
            <span><?= e((string)$residentUnit['baths']) ?> ba</span>
        <?php endif; ?>
        <?php if ($residentUnit && !empty($residentUnit['square_footage'])): ?>
            <span><?= number_format((int)$residentUnit['square_footage']) ?> sq ft</span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="res-shortcuts">
    <a class="res-shortcut" href="/dashboard/concerns.php?action=submit">
        <div class="res-shortcut__icon">💬</div>
        <div class="res-shortcut__label">Feedback to the Board</div>
        <div class="res-shortcut__sub">Questions, concerns, ideas</div>
    </a>

    <a class="res-shortcut" href="/dashboard/search.php">
        <div class="res-shortcut__icon">📜</div>
        <div class="res-shortcut__label">Rules &amp; Bylaws</div>
        <div class="res-shortcut__sub">Search community rules</div>
    </a>

    <a class="res-shortcut" href="/dashboard/documents.php">
        <div class="res-shortcut__icon">📁</div>
        <div class="res-shortcut__label">My Documents</div>
        <div class="res-shortcut__sub">Notices &amp; files</div>
    </a>

    <a class="res-shortcut" href="/dashboard/marketplace.php">
        <div class="res-shortcut__icon">🛒</div>
        <div class="res-shortcut__label">Marketplace</div>
        <div class="res-shortcut__sub">Buy, sell, give away</div>
    </a>
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
                $aTs            = strtotime((string)$a['published_at']);
                $typeBadgeStyle = ann_badge_style((string)$a['type'], $dashAnnColors);
                // Pull the type color out of the badge style to accent the row's left border.
                $borderColor = '#d3d8e0';
                if (preg_match('/color:\s*(#[0-9a-fA-F]{6})/', $typeBadgeStyle, $m)) {
                    $borderColor = $m[1];
                }
                $isNew = $aTs >= $newCutoff;
            ?>
                <a class="dash-row res-ann-row"
                   href="/dashboard/communications.php?id=<?= (int)$a['id'] ?>"
                   style="border-left-color: <?= e($borderColor) ?>;">
                    <div class="dash-date">
                        <div class="m"><?= e(udate('M', $aTs)) ?></div>
                        <div class="d"><?= e(udate('j', $aTs)) ?></div>
                    </div>
                    <div class="dash-body">
                        <div class="row" style="gap: var(--sp-2); margin-bottom: 2px; flex-wrap: wrap; align-items:center;">
                            <span class="badge" style="font-size: var(--fs-xs); <?= $typeBadgeStyle ?>"><?= e(ann_type_label((string)$a['type'])) ?></span>
                            <?php if ($isNew): ?><span class="res-new-pill">New</span><?php endif; ?>
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
                $hasImg  = !empty($ev['image_path']);
            ?>
                <a class="dash-row" href="/dashboard/event.php?id=<?= (int)$ev['id'] ?>">
                    <?php if ($hasImg): ?>
                    <div class="res-ev-thumb" style="background-image: url('/event-image.php?id=<?= (int)$ev['id'] ?>');" aria-hidden="true"></div>
                    <?php else: ?>
                    <div class="dash-date">
                        <div class="m"><?= e(udate('M', $startTs)) ?></div>
                        <div class="d"><?= e(udate('j', $startTs)) ?></div>
                        <div class="dow"><?= e(udate('D', $startTs)) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="dash-body">
                        <div class="row" style="gap: var(--sp-2); margin-bottom: 2px; flex-wrap: wrap;">
                            <?php if (($ev['recurrence_type'] ?? 'none') !== 'none'): ?>
                                <span class="badge" style="background:var(--color-surface);color:var(--color-text-soft);font-size:var(--fs-xs);">↻ <?= e((string)$ev['recurrence_type']) ?></span>
                            <?php endif; ?>
                            <span class="muted" style="font-size: var(--fs-xs);"><?= e(udate('M j · g:i A', $startTs)) ?></span>
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

<?php if ($mkListings): ?>
<div class="card card--padded" style="margin-bottom: var(--sp-6);">
    <div class="card__head">
        <h2 class="card__title">In the marketplace</h2>
        <a href="/dashboard/marketplace.php" class="muted" style="font-size: var(--fs-sm);">View all →</a>
    </div>
    <div class="res-mk-grid">
        <?php foreach (array_slice($mkListings, 0, 4) as $mk):
            $price = $mk['price_cents'] === null ? 'Free' : '$' . number_format($mk['price_cents'] / 100, 0);
        ?>
        <a class="res-mk-card" href="/dashboard/marketplace.php?id=<?= (int)$mk['id'] ?>">
            <?php if (!empty($mk['photo_path'])): ?>
            <div class="res-mk-card__img" style="background-image: url('/marketplace-image.php?id=<?= (int)$mk['id'] ?>');" aria-hidden="true"></div>
            <?php else: ?>
            <div class="res-mk-card__noimg">🛒</div>
            <?php endif; ?>
            <div class="res-mk-card__body">
                <div class="row" style="gap: var(--sp-2); margin-bottom: 4px;">
                    <span class="badge badge--success" style="font-size: var(--fs-xs);"><?= e($price) ?></span>
                </div>
                <p class="res-mk-card__title"><?= e($mk['title']) ?></p>
                <p class="muted" style="margin:0; font-size: var(--fs-xs);"><?= e(ucfirst(str_replace('_',' ',(string)$mk['category']))) ?></p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($residentRecentConcerns): ?>
<div class="card card--padded res-activity">
    <div class="card__head">
        <h2 class="card__title">Your recent activity</h2>
        <a href="/dashboard/concerns.php" class="muted" style="font-size: var(--fs-sm);">View all →</a>
    </div>
    <div>
    <?php foreach ($residentRecentConcerns as $c):
        $cTs    = strtotime((string)$c['created_at']);
        $status = (string)($c['status'] ?? 'new');
        $label  = $concernLabels[$status] ?? ucfirst($status);
    ?>
        <div class="res-activity__item">
            <div>
                <a href="/dashboard/concerns.php?id=<?= (int)$c['id'] ?>" style="font-weight: 600; color: var(--color-navy); text-decoration: none;">
                    <?= e((string)$c['subject']) ?>
                </a>
                <div class="muted" style="font-size: var(--fs-xs); margin-top: 2px;"><?= e(udate('M j, Y', $cTs)) ?></div>
            </div>
            <span class="badge <?= e($concernBadge($status)) ?>" style="font-size: var(--fs-xs);"><?= e($label) ?></span>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($residentBoardContacts): ?>
<div class="res-board">
    <div class="res-board__title">Reach your board</div>
    <div class="res-board__row">
        <?php foreach ($residentBoardContacts as $bc):
            $bcName = trim(((string)$bc['first_name']) . ' ' . ((string)$bc['last_name']));
            if ($bcName === '') $bcName = (string)$bc['email'];
            $bcRole = $bc['role'] === 'property_manager' ? 'Property manager' : 'Board admin';
        ?>
        <div class="res-board__person">
            <div class="res-board__name"><?= e($bcName) ?></div>
            <div class="res-board__meta">
                <?= e($bcRole) ?><?php if (!empty($bc['email'])): ?> · <a href="mailto:<?= e((string)$bc['email']) ?>"><?= e((string)$bc['email']) ?></a><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <a class="res-board__all" href="/dashboard/contacts.php">See all contacts →</a>
    </div>
</div>
<?php endif; ?>
