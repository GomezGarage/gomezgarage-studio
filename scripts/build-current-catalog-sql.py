"""Build the owner-requested draft catalog reset. No database or credentials access."""
import argparse
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--check', action='store_true')
args = parser.parse_args()
products = json.loads((ROOT / 'database/catalog-products-20261107.json').read_text())

def value(v):
    if isinstance(v, (int, float)):
        return str(v)
    if isinstance(v, (list, dict)):
        v = json.dumps(v, ensure_ascii=False, separators=(',', ':'))
    if '\\' in v or '\0' in v:
        return 'CONVERT(0x' + v.encode().hex() + ' USING utf8mb4)'
    return "'" + v.replace("'", "''") + "'"

columns = [key for key in products[0] if key != 'presentation']
sql = '''-- RESET CATALOG: explicitly requested by the owner, 7 November 2026.
-- Deletes existing draft product records; creates seven completed drafts.
-- Existing images/videos on disk are retained. Orders and invoices are untouched.
-- Back up the shop database before import. Do NOT run database/schema.sql.
-- Refuses to reset if any product is published. Successful import runs only once.
SET NAMES utf8mb4;
''' + (ROOT / 'database/migrations/20261107_product_content.sql').read_text() + '''
START TRANSACTION;
SET @ggs_reset_installed = (SELECT COUNT(*) FROM settings WHERE key_name = 'catalog_reset_20261107');
SET @ggs_published_count = (SELECT COUNT(*) FROM products WHERE status = 'published');
SET @ggs_do_reset = (@ggs_reset_installed = 0 AND @ggs_published_count = 0);
DELETE FROM product_content WHERE @ggs_do_reset = 1;
DELETE FROM products WHERE @ggs_do_reset = 1;
'''
for p in products:
    assert p['status'] == 'draft'
    assert all(not any(c in p.get(k, '') for c in 'ăâîșşțţĂÂÎȘŞȚŢ') for k in ['name_ro','desc_short_ro','desc_long_ro','how_made_ro'])
    sql += '\nINSERT INTO products\n  (' + ', '.join(columns) + ')\nSELECT\n  '
    sql += ',\n  '.join(value(p[c]) for c in columns) + '\nWHERE @ggs_do_reset = 1;\n'
    sql += 'INSERT INTO product_content (product_id, content)\nSELECT id, ' + value(p['presentation'])
    sql += '\nFROM products WHERE slug = ' + value(p['slug']) + ' AND @ggs_do_reset = 1;\n'
sql += '''
INSERT INTO settings (key_name, value)
SELECT 'catalog_reset_20261107', 'installed' WHERE @ggs_do_reset = 1
ON DUPLICATE KEY UPDATE key_name = settings.key_name;
COMMIT;
SELECT CASE
  WHEN @ggs_reset_installed > 0 THEN 'Already installed - no changes made'
  WHEN @ggs_published_count > 0 THEN 'NOT reset - published products exist'
  ELSE 'Installed - seven draft products ready for photos and videos'
END AS catalog_result;
'''
path = ROOT / 'database/migrations/20261107_catalog_reset.sql'
if args.check:
    assert path.read_text() == sql, 'Rebuild the catalog reset SQL'
else:
    path.write_text(sql)
print('PASS: seven draft products and presentation metadata match the catalog SQL.')
