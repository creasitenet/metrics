# Contributing

Thanks for helping improve Metrics.

## Setup

```bash
cp .env.dist .env
composer install
php bin/console doctrine:migrations:migrate --no-interaction
```

Run the collector in one terminal (`php bin/console app:collector:loop`) and the web app in another (`symfony server:start`), or use `docker compose up`.

Dashboard work lives under `templates/dashboard/`, `public/assets/`, and `src/Controller/DashboardController.php`. After UI changes, run `php bin/console lint:twig templates` and reload with migrations applied.

## Before a pull request

```bash
composer test
composer analyse
composer cs-check
```

Keep changes focused. Metrics targets a **single-host**, **SQLite-only**, self-hosted monitor — avoid adding optional databases, auth layers, or heavy infrastructure unless discussed in an issue first.

## Security

Do not open public issues for vulnerabilities. See [SECURITY.md](SECURITY.md).
