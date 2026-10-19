-- --------------------------------------------------------------------------
-- LiquidityBuddy :: account number, card availability, plan weekend rule
-- --------------------------------------------------------------------------
-- Run AFTER 2026_10_15_monthly_plan_indexes.sql. Idempotent (safe to re-run).
--
--   mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_16_account_number_card_weekend.sql
--
-- What it does
--   1. account.accountnumber: the bank account number, optional, for reference only.
--   2. paymentmethod.showindropdown: N hides the method from payment and plan
--      dropdowns (e.g. an account with no card). Payments already recorded on it
--      are unaffected.
--   3. planitem.weekendrule: income only. A due on a Saturday or Sunday moves to
--      the previous or next working day (NONE = no change).
-- --------------------------------------------------------------------------

USE `liquiditybuddy`;

ALTER TABLE `account`
  ADD COLUMN IF NOT EXISTS `accountnumber` varchar(40) DEFAULT NULL COMMENT 'Bank account number, for reference';

ALTER TABLE `paymentmethod`
  ADD COLUMN IF NOT EXISTS `showindropdown` enum('Y','N') NOT NULL DEFAULT 'Y' COMMENT 'N = hidden from payment and plan dropdowns';

ALTER TABLE `planitem`
  ADD COLUMN IF NOT EXISTS `weekendrule` enum('NONE','PREV','NEXT') NOT NULL DEFAULT 'NONE' COMMENT 'Income: weekend due moves to the previous or next working day';
