# TechTalk Hostinger Architecture

Target: `https://techtalkmnl.com/` at the domain root.

## Hostinger directory layout

Upload the package so the domain directory looks like this:

```text
/home/<account>/domains/techtalkmnl.com/
├── public_html/                 # document root for https://techtalkmnl.com/
│   ├── index.php                # dynamic public homepage
│   ├── product-image.php        # authorized catalog image delivery
│   ├── admin/                   # authenticated admin workspace
│   ├── includes/                # PHP includes (direct HTTP access denied)
│   ├── assets/                  # CSS, JS, public artwork, catalog files
│   ├── storage/                 # private avatar storage (direct access denied)
│   ├── .htaccess
│   └── .user.ini                # production PHP settings
├── private/
│   └── config.local.php         # credentials; never web-accessible
├── database/                    # schema/migration SQL; never web-accessible
├── private-tools/               # CLI-only provisioning; never web-accessible
└── backups/                     # owner-created; never web-accessible
```

`public_html/` is the only web root. `private/`, `database/`, `private-tools/`
and `backups/` must remain siblings of `public_html/`, not children of it.
The application automatically checks `../private/config.local.php` relative to
`public_html/`.

## Existing applications

If `public_html/axa-kenmark/` (or any other application) already exists:

- Do not empty, rename, or delete `public_html/`.
- Do not overwrite the existing root `.htaccess` blindly. Back it up and merge
  the TechTalk rules that are missing.
- Upload TechTalk files alongside the existing application.
- Test the existing application after the upload, especially if the root
  `.htaccess` was changed.
- The TechTalk legacy-homepage redirect only matches `index.html` inside the
  directory tree where the rule is placed; it does not rewrite another
  application's PHP entry point.
- The application emits its security headers from PHP rather than global
  `.htaccess` `Header` directives, specifically to avoid imposing TechTalk
  policy on sibling applications.

## Request routing

| URL | Handler | Notes |
|---|---|---|
| `/` | `index.php` | `DirectoryIndex index.php`; published catalog only |
| `/index.html` | no file in package | Root `.htaccess` redirects legacy requests to `/` |
| `/admin/login.php` | `admin/login.php` | Session cookie path `/` at root deployment |
| `/admin/index.php` | admin dashboard | Requires authenticated session |
| `/admin/products.php` | product list | Archive/delete use POST-redirect-GET |
| `/admin/product_edit.php` | create/edit + uploads | GD-sanitized images |
| `/admin/account.php` | Account Settings | Profile, identity, password, preferences, avatar |
| `/product-image.php?f=<hash>.<ext>` | image endpoint | Published: public; draft/archived: admin only |
| `/assets/...` | static assets | CSS/JS/logo/meetup photos |
| `/assets/products/...` | denied | Files are served only through `product-image.php` |
| `/storage/...` | denied | Private avatar storage |
| `/includes/...` | denied | PHP internals |
| `/admin/favicon.php` | favicon loader | Uses `assets/techtalk-logo-opt.png` |

## Catalog image privacy

Product files remain in `public_html/assets/products/` for filesystem
compatibility, but that directory denies all direct HTTP access. Every catalog
image is delivered by `product-image.php`, which:

1. accepts only a 32-hex filename plus a known image extension/thumbnail suffix;
2. looks up the exact `product_images.image_path`;
3. serves `PUBLISHED` product images to anyone with the URL;
4. requires a valid admin session for `DRAFT`/`ARCHIVED` images;
5. denies traversal, symlinks, unknown files and non-image MIME types;
6. applies long-lived public caching only to published images; private images
   are `no-store`.

Published catalog image URLs therefore remain shareable, while unpublication
takes effect immediately for the catalog and for direct endpoint requests.

## Session and cookie behavior

`get_admin_cookie_path()` uses only the server-mapped `SCRIPT_NAME`, not the
client `Host` header. Root deployment resolves to `/`; local development under
`/techtok/` resolves to `/techtok/`. `TECHTALK_COOKIE_PATH` (environment or
config constant) can override it.

Cookies are `HttpOnly`, `SameSite=Strict`, and `Secure` whenever HTTPS is
detected (`HTTPS`, port 443, or a proxy `X-Forwarded-Proto: https`). The
production `.user.ini` also forces `session.cookie_secure=1`.

## Database schema inventory

| Object | Source | Data included in package? |
|---|---|---|
| `admin_users` | `database/01-core-schema.sql` | No |
| `categories` | `database/01-core-schema.sql` | No |
| `products` | `database/01-core-schema.sql` | No |
| `product_images` | `database/01-core-schema.sql` | No |
| `product_audit_log` | `database/01-core-schema.sql` | No |
| `admin_account_profiles` | `database/02-account-profiles.sql` | No |

There is no migration ledger table. Apply migrations in filename order and
record the applied filename/date in the owner's deployment log. See
`database/README.md`.

## Runtime requirements

- PHP 8.0 or newer; PHP 8.2 or 8.3 recommended.
- Extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `session`, `json`,
  `openssl`.
- GD WebP support is required for WebP uploads. Without it, the application
  accepts JPEG/PNG and rejects WebP with a clear user-facing message; it never
  assumes macOS `sips` exists. The macOS `sips` fallback is only used locally on
  Darwin.
- MySQL/MariaDB with `utf8mb4`; InnoDB.
- Apache/LiteSpeed with `.htaccess` support (mod_rewrite for the legacy redirect,
  `Require`/`FilesMatch` for access control).

## Configuration discovery order

1. `TECHTALK_CONFIG_FILE` environment variable.
2. `../private/config.local.php` (recommended Hostinger location).
3. `config.local.php` in `public_html/` (local-development compatibility only).

Production must set `putenv('TECHTALK_ENV=production')` in the private config.
The DB layer refuses root/empty credentials in production.
