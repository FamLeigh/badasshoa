<?php
require __DIR__ . '/_bootstrap.php';

$page_title = 'Legal Reference — ' . e((string)($association['name'] ?? 'Dashboard'));

// Normalize association state to a 2-letter code.
$STATE_NAMES = [
    'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
    'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
    'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID',
    'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
    'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
    'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS',
    'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
    'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
    'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK',
    'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC',
    'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT',
    'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV',
    'wisconsin' => 'WI', 'wyoming' => 'WY', 'district of columbia' => 'DC',
];
$raw = strtolower(trim((string)($association['state_region'] ?? '')));
$stateCode = strlen($raw) === 2
    ? strtoupper($raw)
    : ($STATE_NAMES[$raw] ?? strtoupper($raw));

$q          = trim((string)($_GET['q'] ?? ''));
$filterCat  = trim((string)($_GET['cat'] ?? ''));
$filterApp  = trim((string)($_GET['applies'] ?? ''));
$filterCh   = trim((string)($_GET['chapter'] ?? ''));

// Check if we have any statutes for this state at all.
$_hd = db()->prepare('SELECT COUNT(*) FROM statutes WHERE state_code = ?');
$_hd->execute([$stateCode]);
$hasData = (int)$_hd->fetchColumn() > 0;

// Load filter options (categories and applies_to values for this state).
$catStmt = db()->prepare('SELECT DISTINCT category FROM statutes WHERE state_code = ? AND category IS NOT NULL ORDER BY category');
$catStmt->execute([$stateCode]);
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

$appStmt = db()->prepare('SELECT DISTINCT applies_to FROM statutes WHERE state_code = ? AND applies_to != \'\' ORDER BY applies_to');
$appStmt->execute([$stateCode]);
$applyValues = $appStmt->fetchAll(PDO::FETCH_COLUMN);

$chStmt = db()->prepare('SELECT DISTINCT chapter, chapter_title FROM statutes WHERE state_code = ? AND chapter != \'\' ORDER BY chapter+0, chapter');
$chStmt->execute([$stateCode]);
$chapters = $chStmt->fetchAll();

// Build query.
$results   = [];
$totalRows = 0;
$isSearch  = $q !== '';

if ($hasData) {
    $where  = ['state_code = ?'];
    $params = [$stateCode];

    if ($filterCat !== '') {
        $where[]  = 'category = ?';
        $params[] = $filterCat;
    }
    if ($filterApp !== '') {
        $where[]  = 'applies_to = ?';
        $params[] = $filterApp;
    }
    if ($filterCh !== '') {
        $where[]  = 'chapter = ?';
        $params[] = $filterCh;
    }

    if ($isSearch) {
        // Sanitize boolean mode query — wrap bare terms in + to require them.
        $safeQ = preg_replace('/[+\-><()\~*"@]+/', ' ', $q);
        $safeQ = implode(' ', array_filter(array_map(function($w) {
            $w = trim($w);
            return strlen($w) >= 2 ? '+' . $w . '*' : '';
        }, preg_split('/\s+/', $safeQ))));

        if ($safeQ === '') {
            $results = [];
        } else {
            $where[] = 'MATCH(section_title, keywords, summary) AGAINST (? IN BOOLEAN MODE)';
            $params[] = $safeQ;

            $countSql = 'SELECT COUNT(*) FROM statutes WHERE ' . implode(' AND ', $where);
            $cs = db()->prepare($countSql);
            $cs->execute($params);
            $totalRows = (int)$cs->fetchColumn();

            $sql = 'SELECT id, chapter, chapter_title, applies_to, section, section_title,
                           category, subcategory, summary, has_full_text, full_text, source_url,
                           MATCH(section_title, keywords, summary) AGAINST (? IN BOOLEAN MODE) AS relevance
                      FROM statutes
                     WHERE ' . implode(' AND ', $where) . '
                     ORDER BY relevance DESC, section ASC
                     LIMIT 100';
            $params = array_merge([$safeQ], $params);
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();
        }
    } else {
        // Browse mode — all sections for the state (with active filters), paginated.
        $countSql = 'SELECT COUNT(*) FROM statutes WHERE ' . implode(' AND ', $where);
        $cs = db()->prepare($countSql);
        $cs->execute($params);
        $totalRows = (int)$cs->fetchColumn();

        $sql = 'SELECT id, chapter, chapter_title, applies_to, section, section_title,
                       category, subcategory, summary, has_full_text, full_text, source_url
                  FROM statutes
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY chapter+0, chapter, section+0, section
                 LIMIT 100';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
    }
}

$APPLIES_COLORS = [
    'HOA'        => '#274988',
    'Condo'      => '#2f6296',
    'Coop'       => '#5a2d82',
    'Nonprofit'  => '#1a6e4a',
    'Building'   => '#6b4a1e',
];

require __DIR__ . '/../includes/header.php';
?>

<style>
.legal-hero { padding: var(--sp-6) 0 var(--sp-4); }
.legal-hero h1 { margin: 0 0 0.2em; }
.legal-hero p  { margin: 0; color: var(--color-text-soft); font-size: var(--fs-sm); }

.legal-search-bar {
    display: flex; align-items: stretch; gap: 0;
    background: #fff; border: 2px solid var(--color-border);
    border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(15,31,61,.07);
    transition: border-color 120ms;
}
.legal-search-bar:focus-within { border-color: var(--color-navy); }
.legal-search-bar input[type="search"] {
    flex: 1; border: 0; background: transparent; outline: 0;
    font: inherit; font-size: 15px; padding: 13px 18px; color: var(--color-text);
}
.legal-search-bar input[type="search"]::placeholder { color: var(--color-text-soft); }
.legal-search-bar button[type="submit"] {
    background: var(--color-navy); color: #fff; border: 0;
    padding: 0 22px; font: inherit; font-weight: 700; font-size: 14px;
    cursor: pointer; white-space: nowrap; letter-spacing: .03em;
    transition: background 120ms;
}
.legal-search-bar button[type="submit"]:hover { background: #1a3060; }

.legal-filters {
    display: flex; gap: var(--sp-3); flex-wrap: wrap; align-items: flex-end;
    margin-top: var(--sp-3);
}
.legal-filters .form-group { margin: 0; }
.legal-filters select { font-size: var(--fs-sm); padding: 6px 10px; }

.legal-results-meta {
    font-size: var(--fs-sm); color: var(--color-text-soft);
    margin: var(--sp-4) 0 var(--sp-3);
}

.statute-card {
    background: #fff; border: 1px solid var(--color-border);
    border-radius: 8px; padding: var(--sp-4);
    margin-bottom: var(--sp-3);
    transition: box-shadow 120ms;
}
.statute-card:hover { box-shadow: 0 3px 12px rgba(15,31,61,.1); }
.statute-card__head {
    display: flex; align-items: flex-start; gap: var(--sp-3); flex-wrap: wrap;
    margin-bottom: var(--sp-2);
}
.statute-card__section {
    font-family: monospace; font-size: 12px; background: #f3edd9;
    color: #5d4a00; padding: 3px 8px; border-radius: 5px; white-space: nowrap;
    flex-shrink: 0;
}
.statute-card__title { font-weight: 700; font-size: 15px; flex: 1; }
.statute-card__badges { display: flex; gap: 6px; flex-wrap: wrap; flex-shrink: 0; }
.statute-badge {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
    padding: 2px 8px; border-radius: 999px; color: #fff; white-space: nowrap;
}
.statute-card__meta { font-size: var(--fs-sm); color: var(--color-text-soft); margin-bottom: var(--sp-2); }
.statute-card__summary { font-size: 14px; line-height: 1.6; }
.statute-card__actions {
    margin-top: var(--sp-3); display: flex; gap: var(--sp-2); flex-wrap: wrap; align-items: center;
}
.statute-card__expand-btn {
    display: inline-flex; align-items: center; gap: 6px;
    background: #f3edd9; color: #5d4a00; border: 1px solid #d8c98a;
    border-radius: 6px; padding: 6px 14px; font: inherit; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: background 120ms;
}
.statute-card__expand-btn:hover { background: #e8de9e; }
.statute-card__expand-btn .chevron { font-size: 10px; transition: transform 150ms; }
.statute-card__expand-btn[aria-expanded="true"] .chevron { transform: rotate(90deg); }
.statute-card__source-btn {
    display: inline-flex; align-items: center; gap: 6px;
    background: #fff; color: var(--color-navy); border: 1px solid var(--color-border);
    border-radius: 6px; padding: 6px 14px; font: inherit; font-size: 13px; font-weight: 600;
    text-decoration: none; transition: border-color 120ms, background 120ms;
}
.statute-card__source-btn:hover { background: #f3f4f8; border-color: var(--color-navy); text-decoration: none; }
.statute-card__full-text {
    margin-top: var(--sp-3); padding: var(--sp-4); background: #f8f7f4;
    border: 1px solid var(--color-border); border-left: 3px solid var(--color-navy);
    border-radius: 6px;
    font-size: 13.5px; line-height: 1.75; white-space: pre-wrap; word-break: break-word;
    max-height: 520px; overflow-y: auto;
    display: none;
}
.statute-card__full-text.is-open { display: block; }

.legal-empty {
    text-align: center; padding: var(--sp-8) var(--sp-4);
    background: #fff; border: 1px solid var(--color-border); border-radius: 8px;
    color: var(--color-text-soft);
}
.legal-empty__icon { font-size: 40px; margin-bottom: var(--sp-3); }
.legal-empty h3 { margin: 0 0 0.4em; color: var(--color-text); }
.legal-empty p  { margin: 0; font-size: var(--fs-sm); }

.legal-no-data {
    background: #fff8e6; border: 1px solid #d8b54d; border-radius: 8px;
    padding: var(--sp-4); margin-top: var(--sp-4);
}
</style>

<div class="container">
<div class="legal-hero">
    <h1>Legal Reference</h1>
    <p>
        <?php if ($stateCode): ?>
            <?= e($stateCode) ?> state statutes relevant to community associations.
        <?php else: ?>
            State statutes for community associations.
        <?php endif; ?>
    </p>
</div>

<?php if (!$hasData): ?>
<div class="legal-no-data">
    <strong>No statutes loaded for <?= e($stateCode ?: 'your state') ?> yet.</strong>
    The BadassHOA team periodically imports statute libraries. Check back soon, or
    <?php if (($_SESSION['role'] ?? '') === 'super_admin'): ?>
        <a href="/admin/legal.php">import a CSV now →</a>
    <?php else: ?>
        contact your association administrator.
    <?php endif; ?>
</div>
<?php else: ?>

<form method="get" action="/dashboard/legal.php" id="legal-form">
    <div class="legal-search-bar">
        <input
            type="search"
            name="q"
            placeholder="Search statutes — e.g. "pet policy" or "annual meeting notice""
            value="<?= e($q) ?>"
            autocomplete="off"
            id="legal-q"
        >
        <?php if ($filterCat !== ''): ?><input type="hidden" name="cat" value="<?= e($filterCat) ?>"><?php endif; ?>
        <?php if ($filterApp !== ''): ?><input type="hidden" name="applies" value="<?= e($filterApp) ?>"><?php endif; ?>
        <?php if ($filterCh !== ''): ?><input type="hidden" name="chapter" value="<?= e($filterCh) ?>"><?php endif; ?>
        <button type="submit">Search</button>
    </div>

    <div class="legal-filters">
        <?php if ($chapters): ?>
        <div class="form-group">
            <label class="form-label" style="font-size:12px;" for="f-chapter">Chapter</label>
            <select name="chapter" id="f-chapter" class="form-select" onchange="this.form.submit()">
                <option value="">All chapters</option>
                <?php foreach ($chapters as $ch): ?>
                    <option value="<?= e($ch['chapter']) ?>" <?= $filterCh === $ch['chapter'] ? 'selected' : '' ?>>
                        Ch. <?= e($ch['chapter']) ?><?= $ch['chapter_title'] ? ' — ' . e(mb_strimwidth($ch['chapter_title'], 0, 50, '…')) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if ($applyValues): ?>
        <div class="form-group">
            <label class="form-label" style="font-size:12px;" for="f-applies">Applies to</label>
            <select name="applies" id="f-applies" class="form-select" onchange="this.form.submit()">
                <option value="">All types</option>
                <?php foreach ($applyValues as $av): ?>
                    <option value="<?= e($av) ?>" <?= $filterApp === $av ? 'selected' : '' ?>><?= e($av) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if ($categories): ?>
        <div class="form-group">
            <label class="form-label" style="font-size:12px;" for="f-cat">Category</label>
            <select name="cat" id="f-cat" class="form-select" onchange="this.form.submit()">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c) ?>" <?= $filterCat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if ($q || $filterCat || $filterApp || $filterCh): ?>
        <div class="form-group" style="display:flex; align-items:flex-end;">
            <a href="/dashboard/legal.php" class="btn btn--ghost" style="font-size:var(--fs-sm); padding: 6px 14px;">Clear filters</a>
        </div>
        <?php endif; ?>
    </div>
</form>

<?php if ($isSearch && $q !== '' && empty($results) && isset($safeQ) && $safeQ === ''): ?>
<div class="legal-empty" style="margin-top: var(--sp-4);">
    <div class="legal-empty__icon">⚖️</div>
    <h3>Search term too short</h3>
    <p>Enter at least 2 characters to search.</p>
</div>
<?php elseif ($results !== [] || !$isSearch): ?>

<div class="legal-results-meta">
    <?php if ($isSearch): ?>
        <?= number_format($totalRows) ?> result<?= $totalRows === 1 ? '' : 's' ?> for <strong>"<?= e($q) ?>"</strong>
        <?php if ($totalRows > 100): ?> — showing first 100<?php endif; ?>
    <?php else: ?>
        <?= number_format($totalRows) ?> section<?= $totalRows === 1 ? '' : 's' ?>
        <?php if ($totalRows > 100): ?> — showing first 100 · use search or filters to narrow down<?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!$results): ?>
<div class="legal-empty">
    <div class="legal-empty__icon">⚖️</div>
    <h3>No statutes found</h3>
    <p>Try different search terms or clear the filters.</p>
</div>
<?php else: ?>

<?php foreach ($results as $r): ?>
<?php
$appColor = $APPLIES_COLORS[$r['applies_to']] ?? '#555';
$chLabel  = 'Ch. ' . $r['chapter'];
if (!empty($r['chapter_title'])) {
    $chLabel .= ' · ' . mb_strimwidth($r['chapter_title'], 0, 60, '…');
}
?>
<div class="statute-card">
    <div class="statute-card__head">
        <span class="statute-card__section">§ <?= e($r['section']) ?></span>
        <span class="statute-card__title"><?= e($r['section_title']) ?></span>
        <div class="statute-card__badges">
            <?php if ($r['applies_to']): ?>
            <span class="statute-badge" style="background: <?= e($appColor) ?>;"><?= e($r['applies_to']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="statute-card__meta">
        <?= e($chLabel) ?>
        <?php if ($r['category']): ?> · <?= e($r['category']) ?><?php endif; ?>
        <?php if ($r['subcategory']): ?> / <?= e($r['subcategory']) ?><?php endif; ?>
    </div>

    <div class="statute-card__summary"><?= e($r['summary']) ?></div>

    <?php
    $hasFullText = $r['has_full_text'] && !empty($r['full_text']);
    $hasUrl      = !empty($r['source_url']);
    if ($hasFullText || $hasUrl):
        $uid = 'ft-' . e($r['id']);
    ?>
    <div class="statute-card__actions">
        <?php if ($hasFullText): ?>
        <button type="button" class="statute-card__expand-btn"
                aria-expanded="false" aria-controls="<?= $uid ?>"
                onclick="toggleFullText(this,'<?= $uid ?>')">
            <span class="chevron">▶</span> Full statutory text
        </button>
        <?php endif; ?>
        <?php if ($hasUrl): ?>
        <a class="statute-card__source-btn" href="<?= e($r['source_url']) ?>"
           target="_blank" rel="noopener noreferrer">
            Official site ↗
        </a>
        <?php endif; ?>
    </div>
    <?php if ($hasFullText): ?>
    <div class="statute-card__full-text" id="<?= $uid ?>"><?= e((string)$r['full_text']) ?></div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($totalRows > 100): ?>
<p style="text-align:center; color: var(--color-text-soft); font-size: var(--fs-sm); margin-top: var(--sp-3);">
    Showing 100 of <?= number_format($totalRows) ?> results. Refine your search to see more specific results.
</p>
<?php endif; ?>

<?php endif; // results ?>
<?php endif; // search / browse ?>
<?php endif; // hasData ?>
</div>

<script>
function toggleFullText(btn, id) {
    var el = document.getElementById(id);
    if (!el) return;
    var open = el.classList.toggle('is-open');
    btn.setAttribute('aria-expanded', String(open));
    if (open) el.scrollTop = 0;
}

// Auto-focus search on page load (only if q is empty, avoid interrupting filter changes)
(function () {
    var inp = document.getElementById('legal-q');
    if (inp && !inp.value) inp.focus();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
