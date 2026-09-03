<?php

namespace App\Console\Commands;

use App\Models\SeasonChampion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncSeasonChampions extends Command
{
    protected $signature = 'season-champions:sync
                            {--year= : Sync a specific year only}
                            {--fresh : Truncate the table before syncing}';

    protected $description = 'Fetch F1 world champions from Jolpica API and store them in database';

    private const BASE_URL = 'https://api.jolpi.ca/ergast/f1';
    private const MAX_RETRIES = 5;
    private const FIRST_SEASON = 1950;
    private const LAST_SEASON = 2025;

    private int $requestCount = 0;
    private int $delayMs = 2_000_000;

    public function handle(): int
    {
        if ($this->option('fresh')) {
            SeasonChampion::truncate();
            $this->info('Table truncated.');
        }

        $specificYear = $this->option('year') ? (int) $this->option('year') : null;
        $years = $specificYear !== null ? [$specificYear] : range(self::FIRST_SEASON, self::LAST_SEASON);

        $this->info('Syncing F1 world champions from Jolpica API...');
        $this->newLine();

        $bar = $this->output->createProgressBar(count($years));
        $bar->start();

        $synced = 0;

        foreach ($years as $year) {
            $champion = $this->fetchChampion($year);

            if ($champion !== null) {
                SeasonChampion::updateOrCreate(
                    ['year' => $year],
                    $champion
                );
                $synced++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done! {$synced} champions synced ({$this->requestCount} API calls).");

        return self::SUCCESS;
    }

    private function fetchChampion(int $year): ?array
    {
        $url = self::BASE_URL . "/{$year}/driverStandings/1.json";
        $response = $this->apiGet($url);

        if ($response === null) {
            return null;
        }

        $standings = $response['MRData']['StandingsTable']['StandingsLists'][0]['DriverStandings'][0] ?? null;

        if ($standings === null) {
            $this->newLine();
            $this->warn("  No standings found for {$year}, skipping.");
            return null;
        }

        $driver      = $standings['Driver'];
        $constructor = $standings['Constructors'][0] ?? null;

        return [
            'driver_id'   => $driver['driverId'],
            'given_name'  => $driver['givenName'],
            'family_name' => $driver['familyName'],
            'nationality' => $driver['nationality'],
            'constructor' => $constructor['name'] ?? 'Unknown',
            'wins'        => (int) ($standings['wins'] ?? 0),
            'points'      => (float) ($standings['points'] ?? 0),
        ];
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
                    $retryAfter  = $response->header('Retry-After');
                    $waitSeconds = $retryAfter ? (int) $retryAfter : min(15 * $attempt, 60);
                    $this->delayMs = min($this->delayMs + 1_000_000, 10_000_000);

                    $this->newLine();
                    $this->warn("  429 Rate limited. Waiting {$waitSeconds}s (attempt {$attempt}/" . self::MAX_RETRIES . ")");
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
