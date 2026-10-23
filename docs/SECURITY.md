# TechTalk Security and Release Notes

## Confirmed local fixes in this release

| ID | Status | Change |
|---|---|---|
| O-02 | Fixed locally | Draft/archived catalog images are no longer directly fetchable. `assets/products/` denies direct HTTP; `product-image.php` serves published images publicly and requires an admin session for non-published images. |
| O-03 | Improved locally | PHP responses send `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, and a restrictive `frame-ancestors`/`base-uri`/`object-src`/`form-action` CSP. `X-Powered-By` is removed; production `.user.ini` sets `expose_php=Off` and `display_errors=Off`. Hostinger's own server banner and server-level headers remain host-controlled. |
| O-10 | Fixed in package | `index.html` is excluded and a legacy `/index.html` request redirects to `/`. |
| O-11 | Fixed locally | Product archive/delete actions use POST-redirect-GET with a one-time session flash. Refresh no longer re-submits; archiving an already archived product is a no-op notice. |
| Portability | Improved | Private config discovery outside `public_html`; production environment evaluated after config loading; HTTPS detection covers proxy headers; root-domain cookie path verified; cookie-path config constant honored. |

## Image endpoint controls

- 128-bit random server-generated filenames.
- Exact database lookup by `product_images.image_path`.
- Filename allowlist and thumbnail suffix allowlist.
- `product_image_path()` rejects traversal and symlinks.
- MIME is verified with `finfo`; only JPEG/PNG/WebP responses are emitted.
- Published images: `Cache-Control: public, max-age=0, must-revalidate` with an ETag; publication changes are checked on revalidation.
- Draft/archived images: authenticated admin only, `Cache-Control: private,
  no-store, max-age=0`.
- Missing/unauthorized private images return a generic 404, not a 403 that
  confirms existence.
- Direct access to `assets/products/` returns 403.

## Upload and storage controls (existing, revalidated)

- Server-side MIME/content inspection; extension is not trusted.
- Dimension/pixel/memory checks; GD re-encoding strips EXIF/GPS metadata.
- Exclusive file reservation; no overwrite of existing catalog files.
- Filenames generated with `random_bytes(16)`.
- Per-file independent commits with cleanup on failure.
- Avatars are stored under `storage/admin-avatars/` (direct HTTP denied) and
  served through `admin/avatar.php`.
- Executable file types are denied in the catalog directory and the directory is
  no longer directly readable.

## Authentication controls (existing, revalidated)

- Generic login failures, dummy password verification for unknown users.
- Row locks and transactions serialize failure/lockout counters.
- Six-session lockout behavior tested in Phase F-2.
- Session ID regeneration after login; 30-minute idle timeout.
- Password/identity rotation invalidates existing sessions via timestamp/hash
  signatures.
- CSRF tokens on all admin mutation forms.
- HttpOnly, SameSite=Strict, Secure-on-HTTPS session cookie.

## Residual risks and owner decisions

1. **No live Hostinger TLS test.** HTTPS/HSTS, certificate coverage for `www`,
   and proxy behavior must be validated during deployment. HSTS is intentionally
   not `preload` and no longer includes subdomains.
2. **No migration ledger.** Apply the two SQL files in order and record them in
   the owner's deployment log. This is documented, not automated.
3. **Product/audit atomicity is not crash-tested.** A process kill between the
   product write and audit write can leave an audit gap. Normal validation and
   Phase F-2 tests passed; a crash-injection test remains outstanding.
4. **Hostinger server-level disclosures** (Server banner, LiteSpeed/PHP tuning)
   are outside application control. Disable version disclosure in hPanel if the
   host exposes that option; otherwise accept the residual.
5. **Image endpoint cost.** Every catalog image request performs a small indexed
   lookup. Published responses carry an ETag and force revalidation, so a previous
   browser/CDN copy cannot remain authoritative after unpublication. If traffic grows,
   add a short-lived server cache keyed by filename/publication status.
6. **No native Safari/iOS or real-device touch test.** Phase F-2 covered Chrome
   desktop/mobile emulation and touch events; Safari-specific behavior remains
   for owner acceptance.
7. **Backups are owner-controlled.** The package does not configure Hostinger
   backups, retention, or off-site copies.
8. **No WAF/rate-limit configuration.** Application lockout and CSRF protections
   are present; host-level rate limiting is an operational choice.

## Sensitive-file handling

Excluded from the package:

- `.agent-handoff/`, audit reports, test fixtures, screenshots.
- `config.local.php` and any database dump.
- `includes/.installed` (local install lock).
- `admin/setup.php`, `admin/cli-user.php`, `admin/migrate-account.php`,
  `admin/diagnose-product-storage.php`.
- Raw review evidence (`assets/IMG_*`, `assets/full-reference-sheet*`).
- Private avatar files and session files.
- `index.html`, local source photos (`Pictures/`), raw `Logo.png`.
- Development documentation, backups, logs, temporary files.

The package includes only schema definitions, no database records or
administrator credentials.

## Reporting

When reporting an issue, include the URL, HTTP status, timestamp, and redacted
error-log context. Never include database passwords, session cookies, password
hashes, or customer data.
