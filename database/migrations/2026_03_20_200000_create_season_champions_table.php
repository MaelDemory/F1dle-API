<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_champions', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->string('driver_id', 100);
            $table->string('given_name', 100);
            $table->string('family_name', 100);
            $table->string('nationality', 80);
            $table->string('constructor', 100);
            $table->unsignedInteger('wins')->default(0);
            $table->float('points')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_champions');
    }
};
