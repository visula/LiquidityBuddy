-- --------------------------------------------------------------------------
-- LiquidityBuddy :: password reset tokens
-- --------------------------------------------------------------------------
-- Run AFTER 2026_10_19_sub_accounts.sql. Idempotent.
--
--   mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_20_password_reset.sql
--
-- Only a SHA-256 hash of each token is stored. A token is single use and expires.
-- --------------------------------------------------------------------------

USE `liquiditybuddy`;

CREATE TABLE IF NOT EXISTS `passwordreset` (
  `id`               int(11) NOT NULL AUTO_INCREMENT,
  `sessionid`        char(32) DEFAULT NULL,
  `usersessionid`    char(32) NOT NULL,
  `tokenhash`        char(64) NOT NULL COMMENT 'sha256 of the emailed token',
  `expiresdatetime`  int(11) NOT NULL,
  `useddatetime`     int(11) DEFAULT NULL,
  `createddatetime`  int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_passwordreset_sessionid` (`sessionid`),
  KEY `idx_passwordreset_hash` (`tokenhash`),
  KEY `idx_passwordreset_user` (`usersessionid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TRIGGER IF EXISTS `passwordreset_session_generate`;
CREATE TRIGGER `passwordreset_session_generate` BEFORE INSERT ON `passwordreset`
  FOR EACH ROW SET NEW.sessionid = REPLACE(UUID(), '-', '');
