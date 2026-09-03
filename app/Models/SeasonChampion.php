<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeasonChampion extends Model
{
    protected $connection = 'f1dle';
    protected $table = 'season_champions';

    protected $fillable = [
        'year',
        'driver_id',
        'given_name',
        'family_name',
        'nationality',
        'constructor',
        'wins',
        'points',
    ];
}
