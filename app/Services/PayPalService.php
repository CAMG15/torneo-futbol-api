<?php

namespace App\Services;

use App\Mail\PaymentConfirmedMail;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AffiliateService;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PayPalService
{
    private PayPalClient $client;

    public function __construct()
    {
        $clientId     = config('services.paypal.client_id');
        $clientSecret = config('services.paypal.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            throw new \RuntimeException('PayPal no configurado. Agrega PAYPAL_CLIENT_ID y PAYPAL_CLIENT_SECRET en el archivo .env');
        }

        $mode = config('services.paypal.mode', 'sandbox');

        $paypalConfig = [
            'mode' => $mode,
            'sandbox' => [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'app_id'        => '',
            ],
            'live' => [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'app_id'        => '',
            ],
            'payment_action' => 'Sale',
            'currency'       => 'MXN',
            'notify_url'     => '',
            'locale'         => 'es_MX',
            'validate_ssl'   => true,
        ];

        $this->client = new PayPalClient($paypalConfig);
        $this->client->getAccessToken();
    }

    /**
     * Crear orden de pago único PayPal (flujo existente)
     */
    public function createOrder(Tenant $tenant, Plan $plan): array
    {
        $order = $this->client->createOrder([
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => json_encode([
                        'tenant_id' => $tenant->id,
                        'plan_id' => $plan->id,
                    ]),
                    'description' => 'MiCopa - Plan ' . $plan->name . ' (Mensual)',
                    'amount' => [
                        'currency_code' => $plan->currency,
                        'value' => number_format($plan->price, 2, '.', ''),
                    ],
                ],
            ],
            'application_context' => [
                'brand_name' => 'MiCopa',
                'locale' => 'es-MX',
                'return_url' => config('app.frontend_url', 'http://localhost:8100') . '/admin/billing?status=success&provider=paypal',
                'cancel_url' => config('app.frontend_url', 'http://localhost:8100') . '/admin/billing?status=cancelled&provider=paypal',
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
            ],
        ]);

        if (isset($order['error'])) {
            Log::error('PayPal: Error creating order', ['error' => $order]);
            throw new \Exception('Error al crear la orden de PayPal: ' . ($order['message'] ?? 'Unknown error'));
        }

        $approveLink = collect($order['links'] ?? [])
            ->firstWhere('rel', 'approve');

        return [
            'order_id' => $order['id'],
            'approve_url' => $approveLink['href'] ?? null,
            'status' => $order['status'],
        ];
    }

    /**
     * Capturar pago único después de aprobación del usuario
     */
    public function captureOrder(string $orderId): array
    {
        $result = $this->client->capturePaymentOrder($orderId);

        if (isset($result['error'])) {
            Log::error('PayPal: Error capturing order', ['error' => $result, 'order_id' => $orderId]);
            throw new \Exception('Error al capturar el pago de PayPal');
        }

        return $result;
    }

    /**
     * Procesar captura exitosa y activar suscripción (pago único)
     */
    public function processCapture(string $orderId): Payment
    {
        $captureResult = $this->captureOrder($orderId);

        $purchaseUnit = $captureResult['purchase_units'][0] ?? [];
        $capture = $purchaseUnit['payments']['captures'][0] ?? [];
        $externalRef = json_decode($purchaseUnit['reference_id'] ?? '{}', true);

        if (!$externalRef || !isset($externalRef['tenant_id'], $externalRef['plan_id'])) {
            throw new \Exception('Referencia inválida en la orden de PayPal');
        }

        $tenantId = $externalRef['tenant_id'];
        $planId = $externalRef['plan_id'];
        $status = $this->mapCaptureStatus($capture['status'] ?? 'PENDING');

        $payment = Payment::create([
            'tenant_id'           => $tenantId,
            'amount_cents'        => (int) (($capture['amount']['value'] ?? 0) * 100),
            'currency'            => $capture['amount']['currency_code'] ?? 'MXN',
            'payment_provider'    => 'paypal',
            'provider_payment_id' => $capture['id'] ?? $orderId,
            'status'              => $status,
            'payment_method'      => 'paypal',
            'description'         => 'Pago via PayPal - Orden: ' . $orderId,
            'provider_data'       => [
                'order_id'   => $orderId,
                'capture_id' => $capture['id'] ?? null,
                'status'     => $captureResult['status'] ?? null,
                'payer'      => $captureResult['payer'] ?? null,
            ],
            'paid_at' => $status === 'approved' ? now() : null,
        ]);

        if ($status === 'approved') {
            $this->activateSubscription($tenantId, $planId, $payment);
        }

        return $payment;
    }

    /**
     * Obtener o crear un PayPal Billing Plan para el Plan dado.
     * Guarda el paypal_plan_id en la tabla plans para no recrearlo.
     */
    public function ensurePayPalPlan(Plan $plan): string
    {
        if ($plan->paypal_plan_id) {
            return $plan->paypal_plan_id;
        }

        // Crear producto en PayPal
        $product = $this->client->createProduct([
            'name'        => 'MiCopa - Plan ' . $plan->name,
            'description' => 'Suscripción mensual al plan ' . $plan->name,
            'type'        => 'SERVICE',
            'category'    => 'SOFTWARE',
        ]);

        if (isset($product['error']) || empty($product['id'])) {
            Log::error('PayPal: Error creating product', ['error' => $product]);
            throw new \Exception('Error al crear el producto en PayPal');
        }

        // Crear billing plan mensual
        $billingPlan = $this->client->createPlan([
            'product_id'          => $product['id'],
            'name'                => 'MiCopa ' . $plan->name . ' Mensual',
            'description'         => 'Plan ' . $plan->name . ' con cobro automático mensual',
            'status'              => 'ACTIVE',
            'billing_cycles'      => [
                [
                    'frequency'      => [
                        'interval_unit'  => 'MONTH',
                        'interval_count' => 1,
                    ],
                    'tenure_type'    => 'REGULAR',
                    'sequence'       => 1,
                    'total_cycles'   => 0,
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value'         => number_format($plan->price, 2, '.', ''),
                            'currency_code' => $plan->currency,
                        ],
                    ],
                ],
            ],
            'payment_preferences' => [
                'auto_bill_outstanding'    => true,
                'setup_fee_failure_action' => 'CONTINUE',
                'payment_failure_threshold'=> 3,
            ],
        ]);

        if (isset($billingPlan['error']) || empty($billingPlan['id'])) {
            Log::error('PayPal: Error creating billing plan', ['error' => $billingPlan]);
            throw new \Exception('Error al crear el plan de facturación en PayPal');
        }

        $plan->update(['paypal_plan_id' => $billingPlan['id']]);

        Log::info('PayPal: Billing plan created', [
            'plan_id'        => $plan->id,
            'paypal_plan_id' => $billingPlan['id'],
        ]);

        return $billingPlan['id'];
    }

    /**
     * Crear suscripción recurrente en PayPal
     */
    public function createPayPalSubscription(Tenant $tenant, Plan $plan): array
    {
        $paypalPlanId = $this->ensurePayPalPlan($plan);
        $frontendUrl  = config('app.frontend_url', 'http://localhost:8100');
        $ownerEmail   = $tenant->owner->email ?? $tenant->email;

        $subscription = $this->client->createSubscription([
            'plan_id'             => $paypalPlanId,
            'start_time'          => now()->addMinutes(5)->toIso8601String(),
            'subscriber'          => [
                'name'          => ['given_name' => $tenant->name],
                'email_address' => $ownerEmail,
            ],
            'application_context' => [
                'brand_name'          => 'MiCopa',
                'locale'              => 'es-MX',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action'         => 'SUBSCRIBE_NOW',
                'return_url'          => $frontendUrl . '/admin/billing?status=success&provider=paypal&recurring=true',
                'cancel_url'          => $frontendUrl . '/admin/billing?status=cancelled&provider=paypal',
            ],
            'custom_id' => json_encode([
                'tenant_id' => $tenant->id,
                'plan_id'   => $plan->id,
            ]),
        ]);

        if (isset($subscription['error']) || empty($subscription['id'])) {
            Log::error('PayPal: Error creating subscription', ['error' => $subscription]);
            throw new \Exception('Error al crear la suscripción en PayPal: ' . ($subscription['message'] ?? 'Unknown'));
        }

        $approveLink = collect($subscription['links'] ?? [])->firstWhere('rel', 'approve');

        return [
            'subscription_id' => $subscription['id'],
            'approve_url'     => $approveLink['href'] ?? null,
            'status'          => $subscription['status'],
        ];
    }

    /**
     * Cancelar suscripción recurrente en PayPal
     */
    public function cancelPayPalSubscription(string $subscriptionId): void
    {
        $result = $this->client->cancelSubscription($subscriptionId, 'Cancelación solicitada por el usuario');

        if (isset($result['error'])) {
            Log::warning('PayPal: Could not cancel subscription', [
                'subscription_id' => $subscriptionId,
                'error'           => $result,
            ]);
        }
    }

    /**
     * Procesar webhook de PayPal (pago único + suscripciones recurrentes)
     */
    public function handleWebhook(array $data): void
    {
        $eventType = $data['event_type'] ?? null;

        Log::info('PayPal webhook received', ['event_type' => $eventType]);

        match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED'           => $this->handleCaptureCompleted($data),
            'PAYMENT.CAPTURE.REFUNDED'            => $this->handleRefund($data),
            'BILLING.SUBSCRIPTION.ACTIVATED'      => $this->handleSubscriptionActivated($data),
            'BILLING.SUBSCRIPTION.CANCELLED'      => $this->handleSubscriptionCancelled($data),
            'PAYMENT.SALE.COMPLETED'              => $this->handleRecurringPayment($data),
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => $this->handleSubscriptionPaymentFailed($data),
            default                               => null,
        };
    }

    private function handleCaptureCompleted(array $data): void
    {
        $resource  = $data['resource'] ?? [];
        $captureId = $resource['id'] ?? null;
        if (!$captureId) return;

        $payment = Payment::where('provider_payment_id', $captureId)->first();
        if ($payment && $payment->status !== 'approved') {
            $payment->update(['status' => 'approved', 'paid_at' => now()]);
        }
    }

    private function handleRefund(array $data): void
    {
        $resource  = $data['resource'] ?? [];
        $captureId = $resource['id'] ?? null;
        if (!$captureId) return;

        $payment = Payment::where('provider_payment_id', $captureId)->first();
        if ($payment) {
            $payment->update(['status' => 'refunded']);
        }
    }

    private function handleSubscriptionActivated(array $data): void
    {
        $resource  = $data['resource'] ?? [];
        $subId     = $resource['id'] ?? null;
        $customId  = json_decode($resource['custom_id'] ?? '{}', true);

        if (!$subId || !isset($customId['tenant_id'], $customId['plan_id'])) return;

        // Evitar duplicados
        $exists = Subscription::where('provider_subscription_id', $subId)
            ->where('status', 'active')->exists();
        if ($exists) return;

        $plan = Plan::find($customId['plan_id']);
        if (!$plan) return;

        Subscription::where('tenant_id', $customId['tenant_id'])
            ->where('status', 'active')
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        Subscription::create([
            'tenant_id'                => $customId['tenant_id'],
            'plan_id'                  => $customId['plan_id'],
            'status'                   => 'active',
            'payment_provider'         => 'paypal',
            'provider_subscription_id' => $subId,
            'auto_renew'               => true,
            'current_period_start'     => now(),
            'current_period_end'       => now()->addMonth(),
        ]);

        Tenant::find($customId['tenant_id'])?->update(['plan' => $plan->slug]);

        Log::info('PayPal: Recurring subscription activated', [
            'tenant_id'       => $customId['tenant_id'],
            'subscription_id' => $subId,
            'plan'            => $plan->slug,
        ]);
    }

    private function handleSubscriptionCancelled(array $data): void
    {
        $subId = $data['resource']['id'] ?? null;
        if (!$subId) return;

        Subscription::where('provider_subscription_id', $subId)
            ->whereIn('status', ['active', 'past_due'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        Log::info('PayPal: Subscription cancelled via webhook', ['subscription_id' => $subId]);
    }

    private function handleRecurringPayment(array $data): void
    {
        $resource           = $data['resource'] ?? [];
        $billingAgreementId = $resource['billing_agreement_id'] ?? null;

        if (!$billingAgreementId) return;

        $subscription = Subscription::where('provider_subscription_id', $billingAgreementId)
            ->where('status', 'active')
            ->first();

        if (!$subscription) return;

        // Extender período
        $subscription->update([
            'current_period_start' => now(),
            'current_period_end'   => now()->addMonth(),
        ]);

        // Registrar pago de renovación
        $recurringPayment = Payment::create([
            'tenant_id'           => $subscription->tenant_id,
            'subscription_id'     => $subscription->id,
            'amount_cents'        => (int) (($resource['amount']['total'] ?? 0) * 100),
            'currency'            => $resource['amount']['currency'] ?? 'MXN',
            'payment_provider'    => 'paypal',
            'provider_payment_id' => $resource['id'],
            'status'              => 'approved',
            'paid_at'             => now(),
            'description'         => 'Renovación automática - PayPal',
            'provider_data'       => $resource,
        ]);

        try {
            (new AffiliateService())->processPaymentCommission($recurringPayment);
        } catch (\Exception $e) {
            Log::warning('PayPal: AffiliateService error on recurring payment', [
                'error'      => $e->getMessage(),
                'payment_id' => $recurringPayment->id,
            ]);
        }

        Log::info('PayPal: Recurring payment processed', [
            'subscription_id'     => $subscription->id,
            'billing_agreement_id'=> $billingAgreementId,
        ]);
    }

    private function handleSubscriptionPaymentFailed(array $data): void
    {
        $subId = $data['resource']['id'] ?? null;
        if (!$subId) return;

        Subscription::where('provider_subscription_id', $subId)
            ->where('status', 'active')
            ->update(['status' => 'past_due']);

        Log::warning('PayPal: Subscription payment failed', ['subscription_id' => $subId]);
    }

    /**
     * Activar suscripción después de pago único exitoso
     */
    private function activateSubscription(int $tenantId, int $planId, Payment $payment): void
    {
        $plan = Plan::find($planId);
        if (!$plan) return;

        Subscription::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $subscription = Subscription::create([
            'tenant_id'            => $tenantId,
            'plan_id'              => $planId,
            'status'               => 'active',
            'payment_provider'     => 'paypal',
            'auto_renew'           => false,
            'current_period_start' => now(),
            'current_period_end'   => now()->addMonth(),
        ]);

        $payment->update(['subscription_id' => $subscription->id]);

        $tenant = Tenant::find($tenantId);
        if ($tenant) {
            $tenant->update(['plan' => $plan->slug]);
        }

        Log::info('PayPal: Subscription activated', [
            'tenant_id'       => $tenantId,
            'plan'            => $plan->slug,
            'subscription_id' => $subscription->id,
        ]);

        // Proceso de comisión de afiliado
        try {
            (new AffiliateService())->processPaymentCommission($payment->fresh());
        } catch (\Exception $e) {
            Log::warning('PayPal: AffiliateService error on single payment', [
                'error'      => $e->getMessage(),
                'payment_id' => $payment->id,
            ]);
        }

        try {
            $owner = User::find($tenant->owner_id);
            if ($owner) {
                Mail::to($owner->email)->queue(new PaymentConfirmedMail($owner, $tenant, $payment, $plan->name));
            }
        } catch (\Exception $e) {
            Log::warning('PayPal: Could not send confirmation email', ['error' => $e->getMessage()]);
        }
    }

    private function mapCaptureStatus(string $status): string
    {
        return match ($status) {
            'COMPLETED'                          => 'approved',
            'PENDING'                            => 'pending',
            'DECLINED', 'FAILED'                 => 'rejected',
            'REFUNDED', 'PARTIALLY_REFUNDED'     => 'refunded',
            default                              => 'pending',
        };
    }
}
