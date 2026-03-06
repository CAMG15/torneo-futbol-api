<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->text('reason')->nullable();
            $table->timestamp('withdrawn_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_withdrawals');
    }
};
