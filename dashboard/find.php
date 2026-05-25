<?php
// Global search — one query, six tables, results grouped by type.
// Honors tenant scoping (association_id) and viewer-role visibility:
//   * board_only documents hide from non-managers
//   * board-only events hide from non-managers
//   * own-only concerns hide from non-managers (managers see all)
//
// Linked from the header search box. The box also works on any page.
require __DIR__ . '/_bootstrap.php';

$user      = current_user();
$canManage = role_can_manage(viewing_role());
$q = trim((string)($_GET['q'] ?? ''));

$results = [
    'help'          => [],
    'rules'         => [],
    'documents'     => [],
    'announcements' => [],
    'events'        => [],
    'concerns'      => [],
    'arc'           => [],
    'units'         => [],
    'contacts'      => [],
    'work_orders'   => [],
    'violations'    => [],
    'minutes'       => [],
    'members'       => [],
    'faqs'          => [],
];

if ($q !== '' && strlen($q) >= 2) {
    $like = '%' . $q . '%';

    // Rules
    $rs = db()->prepare(
        'SELECT id, title, rule_number, source, category
           FROM rules
          WHERE association_id = ?
            AND (title LIKE ? OR body LIKE ? OR rule_number LIKE ?)
          ORDER BY CAST(rule_number AS UNSIGNED), rule_number
          LIMIT 15'
    );
    $rs->execute([$assocId, $like, $like, $like]);
    $results['rules'] = $rs->fetchAll();

    // Documents (filter access_level for non-managers)
    $dSql = 'SELECT id, title, category, description, file_path
               FROM documents
              WHERE association_id = ?
                AND (title LIKE ? OR description LIKE ?)';
    $dParams = [$assocId, $like, $like];
    if (!$canManage) {
        $dSql .= " AND access_level <> 'board_only'
                   AND (access_level <> 'unit_only'
                        OR unit_id IN (SELECT unit_id FROM unit_occupants WHERE user_id = ?))";
        $dParams[] = (int)$user['id'];
    }
    $dSql .= ' ORDER BY created_at DESC LIMIT 15';
    $ds = db()->prepare($dSql);
    $ds->execute($dParams);
    $results['documents'] = $ds->fetchAll();

    // Announcements
    $aSql = 'SELECT id, title, type, published_at, expires_at
               FROM announcements
              WHERE association_id = ?
                AND (title LIKE ? OR body LIKE ?)';
    $aParams = [$assocId, $like, $like];
    if (!$canManage) {
        $aSql .= ' AND published_at <= NOW() AND (expires_at IS NULL OR expires_at >= NOW())';
    }
    $aSql .= ' ORDER BY published_at DESC LIMIT 15';
    $as = db()->prepare($aSql);
    $as->execute($aParams);
    $results['announcements'] = $as->fetchAll();

    // Events
    $eSql = "SELECT id, title, starts_at, location, audience
               FROM events
              WHERE association_id = ?
                AND (title LIKE ? OR description LIKE ? OR location LIKE ?)";
    $eParams = [$assocId, $like, $like, $like];
    if (!$canManage) { $eSql .= " AND audience IN ('all','members')"; }
    $eSql .= ' ORDER BY starts_at DESC LIMIT 15';
    $es = db()->prepare($eSql);
    $es->execute($eParams);
    $results['events'] = $es->fetchAll();

    // Concerns — managers see all, members only their own.
    $cSql = 'SELECT id, subject, type, status, created_at
               FROM concerns
              WHERE association_id = ?
                AND (subject LIKE ? OR body LIKE ?)';
    $cParams = [$assocId, $like, $like];
    if (!$canManage) {
        $cSql .= ' AND submitter_user_id = ?';
        $cParams[] = (int)$user['id'];
    }
    $cSql .= ' ORDER BY created_at DESC LIMIT 15';
    $cs = db()->prepare($cSql);
    $cs->execute($cParams);
    $results['concerns'] = $cs->fetchAll();

    // ARC requests — same visibility rule.
    $arcSql = 'SELECT id, title, category, status, created_at
                 FROM arc_requests
                WHERE association_id = ?
                  AND (title LIKE ? OR description LIKE ?)';
    $arcParams = [$assocId, $like, $like];
    if (!$canManage) {
        $arcSql .= ' AND submitter_user_id = ?';
        $arcParams[] = (int)$user['id'];
    }
    $arcSql .= ' ORDER BY created_at DESC LIMIT 15';
    $arcs = db()->prepare($arcSql);
    $arcs->execute($arcParams);
    $results['arc'] = $arcs->fetchAll();

    // Units — management only
    if ($canManage) {
        $uStmt = db()->prepare(
            'SELECT id, unit_number, type, bedrooms, baths,
                    (SELECT COUNT(*) FROM unit_occupants WHERE unit_id = units.id) AS occupant_count
               FROM units
              WHERE association_id = ?
                AND (unit_number LIKE ? OR notes LIKE ?)
              ORDER BY CAST(unit_number AS UNSIGNED), unit_number LIMIT 15'
        );
        $uStmt->execute([$assocId, $like, $like]);
        $results['units'] = $uStmt->fetchAll();
    }

    // Contacts — management only (non-public contacts are board-internal)
    if ($canManage || can_do('read_contacts')) {
        $coStmt = db()->prepare(
            'SELECT id, kind, label, trade, phone, email
               FROM association_contacts
              WHERE association_id = ?
                AND (label LIKE ? OR trade LIKE ? OR phone LIKE ? OR email LIKE ? OR notes LIKE ?)
              ORDER BY sort_order, label LIMIT 15'
        );
        $coStmt->execute([$assocId, $like, $like, $like, $like, $like]);
        $results['contacts'] = $coStmt->fetchAll();
    }

    // Work orders — management only
    if ($canManage) {
        $ws = db()->prepare(
            'SELECT id, title, status, priority, created_at
               FROM work_orders
              WHERE association_id = ?
                AND (title LIKE ? OR body LIKE ?)
              ORDER BY created_at DESC LIMIT 15'
        );
        $ws->execute([$assocId, $like, $like]);
        $results['work_orders'] = $ws->fetchAll();
    }

    // Violations — management only
    if ($canManage) {
        $vs = db()->prepare(
            'SELECT id, violation_type, description, status, created_at
               FROM violations
              WHERE association_id = ?
                AND description LIKE ?
              ORDER BY created_at DESC LIMIT 15'
        );
        $vs->execute([$assocId, $like]);
        $results['violations'] = $vs->fetchAll();
    }

    // Meeting minutes — management or members who have access via can_do()
    if ($canManage || can_do('board_meeting_minutes')) {
        $mnStmt = db()->prepare(
            'SELECT id, title, meeting_date, meeting_type
               FROM meeting_minutes
              WHERE association_id = ?
                AND (title LIKE ? OR body_html LIKE ?)
              ORDER BY meeting_date DESC LIMIT 15'
        );
        $mnStmt->execute([$assocId, $like, $like]);
        $results['minutes'] = $mnStmt->fetchAll();
    }

    // Members — name / email / unit. Renters see only the board; managers
    // see everyone. Other residents see active members.
    if (viewing_role() === 'renter') {
        $mSql = "SELECT id, first_name, last_name, email, unit_number, role, board_office
                   FROM users
                  WHERE association_id = ? AND status <> 'inactive'
                    AND role IN ('board_admin','board_member','property_manager')
                    AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR unit_number LIKE ?)";
    } else {
        $mSql = "SELECT id, first_name, last_name, email, unit_number, role, board_office
                   FROM users
                  WHERE association_id = ? AND status <> 'inactive'
                    AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR unit_number LIKE ?)";
    }
    $mSql .= ' ORDER BY last_name, first_name LIMIT 15';
    $ms = db()->prepare($mSql);
    $ms->execute([$assocId, $like, $like, $like, $like]);
    $results['members'] = $ms->fetchAll();

    // FAQs — visible to anyone who can read documents (i.e., renters and up)
    $fStmt = db()->prepare(
        'SELECT id, question
           FROM faqs
          WHERE association_id = ?
            AND (question LIKE ? OR answer LIKE ?)
          ORDER BY sort_order, id LIMIT 15'
    );
    $fStmt->execute([$assocId, $like, $like]);
    $results['faqs'] = $fStmt->fetchAll();

    // Help topics — global table, filtered by min_role.
    // ROLE_RANK gates which topics are visible to the viewer's current role.
    $roleRank = [
        'renter' => 1, 'staff' => 2, 'owner' => 3, 'property_manager' => 4,
        'board_member' => 5, 'board_admin' => 6, 'super_admin' => 7,
    ];
    $viewRank = $roleRank[viewing_role()] ?? 1;
    $allowedMinRoles = [];
    foreach ($roleRank as $r => $rk) {
        if ($rk <= $viewRank) $allowedMinRoles[] = $r;
    }
    if ($allowedMinRoles) {
        $minPh = implode(',', array_fill(0, count($allowedMinRoles), '?'));
        $hStmt = db()->prepare(
            "SELECT id, slug, title, category
               FROM help_topics
              WHERE active = 1
                AND min_role IN ($minPh)
                AND (title LIKE ? OR body LIKE ? OR category LIKE ?)
              ORDER BY category, sort_order, title
              LIMIT 15"
        );
        $hStmt->execute(array_merge($allowedMinRoles, [$like, $like, $like]));
        $results['help'] = $hStmt->fetchAll();
    }
}

$totalHits = array_sum(array_map('count', $results));

$page_title = 'Search' . ($q !== '' ? ' — ' . $q : '');
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1100px;">

    <form method="get" class="search-bar" style="margin-bottom: var(--sp-5);">
        <span aria-hidden="true">🔎</span>
        <input type="search" name="q" autofocus placeholder="Search everything — help, rules, documents, members, events…" value="<?= e($q) ?>" required minlength="2">
        <button class="btn btn--primary" type="submit">Search</button>
    </form>

    <?php if ($q === ''): ?>
        <div class="card card--padded center" style="padding: var(--sp-8) var(--sp-6);">
            <p class="muted">Type at least two characters to search across help topics, rules, documents, announcements, events, concerns, ARC requests, and members.</p>
        </div>
    <?php elseif (strlen($q) < 2): ?>
        <p class="muted">Type at least two characters.</p>
    <?php elseif ($totalHits === 0): ?>
        <div class="card card--padded center" style="padding: var(--sp-8) var(--sp-6);">
            <p class="muted">No results for &ldquo;<?= e($q) ?>&rdquo;.</p>
        </div>
    <?php else: ?>

        <p class="muted" style="margin-bottom: var(--sp-4); font-size: var(--fs-sm);">
            <?= (int)$totalHits ?> result<?= $totalHits === 1 ? '' : 's' ?> for &ldquo;<?= e($q) ?>&rdquo;.
        </p>

        <?php
        $sections = [
            'help'          => ['label' => '❔ Help topics',      'href' => fn($r) => '/dashboard/help.php?topic=' . urlencode((string)$r['slug'])],
            'rules'         => ['label' => '📜 Rules',           'href' => fn($r) => '/dashboard/rule.php?id=' . (int)$r['id']],
            'documents'     => ['label' => '📄 Documents',       'href' => fn($r) => !empty($r['file_path']) ? '/dashboard/file.php?type=document&id=' . (int)$r['id'] : '/dashboard/document.php?id=' . (int)$r['id']],
            'announcements' => ['label' => '📣 Announcements',   'href' => fn($r) => '/dashboard/communications.php?id=' . (int)$r['id']],
            'events'        => ['label' => '📅 Events',          'href' => fn($r) => '/dashboard/event.php?id=' . (int)$r['id']],
            'concerns'      => ['label' => '💬 Feedback',        'href' => fn($r) => '/dashboard/concerns.php?id=' . (int)$r['id']],
            'arc'           => ['label' => '🏗 ARC requests',    'href' => fn($r) => '/dashboard/arc.php?id=' . (int)$r['id']],
            'work_orders'   => ['label' => '🔧 Work orders',     'href' => fn($r) => '/dashboard/work-orders.php?id=' . (int)$r['id']],
            'violations'    => ['label' => '⚠️ Violations',      'href' => fn($r) => '/dashboard/violations.php?id=' . (int)$r['id']],
            'minutes'       => ['label' => '📋 Meeting minutes', 'href' => fn($r) => '/dashboard/minutes.php?id=' . (int)$r['id']],
            'units'         => ['label' => '🏠 Units',            'href' => fn($r) => '/dashboard/unit.php?id=' . (int)$r['id']],
            'contacts'      => ['label' => '📞 Contacts',         'href' => fn($r) => '/dashboard/contacts.php#contact-' . (int)$r['id']],
            'members'       => ['label' => '👥 Members',         'href' => fn($r) => '/dashboard/directory.php?action=edit&id=' . (int)$r['id']],
            'faqs'          => ['label' => '❓ FAQs',             'href' => fn($r) => '/dashboard/faq.php#faq-' . (int)$r['id']],
        ];
        ?>

        <?php foreach ($sections as $key => $meta):
            $items = $results[$key];
            if (!$items) continue;
        ?>
            <div class="card card--padded" style="margin-bottom: var(--sp-4);">
                <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-3);"><?= e($meta['label']) ?> <span class="muted" style="font-size: var(--fs-sm); font-weight: 400;">(<?= count($items) ?>)</span></h2>
                <ul style="list-style: none; margin: 0; padding: 0;">
                <?php foreach ($items as $r):
                    $href = ($meta['href'])($r);
                ?>
                    <li style="padding: 8px 0; border-bottom: 1px solid var(--color-border);">
                        <?php if ($key === 'rules'): ?>
                            <a href="<?= e($href) ?>"><strong><?php if (!empty($r['rule_number'])): ?>#<?= e((string)$r['rule_number']) ?> ·<?php endif; ?> <?= e((string)$r['title']) ?></strong></a>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e((string)$r['source']) ?><?php if (!empty($r['category'])): ?> · <?= e((string)$r['category']) ?><?php endif; ?></div>
                        <?php elseif ($key === 'documents'): ?>
                            <a href="<?= e($href) ?>" target="_blank" rel="noopener"><strong><?= e((string)$r['title']) ?></strong></a>
                            <?php if (!empty($r['category'])): ?><span class="muted" style="font-size: var(--fs-xs);"> · <?= e((string)$r['category']) ?></span><?php endif; ?>
                            <?php if (!empty($r['description'])): ?>
                                <div class="muted" style="font-size: var(--fs-sm); margin-top: 2px;"><?= e(mb_strimwidth((string)$r['description'], 0, 140, '…')) ?></div>
                            <?php endif; ?>
                        <?php elseif ($key === 'announcements'): ?>
                            <a href="<?= e($href) ?>"><strong><?= e((string)$r['title']) ?></strong></a>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e((string)$r['type']) ?> · <?= e(udate('M j, Y', strtotime((string)$r['published_at']))) ?></div>
                        <?php elseif ($key === 'events'): ?>
                            <a href="<?= e($href) ?>"><strong><?= e((string)$r['title']) ?></strong></a>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e(udate('D, M j, Y · g:i A', strtotime((string)$r['starts_at']))) ?><?php if (!empty($r['location'])): ?> · <?= e((string)$r['location']) ?><?php endif; ?></div>
                        <?php elseif ($key === 'concerns'): ?>
                            <a href="<?= e($href) ?>"><strong><?= e((string)$r['subject']) ?></strong></a>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e((string)$r['type']) ?> · <?= e(str_replace('_',' ', (string)$r['status'])) ?> · <?= e(udate('M j, Y', strtotime((string)$r['created_at']))) ?></div>
                        <?php elseif ($key === 'arc'): ?>
                            <a href="<?= e($href) ?>"><strong><?= e((string)$r['title']) ?></strong></a>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e((string)$r['category']) ?> · <?= e(str_replace('_',' ', (string)$r['status'])) ?> · <?= e(udate('M j, Y', strtotime((string)$r['created_at']))) ?></div>
                        <?php elseif ($key === 'work_orders'): ?>
                            <a href="<?= e($href) ?>"><strong><?= e((string)$r['title']) ?></strong></a>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e(str_replace('_',' ', (string)$r['status'])) ?> · <?= e((string)$r['priority']) ?> priority · <?= e(udate('M j, Y', strtotime((string)$r['created_at']))) ?></div>
                        <?php elseif ($key === 'violations'): ?>
                            <a href="<?= e($href) ?>"><strong><?= e(str_replace('_',' ', ucwords((string)$r['violation_type']))) ?></strong></a>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e(str_replace('_',' ', (string)$r['status'])) ?> · <?= e(udate('M j, Y', strtotime((string)$r['created_at']))) ?></div>
                            <div class="muted" style="font-size: var(--fs-sm); margin-top: 2px;"><?= e(mb_strimwidth((string)$r['description'], 0, 140, '…')) ?></div>
                        <?php elseif ($key === 'minutes'): ?>
                            <a href="<?= e($href) ?>"><strong><?= e((string)$r['title']) ?></strong></a>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e(str_replace('_',' ', (string)$r['meeting_type'])) ?> · <?= e(udate('M j, Y', strtotime((string)$r['meeting_date']))) ?></div>
                        <?php elseif ($key === 'units'): ?>
                            <a href="<?= e($href) ?>"><strong>Unit <?= e((string)$r['unit_number']) ?></strong></a>
                            <div class="muted" style="font-size: var(--fs-xs);">
                                <?= e(ucfirst(str_replace('_',' ',(string)$r['type']))) ?>
                                <?php if (!empty($r['bedrooms'])): ?> · <?= (int)$r['bedrooms'] ?> bd<?php endif; ?>
                                <?php if (!empty($r['baths'])): ?>/<?= rtrim(rtrim(number_format((float)$r['baths'],1),'0'),'.') ?> ba<?php endif; ?>
                                · <?= (int)$r['occupant_count'] ?> occupant<?= $r['occupant_count'] != 1 ? 's' : '' ?>
                            </div>
                        <?php elseif ($key === 'contacts'): ?>
                            <a href="<?= e($href) ?>"><strong><?= e((string)$r['label']) ?></strong></a>
                            <div class="muted" style="font-size: var(--fs-xs);">
                                <?= e(str_replace('_',' ', ucfirst((string)$r['kind']))) ?>
                                <?php if (!empty($r['trade'])): ?> · <?= e((string)$r['trade']) ?><?php endif; ?>
                                <?php if (!empty($r['phone'])): ?> · <?= e((string)$r['phone']) ?><?php endif; ?>
                                <?php if (!empty($r['email'])): ?> · <?= e((string)$r['email']) ?><?php endif; ?>
                            </div>
                        <?php elseif ($key === 'members'): ?>
                            <a href="<?= e($href) ?>"><strong><?= e(trim($r['first_name'] . ' ' . $r['last_name']) ?: $r['email']) ?></strong></a>
                            <div class="muted" style="font-size: var(--fs-xs);">
                                <?= e(str_replace('_',' ',(string)$r['role'])) ?>
                                <?php if (!empty($r['board_office'])): ?> · <?= e(board_office_label((string)$r['board_office'])) ?><?php endif; ?>
                                <?php if (!empty($r['unit_number'])): ?> · Unit <?= e((string)$r['unit_number']) ?><?php endif; ?>
                                <?php if (!is_placeholder_email((string)$r['email'])): ?> · <?= e((string)$r['email']) ?><?php endif; ?>
                            </div>
                        <?php elseif ($key === 'faqs'): ?>
                            <a href="<?= e($href) ?>"><strong><?= e((string)$r['question']) ?></strong></a>
                        <?php elseif ($key === 'help'): ?>
                            <a href="<?= e($href) ?>"><strong><?= e((string)$r['title']) ?></strong></a>
                            <?php if (!empty($r['category'])): ?><span class="muted" style="font-size: var(--fs-xs);"> · <?= e((string)$r['category']) ?></span><?php endif; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
