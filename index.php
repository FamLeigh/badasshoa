<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$page_title = 'BadassHOA — Transparent, Simplified and Built for Your Community';
require __DIR__ . '/includes/header.php';
?>

<section class="hero hero--video">
    <video class="hero__video" autoplay muted loop playsinline preload="auto"
           poster="/assets/images/hero-poster.png" aria-hidden="true">
        <source src="/assets/videos/badasshoa_hero_2026b.mp4" type="video/mp4">
    </video>
    <div class="hero__overlay" aria-hidden="true"></div>

    <div class="container">
        <div class="hero__copy hero__copy--centered">
            <span class="hero__eyebrow">Modern HOA software</span>
            <h1>Transparent, Simplified and Built for Your Community.</h1>
            <p class="hero__lede">
                Rules, documents, announcements, work orders, architectural review, electronic signatures, committees, events, and a real resident directory &mdash; every feature on every plan, in one portal your board can actually run.
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
        <div style="max-width:680px; margin-bottom: var(--sp-12);">
            <span class="badge badge--orange">Built for boards</span>
            <h2 class="mt-2">Everything your association needs. Nothing it doesn&rsquo;t.</h2>
            <p class="muted" style="font-size: var(--fs-lg);">
                We picked the tools real boards actually use &mdash; and ditched the ones they don&rsquo;t. No bloat, no consultants, no page-long PDFs.
            </p>
        </div>

        <div class="grid grid--3">

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">✍️</div>
                <h3>Electronic Signatures</h3>
                <p>Members sign 14 pre-built forms directly in the browser — guest registration, parking permits, pet registration, service animal disclosures, move-in/out, and more. Every signature captures intent, timestamp, IP address, and association context. E-SIGN / UETA audit trail. No paper, no printer, no scanning docs and emailing them back.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">📜</div>
                <h3>Rules &amp; Bylaws</h3>
                <p>Searchable rulebook with categories and source tags (bylaw / board rule / policy). Owners suggest changes, the board edits + approves with one click. Print all or just what&rsquo;s on screen.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">📄</div>
                <h3>Documents</h3>
                <p>Upload PDFs or compose rich-text documents inline. Scope anything to the whole community, a specific unit, or a specific member &mdash; leases, appointment letters, deeds. Categorized + access-controlled.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">📣</div>
                <h3>Announcements</h3>
                <p>Schedule a post for next Monday, pick how long it stays up (a day, a month, until a specific date, or never), route by audience. Auto-expire so old notices don&rsquo;t clutter the board.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">🏗</div>
                <h3>Architectural Review</h3>
                <p>Owners file a request to paint, install a dish, build a deck, replace windows. Board reviews on a thread, approves / denies / approves-with-conditions. Decision letter generated. No more email chains.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">🛠</div>
                <h3>Work Orders</h3>
                <p>Operational tickets the board + property manager actually use. Priority, location, unit, assignee, contractor, cost estimate &amp; actual, timeline of status changes + notes. Convert a complaint to a work order in one click.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">💬</div>
                <h3>Concerns &amp; Compliments</h3>
                <p>Members file complaints, compliments, or suggestions &mdash; with an anonymity option. Board threads internal + public replies, sets status, marks resolved. Submitter gets emailed on every update.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">📅</div>
                <h3>Events &amp; Calendar</h3>
                <p>Board meetings, social gatherings, work parties &mdash; including recurring events that auto-renew. Print today, this week, or this month. Click any event for the detail view.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">📋</div>
                <h3>Board Meetings &amp; Minutes</h3>
                <p>Build an agenda from proposed items, run the meeting from the screen, record motions and resolutions with per-member yes / no / abstain votes, and print a Florida §718-compliant notice + minutes in one click.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">🗳</div>
                <h3>Voting &amp; Ballots</h3>
                <p>Run elections, bylaw amendments, and budget approvals. Owners and renters cast ballots from the dashboard; results reveal after the deadline or as soon as quorum is met. Anonymous or attributed, your choice.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">✒️</div>
                <h3>Sign Any PDF</h3>
                <p>Drop a contract, addendum, or notice into Documents and members sign it directly in the browser &mdash; place the signature, save the PDF. Full audit certificate for every signed copy. Required-signer tracking with one-click email nudges.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">📧</div>
                <h3>Email Broadcasts</h3>
                <p>Compose, audience-target, and send mass emails to your community with PDF attachments. Track who got it, who bounced, who unsubscribed &mdash; per-recipient delivery in the activity log.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">🎉</div>
                <h3>Amenity Booking</h3>
                <p>Members reserve the clubhouse, pool deck, BBQ pavilion, or any common space the board defines. Open events show on the community calendar so neighbors can join; private events keep the occasion hidden but still reserve the space.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">🌍</div>
                <h3>Your Own Domain</h3>
                <p>Run your community on <code>yourassociation.com</code> instead of <code>badasshoa.com/{slug}/</code>. Branded sign-in page with your logo and hero image, your domain in the address bar. $99/year add-on with DNS instructions we email straight to the board.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">🤝</div>
                <h3>Committees</h3>
                <p>Standing committees with chairs and members. Owners self-join from a card, board assigns directly. Print a one-page flyer to recruit. Description editor with inline formatting.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">👥</div>
                <h3>Resident &amp; Board Directory</h3>
                <p>Unit-level roster with primary + secondary phone and email, mailing address, board office titles (President, VP, Secretary, Treasurer, Director). Owners, renters, and employees flagged. Privacy-respecting.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">🌐</div>
                <h3>Public Landing Page</h3>
                <p>Every association gets a branded public page at <code>badasshoa.com/{your-slug}/</code> &mdash; meet your board, public docs, upcoming events, contact form, embedded map. Visitors find you; you control what shows.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">🅿️</div>
                <h3>Parking &amp; Units</h3>
                <p>Per-unit ownership %, square footage, HOA + garage assessments, monthly fee. Parking spots assigned to units with kind (garage / surface / covered / tandem). Rental tag when a unit&rsquo;s tenant-occupied.</p>
            </div>

            <div class="feature">
                <div class="feature__icon" aria-hidden="true">🔎</div>
                <h3>Global Search</h3>
                <p>One search box at the top hits rules, documents, announcements, events, concerns, ARC requests, and members. Click any result to open it. Access-aware so members never see what they shouldn&rsquo;t.</p>
            </div>

        </div>

        <!-- "More tools" strip — the supporting cast -->
        <div style="margin-top: var(--sp-12); padding-top: var(--sp-8); border-top: 1px solid var(--color-border);">
            <h3 style="font-size: var(--fs-xl); margin: 0 0 var(--sp-2);">And the boring-but-essential bits</h3>
            <p class="muted" style="margin-bottom: var(--sp-6); font-size: var(--fs-md);">
                The tools that don&rsquo;t make a splashy demo but every board ends up needing.
            </p>
            <div class="grid grid--3" style="gap: var(--sp-4);">
                <div class="feature feature--compact">
                    <strong>📋 Insurance &amp; COIs</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Track association policies + contractor certificates with color-coded renewal warnings.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>💼 Employees</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Paid + volunteer staff with title, pay type, dates. Works even when an owner is also the maintenance person.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>☎ Contacts</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Emergency lines, contractors, utilities. Print a one-page sheet you can hand to residents.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>❓ FAQ</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Living FAQ with CSV import. Shows up on the public landing too.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>🖼 Media</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Public photo galleries + private albums. Edit metadata after upload.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>📍 Locations</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Name the lobby, pool, clubhouse, mail room. Used everywhere a location matters.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>💾 Storage tracking</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">1 GB included &mdash; plenty to get started. Drive-style breakdown shows where it&rsquo;s going. Add more only if needed.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>🛡 Activity log</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Every state change captured. &ldquo;Who deleted that document?&rdquo; answered in seconds.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>🖨 Print everything</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Letterhead + footer on every printable view: rules, contacts, parking, events, ARC decisions.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>📺 Lobby TV</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">A bookmarkable URL that turns any TV into a live community display &mdash; announcements, events, marketplace. Light or dark, columns or ticker, Tizen-compatible.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>🛒 Marketplace</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Members buy and sell within the community &mdash; furniture, baby gear, kayaks. Photos, price, contact in one card.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>🏷 Property Listings</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Units for sale or rent surface on the public landing. Beds, baths, square footage, photos, contact &mdash; ready for prospects to browse.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>🏙 Area Attractions</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Dining, shopping, outdoor, culture &mdash; a curated &ldquo;what&rsquo;s nearby&rdquo; section the board controls. Great for selling the neighborhood.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>📰 Newsletter Signup</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Prospective owners and neighbors subscribe from your public landing. One-click unsubscribe. Build the list before they move in.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>📝 Violations Workflow</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">Formal violation tracking with print-ready notice letters. Open / sent / resolved status with photos and rule citations.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>📚 In-App Help</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">37 role-gated help topics with images and embedded videos. Searchable from the same global search bar that finds everything else.</p>
                </div>
                <div class="feature feature--compact">
                    <strong>⚖️ Florida Statutes</strong>
                    <p class="muted" style="font-size: var(--fs-sm); margin: var(--sp-1) 0 0;">All 232 sections of FL Ch. 718, 719, 720, and 553 indexed and full-text searchable from inside the portal. No more PDF hunting.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section" id="pricing-preview">
    <div class="container">
        <div style="max-width:640px; margin-bottom: var(--sp-10);">
            <span class="badge badge--navy">Simple pricing</span>
            <h2 class="mt-2">Only pay for what you need.</h2>
            <p class="muted">No per-resident fees, no setup costs, no surprises. Every feature on every plan &mdash; the plan you pick is just about how many units you have.</p>
        </div>

        <div class="grid grid--2" style="max-width: 980px; margin: 0 auto;">
            <div class="price-card">
                <div class="price-card__name">Starter</div>
                <div class="price-card__price">$20<small>/mo</small></div>
                <div class="price-card__limit">Up to 20 units</div>
                <ul>
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
                    <li>✍️ <strong>Electronic signatures</strong> — E-SIGN/UETA audit trail, 14 built-in form types</li>
                    <li>📋 Insurance &amp; COI tracker · 🅿️ parking · 💼 employees · ☎️ contacts · ❓ FAQ</li>
                    <li>🔎 Global search · 🛡 audit log · 🎨 custom branding</li>
                    <li>💾 1 GB of storage included — plenty to get started, add more only if needed</li>
                </ul>
                <a class="btn btn--ghost" href="/signup.php?plan=starter">Start free trial</a>
            </div>

            <div class="price-card price-card--featured">
                <div class="price-card__name">Growth</div>
                <div class="price-card__price">+$0.50<small>/unit over 20</small></div>
                <div class="price-card__limit">21+ units · any size &nbsp;<a href="/pricing.php" style="font-size: var(--fs-xs); font-weight: 600; color: var(--color-navy); background: rgba(15,31,61,0.08); border-radius: 4px; padding: 2px 8px; white-space: nowrap; text-decoration: none;">Calculate →</a></div>
                <ul>
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
                    <li>✍️ <strong>Electronic signatures</strong> — E-SIGN/UETA audit trail, 14 built-in form types</li>
                    <li>📋 Insurance &amp; COI tracker · 🅿️ parking · 💼 employees · ☎️ contacts · ❓ FAQ</li>
                    <li>🔎 Global search · 🛡 audit log · 🎨 custom branding</li>
                    <li>💾 1 GB of storage included — plenty to get started, add more only if needed</li>
                </ul>
                <a class="btn btn--primary" href="/signup.php?plan=growth">Start free trial</a>
            </div>
        </div>

        <div class="center" style="margin-top: var(--sp-10);">
            <a class="btn btn--dark" href="/pricing.php">Use the unit calculator →</a>
        </div>

        <p class="muted center" style="margin-top: var(--sp-6); font-size: var(--fs-sm);">
            Want your own domain? Add <strong>yourassociation.com</strong> to any plan for <strong>$99/year</strong>. <a href="/pricing.php#add-ons">Details →</a>
        </p>
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
                    <p>Only if they want one. Boards can run BadassHOA in board-only mode and selectively invite owners as the directory fills out. Each association also gets a branded public landing page residents can see without signing in.</p>
                </div>
                <div>
                    <h4 style="color:#fff;">Can I import our existing data?</h4>
                    <p>Yes. CSV import for members, units, parking spots, rules, and FAQs. Drop PDFs into Documents and tag them. We get associations live in an afternoon, not a month.</p>
                </div>
                <div>
                    <h4 style="color:#fff;">How do you handle storage?</h4>
                    <p>1 GB of storage included &mdash; plenty to get started. A Drive-style breakdown shows exactly where it&rsquo;s going, so you can clean up before adding more. Extra GB is available only if you need it &mdash; no surprise bills.</p>
                </div>
                <div>
                    <h4 style="color:#fff;">What about payments / dues?</h4>
                    <p>Not in this release. We&rsquo;d rather do one thing well than half-bake a billing system. Coming next.</p>
                </div>
                <div>
                    <h4 style="color:#fff;">Is my association&rsquo;s data private?</h4>
                    <p>Each association is fully isolated. We never share your data with anyone, ever. Every state change goes into the audit log so you can answer &ldquo;who deleted that document?&rdquo; in seconds.</p>
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
