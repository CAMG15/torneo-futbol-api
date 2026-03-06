<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // Free, Pro, Business
            $table->string('slug')->unique(); // free, pro, business
            $table->integer('price_cents');    // 0, 69900, 149900
            $table->string('currency', 3)->default('MXN');
            $table->enum('billing_period', ['monthly', 'yearly'])->default('monthly');
            $table->json('features');         // {"public_site": true, "export": true, ...}
            $table->json('limits');           // {"max_tournaments": 1, ...}
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
