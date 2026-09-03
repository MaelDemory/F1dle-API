<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeasonRace extends Model
{
    protected $fillable = [
        'year',
        'round',
        'race_name',
        'circuit_id',
        'circuit_name',
        'circuit_locality',
        'circuit_country',
        'date',
        'results',
    ];

    protected $casts = [
        'results' => 'array',
        'date'    => 'date',
    ];
}
