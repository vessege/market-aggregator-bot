-- Migration 0001: dynamic_sources table
-- Safe to apply on existing SQLite DBs (no DROP).
CREATE TABLE IF NOT EXISTS dynamic_sources (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  slug              TEXT NOT NULL UNIQUE,
  display_name      TEXT NOT NULL,
  base_url          TEXT,
  search_url        TEXT NOT NULL,
  http_method       TEXT NOT NULL DEFAULT 'GET',
  headers_json      TEXT,
  body_template     TEXT,
  items_path        TEXT,
  field_id          TEXT,
  field_title       TEXT,
  field_price       TEXT,
  field_old_price   TEXT,
  field_currency    TEXT,
  field_image       TEXT,
  field_url         TEXT,
  field_rating      TEXT,
  field_reviews     TEXT,
  field_sold        TEXT,
  external_url_tpl  TEXT,
  is_active         INTEGER NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_dyn_src_active ON dynamic_sources(is_active);
