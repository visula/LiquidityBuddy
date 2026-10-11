-- --------------------------------------------------------------------------
-- LiquidityBuddy :: account types, bank charges, deposit / withdrawal channels
-- --------------------------------------------------------------------------
-- Run AFTER the earlier migrations. Idempotent (safe to re-run). MariaDB 10.3+.
--
--   mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_13_account_types_and_bank_charges.sql
--
-- What it does
--   1. Every account gets a type (cash, savings, current, fixed deposit,
--      credit card, wallet, other) and the bank it is held at. Internal
--      transfers use them to tell a deposit (cash -> bank) from a withdrawal
--      (bank -> cash) or a bank transfer, and to find the bank's charges.
--   2. Adds `bankproductfee`: charges per bank product, pulled from the rate
--      providers together with the interest rates (e.g. Rs 30 at another
--      bank's ATM, first N withdrawals a month free, % of amount with min/max).
--   3. Payments record the channel used (own ATM, other bank ATM, counter,
--      online ...) so "N free per month" rules can be counted.
--   4. Backfills the type of existing accounts from their linked bank product
--      or their default payment method.
-- --------------------------------------------------------------------------

USE `liquiditybuddy`;

-- 1. Account type and bank -----------------------------------------------------

ALTER TABLE `account`
  ADD COLUMN IF NOT EXISTS `accounttype` enum('CASH','SAVINGS','CURRENT','FIXED_DEPOSIT','CREDIT_CARD','WALLET','OTHER') NOT NULL DEFAULT 'OTHER',
  ADD COLUMN IF NOT EXISTS `bankname`    varchar(100) DEFAULT NULL COMMENT 'Bank the account is held at (from its bank product, or typed in)';

-- A database that already had the column with fewer types gets the full list (existing values are kept)
ALTER TABLE `account`
  MODIFY COLUMN `accounttype` enum('CASH','SAVINGS','CURRENT','FIXED_DEPOSIT','CREDIT_CARD','WALLET','OTHER') NOT NULL DEFAULT 'OTHER';

-- 2. Bank charges per product ------------------------------------------------------

-- An earlier draft of this table (columns feetype/amount, other channel names) is replaced.
-- It only ever held copies of the rate feed, which the next sync loads again.
SET @old_fee_layout := (SELECT COUNT(*) FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = 'bankproductfee' AND column_name = 'feetype');
SET @fee_sql := IF(@old_fee_layout > 0, 'DROP TABLE `bankproductfee`', 'DO 0');
PREPARE fee_stmt FROM @fee_sql;
EXECUTE fee_stmt;
DEALLOCATE PREPARE fee_stmt;

CREATE TABLE IF NOT EXISTS `bankproductfee` (
  `id`                 int(11) NOT NULL AUTO_INCREMENT,
  `productsessionid`   char(32) NOT NULL,
  `txntype`            enum('WITHDRAWAL','DEPOSIT','TRANSFER') NOT NULL,
  `channel`            varchar(20) NOT NULL COMMENT 'OWN_ATM, OTHER_ATM, COUNTER, CDM, ONLINE_OWN, ONLINE_OTHER',
  `flatfee`            decimal(15,2) NOT NULL DEFAULT 0.00,
  `percentfee`         decimal(7,4) NOT NULL DEFAULT 0.0000 COMMENT 'Percent of the amount, e.g. 0.5000',
  `minfee`             decimal(15,2) DEFAULT NULL,
  `maxfee`             decimal(15,2) DEFAULT NULL,
  `freepermonth`       int(11) NOT NULL DEFAULT 0 COMMENT 'This many transactions a month are free',
  `note`               varchar(150) DEFAULT NULL,
  `updateddatetime`    int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bankproductfee` (`productsessionid`, `txntype`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Channel on payments --------------------------------------------------------------

ALTER TABLE `payment`
  ADD COLUMN IF NOT EXISTS `channel` varchar(20) DEFAULT NULL COMMENT 'Deposit / withdrawal / transfer channel',
  ADD INDEX IF NOT EXISTS `idx_payment_channel` (`accountsessionid`, `channel`, `date`);

-- 4. Backfill account types (only accounts still on the default) -----------------------

UPDATE `account` a
  JOIN `bankproduct` bp ON bp.`sessionid` = a.`bankproductsessionid`
   SET a.`accounttype` = CASE bp.`producttype` WHEN 'OTHER' THEN 'OTHER' ELSE bp.`producttype` END,
       a.`bankname` = COALESCE(a.`bankname`, bp.`bankname`)
 WHERE a.`accounttype` = 'OTHER';

UPDATE `account` a
  JOIN `paymentmethod` pm ON pm.`accountsessionid` = a.`sessionid` AND pm.`isdefault` = 'Y' AND pm.`status` = 'A'
   SET a.`accounttype` = CASE pm.`type` WHEN 'CASH' THEN 'CASH' WHEN 'CREDIT_CARD' THEN 'CREDIT_CARD' WHEN 'WALLET' THEN 'WALLET' ELSE 'OTHER' END
 WHERE a.`accounttype` = 'OTHER' AND a.`bankproductsessionid` IS NULL;
