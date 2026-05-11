-- Drop legacy Telegram-era tables (no longer used after website-only refactor).
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `broadcast_recipients`;
DROP TABLE IF EXISTS `broadcasts`;
DROP TABLE IF EXISTS `subscription_channels`;
DROP TABLE IF EXISTS `favorites`;
DROP TABLE IF EXISTS `cart_items`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- Useful index for sort-by-recency. MySQL/MariaDB doesn't support
-- "CREATE INDEX IF NOT EXISTS", so this migration is one-shot and only
-- runs on hosts that have not yet been migrated (tracked by schema_migrations).
ALTER TABLE `products` ADD INDEX `idx_products_updated_at` (`updated_at`);
