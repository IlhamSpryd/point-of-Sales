<!-- MOBILE & TABLET DRAWER / FLOATING TRIGGER -->
<div class="lg:hidden" x-data="{ isMobileCartOpen: false }">
    {{-- Floating Cart Summary Bar (Tampil di mobile saat keranjang tidak kosong) --}}
    @if(count($this->cartLines) > 0)
        <div class="fixed bottom-0 inset-x-0 z-40 bg-white/95 backdrop-blur-md border-t border-[#E5E5E5] px-4 py-3 shadow-lg flex items-center justify-between gap-3 select-none">
            <div class="min-w-0">
                <span class="text-[11px] font-medium text-[#6B7280] block">Total ({{ count($this->cartLines) }} Item)</span>
                <span class="text-base font-bold text-[#111827] font-mono">
                    Rp {{ number_format($this->totalAmount, 0, ',', '.') }}
                </span>
            </div>

            <button type="button"
                @click="$dispatch('open-drawer', 'pos-mobile-cart-drawer')"
                class="px-4 py-2.5 bg-[#171717] hover:bg-[#262626] text-white font-medium text-xs rounded-xl shadow-sm active:scale-95 transition-all flex items-center gap-2">
                <span class="material-symbols-rounded text-[18px]">shopping_bag</span>
                <span>Lihat Pesanan</span>
                <span class="bg-white/20 text-white text-[10px] px-1.5 py-0.5 rounded-full font-mono font-bold">
                    {{ count($this->cartLines) }}
                </span>
            </button>
        </div>
    @endif

    {{-- DevDojo Drawer untuk Mobile/Tablet Keranjang --}}
    <x-ui.drawer id="pos-mobile-cart-drawer" position="right" maxWidth="md">
        <x-slot:header>
            <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-[#111827] text-[20px]">shopping_bag</span>
                <span class="font-headline-sm text-[16px] font-semibold text-[#111827]">Pesanan Saat Ini</span>
                <span class="bg-[#171717] text-white text-[11px] font-mono px-2 py-0.5 rounded-full font-bold">
                    {{ count($this->cartLines) }}
                </span>
            </div>
        </x-slot:header>

        <div class="flex flex-col h-full -mx-6 -my-5">
            {{-- Customer Selection di Mobile --}}
            <div class="p-3 border-b border-[#E5E5E5] bg-[#F9FAFB]">
                @unless ($pendingOrderId)
                    <div class="relative" x-data="{ showResults: false }">
                        @if ($customerId)
                            <div class="flex items-center justify-between gap-2 bg-white rounded-lg px-3 py-2 border border-[#E5E5E5] shadow-xs">
                                <span class="flex items-center gap-1.5 text-xs font-semibold text-[#111827] truncate">
                                    <span class="material-symbols-rounded text-[16px] text-gray-400">person</span>
                                    {{ $customerName }}
                                </span>
                                <button type="button" wire:click="clearCustomer" class="text-gray-400 hover:text-rose-500 transition-colors">
                                    <span class="material-symbols-rounded text-[16px] block">close</span>
                                </button>
                            </div>
                        @else
                            <div class="relative">
                                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-[16px] text-[#9CA3AF] pointer-events-none">person_add</span>
                                <input type="text" wire:model.live.debounce.300ms="customerSearch"
                                    x-on:focus="showResults = true" x-on:click.outside="showResults = false"
                                    placeholder="Pelanggan (opsional)..."
                                    class="w-full h-8 pl-9 pr-3 text-xs bg-white border border-[#E5E5E5] rounded-lg text-[#111827] placeholder-[#9CA3AF] focus:border-[#171717] focus:outline-none transition-colors" />
                            </div>

                            @if (mb_strlen(trim($customerSearch)) >= 2)
                                <div x-show="showResults" x-cloak wire:loading.class="opacity-50"
                                    class="absolute z-40 mt-1 w-full bg-white rounded-xl border border-[#E5E5E5] shadow-xl overflow-hidden max-h-48 overflow-y-auto custom-scrollbar">
                                    @forelse ($this->customerResults as $c)
                                        <button type="button" wire:key="customer-mobile-{{ $c->id }}"
                                            wire:click="selectCustomer({{ $c->id }}, '{{ addslashes($c->name) }}')"
                                            x-on:click="showResults = false"
                                            class="w-full flex items-center justify-between gap-2 px-3 py-2 text-left hover:bg-[#F9FAFB] transition-colors border-b border-[#E5E5E5] last:border-0 text-xs">
                                            <span class="font-medium text-[#111827]">{{ $c->name }}</span>
                                            <span class="text-[11px] text-[#6B7280] font-mono">{{ $c->phone ?? '-' }}</span>
                                        </button>
                                    @empty
                                        <div class="px-3 py-2 text-xs text-[#9CA3AF] text-center">Pelanggan tidak ditemukan.</div>
                                    @endforelse
                                </div>
                            @endif
                        @endif
                    </div>
                @endunless
            </div>

            {{-- Isi Cart Items & Summary --}}
            <div class="flex-1 flex flex-col overflow-hidden min-h-0 bg-white p-3">
                @include('livewire.pos.partials.cart-items')
                @include('livewire.pos.partials.cart-summary')
            </div>
        </div>
    </x-ui.drawer>
</div>
