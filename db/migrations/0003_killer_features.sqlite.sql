-- Price history snapshots — written on every meaningful upsert.
-- Lets the frontend render a sparkline + lowest-ever badge.
CREATE TABLE IF NOT EXISTS price_history (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id  INTEGER NOT NULL,
  price       REAL NOT NULL,
  currency    TEXT NOT NULL DEFAULT 'UZS',
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_price_history_product ON price_history(product_id, captured_at);

-- User-submitted price drop alerts. We deliberately don't link these to a
-- registered user account (we don't have user accounts yet) — just email.
CREATE TABLE IF NOT EXISTS price_alerts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id   INTEGER NOT NULL,
  email        TEXT NOT NULL,
  target_price REAL NOT NULL,
  currency     TEXT NOT NULL DEFAULT 'UZS',
  status       TEXT NOT NULL DEFAULT 'active', -- active | notified | cancelled
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notified_at  DATETIME NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_price_alerts_product ON price_alerts(product_id, status);
CREATE INDEX IF NOT EXISTS idx_price_alerts_email   ON price_alerts(email);
