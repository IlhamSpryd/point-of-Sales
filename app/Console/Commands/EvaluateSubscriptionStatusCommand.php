<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Organization\Models\Tenant;
use App\Modules\Subscription\Models\TenantSubscription;
use App\Modules\Subscription\Services\EntitlementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EvaluateSubscriptionStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'subscription:evaluate-status';

    /**
     * The console command description.
     */
    protected $description = 'Evaluasi status kedaluwarsa langganan tenant: active/trialing -> past_due (grace 3 hari) -> suspended';

    /**
     * Execute the console command.
     */
    public function handle(EntitlementService $entitlementService): int
    {
        $now = Carbon::now();
        $this->info("Menjalankan evaluasi status langganan pada {$now->toDateTimeString()}...");

        // Ambil seluruh langganan aktif/trialing/past_due yang telah melewati tanggal ends_at
        // @tenant-bypass: console_command
        $subscriptions = TenantSubscription::withoutGlobalScopes()
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIALING, 'past_due'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->get();

        $transitionedCount = 0;

        foreach ($subscriptions as $subscription) {
            $tenantId = (int) $subscription->tenant_id;
            $endsAt = Carbon::parse($subscription->ends_at);
            $graceCutoff = $endsAt->copy()->addDays(3);

            DB::transaction(function () use ($subscription, $tenantId, $now, $graceCutoff, $entitlementService, &$transitionedCount) {
                // @tenant-bypass: console_command
                $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
                if ($tenant === null) {
                    return;
                }

                if ($now->lte($graceCutoff)) {
                    // Masuk Masa Tenggang (Grace Period 3 Hari: past_due)
                    if ($subscription->status !== 'past_due') {
                        $subscription->update([
                            'status' => 'past_due',
                            'notes' => 'Langganan melewati batas akhir; masa tenggang 3 hari aktif.',
                        ]);

                        $tenant->update([
                            'subscription_status' => 'past_due',
                        ]);

                        $entitlementService->clearCache($tenantId);
                        $transitionedCount++;

                        Log::info("[SUBSCRIPTION EVALUATION] Tenant {$tenantId} dialihkan ke status past_due (Grace Period).");
                    }
                } else {
                    // Melewati Masa Tenggang: Ditangguhkan (Suspended)
                    if ($subscription->status !== 'suspended') {
                        $subscription->update([
                            'status' => 'suspended',
                            'notes' => 'Masa tenggang 3 hari berakhir; langganan ditangguhkan.',
                        ]);

                        $tenant->update([
                            'subscription_status' => 'suspended',
                            'access_status' => Tenant::ACCESS_SUSPENDED,
                            'status' => 'suspended',
                        ]);

                        $entitlementService->clearCache($tenantId);
                        $transitionedCount++;

                        Log::warning("[SUBSCRIPTION EVALUATION] Tenant {$tenantId} ditangguhkan (Suspended) karena tagihan tertunggak.");
                    }
                }
            });
        }

        $this->info("Evaluasi selesai. {$transitionedCount} status langganan tenant diperbarui.");

        return Command::SUCCESS;
    }
}
