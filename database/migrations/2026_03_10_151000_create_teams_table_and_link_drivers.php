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
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->longText('logo_base64')->nullable();
            $table->string('logo_mime_type')->nullable();
            $table->timestamps();
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('team')->constrained('teams')->nullOnDelete();
            $table->dropColumn(['team_logo_base64', 'team_logo_mime_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->longText('team_logo_base64')->nullable()->after('team_id');
            $table->string('team_logo_mime_type')->nullable()->after('team_logo_base64');
            $table->dropConstrainedForeignId('team_id');
        });

        Schema::dropIfExists('teams');
    }
};