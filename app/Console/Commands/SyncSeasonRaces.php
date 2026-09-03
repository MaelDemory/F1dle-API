<?php

namespace App\Console\Commands;

use App\Models\SeasonRace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncSeasonRaces extends Command
{
    protected $signature = 'season-races:sync
                            {--year= : Sync a specific year only}
                            {--fresh : Truncate the table before syncing}';

    protected $description = 'Fetch all F1 race results from Jolpica API and store them in database';

    private const BASE_URL = 'https://api.jolpi.ca/ergast/f1';
    private const MAX_RETRIES = 5;
    private const FIRST_SEASON = 1950;
    private const LAST_SEASON = 2024;

    private int $requestCount = 0;
    private int $delayMs = 2_000_000;

    public function handle(): int
    {
        if ($this->option('fresh')) {
            SeasonRace::truncate();
            $this->info('Table truncated.');
        }

        $specificYear = $this->option('year') ? (int) $this->option('year') : null;

        if ($specificYear !== null) {
            $years = [$specificYear];
        } else {
            $years = range(self::FIRST_SEASON, self::LAST_SEASON);
        }

        $this->info('Syncing F1 race results from Jolpica API...');
        $this->info('Rate limit: ~500 req/h. Delay adapts automatically on 429.');
        $this->newLine();

        $bar = $this->output->createProgressBar(count($years));
        $bar->start();

        $totalSaved = 0;

        foreach ($years as $year) {
            $races = $this->fetchSeasonRaces($year);

            foreach ($races as $round => $race) {
                SeasonRace::updateOrCreate(
                    ['year' => $year, 'round' => (int) $round],
                    [
                        'race_name'        => $race['raceName'],
                        'circuit_id'       => $race['Circuit']['circuitId'],
                        'circuit_name'     => $race['Circuit']['circuitName'],
                        'circuit_locality' => $race['Circuit']['Location']['locality'] ?? null,
                        'circuit_country'  => $race['Circuit']['Location']['country'] ?? null,
                        'date'             => $race['date'],
                        'results'          => $race['Results'],
                    ]
                );
                $totalSaved++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done! {$totalSaved} races synced ({$this->requestCount} API calls).");

        return self::SUCCESS;
    }

    private function fetchSeasonRaces(int $year): array
    {
        $limit = 100;
        $offset = 0;
        $total = 1;
        $racesMap = [];

        while ($offset < $total) {
            $response = $this->apiGet(self::BASE_URL . "/{$year}/results.json?limit={$limit}&offset={$offset}");

            if ($response === null) {
                $this->newLine();
                $this->warn("  Failed to fetch {$year} at offset {$offset}, skipping.");
                break;
            }

            $total = (int) ($response['MRData']['total'] ?? 0);
            $races = $response['MRData']['RaceTable']['Races'] ?? [];

            foreach ($races as $race) {
                $round = $race['round'];
                if (isset($racesMap[$round])) {
                    $racesMap[$round]['Results'] = array_merge(
                        $racesMap[$round]['Results'],
                        $race['Results']
                    );
                } else {
                    $racesMap[$round] = $race;
                }
            }

            $offset += $limit;
        }

        return $racesMap;
    }

    private function apiGet(string $url): ?array
    {
        usleep($this->delayMs);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $this->requestCount++;
                $response = Http::timeout(30)->get($url);

                if ($response->successful()) {
                    return $response->json();
                }

                if ($response->status() === 429) {
                    $retryAfter = $response->header('Retry-After');
                    $waitSeconds = $retryAfter ? (int) $retryAfter : min(15 * $attempt, 60);
                    $this->delayMs = min($this->delayMs + 1_000_000, 10_000_000);

                    $this->newLine();
                    $this->warn("  429 Rate limited. Waiting {$waitSeconds}s, delay now " . ($this->delayMs / 1_000_000) . "s (attempt {$attempt}/" . self::MAX_RETRIES . ")");
                    sleep($waitSeconds);
                    continue;
                }

                $this->newLine();
                $this->warn("  HTTP {$response->status()} for: {$url}");
                return null;
            } catch (\Exception $e) {
                if ($attempt < self::MAX_RETRIES) {
                    $this->newLine();
                    $this->warn("  Request error: {$e->getMessage()} — retrying in 10s...");
                    sleep(10);
                    continue;
                }
                $this->newLine();
                $this->warn("  Request failed: {$e->getMessage()}");
                return null;
            }
        }

        $this->newLine();
        $this->error("  Max retries exceeded for: {$url}");
        return null;
    }
}
