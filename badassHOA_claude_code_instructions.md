# BadassHOA — Claude Code Build Instructions (Original Brief)

> **Note from Reboooot:** This is the original brief Kevin handed off. It's preserved here as a read-only reference. Anywhere this file disagrees with `CLAUDE.md`, **CLAUDE.md wins** — that's where our negotiated decisions live (e.g. MAMP PRO path, socket-based MySQL DSN, path-based tenancy, security additions).

## Project Identity

- **Domain**: badassHOA.com
- **Hosting**: Hostinger (live server)
- **Local Dev**: MAMP (localhost)
- **Project Folder**: Create at `/Applications/MAMP/htdocs/badassHOA/`  *(SUPERSEDED — actual: `/Users/kevinbleigh/Sites/badasshoa/`)*
- **Local URL**: `http://localhost:8888/badassHOA/`  *(SUPERSEDED — actual: `https://badasshoa.com:8890/`)*

---

## What We Are Building

A multi-tenant SaaS platform for condo and HOA associations. Each association that signs up gets their own subdomain *(Phase 1: path-based instead — see CLAUDE.md)*, board portal, and resident portal. We are building **Phase 1 MVP only** in this session.

---

## Tech Stack

| Layer | Choice |
|-------|--------|
| Frontend | HTML5, CSS3 (custom design system), Vanilla JavaScript |
| Backend | PHP 8.x |
| Database | MySQL (via MAMP) |
| File Storage | Local `/uploads/` folder *(Reboooot: stored OUTSIDE web root for security — see CLAUDE.md)* |
| Auth | PHP Sessions + bcrypt password hashing |
| Search | MySQL FULLTEXT search |
| CSS Framework | Custom (no Bootstrap — clean proprietary design) |

---

## Brand & Design Direction

- **Brand Name**: BadassHOA
- **Tone**: Professional but confident. Not stuffy. Modern SaaS energy.
- **Color Palette**:
  - Primary: Deep navy `#0f1f3d`
  - Accent: Bold orange `#f05a28`
  - Surface: Off-white `#f8f7f4`
  - Text: Dark charcoal `#1a1a2e`
  - Success: `#2e7d32`
  - Error: `#c0392b`
- **Fonts**: Load from Google Fonts
  - Display/Headings: `Syne` (bold, modern)
  - Body: `Inter` (clean, readable)
- **Logo**: Inline SVG — bold stylized "B" or shield with "BadassHOA" wordmark
- **Design Feel**: *(Spec said Linear/Vercel; superseded — Stripe / Ramp / Mercury per CLAUDE.md)*

---

## Database Schema (original spec)

The original spec's tables — `associations`, `users`, `documents`, `rules`, `announcements`, `media`, `signups` — are kept as-is. Reboooot is **adding** `password_resets`, `audit_log`, `login_attempts`, `sessions` (see CLAUDE.md → "Additions Reboooot is making"). Original SQL preserved below for reference.

```sql
CREATE DATABASE badassHOA CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE badassHOA;

-- Associations (each condo/HOA is a tenant)
CREATE TABLE associations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  subdomain VARCHAR(100) UNIQUE NOT NULL,
  address TEXT,
  unit_count INT DEFAULT 0,
  logo_path VARCHAR(500),
  primary_color VARCHAR(7) DEFAULT '#0f1f3d',
  plan ENUM('starter','growth','professional','enterprise') DEFAULT 'starter',
  status ENUM('active','inactive','trial') DEFAULT 'trial',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT,
  first_name VARCHAR(100),
  last_name VARCHAR(100),
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','board_admin','board_member','property_manager','resident','renter') DEFAULT 'resident',
  unit_number VARCHAR(20),
  phone VARCHAR(30),
  is_owner TINYINT(1) DEFAULT 1,
  status ENUM('active','inactive','pending') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (association_id) REFERENCES associations(id)
);

CREATE TABLE documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  category VARCHAR(100),
  file_path VARCHAR(500),
  file_type VARCHAR(50),
  access_level ENUM('public','members_only','board_only') DEFAULT 'members_only',
  uploaded_by INT,
  version VARCHAR(20) DEFAULT '1.0',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (association_id) REFERENCES associations(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

CREATE TABLE rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  category VARCHAR(100),
  source ENUM('bylaw','board_rule','policy') DEFAULT 'board_rule',
  rule_number VARCHAR(50),
  effective_date DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FULLTEXT KEY search_rules (title, body),
  FOREIGN KEY (association_id) REFERENCES associations(id)
);

CREATE TABLE announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  author_id INT,
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  type ENUM('general','emergency','event','maintenance') DEFAULT 'general',
  audience ENUM('all','owners','renters','board') DEFAULT 'all',
  send_email TINYINT(1) DEFAULT 0,
  published_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (association_id) REFERENCES associations(id),
  FOREIGN KEY (author_id) REFERENCES users(id)
);

CREATE TABLE media (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_id INT NOT NULL,
  uploaded_by INT,
  file_path VARCHAR(500) NOT NULL,
  file_name VARCHAR(255),
  file_type VARCHAR(50),
  caption TEXT,
  category VARCHAR(100),
  visibility ENUM('public','private') DEFAULT 'private',
  linked_type ENUM('general','work_order','violation','announcement') DEFAULT 'general',
  linked_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (association_id) REFERENCES associations(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

CREATE TABLE signups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  association_name VARCHAR(255),
  contact_name VARCHAR(255),
  contact_email VARCHAR(255),
  contact_phone VARCHAR(30),
  unit_count INT,
  plan_selected VARCHAR(50),
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## Phase 1 Pages (original list — all still in scope)

1. `index.php` — public marketing homepage (hero, features, pricing preview)
2. `pricing.php` — interactive unit-count calculator
   - ≤50 units: $29/mo flat
   - 51–150: $29 + ($1 × units)
   - 151–300: $29 + ($0.90 × units)
   - 300+: contact us
3. `signup.php` — 3-step (association info → admin contact → confirm)
4. `login.php` / `logout.php` — role-based redirect
5. `dashboard/index.php` — welcome banner, quick stats, recent announcements, quick actions
6. `dashboard/documents.php` — upload, filter, list, version control
7. `dashboard/search.php` — FULLTEXT live search of rules/bylaws
8. `dashboard/directory.php` — board members + resident roster
9. `dashboard/communications.php` — announcements
10. `dashboard/media.php` — public/private gallery, upload
11. `dashboard/settings.php` — association edits + invites
12. `admin/` — super-admin panel for all associations & users

---

## Auth & Role Rules (original — to be hardened)

```php
// Original spec — Reboooot is replacing requireRole() with a default-deny version. See CLAUDE.md → Security #4.
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

function requireRole($minRole) {
    $roles = ['renter'=>1,'resident'=>2,'property_manager'=>3,'board_member'=>4,'board_admin'=>5,'super_admin'=>6];
    if ($roles[$_SESSION['role']] < $roles[$minRole]) {
        http_response_code(403);
        die('Access denied.');
    }
}
```

---

## Key Design Rules from the brief

1. No Bootstrap or Tailwind — custom CSS using design tokens
2. All colors/fonts/spacing via CSS variables in `tokens.css`
3. Dark navy nav, orange accents, off-white content
4. Forms validated client-side **and** server-side
5. Uploads sanitized; *(Reboooot: stored outside web root)*
6. PDO prepared statements only — no raw SQL interpolation
7. Sessions checked on every protected page (`auth.php`)
8. Mobile responsive — hamburger nav on mobile
9. **No** payment processing in Phase 1
10. MAMP creds: user=`root` / pass=`root` / db=`badassHOA` *(Reboooot: socket-only — see CLAUDE.md for DSN)*

---

## Reminder Notes

- Domains to consider: heyhomeowner.com, heycondoowner.com, hatetenant.com
- Phase 1 → deploy to Hostinger via FTP or Git
- Phase 2: maintenance requests, violation tracking, digital signatures, amenity booking
- Phase 3: board voting, payment processing, mobile PWA
