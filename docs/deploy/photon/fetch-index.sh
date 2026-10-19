#!/bin/bash
# Download and extract one Photon 0.5 country index.
# Usage: fetch-index.sh <de|at|ch> <dest-dir>
#
# GraphHopper's photon-db-<cc>-latest.tar.bz2 name is a broken symlink (HTTP 404).
# The real file is the dated archive, for example photon-db-de-250720.tar.bz2.
set -euo pipefail

COUNTRY="${1:?country code de, at or ch}"
DEST="${2:?destination directory}"
BASE="https://download1.graphhopper.com/public/extracts/by-country-code/${COUNTRY}"
UA="premium-landkarte-photon"

if [ -d "${DEST}/elasticsearch" ]; then
  echo "Photon index for ${COUNTRY} already present in ${DEST}"
  exit 0
fi

index="$(curl -fsSL -A "$UA" "${BASE}/")"
file="$(printf '%s\n' "$index" | grep -oE "photon-db-${COUNTRY}-[0-9]+\\.tar\\.bz2" | sort -u | tail -1)"
if [ -z "$file" ]; then
  echo "No dated Photon dump listed at ${BASE}/" >&2
  exit 1
fi

# Keep the archive next to the index. /tmp is often a small tmpfs.
parent="$(dirname "$DEST")"
mkdir -p "$parent"
work="$(mktemp -d "${parent}/.fetch.XXXXXX")"
archive="${work}/${file}"
tmp="${work}/extract"
mkdir -p "$tmp"
trap 'rm -rf "$work"' EXIT

echo "Downloading ${BASE}/${file}"
curl -fL --retry 5 --retry-delay 5 -A "$UA" -o "$archive" "${BASE}/${file}"
curl -fsSL -A "$UA" -o "${archive}.md5" "${BASE}/${file}.md5"
(
  cd "$work"
  md5sum -c "${file}.md5"
)

echo "Extracting ${file} into ${DEST}"
tar -xjf "$archive" -C "$tmp"

mkdir -p "$(dirname "$DEST")"
rm -rf "$DEST"
if [ -d "$tmp/photon_data" ]; then
  mv "$tmp/photon_data" "$DEST"
elif [ -d "$tmp/elasticsearch" ]; then
  mkdir -p "$DEST"
  mv "$tmp"/* "$DEST"/
else
  echo "Unexpected Photon archive layout:" >&2
  find "$tmp" -maxdepth 3 -type d >&2
  exit 1
fi

if [ ! -d "${DEST}/elasticsearch" ]; then
  echo "Extracted index has no elasticsearch/ directory in ${DEST}" >&2
  exit 1
fi

echo "Photon index for ${COUNTRY} is in ${DEST}"
