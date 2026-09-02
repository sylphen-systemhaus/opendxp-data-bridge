# Sylphen Data Bridge

**Import/export and REST API for OpenDXP** — exchange data between external sources and OpenDXP objects, assets, and documents without writing code.

Sylphen Data Bridge is an OpenDXP bundle by [Sylphen GmbH & Co. KG](https://sylphen.com), based on [Blackbit Data Director](https://github.com/blackbitdigitalcommerce/pimcore-data-director) 3.10.4, adapted for **OpenDXP 1.3+**.

## Features

- **Dataports** — import/export of XML, CSV, JSON, Excel, and other formats
- **Field mapping** — visual mapping from source to target fields
- **REST API** — import, export, and status via `/api/rest/…` (or `/webservice/SylphenDataBridge/rest/…`)
- **Queue & cron** — automated, parallel processing of large datasets
- **OpenDXP integration** — backend menu, custom field types, custom reports, perspectives

## Requirements

- PHP ≥ 8.3
- OpenDXP ≥ 1.3 (`open-dxp/opendxp`, `open-dxp/admin-bundle`)
- Composer 2.x

## Installation

### Docker demo (try-out)

Isolated **OpenDXP + Data Bridge** on **http://localhost:2000/admin** (`admin` / `admin`). Requires Docker Compose v2 and network access (Composer).

```bash
git clone https://github.com/sylphen-systemhaus/opendxp-data-bridge.git
cd opendxp-data-bridge
chmod +x docker-setup.sh
./docker-setup.sh
```

The checkout is mounted as a Composer **path repo** — edits are visible immediately.

### From GitHub (VCS)

In an existing OpenDXP project:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/sylphen-systemhaus/opendxp-data-bridge.git"
  }
],
"require": {
  "sylphen/opendxp-data-bridge": "@dev"
}
```

```bash
composer require sylphen/opendxp-data-bridge:@dev
bin/console opendxp:bundle:install SylphenDataBridgeBundle
bin/console assets:install --symlink --relative
```

Submodule + path repo (typical in a real project): [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

The bundle registers automatically via `extra.opendxp.bundles` in the package `composer.json`. Or manually in `config/bundles.php`:

```php
Sylphen\DataBridgeBundle\SylphenDataBridgeBundle::class => ['all' => true],
```

### From Packagist (when published)

After a tagged release is registered on Packagist:

```bash
composer require sylphen/opendxp-data-bridge
bin/console opendxp:bundle:install SylphenDataBridgeBundle
bin/console assets:install --symlink --relative
```

Maintainers: [docs/PACKAGIST.md](docs/PACKAGIST.md).

### From artifact ZIP (offline)

If you received a vendor ZIP, place it in `bundles/` and add to the project `composer.json` (OpenDXP root):

```json
"repositories": [
  {
    "type": "artifact",
    "url": "./bundles/"
  }
],
"require": {
  "sylphen/opendxp-data-bridge": "*"
}
```

```bash
composer update sylphen/opendxp-data-bridge
bin/console opendxp:bundle:install SylphenDataBridgeBundle
bin/console assets:install --symlink --relative
```

## REST API

Canonical endpoints (API key required):

```http
POST /api/rest/import/{dataportId}?apikey=…
GET  /api/rest/export/{dataportId}?apikey=…
GET  /api/rest/status?…
```

Alternative webservice schema (same controllers):

```http
POST /webservice/SylphenDataBridge/rest/import/{dataportId}?apikey=…
```

Blackbit URL segments (`BlackbitDataDirector`, `BlackbitPim`, …) are **not supported**. See [`SECURITY.md`](SECURITY.md).

## Documentation & examples

- [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) — integrate into an OpenDXP project (submodule, path repo)
- [docs/PACKAGIST.md](docs/PACKAGIST.md) — tagged releases and Packagist (maintainers)
- [docs/UPGRADE.md](docs/UPGRADE.md) — leftover `data_director_*` indexes after Blackbit / early Sylphen installs
- **Feature handbook** (original Blackbit, including Data Query Selectors): [Data Director Docs](https://blackbitdigitalcommerce.github.io/pimcore-data-director/#data-query-selectors)
- **Tutorial videos:** [YouTube playlist](https://www.youtube.com/playlist?list=PL4-QRNfdsdKIfzQIP-c9hRruXf0r48fjt)
- **Sample data:** [`examples/`](examples/) — Blackbit-origin reference; see [examples/README.md](examples/README.md)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security reports: [SECURITY.md](SECURITY.md) — no public issues for vulnerabilities.

## License

This bundle is licensed under the [GNU General Public License v3.0 or later](LICENSE) (SPDX: `GPL-3.0-or-later`). Additional attribution and bundled dependencies: [`NOTICE`](NOTICE).

Copyright © [Sylphen GmbH & Co. KG](https://sylphen.com). Based on [Blackbit Data Director](https://github.com/blackbitdigitalcommerce/pimcore-data-director) — original copyright © Blackbit digital Commerce GmbH.
