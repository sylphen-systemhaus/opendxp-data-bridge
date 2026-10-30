# Packagist & GitHub releases (maintainers)

How `sylphen/opendxp-data-bridge` is published for `composer require` from [Packagist](https://packagist.org).

## One-time setup

### 1. GitHub repository

- Public repo, default branch **`main`**
- Branch protection on `main` (PR required) — see project onboarding notes
- Tags for releases: **`v1.0.0`**, **`v1.0.1`**, **`v1.1.0`**, … (semver with leading `v` is conventional)

### 2. Register on Packagist

1. Log in at [packagist.org](https://packagist.org) (GitHub OAuth is fine).
2. **Submit** → package URL: `https://github.com/sylphen-systemhaus/opendxp-data-bridge`
3. Packagist reads **`composer.json`** from the default branch and builds version list from **Git tags**.

### 3. Auto-update (recommended)

Packagist must refresh when you push tags or `composer.json` on `main`:

**Option A — GitHub webhook (easiest)**

1. Packagist → your package → **Settings** → **GitHub Hook**
2. Enable the hook (Packagist installs it on the GitHub repo)
3. Every push to GitHub triggers a Packagist update; new tags become installable versions within minutes

**Option B — Manual**

- Packagist package page → **Update** after each release

**Option C — API token**

- Packagist profile → **Show API token**
- Call after release:  
  `curl -XPOST -H 'content-type:json' 'https://packagist.org/api/update-package?username=USER&apiToken=TOKEN' -d '{"repository":{"url":"https://github.com/sylphen-systemhaus/opendxp-data-bridge"}}'`

## Release workflow (each version)

1. Merge feature/fix work to **`main`**
2. Open a short **`chore/release-x.y.z`** PR that bumps `"version"` in `composer.json` (for path/artifact installs and the admin footer tooltip) and `"extra.branch-alias.dev-main"` to the new minor line (e.g. `1.1.x-dev`)
3. Ensure **`composer.json`** on `main` is valid (`composer validate --no-check-lock`)
4. After that PR is squash-merged, create and push an **annotated tag** on the release commit on `main` (tags are the Packagist source of truth — not the merge commit message):

   ```bash
   git tag -a v1.1.0 -m "Sylphen Data Bridge 1.1.0 for OpenDXP"
   git push origin v1.1.0
   ```

5. Optional: **GitHub Release** from the same tag
6. Wait for Packagist webhook (or click **Update**)
7. Verify on Packagist: version `1.1.0` appears
8. Test install:

   ```bash
   composer require sylphen/opendxp-data-bridge:^1.1
   ```

## Versioning notes

| Topic | Guidance |
|-------|----------|
| **Tags = truth** | Packagist versions come from **Git tags**, not from commits alone |
| **`version` in composer.json** | The root `"version"` field is for local/artifact builds; Packagist ignores it when resolving from VCS. Prefer bumping **tags** for public releases |
| **Pre-release** | Tags like `v1.0.0-beta1` work; Composer treats them as unstable unless `minimum-stability` allows |
| **Branch alias** | Optional in `composer.json`: `"extra": { "branch-alias": { "dev-main": "1.1.x-dev" } }` for `@dev` installs from `main` |

## `composer.json` metadata (recommended)

Ensure Packagist page looks complete:

```json
"homepage": "https://github.com/sylphen-systemhaus/opendxp-data-bridge",
"support": {
  "issues": "https://github.com/sylphen-systemhaus/opendxp-data-bridge/issues",
  "source": "https://github.com/sylphen-systemhaus/opendxp-data-bridge"
}
```

## Consumers

After Packagist publish, projects install without a custom VCS or artifact repo:

```json
"require": {
  "sylphen/opendxp-data-bridge": "^1.1"
}
```

```bash
composer require sylphen/opendxp-data-bridge:^1.1
bin/console opendxp:bundle:install SylphenDataBridgeBundle
```

Constraint `^1.0` still resolves 1.1.x; prefer `^1.1` when you need the 1.1 migration and OpenDXP ≥ 1.4.2 virtual-field fixes.

Artifact ZIP installs (`./bundles/*.zip`) remain valid for air-gapped workflows — see [README.md](../README.md).

## Troubleshooting

| Problem | Check |
|---------|--------|
| Tag not on Packagist | Tag pushed? Webhook enabled? Manual **Update** on Packagist |
| Wrong version resolved | Tag name must be valid semver (`v1.0.0` → `1.0.0`) |
| `composer require` finds old version | Clear Composer cache: `composer clear-cache` |
| Package not found | Exact name `sylphen/opendxp-data-bridge` registered and public |
