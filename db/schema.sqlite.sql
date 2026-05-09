-- Market Aggregator Bot — SQLite schema (development / fallback)
PRAGMA foreign_keys = ON;

DROP TABLE IF EXISTS broadcast_recipients;
DROP TABLE IF EXISTS broadcasts;
DROP TABLE IF EXISTS subscription_channels;
DROP TABLE IF EXISTS favorites;
DROP TABLE IF EXISTS cart_items;
DROP TABLE IF EXISTS parser_runs;
DROP TABLE IF EXISTS dynamic_sources;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS admins;
DROP TABLE IF EXISTS settings;

CREATE TABLE users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tg_id         INTEGER NOT NULL UNIQUE,
  username      TEXT,
  first_name    TEXT,
  last_name     TEXT,
  language_code TEXT,
  is_blocked    INTEGER NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME
);
CREATE INDEX idx_users_username ON users(username);

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

CREATE TABLE favorites (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, product_id)
);
CREATE INDEX idx_fav_user ON favorites(user_id);
CREATE INDEX idx_fav_product ON favorites(product_id);

CREATE TABLE cart_items (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  quantity   INTEGER NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, product_id)
);
CREATE INDEX idx_cart_user ON cart_items(user_id);

CREATE TABLE subscription_channels (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  chat_id     TEXT NOT NULL UNIQUE,
  title       TEXT,
  invite_link TEXT,
  is_active   INTEGER NOT NULL DEFAULT 1,
  position    INTEGER NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE broadcasts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id      INTEGER,
  type          TEXT NOT NULL DEFAULT 'text',
  text          TEXT,
  media_path    TEXT,
  parse_mode    TEXT,
  buttons_json  TEXT,
  status        TEXT NOT NULL DEFAULT 'pending',
  total_count   INTEGER NOT NULL DEFAULT 0,
  sent_count    INTEGER NOT NULL DEFAULT 0,
  failed_count  INTEGER NOT NULL DEFAULT 0,
  started_at    DATETIME,
  finished_at   DATETIME,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_broadcasts_status ON broadcasts(status);

CREATE TABLE broadcast_recipients (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  broadcast_id  INTEGER NOT NULL,
  user_id       INTEGER NOT NULL,
  status        TEXT NOT NULL DEFAULT 'pending',
  error         TEXT,
  sent_at       DATETIME,
  UNIQUE(broadcast_id, user_id)
);
CREATE INDEX idx_br_status ON broadcast_recipients(broadcast_id, status);

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
