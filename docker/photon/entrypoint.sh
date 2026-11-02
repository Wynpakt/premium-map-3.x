#!/bin/sh
set -e

COUNTRY="${PHOTON_COUNTRY:-de}"
# Photon 0.5 opens <data-dir>/photon_data/elasticsearch.
PHOTON_HOME=/photon
DATA="${PHOTON_HOME}/photon_data"

. /photon/download-archive.sh

if [ ! -d "$DATA/elasticsearch" ]; then
  echo "Downloading Photon 0.5 index for ${COUNTRY}"
  # photon-db-<cc>-latest.tar.bz2 is a broken symlink on the mirror (HTTP 404).
  # Pick the dated archive, same rule as docs/deploy/photon/fetch-index.sh.
  INDEX=$(curl -fsSL -A "premium-landkarte-photon" \
    "https://download1.graphhopper.com/public/extracts/by-country-code/${COUNTRY}/")
  FILE=$(printf '%s\n' "$INDEX" | grep -oE "photon-db-${COUNTRY}-[0-9]+\\.tar\\.bz2" | sort -u | tail -1)
  if [ -z "$FILE" ]; then
    echo "No dated Photon dump for ${COUNTRY}"
    exit 1
  fi
  URL="https://download1.graphhopper.com/public/extracts/by-country-code/${COUNTRY}/${FILE}"
  echo "Using ${URL}"
  mkdir -p "$DATA"
  download_archive "$URL" "${DATA}/photon.tar.bz2"
  tar -xjf "${DATA}/photon.tar.bz2" -C "$PHOTON_HOME"
  rm -f "${DATA}/photon.tar.bz2"
fi

if [ ! -d "$DATA/elasticsearch" ]; then
  echo "Photon index for ${COUNTRY} is missing. The country dump may not exist."
  exit 1
fi

exec java -jar /photon/photon.jar -data-dir "$PHOTON_HOME" -listen-ip 0.0.0.0 -listen-port 2322
