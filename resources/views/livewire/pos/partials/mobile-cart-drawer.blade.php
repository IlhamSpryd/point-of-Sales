<!-- MOBILE & TABLET DRAWER / FLOATING TRIGGER -->
<div class="lg:hidden" x-data="{ isMobileCartOpen: false }">
    {{-- Floating Cart Summary Bar (Tampil di mobile saat keranjang tidak kosong) --}}
    @if(count($this->cartLines) > 0)
        <div class="fixed bottom-0 inset-x-0 z-40 bg-white/95 backdrop-blur-md border-t border-[#E5E7EB] px-4 py-3 shadow-[0_-8px_30px_rgba(0,0,0,0.08)] flex items-center justify-between gap-3">
            <div class="min-w-0">
                <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Total ({{ count($this->cartLines) }} item)</span>
                <span class="text-lg font-black text-apeiron-ink tabular-nums">
                    Rp {{ number_format($this->totalAmount, 0, ',', '.') }}
                </span>
            </div>

            <button type="button"
                @click="$dispatch('open-drawer', 'pos-mobile-cart-drawer')"
                class="px-5 py-2.5 bg-apeiron-ink text-white font-bold text-xs rounded-xl shadow-md hover:bg-[#111111] active:scale-95 transition-all flex items-center gap-2">
                <span class="material-symbols-rounded text-[18px]">shopping_bag</span>
                <span>Buka Keranjang</span>
                <span class="bg-white/20 text-white text-[10px] px-1.5 py-0.5 rounded-full font-black">
                    {{ count($this->cartLines) }}
                </span>
            </button>
        </div>
    @endif

    {{-- DevDojo Drawer untuk Mobile/Tablet Keranjang --}}
    <x-ui.drawer id="pos-mobile-cart-drawer" position="right" maxWidth="md">
        <x-slot:header>
            <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-apeiron-ink text-[22px]">shopping_bag</span>
                <span>Keranjang Belanja</span>
                <span class="bg-apeiron-ink text-white text-[11px] font-bold px-2 py-0.5 rounded-full">
                    {{ count($this->cartLines) }}
                </span>
            </div>
        </x-slot:header>

        <div class="flex flex-col h-full -mx-6 -my-5">
            {{-- Customer Selection di Mobile --}}
            <div class="p-4 border-b border-[#E5E7EB] bg-gray-50/50">
                @unless ($pendingOrderId)
                    <div class="relative" x-data="{ showResults: false }">
                        @if ($customerId)
                            <div class="flex items-center justify-between gap-2 bg-white rounded-xl px-3.5 py-2.5 border border-[#E5E7EB] shadow-sm">
                                <span class="flex items-center gap-2 text-xs font-bold text-gray-700 truncate">
                                    <span class="material-symbols-rounded text-[18px] text-gray-400">person</span>
                                    {{ $customerName }}
                                </span>
                                <button type="button" wire:click="clearCustomer" class="text-gray-400 hover:text-rose-500 transition-colors">
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
                                        <button type="button" wire:key="customer-mobile-{{ $c->id }}"
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

            {{-- Isi Cart Items & Summary --}}
            <div class="flex-1 flex flex-col overflow-hidden min-h-0 bg-white">
                @include('livewire.pos.partials.cart-items')
                @include('livewire.pos.partials.cart-summary')
            </div>
        </div>
    </x-ui.drawer>
</div>
