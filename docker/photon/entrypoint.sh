#!/bin/sh
set -e

COUNTRY="${PHOTON_COUNTRY:-de}"
DATA="/photon/photon_data"

if [ ! -d "$DATA/elasticsearch" ]; then
  echo "Downloading Photon 0.5 index for ${COUNTRY}"
  # photon-db-<cc>-latest.tar.bz2 is a broken symlink on the mirror (HTTP 404).
  # Pick the dated archive, same rule as docs/deploy/photon/fetch-index.sh.
  INDEX=$(wget -qO- --user-agent="premium-landkarte-photon" \
    "https://download1.graphhopper.com/public/extracts/by-country-code/${COUNTRY}/")
  FILE=$(printf '%s\n' "$INDEX" | grep -oE "photon-db-${COUNTRY}-[0-9]+\\.tar\\.bz2" | sort -u | tail -1)
  if [ -z "$FILE" ]; then
    echo "No dated Photon dump for ${COUNTRY}"
    exit 1
  fi
  URL="https://download1.graphhopper.com/public/extracts/by-country-code/${COUNTRY}/${FILE}"
  echo "Using ${URL}"
  mkdir -p "$DATA"
  wget --user-agent="premium-landkarte-photon" -O "${DATA}/photon.tar.bz2" "$URL"
  tar -xjf "${DATA}/photon.tar.bz2" -C /photon
  rm -f "${DATA}/photon.tar.bz2"
  if [ -d /photon/photon_data ] && [ ! -d "$DATA/elasticsearch" ]; then
    echo "Index extracted into ${DATA}"
  fi
fi

if [ ! -d "$DATA/elasticsearch" ]; then
  echo "Photon index for ${COUNTRY} is missing. The country dump may not exist."
  exit 1
fi

exec java -jar /photon/photon.jar -data-dir "$DATA" -listen-ip 0.0.0.0 -listen-port 2322
