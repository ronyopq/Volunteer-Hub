-- PRAAN additive migration; no existing volunteer field changes.
CREATE TABLE IF NOT EXISTS praan_update_receipts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  from_version TEXT NOT NULL,
  to_version TEXT NOT NULL,
  status TEXT NOT NULL,
  backup_name TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_praan_update_receipts_created ON praan_update_receipts(created_at);
