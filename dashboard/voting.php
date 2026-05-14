<?php
require __DIR__ . '/_bootstrap.php';

// Renters and staff don't vote in owner elections.
if (in_array(viewing_role(), ['renter', 'staff'], true)) {
    redirect('/dashboard/');
}

$user      = current_user();
$uid       = (int)$user['id'];
$canManage = role_can_manage(viewing_role());
$action    = $_GET['action'] ?? 'list';
$voteId    = (int)($_GET['id'] ?? 0);
$flashError = null;

// Eligible roles: any non-renter, non-staff member
$eligibleRoles = ['owner','board_member','board_admin','property_manager','super_admin'];

// ──────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────
function vote_is_open(array $v): bool {
    if ($v['status'] !== 'active') return false;
    $now = time();
    if (!empty($v['starts_at']) && strtotime((string)$v['starts_at']) > $now) return false;
    if (!empty($v['ends_at'])   && strtotime((string)$v['ends_at'])   < $now) return false;
    return true;
}

function vote_can_see_results(array $v, bool $canManage): bool {
    if ($canManage) return true;
    if ($v['results_visible'] === 'board_only') return false;
    if ($v['results_visible'] === 'always') return true;
    // after_close: results visible once closed or past end date
    if ($v['status'] === 'closed') return true;
    if (!empty($v['ends_at']) && strtotime((string)$v['ends_at']) < time()) return true;
    return false;
}

function eligible_voter_count(int $assocId): int {
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM users
          WHERE association_id = ?
            AND role IN ('owner','board_member','board_admin','property_manager')"
    );
    $stmt->execute([$assocId]);
    return (int)$stmt->fetchColumn();
}

// ──────────────────────────────────────────────────────────────────────────
// POST: Save ballot (create or update)
// ──────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save_ballot') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    $bid        = (int)($_POST['vote_id'] ?? 0);
    $title      = trim((string)($_POST['title'] ?? ''));
    $desc       = trim((string)($_POST['description'] ?? ''));
    $type       = (string)($_POST['type'] ?? 'general_motion');
    $anon       = isset($_POST['anonymous']) ? 1 : 0;
    $abstain    = isset($_POST['allow_abstain']) ? 1 : 0;
    $quorum     = $_POST['quorum_pct'] !== '' ? (float)$_POST['quorum_pct'] : null;
    $startsAt   = $_POST['starts_at'] !== '' ? (string)$_POST['starts_at'] : null;
    $endsAt     = $_POST['ends_at']   !== '' ? (string)$_POST['ends_at']   : null;
    $resVisible = (string)($_POST['results_visible'] ?? 'after_close');

    $validTypes   = ['election','bylaw_amendment','budget_approval','general_motion','survey'];
    $validVisible = ['always','after_close','board_only'];
    if (!in_array($type, $validTypes, true))   $type = 'general_motion';
    if (!in_array($resVisible, $validVisible, true)) $resVisible = 'after_close';

    if ($title === '') { $flashError = 'Title is required.'; }

    if (!$flashError) {
        if ($bid > 0) {
            // Update — only drafts can be fully edited; active/closed can't change questions.
            $existing = db()->prepare('SELECT status FROM votes WHERE id = ? AND association_id = ?');
            $existing->execute([$bid, $assocId]);
            $existRow = $existing->fetch();
            if (!$existRow) { http_response_code(404); die('Not found.'); }

            db()->prepare(
                'UPDATE votes SET title=?, description=?, type=?, anonymous=?, allow_abstain=?,
                 quorum_pct=?, starts_at=?, ends_at=?, results_visible=?, updated_at=NOW()
                 WHERE id=? AND association_id=?'
            )->execute([$title, $desc ?: null, $type, $anon, $abstain,
                        $quorum, $startsAt, $endsAt, $resVisible, $bid, $assocId]);

            // Only rebuild questions for drafts.
            if ($existRow['status'] === 'draft') {
                _save_questions($bid);
            }
            audit('vote.updated', ['title' => $title], $bid, 'vote');
            flash('success', 'Ballot saved.');
            redirect('/dashboard/voting.php?action=edit&id=' . $bid);
        } else {
            // Create new draft
            db()->prepare(
                'INSERT INTO votes (association_id, title, description, type, anonymous, allow_abstain,
                 quorum_pct, starts_at, ends_at, results_visible, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$assocId, $title, $desc ?: null, $type, $anon, $abstain,
                        $quorum, $startsAt, $endsAt, $resVisible, $uid]);
            $newId = (int)db()->lastInsertId();
            _save_questions($newId);
            audit('vote.created', ['title' => $title], $newId, 'vote');
            flash('success', 'Ballot created. Add questions below, then publish when ready.');
            redirect('/dashboard/voting.php?action=edit&id=' . $newId);
        }
    }
}

function _save_questions(int $vid): void {
    // Wipe existing questions and re-insert from POST.
    db()->prepare('DELETE FROM vote_questions WHERE vote_id = ?')->execute([$vid]);

    $texts    = $_POST['q_text']     ?? [];
    $types    = $_POST['q_type']     ?? [];
    $required = $_POST['q_required'] ?? [];

    foreach ($texts as $i => $qtext) {
        $qtext = trim((string)$qtext);
        if ($qtext === '') continue;
        $qtype = in_array($types[$i] ?? '', ['yes_no','multiple_choice','text'], true)
               ? $types[$i] : 'yes_no';
        $req   = isset($required[$i]) ? 1 : 0;

        db()->prepare(
            'INSERT INTO vote_questions (vote_id, sort_order, question, type, required)
             VALUES (?,?,?,?,?)'
        )->execute([$vid, (int)$i, $qtext, $qtype, $req]);
        $qid = (int)db()->lastInsertId();

        // Options for multiple_choice
        if ($qtype === 'multiple_choice') {
            $opts = $_POST['q_options'][$i] ?? [];
            $order = 0;
            foreach ($opts as $opt) {
                $opt = trim((string)$opt);
                if ($opt === '') continue;
                db()->prepare(
                    'INSERT INTO vote_options (question_id, sort_order, option_text) VALUES (?,?,?)'
                )->execute([$qid, $order++, $opt]);
            }
        }
    }
}

// ──────────────────────────────────────────────────────────────────────────
// POST: Publish ballot
// ──────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'publish_ballot') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $bid = (int)($_POST['id'] ?? 0);

    $qCount = db()->prepare('SELECT COUNT(*) FROM vote_questions WHERE vote_id = ?');
    $qCount->execute([$bid]);
    if ((int)$qCount->fetchColumn() === 0) {
        flash('error', 'Add at least one question before publishing.');
        redirect('/dashboard/voting.php?action=edit&id=' . $bid);
    }

    db()->prepare('UPDATE votes SET status=? WHERE id=? AND association_id=? AND status=?')
        ->execute(['active', $bid, $assocId, 'draft']);
    audit('vote.published', [], $bid, 'vote');
    flash('success', 'Ballot is now live. Owners can vote.');
    redirect('/dashboard/voting.php?action=results&id=' . $bid);
}

// ──────────────────────────────────────────────────────────────────────────
// POST: Close ballot
// ──────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'close_ballot') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $bid = (int)($_POST['id'] ?? 0);
    db()->prepare('UPDATE votes SET status=? WHERE id=? AND association_id=?')
        ->execute(['closed', $bid, $assocId]);
    audit('vote.closed', [], $bid, 'vote');
    flash('success', 'Ballot closed. Results are now final.');
    redirect('/dashboard/voting.php?action=results&id=' . $bid);
}

// ──────────────────────────────────────────────────────────────────────────
// POST: Delete ballot (draft only)
// ──────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_ballot') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $bid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM votes WHERE id=? AND association_id=? AND status=?')
        ->execute([$bid, $assocId, 'draft']);
    audit('vote.deleted', [], $bid, 'vote');
    flash('success', 'Draft ballot deleted.');
    redirect('/dashboard/voting.php');
}

// ──────────────────────────────────────────────────────────────────────────
// POST: Archive / unarchive ballot
// ──────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['form'] ?? '', ['archive_ballot','unarchive_ballot'], true)) {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $bid      = (int)($_POST['id'] ?? 0);
    $archiving = ($_POST['form'] === 'archive_ballot');
    db()->prepare('UPDATE votes SET archived=? WHERE id=? AND association_id=?')
        ->execute([$archiving ? 1 : 0, $bid, $assocId]);
    audit($archiving ? 'vote.archived' : 'vote.unarchived', [], $bid, 'vote');
    flash('success', $archiving ? 'Ballot archived.' : 'Ballot restored.');
    redirect('/dashboard/voting.php');
}

// ──────────────────────────────────────────────────────────────────────────
// POST: Submit vote
// ──────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'submit_vote') {
    csrf_check();
    $bid = (int)($_POST['vote_id'] ?? 0);

    // Load the ballot
    $bStmt = db()->prepare('SELECT * FROM votes WHERE id=? AND association_id=?');
    $bStmt->execute([$bid, $assocId]);
    $ballot = $bStmt->fetch();
    if (!$ballot || !vote_is_open($ballot)) {
        flash('error', 'This ballot is not currently open.');
        redirect('/dashboard/voting.php');
    }

    // Already voted?
    $dupCheck = db()->prepare('SELECT 1 FROM vote_participants WHERE vote_id=? AND user_id=?');
    $dupCheck->execute([$bid, $uid]);
    if ($dupCheck->fetchColumn()) {
        flash('error', 'You have already voted on this ballot.');
        redirect('/dashboard/voting.php?action=results&id=' . $bid);
    }

    // Load questions
    $qStmt = db()->prepare(
        'SELECT q.*, GROUP_CONCAT(o.id ORDER BY o.sort_order SEPARATOR ",") AS option_ids,
                GROUP_CONCAT(o.option_text ORDER BY o.sort_order SEPARATOR "||") AS option_texts
           FROM vote_questions q
           LEFT JOIN vote_options o ON o.question_id = q.id
          WHERE q.vote_id = ?
          GROUP BY q.id
          ORDER BY q.sort_order'
    );
    $qStmt->execute([$bid]);
    $questions = $qStmt->fetchAll();

    // Validate and collect answers
    $answers = [];
    $answers_text = [];
    $hasError = false;
    foreach ($questions as $q) {
        $qid   = (int)$q['id'];
        $ans   = $_POST['answer'][$qid] ?? null;
        if ($q['required'] && ($ans === null || trim((string)$ans) === '')) {
            $flashError = 'Please answer all required questions.';
            $hasError = true;
            break;
        }
        $answers[$qid] = $ans;
    }

    if (!$hasError) {
        db()->beginTransaction();
        try {
            // Record participation (dedup key)
            db()->prepare('INSERT INTO vote_participants (vote_id, user_id) VALUES (?,?)')
                ->execute([$bid, $uid]);

            // Record each answer
            foreach ($questions as $q) {
                $qid = (int)$q['id'];
                $ans = $answers[$qid] ?? null;
                if ($ans === null) continue;

                $userId = (int)$ballot['anonymous'] === 0 ? $uid : null;

                if ($q['type'] === 'yes_no') {
                    // answer is 'yes', 'no', or 'abstain'
                    db()->prepare(
                        'INSERT INTO vote_responses (vote_id, question_id, user_id, answer_text) VALUES (?,?,?,?)'
                    )->execute([$bid, $qid, $userId, $ans]);
                } elseif ($q['type'] === 'multiple_choice') {
                    // answer is option_id
                    db()->prepare(
                        'INSERT INTO vote_responses (vote_id, question_id, user_id, answer_option_id) VALUES (?,?,?,?)'
                    )->execute([$bid, $qid, $userId, (int)$ans]);
                } else {
                    // text
                    db()->prepare(
                        'INSERT INTO vote_responses (vote_id, question_id, user_id, answer_text) VALUES (?,?,?,?)'
                    )->execute([$bid, $qid, $userId, trim((string)$ans)]);
                }
            }
            db()->commit();
            audit('vote.cast', ['ballot_id' => $bid, 'anonymous' => (bool)$ballot['anonymous']], $bid, 'vote');
            flash('success', '✅ Your vote has been recorded. Thank you for participating.');
            redirect('/dashboard/voting.php?action=results&id=' . $bid);
        } catch (Throwable $e) {
            db()->rollBack();
            $flashError = 'Could not save your vote. Please try again.';
        }
    }
    // On error fall through to re-render the vote form
    $action = 'vote';
    $voteId = $bid;
}

// ──────────────────────────────────────────────────────────────────────────
// Data loading
// ──────────────────────────────────────────────────────────────────────────

// For list view
$allVotes = [];
if ($action === 'list') {
    $lStmt = db()->prepare(
        'SELECT v.*,
                (SELECT COUNT(*) FROM vote_participants vp WHERE vp.vote_id = v.id) AS participant_count,
                (SELECT COUNT(*) FROM vote_questions   vq WHERE vq.vote_id = v.id) AS question_count
           FROM votes v
          WHERE v.association_id = ?
          ORDER BY v.archived ASC, FIELD(v.status,"active","draft","closed"), v.created_at DESC'
    );
    $lStmt->execute([$assocId]);
    $allVotes = $lStmt->fetchAll();

    // Mark which ones the current user has voted on
    $myVotes = [];
    if ($allVotes) {
        $ids = implode(',', array_map(fn($v) => (int)$v['id'], $allVotes));
        $mvStmt = db()->query(
            "SELECT vote_id FROM vote_participants WHERE vote_id IN ($ids) AND user_id = $uid"
        );
        foreach ($mvStmt->fetchAll() as $r) $myVotes[(int)$r['vote_id']] = true;
    }
    $eligibleCount = eligible_voter_count($assocId);
}

// For create/edit view
$editVote      = null;
$editQuestions = [];
if (in_array($action, ['create','edit'], true) && $canManage) {
    if ($voteId > 0) {
        $evStmt = db()->prepare('SELECT * FROM votes WHERE id=? AND association_id=?');
        $evStmt->execute([$voteId, $assocId]);
        $editVote = $evStmt->fetch();
        if (!$editVote) { http_response_code(404); die('Ballot not found.'); }

        $eqStmt = db()->prepare(
            'SELECT q.*, GROUP_CONCAT(o.id ORDER BY o.sort_order SEPARATOR ",") AS option_ids,
                    GROUP_CONCAT(o.option_text ORDER BY o.sort_order SEPARATOR "||") AS option_texts
               FROM vote_questions q
               LEFT JOIN vote_options o ON o.question_id = q.id
              WHERE q.vote_id = ?
              GROUP BY q.id ORDER BY q.sort_order'
        );
        $eqStmt->execute([$voteId]);
        $editQuestions = $eqStmt->fetchAll();
    }
}

// For cast-vote view
$castBallot    = null;
$castQuestions = [];
$alreadyVoted  = false;
if ($action === 'vote' && $voteId > 0) {
    $cvStmt = db()->prepare('SELECT * FROM votes WHERE id=? AND association_id=?');
    $cvStmt->execute([$voteId, $assocId]);
    $castBallot = $cvStmt->fetch();
    if (!$castBallot) { http_response_code(404); die('Ballot not found.'); }
    if (!vote_is_open($castBallot)) { redirect('/dashboard/voting.php?action=results&id=' . $voteId); }

    $avCheck = db()->prepare('SELECT 1 FROM vote_participants WHERE vote_id=? AND user_id=?');
    $avCheck->execute([$voteId, $uid]);
    $alreadyVoted = (bool)$avCheck->fetchColumn();
    if ($alreadyVoted) { redirect('/dashboard/voting.php?action=results&id=' . $voteId); }

    $cvqStmt = db()->prepare(
        'SELECT q.*, GROUP_CONCAT(o.id ORDER BY o.sort_order SEPARATOR ",") AS option_ids,
                GROUP_CONCAT(o.option_text ORDER BY o.sort_order SEPARATOR "||") AS option_texts
           FROM vote_questions q
           LEFT JOIN vote_options o ON o.question_id = q.id
          WHERE q.vote_id = ?
          GROUP BY q.id ORDER BY q.sort_order'
    );
    $cvqStmt->execute([$voteId]);
    $castQuestions = $cvqStmt->fetchAll();
    $castEligible  = eligible_voter_count($assocId);
    $castVoted     = (int)db()->prepare('SELECT COUNT(*) FROM vote_participants WHERE vote_id=?')
                               ->execute([$voteId]) ? (function($vid){
        $s=db()->prepare('SELECT COUNT(*) FROM vote_participants WHERE vote_id=?');
        $s->execute([$vid]); return (int)$s->fetchColumn();
    })($voteId) : 0;
}

// For results view
$resultsBallot    = null;
$resultsQuestions = [];
$resultsVoted     = 0;
$resultsEligible  = 0;
$myParticipated   = false;
if ($action === 'results' && $voteId > 0) {
    $rvStmt = db()->prepare('SELECT * FROM votes WHERE id=? AND association_id=?');
    $rvStmt->execute([$voteId, $assocId]);
    $resultsBallot = $rvStmt->fetch();
    if (!$resultsBallot) { http_response_code(404); die('Ballot not found.'); }

    if (!vote_can_see_results($resultsBallot, $canManage)) {
        // Can vote but not see results yet
        if (vote_is_open($resultsBallot)) redirect('/dashboard/voting.php?action=vote&id=' . $voteId);
        // Closed but board_only — show a placeholder
    }

    $resultsEligible = eligible_voter_count($assocId);
    $rvpStmt = db()->prepare('SELECT COUNT(*) FROM vote_participants WHERE vote_id=?');
    $rvpStmt->execute([$voteId]);
    $resultsVoted = (int)$rvpStmt->fetchColumn();

    $mpStmt = db()->prepare('SELECT 1 FROM vote_participants WHERE vote_id=? AND user_id=?');
    $mpStmt->execute([$voteId, $uid]);
    $myParticipated = (bool)$mpStmt->fetchColumn();

    // Load questions with results
    $rqStmt = db()->prepare(
        'SELECT q.*, GROUP_CONCAT(o.id ORDER BY o.sort_order SEPARATOR ",") AS option_ids,
                GROUP_CONCAT(o.option_text ORDER BY o.sort_order SEPARATOR "||") AS option_texts
           FROM vote_questions q
           LEFT JOIN vote_options o ON o.question_id = q.id
          WHERE q.vote_id = ?
          GROUP BY q.id ORDER BY q.sort_order'
    );
    $rqStmt->execute([$voteId]);
    $resultsQuestions = $rqStmt->fetchAll();
}

$page_title = 'Voting — ' . $association['name'];
$active     = 'voting';
require __DIR__ . '/../includes/header.php';
?>

<style>
/* ── voting module styles ── */
.ballot-card { border: 1px solid var(--color-border); border-radius: var(--r-lg); padding: var(--sp-5); background: #fff; }
.ballot-card + .ballot-card { margin-top: var(--sp-4); }
.ballot-card__head { display:flex; align-items:flex-start; gap: var(--sp-4); }
.ballot-card__body { margin-top: var(--sp-3); }
.ballot-card__actions { display:flex; gap: var(--sp-2); margin-top: var(--sp-4); flex-wrap:wrap; }
.ballot-status { display:inline-block; padding: 3px 10px; border-radius: 999px; font-size: var(--fs-xs); font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
.ballot-status--active { background:#dcfce7; color:#166534; }
.ballot-status--draft  { background:#fef9c3; color:#854d0e; }
.ballot-status--closed { background:#f1f5f9; color:#475569; }
.ballot-icon { font-size:32px; flex-shrink:0; }
.quorum-bar { height:10px; background: var(--color-surface-2); border-radius:99px; overflow:hidden; margin: var(--sp-2) 0; }
.quorum-bar__fill { height:100%; background: var(--color-orange); border-radius:99px; transition: width .4s ease; }
.quorum-bar__fill--met { background: #16a34a; }
.q-block { border: 1px solid var(--color-border); border-radius: var(--r-md); padding: var(--sp-4); margin-bottom: var(--sp-3); background: var(--color-surface); position:relative; }
.q-block__label { font-weight:700; font-size: var(--fs-sm); margin-bottom: var(--sp-2); }
.vote-option { display:flex; align-items:center; gap: var(--sp-3); padding: var(--sp-3) var(--sp-4); border: 2px solid var(--color-border); border-radius: var(--r-md); cursor:pointer; margin-bottom: var(--sp-2); transition: border-color .15s, background .15s; }
.vote-option:hover { border-color: var(--color-orange); background: #fffbf5; }
.vote-option input[type="radio"] { accent-color: var(--color-orange); width:18px; height:18px; flex-shrink:0; }
.vote-option--yes  { --opt-color: #16a34a; }
.vote-option--no   { --opt-color: #dc2626; }
.vote-option--abs  { --opt-color: #64748b; }
.result-bar { height:28px; background: var(--color-surface-2); border-radius: var(--r-sm); overflow:hidden; margin-bottom: var(--sp-1); position:relative; }
.result-bar__fill { height:100%; background: var(--color-orange); border-radius: var(--r-sm); display:flex; align-items:center; padding: 0 var(--sp-3); color:#fff; font-size: var(--fs-xs); font-weight:700; transition: width .5s ease; }
.result-bar__fill--yes { background: #16a34a; }
.result-bar__fill--no  { background: #dc2626; }
.result-bar__fill--abs { background: #94a3b8; }
.builder-add { border: 2px dashed var(--color-border); border-radius: var(--r-md); padding: var(--sp-4); text-align:center; cursor:pointer; color: var(--color-text-soft); background: var(--color-surface); transition: border-color .15s; }
.builder-add:hover { border-color: var(--color-orange); color: var(--color-orange); }
</style>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 900px;">

<?php if ($flashError): ?>
    <div class="flash flash--error" style="margin-bottom: var(--sp-5);"><?= e($flashError) ?></div>
<?php endif; ?>

<?php // ═══════════════════════════════════════════════════════════════════
      // LIST VIEW
      // ═══════════════════════════════════════════════════════════════════
if ($action === 'list'): ?>

    <div class="row row--between" style="margin-bottom: var(--sp-6); flex-wrap:wrap; gap: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin:0 0 var(--sp-1);">Voting</h1>
            <p class="muted" style="margin:0;">Owner ballots and community decisions.</p>
        </div>
        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="?action=create">+ New ballot</a>
        <?php endif; ?>
    </div>

    <?php if (!$allVotes): ?>
        <div class="card card--padded center" style="padding: var(--sp-12);">
            <div style="font-size:48px; margin-bottom: var(--sp-4);">🗳️</div>
            <h2 style="margin:0 0 var(--sp-2);">No ballots yet</h2>
            <p class="muted">When the board creates a ballot, it will appear here for owners to vote on.</p>
            <?php if ($canManage): ?>
                <p style="margin-top: var(--sp-5);"><a class="btn btn--primary" href="?action=create">Create the first ballot</a></p>
            <?php endif; ?>
        </div>
    <?php else:
        // Group by status for display — archived ballots go into their own bucket
        $grouped  = ['active'=>[], 'draft'=>[], 'closed'=>[]];
        $archived = [];
        foreach ($allVotes as $v) {
            if ((int)$v['archived']) { $archived[] = $v; }
            else                     { $grouped[$v['status']][] = $v; }
        }
        $sectionLabels = ['active' => '🟢 Active', 'draft' => '📝 Drafts', 'closed' => '✅ Closed'];
        foreach ($sectionLabels as $status => $label):
            if (!$grouped[$status]) continue;
        ?>
        <h2 style="font-size: var(--fs-md); font-weight:700; text-transform:uppercase; letter-spacing:.06em; color: var(--color-text-soft); margin: var(--sp-6) 0 var(--sp-3);"><?= $label ?></h2>
        <?php foreach ($grouped[$status] as $v):
            $isOpen     = vote_is_open($v);
            $hasVoted   = isset($myVotes[(int)$v['id']]);
            $pct        = $eligibleCount > 0 ? round((int)$v['participant_count'] / $eligibleCount * 100) : 0;
            $quorumMet  = $v['quorum_pct'] !== null && $pct >= (float)$v['quorum_pct'];
            $canSeeRes  = vote_can_see_results($v, $canManage);
            $typeLabels = ['election'=>'Board election','bylaw_amendment'=>'Bylaw amendment','budget_approval'=>'Budget approval','general_motion'=>'General motion','survey'=>'Survey'];
        ?>
        <div class="ballot-card">
            <div class="ballot-card__head">
                <div class="ballot-icon">🗳️</div>
                <div style="flex:1; min-width:0;">
                    <div style="display:flex; align-items:center; gap: var(--sp-2); flex-wrap:wrap; margin-bottom: var(--sp-1);">
                        <span class="ballot-status ballot-status--<?= e($v['status']) ?>"><?= e(ucfirst($v['status'])) ?></span>
                        <span class="muted" style="font-size: var(--fs-xs);"><?= e($typeLabels[$v['type']] ?? $v['type']) ?></span>
                        <?php if ((int)$v['anonymous']): ?>
                            <span class="badge" style="font-size:10px;">🔒 Anonymous</span>
                        <?php endif; ?>
                        <?php if ($hasVoted): ?>
                            <span class="badge badge--success" style="font-size:10px;">✓ You voted</span>
                        <?php endif; ?>
                    </div>
                    <h3 style="margin:0 0 var(--sp-1); font-size: var(--fs-lg);"><?= e($v['title']) ?></h3>
                    <?php if (!empty($v['ends_at'])): ?>
                        <div class="muted" style="font-size: var(--fs-xs);">
                            <?= $status === 'active' ? 'Closes' : 'Closed' ?>
                            <?= e(udate('M j, Y · g:i A', strtotime((string)$v['ends_at']))) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($status !== 'draft'): ?>
            <div class="ballot-card__body">
                <div style="display:flex; justify-content:space-between; font-size: var(--fs-xs); color: var(--color-text-soft); margin-bottom: 4px;">
                    <span><?= (int)$v['participant_count'] ?> of <?= $eligibleCount ?> eligible voters</span>
                    <span><?= $pct ?>%<?php if ($v['quorum_pct'] !== null): ?> — quorum: <?= (float)$v['quorum_pct'] ?>% <?= $quorumMet ? '✓ met' : '(not yet met)' ?><?php endif; ?></span>
                </div>
                <div class="quorum-bar"><div class="quorum-bar__fill <?= $quorumMet ? 'quorum-bar__fill--met' : '' ?>" style="width:<?= min($pct,100) ?>%;"></div></div>
            </div>
            <?php endif; ?>

            <div class="ballot-card__actions">
                <?php if ($isOpen && !$hasVoted): ?>
                    <a class="btn btn--primary" href="?action=vote&id=<?= (int)$v['id'] ?>">Vote now →</a>
                <?php endif; ?>
                <?php if ($canSeeRes && $status !== 'draft'): ?>
                    <a class="btn btn--ghost" href="?action=results&id=<?= (int)$v['id'] ?>">View results</a>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <?php if ($status === 'draft'): ?>
                        <a class="btn btn--ghost" href="?action=edit&id=<?= (int)$v['id'] ?>">Edit</a>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this draft ballot?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="delete_ballot">
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <button class="btn btn--ghost" type="submit" style="color: var(--color-error);">Delete</button>
                        </form>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="archive_ballot">
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <button class="btn btn--ghost" type="submit" style="color: var(--color-text-soft);">Archive</button>
                        </form>
                    <?php elseif ($status === 'active'): ?>
                        <a class="btn btn--ghost" href="?action=edit&id=<?= (int)$v['id'] ?>">Manage</a>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Close this ballot now? Voting will end immediately.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="close_ballot">
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <button class="btn btn--ghost" type="submit" style="color: var(--color-error);">Close now</button>
                        </form>
                    <?php elseif ($status === 'closed'): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="archive_ballot">
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <button class="btn btn--ghost" type="submit" style="color: var(--color-text-soft);">Archive</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>

        <?php if ($archived): ?>
        <details style="margin-top: var(--sp-8);">
            <summary style="cursor:pointer; font-size: var(--fs-sm); font-weight:600; color: var(--color-text-soft); list-style:none; display:flex; align-items:center; gap: var(--sp-2);">
                <span>▸</span> Archived (<?= count($archived) ?>)
            </summary>
            <div style="margin-top: var(--sp-4); opacity: 0.7;">
            <?php foreach ($archived as $v):
                $canSeeRes = vote_can_see_results($v, $canManage);
                $typeLabels = ['election'=>'Board election','bylaw_amendment'=>'Bylaw amendment','budget_approval'=>'Budget approval','general_motion'=>'General motion','survey'=>'Survey'];
            ?>
            <div class="ballot-card" style="border-style: dashed;">
                <div class="ballot-card__head">
                    <div class="ballot-icon" style="opacity:.5;">🗳️</div>
                    <div style="flex:1; min-width:0;">
                        <div style="display:flex; align-items:center; gap: var(--sp-2); flex-wrap:wrap; margin-bottom: var(--sp-1);">
                            <span class="ballot-status ballot-status--<?= e($v['status']) ?>"><?= e(ucfirst($v['status'])) ?></span>
                            <span class="muted" style="font-size: var(--fs-xs);"><?= e($typeLabels[$v['type']] ?? $v['type']) ?></span>
                            <span class="badge" style="font-size:10px;">Archived</span>
                        </div>
                        <h3 style="margin:0; font-size: var(--fs-lg);"><?= e($v['title']) ?></h3>
                    </div>
                </div>
                <div class="ballot-card__actions">
                    <?php if ($canSeeRes && $v['status'] !== 'draft'): ?>
                        <a class="btn btn--ghost" href="?action=results&id=<?= (int)$v['id'] ?>">View results</a>
                    <?php endif; ?>
                    <?php if ($canManage): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="unarchive_ballot">
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <button class="btn btn--ghost" type="submit">Restore</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>

    <?php endif; ?>

<?php // ═══════════════════════════════════════════════════════════════════
      // CREATE / EDIT VIEW
      // ═══════════════════════════════════════════════════════════════════
elseif (in_array($action, ['create','edit'], true) && $canManage):
    $isEdit   = $editVote !== null;
    $isDraft  = !$isEdit || $editVote['status'] === 'draft';
    $ev       = $editVote ?? [];
    $typeOpts = ['election'=>'Board election','bylaw_amendment'=>'Bylaw amendment','budget_approval'=>'Budget approval','general_motion'=>'General motion','survey'=>'Survey'];
?>

    <div class="row row--between" style="margin-bottom: var(--sp-5);">
        <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/voting.php">← Back to voting</a>
    </div>
    <h1 style="font-size: var(--fs-3xl); margin:0 0 var(--sp-6);"><?= $isEdit ? e((string)$ev['title']) : 'New ballot' ?></h1>

    <?php if ($isEdit && !$isDraft): ?>
        <div class="flash flash--info" style="margin-bottom: var(--sp-5);">
            This ballot is <?= e($ev['status']) ?>. Questions can no longer be changed, but you can still close it manually.
            <a href="?action=results&id=<?= (int)$ev['id'] ?>">View results →</a>
        </div>
    <?php endif; ?>

    <div class="card card--padded" style="margin-bottom: var(--sp-5);">
        <h3 class="card__title">Ballot details</h3>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="save_ballot">
            <?php if ($isEdit): ?><input type="hidden" name="vote_id" value="<?= (int)$ev['id'] ?>"><?php endif; ?>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="v-title">Title</label>
                    <input class="input" id="v-title" name="title" required placeholder="e.g. 2026 Board Election" value="<?= e((string)($ev['title'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="v-type">Type</label>
                    <select class="select" id="v-type" name="type">
                        <?php foreach ($typeOpts as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= ($ev['type'] ?? 'general_motion') === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label class="field__label" for="v-desc">Description (optional)</label>
                <textarea class="textarea" id="v-desc" name="description" rows="3" placeholder="What owners need to know before voting."><?= e((string)($ev['description'] ?? '')) ?></textarea>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="v-start">Voting opens</label>
                    <input class="input" type="datetime-local" id="v-start" name="starts_at"
                           value="<?= e(!empty($ev['starts_at']) ? date('Y-m-d\TH:i', strtotime((string)$ev['starts_at'])) : '') ?>">
                    <div class="field__hint">Leave blank to open immediately on publish.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="v-end">Voting closes</label>
                    <input class="input" type="datetime-local" id="v-end" name="ends_at"
                           value="<?= e(!empty($ev['ends_at']) ? date('Y-m-d\TH:i', strtotime((string)$ev['ends_at'])) : '') ?>">
                    <div class="field__hint">Leave blank to close manually.</div>
                </div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="v-quorum">Quorum required (%)</label>
                    <input class="input" type="number" id="v-quorum" name="quorum_pct" min="1" max="100" step="0.1"
                           value="<?= e((string)($ev['quorum_pct'] ?? '')) ?>" placeholder="e.g. 51">
                    <div class="field__hint">Leave blank for no quorum requirement.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="v-results">Results visible to members</label>
                    <select class="select" id="v-results" name="results_visible">
                        <option value="after_close" <?= ($ev['results_visible'] ?? 'after_close') === 'after_close' ? 'selected' : '' ?>>After ballot closes</option>
                        <option value="always"      <?= ($ev['results_visible'] ?? '') === 'always'      ? 'selected' : '' ?>>Live during voting</option>
                        <option value="board_only"  <?= ($ev['results_visible'] ?? '') === 'board_only'  ? 'selected' : '' ?>>Board only (never public)</option>
                    </select>
                </div>
            </div>

            <div class="row" style="gap: var(--sp-6); flex-wrap:wrap;">
                <label style="display:flex; align-items:center; gap: var(--sp-2); cursor:pointer; font-size: var(--fs-sm);">
                    <input type="checkbox" name="anonymous" <?= ($ev['anonymous'] ?? 0) ? 'checked' : '' ?>>
                    <span><strong>Anonymous voting</strong> — votes are recorded without linking to individual owners</span>
                </label>
                <label style="display:flex; align-items:center; gap: var(--sp-2); cursor:pointer; font-size: var(--fs-sm);">
                    <input type="checkbox" name="allow_abstain" <?= ($ev['allow_abstain'] ?? 1) ? 'checked' : '' ?>>
                    <span><strong>Allow abstain</strong> — owners can formally abstain (counts toward quorum)</span>
                </label>
            </div>

            <div class="row" style="justify-content:flex-end; margin-top: var(--sp-5);">
                <button class="btn btn--primary" type="submit">Save details</button>
            </div>
        </form>
    </div>

    <?php if ($isEdit): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-5);">
        <div class="row row--between" style="margin-bottom: var(--sp-4);">
            <h3 class="card__title" style="margin:0;">Questions</h3>
            <?php if (!$isDraft): ?>
                <span class="muted" style="font-size: var(--fs-sm);">Questions locked — ballot is <?= e($ev['status']) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($isDraft): ?>
        <form method="post" class="form" id="q-form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="save_ballot">
            <input type="hidden" name="vote_id" value="<?= (int)$ev['id'] ?>">
            <!-- Pass through all ballot fields so they aren't wiped on question save -->
            <input type="hidden" name="title"           value="<?= e((string)$ev['title']) ?>">
            <input type="hidden" name="type"            value="<?= e((string)$ev['type']) ?>">
            <input type="hidden" name="description"     value="<?= e((string)($ev['description'] ?? '')) ?>">
            <input type="hidden" name="starts_at"       value="<?= e(!empty($ev['starts_at']) ? date('Y-m-d\TH:i', strtotime((string)$ev['starts_at'])) : '') ?>">
            <input type="hidden" name="ends_at"         value="<?= e(!empty($ev['ends_at'])   ? date('Y-m-d\TH:i', strtotime((string)$ev['ends_at'])) : '') ?>">
            <input type="hidden" name="quorum_pct"      value="<?= e((string)($ev['quorum_pct'] ?? '')) ?>">
            <input type="hidden" name="results_visible" value="<?= e((string)$ev['results_visible']) ?>">
            <?php if ($ev['anonymous'])    echo '<input type="hidden" name="anonymous" value="1">'; ?>
            <?php if ($ev['allow_abstain']) echo '<input type="hidden" name="allow_abstain" value="1">'; ?>

            <div id="q-builder">
                <?php foreach ($editQuestions as $qi => $q):
                    $optIds   = $q['option_ids']   ? explode(',', $q['option_ids'])   : [];
                    $optTexts = $q['option_texts'] ? explode('||', $q['option_texts']) : [];
                ?>
                <div class="q-block" data-qi="<?= $qi ?>">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: var(--sp-3);">
                        <span class="q-block__label">Question <?= $qi + 1 ?></span>
                        <button type="button" class="btn btn--ghost" style="padding:2px 10px; font-size: var(--fs-xs); color: var(--color-error);" onclick="removeQ(this)">Remove</button>
                    </div>
                    <div class="field">
                        <textarea class="textarea" name="q_text[<?= $qi ?>]" rows="2" placeholder="What is the question?" required><?= e((string)$q['question']) ?></textarea>
                    </div>
                    <div class="form-row form-row--2">
                        <div class="field">
                            <label class="field__label">Answer type</label>
                            <select class="select q-type-select" name="q_type[<?= $qi ?>]" onchange="toggleOpts(this)">
                                <option value="yes_no"          <?= $q['type']==='yes_no'          ? 'selected':'' ?>>Yes / No</option>
                                <option value="multiple_choice" <?= $q['type']==='multiple_choice' ? 'selected':'' ?>>Multiple choice</option>
                                <option value="text"            <?= $q['type']==='text'            ? 'selected':'' ?>>Free text</option>
                            </select>
                        </div>
                        <div class="field" style="display:flex; align-items:center; padding-top: var(--sp-5);">
                            <label style="display:flex; align-items:center; gap: var(--sp-2); font-size: var(--fs-sm); cursor:pointer;">
                                <input type="checkbox" name="q_required[<?= $qi ?>]" <?= $q['required'] ? 'checked' : '' ?>>
                                Required
                            </label>
                        </div>
                    </div>
                    <div class="q-options" style="<?= $q['type'] !== 'multiple_choice' ? 'display:none;' : '' ?>">
                        <div class="field__label" style="font-size: var(--fs-sm); margin-bottom: var(--sp-2);">Options</div>
                        <div class="q-opts-list">
                            <?php foreach ($optTexts as $oi => $ot): ?>
                            <div style="display:flex; gap: var(--sp-2); margin-bottom: var(--sp-2);">
                                <input class="input" name="q_options[<?= $qi ?>][]" value="<?= e($ot) ?>" placeholder="Option text">
                                <button type="button" class="btn btn--ghost" style="padding:0 10px;" onclick="this.closest('div').remove()">✕</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn--ghost" style="font-size: var(--fs-xs);" onclick="addOpt(this)">+ Add option</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="builder-add" onclick="addQuestion()" style="margin-bottom: var(--sp-5);">
                + Add a question
            </div>

            <div class="row" style="justify-content:flex-end; gap: var(--sp-3);">
                <button class="btn btn--ghost" type="submit">Save questions</button>
            </div>
        </form>

        <?php else: // Not a draft — show read-only questions ?>
        <?php foreach ($editQuestions as $qi => $q):
            $optTexts = $q['option_texts'] ? explode('||', $q['option_texts']) : [];
        ?>
        <div class="q-block">
            <div class="q-block__label">Q<?= $qi + 1 ?>: <?= e((string)$q['question']) ?></div>
            <div class="muted" style="font-size: var(--fs-xs); margin-bottom: var(--sp-1);">
                <?= $q['type'] === 'yes_no' ? 'Yes / No' : ($q['type'] === 'multiple_choice' ? 'Multiple choice' : 'Free text') ?>
            </div>
            <?php if ($optTexts): ?>
            <ul style="margin: var(--sp-2) 0 0; padding-left:1.2em; font-size: var(--fs-sm);">
                <?php foreach ($optTexts as $ot): ?><li><?= e($ot) ?></li><?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($isDraft): ?>
    <div class="card card--padded" style="background: var(--color-surface); border: 2px solid var(--color-navy);">
        <h3 style="margin:0 0 var(--sp-2);">Ready to go live?</h3>
        <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-4);">Once published, owners can vote. Questions can no longer be edited after publishing.</p>
        <form method="post" onsubmit="return confirm('Publish this ballot? Owners will be able to vote immediately.');">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="publish_ballot">
            <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
            <button class="btn btn--primary" type="submit">🗳 Publish ballot</button>
        </form>
    </div>
    <?php elseif ($ev['status'] === 'active'): ?>
    <div class="card card--padded" style="border-color: var(--color-error);">
        <h3 style="margin:0 0 var(--sp-2); color: var(--color-error);">Close early</h3>
        <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-4);">Voting will end immediately and results will be final.</p>
        <form method="post" onsubmit="return confirm('Close this ballot now? This cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="close_ballot">
            <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
            <button class="btn btn--danger" type="submit">Close ballot now</button>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; // isEdit ?>

<?php // ═══════════════════════════════════════════════════════════════════
      // CAST VOTE VIEW
      // ═══════════════════════════════════════════════════════════════════
elseif ($action === 'vote' && $castBallot):
    $pct = $castEligible > 0 ? round($castVoted / $castEligible * 100) : 0;
    $typeLabels = ['election'=>'Board election','bylaw_amendment'=>'Bylaw amendment','budget_approval'=>'Budget approval','general_motion'=>'General motion','survey'=>'Survey'];
?>

    <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/voting.php">← Back to voting</a>

    <div style="margin: var(--sp-6) 0;">
        <div style="display:flex; align-items:center; gap: var(--sp-2); margin-bottom: var(--sp-2);">
            <span class="ballot-status ballot-status--active">Live</span>
            <span class="muted" style="font-size: var(--fs-xs);"><?= e($typeLabels[$castBallot['type']] ?? '') ?></span>
            <?php if ((int)$castBallot['anonymous']): ?><span class="badge" style="font-size:10px;">🔒 Anonymous</span><?php endif; ?>
        </div>
        <h1 style="font-size: var(--fs-3xl); margin:0 0 var(--sp-2);"><?= e((string)$castBallot['title']) ?></h1>
        <?php if (!empty($castBallot['description'])): ?>
            <p style="color: var(--color-text-soft); margin:0 0 var(--sp-2);"><?= nl2br(e((string)$castBallot['description'])) ?></p>
        <?php endif; ?>
        <?php if (!empty($castBallot['ends_at'])): ?>
            <p class="muted" style="font-size: var(--fs-sm); margin:0;">Closes <?= e(udate('l, F j, Y · g:i A', strtotime((string)$castBallot['ends_at']))) ?></p>
        <?php endif; ?>
    </div>

    <div class="card card--padded" style="margin-bottom: var(--sp-5);">
        <div style="display:flex; justify-content:space-between; font-size: var(--fs-sm); color: var(--color-text-soft); margin-bottom: var(--sp-2);">
            <span>Participation: <?= $castVoted ?> of <?= $castEligible ?> eligible voters</span>
            <span><?= $pct ?>%</span>
        </div>
        <div class="quorum-bar">
            <div class="quorum-bar__fill <?= ($castBallot['quorum_pct'] !== null && $pct >= $castBallot['quorum_pct']) ? 'quorum-bar__fill--met' : '' ?>"
                 style="width:<?= min($pct,100) ?>%;"></div>
        </div>
        <?php if ($castBallot['quorum_pct'] !== null): ?>
            <div class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-1);">
                Quorum required: <?= (float)$castBallot['quorum_pct'] ?>% —
                <?php if ($pct >= (float)$castBallot['quorum_pct']): ?>
                    <strong style="color:#16a34a;">✓ Met</strong>
                <?php else: ?>
                    <?= max(0, (int)ceil(($castBallot['quorum_pct'] / 100 * $castEligible) - $castVoted)) ?> more votes needed
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="submit_vote">
        <input type="hidden" name="vote_id" value="<?= (int)$castBallot['id'] ?>">

        <?php foreach ($castQuestions as $qi => $q):
            $optIds   = $q['option_ids']   ? explode(',', $q['option_ids'])   : [];
            $optTexts = $q['option_texts'] ? explode('||', $q['option_texts']) : [];
            $qid = (int)$q['id'];
        ?>
        <div class="card card--padded" style="margin-bottom: var(--sp-4);">
            <div style="font-weight:700; font-size: var(--fs-md); margin-bottom: var(--sp-4);">
                <?= $qi + 1 ?>. <?= e((string)$q['question']) ?>
                <?php if ((int)$q['required']): ?><span class="muted" style="font-size: var(--fs-xs); font-weight:400;"> — required</span><?php endif; ?>
            </div>

            <?php if ($q['type'] === 'yes_no'): ?>
                <label class="vote-option vote-option--yes">
                    <input type="radio" name="answer[<?= $qid ?>]" value="yes" required>
                    <span style="font-size:20px;">✅</span> <span>Yes</span>
                </label>
                <label class="vote-option vote-option--no">
                    <input type="radio" name="answer[<?= $qid ?>]" value="no">
                    <span style="font-size:20px;">❌</span> <span>No</span>
                </label>
                <?php if ((int)$castBallot['allow_abstain']): ?>
                <label class="vote-option vote-option--abs">
                    <input type="radio" name="answer[<?= $qid ?>]" value="abstain">
                    <span style="font-size:20px;">⬜</span> <span>Abstain</span>
                </label>
                <?php endif; ?>

            <?php elseif ($q['type'] === 'multiple_choice'): ?>
                <?php foreach ($optIds as $oi => $oid): ?>
                <label class="vote-option">
                    <input type="radio" name="answer[<?= $qid ?>]" value="<?= (int)$oid ?>" <?= !$qi && !$oi ? 'required' : '' ?>>
                    <span><?= e($optTexts[$oi] ?? '') ?></span>
                </label>
                <?php endforeach; ?>
                <?php if ((int)$castBallot['allow_abstain']): ?>
                <label class="vote-option vote-option--abs">
                    <input type="radio" name="answer[<?= $qid ?>]" value="abstain">
                    <span style="font-size:18px;">⬜</span> <span>Abstain</span>
                </label>
                <?php endif; ?>

            <?php else: // text ?>
                <textarea class="textarea" name="answer[<?= $qid ?>]" rows="4" placeholder="Your answer…"></textarea>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div class="card card--padded" style="background: #fffbf5; border-color: var(--color-orange);">
            <p style="margin:0 0 var(--sp-3); font-size: var(--fs-sm);">
                <?php if ((int)$castBallot['anonymous']): ?>
                    🔒 Your vote is <strong>anonymous</strong> — it cannot be linked to your account after submission.
                <?php else: ?>
                    Your vote will be recorded on your account. Each owner may vote once.
                <?php endif; ?>
            </p>
            <button class="btn btn--primary btn--lg" type="submit">Submit my vote →</button>
        </div>
    </form>

<?php // ═══════════════════════════════════════════════════════════════════
      // RESULTS VIEW
      // ═══════════════════════════════════════════════════════════════════
elseif ($action === 'results' && $resultsBallot):
    $canSeeRes   = vote_can_see_results($resultsBallot, $canManage);
    $isOpen      = vote_is_open($resultsBallot);
    $pct         = $resultsEligible > 0 ? round($resultsVoted / $resultsEligible * 100) : 0;
    $quorumMet   = $resultsBallot['quorum_pct'] !== null && $pct >= (float)$resultsBallot['quorum_pct'];
    $typeLabels  = ['election'=>'Board election','bylaw_amendment'=>'Bylaw amendment','budget_approval'=>'Budget approval','general_motion'=>'General motion','survey'=>'Survey'];
?>

    <div class="row row--between" style="margin-bottom: var(--sp-4);">
        <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/voting.php">← Back to voting</a>
        <div class="row" style="gap: var(--sp-2);">
            <?php if ($isOpen && !$myParticipated): ?>
                <a class="btn btn--primary" href="?action=vote&id=<?= (int)$resultsBallot['id'] ?>">Vote now →</a>
            <?php endif; ?>
            <?php if ($canManage && $resultsBallot['status'] === 'active'): ?>
                <a class="btn btn--ghost" href="?action=edit&id=<?= (int)$resultsBallot['id'] ?>">Manage</a>
            <?php endif; ?>
        </div>
    </div>

    <div style="margin-bottom: var(--sp-6);">
        <div style="display:flex; align-items:center; gap: var(--sp-2); margin-bottom: var(--sp-2);">
            <span class="ballot-status ballot-status--<?= e($resultsBallot['status']) ?>"><?= e(ucfirst($resultsBallot['status'])) ?></span>
            <span class="muted" style="font-size: var(--fs-xs);"><?= e($typeLabels[$resultsBallot['type']] ?? '') ?></span>
            <?php if ($myParticipated): ?><span class="badge badge--success" style="font-size:10px;">✓ You voted</span><?php endif; ?>
        </div>
        <h1 style="font-size: var(--fs-3xl); margin:0 0 var(--sp-2);"><?= e((string)$resultsBallot['title']) ?></h1>
        <?php if (!empty($resultsBallot['description'])): ?>
            <p style="color: var(--color-text-soft); margin:0;"><?= nl2br(e((string)$resultsBallot['description'])) ?></p>
        <?php endif; ?>
    </div>

    <!-- Participation & quorum card -->
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 style="margin:0 0 var(--sp-4);">Participation</h3>
        <div style="display:flex; justify-content:space-between; font-size: var(--fs-sm); color: var(--color-text-soft); margin-bottom: var(--sp-2);">
            <span><?= $resultsVoted ?> of <?= $resultsEligible ?> eligible voters</span>
            <span><strong><?= $pct ?>%</strong></span>
        </div>
        <div class="quorum-bar" style="height:14px;">
            <div class="quorum-bar__fill <?= $quorumMet ? 'quorum-bar__fill--met' : '' ?>" style="width:<?= min($pct,100) ?>%;"></div>
        </div>
        <?php if ($resultsBallot['quorum_pct'] !== null): ?>
        <div style="font-size: var(--fs-sm); margin-top: var(--sp-3);">
            <?php if ($quorumMet): ?>
                <strong style="color:#16a34a;">✓ Quorum reached</strong> (required <?= (float)$resultsBallot['quorum_pct'] ?>%)
            <?php else: ?>
                <strong style="color: var(--color-error);">Quorum not yet reached</strong> — need <?= (float)$resultsBallot['quorum_pct'] ?>%
                (<?= max(0,(int)ceil($resultsBallot['quorum_pct']/100*$resultsEligible - $resultsVoted)) ?> more)
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($resultsBallot['ends_at'])): ?>
        <div class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-2);">
            <?= $resultsBallot['status'] === 'closed' ? 'Closed' : 'Closes' ?>
            <?= e(udate('l, F j, Y · g:i A', strtotime((string)$resultsBallot['ends_at']))) ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$canSeeRes): ?>
    <div class="card card--padded center" style="padding: var(--sp-10);">
        <div style="font-size:40px; margin-bottom: var(--sp-3);">🔒</div>
        <h2 style="margin:0 0 var(--sp-2);">Results pending</h2>
        <p class="muted">Results will be visible once the ballot closes.</p>
    </div>
    <?php else: ?>
    <?php foreach ($resultsQuestions as $qi => $q):
        $qid = (int)$q['id'];
        $optIds   = $q['option_ids']   ? explode(',', $q['option_ids'])   : [];
        $optTexts = $q['option_texts'] ? explode('||', $q['option_texts']) : [];
        $totalResponses = 0;

        if ($q['type'] === 'yes_no') {
            $yesN = $noN = $absN = 0;
            $rStmt = db()->prepare("SELECT answer_text, COUNT(*) AS n FROM vote_responses WHERE question_id=? GROUP BY answer_text");
            $rStmt->execute([$qid]);
            foreach ($rStmt->fetchAll() as $r) {
                if ($r['answer_text'] === 'yes') $yesN = (int)$r['n'];
                elseif ($r['answer_text'] === 'no') $noN = (int)$r['n'];
                else $absN += (int)$r['n'];
            }
            $totalResponses = $yesN + $noN + $absN;
        } elseif ($q['type'] === 'multiple_choice') {
            $optCounts = array_fill_keys($optIds, 0);
            $absN = 0;
            $rStmt = db()->prepare("SELECT answer_option_id, answer_text, COUNT(*) AS n FROM vote_responses WHERE question_id=? GROUP BY answer_option_id, answer_text");
            $rStmt->execute([$qid]);
            foreach ($rStmt->fetchAll() as $r) {
                if ($r['answer_text'] === 'abstain') { $absN += (int)$r['n']; continue; }
                if ($r['answer_option_id'] !== null) $optCounts[$r['answer_option_id']] = (int)$r['n'];
            }
            $totalResponses = array_sum($optCounts) + $absN;
        } else {
            $rStmt = db()->prepare("SELECT answer_text FROM vote_responses WHERE question_id=? ORDER BY cast_at DESC");
            $rStmt->execute([$qid]);
            $textAnswers = $rStmt->fetchAll(PDO::FETCH_COLUMN);
            $totalResponses = count($textAnswers);
        }
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-4);">
        <h3 style="font-size: var(--fs-md); margin:0 0 var(--sp-4);">
            <?= $qi + 1 ?>. <?= e((string)$q['question']) ?>
            <span class="muted" style="font-size: var(--fs-xs); font-weight:400; margin-left: var(--sp-2);"><?= $totalResponses ?> response<?= $totalResponses !== 1 ? 's' : '' ?></span>
        </h3>

        <?php if ($q['type'] === 'yes_no'):
            $bars = [
                ['label'=>'Yes',     'n'=>$yesN, 'cls'=>'result-bar__fill--yes', 'icon'=>'✅'],
                ['label'=>'No',      'n'=>$noN,  'cls'=>'result-bar__fill--no',  'icon'=>'❌'],
                ['label'=>'Abstain', 'n'=>$absN, 'cls'=>'result-bar__fill--abs', 'icon'=>'⬜'],
            ];
            foreach ($bars as $b):
                $bPct = $totalResponses > 0 ? round($b['n'] / $totalResponses * 100) : 0;
                if ($b['n'] === 0 && $b['label'] === 'Abstain') continue;
        ?>
        <div style="margin-bottom: var(--sp-3);">
            <div style="display:flex; justify-content:space-between; font-size: var(--fs-sm); margin-bottom: 4px;">
                <span><?= $b['icon'] ?> <?= $b['label'] ?></span>
                <span><strong><?= $b['n'] ?></strong> <span class="muted">(<?= $bPct ?>%)</span></span>
            </div>
            <div class="result-bar">
                <div class="result-bar__fill <?= $b['cls'] ?>" style="width:<?= $bPct ?>%;">
                    <?php if ($bPct >= 12): ?><?= $bPct ?>%<?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php elseif ($q['type'] === 'multiple_choice'):
            foreach ($optIds as $oi => $oid):
                $n    = $optCounts[$oid] ?? 0;
                $bPct = $totalResponses > 0 ? round($n / $totalResponses * 100) : 0;
        ?>
        <div style="margin-bottom: var(--sp-3);">
            <div style="display:flex; justify-content:space-between; font-size: var(--fs-sm); margin-bottom: 4px;">
                <span><?= e($optTexts[$oi] ?? '') ?></span>
                <span><strong><?= $n ?></strong> <span class="muted">(<?= $bPct ?>%)</span></span>
            </div>
            <div class="result-bar">
                <div class="result-bar__fill" style="width:<?= $bPct ?>%;">
                    <?php if ($bPct >= 12): ?><?= $bPct ?>%<?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach;
            if ($absN > 0): $bPct = $totalResponses > 0 ? round($absN/$totalResponses*100) : 0; ?>
        <div style="margin-bottom: var(--sp-3);">
            <div style="display:flex; justify-content:space-between; font-size: var(--fs-sm); margin-bottom: 4px;">
                <span>⬜ Abstain</span>
                <span><strong><?= $absN ?></strong> <span class="muted">(<?= $bPct ?>%)</span></span>
            </div>
            <div class="result-bar"><div class="result-bar__fill result-bar__fill--abs" style="width:<?= $bPct ?>%;"></div></div>
        </div>
        <?php endif; ?>

        <?php else: // text answers ?>
        <?php if ($textAnswers): ?>
        <ul style="margin:0; padding-left:1.2em; font-size: var(--fs-sm); display:flex; flex-direction:column; gap: var(--sp-2);">
            <?php foreach ($textAnswers as $ta): ?><li><?= e((string)$ta) ?></li><?php endforeach; ?>
        </ul>
        <?php else: ?><p class="muted">No responses yet.</p><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; // canSeeRes ?>

<?php endif; // action routing ?>

</div>

<?php if (in_array($action, ['create','edit'], true) && $isDraft): ?>
<script>
var qCount = <?= count($editQuestions) ?>;

function removeQ(btn) {
    btn.closest('.q-block').remove();
    renumber();
}

function renumber() {
    document.querySelectorAll('#q-builder .q-block').forEach(function(block, i) {
        block.querySelector('.q-block__label').textContent = 'Question ' + (i + 1);
        block.querySelectorAll('[name]').forEach(function(el) {
            el.name = el.name.replace(/\[\d+\]/, '[' + i + ']');
        });
        block.dataset.qi = i;
    });
    qCount = document.querySelectorAll('#q-builder .q-block').length;
}

function toggleOpts(sel) {
    var block = sel.closest('.q-block');
    var optsDiv = block.querySelector('.q-options');
    if (optsDiv) optsDiv.style.display = sel.value === 'multiple_choice' ? '' : 'none';
}

function addOpt(btn) {
    var list = btn.previousElementSibling;
    var qi = btn.closest('.q-block').dataset.qi;
    var div = document.createElement('div');
    div.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;';
    div.innerHTML = '<input class="input" name="q_options[' + qi + '][]" placeholder="Option text">'
                  + '<button type="button" class="btn btn--ghost" style="padding:0 10px;" onclick="this.closest(\'div\').remove()">✕</button>';
    list.appendChild(div);
}

function addQuestion() {
    var i = document.querySelectorAll('#q-builder .q-block').length;
    var tpl = '<div class="q-block" data-qi="' + i + '">'
            + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">'
            + '<span class="q-block__label">Question ' + (i + 1) + '</span>'
            + '<button type="button" class="btn btn--ghost" style="padding:2px 10px;font-size:var(--fs-xs);color:var(--color-error);" onclick="removeQ(this)">Remove</button>'
            + '</div>'
            + '<div class="field"><textarea class="textarea" name="q_text[' + i + ']" rows="2" placeholder="What is the question?" required></textarea></div>'
            + '<div class="form-row form-row--2">'
            + '<div class="field"><label class="field__label">Answer type</label>'
            + '<select class="select q-type-select" name="q_type[' + i + ']" onchange="toggleOpts(this)">'
            + '<option value="yes_no">Yes / No</option>'
            + '<option value="multiple_choice">Multiple choice</option>'
            + '<option value="text">Free text</option>'
            + '</select></div>'
            + '<div class="field" style="display:flex;align-items:center;padding-top:var(--sp-5);">'
            + '<label style="display:flex;align-items:center;gap:8px;font-size:var(--fs-sm);cursor:pointer;">'
            + '<input type="checkbox" name="q_required[' + i + ']" checked> Required</label></div>'
            + '</div>'
            + '<div class="q-options" style="display:none;">'
            + '<div class="field__label" style="font-size:var(--fs-sm);margin-bottom:8px;">Options</div>'
            + '<div class="q-opts-list"></div>'
            + '<button type="button" class="btn btn--ghost" style="font-size:var(--fs-xs);" onclick="addOpt(this)">+ Add option</button>'
            + '</div>'
            + '</div>';
    var container = document.getElementById('q-builder');
    var div = document.createElement('div');
    div.innerHTML = tpl;
    container.appendChild(div.firstChild);
    container.lastElementChild.querySelector('textarea').focus();
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
