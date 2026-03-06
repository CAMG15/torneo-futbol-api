<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MercadoPagoService;
use App\Services\PayPalService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Webhook de MercadoPago
     */
    public function mercadopago(Request $request): JsonResponse
    {
        Log::info('MercadoPago webhook received', $request->all());

        // Verificar firma del webhook (opcional pero recomendado)
        $secret = config('services.mercadopago.webhook_secret');
        if ($secret) {
            $signature = $request->header('x-signature');
            if (!$this->verifyMercadoPagoSignature($request, $signature, $secret)) {
                Log::warning('MercadoPago webhook: Invalid signature');
                return response()->json(['message' => 'Invalid signature'], 401);
            }
        }

        try {
            $mpService = new MercadoPagoService();
            $mpService->handleWebhook($request->all());

            return response()->json(['message' => 'OK']);
        } catch (\Exception $e) {
            Log::error('MercadoPago webhook error', [
                'error' => $e->getMessage(),
                'data' => $request->all(),
            ]);
            return response()->json(['message' => 'Error processing webhook'], 500);
        }
    }

    /**
     * Webhook de PayPal
     */
    public function paypal(Request $request): JsonResponse
    {
        Log::info('PayPal webhook received', $request->all());

        // Verificar el webhook de PayPal
        $webhookId = config('services.paypal.webhook_id');
        if ($webhookId && !$this->verifyPayPalWebhook($request, $webhookId)) {
            Log::warning('PayPal webhook: Verification failed');
            return response()->json(['message' => 'Verification failed'], 401);
        }

        try {
            $paypalService = new PayPalService();
            $paypalService->handleWebhook($request->all());

            return response()->json(['message' => 'OK']);
        } catch (\Exception $e) {
            Log::error('PayPal webhook error', [
                'error' => $e->getMessage(),
                'data' => $request->all(),
            ]);
            return response()->json(['message' => 'Error processing webhook'], 500);
        }
    }

    /**
     * Verificar firma de MercadoPago
     */
    private function verifyMercadoPagoSignature(Request $request, ?string $signature, string $secret): bool
    {
        if (!$signature) return false;

        // Parsear la firma: ts=xxx,v1=xxx
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = explode('=', $part, 2);
            $parts[$key] = $value;
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';
        $dataId = $request->input('data.id', '');

        // Generar firma esperada
        $manifest = "id:{$dataId};request-id:{$request->header('x-request-id')};ts:{$ts};";
        $expectedSignature = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expectedSignature, $v1);
    }

    /**
     * Verificar webhook de PayPal usando la API de verificación de PayPal.
     * Docs: https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature
     */
    private function verifyPayPalWebhook(Request $request, string $webhookId): bool
    {
        $mode     = config('services.paypal.mode', 'sandbox');
        $baseUrl  = $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        $clientId     = config('services.paypal.client_id');
        $clientSecret = config('services.paypal.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            Log::warning('PayPal webhook: credentials not configured, skipping verification');
            return false;
        }

        try {
            // 1. Obtener access token
            $tokenResponse = Http::withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->post("{$baseUrl}/v1/oauth2/token", ['grant_type' => 'client_credentials']);

            if ($tokenResponse->failed()) {
                Log::error('PayPal webhook: failed to get access token', ['status' => $tokenResponse->status()]);
                return false;
            }

            $accessToken = $tokenResponse->json('access_token');

            // 2. Verificar la firma del webhook
            $verifyResponse = Http::withToken($accessToken)
                ->post("{$baseUrl}/v1/notifications/verify-webhook-signature", [
                    'auth_algo'         => $request->header('PAYPAL-AUTH-ALGO'),
                    'cert_url'          => $request->header('PAYPAL-CERT-URL'),
                    'transmission_id'   => $request->header('PAYPAL-TRANSMISSION-ID'),
                    'transmission_sig'  => $request->header('PAYPAL-TRANSMISSION-SIG'),
                    'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                    'webhook_id'        => $webhookId,
                    'webhook_event'     => $request->all(),
                ]);

            if ($verifyResponse->failed()) {
                Log::error('PayPal webhook: verification API call failed', ['status' => $verifyResponse->status()]);
                return false;
            }

            $status = $verifyResponse->json('verification_status');
            Log::info('PayPal webhook verification', ['status' => $status]);

            return $status === 'SUCCESS';
        } catch (\Exception $e) {
            Log::error('PayPal webhook: verification exception', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
