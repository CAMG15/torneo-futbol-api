<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('domain')->unique();
            $table->enum('status', ['pending', 'verified', 'failed'])->default('pending');
            $table->string('verification_token');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_check_at')->nullable();
            $table->timestamps();

            $table->index('domain');
            $table->index(['tenant_id', 'status']);
        });

        // Agregar campos de branding extendidos al tenant
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('favicon')->nullable()->after('logo');
            $table->string('cover_image')->nullable()->after('favicon');
            $table->string('font_family')->nullable()->after('secondary_color');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['favicon', 'cover_image', 'font_family']);
        });

        Schema::dropIfExists('tenant_domains');
    }
};
