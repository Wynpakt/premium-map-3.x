FROM eclipse-temurin:11-jre-jammy

RUN apt-get update \
    && apt-get install -y --no-install-recommends bzip2 ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /photon
ADD https://github.com/komoot/photon/releases/download/0.5.0/photon-0.5.0.jar /photon/photon.jar
COPY docker/photon/entrypoint.sh /photon/entrypoint.sh
COPY docs/deploy/photon/download-archive.sh /photon/download-archive.sh
RUN chmod +x /photon/entrypoint.sh /photon/download-archive.sh

VOLUME /photon/photon_data
EXPOSE 2322

ENTRYPOINT ["/photon/entrypoint.sh"]
