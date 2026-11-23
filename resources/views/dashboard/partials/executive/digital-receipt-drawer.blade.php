{{-- ========================================================================= --}}
{{-- SLIDE-OVER INSPECTOR: DIGITAL RECEIPT & DOUBLE-ENTRY GL LEDGER POSTING     --}}
{{-- ========================================================================= --}}
<div x-cloak x-show="receiptDrawerOpen" class="fixed inset-0 z-50 overflow-hidden" aria-labelledby="receipt-drawer-title" role="dialog" aria-modal="true">
    {{-- Backdrop --}}
    <div x-show="receiptDrawerOpen"
         x-transition:enter="ease-in-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in-out duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="closeReceiptDrawer()"
         class="fixed inset-0 bg-background/80 backdrop-blur-xs transition-opacity"></div>

    <div class="fixed inset-y-0 right-0 max-w-full flex pl-10">
        <div x-show="receiptDrawerOpen"
             x-transition:enter="transform transition ease-in-out duration-300 sm:duration-400"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transform transition ease-in-out duration-300 sm:duration-400"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             class="w-screen max-w-md lg:max-w-lg bg-card border-l border-border shadow-2xl flex flex-col justify-between">

            {{-- Header --}}
            <div class="p-5 sm:p-6 border-b border-border flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-primary text-2xl">receipt_long</span>
                    <div>
                        <h2 class="text-base font-bold text-foreground" id="receipt-drawer-title"
                            x-text="selectedReceipt ? ('Receipt #' + selectedReceipt.order_code) : 'Digital Receipt'">
                            Digital Receipt
                        </h2>
                        <p class="text-xs text-muted-foreground mt-0.5"
                           x-text="selectedReceipt ? (selectedReceipt.store_name + ' · ' + (selectedReceipt.date_time || '')) : 'Order Details & Ledger Entry'">
                            Order Details &amp; Ledger Entry
                        </p>
                    </div>
                </div>
                <button type="button"
                        @click="closeReceiptDrawer()"
                        aria-label="Close receipt drawer"
                        class="rounded-lg p-1.5 text-muted-foreground hover:text-foreground hover:bg-muted transition-colors cursor-pointer">
                    <span class="material-symbols-rounded text-xl">close</span>
                </button>
            </div>

            {{-- Body --}}
            <div class="p-5 sm:p-6 flex-1 overflow-y-auto space-y-6">
                {{-- Loading Skeleton --}}
                <template x-if="isLoadingReceipt">
                    <div class="space-y-4 animate-pulse">
                        <div class="h-28 bg-muted rounded-xl"></div>
                        <div class="h-44 bg-muted rounded-xl"></div>
                        <div class="h-28 bg-muted rounded-xl"></div>
                    </div>
                </template>

                {{-- Loaded Receipt Content --}}
                <template x-if="!isLoadingReceipt && selectedReceipt">
                    <div class="space-y-6">
                        {{-- Printable Receipt Paper Simulation --}}
                        <div class="p-5 rounded-2xl bg-secondary border border-border shadow-xs space-y-4 font-mono text-xs">
                            {{-- Receipt Header --}}
                            <div class="text-center pb-3 border-b border-border/80 space-y-1">
                                <div class="text-sm font-bold uppercase tracking-wider text-foreground"
                                     x-text="receiptDetailData?.order?.store_name || selectedReceipt.store_name"></div>
                                <div class="text-[11px] text-muted-foreground"
                                     x-text="'Order Code: ' + (receiptDetailData?.order?.order_code || selectedReceipt.order_code)"></div>
                                <div class="text-[11px] text-muted-foreground"
                                     x-text="receiptDetailData?.order?.date_time || selectedReceipt.date_time"></div>
                                <div class="text-[10px] text-muted-foreground mt-1">
                                    Cashier: <span class="text-foreground font-semibold" x-text="receiptDetailData?.order?.cashier_name || selectedReceipt.cashier_name"></span> ·
                                    Customer: <span class="text-foreground font-semibold" x-text="receiptDetailData?.order?.customer_name || selectedReceipt.customer_name"></span>
                                </div>
                            </div>

                            {{-- Line Items Table --}}
                            <div class="space-y-2 py-2 border-b border-border/80">
                                <template x-for="(line, idx) in (receiptDetailData?.items || [])" :key="idx">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex-1 min-w-0">
                                            <div class="font-bold text-foreground truncate" x-text="line.product_name"></div>
                                            <div class="text-[10px] text-muted-foreground tabular-nums"
                                                 x-text="line.quantity + ' × ' + line.unit_price_formatted"></div>
                                        </div>
                                        <div class="text-right font-bold text-foreground tabular-nums whitespace-nowrap"
                                             x-text="line.subtotal_formatted"></div>
                                    </div>
                                </template>
                                <template x-if="!receiptDetailData?.items || receiptDetailData.items.length === 0">
                                    <div class="flex items-center justify-between font-bold text-foreground">
                                        <span>Order Total</span>
                                        <span class="tabular-nums" x-text="selectedReceipt.total_formatted"></span>
                                    </div>
                                </template>
                            </div>

                            {{-- Summary & Totals --}}
                            <div class="space-y-1.5 pt-1 text-xs">
                                <div class="flex justify-between text-muted-foreground">
                                    <span>Subtotal</span>
                                    <span class="tabular-nums" x-text="receiptDetailData?.order?.subtotal_formatted || selectedReceipt.total_formatted"></span>
                                </div>
                                <template x-if="(receiptDetailData?.order?.discount || 0) > 0">
                                    <div class="flex justify-between text-destructive">
                                        <span>Discount</span>
                                        <span class="tabular-nums" x-text="'-' + receiptDetailData?.order?.discount_formatted"></span>
                                    </div>
                                </template>
                                <div class="flex justify-between font-extrabold text-sm text-foreground pt-1 border-t border-border/60">
                                    <span>Total Amount</span>
                                    <span class="tabular-nums text-primary" x-text="receiptDetailData?.order?.total_formatted || selectedReceipt.total_formatted"></span>
                                </div>
                                <div class="flex justify-between text-[11px] text-muted-foreground pt-1">
                                    <span>Tender Method</span>
                                    <span class="font-bold text-foreground uppercase" x-text="selectedReceipt.payment_method"></span>
                                </div>
                            </div>
                        </div>

                        {{-- Double-Entry GL Ledger Posting --}}
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-1.5">
                                    <span class="material-symbols-rounded text-sm text-primary">account_balance</span>
                                    <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">GL Subledger Postings</h3>
                                </div>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                      :class="receiptDetailData?.gl_journal ? 'bg-success-muted text-success border border-success-border' : 'bg-muted text-muted-foreground'"
                                      x-text="receiptDetailData?.gl_journal ? 'POSTED & IN SYNC' : 'AUTO POSTED'">
                                </span>
                            </div>

                            <template x-if="receiptDetailData?.gl_journal">
                                <div class="overflow-x-auto rounded-xl border border-border">
                                    <table class="w-full text-xs text-left">
                                        <thead class="bg-muted/50 border-b border-border text-muted-foreground font-semibold">
                                            <tr>
                                                <th scope="col" class="py-2.5 px-3">Account</th>
                                                <th scope="col" class="py-2.5 px-2.5 text-right">Debit</th>
                                                <th scope="col" class="py-2.5 px-3 text-right">Credit</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-border/60">
                                            <template x-for="(line, idx) in receiptDetailData.gl_journal.lines" :key="idx">
                                                <tr class="hover:bg-muted/30">
                                                    <td class="py-2 px-3">
                                                        <span class="font-mono font-semibold text-foreground" x-text="line.account_code"></span>
                                                        <span class="text-muted-foreground ml-1" x-text="line.account_name"></span>
                                                    </td>
                                                    <td class="py-2 px-2.5 text-right tabular-nums text-foreground font-medium"
                                                        x-text="line.debit > 0 ? ('Rp ' + new Intl.NumberFormat('id-ID').format(line.debit)) : '-'"></td>
                                                    <td class="py-2 px-3 text-right tabular-nums text-foreground font-medium"
                                                        x-text="line.credit > 0 ? ('Rp ' + new Intl.NumberFormat('id-ID').format(line.credit)) : '-'"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </template>

                            <template x-if="!receiptDetailData?.gl_journal">
                                <div class="p-3 rounded-lg bg-secondary border border-border text-xs text-muted-foreground">
                                    Subledger entry posted in real-time to Cash/Bank &amp; Sales Revenue accounts.
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Footer --}}
            <div class="p-4 border-t border-border bg-secondary flex items-center justify-between">
                <button type="button"
                        onclick="window.print()"
                        class="px-3.5 py-2 rounded-xl text-xs font-semibold text-foreground bg-card hover:bg-muted border border-border shadow-xs transition-colors cursor-pointer inline-flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-sm">print</span>
                    <span>Print Receipt</span>
                </button>
                <button type="button"
                        @click="closeReceiptDrawer()"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-primary-foreground bg-primary hover:opacity-90 transition-opacity cursor-pointer">
                    Done
                </button>
            </div>
        </div>
    </div>
</div>

