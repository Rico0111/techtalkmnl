# TechTalk Hostinger Deployment Guide

Target: `https://techtalkmnl.com/` (domain root).

This package was prepared and validated locally. **Nothing has been deployed to
Hostinger, DNS, or the production database.** The owner performs these steps
only after independent review.

## 0. Before touching Hostinger

1. Export/download a full backup of the existing `public_html/` tree, including
   hidden files, into the owner's `backups/` area.
2. Export the existing production database through hPanel/phpMyAdmin.
3. Record the current PHP version, document root, `.htaccess` contents, and all
   existing directories under `public_html/`.
4. Confirm whether `public_html/axa-kenmark/` or another application exists.
   Do not empty `public_html/` and do not delete another application.
5. Keep a copy of the current root `.htaccess`; merge rules instead of replacing
   it if another application depends on it.

## 1. Create the database and user

In hPanel → Databases:

- Create a MySQL database (for example `techtalkmnl`).
- Create a dedicated non-root database user with a strong password.
- Grant that user privileges only on the TechTalk database.
- Record the exact host, database name, username and password shown by hPanel.
  Hostinger commonly uses `localhost`, but use the value actually shown.

### Option A — import the owner's existing TechTalk data (recommended)

Import the owner-approved TechTalk database dump through phpMyAdmin. Do not
import any AXA-Kenneth dump. The imported database already contains the six
tables and the existing catalog/administrator records.

Then apply the additive account migration only if the imported dump predates it:
open/import `02-account-profiles.sql` in phpMyAdmin. The script is idempotent;
inspect it first and run it only once per database.

### Option B — fresh installation

Through phpMyAdmin, in this order:

1. Run `01-core-schema.sql` (tables only; no records).
2. Run `02-account-profiles.sql`.
3. Create the reference category rows the owner wants to use. The current site
   taxonomy is: `Laptops`, `MacBooks`, `Accessories`, `Gadgets` (unique slugs
   `laptops`, `macbooks`, `accessories`, `gadgets`). Keep the owner's existing
   taxonomy if importing data.
4. Provision the first administrator using Step 3 below.

No SQL in this package inserts an administrator, password, product, image or
audit record.

## 2. Place private files and configuration

Upload the package's `private/` directory to the domain directory, as a sibling
of `public_html/`:

```text
/home/<account>/domains/techtalkmnl.com/private/
```

Copy the template and edit it with hPanel File Manager or SSH:

```bash
cp private/config.local.example.php private/config.local.php
chmod 600 private/config.local.php
```

Set `TECHTALK_DB_HOST`, `TECHTALK_DB_PORT`, `TECHTALK_DB_NAME`,
`TECHTALK_DB_USER`, and `TECHTALK_DB_PASS` to the hPanel values. Keep
`putenv('TECHTALK_ENV=production');` enabled. The application refuses empty or
root production credentials.

Do **not** put `config.local.php` inside `public_html/`. If the host cannot use
a sibling directory, the legacy `public_html/config.local.php` path is supported
but is less isolated; the package's `.htaccess` denies direct access to it.

## 3. Provision the first administrator

Only needed for a fresh database. If the owner's existing database was imported,
skip this step and use the existing admin account.

With SSH (recommended):

```bash
cd /home/<account>/domains/techtalkmnl.com
php private-tools/create-admin.php <username>
```

The password is prompted without echo. It must be 12–72 bytes with uppercase,
lowercase and a number. The tool creates or (with `--rotate`) rotates a password
and writes an audit event.

Without SSH, on a trusted local PHP 8 machine:

```bash
php private-tools/password-hash.php <username>
```

Copy only the printed bcrypt hash into phpMyAdmin:

```sql
INSERT INTO admin_users (username, password_hash, created_at, updated_at)
VALUES ('your_username', '<printed hash>', NOW(), NOW());
```

Do not paste the plaintext password into SQL, chat, tickets, or shell history.

## 4. Upload the public application

Upload the **contents of `public_html/`** so they land directly in the domain's
`public_html/`, not in a subfolder. Use File Manager "upload and extract" or
SFTP.

If another application already exists:

- Upload TechTalk files alongside it; do not clear the directory.
- Preserve `axa-kenmark/` and any other subdirectories.
- Merge `.htaccess` rules rather than replacing a root file that serves the
  other application.
- The package `.htaccess` denies direct access to hidden files, SQL/backup/log
  files, `includes/`, `storage/`, and `assets/products/`, and redirects a legacy
  root `index.html` to the directory index.

After upload, create/verify writable directories:

```text
public_html/assets/products/                 755 (or 775 if the PHP user needs group write)
public_html/storage/admin-avatars/           755 (or 775)
```

Keep `private/config.local.php` at mode `600`. Do not make it world-readable.

## 5. PHP and TLS settings

In hPanel → PHP Configuration:

- Select PHP 8.2 or 8.3 (8.0+ is the code minimum).
- Ensure `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `session`, `json`, `openssl`.
- Enable GD WebP support if the owner needs WebP uploads. Without it, JPEG/PNG
  still work and WebP is rejected with a clear message.
- Confirm `.user.ini` is honored. It sets `expose_php=Off`,
  `display_errors=Off`, `log_errors=On`, strict sessions, Secure/HttpOnly/
  SameSite cookies, and upload limits.

In hPanel → SSL:

- Install/activate the certificate for `techtalkmnl.com` and `www` as needed.
- Enable the host's **Force HTTPS** option.
- Test `http://techtalkmnl.com/` redirects to HTTPS before announcing the site.
- HSTS is emitted by PHP only when an HTTPS request is detected, with a
  one-year max-age and no `includeSubDomains`. Do not add `preload` until the
  owner has confirmed every subdomain is HTTPS-only.

## 6. Smoke test after upload

Use a private browser session and verify:

1. `https://techtalkmnl.com/` returns the dynamic homepage and product catalog.
2. `https://techtalkmnl.com/index.html` redirects to `/` (no stale static page).
3. CSS, JS, logo, favicon, and meetup imagery load without 404s.
4. Published product thumbnails and gallery images load through
   `/product-image.php`.
5. A direct URL under `/assets/products/` returns 403.
6. `/admin/login.php` loads; login succeeds; the session cookie path is `/` and
   the cookie is `Secure`, `HttpOnly`, `SameSite=Strict`.
7. Admin dashboard, products, product edit, account settings, and audit log load.
8. Draft/archived product image URLs return 404 when logged out; the same image
   is visible while logged in as an administrator.
9. Archive/delete a disposable test record (or use an isolated staging database):
   the response is `302` to `products.php`, and a refresh does not repeat the
   action.
10. Response headers include `X-Content-Type-Options`, `Referrer-Policy`,
    `X-Frame-Options`, `Permissions-Policy`, `Content-Security-Policy`, and
    `Strict-Transport-Security` (on HTTPS). `X-Powered-By` is absent.
11. `public_html/axa-kenmark/` (if present) still works.
12. The error log contains no PHP warnings/fatals after these requests.

## 7. Rollback

See `ROLLBACK.md`. Never drop a production table or delete another
application as part of a TechTalk rollback without explicit owner approval.

## 8. Package integrity

Verify the archive before upload:

```bash
shasum -a 256 techtalkmnl-20261022.zip
unzip -t techtalkmnl-20261022.zip
```

Compare the result with `checksums.sha256` supplied with the package. Verify
individual files against `MANIFEST.sha256`.
