<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandingController extends Controller
{
    /**
     * Obtener configuración de branding actual
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        return response()->json([
            'branding' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'logo' => $tenant->logo,
                'favicon' => $tenant->favicon,
                'cover_image' => $tenant->cover_image,
                'primary_color' => $tenant->primary_color,
                'secondary_color' => $tenant->secondary_color,
                'font_family' => $tenant->font_family,
                'description' => $tenant->description,
                'phone' => $tenant->phone,
                'email' => $tenant->email,
                'address' => $tenant->address,
            ],
            'can_customize' => in_array($tenant->plan, ['pro', 'business']),
        ]);
    }

    /**
     * Actualizar branding (logo, colores, favicon, cover)
     */
    public function update(Request $request): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        // Verificar permisos
        $role = $request->user()->roleInTenant($tenant);
        if (!in_array($role, ['owner', 'admin']) && !$request->user()->isSuperAdmin()) {
            return response()->json(['error' => 'No tienes permisos para editar el branding'], 403);
        }

        // Solo pro y business pueden personalizar branding completo
        if (!in_array($tenant->plan, ['pro', 'business'])) {
            // Free puede cambiar solo nombre, slug, descripción, teléfono y email
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'slug' => 'sometimes|string|max:100|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|unique:tenants,slug,' . $tenant->id,
                'description' => 'sometimes|nullable|string|max:1000',
                'phone' => 'sometimes|nullable|string|max:30',
                'email' => 'sometimes|nullable|email|max:255',
                'address' => 'sometimes|nullable|string|max:255',
            ]);

            $tenant->update($validated);

            return response()->json([
                'message' => 'Información actualizada',
                'tenant' => $tenant->fresh(),
                'upgrade_required' => true,
            ]);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:100|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|unique:tenants,slug,' . $tenant->id,
            'primary_color' => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_color' => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'font_family' => 'sometimes|nullable|string|max:100',
            'description' => 'sometimes|nullable|string|max:1000',
            'phone' => 'sometimes|nullable|string|max:30',
            'email' => 'sometimes|nullable|email|max:255',
            'address' => 'sometimes|nullable|string|max:255',
        ]);

        $tenant->update($validated);

        return response()->json([
            'message' => 'Branding actualizado correctamente',
            'tenant' => $tenant->fresh(),
        ]);
    }

    /**
     * Subir logo
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        if (!in_array($tenant->plan, ['pro', 'business'])) {
            return response()->json(['error' => 'Necesitas plan Pro o Business para personalizar el logo'], 403);
        }

        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,svg,webp|max:2048',
        ]);

        // Eliminar logo anterior si existe
        if ($tenant->logo) {
            Storage::disk('public')->delete($tenant->logo);
        }

        $path = $request->file('logo')->store("tenants/{$tenant->id}/branding", 'public');
        $tenant->update(['logo' => $path]);

        return response()->json([
            'message' => 'Logo actualizado',
            'logo' => $path,
        ]);
    }

    /**
     * Subir favicon
     */
    public function uploadFavicon(Request $request): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        if ($tenant->plan !== 'business') {
            return response()->json(['error' => 'Necesitas plan Business para personalizar el favicon'], 403);
        }

        $request->validate([
            'favicon' => 'required|image|mimes:ico,png,svg|max:512',
        ]);

        if ($tenant->favicon) {
            Storage::disk('public')->delete($tenant->favicon);
        }

        $path = $request->file('favicon')->store("tenants/{$tenant->id}/branding", 'public');
        $tenant->update(['favicon' => $path]);

        return response()->json([
            'message' => 'Favicon actualizado',
            'favicon' => $path,
        ]);
    }

    /**
     * Subir imagen de portada
     */
    public function uploadCover(Request $request): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        if (!in_array($tenant->plan, ['pro', 'business'])) {
            return response()->json(['error' => 'Necesitas plan Pro o Business'], 403);
        }

        $request->validate([
            'cover_image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        if ($tenant->cover_image) {
            Storage::disk('public')->delete($tenant->cover_image);
        }

        $path = $request->file('cover_image')->store("tenants/{$tenant->id}/branding", 'public');
        $tenant->update(['cover_image' => $path]);

        return response()->json([
            'message' => 'Imagen de portada actualizada',
            'cover_image' => $path,
        ]);
    }

    /**
     * Eliminar logo/favicon/cover
     */
    public function removeAsset(Request $request, string $type): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        if (!in_array($type, ['logo', 'favicon', 'cover_image'])) {
            return response()->json(['error' => 'Tipo de asset no válido'], 422);
        }

        if ($tenant->$type) {
            Storage::disk('public')->delete($tenant->$type);
            $tenant->update([$type => null]);
        }

        return response()->json(['message' => ucfirst($type) . ' eliminado']);
    }
}
