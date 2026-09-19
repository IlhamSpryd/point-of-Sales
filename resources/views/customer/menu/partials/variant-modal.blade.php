<div x-show="modalOpen" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" x-cloak @keydown.escape.window="modalOpen = false">
    <!-- Backdrop -->
    <div x-show="modalOpen" x-transition.opacity.duration.300ms class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="modalOpen = false"></div>
    
    <template x-if="modalOpen && activeProduct">
        <div x-data="{
                loading: true,
                product: null,
                groups: [],
                selected: {},
                qty: 1,
                notes: '',
                submitting: false,

                async init() {
                    this.loading = true;
                    const res = await fetch(`/menu/${activeProduct}/modifiers`);
                    const data = await res.json();
                    this.product = data.product;
                    this.groups = data.modifier_groups;

                    this.groups.forEach(g => {
                        const def = g.modifiers.find(m => m.is_default);
                        this.selected[g.id] = def ? [def.id] : [];
                    });

                    this.loading = false;
                },

                toggleOption(group, modifierId) {
                    if (group.selection_type === 'single') {
                        this.selected[group.id] = [modifierId];
                    } else {
                        const current = this.selected[group.id] || [];
                        this.selected[group.id] = current.includes(modifierId)
                            ? current.filter(id => id !== modifierId)
                            : [...current, modifierId];
                    }
                },

                isSelected(groupId, modifierId) {
                    return (this.selected[groupId] || []).includes(modifierId);
                },

                get totalPrice() {
                    let base = this.product?.price ?? 0;
                    let extra = 0;
                    this.groups.forEach(g => {
                        (this.selected[g.id] || []).forEach(mid => {
                            const mod = g.modifiers.find(m => m.id === mid);
                            if (mod) extra += mod.extra_price;
                        });
                    });
                    return (base + extra) * this.qty;
                },

                async addToCart() {
                    this.submitting = true;
                    const modifierIds = Object.values(this.selected).flat();

                    const res = await fetch('{{ route('customer.cart.store') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify({
                            product_id: activeProduct,
                            qty: this.qty,
                            modifier_ids: modifierIds,
                            notes: this.notes,
                        }),
                    });
                    const data = await res.json();

                    window.customerCartCount = data.total_qty;
                    window.dispatchEvent(new CustomEvent('cart-updated', { detail: data }));
                    this.submitting = false;
                    modalOpen = false;
                }
            }"
            x-init="init()"
            @click.stop
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-y-full sm:translate-y-8 sm:scale-95 opacity-0 sm:opacity-0"
            x-transition:enter-end="translate-y-0 sm:scale-100 opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-y-0 sm:scale-100 opacity-100"
            x-transition:leave-end="translate-y-full sm:translate-y-8 sm:scale-95 opacity-0"
            class="relative bg-white w-full max-w-md rounded-t-3xl sm:rounded-3xl overflow-hidden flex flex-col max-h-[85vh] shadow-2xl z-10">
            
            <!-- Handle (Mobile only) -->
            <div class="w-full flex sm:hidden justify-center pt-3 pb-2 bg-white rounded-t-3xl shrink-0 cursor-pointer" @click="modalOpen = false">
                <div class="w-12 h-1.5 bg-gray-200 rounded-full"></div>
            </div>

            <!-- Loading State -->
            <template x-if="loading">
                <div class="flex flex-col items-center justify-center py-16 px-6">
                    <div class="w-8 h-8 border-2 border-gray-200 border-t-[#37352F] rounded-full animate-spin mb-4"></div>
                    <p class="text-sm text-gray-500 font-medium">Memuat varian...</p>
                </div>
            </template>

            <template x-if="!loading && product">
                <div class="flex flex-col max-h-[85vh]">
                    <!-- Product Header Info -->
                    <div class="px-5 pt-2 sm:pt-6 pb-4 border-b border-gray-100 shrink-0 relative">
                        <button @click="modalOpen = false" class="hidden sm:flex absolute right-4 top-4 p-2 rounded-xl bg-gray-50 text-gray-400 hover:text-gray-900 transition active:scale-90">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                        <h2 class="text-xl font-bold text-gray-900 pr-8" x-text="product.name"></h2>
                        <div class="mt-2 text-lg font-bold text-gray-900" x-text="'Rp ' + (product.price || 0).toLocaleString('id-ID')"></div>
                    </div>

                    <!-- Scrollable Options -->
                    <div class="flex-1 overflow-y-auto px-5 py-4 space-y-6 hide-scrollbar pb-32">
                        <template x-for="group in groups" :key="group.id">
                            <div>
                                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3 flex items-center justify-between">
                                    <span x-text="group.name"></span>
                                    <span class="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded" x-text="group.selection_type === 'single' ? 'Pilih 1' : 'Multi'"></span>
                                </h3>
                                
                                <template x-if="group.selection_type === 'single'">
                                    <div class="grid grid-cols-2 gap-3">
                                        <template x-for="mod in group.modifiers" :key="mod.id">
                                            <!-- Custom Radio Button-Box -->
                                            <div @click="toggleOption(group, mod.id)" 
                                                :class="isSelected(group.id, mod.id) ? 'border-[#37352F] bg-gray-50' : 'border-gray-200'"
                                                class="border rounded-2xl p-4 flex flex-col items-center justify-center cursor-pointer transition relative overflow-hidden h-full min-h-[70px]">
                                                <span class="text-sm font-bold text-center leading-tight z-10" :class="isSelected(group.id, mod.id) ? 'text-[#37352F]' : 'text-gray-600'" x-text="mod.name"></span>
                                                <span x-show="mod.extra_price > 0" class="text-[11px] mt-1 z-10" :class="isSelected(group.id, mod.id) ? 'text-gray-600' : 'text-gray-400'" x-text="'+Rp ' + mod.extra_price.toLocaleString('id-ID')"></span>
                                                
                                                <!-- Small Check Indicator -->
                                                <div x-show="isSelected(group.id, mod.id)" class="absolute top-2 right-2">
                                                    <svg class="w-4 h-4 text-[#37352F]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <template x-if="group.selection_type === 'multiple'">
                                    <div class="flex flex-col gap-2">
                                        <template x-for="mod in group.modifiers" :key="mod.id">
                                            <!-- List Item style for Multiple Selection -->
                                            <div @click="toggleOption(group, mod.id)" 
                                                :class="isSelected(group.id, mod.id) ? 'border-[#37352F] bg-gray-50' : 'border-gray-200'"
                                                class="border rounded-2xl p-4 flex justify-between items-center cursor-pointer transition">
                                                <div>
                                                    <span class="text-sm font-semibold" :class="isSelected(group.id, mod.id) ? 'text-[#37352F]' : 'text-gray-600'" x-text="mod.name"></span>
                                                    <p x-show="mod.extra_price > 0" class="text-[11px] text-gray-500" x-text="'+Rp ' + mod.extra_price.toLocaleString('id-ID')"></p>
                                                </div>
                                                <div class="w-5 h-5 rounded-md border flex items-center justify-center transition-colors" :class="isSelected(group.id, mod.id) ? 'border-[#37352F] bg-[#37352F]' : 'border-gray-300'">
                                                    <svg x-show="isSelected(group.id, mod.id)" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <!-- Notes -->
                        <div>
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Catatan</p>
                            <textarea x-model="notes" rows="2" placeholder="Contoh: Kurangi es, tanpa sedotan..."
                                      class="w-full bg-gray-50 border border-gray-200 rounded-xl text-sm text-gray-900 placeholder:text-gray-400 focus:border-[#37352F] focus:ring-1 focus:ring-[#37352F] focus:bg-white transition-all duration-200 px-4 py-3 resize-none"></textarea>
                        </div>
                    </div>

                    <!-- Sticky Footer Action -->
                    <div class="absolute bottom-0 inset-x-0 p-5 bg-white border-t border-gray-100 z-10 flex flex-col gap-3 pb-safe">
                        <!-- Qty Selector -->
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-gray-900">Jumlah</span>
                            <div class="flex items-center gap-4 bg-gray-50 rounded-full border border-gray-200 p-1">
                                <button type="button" @click="qty = Math.max(1, qty - 1)"
                                        class="w-8 h-8 rounded-full bg-white flex items-center justify-center text-gray-600 font-bold shadow-sm active:scale-90 transition">−</button>
                                <span class="w-4 text-center font-bold text-gray-900 text-sm" x-text="qty"></span>
                                <button type="button" @click="qty++"
                                        class="w-8 h-8 rounded-full bg-white flex items-center justify-center text-gray-600 font-bold shadow-sm active:scale-90 transition">+</button>
                            </div>
                        </div>

                        <!-- Add to Cart Button -->
                        <button type="button" @click="addToCart()" :disabled="submitting"
                                class="w-full bg-[#37352F] hover:bg-black text-white rounded-2xl py-4 font-bold active:scale-95 transition flex justify-center items-center gap-2 shadow-lg disabled:opacity-50">
                            <div x-show="submitting" class="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></div>
                            <span x-show="!submitting">Tambah ke Pesanan</span>
                            <span x-show="!submitting" class="w-1 h-1 bg-gray-400 rounded-full mx-1"></span>
                            <span x-show="!submitting" x-text="'Rp ' + totalPrice.toLocaleString('id-ID')"></span>
                            <span x-show="submitting">Menambahkan...</span>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </template>
</div>
