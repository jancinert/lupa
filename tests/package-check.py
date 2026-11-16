"""Install the exported package in an empty consumer and exercise its Composer bin proxy."""

import argparse
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile

parser = argparse.ArgumentParser()
parser.add_argument('archive', type=Path)
parser.add_argument('--php', default='php')
parser.add_argument('--composer', default=shutil.which('composer'))
args = parser.parse_args()
archive = args.archive.resolve(strict=True)
if not args.composer:
	parser.error('Composer was not found; pass its PHP executable using --composer.')

with zipfile.ZipFile(archive) as package:
	names = [name for name in package.namelist() if not name.endswith('/')]
	if not all(name in ['README.md', 'LICENSE', 'composer.json'] or name.startswith(('src/', 'bin/')) for name in names):
		raise RuntimeError('The distribution contains unexpected files.')
	if not {'bin/lupa', 'src/Inspector.php', 'src/Http/Endpoint.php'}.issubset(names):
		raise RuntimeError('The distribution is incomplete.')
	manifest = json.loads(package.read('composer.json'))

manifest.update(version='0.1.0', dist={'type': 'zip', 'url': archive.as_uri()})
manifest.pop('require-dev', None)
manifest.pop('autoload-dev', None)
with tempfile.TemporaryDirectory(prefix='lupa-package-') as directory:
	consumer = Path(directory)
	(consumer / 'composer.json').write_text(json.dumps({
		'name': 'lupa/package-smoke',
		'repositories': [{'type': 'package', 'package': manifest}],
	}), encoding='utf-8')
	commands = [
		[args.php, args.composer, 'require', '--dev', 'jancinert/lupa:0.1.0', '--no-interaction', '--no-progress'],
		[args.php, args.composer, 'check-platform-reqs'],
		[args.php, 'vendor/bin/lupa', 'init', '--framework', 'none'],
		[args.php, 'vendor/bin/lupa', 'call', 'app_info'],
	]
	for command in commands:
		result = subprocess.run(command, cwd=consumer, capture_output=True, text=True, timeout=180)
		if result.returncode:
			raise RuntimeError(result.stdout + result.stderr)
	if '"framework": "none"' not in result.stdout:
		raise RuntimeError('The installed CLI did not inspect the plain application.')
	installed = consumer / 'vendor/jancinert/lupa'
	if (installed / 'tests').exists() or (installed / '.local').exists():
		raise RuntimeError('Development files were installed with the package.')
print(f'Package passed: {len(names)} files, clean Composer installation, init and app_info.')
