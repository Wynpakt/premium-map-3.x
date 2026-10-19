#!/bin/sh
# Started by systemd photon@.service. Environment comes from /etc/photon/<country>.env.
set -eu

JAVA="$(echo /usr/lib/jvm/java-11-openjdk-*/bin/java)"

if [ ! -x "$JAVA" ]; then
  echo "Java 11 missing at ${JAVA}. Photon 0.5 does not run on a newer JDK." >&2
  exit 1
fi

if [ ! -d "${PHOTON_DATA:?}/elasticsearch" ]; then
  echo "Photon index missing in ${PHOTON_DATA}" >&2
  exit 1
fi

exec "$JAVA" \
  -Xms"${PHOTON_XMS:-512m}" \
  -Xmx"${PHOTON_XMX:-2g}" \
  -jar /opt/photon/photon-0.5.0.jar \
  -data-dir "$PHOTON_DATA" \
  -listen-ip 127.0.0.1 \
  -listen-port "${PHOTON_PORT:?}"
