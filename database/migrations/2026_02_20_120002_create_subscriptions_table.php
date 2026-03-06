<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('plan_id')->constrained()->onDelete('restrict');
            $table->enum('status', ['active', 'cancelled', 'past_due', 'trialing', 'paused'])->default('active');
            $table->enum('payment_provider', ['mercadopago', 'paypal'])->nullable();
            $table->string('provider_subscription_id')->nullable(); // ID de suscripción en el proveedor
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('metadata')->nullable(); // Datos extra del proveedor
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('provider_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
