<?php

declare(strict_types=1);

namespace App\Livewire\Finance;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Store;
use App\Models\User;
use App\Modules\Finance\Enums\DepreciationMethodEnum;
use App\Modules\Finance\Enums\FixedAssetStatusEnum;
use App\Modules\Finance\Models\FixedAsset;
use App\Modules\Finance\Models\FixedAssetCategory;
use App\Modules\Finance\Services\FixedAssetService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class FixedAssetManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $statusFilter = '';

    // Modal Create Asset State
    public bool $showCreateModal = false;
    public ?int $categoryId = null;
    public ?int $storeId = null;
    public string $assetCode = '';
    public string $assetName = '';
    public string $acquisitionDate = '';
    public string $acquisitionCost = '';
    public string $salvageValue = '0.00';
    public int $usefulLifeMonths = 48;
    public string $depreciationMethod = 'STRAIGHT_LINE';
    public string $locationNote = '';

    // Detail / Schedule Modal State
    public bool $showDetailModal = false;
    public ?int $selectedAssetId = null;

    public function mount(): void
    {
        $this->acquisitionDate = now()->toDateString();
        $this->storeId = app(TenantContext::class)->getStoreId();
    }

    public function openCreateModal(): void
    {
        $this->resetValidation();
        $this->assetCode = 'FA-' . strtoupper(substr(uniqid(), -6));
        $this->assetName = '';
        $this->acquisitionCost = '';
        $this->salvageValue = '0.00';
        $this->usefulLifeMonths = 48;
        $this->locationNote = '';
        $this->showCreateModal = true;
    }

    public function saveAsset(FixedAssetService $service): void
    {
        $this->validate([
            'categoryId' => ['required', 'integer'],
            'assetCode' => ['required', 'string', 'max:32'],
            'assetName' => ['required', 'string', 'max:128'],
            'acquisitionDate' => ['required', 'date'],
            'acquisitionCost' => ['required', 'numeric', 'gt:0'],
            'salvageValue' => ['required', 'numeric', 'gte:0'],
            'usefulLifeMonths' => ['required', 'integer', 'gt:0'],
            'locationNote' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $service->createAsset([
                'category_id' => $this->categoryId,
                'store_id' => $this->storeId,
                'asset_code' => $this->assetCode,
                'asset_name' => $this->assetName,
                'acquisition_date' => $this->acquisitionDate,
                'acquisition_cost' => $this->acquisitionCost,
                'salvage_value' => $this->salvageValue,
                'useful_life_months' => $this->usefulLifeMonths,
                'depreciation_method' => DepreciationMethodEnum::from($this->depreciationMethod),
                'location_note' => $this->locationNote,
            ], $actor);

            $this->showCreateModal = false;
            session()->flash('success', "Aset tetap '{$this->assetName}' berhasil didaftarkan.");
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function activateAsset(int $assetId, FixedAssetService $service): void
    {
        /** @var User $actor */
        $actor = Auth::user();
        $tenantId = (int) $actor->tenant_id;

        $asset = FixedAsset::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $assetId)
            ->firstOrFail();

        try {
            $service->activateAsset($asset, $actor);
            session()->flash('success', "Aset '{$asset->asset_name}' berhasil diaktifkan dan jadwal depresiasi telah digenerate.");
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function viewDetails(int $assetId): void
    {
        $this->selectedAssetId = $assetId;
        $this->showDetailModal = true;
    }

    public function render(): View
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? (int) Auth::user()->tenant_id;

        $query = FixedAsset::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with(['category', 'store']);

        if ($this->search !== '') {
            $s = trim($this->search);
            $query->where(function ($q) use ($s) {
                $q->where('asset_code', 'like', "%{$s}%")
                    ->orWhere('asset_name', 'like', "%{$s}%");
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        $assets = $query->latest('id')->paginate(15);
        $categories = FixedAssetCategory::withoutTenantScope()->where('tenant_id', $tenantId)->get();
        $stores = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();

        $selectedAsset = $this->selectedAssetId
            ? FixedAsset::withoutTenantScope()->where('tenant_id', $tenantId)->with(['schedules', 'category'])->find($this->selectedAssetId)
            : null;

        return view('livewire.finance.fixed-asset-manager', [
            'assets' => $assets,
            'categories' => $categories,
            'stores' => $stores,
            'selectedAsset' => $selectedAsset,
        ]);
    }
}
