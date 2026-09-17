<div x-show="modalOpen"
     x-cloak
     class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 backdrop-blur-sm"
     @click.self="modalOpen = false">

    {{--
        x-data di sini membuat instance BARU setiap kali activeProduct berubah,
        karena Alpine akan re-init blok ini lewat x-if di bawah (kunci ':key').
    --}}
    <template x-if="modalOpen && activeProduct">
        <div x-data="{
                loading: true,
                product: null,
                groups: [],
                selected: {},   // { [group_id]: [modifier_id, ...] }
                qty: 1,
                notes: '',
                submitting: false,

                async init() {
                    this.loading = true;
                    const res = await fetch(`/menu/${activeProduct}/modifiers`);
                    const data = await res.json();
                    this.product = data.product;
                    this.groups = data.modifier_groups;

                    // Auto-pilih opsi default per grup, supaya user tidak wajib
                    // klik semua kalau sudah ada rekomendasi bawaan (is_default).
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

                    window.customerCartCount = data.total_qty; // dibaca ulang oleh badge di layout
                    this.submitting = false;
                    modalOpen = false;
                }
            }"
            x-init="init()"
            @click.stop
            class="bg-white dark:bg-zinc-900 rounded-t-3xl sm:rounded-3xl w-full sm:max-w-md max-h-[85vh] overflow-y-auto p-6">

            <template x-if="loading">
                <p class="text-center text-sm text-zinc-500 py-10">Memuat pilihan varian...</p>
            </template>

            <template x-if="!loading && product">
                <div>
                    <h2 class="text-lg font-bold" x-text="product.name"></h2>

                    {{-- Loop tiap grup varian: render radio jika single, checkbox jika multiple --}}
                    <template x-for="group in groups" :key="group.id">
                        <div class="mt-5">
                            <p class="text-xs font-bold uppercase tracking-wide text-zinc-500 mb-2" x-text="group.name"></p>
                            <div class="space-y-2">
                                <template x-for="mod in group.modifiers" :key="mod.id">
                                    <button type="button"
                                            @click="toggleOption(group, mod.id)"
                                            :class="isSelected(group.id, mod.id) ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 dark:border-zinc-700'"
                                            class="w-full flex items-center justify-between px-4 py-2.5 rounded-xl border text-sm font-medium transition-colors">
                                        <span x-text="mod.name"></span>
                                        <span x-show="mod.extra_price > 0" x-text="'+Rp ' + mod.extra_price.toLocaleString('id-ID')" class="text-xs"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>

                    {{-- Stepper qty --}}
                    <div class="flex items-center justify-between mt-6">
                        <span class="text-sm font-semibold">Jumlah</span>
                        <div class="flex items-center gap-3">
                            <button type="button" @click="qty = Math.max(1, qty - 1)" class="w-8 h-8 rounded-full border border-zinc-200 dark:border-zinc-700 flex items-center justify-center">-</button>
                            <span class="w-6 text-center font-bold" x-text="qty"></span>
                            <button type="button" @click="qty++" class="w-8 h-8 rounded-full border border-zinc-200 dark:border-zinc-700 flex items-center justify-center">+</button>
                        </div>
                    </div>

                    <button type="button" @click="addToCart()" :disabled="submitting"
                            class="w-full mt-6 bg-zinc-900 text-white rounded-xl py-3.5 font-bold text-sm flex items-center justify-center gap-2">
                        <span x-text="submitting ? 'Menambahkan...' : 'Tambah - Rp ' + totalPrice.toLocaleString('id-ID')"></span>
                    </button>
                </div>
            </template>
        </div>
    </template>
</div>
