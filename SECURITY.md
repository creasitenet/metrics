# Security Policy

Metrics is a self-hosted Docker host monitor. This document covers deployment risks and how to report vulnerabilities.

## Supported versions

| Version | Supported |
| ------- | --------- |
| latest `main` / latest release tag | yes |
| older tags | best effort |

## Reporting a vulnerability

**Do not open a public GitHub issue for security problems.**

Please report privately via [GitHub Security Advisories](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability) for this repository, or by email to the maintainer address listed on the GitHub repo.

Include:

- A clear description of the issue
- Steps to reproduce
- Impact assessment (what an attacker could do)
- Affected version or commit hash, if known

We aim to acknowledge reports within a few business days and to coordinate a fix before public disclosure when possible.

## Deployment model (read this before exposing Metrics)

Metrics is designed to run on a **trusted Docker host**, not on the public Internet without additional controls.

### Docker socket access

The **collector** service mounts `docker.sock` (read-only in Compose, but still powerful). Anyone who can talk to that socket effectively has **root-level control over containers on the host**.

- Mount `docker.sock` **only** on the collector service (the default Compose layout does this).
- Do not expose the collector container to untrusted networks.
- Prefer running Metrics on a dedicated monitoring host or a restricted management network.

### Web dashboard

The dashboard shows container metrics and log tails from your host.

Metrics has **no built-in authentication** and no user accounts. Access control is delegated to your reverse proxy (Caddy, Traefik, nginx, Nginx Proxy Manager, oauth2-proxy…).

- **Always set a strong `APP_SECRET`** in production (see `.env.dist`).
- Put Metrics **behind HTTPS** (reverse proxy: Caddy, Traefik, nginx, etc.) when accessed outside localhost.
- Enforce authentication at the proxy level (Basic auth, OAuth2 proxy, VPN, IP allowlist).
- Restrict network access (VPN, firewall, internal DNS) if the instance is not purely local.
- Do not publish the dashboard port directly on the Internet.
- Compose binds the published port to `127.0.0.1` by default (`METRICS_BIND`). Setting `METRICS_BIND=0.0.0.0` exposes the dashboard on every interface of the host — only do it behind an authenticating proxy.

### Database

SQLite data lives in `./var/data` by default. Protect that directory on the host (filesystem permissions, backups, encryption at rest if required by your policy).

### Container privileges

The collector may run as `root` inside the container to read `docker.sock` on some hosts. Treat the collector container as a **high-privilege workload**.

## Out of scope / known limitations

These are product choices, not bugs:

- No built-in authentication: access control is the reverse proxy's responsibility.
- No rate limiting on the dashboard (mitigate with network controls or a reverse proxy).
- Metrics reflects what the Docker Engine API exposes; it is not a full SIEM or intrusion detection system.

## Security updates

Watch releases on GitHub and rebuild/redeploy the Docker image when security fixes are published.

For dependency updates, the project uses Composer lock file pinning and CI (PHPUnit, PHPStan, linting) on each change.
