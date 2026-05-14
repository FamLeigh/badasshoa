<?php
require __DIR__ . '/_bootstrap.php';

$page_title = 'Legal / Statutes — BadassHOA Admin';
$flash = [];

// --- CSV import -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'import') {
    csrf_check();

    $file = $_FILES['csv_file'] ?? null;
    $deleteFirst = !empty($_POST['delete_state']) && preg_match('/^[A-Z]{2}$/', (string)$_POST['delete_state']);
    $deleteState = $deleteFirst ? strtoupper((string)$_POST['delete_state']) : null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $flash = ['type' => 'error', 'msg' => 'Upload failed (error code ' . ($file['error'] ?? '?') . ').'];
    } elseif (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $flash = ['type' => 'error', 'msg' => 'File must be a .csv.'];
    } else {
        $fh = fopen($file['tmp_name'], 'r');
        if (!$fh) {
            $flash = ['type' => 'error', 'msg' => 'Could not open uploaded file.'];
        } else {
            $header = fgetcsv($fh);
            if (!$header) {
                $flash = ['type' => 'error', 'msg' => 'CSV appears empty or malformed.'];
                fclose($fh);
            } else {
                $header = array_map('trim', $header);
                $col = array_flip($header);
                $required = ['id', 'state_code', 'chapter', 'section', 'section_title', 'summary'];
                $missing  = array_diff($required, $header);
                if ($missing) {
                    $flash = ['type' => 'error', 'msg' => 'CSV is missing required columns: ' . implode(', ', $missing)];
                    fclose($fh);
                } else {
                    $get = function(array $row, string $key) use ($col): ?string {
                        if (!isset($col[$key])) return null;
                        $v = $row[$col[$key]] ?? '';
                        $v = trim((string)$v);
                        return $v === '' ? null : $v;
                    };

                    if ($deleteState) {
                        db()->prepare('DELETE FROM statutes WHERE state_code = ?')->execute([$deleteState]);
                    }

                    $sql = "INSERT INTO statutes
                                (id, jurisdiction, state_code, chapter, chapter_title, applies_to,
                                 part, part_title, section, section_title, category, subcategory,
                                 keywords, summary, full_text, has_full_text, effective_date, source_url, last_scraped)
                            VALUES
                                (:id, :jurisdiction, :state_code, :chapter, :chapter_title, :applies_to,
                                 :part, :part_title, :section, :section_title, :category, :subcategory,
                                 :keywords, :summary, :full_text, :has_full_text, :effective_date, :source_url, :last_scraped)
                            ON DUPLICATE KEY UPDATE
                                jurisdiction   = VALUES(jurisdiction),
                                chapter        = VALUES(chapter),
                                chapter_title  = VALUES(chapter_title),
                                applies_to     = VALUES(applies_to),
                                part           = VALUES(part),
                                part_title     = VALUES(part_title),
                                section_title  = VALUES(section_title),
                                category       = VALUES(category),
                                subcategory    = VALUES(subcategory),
                                keywords       = VALUES(keywords),
                                summary        = VALUES(summary),
                                full_text      = VALUES(full_text),
                                has_full_text  = VALUES(has_full_text),
                                effective_date = VALUES(effective_date),
                                source_url     = VALUES(source_url),
                                last_scraped   = VALUES(last_scraped)";

                    $stmt = db()->prepare($sql);
                    $inserted = 0;
                    $errors   = 0;
                    $rowNum   = 1;

                    db()->beginTransaction();
                    try {
                        while (($row = fgetcsv($fh)) !== false) {
                            $rowNum++;
                            $id = $get($row, 'id');
                            $section = $get($row, 'section');
                            if (!$id || !$section) { $errors++; continue; }

                            $hasFullText = strtolower((string)($get($row, 'has_full_text') ?? 'false'));

                            $stmt->execute([
                                ':id'            => $id,
                                ':jurisdiction'  => $get($row, 'jurisdiction') ?? '',
                                ':state_code'    => strtoupper((string)($get($row, 'state_code') ?? '')),
                                ':chapter'       => $get($row, 'chapter') ?? '',
                                ':chapter_title' => $get($row, 'chapter_title') ?? '',
                                ':applies_to'    => $get($row, 'applies_to') ?? '',
                                ':part'          => $get($row, 'part'),
                                ':part_title'    => $get($row, 'part_title'),
                                ':section'       => $section,
                                ':section_title' => $get($row, 'section_title') ?? '',
                                ':category'      => $get($row, 'category'),
                                ':subcategory'   => $get($row, 'subcategory'),
                                ':keywords'      => $get($row, 'keywords'),
                                ':summary'       => $get($row, 'summary') ?? '',
                                ':full_text'     => $get($row, 'full_text'),
                                ':has_full_text' => in_array($hasFullText, ['true', '1', 'yes'], true) ? 1 : 0,
                                ':effective_date'=> $get($row, 'effective_date'),
                                ':source_url'    => $get($row, 'source_url'),
                                ':last_scraped'  => $get($row, 'last_scraped'),
                            ]);
                            $inserted++;
                        }
                        db()->commit();
                    } catch (Throwable $e) {
                        db()->rollBack();
                        $flash = ['type' => 'error', 'msg' => 'DB error on row ' . $rowNum . ': ' . $e->getMessage()];
                        $inserted = 0;
                        $errors   = 0;
                    }
                    fclose($fh);

                    if (empty($flash)) {
                        $msg = 'Imported ' . number_format($inserted) . ' statute' . ($inserted === 1 ? '' : 's') . '.';
                        if ($errors) $msg .= ' Skipped ' . $errors . ' row(s) with missing id or section.';
                        $flash = ['type' => 'success', 'msg' => $msg];
                        audit('statutes.import', ['rows' => $inserted, 'skipped' => $errors]);
                    }
                }
            }
        }
    }
}

// --- Stats ---------------------------------------------------------------
$stats = db()->query(
    "SELECT state_code, chapter, applies_to, COUNT(*) as cnt
       FROM statutes
      GROUP BY state_code, chapter, applies_to
      ORDER BY state_code, chapter, applies_to"
)->fetchAll();

$totals = [];
foreach ($stats as $row) {
    $totals[$row['state_code']] = ($totals[$row['state_code']] ?? 0) + (int)$row['cnt'];
}

require __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top: var(--sp-6); padding-bottom: var(--sp-8);">

    <div class="row row--between" style="align-items: flex-start; margin-bottom: var(--sp-5); flex-wrap: wrap; gap: var(--sp-3);">
        <div>
            <h1 style="margin: 0 0 0.2em;">Legal / Statutes</h1>
            <p style="margin: 0; color: var(--color-text-soft); font-size: var(--fs-sm);">
                Import and manage state statute libraries. Statutes are shared across all associations in a state.
            </p>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert--<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" style="margin-bottom: var(--sp-4);">
        <?= e($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <!-- Current stats -->
    <div class="card" style="margin-bottom: var(--sp-5);">
        <div class="card__header"><h2 class="card__title">Current statute data</h2></div>
        <div class="card__body">
        <?php if (!$stats): ?>
            <p style="color: var(--color-text-soft); margin: 0;">No statutes imported yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>State</th>
                        <th>Chapter</th>
                        <th>Applies to</th>
                        <th style="text-align:right;">Sections</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($stats as $r): ?>
                    <tr>
                        <td><strong><?= e($r['state_code']) ?></strong></td>
                        <td><?= e($r['chapter']) ?></td>
                        <td><?= e($r['applies_to']) ?></td>
                        <td style="text-align:right;"><?= number_format((int)$r['cnt']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php foreach ($totals as $sc => $n): ?>
                    <tr>
                        <td colspan="3" style="font-weight:600;"><?= e($sc) ?> total</td>
                        <td style="text-align:right; font-weight:600;"><?= number_format($n) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tfoot>
            </table>
        <?php endif; ?>
        </div>
    </div>

    <!-- Import form -->
    <div class="card">
        <div class="card__header"><h2 class="card__title">Import CSV</h2></div>
        <div class="card__body">
            <p style="color: var(--color-text-soft); font-size: var(--fs-sm); margin-bottom: var(--sp-4);">
                Upload a CSV with columns: <code>id, jurisdiction, state_code, chapter, chapter_title, applies_to, part, part_title, section, section_title, category, subcategory, keywords, summary, full_text, has_full_text, effective_date, source_url, last_scraped</code>.<br>
                Existing rows are updated in place (matched by <code>id</code>). Optionally delete all rows for a state first.
            </p>
            <form method="post" enctype="multipart/form-data" action="/admin/legal.php">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="import">

                <div class="form-row" style="margin-bottom: var(--sp-4);">
                    <label class="form-label" for="csv_file">CSV file <span style="color:var(--color-error);">*</span></label>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required class="form-input" style="max-width:480px;">
                </div>

                <div class="form-row" style="margin-bottom: var(--sp-4);">
                    <label class="form-label" for="delete_state">Delete existing rows for state before import</label>
                    <select id="delete_state" name="delete_state" class="form-select" style="max-width:220px;">
                        <option value="">(keep existing rows)</option>
                        <option value="FL">FL — Florida</option>
                        <option value="TX">TX — Texas</option>
                        <option value="CA">CA — California</option>
                        <option value="NY">NY — New York</option>
                        <option value="AZ">AZ — Arizona</option>
                        <option value="CO">CO — Colorado</option>
                        <option value="GA">GA — Georgia</option>
                        <option value="NC">NC — North Carolina</option>
                        <option value="NV">NV — Nevada</option>
                        <option value="SC">SC — South Carolina</option>
                        <option value="VA">VA — Virginia</option>
                    </select>
                    <small style="color:var(--color-text-soft); display:block; margin-top: 4px;">
                        Leave blank to upsert (safe for incremental updates). Choose a state to full-replace its statute library.
                    </small>
                </div>

                <button type="submit" class="btn btn--primary">Import statutes</button>
            </form>
        </div>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
