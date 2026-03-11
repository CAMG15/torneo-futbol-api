<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->nullable()->constrained('teams')->onDelete('set null');
            $table->foreignId('tournament_id')->nullable()->constrained('tournaments')->onDelete('set null');
            $table->enum('concepto', ['inscripcion', 'arbitraje', 'multa', 'otro'])->default('inscripcion');
            $table->string('descripcion')->nullable();
            $table->decimal('monto', 10, 2);
            $table->decimal('monto_pagado', 10, 2)->default(0);
            $table->enum('estado', ['pendiente', 'parcial', 'pagado', 'cancelado'])->default('pendiente');
            $table->enum('metodo_pago', ['efectivo', 'transferencia', 'mercadopago', 'otro'])->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->timestamp('pagado_at')->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'estado']);
            $table->index(['tenant_id', 'team_id']);
            $table->index(['tenant_id', 'tournament_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_payments');
    }
};
