<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SeasonRace;
use Illuminate\Http\JsonResponse;

class SeasonRaceController extends Controller
{
    public function show(int $year): JsonResponse
    {
        $races = SeasonRace::where('year', $year)
            ->orderBy('round')
            ->get();

        return response()->json($races->map(fn (SeasonRace $r) => [
            'season'   => (string) $r->year,
            'round'    => (string) $r->round,
            'raceName' => $r->race_name,
            'date'     => $r->date->format('Y-m-d'),
            'Circuit'  => [
                'circuitId'   => $r->circuit_id,
                'circuitName' => $r->circuit_name,
                'Location'    => [
                    'locality' => $r->circuit_locality,
                    'country'  => $r->circuit_country,
                ],
            ],
            'Results' => $r->results,
        ])->values());
    }
}
