{{--
    Bottom sheet pilihan varian.
    Kontrak: dibuka lewat window event `open-variant` ({ id }); menembakkan `cart-updated` dan `toast`.
    Sengaja TIDAK bergantung pada state induk — sheet mengelola state-nya sendiri.
--}}
<div x-data="variantSheet()"
     x-on:open-variant.window="show($event.detail.id)"
     x-on:keydown.escape.window="close()"
     x-effect="document.body.classList.toggle('overflow-hidden', open)"
     :class="open ? '' : 'pointer-events-none'"
     class="fixed inset-0 z-50 flex items-end justify-center mx-auto max-w-md">

    {{-- Backdrop --}}
    <div x-show="open" x-cloak @click="close()" aria-hidden="true"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-yovel-ink/40 backdrop-blur-sm sm:border-x sm:border-yovel-border"></div>

    {{-- Sheet --}}
    <div x-show="open" x-cloak role="dialog" aria-modal="true" aria-labelledby="variant-title"
         x-transition:enter="transition ease-[cubic-bezier(0.16,1,0.3,1)] duration-300"
         x-transition:enter-start="translate-y-full opacity-0"
         x-transition:enter-end="translate-y-0 opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="translate-y-0 opacity-100"
         x-transition:leave-end="translate-y-full opacity-0"
         class="relative z-10 mx-auto flex max-h-[88dvh] w-full max-w-md flex-col overflow-hidden rounded-t-[1.75rem] bg-white shadow-2xl">

        {{-- Handle (mobile) --}}
        <div class="flex shrink-0 justify-center pt-3" aria-hidden="true">
            <span class="h-1.5 w-12 rounded-full bg-yovel-border"></span>
        </div>

        {{-- Header --}}
        <div class="flex shrink-0 items-start justify-between gap-3 border-b border-yovel-border px-5 pb-4 pt-3 sm:pt-5">
            <div class="min-w-0">
                <h2 id="variant-title" class="text-[20px] font-bold leading-snug" x-text="product ? product.name : 'Memuat…'"></h2>
                <p x-show="product" x-cloak class="mt-1 text-[17px] font-bold tabular-nums text-yovel-muted" x-text="rupiah(product ? product.price : 0)"></p>
            </div>
            <button type="button" @click="close()" aria-label="Tutup"
                    class="-mr-2 flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-yovel-muted transition-all duration-200 hover:bg-yovel-surface hover:text-yovel-ink active:scale-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-yovel-ink">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        {{-- Body --}}
        <div class="flex-1 overflow-y-auto px-5 py-5">

            {{-- Loading skeleton --}}
            <div x-show="loading" x-cloak class="space-y-4" aria-hidden="true">
                <div class="h-4 w-24 animate-pulse rounded bg-yovel-surface"></div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="h-14 animate-pulse rounded-2xl bg-yovel-surface"></div>
                    <div class="h-14 animate-pulse rounded-2xl bg-yovel-surface"></div>
                </div>
                <div class="h-4 w-32 animate-pulse rounded bg-yovel-surface"></div>
                <div class="h-14 animate-pulse rounded-2xl bg-yovel-surface"></div>
            </div>

            {{-- Error --}}
            <div x-show="error && !loading" x-cloak role="alert" class="flex flex-col items-center py-10 text-center">
                <span class="material-symbols-rounded mb-3 text-[32px] text-primary-300">cloud_off</span>
                <p class="mb-4 max-w-xs text-sm font-medium text-yovel-muted" x-text="error"></p>
                <button type="button" @click="load()"
                        class="min-h-11 rounded-xl border border-yovel-border bg-white px-5 text-sm font-semibold shadow-sm transition-all duration-200 hover:bg-yovel-bg active:scale-95">
                    Coba lagi
                </button>
            </div>

            {{-- Konten --}}
            <div x-show="product && !loading && !error" x-cloak>
                <template x-for="group in groups" :key="group.id">
                    <div class="mb-6">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h3 class="text-[12px] font-bold uppercase tracking-wider text-yovel-muted" x-text="group.name"></h3>
                            <span class="rounded-md px-2 py-0.5 text-[11px] font-bold uppercase tracking-wider"
                                  :class="group.is_required ? 'bg-yovel-ink text-white' : 'bg-yovel-surface text-yovel-muted'"
                                  x-text="(group.is_required ? 'Wajib' : 'Opsional') + ' · ' + (group.selection_type === 'single' ? 'Pilih 1' : 'Bisa lebih')"></span>
                        </div>

                        <div :role="group.selection_type === 'single' ? 'radiogroup' : 'group'" :aria-label="group.name"
                             class="grid gap-2" :class="group.selection_type === 'single' ? 'grid-cols-2' : 'grid-cols-1'">
                            <template x-for="mod in group.modifiers" :key="mod.id">
                                <button type="button"
                                        :role="group.selection_type === 'single' ? 'radio' : 'checkbox'"
                                        :aria-checked="isSelected(group, mod.id).toString()"
                                        @click="toggle(group, mod.id)"
                                        :class="isSelected(group, mod.id) ? 'border-yovel-ink bg-yovel-bg ring-1 ring-yovel-ink' : 'border-yovel-border bg-white hover:bg-yovel-bg'"
                                        class="flex min-h-14 items-center justify-between gap-3 rounded-2xl border-[1.5px] px-4 py-3 text-left transition-all duration-200 active:scale-[0.97] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-yovel-ink">
                                    <span class="min-w-0">
                                        <span class="block text-[14px] font-bold leading-tight" x-text="mod.name"></span>
                                        <span x-show="mod.extra_price > 0" class="mt-0.5 block text-[12px] font-medium text-yovel-muted" x-text="'+' + rupiah(mod.extra_price)"></span>
                                    </span>
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center border-[1.5px] transition-all duration-200"
                                          :class="[
                                              group.selection_type === 'single' ? 'rounded-full' : 'rounded-md',
                                              isSelected(group, mod.id) ? 'border-yovel-ink bg-yovel-ink text-white' : 'border-primary-300 text-transparent'
                                          ]">
                                        <span class="material-symbols-rounded text-[16px]">check</span>
                                    </span>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>

                <div>
                    <label for="variant-notes" class="mb-2 block text-[12px] font-bold uppercase tracking-wider text-yovel-muted">Catatan (opsional)</label>
                    {{-- text-[15px] (minimal 16px sebenernya utk iOS agar tidak auto-zoom, tp 15px/16px msh ok) --}}
                    <textarea id="variant-notes" x-model="notes" rows="2" maxlength="255"
                              placeholder="Contoh: es dipisah, tanpa sedotan"
                              class="w-full resize-none rounded-2xl border border-yovel-border bg-yovel-bg px-4 py-3 text-[15px] placeholder:text-primary-400 transition-all duration-200 focus:border-yovel-ink focus:bg-white focus:outline-none focus:ring-1 focus:ring-yovel-ink"></textarea>
                </div>
            </div>
        </div>

        {{-- Footer --}}
        <div x-show="product && !error && !loading" x-cloak class="shrink-0 border-t border-yovel-border bg-white px-5 pt-4 pb-safe">
            <p x-show="missing.length" x-cloak class="mb-3 text-xs font-medium text-rose-600"
               x-text="'Lengkapi pilihan wajib: ' + missing.join(', ')"></p>
            
            <div class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <span class="text-[14px] font-bold text-yovel-muted">Jumlah:</span>
                    <div class="flex shrink-0 items-center rounded-full border border-yovel-border bg-yovel-surface p-0.5" role="group" aria-label="Jumlah">
                        <button type="button" @click="qty = Math.max(1, qty - 1)" :disabled="qty <= 1" aria-label="Kurangi jumlah"
                                class="flex h-11 w-11 items-center justify-center rounded-full bg-white shadow-sm transition-all duration-200 active:scale-90 disabled:opacity-40">
                            <span class="material-symbols-rounded text-[18px]">remove</span>
                        </button>
                        <span class="w-8 text-center text-[15px] font-bold tabular-nums" x-text="qty" aria-live="polite"></span>
                        <button type="button" @click="qty = Math.min(20, qty + 1)" :disabled="qty >= 20" aria-label="Tambah jumlah"
                                class="flex h-11 w-11 items-center justify-center rounded-full bg-white shadow-sm transition-all duration-200 active:scale-90 disabled:opacity-40">
                            <span class="material-symbols-rounded text-[18px]">add</span>
                        </button>
                    </div>
                </div>

                <button type="button" @click="submit()" :disabled="!canSubmit"
                        class="flex min-h-12 w-full items-center justify-between gap-3 rounded-2xl bg-yovel-ink px-5 font-bold text-white shadow-lg transition-all duration-200 hover:bg-black active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-40 disabled:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-yovel-ink">
                    <span class="flex items-center gap-2 text-[15px]">
                        <span x-show="submitting" x-cloak class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true"></span>
                        <span x-text="submitting ? 'Menambahkan…' : 'Tambah ke Pesanan'"></span>
                    </span>
                    <span class="tabular-nums" x-text="rupiah(total)"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        const modifiersUrl = @js(route('customer.menu.modifiers', ['product' => '__ID__']));
        const cartUrl = @js(route('customer.cart.store'));
        const memo = {}; // cache per produk: mengurangi request ke endpoint yang ber-throttle

        Alpine.data('variantSheet', () => ({
            open: false,
            loading: false,
            submitting: false,
            error: null,
            productId: null,
            product: null,
            groups: [],
            selected: {},
            qty: 1,
            notes: '',
            reqId: 0,

            async show(id) {
                this.productId = id;
                this.open = true;
                await this.load();
            },

            close() { this.open = false; },

            async load() {
                const req = ++this.reqId;
                this.error = null;
                this.product = null;
                this.groups = [];
                this.selected = {};
                this.qty = 1;
                this.notes = '';
                try {
                    let data = memo[this.productId];
                    if (!data) {
                        this.loading = true;
                        const res = await fetch(modifiersUrl.replace('__ID__', this.productId), { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) throw new Error(res.status === 404 ? 'Produk ini sudah tidak tersedia.' : 'Gagal memuat varian. Coba lagi.');
                        data = await res.json();
                        memo[this.productId] = data;
                    }
                    if (req !== this.reqId) return; // permintaan usang, abaikan
                    this.product = data.product;
                    this.groups = data.modifier_groups;
                    const sel = {};
                    data.modifier_groups.forEach(g => {
                        const def = g.modifiers.find(m => m.is_default);
                        sel[g.id] = def ? [def.id] : [];
                    });
                    this.selected = sel;
                } catch (e) {
                    if (req !== this.reqId) return;
                    this.error = e.name === 'TypeError' ? 'Koneksi bermasalah. Periksa jaringan Anda.' : (e.message || 'Gagal memuat varian. Coba lagi.');
                } finally {
                    if (req === this.reqId) this.loading = false;
                }
            },

            isSelected(group, id) {
                return (this.selected[group.id] || []).includes(id);
            },

            toggle(group, id) {
                const cur = this.selected[group.id] || [];
                if (group.selection_type === 'single') {
                    // Grup opsional boleh dibatalkan dengan tap ulang; grup wajib tetap terpilih.
                    this.selected[group.id] = (cur.includes(id) && !group.is_required) ? [] : [id];
                } else {
                    this.selected[group.id] = cur.includes(id) ? cur.filter(x => x !== id) : [...cur, id];
                }
            },

            get missing() {
                return this.groups
                    .filter(g => g.is_required && !(this.selected[g.id] || []).length)
                    .map(g => g.name);
            },

            get canSubmit() {
                return !!this.product && !this.loading && !this.error && !this.submitting && this.missing.length === 0;
            },

            get unitPrice() {
                if (!this.product) return 0;
                let extra = 0;
                this.groups.forEach(g => (this.selected[g.id] || []).forEach(id => {
                    const m = g.modifiers.find(x => x.id === id);
                    if (m) extra += Number(m.extra_price) || 0;
                }));
                return Number(this.product.price) + extra;
            },

            get total() { return this.unitPrice * this.qty; },

            rupiah(n) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(n)); },

            async submit() {
                if (!this.canSubmit) return;
                this.submitting = true;
                try {
                    const res = await fetch(cartUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify({
                            product_id: this.productId,
                            qty: this.qty,
                            modifier_ids: Object.values(this.selected).flat(),
                            notes: this.notes || null,
                        }),
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.message || 'Gagal menambahkan ke keranjang.');
                    window.customerCartCount = data.total_qty;
                    window.dispatchEvent(new CustomEvent('cart-updated', { detail: data }));
                    this.$dispatch('toast', { message: 'Ditambahkan ke keranjang' });
                    this.close();
                } catch (e) {
                    this.$dispatch('toast', { message: e.name === 'TypeError' ? 'Koneksi bermasalah. Coba lagi.' : e.message });
                } finally {
                    this.submitting = false;
                }
            },
        }));
    });
</script>
