<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tenant_user MODIFY COLUMN role ENUM('owner','admin','editor','arbitro') NOT NULL DEFAULT 'admin'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tenant_user MODIFY COLUMN role ENUM('owner','admin','editor') NOT NULL DEFAULT 'admin'");
    }
};
