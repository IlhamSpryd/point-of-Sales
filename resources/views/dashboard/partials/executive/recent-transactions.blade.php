{{-- ========================================================================= --}}
{{-- SECTION F: RECENT TRANSACTIONS (10-COL AUDIT TABLE + RECEIPT TRIGGER)     --}}
{{-- ========================================================================= --}}
@php
    $txData = $executiveData['recent_transactions'] ?? [];
    $transactions = $txData['items'] ?? [];
    $totalTx = (int) ($txData['total'] ?? count($transactions));
@endphp

<section aria-label="Recent Transactions" class="rounded-xl border border-border bg-card p-4 sm:p-5 space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
            <h2 class="text-sm sm:text-base font-semibold text-foreground">Recent Transactions</h2>
            <p class="text-xs text-muted-foreground mt-0.5">
                Real-time POS checkout audit log with digital receipt and general ledger inspection.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-muted-foreground tabular-nums">
                {{ number_format($totalTx, 0, ',', '.') }} Transactions Total
            </span>
            <a href="{{ route('orders.index') }}" wire:navigate
               class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline ml-2">
                <span>View all</span>
                <span class="material-symbols-rounded text-sm">arrow_forward</span>
            </a>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-xs text-left">
            <thead class="border-b border-border text-muted-foreground font-semibold">
                <tr>
                    <th scope="col" class="py-2.5 pr-3">Order Code</th>
                    <th scope="col" class="py-2.5 px-2.5">Date &amp; Time</th>
                    <th scope="col" class="py-2.5 px-2.5">Branch</th>
                    <th scope="col" class="py-2.5 px-2.5">Customer</th>
                    <th scope="col" class="py-2.5 px-2.5">Cashier</th>
                    <th scope="col" class="py-2.5 px-2.5">Payment</th>
                    <th scope="col" class="py-2.5 px-2.5 text-right">Amount</th>
                    <th scope="col" class="py-2.5 px-2.5 text-center">Status</th>
                    <th scope="col" class="py-2.5 pl-2.5 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
                @forelse($transactions as $tx)
                    @php
                        $st = strtolower((string) ($tx['status'] ?? $tx['order_status'] ?? 'pending'));
                        $isPaid = in_array($st, ['paid', 'completed'], true);
                        $isPending = in_array($st, ['pending', 'processing'], true);
                    @endphp
                    <tr wire:key="recent-tx-{{ $tx['id'] }}"
                        @click="openReceiptDrawer(@js($tx))"
                        class="hover:bg-muted/40 transition-colors cursor-pointer group">
                        <td class="py-3 pr-3 font-semibold text-foreground flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-sm text-primary">receipt</span>
                            <span>{{ $tx['order_code'] }}</span>
                        </td>
                        <td class="py-3 px-2.5 text-muted-foreground tabular-nums whitespace-nowrap">
                            {{ $tx['date_time'] ?? '-' }}
                        </td>
                        <td class="py-3 px-2.5 text-foreground truncate max-w-[120px]">
                            {{ $tx['store_name'] ?? 'Branch' }}
                        </td>
                        <td class="py-3 px-2.5 text-foreground truncate max-w-[130px]">
                            {{ $tx['customer_name'] ?? 'Pelanggan Umum' }}
                        </td>
                        <td class="py-3 px-2.5 text-muted-foreground truncate max-w-[110px]">
                            {{ $tx['cashier_name'] ?? 'Kasir' }}
                        </td>
                        <td class="py-3 px-2.5 text-foreground font-medium">
                            <span class="px-1.5 py-0.5 rounded bg-muted text-[11px] font-mono">
                                {{ $tx['payment_method'] ?? 'CASH' }}
                            </span>
                        </td>
                        <td class="py-3 px-2.5 text-right font-bold text-foreground tabular-nums whitespace-nowrap">
                            {{ $tx['total_formatted'] ?? ('Rp ' . number_format((float) ($tx['total'] ?? 0), 0, ',', '.')) }}
                        </td>
                        <td class="py-3 px-2.5 text-center whitespace-nowrap">
                            @if($isPaid)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-success-muted text-success border border-success-border">
                                    Paid
                                </span>
                            @elseif($isPending)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-warning-muted text-warning border border-warning-border">
                                    Pending
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-destructive-muted text-destructive border border-destructive-border">
                                    {{ ucfirst($st) }}
                                </span>
                            @endif
                        </td>
                        <td class="py-3 pl-2.5 text-right whitespace-nowrap">
                            <button type="button"
                                    @click.stop="openReceiptDrawer(@js($tx))"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium text-foreground bg-secondary hover:bg-muted border border-border transition-colors cursor-pointer"
                                    title="View Digital Receipt & GL Posting">
                                <span class="material-symbols-rounded text-sm">visibility</span>
                                <span class="hidden sm:inline">Receipt</span>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="py-8 text-center text-xs text-muted-foreground">
                            No recent transactions found for the selected period.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

