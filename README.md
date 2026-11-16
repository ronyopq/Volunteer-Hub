# PRAAN Volunteer Management System v1.9.0

PHP + SQLite + AdminLTE — **System Doctor & Secure Update Hardening**.

This is an incremental update to v1.8.0; it preserves volunteer data, existing role/permission logic, encrypted offline queue, mobile PWA, media upload, 3-page A4 print, DOCX and CSV/Excel exports.

## New in v1.9.0

- **System Doctor** (`index.php?page=system_doctor`): health checks for PHP/extensions, SQLite integrity and foreign keys, storage, backups, signing key, PWA and interrupted updates; downloadable sanitized JSON, without volunteer content or secrets.
- **Signed Release Update Progress**: 6+ tracked stages, browser polling via `index.php?page=update_progress`; AJAX form releases PHP session lock so polling does not block.
- **Verified installation & automatic rollback**: checksum all files after install, verify SQLite, restore code from validated backup on failure, remove only newly-created release files; keep current SQLite database and photos.
- **Interrupted update journal & recovery**: restore previous code from the matched verified backup; require Super Admin password and a confirmation phrase. If this fails, cPanel restore is required.
- **Transactional and additive SQL migrations** (`src/migrations/*.sql`): migration checksums, one-time migrations, SQLite transaction rollback; only CREATE TABLE, CREATE INDEX or ADD COLUMN accepted. No arbitrary migration PHP and no destructive DROP/DELETE SQL.
- **Maintenance control**: automatic maintenance during install; administrator controls retained. `update_migrate` creates verified backup and temporarily enables maintenance for manual migrations.
- **Super Admin enforcement** for signing key, release repository, maintenance, install, manual migration, rollback and interrupted-update recovery.
- Historical **Update History** and trusted signing-key verification are retained. Signed release asset naming/protocol retain the legacy `pran-update-vX.Y.Z.zip` convention for backward compatibility; customer-facing branding remains **PRAAN**.

## Important boundaries

- **Only signed public GitHub releases** may be installed. A normal ZIP in this chat is a **manual cPanel deployment**, not an automatically trusted GitHub release. Private signing key must never be uploaded to the server.
- Updates are performed by plain PHP without Laravel, Composer or shell execution. PHP ZipArchive, PDO SQLite, Sodium and cURL must be enabled for remote one-click install. GD/fileinfo support media features.
- **Rollback only restores code**; volunteer records, images and additive schema changes remain. Back up the entire installation before deployment and validate schema compatibility.
- A sudden PHP crash, filesystem damage, disk-full condition or PHP parse error in deployed code can prevent web-based recovery; retain an offsite backup and cPanel file access.
- Service Worker intentionally caches static assets only, not private HTML or volunteer data.

See `INSTALL_CPANEL_BN.txt` for deployment steps, `QA_REPORT.txt` for verified and unverified tests, and `ROADMAP_BN.md` for the remaining roadmap.
