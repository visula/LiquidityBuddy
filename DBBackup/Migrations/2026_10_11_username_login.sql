-- --------------------------------------------------------------------------
-- LiquidityBuddy :: log in with a username or an email
-- --------------------------------------------------------------------------
-- Run AFTER the earlier migrations. Idempotent (safe to re-run). MariaDB 10.3+.
--
--   mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_11_username_login.sql
--
-- Usernames become unique (case-insensitive, like the column collation), so a
-- username identifies exactly one user at login. Users may have a username, an
-- email or both; empty strings are stored as NULL so they never collide.
-- If two users already share a username, every one but the oldest gets
-- "_<id>" appended (e.g. visula_7) before the unique index is added.
-- --------------------------------------------------------------------------

USE `liquiditybuddy`;

UPDATE `user` SET `username` = NULL WHERE `username` = '';
UPDATE `user` SET `email` = NULL WHERE `email` = '';

UPDATE `user` u
  JOIN (SELECT LOWER(`username`) AS uname, MIN(`id`) AS keep_id
          FROM `user`
         WHERE `username` IS NOT NULL
         GROUP BY LOWER(`username`)
        HAVING COUNT(*) > 1) d
    ON LOWER(u.`username`) = d.uname AND u.`id` <> d.keep_id
   SET u.`username` = CONCAT(u.`username`, '_', u.`id`);

ALTER TABLE `user`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_user_username` (`username`),
  ADD INDEX IF NOT EXISTS `idx_user_email` (`email`);
