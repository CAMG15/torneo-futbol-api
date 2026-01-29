<?php
// database/migrations/2024_01_03_create_schedule_constraints_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('schedule_constraints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->nullable()->constrained(); // null = aplica a todos
            $table->enum('constraint_type', [
                'DiaNoDisponible',
                'HorarioNoDisponible',
                'UbicacionRequerida',
                'DescansoMinimo',
                'MaxPartidosPorDia'
            ]);
            $table->json('constraint_data'); // Datos específicos de la restricción
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('blackout_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->date('blackout_date');
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('schedule_constraints');
        Schema::dropIfExists('blackout_dates');
    }
};
