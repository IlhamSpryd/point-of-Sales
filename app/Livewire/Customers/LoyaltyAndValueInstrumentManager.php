<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\Store;
use App\Models\StoreCreditAccount;
use App\Models\Voucher;
use App\Modules\Customers\Enums\StoreCreditLedgerTypeEnum;
use App\Modules\Customers\Services\GiftCardService;
use App\Modules\Customers\Services\StoreCreditService;
use App\Modules\Customers\Services\VoucherService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class LoyaltyAndValueInstrumentManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $activeTab = 'vouchers'; // vouchers, gift_cards, store_credits

    public string $search = '';

    // Modal state
    public bool $showVoucherModal = false;

    public bool $showGiftCardModal = false;

    public bool $showStoreCreditModal = false;

    // Voucher Form State
    public string $voucher_code = '';

    public string $voucher_name = '';

    public string $voucher_discount_type = 'PERCENTAGE';

    public string $voucher_discount_value = '';

    public string $voucher_max_discount = '';

    public string $voucher_min_order = '0.00';

    public int $voucher_max_uses = 100;

    public string $voucher_valid_until = '';

    // Gift Card Form State
    public string $gift_card_number = '';

    public string $gift_card_amount = '';

    public string $gift_card_pin = '';

    public ?int $gift_card_customer_id = null;

    public string $gift_card_expires_at = '';

    // Store Credit Form State
    public ?int $store_credit_customer_id = null;

    public string $store_credit_amount = '';

    public string $store_credit_notes = '';

    public function mount(): void
    {
        $this->voucher_valid_until = Carbon::now()->addMonths(3)->toDateString();
        $this->gift_card_expires_at = Carbon::now()->addYear()->toDateString();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingActiveTab(): void
    {
        $this->resetPage();
        $this->search = '';
    }

    public function createVoucher(): void
    {
        $this->validate([
            'voucher_code' => 'required|string|max:64',
            'voucher_name' => 'required|string|max:150',
            'voucher_discount_type' => 'required|in:PERCENTAGE,FIXED_AMOUNT',
            'voucher_discount_value' => 'required|numeric|min:0.01',
            'voucher_min_order' => 'nullable|numeric|min:0',
            'voucher_max_uses' => 'required|integer|min:1',
        ]);

        $service = app(VoucherService::class);
        $service->createVoucher([
            'code' => $this->voucher_code,
            'name' => $this->voucher_name,
            'discount_type' => $this->voucher_discount_type,
            'discount_value' => $this->voucher_discount_value,
            'max_discount_amount' => ! empty($this->voucher_max_discount) ? $this->voucher_max_discount : null,
            'min_order_amount' => $this->voucher_min_order ?: '0.00',
            'max_uses' => $this->voucher_max_uses,
            'valid_until' => ! empty($this->voucher_valid_until) ? $this->voucher_valid_until : null,
        ], auth()->user());

        $this->showVoucherModal = false;
        $this->resetVoucherForm();
        session()->flash('message', 'Kode voucher berhasil dibuat dan diaktifkan.');
    }

    public function issueGiftCard(): void
    {
        $this->validate([
            'gift_card_amount' => 'required|numeric|min:1000',
        ]);

        $service = app(GiftCardService::class);
        $service->issueGiftCard([
            'card_number' => $this->gift_card_number ?: null,
            'amount' => $this->gift_card_amount,
            'pin' => $this->gift_card_pin ?: null,
            'customer_id' => $this->gift_card_customer_id,
            'expires_at' => ! empty($this->gift_card_expires_at) ? $this->gift_card_expires_at : null,
            'store_id' => app(TenantContext::class)->getStoreId(),
        ], auth()->user());

        $this->showGiftCardModal = false;
        $this->resetGiftCardForm();
        session()->flash('message', 'Kartu hadiah (Gift Card) berhasil diterbitkan.');
    }

    public function issueStoreCredit(): void
    {
        $this->validate([
            'store_credit_customer_id' => 'required|integer',
            'store_credit_amount' => 'required|numeric|min:1000',
        ]);

        $customer = Customer::withoutGlobalScopes()->findOrFail($this->store_credit_customer_id);

        $service = app(StoreCreditService::class);
        $service->issueStoreCredit(
            customer: $customer,
            amount: $this->store_credit_amount,
            entryType: StoreCreditLedgerTypeEnum::CREDIT_NOTE_ISSUE,
            storeId: app(TenantContext::class)->getStoreId(),
            referenceType: 'MANUAL_DEPOSIT',
            actor: auth()->user()
        );

        $this->showStoreCreditModal = false;
        $this->resetStoreCreditForm();
        session()->flash('message', 'Saldo Store Credit berhasil ditambahkan ke pelanggan.');
    }

    private function resetVoucherForm(): void
    {
        $this->voucher_code = '';
        $this->voucher_name = '';
        $this->voucher_discount_value = '';
        $this->voucher_max_discount = '';
        $this->voucher_min_order = '0.00';
        $this->resetErrorBag();
    }

    private function resetGiftCardForm(): void
    {
        $this->gift_card_number = '';
        $this->gift_card_amount = '';
        $this->gift_card_pin = '';
        $this->gift_card_customer_id = null;
        $this->resetErrorBag();
    }

    private function resetStoreCreditForm(): void
    {
        $this->store_credit_customer_id = null;
        $this->store_credit_amount = '';
        $this->store_credit_notes = '';
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        $vouchers = collect();
        $giftCards = collect();
        $storeCredits = collect();

        if ($this->activeTab === 'vouchers') {
            $q = Voucher::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->with(['customer', 'customerGroup']);
            if ($this->search !== '') {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($sub) => $sub->where('code', 'like', $term)->orWhere('name', 'like', $term));
            }
            $vouchers = $q->orderByDesc('id')->paginate(15);
        } elseif ($this->activeTab === 'gift_cards') {
            $q = GiftCard::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->with(['customer', 'store']);
            if ($this->search !== '') {
                $term = '%'.trim($this->search).'%';
                $q->where('card_number', 'like', $term);
            }
            $giftCards = $q->orderByDesc('id')->paginate(15);
        } elseif ($this->activeTab === 'store_credits') {
            $q = StoreCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->with(['customer']);
            if ($this->search !== '') {
                $term = '%'.trim($this->search).'%';
                $q->whereHas('customer', fn ($c) => $c->where('name', 'like', $term)->orWhere('customer_code', 'like', $term));
            }
            $storeCredits = $q->orderByDesc('current_balance')->paginate(15);
        }

        $customers = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('merged_into_customer_id')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'customer_code', 'phone']);

        return view('livewire.customers.loyalty-and-value-instrument-manager', [
            'vouchers' => $vouchers,
            'giftCards' => $giftCards,
            'storeCredits' => $storeCredits,
            'customers' => $customers,
        ]);
    }
}
