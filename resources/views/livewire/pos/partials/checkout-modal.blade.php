{{-- SPLIT PAYMENT & CHECKOUT MODAL --}}
<x-ui.modal name="checkout-payment" maxWidth="2xl" :closeButton="false" class="p-0 overflow-hidden">
    <div x-data="checkoutPayment()" x-init="init()" class="bg-card text-card-foreground w-full flex flex-col overflow-hidden max-h-[92vh]">
        <!-- Header Modal Checkout -->
        <div class="p-4 border-b border-border flex items-center justify-between bg-muted shrink-0">
            <div>
                <h3 class="text-base font-semibold text-foreground">Complete Payment (Cashier)</h3>
                <span class="text-xs text-muted-foreground">
                    {{ $pendingOrderCode ? 'Order: #' . $pendingOrderCode : ($this->activeShift ? 'Shift #' . $this->activeShift->id : 'POS Terminal') }}
                    @if($tableId) &bull; Table {{ $this->activeTables->firstWhere('id', $tableId)?->table_name ?? $tableId }} @endif
                </span>
            </div>
            <button type="button" x-on:click="$dispatch('close')" aria-label="Close payment (Esc)"
                class="w-8 h-8 rounded-lg flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors">
                <span class="material-symbols-rounded text-[20px]">close</span>
            </button>
        </div>

        <!-- Payment Tabs Bar -->
        <div class="grid grid-cols-5 p-2 bg-muted border-b border-border gap-1 shrink-0">
            <button type="button" x-on:click="setMethod('cash')"
                :class="method === 'cash' ? 'bg-primary text-white' : 'text-muted-foreground hover:text-foreground'"
                class="px-3 py-2 rounded-lg font-medium text-xs transition-all flex items-center justify-center gap-1">
                <span class="material-symbols-rounded text-[14px]">payments</span>
                <span>Cash</span>
            </button>
            <button type="button" x-on:click="setMethod('qris')"
                :class="method === 'qris' ? 'bg-primary text-white' : 'text-muted-foreground hover:text-foreground'"
                class="px-3 py-2 rounded-lg font-medium text-xs transition-all flex items-center justify-center gap-1">
                <span class="material-symbols-rounded text-[14px]">qr_code_2</span>
                <span>QRIS</span>
            </button>
            <button type="button" x-on:click="setMethod('card')"
                :class="method === 'card' ? 'bg-primary text-white' : 'text-muted-foreground hover:text-foreground'"
                class="px-3 py-2 rounded-lg font-medium text-xs transition-all flex items-center justify-center gap-1">
                <span class="material-symbols-rounded text-[14px]">credit_card</span>
                <span>Card</span>
            </button>
            <button type="button" x-on:click="setMethod('store_credit')"
                :class="method === 'store_credit' ? 'bg-primary text-white' : 'text-muted-foreground hover:text-foreground'"
                class="px-3 py-2 rounded-lg font-medium text-xs transition-all flex items-center justify-center gap-1">
                <span class="material-symbols-rounded text-[14px]">account_balance</span>
                <span>Transfer</span>
            </button>
            <button type="button" x-on:click="setMethod('split')"
                :class="method === 'split' ? 'bg-primary text-white' : 'text-muted-foreground hover:text-foreground'"
                class="px-3 py-2 rounded-lg font-medium text-xs transition-all flex items-center justify-center gap-1">
                <span class="material-symbols-rounded text-[14px]">call_split</span>
                <span>Split Bill</span>
            </button>
        </div>

        <!-- Body Modal Content -->
        <div class="p-5 overflow-y-auto max-h-[62vh] space-y-4 custom-scrollbar bg-white">
            <!-- Net Total Due Banner -->
            <div class="flex items-center justify-between p-3.5 rounded-xl bg-muted border border-border">
                <div>
                    <span class="text-xs text-muted-foreground font-medium uppercase tracking-wider block">Net Total Due</span>
                    <span class="text-2xl font-bold text-foreground tabular-nums" x-text="'Rp ' + formatRupiah(total)"></span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="$set('isManualDiscountModalOpen', true)"
                        class="px-3 py-1.5 rounded-lg bg-white border border-border text-foreground hover:bg-muted text-xs font-medium flex items-center gap-1.5 transition-colors">
                        <span class="material-symbols-rounded text-[16px] text-amber-500">sell</span>
                        <span>Apply Discount</span>
                    </button>
                </div>
            </div>

            <!-- Tab Pane: Tunai (Cash) -->
            <div x-show="method === 'cash'" class="space-y-3.5">
                <label class="text-xs font-semibold text-foreground uppercase tracking-wider block">
                    Cash Received
                </label>
                <div class="relative">
                    <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-muted-foreground font-semibold text-base">Rp</span>
                    <input type="text" inputmode="numeric" :value="amountFormatted"
                        x-on:input="setAmountInput($event)"
                        placeholder="0"
                        class="w-full h-12 pl-12 pr-24 rounded-xl border-2 border-foreground tabular-nums text-xl font-bold text-foreground focus:outline-none bg-white">
                    <button type="button" x-on:click="fillRemaining()"
                        class="absolute right-2 top-1/2 -translate-y-1/2 px-2.5 py-1.5 rounded-lg bg-muted text-foreground text-xs hover:bg-secondary transition-colors font-medium">
                        Exact Amount
                    </button>
                </div>

                <!-- Quick Cash Buttons -->
                <div class="flex flex-wrap gap-2">
                    <button type="button" x-on:click="addQuick(20000)"
                        class="px-3 py-1.5 rounded-lg bg-muted border border-border hover:border-foreground tabular-nums text-xs text-foreground font-medium">
                        +Rp20.000
                    </button>
                    <button type="button" x-on:click="addQuick(50000)"
                        class="px-3 py-1.5 rounded-lg bg-muted border border-border hover:border-foreground tabular-nums text-xs text-foreground font-medium">
                        +Rp50.000
                    </button>
                    <button type="button" x-on:click="addQuick(100000)"
                        class="px-3 py-1.5 rounded-lg bg-muted border border-border hover:border-foreground tabular-nums text-xs text-foreground font-medium">
                        +Rp100.000
                    </button>
                    <button type="button" x-on:click="addQuick(200000)"
                        class="px-3 py-1.5 rounded-lg bg-muted border border-border hover:border-foreground tabular-nums text-xs text-foreground font-medium">
                        +Rp200.000
                    </button>
                </div>

                <!-- Change Display Box -->
                <div class="p-3.5 rounded-xl border flex items-center justify-between transition-colors"
                    :class="changePreview >= 0 ? 'border-border bg-muted text-foreground' : 'border-rose-200 bg-rose-50 text-rose-700'">
                    <span class="text-sm font-semibold" x-text="changePreview >= 0 ? 'Change Due:' : 'Amount Underpaid:'"></span>
                    <span class="tabular-nums font-bold text-lg" x-text="'Rp ' + formatRupiah(Math.abs(changePreview))"></span>
                </div>
            </div>

            <!-- Tab Pane: QRIS & Digital Payment -->
            <div x-show="method === 'qris'" class="space-y-3.5 p-4 bg-muted rounded-xl border border-border">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-foreground uppercase tracking-wider">
                        QR Payment Mode
                    </span>
                    <div class="inline-flex p-1 bg-white rounded-lg border border-border">
                        <button type="button" x-on:click="qrisMode = 'midtrans'"
                            :class="qrisMode === 'midtrans' ? 'bg-primary text-white shadow-2xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-2.5 py-1 rounded-md text-xs font-semibold transition-all cursor-pointer">
                            Dynamic QRIS
                        </button>
                        <button type="button" x-on:click="qrisMode = 'manual'"
                            :class="qrisMode === 'manual' ? 'bg-primary text-white shadow-2xs' : 'text-muted-foreground hover:text-foreground'"
                            class="px-2.5 py-1 rounded-md text-xs font-semibold transition-all cursor-pointer">
                            Static QR / Counter EDC
                        </button>
                    </div>
                </div>

                <!-- Mode 1: Dynamic QRIS & Digital Gateway -->
                <div x-show="qrisMode === 'midtrans'" class="p-4 bg-white rounded-xl border border-border space-y-3">
                    <template x-if="!awaitingMidtransOrderCode">
                        <div class="flex items-start gap-3.5">
                            <div class="w-11 h-11 rounded-xl bg-muted border border-border text-foreground flex items-center justify-center shrink-0">
                                <span class="material-symbols-rounded text-[24px]">qr_code_scanner</span>
                            </div>
                            <div class="space-y-1">
                                <span class="font-semibold text-sm text-foreground block">Dynamic QR Payment (QRIS)</span>
                                <p class="text-xs text-muted-foreground leading-relaxed">
                                    Generates an official dynamic QRIS payment session (GoPay, ShopeePay, BCA, OVO, Dana). Order automatically updates to Paid upon customer completion.
                                </p>
                            </div>
                        </div>
                    </template>

                    <template x-if="awaitingMidtransOrderCode">
                        <div class="flex flex-col items-center text-center space-y-2.5 py-3">
                            <div class="w-11 h-11 rounded-xl bg-muted border border-border text-foreground flex items-center justify-center">
                                <span class="material-symbols-rounded text-[24px]">qr_code_scanner</span>
                            </div>
                            <div class="space-y-0.5">
                                <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground block">Awaiting Customer Payment</span>
                                <span class="font-sans font-bold text-base text-foreground block tabular-nums" x-text="awaitingMidtransOrderCode"></span>
                                <span class="text-xs text-muted-foreground block max-w-sm">Scan QR on customer terminal or tap Verify Payment below.</span>
                            </div>
                            <div class="flex flex-wrap items-center justify-center gap-2 pt-1 w-full">
                                <button type="button" x-show="awaitingSnapToken" x-on:click="openSnapPopup()"
                                    class="px-3.5 py-2 rounded-lg bg-white border border-border hover:bg-muted text-foreground text-xs font-semibold flex items-center gap-1.5 transition-colors cursor-pointer">
                                    <span class="material-symbols-rounded text-[16px]">qr_code_2</span>
                                    <span>Re-open QR Code</span>
                                </button>
                                <button type="button" x-on:click="checkMidtransStatus()" :disabled="verifyingMidtrans"
                                    class="px-4 py-2 rounded-lg bg-primary hover:bg-primary/90 text-white text-xs font-semibold flex items-center gap-1.5 shadow-xs transition-all disabled:opacity-50 cursor-pointer">
                                    <span x-show="verifyingMidtrans" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                                    <span x-show="!verifyingMidtrans" class="material-symbols-rounded text-[16px]">sync</span>
                                    <span x-text="verifyingMidtrans ? 'Verifying...' : 'Verify Payment Status'"></span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Mode 2: Manual Static QRIS -->
                <div x-show="qrisMode === 'manual'" class="p-4 bg-white rounded-xl border border-border space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-muted border border-border flex items-center justify-center shrink-0">
                            <span class="material-symbols-rounded text-[24px] text-foreground">qr_code_2</span>
                        </div>
                        <div>
                            <span class="font-semibold text-sm text-foreground block">Static QR / Counter Terminal</span>
                            <span class="text-xs text-muted-foreground">Verify payment notification on counter terminal or physical slip before confirming.</span>
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-muted-foreground block mb-1">Reference Number / RRN (Optional)</label>
                        <input type="text" x-model="qrisReference" placeholder="e.g. RRN-99283741"
                            class="w-full h-9 px-3 rounded-lg border border-border text-xs text-foreground tabular-nums focus:outline-none focus:border-foreground bg-white">
                    </div>
                </div>
            </div>

            <!-- Tab Pane: EDC Kartu -->
            <div x-show="method === 'card'" class="space-y-3 p-4 bg-muted rounded-xl border border-border">
                <label class="text-xs font-semibold text-foreground uppercase tracking-wider block">
                    Select Card Terminal (EDC)
                </label>
                <div class="grid grid-cols-2 gap-2">
                    @forelse ($this->storeEdcTerminals as $term)
                        <button type="button" x-on:click="edcBank = '{{ $term->device_name }}'"
                            :class="edcBank === '{{ $term->device_name }}' ? 'border-foreground bg-primary text-white shadow-xs' : 'border-border bg-white text-foreground hover:bg-muted'"
                            class="p-2.5 rounded-lg border font-medium text-xs flex items-center justify-between transition-colors cursor-pointer">
                            <span class="truncate">{{ $term->device_name }}</span>
                            <span class="text-xs opacity-80">{{ $term->device_code ?? 'Online' }}</span>
                        </button>
                    @empty
                        <button type="button" x-on:click="edcBank = 'EDC Utama'"
                            :class="edcBank === 'EDC Utama' ? 'border-foreground bg-primary text-white shadow-xs' : 'border-border bg-white text-foreground hover:bg-muted'"
                            class="p-2.5 rounded-lg border font-medium text-xs flex items-center justify-between transition-colors cursor-pointer">
                            <span>Main EDC Terminal</span>
                            <span class="text-xs opacity-80">Online</span>
                        </button>
                    @endforelse
                </div>
                <div>
                    <label class="text-xs text-muted-foreground block mb-1">Approval Code / Trace Number (EDC Slip)</label>
                    <input type="text" x-model="edcTrace" placeholder="e.g. 849201"
                        class="w-full h-9 px-3 rounded-lg border border-border text-xs text-foreground tabular-nums focus:outline-none focus:border-foreground bg-white">
                </div>
            </div>

            <!-- Tab Pane: Bank Transfer -->
            <div x-show="method === 'store_credit'" class="space-y-3 p-4 bg-muted rounded-xl border border-border">
                <div class="space-y-2">
                    <label class="text-xs font-semibold text-foreground uppercase tracking-wider block">
                        Official Store Bank Account
                    </label>
                    @forelse ($this->storeBankAccounts as $acc)
                        <div class="flex items-center justify-between p-3 rounded-lg bg-white border border-border">
                            <div class="flex flex-col min-w-0 pr-2">
                                <span class="text-xs text-muted-foreground">{{ $acc->bank_name ?? $acc->account_name }}</span>
                                <span class="tabular-nums font-bold text-sm text-foreground font-sans tracking-wide">{{ $acc->bank_account_number }}</span>
                                <span class="text-xs text-muted-foreground truncate">a.n. {{ $acc->bank_account_holder ?? $acc->account_name }}</span>
                            </div>
                            <button type="button" x-on:click="navigator.clipboard?.writeText('{{ $acc->bank_account_number }}'); $dispatch('toast', { message: 'Account number copied to clipboard!', type: 'success' })"
                                class="px-2.5 py-1 rounded bg-muted text-xs font-medium text-foreground hover:bg-secondary transition-colors shrink-0 cursor-pointer">
                                Copy
                            </button>
                        </div>
                    @empty
                        <div class="flex items-center justify-between p-3 rounded-lg bg-white border border-border">
                            <div class="flex flex-col min-w-0">
                                <span class="text-xs text-muted-foreground">Bank Central Asia (BCA)</span>
                                <span class="tabular-nums font-bold text-sm text-foreground font-sans">8010 9928 1120</span>
                                <span class="text-xs text-muted-foreground">a.n. Apeiron System Coffee</span>
                            </div>
                            <button type="button" x-on:click="navigator.clipboard?.writeText('801099281120'); $dispatch('toast', { message: 'Account number copied to clipboard!', type: 'success' })"
                                class="px-2.5 py-1 rounded bg-muted text-xs font-medium text-foreground hover:bg-secondary transition-colors shrink-0 cursor-pointer">
                                Copy
                            </button>
                        </div>
                    @endforelse
                </div>
                <div>
                    <label class="text-xs text-muted-foreground block mb-1">Sender Name / Transfer Reference</label>
                    <input type="text" x-model="transferSender" placeholder="Account holder name or payment note..."
                        class="w-full h-9 px-3 rounded-lg border border-border text-xs text-foreground focus:outline-none focus:border-foreground bg-white">
                </div>
            </div>

            <!-- Tab Pane: Split Bill (Multi-Payment Legs) -->
            <div x-show="method === 'split'" class="space-y-3 p-4 bg-muted rounded-xl border border-border">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-foreground">Split Payment Allocation</span>
                    <span class="tabular-nums text-xs font-bold"
                        :class="remaining > 0 ? 'text-rose-600' : 'text-emerald-600'"
                        x-text="remaining > 0 ? 'Remaining: Rp ' + formatRupiah(remaining) : 'Fully Allocated'"></span>
                </div>

                <!-- Added Payment Legs List -->
                <div x-show="legs.length > 0" class="space-y-2">
                    <template x-for="leg in legs" :key="leg.uid">
                        <div class="flex items-center justify-between gap-3 bg-white rounded-lg border border-border p-2.5">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-rounded text-[16px] text-foreground">check_circle</span>
                                <div>
                                    <span class="font-medium text-xs text-foreground" x-text="leg.label"></span>
                                    <span class="block text-xs text-muted-foreground tabular-nums" x-text="'Rp ' + formatRupiah(leg.amount)"></span>
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
                            class="px-2 py-1.5 rounded-md border text-xs font-medium">Cash</button>
                        <button type="button" x-on:click="splitTargetMethod = 'qris'"
                            :class="splitTargetMethod === 'qris' ? 'bg-primary text-white' : 'bg-white border-border text-foreground'"
                            class="px-2 py-1.5 rounded-md border text-xs font-medium">QRIS</button>
                        <button type="button" x-on:click="splitTargetMethod = 'card'"
                            :class="splitTargetMethod === 'card' ? 'bg-primary text-white' : 'bg-white border-border text-foreground'"
                            class="px-2 py-1.5 rounded-md border text-xs font-medium">Card</button>
                        <button type="button" x-on:click="splitTargetMethod = 'store_credit'"
                            :class="splitTargetMethod === 'store_credit' ? 'bg-primary text-white' : 'bg-white border-border text-foreground'"
                            class="px-2 py-1.5 rounded-md border text-xs font-medium">Transfer</button>
                    </div>
                    <div class="flex gap-2">
                        <input type="text" inputmode="numeric" :value="splitAmountFormatted"
                            x-on:input="setSplitAmountInput($event)"
                            placeholder="Allocation amount..."
                            class="flex-1 h-9 px-3 rounded-lg border border-border tabular-nums text-xs text-foreground bg-white focus:outline-none focus:border-foreground">
                        <button type="button" x-on:click="addSplitLeg()"
                            class="px-3 h-9 rounded-lg bg-primary text-white text-xs font-medium hover:bg-primary/90 transition-colors">
                            + Allocate
                        </button>
                    </div>
                </div>
            </div>

            <!-- Error message if any -->
            <p x-show="errorMsg" x-cloak class="text-xs font-semibold text-rose-600 bg-rose-50 p-2.5 rounded-lg border border-rose-100" x-text="errorMsg"></p>
        </div>

        <!-- Footer Modal Checkout -->
        <div class="p-4 bg-muted border-t border-border flex items-center justify-between shrink-0">
            <button type="button" x-on:click="closeModal()"
                class="px-4 py-2.5 rounded-lg border border-border hover:bg-white text-foreground font-medium text-sm transition-colors cursor-pointer">
                <span x-text="awaitingMidtransOrderCode ? 'Close Window' : 'Cancel'"></span>
            </button>
            <button type="button" id="btn-confirm-pay"
                x-show="!awaitingMidtransOrderCode"
                x-on:click="submit()"
                :disabled="submitting || (method === 'split' ? remaining > 0 : false)"
                class="px-6 py-2.5 rounded-lg bg-primary hover:bg-primary/90 text-white font-medium text-sm flex items-center gap-2 shadow-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
                <span x-show="submitting" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true"></span>
                <span class="material-symbols-rounded text-[16px]" x-show="!submitting" x-text="(method === 'qris' && qrisMode === 'midtrans') ? 'qr_code_scanner' : 'check_circle'"></span>
                <span x-text="submitting ? 'Processing Payment...' : ((method === 'qris' && qrisMode === 'midtrans') ? 'Proceed to QR Payment' : 'Confirm & Complete Order')"></span>
            </button>
            <button type="button"
                x-show="awaitingMidtransOrderCode"
                x-cloak
                x-on:click="checkMidtransStatus()"
                :disabled="verifyingMidtrans"
                class="px-6 py-2.5 rounded-lg bg-primary hover:bg-primary/90 text-white font-medium text-sm flex items-center gap-2 shadow-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
                <span x-show="verifyingMidtrans" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true"></span>
                <span class="material-symbols-rounded text-[16px]" x-show="!verifyingMidtrans">verified</span>
                <span x-text="verifyingMidtrans ? 'Verifying...' : 'Verify & Complete Order'"></span>
            </button>
        </div>
    </div>
</x-ui.modal>

<script>
    function registerCheckoutPayment() {
        if (!window.Alpine) return;
        if (window.__checkoutPaymentRegistered) return;
        window.__checkoutPaymentRegistered = true;

        Alpine.data('checkoutPayment', () => ({
            total: 0,
            legs: [],
            method: 'cash',
            qrisMode: 'midtrans',
            qrisReference: '',
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
            awaitingMidtransOrderCode: null,
            awaitingSnapToken: null,
            verifyingMidtrans: false,
            pollTimer: null,

            getWire() {
                if (this.$wire) return this.$wire;
                if (typeof window.Livewire !== 'undefined') {
                    const el = this.$el?.closest('[wire\\:id]');
                    if (el) {
                        return window.Livewire.find(el.getAttribute('wire:id'));
                    }
                    return window.Livewire.first();
                }
                return null;
            },

            init() {
                this._openModalHandler = (e) => {
                    this.stopPolling();
                    this.total = e.detail.total;
                    this.legs = [];
                    this.method = e.detail.method || 'cash';
                    this.qrisMode = 'midtrans';
                    this.qrisReference = '';
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
                    this.awaitingMidtransOrderCode = null;
                    this.awaitingSnapToken = null;
                    this.verifyingMidtrans = false;
                    this.idempotencyKey = (window.crypto && window.crypto.randomUUID) ?
                        window.crypto.randomUUID() :
                        (Date.now().toString(36) + Math.random().toString(36).slice(2));
                };
                window.addEventListener('open-checkout-modal', this._openModalHandler);

                if (window.Livewire) {
                    this.bindMidtransEvents();
                } else {
                    document.addEventListener('livewire:init', () => this.bindMidtransEvents());
                }
            },

            bindMidtransEvents() {
                Livewire.on('midtrans-payment-pending', (e) => {
                    const payload = Array.isArray(e) ? e[0] : (e?.detail ?? e);
                    if (!payload?.orderCode) return;

                    this.method = 'qris';
                    this.qrisMode = 'midtrans';
                    this.awaitingMidtransOrderCode = payload.orderCode;
                    this.awaitingSnapToken = payload.snapToken || null;
                    this.errorMsg = '';

                    if (this.awaitingSnapToken) {
                        setTimeout(() => this.openSnapPopup(), 250);
                    }
                    this.startPolling();
                });

                Livewire.on('midtrans-payment-failed', (e) => {
                    const payload = Array.isArray(e) ? e[0] : (e?.detail ?? e);
                    this.stopPolling();
                    this.verifyingMidtrans = false;
                    this.errorMsg = 'Midtrans payment was ' + (payload?.status || 'cancelled/expired') + '. Please create a new order.';
                });

                Livewire.on('midtrans-payment-still-pending', () => {
                    this.verifyingMidtrans = false;
                });

                Livewire.on('transaction-success', () => {
                    this.stopPolling();
                    this.verifyingMidtrans = false;
                    this.awaitingMidtransOrderCode = null;
                    this.awaitingSnapToken = null;
                    this.closeModal();
                });
            },

            openSnapPopup() {
                if (!this.awaitingSnapToken) return;
                if (typeof window.snap !== 'undefined' && window.snap?.pay) {
                    window.snap.pay(this.awaitingSnapToken, {
                        onSuccess: () => { this.checkMidtransStatus(); },
                        onPending: () => { this.checkMidtransStatus(); },
                        onError: () => {
                            this.errorMsg = 'Midtrans payment attempt encountered an error. You may retry or verify status.';
                        },
                        onClose: () => {}
                    });
                }
            },

            async checkMidtransStatus() {
                if (!this.awaitingMidtransOrderCode || this.verifyingMidtrans) return;
                this.verifyingMidtrans = true;
                try {
                    const wire = this.getWire();
                    if (wire) {
                        await wire.call('verifyMidtransPayment', this.awaitingMidtransOrderCode);
                    }
                } catch (e) {
                    // Ignore transient network errors during polling
                } finally {
                    this.verifyingMidtrans = false;
                }
            },

            startPolling() {
                this.stopPolling();
                this.pollTimer = setInterval(() => {
                    if (this.awaitingMidtransOrderCode && !this.verifyingMidtrans) {
                        this.checkMidtransStatus();
                    }
                }, 4000);
            },

            stopPolling() {
                if (this.pollTimer) {
                    clearInterval(this.pollTimer);
                    this.pollTimer = null;
                }
            },

            closeModal() {
                this.stopPolling();
                this.$dispatch('close');
            },

            destroy() {
                this.stopPolling();
                if (this._openModalHandler) {
                    window.removeEventListener('open-checkout-modal', this._openModalHandler);
                }
            },

            setMethod(m) {
                this.method = m;
                this.errorMsg = '';
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
                    cash: 'Cash',
                    qris: 'QRIS',
                    ewallet: 'E-Wallet',
                    card: 'Card',
                    gift_card: 'Gift Card',
                    store_credit: 'Bank Transfer'
                } [m] ?? m;
            },

            addSplitLeg() {
                this.errorMsg = '';
                if (!this.splitAmountRaw || this.splitAmountRaw <= 0) {
                    this.errorMsg = 'Please enter an allocation amount.';
                    return;
                }
                const applied = Math.min(this.splitAmountRaw, this.remaining);
                const tendered = this.splitTargetMethod === 'cash' ? Math.max(applied, this.splitAmountRaw) : applied;
                const change = this.splitTargetMethod === 'cash' ? Math.max(0, tendered - applied) : 0;

                this.legs.push({
                    uid: Date.now() + Math.random(),
                    method: this.splitTargetMethod,
                    label: this.methodLabel(this.splitTargetMethod),
                    amount: applied,
                    amount_tendered: tendered,
                    change_amount: change,
                });
                this.splitAmountRaw = 0;
                this.splitAmountFormatted = '';
            },

            removeLeg(uid) {
                this.legs = this.legs.filter(l => l.uid !== uid);
            },

            async submit() {
                if (this.submitting) return;
                this.errorMsg = '';

                const wire = this.getWire();
                if (!wire) {
                    this.errorMsg = 'Cashier connection lost. Please refresh the page.';
                    return;
                }

                const livewireTotal = await wire.get('totalAmount');
                if (livewireTotal !== undefined && livewireTotal !== null && livewireTotal !== this.total) {
                    this.total = livewireTotal;
                    if (this.method === 'split' && this.remaining > 0) {
                        this.errorMsg = 'Order total changed (Rp ' + this.formatRupiah(livewireTotal) + '). Please re-adjust split allocations.';
                        return;
                    }
                }

                let payload = [];
                let useMidtransGateway = false;

                if (this.method === 'split') {
                    if (this.remaining > 0 || this.legs.length === 0) {
                        this.errorMsg = 'Split payment incomplete. Remaining: Rp ' + this.formatRupiah(this.remaining);
                        return;
                    }
                    payload = this.legs.map(l => ({
                        method: l.method,
                        amount: l.amount,
                        amount_tendered: l.amount_tendered ?? l.amount,
                        change_amount: l.change_amount ?? 0,
                        reference_number: l.reference_number ?? null,
                    }));
                } else if (this.method === 'cash') {
                    if (this.amountRaw < this.total) {
                        this.errorMsg = 'Cash received is less than total amount due!';
                        return;
                    }
                    payload = [{
                        method: 'cash',
                        amount: this.total,
                        amount_tendered: this.amountRaw,
                        change_amount: Math.max(0, this.amountRaw - this.total),
                    }];
                } else if (this.method === 'qris') {
                    useMidtransGateway = (this.qrisMode === 'midtrans');
                    payload = [{
                        method: 'qris',
                        amount: this.total,
                        amount_tendered: this.total,
                        change_amount: 0,
                        reference_number: this.qrisReference ? ('QRIS-' + this.qrisReference.trim()) : null,
                        use_midtrans_gateway: useMidtransGateway,
                    }];
                } else if (this.method === 'card') {
                    const cardRef = [this.edcBank, (this.edcTrace || '').trim()].filter(Boolean).join('-');
                    payload = [{
                        method: 'card',
                        amount: this.total,
                        amount_tendered: this.total,
                        change_amount: 0,
                        card_number: cardRef || null,
                        reference_number: cardRef || null,
                    }];
                } else if (this.method === 'store_credit') {
                    const transferRef = (this.transferSender || '').trim();
                    payload = [{
                        method: 'store_credit',
                        amount: this.total,
                        amount_tendered: this.total,
                        change_amount: 0,
                        reference_number: transferRef ? ('TRF-' + transferRef) : null,
                    }];
                } else {
                    payload = [{
                        method: this.method,
                        amount: this.total,
                        amount_tendered: this.total,
                        change_amount: 0,
                    }];
                }

                this.submitting = true;
                try {
                    await wire.call('submitOrder', payload, this.idempotencyKey, useMidtransGateway);
                } catch (err) {
                    this.errorMsg = err?.message || 'Unable to complete cashier payment. Please try again.';
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

            // [ZERO HARD RELOAD] Tutup modal dan reset dirty flag secara instan tanpa reload halaman
            window.apeironPosCartDirty = false;
            window.dispatchEvent(new CustomEvent('close-modal', { detail: 'checkout-payment' }));
        });
    }

    if (window.Livewire) {
        registerTransactionSuccessListener();
    } else {
        document.addEventListener('livewire:init', registerTransactionSuccessListener);
    }
</script>
