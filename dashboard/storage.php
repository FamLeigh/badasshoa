<?php
// Manager-only storage breakdown — where is the association's quota going?
// Mirrors the "Storage" page Google Drive / iCloud / Dropbox surface, with
// a category bar, a per-category breakdown, and a "Largest files" list per
// category that links back to the management page where they can be deleted.
require __DIR__ . '/_bootstrap.php';
require_management();

$used  = association_storage_used_bytes($assocId);
$quota = association_storage_quota_bytes($association);
$pct   = $quota > 0 ? min(100, ($used / $quota) * 100) : 0;
$cats  = association_storage_breakdown($assocId);

// Colors for each category bar slice (also used as the chip background).
$CAT_COLORS = [
    'documents' => '#1f4f9c',
    'media'     => '#2f7a3d',
    'avatars'   => '#a8782a',
    'branding'  => '#5d3a8a',
    'other'     => '#666666',
];
// Where to send the admin to clean up each category.
$CAT_LINKS = [
    'documents' => '/dashboard/documents.php',
    'media'     => '/dashboard/media.php',
    'avatars'   => '/dashboard/directory.php',
    'branding'  => '/dashboard/settings.php',
    'other'     => null,
];

// Best-effort: map a file's relative path back to a clickable URL when we
// can — uploaded documents are served via /dashboard/file.php?id=N, branded
// logo via /branding.php. For the rest just show the filename.
$docMap = [];
$dStmt = db()->prepare("SELECT id, file_path FROM documents WHERE association_id = ? AND file_path IS NOT NULL");
$dStmt->execute([$assocId]);
foreach ($dStmt->fetchAll() as $d) {
    $docMap['uploads/' . $assocId . '/' . ($d['file_path'] ?? '')] = (int)$d['id'];
    $docMap[$d['file_path']] = (int)$d['id'];
}

function file_url_for(string $rel, int $assocId, array $docMap): ?string
{
    $fullRel = 'uploads/' . $assocId . '/' . $rel;
    if (isset($docMap[$fullRel])) return '/dashboard/file.php?id=' . $docMap[$fullRel];
    if (str_starts_with($rel, 'branding/')) return '/branding.php?id=' . $assocId;
    return null;
}

$page_title = 'Storage — ' . $association['name'];
require __DIR__ . '/../includes/header.php';

$paidGb = (int)($association['storage_paid_extra_gb'] ?? 0);
?>

<div class="container" style="padding: var(--sp-8) var(--sp-6) var(--sp-12); max-width: 1180px;">

    <div class="row row--between" style="margin-bottom: var(--sp-6); flex-wrap: wrap; gap: var(--sp-3);">
        <div>
            <h1 style="font-size: var(--fs-3xl); margin: 0;">Storage</h1>
            <p class="muted">Where your <?= e(format_bytes($quota)) ?> of file space is going.</p>
        </div>
        <a class="btn btn--ghost" href="mailto:success@badasshoa.com?subject=Storage%20upgrade%20for%20<?= urlencode((string)$association['name']) ?>">Need more space? $5/mo per GB</a>
    </div>

    <!-- Headline total -->
    <div class="card card--padded" style="margin-bottom: var(--sp-5);">
        <div class="row row--between" style="align-items: baseline; flex-wrap: wrap; gap: var(--sp-2);">
            <div>
                <strong style="font-size: var(--fs-2xl); color: var(--color-navy);"><?= e(format_bytes($used)) ?></strong>
                <span class="muted">of <?= e(format_bytes($quota)) ?> used (<?= number_format($pct, 1) ?>%)</span>
            </div>
            <div class="muted" style="font-size: var(--fs-sm);">
                1 GB free<?php if ($paidGb > 0): ?> + <?= (int)$paidGb ?> GB paid add-on<?php endif; ?>
            </div>
        </div>

        <!-- Stacked category bar (Drive-style) -->
        <div style="margin-top: var(--sp-3); height: 14px; background: var(--color-surface); border-radius: 999px; overflow: hidden; display: flex;">
            <?php foreach ($cats as $key => $c):
                if ($c['bytes'] <= 0) continue;
                $w = ($c['bytes'] / max(1, $quota)) * 100;
            ?>
                <div title="<?= e($c['label']) ?> · <?= e(format_bytes($c['bytes'])) ?>"
                     style="width: <?= number_format($w, 3) ?>%; background: <?= e($CAT_COLORS[$key]) ?>;"></div>
            <?php endforeach; ?>
        </div>

        <!-- Legend -->
        <div class="row" style="margin-top: var(--sp-3); gap: var(--sp-4); flex-wrap: wrap;">
            <?php foreach ($cats as $key => $c): ?>
                <div class="row" style="gap: 6px; align-items: center; font-size: var(--fs-sm);">
                    <span style="width: 12px; height: 12px; border-radius: 3px; background: <?= e($CAT_COLORS[$key]) ?>; flex: 0 0 12px;"></span>
                    <strong><?= e($c['label']) ?></strong>
                    <span class="muted"><?= e(format_bytes($c['bytes'])) ?> · <?= (int)$c['count'] ?> file<?= $c['count'] === 1 ? '' : 's' ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Per-category cards with largest files -->
    <?php foreach ($cats as $key => $c):
        if ($c['count'] === 0) continue;
        $linkTo = $CAT_LINKS[$key] ?? null;
        $catPct = $used > 0 ? ($c['bytes'] / $used) * 100 : 0;
    ?>
        <div class="card card--padded" style="margin-bottom: var(--sp-4);">
            <div class="row row--between" style="align-items: baseline; margin-bottom: var(--sp-3); flex-wrap: wrap; gap: var(--sp-2);">
                <div class="row" style="gap: var(--sp-2); align-items: center;">
                    <span style="width: 12px; height: 12px; border-radius: 3px; background: <?= e($CAT_COLORS[$key]) ?>; flex: 0 0 12px;"></span>
                    <h2 style="font-size: var(--fs-xl); margin: 0;"><?= e($c['label']) ?></h2>
                </div>
                <div class="muted" style="font-size: var(--fs-sm);">
                    <strong><?= e(format_bytes($c['bytes'])) ?></strong> · <?= (int)$c['count'] ?> file<?= $c['count'] === 1 ? '' : 's' ?> · <?= number_format($catPct, 1) ?>% of used
                    <?php if ($linkTo): ?>
                        · <a href="<?= e($linkTo) ?>">Manage →</a>
                    <?php endif; ?>
                </div>
            </div>

            <table class="table" style="font-size: var(--fs-sm);">
                <thead>
                    <tr><th>File</th><th style="text-align:right; white-space:nowrap;">Size</th><th style="white-space:nowrap;">Uploaded</th></tr>
                </thead>
                <tbody>
                <?php foreach ($c['files'] as $f):
                    $name = basename($f['rel']);
                    $url  = file_url_for($f['rel'], $assocId, $docMap);
                ?>
                    <tr>
                        <td>
                            <?php if ($url): ?>
                                <a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($name) ?></a>
                            <?php else: ?>
                                <?= e($name) ?>
                            <?php endif; ?>
                            <div class="muted" style="font-size: var(--fs-xs);"><?= e($f['rel']) ?></div>
                        </td>
                        <td style="text-align:right; white-space:nowrap; font-variant-numeric: tabular-nums;"><?= e(format_bytes($f['size'])) ?></td>
                        <td class="muted" style="white-space:nowrap;"><?= e(date('M j, Y', $f['mtime'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($c['count'] > 50): ?>
                <p class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-2);">Showing the 50 largest files in this category. Open the management page to see them all.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($used === 0): ?>
        <div class="card card--padded center" style="padding: var(--sp-12) var(--sp-6);">
            <p class="muted">No files uploaded yet. Once you upload documents, media, or avatars they'll show up here grouped by type.</p>
        </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
