<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->integer('id_driver')->autoIncrement();
            $table->string('name');
            $table->string('surname');
            $table->date('birth_date');
            $table->string('nationality');
            $table->string('team');
            $table->integer('win');
            $table->integer('pole');
            $table->integer('podium');
            $table->integer('year_arrival');
            $table->integer('driver_number');
            $table->integer('fastest_laps');
            $table->float('career_points');
            $table->integer('number_of_races');
            $table->integer('world_championship');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
