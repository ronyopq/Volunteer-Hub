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
