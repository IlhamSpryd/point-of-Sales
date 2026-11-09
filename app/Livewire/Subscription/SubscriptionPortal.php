<?php

declare(strict_types=1);

namespace App\Livewire\Subscription;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Store;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Modules\Settings\Models\ProvisioningRequest;
use App\Modules\Subscription\Services\EntitlementEnforcementService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class SubscriptionPortal extends Component
{
    use RequiresTenantContext;

    public bool $isUpgradeModalOpen = false;

    public ?int $selectedPlanId = null;

    public function requestPlanUpgrade(): void
    {
        $this->validate([
            'selectedPlanId' => 'required|integer|exists:plans,id',
        ]);

        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $plan = Plan::findOrFail($this->selectedPlanId);
        $user = auth()->user();

        $idempotencyKey = 'upgrade:'.$tenantId.':'.$plan->id.':'.now()->timestamp.':'.Str::random(6);
        $fingerprint = hash('sha256', 'upgrade|'.$tenantId.'|'.$plan->id.'|'.($user?->email ?? 'unknown'));

        ProvisioningRequest::create([
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $fingerprint,
            'owner_email' => $user?->email ?? "tenant-{$tenantId}@domain.internal",
            'status' => 'pending',
            'result_tenant_id' => $tenantId,
            'result_user_id' => $user?->id,
            'plan_id' => $plan->id,
            'notes' => "Permintaan upgrade paket ke {$plan->name} dari Subscription Portal",
            'correlation_id' => (string) Str::uuid(),
            'started_at' => now(),
            'attempt_count' => 0,
        ]);

        $this->isUpgradeModalOpen = false;

        session()->flash('message', "Permintaan upgrade ke paket {$plan->name} telah dicatat. Tim representatif kami akan menghubungi Anda untuk konfirmasi penagihan.");
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $service = app(EntitlementEnforcementService::class);

        $activePlan = $service->resolveActivePlanForTenant($tenantId);
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
        $maxStores = $service->getQuota($tenantId, EntitlementEnforcementService::QUOTA_MAX_STORES);
        $currentStores = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $maxDevices = $service->getQuota($tenantId, EntitlementEnforcementService::QUOTA_MAX_DEVICES);
        $currentDevices = Device::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $maxUsers = $service->getQuota($tenantId, EntitlementEnforcementService::QUOTA_MAX_USERS);
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
