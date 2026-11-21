-- SQLite schema for AutoLand. The PHP application creates this schema automatically
-- on first visit; this file is included as a standalone schema reference/backup aid.
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS admins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  totp_secret_enc TEXT NULL,
  totp_enabled INTEGER NOT NULL DEFAULT 0,
  totp_recovery_hashes TEXT NOT NULL DEFAULT '[]',
  totp_last_step INTEGER NOT NULL DEFAULT -1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS admin_login_attempts (
  login_key TEXT PRIMARY KEY,
  attempts INTEGER NOT NULL DEFAULT 0,
  window_started INTEGER NOT NULL,
  blocked_until INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS admin_mfa_attempts (
  login_key TEXT PRIMARY KEY,
  attempts INTEGER NOT NULL DEFAULT 0,
  window_started INTEGER NOT NULL,
  blocked_until INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS admin_email_attempts (
  login_key TEXT PRIMARY KEY,
  attempts INTEGER NOT NULL DEFAULT 0,
  window_started INTEGER NOT NULL,
  blocked_until INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS admin_remember_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id INTEGER NOT NULL,
  selector TEXT NOT NULL UNIQUE,
  token_hash TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  created_at INTEGER NOT NULL,
  last_used_at INTEGER NOT NULL,
  FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_admin_remember_expiry ON admin_remember_tokens(expires_at);
CREATE TABLE IF NOT EXISTS admin_password_resets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id INTEGER NOT NULL,
  selector TEXT NOT NULL UNIQUE,
  token_hash TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  created_at INTEGER NOT NULL,
  used_at INTEGER NULL,
  FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_admin_reset_expiry ON admin_password_resets(expires_at);
CREATE TABLE IF NOT EXISTS admin_reset_attempts (
  login_key TEXT PRIMARY KEY,
  attempts INTEGER NOT NULL DEFAULT 0,
  window_started INTEGER NOT NULL,
  blocked_until INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS services (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  active INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  price NUMERIC NULL DEFAULT NULL,
  image TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  category_id INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_products_active ON products(active,id);
CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_code TEXT NOT NULL UNIQUE,
  customer_name TEXT NOT NULL,
  phone TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  total NUMERIC NOT NULL DEFAULT 0,
  quote_required INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','contacted','confirmed','completed','cancelled')),
  delivery_company_id INTEGER NULL,
  tracking_number TEXT NOT NULL DEFAULT '',
  coupon_code TEXT NOT NULL DEFAULT '',
  discount NUMERIC NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
CREATE TABLE IF NOT EXISTS order_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  product_id INTEGER NULL,
  product_name TEXT NOT NULL,
  quantity INTEGER NOT NULL CHECK(quantity>0),
  unit_price NUMERIC NULL,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items(order_id);
CREATE TABLE IF NOT EXISTS messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  phone TEXT NOT NULL,
  message TEXT NOT NULL,
  is_read INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS customers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  phone TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS coupons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  discount_type TEXT NOT NULL CHECK(discount_type IN ('percent','fixed')),
  discount_value NUMERIC NOT NULL CHECK(discount_value>0),
  minimum_total NUMERIC NOT NULL DEFAULT 0,
  usage_limit INTEGER NULL,
  used_count INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  starts_at TEXT NULL,
  ends_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS delivery_companies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  phone TEXT NOT NULL DEFAULT '',
  fee NUMERIC NOT NULL DEFAULT 0,
  notes TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS settings (
  "key" TEXT PRIMARY KEY,
  "value" TEXT NOT NULL
);

INSERT INTO services(title,description,sort_order)
SELECT 'نسخ مفاتيح السيارات','نسخ مفاتيح السيارات التقليدية والحديثة.',1
WHERE NOT EXISTS(SELECT 1 FROM services WHERE title='نسخ مفاتيح السيارات');
INSERT INTO services(title,description,sort_order)
SELECT 'برمجة المفاتيح الذكية','برمجة المفاتيح والريموتات وأجهزة التحكم.',2
WHERE NOT EXISTS(SELECT 1 FROM services WHERE title='برمجة المفاتيح الذكية');
INSERT INTO services(title,description,sort_order)
SELECT 'إصلاح المفاتيح والريموتات','تشخيص الأعطال وتغيير هياكل المفاتيح والبطاريات.',3
WHERE NOT EXISTS(SELECT 1 FROM services WHERE title='إصلاح المفاتيح والريموتات');
INSERT INTO services(title,description,sort_order)
SELECT 'لواحق وإكسسوارات السيارات','تشكيلة متنوعة من لواحق وتجهيزات السيارات. اسألنا عن التوفر.',4
WHERE NOT EXISTS(SELECT 1 FROM services WHERE title='لواحق وإكسسوارات السيارات');

INSERT INTO products(name,description,price)
SELECT 'إكسسوارات داخلية','منتجات متنوعة للمقصورة. السعر والتوفر عند الطلب.',NULL
WHERE NOT EXISTS(SELECT 1 FROM products WHERE name='إكسسوارات داخلية');
INSERT INTO products(name,description,price)
SELECT 'إكسسوارات خارجية','اسألنا عن المنتجات المتوفرة لسيارتك.',NULL
WHERE NOT EXISTS(SELECT 1 FROM products WHERE name='إكسسوارات خارجية');
INSERT INTO products(name,description,price)
SELECT 'مصابيح وتجهيزات إنارة','اسأل عن الأنواع والمقاسات المتوفرة.',NULL
WHERE NOT EXISTS(SELECT 1 FROM products WHERE name='مصابيح وتجهيزات إنارة');
INSERT INTO products(name,description,price)
SELECT 'حماية وتنظيف السيارة','منتجات حماية وعناية متنوعة. السعر عند الطلب.',NULL
WHERE NOT EXISTS(SELECT 1 FROM products WHERE name='حماية وتنظيف السيارة');
INSERT INTO products(name,description,price)
SELECT 'أجهزة وإلكترونيات السيارات','تحقق من التوافق والتوفر قبل الطلب.',NULL
WHERE NOT EXISTS(SELECT 1 FROM products WHERE name='أجهزة وإلكترونيات السيارات');
INSERT INTO products(name,description,price)
SELECT 'شواحن وحوامل الهواتف','اختيارات متنوعة للسيارة. السعر عند الطلب.',NULL
WHERE NOT EXISTS(SELECT 1 FROM products WHERE name='شواحن وحوامل الهواتف');
