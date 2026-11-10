@props([
    'poNumber' => '',
    'grnNumber' => '',
    'invoiceNumber' => '',
    'poTotal' => 0,
    'grnTotal' => 0,
    'invoiceTotal' => 0,
    'lines' => [],
])

@php
    $poTotalVal = (float) $poTotal;
    $grnTotalVal = (float) $grnTotal;
    $invoiceTotalVal = (float) $invoiceTotal;
    $variance = $invoiceTotalVal - $poTotalVal;
    $isMatched = abs($variance) < 0.01;
@endphp

<div {{ $attributes->twMerge('space-y-4') }}>
    {{-- Summary Metric Cards (TailAdmin KPI Trio) --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="card-surface bg-card border border-border rounded-xl p-4 shadow-xs">
            <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground block">
                1. Purchase Order (PO)
            </span>
            <div class="text-lg font-bold text-foreground mt-1 truncate">
                {{ $poNumber }}
            </div>
            <div class="text-xl font-extrabold text-foreground mt-1 numeric font-sans">
                Rp {{ number_format($poTotalVal, 0, ',', '.') }}
            </div>
        </div>

        <div class="card-surface bg-card border border-border rounded-xl p-4 shadow-xs">
            <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground block">
                2. Goods Received (GRN)
            </span>
            <div class="text-lg font-bold text-foreground mt-1 truncate">
                {{ $grnNumber }}
            </div>
            <div class="text-xl font-extrabold text-foreground mt-1 numeric font-sans">
                Rp {{ number_format($grnTotalVal, 0, ',', '.') }}
            </div>
        </div>

        <div class="card-surface bg-card border rounded-xl p-4 shadow-xs {{ $isMatched ? 'border-success-border bg-success-muted/20' : 'border-destructive-border bg-destructive-muted/20' }}">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    3. Supplier Invoice (AP)
                </span>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border {{ $isMatched ? 'bg-success-muted text-success border-success-border' : 'bg-destructive-muted text-destructive border-destructive-border' }}">
                    <span class="material-symbols-rounded text-[14px]">{{ $isMatched ? 'check_circle' : 'warning' }}</span>
                    <span>{{ $isMatched ? 'Matched' : 'Discrepancy' }}</span>
                </span>
            </div>
            <div class="text-lg font-bold text-foreground mt-1 truncate">
                {{ $invoiceNumber }}
            </div>
            <div class="text-xl font-extrabold text-foreground mt-1 numeric font-sans">
                Rp {{ number_format($invoiceTotalVal, 0, ',', '.') }}
            </div>
        </div>
    </div>

    {{-- Detailed 3-Way Line Comparison Table --}}
    <div class="card-surface bg-card border border-border rounded-xl overflow-hidden shadow-xs">
        <div class="px-5 py-3.5 border-b border-border bg-muted flex items-center justify-between">
            <h4 class="text-xs font-bold uppercase tracking-wider text-foreground">
                3-Way Line Item Matching
            </h4>
            @if (!$isMatched)
                <span class="text-xs font-semibold text-destructive numeric font-sans">
                    Variance: Rp {{ number_format(abs($variance), 0, ',', '.') }}
                </span>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-border bg-muted text-muted-foreground uppercase font-bold text-[10px] tracking-wider">
                    <tr>
                        <th class="px-4 py-3">Item / Raw Material</th>
                        <th class="px-3 py-3 text-right">PO Qty</th>
                        <th class="px-3 py-3 text-right">GRN Qty</th>
                        <th class="px-3 py-3 text-right">Invoice Qty</th>
                        <th class="px-3 py-3 text-right">PO Unit Price</th>
                        <th class="px-3 py-3 text-right">Invoice Unit Price</th>
                        <th class="px-4 py-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($lines as $line)
                        @php
                            $linePoQty = (float) ($line['po_qty'] ?? 0);
                            $lineGrnQty = (float) ($line['grn_qty'] ?? 0);
                            $lineInvQty = (float) ($line['inv_qty'] ?? 0);
                            $linePoPrice = (float) ($line['po_price'] ?? 0);
                            $lineInvPrice = (float) ($line['inv_price'] ?? 0);
                            $lineMatch = ($linePoQty == $lineGrnQty) && ($lineGrnQty == $lineInvQty) && ($linePoPrice == $lineInvPrice);
                        @endphp
                        <tr class="hover:bg-muted transition-colors">
                            <td class="px-4 py-3 font-medium text-foreground">
                                {{ $line['item_name'] ?? 'Item' }}
                            </td>
                            <td class="px-3 py-3 text-right numeric font-sans text-muted-foreground">
                                {{ number_format($linePoQty, 0) }}
                            </td>
                            <td class="px-3 py-3 text-right numeric font-sans font-semibold {{ $lineGrnQty < $linePoQty ? 'text-warning' : 'text-foreground' }}">
                                {{ number_format($lineGrnQty, 0) }}
                            </td>
                            <td class="px-3 py-3 text-right numeric font-sans font-semibold {{ $lineInvQty != $lineGrnQty ? 'text-destructive' : 'text-foreground' }}">
                                {{ number_format($lineInvQty, 0) }}
                            </td>
                            <td class="px-3 py-3 text-right numeric font-sans text-muted-foreground">
                                Rp {{ number_format($linePoPrice, 0, ',', '.') }}
                            </td>
                            <td class="px-3 py-3 text-right numeric font-sans font-semibold {{ $lineInvPrice != $linePoPrice ? 'text-destructive' : 'text-foreground' }}">
                                Rp {{ number_format($lineInvPrice, 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if ($lineMatch)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-success-muted text-success border border-success-border">
                                        <span class="material-symbols-rounded text-xs">check</span>
                                        <span>Match</span>
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-destructive-muted text-destructive border border-destructive-border">
                                        <span class="material-symbols-rounded text-xs">close</span>
                                        <span>Variance</span>
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
                                No line items available for matching.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
