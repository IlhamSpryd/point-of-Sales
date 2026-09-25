{{-- [OMEGA-NODE2] Tambah pemilihan pelanggan (Loyalty) + modal Split Payment
     (Alpine.js murni, exact-sum ke TransactionService). Skenario Tarik
     Pesanan sengaja TIDAK diubah -- lihat SYNC ALERT Node 1. | 2026-09-22 --}}
<div id="livewire-pos-root" data-testid="lw-pos-root" dusk="lw-pos-root"
     x-data="{
         focusSearch() {
             const input = document.getElementById('search-input');
             if (input) input.focus();
         },
         triggerPay() {
             const btn = document.getElementById('btn-bayar');
             if (btn && !btn.disabled) btn.click();
         }
     }"
     @keydown.window.f2.prevent="focusSearch()"
     @keydown.window.f4.prevent="triggerPay()"
     class="p-4 sm:p-6 lg:p-6 h-full">
    <div class="flex flex-col md:flex-row gap-6 h-[calc(100vh-110px)] min-h-[600px]">
        
        {{-- KOLOM KIRI: pilih order type + produk --}}
        <div class="flex-1 flex flex-col h-full overflow-hidden">
            <!-- Tipe Pesanan & Meja -->
            <div class="mb-6 flex flex-col sm:flex-row items-center justify-between gap-4 shrink-0 z-10">
                <div class="flex gap-1 p-1 bg-white shadow-sm rounded-xl border border-yovel-border">
                    <label class="flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="orderType" value="dine_in" class="peer sr-only">
                        <span class="px-5 py-2 rounded-lg text-[13px] font-bold transition-all duration-200 peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-yovel-ink text-yovel-muted hover:text-yovel-ink active:scale-95">
                            Dine-In
                        </span>
                    </label>
                    <label class="flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="orderType" value="takeaway" class="peer sr-only">
                        <span class="px-5 py-2 rounded-lg text-[13px] font-bold transition-all duration-200 peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-yovel-ink text-yovel-muted hover:text-yovel-ink active:scale-95">
                            Takeaway
                        </span>
                    </label>
                </div>

                @if ($orderType === 'dine_in')
                    <div class="w-full sm:w-64">
                        <select wire:model="tableId" class="bg-white border border-yovel-border text-yovel-ink text-sm rounded-xl focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink block w-full px-4 py-2.5 shadow-sm transition-all duration-200 min-h-[44px] appearance-none cursor-pointer">
                            <option value="">-- Pilih Meja --</option>
                            @foreach ($this->activeTables as $table)
                                <option value="{{ $table->id }}">Meja {{ $table->table_name }}</option>
                            @endforeach
                        </select>
                        @error('tableId') <p class="text-rose-500 text-xs mt-1.5 font-medium">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <!-- List Kategori & Produk (scrollable independently) -->
            <div class="flex-1 overflow-y-auto custom-scrollbar space-y-8 pr-2 pb-6">
                @foreach ($this->categories as $category)
                    @if($category->products->count() > 0)
                        <div>
                            <h3 class="text-sm font-bold tracking-wider uppercase text-yovel-muted mb-4 flex items-center gap-2">
                                {{ $category->category_name }}
                            </h3>
                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                                @foreach ($category->products as $product)
                                    @if($product->modifierGroups->count() > 0)
                                        <button wire:click="openModifierPicker({{ $product->id }})"
                                                class="text-left bg-white rounded-2xl border border-yovel-border p-4 hover:shadow-lg hover:-translate-y-1 hover:border-yovel-ink transition-all duration-300 active:scale-95 flex flex-col justify-between h-full group">
                                    @else
                                        <button wire:click="addToCartDirectly({{ $product->id }})"
                                                class="text-left bg-white rounded-2xl border border-yovel-border p-4 hover:shadow-lg hover:-translate-y-1 hover:border-yovel-ink transition-all duration-300 active:scale-95 flex flex-col justify-between h-full group">
                                    @endif
                                        <div class="font-bold text-[14px] text-yovel-ink leading-snug">{{ $product->product_name }}</div>
                                        <div class="mt-4 flex items-center justify-between w-full">
                                            <div class="text-yovel-ink font-semibold text-[13px]">Rp {{ number_format($product->product_price, 0, ',', '.') }}</div>
                                            <div class="w-7 h-7 rounded-full bg-yovel-bg flex items-center justify-center group-hover:bg-yovel-ink group-hover:text-white transition-colors duration-300">
                                                <span class="material-symbols-rounded text-[16px]">add</span>
                                            </div>
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            {{-- MODAL pemilihan modifier --}}
            @if ($selectingProductId && isset($selectingProduct))
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-primary-700/40 backdrop-blur-sm" wire:transition>
                    <div class="bg-white rounded-2xl p-0 w-full max-w-md max-h-[90vh] flex flex-col shadow-2xl border border-yovel-border">

                        <!-- Header Modal -->
                        <div class="px-5 py-4 border-b border-yovel-border flex justify-between items-center bg-white rounded-t-2xl z-10 shrink-0">
                            <div>
                                <h3 class="font-bold text-lg text-yovel-ink">{{ $selectingProduct->product_name }}</h3>
                                <p class="text-sm text-yovel-muted mt-0.5">Rp {{ number_format($selectingProduct->product_price, 0, ',', '.') }}</p>
                            </div>
                            <button wire:click="closeModifierPicker" class="p-2 bg-yovel-surface rounded-xl text-yovel-muted hover:text-yovel-ink hover:bg-primary-200 transition-all duration-200 active:scale-90 min-w-[44px] min-h-[44px]">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>

                        <!-- Body Modal (Modifiers) -->
                        <div class="p-4 overflow-y-auto flex-1 bg-yovel-bg space-y-4 custom-scrollbar">
                            @foreach ($selectingProduct->modifierGroups as $group)
                                <div class="bg-white p-4 rounded-xl border border-yovel-border shadow-sm">
                                    <div class="flex justify-between items-center mb-3 pb-2 border-b border-primary-100">
                                        <h4 class="font-bold text-sm text-yovel-ink">{{ $group->name }}</h4>
                                        <span class="text-[10px] px-2 py-0.5 rounded-md bg-yovel-bg text-yovel-muted font-medium border border-yovel-border">
                                            {{ $group->is_required ? ($group->selection_type === 'single' ? 'Wajib pilih 1' : 'Wajib pilih') : 'Opsional' }}
                                        </span>
                                    </div>

                                    <div class="grid grid-cols-2 gap-2">
                                        @foreach ($group->modifiers as $mod)
                                            @php
                                                $isSelected = in_array($mod->id, $pendingModifierIds);
                                            @endphp
                                            <label class="modifier-box flex-col min-h-[56px]"
                                                   :class="{ 'selected': {{ $isSelected ? 'true' : 'false' }} }">
                                                @if($group->selection_type === 'single')
                                                    <input type="radio"
                                                           wire:click="toggleModifier({{ $group->id }}, {{ $mod->id }}, 'single')"
                                                           name="group_{{ $group->id }}"
                                                           {{ $isSelected ? 'checked' : '' }}
                                                           class="sr-only">
                                                @else
                                                    <input type="checkbox"
                                                           wire:click="toggleModifier({{ $group->id }}, {{ $mod->id }}, 'multiple')"
                                                           {{ $isSelected ? 'checked' : '' }}
                                                           class="sr-only">
                                                @endif
                                                <span class="font-medium text-sm leading-tight transition-colors {{ $isSelected ? 'text-yovel-ink' : 'text-primary-600' }}">
                                                    {{ $mod->name }}
                                                </span>
                                                @if($mod->extra_price > 0)
                                                    <span class="text-[11px] font-medium text-primary-400 mt-0.5">+Rp {{ number_format($mod->extra_price, 0, ',', '.') }}</span>
                                                @endif
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            <div class="bg-white p-4 rounded-xl border border-yovel-border shadow-sm">
                                <label class="block font-bold text-sm text-yovel-ink mb-2 border-b border-primary-100 pb-2">Catatan Tambahan</label>
                                <textarea wire:model="pendingNotes" class="w-full bg-yovel-bg border border-yovel-border rounded-xl text-sm focus:border-yovel-ink focus:ring-1 focus:ring-yovel-ink focus:bg-white transition-all duration-200 px-3 py-2 resize-none" rows="2" placeholder="Contoh: Kurangi es, jangan pakai sedotan"></textarea>
                            </div>
                        </div>

                        <!-- Footer Modal -->
                        <div class="p-4 border-t border-yovel-border bg-white flex items-center justify-between gap-4 rounded-b-2xl shrink-0">
                            <div class="flex items-center gap-1 bg-yovel-surface rounded-xl p-1 border border-yovel-border">
                                <button type="button" wire:click="$set('pendingQty', {{ max(1, $pendingQty - 1) }})" class="w-11 h-11 bg-white rounded-lg shadow-sm text-yovel-ink font-medium hover:bg-yovel-bg active:scale-90 transition-all duration-200">−</button>
                                <span class="w-8 text-center font-bold text-yovel-ink">{{ $pendingQty }}</span>
                                <button type="button" wire:click="$set('pendingQty', {{ $pendingQty + 1 }})" class="w-11 h-11 bg-white rounded-lg shadow-sm text-yovel-ink font-medium hover:bg-yovel-bg active:scale-90 transition-all duration-200">+</button>
                            </div>
                            <button wire:click="confirmAddToCart" class="flex-1 min-h-[44px] bg-primary-700 hover:bg-primary-900 text-white py-3 rounded-xl font-medium shadow-md transition-all duration-200 active:scale-[0.97]">
                                Tambahkan
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- KOLOM KANAN: keranjang + pembayaran --}}
        <div class="w-full md:w-[350px] lg:w-[380px] xl:w-[420px] h-full bg-white flex flex-col shadow-sm rounded-2xl border border-yovel-border shrink-0 overflow-hidden">
            <div class="p-5 border-b border-yovel-border shrink-0 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-[17px] text-yovel-ink flex items-center gap-2">
                        Pesanan Saat Ini
                    </h3>
                    <div class="bg-yovel-ink text-white text-[11px] font-bold px-2.5 py-1 rounded-full leading-none">{{ count($this->cartLines) }}</div>
                </div>

                <x-search-input id="search-input" wire:model.live.debounce.300ms="search" placeholder="Scan QR Pesanan (F2)..." icon="qr_code_scanner" autofocus />

                {{-- [OMEGA-NODE2] Pemilihan Pelanggan (Loyalty) -- hanya untuk
                     mode Walk-in Baru, lihat SYNC ALERT #3 untuk mode Tarik Pesanan. --}}
                @unless ($pendingOrderId)
                    <div class="relative" x-data="{ showResults: false }">
                        @if ($customerId)
                            <div class="flex items-center justify-between gap-2 bg-yovel-bg rounded-xl px-4 py-2.5 min-h-[44px]">
                                <span class="flex items-center gap-2 text-[13px] font-bold text-yovel-ink truncate">
                                    <span class="material-symbols-rounded text-[18px] text-primary-500">person</span>
                                    {{ $customerName }}
                                </span>
                                <button type="button" wire:click="clearCustomer" aria-label="Hapus pelanggan"
                                        class="text-primary-400 hover:text-rose-500 transition-colors duration-200">
                                    <span class="material-symbols-rounded text-[18px]">close</span>
                                </button>
                            </div>
                        @else
                            <x-search-input wire:model.live.debounce.300ms="customerSearch"
                                   x-on:focus="showResults = true"
                                   x-on:click.outside="showResults = false"
                                   placeholder="Tambah pelanggan (opsional)..."
                                   icon="person_search" />

                            @if (mb_strlen(trim($customerSearch)) >= 2)
                                <div x-show="showResults" x-cloak wire:loading.class="opacity-50" wire:target="customerSearch"
                                     class="absolute z-40 mt-1 w-full bg-white rounded-xl border border-yovel-border shadow-lg overflow-hidden max-h-56 overflow-y-auto custom-scrollbar">
                                    @forelse ($this->customerResults as $c)
                                        <button type="button" wire:key="customer-result-{{ $c->id }}"
                                                wire:click="selectCustomer({{ $c->id }}, '{{ addslashes($c->name) }}')"
                                                x-on:click="showResults = false"
                                                class="w-full min-h-[44px] flex items-center justify-between gap-2 px-4 py-2 text-left hover:bg-yovel-bg transition-colors duration-150">
                                            <span class="text-[13px] font-bold text-yovel-ink truncate">{{ $c->name }}</span>
                                            <span class="text-[11px] font-medium text-yovel-muted shrink-0">{{ $c->phone ?? '-' }}</span>
                                        </button>
                                    @empty
                                        <div class="px-4 py-3 text-[13px] text-yovel-muted text-center">Pelanggan tidak ditemukan.</div>
                                    @endforelse
                                </div>
                            @endif
                        @endif
                    </div>
                @endunless

                {{-- Alert Mode Tarik Pesanan --}}
                @if($pendingOrderCode)
                    <div class="bg-amber-50 rounded-xl p-3 flex justify-between items-center border border-amber-100">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-amber-600 block mb-0.5">Membayar Pesanan Self-Order</span>
                            <span class="font-bold text-[14px] text-yovel-ink">{{ $pendingOrderCode }}</span>
                        </div>
                        <button wire:click="cancelPendingOrderMode" class="text-amber-600 hover:text-amber-800 transition-colors duration-200">
                            <span class="material-symbols-rounded text-[20px]">close</span>
                        </button>
                    </div>
                @endif
            </div>

            <div class="flex-1 overflow-y-auto bg-white custom-scrollbar px-5">
                @if(count($this->cartLines) === 0)
                    <x-empty-state icon="shopping_bag" title="Keranjang kosong" description="Pesanan akan muncul di sini" class="py-16 border-none bg-transparent shadow-none" />
                @else
                    <div class="flex flex-col py-2 gap-4 mt-2">
                        @foreach ($this->cartLines as $line)
                            <div class="flex gap-3 group relative">
                                <div class="w-8 h-8 rounded-lg bg-yovel-bg flex items-center justify-center text-yovel-ink font-bold text-[13px] shrink-0 mt-0.5">
                                    {{ $line['qty'] }}x
                                </div>
                                <div class="flex-1 min-w-0 pb-4 {{ !$loop->last ? 'border-b border-yovel-border/60' : '' }}">
                                    <div class="flex justify-between items-start gap-2">
                                        <div class="pr-2">
                                            <h4 class="text-[14px] font-bold text-yovel-ink leading-tight">{{ $line['product_name'] }}</h4>
                                            @if(count($line['options']) > 0 || $line['notes'])
                                                <p class="text-[12px] text-yovel-muted mt-1 leading-snug">
                                                    {{ collect($line['options'])->pluck('name')->join(', ') }}
                                                    @if($line['notes'])
                                                        <span class="block text-primary-500 mt-0.5 font-medium">{{ $line['notes'] }}</span>
                                                    @endif
                                                </p>
                                            @endif
                                        </div>
                                        <div class="flex flex-col items-end shrink-0">
                                            <div class="text-[14px] font-bold text-yovel-ink whitespace-nowrap">Rp {{ number_format($line['order_price'], 0, ',', '.') }}</div>
                                            <button wire:click="removeFromCart({{ $line['index'] }})" class="mt-1 text-yovel-muted hover:text-rose-500 opacity-0 group-hover:opacity-100 transition-opacity">
                                                <span class="material-symbols-rounded text-[18px]">delete</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="p-5 border-t border-yovel-border bg-white shrink-0">
                {{-- Diskon Selector --}}
                @if(count($this->activeDiscounts) > 0)
                <div class="mb-4">
                    <select wire:model.live="selectedDiscountId" class="bg-yovel-bg text-yovel-ink text-[13px] font-semibold rounded-xl focus:ring-2 focus:ring-yovel-ink focus:outline-none block w-full px-4 py-2.5 transition-all duration-200 cursor-pointer appearance-none">
                        <option value="">+ Tambah Diskon (Opsional)</option>
                        @foreach($this->activeDiscounts as $d)
                            <option value="{{ $d->id }}">{{ $d->name }} ({{ $d->type === 'percentage' ? $d->value . '%' : 'Rp ' . number_format($d->value, 0, ',', '.') }})</option>
                        @endforeach
                    </select>
                </div>
                @endif

                <div class="space-y-2.5 mb-5">
                    <div class="flex justify-between text-[13px] text-yovel-muted font-medium">
                        <span>Subtotal</span>
                        <span class="text-yovel-ink font-semibold">Rp {{ number_format($this->subtotal, 0, ',', '.') }}</span>
                    </div>
                    @if($this->discountAmount > 0)
                    <div class="flex justify-between text-[13px] text-rose-500 font-semibold">
                        <span>Diskon</span>
                        <span>- Rp {{ number_format($this->discountAmount, 0, ',', '.') }}</span>
                    </div>
                    @endif
                    <div class="flex justify-between text-[13px] text-yovel-muted font-medium">
                        <span>Pajak ({{ rtrim(rtrim(number_format(config('pos.tax_rate', 0.11) * 100, 1), '0'), '.') }}%)</span>
                        <span class="text-yovel-ink font-semibold">Rp {{ number_format($this->taxAmount, 0, ',', '.') }}</span>
                    </div>
                </div>

                {{-- [OMEGA-NODE2] Dua jalur pembayaran: Tarik Pesanan (tunggal, UNCHANGED)
                     vs Walk-in Baru (Split Payment via modal). --}}
                @if ($pendingOrderId)
                    <div class="flex items-center justify-between mb-4 pt-4 border-t border-yovel-border">
                        <span class="text-[15px] font-black text-yovel-ink">Total</span>
                        <span class="text-[20px] font-black text-yovel-ink tracking-tight">Rp {{ number_format($this->totalAmount, 0, ',', '.') }}</span>
                    </div>

                    <div class="space-y-3 mb-4">
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-yovel-muted font-bold text-[14px]">Rp</span>
                            <input type="number" wire:model.live="cashReceived"
                                   class="bg-yovel-bg text-yovel-ink text-base rounded-xl focus:outline-none focus:bg-white focus:ring-2 focus:ring-yovel-ink block w-full pl-11 pr-4 py-3 transition-all duration-200 font-bold"
                                   placeholder="0 (Uang Diterima)">
                        </div>

                        <div class="flex justify-between items-center px-4 py-3 bg-yovel-bg rounded-xl">
                            <span class="text-[13px] font-bold text-yovel-muted">Kembalian</span>
                            <span class="font-bold text-yovel-ink text-[15px]">Rp {{ number_format($this->changeAmount, 0, ',', '.') }}</span>
                        </div>
                    </div>

                    @error('cart')
                        <div class="mb-4 p-3 bg-rose-50 text-rose-700 rounded-xl text-[13px] font-bold flex items-start gap-2">
                            <span class="material-symbols-rounded text-[18px]">error</span> {{ $message }}
                        </div>
                    @enderror

                    <button wire:click="submitOrder" id="btn-bayar" class="w-full h-[52px] bg-yovel-ink hover:bg-opacity-90 text-white rounded-xl font-bold text-[15px] shadow-lg transition-all duration-300 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                            :disabled="count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && !$tableId)">
                        Bayar Sekarang
                    </button>
                @else
                    @error('cart')
                        <div class="mb-4 p-3 bg-rose-50 text-rose-700 rounded-xl text-[13px] font-bold flex items-start gap-2">
                            <span class="material-symbols-rounded text-[18px]">error</span> {{ $message }}
                        </div>
                    @enderror

                    <button type="button" id="btn-bayar" class="w-full h-[60px] bg-yovel-ink hover:bg-opacity-90 text-white rounded-xl font-bold shadow-lg transition-all duration-300 active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed px-5 flex items-center justify-between"
                            @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }} }); $dispatch('open-modal', 'checkout-payment')"
                            :disabled="count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && !$tableId)">
                        <span class="text-[15px]">Bayar</span>
                        <span class="text-[18px] tracking-tight">Rp {{ number_format($this->totalAmount, 0, ',', '.') }}</span>
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- [OMEGA-NODE2] Split Payment Modal -- murni Alpine.js, exact-sum,
         hanya dikirim ke server saat "Proses Transaksi" ditekan. --}}
    <x-modal name="checkout-payment" max-width="lg" focusable>
        <div x-data="checkoutPayment()" x-init="init()" class="flex flex-col max-h-[85vh]">
            <div class="px-5 py-4 border-b border-yovel-border flex items-center justify-between shrink-0">
                <h3 class="font-bold text-lg text-yovel-ink flex items-center gap-2">
                    <span class="material-symbols-rounded text-[22px]">payments</span> Pembayaran
                </h3>
                <button type="button" x-on:click="$dispatch('close')" aria-label="Tutup"
                        class="min-w-[44px] min-h-[44px] flex items-center justify-center rounded-xl text-yovel-muted hover:bg-yovel-surface hover:text-yovel-ink transition-all duration-200 active:scale-90">
                    <span class="material-symbols-rounded text-[22px]">close</span>
                </button>
            </div>

            <div class="p-5 overflow-y-auto flex-1 custom-scrollbar space-y-5 bg-yovel-bg">
                <!-- Ringkasan tagihan -->
                <div class="bg-white rounded-2xl border border-yovel-border p-4 shadow-sm space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-yovel-muted font-medium">Total Tagihan</span>
                        <span class="font-bold text-yovel-ink tabular-nums" x-text="'Rp ' + formatRupiah(total)"></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-yovel-muted font-medium">Sudah Dibayar</span>
                        <span class="font-semibold text-emerald-600 tabular-nums" x-text="'Rp ' + formatRupiah(paidTotal)"></span>
                    </div>
                    <div class="flex justify-between items-baseline pt-2 border-t border-yovel-border">
                        <span class="font-bold text-yovel-ink">Sisa Tagihan</span>
                        <span class="text-2xl font-black tabular-nums" :class="remaining > 0 ? 'text-rose-600' : 'text-emerald-600'" x-text="'Rp ' + formatRupiah(remaining)"></span>
                    </div>
                </div>

                <!-- Daftar leg yang sudah ditambahkan -->
                <div x-show="legs.length > 0" x-cloak class="space-y-2">
                    <template x-for="leg in legs" :key="leg.uid">
                        <div class="flex items-center justify-between gap-3 bg-white rounded-xl border border-yovel-border p-3 shadow-sm">
                            <div class="min-w-0">
                                <span class="block text-sm font-bold text-yovel-ink" x-text="leg.label"></span>
                                <span class="block text-xs text-yovel-muted tabular-nums" x-text="'Rp ' + formatRupiah(leg.amount) + (leg.changeShown > 0 ? ' · Kembalian Rp ' + formatRupiah(leg.changeShown) : '')"></span>
                            </div>
                            <button type="button" x-on:click="removeLeg(leg.uid)" aria-label="Hapus metode ini"
                                    class="min-w-[44px] min-h-[44px] flex items-center justify-center rounded-lg text-primary-400 hover:text-rose-500 hover:bg-rose-50 transition-all duration-200 active:scale-90 shrink-0">
                                <span class="material-symbols-rounded text-[20px]">delete</span>
                            </button>
                        </div>
                    </template>
                </div>

                <!-- Form tambah metode -->
                <div x-show="remaining > 0" x-cloak class="bg-white rounded-2xl border border-yovel-border p-4 shadow-sm space-y-4">
                    <div>
                        <span class="block text-xs font-bold uppercase tracking-wider text-yovel-muted mb-2">Metode Pembayaran</span>
                        <div class="grid grid-cols-4 gap-2">
                            <button type="button" x-on:click="method = 'cash'" :class="method === 'cash' ? 'bg-yovel-ink text-white border-yovel-ink' : 'bg-white text-yovel-muted border-yovel-border hover:bg-yovel-bg'"
                                    class="min-h-[52px] rounded-xl border-[1.5px] flex flex-col items-center justify-center gap-1 text-[11px] font-bold transition-all duration-200 active:scale-95">
                                <span class="material-symbols-rounded text-[20px]">payments</span> Tunai
                            </button>
                            <button type="button" x-on:click="method = 'qris'" :class="method === 'qris' ? 'bg-yovel-ink text-white border-yovel-ink' : 'bg-white text-yovel-muted border-yovel-border hover:bg-yovel-bg'"
                                    class="min-h-[52px] rounded-xl border-[1.5px] flex flex-col items-center justify-center gap-1 text-[11px] font-bold transition-all duration-200 active:scale-95">
                                <span class="material-symbols-rounded text-[20px]">qr_code_2</span> QRIS
                            </button>
                            <button type="button" x-on:click="method = 'ewallet'" :class="method === 'ewallet' ? 'bg-yovel-ink text-white border-yovel-ink' : 'bg-white text-yovel-muted border-yovel-border hover:bg-yovel-bg'"
                                    class="min-h-[52px] rounded-xl border-[1.5px] flex flex-col items-center justify-center gap-1 text-[11px] font-bold transition-all duration-200 active:scale-95">
                                <span class="material-symbols-rounded text-[20px]">account_balance_wallet</span> E-Wallet
                            </button>
                            <button type="button" x-on:click="method = 'card'" :class="method === 'card' ? 'bg-yovel-ink text-white border-yovel-ink' : 'bg-white text-yovel-muted border-yovel-border hover:bg-yovel-bg'"
                                    class="min-h-[52px] rounded-xl border-[1.5px] flex flex-col items-center justify-center gap-1 text-[11px] font-bold transition-all duration-200 active:scale-95">
                                <span class="material-symbols-rounded text-[20px]">credit_card</span> Kartu
                            </button>
                        </div>
                    </div>

                    <div>
                        <span class="block text-xs font-bold uppercase tracking-wider text-yovel-muted mb-2" x-text="method === 'cash' ? 'Uang Diterima' : 'Nominal Dibayar'"></span>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-4 font-bold text-primary-400 text-lg">Rp</span>
                            <input type="text" inputmode="numeric" :value="amountFormatted" x-on:input="setAmountInput($event)"
                                   class="w-full min-h-[56px] pl-12 pr-4 bg-yovel-bg border border-yovel-border rounded-xl focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink focus:bg-white outline-none text-xl font-black text-yovel-ink transition-all duration-200"
                                   placeholder="0">
                        </div>
                        <p x-show="method === 'cash' && changePreview > 0" x-cloak class="mt-2 text-sm font-semibold text-emerald-600">
                            Kembalian: <span x-text="'Rp ' + formatRupiah(changePreview)"></span>
                        </p>
                    </div>

                    <div class="flex gap-2">
                        <button type="button" x-on:click="fillRemaining()"
                                class="flex-1 min-h-[44px] text-sm font-black rounded-xl bg-primary-700 text-white hover:bg-primary-900 shadow-sm transition-all duration-200 active:scale-95">PAS</button>
                        <template x-if="method === 'cash'">
                            <button type="button" x-on:click="addQuick(50000)"
                                    class="flex-1 min-h-[44px] text-sm font-bold rounded-xl bg-yovel-surface text-yovel-muted hover:bg-primary-200 hover:text-yovel-ink transition-all duration-200 active:scale-95">+50K</button>
                        </template>
                        <template x-if="method === 'cash'">
                            <button type="button" x-on:click="addQuick(100000)"
                                    class="flex-1 min-h-[44px] text-sm font-bold rounded-xl bg-yovel-surface text-yovel-muted hover:bg-primary-200 hover:text-yovel-ink transition-all duration-200 active:scale-95">+100K</button>
                        </template>
                    </div>

                    <p x-show="errorMsg" x-cloak class="text-sm font-medium text-rose-600" x-text="errorMsg"></p>

                    <button type="button" x-on:click="addLeg()"
                            class="w-full min-h-[52px] rounded-xl bg-yovel-surface text-yovel-ink font-bold text-sm border border-yovel-border hover:bg-primary-200 transition-all duration-200 active:scale-[0.98] flex items-center justify-center gap-2">
                        <span class="material-symbols-rounded text-[20px]">add_circle</span> Tambah Metode Ini
                    </button>
                </div>

                @error('cart')
                    <div class="p-3 bg-rose-50 text-rose-700 border border-rose-200 rounded-xl text-sm font-medium flex items-start gap-2">
                        <span class="material-symbols-rounded text-[18px] mt-0.5">error</span> {{ $message }}
                    </div>
                @enderror
            </div>

            <div class="p-4 border-t border-yovel-border bg-white shrink-0">
                <button type="button" x-on:click="submit()" :disabled="remaining > 0 || legs.length === 0 || submitting"
                        class="w-full min-h-[56px] rounded-xl bg-primary-700 hover:bg-primary-900 text-white font-bold text-lg shadow-md transition-all duration-200 active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                    <span x-show="submitting" class="h-5 w-5 animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true"></span>
                    <span x-text="submitting ? 'Memproses...' : 'Proses Transaksi'"></span>
                </button>
            </div>
        </div>
    </x-modal>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('checkoutPayment', () => ({
                total: 0,
                legs: [],
                method: 'cash',
                amountRaw: 0,
                amountFormatted: '',
                submitting: false,
                errorMsg: '',
                idempotencyKey: null,

                init() {
                    window.addEventListener('open-checkout-modal', (e) => {
                        this.total = e.detail.total;
                        this.legs = [];
                        this.method = 'cash';
                        this.amountRaw = 0;
                        this.amountFormatted = '';
                        this.errorMsg = '';
                        this.submitting = false;
                        this.idempotencyKey = (window.crypto && window.crypto.randomUUID)
                            ? window.crypto.randomUUID()
                            : (Date.now().toString(36) + Math.random().toString(36).slice(2));
                    });
                },

                get paidTotal() {
                    return this.legs.reduce((s, l) => s + l.amount, 0);
                },
                get remaining() {
                    return Math.max(0, this.total - this.paidTotal);
                },
                get changePreview() {
                    if (this.method !== 'cash') return 0;
                    return Math.max(0, (this.amountRaw || 0) - this.remaining);
                },

                formatRupiah(n) {
                    return new Intl.NumberFormat('id-ID').format(Math.round(n || 0));
                },

                setAmountInput(e) {
                    const raw = e.target.value.replace(/[^0-9]/g, '');
                    this.amountRaw = raw === '' ? 0 : parseInt(raw, 10);
                    this.amountFormatted = raw === '' ? '' : this.formatRupiah(this.amountRaw);
                },

                fillRemaining() {
                    this.amountRaw = this.remaining;
                    this.amountFormatted = this.formatRupiah(this.remaining);
                },

                addQuick(amount) {
                    if (this.method !== 'cash') this.method = 'cash';
                    this.amountRaw = (this.amountRaw || 0) + amount;
                    this.amountFormatted = this.formatRupiah(this.amountRaw);
                },

                methodLabel(m) {
                    return { cash: 'Tunai', qris: 'QRIS', ewallet: 'E-Wallet', card: 'Kartu' }[m] ?? m;
                },

                addLeg() {
                    this.errorMsg = '';
                    if (!this.amountRaw || this.amountRaw <= 0) {
                        this.errorMsg = 'Masukkan nominal pembayaran.';
                        return;
                    }
                    if (this.remaining <= 0) {
                        this.errorMsg = 'Tagihan sudah lunas.';
                        return;
                    }
                    const applied = Math.min(this.amountRaw, this.remaining);
                    const change = Math.max(0, this.amountRaw - this.remaining);
                    this.legs.push({
                        uid: Date.now() + Math.random(),
                        method: this.method,
                        label: this.methodLabel(this.method),
                        amount: applied,
                        changeShown: this.method === 'cash' ? change : 0,
                    });
                    this.amountRaw = 0;
                    this.amountFormatted = '';
                },

                removeLeg(uid) {
                    this.legs = this.legs.filter(l => l.uid !== uid);
                },

                async submit() {
                    if (this.remaining > 0 || this.legs.length === 0 || this.submitting) return;
                    this.submitting = true;
                    const payload = this.legs.map(l => ({ method: l.method, amount: l.amount }));
                    try {
                        await $wire.call('submitOrder', payload, this.idempotencyKey);
                    } finally {
                        this.submitting = false; // Note: akan di-reload oleh listener di bawah
                    }
                },
            }));
        });

        document.addEventListener('livewire:init', () => {
            Livewire.on('transaction-success', async (e) => {
                const orderCode = e[0].orderCode;
                const isCash = e[0].isCash;
                
                // Show loading modal while printing
                Swal.fire({
                    title: 'Memproses Struk...',
                    text: 'Menghubungkan ke printer',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                // Hardware Drawer Kick
                if (isCash && {{ config('pos.auto_open_drawer') ? 'true' : 'false' }}) {
                    await window.YovelPrint.kickDrawer(orderCode);
                }
                
                // Silent Print
                const printed = await window.YovelPrint.printReceipt(orderCode);
                
                if (!printed) {
                    // Fallback to manual print dialog window
                    window.open(`/transaction/receipt/${orderCode}?autoprint=1`, '_blank', 'width=400,height=600');
                }
                
                window.location.reload();
            });
        });
    </script>
</div>
