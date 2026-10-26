"""Package only the reviewed runtime allowlist, SQL and selected WebP photographs."""
import argparse
import hashlib
import shutil
import zipfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--output', required=True, type=Path, help='New destination folder, outside the served website')
args = parser.parse_args()
destination = args.output.expanduser().resolve()
if destination.exists():
    raise SystemExit('Use a new output folder; existing deliverables are not overwritten.')
destination.mkdir(parents=True)
docs = root / 'docs/catalog-release'
files = (docs / 'runtime-files.txt').read_text().splitlines()
if any(Path(f).is_absolute() or '..' in Path(f).parts or f.startswith(('config', 'vendor/', 'database/', 'docs/')) for f in files):
    raise SystemExit('Unexpected runtime entry.')
hashes = []
with zipfile.ZipFile(destination / 'studio-upload.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
    for name in files:
        source = root / name
        archive.write(source, name)
        hashes.append(hashlib.sha256(source.read_bytes()).hexdigest() + '  ' + name)
(destination / 'runtime-files.txt').write_text('\n'.join(files) + '\n')
(destination / 'runtime-sha256.txt').write_text('\n'.join(hashes) + '\n')
(destination / 'sql').mkdir()
for name in ['20261026_home_category.sql', '20261026_initial_catalog.sql']:
    shutil.copy2(root / 'database/migrations' / name, destination / 'sql' / name)
shutil.copy2(root / 'database/catalog-products.json', destination / 'catalog-products.json')
for name in ['product-copy.md', 'verification.md']:
    shutil.copy2(docs / name, destination / name)
shutil.copytree(docs / 'photos', destination / 'photos')
guide = (docs / 'README.md').read_text()
guide = guide.replace('../../database/migrations/', 'sql/').replace('../../database/catalog-products.json', 'catalog-products.json')
guide = '# Incepe aici\n\n**Pentru server foloseste numai `studio-upload.zip`, nu arhiva completa. SQL-urile se importa separat, iar pozele se incarca din administrare.**\n\n' + guide
(destination / 'START-HERE.md').write_text(guide)
members = sorted(destination.rglob('*'))
with zipfile.ZipFile(destination / 'catalog-release.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
    for source in members:
        if source.is_file():
            archive.write(source, str(source.relative_to(destination)))
print(destination / 'catalog-release.zip')
print(f'{len(files)} runtime files; 2 SQL migrations; 12 selected WebP photographs; guide and verification report.')
