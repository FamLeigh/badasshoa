<?php
/**
 * Seed help_topics with content for every BadassHOA feature.
 *
 * Run once after migration 097:
 *   php migrations/seed_help_topics.php
 *
 * Safe to re-run: uses INSERT IGNORE so existing slugs are skipped.
 * To refresh a topic body, delete the row first or update it in /admin/help.php.
 */
declare(strict_types=1);

$cfg = require __DIR__ . '/../config.php';

$dsn = $cfg['db']['dsn']  ?? 'mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=badassHOA;charset=utf8mb4';
$usr = $cfg['db']['user'] ?? 'root';
$pwd = $cfg['db']['pass'] ?? 'root';

try {
    $pdo = new PDO($dsn, $usr, $pwd, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    die("Could not connect: " . $e->getMessage() . "\n");
}

$stmt = $pdo->prepare(
    'INSERT IGNORE INTO help_topics (slug, title, category, min_role, body, sort_order, active)
     VALUES (:slug, :title, :category, :min_role, :body, :sort_order, 1)'
);

function insert(PDO $pdo, PDOStatement $stmt, array $t): void
{
    $stmt->execute([
        ':slug'       => $t['slug'],
        ':title'      => $t['title'],
        ':category'   => $t['category'],
        ':min_role'   => $t['min_role'],
        ':body'       => $t['body'],
        ':sort_order' => $t['sort_order'],
    ]);
    echo "  " . ($pdo->errorCode() === '00000' ? 'OK' : 'SKIP') . "  {$t['slug']}\n";
}

$topics = [

    // ── YOUR ACCOUNT ────────────────────────────────────────────────────

    [
        'slug'       => 'getting-started',
        'title'      => 'Getting started',
        'category'   => 'Your account',
        'min_role'   => 'renter',
        'sort_order' => 10,
        'body'       => <<<HTML
<p>Welcome to BadassHOA — a community portal built for boards and residents who want transparency without the runaround. Here is what you can do from the moment you sign in.</p>

<h3>Your dashboard home</h3>
<p>The home page shows a live count of everything in your community — members, units, announcements, events, documents, and more. Each tile is a link that takes you straight to that section.</p>

<h3>The sidebar</h3>
<p>Everything is organized into collapsible groups on the left sidebar. What you see depends on your role:</p>
<ul>
    <li><strong>Community</strong> — announcements, email broadcasts, events, FAQ, marketplace, property listings, area attractions</li>
    <li><strong>Resources</strong> — documents, forms, rules &amp; bylaws, legal reference, meeting minutes, media gallery, member directory, contacts</li>
    <li><strong>Governance</strong> — committees, feedback &amp; concerns, architectural review, violations, work orders, board voting, board meetings</li>
    <li><strong>Operations</strong> — units, parking, employees, insurance (board and management only)</li>
    <li><strong>Configuration</strong> — activity log, settings (board and management only)</li>
</ul>
<p>Items you do not have permission to access are hidden automatically — your sidebar only shows what you can use. Click any group header to collapse or expand it. The sidebar collapses to icon-only mode using the toggle button on the left edge of the screen.</p>

<h3>Your profile</h3>
<p>Click your name or initials in the top-right corner to reach your profile page. From there you can upload a headshot, write a short bio, set your preferred timezone, and manage your saved e-signature images.</p>

<h3>Getting help</h3>
<p>This Help section covers every feature in the portal. Use the search box in the left sidebar to find a topic, or browse by category. If something is missing or wrong, let your board know via the Feedback page.</p>
HTML,
    ],

    [
        'slug'       => 'directory-privacy',
        'title'      => 'Directory privacy — hiding yourself from other residents',
        'category'   => 'Your account',
        'min_role'   => 'renter',
        'sort_order' => 20,
        'body'       => <<<HTML
<p>Owners and renters can hide themselves from the resident directory without involving the board.</p>

<h3>How to opt out</h3>
<p>Go to <strong>Settings → Your account</strong> and check <em>"Hide me from the resident directory."</em> Save. That is it. Other residents will no longer see your name, unit, email, or phone in the directory listing.</p>

<h3>What still works when you are hidden</h3>
<p>You can still log in, submit forms, post to the marketplace, attend events, and use everything else normally. Hiding yourself only removes you from the directory view that other residents see.</p>

<h3>Who can still see you</h3>
<p>The board and management can always see every resident regardless of the privacy setting. They need your contact information to manage the community — dues, work orders, violation notices, and anything that requires reaching you directly.</p>

<h3>Board members cannot opt out</h3>
<p>Board members and admins do not have the opt-out toggle. Their presence in the directory is part of the role — residents have a right to know who is managing their community.</p>

<h3>The board can also deactivate accounts</h3>
<p>A board admin can mark an account as inactive from the directory edit screen, which hides the member more completely. Your privacy toggle is the self-serve version that does not require any board action.</p>
HTML,
    ],

    [
        'slug'       => 'change-password',
        'title'      => 'Changing your password',
        'category'   => 'Your account',
        'min_role'   => 'renter',
        'sort_order' => 30,
        'body'       => <<<HTML
<h3>While signed in</h3>
<p>Go to <strong>Settings → Your account</strong> and use the Change password card. You will need your current password. The new password must be at least 8 characters. After saving, any outstanding password-reset tokens for your account are invalidated automatically.</p>

<h3>If you are locked out</h3>
<p>Use <a href="/forgot.php">Forgot password</a> on the login page. Enter your email address and a reset link will be sent to you. The link expires after 1 hour and can only be used once. Requesting a new link cancels any previously sent link.</p>

<h3>If the reset email does not arrive</h3>
<p>Check your spam or junk folder first. If it is not there, contact your board admin — they can send you a password reset link directly from the directory, or resend your original invitation email.</p>

<h3>Rate limiting</h3>
<p>For security, no more than 5 password-reset requests per IP address are allowed per hour. If you hit that limit, wait 60 minutes and try again or contact your board admin.</p>
HTML,
    ],

    [
        'slug'       => 'your-profile',
        'title'      => 'Your profile — headshot, bio, and saved signatures',
        'category'   => 'Your account',
        'min_role'   => 'renter',
        'sort_order' => 40,
        'body'       => <<<HTML
<p>Your profile stores your headshot, bio, timezone preference, and saved e-signature images. Access it by clicking your name in the top-right corner of any page.</p>

<h3>Headshot</h3>
<p>Upload a square photo — JPG, PNG, WEBP, or GIF, max 8 MB. Your headshot appears in the member directory (if you are not hidden), on the public community landing page in the Meet Your Board section (board members who opt in), and in board meeting minutes.</p>

<h3>Bio</h3>
<p>A short text bio displayed in the directory and on the public landing page. Keep it brief — a sentence or two is enough. Board members often use this for their title or committee role.</p>

<h3>Timezone</h3>
<p>Set your preferred timezone and all timestamps in the portal — events, meeting times, announcements — will display in your local time instead of UTC.</p>

<h3>Saved e-signatures</h3>
<p>You can draw and save one or more signature images on your profile. When you sign a document, your saved signature is used automatically — no re-drawing required. You can save multiple signatures and choose which one to use at signing time. To add a signature, click <strong>Add signature</strong> on the profile page and use the drawing pad.</p>

<h3>Show on public landing page</h3>
<p>Board members have an additional toggle: <em>Show me in the Meet Your Board section on the public landing page.</em> When enabled, your name, headshot, and bio appear publicly without requiring a login. This is opt-in only.</p>
HTML,
    ],

    // ── COMMUNITY ────────────────────────────────────────────────────────

    [
        'slug'       => 'announcements',
        'title'      => 'Announcements',
        'category'   => 'Community',
        'min_role'   => 'renter',
        'sort_order' => 110,
        'body'       => <<<HTML
<p>Announcements are how your board communicates with the community — meeting notices, maintenance alerts, social events, emergency advisories, and more.</p>

<h3>Reading announcements</h3>
<p>The dashboard home shows the five most recent announcements. Click any one to read the full text. Go to <strong>Announcements</strong> in the sidebar to see the complete list. You can filter by type and date range from the top of the page.</p>

<h3>Announcement types</h3>
<ul>
    <li><strong>General</strong> — routine community news and updates</li>
    <li><strong>Event</strong> — upcoming social events and gatherings</li>
    <li><strong>Maintenance</strong> — scheduled work, outages, access restrictions</li>
    <li><strong>Emergency</strong> — urgent notices that display as a red banner at the top of every dashboard page until they expire</li>
    <li><strong>Beautification</strong> — landscaping and common-area improvements</li>
    <li><strong>Birth notice / Death notice</strong> — community life events</li>
</ul>

<h3>Audience</h3>
<p>Each announcement has an audience setting. <em>All residents</em> means everyone including renters. <em>Owners only</em> hides it from renters. <em>Board only</em> is internal and only visible to board and management.</p>

<h3>Expiry</h3>
<p>Announcements can have an optional expiry date. Once expired, they move to the archive and stop appearing in the main list. Board admins can view the archive from the Archived tab on the Announcements page.</p>

<h3>Lobby TV</h3>
<p>Non-expired announcements set to All or Members audience also appear on the Lobby TV display in the lobby kiosk, if your community uses it.</p>
HTML,
    ],

    [
        'slug'       => 'events',
        'title'      => 'Events',
        'category'   => 'Community',
        'min_role'   => 'renter',
        'sort_order' => 120,
        'body'       => <<<HTML
<p>The Events page lists community events — board meetings, social gatherings, pool parties, work parties, and anything else the board schedules.</p>

<h3>Recurring events</h3>
<p>A weekly board meeting or monthly social is stored once and expanded automatically. You will see every upcoming occurrence as a separate entry — not just one row with a recurrence note.</p>

<h3>Print calendars</h3>
<p>Use the Print buttons at the top of the Events page to get a clean, printable calendar for Today, This Week, or This Month. Useful for posting on a community bulletin board.</p>

<h3>Public events</h3>
<p>Events set to <em>All residents (public)</em> also appear on your community public landing page so guests and prospective residents can see what is going on. Events set to Members or Board only do not appear publicly.</p>

<h3>Timezones</h3>
<p>All event times display in your preferred timezone if you have set one in your profile, or in the association local timezone if you have not. Either way, no UTC conversion is needed on your part.</p>

<h3>Lobby TV</h3>
<p>Upcoming events also appear in the Events column on the Lobby TV display. Recurring series are fully expanded so every upcoming date shows up individually.</p>
HTML,
    ],

    [
        'slug'       => 'marketplace',
        'title'      => 'Marketplace — buy, sell, and give away',
        'category'   => 'Community',
        'min_role'   => 'renter',
        'sort_order' => 130,
        'body'       => <<<HTML
<p>The community marketplace lets residents buy, sell, give away items, or post wanted ads — all within your community portal.</p>

<h3>Posting a listing</h3>
<p>Click <strong>+ New listing</strong> on the Marketplace page. Pick a category (Furniture, Appliances, Free, Wanted, etc.), enter a price or mark it Free, write a description, and optionally add a photo. Your listing goes live immediately.</p>

<h3>Managing your listings</h3>
<p>You can edit your listing or mark it as Sold at any time from the Marketplace page. Sold listings disappear from the active view but stay in the history. You can also delete a listing entirely if the item is no longer available.</p>

<h3>Contacting a seller</h3>
<p>Each listing shows the poster name and contact information so interested neighbors can reach out directly. There is no in-app messaging — contact is handled outside the portal.</p>

<h3>Lobby TV</h3>
<p>Active listings also appear on the Lobby TV display in the Marketplace column, so neighbors browsing the lobby can see what is available. Photos are shown when present.</p>

<h3>Moderation</h3>
<p>Board admins can remove any listing. If you see something that should not be there, use the Feedback page to let the board know.</p>
HTML,
    ],

    [
        'slug'       => 'faq',
        'title'      => 'FAQ — community frequently asked questions',
        'category'   => 'Community',
        'min_role'   => 'renter',
        'sort_order' => 140,
        'body'       => <<<HTML
<p>The FAQ page is a curated list of common questions and answers about your community — pool hours, guest policies, parking rules, move-in procedures, and anything else residents ask repeatedly.</p>

<h3>Finding an answer</h3>
<p>Use the search bar at the top of the FAQ page to search questions and answers by keyword. You can also browse all questions in order. Click any question to expand the answer.</p>

<h3>Categories</h3>
<p>FAQs are grouped into categories set by the board — for example Amenities, Parking, Move-in, Pets. Browse by category or search across all of them at once.</p>

<h3>Not finding an answer?</h3>
<p>If your question is not answered in the FAQ, use the <strong>Feedback</strong> page to send it to the board. If the board answers the same question from multiple residents, they will typically add it to the FAQ so everyone benefits.</p>
HTML,
    ],

    [
        'slug'       => 'forms',
        'title'      => 'Forms — guest registration, parking passes, and more',
        'category'   => 'Community',
        'min_role'   => 'renter',
        'sort_order' => 150,
        'body'       => <<<HTML
<p>The Forms page gives residents a library of community forms to complete online — no printing or scanning required. Submitted forms are stored on your account and visible to board management.</p>

<h3>Available form types</h3>
<ul>
    <li><strong>Guest registration</strong> — register a guest or visitor in advance</li>
    <li><strong>Temporary parking pass</strong> — request a short-term visitor parking pass</li>
    <li><strong>Move-in / move-out notice</strong> — notify the building of a move date and elevator reservation</li>
    <li><strong>Pet registration</strong> — register a pet per community rules</li>
    <li><strong>Service animal notification</strong> — protected disclosure under Fair Housing rules</li>
    <li><strong>Key / fob request</strong> — request a replacement or additional access credential</li>
    <li><strong>Maintenance request</strong> — report something needing repair in your unit or common area</li>
    <li><strong>Lease renewal notice</strong> — notify the board of a lease renewal</li>
    <li><strong>Sublease notice</strong> — notify the board of an upcoming sublease</li>
    <li><strong>Background check consent</strong> — consent to a background screening (if required by community rules)</li>
    <li><strong>Variance / hardship request</strong> — request an exception to a community rule</li>
    <li><strong>Committee interest</strong> — express interest in joining a committee</li>
    <li><strong>Newsletter subscription</strong> — subscribe or unsubscribe to the community newsletter</li>
    <li><strong>Other</strong> — general-purpose form for anything not covered above</li>
</ul>

<h3>Submitting a form</h3>
<p>Click any form type, fill in the fields, and click Submit. You will see a confirmation message and the submission appears in your form history at the bottom of the Forms page. The board is notified of new submissions.</p>

<h3>Viewing your past submissions</h3>
<p>Your previous form submissions are listed on the Forms page under the form library. Click any entry to see what you submitted and any board notes added.</p>
HTML,
    ],

    [
        'slug'       => 'property-listings',
        'title'      => 'Property listings — for sale and for rent',
        'category'   => 'Community',
        'min_role'   => 'owner',
        'sort_order' => 160,
        'body'       => <<<HTML
<p>The Listings page lets owners advertise units that are for sale or available for rent — directly within your community portal.</p>

<h3>Posting a listing</h3>
<p>Go to <strong>Listings</strong> in the sidebar and click <strong>+ New listing.</strong> Fill in:</p>
<ul>
    <li>Type — For Sale or For Rent</li>
    <li>Asking price or monthly rent</li>
    <li>Bedrooms, bathrooms, and square footage</li>
    <li>A description of the unit</li>
    <li>Contact name, email, and phone for inquiries</li>
    <li>An optional photo</li>
</ul>

<h3>Managing your listing</h3>
<p>You can update the listing status at any time — Active, Pending, Sold, or Rented — directly from the status dropdown in the listings table without opening the full edit form. Use Edit to change any other detail or delete to remove the listing entirely.</p>

<h3>Public visibility</h3>
<p>Active listings automatically appear in the <em>Properties Available</em> section of your community public landing page. This is intentional — prospective buyers and renters often browse community pages before contacting a listing agent, and visibility there can accelerate the process.</p>

<h3>Who can post</h3>
<p>Owners and above can post listings by default. The board can adjust this minimum role in <strong>Settings → Permissions → Post property listings.</strong></p>
HTML,
    ],

    [
        'slug'       => 'area-attractions',
        'title'      => 'Area attractions',
        'category'   => 'Community',
        'min_role'   => 'board_member',
        'sort_order' => 170,
        'body'       => <<<HTML
<p>The Area Attractions page lets the board curate a directory of nearby places worth visiting — restaurants, shops, parks, gyms, and more. It appears on your public community landing page so guests and prospective residents can explore the neighborhood.</p>

<h3>Adding an attraction</h3>
<p>Go to <strong>Area Attractions</strong> in the sidebar and click <strong>+ Add attraction.</strong> Fill in the name, category, a short description, address, optional website URL, and optionally a photo. Click Save and it goes live on the public landing immediately.</p>

<h3>Categories</h3>
<p>Dining, Shopping, Entertainment, Outdoor, Culture, Services, Other. The public page shows a tab for each category that has at least one active entry.</p>

<h3>Distance display</h3>
<p>If your association address is geocoded (set under <strong>Settings → Association profile</strong>), a distance badge appears automatically on each attraction card — for example "0.3 mi away." This uses the attraction address and your association coordinates.</p>

<h3>Sort order and visibility</h3>
<p>Each attraction has a sort order field. Lower numbers appear first. Use the Active toggle to temporarily hide an entry without deleting it.</p>

<h3>Public dedicated page</h3>
<p>The public landing page shows a preview of up to four attractions with a "See all" link. That link opens a dedicated <em>/{slug}/attractions</em> page with the full directory, category tabs, and distance info.</p>
HTML,
    ],

    // ── GOVERNANCE ────────────────────────────────────────────────────────

    [
        'slug'       => 'concerns',
        'title'      => 'Feedback, complaints, and compliments',
        'category'   => 'Governance',
        'min_role'   => 'renter',
        'sort_order' => 210,
        'body'       => <<<HTML
<p>The Feedback page lets you send concerns, complaints, compliments, or suggestions directly to the board. This is your primary channel for anything that needs board attention.</p>

<h3>What to include</h3>
<p>The more specific you are, the faster the board can act. The form lets you optionally:</p>
<ul>
    <li>Tag a specific member or unit the concern is about</li>
    <li>Cite the community rule being violated</li>
    <li>Include a detailed description with dates and context</li>
</ul>

<h3>Your contact information</h3>
<p>When you open the form, it shows your name, unit, email, and phone so you know exactly what the board will use to follow up. If your phone is not on file, there is a link to add it in your profile.</p>

<h3>Anonymous submissions</h3>
<p>You can check <em>Submit anonymously</em> to hide your identity from the board. Anonymous submissions are accepted, but the board cannot update you on the outcome or ask clarifying questions. For issues that need a resolution you can track, submitting with your name attached is strongly recommended.</p>

<h3>Tracking your submission</h3>
<p>After submitting, your concern appears in your Feedback page view with its current status — New, In Progress, Resolved, or Closed. You can see any board responses. The board may also add internal notes that are not shown to you.</p>

<h3>Converting to a violation</h3>
<p>Board admins can convert a concern to a formal violation record with one click. The violation form pre-fills from the concern text. You will not see this action but the board uses it to escalate rule violations through the formal notice workflow.</p>

<h3>Access control</h3>
<p>The board can configure who is allowed to submit concerns in <strong>Settings → Permissions → Submit concerns.</strong> By default all residents can submit, including renters.</p>
HTML,
    ],

    [
        'slug'       => 'arc-requests',
        'title'      => 'Architectural review requests (ARC)',
        'category'   => 'Governance',
        'min_role'   => 'owner',
        'sort_order' => 220,
        'body'       => <<<HTML
<p>Any modification to the exterior of your unit — paint color, satellite dish, deck addition, storm shutters, landscaping changes — typically requires approval from the Architectural Review Committee (ARC) before work begins. Check your community rules or bylaws for the specific list.</p>

<h3>Filing a request</h3>
<p>Go to <strong>Arch. review</strong> in the sidebar and click <strong>+ New request.</strong> Fill in:</p>
<ul>
    <li>Category — Paint, Addition, Landscaping, Roofing, Window, Door, Fence, Other</li>
    <li>Planned start and end dates for the work</li>
    <li>Contractor name and contact information, if applicable</li>
    <li>Estimated cost of the project</li>
    <li>A full description of the proposed changes</li>
</ul>
<p>Submit the request and the board will review. You will receive an email notification when a decision is made.</p>

<h3>Decisions and conditions</h3>
<p>The board can approve, deny, or approve with conditions. All decisions include a written explanation. If approved with conditions, the conditions are listed in the decision record and you must comply with them to maintain approval.</p>

<h3>Converting to a work order</h3>
<p>If the approved modification also requires coordination of common-area work, the board can convert the ARC request to a Work Order to track that work separately.</p>

<h3>Renters cannot file ARC requests</h3>
<p>ARC requests are the unit owner responsibility. If you are a renter and need exterior modifications, the owner of your unit needs to file the request. Contact your owner or property manager.</p>

<h3>Access control</h3>
<p>The board can adjust who can file ARC requests in <strong>Settings → Permissions → Submit ARC requests.</strong></p>
HTML,
    ],

    // ── RESOURCES ────────────────────────────────────────────────────────

    [
        'slug'       => 'documents',
        'title'      => 'Documents and files',
        'category'   => 'Resources',
        'min_role'   => 'renter',
        'sort_order' => 310,
        'body'       => <<<HTML
<p>The Documents page is where the board stores official files — bylaws, meeting minutes, insurance certificates, lease addenda, floor plans, and anything else the community needs on record.</p>

<h3>Browsing and downloading</h3>
<p>Documents are organized by category and access level. You can filter by category using the dropdown at the top of the list. Click the document title to view or download it. Your role determines which access levels are visible to you.</p>

<h3>Access levels</h3>
<ul>
    <li><strong>Public</strong> — visible to anyone on the community landing page, no login required</li>
    <li><strong>Members</strong> — visible to all signed-in residents, both owners and renters</li>
    <li><strong>Owners only</strong> — visible to owners and above; hidden from renters</li>
    <li><strong>Board only</strong> — visible only to board members and management</li>
    <li><strong>Unit only</strong> — visible to residents of a specific unit plus the board</li>
</ul>

<h3>Composed documents</h3>
<p>Board admins can write a document directly in the portal using the built-in rich-text editor instead of uploading a file. Good for quick notices, policy clarifications, welcome letters, and announcements that do not need a PDF.</p>

<h3>Document archiving</h3>
<p>Documents can be archived (soft-deleted) instead of permanently removed. Archived documents disappear from the normal listing but can be restored by a board admin. Useful for superseded bylaws or old insurance certificates you want to keep on record.</p>

<h3>E-signatures</h3>
<p>PDF documents can be sent for e-signature. Board admins tag which members must sign, send a notification, and the signed copy is stored against the signer record. See the Document signing help topic for full details.</p>

<h3>Versions</h3>
<p>Each document has a version number. If a file is replaced, the version increments automatically and the history is preserved.</p>
HTML,
    ],

    [
        'slug'       => 'rules-bylaws',
        'title'      => 'Rules, bylaws, and community policies',
        'category'   => 'Resources',
        'min_role'   => 'renter',
        'sort_order' => 320,
        'body'       => <<<HTML
<p>The Rules page gives every resident full-text access to the community rules and bylaws, with live search across all content.</p>

<h3>Searching rules</h3>
<p>Use the search bar at the top of the Rules page to search by keyword across all rule text, titles, and categories. Results highlight the matching terms. This is the fastest way to find the specific rule governing parking, pets, noise, or any other topic.</p>

<h3>Categories and filtering</h3>
<p>Rules are organized into categories — Parking, Noise, Pets, Common Areas, Guests, Maintenance, Modifications, etc. Use the category filter dropdown to narrow the list to a specific section of your community rules.</p>

<h3>Inline images</h3>
<p>Rules can include inline photos and diagrams — useful for illustrating parking layouts, pool signage, or approved exterior color palettes. These appear inline in the rule text when present.</p>

<h3>PDF version</h3>
<p>Your complete rules document may also be posted in the Documents section as a PDF. The Rules page and the PDF may differ if the board has made updates that have not yet been reflected in both places — always check with your board if there is a discrepancy.</p>
HTML,
    ],

    [
        'slug'       => 'legal-reference',
        'title'      => 'Legal reference — Florida statutes',
        'category'   => 'Resources',
        'min_role'   => 'renter',
        'sort_order' => 330,
        'body'       => <<<HTML
<p>The Legal page gives every signed-in member access to Florida HOA and condo statutes — the actual laws that govern your community.</p>

<h3>What is covered</h3>
<ul>
    <li><strong>Chapter 718</strong> — Florida Condominium Act (condominiums)</li>
    <li><strong>Chapter 719</strong> — Florida Cooperative Act (cooperatives)</li>
    <li><strong>Chapter 720</strong> — Florida Homeowners Association Act (HOAs)</li>
    <li><strong>Chapter 553</strong> — Building Construction Standards</li>
</ul>
<p>232 Florida statutes are loaded in-app with full text for many sections. Use the search bar to find statutes by keyword, or filter by chapter, applies-to category, or topic category.</p>

<h3>Reading statutes</h3>
<p>Sections with full text loaded show an <strong>Expand</strong> button to read the complete language in-app. For the remaining sections, an <strong>Official site</strong> button links directly to the Florida Legislature website for that section number.</p>

<h3>Important note</h3>
<p>This tool is a reference resource, not legal advice. Laws change and interpretations vary by association type, governing documents, and specific facts. For anything consequential — fines, foreclosures, election disputes, variance requests — consult a licensed Florida HOA attorney before taking action.</p>
HTML,
    ],

    [
        'slug'       => 'meeting-minutes',
        'title'      => 'Meeting minutes',
        'category'   => 'Resources',
        'min_role'   => 'renter',
        'sort_order' => 340,
        'body'       => <<<HTML
<p>The Minutes page gives residents read-only access to past board meeting minutes — the official record of what was discussed, voted on, and decided at each meeting.</p>

<h3>What minutes contain</h3>
<p>Each meeting record includes the date, type (Regular, Special, Annual, Emergency), location, attendees, agenda items with their outcomes, resolution votes with per-member Yes/No/Abstain tallies, and the final vote counts.</p>

<h3>Access</h3>
<p>The board controls who can read minutes in <strong>Settings → Permissions → Read meeting minutes.</strong> By default, all signed-in members can read minutes. If your board has restricted access, you may not see this page or may see only the list without full detail.</p>

<h3>Printing</h3>
<p>The board can produce print-ready minutes from the board meeting management page. If you need a printed copy for any reason, ask your board admin to provide one.</p>

<h3>Florida law note</h3>
<p>Florida Chapters 718 and 720 require boards to keep minutes of all meetings and make them available to unit owners. The specific access rules (time window, copy fees) differ by association type. Your board is responsible for compliance with the applicable chapter.</p>
HTML,
    ],

    [
        'slug'       => 'media-gallery',
        'title'      => 'Media gallery — community photos',
        'category'   => 'Resources',
        'min_role'   => 'renter',
        'sort_order' => 350,
        'body'       => <<<HTML
<p>The Media page is the community photo gallery — board members upload photos of common areas, events, renovations, and anything else worth sharing.</p>

<h3>Browsing photos</h3>
<p>Photos are organized by category and displayed in a grid. Click any photo to view it full-size. Use the category filter to narrow to a specific type — Common Areas, Events, Renovation, etc.</p>

<h3>Visibility</h3>
<p>Photos have a visibility setting:</p>
<ul>
    <li><strong>Public</strong> — appears on the community public landing page, no login required</li>
    <li><strong>Private</strong> — signed-in members only; does not appear on the public page</li>
</ul>

<h3>Captions</h3>
<p>Each photo can have a caption. Captions appear in the gallery grid and on the full-size view.</p>

<h3>Who can upload</h3>
<p>Board members and management can upload photos. Residents can view them.</p>
HTML,
    ],

    [
        'slug'       => 'directory',
        'title'      => 'Member directory',
        'category'   => 'Resources',
        'min_role'   => 'renter',
        'sort_order' => 360,
        'body'       => <<<HTML
<p>The Directory lists your community members — name, unit, role, contact information, and headshot when available. Access is role-based and privacy-respecting.</p>

<h3>What you can see</h3>
<p>By default, all signed-in residents can see the basic directory. The board controls whether full contact details (email, phone) are visible to residents vs. board-only. Check with your board if you cannot see contact information you expect.</p>

<h3>Privacy opt-out</h3>
<p>Members who have opted out of the directory in their Settings are not shown in your view. They still exist in the system and can use all features normally.</p>

<h3>Filtering and search</h3>
<p>Use the search bar at the top of the Directory page to search by name, unit number, or email. Filter by role (owners, renters, board, staff) using the role dropdown.</p>

<h3>Unit view</h3>
<p>Click a unit number in the directory to see all residents associated with that unit — useful for understanding who lives where in a building with shared units.</p>

<h3>Board contacts</h3>
<p>Board members who have opted in to show on the public landing page will also appear in the Meet Your Board section of the community public site, visible to anyone.</p>

<h3>Contacts page</h3>
<p>The Contacts page (separate from the Directory) lists management contacts — property managers, building superintendent, emergency contacts, vendor contacts. This page is configured by the board and is read-only for residents.</p>
HTML,
    ],

    [
        'slug'       => 'contacts',
        'title'      => 'Contacts — management and vendor directory',
        'category'   => 'Resources',
        'min_role'   => 'renter',
        'sort_order' => 370,
        'body'       => <<<HTML
<p>The Contacts page lists key management and vendor contacts for the community — property management company, building superintendent, maintenance contractor, emergency services, and anything else the board wants residents to have quick access to.</p>

<h3>Who maintains this list</h3>
<p>Board admins add and update contacts. If a number is wrong or a contact is missing, let your board admin know via the Feedback page.</p>

<h3>Access control</h3>
<p>The board can control who can see the Contacts page in <strong>Settings → Permissions → Read contacts.</strong> By default it is visible to all signed-in members.</p>

<h3>Emergency contacts</h3>
<p>If your board has added an emergency contact (police non-emergency, fire department, building super after hours, etc.), they appear at the top of the Contacts list. Save these to your phone as a backup.</p>
HTML,
    ],

    // ── BOARD & MANAGEMENT ────────────────────────────────────────────────

    [
        'slug'       => 'board-meetings',
        'title'      => 'Board meetings — agenda, minutes, and resolutions',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 410,
        'body'       => <<<HTML
<p>The Board Meetings page manages the full lifecycle of a meeting — building the agenda, recording attendance, capturing resolution votes with per-member tallies, and producing print-ready minutes in the format required by Florida statute.</p>

<h3>Creating a meeting</h3>
<p>Click <strong>+ New meeting</strong> and fill in the date, time, location, and meeting type (Regular, Special, Annual, Emergency). The system automatically adds an "Approve minutes from the last meeting" item to every new agenda. Add additional agenda items on the meeting detail page.</p>

<h3>Agenda items</h3>
<p>Each item has a type (Discussion, Resolution, Vote, Report, Old Business, New Business, etc.), a description, and optional fields for the motion text and BE IT RESOLVED clause. Items are displayed in sort order — adjust the sort number to reorder them. During or after the meeting, set each item to Approved, Denied, Tabled, or No Action.</p>

<h3>Resolutions and per-member votes</h3>
<p>Resolution-type items show a vote grid with one row per board member. Votes can be Yes, No, Abstain, Not Present, or N/A. Only board members and managers appear in the vote grid — residents do not. Vote counts tally automatically and appear in the minutes. Add the full resolution language in the BE IT RESOLVED field.</p>

<h3>Print-ready minutes</h3>
<p>Click <strong>Print minutes</strong> on any meeting to produce a clean, formatted document with the meeting header, all agenda items in order, resolution votes with counts, and a signature block. Formatted to Florida §718.112 board meeting standards and ready to sign.</p>

<h3>Minutes archive</h3>
<p>All meetings stay on record. The <strong>Minutes</strong> page in the Resources section gives members read-only access to past meetings according to the permission you set in Settings → Permissions → Read meeting minutes.</p>
HTML,
    ],

    [
        'slug'       => 'voting',
        'title'      => 'Board voting — ballots and results',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 420,
        'body'       => <<<HTML
<p>The Voting page handles formal ballot-style votes separate from board meeting resolutions — budget approvals, bylaw amendments, board elections, or any issue that needs a recorded community vote.</p>

<h3>Creating a ballot</h3>
<p>Click <strong>+ New ballot</strong> and fill in the title, description, deadline, and eligible voter group (all members, owners only, or board only). Add the question and the choices voters will select from.</p>

<h3>Casting a vote</h3>
<p>Members who are eligible see open ballots on the Voting page and click to cast their choice before the deadline. Each eligible member can vote once. Once cast, a vote cannot be changed.</p>

<h3>Anonymous by default</h3>
<p>Votes are anonymous by default — only participation counts are tracked during the open window, not who voted what. Individual choices are not stored against user accounts.</p>

<h3>Results reveal</h3>
<p>Board admins control when results are revealed. Before the reveal, only participation count is visible ("X of Y eligible members have voted"). After the board reveals results, vote totals and percentages appear for everyone eligible.</p>

<h3>Closing a ballot</h3>
<p>After the deadline passes, no new votes are accepted automatically. The board can also close a ballot manually before the deadline. Closed ballots stay on record with final totals.</p>
HTML,
    ],

    [
        'slug'       => 'broadcasts',
        'title'      => 'Email broadcasts',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 430,
        'body'       => <<<HTML
<p>Email Broadcasts let the board send a formatted email directly to members inboxes — meeting notices, maintenance alerts, newsletters, emergency notifications, or any communication that needs to reach everyone at once.</p>

<h3>Composing a broadcast</h3>
<p>Go to <strong>Email Broadcasts</strong> in the sidebar and click <strong>+ New broadcast.</strong> Write a subject line and body, choose your audience (all members or a custom selection), optionally attach a PDF, and click Send.</p>

<h3>Audience selection</h3>
<p>By default the broadcast goes to all active members with a real email address on file. Use the <strong>Custom member selection</strong> option to open a member picker and select specific individuals — useful for committee-only notices, floor-specific alerts, or targeted unit owner communications.</p>

<h3>PDF attachments</h3>
<p>Attach one PDF per broadcast — meeting agendas, budget summaries, formal notices, or any document you want to accompany the email. The PDF is included as an email attachment for every recipient.</p>

<h3>Delivery tracking</h3>
<p>After sending, the broadcast detail page shows per-recipient delivery status — Sent, Delivered, or Failed. Members with placeholder email addresses (addresses that are not real inboxes) are automatically excluded from the send count and recipient list.</p>

<h3>Who can send</h3>
<p>Board members and above can send broadcasts by default. The board admin can adjust this in <strong>Settings → Permissions → Send email broadcasts.</strong></p>

<h3>Activity log</h3>
<p>Every broadcast is recorded in the Activity log with sender, subject, recipient count, and timestamp. This provides an audit trail for all community communications.</p>
HTML,
    ],

    [
        'slug'       => 'document-signing',
        'title'      => 'Document signing and e-signatures',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 440,
        'body'       => <<<HTML
<p>BadassHOA includes a built-in e-signature workflow for PDF documents — lease addenda, estoppel certificates, policy acknowledgments, and similar documents that require a resident signature on record.</p>

<h3>Requiring signatures on a document</h3>
<p>On the Documents page, open a PDF and use the <strong>Required signers</strong> tag field to select which members must sign. Start typing a name and select from the autocomplete list. You can add multiple required signers. Click <strong>Notify</strong> to send each pending signer an email with a direct link to sign.</p>

<h3>Signing a document</h3>
<p>When you receive the signing notification (or open the document yourself), you see a preview of the PDF. Drag and resize the signature placement box to position where your signature will appear on the document. Click <strong>Sign and save.</strong> Your saved signature image is used automatically — you do not need to redraw it each time. Manage your saved signatures from your Profile page.</p>

<h3>Signed copies</h3>
<p>After signing, a signed copy of the PDF is generated and stored against your signer record. You can download your signed copy from the document page. Board admins can see all signed copies for all signers.</p>

<h3>Audit certificate</h3>
<p>Every signed document has a dedicated audit certificate page showing: who signed, the timestamp of each signature, the IP address at signing, the document hash before and after signing, and the legal disclosure text each signer accepted. This meets the core requirements of the federal E-SIGN Act and Florida UETA.</p>

<h3>Signature badge</h3>
<p>Documents with required signers show a signature status badge in the Documents list — how many have signed out of how many are required, and whether you specifically still need to sign.</p>

<h3>Legal note</h3>
<p>E-signatures are legally binding for most HOA documents under E-SIGN/UETA. However, some specific notice types — foreclosure proceedings, fine hearing notices — may still require physical paper service under Florida statute. Confirm with your HOA attorney for anything consequential before relying on e-signatures exclusively.</p>
HTML,
    ],

    [
        'slug'       => 'lobby-tv',
        'title'      => 'Lobby TV — setup, PIN, layouts, and themes',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 450,
        'body'       => <<<HTML
<p>The Lobby TV is a full-screen display designed for lobby kiosks, monitors, or any TV with a browser. It shows announcements, upcoming events, and marketplace listings in real time without requiring a signed-in user account.</p>

<h3>Accessing the TV display</h3>
<p>Navigate to <strong>/tv</strong> in any browser. Enter your community slug (e.g., <code>bellair</code>) and the 4–10 digit PIN set by the board admin. After a successful login you will receive a bookmarkable URL — set this as the home page on the lobby browser. The URL never expires unless you change the PIN.</p>

<h3>Managing the PIN</h3>
<p>Go to <strong>Settings → Lobby TV.</strong> The current PIN is shown. Click <strong>Change PIN</strong> to set a new 4–10 digit numeric PIN. Changing the PIN invalidates all previously bookmarked TV URLs immediately — every TV screen will need to log in again with the new PIN.</p>

<h3>Layouts</h3>
<ul>
    <li><strong>3-column</strong> — Announcements, Events, and Marketplace displayed side by side. Each column scrolls independently. Best for wide-format landscape screens.</li>
    <li><strong>Ticker</strong> — All content (announcements, events, marketplace) merges into a single horizontal scrolling feed of large cards, sorted by date. Best for narrow displays or when you want one unified stream.</li>
</ul>

<h3>Themes</h3>
<ul>
    <li><strong>Dark</strong> — navy background, white text. Default. Best for dim lobbies where dark screens pop.</li>
    <li><strong>Light</strong> — white background, dark text. Better in brightly-lit lobbies where dark screens wash out.</li>
</ul>

<h3>URL parameter overrides</h3>
<p>You can override layout and theme in the bookmarkable URL without changing Settings. Append:</p>
<ul>
    <li><code>&amp;style=columns</code> or <code>&amp;style=ticker</code></li>
    <li><code>&amp;dark=1</code> or <code>&amp;dark=0</code></li>
</ul>
<p>Example: <code>?slug=bellair&amp;pin=2727&amp;style=ticker&amp;dark=0</code></p>
<p>Useful if you have multiple screens in the same community with different layout preferences.</p>

<h3>What the TV shows</h3>
<ul>
    <li><strong>Announcements</strong> — active, non-expired posts with audience set to All or Members</li>
    <li><strong>Events</strong> — upcoming events in the next 30 days; recurring series expanded to individual dates</li>
    <li><strong>Marketplace</strong> — active listings with photos when available</li>
</ul>
<p>The page auto-refreshes every 10 minutes to pick up new content without manual intervention.</p>

<h3>Weather widget</h3>
<p>The TV header shows current conditions (temperature in °F and a description) using the free Open-Meteo weather API. It uses your association latitude and longitude from Settings. If those coordinates are not set, the weather widget will not appear.</p>

<h3>Rate limiting on login</h3>
<p>After 10 wrong PIN attempts from the same IP address, that IP is locked out for 15 minutes. This prevents brute-force PIN guessing.</p>
HTML,
    ],

    [
        'slug'       => 'permissions',
        'title'      => 'Configuring access — who can see what',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 460,
        'body'       => <<<HTML
<p>Board admins can configure exactly which role is required to access each feature in the portal. Go to <strong>Settings → Permissions</strong> (board admin access only).</p>

<h3>How role tiers work</h3>
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
<p>Setting a permission to "Board member" means board members, board admins, and super admins can all access it — but renters, staff, owners, and property managers cannot.</p>

<h3>What is configurable</h3>
<ul>
    <li>Full resident directory (contact details visible to members)</li>
    <li>Contacts page</li>
    <li>Meeting minutes (read-only)</li>
    <li>Work orders (read-only view)</li>
    <li>Violations (read-only view)</li>
    <li>Employee roster</li>
    <li>Insurance records</li>
    <li>Submitting concerns</li>
    <li>Submitting ARC requests</li>
    <li>Managing area attractions</li>
    <li>Posting property listings</li>
</ul>

<h3>What is not configurable</h3>
<p>Management write actions — creating work orders, issuing violation notices, editing employees, uploading documents — are always restricted to board and management roles. A misconfiguration cannot accidentally expose those to residents.</p>

<h3>Defaults</h3>
<p>If you have not changed a permission, it uses the system default. Permissions that have been customized are labeled so you always know what your association has changed vs. what is default.</p>
HTML,
    ],

    [
        'slug'       => 'violations',
        'title'      => 'Violation workflow — notices, fines, and tracking',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 470,
        'body'       => <<<HTML
<p>The Violations page lets the board formally record rule violations, issue notices, track progress, and maintain a complete audit trail from first observation to resolution.</p>

<h3>Recording a violation</h3>
<p>Click <strong>+ New violation.</strong> Fill in:</p>
<ul>
    <li>Unit number where the violation occurred</li>
    <li>The resident involved, if known</li>
    <li>The specific community rule being violated (searchable from your rules list)</li>
    <li>A detailed description of what was observed, with dates</li>
</ul>
<p>Save it and the violation is on record with a timestamp and the recording officer.</p>

<h3>Notice types</h3>
<p>From the violation detail page, issue one of four notice types:</p>
<ul>
    <li><strong>Warning</strong> — first contact, informational, no fine yet</li>
    <li><strong>Cure notice</strong> — requires the violation to be resolved by a specific deadline</li>
    <li><strong>Fine notice</strong> — formal notice of a fine amount and due date</li>
    <li><strong>Hearing notice</strong> — summons the resident to appear at a board hearing</li>
</ul>
<p>Each notice pre-populates a letter template. You can edit the letter before saving. Every saved notice is timestamped and appears in a timeline on the violation record.</p>

<h3>Print-ready letters</h3>
<p>Click Print on any notice to produce a print-ready letter with your association letterhead, the rule citation, applicable deadlines, fine amounts, and a signature block. Format is suitable for mailing.</p>

<h3>Status tracking</h3>
<p>Violations move through statuses: Open, In Progress, Hearing Scheduled, Resolved, Closed. Update the status as the situation progresses. Each status change is logged in the timeline.</p>

<h3>Converting from concerns</h3>
<p>A resident concern submitted through the Feedback page can be converted to a violation with one click. The violation form pre-fills from the concern text, preserving the original report.</p>

<h3>Resident view</h3>
<p>The board controls whether residents can see violations in <strong>Settings → Permissions → View violations.</strong> If enabled, residents see a read-only view of violations (they cannot see internal notes). By default, violations are board-only.</p>

<h3>Florida law note</h3>
<p>Florida Chapters 718 and 720 have specific service requirements for violation notices — especially for anything that could lead to a fine or hearing. Certified mail with return receipt is the safe default for anything fineable. Confirm your process with your HOA attorney before relying solely on in-app letters.</p>
HTML,
    ],

    [
        'slug'       => 'work-orders',
        'title'      => 'Work orders — maintenance and repair tracking',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 480,
        'body'       => <<<HTML
<p>Work orders are internal tickets for maintenance and repair tasks. Board admins and property managers create and manage them. Residents can be granted read-only access via the Permissions page.</p>

<h3>Creating a work order</h3>
<p>Click <strong>+ New work order</strong> on the Work Orders page. Fill in:</p>
<ul>
    <li>Title — a short description of the task</li>
    <li>Description — full details, including what failed, who reported it, and any access instructions</li>
    <li>Priority — Low, Normal, High, or Urgent</li>
    <li>Location — building area or common space</li>
    <li>Unit — if the issue is unit-specific</li>
    <li>Assignee — any active employee, board member, or management contact</li>
</ul>

<h3>Status tracking</h3>
<p>Work orders move through: Open → In Progress → Blocked → Completed → Closed. Each status change is logged in a timeline on the work order. You can add free-text notes at any status change — useful for recording what the contractor said, parts ordered, or estimated completion.</p>

<h3>Posting a status announcement</h3>
<p>When updating a work order status, an optional "Post an announcement" section appears. Tick the checkbox to publish a community announcement at the same time. This lets you close the work order and notify residents in a single action — no need to write a separate announcement.</p>

<h3>Converting from concerns or ARC requests</h3>
<p>A resident concern or an approved ARC request can be converted to a work order with one click. The work order form pre-fills from the source record, keeping the paper trail connected.</p>

<h3>Resident view</h3>
<p>The board can enable resident read-only access to work orders in <strong>Settings → Permissions → View work orders.</strong> Residents see status and description but not internal assignee notes.</p>
HTML,
    ],

    [
        'slug'       => 'directory-management',
        'title'      => 'Managing members — adding, editing, and inviting',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 490,
        'body'       => <<<HTML
<p>The Directory gives board admins full control over the member roster — adding new members, editing details, inviting members to activate their accounts, sending password resets, assigning roles, and deactivating accounts.</p>

<h3>Adding a member</h3>
<p>Click <strong>+ Add member</strong> from the Directory page. Fill in name, email, role, and unit assignment. The member is created with no password — they activate their account via an invitation email.</p>

<h3>Importing members from CSV</h3>
<p>Use the <strong>Import CSV</strong> button to bulk-import a list of members. The CSV must have columns: first_name, last_name, email, unit_number, role. Members without a real email address can be imported with a placeholder email — they will not receive an invitation but will be in the system for record-keeping.</p>

<h3>Sending an invitation</h3>
<p>From a member edit page, click <strong>Send invite email</strong> to send a welcome email with a secure one-time link. The member clicks the link, sets their password, and their account is activated. Invitation links expire after 72 hours.</p>

<h3>Sending a password reset</h3>
<p>From a member edit page, click <strong>Send password reset</strong> to send the member a 1-hour reset link — the same link generated by the Forgot password flow, but triggered by the board admin. Useful when a member is locked out and cannot receive the automated email for some reason.</p>

<h3>Editing member details</h3>
<p>Board admins can edit any member name, email, role, unit assignment, phone, and status. Use the Edit button on any directory row to open the edit form.</p>

<h3>Roles</h3>
<ul>
    <li><strong>Renter</strong> — limited access; cannot file ARC requests or see owner-only content</li>
    <li><strong>Staff</strong> — building staff; slightly more access than renter</li>
    <li><strong>Owner</strong> — full resident access; can file ARC requests and see owner-only content</li>
    <li><strong>Property manager</strong> — management-level read access to operational data</li>
    <li><strong>Board member</strong> — full board access except admin-only settings</li>
    <li><strong>Board admin</strong> — full board access including permissions and settings changes</li>
</ul>

<h3>Deactivating an account</h3>
<p>Set a member status to Inactive to disable their login. The member cannot sign in but their data is preserved. This is the correct approach when a resident moves out — do not delete accounts, deactivate them. Inactive accounts are hidden from the directory by default.</p>

<h3>Board notes</h3>
<p>Board admins can add internal notes to any member record — visible only to board and management. Useful for recording context, move-out dates, outstanding issues, or special arrangements.</p>

<h3>Last login tracking</h3>
<p>The Directory table shows the last login timestamp for each member. Use this to identify members who have never logged in (shown as "Never") and may need a reminder invitation.</p>
HTML,
    ],

    [
        'slug'       => 'units-management',
        'title'      => 'Units — managing unit records',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 500,
        'body'       => <<<HTML
<p>The Units page is a registry of every residential unit in your community — unit number, floor, type, residents assigned, and any unit-specific documents or board notes.</p>

<h3>Unit records</h3>
<p>Each unit has: unit number, floor, unit type (Studio, 1BR, 2BR, etc.), and a list of residents currently assigned to it. Multiple residents can be assigned to one unit (owner + renter, for example).</p>

<h3>Assigning residents to units</h3>
<p>Residents are assigned to units when their account is created or edited in the Directory. You can also reassign a resident from the unit detail page — open the unit, then use the resident assignment section to add or remove members.</p>

<h3>Unit documents</h3>
<p>Board admins can attach documents to a specific unit — floor plan, lease agreement, inspection report, etc. These documents are visible to residents of that unit plus the board, using the Unit Only access level. Useful for keeping unit-specific paperwork organized without making it visible to all members.</p>

<h3>Board notes on units</h3>
<p>Board admins can add internal notes to any unit record — renovation history, outstanding issues, special access arrangements, pet registrations not yet in the Forms system. Notes are visible to board and management only.</p>

<h3>Parking</h3>
<p>Parking spots can be linked to units from the Parking page. See the Parking help topic for details.</p>
HTML,
    ],

    [
        'slug'       => 'employees',
        'title'      => 'Employees — building staff and vendor roster',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 510,
        'body'       => <<<HTML
<p>The Employees page is a roster of building staff and regular vendors — superintendents, maintenance technicians, doormen, cleaning crews, and anyone else who works in or for the building.</p>

<h3>Adding an employee</h3>
<p>Click <strong>+ Add employee</strong> and fill in name, title, department, employment type (Full-time, Part-time, Contract, Seasonal), contact information, start date, and notes. Financial details like pay rate and salary are restricted to board admin and super admin view.</p>

<h3>Assigning to work orders</h3>
<p>Active employees appear in the Assignee dropdown when creating or editing a Work Order. This keeps work order assignments connected to real people in your roster.</p>

<h3>Employee documents</h3>
<p>Board admins can attach documents to an employee record — W-9, contract, certification, ID. These documents are board-only and quota-tracked against your storage allowance.</p>

<h3>Access control</h3>
<p>The board can control who can see the employee roster in <strong>Settings → Permissions → View employee roster.</strong> By default the roster is board and management only. Financial details are always board admin and above, regardless of permissions setting.</p>
HTML,
    ],

    [
        'slug'       => 'insurance',
        'title'      => 'Insurance and certificates of insurance (COIs)',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 520,
        'body'       => <<<HTML
<p>The Insurance page tracks the community insurance policies and certificates of insurance (COIs) for vendors and contractors who work in the building.</p>

<h3>Community policies</h3>
<p>Add your community master policy, directors and officers (D&amp;O) coverage, flood policy, umbrella policy, and any other community-level insurance. Each record tracks: carrier, policy number, effective date, expiration date, coverage amount, and attached certificate PDF.</p>

<h3>Vendor COIs</h3>
<p>When a contractor or vendor does work in the building, they must provide a COI naming your association as an additional insured. Track those COIs here with the vendor name, expiration date, and the attached PDF. Set an expiry reminder so you know before a COI lapses.</p>

<h3>Expiry tracking</h3>
<p>The Insurance page highlights policies and COIs that are expiring soon or already expired. Review this regularly — an expired COI means your association may be liable if the contractor causes damage.</p>

<h3>Access control</h3>
<p>The board can control who sees the Insurance page in <strong>Settings → Permissions → View insurance records.</strong> By default it is board and management only.</p>
HTML,
    ],

    [
        'slug'       => 'committees',
        'title'      => 'Committees',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 530,
        'body'       => <<<HTML
<p>The Committees page lets the board create and manage standing or ad hoc committees — Landscape, Social, Welcome, Budget, Rules Review, and so on.</p>

<h3>Creating a committee</h3>
<p>Click <strong>+ New committee</strong> and give it a name, description, and optional chair assignment. Committees can be marked active or inactive.</p>

<h3>Adding members</h3>
<p>Add any signed-in community member to a committee. Renters cannot be added to committees per the access rules — only owners and above can participate. Each committee member can be designated as Chair or Member.</p>

<h3>Committee interest forms</h3>
<p>Residents can express interest in joining a committee by submitting a Committee Interest form from the Forms page. Board admins review the submissions and add members manually.</p>

<h3>Visibility</h3>
<p>Committee rosters are visible to all signed-in members by default. The board can discuss committee business using the document system (Board Only access level) or meeting minutes.</p>
HTML,
    ],

    [
        'slug'       => 'parking',
        'title'      => 'Parking management',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 540,
        'body'       => <<<HTML
<p>The Parking page tracks assigned parking spots, visitor spaces, and the residents or vehicles using them.</p>

<h3>Parking spots</h3>
<p>Add each parking spot with its identifier (number, letter, or combined label), type (Assigned, Visitor, Handicap, Reserved), and any notes. Link the spot to a unit to track which unit holds the assignment.</p>

<h3>Vehicle tracking</h3>
<p>Record the vehicles associated with each resident — make, model, year, color, and license plate. This makes it easy to identify unauthorized vehicles or verify a resident complaint about a specific car.</p>

<h3>Temporary parking passes</h3>
<p>Residents can request a temporary parking pass via the Forms page (Temporary parking pass form). Board admins receive the request and can issue or deny it. Issued passes show in the parking system with an expiry date.</p>

<h3>Parking violations</h3>
<p>If a parking violation is observed, you can file it as a Violation from the Violations page with the vehicle details and parking rule citation. The violation workflow handles the notice and tracking from there.</p>
HTML,
    ],

    [
        'slug'       => 'activity-log',
        'title'      => 'Activity log — who did what and when',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 550,
        'body'       => <<<HTML
<p>The Activity log is a full audit trail of every meaningful action taken in the portal — who created, edited, or deleted what, and when. It is append-only: nothing is ever removed from the log.</p>

<h3>What is logged</h3>
<p>Every significant action is recorded: member logins, document uploads, announcement posts, violation notices issued, work orders created or updated, broadcast emails sent, settings changes, permission changes, member invitations sent, signature events, and more. Minor read-only views are not logged to keep the log useful.</p>

<h3>Filtering</h3>
<p>Use the date range pickers and the action type dropdown to filter the log to a specific window or event type. Search by member name to see all actions by or about a specific person.</p>

<h3>Access</h3>
<p>The Activity log is visible to board members and above in the dashboard (<strong>Activity</strong> in the Configuration group). Super admins see a platform-wide log at /admin/activity.php covering all associations.</p>

<h3>Common use cases</h3>
<ul>
    <li>"Who deleted that document?" — filter by document.deleted</li>
    <li>"When was the last violation notice sent?" — filter by violation.notice.issued</li>
    <li>"Has the broadcast email been sent yet?" — filter by broadcast.sent</li>
    <li>"Who changed the permission settings?" — filter by permission.updated</li>
</ul>
HTML,
    ],

    [
        'slug'       => 'settings',
        'title'      => 'Association settings — profile, plan, and configuration',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_member',
        'sort_order' => 560,
        'body'       => <<<HTML
<p>The Settings page is where the board configures association-wide details — profile, address, geocoding, public landing content, lobby TV, and permissions. Board admin access is required for most sections.</p>

<h3>Association profile</h3>
<p>Set the association name, slug (used in the public landing URL), address, phone, email, and description. The Photon geocoder runs automatically on save to set the latitude and longitude — these coordinates power the weather widget on the Lobby TV and the map embed on the public landing page.</p>

<h3>Public landing sections</h3>
<p>The public community landing page at <code>/{slug}/</code> is built from content you maintain in Settings: about text (Quill editor), amenity list, community photos (from the Media gallery), meet-your-board members (opt-in per user), FAQ, announcements (public ones), events (public ones), and a Plan a Visit section (directions, parking, hours, notes).</p>

<h3>Lobby TV</h3>
<p>Set or change the TV PIN, choose the default layout (3-column or ticker), choose the default theme (dark or light), and view the bookmarkable TV URL. See the Lobby TV help topic for full details.</p>

<h3>Permissions</h3>
<p>Configure which role can access each configurable feature. See the Permissions help topic for the full list of what is configurable.</p>

<h3>Your account (personal settings)</h3>
<p>The Settings page also has a personal section for changing your own password and managing your directory privacy toggle. These settings apply to your account only, not the association.</p>

<h3>Plan and storage</h3>
<p>Your current plan tier and storage usage are shown in Settings. Storage usage breaks down by category (documents, media, avatars, branding). If you are near your quota, delete old files or contact us about upgrading.</p>
HTML,
    ],

    [
        'slug'       => 'inviting-members',
        'title'      => 'Inviting members and managing account activation',
        'category'   => 'Board &amp; management',
        'min_role'   => 'board_admin',
        'sort_order' => 570,
        'body'       => <<<HTML
<p>New members are added to the system by board admins, then activated via an invitation email that lets them set their own password.</p>

<h3>The invitation flow</h3>
<ol>
    <li>Board admin adds the member in the Directory (name, email, role, unit).</li>
    <li>From the member edit page, click <strong>Send invite email.</strong></li>
    <li>The member receives a welcome email with a secure one-time link. The link is valid for 72 hours.</li>
    <li>The member clicks the link, sets their password, and their account is active.</li>
</ol>

<h3>What the invitation email contains</h3>
<p>The invitation email includes a personalized greeting, a summary of what BadassHOA offers, and a clear call-to-action button linking to the account setup page. It is sent from your association email and signed with the board admin name.</p>

<h3>Resending an invitation</h3>
<p>If the member missed the email or the 72-hour window expired, open the member edit page and click <strong>Send invite email</strong> again. A new token is generated and the old one is invalidated.</p>

<h3>Members who never activate</h3>
<p>The Directory last-login column shows "Never" for members who have not logged in. Use this to identify who needs a follow-up invite. You can also bulk-see this in the directory stats panel (board admin only).</p>

<h3>Sending a password reset instead</h3>
<p>If a member has previously activated their account but is locked out, use <strong>Send password reset</strong> from the member edit page instead of a new invitation. The reset link expires in 1 hour and uses the same reset flow as Forgot password.</p>

<h3>Members without real email addresses</h3>
<p>If you import a member without a real email (using a placeholder like "noemail@example.com"), you cannot send them an invitation. These members can only access the system if you manually set their password and give it to them — there is currently no in-app mechanism for offline credential distribution.</p>
HTML,
    ],

];

echo "Seeding help topics...\n";
foreach ($topics as $topic) {
    insert($pdo, $stmt, $topic);
}

$count = $pdo->query('SELECT COUNT(*) FROM help_topics')->fetchColumn();
echo "\nDone. $count topics in help_topics.\n";
