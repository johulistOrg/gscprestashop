CREATE TABLE IF NOT EXISTS `PREFIX_seocrawler_audit` (
  `id_seocrawler_audit` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `url` VARCHAR(2083) NOT NULL,
  `page_type` VARCHAR(32) NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `issue_type` VARCHAR(128) NOT NULL,
  `severity` VARCHAR(16) NOT NULL,
  `details` TEXT NOT NULL,
  `content_hash` CHAR(40) NOT NULL DEFAULT '',
  `similarity_hash` VARCHAR(255) NOT NULL DEFAULT '',
  `word_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_seocrawler_audit`),
  KEY `idx_issue_type` (`issue_type`),
  KEY `idx_page_type` (`page_type`),
  KEY `idx_content_hash` (`content_hash`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
