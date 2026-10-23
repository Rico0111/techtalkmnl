# TechTalk

TechTalk is a PHP website and administrator workspace for a buy, sell and trade business. The public site provides a published product catalog, testimonials, meetup locations and contact links.

## Stack and features

PHP 8.2+, MySQL/MariaDB (InnoDB, utf8mb4), Apache/XAMPP, vanilla JavaScript and CSS. No package installation or frontend build is required. PHP extensions: pdo_mysql, mbstring, fileinfo, gd, session and json.

The administrator workspace supports product editing, image uploads, publication states, audit history and account preferences. Authentication uses hashed passwords, CSRF protection, lockout and session invalidation. Product images pass through an authorization-aware endpoint; private avatars are not public static assets. The public interface includes keyboard controls and reduced-motion support.

## Local XAMPP setup

1. Place the checkout under XAMPP htdocs, for example `htdocs/techtok`.
2. Start Apache and MySQL. Enable the required PHP extensions and Apache `.htaccess` access controls.
3. Copy `config.local.example.php` to `config.local.php`; fill in your own local database settings. This actual configuration is ignored by Git. Alternatively supply `TECHTALK_DB_HOST`, `TECHTALK_DB_PORT`, `TECHTALK_DB_NAME`, `TECHTALK_DB_USER` and `TECHTALK_DB_PASS` through the environment. `TECHTALK_CONFIG_FILE` selects a private config file.
4. Create a dedicated empty development database. With separate authorization, apply `database/01-core-schema.sql`, then `database/02-account-profiles.sql`. These files contain schema only; create the required category rows separately. Never import these into an existing business database without review and a backup.
5. Provision your administrator locally using `php admin/cli-user.php <your-username>` and its hidden password prompt. Do not supply passwords as command arguments. No default account or password is shipped. Use a strong unique password. Do not expose `admin/setup.php` on a public server; production packaging omits setup, diagnostics and local CLI utilities.
6. Visit `http://localhost/techtok/` and `http://localhost/techtok/admin/login.php`.

A fresh checkout deliberately contains no inventory records, uploaded product images, private avatars or installation state. Existing local data remains on the owner's machine. `index.html`, `README.txt`, `REVIEWS-SETUP.txt` and `START-HERE.txt` preserve historical source/guidance; the PHP homepage and this README describe the current application.

## Migrations

See [database/README.md](database/README.md). The dated migration in `migrations/` is the same account-profile schema as `database/02-account-profiles.sql`; it is not an additional migration. Review prerequisites and apply the account migration only when needed. No automatic migration ledger exists: record filename, checksum, time and operator manually. Never execute migrations during repository validation.

## Validation

Run PHP syntax checks without executing application code:

```sh
find admin includes -name '*.php' -exec php -l {} \\;
php -l index.php
php -l product-image.php
php -l config.local.example.php
```

Run `node --check <file.js>` for JavaScript syntax. On a separately authorized disposable database, verify public catalog visibility, administrator login, CSRF rejection, uploads, draft/archived image privacy and account/session invalidation. Check keyboard navigation, reduced motion and mobile layouts in a browser. Do not run mutation tests against business data. Historical release validation is documented in [docs/SECURITY.md](docs/SECURITY.md); it is not evidence of live hosting validation.

## Security and deployment

Never commit configuration secrets, account hashes, data dumps, sessions, private uploads, raw testimonial evidence, backups or release ZIPs. The repository's fixed dummy bcrypt value is used only for unknown-user timing equalization; it is not an administrator password hash. The development DB fallback uses localhost/root with an empty password; set explicit credentials and `TECHTALK_ENV=production` for hosting, which rejects root/empty credentials.

See [Hostinger deployment guidance](docs/DEPLOYMENT.md), [architecture](docs/ARCHITECTURE.md), [security notes](docs/SECURITY.md) and [rollback](docs/ROLLBACK.md). Those documents describe the separately prepared release package, not a direct upload of this repository. Production packaging must omit local setup/diagnostics, raw source history and configuration; supply private credentials outside the document root. Do not deploy this checkout wholesale. GitHub publication does not deploy the site.

Live Hostinger behavior, native Safari/iOS and crash-window product/audit atomicity remain outside verified coverage. Public testimonials and business contact links are intentional site content.
