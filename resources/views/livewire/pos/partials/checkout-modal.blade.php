{{-- SPLIT PAYMENT & CHECKOUT MODAL --}}
<x-modal name="checkout-payment" max-width="lg" focusable>
    <div x-data="checkoutPayment()" x-init="init()" class="flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between shrink-0 bg-white rounded-t-3xl relative z-10 shadow-sm">
            <h3 class="font-black text-lg text-apeiron-ink tracking-tight flex items-center gap-2">
                <span class="material-symbols-rounded text-[22px]">payments</span> Selesaikan Pembayaran
            </h3>
            <button type="button" x-on:click="$dispatch('close')" aria-label="Tutup modal pembayaran (Esc)"
                class="size-8 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-apeiron-ink transition-all active:scale-90">
                <span class="material-symbols-rounded text-[20px]">close</span>
            </button>
        </div>

        <div class="p-6 overflow-y-auto flex-1 custom-scrollbar space-y-5 bg-gray-50">
            <!-- Ringkasan tagihan -->
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm space-y-2.5 relative overflow-hidden">
                <div class="flex justify-between text-xs font-semibold">
                    <span class="text-gray-500">Total Tagihan</span>
                    <span class="font-bold text-apeiron-ink tabular-nums" x-text="'Rp ' + formatRupiah(total)"></span>
                </div>
                <div class="flex justify-between text-xs font-semibold">
                    <span class="text-gray-500">Sudah Dibayar</span>
                    <span class="font-bold text-emerald-600 tabular-nums" x-text="'Rp ' + formatRupiah(paidTotal)"></span>
                </div>
                <div class="flex justify-between items-baseline pt-2.5 border-t border-gray-100 mt-1">
                    <span class="text-xs font-bold uppercase tracking-wider text-gray-600">Sisa Tagihan</span>
                    <span class="text-2xl font-black tabular-nums tracking-tight"
                        :class="remaining > 0 ? 'text-rose-600' : 'text-emerald-600'"
                        x-text="'Rp ' + formatRupiah(remaining)"></span>
                </div>
            </div>

            <!-- Daftar leg yang sudah ditambahkan -->
            <div x-show="legs.length > 0" x-cloak class="space-y-2">
                <template x-for="leg in legs" :key="leg.uid">
                    <div class="flex items-center justify-between gap-3 bg-white rounded-xl border border-gray-200 p-3 shadow-sm group hover:border-gray-300 transition-colors">
                        <div class="min-w-0 flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-gray-50 flex items-center justify-center border border-gray-100 shrink-0 text-gray-600">
                                <template x-if="leg.method === 'cash'"><span class="material-symbols-rounded text-[18px]">payments</span></template>
                                <template x-if="leg.method === 'qris'"><span class="material-symbols-rounded text-[18px]">qr_code_2</span></template>
                                <template x-if="leg.method === 'ewallet'"><span class="material-symbols-rounded text-[18px]">account_balance_wallet</span></template>
                                <template x-if="leg.method === 'card'"><span class="material-symbols-rounded text-[18px]">credit_card</span></template>
                            </div>
                            <div>
                                <span class="block text-xs font-bold text-apeiron-ink" x-text="leg.label"></span>
                                <span class="block text-[11px] font-medium text-gray-500 tabular-nums"
                                    x-text="'Rp ' + formatRupiah(leg.amount) + (leg.changeShown > 0 ? ' · Kembali Rp ' + formatRupiah(leg.changeShown) : '')"></span>
                            </div>
                        </div>
                        <button type="button" x-on:click="removeLeg(leg.uid)" aria-label="Hapus metode ini"
                            class="size-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-rose-500 hover:bg-rose-50 transition-all active:scale-90 shrink-0">
                            <span class="material-symbols-rounded text-[18px]">delete</span>
                        </button>
                    </div>
                </template>
            </div>

            <!-- Form tambah metode -->
            <div x-show="remaining > 0" x-cloak class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm space-y-4">
                <div>
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2">Pilih Cara Pembayaran</span>
                    <div class="grid grid-cols-4 gap-2">
                        <button type="button" x-on:click="method = 'cash'"
                            :class="method === 'cash' ?
                                'bg-apeiron-ink text-white border-apeiron-ink shadow-sm' :
                                'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                            class="min-h-[54px] rounded-xl border flex flex-col items-center justify-center gap-1 text-[11px] font-bold transition-all active:scale-95">
                            <span class="material-symbols-rounded text-[20px]">payments</span> Tunai
                        </button>
                        <button type="button" x-on:click="method = 'qris'"
                            :class="method === 'qris' ?
                                'bg-apeiron-ink text-white border-apeiron-ink shadow-sm' :
                                'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                            class="min-h-[54px] rounded-xl border flex flex-col items-center justify-center gap-1 text-[11px] font-bold transition-all active:scale-95">
                            <span class="material-symbols-rounded text-[20px]">qr_code_2</span> QRIS
                        </button>
                        <button type="button" x-on:click="method = 'ewallet'"
                            :class="method === 'ewallet' ?
                                'bg-apeiron-ink text-white border-apeiron-ink shadow-sm' :
                                'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                            class="min-h-[54px] rounded-xl border flex flex-col items-center justify-center gap-1 text-[11px] font-bold transition-all active:scale-95">
                            <span class="material-symbols-rounded text-[20px]">account_balance_wallet</span> E-Wallet
                        </button>
                        <button type="button" x-on:click="method = 'card'"
                            :class="method === 'card' ?
                                'bg-apeiron-ink text-white border-apeiron-ink shadow-sm' :
                                'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                            class="min-h-[54px] rounded-xl border flex flex-col items-center justify-center gap-1 text-[11px] font-bold transition-all active:scale-95">
                            <span class="material-symbols-rounded text-[20px]">credit_card</span> Kartu
                        </button>
                    </div>
                </div>

                <div>
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1.5"
                        x-text="method === 'cash' ? 'Uang Diterima' : 'Nominal Dibayar'"></span>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 font-bold text-gray-400 text-base">Rp</span>
                        <input type="text" inputmode="numeric" :value="amountFormatted"
                            x-on:input="setAmountInput($event)"
                            class="w-full min-h-[52px] pl-12 pr-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-1 focus:ring-gray-900 focus:border-apeiron-ink focus:bg-white outline-none text-xl font-black text-apeiron-ink transition-all shadow-inner"
                            placeholder="0">
                    </div>
                    <p x-show="method === 'cash' && changePreview > 0" x-cloak class="mt-1.5 text-xs font-semibold text-emerald-600">
                        Kembalian: <span x-text="'Rp ' + formatRupiah(changePreview)"></span>
                    </p>
                </div>

                <div class="flex gap-2">
                    <button type="button" x-on:click="fillRemaining()"
                        class="flex-1 min-h-[40px] text-xs font-black rounded-xl bg-apeiron-ink text-white hover:bg-[#111111] shadow-sm transition-all active:scale-95">PAS</button>
                    <template x-if="method === 'cash'">
                        <button type="button" x-on:click="addQuick(50000)"
                            class="flex-1 min-h-[40px] text-xs font-bold rounded-xl bg-white text-gray-700 hover:bg-gray-50 border border-gray-200 shadow-sm transition-all active:scale-95">+50K</button>
                    </template>
                    <template x-if="method === 'cash'">
                        <button type="button" x-on:click="addQuick(100000)"
                            class="flex-1 min-h-[40px] text-xs font-bold rounded-xl bg-white text-gray-700 hover:bg-gray-50 border border-gray-200 shadow-sm transition-all active:scale-95">+100K</button>
                    </template>
                </div>

                <p x-show="errorMsg" x-cloak class="text-xs font-semibold text-rose-600 bg-rose-50 p-2.5 rounded-xl border border-rose-100" x-text="errorMsg"></p>

                <button type="button" x-on:click="addLeg()"
                    class="w-full min-h-[46px] rounded-xl bg-gray-50 text-apeiron-ink font-bold text-xs border border-gray-200 hover:bg-gray-100 transition-all active:scale-[0.98] flex items-center justify-center gap-1.5 shadow-sm">
                    <span class="material-symbols-rounded text-[18px]">add_circle</span> Tambah Pembayaran Ini
                </button>
            </div>

            @error('cart')
                <div class="p-3 bg-rose-50 text-rose-700 border border-rose-200 rounded-xl text-xs font-semibold flex items-start gap-2 shadow-sm">
                    <span class="material-symbols-rounded text-[18px] text-rose-500">error</span>
                    {{ $message }}
                </div>
            @enderror
        </div>

        <div class="p-6 border-t border-gray-200 bg-white shrink-0 rounded-b-3xl">
            <button type="button" x-on:click="submit()"
                :disabled="remaining > 0 || legs.length === 0 || submitting"
                class="w-full min-h-[54px] rounded-xl bg-apeiron-ink hover:bg-[#111111] text-white font-black text-base shadow-lg transition-all active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                <span x-show="submitting" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true"></span>
                <span x-text="submitting ? 'Memproses Transaksi...' : 'Selesaikan Transaksi'"></span>
            </button>
        </div>
    </div>
</x-modal>

<script>
    function registerCheckoutPayment() {
        if (!window.Alpine) return;
        if (window.__checkoutPaymentRegistered) return;
        window.__checkoutPaymentRegistered = true;

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
                this._openModalHandler = (e) => {
                    this.total = e.detail.total;
                    this.legs = [];
                    this.method = 'cash';
                    this.amountRaw = 0;
                    this.amountFormatted = '';
                    this.errorMsg = '';
                    this.submitting = false;
                    this.idempotencyKey = (window.crypto && window.crypto.randomUUID) ?
                        window.crypto.randomUUID() :
                        (Date.now().toString(36) + Math.random().toString(36).slice(2));
                };
                window.addEventListener('open-checkout-modal', this._openModalHandler);
            },

            destroy() {
                if (this._openModalHandler) {
                    window.removeEventListener('open-checkout-modal', this._openModalHandler);
                }
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
                return {
                    cash: 'Tunai',
                    qris: 'QRIS',
                    ewallet: 'E-Wallet',
                    card: 'Kartu'
                } [m] ?? m;
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
                if (this.submitting) return;

                // Sinkronisasi totalAmount langsung dari instance Livewire sebelum submit
                const livewireTotal = await this.$wire.get('totalAmount');
                if (livewireTotal !== undefined && livewireTotal !== null && livewireTotal !== this.total) {
                    this.total = livewireTotal;
                    if (this.remaining > 0 || this.paidTotal !== this.total) {
                        this.errorMsg = 'Total tagihan berubah (Rp' + this.formatRupiah(livewireTotal) + '). Silakan sesuaikan rincian pembayaran.';
                        return;
                    }
                }

                if (this.remaining > 0 || this.legs.length === 0) return;
                this.submitting = true;
                const payload = this.legs.map(l => ({
                    method: l.method,
                    amount: l.amount
                }));
                try {
                    await $wire.call('submitOrder', payload, this.idempotencyKey);
                } catch (err) {
                    this.errorMsg = err?.message || 'Gagal memproses pembayaran.';
                } finally {
                    this.submitting = false;
                }
            },
        }));
    }

    if (window.Alpine) {
        registerCheckoutPayment();
    } else {
        document.addEventListener('alpine:init', registerCheckoutPayment);
    }

    function registerTransactionSuccessListener() {
        if (!window.Livewire) return;
        if (window.__transactionSuccessBound) return;
        window.__transactionSuccessBound = true;

        Livewire.on('transaction-success', async (e) => {
            const payload = Array.isArray(e) ? e[0] : (e?.detail ?? e);
            const orderCode = payload?.orderCode;
            const isCash = Boolean(payload?.isCash);

            if (!orderCode) {
                return;
            }

            if (isCash && {{ config('pos.auto_open_drawer') ? 'true' : 'false' }}) {
                if (window.ApeironPrint?.kickDrawer) {
                    await window.ApeironPrint.kickDrawer(orderCode);
                }
            }

            let printed = false;
            if (window.ApeironPrint?.printReceipt) {
                printed = await window.ApeironPrint.printReceipt(orderCode);
            }

            if (!printed) {
                window.open(`/transaction/receipt/${orderCode}?autoprint=1`, '_blank', 'width=400,height=600');
            }

            window.location.reload();
        });
    }

    if (window.Livewire) {
        registerTransactionSuccessListener();
    } else {
        document.addEventListener('livewire:init', registerTransactionSuccessListener);
    }
</script>
