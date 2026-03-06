<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireSubscriptions extends Command
{
    protected $signature   = 'subscriptions:expire';
    protected $description = 'Downgrade tenants with expired non-recurring subscriptions to free plan';

    public function handle(): int
    {
        $this->info('Checking for expired subscriptions...');

        // 1. Suscripciones manuales (auto_renew=false) cuyo período ya venció
        $expired = Subscription::where('status', 'active')
            ->where('auto_renew', false)
            ->where('current_period_end', '<', now())
            ->get();

        foreach ($expired as $subscription) {
            $subscription->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            Tenant::find($subscription->tenant_id)?->update(['plan' => 'free']);

            $this->line("Expired: subscription #{$subscription->id} → tenant #{$subscription->tenant_id} downgraded to free");
            Log::info('ExpireSubscriptions: manual subscription expired', [
                'subscription_id' => $subscription->id,
                'tenant_id'       => $subscription->tenant_id,
            ]);
        }

        // 2. Suscripciones recurrentes con pago fallido (past_due) por más de 7 días
        $pastDue = Subscription::where('status', 'past_due')
            ->where('current_period_end', '<', now()->subDays(7))
            ->get();

        foreach ($pastDue as $subscription) {
            $subscription->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
                'auto_renew'   => false,
            ]);
            Tenant::find($subscription->tenant_id)?->update(['plan' => 'free']);

            $this->line("Past due: subscription #{$subscription->id} → tenant #{$subscription->tenant_id} downgraded to free");
            Log::info('ExpireSubscriptions: past_due subscription expired', [
                'subscription_id' => $subscription->id,
                'tenant_id'       => $subscription->tenant_id,
            ]);
        }

        $total = $expired->count() + $pastDue->count();
        $this->info("Done. {$total} subscription(s) expired.");

        return self::SUCCESS;
    }
}
