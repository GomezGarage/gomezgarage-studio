"""Build the initial catalog SQL from reviewed, readable bilingual content. No DB access."""
import argparse
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--check', action='store_true')
args = parser.parse_args()
products = json.loads((root / 'database/catalog-products.json').read_text())
columns = list(products[0])


def sql_value(value):
    if isinstance(value, int):
        return str(value)
    if isinstance(value, list):
        value = json.dumps(value, ensure_ascii=False, separators=(',', ':'))
    # Plain SQL strings keep the reviewed catalog readable. Doubling apostrophes
    # works with and without NO_BACKSLASH_ESCAPES; literal newlines are preserved.
    # Reserve hex for future values with backslashes/NUL, where SQL modes differ.
    if '\\' in value or '\0' in value:
        return 'CONVERT(0x' + value.encode().hex() + ' USING utf8mb4)'
    return "'" + value.replace("'", "''") + "'"


insert = ('INSERT INTO products\n  (' + ', '.join(columns) + ')\nVALUES\n'
          + ',\n'.join('(\n  ' + ',\n  '.join(sql_value(p[c]) for c in columns) + '\n)' for p in products)
          + '\nON DUPLICATE KEY UPDATE slug = products.slug;\n')
schema_path = root / 'database/schema.sql'
marker = '-- Initial real catalog:'
schema = schema_path.read_text()
schema = schema[:schema.index(marker)] + '''-- Initial real catalog: drafts; price/stock/photos are completed in admin.
-- Source: database/catalog-products.json. No published demo products.
-- ==========================================================================
''' + insert
slugs = ', '.join("'" + p['slug'] + "'" for p in products)
migration = '''-- Owner-authorized replacement of the old public catalog. Run AFTER home_category.sql.
-- Archive existing listings without deleting order history or product identifiers.
-- Replay does not reset completed products, prices, images, stock or publication status.
-- Readable UTF-8 text; apostrophes use standard SQL quoting (two apostrophes).
-- Source: database/catalog-products.json. Product data and import effects are unchanged.
SET NAMES utf8mb4;
START TRANSACTION;
SET @ggs_catalog_installed = (SELECT COUNT(*) FROM settings WHERE key_name = 'catalog_release_20261026');
UPDATE products SET status = 'draft', featured = 0
WHERE @ggs_catalog_installed = 0 AND slug NOT IN (''' + slugs + ''');
''' + insert + '''INSERT INTO settings (key_name, value) VALUES ('catalog_release_20261026', 'installed')
ON DUPLICATE KEY UPDATE key_name = settings.key_name;
COMMIT;
'''
for path, content in [(schema_path, schema), (root / 'database/migrations/20261026_initial_catalog.sql', migration)]:
    if args.check:
        if path.read_text() != content:
            raise SystemExit('Rebuild generated SQL: ' + str(path.relative_to(root)))
    else:
        path.write_text(content)
print('PASS: catalog SQL matches reviewed JSON.' if args.check else 'Catalog SQL generated.')
