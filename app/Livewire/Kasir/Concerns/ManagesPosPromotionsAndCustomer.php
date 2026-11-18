<?php

declare(strict_types=1);

namespace App\Livewire\Kasir\Concerns;

use App\Core\Context\TenantContext;
use App\Modules\Customers\Models\Customer;
use App\Modules\Organization\Models\Approval;
use App\Modules\Pricing\Enums\StoreCreditStatusEnum;
use App\Modules\Pricing\Models\Discount;
use App\Modules\Pricing\Models\StoreCreditAccount;
use App\Modules\Pricing\Services\DiscountApprovalService;
use App\Modules\Pricing\Services\VoucherService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Manages customer selection, loyalty credit lookups, voucher lifecycle,
 * manual discount overrides, and promotions for POS terminals (ADR-108).
 */
trait ManagesPosPromotionsAndCustomer
{
    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public ?string $customerName = null;

    public string $customerSearch = '';

    public ?int $selectedDiscountId = null;

    public array $itemDiscounts = [];

    public bool $isManualDiscountModalOpen = false;

    public int $manualDiscountAmount = 0;

    public $manualDiscountAmountInput = null;

    public string $manualDiscountReason = '';

    public int $appliedManualDiscount = 0;

    public string $appliedManualDiscountReason = '';

    public ?int $manualDiscountApprovalId = null;

    public ?string $voucherCode = null;

    public string $voucherInput = '';

    public ?string $voucherError = null;

    #[Computed]
    public function customerResults()
    {
        $q = trim($this->customerSearch);

        if (mb_strlen($q) < 2) {
            return collect();
        }

        return Customer::query()
            ->where('is_active', true)
            ->where(fn ($qq) => $qq->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'phone']);
    }

    public function selectCustomer(int $id, string $name): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $customerExists = Customer::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->where('is_active', true)
            ->exists();

        if (! $customerExists) {
            $this->storeCreditBalance = 0.0;

            return;
        }

        $this->customerId = $id;
        $this->customerName = $name;
        $this->customerSearch = '';

        $sc = StoreCreditAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $id)
            ->where('status', StoreCreditStatusEnum::ACTIVE)
            ->first();

        $this->storeCreditBalance = $sc ? (float) $sc->current_balance : 0.0;
    }

    public function clearCustomer(): void
    {
        $this->customerId = null;
        $this->customerName = null;
        $this->storeCreditBalance = 0.0;
    }

    public function applyVoucher(): void
    {
        $this->voucherError = null;
        $code = strtoupper(trim($this->voucherInput));
        if (empty($code)) {
            $this->voucherError = 'Voucher code cannot be empty.';

            return;
        }

        try {
            $customer = $this->customerId ? Customer::find($this->customerId) : null;
            $subtotal = (string) collect($this->cart)->sum(fn ($i) => (float) ($i['total_price'] ?? ($i['price'] ?? 0) * ($i['qty'] ?? 1)));
            $validation = app(VoucherService::class)->validateVoucher($code, $customer, $subtotal);

            app(VoucherService::class)->reserveVoucher(
                code: $code,
                cartUuid: $this->idempotencyKey,
                storeId: (int) ($this->activeStoreId ?? 1),
                customerId: $this->customerId ? (int) $this->customerId : null,
                reservedAmount: (float) $validation['discount_amount']
            );

            $this->voucherCode = $code;
            $this->voucherInput = '';
            session()->flash('success', "Voucher '{$code}' applied successfully.");
        } catch (\Throwable $e) {
            $this->voucherError = $e->getMessage();
        }
    }

    public function removeVoucher(): void
    {
        if ($this->voucherCode) {
            app(VoucherService::class)->releaseVoucher($this->idempotencyKey);
            $this->voucherCode = null;
            $this->voucherError = null;
        }
    }

    #[Computed]
    public function activeDiscounts()
    {
        return Discount::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->get();
    }

    #[Computed]
    public function selectedDiscount(): ?Discount
    {
        if (! $this->selectedDiscountId) {
            return null;
        }

        return Discount::find($this->selectedDiscountId);
    }

    public function updatedSelectedDiscountId(): void
    {
        if ($this->pendingOrderId !== null) {
            $this->selectedDiscountId = null;
            $this->addError('cart', 'Discounts cannot be modified on pending self-orders.');
        }
    }

    public function setItemDiscount(int $indexOrProductId, int $discountAmount): void
    {
        $discountAmount = max(0, $discountAmount);
        $this->itemDiscounts[$indexOrProductId] = $discountAmount;
        if (isset($this->cart[$indexOrProductId])) {
            $this->cart[$indexOrProductId]['item_discount_amount'] = $discountAmount;
        }
        foreach ($this->cart as $i => $item) {
            if (($item['product_id'] ?? null) === $indexOrProductId) {
                $this->cart[$i]['item_discount_amount'] = $discountAmount;
            }
        }
    }

    #[Computed]
    public function totalItemDiscount(): int
    {
        return (int) collect($this->cart)->sum(fn ($item) => (int) ($item['item_discount_amount'] ?? 0));
    }

    public function applyManualDiscount(string $pin = ''): void
    {
        if ($this->manualDiscountAmountInput !== null) {
            $this->manualDiscountAmount = (int) $this->manualDiscountAmountInput;
        }

        $this->validate([
            'manualDiscountAmount' => 'required|integer|min:1',
            'manualDiscountReason' => 'required|string|min:3|max:255',
        ]);

        $subtotal = (float) $this->subtotal();
        $amount = (float) $this->manualDiscountAmount;
        $percent = $subtotal > 0 ? (($amount / $subtotal) * 100) : 100.0;

        $approvalService = app(DiscountApprovalService::class);
        $needsApproval = $approvalService->evaluateThreshold($percent, $amount, $subtotal);

        if ($needsApproval) {
            $pinValidator = validator(
                ['pin' => $pin],
                ['pin' => 'required|string|min:4'],
                ['pin.required' => 'Supervisor PIN is required.', 'pin.min' => 'Supervisor PIN must be at least 4 characters.']
            );

            if ($pinValidator->fails()) {
                $this->addError('manualDiscountSupervisorPin', $pinValidator->errors()->first('pin'));

                return;
            }

            $storeId = (int) (app(TenantContext::class)->getStoreId() ?? Auth::user()->store_id ?? 0);

            try {
                $approval = $approvalService->createApproval(
                    requesterId: (int) Auth::id(),
                    pin: $pin,
                    action: Approval::ACTION_MANUAL_DISCOUNT,
                    amount: $amount,
                    reason: $this->manualDiscountReason,
                    storeId: $storeId
                );
                $this->manualDiscountApprovalId = (int) $approval->id;
            } catch (ValidationException $e) {
                $msg = $e->validator->errors()->first('pin') ?: $e->getMessage();
                $this->addError('manualDiscountSupervisorPin', $msg);
                $this->dispatch('toast', message: $msg, type: 'error');

                return;
            }
        } else {
            $this->manualDiscountApprovalId = null;
        }

        $this->appliedManualDiscount = (int) $this->manualDiscountAmount;
        $this->appliedManualDiscountReason = $this->manualDiscountReason;
        $this->isManualDiscountModalOpen = false;
        $this->dispatch('toast', message: 'Manual discount applied successfully.', type: 'success');
    }

    public function removeManualDiscount(): void
    {
        $this->appliedManualDiscount = 0;
        $this->appliedManualDiscountReason = '';
        $this->manualDiscountAmount = 0;
        $this->manualDiscountReason = '';
        $this->manualDiscountApprovalId = null;
    }

    #[Computed]
    public function discountAmount(): int
    {
        $subtotal = $this->subtotal;
        $voucherDiscount = 0;
        $discount = $this->selectedDiscount;

        if ($discount && $discount->is_active) {
            if ($subtotal >= $discount->min_purchase_amount && $discount->hasAvailableQuota()) {
                if ($discount->type === 'percentage') {
                    $calc = (int) round($subtotal * ($discount->value / 100));
                    if ($discount->max_discount_amount) {
                        $calc = min($calc, $discount->max_discount_amount);
                    }
                    $voucherDiscount = $calc;
                } else {
                    $voucherDiscount = min($subtotal, (int) $discount->value);
                }
            }
        }

        $manualDiscount = (int) $this->appliedManualDiscount;

        if ($voucherDiscount > 0 && $manualDiscount > 0 && $discount && ! $discount->is_stackable) {
            return min($subtotal, max($voucherDiscount, $manualDiscount) + $this->totalItemDiscount());
        }

        $totalDisc = $voucherDiscount + $manualDiscount + $this->totalItemDiscount();

        return min($subtotal, $totalDisc);
    }
}
