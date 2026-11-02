#!/bin/bash
# Range plan for the Germany dump, and a local server that speaks 206.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
. "$root/docs/deploy/photon/download-archive.sh"

de_bytes=9881965371
plan=$(range_plan "$de_bytes" "$RANGE_COUNT")
ranges=$(printf '%s\n' "$plan" | wc -l | tr -d ' ')
if [ "$ranges" != "8" ]; then
  echo "expected 8 ranges, got ${ranges}" >&2
  exit 1
fi
first=$(printf '%s\n' "$plan" | head -1)
last=$(printf '%s\n' "$plan" | tail -1)
if [ "$first" != "0 1235245671" ] || [ "$last" != "8646719704 9881965370" ]; then
  echo "Germany ranges drifted: ${first} .. ${last}" >&2
  exit 1
fi
covered=0
while read -r start end; do
  covered=$((covered + end - start + 1))
done <<EOF
$plan
EOF
if [ "$covered" != "$de_bytes" ]; then
  echo "ranges cover ${covered}, not ${de_bytes}" >&2
  exit 1
fi

port=$(python3 - << 'PY'
import socket
s = socket.socket()
s.bind(("127.0.0.1", 0))
print(s.getsockname()[1])
s.close()
PY
)
python3 - "$port" << 'PY' &
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
body = b"0123456789" * 10000

class Handler(BaseHTTPRequestHandler):
    def do_HEAD(self):
        self.send_response(200)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
    def do_GET(self):
        spec = self.headers.get("Range")
        if spec:
            start, end = spec.split("=", 1)[1].split("-")
            start, end = int(start), int(end)
            chunk = body[start:end + 1]
            self.send_response(206)
            self.send_header("Content-Range", f"bytes {start}-{end}/{len(body)}")
            self.send_header("Content-Length", str(len(chunk)))
            self.end_headers()
            self.wfile.write(chunk)
            return
        self.send_response(200)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)
    def log_message(self, fmt, *args):
        return

ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1])), Handler).serve_forever()
PY
server=$!
trap 'kill "$server" 2>/dev/null || true' EXIT
for _ in 1 2 3 4 5 6 7 8 9 10; do
  if curl -sf --max-time 1 -o /dev/null "http://127.0.0.1:${port}/file"; then
    break
  fi
  sleep 0.1
done

dest=$(mktemp)
download_archive "http://127.0.0.1:${port}/file" "$dest"
python3 - "$dest" << 'PY'
import sys
got = open(sys.argv[1], "rb").read()
want = b"0123456789" * 10000
if got != want:
    raise SystemExit(f"downloaded {len(got)} bytes, expected {len(want)}")
PY
rm -f "$dest"

grep -q 'PHOTON_MIN_FREE_GB=30' "$root/docs/deploy/install-ubuntu-26.04.sh"
grep -q 'PHOTON_READY_TRIES=180' "$root/docs/deploy/install-ubuntu-26.04.sh"
grep -q 'umask 077' "$root/docs/deploy/install-ubuntu-26.04.sh"
grep -q 'chmod 644 /opt/photon/photon-0.5.0.jar' "$root/docs/deploy/install-ubuntu-26.04.sh"
grep -q 'download-archive.sh' "$root/docs/deploy/install-ubuntu-26.04.sh"
grep -q 'q=Bern' "$root/docs/deploy/install-ubuntu-26.04.sh"
grep -q 'READY_TRIES=180' "$root/docs/deploy/photon/refresh-indexes.sh"
grep -q 'data-dir "$PHOTON_HOME"' "$root/docker/photon/entrypoint.sh"
grep -q 'dockerfile: docker/photon/Dockerfile' "$root/docker-compose.yml"

echo "photon download ok"
