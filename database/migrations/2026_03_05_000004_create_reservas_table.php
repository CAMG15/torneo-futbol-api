<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('cancha_id')->constrained('canchas')->onDelete('cascade');
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->enum('tipo_evento', [
                'partido_amistoso', 'entrenamiento', 'evento_privado',
                'cumpleanos', 'mantenimiento', 'otro'
            ])->default('partido_amistoso');
            $table->enum('estado', [
                'pendiente', 'confirmada', 'cancelada', 'completada', 'no_show'
            ])->default('pendiente');
            $table->string('nombre_cliente')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->string('nombre_equipo')->nullable();
            $table->unsignedInteger('cantidad_jugadores')->nullable();
            $table->decimal('monto_total', 10, 2)->nullable();
            $table->decimal('monto_sena', 10, 2)->nullable();
            $table->enum('estado_pago', [
                'sin_pagar', 'sena_pagada', 'pagado_total'
            ])->default('sin_pagar');
            $table->enum('metodo_pago', [
                'efectivo', 'transferencia', 'mercadopago', 'otro'
            ])->nullable();
            $table->text('notas_cliente')->nullable();
            $table->text('notas_internas')->nullable();
            $table->unsignedBigInteger('recurrencia_id')->nullable(); // FK agregada en migración 000005
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'fecha']);
            $table->index(['cancha_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas');
    }
};
