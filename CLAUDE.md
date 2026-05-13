# BadassHOA — Project Notes for Claude

This file is the living source of truth across sessions. The original spec (badassHOA_claude_code_instructions.md) is the *brief*; this file captures **decisions, deviations, deferred items, and current status**. When in doubt, this file wins over the brief because it reflects what we actually agreed.

Persona: Claude operates here as **"Reboooot"** — seasoned dev + UX designer. Push back, propose alternatives, flag gaps before executing. Kevin invited that explicitly.

---

## Local environment (verified 2026-05-09)

- **Project root:** `/Users/kevinbleigh/Sites/badasshoa/` (MAMP PRO default location — NOT the `/Applications/MAMP/htdocs/` path the original spec assumed)
- **Local URL:** `https://badasshoa.com:8890/` (MAMP PRO vhost on HTTPS port 8890 with self-signed cert; `badasshoa.com` is mapped to 127.0.0.1 via `/etc/hosts`)
- **HTTP mirror:** Apache also listens on `:8888` if needed
- **PHP:** 8.5.2 active (7.3.33 also installed). Target 8.x.
- **MySQL:** 8.0.44 — **socket-only**, no TCP listener
  - Socket: `/Applications/MAMP/tmp/mysql/mysql.sock`
  - User: `root` / Pass: `root`
  - Database: `badassHOA` (to be created — does not exist yet)
- **MySQL CLI:** `/Applications/MAMP/Library/bin/mysql80/bin/mysql --socket=/Applications/MAMP/tmp/mysql/mysql.sock -u root -proot`
- **PDO DSN must use:** `mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=badassHOA;charset=utf8mb4` — NOT `host=127.0.0.1;port=3306` from the spec
- **Live target:** `https://badasshoa.com` on Hostinger — **deployed 2026-05-10**, see Production environment below.

---

## Production environment (live 2026-05-10)

- **Live URL:** `https://badasshoa.com`
- **Hosting:** Hostinger shared hosting, same account as sellinglane / BadassNovels / Prayersto
- **SSH:** `ssh -p 65002 u535581001@77.37.59.82`
- **Repo dir on server:** `~/badasshoa/` (laptop rsyncs here)
- **Web dir on server:** `~/domains/badasshoa.com/public_html/` (synced from repo by `deploy.sh`)
- **PHP:** 8.2.30
- **DB:** MariaDB 11.8.6 — `u535581001_badassHOA` (user `u535581001_kleigh`)
- **Mail:** msmtp → `smtp.hostinger.com:465` SSL, mailbox `success@badasshoa.com`. `~/.msmtprc` on server holds creds. Send log at `~/.msmtp.log`. `send_mail()` driver is `msmtp` in prod, `log` locally.
- **`config.php`:** lives only on the server (not in git). Holds DB + mail creds. Mode 600 at `~/domains/badasshoa.com/public_html/config.php`.
- **Live super admin:** `me@kevinleigh.com` (seeded in prod with the same password used locally — change via `/dashboard/settings.php` after first login if you want it different).

### Deploy from laptop

```bash
cd /Users/kevinbleigh/Sites/badasshoa
bash push.sh   # rsync laptop → server, then runs deploy.sh on the server
```

`push.sh` (laptop) and `deploy.sh` (server) both exclude `config.php`, `storage/uploads/`, `storage/logs/`, `content/changelog.json`, `*.md`, and `migrations/` — so prod-mutable state is never overwritten. `deploy.sh` also touches every `*.php` to bust opcache.

> Note: `content/changelog.json` is admin-edited via `/admin/changelog.php` on prod. The repo copy is only seeded on first deploy. To bridge a manual local edit to prod, `scp` it once: `scp -P 65002 content/changelog.json u535581001@77.37.59.82:~/domains/badasshoa.com/public_html/content/`.

### Run a new migration on prod

```bash
# from the laptop, after adding migrations/0XX_thing.sql
scp -P 65002 migrations/0XX_thing.sql u535581001@77.37.59.82:/tmp/
ssh -p 65002 u535581001@77.37.59.82 \
  'mysql -u u535581001_kleigh -p<DB_PASSWORD> u535581001_badassHOA < /tmp/0XX_thing.sql'
```

(The DB password is in `~/domains/badasshoa.com/public_html/config.php` on the server.)

### Production logs

| Log | Path on server |
|---|---|
| PHP errors | `~/domains/badasshoa.com/public_html/storage/logs/php-errors.log` |
| Mail (only when send fails / driver=log) | `~/domains/badasshoa.com/public_html/storage/logs/mail.log` |
| msmtp send log (always) | `~/.msmtp.log` |
| Apache access/error | hPanel → Advanced → Error Logs |

---

## Locked Phase 1 decisions

| Topic | Decision | Why |
|---|---|---|
| **Tenancy routing** | Path-based `/{slug}/` for Phase 1 | Subdomains need wildcard DNS + vhost work on Hostinger. Defer to Phase 2. |
| **Phase 1 scope** | Full 11-page list from the spec | Kevin's explicit choice; chose breadth over a tight spine. |
| **Design language** | Stripe / Ramp / Mercury vibe (confident, colored, modern) — NOT Linear/Vercel zinc-minimal | "BadassHOA" wants visual confidence. Navy `#0f1f3d` + bold orange `#f05a28` + off-white `#f8f7f4`. |
| **Fonts** | Syne (display) + Inter (body), Google Fonts | Per spec. |
| **Email** | Stub locally (file logger), real SMTP before launch | Avoids blocking Phase 1; PHP `mail()` won't work reliably on Hostinger anyway. |
| **CSS framework** | Custom only — no Bootstrap, no Tailwind | Per spec. |
| **DB driver** | PHP PDO with prepared statements | Per spec. No raw SQL interpolation anywhere. |
| **Multi-tenancy data model** | Shared DB, shared schema, `association_id` foreign key on every tenant row | All queries must filter by `association_id` from session. |

---

## Additions Reboooot is making to the original schema

The spec's schema is incomplete for a real signup/login/audit story. These are added in the migration:

- **`password_resets`** — token hash + user_id + expires_at; for the password-reset flow that the spec asks for but doesn't model.
- **`audit_log`** — actor_user_id, association_id, action, target_type, target_id, metadata JSON, created_at. Boards will absolutely ask "who deleted that doc?" Cheap to add now.
- **`login_attempts`** — email + ip + attempted_at. Rate-limit auth (5 fails / 15 min / IP).
- **`sessions`** — server-side session store keyed by session id. Lets us log users out remotely and survives across servers later.

These are *additive* — they don't change anything in the spec's schema.

---

## Security baseline (non-negotiable)

These get applied everywhere from day one because retrofitting is painful:

1. **CSRF token** on every state-changing POST. Token in session, hidden field in form, validated server-side.
2. **Session regeneration** on login (`session_regenerate_id(true)`) and on privilege change.
3. **Login throttling** via `login_attempts` table — 5 failed attempts / IP / 15 min returns 429.
4. **Strict role check** — `requireRole()` must default-deny when the role string is missing or unknown. The version in the spec has a silent-failure bug.
5. **File uploads** stored *outside* the web root (`/var/badasshoa-uploads/` locally, equivalent on Hostinger), served through a PHP gatekeeper that checks auth + association. Public assets get a separate web-root path.
6. **MIME + extension whitelist** on uploads, max-size cap, sanitized filenames (uuid-based, not user input).
7. **Output escaping** — every templated value goes through `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`. No raw `echo $var`.
8. **bcrypt via `password_hash(PASSWORD_DEFAULT)`** for passwords. Never store plaintext, never SHA.
9. **Tenant scoping at the query layer** — every read/write that hits a tenant table must include `association_id = :aid` from session. Helper functions enforce this.

---

## Deferred / Phase 2+

- Subdomain-per-tenant routing
- Maintenance requests, violation tracking
- Digital signatures, amenity booking
- Board voting
- Stripe / payment processing
- Mobile PWA
- 2FA (hooks may be added now if cheap)
- Real SMTP wiring (stubbed in Phase 1)
- Hostinger deploy automation
- Custom domain per tenant

---

## Folder layout (as built)

```
/badasshoa/
├── CLAUDE.md                  ← this file
├── badassHOA_claude_code_instructions.md  ← original spec (read-only reference)
├── index.php                  ← public marketing home
├── pricing.php
├── signup.php
├── login.php / logout.php
├── dashboard/                 ← board + resident portal
├── admin/                     ← super-admin panel
├── includes/                  ← db, auth, header, footer, functions
├── assets/
│   ├── css/                   ← tokens, base, app
│   └── js/                    ← app, search
├── migrations/                ← SQL files, run in order
└── (uploads stored OUTSIDE web root — see Security #5)
```

---

## How to resume mid-session

If you're picking this up after a break:

1. Read **"Locked Phase 1 decisions"** above.
2. Run the todo list (it's tracked via TodoWrite each session).
3. Verify MAMP is up: `lsof -nP -iTCP -sTCP:LISTEN | grep -E "8890|mysql"` should show httpd on 8890 and a mysqld process.
4. Verify DB: `/Applications/MAMP/Library/bin/mysql80/bin/mysql --socket=/Applications/MAMP/tmp/mysql/mysql.sock -u root -proot -e "SHOW DATABASES;"` should include `badassHOA`.
5. Open `https://badasshoa.com:8890/` in a browser (accept the self-signed cert).

---

## Open questions to revisit

- **Hostinger deploy path** — need FTP creds or git remote when ready to ship. Not blocking for Phase 1 local build.
- **Logo** — spec says "inline SVG B or shield." No final design yet. Reboooot will scaffold a placeholder mark and can iterate.
- **Domain alternatives mentioned in spec** — heyhomeowner.com, heycondoowner.com, hatetenant.com — Kevin to decide whether to register; doesn't affect the build.
- **Pilot association?** — building generic. If there's a real first customer, their data shape might tweak defaults.

---

## Phase 1 — DONE (2026-05-09)

Verified end-to-end on `https://badasshoa.com:8890/`:

| Surface | Pages | Status |
|---|---|---|
| Public marketing | `/`, `/pricing.php`, `/signup.php` | ✓ HTTP 200, no errors |
| Auth | `/login.php`, `/logout.php` | ✓ Login → role-based redirect verified |
| Tenant dashboard | `/dashboard/` + 6 sub-pages + `/dashboard/file.php` gatekeeper | ✓ All 200 when authenticated, 302→login when not |
| Super admin | `/admin/` + associations + users | ✓ 200 for super_admin, 403 for board users |

**Smoke test passed:**
- Board user `board@demo.badasshoa.com / changeme!` → lands at `/dashboard/`, all 7 pages serve, blocked from `/admin/` (403)
- Super admin `admin@badasshoa.com / changeme!` → lands at `/admin/`, all 3 admin pages serve
- No PHP fatal errors, warnings, or notices in rendered output
- Login throttling, CSRF tokens, session regeneration on login, role default-deny — all wired up
- File uploads stored at `/storage/uploads/{association_id}/...` outside web root, served via `/dashboard/file.php` gatekeeper with access-level + tenant checks

### Demo logins (local dev only — change before production)

| Email | Password | Role |
|---|---|---|
| `me@kevinleigh.com` | `bhoaK0m3r2.6` | super_admin |
| `board@demo.badasshoa.com` | `changeme!` | board_admin (Demo Condos) |

Seeded by `migrations/002_seed_dev.sql`.

### What's stubbed / what's next

**Production wiring done (2026-05-10):**
- **Email** — `send_mail()` now branches on `config()['mail']['driver']`: `log` (local), `mail` (PHP mail() via host sendmail), or `msmtp` (production, pipes RFC822 to `/usr/bin/msmtp -t`). Prod uses `msmtp` against `smtp.hostinger.com:465` SSL with mailbox `success@badasshoa.com`. Failed sends fall through to `mail.log` so nothing is silently dropped.
- **Production DB DSN** — `includes/db.php` reads from `config.php`. The on-server `config.php` (mode 600, never in git) holds the Hostinger DB creds. PDO still pins every connection to UTC via `SET time_zone = '+00:00'`.

**Still deferred:**
- **Subdomain-per-tenant routing** — Phase 2. Phase 1 is single-tenant-per-session via `association_id` from the `users` table; no slug in URL (the public landing uses path-based slugs but auth + dashboards are per-user).

**Phase 1.5 — DONE (2026-05-09):**
- ✅ **Password reset flow** — `/forgot.php` (anti-enumeration: same response for unknown emails) + `/reset.php` (SQL-side expiry check). Tokens are 32-byte random, stored as SHA-256 hashes, single-use, 1-hour TTL. Issuing a new token invalidates any prior unused tokens for the user.
- ✅ **Change password while logged in** — card on `/dashboard/settings.php` that verifies current password, requires 8+ chars, also clears any outstanding reset tokens for that user.
- ✅ **Public community landing pages** — per-association `/{slug}/` pages (path-based, .htaccess rewrites). Sections: hero (banner image), about (Quill), amenities, community photos, meet-your-board (per-user opt-in via `users.show_on_public_landing`), public documents, upcoming events, embedded OSM map, FAQ, announcements, contact form, social-link footer. All sections conditionally render based on data presence. Free APIs only: Photon (komoot.io) for geocoding + address autocomplete, Zippopotam.us for ZIP fallback, OpenStreetMap iframe for the map.
- ✅ **Events** — `/dashboard/events.php` board CRUD with audience field (`all`/`members`/`board`); only `audience='all'` events appear on the public landing. Migration `013_map_and_events.sql`.
- ✅ **Public file/branding endpoints** — `/branding.php`, `/public-media.php`, `/public-document.php`, `/contact.php` bypass auth and gate by visibility/access_level. `.htaccess` excludes these from the slug-rewrite catch-all.
- ✅ **Changelog system** — JSON-backed (`/content/changelog.json`), public page at `/changelog.php`, super-admin CRUD at `/admin/changelog.php`. Mirrors the sister sellinglane project pattern.
- ✅ **Address structure everywhere** — signup, settings, admin/associations all use `address / city / state_region / postal_code / country` with Photon autocomplete + Zippopotam ZIP fallback.

**Phase 1.5 left over (small, low-risk, ship when ready):**
- IP-based rate limiting on `/forgot.php` (currently anti-enumeration but no per-IP cap; an attacker on one IP could spam unlimited reset emails). Add a `request_attempts` table or reuse `login_attempts` with a `kind` column.
- CSV import for residents on `/dashboard/directory.php`
- Per-association logo upload + use it in the dashboard nav
- `audit_log` viewer on `/dashboard/settings.php` (board admins only)
- Image thumbnail generation on media upload (currently serves full-res through the gatekeeper)
- Display-side timezone polish: page footers show timestamps in UTC right now (clock pinned globally); add per-user TZ preference on `/dashboard/settings.php` and wrap display formatters once.

**Phase 2 (planned):**
- Maintenance request workflow + violation tracking
- Subdomain or path-prefix multi-tenancy in the URL
- ~~Real SMTP wiring + email templates~~ → **SMTP wired 2026-05-10**; email *templates* (HTML versions, branded headers) still TODO
- ~~Hostinger deploy pipeline~~ → **shipped 2026-05-10** as `push.sh` (laptop) + `deploy.sh` (server)

**Phase 3 (planned):**
- Payment processing (Stripe)
- Board voting
- Mobile PWA

---

## Public changelog convention

Every meaningful change to BadassHOA gets logged at **`/content/changelog.json`** (the source of truth) and renders on:
- **`/changelog.php`** — public marketing page (linked from the public footer)
- **`/admin/changelog.php`** — super-admin CRUD UI (linked in the admin sidebar)

**Categories** (defined in `includes/changelog.php` → `changelog_types()`):
- `feature` — new capability shipping for the first time
- `improvement` — existing thing made better
- `fix` — bug fix
- `design` — visual / UX change
- `security` — auth, validation, or hardening change
- `content` — copy / messaging update

**For future sessions:** any time you ship something visible to the user (new feature, design tweak, copy change, bug fix), add an entry. Easiest path:
1. Sign in as super admin → `/admin/changelog.php` → "+ New entry"
2. Or edit `content/changelog.json` directly and commit it

This file (CLAUDE.md) keeps an internal-only summary in the section below for cross-session continuity. The user-facing list lives in JSON.

---

## Where we left off (resume here next session)

**Last session ended:** 2026-05-12 (session 4) — **UI polish + global search sprint**. No new migrations. All changes are PHP/CSS only. Last commit is session-4 commit (see git log).

**What got built this session:**
- **Dashboard tile grid** — 6-across (was 4), tighter padding/icons; breakpoints 4@≤1100px, 3@≤680px, 2@≤420px
- **Dashboard cleanup** — removed "incl. PM" hint on board tile, "upcoming" hint on events tile; feedback value now shows pending count + inline "/ N total" on same line; removed "Post announcement" and "Upload document" quick-action buttons
- **Sidebar nav tightened** — link padding reduced to 5px vertical; group-label font bumped to 11px at 45% opacity; group-label top padding removed
- **Collapse toggle redesign** — floating `position: fixed` circular button (28px, navy border + shadow) pinned at `left: calc(--side-nav-w - 14px)`, outside the sidebar so `overflow: hidden` doesn't clip it; slides with the sidebar via CSS variable; chevron rotates 180° when collapsed
- **Sign-out button** — added text label "Sign out" next to icon; hidden at ≤860px
- **Scroll hint** — changed from non-interactive `<div>` to a `<button>`; click scrolls the nav list down 120px smoothly
- **Weather tile** — second click (when forecast is loaded) opens the NWS forecast page for the association's lat/lon in a new tab
- **Board member headshots** — 88px centered avatars (photo or navy initial fallback) on directory board-member cards
- **Directory activity stats** — collapsible `<details>` panel (board_admin/super_admin only) showing count of: members with no real email, members who've never logged in, members active in last 30 days
- **`can_do()` bug fix** — was reading `$_SESSION['role']` directly; now correctly calls `viewing_role()` so view-as-renter/owner actually hides board-only nav links and pages
- **Global search expanded** — `dashboard/find.php` now covers: work orders (management), violations (management), meeting minutes (via `can_do()`), members, and FAQs (all roles)

**Production state in DB:**
- Migrations through 045 applied to `u535581001_badassHOA` — no new migrations this session
- 191 users with `role='owner'`; 2 staff; 4 board_member; 1 property_manager; 1 board_admin; 2 super_admin
- `association_permissions` table exists; no custom overrides (all defaults in effect)
- Email driver still `log` (paused since 2026-05-10)

**Logins:**
- **Prod** super admin: `me@kevinleigh.com / bhoaK0m3r2.6`
- Local MAMP DB is behind prod (migrations 014–045). If reviving local dev, run migrations 014 onward in order.

**Quick visual check (prod):**
- Marketing home: https://badasshoa.com/
- Public landing: https://badasshoa.com/bellair/
- Dashboard: https://badasshoa.com/dashboard/
- Global search: https://badasshoa.com/dashboard/find.php?q=pool
- Permissions: https://badasshoa.com/dashboard/permissions.php

**Known gotchas to not regress:**
- `can_do()` now calls `viewing_role()` — this is intentional and load-bearing for the view-as feature. Do not revert to `$_SESSION['role']`.
- `can_do()` static cache is per-request (keyed by `$aid`). Two associations in the same PHP process will need a fresh request to clear it.
- Every write POST handler in work-orders.php and violations.php has an explicit `if (!$canManageWO/Vio) { 403; die }` guard — the individual guards are load-bearing (no top-level `require_management()`).
- `documents` table has `employee_id` and `insurance_id` nullable FKs (migration 044). Queries that list "all docs for an association" will include employee/insurance docs unless filtered. The file gatekeeper blocks renters via `access_level='board_only'`.
- The pool FAQ (id 8) still has `[VERIFY]` markers in the answer text — Kevin's rewrite from the paper sign was never finalized.
- The first Bellair user form (temp parking pass) was never test-filed to validate the flow end-to-end.

**Candidates for next session** (queued + roughly prioritized):
1. **Board voting module** — Kevin said "next up voting." Board creates a ballot with options + deadline; members cast one vote; results reveal after deadline. New table `votes` (id, association_id, title, description, options JSON, deadline, status) + `vote_responses` (vote_id, user_id, choice, cast_at) + `/dashboard/voting.php`.
2. **Lobby-TV digital signage** — rotating-content TV mode, auto-refresh, no-login URL with a time-limited token.
3. **Maintenance request → Work Order conversion** — form submission lands in the WO queue (same Concern→WO / ARC→WO pattern already in place).
4. **Turn email back on** — flip prod `config.mail.driver` from `log` to `msmtp` (one line in server config.php whenever Kevin says go).
5. **Service animal registration form** — queued from a prior session; never shipped.
6. **Newsletter signup** on the public landing.
7. **Per-user TZ preference** — all server-rendered timestamps are UTC right now; add a TZ field to user settings and wrap display formatters.
8. **Per-association logo upload** — use it in the dashboard nav instead of the text name.
9. **Rental agents as contacts** — add `rental_agent` to `association_contacts.kind` ENUM; add `rental_agent_contact_id` FK on `users`; show a "Rental agent" dropdown in the renter add/edit form (directory.php) so renters can be linked to their agent.

**Big-ticket comms / outreach features (queued — likely a Phase 3 batch):**

All four share a `broadcasts` table (kind / audience / subject / body / scheduled_at / sent_at / status) + a `broadcast_recipients` table (broadcast_id / user_id / channel / status / provider_id / error). And all four cost real money per-send — needs a real billing path before going GA.

8. **Broadcast SMS with opt-in / opt-out (TCPA-compliant).**
    - Provider: Twilio (default), Telnyx or Bandwidth as alternates. Long code or short code; for HOAs a per-association toll-free number with verified business use makes the most sense.
    - Per-user fields on `users`: `sms_opt_in` (TINYINT), `sms_opt_in_at` (TIMESTAMP), `sms_phone_verified_at`. **Opt-in must be express + recorded** — phone-verification round trip (send code, confirm) before any marketing-style broadcast.
    - **STOP / HELP keyword handling** — inbound webhook → set `sms_opt_in = 0` on STOP, send help text on HELP. Required by carriers regardless of TCPA.
    - Two tiers: "Emergency only" (TCPA-exempt under certain conditions) vs "General" (requires explicit opt-in). Surface the tier on each broadcast.
    - Audit per recipient: delivered / undelivered / opted_out, with provider message-id for the trail.
    - **Caveat:** TCPA penalties are $500–$1,500 per violation. Don't ship without counsel review of the consent flow.

9. **Broadcast email with opt-in / opt-out + bounce handling.**
    - Transactional email already works via msmtp. Broadcast is different — needs a provider that handles bulk, list-unsubscribe headers, bounce + complaint webhooks. **Postmark** is the cleanest choice for boards (excellent reputation, real human support); **AWS SES** is cheaper if Kevin's price-sensitive; **SendGrid** if he wants templating built-in.
    - Per-user fields: `email_broadcast_opt_in` (distinct from transactional opt-out — owners can't opt out of dues notices, can opt out of newsletters), `email_opt_in_at`, `last_bounced_at`, `bounce_count`.
    - Every broadcast gets a `List-Unsubscribe` header + a one-click unsubscribe link.
    - Bounce + complaint webhook → auto-flip `email_broadcast_opt_in = 0` after N bounces or any complaint.
    - **Two tiers** same as SMS: "Required" (assessments, legal notices — sent regardless of broadcast-opt-in but always to the email of record) vs "Optional" (newsletter, social events).
    - DKIM + SPF + DMARC must be set up on the sending domain before any volume — Postmark / SES walk you through it.

10. **Broadcast call (pre-recorded voice).**
    - Twilio Voice + `<Play>` of an uploaded MP3, or `<Say>` with TTS. Falls back to leaving a voicemail if no pickup.
    - Per-user `voice_opt_in` field — even more restrictive than SMS opt-in. **Express written consent required for marketing robocalls** under TCPA; emergency / public safety calls have a narrow exemption.
    - Time-of-day guardrails: TCPA window is 8 AM – 9 PM in the recipient's local timezone. Build refuses to schedule outside that window.
    - Best use case for an HOA: hurricane warnings, urgent water shutoffs, building lockdowns. Not for "pool party Saturday."
    - Per-call audit: answered / voicemail / no-answer / opted-out. Twilio gives you the call SID and recording (if you record).
    - **Highest legal risk of the four — get counsel sign-off before any production traffic.** Consider gating behind an "Emergency only" tier from day one.

11. **Physical mail integration (Lob.com).**
    - Use cases driving this: violation notices (Florida requires first-class mail at minimum; certified for fines + final notices), board meeting notices (state statute may require mailed notice for annual meetings), and invoices / dues statements (when the billing module ships).
    - **Lob** is the obvious provider — REST API for letters, postcards, certified mail with USPS tracking; address normalization built in; templated HTML → PDF → physical letter.
    - New table `mail_pieces`: id, association_id, kind ENUM('violation','meeting_notice','invoice','general'), recipient_user_id, recipient_address_snapshot (frozen at send time so a later address change doesn't break the trail), lob_id, status (created/in_transit/delivered/returned/failed), tracking_url, cost_cents, created_at, delivered_at.
    - Address comes from `users.mailing_address*` columns if present, else the unit address.
    - Per-association settings: lob API key (in association config, encrypted), default letterhead, return address.
    - Cost: ~$1–$2 per letter for first class, ~$5+ for certified. Pass-through pricing OR baked into the plan.
    - **Florida-specific:** violation notice statutes (Ch. 718 for condos, Ch. 720 for HOAs) have very specific service requirements — get those right before automating any notice. Certified mail with return receipt is the safe default for anything fineable.

12. **Custom domain per association ($5/mo add-on).**
    - **App layer (easy, half a day):** add `associations.custom_domain VARCHAR(190) UNIQUE NULL`; in `_bootstrap.php` if `$_SERVER['HTTP_HOST']` doesn't match `badasshoa.com`, look up the association by `custom_domain` and set context; add a Custom Domain card to `/dashboard/settings.php` for the board to enter + a setup-instructions block ("Point an A record at our IP, CNAME `www` at our domain").
    - **Ops layer (hard, hosting-dependent):**
        - On Hostinger shared: each custom domain must be added in hPanel manually before Apache will serve it. Workable for ~5-10 paying tenants; doesn't scale beyond that. Cert provisioning is also manual.
        - **Right answer once we have multiple paying tenants:** move BadassHOA to a VPS (DigitalOcean / Hetzner / Linode, ~$10-20/mo) with **Caddy** as the reverse proxy. Caddy auto-provisions Let's Encrypt certs for any domain that resolves to the IP. Zero per-customer ops.
        - Alternative: Cloudflare's "Custom Hostnames" (SaaS for Platforms) — they handle certs per custom domain, but pricing kicks in around $200/mo. Overkill until volume.
    - **Pricing:** $5/mo per association is fair (SquareSpace / Wix / Shopify all charge $10-20/mo for the same thing). Surface in association settings with a clear billing note.
    - **Build order:** ship the app-layer columns + bootstrap routing now (Phase 2.5) so the data model doesn't need a future migration. Hold off on customer-facing on/off until VPS migration happens. **Recommendation: do the VPS move once Bellair has its second paying neighbor association.**

**Cross-cutting compliance work needed for any of #8–#11:**
- A new `/dashboard/communication-preferences.php` for each user (and a section on `/dashboard/profile.php`) showing what they're opted into per channel.
- Audit-log every consent state change (opted in / out / verified phone).
- An association-level "do I have a real billing path?" check before allowing any of the above — these incur per-send costs, and we don't want a board accidentally spending $400 on a robocall that wasn't authorized.

**Important caveats / known gotchas:**
- E-signatures are E-SIGN/UETA-shaped but Kevin should have counsel review before relying on them for binding documents. The implementation captures everything the law requires (intent, consent, association, audit, tamper-detection); the disclosure language could be more formal (right-to-paper-copy, right-to-withdraw).
- The settings page's profile + landing forms used to both POST `form=update` to a single handler that overwrote every column — fixed via section markers. If you add a third form to settings.php, give it its own section value or you'll regress this.
- The pool FAQ (id 8) has Bellair-specific placeholder copy with `[VERIFY]` markers that may still be in the answer text — Kevin meant to refine but session ended before the update SQL ran. The full update is in `/tmp/bellair_faq_pool_update.sql` (laptop only, didn't ship) — was interrupted before he could approve the bigger rewrite from the actual paper pool-rules sign he sent.
- The first Bellair user form hasn't been filed yet — Kevin was going to file a temp parking pass to validate the flow but session ended.

---

## Changelog

- **2026-05-12 (session 4) — UI polish + global search sprint.**
    - Dashboard: 6-across tile grid, tighter padding/icons, removed "incl. PM" / "upcoming" hints, feedback shows pending + inline total, removed quick-action buttons at top.
    - Sidebar collapse toggle: redesigned as floating `position: fixed` circular edge button (no longer clipped by sidebar overflow). Slides with sidebar via CSS variable.
    - Sign-out button: added visible text label. Scroll hint: now a clickable button that scrolls nav down.
    - Weather tile: second click opens NWS forecast for association's lat/lon.
    - Board member headshots in directory (88px avatar or navy-initial fallback).
    - Directory activity stats panel (board_admin only): no-real-email count, never-logged-in count, active-last-30-days count.
    - Bug fix: `can_do()` was reading `$_SESSION['role']` instead of `viewing_role()` — view-as simulation was broken for permission checks.
    - Global search now covers: work orders, violations, meeting minutes, members, and FAQs.

- **2026-05-12 (session 3) — Permissions, role cleanup, and ops polish sprint.**
    - Migrations 042–045 applied to prod. Working tree has untracked new files (violations.php, minutes.php, violation-notice-print.php, migrations 042–045).
    - Employee financial details (pay type/rate/salary) now hidden from board_member + property_manager; board_admin and super_admin only.
    - Documents can now be attached directly to employee records and insurance records (board-only; quota-tracked).
    - Work order "Assigned to" dropdown includes active employees (not just board/management roles).
    - Work order status change optionally posts a resident announcement in one action.
    - Role renamed from `resident` → `owner` across DB (3-step ENUM migration) and all PHP files. 191 accounts migrated.
    - Staff role fully wired: ROLE_RANK, directory allowedRoles, role selects, role filter dropdown.
    - Configurable permissions dashboard at `/dashboard/permissions.php`. `can_do()` helper in auth.php. 9 configurable features. Minutes, work orders, violations now use `can_do()` for view gating.
    - Board meeting minutes page shipped (`/dashboard/minutes.php`).
    - Formal violation workflow shipped (`/dashboard/violations.php`) with print-ready letters.
    - Forgot-password IP rate limiting (5 req/IP/hour via `login_attempts` with `kind='password_reset'`).
    - Changelog updated with 7 new entries.

- **2026-05-11 to 2026-05-12 — Phase-2 feature sprint.**
    - Migrations 014 → 041 (28 migrations). Working tree clean at commit `a65d0fb`.
    - Bellair populated: 196 active members, 128 units, 159 rules, 21 FAQs, board officers including Director Scott Bender.
    - Tools added: Work Orders, Architectural Review, Employees, Insurance + COIs, per-unit/per-member documents, Forms library (14 types — guest registration mirrors the actual Bellair card), Electronic signatures (E-SIGN/UETA) with saved-signature library, Concerns with target person/unit/rule, Storage breakdown, Global search, ARC↔WO + Concern↔WO conversion, Activity log viewer, Funny 404 page, Public landing with map + meet-your-board.
    - Renter restrictions: can't file ARC, can't join committees, no Settings or Committees nav.
    - See `/Where we left off` section above for full state + next-session candidates. Email still paused (`config.mail.driver = log`).

- **2026-05-10 — Shipped to production at https://badasshoa.com.**
    - DB `u535581001_badassHOA` provisioned in hPanel; schema migrations 001 + 003–013 imported via SSH (skipped `002_seed_dev.sql` — prod starts clean)
    - Super admin `me@kevinleigh.com` seeded; bcrypt hash verified by `password_verify` round-trip on the server
    - `config.php` written directly on the server (mode 600, never in git)
    - Hostinger placeholder `default.php` removed; first rsync deployed all 200+ files into `~/domains/badasshoa.com/public_html/`
    - **Real SMTP wired.** `send_mail()` got new `mail` and `msmtp` driver branches in `includes/functions.php`. `~/.msmtprc` rebuilt from scratch to point at `smtp.hostinger.com:465` SSL with mailbox `success@badasshoa.com`. End-to-end probe through `send_mail()` returned `smtpstatus=250 Ok: queued`. Failed sends still fall through to `mail.log` so nothing is silently dropped.
    - **Deploy automation.** `push.sh` (laptop, rsync + ssh) and `deploy.sh` (server, rsync repo→public_html + opcache bust). One-command deploys from now on: `bash push.sh`.
    - **Discovery during deploy:** the old `~/.msmtprc` on this Hostinger account was pointing at `127.0.0.1:125` with stale auth — broken before today. Rewriting it to `smtp.hostinger.com:465` would have been required even if BadassHOA hadn't been the trigger. Old file backed up to `~/.msmtprc.bak.<timestamp>`.

- **2026-05-09 — Public community landing + events + map.**
    - Per-association public landing at `/{slug}/` with hero, about, amenities, photos, board (opt-in), documents, events, map, FAQ, announcements, contact form, social footer
    - `/dashboard/events.php` board CRUD with `all`/`members`/`board` audience filter; only public events surface on the landing
    - Photon geocoding helper (`includes/functions.php::geocode_address()`) wired to `/dashboard/settings.php`; OSM iframe renders on the landing when lat/lon set
    - **Bug fix:** geocode-on-save was only firing when address fields *changed*. Associations that already had an address but no stored coordinates never got geocoded and their map stayed hidden. Fixed in `dashboard/settings.php` — also re-geocodes when `latitude` or `longitude` is empty regardless of address change. Verified end-to-end on `/demo/`.
    - Public endpoints `/branding.php`, `/public-media.php`, `/public-document.php`, `/contact.php` (CSRF + honeypot) excluded from the slug-rewrite in `.htaccess`
    - Changelog system (JSON-backed) at `/changelog.php` + `/admin/changelog.php`

- **2026-05-09 — Phase 1.5: password reset flow.**
    - Added `/forgot.php` and `/reset.php` for the email-link reset flow
    - Added "Change your password" card to `/dashboard/settings.php` for already-authenticated users
    - **Fixed timezone drift bug discovered during smoke test:** MAMP's MySQL runs in the system TZ (EDT here) while PHP defaults to UTC. Stored TIMESTAMP values were being interpreted 4 hours off when compared via `strtotime()` vs `time()`. Fix: `includes/db.php` now pins every PDO connection to UTC (`SET time_zone = '+00:00'`), and `reset.php` does its expiry check in SQL (`NOW() > pr.expires_at`) rather than PHP. This is a once-and-done fix for the whole app — any future code that compares stored timestamps will work correctly because both sides agree on UTC.
    - Verified end-to-end: request reset → grab token from `mail.log` → consume → old password rejected → new password accepted → reused token rejected with "already been used" → invalid token rejected with "invalid".

- **2026-05-09 — Phase 1 build complete.**
    - DB: 11 tables migrated, 1 demo association + 2 demo users seeded
    - Foundation: PDO singleton, auth (CSRF + throttling + default-deny role check), header/footer, mailer stub
    - Design system: Stripe/Ramp-flavored tokens, base reset, full component library
    - Public site: marketing home, pricing with live calculator, 3-step signup
    - Dashboard: home + documents (with secure file gatekeeper) + rules/bylaws live search + directory + communications + media + settings
    - Admin: overview, associations (with signup-approve provisioning flow), users
    - End-to-end smoke test passed on `https://badasshoa.com:8890/`

- **2026-05-09** — Project kicked off. Initial spec received. CLAUDE.md created with locked decisions (path-based tenancy, full-scope Phase 1, Stripe-aesthetic, stubbed email). MAMP PRO environment verified end-to-end.
