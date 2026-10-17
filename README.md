# Police Database Security System

A secure, role-based police records management system built as a final-year project UNIZIK Computer Science. It manages crimes, suspects, victims, cases, evidence, and officers with an emphasis on **data protection, accountability, and OWASP Top 10 (2021) hardening**.

**Stack:** PHP 8.2 (OOP + PDO) · Bootstrap 5.3 · MariaDB 10.4 (XAMPP) · No framework, no Composer
**Methodology:** OOADM · **Accessibility:** WCAG 2.1 AA target

---

## Table of Contents

- [Key Features](#key-features)
- [Tech Stack](#tech-stack)
- [Quick Start (XAMPP / Windows)](#quick-start-xampp--windows)
- [Secrets & First Admin](#secrets--first-admin)
- [Roles & Permissions](#roles--permissions)
- [Database Schema](#database-schema)
- [Project Structure](#project-structure)
- [Objectives Coverage (Chapters 1 & 3)](#objectives-coverage-chapters-1--3)
- [OWASP Top 10 (2021) Mapping](#owasp-top-10-2021-mapping)
- [Accessibility (WCAG 2.1 AA)](#accessibility-wcag-21-aa)
- [Demo Data](#demo-data)
- [Production Deployment](#production-deployment)
- [Backup & Recovery](#backup--recovery)

---

## Key Features

- **Full CRUD** for crimes, suspects, victims, cases, evidence, and officers — PDO prepared statements only
- **Authentication:** Argon2id (bcrypt fallback) hashing, force password reset, progressive lockout, session regeneration, optional **TOTP 2FA**
- **Role-based access control:** 4 roles with a deny-by-default permission matrix; investigating officers see only assigned cases
- **Field-level encryption:** AES-256-GCM for suspect ID/contact and officer contact data (unique IV + tag per record)
- **Audit trail:** every login, logout, CRUD action, role change, and export is logged — no delete UI for audit records
- **Search & reports:** multi-field search, filtered printable reports, audited CSV export
- **Backup tooling:** mysqldump-based backup with restore documentation
- **Hardened uploads:** extension + MIME allowlist, 2 MB cap, random names, GD re-encode (strips EXIF), no-execute directory

## Tech Stack

| Layer | Choice |
|---|---|
| Language | PHP 8.2 (strict types, OOP) |
| Database | MariaDB 10.4 / InnoDB / utf8mb4 (XAMPP, port 3307) |
| Data access | PDO, prepared statements, emulation off |
| Frontend | Bootstrap 5.3.3 (CDN + SRI), custom CSS/JS |
| Secrets | Custom `.env` loader — no Composer dependency |
| Server | Apache (XAMPP) or PHP built-in dev server |

## Quick Start (XAMPP / Windows)

1. **Install XAMPP** with PHP 8.2 + MariaDB. Note your MySQL port (`C:\xampp\mysql\bin\my.ini` — this build uses `3307`) and root password (`C:\xampp\phpMyAdmin\config.inc.php`).

2. **Copy this folder** to `C:\xampp\htdocs\finalyearproject\Joshua\police_db\`.

3. **Configure secrets + schema:**

   ```bat
   copy config\.env.example config\.env
   C:\xampp\mysql\bin\mysql.exe -u root -p --port=3307 -h 127.0.0.1 < schema.sql
   C:\xampp\php\php.exe docs\create_admin.php admin "System Administrator" 1
   C:\xampp\php\php.exe docs\seed_demo.php
   ```

   `config/.env` must match your port/user. The app connects as the least-privilege `police_app` user — **never root**.

4. **Serve.** If `http://localhost/finalyearproject/...` 404s (Apache vhost mismatch), either add an Apache `Alias /police_db "C:/xampp/htdocs/finalyearproject/Joshua/police_db"` or run the built-in server:

   ```bat
   C:\xampp\php\php.exe -S 127.0.0.1:8081 -t C:\xampp\htdocs\finalyearproject\Joshua\police_db
   ```

   Open `http://127.0.0.1:8081/login.php`.

## Secrets & First Admin

**No passwords or keys are stored in source.**

1. Copy the template and generate real secrets (never commit `config/.env`):

   ```bat
   copy config\.env.example config\.env
   C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(18)), PHP_EOL;"
   C:\xampp\php\php.exe -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
   ```

   Put the DB password in `DB_PASS` (same value used when creating `police_app` below) and the base64 key in `ENC_KEY_B64`.

2. Create the least-privilege DB user (replace `<same-as-DB_PASS>`):

   ```sql
   CREATE USER IF NOT EXISTS 'police_app'@'localhost' IDENTIFIED BY '<same-as-DB_PASS>';
   CREATE USER IF NOT EXISTS 'police_app'@'127.0.0.1' IDENTIFIED BY '<same-as-DB_PASS>';
   GRANT SELECT, INSERT, UPDATE, DELETE ON police_db_security.* TO 'police_app'@'localhost';
   GRANT SELECT, INSERT, UPDATE, DELETE ON police_db_security.* TO 'police_app'@'127.0.0.1';
   FLUSH PRIVILEGES;
   ```

3. Create the first administrator (temp password printed **once**, forced change on first login):

   ```bat
   C:\xampp\php\php.exe docs\create_admin.php admin "System Administrator" 1
   ```

**Password policy:** min 10 chars, upper + lower + number, common-password reject.
**Lockout:** progressive delay 1s/2s/4s… capped at 5s; hard lock after 10 failures / 15 min.

## Roles & Permissions

| ID | Role | Access |
|---|---|---|
| 1 | Administrator | Full access incl. users, audit logs, backup |
| 2 | Records Officer | Record CRUD; blocked from evidence/investigation edits |
| 3 | Investigating Officer | **Assigned cases only** (via `users.officer_id`) |
| 4 | Supervisor | Read-only users + audit review |

Permission matrix lives in `includes/security.php` (`PERMISSIONS`), enforced by `require_login()` / `require_perm()` / `can()` on every page.

## Database Schema

Database `police_db_security` (InnoDB, utf8mb4) — 15 tables:

```
roles · users · officers · login_history
crimes · suspects · victims · cases · case_suspects · case_events
evidence · delete_requests · audit_log · backup_logs
```

Full DDL in [`schema.sql`](schema.sql).

## Project Structure

```
config/
  env.php             .env loader (no Composer)
  .env.example        secret template (copy to .env)
  db.php              PDO (env-driven) + ENC key from env
includes/
  security.php        session / CSRF / RBAC / policy / lockout / audit
  crypto.php          AES-256-GCM (ENC1: payloads)
  crud.php            IDs, whitelists, upload, assigned-checks
  header.php          secure headers + topbar
  sidebar.php         nav + Search
  footer.php          footer + timeout modal
assets/
  css/custom.css      navy/blue theme, focus, responsive, print-safe
  js/app.js           focus + timeout warning timers
docs/
  BACKUP.md           backup/restore runbook
  backup_run.php      CLI backup (refuses web SAPI)
  create_admin.php    CLI first-admin tool
  seed_demo.php       idempotent demo data
login.php  logout.php  change_password.php  verify_2fa.php
dashboard.php  users.php  officers.php
crimes.php  suspects.php  victims.php  cases.php  evidence.php
search.php  reports.php  audit_logs.php  backup.php  delete_requests.php
403.php  404.php  schema.sql
uploads/suspects/     (+ no-execute .htaccess)
```

## Objectives Coverage (Chapters 1 & 3)

| Objective | Where |
|---|---|
| Controlled record create/store/retrieve/update | CRUD pages, PDO prepared statements, validation |
| Authentication + password protection | `login.php`, BCRYPT/Argon2id, force reset, lockout, session regenerate |
| Role-based access control | `PERMISSIONS` matrix; IO assigned-only; Records blocked from evidence; Supervisor read-only |
| Data protection / encryption | AES-256-GCM (`includes/crypto.php`), TLS-ready headers/HSTS |
| Audit + accountability | `audit_log` on auth + CRUD + export; `audit_logs.php`; `login_history`; no delete UI |
| Backup & recovery | `backup.php` + `docs/BACKUP.md` (mysqldump, uploads copy, restore test) |
| Search / reporting | `search.php`, `reports.php` (filters + print + audited CSV) |
| Defence in depth | validation + RBAC + sessions + CSRF + headers + least-privilege DB user + upload hardening |

## OWASP Top 10 (2021) Mapping

| # | Risk | Implementation |
|---|---|---|
| A01 | Broken Access Control | `require_login/require_perm/can()` on every page; deny-by-default; IO assigned-only; IDOR checked on edit; 403 page |
| A02 | Cryptographic Failures | Argon2id/BCRYPT; AES-256-GCM (not CBC) with per-record IV+tag; secure cookies (`HttpOnly`, `Secure` on HTTPS, `SameSite=Lax`); no plain secrets |
| A03 | Injection | PDO prepared statements only (emulation off); whitelist enums; `htmlspecialchars` via `e()`; bound LIKE params |
| A04 | Insecure Design | Password policy; progressive-delay + hard lockout (anti-enumeration generic errors); force reset; assigned-only business rules |
| A05 | Misconfiguration | `display_errors off` (code + htaccess); no phpinfo/debug; secure headers; `Options -Indexes`; least-privilege `police_app`; 404 page |
| A06 | Vulnerable Components | PHP 8.2, MariaDB 10.4, Bootstrap 5.3.3 CDN with SRI; versions documented |
| A07 | Auth Failures | `session_regenerate_id`, 15-min inactivity + warning modal, logout destroys session, no URL sessions |
| A08 | Integrity Failures | CSRF token on all POST; no `unserialize`; uploads: ext+MIME allowlist, 2MB, random names, GD re-encode, no-execute `.htaccess` |
| A09 | Logging Failures | Audit every auth + CRUD + export with user/IP/time; supervisor review; `login_history`; no audit delete |
| A10 | SSRF | No server-side URL fetch; CSV generated locally; no user-controlled redirects |

## Accessibility (WCAG 2.1 AA)

Skip-link, landmarks, one `h1`/page, labels + `fieldset/legend`, `:focus-visible`, 4.5:1 palette, keyboard-only flow, `role="alert"` errors, captioned tables with `th scope`, `alertdialog` timeout modal, `rem` sizing + reduced-motion support. Verify with a keyboard-only pass + axe DevTools.

## Demo Data

`docs/seed_demo.php` is idempotent and seeds:

- 5 crimes (`DEMO_CR1`–`DEMO_CR5`)
- 3 suspects (`DEMO_SP1`–`DEMO_SP3`, encrypted contacts)
- 2 cases (`PD/26/101`–`102` on `OFF001`)
- 2 evidence rows

```bat
C:\xampp\php\php.exe docs\seed_demo.php
```

## Production Deployment

This build is hardened for production, not only coursework defence.

### Secrets outside the webroot

1. Move secrets out of the app folder (e.g. `C:\ProgramData\police_db\.env`) and delete `config\.env`.
2. Point the app at it (Apache vhost):

   ```apache
   SetEnv POLICE_ENV_FILE "C:/ProgramData/police_db/.env"
   ```

   Or place the file at `<parent-of-app>/.police_db.env` (auto-detected).
3. Set `APP_ENV=production`, `FORCE_HTTPS=1`, `APP_DEBUG=0`. Terminate TLS at Apache or a reverse proxy; PHP force-redirects HTTP→HTTPS and sends HSTS (`X-Forwarded-Proto: https` supported).

### Enforced in production

| Control | Where |
|---|---|
| HTTPS redirect + HSTS | `security.php` — `force_https_redirect()` / `send_security_headers()` |
| Content-Security-Policy (nonce) | same; inline scripts use `nonce=` |
| Per-account lockout (10 fails / 15 min) | `login.php` + `users` table |
| Per user+IP throttle (5 fails / 5 min) | `user_ip_throttled()` via `login_history` |
| Global per-IP throttle (20 fails / 5 min) | `ip_login_throttled()` |
| Argon2id hashing (bcrypt fallback) | `password_hash_secure()` |
| Custom session name `PDBSESS`, HttpOnly, SameSite=Lax, Secure | `ensure_session()` |
| TOTP 2FA with 5-minute pending expiry | `verify_2fa.php` |
| `config/`, `docs/`, `logs/` blocked from HTTP | per-directory `.htaccess` |
| CLI tools refuse web SAPI | `docs/*.php` guards |
| App errors to `logs/app.log` (never displayed) | `app_log()`; `display_errors=0` |

### Go-live checklist

- [ ] `config/.env` deleted from webroot; secrets only via `POLICE_ENV_FILE` or parent file
- [ ] Fresh `DB_PASS` + `ENC_KEY_B64` (re-encrypt data before rotating ENC key)
- [ ] `police_app` user: SELECT/INSERT/UPDATE/DELETE only; both `localhost` and `127.0.0.1` hosts
- [ ] HTTPS only; HSTS active; HTTP redirects 301
- [ ] `APP_ENV=production`, `APP_DEBUG=0`
- [ ] Admin + staff accounts via `create_admin.php`; no demo accounts
- [ ] Scheduled backups + tested restore
- [ ] PHP production.ini: `expose_php=Off`, `session.cookie_secure=1` recommended
- [ ] Server firewall: only 443 (and 22/3389 for ops); MySQL not internet-facing

## Backup & Recovery

```bat
C:\xampp\php\php.exe docs\backup_run.php
```

- Uses least-privilege DB creds (not root)
- Dumps go outside the webroot (`../backups`)
- Schedule with Windows Task Scheduler; log restores in the **Backup** UI
- Full runbook: [`docs/BACKUP.md`](docs/BACKUP.md)

---

**Author:** Igboke Joshua — Department of Computer Science, UNIZIK
**Academic project:** *Design and Implementation of a Police Data Base Security System*
