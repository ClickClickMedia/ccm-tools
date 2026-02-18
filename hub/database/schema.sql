-- CCM API Hub Database Schema
-- MySQL 8.4 Compatible
-- Created: 2026-02-19
-- Version: 1.0.0

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- Users (admin panel access via Google SSO)
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `name` VARCHAR(255) NOT NULL DEFAULT '',
    `role` ENUM('viewer', 'manager', 'admin') NOT NULL DEFAULT 'viewer',
    `google_id` VARCHAR(255) DEFAULT NULL,
    `avatar_url` VARCHAR(500) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`),
    INDEX `idx_google_id` (`google_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- User Sessions (remember-me tokens)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_token` (`token_hash`),
    INDEX `idx_expires` (`expires_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Licensed Sites (URL access control)
-- ============================================================
CREATE TABLE IF NOT EXISTS `licensed_sites` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `site_url` VARCHAR(500) NOT NULL,
    `site_name` VARCHAR(255) NOT NULL DEFAULT '',
    `api_key_hash` VARCHAR(255) NOT NULL COMMENT 'Hashed site API key for auth',
    `api_key_prefix` VARCHAR(12) NOT NULL COMMENT 'First 8 chars for identification',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `ai_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `pagespeed_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `ai_monthly_limit` INT UNSIGNED NOT NULL DEFAULT 1000 COMMENT 'Max AI tokens per month (in thousands)',
    `pagespeed_daily_limit` INT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Max PageSpeed tests per day',
    `notes` TEXT DEFAULT NULL,
    `last_seen` DATETIME DEFAULT NULL,
    `expires_at` DATETIME DEFAULT NULL COMMENT 'NULL = never expires',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_site_url` (`site_url`(191)),
    INDEX `idx_api_key_prefix` (`api_key_prefix`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_expires` (`expires_at`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- API Usage Log (track all API calls)
-- ============================================================
CREATE TABLE IF NOT EXISTS `api_usage_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `site_id` INT UNSIGNED NOT NULL,
    `endpoint` VARCHAR(100) NOT NULL COMMENT 'e.g. claude/analyze, pagespeed/test',
    `request_type` ENUM('ai', 'pagespeed', 'other') NOT NULL DEFAULT 'other',
    `tokens_used` INT UNSIGNED DEFAULT 0 COMMENT 'AI tokens consumed',
    `input_tokens` INT UNSIGNED DEFAULT 0,
    `output_tokens` INT UNSIGNED DEFAULT 0,
    `cost_usd` DECIMAL(10, 6) DEFAULT 0 COMMENT 'Estimated cost in USD',
    `status_code` SMALLINT UNSIGNED DEFAULT NULL,
    `response_time_ms` INT UNSIGNED DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `request_ip` VARCHAR(45) DEFAULT NULL,
    `metadata` JSON DEFAULT NULL COMMENT 'Additional context data',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_site_id` (`site_id`),
    INDEX `idx_endpoint` (`endpoint`),
    INDEX `idx_request_type` (`request_type`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_site_date` (`site_id`, `created_at`),
    FOREIGN KEY (`site_id`) REFERENCES `licensed_sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PageSpeed Results (cached test results)
-- ============================================================
CREATE TABLE IF NOT EXISTS `pagespeed_results` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `site_id` INT UNSIGNED NOT NULL,
    `test_url` VARCHAR(2000) NOT NULL,
    `strategy` ENUM('mobile', 'desktop') NOT NULL DEFAULT 'mobile',
    `performance_score` TINYINT UNSIGNED DEFAULT NULL,
    `accessibility_score` TINYINT UNSIGNED DEFAULT NULL,
    `best_practices_score` TINYINT UNSIGNED DEFAULT NULL,
    `seo_score` TINYINT UNSIGNED DEFAULT NULL,
    `fcp_ms` INT UNSIGNED DEFAULT NULL COMMENT 'First Contentful Paint',
    `lcp_ms` INT UNSIGNED DEFAULT NULL COMMENT 'Largest Contentful Paint',
    `cls` DECIMAL(5,3) DEFAULT NULL COMMENT 'Cumulative Layout Shift',
    `tbt_ms` INT UNSIGNED DEFAULT NULL COMMENT 'Total Blocking Time',
    `si_ms` INT UNSIGNED DEFAULT NULL COMMENT 'Speed Index',
    `tti_ms` INT UNSIGNED DEFAULT NULL COMMENT 'Time to Interactive',
    `opportunities` JSON DEFAULT NULL COMMENT 'Improvement opportunities',
    `diagnostics` JSON DEFAULT NULL COMMENT 'Diagnostic audit details',
    `full_response` JSON DEFAULT NULL COMMENT 'Complete PSI API response',
    `ai_analysis` JSON DEFAULT NULL COMMENT 'Claude AI analysis of results',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_site_id` (`site_id`),
    INDEX `idx_strategy` (`strategy`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_site_strategy` (`site_id`, `strategy`, `created_at`),
    FOREIGN KEY (`site_id`) REFERENCES `licensed_sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AI Optimization Sessions (track optimization workflows)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ai_sessions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `site_id` INT UNSIGNED NOT NULL,
    `session_type` ENUM('full_audit', 'quick_fix', 'targeted') NOT NULL DEFAULT 'full_audit',
    `status` ENUM('pending', 'running', 'analyzing', 'applying', 'testing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    `initial_scores` JSON DEFAULT NULL COMMENT 'PageSpeed scores before optimization',
    `final_scores` JSON DEFAULT NULL COMMENT 'PageSpeed scores after optimization',
    `recommendations` JSON DEFAULT NULL COMMENT 'AI recommendations generated',
    `actions_taken` JSON DEFAULT NULL COMMENT 'Actions applied to site',
    `total_tokens_used` INT UNSIGNED DEFAULT 0,
    `total_cost_usd` DECIMAL(10, 6) DEFAULT 0,
    `iterations` TINYINT UNSIGNED DEFAULT 0 COMMENT 'Number of test-optimize cycles',
    `error_log` TEXT DEFAULT NULL,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_site_id` (`site_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    FOREIGN KEY (`site_id`) REFERENCES `licensed_sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- App Settings (key-value store with encryption support)
-- ============================================================
CREATE TABLE IF NOT EXISTS `app_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT DEFAULT NULL,
    `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
    `category` VARCHAR(50) NOT NULL DEFAULT 'general',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_key` (`setting_key`),
    INDEX `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Activity Log (admin audit trail)
-- ============================================================
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `target_type` VARCHAR(50) DEFAULT NULL COMMENT 'e.g. licensed_site, user, setting',
    `target_id` VARCHAR(50) DEFAULT NULL,
    `details` JSON DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Rate Limiting (track API rate limits)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(255) NOT NULL COMMENT 'site_id or IP address',
    `endpoint` VARCHAR(100) NOT NULL,
    `window_start` DATETIME NOT NULL,
    `request_count` INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE INDEX `idx_identifier_endpoint_window` (`identifier`(100), `endpoint`, `window_start`),
    INDEX `idx_window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Default Settings
-- All configuration lives here. Encrypted values are stored
-- after first setup via the admin wizard. The .env file only
-- holds DB credentials and the ENCRYPTION_KEY.
-- ============================================================
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `is_encrypted`, `category`) VALUES
-- General
('app_name', 'CCM API Hub', 0, 'general'),
('app_url', 'https://api.tools.clickclick.media', 0, 'general'),
('app_secret_key', '', 1, 'general'),
('maintenance_mode', '0', 0, 'general'),
('setup_complete', '0', 0, 'general'),

-- Session
('session_secure', '1', 0, 'session'),
('session_lifetime', '7200', 0, 'session'),

-- Google OAuth (values added via setup wizard, stored encrypted)
('google_client_id', '', 1, 'oauth'),
('google_client_secret', '', 1, 'oauth'),
('google_redirect_uri', '', 0, 'oauth'),
('google_allowed_domain', 'clickclickmedia.com.au', 0, 'oauth'),
('wizard_emails', 'rik@clickclickmedia.com.au', 0, 'oauth'),

-- Claude AI
('claude_api_key', '', 1, 'ai'),
('claude_model', 'claude-sonnet-4-20250514', 0, 'ai'),
('claude_max_tokens', '4096', 0, 'ai'),
('max_optimization_iterations', '5', 0, 'ai'),

-- PageSpeed
('pagespeed_api_key', '', 1, 'pagespeed'),
('pagespeed_cache_hours', '24', 0, 'pagespeed'),

-- Rate Limiting
('rate_limit_per_minute', '30', 0, 'limits'),
('rate_limit_ai_per_hour', '50', 0, 'limits'),
('default_ai_monthly_limit', '1000', 0, 'limits'),
('default_pagespeed_daily_limit', '100', 0, 'limits'),

-- Logging
('log_level', 'info', 0, 'logging'),
('log_retention_days', '90', 0, 'logging')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

SET FOREIGN_KEY_CHECKS = 1;
