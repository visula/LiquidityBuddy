-- --------------------------------------------------------------------------
-- LiquidityBuddy :: Account-wise payment methods + payment ledger
-- --------------------------------------------------------------------------
-- Run AFTER DBBackup/Liquidity_Buddy.sql (or on any existing liquiditybuddy DB).
-- Idempotent: every statement uses IF NOT EXISTS / guarded UPDATEs, so it is
-- safe to run more than once. Requires MariaDB 10.3+ (tested on 10.11).
--
--   mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_02_account_payment_methods.sql
--
-- What it does
--   1. Aligns the base schema with columns the PHP code already reads/writes
--      (user profile cols, user_history, transaction/purchase.datetime,
--      paymentplan start/end/status, payable.duedatetime, travel.usersessionid).
--   2. Adds `paymentmethod`  - payment methods owned by an account
--      (cash, debit card, credit card with limit, bank transfer, ...).
--   3. Adds `payment`        - one row per real-world payment / receipt /
--      transfer, recording the account + method used and FULL / PARTIAL /
--      INSTALLMENT settlement.
--   4. Adds `duepaid`        - allocation of a payment against payables
--      (the Due_Paid entity in the ERD), enabling full and partial settlement.
--   5. Links every `transaction` row to its payment and payment method and
--      stores the account balance after posting.
--   6. Seeds one default payment method per existing account.
-- --------------------------------------------------------------------------

USE `liquiditybuddy`;

-- 1. Base schema alignment ---------------------------------------------------

ALTER TABLE `user`
  ADD COLUMN IF NOT EXISTS `first_name`  varchar(50)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `last_name`   varchar(50)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `profile_pic` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `google_id`   varchar(64)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `created_at`  int(11)      DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `updated_at`  int(11)      DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `user_history` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `loginsessionid`  char(32) DEFAULT NULL,
  `userid`          char(32) DEFAULT NULL,
  `starteddatetime` int(11) DEFAULT NULL,
  `endeddatetime`   int(11) DEFAULT NULL,
  `status`          char(1) DEFAULT 'A',
  `jwttoken`        text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_history_user` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `transaction`
  ADD COLUMN IF NOT EXISTS `datetime` int(11) DEFAULT NULL AFTER `date`;

ALTER TABLE `purchase`
  ADD COLUMN IF NOT EXISTS `datetime` int(11) DEFAULT NULL AFTER `date`;

ALTER TABLE `paymentplan`
  ADD COLUMN IF NOT EXISTS `startdatetime` int(11) DEFAULT NULL AFTER `startdate`,
  ADD COLUMN IF NOT EXISTS `enddatetime`   int(11) DEFAULT NULL AFTER `enddate`,
  ADD COLUMN IF NOT EXISTS `status`        char(1) NOT NULL DEFAULT 'A' COMMENT 'A=Active, S=Settled, C=Cancelled',
  ADD COLUMN IF NOT EXISTS `description`   varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `amount`        decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Total plan amount';

-- travel was created with a typo'd column name; the code writes `updateddatetime`
ALTER TABLE `travel`
  CHANGE COLUMN IF EXISTS `updateddatefime` `updateddatetime` int(11) NOT NULL DEFAULT 0;

ALTER TABLE `payable`
  ADD COLUMN IF NOT EXISTS `duedatetime` int(11) DEFAULT NULL AFTER `duedate`,
  ADD COLUMN IF NOT EXISTS `status` char(1) NOT NULL DEFAULT 'P' COMMENT 'P=Pending, T=Partially paid, S=Settled, C=Cancelled';

ALTER TABLE `travel`
  ADD COLUMN IF NOT EXISTS `usersessionid` char(32) DEFAULT NULL;

-- Backfill derived columns so old rows show up in date-range reports.
UPDATE `transaction` SET `datetime` = COALESCE(`createddatetime`, UNIX_TIMESTAMP(`date`))
 WHERE `datetime` IS NULL;
UPDATE `purchase` SET `datetime` = COALESCE(`createddatetime`, UNIX_TIMESTAMP(`date`))
 WHERE `datetime` IS NULL;
UPDATE `paymentplan` SET `startdatetime` = UNIX_TIMESTAMP(`startdate`)
 WHERE `startdatetime` IS NULL AND `startdate` > '1970-01-01';
UPDATE `paymentplan` SET `enddatetime` = UNIX_TIMESTAMP(`enddate`)
 WHERE `enddatetime` IS NULL AND `enddate` > '1970-01-01';
UPDATE `payable` SET `duedatetime` = UNIX_TIMESTAMP(`duedate`)
 WHERE `duedatetime` IS NULL AND `duedate` > '1970-01-01';
UPDATE `paymentplan` pp SET pp.`amount` =
       (SELECT COALESCE(SUM(p.`dueamount`), 0) FROM `payable` p WHERE p.`paymentplansessionid` = pp.`sessionid`)
 WHERE pp.`amount` = 0;
UPDATE `paymentplan` pp JOIN `purchase` pu ON pu.`sessionid` = pp.`paymentplansessionid`
   SET pp.`description` = pu.`description`
 WHERE pp.`paymentplanlink` = 'purchase' AND pp.`description` IS NULL;
UPDATE `payable` SET `status` = CASE
        WHEN `paidamount` >= `dueamount` THEN 'S'
        WHEN `paidamount` > 0           THEN 'T'
        ELSE 'P' END
 WHERE `status` NOT IN ('C');

-- 2. Payment methods (account wise) -----------------------------------------

CREATE TABLE IF NOT EXISTS `paymentmethod` (
  `id`               int(11) NOT NULL AUTO_INCREMENT,
  `sessionid`        char(32) DEFAULT NULL,
  `accountsessionid` char(32) NOT NULL,
  `usersessionid`    char(32) NOT NULL,
  `name`             varchar(50) NOT NULL,
  `type`             enum('CASH','DEBIT_CARD','CREDIT_CARD','BANK_TRANSFER','ONLINE','CHEQUE','WALLET') NOT NULL DEFAULT 'CASH',
  `reference`        varchar(20) DEFAULT NULL COMMENT 'Masked reference only, e.g. last 4 digits. Never store full card numbers.',
  `creditlimit`      decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'CREDIT_CARD only: account may go negative down to -creditlimit',
  `isdefault`        enum('Y','N') NOT NULL DEFAULT 'N',
  `status`           char(1) NOT NULL DEFAULT 'A' COMMENT 'A=Active, D=Deleted',
  `createddatetime`  int(11) DEFAULT NULL,
  `updateddatetime`  int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sessionid` (`sessionid`),
  KEY `FK_paymentmethod_account` (`accountsessionid`),
  KEY `FK_paymentmethod_user` (`usersessionid`),
  CONSTRAINT `FK_paymentmethod_account` FOREIGN KEY (`accountsessionid`) REFERENCES `account` (`sessionid`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `FK_paymentmethod_user` FOREIGN KEY (`usersessionid`) REFERENCES `user` (`sessionid`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS `paymentmethod_session_generate` BEFORE INSERT ON `paymentmethod`
  FOR EACH ROW SET NEW.sessionid = MD5(UUID());

-- 3. Payment ledger ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `payment` (
  `id`                     int(11) NOT NULL AUTO_INCREMENT,
  `sessionid`              char(32) DEFAULT NULL,
  `usersessionid`          char(32) NOT NULL,
  `accountsessionid`       char(32) NOT NULL,
  `paymentmethodsessionid` char(32) DEFAULT NULL,
  `toaccountsessionid`     char(32) DEFAULT NULL COMMENT 'Internal transfers only',
  `budgetsessionid`        char(32) DEFAULT NULL,
  `direction`              enum('OUT','IN','TRANSFER') NOT NULL DEFAULT 'OUT',
  `category`               varchar(20) NOT NULL DEFAULT 'expense' COMMENT 'expense, purchase, travel, charity, income, transfer, settlement',
  `settlementtype`         enum('FULL','PARTIAL','INSTALLMENT') NOT NULL DEFAULT 'FULL',
  `linktype`               varchar(20) DEFAULT NULL COMMENT 'purchase, paymentplan, travel, ...',
  `linksessionid`          varchar(32) DEFAULT NULL,
  `amount`                 decimal(15,2) NOT NULL DEFAULT 0.00,
  `reference`              varchar(50) DEFAULT NULL,
  `description`            varchar(100) DEFAULT NULL,
  `date`                   date DEFAULT NULL,
  `datetime`               int(11) DEFAULT NULL,
  `status`                 char(1) NOT NULL DEFAULT 'A',
  `createddatetime`        int(11) DEFAULT NULL,
  `updateddatetime`        int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sessionid` (`sessionid`),
  KEY `FK_payment_user` (`usersessionid`),
  KEY `FK_payment_account` (`accountsessionid`),
  KEY `idx_payment_method` (`paymentmethodsessionid`),
  KEY `idx_payment_link` (`linktype`, `linksessionid`),
  KEY `idx_payment_datetime` (`datetime`),
  CONSTRAINT `FK_payment_user` FOREIGN KEY (`usersessionid`) REFERENCES `user` (`sessionid`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `FK_payment_account` FOREIGN KEY (`accountsessionid`) REFERENCES `account` (`sessionid`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS `payment_session_generate` BEFORE INSERT ON `payment`
  FOR EACH ROW SET NEW.sessionid = MD5(UUID());

-- 4. Payment allocation against payables (ERD: Due_Paid) ---------------------

CREATE TABLE IF NOT EXISTS `duepaid` (
  `id`               int(11) NOT NULL AUTO_INCREMENT,
  `sessionid`        char(32) DEFAULT NULL,
  `payablesessionid` char(32) NOT NULL,
  `paymentsessionid` char(32) NOT NULL,
  `settledportion`   decimal(15,2) NOT NULL DEFAULT 0.00,
  `status`           char(1) NOT NULL DEFAULT 'A',
  `createddatetime`  int(11) DEFAULT NULL,
  `updateddatetime`  int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sessionid` (`sessionid`),
  KEY `FK_duepaid_payable` (`payablesessionid`),
  KEY `FK_duepaid_payment` (`paymentsessionid`),
  CONSTRAINT `FK_duepaid_payable` FOREIGN KEY (`payablesessionid`) REFERENCES `payable` (`sessionid`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `FK_duepaid_payment` FOREIGN KEY (`paymentsessionid`) REFERENCES `payment` (`sessionid`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TRIGGER IF NOT EXISTS `duepaid_session_generate` BEFORE INSERT ON `duepaid`
  FOR EACH ROW SET NEW.sessionid = MD5(UUID());

-- 5. Link transactions to payments ------------------------------------------

ALTER TABLE `transaction`
  ADD COLUMN IF NOT EXISTS `paymentsessionid`       char(32) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `paymentmethodsessionid` char(32) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `balanceafter`           decimal(15,2) DEFAULT NULL COMMENT 'Account balance right after this transaction was posted',
  ADD INDEX IF NOT EXISTS `idx_transaction_payment` (`paymentsessionid`),
  ADD INDEX IF NOT EXISTS `idx_transaction_paymentmethod` (`paymentmethodsessionid`);

-- 6. Seed one default payment method per account ----------------------------

INSERT INTO `paymentmethod`
       (`accountsessionid`, `usersessionid`, `name`, `type`, `creditlimit`, `isdefault`, `status`, `createddatetime`, `updateddatetime`)
SELECT a.`sessionid`,
       a.`usersessionid`,
       CASE WHEN LOWER(a.`name`) LIKE '%credit%' THEN 'Credit Card'
            WHEN LOWER(a.`name`) LIKE '%cash%'   THEN 'Cash'
            ELSE 'Bank Transfer' END,
       CASE WHEN LOWER(a.`name`) LIKE '%credit%' THEN 'CREDIT_CARD'
            WHEN LOWER(a.`name`) LIKE '%cash%'   THEN 'CASH'
            ELSE 'BANK_TRANSFER' END,
       0.00, 'Y', 'A', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
  FROM `account` a
 WHERE a.`usersessionid` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `paymentmethod` pm WHERE pm.`accountsessionid` = a.`sessionid`);
