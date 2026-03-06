<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['tournaments', 'teams', 'players', 'advertisements', 'matches'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('tenant_id')->nullable()->after('id')
                    ->constrained('tenants')->onDelete('cascade');
                $blueprint->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign(["{$table}_tenant_id_foreign"]);
                $blueprint->dropIndex(["{$table}_tenant_id_index"]);
                $blueprint->dropColumn('tenant_id');
            });
        }
    }
};
