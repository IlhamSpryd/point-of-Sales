<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Customers\Enums\VoucherDiscountTypeEnum;
use App\Modules\Customers\Enums\VoucherStatusEnum;
use App\Modules\Customers\Models\Voucher;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class VoucherManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'ALL';

    // Modal state
    public bool $isFormModalOpen = false;
    public ?int $editingVoucherId = null;

    public string $code = '';
    public string $name = '';
    public string $discountType = 'PERCENTAGE';
    public float $discountValue = 10.0;
    public float $minOrderAmount = 0.0;
    public ?float $maxDiscountAmount = null;
    public int $maxUses = 100;
    public int $maxUsesPerCustomer = 1;
    public string $startsAt = '';
    public string $expiresAt = '';
    public string $status = 'ACTIVE';

    protected function rules(): array
    {
        return [
            'code' => 'required|string|max:64',
            'name' => 'required|string|max:150',
            'discountType' => 'required|string|in:PERCENTAGE,FIXED_AMOUNT',
            'discountValue' => 'required|numeric|min:0.01',
            'minOrderAmount' => 'required|numeric|min:0',
            'maxUses' => 'required|integer|min:0',
            'maxUsesPerCustomer' => 'required|integer|min:1',
            'startsAt' => 'required|date',
            'expiresAt' => 'required|date|after_or_equal:startsAt',
            'status' => 'required|string|in:ACTIVE,INACTIVE,EXPIRED',
        ];
    }

    public function mount(): void
    {
        $this->bootRequiresTenantContext();
        $this->startsAt = now()->format('Y-m-d\TH:i');
        $this->expiresAt = now()->addDays(30)->format('Y-m-d\TH:i');
    }

    public function generateCode(): void
    {
        $this->code = 'VCR-' . strtoupper(Str::random(6));
    }

    public function openCreateModal(): void
    {
        $this->resetValidation();
        $this->editingVoucherId = null;
        $this->generateCode();
        $this->name = '';
        $this->discountType = 'PERCENTAGE';
        $this->discountValue = 10.0;
        $this->minOrderAmount = 0.0;
        $this->maxDiscountAmount = null;
        $this->maxUses = 100;
        $this->maxUsesPerCustomer = 1;
        $this->startsAt = now()->format('Y-m-d\TH:i');
        $this->expiresAt = now()->addDays(30)->format('Y-m-d\TH:i');
        $this->status = 'ACTIVE';

        $this->isFormModalOpen = true;
    }

    public function openEditModal(int $id): void
    {
        $this->resetValidation();
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $voucher = Voucher::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $this->editingVoucherId = $voucher->id;
        $this->code = $voucher->code;
        $this->name = $voucher->name;
        $this->discountType = $voucher->discount_type instanceof VoucherDiscountTypeEnum
            ? $voucher->discount_type->value
            : (string) $voucher->discount_type;
        $this->discountValue = (float) $voucher->discount_value;
        $this->minOrderAmount = (float) $voucher->min_order_amount;
        $this->maxDiscountAmount = $voucher->max_discount_amount ? (float) $voucher->max_discount_amount : null;
        $this->maxUses = (int) $voucher->max_uses;
        $this->maxUsesPerCustomer = (int) $voucher->max_uses_per_customer;
        $this->startsAt = $voucher->starts_at ? Carbon::parse($voucher->starts_at)->format('Y-m-d\TH:i') : now()->format('Y-m-d\TH:i');
        $this->expiresAt = $voucher->expires_at ? Carbon::parse($voucher->expires_at)->format('Y-m-d\TH:i') : now()->addDays(30)->format('Y-m-d\TH:i');
        $this->status = $voucher->status instanceof VoucherStatusEnum ? $voucher->status->value : (string) $voucher->status;

        $this->isFormModalOpen = true;
    }

    public function save(): void
    {
        $this->validate();
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $data = [
            'tenant_id' => $tenantId,
            'code' => strtoupper(trim($this->code)),
            'name' => trim($this->name),
            'discount_type' => VoucherDiscountTypeEnum::from($this->discountType),
            'discount_value' => (string) $this->discountValue,
            'min_order_amount' => (string) $this->minOrderAmount,
            'max_discount_amount' => $this->maxDiscountAmount !== null ? (string) $this->maxDiscountAmount : null,
            'max_uses' => $this->maxUses,
            'max_uses_per_customer' => $this->maxUsesPerCustomer,
            'starts_at' => Carbon::parse($this->startsAt),
            'expires_at' => Carbon::parse($this->expiresAt),
            'status' => VoucherStatusEnum::from($this->status),
        ];

        if ($this->editingVoucherId) {
            $voucher = Voucher::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $this->editingVoucherId)
                ->firstOrFail();
            $voucher->update($data);
        } else {
            Voucher::create($data);
        }

        $this->isFormModalOpen = false;
        session()->flash('success', 'Voucher berhasil disimpan.');
    }

    public function toggleStatus(int $id): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $voucher = Voucher::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $newStatus = $voucher->status === VoucherStatusEnum::ACTIVE
            ? VoucherStatusEnum::INACTIVE
            : VoucherStatusEnum::ACTIVE;

        $voucher->status = $newStatus;
        $voucher->save();

        session()->flash('success', "Status voucher '{$voucher->code}' diubah menjadi {$newStatus->value}.");
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $query = Voucher::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('id', 'desc');

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('code', 'like', "%{$this->search}%")
                    ->orWhere('name', 'like', "%{$this->search}%");
            });
        }

        if ($this->statusFilter !== 'ALL') {
            $query->where('status', $this->statusFilter);
        }

        $vouchers = $query->paginate(10);

        return view('livewire.pricing.voucher-manager', [
            'vouchers' => $vouchers,
        ])->layout('layouts.app', ['header' => 'Manajemen Kupon & Voucher Belanja']);
    }
}
