<div class="space-y-6" data-gsap="page-enter">
    @include('customers.partials.sub-nav')

    <!-- Page Header -->
    <x-erp.page-header
        title="Loyalty & Value Instruments"
        description="Manage promotional campaign vouchers, PIN-encrypted gift cards, and customer store credit balances.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('customers.index') }}">Customers</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Loyalty & Value Instruments</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('customers.index') }}" class="gap-2">
                <span class="material-symbols-rounded text-base">contacts</span>
                Customer Directory
            </x-ui.button>
            @if ($activeTab === 'vouchers')
                <x-ui.button variant="primary" wire:click="$set('showVoucherModal', true)" class="gap-2">
                    <span class="material-symbols-rounded text-base">confirmation_number</span>
                    Create Voucher
                </x-ui.button>
            @elseif ($activeTab === 'gift_cards')
                <x-ui.button variant="primary" wire:click="$set('showGiftCardModal', true)" class="gap-2">
                    <span class="material-symbols-rounded text-base">card_giftcard</span>
                    Issue Gift Card
                </x-ui.button>
            @elseif ($activeTab === 'store_credits')
                <x-ui.button variant="primary" wire:click="$set('showStoreCreditModal', true)" class="gap-2">
                    <span class="material-symbols-rounded text-base">account_balance_wallet</span>
                    Deposit Store Credit
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Messages -->
    @if (session()->has('message'))
        <x-ui.alert type="success" title="Success" class="rounded-2xl">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert type="danger" title="Error Encountered" class="rounded-2xl">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- Tabs Navigation -->
    <div class="flex items-center border-b border-zinc-200 dark:border-zinc-800 gap-6">
        <button wire:click="$set('activeTab', 'vouchers')" class="pb-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'vouchers' ? 'border-apeiron-primary text-apeiron-primary dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-300' }}">
            <span class="material-symbols-rounded text-lg">confirmation_number</span>
            Promo Vouchers
        </button>
        <button wire:click="$set('activeTab', 'gift_cards')" class="pb-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'gift_cards' ? 'border-apeiron-primary text-apeiron-primary dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-300' }}">
            <span class="material-symbols-rounded text-lg">card_giftcard</span>
            Gift Cards
        </button>
        <button wire:click="$set('activeTab', 'store_credits')" class="pb-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'store_credits' ? 'border-apeiron-primary text-apeiron-primary dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-300' }}">
            <span class="material-symbols-rounded text-lg">account_balance_wallet</span>
            Store Credit
        </button>
    </div>

    <!-- Search Toolbar -->
    <div class="card-surface p-4 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 flex items-center justify-between gap-4">
        <div class="relative flex-1 max-w-md">
            <span class="material-symbols-rounded absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search by code, name, card number, or customer..." class="w-full pl-10 pr-4 py-2 rounded-xl text-sm bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 text-zinc-900 dark:text-white focus:ring-2 focus:ring-apeiron-primary/20 focus:outline-hidden">
        </div>
    </div>

    <!-- Tab 1: Vouchers -->
    @if ($activeTab === 'vouchers')
        <div class="card-surface rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                            <th class="px-6 py-3.5">Code & Name</th>
                            <th class="px-6 py-3.5">Discount Type</th>
                            <th class="px-6 py-3.5 text-right">Discount Value</th>
                            <th class="px-6 py-3.5 text-right">Min. Spend</th>
                            <th class="px-6 py-3.5">Usage</th>
                            <th class="px-6 py-3.5">Validity</th>
                            <th class="px-6 py-3.5 text-center">Status</th>
                            <th class="px-6 py-3.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                        @forelse ($vouchers as $voucher)
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-900/50 transition">
                                <td class="px-6 py-4">
                                    <div class="font-bold text-zinc-900 dark:text-white flex items-center gap-2 font-mono">
                                        <span class="px-2 py-0.5 rounded-md bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-xs">{{ $voucher->voucher_code }}</span>
                                    </div>
                                    <div class="text-xs text-zinc-500 mt-1">{{ $voucher->name }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <x-ui.badge variant="{{ $voucher->discount_type->value === 'PERCENTAGE' ? 'info' : 'success' }}" size="sm">
                                        {{ $voucher->discount_type->value === 'PERCENTAGE' ? 'Percentage (%)' : 'Fixed Amount (Rp)' }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4 font-semibold text-right text-zinc-900 dark:text-white font-mono tabular-nums">
                                    @if ($voucher->discount_type->value === 'PERCENTAGE')
                                        {{ number_format((float) $voucher->discount_value, 0) }}%
                                        @if ($voucher->max_discount_amount)
                                            <span class="block text-[11px] text-zinc-400 font-normal">Max. Rp {{ number_format((float) $voucher->max_discount_amount, 2, ',', '.') }}</span>
                                        @endif
                                    @else
                                        Rp {{ number_format((float) $voucher->discount_value, 2, ',', '.') }}
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-zinc-700 dark:text-zinc-300 font-mono tabular-nums">
                                    Rp {{ number_format((float) $voucher->min_order_amount, 2, ',', '.') }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-xs font-medium text-zinc-900 dark:text-white">
                                        {{ $voucher->times_used }} / {{ $voucher->max_uses_global ?? '∞' }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-xs text-zinc-500">
                                    @if ($voucher->valid_until)
                                        Until {{ \Carbon\Carbon::parse($voucher->valid_until)->format('d M Y') }}
                                    @else
                                        No Expiry
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <x-ui.badge variant="{{ $voucher->is_active ? 'success' : 'neutral' }}" size="sm">
                                        {{ $voucher->is_active ? 'Active' : 'Inactive' }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    @if ($voucher->is_active)
                                        <x-ui.button variant="danger" size="xs" wire:click="deactivateVoucher({{ $voucher->id }})" wire:confirm="Deactivate this voucher?">
                                            Deactivate
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-10 text-center text-zinc-500 dark:text-zinc-400">
                                    No promotional vouchers registered.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($vouchers->hasPages())
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                    {{ $vouchers->links() }}
                </div>
            @endif
        </div>

    <!-- Tab 2: Gift Cards -->
    @elseif ($activeTab === 'gift_cards')
        <div class="card-surface rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                            <th class="px-6 py-3.5">Card Number</th>
                            <th class="px-6 py-3.5">Customer</th>
                            <th class="px-6 py-3.5 text-right">Initial Balance</th>
                            <th class="px-6 py-3.5 text-right">Current Balance</th>
                            <th class="px-6 py-3.5">Expiry Date</th>
                            <th class="px-6 py-3.5 text-center">Status</th>
                            <th class="px-6 py-3.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                        @forelse ($giftCards as $card)
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-900/50 transition">
                                <td class="px-6 py-4 font-mono font-bold text-zinc-900 dark:text-white">
                                    {{ $card->masked_card_number }}
                                </td>
                                <td class="px-6 py-4">
                                    @if ($card->customer)
                                        <div class="font-medium text-zinc-900 dark:text-white">{{ $card->customer->name }}</div>
                                        <div class="text-xs text-zinc-400 font-mono">{{ $card->customer->customer_code }}</div>
                                    @else
                                        <span class="text-xs text-zinc-400 italic">Anonymous / Bearer</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-zinc-600 dark:text-zinc-400 font-mono tabular-nums">
                                    Rp {{ number_format((float) $card->initial_amount, 2, ',', '.') }}
                                </td>
                                <td class="px-6 py-4 text-right font-semibold text-emerald-700 dark:text-emerald-400 font-mono tabular-nums">
                                    Rp {{ number_format((float) $card->current_balance, 2, ',', '.') }}
                                </td>
                                <td class="px-6 py-4 text-xs text-zinc-500">
                                    @if ($card->expires_at)
                                        {{ \Carbon\Carbon::parse($card->expires_at)->format('d M Y') }}
                                    @else
                                        No Expiry
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <x-ui.badge variant="{{ $card->status->value === 'ACTIVE' ? 'success' : ($card->status->value === 'FROZEN' ? 'warning' : 'neutral') }}" size="sm">
                                        {{ $card->status->value }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    @if ($card->status->value === 'ACTIVE')
                                        <x-ui.button variant="warning" size="xs" wire:click="freezeGiftCard({{ $card->id }})" wire:confirm="Freeze this gift card?">
                                            Freeze
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-10 text-center text-zinc-500 dark:text-zinc-400">
                                    No gift cards issued yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($giftCards->hasPages())
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                    {{ $giftCards->links() }}
                </div>
            @endif
        </div>

    <!-- Tab 3: Store Credits -->
    @elseif ($activeTab === 'store_credits')
        <div class="card-surface rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                            <th class="px-6 py-3.5">Customer</th>
                            <th class="px-6 py-3.5 text-center">Account Status</th>
                            <th class="px-6 py-3.5 text-right">Store Credit Balance</th>
                            <th class="px-6 py-3.5">Last Updated</th>
                            <th class="px-6 py-3.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                        @forelse ($storeCredits as $sc)
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-900/50 transition">
                                <td class="px-6 py-4">
                                    <div class="font-bold text-zinc-900 dark:text-white">{{ $sc->customer->name ?? '-' }}</div>
                                    <div class="text-xs text-zinc-400 font-mono">{{ $sc->customer->customer_code ?? '' }}</div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <x-ui.badge variant="{{ $sc->status->value === 'ACTIVE' ? 'success' : 'danger' }}" size="sm">
                                        {{ $sc->status->value }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4 text-right font-bold text-emerald-700 dark:text-emerald-400 font-mono tabular-nums text-base">
                                    Rp {{ number_format((float) $sc->current_balance, 2, ',', '.') }}
                                </td>
                                <td class="px-6 py-4 text-xs text-zinc-500">
                                    {{ $sc->updated_at ? $sc->updated_at->diffForHumans() : '-' }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <x-ui.button variant="secondary" size="xs" wire:click="$set('store_credit_customer_id', {{ $sc->customer_id }}); $set('showStoreCreditModal', true)">
                                        + Add Balance
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-zinc-500 dark:text-zinc-400">
                                    No store credit accounts registered.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($storeCredits->hasPages())
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                    {{ $storeCredits->links() }}
                </div>
            @endif
        </div>
    @endif

    <!-- MODAL: CREATE VOUCHER -->
    <x-ui.modal wire:model="showVoucherModal" maxWidth="lg">
        <x-slot:title>
            Create New Promotional Voucher
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-4 text-sm">
                <div>
                    <x-ui.label for="voucher_code">Voucher Code *</x-ui.label>
                    <x-ui.input type="text" id="voucher_code" wire:model="voucher_code" placeholder="e.g., SAVE20" class="uppercase font-mono" />
                    @error('voucher_code') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <x-ui.label for="voucher_name">Voucher / Campaign Name *</x-ui.label>
                    <x-ui.input type="text" id="voucher_name" wire:model="voucher_name" placeholder="e.g., B2B Shopping Day Discount" />
                    @error('voucher_name') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-ui.label for="voucher_discount_type">Discount Type</x-ui.label>
                        <x-ui.select id="voucher_discount_type" wire:model.live="voucher_discount_type">
                            <option value="PERCENTAGE">Percentage (%)</option>
                            <option value="FIXED_AMOUNT">Fixed Amount (Rp)</option>
                        </x-ui.select>
                    </div>
                    <div>
                        <x-ui.label for="voucher_discount_value">Discount Value *</x-ui.label>
                        <x-ui.input type="number" step="0.01" id="voucher_discount_value" wire:model="voucher_discount_value" placeholder="{{ $voucher_discount_type === 'PERCENTAGE' ? '15' : '50000' }}" />
                        @error('voucher_discount_value') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-ui.label for="voucher_max_discount">Max Discount (Rp)</x-ui.label>
                        <x-ui.input type="number" step="0.01" id="voucher_max_discount" wire:model="voucher_max_discount" placeholder="Leave empty for unlimited" />
                    </div>
                    <div>
                        <x-ui.label for="voucher_min_order">Min. Spend (Rp)</x-ui.label>
                        <x-ui.input type="number" step="0.01" id="voucher_min_order" wire:model="voucher_min_order" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-ui.label for="voucher_max_uses">Total Usage Limit</x-ui.label>
                        <x-ui.input type="number" id="voucher_max_uses" wire:model="voucher_max_uses" />
                    </div>
                    <div>
                        <x-ui.label for="voucher_valid_until">Valid Until</x-ui.label>
                        <x-ui.input type="date" id="voucher_valid_until" wire:model="voucher_valid_until" />
                    </div>
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('showVoucherModal', false)">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="createVoucher">
                    Save Voucher
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- MODAL: ISSUE GIFT CARD -->
    <x-ui.modal wire:model="showGiftCardModal" maxWidth="lg">
        <x-slot:title>
            Issue Gift Card
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-4 text-sm">
                <div>
                    <x-ui.label for="gift_card_number">Card Number (Optional / Auto-generated)</x-ui.label>
                    <x-ui.input type="text" id="gift_card_number" wire:model="gift_card_number" placeholder="Leave empty to auto-generate" class="font-mono" />
                    @error('gift_card_number') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-ui.label for="gift_card_amount">Initial Balance (Rp) *</x-ui.label>
                        <x-ui.input type="number" step="0.01" id="gift_card_amount" wire:model="gift_card_amount" placeholder="100000" />
                        @error('gift_card_amount') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-ui.label for="gift_card_pin">Secret PIN (4-8 Digits) *</x-ui.label>
                        <x-ui.input type="password" id="gift_card_pin" wire:model="gift_card_pin" placeholder="••••" class="font-mono" />
                        @error('gift_card_pin') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <x-ui.label for="gift_card_customer_id">Link to Customer (Optional)</x-ui.label>
                    <x-ui.select id="gift_card_customer_id" wire:model="gift_card_customer_id">
                        <option value="">-- Unlinked Customer (Bearer Card) --</option>
                        @foreach ($customers as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->customer_code }})</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div>
                    <x-ui.label for="gift_card_expires_at">Expires At</x-ui.label>
                    <x-ui.input type="date" id="gift_card_expires_at" wire:model="gift_card_expires_at" />
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('showGiftCardModal', false)">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="issueGiftCard">
                    Issue Card
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- MODAL: DEPOSIT STORE CREDIT -->
    <x-ui.modal wire:model="showStoreCreditModal" maxWidth="lg">
        <x-slot:title>
            Deposit Store Credit Balance
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-4 text-sm">
                <div>
                    <x-ui.label for="store_credit_customer_id">Select Customer *</x-ui.label>
                    <x-ui.select id="store_credit_customer_id" wire:model="store_credit_customer_id">
                        <option value="">-- Select Customer --</option>
                        @foreach ($customers as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->customer_code }})</option>
                        @endforeach
                    </x-ui.select>
                    @error('store_credit_customer_id') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <x-ui.label for="store_credit_amount">Deposit Amount (Rp) *</x-ui.label>
                    <x-ui.input type="number" step="0.01" id="store_credit_amount" wire:model="store_credit_amount" placeholder="50000" />
                    @error('store_credit_amount') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <x-ui.label for="store_credit_notes">Notes / Deposit Reason</x-ui.label>
                    <x-ui.input type="text" id="store_credit_notes" wire:model="store_credit_notes" placeholder="e.g., Loyalty bonus, cash refund, or goodwill compensation" />
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('showStoreCreditModal', false)">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="issueStoreCredit">
                    Deposit Credit
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
