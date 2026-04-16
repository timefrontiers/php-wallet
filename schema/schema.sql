-- ============================================================================
-- TimeFrontiers Wallet System - Database Schema
-- ============================================================================
-- Version: 1.0
-- Engine: MySQL 8.0+ / MariaDB 10.5+
-- 
-- USAGE:
--   1. Create your database manually (with any required prefix)
--   2. Run this script against your database
--
-- Example:
--   CREATE DATABASE myprefix_wallet;
--   USE myprefix_wallet;
--   SOURCE schema.sql;
-- ============================================================================

-- ----------------------------------------------------------------------------
-- WALLETS TABLE
-- Stores wallet addresses and ownership
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wallets` (
  `address` VARCHAR(15) NOT NULL COMMENT 'Unique wallet address (e.g., 219123456789012)',
  `user` VARCHAR(32) NOT NULL COMMENT 'Owner user code',
  `currency` VARCHAR(5) NOT NULL COMMENT 'Currency code (NGN, USD, DWL, etc.)',
  `status` ENUM('ACTIVE', 'FROZEN', 'CLOSED') NOT NULL DEFAULT 'ACTIVE',
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_updated` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`address`),
  UNIQUE KEY `uk_user_currency` (`user`, `currency`),
  INDEX `idx_user` (`user`),
  INDEX `idx_currency` (`currency`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Wallet addresses and ownership';

-- ----------------------------------------------------------------------------
-- WALLET HISTORY TABLE
-- Immutable transaction log (append-only, no updates/deletes)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wallet_history` (
  `hash` VARCHAR(32) NOT NULL COMMENT 'Unique transaction hash',
  `origin_hash` VARCHAR(32) NOT NULL COMMENT 'Links credit/debit pair or payment ref',
  `address` VARCHAR(15) NOT NULL COMMENT 'Wallet address',
  `origin` VARCHAR(64) NOT NULL COMMENT 'Source: wallet address, payment ref, or batch ID',
  `batch` VARCHAR(20) NOT NULL COMMENT 'Batch ID (e.g., 127123456789012)',
  `type` ENUM('credit', 'debit') NOT NULL,
  `amount` DECIMAL(20, 8) NOT NULL COMMENT 'Transaction amount',
  `balance` DECIMAL(20, 8) NOT NULL COMMENT 'Balance after transaction',
  `narration` VARCHAR(255) NULL COMMENT 'Transaction description',
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`hash`),
  INDEX `idx_address` (`address`),
  INDEX `idx_address_type` (`address`, `type`),
  INDEX `idx_origin` (`origin`),
  INDEX `idx_origin_hash` (`origin_hash`),
  INDEX `idx_batch` (`batch`),
  INDEX `idx_created` (`_created`),
  INDEX `idx_address_created` (`address`, `_created` DESC),
  
  CONSTRAINT `fk_wallet_history_address`
    FOREIGN KEY (`address`) REFERENCES `wallets` (`address`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable transaction history (append-only)';

-- ----------------------------------------------------------------------------
-- TRANSACTION ALERTS TABLE
-- Queue for pending notifications
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tranx_alert` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tx` VARCHAR(32) NOT NULL COMMENT 'Transaction hash',
  `sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=pending, 1=sent, 2=failed',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_attempt` DATETIME NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  INDEX `idx_sent` (`sent`),
  INDEX `idx_tx` (`tx`),
  INDEX `idx_pending` (`sent`, `_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Transaction notification queue';

-- ----------------------------------------------------------------------------
-- TRANSACTION BATCHES TABLE (Optional)
-- Metadata for batch transactions
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wallet_batches` (
  `batch_id` VARCHAR(20) NOT NULL COMMENT 'Batch identifier (e.g., 127123456789012)',
  `source_address` VARCHAR(15) NOT NULL COMMENT 'Source wallet',
  `total_amount` DECIMAL(20, 8) NOT NULL COMMENT 'Total amount transferred',
  `credit_count` INT UNSIGNED NOT NULL COMMENT 'Number of credits',
  `status` ENUM('PENDING', 'COMPLETED', 'PARTIAL', 'FAILED') NOT NULL DEFAULT 'PENDING',
  `narration` VARCHAR(255) NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_completed` DATETIME NULL,
  
  PRIMARY KEY (`batch_id`),
  INDEX `idx_source` (`source_address`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created` (`_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Batch transaction metadata';

-- ----------------------------------------------------------------------------
-- EXCHANGE RATES TABLE (Optional)
-- For dynamic exchange rates
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exchange_rates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_currency` VARCHAR(5) NOT NULL,
  `to_currency` VARCHAR(5) NOT NULL,
  `rate` DECIMAL(20, 8) NOT NULL,
  `source` VARCHAR(50) NULL COMMENT 'Rate source (manual, api, etc.)',
  `valid_from` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `valid_until` DATETIME NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pair_valid` (`from_currency`, `to_currency`, `valid_from`),
  INDEX `idx_pair` (`from_currency`, `to_currency`),
  INDEX `idx_valid` (`valid_from`, `valid_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Currency exchange rates history';

-- ----------------------------------------------------------------------------
-- LEDGER INTEGRITY TABLE (Optional)
-- Track ledger file checksums for monitoring
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ledger_integrity` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `address` VARCHAR(15) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `checksum` VARCHAR(64) NOT NULL COMMENT 'SHA-256 of ledger file',
  `tx_count` INT UNSIGNED NOT NULL,
  `balance` DECIMAL(20, 8) NOT NULL,
  `verified_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('OK', 'MISMATCH', 'CORRUPT', 'MISSING') NOT NULL DEFAULT 'OK',
  
  PRIMARY KEY (`id`),
  INDEX `idx_address` (`address`),
  INDEX `idx_status` (`status`),
  INDEX `idx_verified` (`verified_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ledger file integrity tracking';

-- ----------------------------------------------------------------------------
-- BALANCE SNAPSHOTS TABLE (Optional)
-- For historical reporting
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `balance_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `address` VARCHAR(15) NOT NULL,
  `balance` DECIMAL(20, 8) NOT NULL,
  `snapshot_date` DATE NOT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_address_date` (`address`, `snapshot_date`),
  INDEX `idx_date` (`snapshot_date`),
  INDEX `idx_address` (`address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Daily balance snapshots for reporting';

-- ============================================================================
-- VIEWS
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Wallet Balances View
-- Quick balance lookup from database
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_wallet_balances` AS
SELECT 
  w.`address`,
  w.`user`,
  w.`currency`,
  w.`status`,
  COALESCE(SUM(CASE WHEN h.`type` = 'credit' THEN h.`amount` ELSE 0 END), 0) AS `total_credit`,
  COALESCE(SUM(CASE WHEN h.`type` = 'debit' THEN h.`amount` ELSE 0 END), 0) AS `total_debit`,
  COALESCE(SUM(CASE WHEN h.`type` = 'credit' THEN h.`amount` ELSE -h.`amount` END), 0) AS `balance`,
  COUNT(h.`hash`) AS `tx_count`,
  MAX(h.`_created`) AS `last_tx_at`,
  w.`_created` AS `created_at`
FROM `wallets` w
LEFT JOIN `wallet_history` h ON w.`address` = h.`address`
GROUP BY w.`address`, w.`user`, w.`currency`, w.`status`, w.`_created`;

-- ----------------------------------------------------------------------------
-- Recent Transactions View
-- Last 1000 transactions across all wallets
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_recent_transactions` AS
SELECT 
  h.`hash`,
  h.`address`,
  w.`user`,
  w.`currency`,
  h.`type`,
  h.`amount`,
  h.`balance`,
  h.`origin`,
  h.`batch`,
  h.`narration`,
  h.`_created`
FROM `wallet_history` h
JOIN `wallets` w ON h.`address` = w.`address`
ORDER BY h.`_created` DESC
LIMIT 1000;

-- ----------------------------------------------------------------------------
-- Pending Alerts View
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_pending_alerts` AS
SELECT 
  a.`id`,
  a.`tx`,
  h.`address`,
  h.`type`,
  h.`amount`,
  w.`user`,
  w.`currency`,
  a.`attempts`,
  a.`_created` AS `queued_at`
FROM `tranx_alert` a
JOIN `wallet_history` h ON a.`tx` = h.`hash`
JOIN `wallets` w ON h.`address` = w.`address`
WHERE a.`sent` = 0
ORDER BY a.`_created` ASC;

-- ============================================================================
-- STORED PROCEDURES
-- ============================================================================

DELIMITER //

-- ----------------------------------------------------------------------------
-- Get wallet balance (from DB, for verification against ledger)
-- ----------------------------------------------------------------------------
CREATE PROCEDURE IF NOT EXISTS `sp_get_wallet_balance`(
  IN p_address VARCHAR(15)
)
BEGIN
  SELECT 
    COALESCE(SUM(CASE WHEN `type` = 'credit' THEN `amount` ELSE 0 END), 0) -
    COALESCE(SUM(CASE WHEN `type` = 'debit' THEN `amount` ELSE 0 END), 0) AS `balance`,
    COUNT(*) AS `tx_count`
  FROM `wallet_history`
  WHERE `address` = p_address;
END //

-- ----------------------------------------------------------------------------
-- Get user's total balance across all wallets
-- ----------------------------------------------------------------------------
CREATE PROCEDURE IF NOT EXISTS `sp_get_user_total_balance`(
  IN p_user VARCHAR(32),
  IN p_currency VARCHAR(5)
)
BEGIN
  SELECT 
    w.`currency`,
    SUM(
      COALESCE(
        (SELECT SUM(CASE WHEN `type` = 'credit' THEN `amount` ELSE -`amount` END)
         FROM `wallet_history` WHERE `address` = w.`address`), 
        0
      )
    ) AS `total_balance`,
    COUNT(w.`address`) AS `wallet_count`
  FROM `wallets` w
  WHERE w.`user` = p_user
    AND (p_currency IS NULL OR w.`currency` = p_currency)
    AND w.`status` = 'ACTIVE'
  GROUP BY w.`currency`;
END //

-- ----------------------------------------------------------------------------
-- Process pending alerts (mark as sent)
-- ----------------------------------------------------------------------------
CREATE PROCEDURE IF NOT EXISTS `sp_process_alert`(
  IN p_alert_id INT,
  IN p_success TINYINT
)
BEGIN
  UPDATE `tranx_alert`
  SET 
    `sent` = IF(p_success = 1, 1, 2),
    `attempts` = `attempts` + 1,
    `last_attempt` = CURRENT_TIMESTAMP
  WHERE `id` = p_alert_id;
END //

-- ----------------------------------------------------------------------------
-- Daily balance snapshot (for reporting)
-- ----------------------------------------------------------------------------
CREATE PROCEDURE IF NOT EXISTS `sp_daily_balance_snapshot`()
BEGIN
  INSERT INTO `balance_snapshots` (`address`, `balance`, `snapshot_date`)
  SELECT 
    `address`,
    `balance`,
    CURDATE()
  FROM `v_wallet_balances`
  WHERE `tx_count` > 0
  ON DUPLICATE KEY UPDATE `balance` = VALUES(`balance`);
END //

DELIMITER ;

-- ============================================================================
-- TRIGGERS (Enforce immutability)
-- ============================================================================

DELIMITER //

-- Prevent updates to wallet_history (immutable)
CREATE TRIGGER `trg_wallet_history_no_update`
BEFORE UPDATE ON `wallet_history`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Updates not allowed on wallet_history (immutable)';
END //

-- Prevent deletes from wallet_history (immutable)
CREATE TRIGGER `trg_wallet_history_no_delete`
BEFORE DELETE ON `wallet_history`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Deletes not allowed on wallet_history (immutable)';
END //

DELIMITER ;
