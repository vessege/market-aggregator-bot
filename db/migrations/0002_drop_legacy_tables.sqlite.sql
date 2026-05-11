-- Drop legacy Telegram-era tables (no longer used after website-only refactor).
-- Safe to run on fresh DBs (DROP IF EXISTS).
DROP TABLE IF EXISTS broadcast_recipients;
DROP TABLE IF EXISTS broadcasts;
DROP TABLE IF EXISTS subscription_channels;
DROP TABLE IF EXISTS favorites;
DROP TABLE IF EXISTS cart_items;
DROP TABLE IF EXISTS users;

-- Useful index for sort-by-recency (skipped by IF NOT EXISTS for re-runs).
CREATE INDEX IF NOT EXISTS idx_products_updated_at ON products(updated_at);
