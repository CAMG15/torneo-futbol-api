<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE canchas MODIFY COLUMN tipo ENUM('futbol5','futbol7','futbol9','futbol11','futsal','otro') NOT NULL DEFAULT 'futbol11'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE canchas MODIFY COLUMN tipo ENUM('futbol5','futbol7','futbol11','futsal','otro') NOT NULL DEFAULT 'futbol11'");
    }
};
