# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| current `main` | Yes |
| tagged releases | Yes, once published |

## Reporting a vulnerability

**Please do not open public GitHub issues for security problems.**

Report vulnerabilities privately to:

**[info@sylphen.com](mailto:info@sylphen.com)** — subject line: `Security: Sylphen Data Bridge`

Include, where possible:

- Affected version (Composer package version or Git commit)
- Component (REST API, import pipeline, admin UI, CLI, etc.)
- Steps to reproduce and impact assessment
- Proof-of-concept or patch suggestion (optional)

We aim to acknowledge reports within **5 business days** and will coordinate disclosure timing with the reporter when feasible.

## Scope notes

- **In scope:** This repository (`sylphen/opendxp-data-bridge`) — PHP bundle code, bundled configuration, and shipped admin assets under `Resources/public/` (excluding unrelated OpenDXP core issues in the host application).
- **Out of scope:** OpenDXP core, third-party OpenDXP plugins, host server misconfiguration, and issues in vendored libraries under `Resources/public/vendor/` (report those upstream; we will upgrade vendored copies when fixes are available).
- **Legacy URL aliases** removed in v1.0 — REST uses `SylphenDataBridge` only.

## Security-related configuration

- Review **API keys** and **dataport** permissions in production; restrict network access to REST endpoints where possible.
- **Telemetry:** `ImportController` may contact `mdm.sylphen.com` when enabled — disable or block at the network layer if not required in your environment.
- Run the bundle on **supported PHP versions** (≥ 8.3) with current OpenDXP security patches.
