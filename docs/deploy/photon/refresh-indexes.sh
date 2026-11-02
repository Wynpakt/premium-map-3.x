#!/bin/bash
# Install a newer GraphHopper Photon dump for DE, AT and CH.
# One country at a time. A country is left alone when its .release file
# already names the newest dated archive.
#
# Peak disk for a country is about three times the .tar.bz2 (the archive plus
# an index that expands to roughly twice that). When that much is free, the
# new index is built beside the old one and swapped. Otherwise the old index
# is removed first. On a 50 GB volume that is the Germany path.
set -euo pipefail

PHOTON_ROOT=/var/lib/photon
FETCH=/opt/photon/fetch-index.sh
LOCK="${PHOTON_ROOT}/refresh.lock"
MIRROR=https://download1.graphhopper.com/public/extracts/by-country-code
UA=premium-landkarte-photon
# Archive plus extracted index, with a little room over the measured ~2x expansion.
SPACE_FACTOR=3
# A cold Germany index took about 8 minutes before it answered. 180 * 5s covers that.
READY_TRIES=180

if [ "$(id -u)" -ne 0 ]; then
  echo "refresh-indexes.sh must run as root" >&2
  exit 1
fi

if [ ! -x "$FETCH" ]; then
  echo "missing ${FETCH}" >&2
  exit 1
fi

if [ -f "$LOCK" ]; then
  echo "Photon refresh already running (${LOCK})" >&2
  exit 1
fi

echo $$ > "$LOCK"
trap 'rm -f "$LOCK"' EXIT

latest_name() {
  local cc="$1"
  local page
  page="$(curl -fsSL -A "$UA" "${MIRROR}/${cc}/")"
  printf '%s\n' "$page" | grep -oE "photon-db-${cc}-[0-9]+\\.tar\\.bz2" | sort -u | tail -1
}

archive_bytes() {
  local url="$1"
  local size
  size="$(curl -fsSL -A "$UA" -I -L "$url" | awk 'tolower($1)=="content-length:" {print $2}' | tr -d '\r' | tail -1)"
  if ! [[ "$size" =~ ^[0-9]+$ ]]; then
    echo "no Content-Length for ${url}" >&2
    exit 1
  fi
  printf '%s' "$size"
}

probe_query() {
  case "$1" in
    de) printf '%s' Berlin ;;
    at) printf '%s' Wien ;;
    ch) printf '%s' Bern ;;
  esac
}

listen_port() {
  case "$1" in
    de) printf '%s' 2322 ;;
    at) printf '%s' 2323 ;;
    ch) printf '%s' 2324 ;;
  esac
}

wait_until_up() {
  local cc="$1"
  local port query
  port="$(listen_port "$cc")"
  query="$(probe_query "$cc")"
  local _
  for _ in $(seq 1 "$READY_TRIES"); do
    if curl -sf -o /dev/null --max-time 2 "http://127.0.0.1:${port}/api?limit=1&lang=de&q=${query}"; then
      return 0
    fi
    sleep 5
  done
  echo "${cc}: Photon is not answering on 127.0.0.1:${port}" >&2
  return 1
}

install_index() {
  local cc="$1"
  local file="$2"
  local dest="${PHOTON_ROOT}/${cc}"
  local stage="${PHOTON_ROOT}/${cc}.new"
  local url="${MIRROR}/${cc}/${file}"
  local bytes need avail reusable
  bytes="$(archive_bytes "$url")"
  need=$((bytes * SPACE_FACTOR))
  avail="$(df -B1 --output=avail "$PHOTON_ROOT" | tail -1 | tr -dc '0-9')"
  reusable=0
  if [ -d "$dest" ]; then
    reusable="$(du -sb "$dest" | awk '{print $1}')"
  fi

  echo "${cc}: ${file} ($(numfmt --to=iec "$bytes") archive, need $(numfmt --to=iec "$need") free)"
  if [ $((avail + reusable)) -lt "$need" ]; then
    echo "${cc}: not enough disk. free $(numfmt --to=iec "$avail"), reusable $(numfmt --to=iec "$reusable")" >&2
    exit 1
  fi

  if [ "$avail" -ge "$need" ]; then
    rm -rf "$stage"
    bash "$FETCH" "$cc" "$stage"
    systemctl stop "photon@${cc}" || true
    rm -rf "$dest"
    mv "$stage" "$dest"
  else
    echo "${cc}: removing the installed index before the download"
    systemctl stop "photon@${cc}" || true
    rm -rf "$dest" "$stage"
    bash "$FETCH" "$cc" "$dest"
  fi
  chown -R photon:photon "$dest"
  systemctl enable --now "photon@${cc}"
  wait_until_up "$cc"
  printf '%s\n' "$file" > "${PHOTON_ROOT}/${cc}.release"
  echo "${cc}: installed ${file}"
}

for cc in at ch de; do
  file="$(latest_name "$cc")"
  if [ -z "$file" ]; then
    echo "no dated Photon dump listed for ${cc}" >&2
    exit 1
  fi
  dest="${PHOTON_ROOT}/${cc}"
  stamp="${PHOTON_ROOT}/${cc}.release"
  if [ -d "${dest}/photon_data/elasticsearch" ] && [ -f "$stamp" ] && [ "$(tr -d '[:space:]' < "$stamp")" = "$file" ]; then
    echo "${cc}: ${file} already installed"
    continue
  fi
  install_index "$cc" "$file"
done

echo "Photon indexes are current"
