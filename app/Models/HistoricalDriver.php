<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoricalDriver extends Model
{
    protected $connection = 'f1dle';
    protected $table = 'historical_drivers';

    protected $fillable = [
        'driver_id',
        'given_name',
        'family_name',
        'date_of_birth',
        'nationality',
        'permanent_number',
        'code',
        'total_wins',
        'total_points',
        'championships',
        'seasons_active',
        'first_season',
        'last_season',
        'last_team',
        'teams_history',
    ];

    protected $casts = [
        'teams_history' => 'array',
        'date_of_birth' => 'date',
    ];
}
