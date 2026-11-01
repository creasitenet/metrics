# Metrics

Metrics is a simple, open-source, self-hosted **Docker host monitor**. A dedicated collector reads the Docker Engine API through `docker.sock` and stores CPU/memory time series. The web UI only reads that database. No agents, no project tokens, no push API.

![Metrics dashboard](docs/screenshots/dashboard.png)

It runs on FrankenPHP (Caddy + PHP in one binary) with **SQLite** (single file in `./var/data`, low RAM, ideal for self-hosting).

## Features

- Automatic Docker collection (host + containers) on a schedule
- Host-level gauges (container counts, aggregated CPU/RAM)
- Per-container charts (1h / 24h / 7d / 30d)
- Compose grouping by folder → project → service (CPU/RAM totals per level)
- Dozzle-style grouping via the `dev.dozzle.group` label
- No built-in login: access control is delegated to a reverse proxy (Caddy, Traefik, nginx)
- Collector loop in a plain PHP process (no Messenger, no Scheduler)
- Retention pruning for historical samples (and orphan GC for removed containers)
- Bounded container log tails (last 500 lines per container by default, collector-synced)

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

```bash
cp .env.dist .env
# Edit APP_SECRET (and METRICS_PORT / DEFAULT_URI), then:
ENV_FILE=.env docker compose up -d --build
```

SQLite data persists in `./var/data` (shared by `metrics` and `collector`). Only the **collector** mounts `docker.sock` read-only.

The collector is a plain PHP loop (`app:collector:loop`) that samples Docker every `COLLECTOR_INTERVAL` seconds and runs the daily retention/orphan maintenance on its first cycle of the day. It stops cleanly on `SIGTERM` / `SIGINT`, so `docker compose stop` never kills it mid-write.

### Grouping

The dashboard builds a **host → folder → project → service → container** tree and sums CPU/RAM at each level:

| Level | Source |
|-------|--------|
| Folder | `com.docker.compose.project.working_dir` |
| Project | `dev.dozzle.group`, else `com.docker.compose.project` |
| Service | `com.docker.compose.service`, else the container name |

Containers without those labels are grouped under *Other containers* / *No Compose project* / *No service*.

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

Two maintenance jobs run once a day from the `collector` (needs Docker socket for orphan GC):

| When | What |
|------|------|
| First cycle of the day | `container_sample` / `host_sample` rows older than `METRICS_RETENTION_DAYS` (default **30**) |
| First cycle of the day | `container` / `container_sample` / `container_log` rows for containers **removed** from Docker (`?all=1`, so stopped containers are kept) |

Host samples are never pruned by the orphan job, and no container row is dropped while Docker still knows it.

Manual run:

```bash
docker compose exec metrics php bin/console app:sample:prune
# or override:
docker compose exec metrics php bin/console app:sample:prune --days=7

# Orphans (compare DB vs Docker inventory):
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
docker.sock → collector (loop) → MetricsWriter → container / container_sample / host_sample
                                                        ↓
                                            metrics dashboard (DB only)
```

- `src/Collector/Docker/`: Engine client, mapper, collector
- `src/Metrics/MetricsWriter.php`: transactional container + sample writes
- `src/Command/CollectorLoopCommand.php`: sampling loop + daily maintenance (SIGTERM/SIGINT aware)
- `src/Entity/`: `Container` (metadata), `ContainerSample` (history), `HostSample` (host history), `ContainerLog`
- `compose.yaml`: FrankenPHP `metrics` (UI) + `collector` (socket); SQLite volume shared

### Data model

| Table | Holds | Pruned by |
|-------|-------|-----------|
| `container` | Identity + Compose/Dozzle labels of each container | Orphan GC |
| `container_sample` | CPU, RAM and running state over time | Retention + orphan GC |
| `host_sample` | Host CPU, RAM, container/image counts over time | Retention |
| `container_log` | Last N log lines per container | Orphan GC |

CPU follows the `docker stats` scale: **100 % = one fully used core**.

## Security

See [SECURITY.md](SECURITY.md) for deployment guidance and how to report vulnerabilities.

## License

Metrics is distributed under the [MIT License](LICENSE).
