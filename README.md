# Landkarte

Premium map based on leaflet and openstreetmap tiles

## Run local development server (Docker)

Requires Docker Compose. The app targets **PHP 8.5 + Phalcon 5.23** and Symfony 8.1.

```sh
docker compose up --build
```

The web image uses the moving tag `php:8.5-apache-bookworm`. Compose pulls that base image on build. The entrypoint stops if the image is still older than PHP 8.5.12.

Then open http://localhost:8085

On first start the entrypoint creates `app/config/config.php` from `app/config/config.docker.php`, runs `composer install`, and MySQL loads `docs/database/schema.sql`.

Smoke check:

```sh
curl -s 'http://localhost:8085/item/list?types[]=laeden&countries[]=DE&products[]=cola'
```

(Empty JSON list with seed schema only is fine — there are no items yet.)

Stop / wipe DB:

```sh
docker compose down
docker compose down -v   # also drop the MySQL volume
```

### Alternative: Vagrant

```sh
vagrant up
```

Open http://localhost:8085. Copy `app/config/config.vagrant.default.php` to `app/config/config.php` if needed.

```sh
vagrant destroy --force && vagrant up
```

## composer

install dependencies via

```sh
composer i
```

## configuration

copy config template

```sh
cp ./app/config/config.sample.php ./app/config/config.php
```

edit config vars for mysql connection and collmex api connection

```sh
nano ./app/config/config.php
```

## Deploy

Production is a normal Ubuntu host, not the Compose dev stack. The API lives at `/var/www/api.landkarte.premium-cola.de`, the Angular build at `/var/www/landkarte.premium-cola.de`. Photon for DE, AT and CH listens on `127.0.0.1:2322`, `:2323` and `:2324`. `config.default.php` already points at those URLs. Cron runs the Collmex sync and then geocoding.

### Update an existing host

```sh
rsync -e ssh -rv --exclude='app/config/config.php' app public composer.json composer.lock docs premium:/var/www/api.landkarte.premium-cola.de/
ssh premium 'cd /var/www/api.landkarte.premium-cola.de && sudo -u www-data composer install --no-dev --no-interaction && sudo -u www-data php app/migrate.php'
```

Leave `app/config/config.php` on the server. Install `vendor` there too, so the lockfile is resolved on the host that actually runs PHP.

### Fresh Ubuntu 26.04

Photon indexes need about 15 GB under `/var` (the installer asks for 20 GB free). Prefer 8 GB+ RAM for the Germany index.

On the server:

```sh
sudo git clone https://github.com/Wynpakt/premium-map-3.x.git /var/www/api.landkarte.premium-cola.de
cd /var/www/api.landkarte.premium-cola.de
sudo I_UNDERSTAND=1 bash docs/deploy/install-ubuntu-26.04.sh
```

The script installs Apache, PHP 8.5, Phalcon 5.23 from PECL, MySQL 8.4, Composer, three Photon 0.5 services, `/etc/cron.d/landkarte`, and builds the Angular map from `https://github.com/Wynpakt/premium-map-frontend` into `/var/www/landkarte.premium-cola.de`. Frontend source stays in `/var/src/premium-map-frontend`. It writes `app/config/config.php` from `config.default.php` and stores the database password in `/etc/landkarte/db.env`. Collmex and SMTP stay empty until you fill them in. Ubuntu 26.04 ships PHP 8.5 and MySQL 8.4; Phalcon is not packaged, so it is built with PECL. Photon 0.5 stays on Java 11. The Angular build needs Node 24 (installed via NodeSource).

`SKIP_FRONTEND=1` leaves the map document root alone. `SKIP_PHOTON=1` skips geocoding data. Rebuild only the map later with `I_UNDERSTAND=1 SKIP_PHOTON=1 bash docs/deploy/install-ubuntu-26.04.sh`.

TLS, once DNS for both hostnames points at the server:

```sh
sudo ENABLE_TLS=1 TLS_EMAIL=you@example.com I_UNDERSTAND=1 bash docs/deploy/install-ubuntu-26.04.sh
```

## Run collmex sync

```sh
# Fetch new item from collmex database
php app/cli.php update

# Enrich items with geolocation information (local Photon, see below)
php app/cli.php update geo

# Queue rows that gave up (geolocate_count >= 20) or still have a pending address change
php app/cli.php update geo-reset
```

## Add, list, delete products

```sh
# Add new product
php app/cli.php product add "New beverage name" "Beverage description" collmexId

# List all products
php app/cli.php product list

# Delete existing product
php app/cli.php product delete 5
```

## Which data points are published (and which are sorted out)

The map gets its markers from `GET /item/list` (`ItemController::apiListAction`). **A data point is published if, and only if, this endpoint returns it.** Everything else is considered sorted out, no matter whether it is stored in the database or not.

The front-end requests all offer types, products and countries:

```
/item/list?types[]=laeden&types[]=haendler&types[]=sprecher&types[]=webshop&countries[]=DE&countries[]=AT&countries[]=CH&products[]=cola&products[]=bier&products[]=frohlunder&products[]=muntermate
```

With this request, the parts of criteria 4, 5 and 8 below that depend on the request ("was requested via ...") never sort anything out. The criteria then boil down to: the item has at least one offer type, at least one product and a country of `DE`, `AT` or `CH`. They only matter if the front-end lets users narrow down the filter.

An address from Collmex has to pass all of the following criteria, in this order:

| # | Criterion | Enforced by | Sorted out if... |
|---|---|---|---|
| 1 | Record type | `BaseTask::collmexAddressGet` | The Collmex record type is not `CMXADR` |
| 2 | Opt-in | `UpdateTask::mainAction`, `deleteOldEntries` | The address is **not** in Collmex group **124** ("möchte auf die Landkarte") |
| 3 | Still in Collmex | `UpdateTask::deleteOldEntries` | The `address_id` is not returned by Collmex any more |
| 4 | Offer type | `UpdateTask::mapOffertypes`, `ItemController::apiListAction` | The address is in none of the groups **8** (Laden), **9** (Händler), **7** (Sprecher), **112** (Webshop), or none of its offer types was requested via `types` |
| 5 | Product | `Item::listMarker` | The address is in none of the product groups **20** (Cola), **21** (Bier), **29** (Frohlunder), **33** (Muntermate), or none of its products was requested via `products` |
| 6 | Opt-out / former customer | `ItemController::apiListAction` | The address is in Collmex group **93** ("möchte auf eigenen Wunsch nicht auf die Landkarte") or **16** ("ehemalige Kunden"), even if it is also in group 124 |
| 7 | Coordinates | `UpdateTask::geoAction`, `Item::listMarker` | The item has no `lat`/`lng` (see below) |
| 8 | Country | `Item::listMarker` | The Collmex country is not in the requested `countries` (default `DE`, `AT`, `CH`). An empty or other country code is sorted out |

Additionally, `/item/list` returns an empty list if the request has no `types` parameter at all. Valid values for `types` are `laeden`, `haendler`, `sprecher` and `webshop`.

The group ids are hard-coded in `UpdateTask::initialize()`, except for the product groups, which are read from `product.collmex_id`. The sync (`php app/cli.php update`) applies criteria 1–4 when it writes to the database. Criteria 5–8 are checked at request time by the API.

### Coordinates (criterion 7)

Coordinates are looked up via a local [Photon 0.5](https://github.com/komoot/photon) instance by `php app/cli.php update geo`. Photon serves the same OpenStreetMap data as Nominatim, without the public 1-request-per-second limit. Street names in the popup still come from Collmex, so they will not start matching Google Maps.

DE, AT and CH each have their own Photon process, because a country dump is one Elasticsearch index and Photon 0.5 does not merge them:

```sh
docker compose --profile photon up --build
```

The first start downloads the GraphHopper country indexes into Docker volumes. The name `photon-db-<cc>-latest.tar.bz2` is a broken symlink on the mirror (HTTP 404). The entrypoint picks the dated archive instead (`photon-db-de-250720.tar.bz2`, and the same pattern for AT and CH). Germany is about 9 GB compressed, Austria and Switzerland about 1 GB each. The API itself starts without the profile; geocoding then reports that the endpoint is missing.

- Only items with `geolocate_count < 20` are tried, and only when `lat`/`lng` is empty or `geocode_pending` is 1. Every attempt increments the counter. An item Photon cannot find 20 times is given up on. If the address changed, the previous pin stays until a new coordinate arrives — the marker does not disappear in between.
- To retry given-up rows and pending address changes: `php app/cli.php update geo-reset`, then `php app/cli.php update geo`.
- Items whose only offer type is *Sprecher* are located by city only.
- An item that has not been geocoded yet (for example right after the first sync) is sorted out until `update geo` has run.

Config key `photon` maps country codes to base URLs. Inside Compose that is `http://photon-de:2322` (and `-at`, `-ch`). From the host the published ports are 2322, 2323 and 2324.

### Collmex sync schedule

The map does not read Collmex on each request. `docs/cron/landkarte.cron` runs `update` and then `update geo` every hour. Copy it to `/etc/cron.d/landkarte` on the API host. The repository did not previously contain a cron unit, so a Collmex edit stays invisible until those commands run.

#### Checking whether an address can be located

With Photon running, the same query the sync sends is:

```
http://127.0.0.1:2322/api?limit=1&lang=de&q=STREET+ZIP+CITY+Germany
```

Use port 2323 and `Austria` for AT, port 2324 and `Switzerland` for CH. For speakers, omit street and zip. A hit has `features[0].geometry.coordinates` as `[lon, lat]`. An empty `features` array is why the item is missing. Typically that is a typo or a missing house number, zip or city; correcting it in Collmex resets the retry counter on the next sync and sets `geocode_pending`.

If Photon finds the address but the map still does not, check `lat`, `lng`, `geolocate_count` and `geocode_pending` on the `item` row.

### Things to know

- **Product names.** The product filter compares the requested names with `product.name` in the database. The seed data uses `Cola`, `Bier`, `Frohlunder`, `Muntermate`, and the front-end sends `cola`, `bier`, `frohlunder`, `muntermate`. The table charset is `utf8`, whose default collation compares those case-insensitively. The default product list, used when `products` is omitted, is `frohlunder` (the old default `holunder` matched nothing).
- **Checks that look like criteria but are not.** The required-fields check in `addressCleanup()` uses `isset()`, so an address with an empty city or zip passes (it will most likely just fail geocoding). The empty-groups check in `checkAddressGroups()` can never trigger either, because `explode()` always returns at least one element. An invalid e-mail address does not sort out an item, the e-mail is just not stored. Phone is stored from Collmex field 16 (CSV index 15) and does not sort an item out either.
- **Other endpoints.** `/item/all`, `/search/<string>` and `/item/<id>` apply fewer checks than `/item/list` (for example none of them looks at groups 16/93, coordinates or country). They are not what the map shows, so they are not relevant for the criteria above.

### Checklist: why is a shop missing on the map?

1. Is the Collmex address in group 124, and *not* in 93 or 16?
2. Does it have at least one offer type group (8, 9, 7, 112) **and** at least one product group (20, 21, 29, 33)?
3. Was `php app/cli.php update` run after the change in Collmex, followed by `php app/cli.php update geo`?
4. Does the item have `lat`/`lng` in the `item` table? If not, check `geolocate_count` (20 = given up) and `geocode_pending`, and whether Photon is running for that country.
5. Is the country `DE`, `AT` or `CH`?
6. Does the request really include the item's offer type and product? (The default front-end request includes all of them.)

Search (`GET /search/<text>`) matches name, city, zip or street, up to 20 rows, with a bound parameter. Duplicate candidates are listed by `php app/cli.php update duplicates` (same normalized address, or the same rounded pin). Nothing is hidden. `php app/cli.php update streets` prints Collmex streets that Photon resolved to a different OSM street. A new shop that is not a marker yet can be mailed via `POST /feedback/report` (`email`, `feedback`, `name`, optional `street`, `zip`, `city`, `country`).

## Production

### Restore db-backup

```bash
mysql landkarte < /var/backups/rsnapshot/*/mysql/*.sql
```
