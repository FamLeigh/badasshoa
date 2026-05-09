<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$errors = [];
$values = [
    'association_name' => $_POST['association_name'] ?? '',
    'address'          => $_POST['address'] ?? '',
    'unit_count'       => $_POST['unit_count'] ?? '',
    'contact_name'     => $_POST['contact_name'] ?? '',
    'contact_email'    => $_POST['contact_email'] ?? '',
    'contact_phone'    => $_POST['contact_phone'] ?? '',
    'plan'             => $_POST['plan'] ?? ($_GET['plan'] ?? 'starter'),
];

$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$completed = false;

if ($submitted) {
    csrf_check();

    if (trim($values['association_name']) === '') $errors['association_name'] = 'Required.';
    if ((int)$values['unit_count'] < 1)            $errors['unit_count']       = 'Enter a number of units.';
    if (trim($values['contact_name']) === '')      $errors['contact_name']     = 'Required.';
    if (!filter_var($values['contact_email'], FILTER_VALIDATE_EMAIL)) $errors['contact_email'] = 'Valid email required.';

    if (!$errors) {
        $stmt = db()->prepare(
            'INSERT INTO signups (association_name, contact_name, contact_email, contact_phone, unit_count, plan_selected, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            trim($values['association_name']),
            trim($values['contact_name']),
            trim($values['contact_email']),
            trim($values['contact_phone']),
            (int)$values['unit_count'],
            $values['plan'] ?: 'starter',
            'pending',
        ]);
        $signupId = (int)db()->lastInsertId();

        send_mail(
            $values['contact_email'],
            'Welcome to BadassHOA — your portal is being set up',
            "Hi {$values['contact_name']},\n\nThanks for signing up {$values['association_name']} on BadassHOA.\nWe'll be in touch within 24 hours to get you live.\n\n— The BadassHOA team"
        );
        send_mail(
            (config()['app']['admin_email'] ?? 'admin@badasshoa.com'),
            "New signup: {$values['association_name']} ({$values['unit_count']} units)",
            "Plan: {$values['plan']}\nContact: {$values['contact_name']} <{$values['contact_email']}> {$values['contact_phone']}\nAddress: {$values['address']}\nSignup ID: $signupId"
        );

        audit('signup.submitted', ['signup_id' => $signupId, 'units' => (int)$values['unit_count']]);
        $completed = true;
    }
}

$page_title = 'Sign up — BadassHOA';
require __DIR__ . '/includes/header.php';
?>

<section class="section">
    <div class="container container--narrow">

        <?php if ($completed): ?>
            <div class="card card--padded center" style="text-align:center;">
                <span class="badge badge--success">Submitted</span>
                <h1 class="mt-2">Your portal is being set up.</h1>
                <p class="muted" style="font-size: var(--fs-lg);">
                    We&rsquo;ve received your application for <strong><?= e($values['association_name']) ?></strong>. Watch your inbox at <strong><?= e($values['contact_email']) ?></strong> — you&rsquo;ll hear from us within 24 hours.
                </p>
                <div class="row" style="justify-content:center; margin-top: var(--sp-6);">
                    <a class="btn btn--primary" href="/login.php">Sign in</a>
                    <a class="btn btn--ghost" href="/">Back to home</a>
                </div>
            </div>
        <?php else: ?>

            <div class="center" style="margin-bottom: var(--sp-8);">
                <span class="badge badge--orange">Free 14-day trial</span>
                <h1 class="mt-2">Set up your association.</h1>
                <p class="muted">Three quick steps. Under five minutes.</p>
            </div>

            <div class="steps" data-stepper>
                <div class="step" data-step>Association</div>
                <div class="step" data-step>Admin contact</div>
                <div class="step" data-step>Confirm</div>
            </div>

            <form class="card card--padded form" method="post" action="/signup.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="plan" value="<?= e($values['plan']) ?>">

                <!-- Step 1 -->
                <div data-step-panel>
                    <div class="form">
                        <div class="field">
                            <label class="field__label" for="association_name">Association name</label>
                            <input class="input" id="association_name" name="association_name" required value="<?= e($values['association_name']) ?>" placeholder="e.g. Oakridge Condominiums">
                            <?php if (!empty($errors['association_name'])): ?><div class="field__error"><?= e($errors['association_name']) ?></div><?php endif; ?>
                        </div>
                        <div class="field">
                            <label class="field__label" for="address">Street address</label>
                            <input class="input" id="address" name="address" value="<?= e($values['address']) ?>" placeholder="123 Main St, Anytown">
                        </div>
                        <div class="field">
                            <label class="field__label" for="unit_count">Number of units</label>
                            <input class="input" type="number" min="1" max="2000" id="unit_count" name="unit_count" required value="<?= e((string)$values['unit_count']) ?>" placeholder="48">
                            <div class="field__hint">Determines your plan. We&rsquo;ll confirm pricing on the next step.</div>
                            <?php if (!empty($errors['unit_count'])): ?><div class="field__error"><?= e($errors['unit_count']) ?></div><?php endif; ?>
                        </div>
                        <div class="row" style="justify-content: flex-end;">
                            <a class="btn btn--ghost" href="/">Cancel</a>
                            <button type="button" class="btn btn--primary" data-step-next="1">Continue →</button>
                        </div>
                    </div>
                </div>

                <!-- Step 2 -->
                <div data-step-panel hidden>
                    <div class="form">
                        <div class="form-row form-row--2">
                            <div class="field">
                                <label class="field__label" for="contact_name">Your name</label>
                                <input class="input" id="contact_name" name="contact_name" required value="<?= e($values['contact_name']) ?>">
                                <?php if (!empty($errors['contact_name'])): ?><div class="field__error"><?= e($errors['contact_name']) ?></div><?php endif; ?>
                            </div>
                            <div class="field">
                                <label class="field__label" for="contact_phone">Phone</label>
                                <input class="input" id="contact_phone" name="contact_phone" value="<?= e($values['contact_phone']) ?>" placeholder="555-123-4567">
                            </div>
                        </div>
                        <div class="field">
                            <label class="field__label" for="contact_email">Email</label>
                            <input class="input" type="email" id="contact_email" name="contact_email" required value="<?= e($values['contact_email']) ?>" placeholder="you@yourassociation.com">
                            <div class="field__hint">We&rsquo;ll send your portal credentials here.</div>
                            <?php if (!empty($errors['contact_email'])): ?><div class="field__error"><?= e($errors['contact_email']) ?></div><?php endif; ?>
                        </div>
                        <div class="row" style="justify-content: space-between;">
                            <button type="button" class="btn btn--ghost" data-step-prev="0">← Back</button>
                            <button type="button" class="btn btn--primary" data-step-next="2">Review →</button>
                        </div>
                    </div>
                </div>

                <!-- Step 3 -->
                <div data-step-panel hidden>
                    <div class="card" style="background: var(--color-surface); border-color: var(--color-border-strong);">
                        <p class="muted" style="font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Review your details</p>
                        <div class="grid grid--2" style="gap: var(--sp-4); font-size: var(--fs-sm);">
                            <div><strong>Association:</strong><br><span data-confirm="association_name">—</span></div>
                            <div><strong>Address:</strong><br><span data-confirm="address">—</span></div>
                            <div><strong>Units:</strong><br><span data-confirm="unit_count">—</span></div>
                            <div><strong>Contact:</strong><br><span data-confirm="contact_name">—</span> &lt;<span data-confirm="contact_email">—</span>&gt;</div>
                        </div>
                    </div>
                    <p class="muted" style="margin-top: var(--sp-5);">By submitting, you agree to our (yet to be written) terms. We&rsquo;ll set up your portal and email you within 24 hours.</p>
                    <div class="row" style="justify-content: space-between; margin-top: var(--sp-5);">
                        <button type="button" class="btn btn--ghost" data-step-prev="1">← Back</button>
                        <button type="submit" class="btn btn--primary btn--lg">Submit application</button>
                    </div>
                </div>

            </form>

            <script>
                // Mirror step-1+2 values into review on each "Review →" click
                document.querySelector('[data-step-next="2"]')?.addEventListener('click', function () {
                    document.querySelectorAll('[data-confirm]').forEach(function (el) {
                        var name = el.getAttribute('data-confirm');
                        var input = document.querySelector('[name="' + name + '"]');
                        if (input) el.textContent = input.value || '—';
                    });
                });
            </script>

        <?php endif; ?>

    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
