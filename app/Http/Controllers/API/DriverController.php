<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\HistoricalDriverResource;
use App\Models\Driver;
use App\Models\HistoricalDriver;
use App\Models\Team;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $drivers = Driver::with('teamRecord')->get();

        return response()->json($drivers);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $driver = Driver::with('teamRecord')->findOrFail($id);

        return response()->json($driver);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function getRandomDriver(): \Illuminate\Http\JsonResponse
    {
        $drivers = Driver::with('teamRecord')->get();
        $driver = $drivers->random();

        return response()->json($driver);
    }

    public function teams()
    {
        return response()->json(
            Team::select('name', 'logo_base64', 'logo_mime_type')->get()
        );
    }

    public function historicalIndex()
    {
        $drivers = HistoricalDriver::orderByDesc('total_wins')
            ->orderByDesc('total_points')
            ->get();

        return HistoricalDriverResource::collection($drivers);
    }

    public function randomHistoricalWinner(): \Illuminate\Http\JsonResponse
    {
        $driver = HistoricalDriver::where('total_wins', '>=', 1)
            ->inRandomOrder()
            ->firstOrFail();

        return response()->json(new HistoricalDriverResource($driver));
    }

    /**
     * Parameters
     *   None.
     * What it does
     *   Picks one driver at random from the whole historical roster, with no
     *   performance threshold. Unlike randomHistoricalWinner(), which restricts
     *   the pool to race winners, this covers every driver since 1950 — including
     *   one-race entrants — because the All Time guessing mode plays on the full
     *   roster.
     * Output
     *   JSON HistoricalDriverResource, or 404 when the table is empty.
     */
    public function randomHistoricalDriver(): \Illuminate\Http\JsonResponse
    {
        $driver = HistoricalDriver::inRandomOrder()->firstOrFail();

        return response()->json(new HistoricalDriverResource($driver));
    }
}
