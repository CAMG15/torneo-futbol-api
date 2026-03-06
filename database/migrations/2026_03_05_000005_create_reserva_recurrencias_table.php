<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_recurrencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('cancha_id')->constrained('canchas')->onDelete('cascade');
            $table->unsignedTinyInteger('dia_semana'); // 0=domingo, 1=lunes, ..., 6=sábado
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->enum('tipo_evento', [
                'partido_amistoso', 'entrenamiento', 'evento_privado',
                'cumpleanos', 'mantenimiento', 'otro'
            ])->default('entrenamiento');
            $table->string('nombre_cliente')->nullable();
            $table->string('telefono')->nullable();
            $table->string('nombre_equipo')->nullable();
            $table->decimal('monto_total', 10, 2)->nullable();
            $table->enum('metodo_pago', [
                'efectivo', 'transferencia', 'mercadopago', 'otro'
            ])->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'activa']);
        });

        // Agregar FK de reservas → reserva_recurrencias (tabla ya existe en este punto)
        Schema::table('reservas', function (Blueprint $table) {
            $table->foreign('recurrencia_id')
                ->references('id')
                ->on('reserva_recurrencias')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropForeign(['recurrencia_id']);
        });
        Schema::dropIfExists('reserva_recurrencias');
    }
};
