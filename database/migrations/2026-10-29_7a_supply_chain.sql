-- ====================================================================
-- Phase 7a: supply chain core
-- --------------------------------------------------------------------
-- Mga supplier, restock purchase order (RPO) at ang reference ng bawat
-- galaw ng stock. Ginagamit ng includes/purchasing_ops.php.
--
-- Puro dagdag lang (bagong table + nullable/may-default na column), kaya
-- gumagana pa rin ang lumang code pagkatapos nito.
-- ORDER: patakbuhin MUNA ito, saka i-upload ang code ng Phase 7
-- (ang bagong code ay sumusulat sa stock_movements.ref_type / ref_id).
--
-- Laging mag-mysqldump muna. Patakbuhin nang isang beses lang:
-- ang ALTER TABLE ay hindi ligtas ulitin (MySQL/MariaDB parehong supported).
-- Tingnan muna kung tapos na:  SHOW TABLES LIKE 'suppliers';
-- ====================================================================

-- 1) Mga supplier (records lang; walang login sa ngayon)
CREATE TABLE IF NOT EXISTS suppliers (
  id              INT(11)      NOT NULL AUTO_INCREMENT,
  name            VARCHAR(160) NOT NULL,
  contact_person  VARCHAR(120) DEFAULT NULL,
  email           VARCHAR(190) DEFAULT NULL,
  phone           VARCHAR(30)  DEFAULT NULL,
  address         VARCHAR(255) DEFAULT NULL,
  lead_time_days  SMALLINT     NOT NULL DEFAULT 7,     -- ilang araw bago dumating ang order
  payment_terms   VARCHAR(60)  DEFAULT NULL,          -- hal. "COD", "30 days"
  notes           TEXT         DEFAULT NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Products: supplier, puhunan at reorder settings
--    reorder_point DEFAULT 10 = kapareho ng dating "10" sa code, kaya walang nagbabago sa low-stock.
ALTER TABLE products
  ADD COLUMN supplier_id   INT(11)       NULL DEFAULT NULL AFTER category,
  ADD COLUMN cost_price    DECIMAL(10,2) NULL DEFAULT NULL AFTER price,
  ADD COLUMN reorder_point INT(11)       NOT NULL DEFAULT 10 AFTER cost_price,
  ADD COLUMN reorder_qty   INT(11)       NULL DEFAULT NULL AFTER reorder_point,
  ADD KEY idx_products_supplier (supplier_id),
  ADD CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL;

-- 3) Restock purchase orders (bibili TAYO sa supplier).
--    Iba ito sa "Pending PO" ng business customer (benta iyon, nasa orders table).
--    Daloy: draft → sent → partial → received; draft/sent → cancelled.
CREATE TABLE IF NOT EXISTS purchase_orders (
  id               INT(11)       NOT NULL AUTO_INCREMENT,
  po_number        VARCHAR(20)   DEFAULT NULL,         -- RPO-2026-0001; itinatakda pagkatapos ng INSERT (galing sa id)
  supplier_id      INT(11)       NOT NULL,
  location_id      INT(11)       NOT NULL DEFAULT 1,
  status           ENUM('draft','sent','partial','received','cancelled') NOT NULL DEFAULT 'draft',
  expected_date    DATE          DEFAULT NULL,
  notes            TEXT          DEFAULT NULL,
  status_note      VARCHAR(255)  DEFAULT NULL,         -- dahilan ng cancel / "close short"
  total            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_by       INT(11)       DEFAULT NULL,
  created_by_name  VARCHAR(120)  DEFAULT NULL,
  sent_at          DATETIME      DEFAULT NULL,
  received_at      DATETIME      DEFAULT NULL,
  cancelled_at     DATETIME      DEFAULT NULL,
  created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_po_number (po_number),
  KEY idx_po_status (status),
  KEY idx_po_supplier (supplier_id),
  KEY idx_po_location (location_id),
  KEY idx_po_created_by (created_by),
  CONSTRAINT fk_po_supplier   FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT,
  CONSTRAINT fk_po_location   FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE RESTRICT,
  CONSTRAINT fk_po_created_by FOREIGN KEY (created_by)  REFERENCES users (id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id            INT(11)       NOT NULL AUTO_INCREMENT,
  po_id         INT(11)       NOT NULL,
  product_id    INT(11)       NOT NULL,
  qty_ordered   INT(11)       NOT NULL,
  qty_received  INT(11)       NOT NULL DEFAULT 0,
  unit_cost     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  UNIQUE KEY uq_po_product (po_id, product_id),
  KEY idx_poi_product (product_id),
  CONSTRAINT fk_poi_po      FOREIGN KEY (po_id)      REFERENCES purchase_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products (id)        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Pinagmulan ng bawat galaw ng stock ('po' = restock PO, 'order' = order ng customer).
--    NULL = manual (Stock in/out/adjust, CSV import) o lumang row.
ALTER TABLE stock_movements
  ADD COLUMN ref_type VARCHAR(20) NULL DEFAULT NULL AFTER remark,
  ADD COLUMN ref_id   INT(11)     NULL DEFAULT NULL AFTER ref_type,
  ADD KEY idx_sm_ref (ref_type, ref_id);

-- --------------------------------------------------------------------
-- verify:
--   SHOW TABLES LIKE 'suppliers';                      → 1 row
--   SHOW TABLES LIKE 'purchase_order%';                → purchase_orders, purchase_order_items
--   SHOW COLUMNS FROM products LIKE '%reorder%';       → reorder_point (default 10), reorder_qty
--   SELECT COUNT(*) FROM products WHERE reorder_point <> 10;   → 0
--   SHOW COLUMNS FROM stock_movements LIKE 'ref_%';    → ref_type, ref_id
--
-- ROLLBACK (kapag wala pang laman ang mga bagong table):
--   ALTER TABLE stock_movements DROP KEY idx_sm_ref, DROP COLUMN ref_id, DROP COLUMN ref_type;
--   DROP TABLE purchase_order_items;
--   DROP TABLE purchase_orders;
--   ALTER TABLE products DROP FOREIGN KEY fk_products_supplier, DROP KEY idx_products_supplier,
--     DROP COLUMN reorder_qty, DROP COLUMN reorder_point, DROP COLUMN cost_price, DROP COLUMN supplier_id;
--   DROP TABLE suppliers;
-- ====================================================================
