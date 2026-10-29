-- DEMO LANG: mga gawa-gawang supplier at assignment para may laman ang supply chain pages.
-- Ligtas patakbuhin ulit (idempotent): hindi nito ginagalaw ang product na may supplier na,
-- at hindi nito pinapalitan ang cost price na nakalagay na.
SET NAMES utf8mb4;

INSERT INTO suppliers (name, contact_person, email, phone, address, lead_time_days, payment_terms, notes, is_active) VALUES
 ('Metro Chem Supply Co.',        'Ramon Dela Cruz', 'orders@metrochem.example',  '0917 000 0101', 'Valenzuela City',    5, 'Net 15',        'Demo supplier: cleaning chemicals', 1),
 ('PackRight Trading',            'Liza Mercado',    'sales@packright.example',   '0917 000 0102', 'Caloocan City',      4, 'Net 30',        'Demo supplier: tapes and adhesives', 1),
 ('CleanTech Equipment Corp.',    'Arvin Santos',    'sales@cleantech.example',   '0917 000 0103', 'Pasig City',        14, '50% down, balance on delivery', 'Demo supplier: machines and mops', 1),
 ('FloorPro Distributors',        'Grace Villanueva','hello@floorpro.example',    '0917 000 0104', 'Quezon City',       10, 'Net 30',        'Demo supplier: matting and floor care', 1),
 ('Brightline Janitorial Wholesale','Noel Reyes',     'noel@brightline.example',   '0917 000 0105', 'Mandaluyong City',   6, 'Net 15',        'Demo supplier: tools, pads and gloves', 1),
 ('3M',                           'Carla Bautista',  'orders@3m-distributor.example', '0917 000 0106', 'Taguig City',      9, 'Net 30',        'Demo supplier: 3M brand floor and surface care', 1)
ON DUPLICATE KEY UPDATE name = name;

-- Supplier ayon sa category (ang "3M" supplier na nasa DB na ang kukuha ng Floor & Surface Care)
UPDATE products p JOIN suppliers s ON s.name = CASE
        WHEN p.category = 'Cleaning Supplies'     THEN 'Metro Chem Supply Co.'
        WHEN p.category = 'Tapes & Adhesives'     THEN 'PackRight Trading'
        WHEN p.category = 'Equipment'             THEN 'CleanTech Equipment Corp.'
        WHEN p.category = 'Floor Mats'            THEN 'FloorPro Distributors'
        WHEN p.category = 'Floor & Surface Care'  THEN '3M'
        ELSE 'Brightline Janitorial Wholesale' END
   SET p.supplier_id = s.id
 WHERE p.supplier_id IS NULL;

-- Cost price = porsyon ng presyo, ayon sa category (kung wala pang cost)
UPDATE products SET cost_price = ROUND(price * CASE category
        WHEN 'Cleaning Supplies' THEN 0.60 WHEN 'Tapes & Adhesives' THEN 0.65
        WHEN 'Equipment' THEN 0.72 WHEN 'Floor Mats' THEN 0.58
        WHEN 'Floor & Surface Care' THEN 0.80 ELSE 0.55 END, 2)
 WHERE cost_price IS NULL;
