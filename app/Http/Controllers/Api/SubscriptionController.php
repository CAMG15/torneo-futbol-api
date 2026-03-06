<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SubscriptionCancelledMail;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MercadoPagoService;
use App\Services\PayPalService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SubscriptionController extends Controller
{
    /**
     * Listar planes disponibles
     */
    public function plans(): JsonResponse
    {
        $plans = Plan::active()->ordered()->get();

        return response()->json([
            'plans' => $plans->map(fn($plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'price' => $plan->price,
                'price_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'billing_period' => $plan->billing_period,
                'features' => $plan->features,
                'limits' => $plan->limits,
                'description' => $plan->description,
                'is_free' => $plan->isFree(),
            ]),
        ]);
    }

    /**
     * Plan actual del tenant
     */
    public function currentPlan(): JsonResponse
    {
        $tenant = app('current_tenant');
        if (!$tenant) {
            return response()->json(['message' => 'Tenant no encontrado'], 404);
        }

        $subscription = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->with('plan')
            ->latest()
            ->first();

        $plan = $subscription?->plan ?? Plan::where('slug', 'free')->first();

        return response()->json([
            'current_plan' => [
                'name' => $plan->name,
                'slug' => $plan->slug,
                'features' => $plan->features,
                'limits' => $plan->limits,
            ],
            'subscription' => $subscription ? [
                'id'                       => $subscription->id,
                'status'                   => $subscription->status,
                'payment_provider'         => $subscription->payment_provider,
                'auto_renew'               => (bool) $subscription->auto_renew,
                'provider_subscription_id' => $subscription->provider_subscription_id,
                'current_period_start'     => $subscription->current_period_start,
                'current_period_end'       => $subscription->current_period_end,
                'cancelled_at'             => $subscription->cancelled_at,
            ] : null,
            'tenant_plan' => $tenant->plan,
        ]);
    }

    /**
     * Iniciar checkout con MercadoPago
     */
    public function checkoutMercadoPago(Request $request): JsonResponse
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);

        $tenant = app('current_tenant');
        $plan = Plan::findOrFail($request->plan_id);

        if ($plan->isFree()) {
            return response()->json(['message' => 'No se puede pagar por un plan gratuito'], 422);
        }

        if ($tenant->plan === $plan->slug) {
            return response()->json(['message' => 'Ya tienes este plan activo'], 422);
        }

        try {
            $mpService = new MercadoPagoService();
            $preference = $mpService->createSubscriptionPreference($tenant, $plan);

            return response()->json([
                'provider' => 'mercadopago',
                'preference_id' => $preference['preference_id'],
                'init_point' => $preference['init_point'],
                'sandbox_init_point' => $preference['sandbox_init_point'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'setup_required' => true,
            ], 503);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear la preferencia de pago: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Iniciar checkout con PayPal
     */
    public function checkoutPayPal(Request $request): JsonResponse
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);

        $tenant = app('current_tenant');
        $plan = Plan::findOrFail($request->plan_id);

        if ($plan->isFree()) {
            return response()->json(['message' => 'No se puede pagar por un plan gratuito'], 422);
        }

        if ($tenant->plan === $plan->slug) {
            return response()->json(['message' => 'Ya tienes este plan activo'], 422);
        }

        try {
            $paypalService = new PayPalService();
            $order = $paypalService->createOrder($tenant, $plan);

            return response()->json([
                'provider' => 'paypal',
                'order_id' => $order['order_id'],
                'approve_url' => $order['approve_url'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'setup_required' => true,
            ], 503);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear la orden de PayPal: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Capturar pago de PayPal después de aprobación
     */
    public function capturePayPal(Request $request): JsonResponse
    {
        $request->validate(['order_id' => 'required|string']);

        try {
            $paypalService = new PayPalService();
            $payment = $paypalService->processCapture($request->order_id);

            return response()->json([
                'message' => $payment->isApproved()
                    ? 'Pago procesado exitosamente'
                    : 'El pago está pendiente de confirmación',
                'payment' => [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'amount' => $payment->formatted_amount,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al procesar el pago de PayPal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancelar suscripción (downgrade a free al final del período)
     */
    public function cancel(): JsonResponse
    {
        $tenant = app('current_tenant');

        $subscription = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No tienes una suscripción activa'], 404);
        }

        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $activeUntil = $subscription->current_period_end->format('d/m/Y');

        // Enviar email de cancelación al owner
        try {
            $owner = User::find($tenant->owner_id);
            if ($owner) {
                Mail::to($owner->email)->queue(new SubscriptionCancelledMail($owner, $tenant, $activeUntil));
            }
        } catch (\Exception $e) {
            // No interrumpir si el email falla
        }

        return response()->json([
            'message' => 'Suscripción cancelada. Tu plan seguirá activo hasta ' . $activeUntil,
            'active_until' => $subscription->current_period_end,
        ]);
    }

    /**
     * Cambiar a plan Free (downgrade inmediato)
     */
    public function downgradeToFree(): JsonResponse
    {
        $tenant = app('current_tenant');

        // Validar: no permitir si tiene más de 1 torneo
        $tournamentCount = $tenant->tournaments()->count();
        if ($tournamentCount > 1) {
            return response()->json([
                'error' => "No puedes bajar a Free porque tienes {$tournamentCount} torneos. El plan Free solo permite 1. Elimina torneos primero.",
            ], 422);
        }

        // Cancelar suscripción activa
        Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $tenant->update(['plan' => 'free']);

        return response()->json([
            'message' => 'Has cambiado al plan gratuito',
            'plan' => 'free',
        ]);
    }

    /**
     * Iniciar suscripción recurrente con MercadoPago (Preapproval)
     */
    public function subscribeMercadoPago(Request $request): JsonResponse
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);

        $tenant = app('current_tenant');
        $plan   = Plan::findOrFail($request->plan_id);

        if ($plan->isFree()) {
            return response()->json(['message' => 'El plan gratuito no requiere suscripción'], 422);
        }

        try {
            $mpService   = new MercadoPagoService();
            $preapproval = $mpService->createPreapproval($tenant, $plan);

            return response()->json([
                'provider'       => 'mercadopago',
                'preapproval_id' => $preapproval['preapproval_id'],
                'init_point'     => $preapproval['init_point'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'setup_required' => true], 503);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al crear la suscripción: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Iniciar suscripción recurrente con PayPal Subscriptions API
     */
    public function subscribePayPal(Request $request): JsonResponse
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);

        $tenant = app('current_tenant');
        $plan   = Plan::findOrFail($request->plan_id);

        if ($plan->isFree()) {
            return response()->json(['message' => 'El plan gratuito no requiere suscripción'], 422);
        }

        try {
            $paypalService = new PayPalService();
            $subscription  = $paypalService->createPayPalSubscription($tenant, $plan);

            return response()->json([
                'provider'        => 'paypal',
                'subscription_id' => $subscription['subscription_id'],
                'approve_url'     => $subscription['approve_url'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'setup_required' => true], 503);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al crear la suscripción: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Cancelar suscripción recurrente en el proveedor de pago
     */
    public function cancelRecurring(): JsonResponse
    {
        $tenant = app('current_tenant');

        $subscription = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'No tienes una suscripción recurrente activa'], 404);
        }

        // Cancelar en el proveedor
        if ($subscription->provider_subscription_id) {
            try {
                if ($subscription->payment_provider === 'mercadopago') {
                    (new MercadoPagoService())->cancelPreapproval($subscription->provider_subscription_id);
                } elseif ($subscription->payment_provider === 'paypal') {
                    (new PayPalService())->cancelPayPalSubscription($subscription->provider_subscription_id);
                }
            } catch (\Exception $e) {
                Log::warning('Could not cancel subscription in provider: ' . $e->getMessage(), [
                    'subscription_id' => $subscription->id,
                    'provider'        => $subscription->payment_provider,
                ]);
            }
        }

        $subscription->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
            'auto_renew'   => false,
        ]);

        $activeUntil = $subscription->current_period_end->format('d/m/Y');

        return response()->json([
            'message'      => 'Auto-renovación cancelada. Tu plan seguirá activo hasta ' . $activeUntil,
            'active_until' => $subscription->current_period_end,
        ]);
    }

    /**
     * Historial de pagos
     */
    public function paymentHistory(): JsonResponse
    {
        $tenant = app('current_tenant');

        $payments = Payment::where('tenant_id', $tenant->id)
            ->with('subscription.plan')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($payments);
    }
}
