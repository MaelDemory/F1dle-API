# F1dle — API

Laravel API for **F1dle**, a Formula 1 guessing game inspired by Wordle. It
serves drivers, teams, world champions and race results out of MySQL.

This repository holds the API only. The React frontend lives in a separate
repository: **[MaelDemory/F1dle](https://github.com/MaelDemory/F1dle)** — that
is the publicly exposed app, and it proxies `/api/` through to here.

## Stack

Laravel 11 · PHP 8.2 · Eloquent · MySQL 8 · php-fpm + nginx

## Endpoints

| Route | Description |
|---|---|
| `GET /api/drivers` | Current-grid drivers |
| `GET /api/random` | Random driver from the current grid |
| `GET /api/historical-drivers` | Every driver since 1950 |
| `GET /api/random-historical-driver` | Random historical driver, no threshold |
| `GET /api/random-historical-winner` | Random historical driver with at least one win |
| `GET /api/teams` | Teams with logos |
| `GET /api/season-champions` | World champion per season |
| `GET /api/season-races/{year}` | Results for that season |
| `GET /metrics` | Prometheus metrics |
| `GET /up` | Health check |

**The controllers only ever read the database.** There is no fallback to the
Jolpica API at request time: an empty table returns an empty response. Seeding
is an explicit act.

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve           # http://localhost:8000
```

## Seeding

```bash
php artisan app:seed --with-stats     # drivers, historical drivers, champions
php artisan season-races:sync         # race results (~1 h)
```

> `--with-stats` is not optional in practice. Without it, `entries`, `pole`,
> `podium` and `fastest_laps` stay at 0 for the whole grid, and the game shows
> "0 entries" for every driver.
>
> The Jolpica API is capped at roughly 500 requests per hour. **Never run two
> seed commands at once**: the resulting 429s leave drivers at 0 with nothing
> more than a warning. `season-races:sync` writes with `updateOrCreate` and
> accepts `--year=`, so interrupting it is safe and a targeted re-run is
> possible.

Data comes from [the Jolpica/Ergast API](https://api.jolpi.ca/ergast/f1).

## Full documentation

The architecture, the Fly.io deployment procedure and the database schema are
documented one level up, in the workspace that holds this repository alongside
the frontend's, next to `docker-compose.yml`:

- `README.md` — features, architecture, API, seeding, deployment
- `DEPLOY.md` — Fly.io procedure, secrets, known pitfalls

Those files are not versioned in this repository.
