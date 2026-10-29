-- SweepXpress: tanggalin ang LAHAT ng demo data na ginawa ng database/demo/*.sql. Hindi nito ginagalaw ang
-- tunay na order, supplier, produkto o account. Mag-mysqldump muna.
--
-- Ang mga produkto, stock at ang cost price/reorder point na itinakda ng suppliers.sql ay HINDI ibinabalik.
SET NAMES utf8mb4;

-- Demo orders (kasama ang items at deliveries sa pamamagitan ng ON DELETE CASCADE)
DELETE FROM orders WHERE notes LIKE '[DEMO]%';

-- Demo purchase orders (kasama ang items)
DELETE FROM purchase_orders WHERE notes LIKE '[DEMO]%';

-- Demo suppliers (wala nang PO na nakaharang; ang mga produktong nakakabit ay magiging walang supplier)
DELETE FROM suppliers WHERE notes LIKE 'Demo supplier:%';

-- Demo accounts at ang mga tala nila
DELETE FROM user_logs WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@demo.sweepxpress.com');
DELETE FROM users WHERE email LIKE '%@demo.sweepxpress.com';

SELECT (SELECT COUNT(*) FROM orders WHERE notes LIKE '[DEMO]%') AS demo_orders_left,
       (SELECT COUNT(*) FROM purchase_orders WHERE notes LIKE '[DEMO]%') AS demo_pos_left,
       (SELECT COUNT(*) FROM users WHERE email LIKE '%@demo.sweepxpress.com') AS demo_accounts_left;
