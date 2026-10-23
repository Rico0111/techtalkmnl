# TechTalk Rollback Procedure

This package has **not** been deployed. Keep this procedure with the package and
the pre-deployment backups.

## A. Stop and preserve evidence

1. Do not delete logs, database dumps, or the current `public_html/` tree.
2. Record the failing URL, HTTP status, timestamp, and the relevant PHP error-log
   lines (redact credentials and session IDs).
3. If the site is actively broken, enable Hostinger's maintenance/error state or
   restore only the affected files; do not drop the database.

## B. File rollback (no database change)

1. Back up the current `public_html/` tree, including hidden files, to
   `backups/rollback-<timestamp>/`.
2. Restore the pre-deployment `public_html/` contents from the owner's backup.
3. Preserve `public_html/axa-kenmark/` and any other unrelated application
   directories unless the owner explicitly authorizes their removal.
4. Restore the previous root `.htaccess` if the deployment replaced/merged it.
5. Leave `private/config.local.php` in place only if it is still valid; otherwise
   restore the previous private configuration.
6. Clear any Hostinger/LiteSpeed page cache/CDN cache.
7. Re-test `/`, `/admin/login.php`, and the preserved sub-application.

## C. Database rollback

The release package contains schema-only files; it does not contain production
data.

- If a pre-deployment database backup exists and the owner authorizes a restore,
  restore that dump through hPanel/phpMyAdmin.
- `02-account-profiles.sql` is additive (`CREATE TABLE IF NOT EXISTS`). If it was
  applied and the application is rolled back to a version that does not use the
  table, the table may remain without harming older code. Drop it only with owner
  approval and only after a verified backup.
- Do **not** run `DROP DATABASE`, `TRUNCATE`, or destructive schema resets as a
  first response.
- If product data was changed during smoke testing, restore only the affected
  rows from the owner-approved backup; do not reset the whole database.

## D. Configuration rollback

If the database credentials are wrong or the production guard rejects the
connection:

1. Verify the hPanel DB host/name/user/password.
2. Check that `private/config.local.php` is readable by the PHP user and not
   inside `public_html/`.
3. Confirm `putenv('TECHTALK_ENV=production');` is present.
4. Check the Hostinger error log; the application logs DB failures without
   rendering credentials or stack traces.
5. Do not temporarily switch to root/empty credentials to "test" production.

## E. Image/catalog rollback

- Catalog files live in `public_html/assets/products/` and are served only by
  `product-image.php`.
- If the endpoint is unavailable, do not re-enable direct static access as a
  permanent fix; it would re-expose draft/archived images.
- Restore the previous `product-image.php` and templates together, or restore the
  pre-deployment tree from backup.

## F. Verification after rollback

- `/` returns 200 and the expected catalog.
- `/admin/login.php` returns 200 and login works.
- `/assets/products/<known-file>` returns 403.
- `/product-image.php?f=<known-published-file>` returns the image with
  `Content-Type: image/...`.
- The existing `axa-kenmark/` application (if any) still loads.
- No new PHP fatal errors appear in the Hostinger log.

## G. Rollback limitations

The release validation covered local root-domain HTTP/HTTPS simulation only.
Hostinger-specific TLS termination, LiteSpeed `.htaccess` behavior, PHP-FPM
`.user.ini` behavior, and the live database must be verified during the owner's
deployment window. This package does not include an automated production
rollback executor.
