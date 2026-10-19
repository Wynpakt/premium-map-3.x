FROM eclipse-temurin:11-jre-jammy

RUN apt-get update \
    && apt-get install -y --no-install-recommends bzip2 ca-certificates wget \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /photon
ADD https://github.com/komoot/photon/releases/download/0.5.0/photon-0.5.0.jar /photon/photon.jar
COPY entrypoint.sh /photon/entrypoint.sh
RUN chmod +x /photon/entrypoint.sh

VOLUME /photon/photon_data
EXPOSE 2322

ENTRYPOINT ["/photon/entrypoint.sh"]
