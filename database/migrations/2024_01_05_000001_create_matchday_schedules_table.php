<?php
// database/migrations/2024_01_05_000001_create_matchday_schedules_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Tabla para guardar configuraciones de horarios reutilizables
        Schema::create('matchday_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->enum('schedule_type', ['single_day', 'multi_day']);
            $table->string('name')->nullable();
            $table->timestamps();
        });

        // Slots de horarios dentro de un schedule
        Schema::create('matchday_schedule_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_schedule_id')->constrained()->onDelete('cascade');
            $table->tinyInteger('day_of_week')->nullable(); // 0=Dom, 1=Lun...6=Sab (para multi_day)
            $table->time('start_time');
            $table->string('location')->nullable();
            $table->integer('max_matches')->default(1);
            $table->integer('slot_order')->default(0);
            $table->timestamps();
        });

        // Agregar columnas a matchdays
        Schema::table('matchdays', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('date');
            $table->foreignId('matchday_schedule_id')->nullable()->after('end_date')
                ->constrained()->onDelete('set null');
            $table->enum('phase_type', ['regular', 'playoff'])->default('regular')->after('matchday_schedule_id');
        });
    }

    public function down()
    {
        Schema::table('matchdays', function (Blueprint $table) {
            $table->dropForeign(['matchday_schedule_id']);
            $table->dropColumn(['end_date', 'matchday_schedule_id', 'phase_type']);
        });

        Schema::dropIfExists('matchday_schedule_slots');
        Schema::dropIfExists('matchday_schedules');
    }
};
