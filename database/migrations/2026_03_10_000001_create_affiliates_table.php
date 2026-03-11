<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code', 10)->unique();
            $table->enum('status', ['pending', 'active', 'suspended', 'rejected'])->default('pending');
            $table->decimal('commission_rate', 5, 2)->default(0.10);
            $table->unsignedBigInteger('total_earned_cents')->default(0);
            $table->unsignedBigInteger('pending_payout_cents')->default(0);
            $table->unsignedBigInteger('total_paid_cents')->default(0);
            $table->json('payment_info')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
