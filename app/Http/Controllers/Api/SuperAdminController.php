<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminController extends Controller
{
    /**
     * Dashboard con stats globales
     */
    public function dashboard(): JsonResponse
    {
        $totalTenants = Tenant::count();
        $tenantsByPlan = Tenant::select('plan', DB::raw('count(*) as total'))
            ->groupBy('plan')
            ->pluck('total', 'plan');

        $totalUsers = User::where('is_superadmin', false)->count();
        $activeTenants = Tenant::where('is_active', true)->count();

        // Ingresos del mes actual
        $monthlyRevenue = Payment::where('status', 'approved')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount_cents');

        // Tenants recientes (últimos 10)
        $recentTenants = Tenant::with('owner:id,name,email')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Últimos pagos (últimos 10)
        $recentPayments = Payment::with(['tenant:id,name,slug', 'subscription.plan:id,name,slug'])
            ->where('status', 'approved')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Suscripciones activas
        $activeSubscriptions = Subscription::where('status', 'active')->count();

        return response()->json([
            'stats' => [
                'total_tenants' => $totalTenants,
                'active_tenants' => $activeTenants,
                'total_users' => $totalUsers,
                'active_subscriptions' => $activeSubscriptions,
                'monthly_revenue_cents' => $monthlyRevenue,
                'monthly_revenue' => number_format($monthlyRevenue / 100, 2),
                'tenants_by_plan' => [
                    'free' => $tenantsByPlan['free'] ?? 0,
                    'pro' => $tenantsByPlan['pro'] ?? 0,
                    'business' => $tenantsByPlan['business'] ?? 0,
                ],
            ],
            'recent_tenants' => $recentTenants,
            'recent_payments' => $recentPayments,
        ]);
    }

    /**
     * Lista paginada de tenants
     */
    public function tenants(Request $request): JsonResponse
    {
        $query = Tenant::with('owner:id,name,email')
            ->withCount(['tournaments', 'teams', 'players']);

        // Filtros
        if ($plan = $request->query('plan')) {
            $query->where('plan', $plan);
        }

        if ($status = $request->query('status')) {
            $query->where('is_active', $status === 'active');
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->query('sort', 'created_at');
        $sortDir = $request->query('dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $tenants = $query->paginate($request->query('per_page', 20));

        return response()->json($tenants);
    }

    /**
     * Detalle de un tenant
     */
    public function tenantDetail(Tenant $tenant): JsonResponse
    {
        $tenant->load([
            'owner:id,name,email,created_at',
            'users:id,name,email',
        ]);

        $stats = [
            'tournaments_count' => $tenant->tournaments()->count(),
            'teams_count' => $tenant->teams()->count(),
            'players_count' => $tenant->players()->count(),
            'active_tournaments' => $tenant->tournaments()->where('status', 'En Curso')->count(),
        ];

        $subscription = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->with('plan:id,name,slug,price_cents')
            ->latest()
            ->first();

        $recentPayments = Payment::where('tenant_id', $tenant->id)
            ->with('subscription.plan:id,name,slug')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $domains = $tenant->domains()->get();

        return response()->json([
            'tenant' => $tenant,
            'stats' => $stats,
            'subscription' => $subscription,
            'recent_payments' => $recentPayments,
            'domains' => $domains,
        ]);
    }

    /**
     * Editar tenant (nombre, status, etc.)
     */
    public function updateTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
            'description' => 'sometimes|nullable|string|max:1000',
        ]);

        $tenant->update($validated);

        return response()->json([
            'message' => 'Tenant actualizado',
            'tenant' => $tenant->fresh(),
        ]);
    }

    /**
     * Cambiar plan de un tenant manualmente
     */
    public function changePlan(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'plan' => 'required|in:free,pro,business',
        ]);

        $oldPlan = $tenant->plan;
        $newPlan = $validated['plan'];

        // Validar downgrade a Free: no permitir si tiene más de 1 torneo
        if ($newPlan === 'free' && $oldPlan !== 'free') {
            $tournamentCount = $tenant->tournaments()->count();
            if ($tournamentCount > 1) {
                return response()->json([
                    'error' => "No se puede bajar a Free. El tenant tiene {$tournamentCount} torneos (máximo 1 en Free). Debe eliminar torneos primero.",
                ], 422);
            }
        }

        $tenant->update(['plan' => $newPlan]);

        // Cancelar suscripción activa anterior
        $activeSub = $tenant->activeSubscription()->first();
        if ($activeSub) {
            $activeSub->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
        }

        // Si el nuevo plan no es free, crear suscripción administrativa
        if ($newPlan !== 'free') {
            $planModel = \App\Models\Plan::where('slug', $newPlan)->first();

            if ($planModel) {
                Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $planModel->id,
                    'status' => 'active',
                    'payment_provider' => null,
                    'current_period_start' => now(),
                    'current_period_end' => now()->addYear(),
                    'metadata' => json_encode(['assigned_by' => 'superadmin', 'previous_plan' => $oldPlan]),
                ]);
            }
        }

        return response()->json([
            'message' => "Plan cambiado de {$oldPlan} a {$newPlan}",
            'tenant' => $tenant->fresh(),
        ]);
    }

    /**
     * Lista de suscripciones
     */
    public function subscriptions(Request $request): JsonResponse
    {
        $query = Subscription::with([
            'tenant:id,name,slug,plan',
            'plan:id,name,slug,price_cents',
        ]);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($provider = $request->query('provider')) {
            $query->where('payment_provider', $provider);
        }

        $subscriptions = $query->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 20));

        return response()->json($subscriptions);
    }

    /**
     * Historial global de pagos
     */
    public function payments(Request $request): JsonResponse
    {
        $query = Payment::with([
            'tenant:id,name,slug',
            'subscription.plan:id,name,slug',
        ]);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($provider = $request->query('provider')) {
            $query->where('payment_provider', $provider);
        }

        $payments = $query->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 20));

        return response()->json($payments);
    }
}
