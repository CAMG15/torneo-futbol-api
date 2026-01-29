<?php
// database/migrations/2024_01_01_000001_create_players_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('lastname');
            $table->string('nickname')->nullable();
            $table->date('birthdate');
            $table->string('photo')->nullable();
            $table->enum('position', ['Portero', 'Defensa', 'Medio', 'Delantero']);
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->integer('goals')->default(0);
            $table->integer('assists')->default(0);
            $table->integer('yellow_cards')->default(0);
            $table->integer('red_cards')->default(0);
            $table->integer('matches_played')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('logo')->nullable();
            $table->string('primary_color')->default('#10B981');
            $table->string('secondary_color')->default('#1E40AF');
            $table->foreignId('captain_id')->nullable()->constrained('players');
            $table->integer('matches_played')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('draws')->default(0);
            $table->integer('losses')->default(0);
            $table->integer('goals_for')->default(0);
            $table->integer('goals_against')->default(0);
            $table->integer('points')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('player_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->integer('jersey_number')->nullable();
            $table->date('joined_at');
            $table->date('left_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['Futbol 7', 'Futbol 9']);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('status', ['Planificado', 'En Curso', 'Finalizado'])->default('Planificado');
            $table->string('banner')->nullable();
            $table->timestamps();
        });

        Schema::create('matchdays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->integer('number');
            $table->string('name')->nullable();
            $table->date('date');
            $table->timestamps();
        });

        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->onDelete('cascade');
            $table->foreignId('home_team_id')->constrained('teams');
            $table->foreignId('away_team_id')->constrained('teams');
            $table->dateTime('match_date');
            $table->string('location')->nullable();
            $table->integer('home_score')->nullable();
            $table->integer('away_score')->nullable();
            $table->enum('status', ['Programado', 'En Vivo', 'Finalizado', 'Suspendido'])->default('Programado');
            $table->timestamps();
        });

        Schema::create('match_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->onDelete('cascade');
            $table->foreignId('player_id')->constrained();
            $table->foreignId('team_id')->constrained();
            $table->enum('event_type', ['Gol', 'Asistencia', 'Tarjeta Amarilla', 'Tarjeta Roja', 'Sustitución']);
            $table->integer('minute');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('advertisements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('image');
            $table->string('link')->nullable();
            $table->enum('position', ['Header', 'Sidebar', 'Footer', 'Popup']);
            $table->integer('order')->default(0);
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('advertisements');
        Schema::dropIfExists('match_events');
        Schema::dropIfExists('matches');
        Schema::dropIfExists('matchdays');
        Schema::dropIfExists('tournaments');
        Schema::dropIfExists('player_team');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('players');
    }
};