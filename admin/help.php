<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// ── helpers ────────────────────────────────────────────────────────────────

/** Extract a YouTube video ID from any common YouTube URL format, or return null. */
function youtube_video_id(string $url): ?string
{
    $url = trim($url);
    if (!$url) return null;
    // youtu.be/VIDEO_ID
    if (preg_match('~youtu\.be/([A-Za-z0-9_-]{11})~', $url, $m)) return $m[1];
    // youtube.com/watch?v=VIDEO_ID  or  youtube.com/embed/VIDEO_ID
    if (preg_match('~[?&/](?:v=|embed/)([A-Za-z0-9_-]{11})~', $url, $m)) return $m[1];
    // Bare 11-char ID
    if (preg_match('~^[A-Za-z0-9_-]{11}$~', $url)) return $url;
    return null;
}

const ROLES_ORDERED = [
    'renter', 'staff', 'owner', 'property_manager',
    'board_member', 'board_admin', 'super_admin',
];

// ── POST handlers ──────────────────────────────────────────────────────────

$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = (string)($_POST['form'] ?? '');

    // --- Delete ---
    if ($form === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Also delete associated images from disk.
        $row = db()->prepare('SELECT images FROM help_topics WHERE id = ?');
        $row->execute([$id]);
        $existing = $row->fetchColumn();
        if ($existing) {
            $imgs = json_decode((string)$existing, true) ?: [];
            foreach ($imgs as $fn) {
                $path = __DIR__ . '/../storage/uploads/help/' . basename((string)$fn);
                if (is_file($path)) @unlink($path);
            }
        }
        db()->prepare('DELETE FROM help_topics WHERE id = ?')->execute([$id]);
        audit('help.deleted', ['id' => $id]);
        flash('success', 'Topic deleted.');
        redirect('/admin/help.php');
    }

    // --- Create / Update ---
    if ($form === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $slug       = trim((string)($_POST['slug']       ?? ''));
        $title      = trim((string)($_POST['title']      ?? ''));
        $category   = trim((string)($_POST['category']   ?? ''));
        $minRole    = (string)($_POST['min_role']  ?? 'renter');
        $body       = (string)($_POST['body']       ?? '');
        $youtubeUrl = trim((string)($_POST['youtube_url'] ?? ''));
        $sortOrder  = (int)($_POST['sort_order'] ?? 0);
        $active     = isset($_POST['active']) ? 1 : 0;

        // Validate
        if (!$slug || !$title || !$category || !in_array($minRole, ROLES_ORDERED, true)) {
            $flashError = 'Slug, title, category, and role are required.';
        }

        // Slug: lowercase letters, numbers, hyphens only
        $slug = strtolower(preg_replace('/[^a-z0-9-]/', '-', $slug));

        if (!$flashError) {
            // Handle existing images: keep those not removed.
            $existingJson = '[]';
            if ($id) {
                $r = db()->prepare('SELECT images FROM help_topics WHERE id = ?');
                $r->execute([$id]);
                $existingJson = $r->fetchColumn() ?: '[]';
            }
            $existingImgs = json_decode($existingJson, true) ?: [];

            // Remove images checked for deletion.
            $remove = (array)($_POST['remove_image'] ?? []);
            foreach ($remove as $fn) {
                $fn = basename((string)$fn);
                $key = array_search($fn, $existingImgs, true);
                if ($key !== false) {
                    unset($existingImgs[$key]);
                    $path = __DIR__ . '/../storage/uploads/help/' . $fn;
                    if (is_file($path)) @unlink($path);
                }
            }
            $existingImgs = array_values($existingImgs);

            // Upload new images.
            $newFiles = $_FILES['new_images'] ?? [];
            if (!empty($newFiles['name'][0])) {
                $dir = __DIR__ . '/../storage/uploads/help';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $allowed = ['png' => 1, 'jpg' => 1, 'jpeg' => 1, 'gif' => 1, 'webp' => 1];
                $count   = count($newFiles['name']);
                for ($i = 0; $i < $count; $i++) {
                    if ($newFiles['error'][$i] !== UPLOAD_ERR_OK) continue;
                    if ($newFiles['size'][$i] > 8 * 1024 * 1024)  continue;
                    $ext = strtolower(pathinfo($newFiles['name'][$i], PATHINFO_EXTENSION));
                    if (!isset($allowed[$ext])) continue;
                    $fn   = bin2hex(random_bytes(12)) . '.' . $ext;
                    $dest = $dir . '/' . $fn;
                    if (move_uploaded_file($newFiles['tmp_name'][$i], $dest)) {
                        $existingImgs[] = $fn;
                    }
                }
            }

            $imagesJson  = json_encode(array_values($existingImgs));
            $youtubeClean = $youtubeUrl ?: null;

            if ($id) {
                // Update
                $sql = 'UPDATE help_topics
                        SET slug=?, title=?, category=?, min_role=?, body=?,
                            youtube_url=?, images=?, sort_order=?, active=?
                        WHERE id=?';
                db()->prepare($sql)->execute([
                    $slug, $title, $category, $minRole, $body,
                    $youtubeClean, $imagesJson, $sortOrder, $active, $id,
                ]);
                audit('help.updated', ['id' => $id, 'slug' => $slug]);
                flash('success', 'Topic updated.');
            } else {
                // Create
                $sql = 'INSERT INTO help_topics (slug, title, category, min_role, body, youtube_url, images, sort_order, active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
                db()->prepare($sql)->execute([
                    $slug, $title, $category, $minRole, $body,
                    $youtubeClean, $imagesJson, $sortOrder, $active,
                ]);
                audit('help.created', ['slug' => $slug]);
                flash('success', 'Topic created.');
            }
            redirect('/admin/help.php');
        }
    }
}

// ── Load data ──────────────────────────────────────────────────────────────

$topics = db()->query(
    'SELECT id, slug, title, category, min_role, sort_order, active, youtube_url, images
     FROM help_topics
     ORDER BY sort_order, title'
)->fetchAll();

$editing  = null;
$creating = false;

if (($_GET['action'] ?? '') === 'edit') {
    $eid = (int)($_GET['id'] ?? 0);
    $r   = db()->prepare('SELECT * FROM help_topics WHERE id = ?');
    $r->execute([$eid]);
    $editing = $r->fetch() ?: null;
} elseif (($_GET['action'] ?? '') === 'new') {
    $creating = true;
}

// Collect distinct categories for the datalist.
$categories = array_unique(array_column($topics, 'category'));
sort($categories);

// ── Page ───────────────────────────────────────────────────────────────────

$page_title      = 'Help Topics — Admin';
$page_extra_head = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">';
require __DIR__ . '/../includes/header.php';

$roleLabels = [
    'renter'           => 'Renter (everyone)',
    'staff'            => 'Staff',
    'owner'            => 'Owner',
    'property_manager' => 'Property manager',
    'board_member'     => 'Board member',
    'board_admin'      => 'Board admin',
    'super_admin'      => 'Super admin only',
];
?>
<style>
.ht-grid {
    display: grid;
    grid-template-columns: 1fr 420px;
    gap: var(--sp-6);
    align-items: start;
    max-width: 1280px;
    margin: var(--sp-8) auto;
    padding: 0 var(--sp-6) var(--sp-12);
}
@media (max-width: 900px) { .ht-grid { grid-template-columns: 1fr; } }

.ht-table { width: 100%; border-collapse: collapse; font-size: var(--fs-sm); }
.ht-table th { text-align: left; padding: 6px 10px; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em; color: var(--color-text-soft);
    border-bottom: 2px solid var(--color-border); }
.ht-table td { padding: 8px 10px; border-bottom: 1px solid var(--color-border);
    vertical-align: middle; }
.ht-table tr:hover td { background: var(--color-surface); }
.badge-role { display: inline-block; padding: 2px 7px; border-radius: 20px;
    font-size: 11px; font-weight: 600; background: var(--color-surface);
    border: 1px solid var(--color-border); white-space: nowrap; }
.badge-inactive { background: #fef3c7; color: #92400e; border-color: #fde68a; }

/* Form panel */
.ht-form-panel { background: var(--color-card); border: 1px solid var(--color-border);
    border-radius: var(--radius-lg); padding: var(--sp-6); position: sticky; top: 90px; }
.ht-form-panel h2 { font-size: var(--fs-xl); font-weight: 800; color: var(--color-navy);
    margin: 0 0 var(--sp-5); }
.form-row { margin-bottom: var(--sp-4); }
.form-row label { display: block; font-size: var(--fs-sm); font-weight: 600;
    margin-bottom: 4px; color: var(--color-text); }
.form-row .help { font-size: var(--fs-xs); color: var(--color-text-soft); margin-top: 3px; }
#quill-editor { min-height: 280px; background: #fff; }
.ht-img-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.ht-img-item { position: relative; border: 1px solid var(--color-border);
    border-radius: var(--radius-md); overflow: hidden; }
.ht-img-item img { display: block; width: 90px; height: 68px; object-fit: cover; }
.ht-img-item label { position: absolute; top: 4px; right: 4px;
    background: rgba(0,0,0,.55); border-radius: 50%; width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center; cursor: pointer; }
.ht-img-item input[type=checkbox] { display: none; }
.ht-img-item label svg { display: block; }
.ht-img-item.marked { outline: 2px solid #ef4444; }
</style>

<div class="ht-grid">

    <!-- LEFT: topic list -->
    <div>
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom: var(--sp-5);">
            <div>
                <h1 style="font-size: var(--fs-3xl); margin:0;">Help Topics</h1>
                <p class="muted">Content for <code>/dashboard/help.php</code>. Role filtering applied at view time.</p>
            </div>
            <a href="/admin/help.php?action=new" class="btn btn--primary">+ New topic</a>
        </div>

        <?php if ($flashError): ?>
        <div class="flash flash--error"><?= e($flashError) ?></div>
        <?php endif; ?>

        <?php if (empty($topics)): ?>
        <div class="card" style="padding: var(--sp-8); text-align:center;">
            <p class="muted">No topics yet. Click "+ New topic" to add the first one.</p>
        </div>
        <?php else: ?>

        <?php
        // Group by category for display.
        $grouped = [];
        foreach ($topics as $t) { $grouped[$t['category']][] = $t; }
        ?>

        <?php foreach ($grouped as $cat => $rows): ?>
        <div style="margin-bottom: var(--sp-6);">
            <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.07em;
                        color:var(--color-text-soft); margin-bottom: var(--sp-2); padding: 0 2px;">
                <?= e($cat) ?>
            </div>
            <div class="card" style="padding:0; overflow:hidden;">
            <table class="ht-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Min role</th>
                        <th style="width:42px; text-align:center;">Sort</th>
                        <th style="width:70px; text-align:center;">Status</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $t): ?>
                <tr>
                    <td>
                        <div style="font-weight:600; color:var(--color-navy);"><?= e($t['title']) ?></div>
                        <div style="font-size:11px; color:var(--color-text-soft);"><?= e($t['slug']) ?></div>
                    </td>
                    <td><span class="badge-role"><?= e($roleLabels[$t['min_role']] ?? $t['min_role']) ?></span></td>
                    <td style="text-align:center;"><?= (int)$t['sort_order'] ?></td>
                    <td style="text-align:center;">
                        <?php if ($t['active']): ?>
                        <span class="badge badge--success">Active</span>
                        <?php else: ?>
                        <span class="badge badge-inactive">Hidden</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex; gap:6px; justify-content:flex-end;">
                            <a href="/admin/help.php?action=edit&id=<?= (int)$t['id'] ?>" class="btn btn--sm">Edit</a>
                            <form method="POST" onsubmit="return confirm('Delete this topic?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="btn btn--sm btn--danger">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- RIGHT: create / edit form -->
    <?php if ($editing || $creating): ?>
    <?php $t = $editing ?: []; ?>
    <div class="ht-form-panel">
        <h2><?= $editing ? 'Edit topic' : 'New topic' ?></h2>

        <form method="POST" enctype="multipart/form-data" id="ht-form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="save">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
            <input type="hidden" name="body" id="body-hidden">

            <div class="form-row">
                <label for="ht-title">Title</label>
                <input class="input" type="text" id="ht-title" name="title"
                    value="<?= e((string)($t['title'] ?? '')) ?>" required
                    placeholder="e.g. Submitting a work order">
            </div>

            <div class="form-row">
                <label for="ht-slug">Slug</label>
                <input class="input" type="text" id="ht-slug" name="slug"
                    value="<?= e((string)($t['slug'] ?? '')) ?>" required
                    placeholder="e.g. work-orders" pattern="[a-z0-9\-]+">
                <div class="help">URL-safe: lowercase letters, numbers, hyphens only. Auto-generated from title if blank.</div>
            </div>

            <div class="form-row">
                <label for="ht-category">Category</label>
                <input class="input" type="text" id="ht-category" name="category"
                    value="<?= e((string)($t['category'] ?? '')) ?>" required
                    list="ht-cat-list" placeholder="e.g. Board &amp; management">
                <datalist id="ht-cat-list">
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat) ?>">
                    <?php endforeach; ?>
                    <option value="Your account">
                    <option value="Community">
                    <option value="Governance">
                    <option value="Resources">
                    <option value="Board &amp; management">
                </datalist>
            </div>

            <div class="form-row">
                <label for="ht-role">Minimum role</label>
                <select class="input" id="ht-role" name="min_role">
                    <?php foreach (ROLES_ORDERED as $r): ?>
                    <option value="<?= e($r) ?>"<?= ($t['min_role'] ?? 'renter') === $r ? ' selected' : '' ?>>
                        <?= e($roleLabels[$r]) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="help">Users at this role or higher can see the topic.</div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:var(--sp-3);" class="form-row">
                <div>
                    <label for="ht-sort">Sort order</label>
                    <input class="input" type="number" id="ht-sort" name="sort_order"
                        value="<?= (int)($t['sort_order'] ?? 0) ?>" min="0" max="9999">
                </div>
                <div style="padding-top: 28px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="active" value="1"<?= ($t['active'] ?? 1) ? ' checked' : '' ?>>
                        <span style="font-size:var(--fs-sm); font-weight:600;">Active (visible to users)</span>
                    </label>
                </div>
            </div>

            <div class="form-row">
                <label>Body content</label>
                <div id="quill-editor"><?= $editing ? $editing['body'] : '' ?></div>
            </div>

            <div class="form-row">
                <label for="ht-yt">YouTube video URL (optional)</label>
                <input class="input" type="url" id="ht-yt" name="youtube_url"
                    value="<?= e((string)($t['youtube_url'] ?? '')) ?>"
                    placeholder="https://www.youtube.com/watch?v=...">
                <div class="help">Paste a YouTube watch URL or share link. Displayed below the body as an embedded player.</div>
            </div>

            <!-- Existing images -->
            <?php
            $existingImgs = [];
            if (!empty($t['images'])) {
                $existingImgs = json_decode((string)$t['images'], true) ?: [];
            }
            ?>
            <?php if ($existingImgs): ?>
            <div class="form-row">
                <label>Existing images <span class="help" style="display:inline;">(check to remove)</span></label>
                <div class="ht-img-grid" id="existing-img-grid">
                    <?php foreach ($existingImgs as $fn): ?>
                    <?php $fn = basename((string)$fn); ?>
                    <div class="ht-img-item" id="img-wrap-<?= e($fn) ?>">
                        <img src="/help-image.php?f=<?= e(urlencode($fn)) ?>" alt="Help image">
                        <label title="Mark for removal">
                            <input type="checkbox" name="remove_image[]" value="<?= e($fn) ?>"
                                onchange="this.closest('.ht-img-item').classList.toggle('marked', this.checked)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                                stroke="#fff" stroke-width="3" stroke-linecap="round">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="help">Checked images are deleted on save.</div>
            </div>
            <?php endif; ?>

            <!-- New image uploads -->
            <div class="form-row">
                <label for="ht-imgs">Add images (optional)</label>
                <input type="file" id="ht-imgs" name="new_images[]" multiple
                    accept="image/png,image/jpeg,image/gif,image/webp"
                    style="font-size:var(--fs-sm);">
                <div class="help">PNG, JPG, GIF, or WEBP. Max 8 MB each. Images appear below the body content in the help viewer.</div>
            </div>

            <div style="display:flex; gap:var(--sp-3); flex-wrap:wrap; margin-top: var(--sp-2);">
                <button type="submit" class="btn btn--primary">Save topic</button>
                <a href="/admin/help.php" class="btn">Cancel</a>
            </div>
        </form>
    </div>
    <?php else: ?>
    <div class="card" style="padding: var(--sp-6); text-align:center; color:var(--color-text-soft);">
        <p>Select a topic to edit, or click "+ New topic" to create one.</p>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
(function () {
    // Auto-slug from title
    var titleInput = document.getElementById('ht-title');
    var slugInput  = document.getElementById('ht-slug');
    var slugTouched = !!(slugInput && slugInput.value.trim());
    if (titleInput && slugInput) {
        titleInput.addEventListener('input', function () {
            if (slugTouched) return;
            slugInput.value = this.value.toLowerCase()
                .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        });
        slugInput.addEventListener('input', function () { slugTouched = !!this.value; });
    }

    // Quill editor
    var editorEl = document.getElementById('quill-editor');
    var bodyHidden = document.getElementById('body-hidden');
    if (!editorEl) return;

    var csrfTok = (document.querySelector('meta[name=csrf]') || {}).content
                  || (document.querySelector('[name=_csrf]') || {}).value || '';

    var quill = new Quill('#quill-editor', {
        theme: 'snow',
        modules: {
            toolbar: {
                container: [
                    [{ header: [2, 3, false] }],
                    ['bold', 'italic', 'underline'],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    ['link', 'image', 'code-block'],
                    ['clean'],
                ],
                handlers: {
                    image: function () {
                        var input = document.createElement('input');
                        input.type = 'file';
                        input.accept = 'image/png,image/jpeg,image/gif,image/webp';
                        input.click();
                        input.onchange = async function () {
                            var file = input.files[0];
                            if (!file) return;
                            var fd = new FormData();
                            fd.append('file', file);
                            fd.append('_csrf', csrfTok);
                            try {
                                var res = await fetch('/admin/upload-help-image.php', {
                                    method: 'POST', body: fd, credentials: 'same-origin',
                                    headers: { 'X-CSRF': csrfTok },
                                });
                                var data = await res.json();
                                if (data.ok) {
                                    var range = quill.getSelection(true);
                                    quill.insertEmbed(range.index, 'image', data.url, 'user');
                                    quill.setSelection(range.index + 1);
                                } else {
                                    alert('Image upload failed: ' + (data.error || 'unknown'));
                                }
                            } catch (e) {
                                alert('Image upload error: ' + e.message);
                            }
                        };
                    }
                }
            }
        }
    });

    // Sync hidden field on form submit
    var form = document.getElementById('ht-form');
    if (form) {
        form.addEventListener('submit', function () {
            bodyHidden.value = quill.root.innerHTML;
        });
    }

    // Expose CSRF token via meta tag if not already present
    var metaCsrf = document.querySelector('meta[name=csrf]');
    if (!metaCsrf) {
        var hiddenCsrf = document.querySelector('input[name=_csrf]');
        if (hiddenCsrf) csrfTok = hiddenCsrf.value;
    }
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
