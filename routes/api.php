<?php

use App\Http\Controllers\API\DriverController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('drivers',DriverController::class);


Route::get('random', [DriverController::class, 'getRandomDriver']);
