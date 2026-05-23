<?php
// Board meeting detail — agenda builder, resolutions, notice & proof, minutes linkage.
require __DIR__ . '/_bootstrap.php';
require_login();

$role       = viewing_role();
$canView    = in_array($role, ['board_admin','board_member','property_manager','super_admin'], true);
$canManage  = in_array($role, ['board_admin','property_manager','super_admin'], true);
$canPropose = $canView; // board_member can propose agenda items

if (!$canView) { http_response_code(403); die('Forbidden'); }

$user = current_user();
$uid  = (int)$user['id'];

$mid = (int)($_GET['id'] ?? 0);
if (!$mid) { redirect('/dashboard/meetings.php'); }

function load_meeting(int $mid, int $assocId): ?array {
    $s = db()->prepare('SELECT * FROM board_meetings WHERE id = ? AND association_id = ?');
    $s->execute([$mid, $assocId]);
    return $s->fetch() ?: null;
}

$meeting = load_meeting($mid, $assocId);
if (!$meeting) { http_response_code(404); die('Meeting not found.'); }

$MEETING_TYPES = [
    'regular'   => 'Regular Meeting',
    'special'   => 'Special Meeting',
    'annual'    => 'Annual Meeting',
    'executive' => 'Executive Session',
];
$CATEGORIES = [
    'call_to_order'    => 'Call to Order',
    'proof_of_notice'  => 'Proof of Notice',
    'certify_quorum'   => 'Certify Quorum',
    'approve_minutes'  => 'Approve Minutes',
    'officers_report'  => "Officers' Report",
    'old_business'     => 'Old Business',
    'new_business'     => 'New Business',
    'motion_to_adjourn'=> 'Motion to Adjourn',
    'public_comments'  => 'Public Comments',
    'custom'           => 'Other',
];
$STANDARD_CATS = ['call_to_order','proof_of_notice','certify_quorum','approve_minutes','motion_to_adjourn','public_comments'];
$STATUS_LABELS = ['draft'=>'Draft','notice_posted'=>'Notice Posted','completed'=>'Completed','cancelled'=>'Cancelled'];

// Board + management members for pickers.
$boardStmt = db()->prepare(
    "SELECT id, first_name, last_name, board_office, role FROM users
      WHERE association_id = ? AND role IN ('board_admin','board_member') AND status <> 'inactive'
      ORDER BY FIELD(board_office,'president','vice_president','secretary','treasurer','secretary_treasurer','director') = 0,
               FIELD(board_office,'president','vice_president','secretary','treasurer','secretary_treasurer','director'),
               last_name, first_name"
);
$boardStmt->execute([$assocId]);
$boardMembers = $boardStmt->fetchAll();
$boardById    = [];
foreach ($boardMembers as $bm) { $boardById[(int)$bm['id']] = $bm; }

// All members for "proposed by" picker (board + mgmt only — owners can't propose).
$proposeMembers = $boardMembers;

// ============================================================
// POST HANDLERS
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = $_POST['form'] ?? '';

    // --- Update meeting info ---
    if ($form === 'update_meeting' && $canManage) {
        $mtype   = array_key_exists($_POST['meeting_type'] ?? '', $MEETING_TYPES) ? $_POST['meeting_type'] : $meeting['meeting_type'];
        $mdate   = trim((string)($_POST['meeting_date'] ?? ''));
        $mtime   = trim((string)($_POST['meeting_time'] ?? '')) ?: null;
        $title   = trim((string)($_POST['title'] ?? ''));
        $loc     = trim((string)($_POST['location'] ?? '')) ?: null;
        $vplat   = in_array($_POST['virtual_platform'] ?? '', ['none','zoom','google_meet','teams','webex','other']) ? $_POST['virtual_platform'] : 'none';
        $vurl    = trim((string)($_POST['virtual_url'] ?? '')) ?: null;
        $vmid    = trim((string)($_POST['virtual_meeting_id'] ?? '')) ?: null;
        $vpass   = trim((string)($_POST['virtual_passcode'] ?? '')) ?: null;
        $vphones = trim((string)($_POST['virtual_phone_numbers'] ?? '')) ?: null;
        $vsip    = trim((string)($_POST['virtual_sip'] ?? '')) ?: null;
        $vnotes  = trim((string)($_POST['virtual_notes'] ?? '')) ?: null;

        if ($mdate && $title) {
            db()->prepare(
                'UPDATE board_meetings
                    SET title=?, meeting_type=?, meeting_date=?, meeting_time=?,
                        location=?, virtual_platform=?, virtual_url=?, virtual_meeting_id=?,
                        virtual_passcode=?, virtual_phone_numbers=?, virtual_sip=?, virtual_notes=?
                  WHERE id=? AND association_id=?'
            )->execute([$title,$mtype,$mdate,$mtime,$loc,$vplat,$vurl,$vmid,$vpass,$vphones,$vsip,$vnotes,$mid,$assocId]);
            audit('meeting.updated', ['title'=>$title], $mid, 'board_meetings');
            flash('success','Meeting details updated.');
        }
        redirect('/dashboard/meeting-detail.php?id='.$mid.'&tab=notice');
    }

    // --- Add agenda item ---
    if ($form === 'add_item' && $canPropose) {
        $cat   = array_key_exists($_POST['category'] ?? '', $CATEGORIES) ? $_POST['category'] : 'custom';
        $title = trim((string)($_POST['title'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? '')) ?: null;
        $propBy = (int)($_POST['proposed_by'] ?? $uid);
        if ($canManage) {
            // Verify proposed_by belongs to this association.
            if (!isset($boardById[$propBy])) $propBy = $uid;
        } else {
            $propBy = $uid; // members can only propose for themselves
        }
        if ($title !== '') {
            // Sort between last custom and motion_to_adjourn (sort 990).
            $maxSortStmt = db()->prepare('SELECT MAX(sort_order) FROM agenda_items WHERE meeting_id = ? AND sort_order < 990');
            $maxSortStmt->execute([$mid]);
            $maxSort = (int)($maxSortStmt->fetchColumn() ?: 30);
            $newSort = max($maxSort + 10, 40);

            $iStatus = $canManage ? 'approved' : 'proposed';
            db()->prepare(
                'INSERT INTO agenda_items
                    (meeting_id, association_id, sort_order, category, title, description,
                     proposed_by_user_id, entered_by_user_id, status)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$mid,$assocId,$newSort,$cat,$title,$desc,$propBy,$uid,$iStatus]);
            audit('agenda_item.added', ['title'=>$title, 'meeting_id'=>$mid], (int)db()->lastInsertId(), 'agenda_items');
            flash('success', $canManage ? 'Item added.' : 'Item proposed — pending board approval.');
        }
        redirect('/dashboard/meeting-detail.php?id='.$mid.'&tab=agenda');
    }

    // --- Edit agenda item ---
    if ($form === 'edit_item' && $canManage) {
        $iid   = (int)($_POST['item_id'] ?? 0);
        $cat   = array_key_exists($_POST['category'] ?? '', $CATEGORIES) ? $_POST['category'] : 'custom';
        $title = trim((string)($_POST['title'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? '')) ?: null;
        $propBy = (int)($_POST['proposed_by'] ?? 0);
        if (!isset($boardById[$propBy])) $propBy = null;
        $iStatus = in_array($_POST['status']??'', ['proposed','approved','tabled','deferred','removed']) ? $_POST['status'] : 'approved';
        $outcome = trim((string)($_POST['outcome_notes'] ?? '')) ?: null;
        if ($iid && $title) {
            db()->prepare(
                'UPDATE agenda_items
                    SET category=?, title=?, description=?, proposed_by_user_id=?,
                        status=?, outcome_notes=?
                  WHERE id=? AND meeting_id=? AND association_id=?'
            )->execute([$cat,$title,$desc,$propBy,$iStatus,$outcome,$iid,$mid,$assocId]);
            flash('success','Item updated.');
        }
        redirect('/dashboard/meeting-detail.php?id='.$mid.'&tab=agenda');
    }

    // --- Approve / remove item (quick actions) ---
    if ($form === 'approve_item' && $canManage) {
        $iid    = (int)($_POST['item_id'] ?? 0);
        $action = ($_POST['action'] ?? '') === 'remove' ? 'removed' : 'approved';
        db()->prepare('UPDATE agenda_items SET status=? WHERE id=? AND meeting_id=? AND association_id=?')
            ->execute([$action,$iid,$mid,$assocId]);
        flash('success', $action === 'removed' ? 'Item removed.' : 'Item approved.');
        redirect('/dashboard/meeting-detail.php?id='.$mid.'&tab=agenda');
    }

    // --- Reorder items ---
    if ($form === 'reorder_items' && $canManage) {
        $ids = array_filter(array_map('intval', (array)($_POST['order'] ?? [])));
        $updStmt = db()->prepare('UPDATE agenda_items SET sort_order=? WHERE id=? AND meeting_id=? AND association_id=?');
        foreach (array_values($ids) as $idx => $iid) {
            $updStmt->execute([($idx + 1) * 10, $iid, $mid, $assocId]);
        }
        echo json_encode(['ok' => true]); exit;
    }

    // --- Add resolution ---
    if ($form === 'add_resolution' && $canManage) {
        $rtitle  = trim((string)($_POST['title'] ?? ''));
        $rbody   = trim((string)($_POST['body_text'] ?? '')) ?: null;
        $ragItem = (int)($_POST['agenda_item_id'] ?? 0) ?: null;
        $rmoved  = (int)($_POST['moved_by'] ?? 0) ?: null;
        $rsec    = (int)($_POST['seconded_by'] ?? 0) ?: null;
        if ($rmoved && !isset($boardById[$rmoved])) $rmoved = null;
        if ($rsec   && !isset($boardById[$rsec]))   $rsec   = null;
        if ($rtitle !== '') {
            $maxR = db()->prepare('SELECT MAX(sort_order) FROM resolutions WHERE meeting_id=?');
            $maxR->execute([$mid]);
            $rSort = (int)($maxR->fetchColumn() ?: 0) + 10;
            db()->prepare(
                'INSERT INTO resolutions
                    (meeting_id, agenda_item_id, association_id, sort_order, title, body_text,
                     moved_by_user_id, seconded_by_user_id)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$mid,$ragItem,$assocId,$rSort,$rtitle,$rbody,$rmoved,$rsec]);
            $rid = (int)db()->lastInsertId();
            // Save votes if submitted.
            $vStmt = db()->prepare(
                'INSERT INTO resolution_votes (resolution_id, voter_user_id, vote, entered_by_user_id)
                 VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE vote=VALUES(vote), entered_by_user_id=VALUES(entered_by_user_id)'
            );
            foreach ($boardMembers as $bm) {
                $bmid = (int)$bm['id'];
                $v = $_POST['vote_'.$bmid] ?? '';
                if (in_array($v, ['yes','no','abstain','not_present','na'], true)) {
                    $vStmt->execute([$rid,$bmid,$v,$uid]);
                }
            }
            // Auto-compute result from votes.
            update_resolution_result($rid);
            audit('resolution.added', ['title'=>$rtitle, 'meeting_id'=>$mid], $rid, 'resolutions');
            flash('success', 'Resolution added.');
        }
        redirect('/dashboard/meeting-detail.php?id='.$mid.'&tab=resolutions');
    }

    // --- Save resolution votes + details ---
    if ($form === 'save_resolution' && $canManage) {
        $rid    = (int)($_POST['resolution_id'] ?? 0);
        $rchk   = db()->prepare('SELECT id FROM resolutions WHERE id=? AND meeting_id=? AND association_id=?');
        $rchk->execute([$rid,$mid,$assocId]);
        if ($rchk->fetch()) {
            $rtitle  = trim((string)($_POST['title'] ?? ''));
            $rbody   = trim((string)($_POST['body_text'] ?? '')) ?: null;
            $ragItem = (int)($_POST['agenda_item_id'] ?? 0) ?: null;
            $rmoved  = (int)($_POST['moved_by'] ?? 0) ?: null;
            $rsec    = (int)($_POST['seconded_by'] ?? 0) ?: null;
            $rnotes  = trim((string)($_POST['result_notes'] ?? '')) ?: null;
            if ($rmoved && !isset($boardById[$rmoved])) $rmoved = null;
            if ($rsec   && !isset($boardById[$rsec]))   $rsec   = null;
            if ($rtitle) {
                db()->prepare(
                    'UPDATE resolutions SET title=?, body_text=?, agenda_item_id=?,
                        moved_by_user_id=?, seconded_by_user_id=?, result_notes=?
                      WHERE id=?'
                )->execute([$rtitle,$rbody,$ragItem,$rmoved,$rsec,$rnotes,$rid]);
            }
            // Upsert votes.
            $vStmt = db()->prepare(
                'INSERT INTO resolution_votes (resolution_id, voter_user_id, vote, entered_by_user_id)
                 VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE vote=VALUES(vote), entered_by_user_id=VALUES(entered_by_user_id)'
            );
            $dStmt = db()->prepare('DELETE FROM resolution_votes WHERE resolution_id=? AND voter_user_id=?');
            foreach ($boardMembers as $bm) {
                $bmid = (int)$bm['id'];
                $v = $_POST['vote_'.$bmid] ?? '';
                if (in_array($v, ['yes','no','abstain','not_present','na'], true)) {
                    $vStmt->execute([$rid,$bmid,$v,$uid]);
                } else {
                    $dStmt->execute([$rid,$bmid]);
                }
            }
            update_resolution_result($rid);
            flash('success','Resolution saved.');
        }
        redirect('/dashboard/meeting-detail.php?id='.$mid.'&tab=resolutions');
    }

    // --- Delete resolution ---
    if ($form === 'delete_resolution' && $canManage) {
        $rid = (int)($_POST['resolution_id'] ?? 0);
        $rchk = db()->prepare('SELECT title FROM resolutions WHERE id=? AND meeting_id=? AND association_id=?');
        $rchk->execute([$rid,$mid,$assocId]);
        if ($r = $rchk->fetch()) {
            db()->prepare('DELETE FROM resolution_votes WHERE resolution_id=?')->execute([$rid]);
            db()->prepare('DELETE FROM resolutions WHERE id=?')->execute([$rid]);
            audit('resolution.deleted', ['title'=>$r['title'], 'meeting_id'=>$mid], $rid, 'resolutions');
            flash('success','Resolution deleted.');
        }
        redirect('/dashboard/meeting-detail.php?id='.$mid.'&tab=resolutions');
    }

    // --- Update proof of notice data ---
    if ($form === 'update_proof' && $canManage) {
        $pstate  = trim((string)($_POST['proof_state'] ?? 'Florida')) ?: 'Florida';
        $pcounty = trim((string)($_POST['proof_county'] ?? '')) ?: null;
        $psigned = (int)($_POST['proof_signed_by'] ?? 0) ?: null;
        if ($psigned && !isset($boardById[$psigned])) $psigned = null;
        $psdate  = trim((string)($_POST['proof_signed_date'] ?? '')) ?: null;
        $pnname  = trim((string)($_POST['proof_notary_name'] ?? '')) ?: null;
        $pncomm  = trim((string)($_POST['proof_notary_commission'] ?? '')) ?: null;
        $pnexp   = trim((string)($_POST['proof_notary_expires'] ?? '')) ?: null;

        db()->prepare(
            'UPDATE board_meetings SET proof_state=?, proof_county=?, proof_signed_by_user_id=?,
                proof_signed_date=?, proof_notary_name=?, proof_notary_commission=?, proof_notary_expires=?
              WHERE id=? AND association_id=?'
        )->execute([$pstate,$pcounty,$psigned,$psdate,$pnname,$pncomm,$pnexp,$mid,$assocId]);
        flash('success','Proof of notice data saved.');
        redirect('/dashboard/meeting-detail.php?id='.$mid.'&tab=notice');
    }

    // --- Mark notice posted ---
    if ($form === 'mark_notice_posted' && $canManage) {
        db()->prepare(
            'UPDATE board_meetings SET status=\'notice_posted\', notice_posted_at=NOW(), notice_posted_by=?
              WHERE id=? AND association_id=?'
        )->execute([$uid,$mid,$assocId]);
        audit('meeting.notice_posted', [], $mid, 'board_meetings');
        flash('success','Meeting marked as notice posted.');
        redirect('/dashboard/meeting-detail.php?id='.$mid.'&tab=notice');
    }

    // --- Mark completed ---
    if ($form === 'mark_completed' && $canManage) {
        db()->prepare('UPDATE board_meetings SET status=\'completed\' WHERE id=? AND association_id=?')
            ->execute([$mid,$assocId]);
        audit('meeting.completed', [], $mid, 'board_meetings');
        flash('success','Meeting marked as completed.');
        redirect('/dashboard/meeting-detail.php?id='.$mid.'&tab=notice');
    }

    // --- Link minutes ---
    if ($form === 'link_minutes' && $canManage) {
        $mmid = (int)($_POST['minutes_id'] ?? 0);
        if ($mmid) {
            $chk = db()->prepare('SELECT id FROM meeting_minutes WHERE id=? AND association_id=?');
            $chk->execute([$mmid,$assocId]);
            if ($chk->fetch()) {
                db()->prepare('UPDATE meeting_minutes SET board_meeting_id=? WHERE id=?')->execute([$mid,$mmid]);
                flash('success','Minutes linked.');
            }
        }
        redirect('/dashboard/meeting-detail.php?id='.$mid.'&tab=minutes');
    }

    // --- Unlink minutes ---
    if ($form === 'unlink_minutes' && $canManage) {
        $mmid = (int)($_POST['minutes_id'] ?? 0);
        db()->prepare('UPDATE meeting_minutes SET board_meeting_id=NULL WHERE id=? AND association_id=?')
            ->execute([$mmid,$assocId]);
        flash('success','Minutes unlinked.');
        redirect('/dashboard/meeting-detail.php?id='.$mid.'&tab=minutes');
    }
}

function update_resolution_result(int $rid): void {
    $vs = db()->prepare('SELECT vote, COUNT(*) cnt FROM resolution_votes WHERE resolution_id=? GROUP BY vote');
    $vs->execute([$rid]);
    $tally = ['yes'=>0,'no'=>0,'abstain'=>0,'not_present'=>0,'na'=>0];
    foreach ($vs->fetchAll() as $v) $tally[$v['vote']] = (int)$v['cnt'];
    $result = 'pending';
    // Only yes/no count toward pass/fail; abstain, not_present, na are excluded from the majority.
    if ($tally['yes'] > 0 || $tally['no'] > 0) {
        $result = $tally['yes'] > $tally['no'] ? 'passed' : 'failed';
    }
    db()->prepare('UPDATE resolutions SET result=? WHERE id=?')->execute([$result,$rid]);
}

// ============================================================
// LOAD DATA FOR VIEW
// ============================================================

$meeting = load_meeting($mid, $assocId); // reload after any saves

$agendaStmt = db()->prepare(
    "SELECT a.*, TRIM(CONCAT(IFNULL(p.first_name,''),' ',IFNULL(p.last_name,''))) AS proposer_name,
                 TRIM(CONCAT(IFNULL(e.first_name,''),' ',IFNULL(e.last_name,''))) AS enterer_name
       FROM agenda_items a
       LEFT JOIN users p ON p.id = a.proposed_by_user_id
       LEFT JOIN users e ON e.id = a.entered_by_user_id
      WHERE a.meeting_id = ? AND a.association_id = ? AND a.status <> 'removed'
      ORDER BY a.sort_order, a.id"
);
$agendaStmt->execute([$mid,$assocId]);
$agendaItems = $agendaStmt->fetchAll();

$resStmt = db()->prepare(
    "SELECT r.*,
            TRIM(CONCAT(IFNULL(mv.first_name,''),' ',IFNULL(mv.last_name,''))) AS mover_name,
            mv.board_office AS mover_office,
            TRIM(CONCAT(IFNULL(sv.first_name,''),' ',IFNULL(sv.last_name,''))) AS seconder_name,
            sv.board_office AS seconder_office,
            ai.title AS agenda_item_title
       FROM resolutions r
       LEFT JOIN users mv ON mv.id = r.moved_by_user_id
       LEFT JOIN users sv ON sv.id = r.seconded_by_user_id
       LEFT JOIN agenda_items ai ON ai.id = r.agenda_item_id
      WHERE r.meeting_id = ? AND r.association_id = ?
      ORDER BY r.sort_order, r.id"
);
$resStmt->execute([$mid,$assocId]);
$resolutions = $resStmt->fetchAll();

// Load votes for each resolution.
$allVotes = [];
if ($resolutions) {
    $rids  = array_column($resolutions, 'id');
    $phstr = implode(',', array_fill(0, count($rids), '?'));
    $vStmt = db()->prepare("SELECT * FROM resolution_votes WHERE resolution_id IN ($phstr)");
    $vStmt->execute($rids);
    foreach ($vStmt->fetchAll() as $v) {
        $allVotes[(int)$v['resolution_id']][(int)$v['voter_user_id']] = $v['vote'];
    }
}

// Linked minutes.
$linkedMinutes = [];
$lmStmt = db()->prepare('SELECT id, title, meeting_date FROM meeting_minutes WHERE board_meeting_id=? AND association_id=?');
$lmStmt->execute([$mid,$assocId]);
$linkedMinutes = $lmStmt->fetchAll();

// Unlinkable minutes (same association, not already linked to a different meeting).
$availMinutes = [];
if ($canManage) {
    $amStmt = db()->prepare(
        'SELECT id, title, meeting_date FROM meeting_minutes
          WHERE association_id=? AND (board_meeting_id IS NULL OR board_meeting_id=?)
          ORDER BY meeting_date DESC LIMIT 50'
    );
    $amStmt->execute([$assocId,$mid]);
    $availMinutes = $amStmt->fetchAll();
}

// Proof-of-notice signer info.
$proofSigner = null;
if (!empty($meeting['proof_signed_by_user_id'])) {
    $proofSigner = $boardById[(int)$meeting['proof_signed_by_user_id']] ?? null;
}

$tab = $_GET['tab'] ?? 'agenda';
if (!in_array($tab, ['agenda','resolutions','notice','minutes'], true)) $tab = 'agenda';

$editItemId   = (int)($_GET['edit_item'] ?? 0);
$editResId    = (int)($_GET['edit_res'] ?? 0);
$showAddItem  = !empty($_GET['add_item']);
$showAddRes   = !empty($_GET['add_res']);

$active     = 'meetings';
$page_title = e((string)$meeting['title']) . ' — ' . e((string)$association['name']);
require __DIR__ . '/../includes/header.php';

// Helpers for display.
function res_result_badge(string $r): string {
    return match($r) {
        'passed'    => '<span class="badge badge--success">Passed</span>',
        'failed'    => '<span class="badge" style="background:var(--color-error-bg,#fef2f2);color:var(--color-error);">Failed</span>',
        'tabled'    => '<span class="badge badge--warning">Tabled</span>',
        'withdrawn' => '<span class="badge">Withdrawn</span>',
        default     => '<span class="badge" style="opacity:.6;">Pending</span>',
    };
}
function vote_icon(string $v): string {
    return match($v) {
        'yes'         => '<span style="color:var(--color-success,#16a34a);font-weight:700;">Yes</span>',
        'no'          => '<span style="color:var(--color-error);font-weight:700;">No</span>',
        'abstain'     => '<span class="muted">Abstain</span>',
        'not_present' => '<span class="muted">Not present</span>',
        'na'          => '<span class="muted">N/A</span>',
        default       => '<span class="muted">—</span>',
    };
}
function board_label(array $bm): string {
    $name = trim($bm['first_name'] . ' ' . $bm['last_name']);
    $off  = board_office_label($bm['board_office'] ?? null);
    return $off ? "$name ($off)" : $name;
}
?>

<div class="container" style="padding:var(--sp-6) var(--sp-6) var(--sp-12); max-width:1060px;">

<!-- Header -->
<div style="margin-bottom:var(--sp-4);">
    <a class="muted" style="font-size:var(--fs-sm);" href="/dashboard/meetings.php">← Back to meetings</a>
    <div class="row row--between" style="margin-top:var(--sp-2); flex-wrap:wrap; gap:var(--sp-2); align-items:flex-start;">
        <div>
            <div class="row" style="gap:var(--sp-2); margin-bottom:var(--sp-1); flex-wrap:wrap; align-items:center;">
                <span class="badge <?= (string)$meeting['status']==='notice_posted'?'badge--info':((string)$meeting['status']==='completed'?'badge--success':'') ?>">
                    <?= e($STATUS_LABELS[(string)$meeting['status']] ?? (string)$meeting['status']) ?>
                </span>
                <span class="badge" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-muted);">
                    <?= e($MEETING_TYPES[(string)$meeting['meeting_type']] ?? (string)$meeting['meeting_type']) ?>
                </span>
            </div>
            <h1 style="font-size:var(--fs-2xl);margin:0 0 var(--sp-1);"><?= e((string)$meeting['title']) ?></h1>
            <p class="muted" style="font-size:var(--fs-sm); margin:0;">
                <?= e(udate('l, F j, Y', strtotime((string)$meeting['meeting_date']))) ?>
                <?php if (!empty($meeting['meeting_time'])): ?>
                    at <?= e(date('g:i A', strtotime((string)$meeting['meeting_time']))) ?>
                <?php endif; ?>
                <?php if (!empty($meeting['location'])): ?>
                    · <?= e((string)$meeting['location']) ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="row" style="gap:var(--sp-2); flex-wrap:wrap;">
            <a class="btn btn--ghost" href="/dashboard/meeting-print.php?id=<?= $mid ?>&type=agenda" target="_blank">Print agenda</a>
            <a class="btn btn--ghost" href="/dashboard/meeting-print.php?id=<?= $mid ?>&type=package" target="_blank">Print package</a>
        </div>
    </div>
</div>

<!-- Tab nav -->
<div style="border-bottom:2px solid var(--color-border); margin-bottom:var(--sp-5); display:flex; gap:0;">
    <?php foreach (['agenda'=>'Agenda','resolutions'=>'Resolutions','notice'=>'Notice & Print','minutes'=>'Minutes'] as $tv=>$tl): ?>
        <a href="?id=<?= $mid ?>&tab=<?= $tv ?>"
           style="padding:var(--sp-2) var(--sp-4); text-decoration:none; font-weight:600; font-size:var(--fs-sm);
                  border-bottom:3px solid <?= $tab===$tv?'var(--color-primary)':'transparent' ?>;
                  color:<?= $tab===$tv?'var(--color-primary)':'var(--color-text-muted)' ?>;
                  margin-bottom:-2px; white-space:nowrap;"><?= $tl ?></a>
    <?php endforeach; ?>
</div>

<?php /* ===== TAB: AGENDA ===== */ if ($tab === 'agenda'): ?>

<div class="row row--between" style="margin-bottom:var(--sp-3);">
    <div>
        <h2 style="font-size:var(--fs-xl);margin:0;">Agenda</h2>
        <?php if (!$canManage): ?>
            <p class="muted" style="font-size:var(--fs-sm); margin:var(--sp-1) 0 0;">You can propose new items. The board admin will approve them.</p>
        <?php endif; ?>
    </div>
    <a class="btn btn--primary" href="?id=<?= $mid ?>&tab=agenda&add_item=1">+ Propose item</a>
</div>

<?php /* Pending proposals banner for managers */ ?>
<?php
$pendingCount = count(array_filter($agendaItems, fn($i) => $i['status'] === 'proposed'));
if ($pendingCount && $canManage):
?>
<div class="flash flash--info" style="margin-bottom:var(--sp-3);">
    <?= $pendingCount ?> item<?= $pendingCount!==1?'s':'' ?> awaiting approval.
</div>
<?php endif; ?>

<?php /* Add item form */ ?>
<?php if ($showAddItem): ?>
<div class="card card--padded" style="margin-bottom:var(--sp-4); border-top:3px solid var(--color-primary);">
    <h3 style="font-size:var(--fs-lg);margin:0 0 var(--sp-3);">Propose agenda item</h3>
    <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="add_item">
        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label" for="icat">Category</label>
                <select class="select" id="icat" name="category">
                    <?php foreach ($CATEGORIES as $cv=>$cl): ?>
                        <?php if (!in_array($cv, $STANDARD_CATS, true)): ?>
                            <option value="<?= e($cv) ?>"><?= e($cl) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($canManage && count($proposeMembers) > 1): ?>
            <div class="field">
                <label class="field__label" for="ipropby">Proposed by</label>
                <select class="select" id="ipropby" name="proposed_by">
                    <?php foreach ($proposeMembers as $pm): ?>
                        <option value="<?= (int)$pm['id'] ?>" <?= (int)$pm['id']===$uid?'selected':'' ?>>
                            <?= e(board_label($pm)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
                <input type="hidden" name="proposed_by" value="<?= $uid ?>">
            <?php endif; ?>
        </div>
        <div class="field">
            <label class="field__label" for="ititl">Title <span style="color:var(--color-error)">*</span></label>
            <input class="input" id="ititl" name="title" required maxlength="500"
                   placeholder="e.g. Elevator repair authorization — AWS Elevator">
        </div>
        <div class="field">
            <label class="field__label" for="idesc">Details / background <span class="muted" style="font-weight:400;">(optional)</span></label>
            <textarea class="textarea" id="idesc" name="description" rows="2"
                      placeholder="Additional context, dollar amounts, vendor info…"></textarea>
        </div>
        <div class="row" style="justify-content:flex-end; gap:var(--sp-2);">
            <a class="btn btn--ghost" href="?id=<?= $mid ?>&tab=agenda">Cancel</a>
            <button class="btn btn--primary" type="submit">Propose item</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php /* Edit item form */ ?>
<?php
$editItem = null;
if ($editItemId && $canManage) {
    foreach ($agendaItems as $ai) {
        if ((int)$ai['id'] === $editItemId) { $editItem = $ai; break; }
    }
}
?>
<?php if ($editItem): ?>
<div class="card card--padded" style="margin-bottom:var(--sp-4); border-top:3px solid var(--color-warning);">
    <h3 style="font-size:var(--fs-lg);margin:0 0 var(--sp-3);">Edit item</h3>
    <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="edit_item">
        <input type="hidden" name="item_id" value="<?= (int)$editItem['id'] ?>">
        <div class="form-row form-row--3">
            <div class="field">
                <label class="field__label">Category</label>
                <select class="select" name="category">
                    <?php foreach ($CATEGORIES as $cv=>$cl): ?>
                        <option value="<?= e($cv) ?>" <?= $editItem['category']===$cv?'selected':'' ?>><?= e($cl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label">Proposed by</label>
                <select class="select" name="proposed_by">
                    <option value="">— unassigned —</option>
                    <?php foreach ($proposeMembers as $pm): ?>
                        <option value="<?= (int)$pm['id'] ?>" <?= (int)$editItem['proposed_by_user_id']===(int)$pm['id']?'selected':'' ?>>
                            <?= e(board_label($pm)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label">Status</label>
                <select class="select" name="status">
                    <?php foreach (['proposed'=>'Proposed','approved'=>'Approved','tabled'=>'Tabled','deferred'=>'Deferred'] as $sv=>$sl): ?>
                        <option value="<?= $sv ?>" <?= $editItem['status']===$sv?'selected':'' ?>><?= $sl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field">
            <label class="field__label">Title</label>
            <input class="input" name="title" required maxlength="500" value="<?= e((string)$editItem['title']) ?>">
        </div>
        <div class="field">
            <label class="field__label">Details</label>
            <textarea class="textarea" name="description" rows="2"><?= e((string)($editItem['description'] ?? '')) ?></textarea>
        </div>
        <div class="field">
            <label class="field__label">Outcome notes</label>
            <textarea class="textarea" name="outcome_notes" rows="2"
                      placeholder="What was decided, tabled, or discussed…"><?= e((string)($editItem['outcome_notes'] ?? '')) ?></textarea>
        </div>
        <div class="row" style="justify-content:flex-end; gap:var(--sp-2);">
            <a class="btn btn--ghost" href="?id=<?= $mid ?>&tab=agenda">Cancel</a>
            <button class="btn btn--primary" type="submit">Save</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php /* Agenda list */ ?>
<?php if (!$agendaItems): ?>
    <div class="card card--padded center" style="padding:var(--sp-10) var(--sp-6);">
        <p class="muted">No agenda items yet.</p>
    </div>
<?php else: ?>
<div id="agenda-list" style="display:flex; flex-direction:column; gap:var(--sp-2);">
<?php
$approvedItems = array_filter($agendaItems, fn($i) => $i['status'] === 'approved');
$pendingItems  = array_filter($agendaItems, fn($i) => $i['status'] === 'proposed');
$otherItems    = array_filter($agendaItems, fn($i) => in_array($i['status'], ['tabled','deferred'], true));
$num = 0;
foreach ($agendaItems as $ai):
    $isStd  = in_array($ai['category'], $STANDARD_CATS, true);
    $isPend = $ai['status'] === 'proposed';
    if ($ai['status'] === 'approved') $num++;
    $dispNum = $ai['status'] === 'approved' ? $num : null;
?>
<div class="card card--padded" data-item-id="<?= (int)$ai['id'] ?>"
     style="display:flex; gap:var(--sp-3); align-items:flex-start;
            opacity:<?= $isPend?'0.75':'1' ?>;
            border-left:3px solid <?= $isPend?'var(--color-warning)':($isStd?'var(--color-border)':'var(--color-primary)') ?>;">
    <?php if ($canManage && !$isStd): ?>
    <div style="display:flex;flex-direction:column;gap:2px;padding-top:2px;flex-shrink:0;">
        <form method="post" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="reorder_items">
            <button type="button" class="btn btn--ghost" style="padding:2px 6px;font-size:10px;" onclick="moveItem(<?= (int)$ai['id'] ?>,-1)" title="Move up">▲</button>
        </form>
        <form method="post" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="reorder_items">
            <button type="button" class="btn btn--ghost" style="padding:2px 6px;font-size:10px;" onclick="moveItem(<?= (int)$ai['id'] ?>,1)" title="Move down">▼</button>
        </form>
    </div>
    <?php endif; ?>
    <div style="flex:1;min-width:0;">
        <div class="row" style="gap:var(--sp-2);flex-wrap:wrap;align-items:center;margin-bottom:var(--sp-1);">
            <?php if ($dispNum): ?><span style="font-weight:700;color:var(--color-text-muted);min-width:1.5em;"><?= $dispNum ?>.</span><?php endif; ?>
            <span class="badge" style="font-size:var(--fs-xs);background:var(--color-bg);border:1px solid var(--color-border);">
                <?= e($CATEGORIES[$ai['category']] ?? (string)$ai['category']) ?>
            </span>
            <?php if ($isPend): ?><span class="badge badge--warning" style="font-size:var(--fs-xs);">Pending approval</span><?php endif; ?>
            <?php if (in_array($ai['status'],['tabled','deferred'],true)): ?><span class="badge" style="font-size:var(--fs-xs);"><?= ucfirst($ai['status']) ?></span><?php endif; ?>
            <strong><?= e((string)$ai['title']) ?></strong>
        </div>
        <?php if (!empty($ai['description'])): ?>
            <p style="margin:0 0 var(--sp-1);font-size:var(--fs-sm);color:var(--color-text-muted);"><?= e((string)$ai['description']) ?></p>
        <?php endif; ?>
        <div class="muted" style="font-size:var(--fs-xs);">
            <?php
            $propName = trim((string)($ai['proposer_name'] ?? ''));
            $entName  = trim((string)($ai['enterer_name'] ?? ''));
            if ($propName) {
                echo 'Proposed by ' . e($propName);
                if ($entName && $entName !== $propName) echo ' · entered by ' . e($entName);
            }
            ?>
            <?php if (!empty($ai['outcome_notes'])): ?>
                <br><?= e((string)$ai['outcome_notes']) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($canManage && !$isStd): ?>
    <div class="row" style="gap:var(--sp-1);flex-shrink:0;">
        <?php if ($isPend): ?>
            <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="approve_item">
                <input type="hidden" name="item_id" value="<?= (int)$ai['id'] ?>">
                <input type="hidden" name="action" value="approve">
                <button class="btn btn--ghost" type="submit" style="font-size:var(--fs-xs);padding:var(--sp-1) var(--sp-2);color:var(--color-success,#16a34a);">Approve</button>
            </form>
        <?php endif; ?>
        <a class="btn btn--ghost" href="?id=<?= $mid ?>&tab=agenda&edit_item=<?= (int)$ai['id'] ?>"
           style="font-size:var(--fs-xs);padding:var(--sp-1) var(--sp-2);">Edit</a>
        <form method="post" style="display:inline;"
              onsubmit="return confirm('Remove this item?');">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="approve_item">
            <input type="hidden" name="item_id" value="<?= (int)$ai['id'] ?>">
            <input type="hidden" name="action" value="remove">
            <button class="btn btn--ghost" type="submit"
                    style="font-size:var(--fs-xs);padding:var(--sp-1) var(--sp-2);color:var(--color-error);">Remove</button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<div style="margin-top:var(--sp-4);">
    <form method="post" id="reorder-form">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="reorder_items">
        <div id="reorder-ids"></div>
    </form>
</div>
<?php endif; ?>

<?php /* ===== TAB: RESOLUTIONS ===== */ elseif ($tab === 'resolutions'): ?>

<div class="row row--between" style="margin-bottom:var(--sp-3);">
    <div>
        <h2 style="font-size:var(--fs-xl);margin:0;">Resolutions</h2>
        <p class="muted" style="font-size:var(--fs-sm);margin:var(--sp-1) 0 0;">Track formal votes, movers, seconders, and individual board member votes.</p>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn--primary" href="?id=<?= $mid ?>&tab=resolutions&add_res=1">+ Add resolution</a>
    <?php endif; ?>
</div>

<?php /* Add resolution form */ ?>
<?php if ($showAddRes && $canManage): ?>
<?php
// Get approved new-business agenda items as candidates to link.
$nbItems = array_filter($agendaItems, fn($i) => in_array($i['category'], ['new_business','old_business','custom'], true) && $i['status'] === 'approved');
?>
<div class="card card--padded" style="margin-bottom:var(--sp-5); border-top:3px solid var(--color-primary);">
    <h3 style="font-size:var(--fs-lg);margin:0 0 var(--sp-3);">Add resolution</h3>
    <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="add_resolution">
        <div class="field">
            <label class="field__label">Title <span style="color:var(--color-error)">*</span></label>
            <input class="input" name="title" required maxlength="500"
                   placeholder="e.g. Authorize elevator repair by Raymond Key Co. up to $6,000">
        </div>
        <div class="field">
            <label class="field__label">Resolution clauses <span class="muted" style="font-weight:400;">(one clause per line — printed with "BE IT RESOLVED THAT" before each)</span></label>
            <textarea class="textarea" name="body_text" rows="4"
                      placeholder="the Board of Directors hereby authorizes Raymond Key Co. to perform elevator repairs up to $6,000&#10;the property manager is directed to obtain three bids before proceeding"></textarea>
            <div class="field__hint">Each line becomes a numbered "BE IT RESOLVED THAT…" clause on the printed resolution.</div>
        </div>
        <?php if ($nbItems): ?>
        <div class="field">
            <label class="field__label">Related agenda item <span class="muted" style="font-weight:400;">(optional)</span></label>
            <select class="select" name="agenda_item_id">
                <option value="">— none —</option>
                <?php foreach ($nbItems as $ni): ?>
                    <option value="<?= (int)$ni['id'] ?>"><?= e((string)$ni['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label">Moved by</label>
                <select class="select" name="moved_by">
                    <option value="">— select —</option>
                    <?php foreach ($boardMembers as $bm): ?>
                        <option value="<?= (int)$bm['id'] ?>"><?= e(board_label($bm)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label">Seconded by</label>
                <select class="select" name="seconded_by">
                    <option value="">— select —</option>
                    <?php foreach ($boardMembers as $bm): ?>
                        <option value="<?= (int)$bm['id'] ?>"><?= e(board_label($bm)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <!-- Vote grid -->
        <div class="field">
            <label class="field__label">Votes</label>
            <div style="border:1px solid var(--color-border);border-radius:var(--r-md);overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;font-size:var(--fs-sm);">
                    <thead>
                        <tr style="background:var(--color-bg);">
                            <th style="text-align:left;padding:var(--sp-2) var(--sp-3);">Board member</th>
                            <th style="padding:var(--sp-2) var(--sp-3);text-align:center;color:var(--color-success,#16a34a);">Yes</th>
                            <th style="padding:var(--sp-2) var(--sp-3);text-align:center;color:var(--color-error);">No</th>
                            <th style="padding:var(--sp-2) var(--sp-3);text-align:center;color:var(--color-text-muted);">Abstain</th>
                            <th style="padding:var(--sp-2) var(--sp-3);text-align:center;color:var(--color-text-muted);">Not present</th>
                            <th style="padding:var(--sp-2) var(--sp-3);text-align:center;color:var(--color-text-muted);">N/A</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($boardMembers as $bm): ?>
                        <tr style="border-top:1px solid var(--color-border);">
                            <td style="padding:var(--sp-2) var(--sp-3);"><?= e(board_label($bm)) ?></td>
                            <?php foreach (['yes','no','abstain','not_present','na',''] as $vv): ?>
                            <td style="text-align:center;padding:var(--sp-2);">
                                <input type="radio" name="vote_<?= (int)$bm['id'] ?>"
                                       value="<?= $vv ?>" <?= $vv===''?'checked':'' ?>
                                       style="accent-color:var(--color-primary);width:16px;height:16px;">
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="row" style="justify-content:flex-end;gap:var(--sp-2);">
            <a class="btn btn--ghost" href="?id=<?= $mid ?>&tab=resolutions">Cancel</a>
            <button class="btn btn--primary" type="submit">Save resolution</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$resolutions): ?>
<div class="card card--padded center" style="padding:var(--sp-10) var(--sp-6);">
    <p class="muted">No resolutions yet.</p>
    <?php if ($canManage): ?>
        <p style="margin-top:var(--sp-3);"><a class="btn btn--primary" href="?id=<?= $mid ?>&tab=resolutions&add_res=1">+ Add first resolution</a></p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="stack-md">
<?php foreach ($resolutions as $ri => $res): ?>
<?php
$votes       = $allVotes[(int)$res['id']] ?? [];
$yes         = count(array_filter($votes, fn($v) => $v === 'yes'));
$no          = count(array_filter($votes, fn($v) => $v === 'no'));
$abs         = count(array_filter($votes, fn($v) => $v === 'abstain'));
$notPresent  = count(array_filter($votes, fn($v) => $v === 'not_present'));
$na          = count(array_filter($votes, fn($v) => $v === 'na'));
$isEdit = ($editResId === (int)$res['id']) && $canManage;
$nbItems = array_filter($agendaItems, fn($i) => in_array($i['category'], ['new_business','old_business','custom'], true) && $i['status'] === 'approved');
?>
<div class="card card--padded" style="border-left:3px solid var(--color-primary);">
    <?php if (!$isEdit): ?>
    <div class="row row--between" style="flex-wrap:wrap;gap:var(--sp-2);margin-bottom:var(--sp-2);">
        <div>
            <div class="row" style="gap:var(--sp-2);align-items:center;margin-bottom:var(--sp-1);">
                <strong style="font-size:var(--fs-lg);">Resolution <?= $ri+1 ?>: <?= e((string)$res['title']) ?></strong>
                <?= res_result_badge((string)$res['result']) ?>
            </div>
            <?php if (!empty($res['agenda_item_title'])): ?>
                <div class="muted" style="font-size:var(--fs-xs);">Re: <?= e((string)$res['agenda_item_title']) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($canManage): ?>
        <div class="row" style="gap:var(--sp-1);">
            <a class="btn btn--ghost" href="?id=<?= $mid ?>&tab=resolutions&edit_res=<?= (int)$res['id'] ?>"
               style="font-size:var(--fs-xs);padding:var(--sp-1) var(--sp-2);">Edit</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this resolution?');">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="delete_resolution">
                <input type="hidden" name="resolution_id" value="<?= (int)$res['id'] ?>">
                <button class="btn btn--ghost" type="submit"
                        style="font-size:var(--fs-xs);padding:var(--sp-1) var(--sp-2);color:var(--color-error);">Delete</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($res['body_text'])): ?>
        <p style="font-size:var(--fs-sm);white-space:pre-wrap;margin-bottom:var(--sp-3);"><?= e((string)$res['body_text']) ?></p>
    <?php endif; ?>

    <div style="display:flex;gap:var(--sp-6);flex-wrap:wrap;margin-bottom:var(--sp-3);font-size:var(--fs-sm);">
        <div>
            <span class="muted" style="font-size:var(--fs-xs);display:block;">MOVED BY</span>
            <?= $res['mover_name'] ? e(trim((string)$res['mover_name']) . ($res['mover_office'] ? ' (' . board_office_label((string)$res['mover_office']) . ')' : '')) : '<span class="muted">—</span>' ?>
        </div>
        <div>
            <span class="muted" style="font-size:var(--fs-xs);display:block;">SECONDED BY</span>
            <?= $res['seconder_name'] ? e(trim((string)$res['seconder_name']) . ($res['seconder_office'] ? ' (' . board_office_label((string)$res['seconder_office']) . ')' : '')) : '<span class="muted">—</span>' ?>
        </div>
        <?php if ($votes): ?>
        <div>
            <span class="muted" style="font-size:var(--fs-xs);display:block;">TALLY</span>
            <span style="color:var(--color-success,#16a34a);font-weight:700;"><?= $yes ?></span>–<span style="color:var(--color-error);font-weight:700;"><?= $no ?></span>–<span class="muted"><?= $abs ?></span>
            <span class="muted" style="font-size:var(--fs-xs);">(yes–no–abstain<?= $notPresent ? ', '.$notPresent.' absent' : '' ?><?= $na ? ', '.$na.' N/A' : '' ?>)</span>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($votes): ?>
    <div style="display:flex;flex-wrap:wrap;gap:var(--sp-2);">
        <?php foreach ($boardMembers as $bm): ?>
        <?php $v = $votes[(int)$bm['id']] ?? null; if (!$v) continue; ?>
        <span style="display:inline-flex;align-items:center;gap:var(--sp-1);background:var(--color-bg);border:1px solid var(--color-border);border-radius:var(--r-sm);padding:2px 8px;font-size:var(--fs-xs);">
            <?= e(trim($bm['first_name'].' '.$bm['last_name'])) ?> · <?= vote_icon($v) ?>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php else: /* edit form */ ?>
    <h3 style="font-size:var(--fs-lg);margin:0 0 var(--sp-3);">Edit resolution <?= $ri+1 ?></h3>
    <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="save_resolution">
        <input type="hidden" name="resolution_id" value="<?= (int)$res['id'] ?>">
        <div class="field">
            <label class="field__label">Title</label>
            <input class="input" name="title" required maxlength="500" value="<?= e((string)$res['title']) ?>">
        </div>
        <div class="field">
            <label class="field__label">Resolution clauses <span class="muted" style="font-weight:400;">(one clause per line)</span></label>
            <textarea class="textarea" name="body_text" rows="4"><?= e((string)($res['body_text']??'')) ?></textarea>
            <div class="field__hint">Each line prints as a numbered "BE IT RESOLVED THAT…" clause.</div>
        </div>
        <?php if ($nbItems): ?>
        <div class="field">
            <label class="field__label">Related agenda item</label>
            <select class="select" name="agenda_item_id">
                <option value="">— none —</option>
                <?php foreach ($nbItems as $ni): ?>
                    <option value="<?= (int)$ni['id'] ?>" <?= (int)$res['agenda_item_id']===(int)$ni['id']?'selected':'' ?>><?= e((string)$ni['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label">Moved by</label>
                <select class="select" name="moved_by">
                    <option value="">— select —</option>
                    <?php foreach ($boardMembers as $bm): ?>
                        <option value="<?= (int)$bm['id'] ?>" <?= (int)$res['moved_by_user_id']===(int)$bm['id']?'selected':'' ?>><?= e(board_label($bm)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label">Seconded by</label>
                <select class="select" name="seconded_by">
                    <option value="">— select —</option>
                    <?php foreach ($boardMembers as $bm): ?>
                        <option value="<?= (int)$bm['id'] ?>" <?= (int)$res['seconded_by_user_id']===(int)$bm['id']?'selected':'' ?>><?= e(board_label($bm)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field">
            <label class="field__label">Votes</label>
            <div style="border:1px solid var(--color-border);border-radius:var(--r-md);overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;font-size:var(--fs-sm);">
                    <thead>
                        <tr style="background:var(--color-bg);">
                            <th style="text-align:left;padding:var(--sp-2) var(--sp-3);">Board member</th>
                            <th style="padding:var(--sp-2) var(--sp-3);text-align:center;color:var(--color-success,#16a34a);">Yes</th>
                            <th style="padding:var(--sp-2) var(--sp-3);text-align:center;color:var(--color-error);">No</th>
                            <th style="padding:var(--sp-2) var(--sp-3);text-align:center;color:var(--color-text-muted);">Abstain</th>
                            <th style="padding:var(--sp-2) var(--sp-3);text-align:center;color:var(--color-text-muted);">Not present</th>
                            <th style="padding:var(--sp-2) var(--sp-3);text-align:center;color:var(--color-text-muted);">N/A</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($boardMembers as $bm): ?>
                        <?php $curVote = $votes[(int)$bm['id']] ?? ''; ?>
                        <tr style="border-top:1px solid var(--color-border);">
                            <td style="padding:var(--sp-2) var(--sp-3);"><?= e(board_label($bm)) ?></td>
                            <?php foreach (['yes','no','abstain','not_present','na',''] as $vv): ?>
                            <td style="text-align:center;padding:var(--sp-2);">
                                <input type="radio" name="vote_<?= (int)$bm['id'] ?>"
                                       value="<?= $vv ?>" <?= $curVote===$vv?'checked':'' ?>
                                       style="accent-color:var(--color-primary);width:16px;height:16px;">
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="field">
            <label class="field__label">Result notes</label>
            <textarea class="textarea" name="result_notes" rows="2"
                      placeholder="Any notes about the outcome…"><?= e((string)($res['result_notes']??'')) ?></textarea>
        </div>
        <div class="row" style="justify-content:flex-end;gap:var(--sp-2);">
            <a class="btn btn--ghost" href="?id=<?= $mid ?>&tab=resolutions">Cancel</a>
            <button class="btn btn--primary" type="submit">Save</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php /* ===== TAB: NOTICE & PRINT ===== */ elseif ($tab === 'notice'): ?>

<h2 style="font-size:var(--fs-xl);margin:0 0 var(--sp-4);">Notice & Print</h2>

<!-- Meeting info card -->
<div class="card card--padded" style="margin-bottom:var(--sp-5);">
    <div class="row row--between" style="margin-bottom:var(--sp-3);">
        <strong>Meeting details</strong>
        <?php if ($canManage): ?>
        <a href="?id=<?= $mid ?>&tab=notice&edit_info=1" class="btn btn--ghost" style="font-size:var(--fs-xs);">Edit</a>
        <?php endif; ?>
    </div>
    <?php $editInfo = !empty($_GET['edit_info']) && $canManage; ?>
    <?php if ($editInfo): ?>
    <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="update_meeting">
        <div class="form-row form-row--3">
            <div class="field">
                <label class="field__label">Date</label>
                <input class="input" type="date" name="meeting_date" required value="<?= e((string)$meeting['meeting_date']) ?>">
            </div>
            <div class="field">
                <label class="field__label">Time</label>
                <input class="input" type="time" name="meeting_time" value="<?= e((string)($meeting['meeting_time']??'')) ?>">
            </div>
            <div class="field">
                <label class="field__label">Type</label>
                <select class="select" name="meeting_type">
                    <?php foreach ($MEETING_TYPES as $v=>$l): ?>
                        <option value="<?= e($v) ?>" <?= $meeting['meeting_type']===$v?'selected':'' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row form-row--2">
            <div class="field">
                <label class="field__label">Title</label>
                <input class="input" name="title" required value="<?= e((string)$meeting['title']) ?>">
            </div>
            <div class="field">
                <label class="field__label">Location</label>
                <input class="input" name="location" value="<?= e((string)($meeting['location']??'')) ?>">
            </div>
        </div>
        <div class="field">
            <label class="field__label">Virtual platform</label>
            <select class="select" name="virtual_platform" id="vplat-sel" onchange="toggleVirtual(this.value)">
                <option value="none" <?= ($meeting['virtual_platform']??'none')==='none'?'selected':'' ?>>In-person only</option>
                <option value="zoom" <?= ($meeting['virtual_platform']??'')==='zoom'?'selected':'' ?>>Zoom</option>
                <option value="google_meet" <?= ($meeting['virtual_platform']??'')==='google_meet'?'selected':'' ?>>Google Meet</option>
                <option value="teams" <?= ($meeting['virtual_platform']??'')==='teams'?'selected':'' ?>>Microsoft Teams</option>
                <option value="webex" <?= ($meeting['virtual_platform']??'')==='webex'?'selected':'' ?>>Webex</option>
                <option value="other" <?= ($meeting['virtual_platform']??'')==='other'?'selected':'' ?>>Other</option>
            </select>
        </div>
        <div id="virtual-fields" style="display:<?= ($meeting['virtual_platform']??'none')!=='none'?'block':'none' ?>;">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label">Join URL</label>
                    <input class="input" name="virtual_url" value="<?= e((string)($meeting['virtual_url']??'')) ?>" placeholder="https://zoom.us/j/...">
                </div>
                <div class="field">
                    <label class="field__label">Meeting ID</label>
                    <input class="input" name="virtual_meeting_id" value="<?= e((string)($meeting['virtual_meeting_id']??'')) ?>">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label">Passcode</label>
                    <input class="input" name="virtual_passcode" value="<?= e((string)($meeting['virtual_passcode']??'')) ?>">
                </div>
                <div class="field">
                    <label class="field__label">Dial-in phone numbers <span class="muted" style="font-weight:400;">(one per line)</span></label>
                    <textarea class="textarea" name="virtual_phone_numbers" rows="2"><?= e((string)($meeting['virtual_phone_numbers']??'')) ?></textarea>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label">SIP address</label>
                    <input class="input" name="virtual_sip" value="<?= e((string)($meeting['virtual_sip']??'')) ?>">
                </div>
                <div class="field">
                    <label class="field__label">Additional notes</label>
                    <textarea class="textarea" name="virtual_notes" rows="2"><?= e((string)($meeting['virtual_notes']??'')) ?></textarea>
                </div>
            </div>
        </div>
        <div class="row" style="justify-content:flex-end;gap:var(--sp-2);">
            <a class="btn btn--ghost" href="?id=<?= $mid ?>&tab=notice">Cancel</a>
            <button class="btn btn--primary" type="submit">Save details</button>
        </div>
    </form>
    <script>function toggleVirtual(v){document.getElementById('virtual-fields').style.display=v!=='none'?'block':'none';}</script>
    <?php else: ?>
    <dl style="display:grid;grid-template-columns:auto 1fr;gap:var(--sp-1) var(--sp-4);font-size:var(--fs-sm);">
        <dt class="muted">Date</dt><dd><?= e(udate('l, F j, Y', strtotime((string)$meeting['meeting_date']))) ?></dd>
        <dt class="muted">Time</dt><dd><?= !empty($meeting['meeting_time']) ? e(date('g:i A', strtotime((string)$meeting['meeting_time']))) : '—' ?></dd>
        <dt class="muted">Location</dt><dd><?= !empty($meeting['location']) ? e((string)$meeting['location']) : '—' ?></dd>
        <dt class="muted">Virtual</dt><dd><?= !empty($meeting['virtual_url']) ? '<a href="'.e((string)$meeting['virtual_url']).'" target="_blank" rel="noopener">'.e(ucfirst((string)$meeting['virtual_platform'])).' link</a>' : 'None' ?></dd>
    </dl>
    <?php endif; ?>
</div>

<!-- Proof of notice data -->
<div class="card card--padded" style="margin-bottom:var(--sp-5);">
    <h3 style="font-size:var(--fs-lg);margin:0 0 var(--sp-3);">Proof of Notice — affidavit data</h3>
    <p class="muted" style="font-size:var(--fs-sm);margin-bottom:var(--sp-3);">This data pre-fills the printable Proof of Notice affidavit. The notary seal and signatures must be added to the printed document.</p>
    <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="update_proof">
        <div class="form-row form-row--3">
            <div class="field">
                <label class="field__label">State</label>
                <input class="input" name="proof_state" value="<?= e((string)($meeting['proof_state']??'Florida')) ?>">
            </div>
            <div class="field">
                <label class="field__label">County</label>
                <input class="input" name="proof_county" placeholder="e.g. Volusia"
                       value="<?= e((string)($meeting['proof_county']??'')) ?>">
            </div>
            <div class="field">
                <label class="field__label">Signed by (President)</label>
                <select class="select" name="proof_signed_by">
                    <option value="">— select —</option>
                    <?php foreach ($boardMembers as $bm): ?>
                        <option value="<?= (int)$bm['id'] ?>" <?= (int)($meeting['proof_signed_by_user_id']??0)===(int)$bm['id']?'selected':'' ?>>
                            <?= e(board_label($bm)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row form-row--3">
            <div class="field">
                <label class="field__label">Affidavit date</label>
                <input class="input" type="date" name="proof_signed_date"
                       value="<?= e((string)($meeting['proof_signed_date']??'')) ?>">
            </div>
            <div class="field">
                <label class="field__label">Notary name</label>
                <input class="input" name="proof_notary_name" placeholder="e.g. Mark Edward Applegate"
                       value="<?= e((string)($meeting['proof_notary_name']??'')) ?>">
            </div>
            <div class="field">
                <label class="field__label">Notary commission #</label>
                <input class="input" name="proof_notary_commission" placeholder="e.g. HH 728203"
                       value="<?= e((string)($meeting['proof_notary_commission']??'')) ?>">
            </div>
        </div>
        <div class="field" style="max-width:200px;">
            <label class="field__label">Commission expires</label>
            <input class="input" type="date" name="proof_notary_expires"
                   value="<?= e((string)($meeting['proof_notary_expires']??'')) ?>">
        </div>
        <div class="row" style="justify-content:flex-end;gap:var(--sp-2);">
            <button class="btn btn--primary" type="submit">Save proof data</button>
        </div>
    </form>
</div>

<!-- Status actions -->
<?php if ($canManage): ?>
<div class="card card--padded" style="margin-bottom:var(--sp-5);">
    <h3 style="font-size:var(--fs-lg);margin:0 0 var(--sp-3);">Workflow</h3>
    <div class="row" style="gap:var(--sp-3);flex-wrap:wrap;align-items:center;">
        <?php if ((string)$meeting['status'] === 'draft'): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="mark_notice_posted">
                <button class="btn btn--primary" type="submit">Mark notice as posted</button>
            </form>
            <p class="muted" style="font-size:var(--fs-sm);margin:0;">Confirms the notice was posted at least 48 hours before the meeting per FL §718.112(2)(d).</p>
        <?php elseif ((string)$meeting['status'] === 'notice_posted'): ?>
            <div>
                <span class="badge badge--info">Notice posted</span>
                <?php if (!empty($meeting['notice_posted_at'])): ?>
                    <span class="muted" style="font-size:var(--fs-sm);margin-left:var(--sp-2);"><?= e(udate('M j, Y g:i A', strtotime((string)$meeting['notice_posted_at']))) ?></span>
                <?php endif; ?>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="mark_completed">
                <button class="btn btn--ghost" type="submit">Mark as completed</button>
            </form>
        <?php elseif ((string)$meeting['status'] === 'completed'): ?>
            <span class="badge badge--success">Meeting completed</span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Print buttons -->
<div class="card card--padded">
    <h3 style="font-size:var(--fs-lg);margin:0 0 var(--sp-3);">Print / download</h3>
    <div class="row" style="gap:var(--sp-2);flex-wrap:wrap;">
        <a class="btn btn--ghost" href="/dashboard/meeting-print.php?id=<?= $mid ?>&type=notice" target="_blank">Notice of Board Meeting</a>
        <a class="btn btn--ghost" href="/dashboard/meeting-print.php?id=<?= $mid ?>&type=proof" target="_blank">Proof of Notice Affidavit</a>
        <a class="btn btn--ghost" href="/dashboard/meeting-print.php?id=<?= $mid ?>&type=agenda" target="_blank">Agenda</a>
        <a class="btn btn--ghost" href="/dashboard/meeting-print.php?id=<?= $mid ?>&type=resolutions" target="_blank">Resolutions</a>
        <a class="btn btn--primary" href="/dashboard/meeting-print.php?id=<?= $mid ?>&type=package" target="_blank">Full package (all 4)</a>
    </div>
</div>

<?php /* ===== TAB: MINUTES ===== */ elseif ($tab === 'minutes'): ?>

<h2 style="font-size:var(--fs-xl);margin:0 0 var(--sp-4);">Meeting Minutes</h2>

<?php if ($linkedMinutes): ?>
    <div class="stack-sm" style="margin-bottom:var(--sp-5);">
    <?php foreach ($linkedMinutes as $lm): ?>
    <div class="card card--padded row row--between" style="align-items:center;flex-wrap:wrap;gap:var(--sp-2);">
        <div>
            <a href="/dashboard/minutes.php?id=<?= (int)$lm['id'] ?>" style="font-weight:600;"><?= e((string)$lm['title']) ?></a>
            <div class="muted" style="font-size:var(--fs-sm);"><?= e(udate('F j, Y', strtotime((string)$lm['meeting_date']))) ?></div>
        </div>
        <?php if ($canManage): ?>
        <form method="post" style="display:inline;" onsubmit="return confirm('Unlink these minutes?');">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="unlink_minutes">
            <input type="hidden" name="minutes_id" value="<?= (int)$lm['id'] ?>">
            <button class="btn btn--ghost" type="submit" style="font-size:var(--fs-xs);color:var(--color-text-muted);">Unlink</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card card--padded center" style="padding:var(--sp-8) var(--sp-6);margin-bottom:var(--sp-4);">
        <p class="muted">No minutes linked to this meeting yet.</p>
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="card card--padded" style="margin-bottom:var(--sp-4);">
    <h3 style="font-size:var(--fs-lg);margin:0 0 var(--sp-3);">Create or link minutes</h3>
    <div class="row" style="gap:var(--sp-2);margin-bottom:var(--sp-3);">
        <?php
        $newUrl = '/dashboard/minutes.php?action=new';
        if (!empty($meeting['meeting_date'])) $newUrl .= '&prefill_date=' . urlencode((string)$meeting['meeting_date']);
        ?>
        <a class="btn btn--primary" href="<?= e($newUrl) ?>">+ Record new minutes</a>
    </div>
    <?php if ($availMinutes): ?>
    <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="link_minutes">
        <div class="field">
            <label class="field__label">Or link existing minutes</label>
            <div class="row" style="gap:var(--sp-2);">
                <select class="select" name="minutes_id" style="flex:1;">
                    <option value="">— select —</option>
                    <?php foreach ($availMinutes as $am): ?>
                        <?php $alreadyLinked = (int)($am['board_meeting_id'] ?? 0) === $mid; ?>
                        <option value="<?= (int)$am['id'] ?>" <?= $alreadyLinked?'selected':'' ?>>
                            <?= e((string)$am['title']) ?> (<?= e(udate('M j, Y', strtotime((string)$am['meeting_date']))) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn--ghost" type="submit">Link</button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; /* end tabs */ ?>

</div><!-- /container -->

<script>
function moveItem(id, dir) {
    var list = document.getElementById('agenda-list');
    if (!list) return;
    var items = Array.from(list.querySelectorAll('[data-item-id]'));
    var idx = items.findIndex(function(el) { return +el.dataset.itemId === id; });
    if (idx < 0) return;

    // Skip over locked standard items (no move buttons) when looking for swap target.
    var target = idx + dir;
    while (target >= 0 && target < items.length) {
        if (items[target].querySelector('button[onclick*="moveItem"]')) break;
        target += dir;
    }
    if (target < 0 || target >= items.length) return;

    // Swap in the DOM.
    if (dir < 0) {
        list.insertBefore(items[idx], items[target]);
    } else {
        list.insertBefore(items[target], items[idx]);
    }

    // Collect new ID order and POST to server.
    var newOrder = Array.from(list.querySelectorAll('[data-item-id]'))
        .map(function(el) { return +el.dataset.itemId; });
    var idsDiv = document.getElementById('reorder-ids');
    idsDiv.innerHTML = newOrder
        .map(function(i) { return '<input type="hidden" name="order[]" value="' + i + '">'; })
        .join('');

    var fd = new FormData(document.getElementById('reorder-form'));
    fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd })
        .catch(function() { location.reload(); });
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
