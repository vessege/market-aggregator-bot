-- Faza 6: Hybrid arch — track user searches so cron can pre-fetch popular keywords.

CREATE TABLE IF NOT EXISTS `search_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `query`      VARCHAR(120) NOT NULL,
  `results`    INT UNSIGNED NOT NULL DEFAULT 0,
  `live_used`  TINYINT(1) NOT NULL DEFAULT 0,
  `ip_hash`    CHAR(32) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_search_logs_query` (`query`, `created_at`),
  KEY `idx_search_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hot_keywords` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `keyword`          VARCHAR(120) NOT NULL,
  `priority`         INT NOT NULL DEFAULT 10,
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  `last_fetched_at`  DATETIME NULL,
  `last_results`     INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_hot_keywords_kw` (`keyword`),
  KEY `idx_hot_keywords_active` (`is_active`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
