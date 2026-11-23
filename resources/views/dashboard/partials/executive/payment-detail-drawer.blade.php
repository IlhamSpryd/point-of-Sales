{{-- ========================================================================= --}}
{{-- SLIDE-OVER INSPECTOR: PAYMENT DETAIL DRILLDOWN DRAWER                     --}}
{{-- ========================================================================= --}}
<div x-cloak x-show="paymentDrawerOpen" class="fixed inset-0 z-50 overflow-hidden" aria-labelledby="payment-drawer-title" role="dialog" aria-modal="true">
    {{-- Backdrop --}}
    <div x-show="paymentDrawerOpen"
         x-transition:enter="transition-opacity ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="closePaymentDrawer()"
         class="fixed inset-0 bg-background/80 backdrop-blur-xs"></div>

    <div class="fixed inset-y-0 right-0 max-w-full flex pl-8">
        <div x-show="paymentDrawerOpen"
             x-transition:enter="transform transition ease-out duration-200"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transform transition ease-in duration-150"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             class="w-screen max-w-md lg:max-w-lg bg-card border-l border-border shadow-2xl flex flex-col justify-between">

            {{-- Header --}}
            <div class="p-4 sm:p-5 border-b border-border flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-foreground text-2xl">account_balance_wallet</span>
                    <div>
                        <h2 class="text-sm sm:text-base font-bold text-foreground" id="payment-drawer-title" x-text="paymentDetailData?.method_name ? ('Kanal ' + paymentDetailData.method_name) : 'Detail Pembayaran'">
                            Detail Pembayaran
                        </h2>
                        <p class="text-[11px] text-muted-foreground mt-0.5">
                            Rekonsiliasi gateway, distribusi gerai, dan MDR fee
                        </p>
                    </div>
                </div>
                <button type="button"
                        @click="closePaymentDrawer()"
                        aria-label="Tutup panel pembayaran"
                        class="rounded-md p-1 text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer">
                    <span class="material-symbols-rounded text-xl">close</span>
                </button>
            </div>

            {{-- Body --}}
            <div class="p-4 sm:p-5 flex-1 overflow-y-auto space-y-4">
                <template x-if="isLoadingPayment">
                    <div class="py-16 text-center space-y-2">
                        <div class="inline-block w-6 h-6 border-2 border-foreground border-t-transparent rounded-full animate-spin"></div>
                        <p class="text-xs text-muted-foreground">Mengambil data kanal pembayaran...</p>
                    </div>
                </template>

                <template x-if="!isLoadingPayment && paymentDetailData">
                    <div class="space-y-4">
                        {{-- Summary Cards --}}
                        <div class="grid grid-cols-2 gap-2">
                            <div class="p-3 rounded-[8px] bg-secondary border border-border">
                                <div class="text-[10.5px] text-muted-foreground font-medium">Total Volume</div>
                                <div class="text-base font-bold tabular-nums text-foreground mt-0.5"
                                     x-text="'Rp ' + Math.round(paymentDetailData.summary.total_volume).toLocaleString('id-ID')"></div>
                            </div>
                            <div class="p-3 rounded-[8px] bg-secondary border border-border">
                                <div class="text-[10.5px] text-muted-foreground font-medium">Transactions</div>
                                <div class="text-base font-bold tabular-nums text-foreground mt-0.5"
                                     x-text="paymentDetailData.summary.tx_count + ' tx'"></div>
                            </div>
                            <div class="p-3 rounded-[8px] bg-secondary border border-border">
                                <div class="text-[10.5px] text-muted-foreground font-medium">Average Ticket</div>
                                <div class="text-xs font-bold tabular-nums text-foreground mt-0.5"
                                     x-text="'Rp ' + Math.round(paymentDetailData.summary.avg_ticket).toLocaleString('id-ID')"></div>
                            </div>
                            <div class="p-3 rounded-[8px] bg-secondary border border-border">
                                <div class="text-[10.5px] text-muted-foreground font-medium">MDR Fee Estimate</div>
                                <div class="text-xs font-bold tabular-nums text-muted-foreground mt-0.5"
                                     x-text="paymentDetailData.summary.fee_rate + ' MDR rate'"></div>
                            </div>
                        </div>

                        {{-- Breakdown per Outlet --}}
                        <div>
                            <h3 class="text-xs font-semibold text-foreground mb-2">Distribusi per Gerai Cabang</h3>
                            <div class="space-y-1.5">
                                <template x-for="st in paymentDetailData.stores" :key="st.store_name">
                                    <div class="flex items-center justify-between text-xs p-2 rounded bg-secondary/60 border border-border">
                                        <span class="font-medium text-foreground truncate max-w-[200px]" x-text="st.store_name"></span>
                                        <div class="text-right tabular-nums">
                                            <span class="font-semibold text-foreground" x-text="'Rp ' + Math.round(st.amount).toLocaleString('id-ID')"></span>
                                            <span class="text-[10px] text-muted-foreground ml-1" x-text="'(' + st.count + ' tx)'"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Recent Transactions for this method --}}
                        <div>
                            <h3 class="text-xs font-semibold text-foreground mb-2">10 Transaksi Terakhir</h3>
                            <div class="divide-y divide-border border border-border rounded-[8px] overflow-hidden text-xs">
                                <template x-for="tx in paymentDetailData.recent_transactions" :key="tx.id">
                                    <div class="p-2.5 flex items-center justify-between hover:bg-secondary/40 transition-colors">
                                        <div>
                                            <div class="font-mono font-semibold text-foreground text-[11px]" x-text="tx.order_number"></div>
                                            <div class="text-[10px] text-muted-foreground" x-text="tx.date + ' ' + tx.time + ' · Ref: ' + tx.ref"></div>
                                        </div>
                                        <div class="text-right">
                                            <div class="font-bold tabular-nums text-foreground" x-text="'Rp ' + Math.round(tx.amount).toLocaleString('id-ID')"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Footer --}}
            <div class="p-3 sm:p-4 border-t border-border flex items-center justify-between bg-secondary/20">
                <span class="text-[10.5px] text-muted-foreground">Bank &amp; Payment Gateway Settlement Verified</span>
                <button type="button"
                        @click="closePaymentDrawer()"
                        class="h-7 px-3 rounded-md bg-foreground text-background text-xs font-medium cursor-pointer hover:opacity-90 transition-opacity">
                    Tutup
                </button>
            </div>
        </div>
    </div>
</div>

