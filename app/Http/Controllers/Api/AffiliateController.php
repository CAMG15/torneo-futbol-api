<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AffiliateController extends Controller
{
    /**
     * GET /affiliates — Get current user's affiliate summary
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $affiliate = Affiliate::where('user_id', $user->id)
            ->with('user:id,name,email')
            ->first();

        if (!$affiliate) {
            return response()->json([
                'affiliate'          => null,
                'is_registered'      => false,
                'referral_link'      => null,
                'stats'              => [
                    'total_referrals'  => 0,
                    'active_referrals' => 0,
                    'pending_cents'    => 0,
                    'earned_cents'     => 0,
                    'paid_cents'       => 0,
                ],
                'recent_commissions' => [],
            ]);
        }

        $frontendUrl  = config('app.frontend_url', 'https://app.micopa.pro');
        $referralLink = $frontendUrl . '/auth/register?ref=' . $affiliate->code;

        $activeReferrals = $affiliate->referrals()
            ->whereHas('tenant', fn($q) => $q->where('is_active', true))
            ->count();

        $recentCommissions = $affiliate->referrals()
            ->with('tenant:id,name,plan')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'affiliate'          => $affiliate,
            'is_registered'      => true,
            'referral_link'      => $referralLink,
            'stats'              => [
                'total_referrals'  => $affiliate->referrals()->count(),
                'active_referrals' => $activeReferrals,
                'pending_cents'    => $affiliate->pending_payout_cents,
                'earned_cents'     => $affiliate->total_earned_cents,
                'paid_cents'       => $affiliate->total_paid_cents,
            ],
            'recent_commissions' => $recentCommissions,
        ]);
    }

    /**
     * POST /affiliates/register — Apply to become an affiliate
     */
    public function register(Request $request): JsonResponse
    {
        $user = $request->user();

        // Check if already registered
        if (Affiliate::where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'Ya tienes una solicitud de afiliado registrada.',
            ], 422);
        }

        // Generate unique code
        $code = $this->generateUniqueCode();

        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'code'    => $code,
            'status'  => 'pending',
        ]);

        return response()->json([
            'message'   => 'Solicitud de afiliado enviada. El equipo de MiCopa la revisará pronto.',
            'affiliate' => $affiliate->load('user:id,name,email'),
        ], 201);
    }

    /**
     * PUT /affiliates/payment-info — Update payment info (CLABE, PayPal, etc.)
     */
    public function updatePaymentInfo(Request $request): JsonResponse
    {
        $affiliate = Affiliate::where('user_id', $request->user()->id)->first();

        if (!$affiliate) {
            return response()->json(['message' => 'No tienes un perfil de afiliado.'], 404);
        }

        $validated = $request->validate([
            'payment_info' => 'required|string|max:500',
        ]);

        $affiliate->update(['payment_info' => $validated['payment_info']]);

        return response()->json([
            'message'   => 'Información de pago actualizada.',
            'affiliate' => $affiliate->fresh(),
        ]);
    }

    /**
     * GET /affiliates/commissions — List commissions for this affiliate (paginated)
     */
    public function commissions(Request $request): JsonResponse
    {
        $affiliate = Affiliate::where('user_id', $request->user()->id)->first();

        if (!$affiliate) {
            return response()->json(['message' => 'No tienes un perfil de afiliado.'], 404);
        }

        $commissions = $affiliate->referrals()
            ->with('tenant:id,name,slug')
            ->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 20));

        return response()->json($commissions);
    }

    /**
     * POST /affiliates/request-payout — Request manual payout (min $500 MXN = 50000 cents)
     */
    public function requestPayout(Request $request): JsonResponse
    {
        $affiliate = Affiliate::where('user_id', $request->user()->id)->first();

        if (!$affiliate) {
            return response()->json(['message' => 'No tienes un perfil de afiliado.'], 404);
        }

        if (!$affiliate->isActive()) {
            return response()->json(['message' => 'Tu cuenta de afiliado no está activa.'], 422);
        }

        $minimumCents = 50000; // $500 MXN

        if ($affiliate->pending_payout_cents < $minimumCents) {
            $needed = number_format(($minimumCents - $affiliate->pending_payout_cents) / 100, 2);
            return response()->json([
                'message' => "El mínimo de retiro es $500 MXN. Te faltan $$needed MXN para alcanzarlo.",
            ], 422);
        }

        if (empty($affiliate->payment_info)) {
            return response()->json([
                'message' => 'Debes configurar tu información de pago antes de solicitar un retiro.',
            ], 422);
        }

        // Mark all confirmed commissions for this affiliate as requested
        $affiliate->referrals()
            ->where('status', 'pending')
            ->update(['status' => 'confirmed']);

        return response()->json([
            'message'            => 'Solicitud de retiro enviada. El equipo de MiCopa procesará tu pago en los próximos días hábiles.',
            'pending_payout'     => number_format($affiliate->pending_payout_cents / 100, 2),
            'payment_info'       => $affiliate->payment_info,
        ]);
    }

    // --- Private helpers ---

    private function generateUniqueCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (Affiliate::where('code', $code)->exists());

        return $code;
    }
}
