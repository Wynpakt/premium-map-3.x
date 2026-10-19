#!/bin/sh
set -e

COUNTRY="${PHOTON_COUNTRY:-de}"
DATA="/photon/photon_data"

if [ ! -d "$DATA/elasticsearch" ] && [ -z "$(ls -A "$DATA" 2>/dev/null)" ]; then
  echo "Downloading Photon 0.5 index for ${COUNTRY}"
  URL="https://download1.graphhopper.com/public/extracts/by-country-code/${COUNTRY}/photon-db-${COUNTRY}-latest.tar.bz2"
  wget --user-agent="premium-landkarte-photon" -O /tmp/photon.tar.bz2 "$URL"
  tar -xjf /tmp/photon.tar.bz2 -C /photon
  rm -f /tmp/photon.tar.bz2
  if [ -d /photon/photon_data ] && [ ! -d "$DATA/elasticsearch" ]; then
    echo "Index extracted into ${DATA}"
  fi
fi

if [ ! -d "$DATA" ] || [ -z "$(ls -A "$DATA" 2>/dev/null)" ]; then
  echo "Photon index for ${COUNTRY} is missing. The country dump may not exist."
  exit 1
fi

exec java -jar /photon/photon.jar -data-dir "$DATA" -listen-ip 0.0.0.0 -listen-port 2322
