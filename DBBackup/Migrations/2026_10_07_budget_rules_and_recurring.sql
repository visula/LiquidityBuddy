-- --------------------------------------------------------------------------
-- LiquidityBuddy :: budget rules + recurring (continuous) transactions
-- --------------------------------------------------------------------------
-- Run AFTER 2026_10_02_account_payment_methods.sql. Idempotent (safe to re-run).
-- Requires MariaDB 10.3+.
--
--   mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_07_budget_rules_and_recurring.sql
--
-- What it does
--   1. budget.periodic: the Budget form offers 'A' (Annually) but the enum only
--      had D/W/M/Q/H/Y, so annual budgets were saved with an empty period.
--   2. Adds `recurring` - continuous transactions (ERD Salary_Bill -> Scheduled):
--      salary, rent, subscriptions ... posted as payments every period.
-- --------------------------------------------------------------------------

USE `liquiditybuddy`;

-- 1. Budget period ------------------------------------------------------------

ALTER TABLE `budget`
  MODIFY COLUMN `periodic` enum('D','W','M','Q','H','Y','A') DEFAULT 'M';

UPDATE `budget` SET `periodic` = 'M' WHERE `periodic` IS NULL OR `periodic` = '';

-- 2. Recurring transactions -----------------------------------------------------

CREATE TABLE IF NOT EXISTS `recurring` (
  `id`                     int(11) NOT NULL AUTO_INCREMENT,
  `sessionid`              char(32) DEFAULT NULL,
  `usersessionid`          char(32) NOT NULL,
  `accountsessionid`       char(32) NOT NULL,
  `paymentmethodsessionid` char(32) DEFAULT NULL,
  `budgetsessionid`        char(32) DEFAULT NULL,
  `direction`              enum('IN','OUT') NOT NULL DEFAULT 'OUT',
  `category`               varchar(20) NOT NULL DEFAULT 'expense',
  `description`            varchar(100) NOT NULL,
  `amount`                 decimal(15,2) NOT NULL DEFAULT 0.00,
  `periodic`               enum('D','W','M','A') NOT NULL DEFAULT 'M',
  `startdate`              date NOT NULL,
  `enddate`                date DEFAULT NULL COMMENT 'NULL = no end',
  `nextduedate`            date NOT NULL,
  `lastposteddate`         date DEFAULT NULL,
  `occurrences`            int(11) NOT NULL DEFAULT 0 COMMENT 'Occurrences posted so far',
  `status`                 char(1) NOT NULL DEFAULT 'A' COMMENT 'A=Active, P=Paused, E=Ended, D=Deleted',
  `lasterror`              varchar(200) DEFAULT NULL COMMENT 'Why the last due occurrence could not be posted',
  `createddatetime`        int(11) DEFAULT NULL,
  `updateddatetime`        int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sessionid` (`sessionid`),
  KEY `idx_recurring_due` (`usersessionid`, `status`, `nextduedate`),
  KEY `FK_recurring_account` (`accountsessionid`),
  CONSTRAINT `FK_recurring_user` FOREIGN KEY (`usersessionid`) REFERENCES `user` (`sessionid`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `FK_recurring_account` FOREIGN KEY (`accountsessionid`) REFERENCES `account` (`sessionid`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS `recurring_session_generate` BEFORE INSERT ON `recurring`
  FOR EACH ROW SET NEW.sessionid = MD5(UUID());
