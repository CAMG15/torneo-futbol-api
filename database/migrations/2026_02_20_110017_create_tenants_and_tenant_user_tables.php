<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_id')->constrained('users');
            $table->enum('plan', ['free', 'pro', 'business'])->default('free');
            $table->string('logo')->nullable();
            $table->string('primary_color', 7)->default('#1E40AF');
            $table->string('secondary_color', 7)->default('#10B981');
            $table->string('custom_domain')->nullable()->unique();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index('slug');
            $table->index('is_active');
        });

        Schema::create('tenant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('role', ['owner', 'admin', 'editor'])->default('admin');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });

        // Agregar campos de multi-tenancy a users
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_superadmin')->default(false)->after('password');
            $table->foreignId('current_tenant_id')->nullable()->after('is_superadmin')
                ->constrained('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_tenant_id']);
            $table->dropColumn(['is_superadmin', 'current_tenant_id']);
        });

        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
    }
};
