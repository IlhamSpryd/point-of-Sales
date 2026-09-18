<div x-show="modalOpen"
     x-cloak
     class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
     @keydown.escape.window="modalOpen = false">

    {{-- Overlay --}}
    <div x-show="modalOpen"
         x-transition:enter="ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black/40 backdrop-blur-sm"
         @click="modalOpen = false"></div>

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
            x-transition:enter="ease-[cubic-bezier(0.16,1,0.3,1)] duration-400"
            x-transition:enter-start="opacity-0 translate-y-full sm:translate-y-8 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-full sm:translate-y-8 sm:scale-95"
            class="relative bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[85vh] flex flex-col shadow-2xl border border-[#E9E9E7] overflow-hidden z-10">

            {{-- Loading State --}}
            <template x-if="loading">
                <div class="flex flex-col items-center justify-center py-16 px-6">
                    <div class="w-8 h-8 border-2 border-[#E9E9E7] border-t-[#37352F] rounded-full animate-spin mb-4"></div>
                    <p class="text-sm text-[#787774] font-medium">Memuat pilihan varian...</p>
                </div>
            </template>

            <template x-if="!loading && product">
                <div class="flex flex-col max-h-[85vh]">
                    {{-- Header --}}
                    <div class="px-6 pt-6 pb-4 border-b border-[#E9E9E7] shrink-0">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-bold text-[#37352F]" x-text="product.name"></h2>
                            <button @click="modalOpen = false" class="p-2 rounded-xl bg-[#F1F1EF] text-[#787774] hover:text-[#37352F] hover:bg-[#E3E2E0] transition-all duration-200 active:scale-90">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>
                        <p class="text-sm text-[#787774] mt-1" x-text="'Rp ' + (product.price || 0).toLocaleString('id-ID')"></p>
                    </div>

                    {{-- Body — Modifier Groups --}}
                    <div class="flex-1 overflow-y-auto px-6 py-4 space-y-5">
                        <template x-for="group in groups" :key="group.id">
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <p class="text-xs font-bold uppercase tracking-wider text-[#787774]" x-text="group.name"></p>
                                    <span class="text-[10px] font-medium text-[#9B9A97] bg-[#F7F7F5] px-2 py-0.5 rounded-md"
                                          x-text="group.selection_type === 'single' ? 'Pilih 1' : 'Multi'"></span>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <template x-for="mod in group.modifiers" :key="mod.id">
                                        <button type="button"
                                                @click="toggleOption(group, mod.id)"
                                                :class="isSelected(group.id, mod.id)
                                                    ? 'ring-2 ring-[#37352F] bg-[#F7F7F5] border-transparent text-[#37352F]'
                                                    : 'border-[#E9E9E7] bg-white text-[#55544E] hover:bg-[#F7F7F5] hover:border-[#C4C3C0]'"
                                                class="flex flex-col items-center justify-center p-3 rounded-xl border text-center transition-all duration-200 active:scale-95 min-h-[60px]">
                                            <span class="text-sm font-medium leading-tight" x-text="mod.name"></span>
                                            <span x-show="mod.extra_price > 0" x-text="'+Rp ' + mod.extra_price.toLocaleString('id-ID')" class="text-[11px] text-[#9B9A97] mt-0.5 font-medium"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>

                        {{-- Notes --}}
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-[#787774] mb-2">Catatan</p>
                            <textarea x-model="notes" rows="2" placeholder="Contoh: Kurangi es, tanpa sedotan..."
                                      class="w-full bg-[#F7F7F5] border border-[#E9E9E7] rounded-xl text-sm text-[#37352F] placeholder:text-[#9B9A97] focus:border-[#37352F] focus:ring-1 focus:ring-[#37352F] focus:bg-white transition-all duration-200 px-4 py-3 resize-none"></textarea>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="px-6 py-4 border-t border-[#E9E9E7] bg-white shrink-0">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-sm font-semibold text-[#37352F]">Jumlah</span>
                            <div class="flex items-center gap-3">
                                <button type="button" @click="qty = Math.max(1, qty - 1)" 
                                        class="w-9 h-9 rounded-xl border border-[#E9E9E7] bg-white flex items-center justify-center text-[#37352F] font-medium hover:bg-[#F7F7F5] transition-all duration-200 active:scale-90 shadow-sm">−</button>
                                <span class="w-6 text-center font-bold text-[#37352F]" x-text="qty"></span>
                                <button type="button" @click="qty++" 
                                        class="w-9 h-9 rounded-xl border border-[#E9E9E7] bg-white flex items-center justify-center text-[#37352F] font-medium hover:bg-[#F7F7F5] transition-all duration-200 active:scale-90 shadow-sm">+</button>
                            </div>
                        </div>

                        <button type="button" @click="addToCart()" :disabled="submitting"
                                class="w-full bg-[#37352F] text-white rounded-2xl py-3.5 font-bold text-sm flex items-center justify-center gap-2 hover:bg-black transition-all duration-200 active:scale-[0.98] shadow-lg disabled:opacity-50 disabled:cursor-not-allowed">
                            <span class="material-symbols-rounded" style="font-size: 18px;" x-show="!submitting">add_shopping_cart</span>
                            <div x-show="submitting" class="spinner"></div>
                            <span x-text="submitting ? 'Menambahkan...' : 'Tambah — Rp ' + totalPrice.toLocaleString('id-ID')"></span>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </template>
</div>
