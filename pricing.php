<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$page_title = 'Pricing — BadassHOA';
require __DIR__ . '/includes/header.php';
?>

<section class="section section--tight">
    <div class="container">
        <div class="center" style="max-width: 640px; margin: 0 auto var(--sp-10);">
            <span class="badge badge--orange">Free 30-day trial &middot; no card required</span>
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
                    <div class="calc__tier" data-calc-tier>Growth</div>
                    <div class="calc__price" data-calc-price>$24/mo</div>
                </div>
            </div>
            <div class="calc__breakdown" data-calc-breakdown>$0.50 × 48 units = $24/mo. 30-day free trial.</div>
            <div class="row" style="margin-top: var(--sp-6); justify-content: flex-end;">
                <a class="btn btn--primary btn--lg" href="/signup.php" data-calc-cta>Start 30-day free trial</a>
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
                <div class="price-card__price">$20<small>/mo</small></div>
                <div class="price-card__limit">Up to 10 units</div>
                <ul>
                    <li>Documents, rules, directory, comms, media</li>
                    <li>Unlimited storage</li>
                    <li>Priority support</li>
                    <li>Custom branding</li>
                    <li>Audit log + API access</li>
                    <li>SSO ready</li>
                </ul>
                <a class="btn btn--ghost" href="/signup.php?plan=starter">Start free trial</a>
            </div>

            <div class="price-card price-card--featured">
                <div class="price-card__name">Growth</div>
                <div class="price-card__price">$0.50<small>/unit/mo</small></div>
                <div class="price-card__limit">11 – 100 units</div>
                <ul>
                    <li>Documents, rules, directory, comms, media</li>
                    <li>Unlimited storage</li>
                    <li>Priority support</li>
                    <li>Custom branding</li>
                    <li>Audit log + API access</li>
                    <li>SSO ready</li>
                </ul>
                <a class="btn btn--primary" href="/signup.php?plan=growth">Start free trial</a>
            </div>

            <div class="price-card">
                <div class="price-card__name">Professional</div>
                <div class="price-card__price">$0.75<small>/unit/mo</small></div>
                <div class="price-card__limit">101+ units</div>
                <ul>
                    <li>Documents, rules, directory, comms, media</li>
                    <li>Unlimited storage</li>
                    <li>Priority support</li>
                    <li>Custom branding</li>
                    <li>Audit log + API access</li>
                    <li>SSO ready</li>
                </ul>
                <a class="btn btn--ghost" href="/signup.php?plan=professional">Start free trial</a>
            </div>

            <div class="price-card">
                <div class="price-card__name">Enterprise</div>
                <div class="price-card__price">Custom</div>
                <div class="price-card__limit">Multi-property &amp; volume</div>
                <ul>
                    <li>Documents, rules, directory, comms, media</li>
                    <li>Unlimited storage</li>
                    <li>Priority support</li>
                    <li>Custom branding</li>
                    <li>Audit log + API access</li>
                    <li>SSO ready</li>
                </ul>
                <a class="btn btn--dark" href="mailto:sales@badasshoa.com?subject=Enterprise%20pricing">Contact sales</a>
            </div>

        </div>
    </div>
</section>

<section class="section section--alt">
    <div class="container">
        <div class="center" style="max-width: 680px; margin: 0 auto var(--sp-10);">
            <span class="badge badge--orange">No tier-gating</span>
            <h2 class="mt-2">Every plan includes everything.</h2>
            <p class="muted" style="font-size: var(--fs-lg);">
                No &ldquo;upgrade to unlock SSO.&rdquo; No &ldquo;the API is Pro-only.&rdquo; If we built it, you have it &mdash; on day one, on any plan. The plan you pick is just about how many units your association has.
            </p>
        </div>

        <div class="grid grid--2" style="max-width: 820px; margin: 0 auto; gap: var(--sp-8);">
            <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap: var(--sp-3); font-size: var(--fs-md);">
                <li><strong>📂 Documents &amp; forms</strong><br><span class="muted">Versioned, access-controlled, searchable.</span></li>
                <li><strong>🔎 Rule &amp; bylaw search</strong><br><span class="muted">FULLTEXT search across every rule on file.</span></li>
                <li><strong>📣 Announcements</strong><br><span class="muted">Audience-targeted &mdash; all, owners, renters, board.</span></li>
                <li><strong>👥 Resident directory</strong><br><span class="muted">Unit-level roster with owner/renter badges.</span></li>
                <li><strong>🖼️ Media library</strong><br><span class="muted">Public gallery + private maintenance album.</span></li>
            </ul>
            <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap: var(--sp-3); font-size: var(--fs-md);">
                <li><strong>♾️ Unlimited storage</strong><br><span class="muted">No GB caps to babysit, ever.</span></li>
                <li><strong>🎨 Custom branding</strong><br><span class="muted">Your association&rsquo;s logo and colors.</span></li>
                <li><strong>🛡️ Audit log + API access</strong><br><span class="muted">Every action tracked. Build integrations.</span></li>
                <li><strong>🔐 SSO ready</strong><br><span class="muted">Works with Google Workspace and Microsoft 365.</span></li>
                <li><strong>⚡ Priority support</strong><br><span class="muted">Real humans, not chatbots.</span></li>
            </ul>
        </div>

        <p class="muted center" style="margin-top: var(--sp-10); font-size: var(--fs-sm); max-width: 560px; margin-left: auto; margin-right: auto;">
            <strong>Enterprise</strong> exists for multi-property portfolios and volume contracts &mdash; same features, custom pricing and onboarding.
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
