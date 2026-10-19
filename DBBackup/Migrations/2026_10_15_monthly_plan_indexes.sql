-- --------------------------------------------------------------------------
-- LiquidityBuddy :: Monthly Payment Plan - grid / month view indexes
-- --------------------------------------------------------------------------
-- Run AFTER 2026_10_14_monthly_payment_plan.sql. Idempotent (safe to re-run).
--
--   mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_15_monthly_plan_indexes.sql
--
-- What it does
--   1. payable(paymentplansessionid, status): every plan page, the Dued tab and
--      the payment plan sync look payables up by their plan; the column had no
--      index, so each lookup scanned the whole table.
--   2. transaction(budgetsessionid, date): envelope actuals sum a budget's
--      expenses within one month.
-- --------------------------------------------------------------------------

USE `liquiditybuddy`;

ALTER TABLE `payable`
  ADD INDEX IF NOT EXISTS `idx_payable_plan_status` (`paymentplansessionid`, `status`);

ALTER TABLE `transaction`
  ADD INDEX IF NOT EXISTS `idx_transaction_budget_date` (`budgetsessionid`, `date`);
