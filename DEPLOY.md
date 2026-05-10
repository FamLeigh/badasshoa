# BadassHOA — Production Operations

This is the operational runbook for **https://badasshoa.com** on Hostinger. The first deploy happened on **2026-05-10**; this file covers day-2 operations from here on.

> CLAUDE.md is the project's living source of truth. This file is the runbook for pushing changes, running migrations, viewing logs, and recovering from problems.

---

## Production at a glance

| Thing | Value |
|---|---|
| Live URL | `https://badasshoa.com` |
| SSH | `ssh -p 65002 u535581001@77.37.59.82` |
| Repo dir on server | `~/badasshoa/` |
| Web dir on server | `~/domains/badasshoa.com/public_html/` |
| PHP | 8.2.30 |
| DB | MariaDB 11.8.6 — `u535581001_badassHOA` |
| DB user | `u535581001_kleigh` |
| Mail | msmtp → `smtp.hostinger.com:465` SSL, mailbox `success@badasshoa.com` |
| Server config file | `~/domains/badasshoa.com/public_html/config.php` (mode 600, not in git) |
| msmtp config | `~/.msmtprc` (mode 600, holds SMTP creds) |
| Super admin | `me@kevinleigh.com` |

---

## Push code changes

From the laptop, after editing locally:

```bash
cd /Users/kevinbleigh/Sites/badasshoa
bash push.sh
```

`push.sh` rsyncs the laptop repo → `~/badasshoa/` on the server, then runs `~/badasshoa/deploy.sh` over SSH which:
1. Removes the Hostinger `default.php` placeholder if present (no-op after first deploy)
2. Rsyncs `~/badasshoa/` → `~/domains/badasshoa.com/public_html/` with `--delete`
3. Excludes runtime-mutable paths (`config.php`, `storage/uploads/`, `storage/logs/`, `content/changelog.json`) so they survive the sync
4. Touches every `*.php` to bust PHP opcache

Both `push.sh` and `deploy.sh` are idempotent — safe to re-run anytime.

---

## Run a new database migration

Add the SQL file to `migrations/` locally (e.g. `migrations/014_meeting_minutes.sql`), then on prod:

```bash
# from the laptop
scp -P 65002 migrations/014_meeting_minutes.sql u535581001@77.37.59.82:/tmp/

# get the DB password (it lives only in the server's config.php)
ssh -p 65002 u535581001@77.37.59.82 \
  'grep "pass" ~/domains/badasshoa.com/public_html/config.php'

# run it
ssh -p 65002 u535581001@77.37.59.82 \
  'mysql -u u535581001_kleigh -p<DB_PASSWORD> u535581001_badassHOA < /tmp/014_meeting_minutes.sql'

# verify
ssh -p 65002 u535581001@77.37.59.82 \
  'mysql -u u535581001_kleigh -p<DB_PASSWORD> u535581001_badassHOA -e "SHOW TABLES;"'
```

> Always back up the prod DB before running a migration with `ALTER` or `DROP`. See "Backup the database" below.

---

## View production logs

```bash
ssh -p 65002 u535581001@77.37.59.82 'tail -50 ~/domains/badasshoa.com/public_html/storage/logs/php-errors.log'
ssh -p 65002 u535581001@77.37.59.82 'tail -50 ~/domains/badasshoa.com/public_html/storage/logs/mail.log'   # only populated if msmtp fails
ssh -p 65002 u535581001@77.37.59.82 'tail -50 ~/.msmtp.log'                                                # every send, success or fail
```

For Apache access/error logs: hPanel → **Advanced** → **Error Logs**.

---

## Change the production config

`config.php` lives only on the server. To edit:

```bash
ssh -p 65002 u535581001@77.37.59.82 'nano ~/domains/badasshoa.com/public_html/config.php'
```

After editing, no rebuild is needed — PHP picks up the new values on the next request.

The `mail.driver` knob is the most useful one to flip:
- `'log'`   → writes to `storage/logs/mail.log` (debugging, no actual sends)
- `'mail'`  → PHP `mail()` via `hsendmail` (no auth needed, but Hostinger silently drops most cross-domain mail this way)
- `'msmtp'` → real SMTP through `smtp.hostinger.com:465` using `~/.msmtprc` (production default)

---

## Rotate the SMTP password

If you change the `success@badasshoa.com` mailbox password in hPanel → Emails:

```bash
ssh -p 65002 u535581001@77.37.59.82 'cp ~/.msmtprc ~/.msmtprc.bak.$(date +%Y%m%d-%H%M%S) && nano ~/.msmtprc'
# update the `password` line, save, then:
ssh -p 65002 u535581001@77.37.59.82 'echo -e "From: success@badasshoa.com\r\nTo: me@kevinleigh.com\r\nSubject: msmtp test\r\n\r\nrotated" | /usr/bin/msmtp -t -f success@badasshoa.com; echo exit=$?'
# exit 0 = working
```

---

## Backup the database

Before any risky migration, or as a periodic precaution:

```bash
ssh -p 65002 u535581001@77.37.59.82 \
  'mysqldump -u u535581001_kleigh -p<DB_PASSWORD> u535581001_badassHOA | gzip > ~/badasshoa-backup-$(date +%Y%m%d-%H%M%S).sql.gz'

# pull the backup to the laptop
scp -P 65002 'u535581001@77.37.59.82:~/badasshoa-backup-*.sql.gz' /tmp/
```

Or via hPanel → **Databases** → **Export**.

---

## Smoke test after a deploy

```bash
for path in / /login.php /pricing.php /signup.php /forgot.php /changelog.php; do
  printf '%-20s ' "$path"
  curl -sS -o /dev/null -w 'HTTP %{http_code}\n' "https://badasshoa.com${path}"
done
printf '%-20s ' "/admin/"
curl -sS -o /dev/null -w 'HTTP %{http_code}  redirect=%{redirect_url}\n' "https://badasshoa.com/admin/"
```

All public paths should return 200; `/admin/` should redirect (302) to `/login.php` for an unauthenticated client.

---

## Troubleshooting

**500 on every page:**
```bash
ssh -p 65002 u535581001@77.37.59.82 'tail -30 ~/domains/badasshoa.com/public_html/storage/logs/php-errors.log'
```

**"Missing config.php" 500:** `config.php` got deleted from the server. Recreate it from `config.example.php` with the prod values (DB creds in your password manager, mail driver `msmtp`, etc.).

**Login redirects in a loop / "Session expired":** Cookie `Secure` flag mismatch — make sure the site is served over HTTPS (Hostinger forces this by default; check hPanel → Security → SSL).

**Password reset email never arrives:**
1. Check `~/.msmtp.log` on the server — `exitcode=EX_OK` and `smtpstatus=250` mean SMTP accepted the handoff.
2. If exitcode is non-zero, the mailbox password rotated — see "Rotate the SMTP password" above.
3. If the log shows EX_OK but the user got nothing, check their spam folder. Set up SPF/DKIM/DMARC on the badasshoa.com domain if this is a recurring issue.

**File upload "Could not save file":** `storage/uploads/` permissions or quota. Check:
```bash
ssh -p 65002 u535581001@77.37.59.82 'ls -ld ~/domains/badasshoa.com/public_html/storage ~/domains/badasshoa.com/public_html/storage/uploads; df -h ~'
```

**`/admin/` returns 403 instead of redirecting to login:** You're signed in but not as a `super_admin`. Sign out and sign in with the super admin email.

---

## What's intentionally not in this file

- **GitHub remote / git deploy.** We deploy by SSH+rsync, not via GitHub webhooks. Nothing is pushed to a public Git host. If we ever want a remote, add one to the laptop repo and adjust `push.sh` to `git push` first — but the current setup is simpler and was the right call for first deploy.
- **CI/CD pipelines.** None. Deploys are explicit and human-triggered.
- **HTML email templates.** `send_mail()` sends `text/plain`. Branded HTML versions are a Phase 2 polish item.

---

## First deploy (historical record)

For posterity, the steps that got us live on 2026-05-10:

1. Created `u535581001_badassHOA` DB + `u535581001_kleigh` user via hPanel → Databases.
2. Bundled `migrations/001_schema.sql + 003..013` (skipped `002_seed_dev`) into `/tmp/badasshoa_deploy_bundle.sql`, piped to `mysql` over SSH.
3. Seeded super admin via PHP CLI on the server (PDO + `password_hash(PASSWORD_DEFAULT)`).
4. Wrote `~/domains/badasshoa.com/public_html/config.php` directly on the server, mode 600.
5. First rsync from laptop → `~/badasshoa/`; ran `deploy.sh` to populate `public_html/`.
6. Wired msmtp: rebuilt `~/.msmtprc` from the broken state to point at `smtp.hostinger.com:465` SSL with mailbox `success@badasshoa.com`. Tested via CLI (`exit 0`, `smtpstatus=250`) and again through `send_mail()` (same result).
7. Smoke-tested all public URLs + admin redirect, confirmed no PHP errors in log.

The original DEPLOY.md described a GitHub-based git deploy via hPanel — we did not use that path. SSH+rsync proved simpler and matches the existing pattern from sellinglane / Prayersto on the same Hostinger account.
