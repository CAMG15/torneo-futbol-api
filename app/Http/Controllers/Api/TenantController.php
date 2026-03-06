<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    /**
     * Info del tenant actual (para admin panel)
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        $tenant->load(['owner:id,name,email']);

        return response()->json([
            'tenant' => $tenant,
            'plan_features' => [
                'max_tournaments' => $tenant->isFreePlan() ? 1 : null,
                'public_site' => $tenant->hasPublicSite(),
                'custom_domain' => $tenant->hasCustomDomain(),
                'export' => $tenant->canExport(),
                'advanced_stats' => $tenant->hasAdvancedStats(),
                'sponsors' => $tenant->canShowSponsors(),
                'show_micopa_branding' => $tenant->showMiCopaBranding(),
            ],
            'usage' => [
                'tournaments_count' => $tenant->tournaments()->count(),
                'teams_count' => $tenant->teams()->count(),
                'players_count' => $tenant->players()->count(),
            ],
        ]);
    }

    /**
     * Actualizar tenant (branding, info)
     */
    public function update(Request $request): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        // Solo owner y admin pueden editar
        $role = $request->user()->roleInTenant($tenant);
        if (!in_array($role, ['owner', 'admin']) && !$request->user()->isSuperAdmin()) {
            return response()->json(['error' => 'No tienes permisos para editar'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'logo' => 'sometimes|nullable|string|max:255',
            'favicon' => 'sometimes|nullable|string|max:255',
            'cover_image' => 'sometimes|nullable|string|max:255',
            'primary_color' => 'sometimes|string|max:7',
            'secondary_color' => 'sometimes|string|max:7',
            'font_family' => 'sometimes|nullable|string|max:100',
            'address' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
            'email' => 'sometimes|nullable|email|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
        ]);

        $tenant->update($validated);

        return response()->json([
            'message' => 'Cancha actualizada correctamente',
            'tenant' => $tenant->fresh(),
        ]);
    }

    /**
     * Info pública del tenant (para sitio público)
     */
    public function publicInfo(Request $request): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant) {
            return response()->json(['error' => 'Cancha no encontrada'], 404);
        }

        // Verificar si tiene sitio público habilitado
        if (!$tenant->hasPublicSite()) {
            return response()->json(['error' => 'Sitio público no disponible'], 403);
        }

        return response()->json([
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'logo' => $tenant->logo,
            'favicon' => $tenant->favicon,
            'cover_image' => $tenant->cover_image,
            'primary_color' => $tenant->primary_color,
            'secondary_color' => $tenant->secondary_color,
            'font_family' => $tenant->font_family,
            'city' => $tenant->city,
            'phone' => $tenant->phone,
            'email' => $tenant->email,
            'description' => $tenant->description,
            'plan' => $tenant->plan,
            'custom_domain' => $tenant->custom_domain,
            'show_micopa_branding' => $tenant->showMiCopaBranding(),
            'features' => [
                'public_site' => $tenant->hasPublicSite(),
                'sponsors' => $tenant->canShowSponsors(),
                'advanced_stats' => $tenant->hasAdvancedStats(),
                'micopa_branding' => $tenant->showMiCopaBranding(),
                'custom_branding' => in_array($tenant->plan, ['pro', 'business']),
            ],
        ]);
    }

    /**
     * Lista pública de canchas registradas (para landing global)
     * Solo muestra tenants activos con plan pro o business
     */
    public function publicList(): JsonResponse
    {
        $tenants = Tenant::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'logo', 'city', 'description', 'plan']);

        return response()->json($tenants);
    }

    /**
     * Cambiar tenant activo del usuario
     */
    public function switchTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $user = $request->user();

        if (!$user->belongsToTenant($tenant->id) && !$user->isSuperAdmin()) {
            return response()->json(['error' => 'No tienes acceso a esta cancha'], 403);
        }

        $user->switchTenant($tenant->id);

        return response()->json([
            'message' => 'Cancha cambiada correctamente',
            'tenant' => $tenant,
        ]);
    }
}
