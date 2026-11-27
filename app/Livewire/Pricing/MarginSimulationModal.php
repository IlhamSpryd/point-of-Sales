<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Core\Context\TenantContext;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Pricing\DataTransferObjects\MarginSimulationResultDto;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Pricing\Services\MarginSimulationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class MarginSimulationModal extends Component
{
    use RequiresTenantContext;

    public bool $isOpen = false;

    #[Locked]
    public ?int $campaignId = null;

    public ?MarginSimulationResultDto $simulation = null;

    public bool $canPublish = false;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();
        $this->authorize('viewAny', PromotionCampaign::class);
    }

    #[On('open-margin-simulation')]
    public function open(int $campaignId): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $campaign = PromotionCampaign::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($campaignId);

        $this->authorize('view', $campaign);

        $this->campaignId = (int) $campaign->id;
        $this->isOpen = true;
        $this->runSimulation();
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->campaignId = null;
        $this->simulation = null;
    }

    public function runSimulation(): void
    {
        if (! $this->campaignId) {
            return;
        }

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $campaign = PromotionCampaign::with(['rules.rewards'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->campaignId);

        $this->authorize('view', $campaign);

        $service = app(MarginSimulationService::class);
        $this->simulation = $service->simulate($campaign);
        $this->canPublish = Auth::user() ? $service->canPublish($campaign, Auth::user()) : false;
    }

    public function render(): View
    {
        return view('livewire.pricing.margin-simulation-modal');
    }
}
