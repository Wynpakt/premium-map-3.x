#!/bin/sh
# Started by systemd photon@.service. Environment comes from /etc/photon/<country>.env.
set -eu

JAVA="$(echo /usr/lib/jvm/java-11-openjdk-*/bin/java)"

if [ ! -x "$JAVA" ]; then
  echo "Java 11 missing at ${JAVA}. Photon 0.5 does not run on a newer JDK." >&2
  exit 1
fi

# Photon 0.5 opens ${data-dir}/photon_data/elasticsearch, not ${data-dir}/elasticsearch.
index="${PHOTON_DATA:?}/photon_data/elasticsearch"
if [ ! -d "$index" ]; then
  echo "Photon index missing in ${index}" >&2
  exit 1
fi

# API ports are 2322, 2323 and 2324. Embedded Elasticsearch must not share 9200/9300,
# or the three country nodes discover each other and form one cluster.
photon_port_base=2320
es_http_base=9200
es_http=$((es_http_base + ${PHOTON_PORT:?} - photon_port_base))
es_transport=$((es_http + 100))
cat > "${index}/config/elasticsearch.yml" << EOF
http.port: ${es_http}
transport.tcp.port: ${es_transport}
discovery.zen.ping.unicast.hosts: ["127.0.0.1:${es_transport}"]
discovery.zen.minimum_master_nodes: 1
EOF

exec "$JAVA" \
  -Xms"${PHOTON_XMS:-512m}" \
  -Xmx"${PHOTON_XMX:-2g}" \
  -jar /opt/photon/photon-0.5.0.jar \
  -data-dir "$PHOTON_DATA" \
  -listen-ip 127.0.0.1 \
  -listen-port "${PHOTON_PORT}"
