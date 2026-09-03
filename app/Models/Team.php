<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'logo_base64',
        'logo_mime_type',
    ];

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }
}