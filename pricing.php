<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$page_title = 'Pricing — BadassHOA';
require __DIR__ . '/includes/header.php';
?>

<section class="section section--tight">
    <div class="container">
        <div class="center" style="max-width: 640px; margin: 0 auto var(--sp-10);">
            <span class="badge badge--orange">Pricing</span>
            <h1 class="mt-2">Pricing that scales with your building.</h1>
            <p class="muted" style="font-size: var(--fs-lg);">
                Slide for your unit count. We&rsquo;ll show your monthly price live.
            </p>
        </div>

        <div class="calc" data-calc>
            <div class="calc__row">
                <div>
                    <label class="calc__label" for="calc-units">How many units in your association?</label>
                    <div class="row">
                        <input type="range" id="calc-units" min="1" max="500" step="1" value="48">
                        <input class="input" type="number" min="1" max="2000" value="48" aria-label="Unit count">
                    </div>
                </div>
                <div class="calc__output">
                    <div class="calc__tier" data-calc-tier>Starter</div>
                    <div class="calc__price" data-calc-price>$44/mo</div>
                </div>
            </div>
            <div class="calc__breakdown" data-calc-breakdown>$20 base + $0.50 × 48 units = $44/mo.</div>
            <div class="row" style="margin-top: var(--sp-6); justify-content: flex-end;">
                <a class="btn btn--primary btn--lg" href="/signup.php" data-calc-cta>Start free</a>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="center" style="margin-bottom: var(--sp-10);">Plans &amp; features</h2>
        <div class="grid grid--4">

            <div class="price-card">
                <div class="price-card__name">Starter</div>
                <div class="price-card__price">$20 + $0.50<small>/unit</small></div>
                <div class="price-card__limit">Up to 50 units</div>
                <ul>
                    <li>All core features</li>
                    <li>10 GB storage</li>
                    <li>Email support</li>
                    <li>Up to 5 board members</li>
                </ul>
                <a class="btn btn--ghost" href="/signup.php?plan=starter">Start free</a>
            </div>

            <div class="price-card price-card--featured">
                <div class="price-card__name">Growth</div>
                <div class="price-card__price">$20 + $0.50<small>/unit</small></div>
                <div class="price-card__limit">51 – 150 units</div>
                <ul>
                    <li>Everything in Starter</li>
                    <li>50 GB storage</li>
                    <li>Priority support</li>
                    <li>Custom branding</li>
                    <li>Audit log export</li>
                </ul>
                <a class="btn btn--primary" href="/signup.php?plan=growth">Start trial</a>
            </div>

            <div class="price-card">
                <div class="price-card__name">Professional</div>
                <div class="price-card__price">$20 + $0.50<small>/unit</small></div>
                <div class="price-card__limit">151 – 300 units</div>
                <ul>
                    <li>Everything in Growth</li>
                    <li>200 GB storage</li>
                    <li>Dedicated success mgr.</li>
                    <li>Custom roles</li>
                    <li>API access (beta)</li>
                </ul>
                <a class="btn btn--ghost" href="/signup.php?plan=professional">Start trial</a>
            </div>

            <div class="price-card">
                <div class="price-card__name">Enterprise</div>
                <div class="price-card__price">Custom</div>
                <div class="price-card__limit">300+ units / multi-property</div>
                <ul>
                    <li>Everything in Professional</li>
                    <li>Unlimited storage</li>
                    <li>SSO &amp; advanced security</li>
                    <li>SLA</li>
                    <li>Onboarding included</li>
                </ul>
                <a class="btn btn--dark" href="mailto:sales@badasshoa.com?subject=Enterprise%20pricing">Contact sales</a>
            </div>

        </div>
    </div>
</section>

<section class="section section--alt">
    <div class="container">
        <h2 class="center">Compare features</h2>
        <div style="overflow-x:auto;">
        <table class="table" style="margin-top: var(--sp-6);">
            <thead>
                <tr>
                    <th>Feature</th>
                    <th>Starter</th>
                    <th>Growth</th>
                    <th>Professional</th>
                    <th>Enterprise</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>Document directory</td><td>✓</td><td>✓</td><td>✓</td><td>✓</td></tr>
                <tr><td>Rule &amp; bylaw search</td><td>✓</td><td>✓</td><td>✓</td><td>✓</td></tr>
                <tr><td>Announcements</td><td>✓</td><td>✓</td><td>✓</td><td>✓</td></tr>
                <tr><td>Resident directory</td><td>✓</td><td>✓</td><td>✓</td><td>✓</td></tr>
                <tr><td>Media library</td><td>✓</td><td>✓</td><td>✓</td><td>✓</td></tr>
                <tr><td>Custom branding</td><td>—</td><td>✓</td><td>✓</td><td>✓</td></tr>
                <tr><td>Audit log export</td><td>—</td><td>✓</td><td>✓</td><td>✓</td></tr>
                <tr><td>Custom roles</td><td>—</td><td>—</td><td>✓</td><td>✓</td></tr>
                <tr><td>API access</td><td>—</td><td>—</td><td>Beta</td><td>✓</td></tr>
                <tr><td>SSO</td><td>—</td><td>—</td><td>—</td><td>✓</td></tr>
                <tr><td>SLA</td><td>—</td><td>—</td><td>—</td><td>✓</td></tr>
            </tbody>
        </table>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
