-- MarketCompare — MySQL/MariaDB schema
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `price_alerts`;
DROP TABLE IF EXISTS `price_history`;
DROP TABLE IF EXISTS `parser_runs`;
DROP TABLE IF EXISTS `dynamic_sources`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `schema_migrations`;

SET FOREIGN_KEY_CHECKS = 1;

-- Admin foydalanuvchilari (admin panelga kirish uchun)
CREATE TABLE `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(128) NULL,
  `is_super` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mahsulot kategoriyalari
CREATE TABLE `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(64) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `icon` VARCHAR(255) NULL,
  `parent_id` INT UNSIGNED NULL,
  `position` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_categories_slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mahsulotlar
CREATE TABLE `products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(32) NOT NULL,
  `external_id` VARCHAR(128) NULL,
  `external_url` VARCHAR(512) NULL,
  `category_id` INT UNSIGNED NULL,
  `title` VARCHAR(512) NOT NULL,
  `description` TEXT NULL,
  `image_url` VARCHAR(1024) NULL,
  `images_json` TEXT NULL,
  `price` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `old_price` DECIMAL(14,2) NULL,
  `currency` VARCHAR(8) NOT NULL DEFAULT 'UZS',
  `rating` DECIMAL(3,2) NULL,
  `reviews_count` INT NOT NULL DEFAULT 0,
  `sold_count` INT NOT NULL DEFAULT 0,
  `seller` VARCHAR(255) NULL,
  `seller_rating` DECIMAL(3,2) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_products_source_external` (`source`, `external_id`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_active_price` (`is_active`, `price`),
  KEY `idx_products_rating` (`rating`),
  KEY `idx_products_updated_at` (`updated_at`),
  FULLTEXT KEY `ft_products_search` (`title`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parser run tarixi
CREATE TABLE `parser_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(32) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'running',
  `params` TEXT NULL,
  `inserted_count` INT NOT NULL DEFAULT 0,
  `updated_count` INT NOT NULL DEFAULT 0,
  `error` TEXT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parser_runs_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin tomonidan boshqariladigan dinamik manbalar
CREATE TABLE `dynamic_sources` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(64) NOT NULL,
  `display_name` VARCHAR(128) NOT NULL,
  `base_url` VARCHAR(255) NULL,
  `search_url` TEXT NOT NULL,
  `http_method` VARCHAR(8) NOT NULL DEFAULT 'GET',
  `headers_json` TEXT NULL,
  `body_template` TEXT NULL,
  `items_path` VARCHAR(255) NULL,
  `field_id` VARCHAR(255) NULL,
  `field_title` VARCHAR(255) NULL,
  `field_price` VARCHAR(255) NULL,
  `field_old_price` VARCHAR(255) NULL,
  `field_currency` VARCHAR(255) NULL,
  `field_image` VARCHAR(255) NULL,
  `field_url` VARCHAR(255) NULL,
  `field_rating` VARCHAR(255) NULL,
  `field_reviews` VARCHAR(255) NULL,
  `field_sold` VARCHAR(255) NULL,
  `external_url_tpl` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_dyn_src_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sozlamalar (key-value)
CREATE TABLE `settings` (
  `key` VARCHAR(64) NOT NULL,
  `value` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Narx tarixi snapshotlari (sparkline + "tarixiy minimum" uchun)
CREATE TABLE `price_history` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`  BIGINT UNSIGNED NOT NULL,
  `price`       DECIMAL(14,2) NOT NULL,
  `currency`    VARCHAR(8) NOT NULL DEFAULT 'UZS',
  `captured_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_price_history_product` (`product_id`, `captured_at`),
  CONSTRAINT `fk_price_history_product` FOREIGN KEY (`product_id`)
    REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Foydalanuvchi narx ogohlantirishlari (email orqali)
CREATE TABLE `price_alerts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`   BIGINT UNSIGNED NOT NULL,
  `email`        VARCHAR(255) NOT NULL,
  `target_price` DECIMAL(14,2) NOT NULL,
  `currency`     VARCHAR(8) NOT NULL DEFAULT 'UZS',
  `status`       ENUM('active','notified','cancelled') NOT NULL DEFAULT 'active',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notified_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_price_alerts_product` (`product_id`, `status`),
  KEY `idx_price_alerts_email` (`email`),
  CONSTRAINT `fk_price_alerts_product` FOREIGN KEY (`product_id`)
    REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration history
CREATE TABLE `schema_migrations` (
  `name` VARCHAR(255) NOT NULL,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Boshlang'ich kategoriyalar
INSERT INTO `categories` (`slug`, `name`, `icon`, `position`) VALUES
  ('elektronika', 'Elektronika', '📱', 1),
  ('uy-jihozlari', 'Uy jihozlari', '🏠', 2),
  ('kiyim',       'Kiyim-kechak', '👕', 3),
  ('go-zallik',   'Go''zallik', '💄', 4),
  ('bolalar',     'Bolalar', '🧸', 5),
  ('sport',       'Sport', '⚽', 6),
  ('oziq-ovqat',  'Oziq-ovqat', '🛒', 7),
  ('kitoblar',    'Kitoblar', '📚', 8),
  ('avtomobil',   'Avto', '🚗', 9);
