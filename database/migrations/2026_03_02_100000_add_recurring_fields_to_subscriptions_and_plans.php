<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('payment_provider');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('paypal_plan_id')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('auto_renew');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('paypal_plan_id');
        });
    }
};
