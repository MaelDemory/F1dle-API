<?php

namespace App\Console\Commands;

use App\Models\Driver;
use App\Models\HistoricalDriver;
use Illuminate\Console\Command;

class SeedAll extends Command
{
    protected $signature = 'app:seed
                            {--fresh : Truncate all sync tables before seeding}
                            {--with-races : Also sync race results (slow, ~1h due to rate limiting)}
                            {--with-stats : Fetch full per-driver stats from API (poles, entries, fastest laps — adds ~10 min)}
                            {--season= : Season year for current-grid drivers (default: current year)}';

    protected $description = 'Populate the database with all F1 data: drivers, historical drivers, champions, and optionally race results';

    public function handle(): int
    {
        $fresh     = (bool) $this->option('fresh');
        $withStats = (bool) $this->option('with-stats');
        $season    = $this->option('season') ?? date('Y');
        $syncArgs  = $fresh ? ['--fresh' => true] : [];

        $this->newLine();
        $this->line('  <fg=red;options=bold>F1dle</> — Full database seeding');
        $this->newLine();

        // ── Step 1 : Current grid drivers + teams (fast: no career API calls) ──
        $driverArgs = ['season' => $season, '--no-career-data' => true];
        if (! $withStats) {
            $driverArgs['--no-stats'] = true;
        }

        $this->components->info(
            "Step 1/5 — Current grid drivers & teams (season {$season})"
            . (! $withStats ? ' [fast mode — add --with-stats for poles/entries]' : '')
        );
        $status = $this->call('drivers:seed-from-api', $driverArgs);
        if ($status !== self::SUCCESS) {
            $this->components->error('Step 1 failed. Aborting.');
            return self::FAILURE;
        }

        // ── Step 2 : Historical drivers ────────────────────────────────────────
        $this->newLine();
        $this->components->info('Step 2/5 — Historical drivers (all seasons since 1950)');
        $status = $this->call('historical-drivers:sync', $syncArgs);
        if ($status !== self::SUCCESS) {
            $this->components->error('Step 2 failed. Aborting.');
            return self::FAILURE;
        }

        // ── Step 3 : Backfill career data for current grid from historical_drivers
        $this->newLine();
        $this->components->info('Step 3/5 — Backfilling career points & championships from historical data');
        $this->backfillCareerData();

        // ── Step 4 : Season champions ──────────────────────────────────────────
        $this->newLine();
        $this->components->info('Step 4/5 — World champions per season (1950–' . date('Y') . ')');
        $status = $this->call('season-champions:sync', $syncArgs);
        if ($status !== self::SUCCESS) {
            $this->components->error('Step 4 failed. Aborting.');
            return self::FAILURE;
        }

        // ── Step 5 : Race results (optional) ──────────────────────────────────
        $this->newLine();
        if ($this->option('with-races')) {
            $this->components->info('Step 5/5 — Race results cache (1950–2024, ~1h due to API rate limits)');
            $this->call('season-races:sync', $syncArgs);
        } else {
            $this->components->warn('Step 5/5 — Race results skipped. Run with --with-races to include them.');
        }

        $this->newLine();
        $this->components->info('All done! The F1dle database is ready.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function backfillCareerData(): void
    {
        $currentDrivers   = Driver::all();
        $historicalDrivers = HistoricalDriver::all();

        $updated = 0;

        foreach ($currentDrivers as $driver) {
            // Driver.name = familyName, Driver.surname = givenName (legacy naming)
            $match = $historicalDrivers->first(function (HistoricalDriver $h) use ($driver): bool {
                return strcasecmp($h->given_name, $driver->surname) === 0
                    && strcasecmp($h->family_name, $driver->name) === 0;
            });

            if ($match === null) {
                continue;
            }

            $driver->update([
                'career_points'      => $match->total_points,
                'world_championship' => $match->championships,
                'win'                => $match->total_wins,
                'first_entry'        => $match->first_season,
            ]);

            $updated++;
        }

        $this->line("  {$updated}/{$currentDrivers->count()} drivers backfilled from historical data.");
    }
}
