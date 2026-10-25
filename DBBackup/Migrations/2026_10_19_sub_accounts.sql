-- --------------------------------------------------------------------------
-- LiquidityBuddy :: sub-accounts of a bank's main account
-- --------------------------------------------------------------------------
-- Run AFTER 2026_10_18_linked_debit_cards.sql. Idempotent.
--
--   mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_19_sub_accounts.sql
--
-- account.parentaccountsessionid: the bank's main account this account is a sub-account of
-- (e.g. Sampath Vishwa sub-accounts under the main Vishwa account). NULL = a main account.
-- Only one level: a sub-account can't be a parent.
-- --------------------------------------------------------------------------

USE `liquiditybuddy`;

ALTER TABLE `account`
  ADD COLUMN IF NOT EXISTS `parentaccountsessionid` char(32) DEFAULT NULL COMMENT 'Main account this is a sub-account of';

ALTER TABLE `account`
  ADD INDEX IF NOT EXISTS `idx_account_parent` (`parentaccountsessionid`);
