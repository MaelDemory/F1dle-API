<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SeasonChampion;
use Illuminate\Http\JsonResponse;

class SeasonChampionController extends Controller
{
    public function index(): JsonResponse
    {
        $champions = SeasonChampion::orderByDesc('year')->get();

        return response()->json($champions->map(fn (SeasonChampion $c) => [
            'year'        => $c->year,
            'driverId'    => $c->driver_id,
            'givenName'   => $c->given_name,
            'familyName'  => $c->family_name,
            'nationality' => $c->nationality,
            'constructor' => $c->constructor,
            'wins'        => $c->wins,
            'points'      => $c->points,
        ])->values());
    }
}
