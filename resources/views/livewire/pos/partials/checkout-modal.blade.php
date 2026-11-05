{{-- SPLIT PAYMENT & CHECKOUT MODAL --}}
<x-modal name="checkout-payment" max-width="2xl" focusable>
    <div x-data="checkoutPayment()" x-init="init()" class="bg-white rounded-2xl w-full shadow-2xl border border-border flex flex-col overflow-hidden max-h-[92vh]">
        <!-- Header Modal Checkout -->
        <div class="p-4 border-b border-border flex items-center justify-between bg-muted shrink-0">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-primary text-white flex items-center justify-center">
                    <span class="material-symbols-rounded text-[18px]">payments</span>
                </div>
                <div>
                    <h3 class="font-headline-sm text-[16px] font-semibold text-foreground">Penyelesaian Pembayaran (Kasir)</h3>
                    <span class="text-[11px] text-muted-foreground">
                        {{ $pendingOrderCode ? 'Order: #' . $pendingOrderCode : ($this->activeShift ? 'Shift #' . $this->activeShift->id : 'POS Kasir') }}
                        @if($tableId) &bull; Meja {{ $this->activeTables->firstWhere('id', $tableId)?->table_name ?? $tableId }} @endif
                    </span>
                </div>
            </div>
            <button type="button" x-on:click="$dispatch('close')" aria-label="Tutup pembayaran (Esc)"
                class="w-8 h-8 rounded-lg flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors">
                <span class="material-symbols-rounded text-[18px]">close</span>
            </button>
        </div>

        <!-- Payment Tabs Bar -->
        <div class="grid grid-cols-5 p-2 bg-muted border-b border-border gap-1 shrink-0">
            <button type="button" x-on:click="setMethod('cash')"
                :class="method === 'cash' ? 'bg-primary text-white' : 'text-muted-foreground hover:text-foreground'"
                class="px-3 py-2 rounded-lg font-medium text-[12px] transition-all flex items-center justify-center gap-1">
                <span class="material-symbols-rounded text-[14px]">payments</span>
                <span>Tunai</span>
            </button>
            <button type="button" x-on:click="setMethod('qris')"
                :class="method === 'qris' ? 'bg-primary text-white' : 'text-muted-foreground hover:text-foreground'"
                class="px-3 py-2 rounded-lg font-medium text-[12px] transition-all flex items-center justify-center gap-1">
                <span class="material-symbols-rounded text-[14px]">qr_code_2</span>
                <span>QRIS</span>
            </button>
            <button type="button" x-on:click="setMethod('card')"
                :class="method === 'card' ? 'bg-primary text-white' : 'text-muted-foreground hover:text-foreground'"
                class="px-3 py-2 rounded-lg font-medium text-[12px] transition-all flex items-center justify-center gap-1">
                <span class="material-symbols-rounded text-[14px]">credit_card</span>
                <span>EDC</span>
            </button>
            <button type="button" x-on:click="setMethod('store_credit')"
                :class="method === 'store_credit' ? 'bg-primary text-white' : 'text-muted-foreground hover:text-foreground'"
                class="px-3 py-2 rounded-lg font-medium text-[12px] transition-all flex items-center justify-center gap-1">
                <span class="material-symbols-rounded text-[14px]">account_balance</span>
                <span>Transfer</span>
            </button>
            <button type="button" x-on:click="setMethod('split')"
                :class="method === 'split' ? 'bg-primary text-white' : 'text-muted-foreground hover:text-foreground'"
                class="px-3 py-2 rounded-lg font-medium text-[12px] transition-all flex items-center justify-center gap-1">
                <span class="material-symbols-rounded text-[14px]">call_split</span>
                <span>Split Bill</span>
            </button>
        </div>

        <!-- Body Modal Content -->
        <div class="p-5 overflow-y-auto max-h-[62vh] space-y-4 custom-scrollbar bg-white">
            <!-- Total Tagihan Bersih Banner -->
            <div class="flex items-center justify-between p-3.5 rounded-xl bg-muted border border-border">
                <div>
                    <span class="text-[11px] text-muted-foreground font-medium uppercase tracking-wider block">Total Tagihan Bersih</span>
                    <span class="font-numeric-metric text-[24px] font-bold text-foreground font-mono" x-text="'Rp ' + formatRupiah(total)"></span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="$set('isManualDiscountModalOpen', true)"
                        class="px-3 py-1.5 rounded-lg bg-white border border-border text-foreground hover:bg-muted text-[12px] font-medium flex items-center gap-1.5 transition-colors">
                        <span class="material-symbols-rounded text-[15px] text-amber-500">sell</span>
                        <span>Atur Diskon</span>
                    </button>
                </div>
            </div>

            <!-- Tab Pane: Tunai (Cash) -->
            <div x-show="method === 'cash'" class="space-y-3.5">
                <label class="font-headline-sm text-[12px] font-semibold text-foreground uppercase tracking-wider block">
                    Uang Tunai Diterima
                </label>
                <div class="relative">
                    <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-mono text-muted-foreground font-semibold text-[16px]">Rp</span>
                    <input type="text" inputmode="numeric" :value="amountFormatted"
                        x-on:input="setAmountInput($event)"
                        placeholder="0"
                        class="w-full h-12 pl-12 pr-24 rounded-xl border-2 border-foreground font-mono text-[20px] font-bold text-foreground focus:outline-none bg-white">
                    <button type="button" x-on:click="fillRemaining()"
                        class="absolute right-2 top-1/2 -translate-y-1/2 px-2.5 py-1.5 rounded-lg bg-muted text-foreground font-label-sm text-[11px] hover:bg-secondary transition-colors font-medium">
                        Uang Pas
                    </button>
                </div>

                <!-- Quick Cash Buttons -->
                <div class="flex flex-wrap gap-2">
                    <button type="button" x-on:click="addQuick(20000)"
                        class="px-3 py-1.5 rounded-lg bg-muted border border-border hover:border-foreground font-mono text-[12px] text-foreground font-medium">
                        +Rp20.000
                    </button>
                    <button type="button" x-on:click="addQuick(50000)"
                        class="px-3 py-1.5 rounded-lg bg-muted border border-border hover:border-foreground font-mono text-[12px] text-foreground font-medium">
                        +Rp50.000
                    </button>
                    <button type="button" x-on:click="addQuick(100000)"
                        class="px-3 py-1.5 rounded-lg bg-muted border border-border hover:border-foreground font-mono text-[12px] text-foreground font-medium">
                        +Rp100.000
                    </button>
                    <button type="button" x-on:click="addQuick(200000)"
                        class="px-3 py-1.5 rounded-lg bg-muted border border-border hover:border-foreground font-mono text-[12px] text-foreground font-medium">
                        +Rp200.000
                    </button>
                </div>

                <!-- Change Display Box -->
                <div class="p-3.5 rounded-xl border flex items-center justify-between transition-colors"
                    :class="changePreview >= 0 ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-700'">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[20px]" x-text="changePreview >= 0 ? 'savings' : 'error'"></span>
                        <span class="text-[13px] font-medium" x-text="changePreview >= 0 ? 'Kembalian Kasir:' : 'Kurang Bayar:'"></span>
                    </div>
                    <span class="font-mono font-bold text-[18px]" x-text="'Rp ' + formatRupiah(Math.abs(changePreview))"></span>
                </div>
            </div>

            <!-- Tab Pane: QRIS -->
            <div x-show="method === 'qris'" class="flex flex-col items-center justify-center p-4 bg-muted rounded-xl border border-border space-y-3">
                <div class="w-44 h-44 bg-white p-2.5 rounded-xl border border-border shadow-xs flex flex-col items-center justify-center">
                    <div class="w-full h-full border-2 border-dashed border-foreground rounded-lg flex flex-col items-center justify-center text-center p-2">
                        <span class="material-symbols-rounded text-[44px] text-foreground">qr_code_2</span>
                        <span class="font-mono text-[11px] font-bold text-foreground mt-1">NMID: ID10293847291</span>
                        <span class="text-[9px] text-muted-foreground">Apeiron Pay &bull; Static QRIS</span>
                    </div>
                </div>
                <div class="text-center">
                    <span class="font-medium text-[13px] text-foreground block">Scan via GoPay, OVO, ShopeePay, BCA, dll</span>
                    <span class="text-[11px] text-muted-foreground">Verifikasi saldo di kasir setelah notifikasi berhasil</span>
                </div>
            </div>

            <!-- Tab Pane: EDC Kartu -->
            <div x-show="method === 'card'" class="space-y-3 p-4 bg-muted rounded-xl border border-border">
                <label class="font-headline-sm text-[12px] font-semibold text-foreground uppercase tracking-wider block">
                    Mesin EDC & Referensi Kartu
                </label>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" x-on:click="edcBank = 'BCA'"
                        :class="edcBank === 'BCA' ? 'border-foreground bg-primary text-white' : 'border-border bg-white text-foreground'"
                        class="p-2.5 rounded-lg border font-medium text-[12px] flex items-center justify-between transition-colors">
                        <span>EDC BCA #01</span>
                        <span class="text-[10px] opacity-80 font-mono">Online</span>
                    </button>
                    <button type="button" x-on:click="edcBank = 'Mandiri'"
                        :class="edcBank === 'Mandiri' ? 'border-foreground bg-primary text-white' : 'border-border bg-white text-foreground'"
                        class="p-2.5 rounded-lg border font-medium text-[12px] flex items-center justify-between transition-colors">
                        <span>EDC Mandiri #02</span>
                        <span class="text-[10px] opacity-80 font-mono">Online</span>
                    </button>
                </div>
                <div>
                    <label class="text-[11px] text-muted-foreground block mb-1">Nomor Approval / Trace Number (Struk EDC)</label>
                    <input type="text" x-model="edcTrace" placeholder="Contoh: 849201"
                        class="w-full h-9 px-3 rounded-lg border border-border text-[12px] text-foreground font-mono focus:outline-none focus:border-foreground bg-white">
                </div>
            </div>

            <!-- Tab Pane: Transfer Bank -->
            <div x-show="method === 'store_credit'" class="space-y-3 p-4 bg-muted rounded-xl border border-border">
                <div class="flex items-center justify-between p-3 rounded-lg bg-white border border-border">
                    <div class="flex flex-col">
                        <span class="text-[11px] text-muted-foreground">Rekening Operasional (BCA)</span>
                        <span class="font-mono font-bold text-[14px] text-foreground">8010 9928 1120</span>
                        <span class="text-[10px] text-muted-foreground">a.n. Apeiron System Coffee</span>
                    </div>
                    <button type="button" x-on:click="navigator.clipboard?.writeText('801099281120')"
                        class="px-2.5 py-1 rounded bg-muted text-[11px] font-medium text-foreground hover:bg-secondary transition-colors">
                        Salin
                    </button>
                </div>
                <div>
                    <label class="text-[11px] text-muted-foreground block mb-1">Nama Pengirim / Catatan Mutasi</label>
                    <input type="text" x-model="transferSender" placeholder="Nama pemilik rekening pengirim..."
                        class="w-full h-9 px-3 rounded-lg border border-border text-[12px] text-foreground focus:outline-none focus:border-foreground bg-white">
                </div>
            </div>

            <!-- Tab Pane: Split Bill (Multi-Payment Legs) -->
            <div x-show="method === 'split'" class="space-y-3 p-4 bg-muted rounded-xl border border-border">
                <div class="flex items-center justify-between">
                    <span class="text-[12px] font-medium text-foreground">Alokasi Pembayaran Split</span>
                    <span class="font-mono text-[12px] font-bold"
                        :class="remaining > 0 ? 'text-rose-600' : 'text-emerald-600'"
                        x-text="remaining > 0 ? 'Sisa: Rp ' + formatRupiah(remaining) : 'Lunas (Pas)'"></span>
                </div>

                <!-- Added Payment Legs List -->
                <div x-show="legs.length > 0" class="space-y-2">
                    <template x-for="leg in legs" :key="leg.uid">
                        <div class="flex items-center justify-between gap-3 bg-white rounded-lg border border-border p-2.5">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-rounded text-[16px] text-foreground">check_circle</span>
                                <div>
                                    <span class="font-medium text-xs text-foreground" x-text="leg.label"></span>
                                    <span class="block text-[11px] text-muted-foreground font-mono" x-text="'Rp ' + formatRupiah(leg.amount)"></span>
                                </div>
                            </div>
                            <button type="button" x-on:click="removeLeg(leg.uid)"
                                class="text-muted-foreground hover:text-rose-600 transition-colors p-1">
                                <span class="material-symbols-rounded text-[16px]">close</span>
                            </button>
                        </div>
                    </template>
                </div>

                <!-- Form Add Leg for Split -->
                <div x-show="remaining > 0" class="space-y-2 pt-2 border-t border-border">
                    <div class="grid grid-cols-4 gap-1.5">
                        <button type="button" x-on:click="splitTargetMethod = 'cash'"
                            :class="splitTargetMethod === 'cash' ? 'bg-primary text-white' : 'bg-white border-border text-foreground'"
                            class="px-2 py-1.5 rounded-md border text-[11px] font-medium">Tunai</button>
                        <button type="button" x-on:click="splitTargetMethod = 'qris'"
                            :class="splitTargetMethod === 'qris' ? 'bg-primary text-white' : 'bg-white border-border text-foreground'"
                            class="px-2 py-1.5 rounded-md border text-[11px] font-medium">QRIS</button>
                        <button type="button" x-on:click="splitTargetMethod = 'card'"
                            :class="splitTargetMethod === 'card' ? 'bg-primary text-white' : 'bg-white border-border text-foreground'"
                            class="px-2 py-1.5 rounded-md border text-[11px] font-medium">Kartu</button>
                        <button type="button" x-on:click="splitTargetMethod = 'store_credit'"
                            :class="splitTargetMethod === 'store_credit' ? 'bg-primary text-white' : 'bg-white border-border text-foreground'"
                            class="px-2 py-1.5 rounded-md border text-[11px] font-medium">Transfer</button>
                    </div>
                    <div class="flex gap-2">
                        <input type="text" inputmode="numeric" :value="splitAmountFormatted"
                            x-on:input="setSplitAmountInput($event)"
                            placeholder="Nominal alokasi..."
                            class="flex-1 h-9 px-3 rounded-lg border border-border font-mono text-xs text-foreground bg-white focus:outline-none focus:border-foreground">
                        <button type="button" x-on:click="addSplitLeg()"
                            class="px-3 h-9 rounded-lg bg-primary text-white text-xs font-medium hover:bg-primary/90 transition-colors">
                            + Alokasikan
                        </button>
                    </div>
                </div>
            </div>

            <!-- Error message if any -->
            <p x-show="errorMsg" x-cloak class="text-xs font-semibold text-rose-600 bg-rose-50 p-2.5 rounded-lg border border-rose-100" x-text="errorMsg"></p>
        </div>

        <!-- Footer Modal Checkout -->
        <div class="p-4 bg-muted border-t border-border flex items-center justify-between shrink-0">
            <button type="button" x-on:click="$dispatch('close')"
                class="px-4 py-2.5 rounded-lg border border-border hover:bg-white text-foreground font-medium text-[13px] transition-colors">
                Batal
            </button>
            <button type="button" id="btn-confirm-pay" x-on:click="submit()"
                :disabled="submitting || (method === 'split' ? remaining > 0 : false)"
                class="px-6 py-2.5 rounded-lg bg-primary hover:bg-primary/90 text-white font-medium text-[13px] flex items-center gap-2 shadow-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                <span x-show="submitting" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true"></span>
                <span class="material-symbols-rounded text-[17px]" x-show="!submitting">check_circle</span>
                <span x-text="submitting ? 'Memproses Transaksi...' : 'Konfirmasi Pembayaran Diterima'"></span>
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
            splitTargetMethod: 'cash',
            amountRaw: 0,
            amountFormatted: '',
            splitAmountRaw: 0,
            splitAmountFormatted: '',
            edcBank: 'BCA',
            edcTrace: '',
            transferSender: '',
            submitting: false,
            errorMsg: '',
            idempotencyKey: null,

            init() {
                this._openModalHandler = (e) => {
                    this.total = e.detail.total;
                    this.legs = [];
                    this.method = e.detail.method || 'cash';
                    this.splitTargetMethod = 'cash';
                    this.amountRaw = this.total;
                    this.amountFormatted = this.formatRupiah(this.total);
                    this.splitAmountRaw = 0;
                    this.splitAmountFormatted = '';
                    this.edcBank = 'BCA';
                    this.edcTrace = '';
                    this.transferSender = '';
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

            setMethod(m) {
                this.method = m;
                if (m === 'cash') {
                    this.fillRemaining();
                } else if (m === 'split') {
                    if (this.legs.length === 0) {
                        this.splitAmountRaw = this.remaining;
                        this.splitAmountFormatted = this.formatRupiah(this.remaining);
                    }
                }
            },

            get paidTotal() {
                if (this.method === 'split') {
                    return this.legs.reduce((s, l) => s + l.amount, 0);
                }
                return this.total;
            },
            get remaining() {
                return Math.max(0, this.total - (this.method === 'split' ? this.legs.reduce((s, l) => s + l.amount, 0) : this.total));
            },
            get changePreview() {
                if (this.method !== 'cash') return 0;
                return (this.amountRaw || 0) - this.total;
            },

            formatRupiah(n) {
                return new Intl.NumberFormat('id-ID').format(Math.round(n || 0));
            },

            setAmountInput(e) {
                const raw = e.target.value.replace(/[^0-9]/g, '');
                this.amountRaw = raw === '' ? 0 : parseInt(raw, 10);
                this.amountFormatted = raw === '' ? '' : this.formatRupiah(this.amountRaw);
            },

            setSplitAmountInput(e) {
                const raw = e.target.value.replace(/[^0-9]/g, '');
                this.splitAmountRaw = raw === '' ? 0 : parseInt(raw, 10);
                this.splitAmountFormatted = raw === '' ? '' : this.formatRupiah(this.splitAmountRaw);
            },

            fillRemaining() {
                this.amountRaw = this.total;
                this.amountFormatted = this.formatRupiah(this.total);
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
                    card: 'Kartu EDC',
                    gift_card: 'Gift Card',
                    store_credit: 'Transfer Bank'
                } [m] ?? m;
            },

            addSplitLeg() {
                this.errorMsg = '';
                if (!this.splitAmountRaw || this.splitAmountRaw <= 0) {
                    this.errorMsg = 'Masukkan nominal alokasi split.';
                    return;
                }
                const applied = Math.min(this.splitAmountRaw, this.remaining);
                this.legs.push({
                    uid: Date.now() + Math.random(),
                    method: this.splitTargetMethod,
                    label: this.methodLabel(this.splitTargetMethod),
                    amount: applied,
                });
                this.splitAmountRaw = 0;
                this.splitAmountFormatted = '';
            },

            removeLeg(uid) {
                this.legs = this.legs.filter(l => l.uid !== uid);
            },

            async submit() {
                if (this.submitting) return;

                const livewireTotal = await this.$wire.get('totalAmount');
                if (livewireTotal !== undefined && livewireTotal !== null && livewireTotal !== this.total) {
                    this.total = livewireTotal;
                    if (this.method === 'split' && this.remaining > 0) {
                        this.errorMsg = 'Total tagihan berubah (Rp ' + this.formatRupiah(livewireTotal) + '). Silakan sesuaikan alokasi split.';
                        return;
                    }
                }

                let payload = [];
                if (this.method === 'split') {
                    if (this.remaining > 0 || this.legs.length === 0) {
                        this.errorMsg = 'Tagihan split belum lunas. Sisa: Rp ' + this.formatRupiah(this.remaining);
                        return;
                    }
                    payload = this.legs.map(l => ({ method: l.method, amount: l.amount }));
                } else if (this.method === 'cash') {
                    if (this.amountRaw < this.total) {
                        this.errorMsg = 'Uang tunai yang diterima kurang dari total tagihan!';
                        return;
                    }
                    payload = [{ method: 'cash', amount: this.total }];
                } else {
                    payload = [{ method: this.method, amount: this.total }];
                }

                this.submitting = true;
                try {
                    await $wire.call('submitOrder', payload, this.idempotencyKey);
                } catch (err) {
                    this.errorMsg = err?.message || 'Gagal memproses pembayaran kasir.';
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

            if (!orderCode) return;

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
