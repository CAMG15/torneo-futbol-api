<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canchas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('nombre');
            $table->enum('tipo', ['futbol5', 'futbol7', 'futbol11', 'futsal', 'otro'])->default('futbol11');
            $table->text('descripcion')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canchas');
    }
};
