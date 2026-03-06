<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $plan = DB::table('plans')->where('slug', 'free')->first();

        if ($plan) {
            $features = json_decode($plan->features, true);
            $features['public_site'] = true;
            DB::table('plans')
                ->where('slug', 'free')
                ->update(['features' => json_encode($features)]);
        }
    }

    public function down(): void
    {
        $plan = DB::table('plans')->where('slug', 'free')->first();

        if ($plan) {
            $features = json_decode($plan->features, true);
            $features['public_site'] = false;
            DB::table('plans')
                ->where('slug', 'free')
                ->update(['features' => json_encode($features)]);
        }
    }
};
