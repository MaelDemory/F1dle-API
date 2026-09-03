<?php

namespace App\Console\Commands;

use App\Models\Driver;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SeedDriversFromApi extends Command
{
    protected $signature = 'drivers:seed-from-api {season?}
                            {--no-stats : Skip per-driver stat API calls (uses wins from standings, omits poles/entries/fastestLaps)}
                            {--no-career-data : Skip fetching first seasons and career points/championships (leaves them at 0)}';
    protected $description = 'Seed drivers and teams from the Jolpica F1 API with up-to-date career stats';

    private const BASE_URL = 'https://api.jolpi.ca/ergast/f1';
    private const MAX_RETRIES = 3;

    /**
     * Mapping: Jolpica constructor name => [F1dle team name, logo filename]
     */
    private const TEAM_MAPPING = [
        'Mercedes'        => ['name' => 'Mercedes',                    'logo' => 'mercedes.png'],
        'Red Bull'        => ['name' => 'Redbull',                     'logo' => 'redbull.png'],
        'Ferrari'         => ['name' => 'Ferrari',                     'logo' => 'ferrari.png'],
        'McLaren'         => ['name' => 'McLaren',                     'logo' => 'mclaren.png'],
        'RB F1 Team'      => ['name' => 'Racing Bulls',                'logo' => 'racing-bulls.png'],
        'Aston Martin'    => ['name' => 'Aston Martin',                'logo' => 'aston-martin.png'],
        'Haas F1 Team'    => ['name' => 'Haas',                        'logo' => 'haas.png'],
        'Williams'        => ['name' => 'Williams',                    'logo' => 'Logo_Williams_F1.png'],
        'Alpine F1 Team'  => ['name' => 'Alpine',                      'logo' => 'alpine.png'],
        'Sauber'          => ['name' => 'Stake F1 Team Kick Sauber',   'logo' => 'sauber.png'],
    ];

    private int $requestCount = 0;
    private int $delayMs = 2_000_000; // 2s initial delay (adapts on 429)

    public function handle(): int
    {
        $season = $this->argument('season') ?? (string) date('Y');
        $this->info("Fetching {$season} season data...");
        $this->info('Rate limit: ~500 req/h. Delay adapts automatically on 429.');
        $this->newLine();

        // 1. Fetch and seed teams
        $this->info('1/4 Fetching constructor standings...');
        $teamMap = $this->seedTeams($season);
        if ($teamMap === null) {
            $this->error("Failed to fetch constructor standings for season {$season}.");
            return self::FAILURE;
        }
        $this->info(count($teamMap) . ' teams seeded.');
        $this->newLine();

        // 2. Fetch driver standings
        $this->info('2/4 Fetching driver standings...');
        $standings = $this->fetchDriverStandings($season);
        if ($standings === null) {
            $this->error("Failed to fetch driver standings for season {$season}.");
            return self::FAILURE;
        }

        $drivers = $this->extractDrivers($standings);
        $this->info(count($drivers) . ' drivers found.');
        $this->newLine();

        $noStats      = (bool) $this->option('no-stats');
        $noCareerData = (bool) $this->option('no-career-data');

        // 3. Fetch first season for each driver + batch career data
        if ($noCareerData) {
            $this->info('3/4 Career data skipped (--no-career-data). Will be backfilled from historical_drivers.');
            foreach ($drivers as &$driverInfo) {
                $driverInfo['firstSeason'] = (int) $season;
            }
            unset($driverInfo);
            $careerData = [];
        } else {
            $this->info('3/4 Fetching career data (first seasons + points + championships)...');
            $firstSeasons = [];
            foreach ($drivers as &$driverInfo) {
                $firstSeason = $this->fetchFirstSeason($driverInfo['driverId']);
                $driverInfo['firstSeason'] = $firstSeason ?? (int) $season;
                $firstSeasons[] = $driverInfo['firstSeason'];
            }
            unset($driverInfo);

            $earliestYear = min($firstSeasons);
            $this->line("  Scanning standings from {$earliestYear} to {$season}...");
            $driverIds = array_column($drivers, 'driverId');
            $careerData = $this->fetchCareerDataBatch($driverIds, $earliestYear, (int) $season);
            $this->newLine();
        }

        // 4. Fetch per-driver stats and upsert
        if ($noStats) {
            $this->info('4/4 Seeding drivers from standings data (--no-stats, skipping per-driver API calls)...');
        } else {
            $this->info('4/4 Fetching per-driver career stats...');
        }

        $bar = $this->output->createProgressBar(count($drivers));
        $bar->start();

        $seeded = 0;
        foreach ($drivers as $driverInfo) {
            $driverId = $driverInfo['driverId'];
            $careerPoints = $careerData[$driverId]['points'] ?? 0;
            $championships = $careerData[$driverId]['championships'] ?? 0;
            $teamName = $driverInfo['team'];
            $teamId = $teamMap[$teamName] ?? null;

            $data = [
                'name'               => $driverInfo['familyName'],
                'surname'            => $driverInfo['givenName'],
                'birth_date'         => $driverInfo['dateOfBirth'],
                'nationality'        => $driverInfo['nationality'],
                'team'               => $teamName,
                'team_id'            => $teamId,
                'first_entry'        => $driverInfo['firstSeason'],
                'career_points'      => $careerPoints,
                'world_championship' => $championships,
            ];

            if ($noStats) {
                // Use wins already available in standings data — skip 6 extra API calls per driver
                $data['win'] = $driverInfo['winsFromStandings'] ?? 0;
            } else {
                $stats = $this->fetchDriverStats($driverId);
                if ($stats !== null) {
                    $data['win']          = $stats['wins'];
                    $data['pole']         = $stats['poles'];
                    $data['podium']       = $stats['podiums'];
                    $data['entries']      = $stats['entries'];
                    $data['fastest_laps'] = $stats['fastestLaps'];
                } else {
                    $this->newLine();
                    $this->warn("  {$driverId}: stats partielles (429), career data OK.");
                }
            }

            Driver::updateOrCreate(
                ['driver_number' => $driverInfo['number']],
                $data
            );

            $seeded++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Done! {$seeded} drivers seeded ({$this->requestCount} API calls).");
        return self::SUCCESS;
    }

    private function seedTeams(string $season): ?array
    {
        $url = self::BASE_URL . "/{$season}/constructorstandings.json?limit=100";
        $response = $this->apiGet($url);

        $lists = $response['MRData']['StandingsTable']['StandingsLists'] ?? [];
        if (empty($lists)) {
            return null;
        }

        $constructorStandings = $lists[0]['ConstructorStandings'] ?? [];
        if (empty($constructorStandings)) {
            return null;
        }

        $logoDirectory = database_path('seeders/team-logos');
        $teamMap = [];

        foreach ($constructorStandings as $entry) {
            $jolpicaName = $entry['Constructor']['name'] ?? null;
            if ($jolpicaName === null) {
                continue;
            }

            $mapping = self::TEAM_MAPPING[$jolpicaName] ?? null;
            $f1dleName = $mapping['name'] ?? $jolpicaName;
            $logoFile = $mapping['logo'] ?? null;

            $logoBase64 = null;
            $logoMimeType = null;
            if ($logoFile) {
                $path = $logoDirectory . DIRECTORY_SEPARATOR . $logoFile;
                if (is_file($path)) {
                    $mimeType = mime_content_type($path);
                    $contents = file_get_contents($path);
                    if ($mimeType !== false && $contents !== false) {
                        $logoBase64 = base64_encode($contents);
                        $logoMimeType = $mimeType;
                    }
                }
            }

            $team = Team::updateOrCreate(
                ['name' => $f1dleName],
                [
                    'logo_base64'  => $logoBase64,
                    'logo_mime_type' => $logoMimeType,
                ]
            );

            $teamMap[$f1dleName] = $team->id;
            $this->line("  {$jolpicaName} → {$f1dleName}" . ($logoBase64 ? ' (logo)' : ' (no logo)'));
        }

        return $teamMap;
    }

    private function fetchDriverStandings(string $season): ?array
    {
        $url = self::BASE_URL . "/{$season}/driverstandings.json?limit=100";
        $response = $this->apiGet($url);

        return $response['MRData']['StandingsTable']['StandingsLists'][0]['DriverStandings'] ?? null;
    }

    private function extractDrivers(array $standings): array
    {
        $drivers = [];

        foreach ($standings as $entry) {
            $driver = $entry['Driver'];
            $constructor = $entry['Constructors'][0] ?? null;
            $jolpicaName = $constructor['name'] ?? 'Unknown';
            $mapping = self::TEAM_MAPPING[$jolpicaName] ?? null;
            $team = $mapping['name'] ?? $jolpicaName;

            $number = $driver['permanentNumber'] ?? null;
            if ($number === null) {
                continue;
            }

            $drivers[] = [
                'driverId'           => $driver['driverId'],
                'givenName'          => $driver['givenName'],
                'familyName'         => $driver['familyName'],
                'dateOfBirth'        => $driver['dateOfBirth'],
                'nationality'        => $driver['nationality'],
                'number'             => (int) $number,
                'team'               => $team,
                'winsFromStandings'  => (int) ($entry['wins'] ?? 0),
            ];
        }

        return $drivers;
    }

    private function fetchDriverStats(string $driverId): ?array
    {
        $wins = $this->fetchTotal("/drivers/{$driverId}/results/1.json");
        if ($wins === null) return null;

        $p2 = $this->fetchTotal("/drivers/{$driverId}/results/2.json");
        $p3 = $this->fetchTotal("/drivers/{$driverId}/results/3.json");
        $poles = $this->fetchTotal("/grid/1/drivers/{$driverId}/results.json");
        $entries = $this->fetchTotal("/drivers/{$driverId}/results.json");
        $fastestLaps = $this->fetchTotal("/fastest/1/drivers/{$driverId}/results.json");

        return [
            'wins'        => $wins,
            'poles'       => $poles ?? 0,
            'podiums'     => $wins + ($p2 ?? 0) + ($p3 ?? 0),
            'entries'     => $entries ?? 0,
            'fastestLaps' => $fastestLaps ?? 0,
        ];
    }

    private function fetchTotal(string $path): ?int
    {
        $url = self::BASE_URL . $path . '?limit=1';
        $response = $this->apiGet($url);
        if ($response === null) return null;

        $total = $response['MRData']['total'] ?? null;
        return $total !== null ? (int) $total : null;
    }

    private function fetchFirstSeason(string $driverId): ?int
    {
        $url = self::BASE_URL . "/drivers/{$driverId}/seasons.json?limit=1";
        $response = $this->apiGet($url);
        if ($response === null) return null;

        $seasons = $response['MRData']['SeasonTable']['Seasons'] ?? [];
        return !empty($seasons) ? (int) $seasons[0]['season'] : null;
    }

    private function fetchCareerDataBatch(array $driverIds, int $fromYear, int $toYear): array
    {
        $careerData = [];
        foreach ($driverIds as $id) {
            $careerData[$id] = ['points' => 0.0, 'championships' => 0];
        }

        $driverIdSet = array_flip($driverIds);
        $totalYears = $toYear - $fromYear + 1;
        $bar = $this->output->createProgressBar($totalYears);
        $bar->start();

        for ($year = $fromYear; $year <= $toYear; $year++) {
            $url = self::BASE_URL . "/{$year}/driverstandings.json?limit=100";
            $response = $this->apiGet($url);

            if ($response !== null) {
                $lists = $response['MRData']['StandingsTable']['StandingsLists'] ?? [];
                if (!empty($lists)) {
                    $standingsList = $lists[count($lists) - 1];
                    $standings = $standingsList['DriverStandings'] ?? [];
                    $shouldCountChampionships = $this->isFinalSeasonStandings($year, $standingsList);

                    foreach ($standings as $entry) {
                        $id = $entry['Driver']['driverId'];
                        if (!isset($driverIdSet[$id])) {
                            continue;
                        }

                        $careerData[$id]['points'] += (float) ($entry['points'] ?? 0);

                        if ($shouldCountChampionships && (int) ($entry['position'] ?? 0) === 1) {
                            $careerData[$id]['championships']++;
                        }
                    }
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $careerData;
    }

    protected function isFinalSeasonStandings(int $year, array $standingsList): bool
    {
        $currentYear = (int) date('Y');
        if ($year < $currentYear) {
            return true;
        }

        if ($year > $currentYear) {
            return false;
        }

        $standingsRound = (int) ($standingsList['round'] ?? 0);
        if ($standingsRound === 0) {
            return false;
        }

        $finalRound = $this->fetchSeasonFinalRound($year);

        return $finalRound !== null && $standingsRound === $finalRound;
    }

    protected function fetchSeasonFinalRound(int $year): ?int
    {
        $url = self::BASE_URL . "/{$year}.json?limit=100";
        $response = $this->apiGet($url);
        if ($response === null) {
            return null;
        }

        $races = $response['MRData']['RaceTable']['Races'] ?? [];
        if (empty($races)) {
            return null;
        }

        $rounds = array_map(
            static fn (array $race): int => (int) ($race['round'] ?? 0),
            $races
        );

        return max($rounds);
    }

    /**
     * HTTP GET with adaptive rate limiting and retry on 429.
     */
    private function apiGet(string $url): ?array
    {
        usleep($this->delayMs);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $this->requestCount++;
                $response = Http::timeout(10)->get($url);

                if ($response->successful()) {
                    return $response->json();
                }

                if ($response->status() === 429) {
                    // Use Retry-After header if available, otherwise exponential backoff
                    $retryAfter = $response->header('Retry-After');
                    $waitSeconds = $retryAfter ? (int) $retryAfter : min(15 * $attempt, 60);

                    // Increase base delay to avoid future 429s
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
