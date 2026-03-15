<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar cancha_id a matches
        Schema::table('matches', function (Blueprint $table) {
            $table->foreignId('cancha_id')->nullable()->after('location')
                ->constrained('canchas')->nullOnDelete();
        });

        // Agregar cancha_id a matchday_schedule_slots
        Schema::table('matchday_schedule_slots', function (Blueprint $table) {
            $table->foreignId('cancha_id')->nullable()->after('location')
                ->constrained('canchas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropForeign(['cancha_id']);
            $table->dropColumn('cancha_id');
        });

        Schema::table('matchday_schedule_slots', function (Blueprint $table) {
            $table->dropForeign(['cancha_id']);
            $table->dropColumn('cancha_id');
        });
    }
};
