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
- **Live target:** `badasshoa.com` on Hostinger (blank site already provisioned). Claude does **not** have FTP/SSH credentials in this environment. Deployment is currently manual.

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

**Stubbed (works in dev, swap before production):**
- **Email** — `send_mail()` writes to `storage/logs/mail.log`. Wire SMTP (Hostinger / Resend / Postmark) before launch.
- **Production DB DSN** — `includes/db.php` hardcodes the MAMP socket. Replace before deploying to Hostinger (host/port/user/pass). NOTE: db.php now also pins every PDO connection to UTC via `SET time_zone = '+00:00'` — keep that in production too.
- **Subdomain-per-tenant routing** — deferred to Phase 2. Phase 1 is single-tenant-per-session via `association_id` from the `users` table; no slug in URL.

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
- Real SMTP wiring + email templates
- Hostinger deploy pipeline

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

**Last session ended:** 2026-05-09 — events + map shipped; geocode-on-save bug fixed.

**Demo state in DB right now:**
- Association `id=1, slug=demo` (Bellair Condo Association) → 420 N Atlantic Ave, Daytona Beach FL — geocoded to lat 29.2297 / lon −81.0090, map iframe renders.
- 3 seeded events: "Pool reopening party" (audience=`all`, public), "Annual HOA meeting" (audience=`members`, hidden from landing), "Closed board session" (audience=`board`, hidden).
- Association `id=3, slug=test` exists but has no address geocoded — its map won't render until someone hits Save on its settings.

**Demo logins (note Kevin renamed the board user):**
- Super admin: `me@kevinleigh.com / bhoaK0m3r2.6`
- Demo board: `me+bellair@kevinleigh.com / changeme!` (was `board@demo.badasshoa.com` before — references in old transcripts may use the old email)

**Quick visual check:** `https://badasshoa.com:8890/demo/` is the public landing, no login required.

**Candidates for next session** (in rough order of "user has been moving in this direction"):
1. **Board meeting minutes** — separate from announcements, stored as a list of past meetings with date + uploaded PDF + optional summary. Renders in a "Board meetings" section on the landing if any are public.
2. **Newsletter signup** — email-only opt-in form on the landing → `newsletter_subscribers(association_id, email, confirmed_at, unsubscribe_token)` table → CSV export for the board.
3. **Property history / building info card** — year built, number of buildings, HOA fee range, pet policy, etc. Structured fields in `associations` + a "Quick facts" section on the landing.
4. **Per-user TZ preference** on `/dashboard/settings.php` and a single display formatter — fixes the UTC-everywhere display polish item.
5. **IP rate limiting on `/forgot.php`** — small, low-risk, listed under "Phase 1.5 left over."

**Known small follow-ups not blocking anything:**
- See "Phase 1.5 left over" list above (CSV import, audit log viewer, image thumbnails, etc).

---

## Changelog

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
