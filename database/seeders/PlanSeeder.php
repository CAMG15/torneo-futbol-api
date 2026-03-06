<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'price_cents' => 0,
                'currency' => 'MXN',
                'billing_period' => 'monthly',
                'description' => 'Ideal para probar la plataforma con un torneo.',
                'sort_order' => 1,
                'features' => [
                    'public_site' => true,
                    'export' => false,
                    'custom_branding' => false,
                    'custom_domain' => false,
                    'sponsors' => false,
                    'advanced_stats' => false,
                    'auto_fixture' => true,
                    'micopa_branding' => true,
                ],
                'limits' => [
                    'max_tournaments' => 1,
                    'max_teams_per_tournament' => 20,
                ],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'price_cents' => 69900,
                'currency' => 'MXN',
                'billing_period' => 'monthly',
                'description' => 'Para canchas que quieren su sitio público y torneos ilimitados.',
                'sort_order' => 2,
                'features' => [
                    'public_site' => true,
                    'export' => true,
                    'custom_branding' => true,
                    'custom_domain' => false,
                    'sponsors' => false,
                    'advanced_stats' => false,
                    'auto_fixture' => true,
                    'micopa_branding' => true,
                ],
                'limits' => [
                    'max_tournaments' => -1, // ilimitados
                    'max_teams_per_tournament' => 50,
                ],
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'price_cents' => 149900,
                'currency' => 'MXN',
                'billing_period' => 'monthly',
                'description' => 'Todo incluido: dominio propio, sin branding, patrocinadores y estadísticas avanzadas.',
                'sort_order' => 3,
                'features' => [
                    'public_site' => true,
                    'export' => true,
                    'custom_branding' => true,
                    'custom_domain' => true,
                    'sponsors' => true,
                    'advanced_stats' => true,
                    'auto_fixture' => true,
                    'micopa_branding' => false,
                ],
                'limits' => [
                    'max_tournaments' => -1,
                    'max_teams_per_tournament' => -1, // ilimitados
                ],
            ],
        ];

        foreach ($plans as $planData) {
            Plan::updateOrCreate(
                ['slug' => $planData['slug']],
                $planData
            );
        }
    }
}
