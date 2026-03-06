<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancha_tarifas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cancha_id')->constrained('canchas')->onDelete('cascade');
            $table->string('nombre'); // ej: "Tarifa nocturna fin de semana"
            $table->enum('dia_tipo', ['semana', 'finde', 'todos'])->default('todos');
            $table->time('hora_desde');
            $table->time('hora_hasta');
            $table->decimal('precio_hora', 8, 2);
            $table->string('moneda', 3)->default('MXN');
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index('cancha_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancha_tarifas');
    }
};
