<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_drivers', function (Blueprint $table) {
            $table->id();
            $table->string('driver_id', 100)->unique();
            $table->string('given_name', 100);
            $table->string('family_name', 100);
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 80);
            $table->string('permanent_number', 10)->nullable();
            $table->string('code', 5)->nullable();
            $table->unsignedInteger('total_wins')->default(0);
            $table->float('total_points')->default(0);
            $table->unsignedInteger('championships')->default(0);
            $table->unsignedInteger('seasons_active')->default(0);
            $table->unsignedSmallInteger('first_season')->default(0);
            $table->unsignedSmallInteger('last_season')->default(0);
            $table->string('last_team', 100)->nullable();
            $table->json('teams_history')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_drivers');
    }
};
