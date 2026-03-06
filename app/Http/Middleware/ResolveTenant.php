<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = null;

        // 1. Ruta pública por slug: /api/t/{tenant_slug}/public/...
        if ($slug = $request->route('tenant_slug')) {
            $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();
            if (!$tenant) {
                return response()->json(['error' => 'Cancha no encontrada'], 404);
            }
        }

        // 2. Header X-Tenant-Id (admin panel autenticado)
        if (!$tenant && $request->hasHeader('X-Tenant-Id')) {
            $tenantId = (int) $request->header('X-Tenant-Id');
            $tenant = Tenant::where('id', $tenantId)->where('is_active', true)->first();

            // Verificar que el usuario pertenece a este tenant
            if ($tenant && $request->user()) {
                $belongs = $request->user()->tenants()
                    ->where('tenant_id', $tenant->id)
                    ->exists();

                if (!$belongs && !$request->user()->is_superadmin) {
                    return response()->json([
                        'error' => 'No tienes acceso a esta cancha'
                    ], 403);
                }
            }
        }

        // 3. Tenant por defecto del usuario autenticado
        if (!$tenant && $request->user() && $request->user()->current_tenant_id) {
            $tenant = Tenant::where('id', $request->user()->current_tenant_id)
                ->where('is_active', true)
                ->first();
        }

        // 4. Custom domain
        if (!$tenant) {
            $host = $request->getHost();
            $excludedHosts = ['micopa.live', 'api.micopa.live', 'admin.micopa.live', 'localhost', '127.0.0.1'];

            if (!in_array($host, $excludedHosts)) {
                $tenant = Tenant::where('custom_domain', $host)
                    ->where('is_active', true)
                    ->first();
            }
        }

        if ($tenant) {
            app()->instance('current_tenant', $tenant);
            app()->instance('current_tenant_id', $tenant->id);
        }

        // Remove tenant_slug from route parameters so controllers don't receive it
        if ($request->route('tenant_slug')) {
            $request->route()->forgetParameter('tenant_slug');
        }

        return $next($request);
    }
}
