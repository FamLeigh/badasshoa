<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/changelog.php';

$filterType = (string)($_GET['type'] ?? '');
$entries    = changelog_all($filterType ?: null);
$types      = changelog_types();

// Group by year-month
$grouped = [];
foreach ($entries as $e) {
    $month = date('Y-m', strtotime((string)$e['at']));
    $grouped[$month][] = $e;
}

$page_title = 'Changelog — BadassHOA';
require __DIR__ . '/includes/header.php';
?>

<section class="section section--tight">
    <div class="container container--narrow">
        <div class="center" style="margin-bottom: var(--sp-8);">
            <span class="badge badge--orange">Release notes</span>
            <h1 class="mt-2">Changelog</h1>
            <p class="muted" style="font-size: var(--fs-lg);">
                Every meaningful change to BadassHOA, dated and categorized.
            </p>
        </div>

        <!-- Filter chips -->
        <div class="row" style="gap: var(--sp-2); margin-bottom: var(--sp-10); flex-wrap: wrap; justify-content: center;">
            <a class="badge <?= $filterType === '' ? 'badge--navy' : '' ?>" href="/changelog.php" style="text-decoration:none; <?= $filterType !== '' ? 'opacity: 0.6;' : '' ?>">
                All <span style="margin-left: 4px;"><?= count(changelog_all()) ?></span>
            </a>
            <?php foreach ($types as $key => $meta):
                $count = count(changelog_all($key));
                if (!$count) continue;
                $active = $filterType === $key;
            ?>
                <a class="badge <?= $meta['badge'] ?>" href="?type=<?= e($key) ?>" style="text-decoration:none; <?= !$active && $filterType !== '' ? 'opacity: 0.5;' : '' ?>">
                    <?= e($meta['label']) ?> <span style="margin-left: 4px;"><?= $count ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!$entries): ?>
            <div class="card card--padded center"><p class="muted">No entries match this filter.</p></div>
        <?php else: ?>
            <?php foreach ($grouped as $month => $monthEntries): ?>
                <div style="margin-bottom: var(--sp-12);">
                    <h2 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-6); color: var(--color-text-soft);">
                        <?= e(date('F Y', strtotime($month . '-01'))) ?>
                    </h2>
                    <div class="stack-lg">
                    <?php foreach ($monthEntries as $entry):
                        $meta = $types[$entry['type']] ?? null;
                    ?>
                    <article class="card card--padded" style="border-left: 3px solid <?= $meta ? e($meta['fg']) : '#cfccc1' ?>;">
                        <div class="row" style="gap: var(--sp-3); margin-bottom: var(--sp-2); flex-wrap: wrap;">
                            <?php if ($meta): ?>
                                <span class="badge <?= e($meta['badge']) ?>"><?= e($meta['label']) ?></span>
                            <?php endif; ?>
                            <span class="muted" style="font-size: var(--fs-xs);">
                                <?= e(date('M j, Y', strtotime((string)$entry['at']))) ?>
                            </span>
                            <?php if (!empty($entry['link'])): ?>
                                <a href="<?= e((string)$entry['link']) ?>" style="font-size: var(--fs-xs); margin-left: auto;">
                                    <?= str_starts_with((string)$entry['link'], 'http') ? 'View →' : 'See it live →' ?>
                                </a>
                            <?php endif; ?>
                        </div>
                        <h3 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-2); letter-spacing: -0.01em;">
                            <?= e((string)$entry['title']) ?>
                        </h3>
                        <?php if (!empty($entry['description'])): ?>
                            <p class="muted" style="margin: 0; font-size: var(--fs-md); line-height: var(--lh-loose);">
                                <?= e((string)$entry['description']) ?>
                            </p>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
