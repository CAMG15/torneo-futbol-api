<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_slot_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('tournament_id')->constrained('tournaments')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->time('preferred_time');
            $table->tinyInteger('preferred_day_of_week')->nullable()->comment('0=Dom,1=Lun..6=Sab — solo para multi_day');
            $table->foreignId('cancha_id')->nullable()->constrained('canchas')->nullOnDelete();
            $table->decimal('monto', 10, 2)->nullable();
            $table->enum('estado_pago', ['pendiente', 'pagado', 'vencido'])->default('pendiente');
            $table->string('metodo_pago', 100)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
            // Un equipo sólo puede tener un horario fijo por torneo
            $table->unique(['tournament_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_slot_reservations');
    }
};
