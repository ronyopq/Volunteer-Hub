# PRAAN Volunteer Hub v2.2.1

A PHP 8.1+ / SQLite / AdminLTE volunteer database for `app.praan.org.bd/a/volunteer/`.

## What's fixed in v2.2

The previous `updates` and `system_doctor` routes caused HTTP 500 because `src/system_operations.php` was omitted from the main include chain. The runtime now loads it from `src/update_engine.php`. Friendly localized error pages and private diagnostic references were added.

## Notifications

`?page=notifications` provides a per-user private inbox, unread badge, mark-read operations, Super Admin broadcasts, deduplicated release/commit notices, and a new-public-registration alert to staff with relevant roles. The SQLite `praan_notifications` table is **additively migrated** when the app opens its database. Notification bodies do not include volunteer personal information.

## GitHub updates

Configured default: `ronyopq/Volunteer-Hub`. Published stable releases are separate from raw commits. On admin GET requests, checks are cached for the interval selected in Settings (default 12 hours). An optional CLI cron can check without visits. GitHub's Releases/latest endpoint requires an actual **published Release**; a tag or a commit without a Release does not qualify.

The current signed updater is retained. Only a newer published Release with `pran-update-vX.Y.Z.zip`, `.zip.sha256`, `.zip.sig`, the matching trusted offline signing key, all preflight checks, a Super Admin account and password confirmation can be installed. It performs a verified backup and tries rollback on failure. Test staging first.

## Security & deployment

- `.htaccess` blocks direct private storage/files and defines Apache error pages for `/a/volunteer/`.
- `storage/runtime-errors.jsonl` contains private error references; never publish it.
- No user data is distributed in the application ZIP.
- No data-wiping migrations are introduced.
- The install instructions are in `INSTALL_CPANEL_BN.txt`.
- A cPanel PHP runtime with `pdo_sqlite`, `curl`, `zip`, `sodium` and working HTTPS is needed for full features.

## Tests

Use `php -l` for PHP files, `node --check` for JavaScript, and CLI tests under `tools/`. SQLite migration and in-browser workflows must also be validated on a staging server. `QA_REPORT_v2.2.0.txt` summarizes tests and limitations.

## v2.2.1 Smart Import — import ALL valid rows without duplicate profiles

Fixes the misleading duplicate count when re-importing a PRAAN full Excel/CSV export. Previously rows with existing Volunteer IDs or NID/birth registration numbers were skipped as duplicates, and rows with shared phone and no document could also be skipped. **Names and shared phones are no longer blocking keys.**

The import preview now classifies every row as `New`, `Update Existing`, `Skip Existing`, `Needs Review` (conflicting identities/archived record/repeated ID within file), or `Invalid` (no name). Operators choose `Update Existing + Import New` (default) or `Only New Records`. The default merges *only supplied nonempty fields* into existing records. Profile pictures, source labels, URL tokens, receipts, displayed IDs, original form numbers, and public-listing consent stay unchanged. Rows with two conflicting identities are not silently re-assigned. Update access requires `volunteers.edit`.

This version does **not** bundle any uploaded volunteer data, nor does it connect to the production SQLite database. To import the provided 27-row XLSX, choose the new mode in Admin > Excel/CSV Import, inspect the preview, then Confirm. An exported CSV copy is separately supplied for hosts lacking PHP Zip/DOM support.

After import, the result page gives New / Updated / Skipped / Review / Invalid counts. Previews are validated by SHA-256 and checked again against the database immediately before committing. SQLite transaction protects the batch.
