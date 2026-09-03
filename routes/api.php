<?php

use App\Http\Controllers\API\DriverController;
use App\Http\Controllers\API\SeasonChampionController;
use App\Http\Controllers\API\SeasonRaceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('drivers',DriverController::class);


Route::get('random', [DriverController::class, 'getRandomDriver']);

Route::get('historical-drivers', [DriverController::class, 'historicalIndex']);

Route::get('teams', [DriverController::class, 'teams']);

Route::get('random-historical-winner', [DriverController::class, 'randomHistoricalWinner']);

Route::get('random-historical-driver', [DriverController::class, 'randomHistoricalDriver']);

Route::get('season-champions', [SeasonChampionController::class, 'index']);

Route::get('season-races/{year}', [SeasonRaceController::class, 'show']);
