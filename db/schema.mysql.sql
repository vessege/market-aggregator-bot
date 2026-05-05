-- Market Aggregator Bot — MySQL/MariaDB schema
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `broadcast_recipients`;
DROP TABLE IF EXISTS `broadcasts`;
DROP TABLE IF EXISTS `subscription_channels`;
DROP TABLE IF EXISTS `favorites`;
DROP TABLE IF EXISTS `cart_items`;
DROP TABLE IF EXISTS `parser_runs`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `settings`;

SET FOREIGN_KEY_CHECKS = 1;

-- Telegram foydalanuvchilari
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tg_id` BIGINT NOT NULL,
  `username` VARCHAR(64) NULL,
  `first_name` VARCHAR(128) NULL,
  `last_name` VARCHAR(128) NULL,
  `language_code` VARCHAR(8) NULL,
  `is_blocked` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_tg_id` (`tg_id`),
  KEY `idx_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `source` VARCHAR(32) NOT NULL,             -- "uzum", "olx", "wildberries", "manual" ...
  `external_id` VARCHAR(128) NULL,           -- saytdagi mahsulot id
  `external_url` VARCHAR(512) NULL,          -- mahsulot havolasi
  `category_id` INT UNSIGNED NULL,
  `title` VARCHAR(512) NOT NULL,
  `description` TEXT NULL,
  `image_url` VARCHAR(1024) NULL,            -- birinchi rasm
  `images_json` TEXT NULL,                   -- JSON array of image urls
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
  FULLTEXT KEY `ft_products_search` (`title`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Foydalanuvchi sevimlilari
CREATE TABLE `favorites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fav_user_product` (`user_id`, `product_id`),
  KEY `idx_fav_user` (`user_id`),
  KEY `idx_fav_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Savat
CREATE TABLE `cart_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cart_user_product` (`user_id`, `product_id`),
  KEY `idx_cart_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Majburiy obuna kanallari
CREATE TABLE `subscription_channels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_id` VARCHAR(64) NOT NULL,            -- masalan: -1001234567890 yoki @channel
  `title` VARCHAR(255) NULL,
  `invite_link` VARCHAR(512) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `position` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sub_chat_id` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Broadcastlar (admin -> foydalanuvchilar)
CREATE TABLE `broadcasts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NULL,
  `type` VARCHAR(16) NOT NULL DEFAULT 'text', -- text | photo | video
  `text` TEXT NULL,                           -- caption yoki matn
  `media_path` VARCHAR(512) NULL,             -- storage/uploads/broadcast/...
  `parse_mode` VARCHAR(16) NULL,              -- HTML | Markdown
  `buttons_json` TEXT NULL,                   -- inline keyboard JSON (ixtiyoriy)
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending', -- pending | running | done | failed
  `total_count` INT NOT NULL DEFAULT 0,
  `sent_count` INT NOT NULL DEFAULT 0,
  `failed_count` INT NOT NULL DEFAULT 0,
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_broadcasts_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Broadcast natijalari (har bir foydalanuvchi uchun)
CREATE TABLE `broadcast_recipients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `broadcast_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending', -- pending | sent | failed
  `error` VARCHAR(255) NULL,
  `sent_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_br_user` (`broadcast_id`, `user_id`),
  KEY `idx_br_status` (`broadcast_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parser run history
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

-- Sozlamalar (key-value)
CREATE TABLE `settings` (
  `key` VARCHAR(64) NOT NULL,
  `value` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
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
