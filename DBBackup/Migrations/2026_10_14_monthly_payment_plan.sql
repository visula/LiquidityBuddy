-- --------------------------------------------------------------------------
-- LiquidityBuddy :: Monthly Payment Plan (docs/plans/MONTHLY_PAYMENT_PLAN.md)
-- --------------------------------------------------------------------------
-- Run AFTER the earlier migrations. Idempotent (safe to re-run). MariaDB 10.3+.
--
--   mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_14_monthly_payment_plan.sql
--
-- What it does
--   1. plancategory : groups plan items, holds the Planning sheet's allocation %.
--   2. planitem     : one line of the Planned Total Expenses sheet (income, due,
--                     transfer or spending envelope) with its account, method,
--                     budget, due day, tracking start and end date.
--   3. planentry    : one cell of the grid - an item's planned amount for one
--                     month; DUE entries from the tracking start get a payable.
--   4. paymentplan.direction (OUT / IN / TRANSFER), backfilled from the link.
--      Plan items own a paymentplan with paymentplanlink = 'planitem'.
-- --------------------------------------------------------------------------

USE `liquiditybuddy`;

-- 1. Categories ----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `plancategory` (
  `id`                int(11) NOT NULL AUTO_INCREMENT,
  `sessionid`         char(32) DEFAULT NULL,
  `usersessionid`     char(32) NOT NULL,
  `name`              varchar(50) NOT NULL,
  `direction`         enum('IN','OUT') NOT NULL DEFAULT 'OUT',
  `allocationpercent` decimal(5,2) DEFAULT NULL COMMENT '% of planned monthly income; NULL = no target',
  `sortorder`         int(11) NOT NULL DEFAULT 0,
  `status`            char(1) NOT NULL DEFAULT 'A',
  `createddatetime`   int(11) DEFAULT NULL,
  `updateddatetime`   int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sessionid` (`sessionid`),
  UNIQUE KEY `uq_plancategory_user_name` (`usersessionid`, `name`),
  CONSTRAINT `FK_plancategory_user` FOREIGN KEY (`usersessionid`) REFERENCES `user` (`sessionid`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS `plancategory_session_generate` BEFORE INSERT ON `plancategory`
  FOR EACH ROW SET NEW.sessionid = MD5(UUID());

-- 2. Items -------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `planitem` (
  `id`                      int(11) NOT NULL AUTO_INCREMENT,
  `sessionid`               char(32) DEFAULT NULL,
  `usersessionid`           char(32) NOT NULL,
  `itemkey`                 varchar(60) NOT NULL COMMENT 'Slug, unique per user; makes the import idempotent',
  `name`                    varchar(100) NOT NULL,
  `direction`               enum('IN','OUT','TRANSFER') NOT NULL DEFAULT 'OUT',
  `kind`                    enum('DUE','ENVELOPE') NOT NULL DEFAULT 'DUE' COMMENT 'DUE creates a payable each month; ENVELOPE is a spending cap tracked by budget',
  `plancategorysessionid`   char(32) DEFAULT NULL,
  `amounttype`              enum('FIXED','ESTIMATE','VARIABLE') NOT NULL DEFAULT 'FIXED',
  `defaultamount`           decimal(15,2) NOT NULL DEFAULT 0.00,
  `dueday`                  tinyint(4) DEFAULT NULL COMMENT '1-31, clamped to month end; NULL = last day of the month',
  `startmonth`              date DEFAULT NULL,
  `trackfrom`               date NOT NULL COMMENT 'Entries before this month are historical (no payable)',
  `recurring`               tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = roll forward keeps adding months',
  `enddate`                 date DEFAULT NULL,
  `accountsessionid`        char(32) DEFAULT NULL,
  `paymentmethodsessionid`  char(32) DEFAULT NULL,
  `toaccountsessionid`      char(32) DEFAULT NULL,
  `budgetsessionid`         char(32) DEFAULT NULL,
  `sourceplanitemsessionid` char(32) DEFAULT NULL COMMENT 'Derived item: amount = source amount x shareratio',
  `shareratio`              decimal(6,4) DEFAULT NULL,
  `stakeholder`             varchar(60) DEFAULT NULL,
  `paymentplansessionid`    char(32) DEFAULT NULL,
  `notes`                   varchar(255) DEFAULT NULL,
  `sortorder`               int(11) NOT NULL DEFAULT 0,
  `status`                  char(1) NOT NULL DEFAULT 'A' COMMENT 'A=active, E=ended, D=deleted',
  `createddatetime`         int(11) DEFAULT NULL,
  `updateddatetime`         int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sessionid` (`sessionid`),
  UNIQUE KEY `uq_planitem_user_key` (`usersessionid`, `itemkey`),
  KEY `idx_planitem_category` (`plancategorysessionid`),
  CONSTRAINT `FK_planitem_user` FOREIGN KEY (`usersessionid`) REFERENCES `user` (`sessionid`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS `planitem_session_generate` BEFORE INSERT ON `planitem`
  FOR EACH ROW SET NEW.sessionid = MD5(UUID());

-- 3. Entries (grid cells) -------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `planentry` (
  `id`                     int(11) NOT NULL AUTO_INCREMENT,
  `sessionid`              char(32) DEFAULT NULL,
  `usersessionid`          char(32) NOT NULL,
  `planitemsessionid`      char(32) NOT NULL,
  `month`                  date NOT NULL COMMENT 'First day of the month',
  `plannedamount`          decimal(15,2) NOT NULL DEFAULT 0.00,
  `duedate`                date NOT NULL,
  `paymentmethodsessionid` char(32) DEFAULT NULL COMMENT 'Override for this month only',
  `payablesessionid`       char(32) DEFAULT NULL,
  `overridden`             tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Derived amount edited by the user',
  `status`                 char(1) NOT NULL DEFAULT 'A' COMMENT 'A=active, H=historical (no payable), X=skipped',
  `createddatetime`        int(11) DEFAULT NULL,
  `updateddatetime`        int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sessionid` (`sessionid`),
  UNIQUE KEY `uq_planentry_item_month` (`planitemsessionid`, `month`),
  KEY `idx_planentry_user_month` (`usersessionid`, `month`),
  KEY `idx_planentry_payable` (`payablesessionid`),
  CONSTRAINT `FK_planentry_user` FOREIGN KEY (`usersessionid`) REFERENCES `user` (`sessionid`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS `planentry_session_generate` BEFORE INSERT ON `planentry`
  FOR EACH ROW SET NEW.sessionid = MD5(UUID());

-- 4. Direction on payment plans ---------------------------------------------------------------

ALTER TABLE `paymentplan`
  ADD COLUMN IF NOT EXISTS `direction` enum('OUT','IN','TRANSFER') DEFAULT NULL COMMENT 'NULL on old rows = derived from paymentplanlink',
  ADD INDEX IF NOT EXISTS `idx_paymentplan_link` (`paymentplanlink`, `paymentplansessionid`);

UPDATE `paymentplan` SET `direction` = IF(`paymentplanlink` = 'income', 'IN', 'OUT') WHERE `direction` IS NULL;
