# Security Policy

Metrics is a self-hosted Docker host monitor. This document covers deployment risks and how to report vulnerabilities.

## Supported versions

| Version | Supported |
| ------- | --------- |
| latest `main` / latest release tag | yes |
| older tags | best effort |

## Reporting a vulnerability

**Do not open a public GitHub issue for security problems.**

Please report privately by email to the maintainers (use the contact address listed on the GitHub repository or in `composer.json` once published).

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

- **Always set a strong `APP_SECRET`** in production (see `.env.dist`).
- Create accounts with `app:user:create`; there is no public registration.
- Use strong passwords (minimum 12 characters enforced by the CLI).
- Put Metrics **behind HTTPS** (reverse proxy: Caddy, Traefik, nginx, etc.) when accessed outside localhost.
- Restrict network access (VPN, firewall, internal DNS) if the instance is not purely local.

### Database

SQLite data lives in `./var/data` by default. Protect that directory on the host (filesystem permissions, backups, encryption at rest if required by your policy).

If you use PostgreSQL or MySQL, follow your database provider's hardening guidance and use least-privilege credentials.

### Container privileges

The collector may run as `root` inside the container to read `docker.sock` on some hosts. Treat the collector container as a **high-privilege workload**.

## Out of scope / known limitations

These are product choices, not bugs:

- No built-in multi-user RBAC (all logged-in users see the same dashboard).
- No rate limiting on the login form (mitigate with network controls or a reverse proxy).
- Metrics reflects what the Docker Engine API exposes; it is not a full SIEM or intrusion detection system.

## Security updates

Watch releases on GitHub and rebuild/redeploy the Docker image when security fixes are published.

For dependency updates, the project uses Composer lock file pinning and CI (PHPUnit, PHPStan, linting) on each change.
