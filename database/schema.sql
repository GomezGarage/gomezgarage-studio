-- ==========================================================================
-- GOMEZ GARAGE STUDIO — MySQL schema
-- Per ARCHITECTURE.md section 6.2. Run once on a fresh database.
--
-- Migrating an already-deployed database: CREATE TABLE IF NOT EXISTS won't
-- retroactively add new columns to an existing `orders` table. If you ran
-- this file before the payment_method column was added, run once:
--   ALTER TABLE orders ADD COLUMN payment_method VARCHAR(20) DEFAULT 'card' AFTER status;
-- ==========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------------------
-- Produse
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) UNIQUE,
  name_en VARCHAR(200),
  name_ro VARCHAR(200),
  desc_short_en TEXT,
  desc_short_ro TEXT,
  desc_long_en TEXT,
  desc_long_ro TEXT,
  how_made_en TEXT,
  how_made_ro TEXT,
  material VARCHAR(100),
  category ENUM('desk','display','automotive','other'),
  price_eur DECIMAL(10,2),
  stock INT DEFAULT 0,
  stock_threshold INT DEFAULT 3,
  images JSON,                    -- array de paths
  specs JSON,                     -- array de {key, value}
  status ENUM('published','draft') DEFAULT 'draft',
  featured TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
-- Comenzi
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(20) UNIQUE,  -- GGS-2026-0001
  revolut_order_id VARCHAR(100),
  status ENUM('pending','paid','processing','shipped','delivered','cancelled') DEFAULT 'pending',
  payment_method VARCHAR(20) DEFAULT 'card', -- 'card' (Revolut online) | 'cod' (cash on delivery)
  customer_name VARCHAR(200),
  customer_email VARCHAR(200),
  customer_phone VARCHAR(50),
  shipping_address JSON,            -- {street, city, postal, country}
  customer_note TEXT,
  items JSON,                       -- [{product_id, name, price_eur, qty}]
  subtotal_eur DECIMAL(10,2),
  shipping_eur DECIMAL(10,2),
  total_eur DECIMAL(10,2),
  tracking_number VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
-- Admin users
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) UNIQUE,
  password_hash VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
-- Settings (key/value store)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  key_name VARCHAR(100) PRIMARY KEY,
  value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================================================
-- Seed data — default settings
-- ==========================================================================
INSERT INTO settings (key_name, value) VALUES
  ('order_notify_email', 'contact@gomezgarage.eu'),
  ('low_stock_notify_email', 'contact@gomezgarage.eu'),
  ('default_stock_threshold', '3'),
  ('shipping_ro_eur', '5.00'),
  ('shipping_eu_eur', '12.00'),
  ('shipping_uk_eur', '18.00'),
  ('default_currency', 'EUR'),
  ('eur_ron_rate', '5.00')
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- ==========================================================================
-- Seed data — default admin user
-- Username: admin / Password: ChangeMe123!  (bcrypt hash below)
-- CHANGE THIS PASSWORD IMMEDIATELY AFTER FIRST LOGIN.
-- Hash generated with PHP: password_hash('ChangeMe123!', PASSWORD_BCRYPT)
-- ==========================================================================
INSERT INTO admin_users (username, password_hash) VALUES
  ('admin', '$2y$05$VyR8S8nN5GfjTJK1rkBFUe3dFQFbyWMGzggGChtpUaDnhRWwD.PNq')
ON DUPLICATE KEY UPDATE username = username;

-- ==========================================================================
-- Seed data — demo products (placeholder imagery, safe to delete in admin)
-- ==========================================================================
INSERT INTO products
  (slug, name_en, name_ro, desc_short_en, desc_short_ro, desc_long_en, desc_long_ro, how_made_en, how_made_ro, material, category, price_eur, stock, stock_threshold, images, specs, status, featured)
VALUES
(
  'cf-desk-organizer', 'CF Desk Organizer', 'Organizator Birou CF',
  'A modular carbon-fiber desk organizer built to disappear into your setup.',
  'Un organizator modular de birou din fibra de carbon, gandit sa se integreze perfect in setup-ul tau.',
  'The CF Desk Organizer keeps cables, tools and small parts contained without cluttering your workspace. Designed from scratch in CAD and printed in ASA-CF for a matte, fiber-textured finish that resists warping and UV fading.',
  'Organizatorul CF pastreaza cablurile, uneltele si piesele mici organizate fara sa aglomereze spatiul de lucru. Proiectat de la zero in CAD si printat in ASA-CF pentru un finisaj mat, texturat, rezistent la deformare si la radiatii UV.',
  'Printed in-house on industrial FDM printers with heated chambers using ASA-CF filament, then hand-sanded and post-processed for a uniform matte finish.',
  'Printat in-house pe imprimante industriale FDM cu camera incalzita, folosind filament ASA-CF, apoi slefuit manual si post-procesat pentru un finisaj mat uniform.',
  'ASA-CF', 'desk', 39.00, 14, 3,
  JSON_ARRAY('/studio/assets/images/products/desk-organizer-1.svg', '/studio/assets/images/products/desk-organizer-2.svg'),
  JSON_ARRAY(JSON_OBJECT('key','Dimensions','value','220 x 100 x 60 mm'), JSON_OBJECT('key','Weight','value','180g'), JSON_OBJECT('key','Compatibility','value','Universal desk setup')),
  'published', 1
),
(
  'phone-dock-pro', 'Phone Dock Pro', 'Suport Telefon Pro',
  'A weighted phone dock with cable channel routing, printed in fiber-reinforced PETG.',
  'Un suport de telefon cu greutate, cu canal integrat pentru cablu, printat in PETG cu fibra.',
  'Phone Dock Pro holds your phone at the ideal viewing angle while routing your charging cable cleanly underneath. PETG-CF gives it the strength and weight to stay put.',
  'Phone Dock Pro tine telefonul la unghiul ideal de vizualizare, in timp ce cablul de incarcare este directionat curat pe dedesubt. PETG-CF ii ofera rezistenta si greutatea necesara pentru stabilitate.',
  'CAD-designed for a snug universal fit, printed in PETG-CF, then de-strung and inspected by hand before packing.',
  'Proiectat in CAD pentru o potrivire universala, printat in PETG-CF, apoi curatat de fire si inspectat manual inainte de ambalare.',
  'PETG-CF', 'desk', 24.00, 22, 5,
  JSON_ARRAY('/studio/assets/images/products/phone-dock-1.svg', '/studio/assets/images/products/phone-dock-2.svg'),
  JSON_ARRAY(JSON_OBJECT('key','Dimensions','value','90 x 70 x 110 mm'), JSON_OBJECT('key','Weight','value','140g'), JSON_OBJECT('key','Compatibility','value','All phone sizes, case-friendly')),
  'published', 1
),
(
  'gt-wing-mirror-cap', 'GT Wing Mirror Cap', 'Capac Oglinda GT Wing',
  'Direct-fit mirror caps engineered from scratch for a factory-flush look.',
  'Capace de oglinda cu montaj direct, proiectate de la zero pentru un aspect flush, ca de fabrica.',
  'Precision-fit mirror caps designed from vehicle scans for a seamless factory-flush finish. ASA-CF construction handles UV exposure and temperature swings without cracking.',
  'Capace de oglinda cu potrivire de precizie, proiectate dupa scanari ale vehiculului pentru un finisaj flush, ca de fabrica. Constructia din ASA-CF rezista la expunere UV si variatii de temperatura fara sa crape.',
  'Modeled from reference scans, printed in ASA-CF for outdoor durability, then wet-sanded for a smooth OEM-like surface.',
  'Modelate dupa scanari de referinta, printate in ASA-CF pentru durabilitate in exterior, apoi slefuite umed pentru o suprafata neteda, similara OEM.',
  'ASA-CF', 'automotive', 59.00, 8, 3,
  JSON_ARRAY('/studio/assets/images/products/mirror-cap-1.svg', '/studio/assets/images/products/mirror-cap-2.svg'),
  JSON_ARRAY(JSON_OBJECT('key','Dimensions','value','Vehicle-specific'), JSON_OBJECT('key','Weight','value','65g / pair'), JSON_OBJECT('key','Compatibility','value','Check fitment guide before ordering')),
  'published', 1
),
(
  'carbon-display-stand', 'Carbon Display Stand', 'Suport Expunere Carbon',
  'A minimal display stand for collectibles, watches and small showpieces.',
  'Un suport minimalist de expunere pentru colectii, ceasuri si obiecte decorative mici.',
  'A clean-lined stand that lets the object do the talking. PLA-CF gives it a rigid, fiber-flecked finish ideal for display shelving.',
  'Un suport cu linii curate, care lasa obiectul sa vorbeasca de la sine. PLA-CF ii ofera un finisaj rigid, cu particule de fibra, ideal pentru rafturi de expunere.',
  'Printed in PLA-CF at a fine layer height for crisp edges, then hand-finished at the base.',
  'Printat in PLA-CF la o inaltime fina a stratului pentru margini clare, apoi finisat manual la baza.',
  'PLA-CF', 'display', 19.00, 30, 5,
  JSON_ARRAY('/studio/assets/images/products/display-stand-1.svg', '/studio/assets/images/products/display-stand-2.svg'),
  JSON_ARRAY(JSON_OBJECT('key','Dimensions','value','80 x 80 x 120 mm'), JSON_OBJECT('key','Weight','value','95g'), JSON_OBJECT('key','Compatibility','value','Universal base')),
  'published', 0
),
(
  'cable-management-tray', 'Cable Management Tray', 'Tava Management Cabluri',
  'Under-desk cable tray that keeps power strips and cables off the floor.',
  'Tava sub birou pentru cabluri, care tine prizele si cablurile departe de podea.',
  'Mounts under any desk to hold your power strip and route cables cleanly. Printed in standard PETG for everyday durability at an accessible price.',
  'Se monteaza sub orice birou pentru a tine priza multipla si a directiona cablurile curat. Printata in PETG standard pentru durabilitate zilnica la un pret accesibil.',
  'Printed in PETG, deburred and drilled for mounting hardware by hand.',
  'Printata in PETG, curatata de bavuri si gaurita manual pentru accesoriile de montaj.',
  'PETG', 'desk', 15.00, 2, 3,
  JSON_ARRAY('/studio/assets/images/products/cable-tray-1.svg', '/studio/assets/images/products/cable-tray-2.svg'),
  JSON_ARRAY(JSON_OBJECT('key','Dimensions','value','300 x 90 x 40 mm'), JSON_OBJECT('key','Weight','value','160g'), JSON_OBJECT('key','Compatibility','value','Desk thickness up to 40mm')),
  'published', 0
),
(
  'watch-stand-onyx', 'Watch Stand Onyx', 'Suport Ceas Onyx',
  'A single-watch stand with a faceted, gallery-ready silhouette.',
  'Un suport pentru un singur ceas, cu o silueta fatetata, demna de galerie.',
  'Designed to hold your watch like a small sculpture. PLA-CF captures fine facets cleanly and resists yellowing over time.',
  'Proiectat sa tina ceasul ca pe o mica sculptura. PLA-CF surprinde fatetele fine cu claritate si rezista la ingalbenire in timp.',
  'Printed in PLA-CF, finished with a light bead-blast for an even matte surface.',
  'Printat in PLA-CF, finisat cu sablare usoara pentru o suprafata mata uniforma.',
  'PLA-CF', 'display', 22.00, 0, 3,
  JSON_ARRAY('/studio/assets/images/products/watch-stand-1.svg', '/studio/assets/images/products/watch-stand-2.svg'),
  JSON_ARRAY(JSON_OBJECT('key','Dimensions','value','70 x 70 x 90 mm'), JSON_OBJECT('key','Weight','value','80g'), JSON_OBJECT('key','Compatibility','value','Fits most watch case sizes')),
  'published', 0
)
ON DUPLICATE KEY UPDATE slug = slug;
