-- DEMO LANG: gawa-gawang restock purchase order para may kasaysayan ang supplier scorecard.
-- Kailangan munang naipatakbo ang database/demo/suppliers.sql (may supplier at cost price ang mga product).
-- Bawat PO ay may notes na "[DEMO]". Hindi ginagalaw ang stock at ang cost price ng product.
-- 6 na natapos na PO bawat supplier (huling ~280 araw) at 3 bukas na PO (isang overdue, isang paparating, isang partial).
-- Ligtas patakbuhin ulit: walang idadagdag kung may [DEMO] na PO na. Pangtanggal: database/demo/remove_demo_data.sql
SET NAMES utf8mb4;
SET @has_demo := (SELECT COUNT(*) FROM purchase_orders WHERE notes LIKE '[DEMO]%');

-- Ugali ng bawat supplier: on-time %, fill %, at pagtaas ng presyo mula una hanggang huling PO (%)
DROP TEMPORARY TABLE IF EXISTS demo_prof;
CREATE TEMPORARY TABLE demo_prof (supplier_id INT PRIMARY KEY, name VARCHAR(120), lead INT, on_time INT, fill INT, drift INT, cnt INT);
INSERT INTO demo_prof
SELECT s.id, s.name, s.lead_time_days,
       CASE s.name WHEN 'Metro Chem Supply Co.' THEN 92 WHEN 'PackRight Trading' THEN 70 WHEN 'CleanTech Equipment Corp.' THEN 40
                   WHEN 'FloorPro Distributors' THEN 85 WHEN 'Brightline Janitorial Wholesale' THEN 80 ELSE 75 END,
       CASE s.name WHEN 'Metro Chem Supply Co.' THEN 100 WHEN 'PackRight Trading' THEN 92 WHEN 'CleanTech Equipment Corp.' THEN 78
                   WHEN 'FloorPro Distributors' THEN 95 WHEN 'Brightline Janitorial Wholesale' THEN 98 ELSE 90 END,
       CASE s.name WHEN 'Metro Chem Supply Co.' THEN 0 WHEN 'PackRight Trading' THEN 14 WHEN 'CleanTech Equipment Corp.' THEN 3
                   WHEN 'FloorPro Distributors' THEN 9 WHEN 'Brightline Janitorial Wholesale' THEN -2 ELSE 4 END,
       (SELECT COUNT(*) FROM products p WHERE p.supplier_id = s.id AND p.cost_price > 0)
FROM suppliers s
WHERE @has_demo = 0 AND s.is_active = 1
  AND (SELECT COUNT(*) FROM products p WHERE p.supplier_id = s.id AND p.cost_price > 0) > 0;

-- Mga product ng supplier, may rank 0,1,2...
DROP TEMPORARY TABLE IF EXISTS demo_sp;
CREATE TEMPORARY TABLE demo_sp (supplier_id INT, rk INT, product_id INT, cost DECIMAL(10,2), PRIMARY KEY (supplier_id, rk));
INSERT INTO demo_sp
SELECT p.supplier_id,
       (SELECT COUNT(*) FROM products q WHERE q.supplier_id = p.supplier_id AND q.cost_price > 0 AND q.id < p.id),
       p.id, p.cost_price
FROM products p
WHERE p.supplier_id IN (SELECT supplier_id FROM demo_prof) AND p.cost_price > 0;

DROP TEMPORARY TABLE IF EXISTS demo_k;
CREATE TEMPORARY TABLE demo_k (k INT PRIMARY KEY);
INSERT INTO demo_k VALUES (1),(2),(3),(4),(5),(6),(7),(8),(9);

-- Mga PO: k 1..6 natapos; 7 = overdue (CleanTech), 8 = paparating (PackRight), 9 = partial (Metro Chem)
DROP TEMPORARY TABLE IF EXISTS demo_po;
CREATE TEMPORARY TABLE demo_po (supplier_id INT, k INT, tag VARCHAR(12) COLLATE utf8mb4_unicode_ci, sent DATETIME, expected DATE, received DATETIME, st VARCHAR(10), PRIMARY KEY (supplier_id, k));
INSERT INTO demo_po
SELECT supplier_id, k, tag, sent, expected,
       IF(k <= 6, DATE_ADD(expected, INTERVAL (IF(CRC32(CONCAT('o', supplier_id, k)) % 100 < on_time,
                                                  -CAST(CRC32(CONCAT('e', supplier_id, k)) % 3 AS SIGNED),
                                                  1 + CRC32(CONCAT('l', supplier_id, k)) % 6) * 24 + 10) HOUR), NULL),
       IF(k <= 6, 'received', IF(k = 9, 'partial', 'sent'))
FROM (
    SELECT pr.supplier_id, k.k, pr.on_time, CONCAT('D', LPAD(pr.supplier_id * 10 + k.k, 3, '0')) AS tag,
           DATE_ADD(DATE_SUB(CURDATE(), INTERVAL
               CASE WHEN k.k <= 6 THEN 280 - (k.k - 1) * 46 - CAST(CRC32(CONCAT('d', pr.supplier_id, k.k)) % 7 AS SIGNED)
                    WHEN k.k = 7 THEN 30 WHEN k.k = 8 THEN 2 ELSE 12 END DAY), INTERVAL 9 HOUR) AS sent,
           DATE_ADD(DATE_SUB(CURDATE(), INTERVAL
               CASE WHEN k.k <= 6 THEN 280 - (k.k - 1) * 46 - CAST(CRC32(CONCAT('d', pr.supplier_id, k.k)) % 7 AS SIGNED)
                    WHEN k.k = 7 THEN 30 WHEN k.k = 8 THEN 2 ELSE 12 END DAY), INTERVAL pr.lead DAY) AS expected
    FROM demo_prof pr JOIN demo_k k
    WHERE k.k <= 6
       OR (k.k = 7 AND pr.name = 'CleanTech Equipment Corp.')
       OR (k.k = 8 AND pr.name = 'PackRight Trading')
       OR (k.k = 9 AND pr.name = 'Metro Chem Supply Co.')
) x;

-- Mga linya: unang product (rank a) palagi; pangalawa (rank a+1) kung may higit sa isang product ang supplier
DROP TEMPORARY TABLE IF EXISTS demo_l;
CREATE TEMPORARY TABLE demo_l (supplier_id INT, k INT, product_id INT, ordered INT, received INT, cost DECIMAL(10,2));
INSERT INTO demo_l
SELECT po.supplier_id, po.k, sp.product_id,
       20 + CRC32(CONCAT('q1', po.supplier_id, po.k)) % 41,
       0, ROUND(sp.cost * (1 + pr.drift / 100 * (LEAST(po.k, 6) - 1) / 5 + (CAST(CRC32(CONCAT('n1', po.supplier_id, po.k)) % 3 AS SIGNED) - 1) / 100), 2)
FROM demo_po po
JOIN demo_prof pr ON pr.supplier_id = po.supplier_id
JOIN demo_sp sp ON sp.supplier_id = po.supplier_id AND sp.rk = (po.k + po.supplier_id) % pr.cnt;

INSERT INTO demo_l
SELECT po.supplier_id, po.k, sp.product_id,
       20 + CRC32(CONCAT('q2', po.supplier_id, po.k)) % 41,
       0, ROUND(sp.cost * (1 + pr.drift / 100 * (LEAST(po.k, 6) - 1) / 5 + (CAST(CRC32(CONCAT('n2', po.supplier_id, po.k)) % 3 AS SIGNED) - 1) / 100), 2)
FROM demo_po po
JOIN demo_prof pr ON pr.supplier_id = po.supplier_id AND pr.cnt > 1
JOIN demo_sp sp ON sp.supplier_id = po.supplier_id AND sp.rk = (po.k + po.supplier_id + 1) % pr.cnt;

-- Natanggap: buo o kulang (ayon sa fill % ng supplier); ang partial ay kalahati; ang sent ay wala pa
UPDATE demo_l l
  JOIN demo_po po ON po.supplier_id = l.supplier_id AND po.k = l.k
  JOIN demo_prof pr ON pr.supplier_id = l.supplier_id
   SET l.received = CASE po.st
         WHEN 'received' THEN IF(CRC32(CONCAT('f', l.supplier_id, l.k, l.product_id)) % 100 < pr.fill, l.ordered, ROUND(l.ordered * 0.7))
         WHEN 'partial'  THEN ROUND(l.ordered * 0.5) ELSE 0 END;

START TRANSACTION;

INSERT INTO purchase_orders (po_number, supplier_id, location_id, status, expected_date, notes, status_note, total, created_by, created_by_name, sent_at, received_at, created_at)
SELECT CONCAT('RPO-', YEAR(po.sent), '-', po.tag), po.supplier_id, 1, po.st, po.expected, '[DEMO] restock', NULL, 0, NULL, 'Demo', po.sent, po.received, po.sent
FROM demo_po po;

INSERT INTO purchase_order_items (po_id, product_id, qty_ordered, qty_received, unit_cost)
SELECT o.id, l.product_id, l.ordered, l.received, l.cost
FROM demo_l l
JOIN demo_po po ON po.supplier_id = l.supplier_id AND po.k = l.k
JOIN purchase_orders o ON o.po_number = CONCAT('RPO-', YEAR(po.sent), '-', po.tag);

UPDATE purchase_orders o
  JOIN (SELECT po_id, SUM(qty_ordered * unit_cost) t, SUM(qty_received < qty_ordered) short FROM purchase_order_items GROUP BY po_id) s ON s.po_id = o.id
   SET o.total = s.t,
       o.status_note = IF(o.status = 'received' AND s.short > 0, 'Closed short: supplier could not fill the full quantity', NULL)
 WHERE o.notes LIKE '[DEMO]%';

COMMIT;

DROP TEMPORARY TABLE demo_prof, demo_sp, demo_k, demo_po, demo_l;
SELECT status, COUNT(*) AS demo_pos FROM purchase_orders WHERE notes LIKE '[DEMO]%' GROUP BY status;
