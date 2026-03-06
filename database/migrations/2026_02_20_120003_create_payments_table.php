<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('amount_cents');          // Monto en centavos
            $table->string('currency', 3)->default('MXN');
            $table->enum('payment_provider', ['mercadopago', 'paypal']);
            $table->string('provider_payment_id')->nullable(); // ID del pago en el proveedor
            $table->enum('status', ['pending', 'approved', 'rejected', 'refunded', 'cancelled'])->default('pending');
            $table->string('payment_method')->nullable(); // credit_card, debit_card, paypal, etc.
            $table->text('description')->nullable();
            $table->json('provider_data')->nullable();    // Respuesta completa del proveedor
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('provider_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
