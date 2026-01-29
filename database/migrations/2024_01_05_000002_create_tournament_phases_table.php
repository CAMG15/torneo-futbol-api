<?php
// database/migrations/2024_01_05_000002_create_tournament_phases_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Fases del torneo (regular, liguilla, copa)
        Schema::create('tournament_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->enum('phase_type', ['regular', 'liguilla', 'copa', 'repechaje']);
            $table->string('name');
            $table->integer('phase_order')->default(0);
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->json('config')->nullable(); // Configuración de liguilla
            $table->timestamps();
        });

        // Brackets de eliminación
        Schema::create('playoff_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_phase_id')->constrained()->onDelete('cascade');
            $table->integer('round_number'); // 1=Cuartos, 2=Semis, 3=Final
            $table->string('round_name'); // "Cuartos de Final", "Semifinal", "Final"
            $table->integer('bracket_position'); // Posición en el bracket (1, 2, 3, 4...)
            $table->foreignId('home_team_id')->nullable()->constrained('teams')->onDelete('set null');
            $table->foreignId('away_team_id')->nullable()->constrained('teams')->onDelete('set null');
            $table->integer('home_seed')->nullable(); // Posición en tabla regular
            $table->integer('away_seed')->nullable();
            $table->foreignId('winner_team_id')->nullable()->constrained('teams')->onDelete('set null');
            $table->unsignedBigInteger('next_bracket_id')->nullable(); // Siguiente ronda
            $table->enum('next_bracket_position', ['home', 'away'])->nullable();
            $table->integer('home_aggregate_score')->default(0);
            $table->integer('away_aggregate_score')->default(0);
            $table->boolean('is_two_legs')->default(true);
            $table->timestamps();

            $table->foreign('next_bracket_id')->references('id')->on('playoff_brackets')->onDelete('set null');
        });

        // Agregar columna para vincular partidos a brackets
        Schema::table('matches', function (Blueprint $table) {
            $table->foreignId('playoff_bracket_id')->nullable()->after('status')
                ->constrained()->onDelete('set null');
            $table->tinyInteger('leg_number')->nullable()->after('playoff_bracket_id'); // 1=Ida, 2=Vuelta
        });

        // Agregar columna para vincular matchdays a fases
        Schema::table('matchdays', function (Blueprint $table) {
            $table->foreignId('tournament_phase_id')->nullable()->after('phase_type')
                ->constrained()->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('matchdays', function (Blueprint $table) {
            $table->dropForeign(['tournament_phase_id']);
            $table->dropColumn('tournament_phase_id');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->dropForeign(['playoff_bracket_id']);
            $table->dropColumn(['playoff_bracket_id', 'leg_number']);
        });

        Schema::dropIfExists('playoff_brackets');
        Schema::dropIfExists('tournament_phases');
    }
};
