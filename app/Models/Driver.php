<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    protected $connection = 'f1dle';
    protected $table = 'drivers';
    protected $primaryKey = "id_driver";

}
