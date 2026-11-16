# PRAAN উন্নয়ন রোডম্যাপ

✅ v1.4.0 — PRAAN Branding, Settings, DOCX, Drafts
✅ v1.5.0 — Resumable Media Upload, Photo Auto-Attach
✅ v1.6.0 — Users, Custom Roles, Sessions, Login Audit
✅ v1.7.0 — Module Manager, Feature Flags, Module Settings
✅ v1.8.0 — Mobile PWA, Encrypted Offline Form Queue
✅ **v1.9.0 — System Doctor, Signed Update Hardening, Additive Migration, Recovery Journal**

## পরবর্তী ধাপ — v2.0.0 (পরিকল্পনা; এখনো বাস্তবায়িত নয়)

1. Production security review: account sessions, CSRF, permission boundaries, rate limits, storage access.
2. Backup/Recovery drill on separate test server: verified images + SQLite + code; reliable operational runbook.
3. Public and volunteer privacy management: consent and PII minimization.
4. Reports, performance profiling and SQLite indexes based on real workloads.
5. Full integration tests, accessibility, PWA/offline field tests, deployment QA.
6. Optional scheduling / update windows only after a safe CLI runner or cron is configured.

**নীতিমালা:** আগের তথ্য কখনো স্বয়ংক্রিয়ভাবে মুছে যাবে না। New release ইন্সটল হওয়ার আগে অবশ্যই Verified Backup, trusted signature ও Super Admin approval লাগবে।
