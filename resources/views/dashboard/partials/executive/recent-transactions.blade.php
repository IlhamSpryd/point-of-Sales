{{-- ========================================================================= --}}
{{-- SECTION F: RECENT TRANSACTIONS — COMPACT 14" SCALE & ZERO PILLS           --}}
{{-- ========================================================================= --}}
@php
    $txData = $executiveData['recent_transactions'] ?? [];
    $transactions = $txData['items'] ?? [];
    $totalTx = (int) ($txData['total'] ?? count($transactions));
@endphp

<section aria-label="Recent Transactions"
         class="rounded-[10px] border border-border bg-card p-3.5 sm:p-4 space-y-3"
         x-data="{
             search: '',
             dateFilter: 'ALL',
             paymentFilter: 'ALL',
             currentPage: 1,
             pageSize: 10,
             allTx: @js($transactions),

             get filteredTx() {
                 let rows = this.allTx;
                 if (this.search.trim()) {
                     const q = this.search.toLowerCase();
                     rows = rows.filter(t =>
                         (t.order_code || '').toLowerCase().includes(q) ||
                         (t.customer_name || '').toLowerCase().includes(q) ||
                         (t.cashier_name || '').toLowerCase().includes(q) ||
                         (t.store_name || '').toLowerCase().includes(q)
                     );
                 }
                 if (this.paymentFilter !== 'ALL') {
                     rows = rows.filter(t => (t.payment_method || '').toUpperCase().includes(this.paymentFilter.toUpperCase()));
                 }
                 if (this.dateFilter !== 'ALL') {
                     const today = '{{ now()->setTimezone('Asia/Jakarta')->format('Y-m-d') }}';
                     const yest = '{{ now()->setTimezone('Asia/Jakarta')->subDay()->format('Y-m-d') }}';
                     if (this.dateFilter === 'TODAY') {
                         rows = rows.filter(t => (t.order_date || '').startsWith(today));
                     } else if (this.dateFilter === 'YESTERDAY') {
                         rows = rows.filter(t => (t.order_date || '').startsWith(yest));
                     }
                 }
                 return rows;
             },
             get totalPages() {
                 return Math.max(1, Math.ceil(this.filteredTx.length / this.pageSize));
             },
             get pagedTx() {
                 const start = (this.currentPage - 1) * this.pageSize;
                 return this.filteredTx.slice(start, start + this.pageSize);
             },
             nextPage() {
                 if (this.currentPage < this.totalPages) this.currentPage++;
             },
             prevPage() {
                 if (this.currentPage > 1) this.currentPage--;
             }
         }">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 pb-2 border-b border-border">
        <div>
            <h2 class="text-[13.5px] font-semibold text-foreground tracking-[-0.01em]">Recent Transactions</h2>
            <p class="text-[11px] text-muted-foreground mt-0.5">
                Klik baris transaksi untuk melihat resi digital, detail item, pajak PB1, dan jurnal buku besar.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Search ID, customer, cashier -->
            <div class="relative">
                <span class="material-symbols-rounded text-sm text-muted-foreground absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none">search</span>
                <input type="text"
                       x-model="search"
                       @input="currentPage = 1"
                       placeholder="Search ID, customer, cashier..."
                       aria-label="Search transactions"
                       class="h-7 pl-7 pr-2.5 rounded-md bg-secondary border border-border text-xs text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring w-44 sm:w-52" />
            </div>

            <!-- Date Filter Dropdown -->
            <select x-model="dateFilter"
                    @change="currentPage = 1"
                    aria-label="Filter by date"
                    class="h-7 px-2 rounded-md bg-secondary border border-border text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring cursor-pointer">
                <option value="ALL">All Dates</option>
                <option value="TODAY">Today ({{ now()->setTimezone('Asia/Jakarta')->format('d M') }})</option>
                <option value="YESTERDAY">Yesterday ({{ now()->setTimezone('Asia/Jakarta')->subDay()->format('d M') }})</option>
            </select>

            <!-- Payment Method Filter Dropdown -->
            <select x-model="paymentFilter"
                    @change="currentPage = 1"
                    aria-label="Filter by payment method"
                    class="h-7 px-2 rounded-md bg-secondary border border-border text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring cursor-pointer">
                <option value="ALL">All Methods</option>
                <option value="QRIS">QRIS</option>
                <option value="CARD">Card / EDC</option>
                <option value="CASH">Cash</option>
                <option value="INVOICE">Transfer / Invoice</option>
            </select>
        </div>
    </div>

    <!-- 8-Column Accessible Audit Table -->
    <div class="overflow-x-auto -mx-3.5 sm:-mx-4 px-3.5 sm:px-4">
        <table class="w-full text-xs text-left border-collapse min-w-[720px]">
            <thead class="border-b border-border text-muted-foreground font-semibold text-[11px]">
                <tr>
                    <th scope="col" class="py-2 pr-3 text-left">Transaction ID</th>
                    <th scope="col" class="py-2 px-2.5 text-left">Date &amp; Time</th>
                    <th scope="col" class="py-2 px-2.5 text-left">Customer</th>
                    <th scope="col" class="py-2 px-2.5 text-left">Cashier</th>
                    <th scope="col" class="py-2 px-2.5 text-left">Outlet</th>
                    <th scope="col" class="py-2 px-2.5 text-left">Payment Method</th>
                    <th scope="col" class="py-2 px-2.5 text-right">Total Amount</th>
                    <th scope="col" class="py-2 pl-2.5 text-right">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
                <template x-for="tx in pagedTx" :key="tx.id">
                    <tr @click="openReceiptDrawer(tx)"
                        @keydown.enter="openReceiptDrawer(tx)"
                        tabindex="0"
                        role="button"
                        class="hover:bg-secondary/40 transition-colors cursor-pointer group focus:outline-none focus:bg-secondary/50">
                        <td class="py-2 pr-3 font-mono font-semibold text-foreground flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-sm text-foreground">receipt</span>
                            <span x-text="tx.order_code"></span>
                        </td>
                        <td class="py-2 px-2.5 text-muted-foreground tabular-nums whitespace-nowrap text-[11px]" x-text="tx.date_time || '-'"></td>
                        <td class="py-2 px-2.5 text-foreground truncate max-w-[120px]" x-text="tx.customer_name || 'Pelanggan Umum'"></td>
                        <td class="py-2 px-2.5 text-muted-foreground truncate max-w-[110px]" x-text="tx.cashier_name || 'Kasir'"></td>
                        <td class="py-2 px-2.5 text-foreground truncate max-w-[110px]" x-text="tx.store_name || 'Branch'"></td>
                        <td class="py-2 px-2.5 text-foreground font-mono text-[11px]" x-text="tx.payment_method || 'CASH'"></td>
                        <td class="py-2 px-2.5 text-right font-bold text-foreground tabular-nums whitespace-nowrap" x-text="tx.total_formatted"></td>
                        <td class="py-2 pl-2.5 text-right whitespace-nowrap font-medium">
                            <span class="inline-flex items-center gap-1.5 text-[11px] text-foreground">
                                <span class="w-1.5 h-1.5 rounded-full"
                                      :class="['paid', 'completed'].includes((tx.status || '').toLowerCase()) ? 'bg-positive' : (['pending', 'processing'].includes((tx.status || '').toLowerCase()) ? 'bg-warning' : 'bg-destructive')"></span>
                                <span x-text="['paid', 'completed'].includes((tx.status || '').toLowerCase()) ? 'Paid' : (['pending', 'processing'].includes((tx.status || '').toLowerCase()) ? 'Pending' : 'Void')"></span>
                            </span>
                        </td>
                    </tr>
                </template>
                <tr x-show="filteredTx.length === 0">
                    <td colspan="8" class="py-8 text-center text-xs text-muted-foreground">
                        No recent transactions found for the active filter.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls -->
    <div class="pt-2.5 border-t border-border flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs text-muted-foreground">
        <div class="text-[11px]">
            Showing <strong class="text-foreground" x-text="filteredTx.length > 0 ? (currentPage - 1) * pageSize + 1 : 0"></strong>–<strong class="text-foreground" x-text="Math.min(currentPage * pageSize, filteredTx.length)"></strong> of <strong class="text-foreground" x-text="filteredTx.length"></strong> transactions
        </div>
        <div class="flex items-center gap-1.5 self-end sm:self-auto">
            <button type="button"
                    @click="prevPage()"
                    :disabled="currentPage <= 1"
                    :class="currentPage <= 1 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-secondary cursor-pointer'"
                    class="h-6 px-2 rounded border border-border text-foreground font-medium transition-colors text-[11px]">
                Previous
            </button>
            <span class="px-1 text-[11px] tabular-nums">Page <span x-text="currentPage"></span> of <span x-text="totalPages"></span></span>
            <button type="button"
                    @click="nextPage()"
                    :disabled="currentPage >= totalPages"
                    :class="currentPage >= totalPages ? 'opacity-40 cursor-not-allowed' : 'hover:bg-secondary cursor-pointer'"
                    class="h-6 px-2 rounded border border-border text-foreground font-medium transition-colors text-[11px]">
                Next
            </button>
        </div>
    </div>
</section>
