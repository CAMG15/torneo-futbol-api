<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    /**
     * Listar dominios del tenant
     */
    public function index(): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        return response()->json([
            'domains' => $tenant->domains()->orderBy('created_at', 'desc')->get(),
            'can_add_domain' => $tenant->plan === 'business',
        ]);
    }

    /**
     * Agregar un dominio personalizado
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        if ($tenant->plan !== 'business') {
            return response()->json(['error' => 'Necesitas plan Business para dominios personalizados'], 403);
        }

        // Solo owner puede agregar dominios
        $role = $request->user()->roleInTenant($tenant);
        if ($role !== 'owner' && !$request->user()->isSuperAdmin()) {
            return response()->json(['error' => 'Solo el dueño puede agregar dominios'], 403);
        }

        $validated = $request->validate([
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/',
                'unique:tenant_domains,domain',
            ],
        ], [
            'domain.regex' => 'Ingresa un dominio válido (ej: torneos.micancha.com)',
            'domain.unique' => 'Este dominio ya está registrado',
        ]);

        $domain = TenantDomain::create([
            'tenant_id' => $tenant->id,
            'domain' => strtolower($validated['domain']),
            'verification_token' => TenantDomain::generateVerificationToken(),
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Dominio agregado. Sigue las instrucciones para verificarlo.',
            'domain' => $domain,
            'instructions' => [
                'step_1' => 'Ve a la configuración DNS de tu dominio',
                'step_2' => 'Agrega un registro CNAME apuntando a micopa.live',
                'step_3' => "Agrega un registro TXT con el valor: {$domain->verification_token}",
                'step_4' => 'Espera unos minutos y presiona "Verificar"',
            ],
        ], 201);
    }

    /**
     * Verificar un dominio (revisar DNS)
     */
    public function verify(Request $request, TenantDomain $domain): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant || $domain->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Dominio no encontrado'], 404);
        }

        if ($domain->isVerified()) {
            return response()->json(['message' => 'El dominio ya está verificado', 'domain' => $domain]);
        }

        // Verificar registro TXT
        $txtRecords = dns_get_record($domain->domain, DNS_TXT);
        $verified = false;

        if ($txtRecords) {
            foreach ($txtRecords as $record) {
                if (isset($record['txt']) && $record['txt'] === $domain->verification_token) {
                    $verified = true;
                    break;
                }
            }
        }

        // Verificar CNAME apunta a micopa.live
        $cnameVerified = false;
        $cnameRecords = dns_get_record($domain->domain, DNS_CNAME);
        if ($cnameRecords) {
            foreach ($cnameRecords as $record) {
                if (isset($record['target']) && str_contains($record['target'], 'micopa.live')) {
                    $cnameVerified = true;
                    break;
                }
            }
        }

        if ($verified && $cnameVerified) {
            $domain->markAsVerified();

            // Actualizar custom_domain en el tenant
            $tenant->update(['custom_domain' => $domain->domain]);

            return response()->json([
                'message' => 'Dominio verificado exitosamente',
                'domain' => $domain->fresh(),
                'verified' => true,
            ]);
        }

        $domain->update(['last_check_at' => now()]);

        $errors = [];
        if (!$verified) {
            $errors[] = "No se encontró el registro TXT con el valor: {$domain->verification_token}";
        }
        if (!$cnameVerified) {
            $errors[] = 'No se encontró el registro CNAME apuntando a micopa.live';
        }

        return response()->json([
            'message' => 'La verificación falló',
            'verified' => false,
            'errors' => $errors,
            'domain' => $domain->fresh(),
        ], 422);
    }

    /**
     * Eliminar un dominio
     */
    public function destroy(Request $request, TenantDomain $domain): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant || $domain->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Dominio no encontrado'], 404);
        }

        $role = $request->user()->roleInTenant($tenant);
        if ($role !== 'owner' && !$request->user()->isSuperAdmin()) {
            return response()->json(['error' => 'Solo el dueño puede eliminar dominios'], 403);
        }

        // Si este dominio es el custom_domain activo, limpiarlo
        if ($tenant->custom_domain === $domain->domain) {
            $tenant->update(['custom_domain' => null]);
        }

        $domain->delete();

        return response()->json(['message' => 'Dominio eliminado']);
    }

    /**
     * Establecer un dominio verificado como el activo
     */
    public function setActive(Request $request, TenantDomain $domain): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant || $domain->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Dominio no encontrado'], 404);
        }

        if (!$domain->isVerified()) {
            return response()->json(['error' => 'El dominio debe estar verificado primero'], 422);
        }

        $tenant->update(['custom_domain' => $domain->domain]);

        return response()->json([
            'message' => 'Dominio activo actualizado',
            'custom_domain' => $domain->domain,
        ]);
    }
}
