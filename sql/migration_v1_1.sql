-- Migration v1.1: 利益設定・見積機能の追加
-- このファイルを既存DBに対して実行してください

-- ── user_settings ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `user_settings` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `brand_name` varchar(255) NOT NULL DEFAULT '',
  `default_hourly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `default_markup_rate` decimal(8,2) NOT NULL DEFAULT 100.00 COMMENT '利益上乗せ率（%）デフォルト',
  `default_markup_type` enum('over_cost','multiplier','margin') NOT NULL DEFAULT 'over_cost',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 10.00,
  `include_tax` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=税込み表示',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── products: 利益設定カラム追加 ────────────────────────────────────────────
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `markup_rate` decimal(8,2) DEFAULT NULL COMMENT 'NULLはグローバル設定を使用',
  ADD COLUMN IF NOT EXISTS `markup_type` enum('over_cost','multiplier','margin') DEFAULT NULL;

-- ── quotes ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `quotes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `quote_number` varchar(32) NOT NULL,
  `client_name` varchar(255) NOT NULL DEFAULT '',
  `valid_until` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('draft','sent','accepted','declined') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_quotes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── quote_items ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `quote_items` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `quote_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `markup_rate` decimal(8,2) NOT NULL DEFAULT 100.00,
  `markup_type` enum('over_cost','multiplier','margin') NOT NULL DEFAULT 'over_cost',
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `quantity` int(10) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_quote_id` (`quote_id`),
  CONSTRAINT `fk_qi_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
