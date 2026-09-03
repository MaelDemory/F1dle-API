<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_races', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('round');
            $table->string('race_name', 150);
            $table->string('circuit_id', 100);
            $table->string('circuit_name', 150);
            $table->string('circuit_locality', 100)->nullable();
            $table->string('circuit_country', 100)->nullable();
            $table->date('date');
            $table->json('results');
            $table->timestamps();
            $table->unique(['year', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_races');
    }
};
