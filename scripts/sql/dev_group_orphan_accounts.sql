-- DEMO DATA HELPER - not part of the schema migration.
-- In DBBackup/Liquidity_Buddy.sql the original accounts (Cash in Hand, Sampath ...,
-- Combank ...) have no account group. The app lists accounts through an inner join on
-- accountgroup, so those accounts never appear in the UI. This puts every ungrouped
-- active account into its owner's first active account group so the demo shows them.
-- Safe to re-run; it only touches accounts whose accountgroupid is NULL.
USE `liquiditybuddy`;

UPDATE `account` a
   SET a.`accountgroupid` = (
        SELECT g.`sessionid` FROM `accountgroup` g
         WHERE g.`usersessionid` = a.`usersessionid` AND g.`status` = 'A'
         ORDER BY g.`id` LIMIT 1)
 WHERE a.`accountgroupid` IS NULL
   AND a.`status` = 'A'
   AND EXISTS (SELECT 1 FROM `accountgroup` g2
                WHERE g2.`usersessionid` = a.`usersessionid` AND g2.`status` = 'A');

SELECT CONCAT(ROW_COUNT(), ' account(s) moved into a group') AS result;
