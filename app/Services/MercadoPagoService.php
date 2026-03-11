<?php

namespace App\Services;

use App\Mail\PaymentConfirmedMail;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AffiliateService;
use MercadoPago\SDK;
use MercadoPago\Preference;
use MercadoPago\Item;
use MercadoPago\Payer;
use MercadoPago\BackUrls;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MercadoPagoService
{
    private string $accessToken;

    public function __construct()
    {
        $this->accessToken = config('services.mercadopago.access_token', '');

        if (empty($this->accessToken)) {
            throw new \RuntimeException('MercadoPago access token no configurado. Agrega MERCADOPAGO_ACCESS_TOKEN en el archivo .env');
        }

        SDK::setAccessToken($this->accessToken);
    }

    /**
     * Crear preferencia de pago único (flujo existente)
     */
    public function createSubscriptionPreference(Tenant $tenant, Plan $plan): array
    {
        $item = new Item();
        $item->id          = 'plan_' . $plan->slug;
        $item->title       = 'MiCopa - Plan ' . $plan->name;
        $item->description = 'Suscripción mensual al plan ' . $plan->name;
        $item->quantity    = 1;
        $item->unit_price  = (float) $plan->price;
        $item->currency_id = $plan->currency;

        $payer = new Payer();
        $payer->email = $tenant->owner->email ?? $tenant->email;

        $frontendUrl = config('app.frontend_url', 'http://localhost:8100');

        $preference = new Preference();
        $preference->items = [$item];
        $preference->payer = $payer;
        $preference->back_urls = [
            'success' => $frontendUrl . '/admin/billing?status=success&provider=mercadopago',
            'failure' => $frontendUrl . '/admin/billing?status=failure&provider=mercadopago',
            'pending' => $frontendUrl . '/admin/billing?status=pending&provider=mercadopago',
        ];
        $preference->auto_return      = 'approved';
        $preference->external_reference = json_encode([
            'tenant_id' => $tenant->id,
            'plan_id'   => $plan->id,
        ]);
        $preference->notification_url  = config('app.url') . '/api/webhooks/mercadopago';
        $preference->statement_descriptor = 'MICOPA';

        $preference->save();

        return [
            'preference_id'       => $preference->id,
            'init_point'          => $preference->init_point,
            'sandbox_init_point'  => $preference->sandbox_init_point,
        ];
    }

    /**
     * Crear preaprobación (suscripción recurrente con cobro automático mensual)
     * POST https://api.mercadopago.com/preapproval
     */
    public function createPreapproval(Tenant $tenant, Plan $plan): array
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:8100');

        $response = Http::withToken($this->accessToken)
            ->post('https://api.mercadopago.com/preapproval', [
                'reason'             => 'MiCopa - Plan ' . $plan->name,
                'auto_recurring'     => [
                    'frequency'          => 1,
                    'frequency_type'     => 'months',
                    'transaction_amount' => (float) $plan->price,
                    'currency_id'        => $plan->currency,
                ],
                'back_url'           => $frontendUrl . '/admin/billing?status=success&provider=mercadopago&recurring=true',
                'payer_email'        => $tenant->owner->email ?? $tenant->email,
                'external_reference' => json_encode([
                    'tenant_id' => $tenant->id,
                    'plan_id'   => $plan->id,
                ]),
                'notification_url'   => config('app.url') . '/api/webhooks/mercadopago',
            ]);

        if ($response->failed()) {
            Log::error('MercadoPago: Error creating preapproval', [
                'status'   => $response->status(),
                'body'     => $response->body(),
                'tenant_id'=> $tenant->id,
            ]);
            throw new \Exception('Error al crear preaprobación en MercadoPago: ' . $response->body());
        }

        return [
            'preapproval_id' => $response->json('id'),
            'init_point'     => $response->json('init_point'),
            'status'         => $response->json('status'),
        ];
    }

    /**
     * Cancelar preaprobación en MercadoPago
     * PUT https://api.mercadopago.com/preapproval/{id}
     */
    public function cancelPreapproval(string $preapprovalId): void
    {
        $response = Http::withToken($this->accessToken)
            ->put("https://api.mercadopago.com/preapproval/{$preapprovalId}", [
                'status' => 'cancelled',
            ]);

        if ($response->failed()) {
            Log::warning('MercadoPago: Could not cancel preapproval', [
                'preapproval_id' => $preapprovalId,
                'status'         => $response->status(),
                'body'           => $response->body(),
            ]);
        }
    }

    /**
     * Procesar notificación de webhook (pago único + suscripciones recurrentes)
     */
    public function handleWebhook(array $data): void
    {
        $type = $data['type'] ?? $data['topic'] ?? null;

        match ($type) {
            'payment'                         => $this->processPayment($data['data']['id'] ?? null),
            'subscription_preapproval'        => $this->processPreapprovalUpdate($data['data']['id'] ?? null),
            'subscription_authorized_payment' => $this->processAuthorizedPayment($data['data']['id'] ?? null),
            default                           => Log::info('MercadoPago: Unhandled webhook type', ['type' => $type]),
        };
    }

    /**
     * Procesar cambio de estado de preaprobación
     */
    private function processPreapprovalUpdate(?string $preapprovalId): void
    {
        if (!$preapprovalId) return;

        $response = Http::withToken($this->accessToken)
            ->get("https://api.mercadopago.com/preapproval/{$preapprovalId}");

        if ($response->failed()) {
            Log::error('MercadoPago: Error fetching preapproval', ['id' => $preapprovalId]);
            return;
        }

        $data        = $response->json();
        $status      = $data['status'] ?? null;
        $externalRef = json_decode($data['external_reference'] ?? '{}', true);

        if (!isset($externalRef['tenant_id'], $externalRef['plan_id'])) {
            Log::warning('MercadoPago: Preapproval missing external_reference', ['id' => $preapprovalId]);
            return;
        }

        if ($status === 'authorized') {
            $this->activateRecurringSubscription(
                $externalRef['tenant_id'],
                $externalRef['plan_id'],
                $preapprovalId
            );
        } elseif (in_array($status, ['cancelled', 'paused'])) {
            Subscription::where('provider_subscription_id', $preapprovalId)
                ->whereIn('status', ['active'])
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            Log::info('MercadoPago: Preapproval cancelled/paused', [
                'preapproval_id' => $preapprovalId,
                'status'         => $status,
            ]);
        }
    }

    /**
     * Procesar pago automático de una suscripción recurrente
     */
    private function processAuthorizedPayment(?string $authorizedPaymentId): void
    {
        if (!$authorizedPaymentId) return;

        $response = Http::withToken($this->accessToken)
            ->get("https://api.mercadopago.com/authorized_payments/{$authorizedPaymentId}");

        if ($response->failed()) {
            Log::error('MercadoPago: Error fetching authorized_payment', ['id' => $authorizedPaymentId]);
            return;
        }

        $data          = $response->json();
        $preapprovalId = $data['preapproval_id'] ?? null;
        $mpStatus      = $data['status'] ?? null;

        if (!$preapprovalId) return;

        $subscription = Subscription::where('provider_subscription_id', $preapprovalId)
            ->where('status', 'active')
            ->first();

        if (!$subscription) {
            Log::warning('MercadoPago: No active subscription for preapproval', ['preapproval_id' => $preapprovalId]);
            return;
        }

        if ($mpStatus === 'authorized') {
            // Extender período de la suscripción
            $subscription->update([
                'current_period_start' => now(),
                'current_period_end'   => now()->addMonth(),
            ]);

            // Registrar pago de renovación
            $recurringPayment = Payment::create([
                'tenant_id'           => $subscription->tenant_id,
                'subscription_id'     => $subscription->id,
                'amount_cents'        => (int) (($data['transaction_amount'] ?? 0) * 100),
                'currency'            => $data['currency_id'] ?? 'MXN',
                'payment_provider'    => 'mercadopago',
                'provider_payment_id' => (string) $authorizedPaymentId,
                'status'              => 'approved',
                'paid_at'             => now(),
                'description'         => 'Renovación automática - MercadoPago',
                'provider_data'       => $data,
            ]);

            try {
                (new AffiliateService())->processPaymentCommission($recurringPayment);
            } catch (\Exception $e) {
                Log::warning('MercadoPago: AffiliateService error on recurring payment', [
                    'error'      => $e->getMessage(),
                    'payment_id' => $recurringPayment->id,
                ]);
            }

            Log::info('MercadoPago: Recurring payment processed', [
                'subscription_id'      => $subscription->id,
                'authorized_payment_id'=> $authorizedPaymentId,
            ]);
        }
    }

    /**
     * Activar suscripción recurrente (desde preapproval autorizado)
     */
    private function activateRecurringSubscription(int $tenantId, int $planId, string $preapprovalId): void
    {
        $plan = Plan::find($planId);
        if (!$plan) return;

        // Evitar duplicados: si ya existe una suscripción activa con este preapproval, no hacer nada
        $exists = Subscription::where('provider_subscription_id', $preapprovalId)
            ->where('status', 'active')->exists();
        if ($exists) return;

        Subscription::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        Subscription::create([
            'tenant_id'                => $tenantId,
            'plan_id'                  => $planId,
            'status'                   => 'active',
            'payment_provider'         => 'mercadopago',
            'provider_subscription_id' => $preapprovalId,
            'auto_renew'               => true,
            'current_period_start'     => now(),
            'current_period_end'       => now()->addMonth(),
        ]);

        Tenant::find($tenantId)?->update(['plan' => $plan->slug]);

        Log::info('MercadoPago: Recurring subscription activated', [
            'tenant_id'      => $tenantId,
            'preapproval_id' => $preapprovalId,
            'plan'           => $plan->slug,
        ]);
    }

    /**
     * Procesar un pago único consultando la API de MercadoPago
     */
    public function processPayment(string $paymentId): void
    {
        try {
            $mpPayment = \MercadoPago\Payment::find_by_id((int) $paymentId);
        } catch (\Exception $e) {
            Log::error('MercadoPago: Error fetching payment', [
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
            return;
        }

        $externalRef = json_decode($mpPayment->external_reference, true);
        if (!$externalRef || !isset($externalRef['tenant_id'], $externalRef['plan_id'])) {
            Log::warning('MercadoPago: Invalid external reference', [
                'payment_id'         => $paymentId,
                'external_reference' => $mpPayment->external_reference,
            ]);
            return;
        }

        $status = $this->mapPaymentStatus($mpPayment->status);

        $payment = Payment::updateOrCreate(
            ['provider_payment_id' => (string) $mpPayment->id],
            [
                'tenant_id'        => $externalRef['tenant_id'],
                'amount_cents'     => (int) ($mpPayment->transaction_amount * 100),
                'currency'         => $mpPayment->currency_id ?? 'MXN',
                'payment_provider' => 'mercadopago',
                'status'           => $status,
                'payment_method'   => $mpPayment->payment_method_id ?? null,
                'description'      => $mpPayment->description ?? null,
                'provider_data'    => [
                    'id'             => $mpPayment->id,
                    'status'         => $mpPayment->status,
                    'status_detail'  => $mpPayment->status_detail,
                    'payment_type'   => $mpPayment->payment_type_id ?? null,
                ],
                'paid_at' => $status === 'approved' ? now() : null,
            ]
        );

        if ($status === 'approved') {
            $this->activateSubscription($externalRef['tenant_id'], $externalRef['plan_id'], $payment);
        }
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
            'payment_provider'     => 'mercadopago',
            'auto_renew'           => false,
            'current_period_start' => now(),
            'current_period_end'   => now()->addMonth(),
        ]);

        $payment->update(['subscription_id' => $subscription->id]);

        $tenant = Tenant::find($tenantId);
        if ($tenant) {
            $tenant->update(['plan' => $plan->slug]);
        }

        Log::info('MercadoPago: Subscription activated', [
            'tenant_id'       => $tenantId,
            'plan'            => $plan->slug,
            'subscription_id' => $subscription->id,
        ]);

        // Proceso de comisión de afiliado
        try {
            (new AffiliateService())->processPaymentCommission($payment->fresh());
        } catch (\Exception $e) {
            Log::warning('MercadoPago: AffiliateService error on single payment', [
                'error'      => $e->getMessage(),
                'payment_id' => $payment->id,
            ]);
        }

        // Enviar email de confirmación al owner
        try {
            $owner = User::find($tenant->owner_id);
            if ($owner) {
                Mail::to($owner->email)->queue(new PaymentConfirmedMail($owner, $tenant, $payment, $plan->name));
            }
        } catch (\Exception $e) {
            Log::warning('MercadoPago: Could not send confirmation email', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Mapear estados de MercadoPago a nuestros estados
     */
    private function mapPaymentStatus(string $mpStatus): string
    {
        return match ($mpStatus) {
            'approved'                              => 'approved',
            'pending', 'in_process', 'authorized'  => 'pending',
            'rejected', 'cancelled'                => 'rejected',
            'refunded'                             => 'refunded',
            default                                => 'pending',
        };
    }
}
