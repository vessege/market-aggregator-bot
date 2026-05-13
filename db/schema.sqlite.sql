-- MarketCompare — SQLite schema (development / fallback)
PRAGMA foreign_keys = ON;

DROP TABLE IF EXISTS price_alerts;
DROP TABLE IF EXISTS price_history;
DROP TABLE IF EXISTS parser_runs;
DROP TABLE IF EXISTS dynamic_sources;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS admins;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS schema_migrations;

CREATE TABLE admins (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  display_name  TEXT,
  is_super      INTEGER NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE categories (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  slug      TEXT NOT NULL UNIQUE,
  name      TEXT NOT NULL,
  icon      TEXT,
  parent_id INTEGER,
  position  INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX idx_categories_parent ON categories(parent_id);

CREATE TABLE products (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  source         TEXT NOT NULL,
  external_id    TEXT,
  external_url   TEXT,
  category_id    INTEGER,
  title          TEXT NOT NULL,
  description    TEXT,
  image_url      TEXT,
  images_json    TEXT,
  price          REAL NOT NULL DEFAULT 0,
  old_price      REAL,
  currency       TEXT NOT NULL DEFAULT 'UZS',
  rating         REAL,
  reviews_count  INTEGER NOT NULL DEFAULT 0,
  sold_count     INTEGER NOT NULL DEFAULT 0,
  seller         TEXT,
  seller_rating  REAL,
  is_active      INTEGER NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(source, external_id)
);
CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_products_active_price ON products(is_active, price);
CREATE INDEX idx_products_rating ON products(rating);
CREATE INDEX idx_products_updated_at ON products(updated_at);

CREATE TABLE parser_runs (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  source          TEXT NOT NULL,
  status          TEXT NOT NULL DEFAULT 'running',
  params          TEXT,
  inserted_count  INTEGER NOT NULL DEFAULT 0,
  updated_count   INTEGER NOT NULL DEFAULT 0,
  error           TEXT,
  started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at     DATETIME
);
CREATE INDEX idx_parser_runs_source ON parser_runs(source);

CREATE TABLE dynamic_sources (
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
CREATE INDEX idx_dyn_src_active ON dynamic_sources(is_active);

CREATE TABLE settings (
  key        TEXT PRIMARY KEY,
  value      TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE price_history (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id  INTEGER NOT NULL,
  price       REAL NOT NULL,
  currency    TEXT NOT NULL DEFAULT 'UZS',
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
CREATE INDEX idx_price_history_product ON price_history(product_id, captured_at);

CREATE TABLE price_alerts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id   INTEGER NOT NULL,
  email        TEXT NOT NULL,
  target_price REAL NOT NULL,
  currency     TEXT NOT NULL DEFAULT 'UZS',
  status       TEXT NOT NULL DEFAULT 'active',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notified_at  DATETIME NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
CREATE INDEX idx_price_alerts_product ON price_alerts(product_id, status);
CREATE INDEX idx_price_alerts_email   ON price_alerts(email);

CREATE TABLE schema_migrations (
  name       TEXT PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Faza 6: foydalanuvchi qidiruvlari (hybrid arch)
CREATE TABLE search_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    "query"    TEXT NOT NULL,
    results    INTEGER NOT NULL DEFAULT 0,
    live_used  INTEGER NOT NULL DEFAULT 0,
    ip_hash    TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_search_logs_query   ON search_logs("query", created_at);
CREATE INDEX idx_search_logs_created ON search_logs(created_at);

-- Faza 6: admin tomonidan boshqariladigan "issiq" qidiruv so'zlari
CREATE TABLE hot_keywords (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    keyword         TEXT NOT NULL UNIQUE,
    priority        INTEGER NOT NULL DEFAULT 10,
    is_active       INTEGER NOT NULL DEFAULT 1,
    last_fetched_at DATETIME,
    last_results    INTEGER NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_hot_keywords_active ON hot_keywords(is_active, priority);

INSERT INTO categories (slug, name, icon, position) VALUES
  ('elektronika', 'Elektronika', '📱', 1),
  ('uy-jihozlari', 'Uy jihozlari', '🏠', 2),
  ('kiyim',       'Kiyim-kechak', '👕', 3),
  ('go-zallik',   'Go''zallik', '💄', 4),
  ('bolalar',     'Bolalar', '🧸', 5),
  ('sport',       'Sport', '⚽', 6),
  ('oziq-ovqat',  'Oziq-ovqat', '🛒', 7),
  ('kitoblar',    'Kitoblar', '📚', 8),
  ('avtomobil',   'Avto', '🚗', 9);
