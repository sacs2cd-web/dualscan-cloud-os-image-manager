#!/usr/bin/env python3
"""Copy current production code into the two exclusively owned local fixture sites."""
import hashlib
import json
import shutil
from pathlib import Path

PROJECT = Path(__file__).resolve().parents[2]
RUNTIME = Path("/Users/williamgrecia/.local/share/dualscan-cloud-os-image-manager-runtime/20261016")

assert RUNTIME.is_dir() and RUNTIME.name == "20261016"
files = [PROJECT / "dualscan-cloud-os-image-manager.php"]
files += [p for name in ["includes", "assets", "runtime", "deployment", "bin"] if (PROJECT / name).is_dir() for p in (PROJECT / name).rglob("*") if p.is_file()]
manifest = {str(p.relative_to(PROJECT)): {"bytes": p.stat().st_size, "sha256": hashlib.sha256(p.read_bytes()).hexdigest()} for p in sorted(files)}
for site in ["source", "target"]:
    destination = RUNTIME / "sites" / site / "wp-content/plugins/dualscan-cloud-os-image-manager"
    if destination.exists():
        shutil.rmtree(destination)
    destination.mkdir()
    for path in manifest:
        output = destination / path
        output.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(PROJECT / path, output)
        assert hashlib.sha256(output.read_bytes()).hexdigest() == manifest[path]["sha256"]
report = {"passed": True, "scope": "Exact production code copied into exclusively owned disposable WordPress sites", "files": manifest}
(PROJECT / "verification/runtime-plugin-files.json").write_text(json.dumps(report, indent=2) + "\n")
print(json.dumps({"passed": True, "files": len(files), "sites": 2}))
