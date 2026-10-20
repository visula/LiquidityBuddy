-- --------------------------------------------------------------------------
-- LiquidityBuddy :: account group type
-- --------------------------------------------------------------------------
-- Run AFTER 2026_10_16_account_number_card_weekend.sql. Idempotent.
--
--   mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_17_account_group_type.sql
--
-- accountgroup.grouptype: BANK = a bank's group. It may hold many accounts, but only one
-- debit card across all of them. OTHER = no limit (the default; existing groups stay OTHER).
-- --------------------------------------------------------------------------

USE `liquiditybuddy`;

ALTER TABLE `accountgroup`
  ADD COLUMN IF NOT EXISTS `grouptype` enum('BANK','OTHER') NOT NULL DEFAULT 'OTHER' COMMENT 'BANK = one debit card across the group';
