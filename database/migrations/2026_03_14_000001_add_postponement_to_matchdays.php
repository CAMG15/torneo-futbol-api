<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matchdays', function (Blueprint $table) {
            $table->date('postponed_from')->nullable()->after('end_date');
            $table->string('postponement_reason')->nullable()->after('postponed_from');
        });
    }

    public function down(): void
    {
        Schema::table('matchdays', function (Blueprint $table) {
            $table->dropColumn(['postponed_from', 'postponement_reason']);
        });
    }
};
