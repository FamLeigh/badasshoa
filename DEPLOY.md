# BadassHOA — Hostinger Deploy Guide

This is a step-by-step walkthrough to deploy **badasshoa.com** to Hostinger using **Git deployment** through hPanel.

> **CLAUDE.md** is the project's living source of truth (decisions, status, gotchas).
> **This file** is the once-per-deploy operational runbook. Read end-to-end before you start.

---

## Pre-flight checklist (one-time)

You'll need:
- [ ] Hostinger account with the badasshoa.com site already provisioned (✓ done)
- [ ] A Git host for the code (GitHub / GitLab / Bitbucket — free private repo is fine, or Hostinger's own Git on Premium+)
- [ ] hPanel access for the badasshoa.com site
- [ ] About 30 minutes for the first deploy

You will **not** be running database migrations from your laptop. You'll run them inside hPanel's phpMyAdmin. That keeps DB creds off your machine.

---

## Step 1 — Create the production database in hPanel

1. hPanel → **Databases** → **MySQL Databases**
2. **Create New MySQL Database**:
   - Database name: `badasshoa` (Hostinger auto-prefixes with `u123456789_`, that's fine)
   - Username: `badasshoa` (also gets prefixed)
   - Password: generate a strong one — **save it in your password manager**, you'll paste it into `config.php` next
3. Note the full prefixed names. You'll see something like:
   - DB:    `u123456789_badasshoa`
   - User:  `u123456789_badasshoa`
   - Host:  `localhost` (Hostinger's MySQL is on the same machine — `localhost` works)

---

## Step 2 — Push the code to a Git remote

From this project (your laptop):

```bash
cd /Users/kevinbleigh/Sites/badasshoa
git status              # should show all files staged
git add -A
git commit -m "Phase 1.5: BadassHOA initial production-ready build"
```

Then create a private repo on GitHub (or GitLab/Bitbucket), e.g. `kevinbleigh/badasshoa`, and:

```bash
git remote add origin git@github.com:kevinbleigh/badasshoa.git
git branch -M main
git push -u origin main
```

> `config.php` is gitignored (real DB creds never leave your laptop). Hostinger gets its own `config.php` in step 5.

---

## Step 3 — Connect Hostinger to your Git repo

1. hPanel → **Advanced** → **Git** (or **Website** → **Git** depending on UI version)
2. **Create Repository**:
   - **Repository URL**: `https://github.com/kevinbleigh/badasshoa.git` (or SSH form if you've added Hostinger's SSH key to GitHub)
   - **Branch**: `main`
   - **Install path**: `/public_html` (or `/domains/badasshoa.com/public_html` depending on your plan layout)
3. Click **Create**. Hostinger pulls the repo and lays the files into the install path.
4. Verify: SSH or File Manager into the install path. You should see `index.php`, `dashboard/`, `admin/`, etc.

> **Auto-deploy on push:** Hostinger gives you a webhook URL. Add it as a webhook on your GitHub repo (Settings → Webhooks) so future `git push` triggers a deploy automatically.

---

## Step 4 — Verify directory permissions

`/storage/` must be writable by the web server. SSH (or File Manager → Permissions) into the install path:

```bash
chmod -R 755 storage
chmod 755 storage/uploads storage/logs
```

Files: 644. Directories: 755. Hostinger's defaults are usually correct, but verify after the first push.

---

## Step 5 — Create production `config.php` on the server

Via hPanel **File Manager** (or SFTP), create `/public_html/config.php` with this content:

```php
<?php
return [
    'env' => 'production',

    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=u123456789_badasshoa;charset=utf8mb4',
        'user' => 'u123456789_badasshoa',
        'pass' => 'YOUR_STRONG_DB_PASSWORD_FROM_STEP_1',
    ],

    'app' => [
        'base_url'    => 'https://badasshoa.com',
        'admin_email' => 'me@kevinleigh.com',
    ],

    'mail' => [
        'driver' => 'log',                       // 'smtp' once you wire it
        'from'   => 'noreply@badasshoa.com',
    ],
];
```

Replace the three `u123456789_*` placeholders with your real prefixed names from Step 1.

> ⚠️ **`config.php` lives only on the server, never in git.** If you ever wipe the install path, you'll need to recreate this file.

---

## Step 6 — Run the schema migration in phpMyAdmin

1. hPanel → **Databases** → **phpMyAdmin** (next to your database)
2. Click your database in the left sidebar
3. **SQL** tab → paste the contents of `migrations/001_schema.sql`, but **comment out the first three lines** (`CREATE DATABASE`, `USE`) — Hostinger created the DB for you and you're already inside it
4. Click **Go**. You should see all 11 tables created.

Verify in the **Structure** tab: `associations`, `users`, `documents`, `rules`, `announcements`, `media`, `signups`, `password_resets`, `audit_log`, `login_attempts`, `sessions`.

---

## Step 7 — Seed the production super admin

Still in phpMyAdmin, **SQL** tab. Paste exactly this — it creates **one** super admin user (you):

```sql
INSERT INTO users (association_id, first_name, last_name, email, password_hash, role, status)
VALUES (
  NULL, 'Kevin', 'Leigh', 'me@kevinleigh.com',
  '$2y$12$FLm/ygQTmyhBTptHevmUvu8ibvxh/rgJizQFe7Fy/oLYd6GQb0T2u',
  'super_admin', 'active'
);
```

The hash above is bcrypt(cost=12) of a one-time random password I generated for you — **see the chat where this file was created**. Log in once with that password, then **immediately change it** at `/dashboard/settings.php` (or actually at `/admin/` since you're a super admin — settings page is tenant-scoped and won't show for super admins; use the password reset flow at `/forgot.php` to set a memorable one).

> No demo association. No `board@demo.badasshoa.com`. Production starts clean.

---

## Step 8 — Confirm PHP version + extensions

hPanel → **Advanced** → **PHP Configuration**:
- **PHP version**: 8.1+ (the code uses `declare(strict_types=1)`, named arguments, `never` return type — needs PHP 8.0 minimum, prefer 8.1+)
- **Extensions enabled**: `pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo`. All are default on Hostinger.

---

## Step 9 — Confirm SSL is on

hPanel → **Security** → **SSL**. Ensure the free Let's Encrypt cert is **installed and active** for `badasshoa.com`. Toggle on **Force HTTPS** if available.

---

## Step 10 — Smoke test the live site

In a private/incognito browser window:

1. Visit `https://badasshoa.com/` — should show the marketing page (no MAMP placeholder)
2. Visit `https://badasshoa.com/login.php` — should show the sign-in form (and **no** demo-creds hint, because env=production)
3. Sign in with `me@kevinleigh.com` + the temporary password
4. You should land on `/admin/`. The dashboard for tenants (`/dashboard/`) won't apply to you — you're a super admin.
5. **Change your password immediately**: log out, click "Forgot password?", enter your email. Then read `/storage/logs/mail.log` on the server (via File Manager) to grab the reset link. Visit it and set a real password.
   - Or: hit `/dashboard/settings.php`'s change-password card after logging in to a tenant. (Super admin-specific password change UI is on the Phase 2 list.)

---

## Step 11 — Configure email (Phase 2 work, not blocking)

Right now `send_mail()` writes to `storage/logs/mail.log` instead of actually sending. That's why Step 10 told you to read the log file for the reset link. **Before launching to real users**, you must wire SMTP:

- Hostinger Email (free with the plan): create a mailbox `noreply@badasshoa.com` in hPanel → **Emails**, then in `config.php` set `'driver' => 'smtp'` and add SMTP host/port/user/pass.
- Or third-party: Resend / Postmark / SendGrid. They give you cleaner deliverability.
- Either way, you'll need to update `includes/functions.php`'s `send_mail()` to branch on `config()['mail']['driver']`. Currently it's `log`-only.

Once you flip this on, `/forgot.php` will actually send the email instead of logging it.

---

## Step 12 — Add Hostinger's webhook for auto-deploy

Future `git push` should trigger a deploy without you SSHing in:

1. hPanel → **Git** → your repo → **Webhook URL** (or **Pull on push** toggle)
2. Copy the webhook URL
3. GitHub repo → **Settings** → **Webhooks** → **Add webhook**:
   - Payload URL: paste from above
   - Content type: `application/json`
   - Events: **Just the push event**
   - Active: ✓

Now `git push origin main` from your laptop = live site updates within ~30 seconds.

---

## Day-2 operations

### Update production
```bash
# from your laptop
git add -A
git commit -m "Whatever you changed"
git push origin main
# Hostinger pulls automatically (if webhook is set up)
```

### View production logs
- App PHP errors: `/public_html/storage/logs/php-errors.log`
- Mailer log (until SMTP wired): `/public_html/storage/logs/mail.log`
- Apache access/error: hPanel → **Advanced** → **Error Logs**

### Take a database backup before any risky change
hPanel → **Databases** → your database → **Export** → save the SQL file locally. Do this before running any new migration.

### Run a new migration (when you add one)
1. Push the migration file to git (`migrations/004_whatever.sql`)
2. After Hostinger pulls, paste its contents into phpMyAdmin **SQL** tab and run

---

## Troubleshooting

**"Missing config.php" 500 error:** You forgot Step 5, or `config.php` got wiped. Recreate it.

**Login page works but submit returns 419 "Session expired":** Cookie `Secure` flag mismatch. Ensure HTTPS is forced (Step 9). The auth code reads `$_SERVER['HTTPS']` to decide.

**Reset email never arrives:** Mailer is still on `log` driver. Either grab the link from `storage/logs/mail.log` (Step 10), or finish Step 11.

**Database connection error:** Triple-check `config.php` matches the prefixed DB names from hPanel exactly. The user, the database, and the password all need to match.

**File upload fails with "Could not save file":** `/storage/uploads/` directory permissions. Re-run Step 4.

**"Access denied" trying to view `/admin/`:** You're not signed in as a `super_admin`. Sign out and sign in with the super admin email.
