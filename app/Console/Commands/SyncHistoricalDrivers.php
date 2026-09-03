<?php

namespace App\Console\Commands;

use App\Models\HistoricalDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncHistoricalDrivers extends Command
{
    protected $signature = 'historical-drivers:sync {--fresh : Truncate the table before syncing}';
    protected $description = 'Fetch all historical F1 drivers from Jolpica API and store aggregated stats in database';

    private const BASE_URL = 'https://api.jolpi.ca/ergast/f1';
    private const MAX_RETRIES = 5;
    private const FIRST_SEASON = 1950;

    private int $requestCount = 0;
    private int $delayMs = 2_000_000;

    public function handle(): int
    {
        if ($this->option('fresh')) {
            HistoricalDriver::truncate();
            $this->info('Table truncated.');
        }

        $currentYear = (int) date('Y');
        $this->info('Syncing historical F1 drivers from Jolpica API...');
        $this->info('Rate limit: ~500 req/h. Delay adapts automatically on 429.');
        $this->newLine();

        // 1. Fetch all drivers
        $this->info('1/3 Fetching all drivers...');
        $drivers = $this->fetchAllDrivers();
        if ($drivers === null) {
            $this->error('Failed to fetch drivers list.');
            return self::FAILURE;
        }
        $this->info(count($drivers) . ' drivers fetched.');
        $this->newLine();

        // 2. Fetch all season standings
        $this->info('2/3 Fetching season standings (1950-' . $currentYear . ')...');
        $statsMap = $this->fetchAndAggregateStandings($currentYear);
        $this->newLine();

        // 3. Upsert into database
        $this->info('3/3 Saving to database...');
        $bar = $this->output->createProgressBar(count($drivers));
        $bar->start();

        $saved = 0;
        foreach ($drivers as $driver) {
            $driverId = $driver['driverId'];
            $stats = $statsMap[$driverId] ?? null;
            $seasons = $stats ? array_keys($stats['seasonTeams']) : [];
            sort($seasons);

            // Build teams_history: unique teams in chronological order of first appearance
            $teamsHistory = [];
            if ($stats) {
                foreach ($seasons as $season) {
                    $team = $stats['seasonTeams'][$season] ?? null;
                    if ($team && !in_array($team, $teamsHistory, true)) {
                        $teamsHistory[] = $team;
                    }
                }
            }

            $data = [
                'given_name'       => $driver['givenName'] ?? '',
                'family_name'      => $driver['familyName'] ?? '',
                'date_of_birth'    => $driver['dateOfBirth'] ?? null,
                'nationality'      => $driver['nationality'] ?? '',
                'permanent_number' => $driver['permanentNumber'] ?? null,
                'code'             => $driver['code'] ?? null,
                'total_wins'       => $stats['totalWins'] ?? 0,
                'total_points'     => round($stats['totalPoints'] ?? 0, 2),
                'championships'    => $stats['championships'] ?? 0,
                'seasons_active'   => count($seasons),
                'first_season'     => !empty($seasons) ? min($seasons) : 0,
                'last_season'      => !empty($seasons) ? max($seasons) : 0,
                'last_team'        => !empty($teamsHistory) ? end($teamsHistory) : null,
                'teams_history'    => $teamsHistory,
            ];

            HistoricalDriver::updateOrCreate(
                ['driver_id' => $driverId],
                $data
            );

            $saved++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done! {$saved} drivers synced ({$this->requestCount} API calls).");

        return self::SUCCESS;
    }

    private function fetchAllDrivers(): ?array
    {
        $limit = 100;
        $offset = 0;
        $total = 1;
        $drivers = [];

        while ($offset < $total) {
            $response = $this->apiGet(self::BASE_URL . "/drivers.json?limit={$limit}&offset={$offset}");
            if ($response === null) {
                return null;
            }

            $total = (int) ($response['MRData']['total'] ?? 0);
            $fetched = $response['MRData']['DriverTable']['Drivers'] ?? [];
            $drivers = array_merge($drivers, $fetched);
            $offset += $limit;

            $this->line("  Fetched {$offset}/{$total} drivers...");
        }

        return $drivers;
    }

    private function fetchAndAggregateStandings(int $currentYear): array
    {
        $totalSeasons = $currentYear - self::FIRST_SEASON + 1;
        $bar = $this->output->createProgressBar($totalSeasons);
        $bar->start();

        $statsMap = [];

        for ($year = self::FIRST_SEASON; $year <= $currentYear; $year++) {
            $response = $this->apiGet(self::BASE_URL . "/{$year}/driverStandings.json?limit=100");

            if ($response !== null) {
                $lists = $response['MRData']['StandingsTable']['StandingsLists'] ?? [];
                if (!empty($lists)) {
                    $standingsList = $lists[0];
                    $standings = $standingsList['DriverStandings'] ?? [];

                    foreach ($standings as $entry) {
                        $driverId = $entry['Driver']['driverId'];
                        $wins = (int) ($entry['wins'] ?? 0);
                        $points = (float) ($entry['points'] ?? 0);
                        $position = (int) ($entry['position'] ?? 0);
                        $team = $entry['Constructors'][0]['name'] ?? null;

                        if (!isset($statsMap[$driverId])) {
                            $statsMap[$driverId] = [
                                'totalWins' => 0,
                                'totalPoints' => 0.0,
                                'championships' => 0,
                                'seasonTeams' => [],
                            ];
                        }

                        $statsMap[$driverId]['totalWins'] += $wins;
                        $statsMap[$driverId]['totalPoints'] += $points;

                        if ($position === 1) {
                            $statsMap[$driverId]['championships']++;
                        }

                        if ($team) {
                            $statsMap[$driverId]['seasonTeams'][$year] = $team;
                        }
                    }
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $statsMap;
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
