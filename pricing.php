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
            <h1 class="mt-2">Only pay for what you need.</h1>
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
                    <div class="calc__price" data-calc-price>$34/mo</div>
                </div>
            </div>
            <div class="calc__breakdown" data-calc-breakdown>$20 base + $0.50 × 28 units over 20 = $34/mo. 30-day free trial.</div>
            <div class="row" style="margin-top: var(--sp-6); justify-content: flex-end;">
                <a class="btn btn--primary btn--lg" href="/signup.php" data-calc-cta>Start 30-day free trial</a>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="center" style="margin-bottom: var(--sp-10);">Plans &amp; features</h2>
        <div class="grid grid--3">

            <?php
            // Same feature list rendered under all three cards — every plan
            // includes everything; the price card is just about unit count.
            $featureList = <<<HTML
                <li>📜 Rules &amp; bylaws · suggestions · category filter · print all / filtered</li>
                <li>📄 Documents (versioned, scoped per unit / per member, access-controlled)</li>
                <li>📣 Announcements with scheduling + auto-expiry</li>
                <li>📅 Events &amp; calendar (recurring, printable, public)</li>
                <li>🏗 Architectural Review requests with board decisions</li>
                <li>🛠 Work Orders (admin-only ops tickets, timeline + notes)</li>
                <li>💬 Concerns &amp; compliments with threaded replies</li>
                <li>🤝 Committees (chairs, members, printable flyers)</li>
                <li>👥 Resident &amp; board directory with officer titles</li>
                <li>🌐 Branded public landing page at <code>badasshoa.com/{slug}/</code></li>
                <li>📝 Forms library (14 types) with electronic signatures</li>
                <li>📋 Insurance &amp; COI tracker · 🅿️ parking · 💼 employees · ☎️ contacts · ❓ FAQ</li>
                <li>🔎 Global search · 🛡 audit log · 🎨 custom branding</li>
                <li>💾 1 GB of storage included &mdash; plenty to get started, add more only if needed</li>
                HTML;
            ?>

            <div class="price-card">
                <div class="price-card__name">Starter</div>
                <div class="price-card__price">$20<small>/mo</small></div>
                <div class="price-card__limit">Up to 20 units</div>
                <ul><?= $featureList ?></ul>
                <a class="btn btn--ghost" href="/signup.php?plan=starter">Start free trial</a>
            </div>

            <div class="price-card price-card--featured">
                <div class="price-card__name">Growth</div>
                <div class="price-card__price">$20<small> + $0.50/unit over 20</small></div>
                <div class="price-card__limit">21+ units · any size</div>
                <ul><?= $featureList ?></ul>
                <a class="btn btn--primary" href="/signup.php?plan=growth">Start free trial</a>
            </div>

            <div class="price-card">
                <div class="price-card__name">Enterprise</div>
                <div class="price-card__price">Custom</div>
                <div class="price-card__limit">Multi-property &amp; volume</div>
                <ul><?= $featureList ?></ul>
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

        <div class="grid grid--2" style="max-width: 880px; margin: 0 auto; gap: var(--sp-8);">
            <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap: var(--sp-3); font-size: var(--fs-md);">
                <li><strong>📜 Rules &amp; bylaws</strong><br><span class="muted">FULLTEXT search, suggestions, category filter, print all.</span></li>
                <li><strong>📄 Documents</strong><br><span class="muted">Versioned, scoped per-unit / per-member, access-controlled.</span></li>
                <li><strong>📣 Announcements</strong><br><span class="muted">Scheduling, auto-expiry, audience-targeted.</span></li>
                <li><strong>📅 Events &amp; calendar</strong><br><span class="muted">Recurring, printable, public landing-page integration.</span></li>
                <li><strong>🏗 Architectural Review</strong><br><span class="muted">Owner requests + board decisions + decision letters.</span></li>
                <li><strong>🛠 Work Orders</strong><br><span class="muted">Operational tickets with timeline + notes + cost tracking.</span></li>
                <li><strong>💬 Concerns &amp; compliments</strong><br><span class="muted">Members file, board threads + resolves.</span></li>
            </ul>
            <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap: var(--sp-3); font-size: var(--fs-md);">
                <li><strong>📝 Forms with e-signatures</strong><br><span class="muted">14 form types, E-SIGN/UETA-shaped audit trail.</span></li>
                <li><strong>👥 Directory + officer roles</strong><br><span class="muted">President / VP / Sec / Treasurer / Director titles.</span></li>
                <li><strong>🌐 Public landing page</strong><br><span class="muted">Branded community page at <code>badasshoa.com/{slug}/</code>.</span></li>
                <li><strong>📋 Insurance + COIs</strong><br><span class="muted">Policy tracking with renewal warnings.</span></li>
                <li><strong>🔎 Global search</strong><br><span class="muted">One search across rules, docs, events, members, more.</span></li>
                <li><strong>🛡 Activity log</strong><br><span class="muted">Every state change captured. Who did what, when.</span></li>
                <li><strong>💾 1 GB included</strong><br><span class="muted">Plenty to get started; add more only if needed. Drive-style breakdown built in.</span></li>
            </ul>
        </div>

        <p class="muted center" style="margin-top: var(--sp-10); font-size: var(--fs-sm); max-width: 560px; margin-left: auto; margin-right: auto;">
            <strong>Enterprise</strong> exists for multi-property portfolios and volume contracts &mdash; same features, custom pricing and onboarding.
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
