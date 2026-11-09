<?php

declare(strict_types=1);

namespace App\Livewire\Subscription;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Store;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Modules\Subscription\Services\EntitlementEnforcementService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
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

        $plan = Plan::findOrFail($this->selectedPlanId);
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
            'storeUsage' => ['used' => $currentStores, 'limit' => $maxStores],
            'deviceUsage' => ['used' => $currentDevices, 'limit' => $maxDevices],
            'userUsage' => ['used' => $currentUsers, 'limit' => $maxUsers],
            'allPlans' => $allPlans,
        ]);
    }
}
