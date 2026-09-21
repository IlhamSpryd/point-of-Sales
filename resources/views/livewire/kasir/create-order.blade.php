<div id="livewire-pos-root" data-testid="lw-pos-root" dusk="lw-pos-root">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 h-[calc(100vh-5rem)]">
        {{-- KOLOM KIRI: pilih order type + produk --}}
        <div class="lg:col-span-2 flex flex-col overflow-hidden">
            <!-- Tipe Pesanan & Meja -->
            <div class="card-surface p-5 mb-6 flex flex-col sm:flex-row items-center gap-6 bg-white shrink-0">
                <div class="flex gap-1 p-1 bg-yovel-surface rounded-xl">
                    <label class="flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="orderType" value="dine_in" class="peer sr-only">
                        <span class="px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200 peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-yovel-ink text-yovel-muted hover:text-yovel-ink active:scale-95">
                            Dine-In
                        </span>
                    </label>
                    <label class="flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="orderType" value="takeaway" class="peer sr-only">
                        <span class="px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200 peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-yovel-ink text-yovel-muted hover:text-yovel-ink active:scale-95">
                            Takeaway
                        </span>
                    </label>
                </div>

                @if ($orderType === 'dine_in')
                    <div class="flex-1 w-full sm:w-auto">
                        <select wire:model="tableId" class="bg-white border border-yovel-border text-yovel-ink text-sm rounded-xl focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink block w-full p-2.5 shadow-sm transition-all duration-200">
                            <option value="">-- Pilih Meja --</option>
                            @foreach ($this->activeTables as $table)
                                <option value="{{ $table->id }}">Meja {{ $table->table_number }}</option>
                            @endforeach
                        </select>
                        @error('tableId') <p class="text-rose-500 text-xs mt-1 font-medium">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <!-- List Kategori & Produk (scrollable) -->
            <div class="flex-1 overflow-y-auto space-y-8 pr-1 custom-scrollbar">
                @foreach ($this->categories as $category)
                    @if($category->products->count() > 0)
                        <div>
                            <h3 class="text-base font-bold text-yovel-ink mb-4 flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-yovel-ink"></span>
                                {{ $category->category_name }}
                            </h3>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                                @foreach ($category->products as $product)
                                    <button wire:click="openModifierPicker({{ $product->id }})"
                                            class="card-surface p-4 text-left hover:shadow-md transition-all duration-200 hover:border-primary-300 active:scale-[0.97] flex flex-col justify-between h-full bg-white group">
                                        <div class="font-semibold text-sm text-yovel-ink leading-snug group-hover:text-primary-900 transition-colors duration-200">{{ $product->product_name }}</div>
                                        <div class="text-yovel-ink font-bold mt-3 text-sm tracking-tight">Rp {{ number_format($product->product_price, 0, ',', '.') }}</div>
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
                            <button wire:click="closeModifierPicker" class="p-2 bg-yovel-surface rounded-xl text-yovel-muted hover:text-yovel-ink hover:bg-primary-200 transition-all duration-200 active:scale-90">
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
                                <button type="button" wire:click="$set('pendingQty', {{ max(1, $pendingQty - 1) }})" class="w-9 h-9 bg-white rounded-lg shadow-sm text-yovel-ink font-medium hover:bg-yovel-bg active:scale-90 transition-all duration-200">−</button>
                                <span class="w-8 text-center font-bold text-yovel-ink">{{ $pendingQty }}</span>
                                <button type="button" wire:click="$set('pendingQty', {{ $pendingQty + 1 }})" class="w-9 h-9 bg-white rounded-lg shadow-sm text-yovel-ink font-medium hover:bg-yovel-bg active:scale-90 transition-all duration-200">+</button>
                            </div>
                            <button wire:click="confirmAddToCart" class="flex-1 bg-primary-700 hover:bg-primary-900 text-white py-3 rounded-xl font-medium shadow-md transition-all duration-200 active:scale-[0.97]">
                                Tambahkan
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- KOLOM KANAN: keranjang + pembayaran --}}
        <div>
            <div class="card-surface sticky top-0 bg-white overflow-hidden flex flex-col max-h-[calc(100vh-5rem)]">
                <div class="p-4 border-b border-yovel-border bg-white shrink-0 flex flex-col gap-3">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-lg text-yovel-ink flex items-center gap-2">
                            <span class="material-symbols-rounded text-[20px]">shopping_cart</span>
                            Detail Pesanan
                        </h3>
                        <span class="bg-yovel-ink text-white text-xs font-bold px-2.5 py-1 rounded-full">{{ count($this->cartLines) }}</span>
                    </div>

                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-yovel-muted">
                            <span class="material-symbols-rounded text-[18px]">qr_code_scanner</span>
                        </span>
                        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Scan QR Pesanan..." class="bg-yovel-bg border border-yovel-border text-yovel-ink text-sm rounded-xl focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink block w-full pl-9 pr-3 py-2 shadow-sm transition-all duration-200" autofocus>
                    </div>

                    {{-- Alert Mode Tarik Pesanan --}}
                    @if($pendingOrderCode)
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 flex justify-between items-center">
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-amber-600 block">Membayar Pesanan Self-Order</span>
                                <span class="font-mono font-bold text-yovel-ink">{{ $pendingOrderCode }}</span>
                            </div>
                            <button wire:click="cancelPendingOrderMode" class="text-rose-500 hover:text-rose-700 p-1 bg-white rounded-lg border border-amber-100 shadow-sm active:scale-90 transition-all duration-200">
                                <span class="material-symbols-rounded text-[20px]">close</span>
                            </button>
                        </div>
                    @endif
                </div>
                
                <div class="p-4 flex-1 overflow-y-auto bg-yovel-bg custom-scrollbar">
                    @if(count($this->cartLines) === 0)
                        <div class="text-center text-primary-400 py-16 text-sm flex flex-col items-center">
                            <div class="w-16 h-16 rounded-2xl bg-yovel-surface flex items-center justify-center mb-4">
                                <span class="material-symbols-rounded text-[32px] text-yovel-border">shopping_cart</span>
                            </div>
                            <p class="font-medium">Belum ada item.</p>
                            <p class="text-xs text-primary-300 mt-1">Pilih produk dari menu di sebelah kiri</p>
                        </div>
                    @else
                        <div class="space-y-3">
                            @foreach ($this->cartLines as $line)
                                <div class="bg-white p-3.5 rounded-xl border border-yovel-border shadow-sm relative group hover:shadow-md transition-all duration-200">
                                    <div class="flex justify-between items-start gap-2">
                                        <div class="flex-1">
                                            <div class="font-bold text-sm text-yovel-ink leading-tight">{{ $line['product_name'] }}</div>
                                            <div class="text-xs text-yovel-muted mt-1 space-y-0.5">
                                                @foreach($line['options'] as $opt)
                                                    <div class="flex items-center gap-1">
                                                        <span class="w-1 h-1 rounded-full bg-primary-300"></span>
                                                        {{ $opt['name'] }}
                                                    </div>
                                                @endforeach
                                            </div>
                                            @if($line['notes'])
                                                <div class="text-xs font-medium text-yovel-ink bg-yovel-surface px-2 py-1 rounded-lg mt-1.5 inline-block">📝 {{ $line['notes'] }}</div>
                                            @endif
                                            <div class="text-sm font-bold text-yovel-ink mt-2">
                                                Rp {{ number_format($line['order_price'], 0, ',', '.') }} <span class="text-primary-400 font-normal text-xs ml-1">×{{ $line['qty'] }}</span>
                                            </div>
                                        </div>
                                        <div class="flex flex-col items-end justify-between h-full">
                                            <button wire:click="removeFromCart({{ $line['index'] }})" class="text-primary-300 hover:text-rose-500 p-1.5 bg-white rounded-lg hover:bg-rose-50 border border-transparent hover:border-rose-200 transition-all duration-200 active:scale-90">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="p-4 border-t border-yovel-border bg-white space-y-2 shrink-0">
                    <div class="flex justify-between text-sm text-yovel-muted">
                        <span>Subtotal</span>
                        <span class="text-yovel-ink font-medium">Rp {{ number_format($this->subtotal, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-sm text-yovel-muted">
                        <span>Pajak (11%)</span>
                        <span class="text-yovel-ink font-medium">Rp {{ number_format($this->taxAmount, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between font-bold text-lg text-yovel-ink border-t border-yovel-border pt-3 mt-3">
                        <span>Total Tagihan</span>
                        <span>Rp {{ number_format($this->totalAmount, 0, ',', '.') }}</span>
                    </div>
                </div>

                <div class="p-4 border-t border-yovel-border bg-yovel-bg shrink-0">
                    <label class="block text-xs font-semibold text-yovel-muted mb-1.5 uppercase tracking-wider">Uang Diterima (Cash)</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary-400 font-medium text-sm">Rp</span>
                        <input type="number" wire:model.live="cashReceived" class="bg-white border border-yovel-border text-yovel-ink text-base rounded-xl focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink block w-full pl-10 pr-3 py-2.5 shadow-sm transition-all duration-200 font-bold" placeholder="0">
                    </div>
                    
                    <div class="flex justify-between items-center mt-3 p-3 bg-white rounded-xl border border-yovel-border shadow-sm">
                        <span class="text-sm font-medium text-yovel-muted">Kembalian</span>
                        <span class="font-bold text-yovel-ink text-lg">Rp {{ number_format($this->changeAmount, 0, ',', '.') }}</span>
                    </div>

                    @error('cart') 
                        <div class="mt-4 p-3 bg-rose-50 text-rose-700 border border-rose-200 rounded-xl text-sm font-medium flex items-start gap-2">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                            {{ $message }}
                        </div> 
                    @enderror

                    <button wire:click="submitOrder" 
                            class="w-full bg-primary-700 hover:bg-primary-900 text-white py-3.5 px-4 rounded-xl mt-5 font-medium shadow-md transition-all duration-200 flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed active:scale-[0.97]"
                            @if(count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && !$tableId)) disabled @endif>
                        <span class="material-symbols-rounded text-[20px]">payments</span>
                        Proses Pembayaran
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
