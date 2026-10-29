-- DEMO LANG: gawa-gawang orders sa huling 120 araw para may "buhay" na benta ang forecast at dashboard.
-- Bawat order ay may notes na nagsisimula sa "[DEMO]" at customer na "Demo Customer/Company ...", walang user account.
-- Hindi ginagalaw ang inventory/stock. Ligtas patakbuhin ulit: walang idadagdag kung may [DEMO] na order na.
-- Pangtanggal: database/demo/remove_demo_data.sql
SET NAMES utf8mb4;
SET @has_demo := (SELECT COUNT(*) FROM orders WHERE notes LIKE '[DEMO]%');

-- 150 numero (1..150)
DROP TEMPORARY TABLE IF EXISTS demo_n;
CREATE TEMPORARY TABLE demo_n (n INT PRIMARY KEY);
INSERT INTO demo_n
SELECT a.d + 10 * b.d + 100 * c.d + 1
FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a,
     (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b,
     (SELECT 0 d UNION ALL SELECT 1) c;
DELETE FROM demo_n WHERE n > 150 OR @has_demo > 0;

-- Mga produktong ibebenta (may presyo), may sunod-sunod na index
DROP TEMPORARY TABLE IF EXISTS demo_p;
CREATE TEMPORARY TABLE demo_p (idx INT PRIMARY KEY, product_id INT, price DECIMAL(10,2));
SET @i := -1;
INSERT INTO demo_p SELECT (@i := @i + 1), id, price FROM products WHERE price > 0 ORDER BY id;
SET @cnt := (SELECT COUNT(*) FROM demo_p);

-- Petsa at uri ng bawat order (deterministic: CRC32 ng numero, hindi RAND)
DROP TEMPORARY TABLE IF EXISTS demo_o;
CREATE TEMPORARY TABLE demo_o (n INT PRIMARY KEY, created_at DATETIME, kind VARCHAR(3), st VARCHAR(20));
INSERT INTO demo_o
SELECT n,
       DATE_ADD(DATE_SUB(CURDATE(), INTERVAL age DAY), INTERVAL 480 + (CRC32(CONCAT('t', n)) % 600) MINUTE),
       IF(CRC32(CONCAT('k', n)) % 100 < 12, 'B2B', 'B2C'),
       CASE
         WHEN age >= 14 THEN IF(r < 6, 'cancelled', IF(r < 40, 'delivered', 'completed'))
         WHEN age >= 4  THEN IF(r < 4, 'cancelled', IF(r < 35, 'preparing', IF(r < 70, 'delivered', 'completed')))
         ELSE                IF(r < 50, 'pending', IF(r < 85, 'preparing', 'shipped'))
       END
FROM (SELECT n, CRC32(CONCAT('d', n)) % 120 AS age, CRC32(CONCAT('s', n)) % 100 AS r FROM demo_n) x;

-- Mga linya: 1 palagi, 45% may pangalawa, 15% may pangatlo. Mas madalas ang mababang index (mas popular).
-- Ang B2B ay 3x ang dami. Isa sa bawat 15 order ay bulk (5-10 pcs).
DROP TEMPORARY TABLE IF EXISTS demo_l;
CREATE TEMPORARY TABLE demo_l (n INT, ln INT, product_id INT, qty INT, price DECIMAL(10,2));
INSERT INTO demo_l
SELECT o.n, l.ln, p.product_id,
       IF(CRC32(CONCAT('b', o.n)) % 15 = 0, 5 + CRC32(CONCAT('B', o.n, l.ln)) % 6, 1 + CRC32(CONCAT('q', o.n, l.ln)) % 4) * IF(o.kind = 'B2B', 3, 1),
       p.price
FROM demo_o o
JOIN (SELECT 1 ln UNION ALL SELECT 2 UNION ALL SELECT 3) l
  ON l.ln = 1 OR (l.ln = 2 AND CRC32(CONCAT('l2', o.n)) % 100 < 45) OR (l.ln = 3 AND CRC32(CONCAT('l3', o.n)) % 100 < 15)
JOIN demo_p p ON p.idx = FLOOR(@cnt * POW((CRC32(CONCAT('p', o.n, l.ln)) % 10000) / 10000, 2));

-- Orders (total = 0 muna, kukwentahin pagkatapos ng items)
INSERT INTO orders (user_id, total, address, status, created_at, payment_method, payment_status, customer_name, notes, payment_terms, order_type)
SELECT NULL, 0, 'Demo address, Metro Manila', o.st, o.created_at, 'COD',
       IF(o.st IN ('delivered', 'completed'), 'paid', 'pending'),
       CONCAT(IF(o.kind = 'B2B', 'Demo Company ', 'Demo Customer '), ELT(1 + CRC32(CONCAT('c', o.n)) % 8, 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H')),
       CONCAT('[DEMO] #', o.n), IF(o.kind = 'B2B', 'Net 30', NULL), o.kind
FROM demo_o o;

INSERT INTO order_items (order_id, product_id, quantity, price)
SELECT od.id, l.product_id, SUM(l.qty), l.price
FROM demo_l l JOIN orders od ON od.notes = CONCAT('[DEMO] #', l.n)
GROUP BY od.id, l.product_id, l.price;

UPDATE orders od
  JOIN (SELECT order_id, SUM(quantity * price) t FROM order_items GROUP BY order_id) s ON s.order_id = od.id
   SET od.total = s.t
 WHERE od.notes LIKE '[DEMO]%';

-- Delivery row para sa bawat hindi-cancelled na demo order
INSERT INTO deliveries (order_id, customer_name, address, status, delivery_date, created_at)
SELECT od.id, od.customer_name, od.address,
       CASE od.status WHEN 'shipped' THEN 'preparing' ELSE od.status END,
       IF(od.status = 'pending', NULL, DATE_ADD(DATE(od.created_at), INTERVAL 3 DAY)),
       od.created_at
FROM orders od
WHERE od.notes LIKE '[DEMO]%' AND od.status <> 'cancelled'
  AND NOT EXISTS (SELECT 1 FROM deliveries d WHERE d.order_id = od.id);

DROP TEMPORARY TABLE demo_n, demo_p, demo_o, demo_l;
SELECT COUNT(*) AS demo_orders_now FROM orders WHERE notes LIKE '[DEMO]%';
