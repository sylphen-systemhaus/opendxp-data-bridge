# Contributing

Thank you for your interest in Sylphen Data Bridge!

## Repository

- **GitHub (public):** [github.com/sylphen-systemhaus/opendxp-data-bridge](https://github.com/sylphen-systemhaus/opendxp-data-bridge) — forks and pull requests welcome
- **Questions / access:** [info@sylphen.com](mailto:info@sylphen.com)

## Workflow (GitHub)

We use a **protected `main` branch**: changes land via **pull request**, not direct pushes (maintainers included).

1. **Fork** the repository (external contributors) or create a **feature branch** (team members with write access).
2. Use a focused branch name, e.g. `feature/rest-export-headers`, `fix/dataport-json-keys`, `docs/examples-readme`.
3. Keep one topic per PR; rebase or merge `main` before opening if the branch is older.
4. Open a **pull request** against `main` with a short description and test notes (template is pre-filled).
5. Ensure **CI** is green (`composer validate`, PHP syntax check).
6. A maintainer reviews and **squash-merges** when approved.

### Branch naming

| Prefix | Use for |
|--------|---------|
| `feature/` | New behavior |
| `fix/` | Bug fixes |
| `docs/` | Documentation only |
| `chore/` | Tooling, CI, non-user-facing |

Release tags (e.g. `v1.0.0`) are created on `main` by maintainers only — not via PR merge commits as version bumps.

## License & copyright headers

All contributions must be licensable under **GPL-3.0-or-later** and use the project file header:

```php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
```

New and changed PHP, JS, and CSS files should include this block at the top.

Do **not** remove Blackbit authorship in existing code; Sylphen changes add attribution, they do not replace it.

## Code & conventions

- PHP ≥ 8.3, OpenDXP ≥ 1.3
- Follow existing namespaces and patterns (`Sylphen\DataBridgeBundle\…`)
- No Pimcore runtime dependencies or misleading Blackbit commercial-license text in new code
- Before opening a PR: run relevant checks in a target OpenDXP project or the Docker demo (`./docker-setup.sh`, see root README)

## Issues

Use GitHub **Issues** for bugs and feature requests (templates provided). Search existing issues first.

Security vulnerabilities: follow [SECURITY.md](SECURITY.md) — **no public issues** for security reports.

## Callback templates

Custom callback functions belong in a separate bundle (similar to the Blackbit template skeleton). For Sylphen-specific templates, contact [info@sylphen.com](mailto:info@sylphen.com).

## Releases & Packagist

Releases are tagged on GitHub (`v*`). Packagist distributes `sylphen/opendxp-data-bridge` from those tags — see [docs/PACKAGIST.md](docs/PACKAGIST.md) (maintainers).
