<x-app-layout>
    <div x-data="{ openEodModal: false }" class="space-y-6" data-gsap="page-enter">
        <x-erp.module-sub-nav module="reports" />

        <!-- Page header -->
        <x-erp.page-header
            :title="$title"
            description="Transaction summary, revenue parity reconciliation, and daily End-of-Day cash closing.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Sales Report</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <form method="GET" action="{{ route('reports.sales') }}" class="flex items-center gap-2">
                    <input type="date" name="start" value="{{ $customStart->toDateString() }}"
                        class="rounded-xl border border-input bg-background px-3 py-1.5 text-xs text-foreground focus:outline-hidden focus:ring-2 focus:ring-ring">
                    <span class="text-xs text-muted-foreground">to</span>
                    <input type="date" name="end" value="{{ $customEnd->toDateString() }}"
                        class="rounded-xl border border-input bg-background px-3 py-1.5 text-xs text-foreground focus:outline-hidden focus:ring-2 focus:ring-ring">
                    <x-ui.button variant="secondary" size="sm" type="submit">
                        Filter
                    </x-ui.button>
                </form>
                @php
                    $startDate = request('start', $customStart->toDateString());
                    $endDate = request('end', $customEnd->toDateString());
                @endphp
                <livewire:async-export-button :start-date="$startDate" :end-date="$endDate" />
            </x-slot:actions>
        </x-erp.page-header>

        @if (session('success'))
            <x-ui.alert type="success" title="Success" class="rounded-2xl">
                {{ session('success') }}
            </x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert type="danger" title="Error Encountered" class="rounded-2xl">
                {{ session('error') }}
            </x-ui.alert>
        @endif

        {{-- EOD / Z-Report Closing Section --}}
        @if ($eodSettlement && $eodSettlement->status === 'closed')
            <div class="card-surface p-6 rounded-2xl border-l-4 border-l-success border border-border shadow-xs">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-border pb-4 mb-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium font-sans">
                                <span class="w-1.5 h-1.5 rounded-full bg-success"></span>
                                CLOSED (LOCKED)
                            </span>
                            <h3 class="text-base font-bold text-foreground">End-of-Day (Z-Report) — {{ $eodSettlement->business_date->format('d M Y') }}</h3>
                        </div>
                        <p class="text-xs text-muted-foreground mt-1">
                            Cash register locked on {{ $eodSettlement->closed_at?->format('d M Y, H:i') }} by <strong>{{ $eodSettlement->closedByUser?->name ?? 'Manager' }}</strong>
                        </p>
                    </div>
                    @if ($eodSettlement->notes)
                        <div class="text-xs text-muted-foreground italic bg-muted p-2.5 rounded-xl border border-border max-w-xs">
                            "{{ $eodSettlement->notes }}"
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-muted p-4 rounded-xl border border-border">
                        <span class="text-xs text-muted-foreground font-semibold uppercase block mb-1">Expected Cash Total</span>
                        <span class="text-lg font-bold tabular-nums text-foreground">Rp {{ number_format((float) $eodSettlement->expected_cash_total, 0, ',', '.') }}</span>
                    </div>
                    <div class="bg-muted p-4 rounded-xl border border-border">
                        <span class="text-xs text-muted-foreground font-semibold uppercase block mb-1">Counted Cash Total</span>
                        <span class="text-lg font-bold tabular-nums text-foreground">Rp {{ number_format((float) $eodSettlement->counted_cash_total, 0, ',', '.') }}</span>
                    </div>
                    <div class="bg-muted p-4 rounded-xl border border-border">
                        <span class="text-xs text-muted-foreground font-semibold uppercase block mb-1">Cash Discrepancy Total</span>
                        <span class="text-lg font-bold tabular-nums {{ (float)$eodSettlement->cash_discrepancy_total == 0 ? 'text-success' : 'text-destructive' }}">
                            Rp {{ number_format((float) $eodSettlement->cash_discrepancy_total, 0, ',', '.') }}
                        </span>
                    </div>
                </div>
            </div>
        @else
            <div class="card-surface p-6 rounded-2xl border-l-4 border-l-warning border border-border flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-xs">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium font-sans">
                            <span class="w-1.5 h-1.5 rounded-full bg-warning"></span>
                            OPEN REGISTER (ACTIVE)
                        </span>
                        <h3 class="text-base font-bold text-foreground">End-of-Day Register Closing (EOD / Z-Report)</h3>
                    </div>
                    <p class="text-xs text-muted-foreground mt-1">
                        Perform daily book closing for business date <strong>{{ $businessDate }}</strong> after all cashier shifts are finalized.
                    </p>
                </div>
                <x-ui.button variant="primary" type="button" @click="openEodModal = true" class="gap-2 shrink-0">
                    <span class="material-symbols-rounded text-base">lock</span>
                    Close EOD / Z-Report
                </x-ui.button>
            </div>
        @endif

        {{-- Detailed Financial Summary Cards --}}
        <div>
            <h3 class="text-xs font-bold text-muted-foreground uppercase tracking-wider mb-3">Detailed Financial Summary (Sales Parity Audit)</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="card-surface p-5 rounded-2xl border border-border">
                    <span class="text-xs font-semibold text-muted-foreground uppercase block mb-1">Gross Sales</span>
                    <h4 class="text-xl font-extrabold tabular-nums text-foreground">Rp {{ number_format((float) $detailedSummary['gross_sales'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-muted-foreground mt-1 block">{{ $detailedSummary['paid_orders_count'] }} Settled Orders</span>
                </div>

                <div class="card-surface p-5 rounded-2xl border border-border">
                    <span class="text-xs font-semibold text-destructive uppercase block mb-1">Total Discounts</span>
                    <h4 class="text-xl font-extrabold tabular-nums text-destructive">- Rp {{ number_format((float) $detailedSummary['total_discounts'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-muted-foreground mt-1 block">Item + Voucher</span>
                </div>

                <div class="card-surface p-5 rounded-2xl border border-border">
                    <span class="text-xs font-semibold text-warning uppercase block mb-1">Total Returns &amp; Refunds</span>
                    <h4 class="text-xl font-extrabold tabular-nums text-warning">- Rp {{ number_format((float) $detailedSummary['total_returns_refunds'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-muted-foreground mt-1 block">Return Adjustments</span>
                </div>

                <div class="card-surface p-5 rounded-2xl bg-success-muted border border-success-border">
                    <span class="text-xs font-bold text-success uppercase block mb-1">Net Sales (Operating Revenue)</span>
                    <h4 class="text-2xl font-black tabular-nums text-success">Rp {{ number_format((float) $detailedSummary['net_sales'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-success mt-1 block">Gross - Discounts - Returns</span>
                </div>

                <div class="card-surface p-5 rounded-2xl border border-border">
                    <span class="text-xs font-semibold text-muted-foreground uppercase block mb-1">Total Tax (PB1 / VAT)</span>
                    <h4 class="text-xl font-extrabold tabular-nums text-foreground">Rp {{ number_format((float) $detailedSummary['total_tax'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-muted-foreground mt-1 block">Tax Liabilities</span>
                </div>

                <div class="card-surface p-5 rounded-2xl border border-border">
                    <span class="text-xs font-semibold text-muted-foreground uppercase block mb-1">Total Service Charge</span>
                    <h4 class="text-xl font-extrabold tabular-nums text-foreground">Rp {{ number_format((float) $detailedSummary['total_service_charge'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-muted-foreground mt-1 block">Hospitality Service Charge</span>
                </div>

                <div class="card-surface p-5 rounded-2xl border border-border">
                    <span class="text-xs font-semibold text-destructive uppercase block mb-1">Total Voids</span>
                    <h4 class="text-xl font-extrabold tabular-nums text-destructive">Rp {{ number_format((float) $detailedSummary['total_voids'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-muted-foreground mt-1 block">{{ $detailedSummary['voided_orders_count'] }} Voided Orders</span>
                </div>

                <div class="card-surface p-5 rounded-2xl border border-border">
                    <span class="text-xs font-semibold text-muted-foreground uppercase block mb-1">Transaction Volume</span>
                    <h4 class="text-xl font-extrabold tabular-nums text-foreground">{{ $detailedSummary['paid_orders_count'] }} Transactions</h4>
                    <span class="text-[11px] text-muted-foreground mt-1 block">Within selected range</span>
                </div>
            </div>
        </div>

        {{-- Payment Method Breakdown --}}
        @if ($paymentBreakdown && $paymentBreakdown->isNotEmpty())
            <div>
                <h3 class="text-xs font-bold text-muted-foreground uppercase tracking-wider mb-3">Payment Method Breakdown</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @foreach ($paymentBreakdown as $pay)
                        <div class="card-surface p-4 rounded-2xl border border-border flex justify-between items-center shadow-xs">
                            <div>
                                <span class="text-xs text-muted-foreground uppercase font-bold block">{{ is_string($pay->payment_method) ? $pay->payment_method : ($pay->payment_method->label() ?? $pay->payment_method->value) }}</span>
                                <span class="text-xs text-muted-foreground">{{ $pay->count }} Transactions</span>
                            </div>
                            <span class="text-base font-bold tabular-nums text-foreground">Rp {{ number_format((float) $pay->total, 0, ',', '.') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Transaction Table -->
        <div class="card-surface overflow-hidden rounded-2xl border border-border shadow-xs">
            <div class="px-6 py-4 border-b border-border bg-surface-table-header flex items-center justify-between">
                <h2 class="text-base font-bold text-foreground">Recent Transactions</h2>
                <span class="text-xs text-muted-foreground">Displaying latest sales activity</span>
            </div>
            <div class="overflow-x-auto">
                <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-border bg-surface-table-header text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                            <th class="px-6 py-3.5">Order Code</th>
                            <th class="px-6 py-3.5">Date & Time</th>
                            <th class="px-6 py-3.5">Cashier</th>
                            <th class="px-6 py-3.5 text-right">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($recentOrders as $order)
                        <tr class="hover:bg-accent transition">
                            <td class="px-6 py-4">
                                <span class="text-xs font-semibold text-foreground tabular-nums">{{ $order->order_code }}</span>
                            </td>
                            <td class="px-6 py-4 text-xs text-muted-foreground">{{ \Carbon\Carbon::parse($order->created_at)->format('d-m-Y H:i') }}</td>
                            <td class="px-6 py-4 text-sm text-foreground">{{ $order->user->name ?? 'Cashier' }}</td>
                            <td class="px-6 py-4 text-right">
                                <span class="text-sm font-bold tabular-nums text-foreground">Rp {{ number_format((float) $order->order_amount, 0, ',', '.') }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-muted-foreground">
                                <span class="material-symbols-rounded text-4xl block mb-2">receipt_long</span>
                                No transactions recorded for this period.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-border">
                {{ $recentOrders->links() }}
            </div>
        </div>

        <!-- Modal Close EOD -->
        <x-ui.modal x-model="openEodModal" maxWidth="md">
            <x-slot:title>
                Close Register End-of-Day (Z-Report)
            </x-slot:title>

            <x-slot:content>
                <div class="space-y-4">
                    <p class="text-xs text-muted-foreground -mt-2">This action locks all transactions for the selected business date and generates the consolidated cashier settlement report.</p>

                    <form id="eodForm" action="{{ route('reports.eod-close') }}" method="POST" class="space-y-4">
                        @csrf
                        <div>
                            <x-ui.label for="business_date_input">Business Date *</x-ui.label>
                            <x-ui.input type="date" id="business_date_input" name="business_date" value="{{ $businessDate }}" required />
                        </div>

                        <div>
                            <x-ui.label for="eod_pin_input">Manager / Supervisor PIN *</x-ui.label>
                            <x-ui.input type="password" id="eod_pin_input" name="pin" required maxlength="6" placeholder="4-6 digit PIN" class="tabular-nums tracking-widest" />
                        </div>

                        <div>
                            <x-ui.label for="eod_notes_input">Closing Notes (Optional)</x-ui.label>
                            <textarea id="eod_notes_input" name="notes" rows="2" placeholder="Operational remarks or cash discrepancy explanations..."
                                class="w-full rounded-xl border border-input bg-background p-2.5 text-xs focus:outline-hidden focus:ring-2 focus:ring-ring text-foreground placeholder:text-muted-foreground"></textarea>
                        </div>

                        <div class="p-3 bg-warning-muted border border-warning-border rounded-xl text-[11px] text-warning leading-relaxed">
                            <strong>Notice:</strong> Ensure all cashier shifts for this business date are marked as Closed and no transactions remain in pending/timeout status.
                        </div>
                    </form>
                </div>
            </x-slot:content>

            <x-slot:footer>
                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" type="button" @click="openEodModal = false">
                        Cancel
                    </x-ui.button>
                    <x-ui.button variant="primary" type="button" @click="document.getElementById('eodForm').submit()">
                        Lock &amp; Finalize EOD
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
