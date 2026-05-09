<?php
require __DIR__ . '/_bootstrap.php';

// Quick stats (tenant-scoped).
$stats = [];
// Registered units = distinct unit numbers with at least one non-inactive member
$unitsStmt = db()->prepare(
    "SELECT COUNT(DISTINCT unit_number) FROM users
     WHERE association_id = ?
       AND status <> 'inactive'
       AND unit_number IS NOT NULL
       AND unit_number <> ''"
);
$unitsStmt->execute([$assocId]);
$stats['units'] = (int)$unitsStmt->fetchColumn();

// Board members = users with any board-level role
$boardCount = db()->prepare(
    "SELECT COUNT(*) FROM users
     WHERE association_id = ? AND status = 'active'
       AND role IN ('board_admin','board_member','property_manager')"
);
$boardCount->execute([$assocId]);
$stats['board_members'] = (int)$boardCount->fetchColumn();

// Total announcements (no time filter)
$annCount = db()->prepare('SELECT COUNT(*) FROM announcements WHERE association_id = ?');
$annCount->execute([$assocId]);
$stats['announcements'] = (int)$annCount->fetchColumn();

$docs = db()->prepare('SELECT COUNT(*) FROM documents WHERE association_id = ?');
$docs->execute([$assocId]);
$stats['documents'] = (int)$docs->fetchColumn();

$comms = db()->prepare('SELECT COUNT(*) FROM committees WHERE association_id = ?');
$comms->execute([$assocId]);
$stats['committees'] = (int)$comms->fetchColumn();

// Latest 5 announcements.
$annStmt = db()->prepare(
    'SELECT a.id, a.title, a.body, a.type, a.published_at,
            CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,"")) AS author
     FROM announcements a LEFT JOIN users u ON u.id = a.author_id
     WHERE a.association_id = ?
     ORDER BY a.published_at DESC LIMIT 5'
);
$annStmt->execute([$assocId]);
$announcements = $annStmt->fetchAll();

$user = current_user();
$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$page_title = 'Dashboard — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top: var(--sp-8); padding-bottom: var(--sp-12); max-width: 1280px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <span class="badge badge--orange"><?= e($association['name']) ?></span>
            <h1 style="font-size: var(--fs-3xl); margin: var(--sp-3) 0 var(--sp-1);"><?= e($greet) ?>, <?= e($user['first_name'] ?: 'there') ?>.</h1>
            <p class="muted">Here&rsquo;s what&rsquo;s happening at <?= e($association['name']) ?> today.</p>
        </div>
        <div class="row">
            <a class="btn btn--ghost" href="/dashboard/communications.php?action=new">Post announcement</a>
            <a class="btn btn--primary" href="/dashboard/documents.php?action=new">Upload document</a>
        </div>
    </div>

    <div class="grid grid--5" style="margin-bottom: var(--sp-8);">
        <a class="stat" href="/dashboard/directory.php">
            <div class="stat__label">Units</div>
            <div class="stat__value"><?= (int)$stats['units'] ?></div>
        </a>
        <a class="stat" href="/dashboard/directory.php">
            <div class="stat__label">Board members</div>
            <div class="stat__value"><?= (int)$stats['board_members'] ?></div>
        </a>
        <a class="stat" href="/dashboard/communications.php">
            <div class="stat__label">Posts</div>
            <div class="stat__value"><?= (int)$stats['announcements'] ?></div>
        </a>
        <a class="stat" href="/dashboard/documents.php">
            <div class="stat__label">Documents</div>
            <div class="stat__value"><?= (int)$stats['documents'] ?></div>
        </a>
        <a class="stat" href="/dashboard/committees.php">
            <div class="stat__label">Committees</div>
            <div class="stat__value"><?= (int)$stats['committees'] ?></div>
        </a>
    </div>

    <div class="grid grid--2" style="align-items:start;">
        <div class="card card--padded">
            <div class="card__head">
                <h2 class="card__title">Recent announcements</h2>
                <a href="/dashboard/communications.php" class="muted" style="font-size: var(--fs-sm);">View all →</a>
            </div>
            <?php if (!$announcements): ?>
                <p class="muted">No announcements yet. <a href="/dashboard/communications.php?action=new">Post the first one</a>.</p>
            <?php else: ?>
                <div class="stack-lg">
                <?php foreach ($announcements as $a): ?>
                    <div>
                        <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-1);">
                            <span class="badge <?= $a['type'] === 'emergency' ? 'badge--error' : ($a['type'] === 'event' ? 'badge--info' : 'badge--orange') ?>">
                                <?= e($a['type']) ?>
                            </span>
                            <span class="muted" style="font-size: var(--fs-xs);"><?= e(date('M j, Y', strtotime($a['published_at']))) ?> &middot; <?= e(trim($a['author']) ?: 'Unknown') ?></span>
                        </div>
                        <strong><?= e($a['title']) ?></strong>
                        <p class="muted" style="margin: var(--sp-1) 0 0; font-size: var(--fs-sm);">
                            <?= e(mb_strimwidth(strip_tags($a['body']), 0, 160, '…')) ?>
                        </p>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card card--padded">
            <div class="card__head">
                <h2 class="card__title">Quick actions</h2>
            </div>
            <div class="stack">
                <a class="btn btn--ghost btn--block" href="/dashboard/documents.php?action=new">📂 Upload a document</a>
                <a class="btn btn--ghost btn--block" href="/dashboard/communications.php?action=new">📣 Post announcement</a>
                <a class="btn btn--ghost btn--block" href="/dashboard/directory.php?action=invite">👥 Invite a board member</a>
                <a class="btn btn--ghost btn--block" href="/dashboard/media.php?action=new">🖼️ Upload a photo</a>
                <a class="btn btn--ghost btn--block" href="/dashboard/search.php?action=new">📜 Add a rule or bylaw</a>
            </div>
            <hr>
            <p class="muted" style="font-size: var(--fs-sm); margin: 0;">
                <strong>Plan:</strong> <?= e(ucfirst((string)$association['plan'])) ?>
                <?php if ($association['status'] === 'trial'): ?>
                    <span class="badge badge--warning" style="margin-left: var(--sp-2);">Trial</span>
                <?php endif; ?>
            </p>
        </div>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
