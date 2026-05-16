CREATE TABLE IF NOT EXISTS `price_history` (
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

CREATE TABLE IF NOT EXISTS `price_alerts` (
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
