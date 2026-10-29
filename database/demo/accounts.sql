-- SweepXpress: tatlong demo account (admin, customer, business) para sa portfolio demo.
--
-- HINDI kasama ang password sa file na ito. Ikaw ang pipili:
--   1. Sa terminal:   php tools/make_password_hash.php
--      (itatanong ang password na gusto mo; ang HASH lang ang ipapakita, hindi ang password)
--   2. Kopyahin ang linyang "SET @demo_hash := '...';" at ipalit sa linya sa ibaba.
--   3. Patakbuhin ang file na ito (phpMyAdmin o: mysql -u root sweepxpress_db < database/demo/accounts.sql)
--
-- Mga account (lahat verified):
--   admin@demo.sweepxpress.com      role admin      Demo Admin
--   customer@demo.sweepxpress.com   role customer   Demo Customer  (nakakabit ang ilang demo order)
--   business@demo.sweepxpress.com   role business   Demo Business  (nakakabit ang ilang demo B2B order)
--
-- PAALALA SA LOGIN: ang bawat login ay humihingi ng OTP na ipinapadala sa email, at walang mailbox ang mga
-- demo address. Para makapag-login sila, ilagay sa .env ng demo site na may DEMO_MODE=1 (tingnan ang README).
-- Sa totoong site na may totoong customer, HUWAG buksan ang DEMO_MODE.
--
-- Ligtas patakbuhin ulit: hindi nito ginagalaw ang account na umiiral na (kaya hindi nire-reset ang password
-- na pinalitan mo). Para i-reset ang password ng tatlo: tingnan ang huling bahagi ng file.
SET NAMES utf8mb4;

SET @demo_hash := 'PALITAN-ITO-NG-HASH-MULA-SA-tools/make_password_hash.php';

-- Hihinto kung hindi mukhang password hash ang laman (bcrypt $2y$ o argon2)
SET @hash_ok := (@demo_hash REGEXP '^[$](2y|argon2id|argon2i)[$]');

INSERT INTO users (name, username, email, password_hash, is_verified, role, phone, streetaddress, city, zipcode)
SELECT d.name, d.username, d.email, @demo_hash, 1, d.role, d.phone, d.street, d.city, d.zip
FROM (
    SELECT 'Demo Admin' AS name, 'demo_admin' AS username, 'admin@demo.sweepxpress.com' AS email, 'admin' AS role,
           NULL AS phone, NULL AS street, NULL AS city, NULL AS zip
    UNION ALL SELECT 'Demo Customer', 'demo_customer', 'customer@demo.sweepxpress.com', 'customer',
           '09170000001', '123 Demo Street, Brgy. Sample', 'Quezon City', '1100'
    UNION ALL SELECT 'Demo Business', 'demo_business', 'business@demo.sweepxpress.com', 'business',
           '09170000002', '456 Sample Avenue, Brgy. Demo', 'Makati City', '1200'
) d
WHERE @hash_ok = 1
  AND NOT EXISTS (SELECT 1 FROM users u WHERE u.email = d.email OR u.username = d.username);

-- Ikabit ang ilang demo order (galing sa database/demo/sales.sql) para may laman ang "My orders"
UPDATE orders o JOIN users u ON u.email = 'customer@demo.sweepxpress.com'
   SET o.user_id = u.id
 WHERE o.notes LIKE '[DEMO]%' AND o.order_type = 'B2C' AND o.customer_name = 'Demo Customer A' AND o.user_id IS NULL;

UPDATE orders o JOIN users u ON u.email = 'business@demo.sweepxpress.com'
   SET o.user_id = u.id
 WHERE o.notes LIKE '[DEMO]%' AND o.order_type = 'B2B' AND o.customer_name = 'Demo Company A' AND o.user_id IS NULL;

SELECT IF(@hash_ok = 1, 'OK', 'HINDI NATULOY: palitan muna ang @demo_hash sa itaas ng totoong hash') AS status;
SELECT id, name, email, role, is_verified FROM users WHERE email LIKE '%@demo.sweepxpress.com' ORDER BY role;

-- ----------------------------------------------------------------------------------------------
-- Para i-RESET ang password ng tatlong demo account (alisin ang "-- " sa unahan ng dalawang linya sa ibaba):
-- UPDATE users SET password_hash = @demo_hash, otp_code = NULL, otp_expires = NULL
--  WHERE email LIKE '%@demo.sweepxpress.com' AND @hash_ok = 1;
-- ----------------------------------------------------------------------------------------------
