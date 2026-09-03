<?php

namespace Tests\Unit;

use App\Console\Commands\SeedDriversFromApi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SeedDriversFromApiTest extends TestCase
{
    #[Test]
    public function it_treats_completed_past_seasons_as_final_standings(): void
    {
        $command = new TestableSeedDriversFromApi();

        $this->assertTrue($command->exposedIsFinalSeasonStandings(2024, ['round' => '24']));
    }

    #[Test]
    public function it_does_not_treat_current_season_intermediate_standings_as_a_world_title(): void
    {
        $currentYear = (int) date('Y');
        $command = new TestableSeedDriversFromApi([$currentYear => 24]);

        $this->assertFalse($command->exposedIsFinalSeasonStandings($currentYear, ['round' => '2']));
    }

    #[Test]
    public function it_accepts_current_season_standings_once_the_final_round_is_reached(): void
    {
        $currentYear = (int) date('Y');
        $command = new TestableSeedDriversFromApi([$currentYear => 24]);

        $this->assertTrue($command->exposedIsFinalSeasonStandings($currentYear, ['round' => '24']));
    }
}

class TestableSeedDriversFromApi extends SeedDriversFromApi
{
    public function __construct(private array $finalRoundsByYear = [])
    {
        parent::__construct();
    }

    public function exposedIsFinalSeasonStandings(int $year, array $standingsList): bool
    {
        return $this->isFinalSeasonStandings($year, $standingsList);
    }

    protected function fetchSeasonFinalRound(int $year): ?int
    {
        return $this->finalRoundsByYear[$year] ?? null;
    }
}