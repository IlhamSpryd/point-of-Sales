<?php

declare(strict_types=1);

namespace App\Livewire\Subscription;

use App\Core\Context\TenantContext;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Organization\Models\Device;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\User;
use App\Modules\Subscription\Application\Services\SubscriptionBillingOrchestrator;
use App\Modules\Subscription\Contracts\EntitlementGateInterface;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\ProvisioningRequest;
use App\Modules\Subscription\Models\TenantSubscription;
use App\Modules\Subscription\Services\EntitlementEnforcementService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class SubscriptionPortal extends Component
{
    use RequiresTenantContext;

    public bool $isUpgradeModalOpen = false;

    public ?int $selectedPlanId = null;

    public string $billingCycle = 'monthly';

    public ?string $pendingPaymentOrderId = null;

    public ?string $pendingPaymentToken = null;

    public function boot(): void
    {
        $this->authorize('subscription.manage');
    }

    /**
     * Legacy manual sales contact request handler (preserved for backward compatibility).
     */
    public function requestPlanUpgrade(): void
    {
        $this->authorize('subscription.manage');

        $this->validate([
            'selectedPlanId' => 'required|integer|exists:plans,id',
            'billingCycle' => 'required|in:monthly,annual',
        ]);

        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $plan = Plan::findOrFail($this->selectedPlanId);
        $user = auth()->user();

        $idempotencyKey = 'upgrade:'.$tenantId.':'.$plan->id.':'.now()->timestamp.':'.Str::random(6);
        $fingerprint = hash('sha256', 'upgrade|'.$tenantId.'|'.$plan->id.'|'.$this->billingCycle.'|'.($user->email ?? 'unknown'));

        ProvisioningRequest::create([
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $fingerprint,
            'owner_email' => $user->email ?? "tenant-{$tenantId}@domain.internal",
            'status' => 'pending',
            'result_tenant_id' => $tenantId,
            'result_user_id' => $user?->id,
            'plan_id' => $plan->id,
            'purchased_billing_cycle' => $this->billingCycle,
            'notes' => "Permintaan upgrade paket ke {$plan->name} dari Subscription Portal",
            'correlation_id' => (string) Str::uuid(),
            'started_at' => now(),
            'attempt_count' => 0,
        ]);

        $this->isUpgradeModalOpen = false;

        session()->flash('message', "Permintaan upgrade ke paket {$plan->name} telah dicatat. Tim representatif kami akan menghubungi Anda untuk konfirmasi penagihan.");
    }

    /**
     * Initiates a Midtrans Snap checkout session without activating entitlements.
     * Activation occurs exclusively via verified Midtrans webhook settlement or Transaction::status() reconciliation.
     */
    public function initiatePaymentUpgrade(SubscriptionBillingOrchestrator $orchestrator): void
    {
        $this->authorize('subscription.manage');

        $this->validate([
            'selectedPlanId' => 'required|integer|exists:plans,id',
            'billingCycle' => 'required|in:monthly,annual',
        ]);

        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $targetPlan = Plan::findOrFail($this->selectedPlanId);

        $upgradeOrder = $orchestrator->initiateUpgrade($tenantId, (int) $targetPlan->id, $this->billingCycle);

        $this->pendingPaymentOrderId = $upgradeOrder['order_id'];
        $this->pendingPaymentToken = $upgradeOrder['payment_token'];
        $this->isUpgradeModalOpen = false;

        $this->dispatch('subscription-payment-initiated', [
            'orderId' => $upgradeOrder['order_id'],
            'paymentToken' => $upgradeOrder['payment_token'],
            'amountFormatted' => $upgradeOrder['amount_formatted'],
            'planName' => $targetPlan->name,
            'billingCycle' => $upgradeOrder['billing_cycle'],
        ]);

        session()->flash('message', "Sesi pembayaran #{$upgradeOrder['order_id']} ({$upgradeOrder['amount_formatted']}) telah dibuat. Selesaikan pembayaran di Midtrans untuk mengaktifkan paket {$targetPlan->name}.");
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $gate = app(EntitlementGateInterface::class);

        $activePlan = $gate->resolveActivePlanForTenant($tenantId);
        $subscription = TenantSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->first();

        $pendingUpgradeRequest = ProvisioningRequest::where('result_tenant_id', $tenantId)
            ->where('status', 'pending')
            ->whereNotNull('plan_id')
            ->with('plan')
            ->latest('id')
            ->first();

        // Quotas & Current Usages
        $maxStores = $gate->getQuota($tenantId, EntitlementEnforcementService::QUOTA_MAX_STORES);
        $currentStores = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $maxDevices = $gate->getQuota($tenantId, EntitlementEnforcementService::QUOTA_MAX_DEVICES);
        $currentDevices = Device::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $maxUsers = $gate->getQuota($tenantId, EntitlementEnforcementService::QUOTA_MAX_USERS);
        $currentUsers = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $allPlans = Plan::where('is_active', true)->orderBy('price')->get();

        return view('livewire.subscription.subscription-portal', [
            'activePlan' => $activePlan,
            'subscription' => $subscription,
            'pendingUpgradeRequest' => $pendingUpgradeRequest,
            'storeUsage' => ['used' => $currentStores, 'limit' => $maxStores],
            'deviceUsage' => ['used' => $currentDevices, 'limit' => $maxDevices],
            'userUsage' => ['used' => $currentUsers, 'limit' => $maxUsers],
            'allPlans' => $allPlans,
        ]);
    }
}
