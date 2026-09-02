# Development in an existing OpenDXP project

How to integrate Sylphen Data Bridge into a **real OpenDXP installation** — as opposed to the isolated Docker demo (`./docker-setup.sh`, port 2000; see the root README).

**Recommended workflow:** This bundle is a **separate Git repository**. The OpenDXP project adds it as a **submodule** (path repo) for development — bundle source is not duplicated in the parent repo. **Production** installs from [Packagist](https://packagist.org/packages/sylphen/opendxp-data-bridge) (`^1.0`); see the root [README.md](../README.md).

## 1. Set up submodule (once)

In the OpenDXP project root (where `composer.json` lives):

```bash
git submodule add https://github.com/sylphen-systemhaus/opendxp-data-bridge.git packages/opendxp-data-bridge
git submodule update --init --recursive
```

> Path `packages/opendxp-data-bridge` is relative to the OpenDXP root.

## 2. Composer: path repository (commit in project)

Path repo first so Composer symlinks:

```json
"repositories": [
  {
    "type": "path",
    "url": "./packages/opendxp-data-bridge",
    "options": { "symlink": true }
  }
],
"require": {
  "sylphen/opendxp-data-bridge": "@dev"
}
```

## 3. Local dev version (optional, gitignored)

`composer.local.json` in the OpenDXP root (merged via merge-plugin):

```json
{
  "require": {
    "sylphen/opendxp-data-bridge": "@dev"
  }
}
```

Then:

```bash
composer update sylphen/opendxp-data-bridge
bin/console cache:clear
bin/console assets:install --symlink --relative
```

Result: `vendor/sylphen/opendxp-data-bridge` → symlink to `packages/opendxp-data-bridge`. Submodule changes are visible immediately.

## 4. Daily dev loop

```bash
# In submodule: develop feature
cd packages/opendxp-data-bridge
git checkout -b feature/…
# … edit …
git commit && git push

# In OpenDXP project: update submodule pointer
cd ../..
git add packages/opendxp-data-bridge
git commit -m "Bump data-bridge submodule"
```

**Important:** Commit bundle code **in the submodule repo**, not in the parent project. The parent only commits the submodule SHA.

## 5. Production without a submodule

Remove the path repository from the project `composer.json` and install a tagged release from Packagist:

```bash
composer require sylphen/opendxp-data-bridge:^1.0
bin/console opendxp:bundle:install SylphenDataBridgeBundle
bin/console assets:install --symlink --relative
```

See [PACKAGIST.md](PACKAGIST.md) for the maintainer release workflow.

## Updates / migrations

```bash
bin/console opendxp:bundle:install SylphenDataBridgeBundle
```

If installation fails, the same command shows migration errors. Install uses a single baseline migration (`Migrations/Version00000001.php`). After schema changes: DB dump, `opendxp:bundle:uninstall`, drop old tables if needed, reinstall.

## See also

- [README.md](../README.md) — Docker demo (`./docker-setup.sh`, port 2000)
- [CONTRIBUTING.md](../CONTRIBUTING.md) — PR workflow, license headers
- [PACKAGIST.md](PACKAGIST.md) — public releases
