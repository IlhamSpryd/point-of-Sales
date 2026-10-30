<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Modules\Pricing\DataTransferObjects\MarginSimulationResultDto;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Pricing\Services\MarginSimulationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class MarginSimulationModal extends Component
{
    public bool $isOpen = false;
    public ?int $campaignId = null;
    public ?MarginSimulationResultDto $simulation = null;
    public bool $canPublish = false;

    #[On('open-margin-simulation')]
    public function open(int $campaignId): void
    {
        $this->campaignId = $campaignId;
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

        $campaign = PromotionCampaign::with(['rules.rewards'])->find($this->campaignId);
        if (! $campaign) {
            $this->close();
            return;
        }

        $service = app(MarginSimulationService::class);
        $this->simulation = $service->simulate($campaign);
        $this->canPublish = Auth::user() ? $service->canPublish($campaign, Auth::user()) : false;
    }

    public function render(): View
    {
        return view('livewire.pricing.margin-simulation-modal');
    }
}
