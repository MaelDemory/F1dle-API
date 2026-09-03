<?php

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TeamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $logoDirectory = database_path('seeders/team-logos');

        $teams = [
            'Mercedes' => 'mercedes.png',
            'Redbull' => 'redbull.png',
            'Ferrari' => 'ferrari.png',
            'McLaren' => 'mclaren.png',
            'Racing Bulls' => 'racing-bulls.png',
            'Aston Martin' => 'aston-martin.png',
            'Haas' => 'haas.png',
            'Williams' => 'williams.png',
            'Alpine' => 'alpine.png',
            'Stake F1 Team Kick Sauber' => 'sauber.png',
        ];

        foreach ($teams as $teamName => $fileName) {
            $path = $logoDirectory . DIRECTORY_SEPARATOR . $fileName;
            $logoBase64 = null;
            $logoMimeType = null;

            if (is_file($path)) {
                $mimeType = mime_content_type($path);
                $contents = file_get_contents($path);

                if ($mimeType !== false && $contents !== false) {
                    $logoBase64 = base64_encode($contents);
                    $logoMimeType = $mimeType;
                }
            }

            $team = Team::updateOrCreate(
                ['name' => $teamName],
                [
                    'logo_base64' => $logoBase64,
                    'logo_mime_type' => $logoMimeType,
                ]
            );

            DB::table('drivers')
                ->where('team', $teamName)
                ->update(['team_id' => $team->id]);
        }
    }
}