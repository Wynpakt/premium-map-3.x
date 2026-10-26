#!/bin/bash
# Floors for CVE-2026-109618 and CVE-2026-109619.
# Ubuntu checks the package revision. Docker checks the upstream version string.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
floor=8.5.4-0ubuntu1.5

if dpkg --compare-versions 8.5.4-0ubuntu1.4 ge "$floor"; then
  echo "older Ubuntu package was accepted" >&2
  exit 1
fi
dpkg --compare-versions "$floor" ge "$floor"
dpkg --compare-versions 8.5.4-0ubuntu1.6 ge "$floor"

python3 - "$root" << 'PY'
import hashlib, json, sys
from pathlib import Path
root = Path(sys.argv[1])
composer = json.loads((root / "composer.json").read_text())
lock = json.loads((root / "composer.lock").read_text())
if "config" in composer:
    raise SystemExit("composer.json still has config.platform")
if "platform-overrides" in lock:
    raise SystemExit("composer.lock still overrides the PHP platform")
keys = ["name","version","require","require-dev","conflict","replace","provide","suggest","autoload","autoload-dev","extra","repositories","minimum-stability","prefer-stable","config"]
relevant = dict(sorted((k, composer[k]) for k in keys if k in composer))
encoded = json.dumps(relevant, ensure_ascii=True, separators=(",", ":")).replace("/", "\\/")
digest = hashlib.md5(encoded.encode()).hexdigest()
if digest != lock["content-hash"]:
    raise SystemExit(f"content-hash {lock['content-hash']} != {digest}")
PY

grep -q 'UBUNTU_PHP_PACKAGE_FLOOR=8.5.4-0ubuntu1.5' "$root/docs/deploy/install-ubuntu-26.04.sh"
grep -q 'pcre.jit=0' "$root/docs/deploy/install-ubuntu-26.04.sh"
grep -q 'systemctl restart apache2' "$root/docs/deploy/install-ubuntu-26.04.sh"
grep -q 'version_compare(PHP_VERSION, "8.5.12"' "$root/docker/entrypoint.sh"
grep -q 'pcre.jit=0' "$root/docker/entrypoint.sh"
grep -q 'FROM php:8.5-apache-bookworm' "$root/docker/Dockerfile"
grep -q 'pull: true' "$root/docker-compose.yml"

echo "php floor ok"
