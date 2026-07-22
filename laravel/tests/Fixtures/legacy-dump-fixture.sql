-- Minimal fixture mirroring banking_dashboard.sql's mysqldump format: one
-- physical line per INSERT statement, column order matching the real dump's
-- CREATE TABLE order for items/institutions/accounts/transactions.
--
-- Data shape: user '1' (the target legacy user) owns item 1 -> institution 10
-- -> account 100 -> transactions 1000/1001. User '2' (someone else) owns
-- item 2 -> institution 20 -> account 200 -> transaction 1002 — every one of
-- these rows must be filtered out of the import.
INSERT INTO `users` VALUES (1,'legacyuser@example.com'),(2,'otheruser@example.com');
INSERT INTO `items` VALUES (1,'1','plaid-item-abc',10,'access-tok-1','2020-01-01 00:00:00','2020-01-01 00:00:00'),(2,'2','plaid-item-xyz',20,'access-tok-2','2020-01-01 00:00:00','2020-01-01 00:00:00');
INSERT INTO `institutions` VALUES (10,'Chase Bank','ins_chase',0,NULL,0,'https://chase.com','/img/chase.png','base64logodata','#005a9c'),(20,'Other Bank','ins_other',0,NULL,0,'https://other.com','/img/other.png','logo2','#123456');
INSERT INTO `accounts` VALUES (100,1,'plaid-acc-checking','1234','Checking Account','Chase Total Checking','checking','depository','encrypted-balances-1'),(200,2,'plaid-acc-other','5678','Other Account','Other Bank Checking','checking','depository','encrypted-balances-2');
INSERT INTO `transactions` VALUES (1000,100,'txn-abc-1',NULL,NULL,'Trader Joe\'s','-45.67','0','place','USD',NULL,NULL,'2019-05-01',NULL,NULL,NULL,'2019-05-01 00:00:00','2019-05-01 00:00:00',NULL),(1001,100,'txn-abc-2','5',NULL,'Paycheck','1500.00','0','special','USD',NULL,NULL,'2019-05-02','["Payroll"]','{"address":"123 Main St"}','{"reference_number":"1234"}','2019-05-02 00:00:00','2019-05-02 00:00:00',NULL),(1002,200,'txn-other-1',NULL,NULL,'Should Not Import','-10.00','0','place','USD',NULL,NULL,'2019-05-03',NULL,NULL,NULL,'2019-05-03 00:00:00','2019-05-03 00:00:00',NULL);
