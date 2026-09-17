<div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 p-6">
        {{-- KOLOM KIRI: pilih order type + produk --}}
        <div class="lg:col-span-2">
            <!-- Tipe Pesanan & Meja -->
            <div class="card-surface p-5 mb-6 flex flex-col sm:flex-row items-center gap-6 bg-white">
                <div class="flex gap-4 p-1 bg-[#F1F1EF] rounded-lg">
                    <label class="flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="orderType" value="dine_in" class="peer sr-only">
                        <span class="px-4 py-2 rounded-md text-sm font-medium transition-all peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-[#37352F] text-[#787774] hover:text-[#37352F]">
                            Dine-In
                        </span>
                    </label>
                    <label class="flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="orderType" value="takeaway" class="peer sr-only">
                        <span class="px-4 py-2 rounded-md text-sm font-medium transition-all peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-[#37352F] text-[#787774] hover:text-[#37352F]">
                            Takeaway
                        </span>
                    </label>
                </div>

                @if ($orderType === 'dine_in')
                    <div class="flex-1 w-full sm:w-auto">
                        <select wire:model="tableId" class="bg-white border-[#E9E9E7] text-[#37352F] text-sm rounded-lg focus:ring-[#37352F] focus:border-[#37352F] block w-full p-2.5 shadow-sm transition">
                            <option value="">-- Pilih Meja --</option>
                            @foreach ($this->activeTables as $table)
                                <option value="{{ $table->id }}">Meja {{ $table->table_number }}</option>
                            @endforeach
                        </select>
                        @error('tableId') <p class="text-rose-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <!-- List Kategori & Produk -->
            <div class="space-y-8">
                @foreach ($this->categories as $category)
                    @if($category->products->count() > 0)
                        <div>
                            <h3 class="text-lg font-bold text-[#37352F] mb-4">{{ $category->category_name }}</h3>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                @foreach ($category->products as $product)
                                    <button wire:click="openModifierPicker({{ $product->id }})"
                                            class="card-surface p-4 text-left hover:shadow-md transition-all hover:border-[#9B9A97] active:scale-[0.98] flex flex-col justify-between h-full bg-white group">
                                        <div class="font-semibold text-sm text-[#37352F] leading-snug group-hover:text-black">{{ $product->product_name }}</div>
                                        <div class="text-[#37352F] font-bold mt-3 text-sm">Rp {{ number_format($product->product_price, 0, ',', '.') }}</div>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            {{-- MODAL pemilihan modifier --}}
            @if ($selectingProductId && isset($selectingProduct))
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm" wire:transition>
                    <div class="bg-white rounded-2xl p-0 w-full max-w-md max-h-[90vh] flex flex-col shadow-2xl border border-[#E9E9E7]">
                        
                        <!-- Header Modal -->
                        <div class="p-4 border-b border-[#E9E9E7] flex justify-between items-center bg-white rounded-t-2xl z-10">
                            <h3 class="font-bold text-lg text-[#37352F]">{{ $selectingProduct->product_name }}</h3>
                            <button wire:click="closeModifierPicker" class="p-2 bg-[#F1F1EF] rounded-full text-[#787774] hover:text-[#37352F] hover:bg-[#E3E2E0] transition">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>

                        <!-- Body Modal (Modifiers) -->
                        <div class="p-4 overflow-y-auto flex-1 bg-[#F7F7F5]">
                            @foreach ($selectingProduct->modifierGroups as $group)
                                <div class="mb-5 bg-white p-4 rounded-xl border border-[#E9E9E7] shadow-sm">
                                    <div class="flex justify-between items-end mb-3 border-b border-[#F1F1EF] pb-2">
                                        <h4 class="font-bold text-[#37352F]">{{ $group->name }}</h4>
                                        <span class="text-xs px-2 py-1 rounded bg-[#F7F7F5] text-[#787774] font-medium">
                                            {{ $group->is_required ? ($group->selection_type === 'single' ? 'Wajib pilih 1' : 'Wajib pilih') : 'Opsional' }}
                                        </span>
                                    </div>
                                    
                                    <div class="space-y-2">
                                        @foreach ($group->modifiers as $mod)
                                            @php
                                                $isSelected = in_array($mod->id, $pendingModifierIds);
                                            @endphp
                                            <!-- Modifier Box Component -->
                                            <label class="modifier-box {{ $isSelected ? 'selected' : '' }}">
                                                @if($group->selection_type === 'single')
                                                    <input type="radio" 
                                                           wire:click="toggleModifier({{ $group->id }}, {{ $mod->id }}, 'single')"
                                                           name="group_{{ $group->id }}" 
                                                           {{ $isSelected ? 'checked' : '' }}>
                                                @else
                                                    <input type="checkbox" 
                                                           wire:click="toggleModifier({{ $group->id }}, {{ $mod->id }}, 'multiple')"
                                                           {{ $isSelected ? 'checked' : '' }}>
                                                @endif
                                                <span class="font-medium text-sm transition-colors {{ $isSelected ? 'text-[#37352F]' : 'text-[#55544E]' }}">
                                                    {{ $mod->name }}
                                                </span>
                                                @if($mod->extra_price > 0)
                                                    <span class="text-xs font-semibold text-[#787774]">+Rp {{ number_format($mod->extra_price, 0, ',', '.') }}</span>
                                                @endif
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            <div class="bg-white p-4 rounded-xl border border-[#E9E9E7] shadow-sm">
                                <label class="block font-bold text-[#37352F] mb-2 border-b border-[#F1F1EF] pb-2">Catatan Tambahan</label>
                                <textarea wire:model="pendingNotes" class="w-full bg-[#F7F7F5] border-transparent rounded-lg text-sm focus:border-[#37352F] focus:ring-[#37352F] focus:bg-white transition" rows="2" placeholder="Contoh: Kurangi es, jangan pakai sedotan"></textarea>
                            </div>
                        </div>

                        <!-- Footer Modal -->
                        <div class="p-4 border-t border-[#E9E9E7] bg-white flex items-center justify-between gap-4 rounded-b-2xl">
                            <div class="flex items-center gap-1 bg-[#F1F1EF] rounded-lg p-1 border border-[#E9E9E7]">
                                <button type="button" wire:click="$set('pendingQty', {{ max(1, $pendingQty - 1) }})" class="w-8 h-8 bg-white rounded shadow-sm text-[#37352F] font-medium hover:bg-gray-50 active:scale-95 transition">-</button>
                                <span class="w-8 text-center font-bold text-[#37352F]">{{ $pendingQty }}</span>
                                <button type="button" wire:click="$set('pendingQty', {{ $pendingQty + 1 }})" class="w-8 h-8 bg-white rounded shadow-sm text-[#37352F] font-medium hover:bg-gray-50 active:scale-95 transition">+</button>
                            </div>
                            <button wire:click="confirmAddToCart" class="flex-1 bg-[#37352F] hover:bg-black text-white py-3 rounded-lg font-medium shadow-md transition active:scale-95">
                                Tambahkan
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- KOLOM KANAN: keranjang + pembayaran --}}
        <div>
            <div class="card-surface sticky top-6 bg-white overflow-hidden flex flex-col max-h-[calc(100vh-2rem)]">
                <div class="p-4 border-b border-[#E9E9E7] bg-white">
                    <h3 class="font-bold text-lg text-[#37352F]">Detail Pesanan</h3>
                </div>
                
                <div class="p-4 flex-1 overflow-y-auto bg-[#F7F7F5]">
                    @if(count($this->cartLines) === 0)
                        <div class="text-center text-[#9B9A97] py-12 text-sm flex flex-col items-center">
                            <svg class="w-12 h-12 mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 0a2 2 0 100 4 2 2 0 000-4z"></path></svg>
                            Belum ada item.
                        </div>
                    @else
                        <div class="space-y-3">
                            @foreach ($this->cartLines as $line)
                                <div class="bg-white p-3 rounded-xl border border-[#E9E9E7] shadow-sm relative group">
                                    <div class="flex justify-between items-start gap-2">
                                        <div class="flex-1">
                                            <div class="font-bold text-sm text-[#37352F] leading-tight">{{ $line['product_name'] }}</div>
                                            <div class="text-xs text-[#787774] mt-1 space-y-0.5">
                                                @foreach($line['options'] as $opt)
                                                    <div>- {{ $opt['name'] }}</div>
                                                @endforeach
                                            </div>
                                            @if($line['notes'])
                                                <div class="text-xs font-medium text-[#37352F] bg-[#F1F1EF] px-1.5 py-0.5 rounded mt-1.5 inline-block">Catatan: {{ $line['notes'] }}</div>
                                            @endif
                                            <div class="text-sm font-bold text-[#37352F] mt-2">
                                                Rp {{ number_format($line['order_price'], 0, ',', '.') }} <span class="text-[#9B9A97] font-normal text-xs ml-1">x{{ $line['qty'] }}</span>
                                            </div>
                                        </div>
                                        <div class="flex flex-col items-end justify-between h-full">
                                            <button wire:click="removeFromCart({{ $line['index'] }})" class="text-[#9B9A97] hover:text-rose-500 p-1 bg-white rounded-md hover:bg-rose-50 transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="p-4 border-t border-[#E9E9E7] bg-white space-y-2">
                    <div class="flex justify-between text-sm text-[#787774]">
                        <span>Subtotal</span>
                        <span class="text-[#37352F]">Rp {{ number_format($this->subtotal, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-sm text-[#787774]">
                        <span>Pajak (11%)</span>
                        <span class="text-[#37352F]">Rp {{ number_format($this->taxAmount, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between font-bold text-lg text-[#37352F] border-t border-[#E9E9E7] pt-3 mt-3">
                        <span>Total Tagihan</span>
                        <span>Rp {{ number_format($this->totalAmount, 0, ',', '.') }}</span>
                    </div>
                </div>

                <div class="p-4 border-t border-[#E9E9E7] bg-[#F7F7F5]">
                    <label class="block text-xs font-semibold text-[#787774] mb-1.5 uppercase tracking-wider">Uang Diterima (Cash)</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-[#9B9A97] font-medium">Rp</span>
                        <input type="number" wire:model.live="cashReceived" class="bg-white border-[#E9E9E7] text-[#37352F] text-base rounded-lg focus:ring-[#37352F] focus:border-[#37352F] block w-full pl-10 pr-3 py-2.5 shadow-sm transition font-bold" placeholder="0">
                    </div>
                    
                    <div class="flex justify-between items-center mt-3 p-3 bg-white rounded-lg border border-[#E9E9E7] shadow-sm">
                        <span class="text-sm font-medium text-[#787774]">Kembalian</span>
                        <span class="font-bold text-[#37352F] text-lg">Rp {{ number_format($this->changeAmount, 0, ',', '.') }}</span>
                    </div>

                    @error('cart') 
                        <div class="mt-4 p-3 bg-rose-50 text-rose-700 border border-rose-200 rounded-lg text-sm font-medium flex items-start gap-2">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                            {{ $message }}
                        </div> 
                    @enderror

                    <button wire:click="submitOrder" 
                            class="w-full bg-[#37352F] hover:bg-black text-white py-3.5 px-4 rounded-xl mt-5 font-medium shadow-md transition flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed active:scale-95"
                            @if(count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && !$tableId)) disabled @endif>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        Proses Pembayaran
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
