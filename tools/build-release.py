#!/usr/bin/env python3
"""Build bounded releases; never package site data, private images or vendor code."""
from pathlib import Path
import hashlib
import json
import stat
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'dist'
SLUG = 'dualscan-cloud-os-image-manager'
VERSION = '1.0.1'


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def collect(directories, singles):
    paths = [ROOT / name for name in singles]
    for directory in directories:
        paths.extend(p for p in (ROOT / directory).rglob('*') if p.is_file())
    return sorted({p for p in paths if p.is_file() and not p.is_symlink()
                   and '__pycache__' not in p.parts and p.suffix not in {'.pyc', '.dualscan', '.key'}
                   and not ('verification' in p.relative_to(ROOT).parts and p.suffix.lower() in {'.jpg', '.jpeg', '.png'})
                   and '-before-' not in p.name
                   and p.name not in {'.DS_Store', 'wp-config.php', '.env'}}, key=lambda p: str(p))


def archive(name, paths):
    destination = OUT / name
    with zipfile.ZipFile(destination, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as out:
        for path in paths:
            relative = path.relative_to(ROOT).as_posix()
            item = zipfile.ZipInfo(f'{SLUG}/{relative}', date_time=(2026, 10, 16, 0, 0, 0))
            item.compress_type = zipfile.ZIP_DEFLATED
            item.external_attr = (stat.S_IFREG | 0o644) << 16
            out.writestr(item, path.read_bytes())
    with zipfile.ZipFile(destination) as check:
        assert check.testzip() is None
        assert len(check.namelist()) == len(paths)
        for path, member in zip(paths, check.namelist()):
            assert hashlib.sha256(check.read(member)).hexdigest() == digest(path)
    return {'file': name, 'bytes': destination.stat().st_size, 'sha256': digest(destination), 'entries': len(paths)}


def main():
    OUT.mkdir(exist_ok=True)
    production = collect(['includes', 'assets', 'runtime', 'bin', 'deployment'],
                         [f'{SLUG}.php', 'LICENSE', 'README.md', 'readme.txt'])
    manifest = {'product': 'Dualscan Cloud OS Image Manager', 'version': VERSION,
                'files': [{'path': p.relative_to(ROOT).as_posix(), 'bytes': p.stat().st_size,
                           'sha256': digest(p)} for p in production]}
    (ROOT / 'SOURCE-MANIFEST.json').write_text(json.dumps(manifest, indent=2) + '\n')
    plugin = collect(['includes', 'assets', 'runtime'], [f'{SLUG}.php', 'LICENSE', 'readme.txt'])
    docs = ['docs/TECHNICAL-GUIDE.md', 'docs/SERVER-KEYS.md', 'docs/SECURITY-REVIEW.md',
            'docs/DELIVERY-VERIFICATION.md', 'docs/GPL-RELEASE-NOTES.md', 'docs/PROJECT-OVERVIEW.md',
            'docs/PRESENTATION-VERIFICATION.md', 'docs/presentation.html']
    source = collect(['includes', 'assets', 'runtime', 'bin', 'deployment', 'tests', 'tools', 'verification', 'docs/media'],
                     [f'{SLUG}.php', 'LICENSE', 'README.md', 'readme.txt', 'SOURCE-MANIFEST.json'] + docs)
    artifacts = [archive(f'{SLUG}-{VERSION}.zip', plugin),
                 archive(f'{SLUG}-{VERSION}-source.zip', source)]
    report = {'product': 'Dualscan Cloud OS Image Manager', 'version': VERSION,
              'allArchivesReadBackAndHashesVerified': True,
              'excludes': ['live site data', 'runtime database', 'private images and keys'],
              'artifacts': artifacts}
    (OUT / 'RELEASE-MANIFEST.json').write_text(json.dumps(report, indent=2) + '\n')
    (OUT / 'SHA256SUMS').write_text(''.join(f"{a['sha256']}  {a['file']}\n" for a in artifacts))
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
