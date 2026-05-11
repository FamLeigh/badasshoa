<?php
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$canEdit = role_can_manage(viewing_role());
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'update') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); die('Forbidden'); }
    $name      = trim((string)($_POST['name'] ?? ''));
    $subdomain = trim((string)($_POST['subdomain'] ?? ''));
    $address   = trim((string)($_POST['address'] ?? ''));
    $city      = trim((string)($_POST['city'] ?? ''));
    $stateReg  = trim((string)($_POST['state_region'] ?? ''));
    $postal    = trim((string)($_POST['postal_code'] ?? ''));
    $country   = strtoupper(trim((string)($_POST['country'] ?? 'US')));
    $units     = (int)($_POST['unit_count'] ?? 0);
    $primary   = trim((string)($_POST['primary_color'] ?? '#0f1f3d'));
    $publicLanding = isset($_POST['public_landing_enabled']) ? 1 : 0;
    if (!preg_match('/^#[0-9a-f]{6}$/i', $primary)) $primary = '#0f1f3d';
    if (!preg_match('/^[A-Z]{2}$/', $country))      $country = 'US';

    // Slug normalize + uniqueness check
    $subdomain = preg_replace('/[^a-z0-9-]/', '', strtolower($subdomain));
    $subdomain = trim($subdomain, '-');
    if ($subdomain === '') {
        $flashError = 'Slug is required (the URL-safe identifier for your community).';
    } elseif (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/', $subdomain)) {
        $flashError = 'Slug must be lowercase letters, numbers, and hyphens only — no leading or trailing hyphen.';
    } else {
        $dupe = db()->prepare('SELECT id FROM associations WHERE subdomain = ? AND id <> ?');
        $dupe->execute([$subdomain, $assocId]);
        if ($dupe->fetchColumn()) {
            $flashError = "Slug \"$subdomain\" is already taken by another association.";
        }
    }

    // --- Helper: upload a single image to /storage/uploads/{assocId}/branding/{filename}.{ext} ---
    $uploadBranding = function (string $field, string $filename, int $maxBytes = 1048576) use ($assocId, &$flashError) {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
        $allowed = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','svg'=>'image/svg+xml','webp'=>'image/webp'];
        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) { $flashError = ucfirst($filename) . ' must be PNG, JPG, SVG, or WEBP.'; return null; }
        if ($_FILES[$field]['size'] > $maxBytes) { $flashError = 'Max ' . ucfirst($filename) . ' size is ' . round($maxBytes / 1048576, 1) . ' MB.'; return null; }
        $relDir = "uploads/$assocId/branding";
        $absDir = storage_path($relDir);
        ensure_dir($absDir);
        $newName = "$filename.$ext";
        $relPath = "$relDir/$newName";
        $absPath = "$absDir/$newName";
        foreach (['png','jpg','jpeg','svg','webp'] as $oldExt) {
            if ($oldExt !== $ext) @unlink("$absDir/$filename.$oldExt");
        }
        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $absPath)) {
            $flashError = 'Could not save ' . $filename . '.';
            return null;
        }
        return $relPath;
    };

    // --- Logo upload (optional) ---
    $newLogoPath = null;
    $removeLogo  = isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1';
    if (!$flashError) {
        $newLogoPath = $uploadBranding('logo', 'logo', 1 * 1024 * 1024);
    }

    // --- Hero image upload (optional) ---
    $newHeroPath = null;
    $removeHero  = isset($_POST['remove_hero']) && $_POST['remove_hero'] === '1';
    if (!$flashError) {
        $newHeroPath = $uploadBranding('hero_image', 'hero', 4 * 1024 * 1024);
    }

    // --- Long-form public-landing content (optional) ---
    $vision        = trim((string)($_POST['vision_statement'] ?? ''));
    $aboutText     = (string)($_POST['about_text'] ?? '');
    $amenitiesText = trim((string)($_POST['amenities_text'] ?? ''));
    $contactEmail  = trim((string)($_POST['contact_email'] ?? ''));
    $contactPhone  = trim((string)($_POST['contact_phone'] ?? ''));
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $flashError = $flashError ?: 'Public contact email is not a valid email address.';
    }

    // --- Social / external links (all optional) ---
    $normalizeUrl = static function (string $u): ?string {
        $u = trim($u);
        if ($u === '') return null;
        // Add scheme if missing — most boards will paste "facebook.com/..."
        if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
        return filter_var($u, FILTER_VALIDATE_URL) ? $u : null;
    };
    $websiteUrl   = $normalizeUrl((string)($_POST['website_url']   ?? ''));
    $facebookUrl  = $normalizeUrl((string)($_POST['facebook_url']  ?? ''));
    $instagramUrl = $normalizeUrl((string)($_POST['instagram_url'] ?? ''));
    $twitterUrl   = $normalizeUrl((string)($_POST['twitter_url']   ?? ''));
    $nextdoorUrl  = $normalizeUrl((string)($_POST['nextdoor_url']  ?? ''));

    // Which section of the page submitted? Either form posts here, but each
    // edits a disjoint slice of the row. Without this, posting the Profile
    // form would clobber the landing-content fields with empty strings.
    $section = $_POST['section'] ?? 'profile';
    if (!in_array($section, ['profile', 'content'], true)) $section = 'profile';

    if ($section === 'profile' && $name === '') {
        $flashError = 'Association name is required.';
    } elseif (!$flashError) {

        if ($section === 'profile') {
            // ---------- PROFILE: name, slug, address, branding, color, public toggle ----------
            $extraSql = ''; $extraArgs = [];

            if ($removeLogo) {
                $extraSql .= ', logo_path = NULL';
                $existing = (string)($association['logo_path'] ?? '');
                if ($existing) { $abs = storage_path($existing); if (is_file($abs)) @unlink($abs); }
            } elseif ($newLogoPath) {
                $extraSql .= ', logo_path = ?'; $extraArgs[] = $newLogoPath;
            }
            if ($removeHero) {
                $extraSql .= ', hero_image_path = NULL';
                $existing = (string)($association['hero_image_path'] ?? '');
                if ($existing) { $abs = storage_path($existing); if (is_file($abs)) @unlink($abs); }
            } elseif ($newHeroPath) {
                $extraSql .= ', hero_image_path = ?'; $extraArgs[] = $newHeroPath;
            }

            // Geocode the address (best-effort — silent fail). Re-geocode if address changed OR lat/lon is missing.
            $oldAddrSig = trim(($association['address'] ?? '') . '|' . ($association['city'] ?? '') . '|' . ($association['state_region'] ?? '') . '|' . ($association['postal_code'] ?? '') . '|' . ($association['country'] ?? ''));
            $newAddrSig = trim(($address ?? '') . '|' . ($city ?? '') . '|' . ($stateReg ?? '') . '|' . ($postal ?? '') . '|' . ($country ?? ''));
            $hasAddr = $newAddrSig !== '||||';
            $missingCoords = empty($association['latitude']) || empty($association['longitude']);
            if ($hasAddr && ($newAddrSig !== $oldAddrSig || $missingCoords)) {
                $queryStr = trim(implode(' ', array_filter([$address, $city, $stateReg, $postal, $country])));
                $latLon = geocode_address($queryStr);
                $extraSql .= ', latitude = ?, longitude = ?';
                $extraArgs[] = $latLon['lat'] ?? null;
                $extraArgs[] = $latLon['lon'] ?? null;
            }

            $slugChanged = $subdomain !== (string)$association['subdomain'];
            db()->prepare(
                "UPDATE associations
                 SET name = ?, subdomain = ?, address = ?, city = ?, state_region = ?, postal_code = ?, country = ?,
                     unit_count = ?, primary_color = ?, public_landing_enabled = ?
                     $extraSql
                 WHERE id = ?"
            )->execute(array_merge(
                [
                    $name, $subdomain,
                    $address ?: null, $city ?: null, $stateReg ?: null, $postal ?: null, $country,
                    $units, $primary, $publicLanding,
                ],
                $extraArgs,
                [$assocId]
            ));
            audit('association.profile_updated', [
                'name' => $name,
                'public_landing_enabled' => $publicLanding,
                'logo_changed' => $newLogoPath !== null || $removeLogo,
                'hero_changed' => $newHeroPath !== null || $removeHero,
                'slug_changed' => $slugChanged,
                'new_slug' => $slugChanged ? $subdomain : null,
            ]);
            flash('success', 'Profile saved.');
        } else {
            // ---------- CONTENT: landing copy + social links (does NOT touch profile fields) ----------
            db()->prepare(
                "UPDATE associations
                 SET vision_statement = ?, about_text = ?, amenities_text = ?,
                     contact_email = ?, contact_phone = ?,
                     website_url = ?, facebook_url = ?, instagram_url = ?, twitter_url = ?, nextdoor_url = ?
                 WHERE id = ?"
            )->execute([
                $vision ?: null, $aboutText ?: null, $amenitiesText ?: null,
                $contactEmail ?: null, $contactPhone ?: null,
                $websiteUrl, $facebookUrl, $instagramUrl, $twitterUrl, $nextdoorUrl,
                $assocId,
            ]);
            audit('association.content_updated', ['has_about' => $aboutText !== '', 'has_vision' => $vision !== '']);
            flash('success', 'Landing content saved.');
        }
        redirect('/dashboard/settings.php');
    }
}

// reload after update or for fresh display
$assocStmt = db()->prepare('SELECT * FROM associations WHERE id = ?');
$assocStmt->execute([$assocId]);
$association = $assocStmt->fetch();

$page_title = 'Settings — ' . $association['name'];
$page_extra_head = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">';
require __DIR__ . '/../includes/header.php';
?>

<div class="container container--narrow" style="padding: var(--sp-8) var(--sp-6) var(--sp-12);">

    <h1 style="font-size: var(--fs-3xl); margin: 0;">Settings</h1>
    <p class="muted">Edit your association profile.</p>

    <?php if ($flashError): ?><div class="flash flash--error" style="margin-top: var(--sp-4);"><?= e($flashError) ?></div><?php endif; ?>

    <div class="card card--padded" style="margin-top: var(--sp-6);">
        <h3 class="card__title">Association profile</h3>
        <form method="post" class="form" enctype="multipart/form-data" data-address-lookup>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="update">
            <input type="hidden" name="section" value="profile">
            <fieldset style="border:0; padding:0; margin:0;" <?= $canEdit ? '' : 'disabled' ?>>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="aname">Name</label>
                        <input class="input" id="aname" name="name" required value="<?= e($association['name']) ?>">
                    </div>
                    <div class="field">
                        <label class="field__label" for="aslug">Public URL slug</label>
                        <div style="display:flex; align-items:center; gap: 0; background: var(--color-white); border: 1px solid var(--color-border-strong); border-radius: var(--r-md); padding: 0;">
                            <span class="muted" style="padding: 0.7rem 0 0.7rem 0.85rem; font-size: var(--fs-sm); white-space: nowrap;">badasshoa.com/</span>
                            <input class="input" id="aslug" name="subdomain" required value="<?= e((string)$association['subdomain']) ?>" pattern="[a-z0-9][a-z0-9-]*[a-z0-9]|[a-z0-9]" style="border: 0; padding-left: 2px;">
                        </div>
                        <div class="field__hint">Lowercase letters, numbers, and hyphens. <strong>Changing this breaks any existing bookmarks.</strong></div>
                    </div>
                </div>
                <div class="field">
                    <label class="field__label" for="aaddr">Street address</label>
                    <input class="input" id="aaddr" name="address" value="<?= e((string)$association['address']) ?>" autocomplete="street-address" placeholder="123 Main St, Suite 100">
                </div>
                <div style="display:grid; grid-template-columns: 1.4fr 1fr 0.8fr; gap: var(--sp-3);">
                    <div class="field">
                        <label class="field__label" for="acity">City</label>
                        <input class="input" id="acity" name="city" value="<?= e((string)($association['city'] ?? '')) ?>" autocomplete="address-level2">
                    </div>
                    <div class="field">
                        <label class="field__label" for="astate">State / Province</label>
                        <input class="input" id="astate" name="state_region" list="us-ca-states" value="<?= e((string)($association['state_region'] ?? '')) ?>" autocomplete="address-level1">
                    </div>
                    <div class="field">
                        <label class="field__label" for="apostal">ZIP / Postal</label>
                        <input class="input" id="apostal" name="postal_code" value="<?= e((string)($association['postal_code'] ?? '')) ?>" autocomplete="postal-code" placeholder="12345 or A1A 1A1">
                    </div>
                </div>
                <?= us_ca_states_datalist() ?>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="acountry">Country</label>
                        <select class="select" id="acountry" name="country">
                            <?php $cur = strtoupper((string)($association['country'] ?? 'US'));
                            foreach (['US'=>'United States','CA'=>'Canada','MX'=>'Mexico','GB'=>'United Kingdom','AU'=>'Australia'] as $code=>$lbl): ?>
                                <option value="<?= e($code) ?>" <?= $cur===$code?'selected':'' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field__label" for="aunits">Unit count</label>
                        <input class="input" type="number" id="aunits" name="unit_count" min="0" value="<?= (int)$association['unit_count'] ?>">
                    </div>
                </div>
                <div class="form-row form-row--2">
                    <div class="field">
                        <label class="field__label" for="acolor">Primary color</label>
                        <input class="input" type="color" id="acolor" name="primary_color" value="<?= e((string)$association['primary_color']) ?>">
                        <div class="field__hint">Used to brand the public landing page (when enabled).</div>
                    </div>
                    <div class="field">
                        <label class="field__label" for="alogo">Association logo</label>
                        <?php if (!empty($association['logo_path'])): ?>
                            <div style="margin-bottom: var(--sp-2); padding: var(--sp-3); background: var(--color-surface-2); border-radius: var(--r-sm); text-align: center;">
                                <img src="/branding.php?id=<?= (int)$association['id'] ?>" alt="Current logo" style="max-height: 64px; max-width: 100%;">
                                <label style="display:flex; align-items:center; gap: var(--sp-2); margin-top: var(--sp-2); font-size: var(--fs-xs); justify-content: center;">
                                    <input type="checkbox" name="remove_logo" value="1"> Remove current logo
                                </label>
                            </div>
                        <?php endif; ?>
                        <input class="input" type="file" id="alogo" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp">
                        <div class="field__hint">PNG / JPG / SVG / WEBP, max 1 MB. Shown on the public landing page.</div>
                    </div>
                </div>

                <?php if ($canEdit): ?>
                    <div class="row" style="justify-content: flex-end;">
                        <button class="btn btn--primary" type="submit">Save changes</button>
                    </div>
                <?php else: ?>
                    <p class="muted" style="font-size: var(--fs-sm);">Only board admins can edit settings.</p>
                <?php endif; ?>
            </fieldset>
        </form>
    </div>

    <?php if ($canEdit): ?>
    <div class="card card--padded" style="margin-top: var(--sp-6);">
        <h3 class="card__title">🌐 Public landing content</h3>
        <p class="muted" style="font-size: var(--fs-sm); margin-bottom: var(--sp-5);">
            What residents and prospective residents see at
            <a href="/<?= e((string)$association['subdomain']) ?>/" target="_blank" rel="noopener">badasshoa.com/<?= e((string)$association['subdomain']) ?>/</a>
            — when the toggle below is on. All fields optional; sections without content simply don't render.
        </p>

        <form method="post" class="form" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="update">
            <input type="hidden" name="section" value="content">
            <input type="hidden" name="name" value="<?= e((string)$association['name']) ?>">
            <input type="hidden" name="subdomain" value="<?= e((string)$association['subdomain']) ?>">
            <input type="hidden" name="address" value="<?= e((string)($association['address'] ?? '')) ?>">
            <input type="hidden" name="city" value="<?= e((string)($association['city'] ?? '')) ?>">
            <input type="hidden" name="state_region" value="<?= e((string)($association['state_region'] ?? '')) ?>">
            <input type="hidden" name="postal_code" value="<?= e((string)($association['postal_code'] ?? '')) ?>">
            <input type="hidden" name="country" value="<?= e((string)($association['country'] ?? 'US')) ?>">
            <input type="hidden" name="unit_count" value="<?= (int)$association['unit_count'] ?>">
            <input type="hidden" name="primary_color" value="<?= e((string)$association['primary_color']) ?>">

            <div class="field">
                <label style="display:flex; align-items:center; gap: var(--sp-3); cursor: pointer;">
                    <input type="checkbox" name="public_landing_enabled" value="1" <?= (int)($association['public_landing_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <div>
                        <strong>Show this landing page publicly at <code>/<?= e((string)$association['subdomain']) ?>/</code></strong>
                        <div class="muted" style="font-size: var(--fs-sm);">
                            When off, the URL bounces visitors to the sign-in page. When on, they see the content below.
                        </div>
                    </div>
                </label>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--color-border); margin: var(--sp-5) 0;">

            <div class="field">
                <label class="field__label" for="ahero">Hero banner image</label>
                <?php if (!empty($association['hero_image_path'])): ?>
                    <div style="margin-bottom: var(--sp-2); padding: var(--sp-2); background: var(--color-surface-2); border-radius: var(--r-sm);">
                        <img src="/branding.php?id=<?= (int)$association['id'] ?>&kind=hero" alt="Current hero" style="max-height: 140px; max-width: 100%; display: block; margin: 0 auto;">
                        <label style="display:flex; align-items:center; gap: var(--sp-2); margin-top: var(--sp-2); font-size: var(--fs-xs); justify-content: center;">
                            <input type="checkbox" name="remove_hero" value="1"> Remove current banner
                        </label>
                    </div>
                <?php endif; ?>
                <input class="input" type="file" id="ahero" name="hero_image" accept="image/png,image/jpeg,image/webp">
                <div class="field__hint">Wide image used at the top of the public landing. PNG / JPG / WEBP, max 4 MB. Aim for 1600×600 or wider.</div>
            </div>

            <div class="field">
                <label class="field__label" for="avision">Vision statement (optional)</label>
                <textarea class="textarea" id="avision" name="vision_statement" rows="3" placeholder="A short paragraph describing what your community stands for. Shown prominently above the About section on the landing."><?= e((string)($association['vision_statement'] ?? '')) ?></textarea>
                <div class="field__hint">Shown above the About section on your public landing.</div>
            </div>

            <div class="field">
                <label class="field__label">About this community</label>
                <div id="about-editor" data-initial-html="<?= e((string)($association['about_text'] ?? '')) ?>" style="background: #fff; border-radius: var(--r-md);"></div>
                <textarea name="about_text" id="about-source" hidden><?= e((string)($association['about_text'] ?? '')) ?></textarea>
                <div class="field__hint">Welcome paragraph. Bold/italic, lists, and links are supported.</div>
            </div>

            <div class="field">
                <label class="field__label" for="aamen">Amenities</label>
                <textarea class="textarea" id="aamen" name="amenities_text" rows="6" placeholder="Pool&#10;Gym&#10;Beach access&#10;24-hour security&#10;Reserved parking"><?= e((string)($association['amenities_text'] ?? '')) ?></textarea>
                <div class="field__hint">One per line. Rendered as a tidy two-column list on the landing.</div>
            </div>

            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="acemail">Main email</label>
                    <input class="input" type="email" id="acemail" name="contact_email" value="<?= e((string)($association['contact_email'] ?? '')) ?>" placeholder="info@yourcommunity.com">
                    <div class="field__hint">Primary contact email for the association — shown on the public landing as a <code>mailto:</code> link and used in the contact form footer.</div>
                </div>
                <div class="field">
                    <label class="field__label" for="acphone">Main phone</label>
                    <input class="input" type="tel" id="acphone" name="contact_phone" value="<?= e((string)($association['contact_phone'] ?? '')) ?>" placeholder="555-555-5555">
                    <div class="field__hint">Primary contact phone — shown on the public landing.</div>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--color-border); margin: var(--sp-5) 0;">

            <h4 style="font-size: var(--fs-md); margin: 0 0 var(--sp-2);">Social &amp; external links</h4>
            <p class="muted" style="font-size: var(--fs-sm); margin: 0 0 var(--sp-4);">
                Optional. Each one renders as an icon in the landing page footer when set. Paste the full URL or just the domain — we'll add <code>https://</code>.
            </p>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="aweb">Community website</label>
                    <input class="input" type="url" id="aweb" name="website_url" value="<?= e((string)($association['website_url'] ?? '')) ?>" placeholder="https://your-community.com">
                </div>
                <div class="field">
                    <label class="field__label" for="afb">Facebook</label>
                    <input class="input" type="url" id="afb" name="facebook_url" value="<?= e((string)($association['facebook_url'] ?? '')) ?>" placeholder="https://facebook.com/your-page">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="aig">Instagram</label>
                    <input class="input" type="url" id="aig" name="instagram_url" value="<?= e((string)($association['instagram_url'] ?? '')) ?>" placeholder="https://instagram.com/your-handle">
                </div>
                <div class="field">
                    <label class="field__label" for="atw">X / Twitter</label>
                    <input class="input" type="url" id="atw" name="twitter_url" value="<?= e((string)($association['twitter_url'] ?? '')) ?>" placeholder="https://x.com/your-handle">
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="field">
                    <label class="field__label" for="and">Nextdoor</label>
                    <input class="input" type="url" id="and" name="nextdoor_url" value="<?= e((string)($association['nextdoor_url'] ?? '')) ?>" placeholder="https://nextdoor.com/neighborhood/...">
                </div>
                <div class="field"><!-- spacer --></div>
            </div>

            <div class="row" style="justify-content: space-between; align-items:center; margin-top: var(--sp-5);">
                <a href="/<?= e((string)$association['subdomain']) ?>/" target="_blank" rel="noopener" class="muted" style="font-size: var(--fs-sm);">
                    Preview the public landing &rarr;
                </a>
                <button class="btn btn--primary" type="submit">Save landing content</button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
    <script>
        (function () {
            if (typeof Quill === 'undefined') return;
            var el = document.getElementById('about-editor');
            if (!el) return;
            var hidden = document.getElementById('about-source');
            var initial = el.getAttribute('data-initial-html') || '';
            var quill = new Quill('#about-editor', {
                theme: 'snow',
                placeholder: 'Tell visitors what makes your community special...',
                modules: { toolbar: [['bold','italic','underline'], [{ 'list': 'ordered' }, { 'list': 'bullet' }], ['link'], ['clean']] }
            });
            el.querySelector('.ql-editor').style.minHeight = '160px';
            if (initial) quill.clipboard.dangerouslyPasteHTML(0, initial);
            // Sync on submit of the surrounding form
            var form = el.closest('form');
            if (form) form.addEventListener('submit', function () { hidden.value = quill.root.innerHTML; });
        })();
    </script>
    <?php endif; ?>

    <div class="card card--padded" style="margin-top: var(--sp-6);">
        <h3 class="card__title">Subscription</h3>
        <p>
            <strong>Plan:</strong> <?= e(ucfirst((string)$association['plan'])) ?>
            <?php if ($association['status'] === 'trial'): ?><span class="badge badge--warning">Trial</span><?php endif; ?>
        </p>
        <p class="muted" style="font-size: var(--fs-sm); margin: 0;">
            Plan upgrades and billing are coming in Phase 2. Email <a href="mailto:billing@badasshoa.com">billing@badasshoa.com</a> for changes.
        </p>
    </div>

    <div class="card card--padded" style="margin-top: var(--sp-6); border-color: var(--color-error); background: var(--color-error-bg);">
        <h3 class="card__title" style="color: var(--color-error);">Danger zone</h3>
        <p>Deactivating an association suspends all access. Only super admins can perform this action.</p>
        <button class="btn btn--danger" type="button" disabled aria-disabled="true">Deactivate association</button>
        <span class="muted" style="font-size: var(--fs-xs); margin-left: var(--sp-3);">Available to super admins only.</span>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
