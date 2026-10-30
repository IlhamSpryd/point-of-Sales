<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Pricing\Models\PromotionRule;
use App\Modules\Pricing\Models\PromotionReward;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class PromotionCampaignManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'ALL';
    public string $typeFilter = 'ALL';

    // Form Modal State
    public bool $isFormModalOpen = false;
    public ?int $editingCampaignId = null;

    public string $campaignCode = '';
    public string $name = '';
    public string $description = '';
    public string $type = 'AUTOMATIC_DISCOUNT';
    public string $startDatetime = '';
    public string $endDatetime = '';
    public array $allowedDays = [];
    public ?string $startTime = null;
    public ?string $endTime = null;
    public int $priority = 10;
    public ?int $maxUsageLimit = null;
    public int $maxUsagePerCustomer = 1;
    public bool $isStackable = false;
    public string $status = 'ACTIVE';

    // Simplified rule & reward fields for quick creation
    public string $rewardType = 'PERCENTAGE_DISCOUNT';
    public ?float $discountPercentage = 10.0;
    public ?float $discountFixedAmount = null;
    public ?float $maxDiscountAmount = null;
    public float $minOrderAmount = 0.0;

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'campaignCode' => 'required|string|max:64',
            'type' => 'required|string|in:AUTOMATIC_DISCOUNT,BUY_X_GET_Y,BUNDLE_PACKAGE,HAPPY_HOUR',
            'startDatetime' => 'required|date',
            'endDatetime' => 'required|date|after_or_equal:startDatetime',
            'priority' => 'required|integer|min:1|max:100',
            'status' => 'required|string|in:DRAFT,SCHEDULED,ACTIVE,PAUSED,EXPIRED,ARCHIVED',
        ];
    }

    public function mount(): void
    {
        $this->bootRequiresTenantContext();
        $this->startDatetime = now()->format('Y-m-d\TH:i');
        $this->endDatetime = now()->addMonth()->format('Y-m-d\TH:i');
    }

    public function openCreateModal(): void
    {
        $this->resetValidation();
        $this->editingCampaignId = null;
        $this->campaignCode = 'PROMO-' . strtoupper(Str::random(6));
        $this->name = '';
        $this->description = '';
        $this->type = 'AUTOMATIC_DISCOUNT';
        $this->startDatetime = now()->format('Y-m-d\TH:i');
        $this->endDatetime = now()->addMonth()->format('Y-m-d\TH:i');
        $this->allowedDays = ['1', '2', '3', '4', '5', '6', '7'];
        $this->startTime = null;
        $this->endTime = null;
        $this->priority = 10;
        $this->maxUsageLimit = null;
        $this->maxUsagePerCustomer = 1;
        $this->isStackable = false;
        $this->status = 'ACTIVE';
        $this->rewardType = 'PERCENTAGE_DISCOUNT';
        $this->discountPercentage = 10.0;
        $this->discountFixedAmount = null;
        $this->maxDiscountAmount = null;
        $this->minOrderAmount = 0.0;

        $this->isFormModalOpen = true;
    }

    public function openEditModal(int $id): void
    {
        $this->resetValidation();
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $campaign = PromotionCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with(['rules.rewards'])
            ->firstOrFail();

        $this->editingCampaignId = $campaign->id;
        $this->campaignCode = $campaign->campaign_code;
        $this->name = $campaign->name;
        $this->description = (string) $campaign->description;
        $this->type = $campaign->type;
        $this->startDatetime = Carbon::parse($campaign->start_datetime)->format('Y-m-d\TH:i');
        $this->endDatetime = Carbon::parse($campaign->end_datetime)->format('Y-m-d\TH:i');
        $this->allowedDays = $campaign->allowed_days ?? [];
        $this->startTime = $campaign->start_time;
        $this->endTime = $campaign->end_time;
        $this->priority = (int) $campaign->priority;
        $this->maxUsageLimit = $campaign->max_usage_limit;
        $this->maxUsagePerCustomer = (int) ($campaign->max_usage_per_customer ?? 1);
        $this->isStackable = (bool) $campaign->is_stackable;
        $this->status = $campaign->status;

        $firstRule = $campaign->rules->first();
        if ($firstRule) {
            $this->minOrderAmount = (float) $firstRule->min_order_amount;
            $firstReward = $firstRule->rewards->first();
            if ($firstReward) {
                $this->rewardType = $firstReward->reward_type;
                $this->discountPercentage = $firstReward->discount_percentage ? (float) $firstReward->discount_percentage : null;
                $this->discountFixedAmount = $firstReward->discount_fixed_amount ? (float) $firstReward->discount_fixed_amount : null;
                $this->maxDiscountAmount = $firstReward->max_discount_amount ? (float) $firstReward->max_discount_amount : null;
            }
        }

        $this->isFormModalOpen = true;
    }

    public function save(): void
    {
        $this->validate();
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $storeId = app(TenantContext::class)->getStoreId();
        $userId = Auth::id();

        DB::transaction(function () use ($tenantId, $storeId, $userId) {
            $data = [
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'campaign_code' => strtoupper(trim($this->campaignCode)),
                'name' => trim($this->name),
                'description' => trim($this->description),
                'type' => $this->type,
                'start_datetime' => Carbon::parse($this->startDatetime),
                'end_datetime' => Carbon::parse($this->endDatetime),
                'allowed_days' => !empty($this->allowedDays) ? $this->allowedDays : null,
                'start_time' => $this->type === 'HAPPY_HOUR' ? $this->startTime : null,
                'end_time' => $this->type === 'HAPPY_HOUR' ? $this->endTime : null,
                'priority' => $this->priority,
                'max_usage_limit' => $this->maxUsageLimit,
                'max_usage_per_customer' => $this->maxUsagePerCustomer,
                'is_stackable' => $this->isStackable,
                'status' => $this->status,
                'created_by_user_id' => $userId,
            ];

            if ($this->editingCampaignId) {
                $campaign = PromotionCampaign::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $this->editingCampaignId)
                    ->firstOrFail();
                $campaign->update($data);
            } else {
                $campaign = PromotionCampaign::create($data);

                // Buat rule dan reward default
                $rule = PromotionRule::create([
                    'tenant_id' => $tenantId,
                    'campaign_id' => $campaign->id,
                    'rule_type' => 'CART_TOTAL',
                    'min_order_amount' => $this->minOrderAmount,
                    'min_order_qty' => 1,
                ]);

                PromotionReward::create([
                    'tenant_id' => $tenantId,
                    'rule_id' => $rule->id,
                    'reward_type' => $this->rewardType,
                    'discount_percentage' => $this->discountPercentage,
                    'discount_fixed_amount' => $this->discountFixedAmount,
                    'max_discount_amount' => $this->maxDiscountAmount,
                ]);
            }
        });

        $this->isFormModalOpen = false;
        session()->flash('success', 'Kampanye promosi berhasil disimpan.');
    }

    public function toggleStatus(int $id): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $campaign = PromotionCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $campaign->status = $campaign->status === 'ACTIVE' ? 'PAUSED' : 'ACTIVE';
        $campaign->save();

        session()->flash('success', "Status promosi '{$campaign->name}' diubah menjadi {$campaign->status}.");
    }

    public function triggerMarginSimulation(int $id): void
    {
        $this->dispatch('openMarginSimulation', campaignId: $id);
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $query = PromotionCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->withCount(['rules'])
            ->orderBy('priority', 'desc')
            ->orderBy('id', 'desc');

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('campaign_code', 'like', "%{$this->search}%");
            });
        }

        if ($this->statusFilter !== 'ALL') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->typeFilter !== 'ALL') {
            $query->where('type', $this->typeFilter);
        }

        $campaigns = $query->paginate(10);

        return view('livewire.pricing.promotion-campaign-manager', [
            'campaigns' => $campaigns,
        ])->layout('layouts.app', ['header' => 'Manajemen Kampanye Promosi']);
    }
}
