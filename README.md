# Metrics

Metrics is a simple, open-source, self-hosted **Docker host monitor**. A dedicated collector reads the Docker Engine API through `docker.sock` and stores CPU/memory time series in SQLite. The web UI only reads that database. No agents, no project tokens, no push API.

![Metrics dashboard](docs/screenshots/dashboard.png)

It runs on FrankenPHP (Caddy + PHP in one binary) with **SQLite** (single file in `./var/data`, low RAM, ideal for self-hosting).

## Features

- Automatic Docker collection (host + containers) on a schedule
- **Single-page UI** (`/` with `?c=` to select a container): grouped sidebar, detail strip, log tail
- **Host context in the header** (node name, Docker version, container count, uptime when available, live CPU/RAM + subtle pulse)
- **Container focus**: 1h CPU/RAM charts beside the title; host charts are not duplicated in the detail panel
- Compose-style sidebar groups with per-group CPU/RAM totals; optional `dev.dozzle.group` label
- Healthcheck state parsed from Docker `Status` (`healthy` / `unhealthy` / `starting`)
- Live updates via HTMX (OOB patches + Idiomorph) so the layout does not rebuild every tick
- No built-in login: access control is delegated to a reverse proxy (Caddy, Traefik, nginx)
- Collector loop in a plain PHP process (no Messenger, no Scheduler)
- Retention pruning for buckets and logs, plus orphan GC on every collector cycle
- Bounded container log tails (default 500 lines per container, collector-synced)

## Requirements

- Docker Engine with Docker Compose v2
- Access to `/var/run/docker.sock` (or `DOCKER_SOCKET`) for the **collector** service

**Security:** mounting `docker.sock` is equivalent to root on the Docker host. Only the collector mounts it. The dashboard has no built-in authentication: keep it behind a reverse proxy that enforces access control (Basic auth, OAuth2 proxy, VPN).

## Quick start

Pick one deployment mode. Reverse proxy (Case 2) is the usual self-hosted setup; Case 1 is fine for a quick try on a free host port.

### Case 1 — Standalone (direct Compose)

Compose publishes the dashboard on the host, **bound to `127.0.0.1` by default** — the dashboard has no built-in authentication, so it must not be reachable from the network as-is. The port comes from `.env` (`METRICS_PORT`, default **8080**). Change it if something else already uses 8080 (e.g. another stack).

```bash
cp .env.dist .env
# optional: METRICS_PORT=8081 and DEFAULT_URI=http://localhost:8081
docker compose up -d --build
```

Open [http://localhost:8080](http://localhost:8080) (or your `METRICS_PORT`). Docker Compose reads `.env` for `${METRICS_PORT}` interpolation; pass `ENV_FILE=.env` if you want the same file injected into the containers instead of `.env.dist`.

To reach the dashboard from another machine, set `METRICS_BIND=0.0.0.0` in `.env` — and put a reverse proxy in front of it, since access control is delegated to that proxy:

```bash
METRICS_BIND=0.0.0.0
METRICS_PORT=8080
```

### Case 2 — Reverse proxy (Nginx / Traefik / Caddy / NPM)

No host port. The UI is only reachable on the Docker network as **`http://metrics:80`**.

1. `cp .env.dist .env` and set `DEFAULT_URI` to your public URL (e.g. `https://metrics.example.com`).
2. In `compose.yaml` on the `metrics` service:
   - **Comment out** the `ports:` block.
   - **Uncomment** `- proxy-tier` under `networks:`.
3. At the bottom of `compose.yaml`, **uncomment** the `proxy-tier:` external network (rename if your proxy network differs).
4. Point your VHost / Proxy Host at `http://metrics:80`.
5. Start:

```bash
docker compose up -d --build
```

Migrations run on `metrics` startup. The `collector` service loops every `COLLECTOR_INTERVAL` seconds (default 30).

Force one collection cycle:

```bash
docker compose exec collector php bin/console app:collector:run -v
```

### Environment

See `.env.dist` for the full list. Common knobs:

| Variable | Default | Role |
|----------|---------|------|
| `COLLECTOR_INTERVAL` | `30` | Sample period (seconds) |
| `METRICS_RETENTION_DAYS` | `2` | Prune legacy `*_sample` rows and old bucket rollups |
| `METRICS_RAW_HOURS` | `2` | Keep **30s** `metric_bucket` rows at least this long |
| `METRICS_CHART_MAX_POINTS` | `400` | Cap points per chart series |
| `METRICS_LOG_TAIL` | `500` | Max log lines stored per container |
| `METRICS_LOG_SYNC_INTERVAL` | `300` | Seconds between log tail syncs per container |
| `METRICS_LOG_RETENTION_DAYS` | `7` | Drop `container_log` rows older than this |

```bash
cp .env.dist .env
# Edit APP_SECRET (and METRICS_PORT / DEFAULT_URI), then:
ENV_FILE=.env docker compose up -d --build
```

SQLite data persists in `./var/data` (shared by `metrics` and `collector`). Only the **collector** mounts `docker.sock` read-only.

The collector is a plain PHP loop (`app:collector:loop`) that samples Docker every `COLLECTOR_INTERVAL` seconds, reconciles removed containers on every cycle, and runs daily retention maintenance on its first cycle of the day. It stops cleanly on `SIGTERM` / `SIGINT`, so `docker compose stop` never kills it mid-write.

### Sidebar grouping

| Level | Source |
|-------|--------|
| Group name | `dev.dozzle.group`, else `com.docker.compose.project`, else *Other containers* |
| Container row | Name, state, health tag (if any), last CPU/RAM |

Containers without Compose labels land in **Other containers**.

## Operations

### Health

```bash
# Case 1
curl -fsS "http://localhost:${METRICS_PORT:-8080}/healthz"
# Case 2 (from a container on the proxy network, or via your public URL)
curl -fsS https://metrics.example.com/healthz
docker compose exec metrics wget -qO- http://127.0.0.1/healthz
```

### Retention

Daily maintenance runs on the collector’s first cycle of each day. Orphan reconciliation runs on **every** collection cycle:

| Action | Detail |
|--------|--------|
| Raw buckets | Delete **30s** `metric_bucket` rows older than `METRICS_RAW_HOURS` |
| Legacy samples | Prune `container_sample` / `host_sample` if present, older than `METRICS_RETENTION_DAYS` |
| Logs | Prune `container_log` older than `METRICS_LOG_RETENTION_DAYS` |
| Orphans | Every cycle: remove DB rows for containers no longer in `docker ps -a` (a Compose recreate gets a new id, so the old row must go) |

The UI charts **one hour** of **30s** buckets. Live gauges come from `host_state` and `container.last_*` columns updated each collect.

Reclaim disk after a large prune: `docker compose exec metrics php bin/console app:sqlite:vacuum` (stop collector first on busy hosts).

Manual run:

```bash
docker compose exec metrics php bin/console app:sample:prune
docker compose exec collector php bin/console app:orphan-containers:prune
```

Set `METRICS_RETENTION_DAYS=0` to disable the scheduled age prune only.

### Backup (SQLite)

```bash
docker compose stop metrics collector
cp ./var/data/metrics.sqlite* /path/to/backup/
docker compose start metrics collector
```

### Troubleshooting

```bash
docker compose ps
docker compose logs --tail=200 metrics collector
docker compose exec metrics php bin/console about
```

- Empty charts: wait for a collector tick, then run `app:collector:run` in the collector container.
- Collector logs “Docker socket is not readable”: check the socket mount and permissions.
- After pulling a UI/storage upgrade, run pending migrations (see below).

### Upgrading

```bash
docker compose pull   # or rebuild from source
docker compose exec metrics php bin/console doctrine:migrations:migrate --no-interaction
docker compose up -d
```

Recent schema changes include `metric_bucket`, `host_state`, `container.last_*` gauges, `health_status`, host `node_name` / `server_version` / `uptime_seconds`. Always migrate before relying on the new dashboard.

## Local installation without Docker Compose

Still requires a readable Docker socket for the product to be useful:

```bash
cp .env.dist .env
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:collector:loop
# in another terminal:
symfony server:start
```

## Contributing

Bug reports and pull requests are welcome on GitHub. Run the quality checks before opening a PR:

```bash
composer test
composer analyse
composer cs-check
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for a short workflow.

## Quality and tests

```bash
composer validate --strict
php bin/console lint:container
php bin/console lint:twig templates
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## Architecture

```text
docker.sock → collector (loop) → MetricsWriter → host_state + container gauges
                        │                      → metric_bucket (30s)
                        │                      → container / container_log
                        ↓
              metrics dashboard (DB only, HTMX partial sync)
```

- `src/Collector/Docker/`: Engine client, mapper, collector
- `src/Metrics/MetricsWriter.php`: transactional writes (no new `*_sample` rows on the hot path)
- `src/Command/CollectorLoopCommand.php`: sampling loop + daily retention maintenance (SIGTERM/SIGINT aware)
- `src/Controller/DashboardController.php`: `/`, `/partials/dashboard` (OOB sync), legacy `/containers/{id}` redirect
- `compose.yaml`: FrankenPHP `metrics` (UI) + `collector` (socket); SQLite volume shared

### Data model (current)

| Table / row | Holds | Pruned by |
|-------------|--------|-----------|
| `container` | Identity, Compose/Dozzle labels, `last_*` gauges, `health_status` | Orphan GC |
| `host_state` | Singleton live host snapshot (CPU, RAM, counts, Docker identity) | — |
| `metric_bucket` | Time series (30s; host id `__host__` + per-container) | `METRICS_RAW_HOURS` / retention |
| `container_log` | Tail of stdout/stderr lines | Log retention + orphan GC |
| `container_sample` / `host_sample` | Legacy history (if any remain) | Retention prune |

CPU follows the `docker stats` scale: **100 % = one fully used core** (aggregated host CPU can exceed 100 %).

Host **uptime** in the header is filled from `/proc/uptime` when the collector runs on Linux (not on Docker Desktop macOS). Container **up …** comes from the Docker `Status` string.

## Security

See [SECURITY.md](SECURITY.md) for deployment guidance and how to report vulnerabilities.

## License

Metrics is distributed under the [MIT License](LICENSE).
