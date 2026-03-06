<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPlanLimit
{
    public function handle(Request $request, Closure $next, string $feature = ''): Response
    {
        if (!app()->bound('current_tenant')) {
            return response()->json(['error' => 'Tenant requerido'], 403);
        }

        $tenant = app('current_tenant');

        switch ($feature) {
            case 'create_tournament':
                if (!$tenant->canCreateTournament()) {
                    return response()->json([
                        'error' => 'El plan gratuito permite solo 1 torneo. Actualiza a Pro para crear más.',
                        'upgrade_required' => true,
                        'current_plan' => $tenant->plan,
                    ], 403);
                }
                break;

            case 'public_site':
                if (!$tenant->hasPublicSite()) {
                    return response()->json([
                        'error' => 'El sitio público requiere plan Pro o Business.',
                        'upgrade_required' => true,
                    ], 403);
                }
                break;

            case 'custom_domain':
                if (!$tenant->isBusinessPlan()) {
                    return response()->json([
                        'error' => 'Dominio personalizado requiere plan Business.',
                        'upgrade_required' => true,
                    ], 403);
                }
                break;

            case 'export':
                if (!$tenant->canExport()) {
                    return response()->json([
                        'error' => 'Exportar requiere plan Pro o Business.',
                        'upgrade_required' => true,
                    ], 403);
                }
                break;

            case 'sponsors':
                if (!$tenant->canShowSponsors()) {
                    return response()->json([
                        'error' => 'Patrocinadores requiere plan Business.',
                        'upgrade_required' => true,
                    ], 403);
                }
                break;

            case 'advanced_stats':
                if (!$tenant->hasAdvancedStats()) {
                    return response()->json([
                        'error' => 'Estadísticas avanzadas requiere plan Business.',
                        'upgrade_required' => true,
                    ], 403);
                }
                break;

            case 'create_reserva':
                if (!$tenant->canCreateReserva()) {
                    $count = $tenant->reservasMesActual();
                    return response()->json([
                        'error'            => 'Alcanzaste el límite de 10 reservas del plan gratuito. Actualiza a Pro para reservas ilimitadas.',
                        'upgrade_required' => true,
                        'current_plan'     => $tenant->plan,
                        'usage'            => ['count' => $count, 'limit' => 10],
                    ], 403);
                }
                break;
        }

        return $next($request);
    }
}
