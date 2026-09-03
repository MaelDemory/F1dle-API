<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Driver extends Model
{
    use HasFactory;

    protected $connection = 'f1dle';
    protected $table = 'drivers';
    protected $primaryKey = "id_driver";
    protected $appends = ['team_logo_base64', 'team_logo_mime_type'];
    protected $hidden = ['teamRecord'];
    protected $fillable = [
        'name', 'surname', 'birth_date', 'nationality', 'team', 'team_id',
        'win', 'pole', 'podium', 'first_entry', 'driver_number',
        'fastest_laps', 'career_points', 'entries', 'world_championship',
    ];

    public function teamRecord(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function getTeamLogoBase64Attribute(): ?string
    {
        return $this->teamRecord?->logo_base64;
    }

    public function getTeamLogoMimeTypeAttribute(): ?string
    {
        return $this->teamRecord?->logo_mime_type;
    }

}
