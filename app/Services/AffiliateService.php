<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\AffiliateReferral;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class AffiliateService
{
    /**
     * Process affiliate commission for an approved payment.
     * Should be called immediately after a Payment is saved with status 'approved'.
     */
    public function processPaymentCommission(Payment $payment): void
    {
        // Load tenant with subscription and plan if not already loaded
        $payment->loadMissing(['tenant.activeSubscription.plan']);

        $tenant = $payment->tenant;

        if (!$tenant) {
            Log::warning('AffiliateService: payment has no tenant', ['payment_id' => $payment->id]);
            return;
        }

        // Check tenant was referred
        if (empty($tenant->referred_by_code)) {
            return;
        }

        // Find the active affiliate with that code
        $affiliate = Affiliate::where('code', $tenant->referred_by_code)
            ->where('status', 'active')
            ->first();

        if (!$affiliate) {
            return;
        }

        // Payment must be approved
        if ($payment->status !== 'approved') {
            return;
        }

        // Payment amount must be > 0 (Free plan has no payment)
        if ($payment->amount_cents <= 0) {
            return;
        }

        // Check plan is Pro or Business (not free)
        // We check via the subscription associated to this payment
        $subscription = $payment->subscription;
        if ($subscription) {
            $plan = $subscription->plan;
            if ($plan && $plan->slug === 'free') {
                return;
            }
        } else {
            // No subscription linked yet — also check tenant's active subscription
            $activeSub = $tenant->activeSubscription;
            if ($activeSub) {
                $plan = $activeSub->plan;
                if ($plan && $plan->slug === 'free') {
                    return;
                }
            }
        }

        // Prevent duplicate commission for the same payment
        $exists = AffiliateReferral::where('payment_id', $payment->id)->exists();
        if ($exists) {
            Log::info('AffiliateService: commission already recorded for payment', ['payment_id' => $payment->id]);
            return;
        }

        // Calculate commission
        $commissionCents = (int) floor($payment->amount_cents * $affiliate->commission_rate / 100);

        // Create the referral/commission record
        AffiliateReferral::create([
            'affiliate_id'               => $affiliate->id,
            'tenant_id'                  => $tenant->id,
            'payment_id'                 => $payment->id,
            'subscription_payment_cents' => $payment->amount_cents,
            'commission_cents'           => $commissionCents,
            'status'                     => 'pending',
        ]);

        // Update affiliate totals
        $affiliate->increment('total_earned_cents', $commissionCents);
        $affiliate->increment('pending_payout_cents', $commissionCents);

        Log::info('AffiliateService: commission recorded', [
            'affiliate_id'    => $affiliate->id,
            'payment_id'      => $payment->id,
            'commission_cents'=> $commissionCents,
            'tenant_id'       => $tenant->id,
        ]);
    }
}
