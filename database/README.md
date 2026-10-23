# Database Migration Inventory

All files here are schema-only. They contain **no** administrator passwords,
products, images, audit records, or account profiles.

| Order | File | Purpose | Safe on existing DB? |
|---|---|---|---|
| 1 | `01-core-schema.sql` | Five core tables: `admin_users`, `categories`, `products`, `product_images`, `product_audit_log` (fresh installs only) | `CREATE TABLE IF NOT EXISTS`; does not alter existing tables |
| 2 | `02-account-profiles.sql` | Additive `admin_account_profiles` table (Account Settings / avatar / preferences / auth version) | Yes, idempotent |

## Apply

- **Existing TechTalk database:** import the owner-approved dump, then apply
  `02-account-profiles.sql` only if the table is absent.
- **Fresh database:** apply `01-core-schema.sql`, then
  `02-account-profiles.sql`, then create the owner's category rows.
- **Never** run these files against `techtalk_db` from this workstation without
  separate authorization. The release preparation did not modify the business
  database.

No migration ledger exists. Record the filename, checksum, timestamp, and
operator in the deployment log. Do not re-run destructive SQL.

## Rollback

`02-account-profiles.sql` is additive. Leaving the table in place after an
application rollback is normally harmless. Do not drop tables or databases
without owner approval and a verified backup. See `docs/ROLLBACK.md`.
