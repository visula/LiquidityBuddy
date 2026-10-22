-- --------------------------------------------------------------------------
-- LiquidityBuddy :: debit cards linked to another account
-- --------------------------------------------------------------------------
-- Run AFTER 2026_10_17_account_group_type.sql. Idempotent.
--
--   mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_18_linked_debit_cards.sql
--
-- paymentmethod.linkedfrom: set on a row that lets this account pay with a debit card
-- that belongs to another account. Linked rows don't count toward the one-card rules.
-- --------------------------------------------------------------------------

USE `liquiditybuddy`;

ALTER TABLE `paymentmethod`
  ADD COLUMN IF NOT EXISTS `linkedfrom` char(32) DEFAULT NULL COMMENT 'sessionid of the card method this links to';

ALTER TABLE `paymentmethod`
  ADD INDEX IF NOT EXISTS `idx_paymentmethod_linkedfrom` (`linkedfrom`);
