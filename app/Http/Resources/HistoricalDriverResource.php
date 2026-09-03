<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HistoricalDriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'driverId'        => $this->driver_id,
            'givenName'       => $this->given_name,
            'familyName'      => $this->family_name,
            'dateOfBirth'     => $this->date_of_birth?->toDateString(),
            'nationality'     => $this->nationality,
            'permanentNumber' => $this->permanent_number,
            'code'            => $this->code,
            'totalWins'       => $this->total_wins,
            'totalPoints'     => $this->total_points,
            'championships'   => $this->championships,
            'seasonsActive'   => $this->seasons_active,
            'firstSeason'     => $this->first_season,
            'lastSeason'      => $this->last_season,
            'lastTeam'        => $this->last_team,
            'teamsHistory'    => $this->teams_history ?? [],
        ];
    }
}
