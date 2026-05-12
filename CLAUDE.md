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

**Last session ended:** 2026-05-12 — **massive feature sprint shipped to prod**. Migrations through 041 (signatures library) all applied to `u535581001_badassHOA`. Bellair is now properly populated (196 active members, 128 units, 159 rules with 2022-12-01 effective date, 21 FAQs, 3 board officers including Scott Bender as Director). Last commit `a65d0fb`. Working tree clean.

**What got built in this sprint** (in rough chronological order):
- Concerns + parking + media metadata + units rebuild (parking_spots + primary owner + rental flag)
- Phone2/email2 + headshots + bios on users
- Committees: WYSIWYG, edit, join/leave, flyers, Make-Chair + Step-down buttons, collapsed descriptions
- Recurring events + day/week/month/upcoming print + clickable date-tile cards
- Public-landing fixes (board pending-status, geocode re-run)
- Documents: per-unit + per-member scope + storage breakdown
- Work Orders (admin-only ops tickets) + Concerns → WO conversion
- Architectural Review (ARC) requests + ARC → WO conversion
- Employees (orthogonal to role) with type-ahead picker
- Insurance + COIs with expiry warnings
- Storage quota tracking (1 GB free + $5/mo per extra GB)
- Global search box (rules + docs + announcements + events + concerns + ARC + members)
- Per-association branded landing + map + meet-your-board
- Concerns with target person/unit + rule citations
- Renters locked out of: ARC submit, committee join, Settings, Committees
- Forms library: 14 form types (guest registration matching the actual Bellair card, parking pass, maintenance, pet reg, vehicle reg, contractor notice, amenity reservation, hurricane checklist, emergency contact, move-in/out, key request, estoppel, other)
- Electronic signatures (E-SIGN / Florida UETA) with type/draw/upload + audit trail + SHA-256 tamper hash
- Saved signatures: private per-user reusable library (managers can't see them)
- Funny 404 page (HOA Violation Notice)
- Marketing home refresh

**Production state in DB:**
- 1 association: Bellair Condo Association (slug=bellair) at 420 N Atlantic Ave, Daytona Beach FL
- 196 active members; 128 units; 159 rules; 21 FAQs; 0 forms filed yet
- Board officers: Kevin Leigh (board_admin), Wayne Dictor (board_member), Mark Applegate (property_manager), Scott Bender (board_member · Director)
- Email driver still `log` (paused since 2026-05-10) — flip back to `msmtp` when ready

**Logins:**
- **Prod** super admin: `me@kevinleigh.com / bhoaK0m3r2.6`
- Local MAMP DB hasn't been touched in days — local schema is behind prod by migrations 014–041. If reviving local dev, run migrations 014 onward from `migrations/` in order.

**Quick visual check (prod):**
- Marketing home: https://badasshoa.com/
- Public landing: https://badasshoa.com/bellair/
- Dashboard: https://badasshoa.com/dashboard/
- Forms: https://badasshoa.com/dashboard/forms.php
- Latest changelog entry visible at the top of https://badasshoa.com/changelog.php

**Candidates for next session** (queued + roughly prioritized by where Kevin's headed):
1. **Lobby-TV digital signage / community board** — long-standing queued item. Probably a rotating-content TV mode that auto-cycles recent announcements + upcoming events + photos + emergency info on a building-lobby display. Auto-refresh, no-login URL with a token.
2. **Maintenance request → Work Order conversion** — same pattern as Concern → WO and ARC → WO, but on `maintenance_request` form submissions. Currently a maintenance form just sits as a submission; should land in the work orders queue.
3. **Turn email back on** — flip prod `config.mail.driver` from `log` to `msmtp` whenever Kevin says. Already wired; one line change in `~/domains/badasshoa.com/public_html/config.php` on the server.
4. **Board meeting minutes** — still a candidate from the earlier list; documents + announcements cover most of it but a dedicated minutes timeline view would be cleaner.
5. **Newsletter signup** on the public landing.
6. **Per-user TZ preference** — dashboard greeting + clock already use browser local time; everywhere else still UTC. Adding a TZ pref on profile + a single `local_date()` helper would clean up server-rendered timestamps.
7. **IP rate limiting on `/forgot.php`**.

**Important caveats / known gotchas:**
- E-signatures are E-SIGN/UETA-shaped but Kevin should have counsel review before relying on them for binding documents. The implementation captures everything the law requires (intent, consent, association, audit, tamper-detection); the disclosure language could be more formal (right-to-paper-copy, right-to-withdraw).
- The settings page's profile + landing forms used to both POST `form=update` to a single handler that overwrote every column — fixed via section markers. If you add a third form to settings.php, give it its own section value or you'll regress this.
- The pool FAQ (id 8) has Bellair-specific placeholder copy with `[VERIFY]` markers that may still be in the answer text — Kevin meant to refine but session ended before the update SQL ran. The full update is in `/tmp/bellair_faq_pool_update.sql` (laptop only, didn't ship) — was interrupted before he could approve the bigger rewrite from the actual paper pool-rules sign he sent.
- The first Bellair user form hasn't been filed yet — Kevin was going to file a temp parking pass to validate the flow but session ended.

---

## Changelog

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
