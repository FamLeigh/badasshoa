<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$page_title = 'BadassHOA — Transparent, Simplified and Built for Your Community';
require __DIR__ . '/includes/header.php';
?>

<section class="hero hero--video">
    <video class="hero__video" autoplay muted loop playsinline preload="auto"
           poster="/assets/images/hero-poster.png" aria-hidden="true">
        <source src="/assets/videos/hero.mp4" type="video/mp4">
    </video>
    <div class="hero__overlay" aria-hidden="true"></div>

    <div class="container">
        <div class="hero__copy hero__copy--centered">
            <span class="hero__eyebrow">Modern HOA software</span>
            <h1>Transparent, Simplified and Built for Your Community.</h1>
            <p class="hero__lede">
                Documents, rules, announcements, and a resident directory &mdash; every feature on every plan, in one portal your board can run and your community will actually use.
            </p>
            <div class="hero__ctas">
                <a class="btn btn--primary btn--lg" href="/signup.php">Get started free</a>
                <a class="btn btn--ghost btn--lg" href="/pricing.php">See pricing</a>
            </div>
            <div class="hero__meta">
                <span><strong>5 minutes</strong> to set up</span>
                <span><strong>No credit card</strong> required</span>
                <span><strong>Made for</strong> condos &amp; HOAs of every size</span>
            </div>
        </div>
    </div>
</section>

<section class="section section--alt" id="features">
    <div class="container">
        <div style="max-width:640px; margin-bottom: var(--sp-12);">
            <span class="badge badge--orange">Built for boards</span>
            <h2 class="mt-2">Everything your association needs. Nothing it doesn&rsquo;t.</h2>
            <p class="muted" style="font-size: var(--fs-lg);">
                We picked the tools real boards actually use — and ditched the ones they don&rsquo;t. No bloat, no consultants, no page-long PDFs.
            </p>
        </div>
        <div class="grid grid--2">

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">📂</div>
                <h3>Documents &amp; Forms</h3>
                <p>One directory for bylaws, meeting minutes, architectural request forms, and insurance certs. Versioned, searchable, access-controlled.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">🔎</div>
                <h3>Rule &amp; Bylaw Search</h3>
                <p>Owners type "pet weight limit" and get the answer instantly. Boards stop fielding the same five questions over and over.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">📣</div>
                <h3>Announcements</h3>
                <p>Send maintenance notices, emergency alerts, and event invites — to all residents, owners only, or board only. Route by audience.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">👥</div>
                <h3>Resident Directory</h3>
                <p>Unit-level roster with owner/renter badges, board roles, contact info. Privacy-respecting — residents control what they share.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">🖼️</div>
                <h3>Media Library</h3>
                <p>Public gallery for amenity photos. Private album for maintenance evidence. Tag images to violations or work orders.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">🛡️</div>
                <h3>Role-Based Access</h3>
                <p>Super admin, board admin, board member, property manager, resident, renter — each sees exactly what they should. Audit log included.</p>
            </div>

        </div>
    </div>
</section>

<section class="section" id="pricing-preview">
    <div class="container">
        <div style="max-width:640px; margin-bottom: var(--sp-10);">
            <span class="badge badge--navy">Simple pricing</span>
            <h2 class="mt-2">Pay for what your building actually has.</h2>
            <p class="muted">Pricing scales with unit count. No per-resident fees, no setup costs, no surprises.</p>
        </div>

        <div class="grid grid--3">
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
        </div>

        <div class="center" style="margin-top: var(--sp-10);">
            <a class="btn btn--dark" href="/pricing.php">Use the unit calculator →</a>
        </div>
    </div>
</section>

<section class="section section--dark" id="faq">
    <div class="container">
        <div class="grid grid--2" style="align-items:start;">
            <div>
                <span class="badge badge--orange">FAQ</span>
                <h2 class="mt-2" style="color:#fff;">Common questions, honest answers.</h2>
                <p style="color:rgba(255,255,255,0.78); max-width:48ch;">
                    No fluff. If we can&rsquo;t answer in two sentences, the feature probably isn&rsquo;t ready yet.
                </p>
            </div>
            <div class="stack-lg" style="color:rgba(255,255,255,0.85);">
                <div>
                    <h4 style="color:#fff;">Do residents need an account?</h4>
                    <p>Only if they want one. Boards can run BadassHOA in board-only mode and selectively invite owners as the directory fills out.</p>
                </div>
                <div>
                    <h4 style="color:#fff;">Can I import our existing documents?</h4>
                    <p>Yes. Drop PDFs into the documents page and tag them. Bulk import via folder upload is on the roadmap.</p>
                </div>
                <div>
                    <h4 style="color:#fff;">What about payments / dues?</h4>
                    <p>Not in this release. We&rsquo;d rather do one thing well than half-bake a billing system. Coming in Phase 3.</p>
                </div>
                <div>
                    <h4 style="color:#fff;">Is my association&rsquo;s data private?</h4>
                    <p>Each association is fully isolated. We never share your data with anyone, ever. Audit log included on every plan.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container center" style="max-width: 640px;">
        <h2>Ready to run your condo like a boss?</h2>
        <p class="muted" style="font-size: var(--fs-lg);">
            Set up your association in under 5 minutes. Free to start. Cancel anytime.
        </p>
        <a class="btn btn--primary btn--lg" href="/signup.php">Get started free</a>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
