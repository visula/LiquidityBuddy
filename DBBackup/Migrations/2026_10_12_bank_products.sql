-- --------------------------------------------------------------------------
-- LiquidityBuddy :: bank products (interest rates, account types) + interest
-- --------------------------------------------------------------------------
-- Run AFTER the earlier migrations. Idempotent (safe to re-run). MariaDB 10.3+.
--
--   mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_12_bank_products.sql
--
-- What it does
--   1. Adds `bankproduct`: the catalog of bank account products (savings,
--      current, fixed deposit ...) with their interest rate, pulled from the
--      providers configured in app/Config/BankRates.php
--      (php spark bankrates:sync, or "Refresh rates" on the Bank Rates page).
--   2. Adds `bankprovidersync`: last sync result per provider.
--   3. Links an account to a product, and tracks interest posting
--      (posted-through date, optional automatic monthly posting).
-- --------------------------------------------------------------------------

USE `liquiditybuddy`;

-- 1. Product catalog -----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `bankproduct` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `sessionid`       char(32) DEFAULT NULL,
  `providerkey`     varchar(40) NOT NULL COMMENT 'Key of the provider in app/Config/BankRates.php',
  `externalcode`    varchar(100) NOT NULL COMMENT 'Product id in the provider feed (or a hash of bank + product)',
  `bankname`        varchar(100) NOT NULL,
  `productname`     varchar(100) NOT NULL,
  `producttype`     enum('SAVINGS','CURRENT','FIXED_DEPOSIT','OTHER') NOT NULL DEFAULT 'OTHER',
  `interestrate`    decimal(7,4) NOT NULL DEFAULT 0.0000 COMMENT 'Annual rate in percent, e.g. 4.2500',
  `previousrate`    decimal(7,4) DEFAULT NULL COMMENT 'Rate before the last change',
  `minbalance`      decimal(15,2) DEFAULT NULL,
  `currency`        char(3) NOT NULL DEFAULT 'LKR',
  `effectivedate`   date DEFAULT NULL,
  `status`          char(1) NOT NULL DEFAULT 'A' COMMENT 'A=offered, I=no longer in the feed',
  `lastseenat`      int(11) DEFAULT NULL,
  `createddatetime` int(11) DEFAULT NULL,
  `updateddatetime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sessionid` (`sessionid`),
  UNIQUE KEY `uq_bankproduct_provider_code` (`providerkey`, `externalcode`),
  KEY `idx_bankproduct_type` (`producttype`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS `bankproduct_session_generate` BEFORE INSERT ON `bankproduct`
  FOR EACH ROW SET NEW.sessionid = MD5(UUID());

-- 2. Sync log per provider -------------------------------------------------------

CREATE TABLE IF NOT EXISTS `bankprovidersync` (
  `providerkey`  varchar(40) NOT NULL,
  `lastsyncat`   int(11) DEFAULT NULL,
  `laststatus`   char(1) DEFAULT NULL COMMENT 'S=success, F=failed',
  `lastmessage`  varchar(255) DEFAULT NULL,
  `productcount` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`providerkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Account <-> product, interest posting --------------------------------------

ALTER TABLE `account`
  ADD COLUMN IF NOT EXISTS `bankproductsessionid`  char(32) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `interestpostedthrough` date DEFAULT NULL COMMENT 'Interest has been posted for every day up to and including this date',
  ADD COLUMN IF NOT EXISTS `autointerest`          enum('Y','N') NOT NULL DEFAULT 'N' COMMENT 'Post interest automatically after each month ends',
  ADD INDEX IF NOT EXISTS `idx_account_bankproduct` (`bankproductsessionid`);
