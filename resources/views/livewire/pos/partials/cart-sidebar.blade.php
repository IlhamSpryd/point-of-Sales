<!-- PANEL KANAN DESKTOP: Daftar Keranjang Belanja -->
<section class="hidden lg:flex w-96 xl:w-[400px] bg-white flex-col shrink-0 h-full border-l border-[#E5E7EB] z-30 shadow-[inset_1px_0_10px_rgba(0,0,0,0.02)]">
    <!-- Judul Keranjang & Customer Selection -->
    <div class="p-4 lg:p-5 border-b border-[#E5E7EB] flex flex-col shrink-0 bg-white">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2.5">
                <h2 class="text-lg font-bold text-apeiron-ink leading-none">Keranjang</h2>
                <span class="bg-apeiron-ink text-white text-[11px] font-bold px-2 py-0.5 rounded-full transition-all duration-300">
                    {{ count($this->cartLines) }}
                </span>
            </div>
            <div class="flex items-center gap-2">
                @if ($pendingOrderCode)
                    <span title="Self Order Code"
                        class="text-xs font-medium text-amber-700 bg-amber-50 px-2.5 py-1 rounded-md border border-amber-200 flex items-center gap-1">
                        #{{ $pendingOrderCode }}
                        <button wire:click="cancelPendingOrderMode"
                            class="hover:text-amber-800 hover:bg-amber-100 rounded p-0.5 transition-colors ml-1" title="Batalkan Self Order">
                            <span class="material-symbols-rounded text-[14px] block">close</span>
                        </button>
                    </span>
                @endif

                <button type="button"
                    x-on:click="
                        if (confirm('Kosongkan semua item dari keranjang?')) {
                            $wire.clearCart();
                        }
                    "
                    title="Kosongkan Keranjang"
                    @if (count($this->cartLines) === 0) disabled @endif
                    class="{{ count($this->cartLines) === 0 ? 'opacity-30 cursor-not-allowed' : 'hover:bg-rose-50 hover:text-rose-600 cursor-pointer' }} text-gray-400 p-2 rounded-lg transition-colors flex items-center justify-center outline-none">
                    <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </button>
            </div>
        </div>

        @unless ($pendingOrderId)
            <div class="relative" x-data="{ showResults: false }">
                @if ($customerId)
                    <div class="flex items-center justify-between gap-2 bg-gray-50 rounded-lg px-3.5 py-2 border border-[#E5E7EB]">
                        <span class="flex items-center gap-2 text-xs font-bold text-gray-700 truncate">
                            <span class="material-symbols-rounded text-[18px] text-gray-400">person</span>
                            {{ $customerName }}
                        </span>
                        <button type="button" wire:click="clearCustomer"
                            class="text-gray-400 hover:text-rose-500 transition-colors">
                            <span class="material-symbols-rounded text-[18px] block">close</span>
                        </button>
                    </div>
                @else
                    <x-search-input wire:model.live.debounce.300ms="customerSearch"
                        x-on:focus="showResults = true" x-on:click.outside="showResults = false"
                        placeholder="Tambah pelanggan (opsional)..." icon="person_add" />

                    @if (mb_strlen(trim($customerSearch)) >= 2)
                        <div x-show="showResults" x-cloak wire:loading.class="opacity-50"
                            wire:target="customerSearch"
                            class="absolute z-40 mt-1 w-full bg-white rounded-xl border border-[#E5E7EB] shadow-xl overflow-hidden max-h-56 overflow-y-auto custom-scrollbar">
                            @forelse ($this->customerResults as $c)
                                <button type="button" wire:key="customer-result-{{ $c->id }}"
                                    wire:click="selectCustomer({{ $c->id }}, '{{ addslashes($c->name) }}')"
                                    x-on:click="showResults = false"
                                    class="w-full flex items-center justify-between gap-2 px-3.5 py-2.5 text-left hover:bg-gray-50 transition-colors border-b border-[#E5E7EB] last:border-0">
                                    <span class="text-xs font-bold text-apeiron-ink">{{ $c->name }}</span>
                                    <span class="text-[11px] font-medium text-gray-400">{{ $c->phone ?? '-' }}</span>
                                </button>
                            @empty
                                <div class="px-3.5 py-3 text-xs text-gray-400 text-center">Pelanggan tidak ditemukan.</div>
                            @endforelse
                        </div>
                    @endif
                @endif
            </div>
        @endunless
    </div>

    <!-- Area Keranjang List & Kalkulasi -->
    <div class="relative flex-1 w-full min-h-0 bg-white">
        <div class="absolute inset-0 flex flex-col">
            @include('livewire.pos.partials.cart-items')
            @include('livewire.pos.partials.cart-summary')
        </div>
    </div>
</section>
