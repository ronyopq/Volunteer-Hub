# Changelog

## v2.2.1 — Smart Excel/CSV Upsert (16 November 2026)
- Corrected misleading duplicate classification for re-imported full exports.
- Non-destructive update of existing records by Volunteer ID or normalized identity document; no name/phone blocking.
- Per-row preview with reason; optional "Only New" mode; explicit confirmation.
- Preserves current photo, URL token, consent, IDs and unprovided fields when updating.
- Prevents two conflicting identity matches or archived records being accidentally overwritten.
- Adds SHA-256 preview file consistency check and database re-preview before commit.
- Displays detailed Import outcome and supports same 119-column Excel/CSV format.
- Keeps existing PWA, modules, notifications and all v2.2.0 fixes.

# PRAAN Volunteer Management — Release history

## v2.2.0 — Error Recovery, GitHub Monitor & Notifications (2026-11-16)

### Bug fixes
- **Fix HTTP 500** on `?page=updates` and `?page=system_doctor`: `src/system_operations.php` was not loaded by the normal web runtime. It had been loaded only in a CLI test.
- Replace unexpected PHP exceptions with a Bangla error page and private `ERR-XXXXXXXXXX` log reference. The sensitive stack trace remains server-side.
- Add static Apache 403, 404, 500 and 503 pages for the known `/a/volunteer/` deployment path.
- Include static error pages in the signed-update and rollback code-path allowlist.

### GitHub release and repository monitor
- Default `ronyopq/Volunteer-Hub`; background-on-visit check every 6/12/24 hours (setting; default 12 h).
- Check published stable GitHub Release and latest `main` commit separately. If only `main` changes, report it as a **commit change, not an installable release**.
- Only show One-Click Install when a newer stable Release publishes the required ZIP, SHA-256 and Ed25519 signature assets and server preflight succeeds; install is Super-Admin-only.
- Manual Refresh in Update Center and optional cPanel PHP CLI cron for unattended checks.

### In-app notifications
- Protected per-account SQLite inbox, unread badge, periodic polling when tab is open, mark read/all read.
- Notify Super Admin accounts about new releases and changed commits (deduplicated by release tag/SHA).
- Notify Admin/Verifier/Super Admin accounts about new *public* registrations, without copying names, phone numbers, NIDs or other personal information into the notification body.
- Super Admin may broadcast announcements to active staff accounts after confirming password.
- Notification preferences and GitHub check interval in Control Center.

### Compatibility
- No existing volunteer, user, photo or backup data is removed. One additive `praan_notifications` table and indexes are created automatically.
- Existing 3-page A4 print, DOCX export, role management, PWA, modules and prior features retained.

### Limitations
- Normal admin page visits trigger periodic checks; continuous unattended checking requires configuring the optional cPanel cron.
- A Git commit or GitHub tag alone **cannot** be installed: One-Click Update requires a published signed Release and a trusted local public signing key.
- Full PHP–SQLite and browser/cPanel integration tests need to run on the target server or staging.

---

## Previous releases

## v2.1.0 — 2026-11-16
- PRAAN identifiers: form numbers, readable volunteer/user codes, opaque URL tokens; preserved legacy IDs.
- Grouped sidebar, favicon, GitHub and WhatsApp defaults, identity-document duplicate detection, Word export template.

# PRAAN Volunteer Management — Change History

## v2.0.0 — 2026-11-16 · Security & Privacy Hardening

- **Security & Privacy Center**: role-checked administrator UI, login failure/session/download counters, SQLite quick check, PHP GD/SQLite/HTTPS/storage status.
- **Privacy Desk**: request tracking for access, correction, public visibility restriction and erasure reviews; request IDs, status transitions, staff accountability and redacted action audit. Completing a public-restriction request hides that profile from the public directory; other requests do not auto-delete personal data.
- **Access control**: `security.view` and `security.manage` granular permissions and route gates. Default Super Admin has both; Administrator has read-only access to the center.
- **Production maintenance**: password-confirmed manual cleanup of expired sessions, old login attempts/rate limits and stale temporary import previews; never removes volunteers/photos/main activity logs. Selectable 30–365 day login-history retention and 7–90 day revoked-session retention.
- **Brute-force resistance**: per-username lockout after 5 failed attempts in 15 minutes, in addition to IP throttling.
- **Public form anti-spam**: configurable hidden honeypot and minimum time-before-submit.
- **Privacy & consent**: new admin publication requires separately confirmed public directory consent. Imported records remain non-public by default.
- **Security defaults**: strict session mode, same-site scoped cookies, no-referrer and no-store response headers, HTTPS-only HSTS, stricter image handling to avoid original EXIF metadata when PHP GD is missing.
- **Export accountability**: audit entries for SQLite backup, standard export, selected bulk export, private update ZIP downloads and form print access.
- **Database performance**: status/date/public-visibility, privacy, login and session indexes, optional `PRAGMA optimize` during explicit cleanup.
- **Bug fixes**: updating a draft UID now constructs SQL parameters after the field list is finalized; prior profile photo kept until volunteer save succeeds; Viewer no longer sees unavailable private print actions.
- **Compatibility**: preserves v1.9.0 tables, volunteer payload, images, three-page A4 print, DOCX, PWA and signed-update architecture.
- **Known limitations**: no full end-to-end PHP+SQLite/browser test in the authoring environment. CDN availability, hosting configuration and backup/restore must be verified on staging before deployment.

# PRAAN Volunteer Management — Version History

## v1.9.0 — 2026-11-16

### ✨ New
- System Doctor with category-based diagnostics and safe JSON report download.
- AJAX signed-update progress indicator, protected polling endpoint, and explicit update journal.
- Interrupted update detection, Super Admin recovery workflow, rollback removal of newly added release files.
- Signed-release preflight: required PHP version/extensions, writable target paths and post-install SHA-256 checks.
- Transactional additive SQLite migrations with file checksums and migration history.
- Backup-before-manual-migration action, maintenance mode during migration, and Super Admin verification.

### 🔒 Security & reliability
- Limit migration SQL to additive operations; destructive SQL and unknown migration files are rejected.
- Keep rollback code-only to protect newly submitted volunteer records; do not roll back the live SQLite file.
- Invalidate PHP OPcache on modified/recovered PHP files when available.
- Preserve legacy signed-release format to avoid breaking existing trusted keys.

### ⚠ Limitations
- No promise of atomic filesystem replacement across multiple PHP files; use maintenance and staging deployment.
- Automatic recovery is best effort; keep cPanel/offsite backups.
- Full production PHP–SQLite–ZipArchive/cURL end-to-end tests remain required on the target host.

## v1.8.0 — Mobile PWA / encrypted offline queue
## v1.7.0 — Module Manager / feature flags
## v1.6.0 — Users / granular roles / sessions
## v1.5.0 — Resumable media upload
## v1.4.0 — PRAAN foundation / settings / 3-page DOCX
## v1.3.0 — Volunteer DataTable / Import–Export
## v1.2.0 — Signed Update Suite
