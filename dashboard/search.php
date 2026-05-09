<?php
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$canManage = (ROLE_RANK[$user['role']] ?? 0) >= ROLE_RANK['board_admin'];
$ajax = isset($_GET['ajax']);

// Add-rule handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
    csrf_check();
    if (!$canManage) { http_response_code(403); die('Forbidden'); }
    $title  = trim((string)($_POST['title'] ?? ''));
    $body   = trim((string)($_POST['body'] ?? ''));
    $cat    = trim((string)($_POST['category'] ?? ''));
    $source = $_POST['source'] ?? 'board_rule';
    $num    = trim((string)($_POST['rule_number'] ?? ''));
    if (!in_array($source, ['bylaw','board_rule','policy'], true)) $source = 'board_rule';
    if ($title !== '' && $body !== '') {
        $stmt = db()->prepare(
            'INSERT INTO rules (association_id, title, body, category, source, rule_number) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$assocId, $title, $body, $cat ?: null, $source, $num ?: null]);
        $newId = (int)db()->lastInsertId();
        audit('rule.added', ['title' => $title, 'source' => $source], $newId, 'rule');
        flash('success', "Rule \"$title\" added.");
        redirect('/dashboard/search.php');
    }
}

// Search
$q       = trim((string)($_GET['q'] ?? ''));
$source  = $_GET['source'] ?? '';
$results = [];

if ($q !== '') {
    $sql = "SELECT id, title, body, category, source, rule_number, effective_date,
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
        // FULLTEXT misses short queries / single keywords; fall back to LIKE
        $sql = "SELECT id, title, body, category, source, rule_number, effective_date
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

// AJAX response: return only the results markup.
if ($ajax) {
    if (empty($results)) {
        echo '<div class="card card--padded muted center">No matches for &ldquo;' . e($q) . '&rdquo;.</div>';
        exit;
    }
    foreach ($results as $r) {
        $excerpt = mb_strimwidth(strip_tags($r['body']), 0, 240, '…');
        // Highlight (very simple, case-insensitive)
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

$showAdd = ($_GET['action'] ?? '') === 'new' && $canManage;
$page_title = 'Rules &amp; Bylaws — ' . $association['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1180px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Rules &amp; bylaws</h1>
            <p class="muted">Search every rule on file. Live results.</p>
        </div>
        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="?action=new">+ Add rule</a>
        <?php endif; ?>
    </div>

    <?php if ($showAdd): ?>
    <div class="card card--padded" style="margin-bottom: var(--sp-6);">
        <h3 class="card__title">New rule</h3>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="add">
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="rtitle">Title</label>
                    <input class="input" id="rtitle" name="title" required placeholder="No barbecues on balconies">
                </div>
                <div class="field">
                    <label class="field__label" for="rsource">Source</label>
                    <select class="select" id="rsource" name="source">
                        <option value="bylaw">Bylaw</option>
                        <option value="board_rule" selected>Board rule</option>
                        <option value="policy">Policy</option>
                    </select>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="rcat">Category</label>
                    <input class="input" id="rcat" name="category" placeholder="Pets / Common areas / Renovations">
                </div>
                <div class="field">
                    <label class="field__label" for="rnum">Rule number</label>
                    <input class="input" id="rnum" name="rule_number" placeholder="3.4.1">
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="rbody">Body</label>
                <textarea class="textarea" id="rbody" name="body" rows="5" required></textarea>
            </div>
            <div class="row" style="justify-content: flex-end;">
                <a class="btn btn--ghost" href="/dashboard/search.php">Cancel</a>
                <button class="btn btn--primary" type="submit">Save rule</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div data-live-search data-endpoint="/dashboard/search.php">
        <form class="search-bar" method="get">
            <span aria-hidden="true">🔎</span>
            <input type="search" name="q" placeholder="What are you looking for? e.g. pets, balconies, renovations" value="<?= e($q) ?>" autofocus>
            <select class="select" name="source" style="max-width: 180px;">
                <option value="">Any source</option>
                <option value="bylaw"      <?= $source==='bylaw'?'selected':'' ?>>Bylaws</option>
                <option value="board_rule" <?= $source==='board_rule'?'selected':'' ?>>Board rules</option>
                <option value="policy"     <?= $source==='policy'?'selected':'' ?>>Policies</option>
            </select>
            <button class="btn btn--primary" type="submit">Search</button>
        </form>

        <div class="search-results" data-results>
            <?php if ($q === ''): ?>
                <div class="card card--padded muted center">Type a term above to search this association&rsquo;s rules and bylaws.</div>
            <?php elseif (!$results): ?>
                <div class="card card--padded muted center">No matches for &ldquo;<?= e($q) ?>&rdquo;.</div>
            <?php else: ?>
                <?php foreach ($results as $r):
                    $excerpt = mb_strimwidth(strip_tags($r['body']), 0, 240, '…');
                    $highlighted = preg_replace('/(' . preg_quote($q, '/') . ')/i', '<mark>$1</mark>', e($excerpt));
                ?>
                <div class="search-result">
                    <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-2);">
                        <span class="badge badge--<?= $r['source']==='bylaw'?'navy':($r['source']==='policy'?'info':'orange') ?>"><?= e(str_replace('_',' ',$r['source'])) ?></span>
                        <?php if ($r['rule_number']): ?><span class="muted" style="font-size: var(--fs-xs);">#<?= e($r['rule_number']) ?></span><?php endif; ?>
                        <?php if ($r['category']): ?><span class="muted" style="font-size: var(--fs-xs);">&middot; <?= e($r['category']) ?></span><?php endif; ?>
                    </div>
                    <strong><?= e($r['title']) ?></strong>
                    <p class="muted" style="margin: var(--sp-2) 0 0; font-size: var(--fs-sm);"><?= $highlighted ?></p>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
