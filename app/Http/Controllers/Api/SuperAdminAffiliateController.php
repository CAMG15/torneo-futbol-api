<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateReferral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminAffiliateController extends Controller
{
    /**
     * GET /superadmin/affiliates — List all affiliates with user info and stats
     */
    public function index(Request $request): JsonResponse
    {
        $query = Affiliate::with('user:id,name,email')
            ->withCount('referrals');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $affiliates = $query->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 20));

        return response()->json($affiliates);
    }
    /**
     * PUT /superadmin/affiliates/{affiliate} — Update status, commission_rate, notes
     */
    public function update(Request $request, Affiliate $affiliate): JsonResponse
    {
        $validated = $request->validate([
            'status'          => 'sometimes|in:active,pending,suspended,rejected',
            'commission_rate' => 'sometimes|numeric|min:0.01|max:0.30',
            'notes'           => 'sometimes|nullable|string|max:1000',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'active' && $affiliate->status !== 'active') {
            $validated['approved_at'] = now();
        }

        $affiliate->update($validated);

        return response()->json($affiliate->fresh()->load('user:id,name,email'));
    }


    /**
     * PUT /superadmin/affiliates/{affiliate}/approve — Approve affiliate
     */
    public function approve(Affiliate $affiliate): JsonResponse
    {
        if ($affiliate->status === 'active') {
            return response()->json(['message' => 'Este afiliado ya está activo.'], 422);
        }

        $affiliate->update([
            'status'      => 'active',
            'approved_at' => now(),
        ]);

        return response()->json([
            'message'   => 'Afiliado aprobado correctamente.',
            'affiliate' => $affiliate->fresh()->load('user:id,name,email'),
        ]);
    }

    /**
     * PUT /superadmin/affiliates/{affiliate}/suspend — Suspend affiliate
     */
    public function suspend(Affiliate $affiliate): JsonResponse
    {
        $affiliate->update(['status' => 'suspended']);

        return response()->json([
            'message'   => 'Afiliado suspendido.',
            'affiliate' => $affiliate->fresh()->load('user:id,name,email'),
        ]);
    }

    /**
     * GET /superadmin/affiliates/{affiliate}/commissions — List referrals for this affiliate
     */
    public function commissions(Request $request, Affiliate $affiliate): JsonResponse
    {
        $affiliate->load('user:id,name,email');

        $referrals = $affiliate->referrals()
            ->with(['tenant:id,name,slug', 'payment:id,amount_cents,currency,payment_provider,paid_at'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 20));

        return response()->json([
            'affiliate' => $affiliate,
            'referrals' => $referrals,
        ]);
    }

    /**
     * POST /superadmin/affiliates/{affiliate}/mark-paid
     * Mark all confirmed commissions as paid, reset pending_payout_cents, increment total_paid_cents
     */
    public function markPaid(Affiliate $affiliate): JsonResponse
    {
        $confirmedReferrals = $affiliate->referrals()
            ->where('status', 'confirmed')
            ->get();

        if ($confirmedReferrals->isEmpty()) {
            return response()->json([
                'message' => 'No hay comisiones confirmadas pendientes de pago para este afiliado.',
            ], 422);
        }

        $totalPaidCents = $confirmedReferrals->sum('commission_cents');

        DB::transaction(function () use ($affiliate, $confirmedReferrals, $totalPaidCents) {
            // Mark referrals as paid
            $affiliate->referrals()
                ->where('status', 'confirmed')
                ->update([
                    'status'  => 'paid',
                    'paid_at' => now(),
                ]);

            // Update affiliate balances
            $affiliate->decrement('pending_payout_cents', $totalPaidCents);
            $affiliate->increment('total_paid_cents', $totalPaidCents);
        });

        return response()->json([
            'message'          => 'Comisiones marcadas como pagadas.',
            'paid_count'       => $confirmedReferrals->count(),
            'paid_amount'      => number_format($totalPaidCents / 100, 2),
            'affiliate'        => $affiliate->fresh()->load('user:id,name,email'),
        ]);
    }
}
