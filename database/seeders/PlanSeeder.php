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
                    'online_payments' => false,
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
                    'online_payments' => true,
                    'micopa_branding' => true,
                ],
                'limits' => [
                    'max_tournaments' => -1,
                    'max_teams_per_tournament' => 50,
                ],
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'price_cents' => 100000,
                'currency' => 'MXN',
                'billing_period' => 'monthly',
                'description' => 'Todo incluido: dominio propio, sin branding MiCopa, patrocinadores y estadísticas avanzadas.',
                'sort_order' => 3,
                'features' => [
                    'public_site' => true,
                    'export' => true,
                    'custom_branding' => true,
                    'custom_domain' => true,
                    'sponsors' => true,
                    'advanced_stats' => true,
                    'auto_fixture' => true,
                    'online_payments' => true,
                    'micopa_branding' => false,
                ],
                'limits' => [
                    'max_tournaments' => -1,
                    'max_teams_per_tournament' => -1,
                ],
            ],
            // Planes anuales (20% de descuento)
            [
                'name' => 'Pro Anual',
                'slug' => 'pro_annual',
                'price_cents' => 671000, // $6,710 MXN/año (vs $8,388 mensual × 12)
                'currency' => 'MXN',
                'billing_period' => 'yearly',
                'description' => 'Para canchas que quieren crecer. Ahorra $1,678 al año.',
                'sort_order' => 4,
                'features' => [
                    'public_site' => true,
                    'export' => true,
                    'custom_branding' => true,
                    'custom_domain' => false,
                    'sponsors' => false,
                    'advanced_stats' => false,
                    'auto_fixture' => true,
                    'online_payments' => true,
                    'micopa_branding' => true,
                ],
                'limits' => [
                    'max_tournaments' => -1,
                    'max_teams_per_tournament' => 50,
                ],
            ],
            [
                'name' => 'Business Anual',
                'slug' => 'business_annual',
                'price_cents' => 960000, // $9,600 MXN/año (vs $12,000 mensual × 12, ahorro 20%)
                'currency' => 'MXN',
                'billing_period' => 'yearly',
                'description' => 'Todo incluido: dominio propio, sin branding MiCopa, patrocinadores. Ahorra $2,400 al año.',
                'sort_order' => 5,
                'features' => [
                    'public_site' => true,
                    'export' => true,
                    'custom_branding' => true,
                    'custom_domain' => true,
                    'sponsors' => true,
                    'advanced_stats' => true,
                    'auto_fixture' => true,
                    'online_payments' => true,
                    'micopa_branding' => false,
                ],
                'limits' => [
                    'max_tournaments' => -1,
                    'max_teams_per_tournament' => -1,
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
