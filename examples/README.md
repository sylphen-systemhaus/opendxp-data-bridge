# Examples (Blackbit origin — reference only)

These tutorials were **taken over from [Blackbit Data Director](https://github.com/blackbitdigitalcommerce/pimcore-data-director)** (tutorial videos, sample dataports, import files, and `Task.md` walkthroughs). They are **included in the repository for reference**, not as turnkey OpenDXP tutorials.

## Status

| Aspect | Notes |
|--------|--------|
| **Platform** | Written for **Pimcore** + Blackbit bundle install (Bitbucket, `docker-install.sh`, Pimcore admin). |
| **OpenDXP** | **Not guaranteed to run as-is.** Sylphen targets OpenDXP 1.3+; UI and CLI differ (e.g. no “Import from Server” for assets — use **ZIP upload** in the Admin UI). |
| **Dataport JSON** | Top-level keys use **`sylphen_dd_*`** (v1.0 import format). **PHP namespaces, CLI commands, service IDs (`@DataDirector*`), class names, calculations, and CoreShop references** remain Blackbit/Pimcore-era — adapt before use on OpenDXP / Data Bridge. |
| **`Task.md` files** | **Blackbit instructions unchanged** (Bitbucket clone, Pimcore paths, CoreShop classes), each with an OpenDXP reference banner at the top. Treat as historical; use `./docker-setup.sh` (port 2000) or [docs/DEVELOPMENT.md](../docs/DEVELOPMENT.md) for OpenDXP. |
| **Sample data** | CSV/XML/assets under each example folder are still useful; you must adapt class definitions and dataports yourself. |

**Do not expect** to clone the repo, run a `Task.md` step-by-step, and get a working demo without manual porting.

## What each folder contains

| Folder | `Task.md` | Typical contents |
|--------|-----------|------------------|
| `1-csv-import` | yes | CSV, solution dataports |
| `2-ways-to-execute-imports` | yes | XML, dataports |
| `3-import-special-cases` | yes | Special import cases, dataports |
| `4-exports` | yes | Export dataports |
| `5-grid-exports` | yes | Grid export dataports |
| `6-data-quality` | yes | Data quality dataports, classes |
| `7-import-plugin-comparison` | yes | Notes only (no full walkthrough) |
| `8-data-query-selectors` | yes | Data query selector demo |
| `9-object-wizard` | yes | Object wizard, web2print dataports |
| `10-object-preview` | no | Preview sample CSV |
| `11-connect-indesign` | yes | InDesign export dataports |
| `12-chatgpt` | yes | ChatGPT / description generation |
| `13-pimcore-backend-on-steroids` | yes | Backend customization demo |
| `14-restore-versions` | yes | Version restore demo |

Video playlist (Blackbit, Pimcore): [YouTube](https://www.youtube.com/playlist?list=PL4-QRNfdsdKIfzQIP-c9hRruXf0r48fjt).

## OpenDXP: where to start

1. Install the bundle in your OpenDXP project — see [docs/DEVELOPMENT.md](../docs/DEVELOPMENT.md), or try the Docker demo (`./docker-setup.sh`, port 2000; root README).
2. Import **your own** class definitions (or adapt JSON under `examples/*/classes/` — check for `CoreShop*` / Pimcore-specific fields).
3. For assets: **ZIP upload** in OpenDXP Admin, or pass filesystem paths to CLI import commands (`data-bridge:complete`, etc.).
4. Import or rebuild dataports from `examples/*/dataports/` or `solution/` — verify JSON keys (`sylphen_dd_*`) and target classes match your OpenDXP project.

Full OpenDXP-specific example maintenance is **not** part of v1.0 scope.

## Licensing

Example data and dataport JSON are part of the same **GPL-3.0-or-later** distribution as the bundle (derivative of Blackbit Data Director). See root [`LICENSE`](../LICENSE) and [`NOTICE`](../NOTICE).
