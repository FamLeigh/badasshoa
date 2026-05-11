<?php
require __DIR__ . '/_bootstrap.php';

$user      = current_user();
$canManage = role_can_manage(viewing_role());

// Bootstrap the 15 default categories the first time a fresh association
// hits this page. Idempotent — does nothing once the assoc has any.
ensure_default_rule_categories($assocId);

$ajax      = isset($_GET['ajax']);
$flashError    = null;
$importSummary = null;

// ============================================================================
// Downloadable CSV template
// ============================================================================
if (($_GET['download'] ?? '') === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="badasshoa-rules-template.csv"');
    echo "title,rule_number,category,source,effective_date,body\n";
    echo '"No grilling on balconies","4.2.1","Common areas","board_rule","2025-01-15","Per fire-code regulations, gas and charcoal grills are prohibited on all unit balconies and patios."' . "\n";
    echo '"Pet weight limit","2.1","Pets","bylaw","2020-06-01","Pets must not exceed 50 lbs at maturity. Documentation required at move-in."' . "\n";
    echo '"Quiet hours","5.3","Noise","policy","2024-03-01","Noise from any source must not be audible outside the unit between 10 PM and 7 AM."' . "\n";
    exit;
}

// ============================================================================
// POST handlers
// ============================================================================

// --- Add rule ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    [$title,$body,$cat,$source,$num,$date,$err] = rule_form_validate($_POST);
    if ($err) {
        $flashError = $err;
    } else {
        db()->prepare(
            'INSERT INTO rules (association_id, title, body, category, source, rule_number, effective_date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$assocId, $title, $body, $cat ?: null, $source, $num ?: null, $date ?: null]);
        $newId = (int)db()->lastInsertId();
        audit('rule.added', ['title' => $title, 'source' => $source], $newId, 'rule');
        flash('success', "Rule \"$title\" added.");
        redirect('/dashboard/search.php');
    }
}

// --- Edit rule ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $rid = (int)($_POST['id'] ?? 0);
    $check = db()->prepare('SELECT 1 FROM rules WHERE id = ? AND association_id = ?');
    $check->execute([$rid, $assocId]);
    if (!$check->fetchColumn()) { http_response_code(404); die('Rule not found'); }

    [$title,$body,$cat,$source,$num,$date,$err] = rule_form_validate($_POST);
    if ($err) {
        $flashError = $err;
    } else {
        db()->prepare(
            'UPDATE rules SET title = ?, body = ?, category = ?, source = ?, rule_number = ?, effective_date = ?
             WHERE id = ? AND association_id = ?'
        )->execute([$title, $body, $cat ?: null, $source, $num ?: null, $date ?: null, $rid, $assocId]);
        audit('rule.edited', ['title' => $title], $rid, 'rule');
        flash('success', "Rule \"$title\" updated.");
        redirect('/dashboard/search.php');
    }
}

// --- Member submits a rule suggestion -------------------------------------
// Anyone signed in to this association can suggest. The board reviews and
// approves (becomes a real rules row) or rejects (stays in rule_suggestions
// with status='rejected' for history).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'suggest_create') {
    csrf_check();
    $title  = trim((string)($_POST['title'] ?? ''));
    $body   = (string)($_POST['body'] ?? '');
    $source = $_POST['source'] ?? 'board_rule';
    $cat    = trim((string)($_POST['category'] ?? ''));
    if (!in_array($source, ['bylaw','board_rule','policy'], true)) $source = 'board_rule';

    $bodyText = trim(strip_tags(str_replace(['&nbsp;', "\xc2\xa0"], ' ', $body)));
    if ($title === '')         $flashError = 'Title is required.';
    elseif ($bodyText === '')  $flashError = 'Body is required.';
    else {
        db()->prepare(
            'INSERT INTO rule_suggestions
                 (association_id, suggester_user_id, title, body, source, category)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$assocId, (int)$user['id'], $title, $body, $source, $cat ?: null]);
        $newId = (int)db()->lastInsertId();
        audit('rule_suggestion.submitted', ['title' => $title], $newId, 'rule_suggestion');

        // Notify managers of this association.
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: (string)$user['email'];
        notify_association_managers(
            $assocId,
            "[{$association['name']}] New rule suggestion: " . $title,
            "$name has suggested a new rule for {$association['name']}.\n\n"
            . "Title: $title\n"
            . ($cat !== '' ? "Category: $cat\n" : '')
            . "Source: $source\n\n"
            . "Body:\n" . $bodyText . "\n\n"
            . "Review and approve or reject:\n"
            . "https://badasshoa.com/dashboard/search.php?action=suggestions\n"
        );

        flash('success', "Thanks — your suggestion is now in front of the board for review. You'll get an email when they decide.");
        redirect('/dashboard/search.php');
    }
}

// --- Approve a suggestion -> creates a real rule -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'suggest_approve') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $sid           = (int)($_POST['id'] ?? 0);
    $title         = trim((string)($_POST['title'] ?? ''));
    $body          = (string)($_POST['body'] ?? '');
    $source        = $_POST['source'] ?? 'board_rule';
    $cat           = trim((string)($_POST['category'] ?? ''));
    $num           = trim((string)($_POST['rule_number'] ?? ''));
    $approvalDate  = trim((string)($_POST['approval_date'] ?? ''));
    $note          = trim((string)($_POST['decision_note'] ?? ''));
    if (!in_array($source, ['bylaw','board_rule','policy'], true)) $source = 'board_rule';
    if ($approvalDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $approvalDate)) $approvalDate = '';
    if ($approvalDate === '') $approvalDate = date('Y-m-d');

    $stmt = db()->prepare('SELECT * FROM rule_suggestions WHERE id = ? AND association_id = ? AND status = "pending"');
    $stmt->execute([$sid, $assocId]);
    $sug = $stmt->fetch();
    if (!$sug) {
        flash('error', 'Suggestion not found or already decided.');
        redirect('/dashboard/search.php?action=suggestions');
    }

    db()->beginTransaction();
    try {
        db()->prepare(
            'INSERT INTO rules (association_id, title, body, category, source, rule_number, effective_date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$assocId, $title, $body, $cat ?: null, $source, $num ?: null, $approvalDate]);
        $newRuleId = (int)db()->lastInsertId();

        db()->prepare(
            'UPDATE rule_suggestions
                SET status = "approved",
                    reviewed_by_user_id = ?,
                    reviewed_at = NOW(),
                    decision_note = ?,
                    approval_date = ?,
                    resulting_rule_id = ?
              WHERE id = ?'
        )->execute([(int)$user['id'], $note ?: null, $approvalDate, $newRuleId, $sid]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        flash('error', 'Approval failed: ' . $e->getMessage());
        redirect('/dashboard/search.php?action=suggestions');
    }

    audit('rule_suggestion.approved', ['title' => $title, 'approval_date' => $approvalDate, 'rule_id' => $newRuleId], $sid, 'rule_suggestion');

    // Email the suggester (if still active in this association).
    $sStmt = db()->prepare('SELECT first_name, email FROM users WHERE id = ? AND status = "active"');
    $sStmt->execute([(int)$sug['suggester_user_id']]);
    if ($s = $sStmt->fetch()) {
        $sName = (string)($s['first_name'] ?: 'there');
        send_mail((string)$s['email'],
            "[{$association['name']}] Your rule suggestion was approved",
            "Hi $sName,\n\nThe board approved your rule suggestion for {$association['name']}:\n\n"
            . "  $title\n\n"
            . ($note !== '' ? "Board's note: $note\n\n" : '')
            . "Effective date: $approvalDate\n\n"
            . "View the full rules list at https://badasshoa.com/dashboard/search.php\n\n"
            . "— {$association['name']}");
    }

    flash('success', "Approved \"$title\". It's now a published rule (effective $approvalDate).");
    redirect('/dashboard/search.php?action=suggestions');
}

// --- Reject a suggestion -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'suggest_reject') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $sid  = (int)($_POST['id'] ?? 0);
    $note = trim((string)($_POST['decision_note'] ?? ''));

    $stmt = db()->prepare('SELECT * FROM rule_suggestions WHERE id = ? AND association_id = ? AND status = "pending"');
    $stmt->execute([$sid, $assocId]);
    $sug = $stmt->fetch();
    if (!$sug) {
        flash('error', 'Suggestion not found or already decided.');
        redirect('/dashboard/search.php?action=suggestions');
    }

    db()->prepare(
        'UPDATE rule_suggestions
            SET status = "rejected",
                reviewed_by_user_id = ?,
                reviewed_at = NOW(),
                decision_note = ?
          WHERE id = ?'
    )->execute([(int)$user['id'], $note ?: null, $sid]);
    audit('rule_suggestion.rejected', ['title' => $sug['title']], $sid, 'rule_suggestion');

    // Email the suggester
    $sStmt = db()->prepare('SELECT first_name, email FROM users WHERE id = ? AND status = "active"');
    $sStmt->execute([(int)$sug['suggester_user_id']]);
    if ($s = $sStmt->fetch()) {
        $sName = (string)($s['first_name'] ?: 'there');
        send_mail((string)$s['email'],
            "[{$association['name']}] Your rule suggestion was reviewed",
            "Hi $sName,\n\nThe board reviewed your rule suggestion for {$association['name']}:\n\n"
            . "  {$sug['title']}\n\n"
            . "Status: not adopted at this time."
            . ($note !== '' ? "\n\nBoard's note: $note\n" : "\n")
            . "\nYou're welcome to refine and resubmit at any time:\n"
            . "https://badasshoa.com/dashboard/search.php\n\n"
            . "— {$association['name']}");
    }

    flash('success', "Suggestion rejected.");
    redirect('/dashboard/search.php?action=suggestions');
}

// --- Toggle review flag on a rule ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'review_flag') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $rid  = (int)($_POST['id'] ?? 0);
    $on   = isset($_POST['on']) && $_POST['on'] === '1';
    $note = trim((string)($_POST['review_note'] ?? ''));
    if ($on) {
        db()->prepare(
            'UPDATE rules
                SET review_flag = 1, review_note = ?, review_flagged_at = NOW(), review_flagged_by_user_id = ?
              WHERE id = ? AND association_id = ?'
        )->execute([$note ?: null, (int)$user['id'], $rid, $assocId]);
        audit('rule.flagged_for_review', ['note' => mb_strimwidth($note, 0, 80, '…')], $rid, 'rule');
        flash('success', 'Flagged for review.');
    } else {
        db()->prepare(
            'UPDATE rules
                SET review_flag = 0, review_note = NULL, review_flagged_at = NULL, review_flagged_by_user_id = NULL
              WHERE id = ? AND association_id = ?'
        )->execute([$rid, $assocId]);
        audit('rule.review_cleared', [], $rid, 'rule');
        flash('success', 'Review flag cleared.');
    }
    redirect($_POST['back'] ?? '/dashboard/search.php');
}

// --- Delete rule ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $rid = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT title FROM rules WHERE id = ? AND association_id = ?');
    $stmt->execute([$rid, $assocId]);
    $row = $stmt->fetch();
    if ($row) {
        db()->prepare('DELETE FROM rules WHERE id = ? AND association_id = ?')->execute([$rid, $assocId]);
        audit('rule.deleted', ['title' => $row['title']], $rid, 'rule');
        flash('success', "Rule \"{$row['title']}\" deleted.");
    }
    redirect('/dashboard/search.php');
}

// --- Add category ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cat_add') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name !== '') {
        try {
            db()->prepare(
                'INSERT INTO rule_categories (association_id, name, sort_order)
                 VALUES (?, ?, COALESCE((SELECT MAX(sort_order) FROM rule_categories AS x WHERE x.association_id = ?), 0) + 10)'
            )->execute([$assocId, $name, $assocId]);
            audit('rule_category.added', ['name' => $name], (int)db()->lastInsertId(), 'rule_category');
            flash('success', "Category \"$name\" added.");
        } catch (PDOException $e) {
            flash('error', "Category \"$name\" already exists.");
        }
    }
    redirect('/dashboard/search.php?action=categories');
}

// --- Rename category ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cat_rename') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $cid     = (int)($_POST['id'] ?? 0);
    $newName = trim((string)($_POST['name'] ?? ''));
    if ($cid && $newName !== '') {
        $oldStmt = db()->prepare('SELECT name FROM rule_categories WHERE id = ? AND association_id = ?');
        $oldStmt->execute([$cid, $assocId]);
        $oldName = (string)($oldStmt->fetchColumn() ?: '');
        if ($oldName === '') {
            flash('error', 'Category not found.');
        } elseif ($oldName !== $newName) {
            db()->beginTransaction();
            try {
                db()->prepare('UPDATE rule_categories SET name = ? WHERE id = ? AND association_id = ?')
                    ->execute([$newName, $cid, $assocId]);
                // Keep existing rules in sync so the rename propagates everywhere.
                db()->prepare('UPDATE rules SET category = ? WHERE association_id = ? AND category = ?')
                    ->execute([$newName, $assocId, $oldName]);
                db()->commit();
                audit('rule_category.renamed', ['from' => $oldName, 'to' => $newName], $cid, 'rule_category');
                flash('success', "Renamed \"$oldName\" → \"$newName\". All existing rules updated.");
            } catch (PDOException $e) {
                db()->rollBack();
                flash('error', "Could not rename — \"$newName\" is already in use.");
            }
        }
    }
    redirect('/dashboard/search.php?action=categories');
}

// --- Delete category ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cat_delete') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $cid = (int)($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM rule_categories WHERE id = ? AND association_id = ?')->execute([$cid, $assocId]);
    audit('rule_category.deleted', [], $cid, 'rule_category');
    flash('success', 'Category deleted. Existing rules keep the category label.');
    redirect('/dashboard/search.php?action=categories');
}

// --- CSV import ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'import') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $flashError = 'CSV upload failed.';
    } elseif ($_FILES['csv']['size'] > 2 * 1024 * 1024) {
        $flashError = 'Max CSV size is 2 MB.';
    } else {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            $flashError = 'Could not read CSV.';
        } else {
            $existingCats = [];
            $catStmt = db()->prepare('SELECT name FROM rule_categories WHERE association_id = ?');
            $catStmt->execute([$assocId]);
            foreach ($catStmt->fetchAll() as $c) $existingCats[mb_strtolower($c['name'])] = $c['name'];

            $added = 0; $errors = []; $row = 0; $headerMap = null;

            while (($cols = fgetcsv($fh)) !== false) {
                $row++;
                if ($cols === [null] || (count($cols) === 1 && trim((string)$cols[0]) === '')) continue;

                if ($headerMap === null) {
                    $headerMap = [];
                    foreach ($cols as $i => $name) {
                        $key = strtolower(trim(str_replace(' ', '_', (string)$name)));
                        $headerMap[$key] = $i;
                    }
                    foreach (['title','body'] as $req) {
                        if (!isset($headerMap[$req])) {
                            $flashError = "Missing required column: $req. Required: title, body. Optional: rule_number, category, source, effective_date.";
                            break 2;
                        }
                    }
                    continue;
                }

                $get   = fn($k) => isset($headerMap[$k], $cols[$headerMap[$k]]) ? trim((string)$cols[$headerMap[$k]]) : '';
                $title = $get('title');
                $body  = $get('body');
                $num   = $get('rule_number');
                $cat   = $get('category');
                $src   = strtolower($get('source')) ?: 'board_rule';
                $date  = $get('effective_date');
                if (!in_array($src, ['bylaw','board_rule','policy'], true)) $src = 'board_rule';
                if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';

                if ($title === '') { $errors[] = "Row $row: missing title"; continue; }
                if ($body === '')  { $errors[] = "Row $row: missing body";  continue; }

                if ($cat !== '' && !isset($existingCats[mb_strtolower($cat)])) {
                    db()->prepare('INSERT IGNORE INTO rule_categories (association_id, name) VALUES (?, ?)')
                        ->execute([$assocId, $cat]);
                    $existingCats[mb_strtolower($cat)] = $cat;
                }

                db()->prepare(
                    'INSERT INTO rules (association_id, title, body, category, source, rule_number, effective_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$assocId, $title, $body, $cat ?: null, $src, $num ?: null, $date ?: null]);
                $added++;
            }
            fclose($fh);
            $importSummary = ['added' => $added, 'errors' => $errors];
            audit('rules.imported', $importSummary);
        }
    }
}

// ============================================================================
// Helpers
// ============================================================================
function rule_form_validate(array $post): array
{
    $title  = trim((string)($post['title'] ?? ''));
    $body   = (string)($post['body'] ?? '');
    $cat    = trim((string)($post['category'] ?? ''));
    $source = $post['source'] ?? 'board_rule';
    $num    = trim((string)($post['rule_number'] ?? ''));
    $date   = trim((string)($post['effective_date'] ?? ''));
    if (!in_array($source, ['bylaw','board_rule','policy'], true)) $source = 'board_rule';
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';

    $bodyText = trim(strip_tags(str_replace(['&nbsp;', "\xc2\xa0"], ' ', $body)));
    $err = '';
    if ($title === '')      $err = 'Title is required.';
    elseif ($bodyText === '') $err = 'Body is required.';

    return [$title, $body, $cat, $source, $num, $date, $err];
}

// ============================================================================
// Data load
// ============================================================================

// Categories (used by add/edit form selects)
$catStmt = db()->prepare('SELECT id, name FROM rule_categories WHERE association_id = ? ORDER BY sort_order, name');
$catStmt->execute([$assocId]);
$categories = $catStmt->fetchAll();

// Edit target
$editRule = null;
if (($_GET['action'] ?? '') === 'edit' && $canManage) {
    $eid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM rules WHERE id = ? AND association_id = ?');
    $stmt->execute([$eid, $assocId]);
    $editRule = $stmt->fetch() ?: null;
}

// Search / list
$q       = trim((string)($_GET['q'] ?? ''));
$source  = $_GET['source'] ?? '';
$results = [];

// No search query → show all rules (newest first), so the page is useful as
// a browsable list. Was previously search-only, which made an association
// with hundreds of imported rules look empty until you typed something.
if ($q === '') {
    $sql = "SELECT id, title, body, category, source, rule_number, effective_date, review_flag, review_note
              FROM rules WHERE association_id = ?";
    $params = [$assocId];
    if (in_array($source, ['bylaw','board_rule','policy'], true)) {
        $sql .= ' AND source = ?'; $params[] = $source;
    }
    // Default order: rule_number (numeric where parseable, then string), then title.
    // Boards reference rules by number, so this is the natural reading order.
    $sql .= " ORDER BY CAST(rule_number AS UNSIGNED), rule_number, title LIMIT 200";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
}

if ($q !== '') {
    $sql = "SELECT id, title, body, category, source, rule_number, effective_date, review_flag, review_note,
                   MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE) AS score
            FROM rules
            WHERE association_id = ?
              AND MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE)";
    $params = [$q, $assocId, $q];
    if (in_array($source, ['bylaw','board_rule','policy'], true)) {
        $sql .= ' AND source = ?'; $params[] = $source;
    }
    $sql .= ' ORDER BY score DESC LIMIT 30';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    if (empty($results)) {
        $sql = "SELECT id, title, body, category, source, rule_number, effective_date, review_flag, review_note
                FROM rules WHERE association_id = ? AND (title LIKE ? OR body LIKE ?)";
        $params = [$assocId, "%$q%", "%$q%"];
        if (in_array($source, ['bylaw','board_rule','policy'], true)) {
            $sql .= ' AND source = ?'; $params[] = $source;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 30';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
    }
}

// AJAX response (live search)
if ($ajax) {
    if (empty($results)) {
        echo '<div class="card card--padded muted center">No matches for &ldquo;' . e($q) . '&rdquo;.</div>';
        exit;
    }
    foreach ($results as $r) {
        $excerpt = mb_strimwidth(strip_tags($r['body']), 0, 240, '…');
        $highlighted = preg_replace('/(' . preg_quote($q, '/') . ')/i', '<mark>$1</mark>', e($excerpt));
        echo '<div class="search-result">
                <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-2);">
                    <span class="badge badge--' . ($r['source'] === 'bylaw' ? 'navy' : ($r['source'] === 'policy' ? 'info' : 'orange')) . '">' . e(str_replace('_',' ',$r['source'])) . '</span>
                    ' . ($r['rule_number'] ? '<span class="muted" style="font-size: var(--fs-xs);">#' . e($r['rule_number']) . '</span>' : '') . '
                    ' . ($r['category'] ? '<span class="muted" style="font-size: var(--fs-xs);">&middot; ' . e($r['category']) . '</span>' : '') . '
                </div>
                <strong>' . e($r['title']) . '</strong>
                <p class="muted" style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm);">' . $highlighted . '</p>
              </div>';
    }
    exit;
}

// View flags
$showAdd  = ($_GET['action'] ?? '') === 'new'        && $canManage;
$showEdit = $editRule !== null;
$showCats = ($_GET['action'] ?? '') === 'categories' && $canManage;
$showImp  = ($_GET['action'] ?? '') === 'import'     && $canManage;
$showForm = $showAdd || $showEdit;

// Rule-suggestion view flags
$showSuggest      = ($_GET['action'] ?? '') === 'suggest';
$showSuggestQueue = ($_GET['action'] ?? '') === 'suggestions' && $canManage;
$approvingSug     = null;
if (($_GET['action'] ?? '') === 'approve' && $canManage) {
    $sid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare(
        'SELECT s.*, TRIM(CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,""))) AS suggester_name, u.email AS suggester_email
           FROM rule_suggestions s LEFT JOIN users u ON u.id = s.suggester_user_id
          WHERE s.id = ? AND s.association_id = ? AND s.status = "pending"'
    );
    $stmt->execute([$sid, $assocId]);
    $approvingSug = $stmt->fetch() ?: null;
}

// Pending suggestion counter (for the manager-facing banner / link badge).
$pendingSugCount = 0;
if ($canManage) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM rule_suggestions WHERE association_id = ? AND status = "pending"');
    $stmt->execute([$assocId]);
    $pendingSugCount = (int)$stmt->fetchColumn();
}

// Suggestions list for the review queue
$suggestionRows = [];
if ($showSuggestQueue) {
    $statusFilter = $_GET['status'] ?? 'pending';
    if (!in_array($statusFilter, ['pending','approved','rejected','all'], true)) $statusFilter = 'pending';
    $sugSql = 'SELECT s.*, TRIM(CONCAT(IFNULL(u.first_name,""), " ", IFNULL(u.last_name,""))) AS suggester_name, u.email AS suggester_email
                 FROM rule_suggestions s LEFT JOIN users u ON u.id = s.suggester_user_id
                WHERE s.association_id = ?';
    $sugParams = [$assocId];
    if ($statusFilter !== 'all') {
        $sugSql .= ' AND s.status = ?';
        $sugParams[] = $statusFilter;
    }
    $sugSql .= ' ORDER BY s.suggested_at DESC LIMIT 100';
    $stmt = db()->prepare($sugSql);
    $stmt->execute($sugParams);
    $suggestionRows = $stmt->fetchAll();
}

$page_title = 'Rules &amp; Bylaws — ' . $association['name'];
if ($showForm) {
    $page_extra_head = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">';
}
require __DIR__ . '/../includes/header.php';

// Helper for the rule form (used by both add and edit)
function rule_form_card(?array $editing, array $categories): void {
    $isEdit = $editing !== null;
    $vals = $editing ?? [];
    $action = $isEdit ? 'edit' : 'add';
    $title  = $vals['title']          ?? '';
    $cat    = $vals['category']       ?? '';
    $src    = $vals['source']         ?? 'board_rule';
    $num    = $vals['rule_number']    ?? '';
    $date   = $vals['effective_date'] ?? '';
    $body   = $vals['body']           ?? '';
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title"><?= $isEdit ? 'Edit rule' : 'New rule' ?></h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="?action=categories">Manage categories →</a>
        </div>
        <form method="post" class="form" data-rule-form>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="<?= e($action) ?>">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$vals['id'] ?>"><?php endif; ?>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="rtitle">Title</label>
                    <input class="input" id="rtitle" name="title" required value="<?= e($title) ?>" placeholder="No barbecues on balconies">
                </div>
                <div class="field">
                    <label class="field__label" for="rsource">Source</label>
                    <select class="select" id="rsource" name="source">
                        <option value="bylaw"      <?= $src==='bylaw'?'selected':'' ?>>Bylaw</option>
                        <option value="board_rule" <?= $src==='board_rule'?'selected':'' ?>>Board rule</option>
                        <option value="policy"     <?= $src==='policy'?'selected':'' ?>>Policy</option>
                    </select>
                </div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="rcat">Category</label>
                    <select class="select" id="rcat" name="category">
                        <option value="">— None —</option>
                        <?php
                        $haveMatch = false;
                        foreach ($categories as $c):
                            $sel = ($cat === $c['name']);
                            if ($sel) $haveMatch = true;
                        ?>
                            <option value="<?= e($c['name']) ?>" <?= $sel ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                        <?php if ($cat !== '' && !$haveMatch): ?>
                            <option value="<?= e($cat) ?>" selected><?= e($cat) ?> (legacy)</option>
                        <?php endif; ?>
                    </select>
                    <div class="field__hint"><a href="?action=categories">Add or manage categories →</a></div>
                </div>
                <div class="field">
                    <label class="field__label" for="rnum">Rule number</label>
                    <input class="input" id="rnum" name="rule_number" value="<?= e($num) ?>" placeholder="3.4.1">
                </div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="rdate">Effective date</label>
                    <input class="input" type="date" id="rdate" name="effective_date" value="<?= e((string)$date) ?>">
                    <div class="field__hint">When this rule went into effect.</div>
                </div>
                <div class="field"><!-- spacer --></div>
            </div>

            <div class="field">
                <label class="field__label">Body</label>
                <div id="rule-editor" data-initial-html="<?= e($body) ?>" style="background: #fff; border-radius: var(--r-md);"></div>
                <textarea name="body" id="rbody" hidden><?= e($body) ?></textarea>
                <div class="field__hint">Use the toolbar to format. Click the image button to upload photos directly into the rule.</div>
            </div>

            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/search.php">Cancel</a>
                <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Save rule' ?></button>
            </div>
        </form>
    </div>
    <?php
}
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1180px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Rules &amp; bylaws</h1>
            <p class="muted">Search, manage, and import your association's rules.</p>
        </div>
        <div class="row" style="gap: var(--sp-2); flex-wrap: wrap;">
            <a class="btn btn--ghost" href="/dashboard/rules-print.php" target="_blank" rel="noopener" title="Open a print-friendly listing of every rule in number order">🖨 Print all</a>
            <a class="btn <?= $canManage ? 'btn--ghost' : 'btn--primary' ?>" href="?action=suggest">+ Suggest a rule</a>
            <?php if ($canManage): ?>
                <a class="btn btn--ghost" href="?action=suggestions">
                    Suggestions<?php if ($pendingSugCount): ?>
                        <span class="badge badge--orange" style="margin-left: 6px;"><?= (int)$pendingSugCount ?></span>
                    <?php endif; ?>
                </a>
                <a class="btn btn--ghost" href="?action=categories">Categories</a>
                <a class="btn btn--ghost" href="?action=import">⬆ Import CSV</a>
                <a class="btn btn--primary" href="?action=new">+ Add rule</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canManage && $pendingSugCount > 0 && !$showSuggestQueue && !$approvingSug): ?>
    <div class="flash flash--warning" style="margin-bottom: var(--sp-4);">
        <strong><?= (int)$pendingSugCount ?> rule suggestion<?= $pendingSugCount === 1 ? '' : 's' ?> awaiting board review.</strong>
        <a href="?action=suggestions" style="margin-left: var(--sp-2);">Review now →</a>
    </div>
    <?php endif; ?>

    <?php if ($flashError): ?><div class="flash flash--error"><?= e($flashError) ?></div><?php endif; ?>
    <?php if ($importSummary): ?>
        <div class="flash flash--success">
            Imported <strong><?= (int)$importSummary['added'] ?></strong> rule<?= $importSummary['added']===1?'':'s' ?>.
            <?php if (!empty($importSummary['errors'])): ?>
                <details style="margin-top: var(--sp-2);">
                    <summary><?= count($importSummary['errors']) ?> row<?= count($importSummary['errors'])===1?'':'s' ?> errored</summary>
                    <ul style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm);">
                        <?php foreach ($importSummary['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($showForm): rule_form_card($editRule, $categories); ?>

    <input type="file" id="rule-img-input" accept="image/*" style="display:none;">
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
    <script>
        (function () {
            if (typeof Quill === 'undefined') return;
            var editorEl = document.getElementById('rule-editor');
            if (!editorEl) return;

            var hidden  = document.getElementById('rbody');
            var csrfTok = document.querySelector('input[name="_csrf"]').value;
            var initial = editorEl.getAttribute('data-initial-html') || '';

            var quill = new Quill('#rule-editor', {
                theme: 'snow',
                placeholder: 'Write the rule. Use the toolbar to format and the image button to add photos.',
                modules: {
                    toolbar: {
                        container: [
                            [{ 'header': [2, 3, false] }],
                            ['bold', 'italic', 'underline', 'strike'],
                            [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                            ['blockquote'],
                            ['link', 'image'],
                            ['clean']
                        ],
                        handlers: {
                            image: function () { document.getElementById('rule-img-input').click(); }
                        }
                    }
                }
            });
            editorEl.querySelector('.ql-editor').style.minHeight = '240px';

            // Pre-populate on edit
            if (initial) {
                quill.clipboard.dangerouslyPasteHTML(0, initial);
            }

            // Image upload
            var imgInput = document.getElementById('rule-img-input');
            imgInput.addEventListener('change', async function (ev) {
                var file = ev.target.files[0]; if (!file) return;
                var fd = new FormData();
                fd.append('file', file); fd.append('_csrf', csrfTok);
                try {
                    var res = await fetch('/dashboard/upload-image.php', {
                        method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF': csrfTok }
                    });
                    var data = await res.json();
                    if (data.ok && data.url) {
                        var range = quill.getSelection(true);
                        quill.insertEmbed(range.index, 'image', data.url, 'user');
                        quill.setSelection(range.index + 1);
                    } else { alert('Upload failed: ' + (data.error || 'unknown error')); }
                } catch (err) { alert('Upload failed: ' + err.message); }
                imgInput.value = '';
            });

            // Sync HTML to hidden textarea on submit
            var form = document.querySelector('form[data-rule-form]');
            if (form) form.addEventListener('submit', function () { hidden.value = quill.root.innerHTML; });
        })();
    </script>
    <?php endif; ?>

    <?php if ($showCats): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Manage categories</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/search.php">← Back to rules</a>
        </div>
        <p class="muted">These categories appear in the dropdown when adding or editing rules. Deleting a category here doesn't remove the label from existing rules.</p>

        <form method="post" class="row" style="gap: var(--sp-2); margin: var(--sp-4) 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="cat_add">
            <input class="input" name="name" required placeholder="New category name (e.g. Trash &amp; recycling)" style="flex: 1; max-width: 360px;">
            <button class="btn btn--primary" type="submit">Add category</button>
        </form>

        <?php if (!$categories): ?>
            <p class="muted">No categories yet.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table">
            <thead><tr><th>Name</th><th style="text-align:right; width: 220px;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td>
                        <form method="post" class="row" style="gap: var(--sp-2);">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="cat_rename">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <input class="input" name="name" value="<?= e((string)$c['name']) ?>" required style="max-width: 320px;">
                            <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Save</button>
                        </form>
                    </td>
                    <td style="text-align:right;">
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete category &quot;<?= e((string)$c['name']) ?>&quot;? Existing rules keep their label as a plain-text value.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="cat_delete">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($showImp): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Import rules from CSV</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/search.php">← Back to rules</a>
        </div>
        <p class="muted">
            Required columns: <code>title</code>, <code>body</code>.
            Optional: <code>rule_number</code>, <code>category</code>, <code>source</code>, <code>effective_date</code>.<br>
            <strong>source</strong> must be one of <code>bylaw</code>, <code>board_rule</code>, or <code>policy</code> (defaults to <code>board_rule</code> if blank).<br>
            <strong>effective_date</strong> must be in <code>YYYY-MM-DD</code> format.<br>
            Categories that don't exist yet are auto-created.
        </p>
        <p>
            <a class="btn btn--ghost" href="?download=template" download>⬇ Download template CSV</a>
        </p>
        <pre style="background: var(--color-surface-2); padding: var(--sp-3); border-radius: var(--r-md); font-size: var(--fs-xs); overflow-x:auto;">title,rule_number,category,source,effective_date,body
"No grilling on balconies","4.2.1","Common areas","board_rule","2025-01-15","Per fire-code regulations, gas and charcoal grills are prohibited on all unit balconies and patios."
"Pet weight limit","2.1","Pets","bylaw","2020-06-01","Pets must not exceed 50 lbs at maturity. Documentation required at move-in."</pre>

        <form method="post" enctype="multipart/form-data" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="import">
            <div class="field">
                <label class="field__label" for="csv">CSV file (max 2 MB)</label>
                <input class="input" type="file" id="csv" name="csv" accept=".csv,text/csv" required>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/search.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Import</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($showSuggest): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Suggest a rule</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/search.php">← Back to rules</a>
        </div>
        <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-4);">
            Submit a rule for the board to consider. They'll review and either approve it (with a final adoption date) or reply with feedback. You'll get an email when they decide.
        </p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="suggest_create">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="sg-title">Title</label>
                    <input class="input" id="sg-title" name="title" required maxlength="255" placeholder="No grilling on balconies">
                </div>
                <div class="field">
                    <label class="field__label" for="sg-source">Type</label>
                    <select class="select" id="sg-source" name="source">
                        <option value="board_rule" selected>Board rule</option>
                        <option value="bylaw">Bylaw amendment</option>
                        <option value="policy">Policy</option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="sg-cat">Category (optional)</label>
                <select class="select" id="sg-cat" name="category">
                    <option value="">— None / not sure —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= e((string)$c['name']) ?>"><?= e((string)$c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="sg-body">What are you suggesting?</label>
                <textarea class="textarea" id="sg-body" name="body" rows="6" required placeholder="Explain the rule and why it would help…"></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/search.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Submit for review</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($showSuggestQueue):
        $statusFilter = $_GET['status'] ?? 'pending';
    ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Rule suggestions from members</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="/dashboard/search.php">← Back to rules</a>
        </div>

        <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-4); flex-wrap: wrap;">
            <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'] as $val => $lbl): ?>
                <a class="badge <?= $statusFilter === $val ? 'badge--navy' : '' ?>" href="?action=suggestions&status=<?= e($val) ?>" style="text-decoration:none; <?= $statusFilter !== $val ? 'opacity: 0.6;' : '' ?>"><?= e($lbl) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (!$suggestionRows): ?>
            <p class="muted">No <?= e($statusFilter === 'all' ? '' : $statusFilter . ' ') ?>suggestions.</p>
        <?php else: ?>
        <div class="stack-md">
        <?php foreach ($suggestionRows as $sug):
            $statusBadge = match ($sug['status']) {
                'approved' => 'badge--success',
                'rejected' => 'badge--error',
                default    => 'badge--warning',
            };
        ?>
            <article class="card card--padded" style="border-left: 3px solid <?= $sug['status']==='pending' ? 'var(--color-orange)' : 'transparent' ?>;">
                <div class="row row--between" style="align-items:flex-start; margin-bottom: var(--sp-2);">
                    <div style="flex: 1; min-width: 0;">
                        <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-2); flex-wrap: wrap;">
                            <span class="badge <?= $statusBadge ?>"><?= e((string)$sug['status']) ?></span>
                            <span class="badge" style="background: var(--color-surface); color: var(--color-text-soft); font-size: var(--fs-xs);"><?= e((string)$sug['source']) ?></span>
                            <?php if (!empty($sug['category'])): ?>
                                <span class="muted" style="font-size: var(--fs-xs);"><?= e((string)$sug['category']) ?></span>
                            <?php endif; ?>
                            <span class="muted" style="font-size: var(--fs-xs);">
                                · suggested <?= e(date('M j, Y', strtotime((string)$sug['suggested_at']))) ?>
                                by <?= e(trim((string)$sug['suggester_name']) ?: (string)($sug['suggester_email'] ?? '—')) ?>
                            </span>
                        </div>
                        <h4 style="margin: 0 0 var(--sp-2); font-size: var(--fs-lg);"><?= e((string)$sug['title']) ?></h4>
                        <div class="muted" style="font-size: var(--fs-sm); white-space: pre-wrap; max-height: 200px; overflow-y: auto;"><?= e(trim(strip_tags(str_replace(['&nbsp;', "\xc2\xa0"], ' ', (string)$sug['body'])))) ?></div>
                        <?php if ($sug['status'] !== 'pending'): ?>
                            <div class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-2); padding-top: var(--sp-2); border-top: 1px solid var(--color-border);">
                                <?= e(ucfirst((string)$sug['status'])) ?> <?= $sug['reviewed_at'] ? 'on ' . e(date('M j, Y', strtotime((string)$sug['reviewed_at']))) : '' ?>
                                <?php if (!empty($sug['decision_note'])): ?>
                                    · <em>"<?= e((string)$sug['decision_note']) ?>"</em>
                                <?php endif; ?>
                                <?php if (!empty($sug['resulting_rule_id'])): ?>
                                    · <a href="?q=<?= urlencode((string)$sug['title']) ?>">View resulting rule →</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($sug['status'] === 'pending'): ?>
                        <div class="row" style="gap: var(--sp-2);">
                            <a class="btn btn--primary" href="?action=approve&id=<?= (int)$sug['id'] ?>" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);">Approve</a>
                            <form method="post" style="display:inline;" onsubmit="var n = prompt('Optional note to the suggester:'); if (n === null) return false; this.querySelector('[name=decision_note]').value = n; return true;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form" value="suggest_reject">
                                <input type="hidden" name="id" value="<?= (int)$sug['id'] ?>">
                                <input type="hidden" name="decision_note" value="">
                                <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);">Reject</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($approvingSug): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <div class="card__head">
            <h3 class="card__title">Approve suggestion</h3>
            <a class="muted" style="font-size: var(--fs-sm);" href="?action=suggestions">← Back to queue</a>
        </div>
        <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-4);">
            Edit any field before adopting. On submit, this becomes a real rule with the approval date set as the effective date, and the suggester gets an email.
        </p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="suggest_approve">
            <input type="hidden" name="id" value="<?= (int)$approvingSug['id'] ?>">

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ap-title">Title</label>
                    <input class="input" id="ap-title" name="title" required value="<?= e((string)$approvingSug['title']) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="ap-source">Source</label>
                    <select class="select" id="ap-source" name="source">
                        <?php foreach (['bylaw'=>'Bylaw','board_rule'=>'Board rule','policy'=>'Policy'] as $v=>$lbl): ?>
                            <option value="<?= e($v) ?>" <?= $approvingSug['source']===$v?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ap-cat">Category</label>
                    <select class="select" id="ap-cat" name="category">
                        <option value="">— None —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= e((string)$c['name']) ?>" <?= $approvingSug['category']===$c['name']?'selected':'' ?>><?= e((string)$c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="ap-num">Rule number (optional)</label>
                    <input class="input" id="ap-num" name="rule_number" placeholder="3.4.1">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="ap-date">Approval / effective date</label>
                    <input class="input" type="date" id="ap-date" name="approval_date" required value="<?= e(date('Y-m-d')) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="ap-note">Note to suggester (optional)</label>
                    <input class="input" id="ap-note" name="decision_note" placeholder="Thanks for the suggestion!">
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="ap-body">Rule body</label>
                <textarea class="textarea" id="ap-body" name="body" rows="6" required><?= e((string)$approvingSug['body']) ?></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="?action=suggestions">Cancel</a>
                <button class="btn btn--primary" type="submit">Approve and publish</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$showCats && !$showImp && !$showSuggest && !$showSuggestQueue && !$approvingSug): /* show search bar + results unless on a sub-view */ ?>
    <div data-live-search data-endpoint="/dashboard/search.php">
        <form class="search-bar" method="get">
            <span aria-hidden="true">🔎</span>
            <input type="search" name="q" placeholder="Search by keyword: pets, balconies, renovations, …" value="<?= e($q) ?>">
            <select class="select" name="source" style="max-width: 180px;">
                <option value="">Any source</option>
                <option value="bylaw"      <?= $source==='bylaw'?'selected':'' ?>>Bylaws</option>
                <option value="board_rule" <?= $source==='board_rule'?'selected':'' ?>>Board rules</option>
                <option value="policy"     <?= $source==='policy'?'selected':'' ?>>Policies</option>
            </select>
            <button class="btn btn--primary" type="submit">Search</button>
        </form>

        <div class="search-results" data-results>
            <?php if ($q === '' && !$results): ?>
                <div class="card card--padded muted center">No rules on file yet.<?= $canManage ? ' <a href="?action=new">Add the first one</a>.' : '' ?></div>
            <?php elseif ($q === ''): ?>
                <p class="muted" style="font-size: var(--fs-sm); margin-top: var(--sp-4);">Showing the most recent <?= count($results) ?> rule<?= count($results)===1?'':'s' ?>.</p>
                <?php foreach ($results as $r): ?>
                <div class="search-result">
                    <div class="row row--between" style="margin-bottom: var(--sp-2); align-items: baseline;">
                        <div class="row" style="gap: var(--sp-2); align-items: baseline;">
                            <?php if ($r['rule_number']): ?>
                                <strong style="font-size: var(--fs-md); color: var(--color-navy);">#<?= e($r['rule_number']) ?></strong>
                            <?php endif; ?>
                            <span class="badge badge--<?= $r['source']==='bylaw'?'navy':($r['source']==='policy'?'info':'orange') ?>"><?= e(str_replace('_',' ',$r['source'])) ?></span>
                            <?php if ($r['category']): ?>
                                <span class="badge" style="background: var(--color-warning-bg); color: var(--color-warning); border: 1px solid rgba(182,130,42,0.25);"><?= e($r['category']) ?></span>
                            <?php endif; ?>
                            <?php if ($r['effective_date']): ?><span class="muted" style="font-size: var(--fs-xs);">&middot; in effect <?= e(date('M j, Y', strtotime((string)$r['effective_date']))) ?></span><?php endif; ?>
                            <?php if (!empty($r['review_flag'])): ?>
                                <span class="badge badge--error" style="font-size: var(--fs-xs);" title="<?= e((string)($r['review_note'] ?? 'Flagged for board review')) ?>">🚩 Needs review</span>
                            <?php endif; ?>
                        </div>
                        <div class="row" style="gap: var(--sp-2);">
                            <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="/dashboard/rule.php?id=<?= (int)$r['id'] ?>">Open</a>
                            <?php if ($canManage): ?>
                            <?php if (empty($r['review_flag'])): ?>
                                <form method="post" style="display:inline;" onsubmit="var n = prompt('Optional note about why this rule needs review:'); if (n === null) return false; this.querySelector('[name=review_note]').value = n; return true;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form" value="review_flag">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="on" value="1">
                                    <input type="hidden" name="review_note" value="">
                                    <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" title="Flag for board review">🚩 Flag</button>
                                </form>
                            <?php endif; ?>
                            <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this rule?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);" type="submit">Delete</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <strong><?= e($r['title']) ?></strong>
                    <p class="muted rule-body-clamp" style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm); white-space: pre-wrap;"><?= e(trim(strip_tags(str_replace(['&nbsp;', "\xc2\xa0"], ' ', (string)$r['body'])))) ?></p>
                </div>
                <?php endforeach; ?>
            <?php elseif (!$results): ?>
                <div class="card card--padded muted center">No matches for &ldquo;<?= e($q) ?>&rdquo;.</div>
            <?php else: ?>
                <?php foreach ($results as $r):
                    $excerpt = mb_strimwidth(strip_tags($r['body']), 0, 240, '…');
                    $highlighted = preg_replace('/(' . preg_quote($q, '/') . ')/i', '<mark>$1</mark>', e($excerpt));
                ?>
                <div class="search-result">
                    <div class="row row--between" style="margin-bottom: var(--sp-2); align-items: baseline;">
                        <div class="row" style="gap: var(--sp-2); align-items: baseline;">
                            <?php if ($r['rule_number']): ?>
                                <strong style="font-size: var(--fs-md); color: var(--color-navy);">#<?= e($r['rule_number']) ?></strong>
                            <?php endif; ?>
                            <span class="badge badge--<?= $r['source']==='bylaw'?'navy':($r['source']==='policy'?'info':'orange') ?>"><?= e(str_replace('_',' ',$r['source'])) ?></span>
                            <?php if ($r['category']): ?>
                                <span class="badge" style="background: var(--color-warning-bg); color: var(--color-warning); border: 1px solid rgba(182,130,42,0.25);"><?= e($r['category']) ?></span>
                            <?php endif; ?>
                            <?php if ($r['effective_date']): ?><span class="muted" style="font-size: var(--fs-xs);">&middot; in effect <?= e(date('M j, Y', strtotime((string)$r['effective_date']))) ?></span><?php endif; ?>
                            <?php if (!empty($r['review_flag'])): ?>
                                <span class="badge badge--error" style="font-size: var(--fs-xs);" title="<?= e((string)($r['review_note'] ?? 'Flagged for board review')) ?>">🚩 Needs review</span>
                            <?php endif; ?>
                        </div>
                        <div class="row" style="gap: var(--sp-2);">
                            <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="/dashboard/rule.php?id=<?= (int)$r['id'] ?>">Open</a>
                            <?php if ($canManage): ?>
                            <?php if (empty($r['review_flag'])): ?>
                                <form method="post" style="display:inline;" onsubmit="var n = prompt('Optional note about why this rule needs review:'); if (n === null) return false; this.querySelector('[name=review_note]').value = n; return true;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form" value="review_flag">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="on" value="1">
                                    <input type="hidden" name="review_note" value="">
                                    <button class="btn btn--ghost" type="submit" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" title="Flag for board review">🚩 Flag</button>
                                </form>
                            <?php endif; ?>
                            <a class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs);" href="?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this rule?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn--ghost" style="padding: 0.4rem 0.75rem; font-size: var(--fs-xs); color: var(--color-error);" type="submit">Delete</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <strong><?= e($r['title']) ?></strong>
                    <p class="muted rule-body-clamp" style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm); white-space: pre-wrap;"><?= $highlighted ?></p>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
