<?php
declare(strict_types=1);

/**
 * Help topic definitions.
 *
 * Each topic:
 *   slug      — URL key (?topic=slug)
 *   title     — display title
 *   category  — group label in the sidebar
 *   min_role  — minimum role that can see this topic (uses ROLE_RANK)
 *               'renter' = everyone, 'board_member' = management only
 *   body      — HTML string rendered in the content panel
 *
 * Add a new topic here and it appears automatically in the viewer.
 * Update the body string when a feature changes.
 */
function help_topics(): array
{
    return [

        // ── YOUR ACCOUNT ────────────────────────────────────────────────

        [
            'slug'     => 'getting-started',
            'title'    => 'Getting started',
            'category' => 'Your account',
            'min_role' => 'renter',
            'body'     => <<<HTML
                <p>Welcome to BadassHOA. Here's what you can do from the moment you sign in.</p>

                <h3>Your dashboard home</h3>
                <p>The home page shows a live count of everything in your community — members, units, announcements, events, and more. Each tile is clickable and takes you straight to that section.</p>

                <h3>The sidebar</h3>
                <p>Everything is organized into groups on the left sidebar:</p>
                <ul>
                    <li><strong>Community</strong> — announcements, events, FAQ, marketplace</li>
                    <li><strong>Resources</strong> — documents, forms, rules &amp; bylaws, legal reference, meeting minutes, media, directory, contacts</li>
                    <li><strong>Governance</strong> — committees, feedback, architectural review, violations, work orders, voting</li>
                    <li><strong>Operations</strong> — units, parking, employees, insurance (board/management only)</li>
                    <li><strong>Configuration</strong> — activity log, settings (board/management only)</li>
                </ul>
                <p>Click any group header to collapse or expand it. The sidebar collapses to icons using the toggle button on the left edge.</p>

                <h3>Your profile</h3>
                <p>Click your name in the top-right corner to edit your profile — upload a headshot, write a bio, and manage your saved e-signatures.</p>
            HTML,
        ],

        [
            'slug'     => 'directory-privacy',
            'title'    => 'Directory privacy — hiding yourself from other residents',
            'category' => 'Your account',
            'min_role' => 'renter',
            'body'     => <<<HTML
                <p>Owners and renters can hide themselves from the resident directory without involving the board.</p>

                <h3>How it works</h3>
                <p>Go to <strong>Settings → Your account</strong> and check <em>"Hide me from the resident directory."</em> Save. That's it.</p>
                <p>Other residents won't see your name, unit, email, or phone in the directory. You still exist in the system — you can still log in, submit forms, post to the marketplace, and use everything else normally.</p>

                <h3>Who can still see you</h3>
                <p>The board and management can always see your information. They need it to manage the community — dues, work orders, violation notices, and anything else that requires contacting you directly.</p>

                <h3>Who doesn't get the toggle</h3>
                <p>Board members and admins don't have an opt-out option. Their presence in the directory is part of the role — residents have a right to know who's managing their community.</p>

                <h3>The board's override</h3>
                <p>A board admin can also mark your account as <em>inactive</em> from the directory's Edit screen, which hides you more completely. Your privacy toggle is the self-serve version that doesn't require board action.</p>
            HTML,
        ],

        [
            'slug'     => 'change-password',
            'title'    => 'Changing your password',
            'category' => 'Your account',
            'min_role' => 'renter',
            'body'     => <<<HTML
                <h3>While signed in</h3>
                <p>Go to <strong>Settings → Your account</strong> and use the Change password form. You'll need your current password.</p>

                <h3>If you're locked out</h3>
                <p>Use <a href="/forgot.php">Forgot password</a> on the login page. Enter your email and we'll send a reset link. The link expires after 1 hour and can only be used once. Requesting a new link cancels any previously sent link.</p>

                <h3>If the reset email doesn't arrive</h3>
                <p>Check your spam folder first. If it's not there, contact your board admin — they can send you an invitation link or reset your password from the directory.</p>
            HTML,
        ],

        // ── COMMUNITY ───────────────────────────────────────────────────

        [
            'slug'     => 'announcements',
            'title'    => 'Announcements',
            'category' => 'Community',
            'min_role' => 'renter',
            'body'     => <<<HTML
                <p>Announcements are how your board communicates with the community — meeting notices, maintenance alerts, social events, and more.</p>

                <h3>Reading announcements</h3>
                <p>The dashboard home shows the five most recent announcements. Click any one to open the full text. Go to <strong>Announcements</strong> in the sidebar to see everything, filtered by type or time period.</p>

                <h3>Announcement types</h3>
                <ul>
                    <li><strong>General</strong> — routine community news</li>
                    <li><strong>Event</strong> — upcoming social events, board meetings</li>
                    <li><strong>Maintenance</strong> — scheduled work, outages, access restrictions</li>
                    <li><strong>Emergency</strong> — urgent notices that display as a red banner at the top of every page until they expire</li>
                    <li><strong>Beautification</strong> — landscaping, common-area improvements</li>
                    <li><strong>Birth notice / Death notice</strong> — community life events</li>
                </ul>

                <h3>Who sees what</h3>
                <p>Each announcement has an audience setting. <em>All residents</em> means everyone including renters. <em>Owners only</em> hides it from renters. <em>Board only</em> is internal.</p>

                <h3>Expiry</h3>
                <p>Announcements can have an expiry date. Once expired, they move to the archive. Board admins can view archived announcements from the Archived tab.</p>
            HTML,
        ],

        [
            'slug'     => 'events',
            'title'    => 'Events',
            'category' => 'Community',
            'min_role' => 'renter',
            'body'     => <<<HTML
                <p>The Events page lists upcoming community events — board meetings, social gatherings, pool parties, work parties, and anything else the board schedules.</p>

                <h3>Recurring events</h3>
                <p>A weekly board meeting or monthly social is stored once and expanded automatically. You'll see every upcoming occurrence, not just one row.</p>

                <h3>Timezones</h3>
                <p>All events display in your association's local timezone. If an event says 6:00 PM, it means 6:00 PM local time — no mental UTC conversion needed.</p>

                <h3>Public events</h3>
                <p>Events set to <em>All residents (public)</em> also appear on your community's public landing page so prospective residents and guests can see them.</p>

                <h3>Printing</h3>
                <p>Use the Print buttons at the top of the Events page to get a clean printout for Today, This Week, or This Month. Useful for posting on a bulletin board.</p>
            HTML,
        ],

        [
            'slug'     => 'marketplace',
            'title'    => 'Marketplace — buy, sell, and give away',
            'category' => 'Community',
            'min_role' => 'renter',
            'body'     => <<<HTML
                <p>The community marketplace lets residents buy, sell, give away items, or post wanted ads — all within your building.</p>

                <h3>Posting a listing</h3>
                <p>Click <strong>+ New listing</strong> on the Marketplace page. Pick a category (Furniture, Appliances, Free, etc.), set a price or mark it Free, write a description, and optionally add a photo. Your listing goes live immediately.</p>

                <h3>Managing your listings</h3>
                <p>You can edit or mark a listing as sold at any time from the Marketplace page. Sold listings disappear from the active view but stay in the history.</p>

                <h3>Lobby TV</h3>
                <p>Active listings also appear on the Lobby TV display in the Marketplace column, so neighbors browsing the lobby can see what's available.</p>

                <h3>Moderation</h3>
                <p>Board admins can remove any listing. If you see something that shouldn't be there, use the Feedback page to let the board know.</p>
            HTML,
        ],

        // ── GOVERNANCE ──────────────────────────────────────────────────

        [
            'slug'     => 'concerns',
            'title'    => 'Submitting feedback, complaints &amp; compliments',
            'category' => 'Governance',
            'min_role' => 'renter',
            'body'     => <<<HTML
                <p>The Feedback page (listed as <em>Feedback</em> in the sidebar) lets you send concerns, complaints, compliments, or suggestions directly to the board.</p>

                <h3>What to include</h3>
                <p>The more specific you are, the faster the board can act. You can optionally:</p>
                <ul>
                    <li>Tag a member or unit the concern is about</li>
                    <li>Cite the specific rule being violated</li>
                    <li>Attach context in the description</li>
                </ul>

                <h3>How the board will contact you</h3>
                <p>When you submit a concern, the form shows your name, unit, email, and phone so you know exactly what the board will use to follow up. If your phone isn't on file, there's a link to add it.</p>

                <h3>Anonymous submissions</h3>
                <p>You can submit anonymously by checking <em>Submit anonymously.</em> The board won't know who you are — but that also means they can't update you on the outcome or ask clarifying questions. For issues that need a resolution you can track, submitting with your name attached is strongly recommended.</p>

                <h3>Tracking your submission</h3>
                <p>After submitting, your concern appears in your own view of the Feedback page. You can see its status (new, in progress, resolved, closed) and any replies from the board. The board can also add internal-only notes that you won't see.</p>
            HTML,
        ],

        [
            'slug'     => 'arc-requests',
            'title'    => 'Architectural review requests',
            'category' => 'Governance',
            'min_role' => 'owner',
            'body'     => <<<HTML
                <p>Any modification to the exterior of your unit — paint color, satellite dish, deck addition, storm shutters, landscaping changes — typically requires approval from the Architectural Review Committee (ARC) before work begins.</p>

                <h3>Filing a request</h3>
                <p>Go to <strong>Arch. review</strong> in the sidebar and click <strong>+ New request.</strong> Fill in:</p>
                <ul>
                    <li>Category (Paint, Addition, Landscaping, etc.)</li>
                    <li>Planned start and end dates</li>
                    <li>Contractor name and contact, if applicable</li>
                    <li>Estimated cost</li>
                    <li>Description of the work</li>
                </ul>
                <p>Submit it and the board will review. You'll get an email when a decision is made.</p>

                <h3>Decisions</h3>
                <p>The board can approve, deny, or approve with conditions. All decisions include a written explanation. Approved requests can be converted to a Work Order if the board also needs to coordinate related common-area work.</p>

                <h3>Renters</h3>
                <p>Renters cannot file ARC requests. Exterior modifications are the unit owner's responsibility — the owner needs to file.</p>
            HTML,
        ],

        // ── RESOURCES ───────────────────────────────────────────────────

        [
            'slug'     => 'documents',
            'title'    => 'Documents &amp; files',
            'category' => 'Resources',
            'min_role' => 'renter',
            'body'     => <<<HTML
                <p>The Documents page is where the board stores official files — bylaws, meeting minutes, insurance certificates, lease addenda, floor plans, and anything else the community needs on record.</p>

                <h3>Access levels</h3>
                <ul>
                    <li><strong>Public</strong> — visible to anyone on the community landing page, no login required</li>
                    <li><strong>Members</strong> — visible to all signed-in residents (owners and renters)</li>
                    <li><strong>Owners only</strong> — visible to owners; hidden from renters</li>
                    <li><strong>Board only</strong> — visible only to board and management</li>
                    <li><strong>Unit only</strong> — visible to residents of a specific unit plus the board</li>
                </ul>

                <h3>Document archiving</h3>
                <p>Documents can be archived (soft-deleted) instead of permanently removed. Archived documents don't show up in the normal listing but can be restored by a board admin. Useful for superseded bylaws or old insurance certificates you want to keep on record.</p>

                <h3>Composing documents</h3>
                <p>Board admins can write a document directly in the app using the built-in rich-text editor (Compose button) instead of uploading a file. Good for quick notices, policy clarifications, or welcome letters.</p>
            HTML,
        ],

        [
            'slug'     => 'legal-reference',
            'title'    => 'Legal reference — Florida statutes',
            'category' => 'Resources',
            'min_role' => 'renter',
            'body'     => <<<HTML
                <p>The Legal page gives every signed-in member access to Florida HOA and condo statutes — the actual laws that govern your community.</p>

                <h3>What's covered</h3>
                <ul>
                    <li>Chapter 718 — Condominium Act</li>
                    <li>Chapter 719 — Cooperative Act</li>
                    <li>Chapter 720 — Homeowners' Association Act</li>
                    <li>Chapter 553 — Building Construction Standards</li>
                </ul>
                <p>232 Florida statutes are loaded in-app. Use the search bar to find statutes by keyword, or filter by chapter, what it applies to, or category.</p>

                <h3>Reading statutes</h3>
                <p>For statutes with full text loaded, click <strong>Expand</strong> to read the full language. For the rest, the <strong>Official site</strong> button opens the Florida Legislature's website for that section.</p>

                <h3>Important note</h3>
                <p>This tool is a reference resource, not legal advice. Laws change and interpretations vary. For anything consequential — fines, foreclosures, election disputes — consult a licensed Florida HOA attorney.</p>
            HTML,
        ],

        // ── BOARD / MANAGEMENT ONLY ─────────────────────────────────────

        [
            'slug'     => 'lobby-tv',
            'title'    => 'Lobby TV — setup and PIN management',
            'category' => 'Board &amp; management',
            'min_role' => 'board_member',
            'body'     => <<<HTML
                <p>The Lobby TV is a full-screen display designed for your lobby kiosk, monitor, or any TV with a browser. It shows announcements, upcoming events, and marketplace listings in a rotating three-column layout.</p>

                <h3>Accessing the TV display</h3>
                <p>Go to <strong>/tv</strong> in any browser. You'll see a login form with two fields:</p>
                <ul>
                    <li><strong>Community ID</strong> — your association's URL slug (e.g., <code>bellair</code>)</li>
                    <li><strong>PIN</strong> — a 4–10 digit code set by the board admin</li>
                </ul>
                <p>After entering both, you get a bookmarkable URL you can set as the home page on the lobby browser. That URL never expires until you change the PIN.</p>

                <h3>Managing the PIN</h3>
                <p>Go to <strong>Settings → Lobby TV.</strong> The current PIN is displayed. Click <strong>Change PIN</strong> to set a new one (4–10 digits, numbers only). Changing the PIN invalidates any previously bookmarked TV URLs — you'll need to log in again to get the new bookmarkable link.</p>

                <h3>Rate limiting</h3>
                <p>After 10 wrong PIN attempts from the same IP address, that IP is locked out for 15 minutes. This prevents someone from guessing the PIN by brute force.</p>

                <h3>What the TV shows</h3>
                <ul>
                    <li><strong>Announcements</strong> — active, non-expired posts with audience set to All or Members</li>
                    <li><strong>Upcoming events</strong> — next 30 days of events, recurring series expanded</li>
                    <li><strong>Marketplace</strong> — active listings with photos when available</li>
                </ul>
                <p>Each column scrolls independently and loops seamlessly. The page auto-refreshes every 10 minutes to pick up new content.</p>

                <h3>Weather</h3>
                <p>The header shows current conditions (temperature, wind, precipitation) using Open-Meteo — free, no API key required. It pulls your association's latitude/longitude from Settings. If those aren't set, weather won't appear.</p>
            HTML,
        ],

        [
            'slug'     => 'permissions',
            'title'    => 'Configuring who can see what',
            'category' => 'Board &amp; management',
            'min_role' => 'board_member',
            'body'     => <<<HTML
                <p>Board admins can configure exactly which role is required to access each feature. Go to <strong>Settings → Permissions</strong> (board admin only).</p>

                <h3>How it works</h3>
                <p>Each configurable feature has a minimum role. Anyone at or above that role can access the feature. Roles in order from least to most access:</p>
                <ol>
                    <li>Renter</li>
                    <li>Staff</li>
                    <li>Owner</li>
                    <li>Property manager</li>
                    <li>Board member</li>
                    <li>Board admin</li>
                    <li>Super admin</li>
                </ol>

                <h3>What's configurable</h3>
                <ul>
                    <li>Full resident directory</li>
                    <li>Contacts page</li>
                    <li>Meeting minutes</li>
                    <li>Work orders (read-only view)</li>
                    <li>Violations (read-only view)</li>
                    <li>Employee roster</li>
                    <li>Insurance records</li>
                    <li>Submitting concerns</li>
                    <li>Submitting ARC requests</li>
                </ul>

                <h3>What's not configurable</h3>
                <p>Management write actions — creating work orders, issuing violation notices, editing employees — are always restricted to board and management roles. A misconfiguration can't accidentally expose those.</p>

                <h3>Defaults</h3>
                <p>If you haven't changed a permission, it uses the system default. Customized permissions are labeled so you always know what you've changed.</p>
            HTML,
        ],

        [
            'slug'     => 'violations',
            'title'    => 'Violation workflow',
            'category' => 'Board &amp; management',
            'min_role' => 'board_member',
            'body'     => <<<HTML
                <p>The Violations page lets the board formally record rule violations, issue notices, and track them through to resolution.</p>

                <h3>Recording a violation</h3>
                <p>Click <strong>+ New violation.</strong> Fill in the unit, the resident (if known), the rule being violated, and a description of what was observed. Save it — the violation is now on record.</p>

                <h3>Issuing notices</h3>
                <p>From the violation detail page, you can issue four types of notice:</p>
                <ul>
                    <li><strong>Warning</strong> — first contact, no fine yet</li>
                    <li><strong>Cure notice</strong> — requires the violation to be resolved by a specific date</li>
                    <li><strong>Fine notice</strong> — formal notice of a fine amount</li>
                    <li><strong>Hearing notice</strong> — summons the resident to a board hearing</li>
                </ul>
                <p>Each notice generates a pre-populated letter you can edit before saving. Every saved notice appears in a timeline on the violation record.</p>

                <h3>Print-ready letters</h3>
                <p>Click Print on any notice to get a print-ready letter with your association's letterhead, the rule citation, deadlines, fine amounts, and a signature block.</p>

                <h3>Converting from concerns</h3>
                <p>A concern submitted by a resident can be converted to a violation with one click. The violation form pre-fills from the concern's text.</p>

                <h3>Florida law note</h3>
                <p>Florida Chapters 718 and 720 have specific service requirements for violation notices — especially for anything leading to a fine. Certified mail with return receipt is the safe default for anything fineable. Confirm your process with your HOA attorney before automating notices.</p>
            HTML,
        ],

        [
            'slug'     => 'work-orders',
            'title'    => 'Work orders',
            'category' => 'Board &amp; management',
            'min_role' => 'board_member',
            'body'     => <<<HTML
                <p>Work orders are internal tickets for maintenance and repair tasks. Board admins and property managers create and manage them; residents can be given read-only access via the Permissions page.</p>

                <h3>Creating a work order</h3>
                <p>Click <strong>+ New work order</strong> on the Work Orders page. Fill in title, description, priority (low/normal/high/urgent), location, unit (if applicable), and assignee. The assignee can be any active employee or board/management member.</p>

                <h3>Status tracking</h3>
                <p>Work orders move through: Open → In progress → Blocked → Completed → Closed. Each status change is logged in a timeline. You can add free-text notes at any stage.</p>

                <h3>Posting an announcement on status change</h3>
                <p>When updating a work order's status, an optional "Post an announcement" section appears. Tick the checkbox to publish a resident announcement at the same time — so "Pool pump fixed" closes the work order and notifies residents in one action.</p>

                <h3>Converting from concerns or ARC</h3>
                <p>A resident concern or approved ARC request can be converted to a work order with one click. The form pre-fills from the source record.</p>
            HTML,
        ],

    ];
}

/** Return a single topic by slug, or null if not found. */
function help_topic(string $slug): ?array
{
    foreach (help_topics() as $t) {
        if ($t['slug'] === $slug) return $t;
    }
    return null;
}
