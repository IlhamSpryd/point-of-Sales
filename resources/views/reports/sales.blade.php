<x-app-layout>
    <div x-data="{ openEodModal: false }" class="space-y-6" data-gsap="page-enter">
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
                        class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-xs text-zinc-800 dark:text-zinc-200 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                    <span class="text-xs text-zinc-400">to</span>
                    <input type="date" name="end" value="{{ $customEnd->toDateString() }}"
                        class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-xs text-zinc-800 dark:text-zinc-200 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
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
            <div class="card-surface p-6 rounded-2xl border-l-4 border-l-emerald-500 border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-zinc-200 dark:border-zinc-800 pb-4 mb-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <x-ui.badge variant="success" size="sm">
                                CLOSED (LOCKED)
                            </x-ui.badge>
                            <h3 class="text-base font-bold text-zinc-900 dark:text-white">End-of-Day (Z-Report) — {{ $eodSettlement->business_date->format('d M Y') }}</h3>
                        </div>
                        <p class="text-xs text-zinc-500 mt-1">
                            Cash register locked on {{ $eodSettlement->closed_at?->format('d M Y, H:i') }} by <strong>{{ $eodSettlement->closedByUser?->name ?? 'Manager' }}</strong>
                        </p>
                    </div>
                    @if ($eodSettlement->notes)
                        <div class="text-xs text-zinc-500 italic bg-zinc-50 dark:bg-zinc-800/50 p-2.5 rounded-xl border border-zinc-200 dark:border-zinc-700 max-w-xs">
                            "{{ $eodSettlement->notes }}"
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-zinc-50 dark:bg-zinc-800/40 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <span class="text-xs text-zinc-500 font-semibold uppercase block mb-1">Expected Cash Total</span>
                        <span class="text-lg font-bold font-mono text-zinc-900 dark:text-white">Rp {{ number_format((float) $eodSettlement->expected_cash_total, 0, ',', '.') }}</span>
                    </div>
                    <div class="bg-zinc-50 dark:bg-zinc-800/40 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <span class="text-xs text-zinc-500 font-semibold uppercase block mb-1">Counted Cash Total</span>
                        <span class="text-lg font-bold font-mono text-zinc-900 dark:text-white">Rp {{ number_format((float) $eodSettlement->counted_cash_total, 0, ',', '.') }}</span>
                    </div>
                    <div class="bg-zinc-50 dark:bg-zinc-800/40 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <span class="text-xs text-zinc-500 font-semibold uppercase block mb-1">Cash Discrepancy Total</span>
                        <span class="text-lg font-bold font-mono {{ (float)$eodSettlement->cash_discrepancy_total == 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                            Rp {{ number_format((float) $eodSettlement->cash_discrepancy_total, 0, ',', '.') }}
                        </span>
                    </div>
                </div>
            </div>
        @else
            <div class="card-surface p-6 rounded-2xl border-l-4 border-l-amber-500 border border-[#E5E7EB] dark:border-zinc-800 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-xs">
                <div>
                    <div class="flex items-center gap-2">
                        <x-ui.badge variant="warning" size="sm">
                            OPEN REGISTER (ACTIVE)
                        </x-ui.badge>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">End-of-Day Register Closing (EOD / Z-Report)</h3>
                    </div>
                    <p class="text-xs text-zinc-500 mt-1">
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
            <h3 class="text-xs font-bold text-zinc-500 uppercase tracking-wider mb-3">Detailed Financial Summary (Sales Parity Audit)</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="card-surface p-5 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800">
                    <span class="text-xs font-semibold text-zinc-500 uppercase block mb-1">Gross Sales</span>
                    <h4 class="text-xl font-extrabold font-mono text-zinc-900 dark:text-white">Rp {{ number_format((float) $detailedSummary['gross_sales'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-zinc-400 mt-1 block">{{ $detailedSummary['paid_orders_count'] }} Settled Orders</span>
                </div>

                <div class="card-surface p-5 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800">
                    <span class="text-xs font-semibold text-rose-600 uppercase block mb-1">Total Discounts</span>
                    <h4 class="text-xl font-extrabold font-mono text-rose-600">- Rp {{ number_format((float) $detailedSummary['total_discounts'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-zinc-400 mt-1 block">Item + Voucher</span>
                </div>

                <div class="card-surface p-5 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800">
                    <span class="text-xs font-semibold text-amber-600 uppercase block mb-1">Total Returns &amp; Refunds</span>
                    <h4 class="text-xl font-extrabold font-mono text-amber-600">- Rp {{ number_format((float) $detailedSummary['total_returns_refunds'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-zinc-400 mt-1 block">Return Adjustments</span>
                </div>

                <div class="card-surface p-5 rounded-2xl bg-emerald-50/40 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800">
                    <span class="text-xs font-bold text-emerald-800 dark:text-emerald-300 uppercase block mb-1">Net Sales (Operating Revenue)</span>
                    <h4 class="text-2xl font-black font-mono text-emerald-700 dark:text-emerald-400">Rp {{ number_format((float) $detailedSummary['net_sales'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-500 mt-1 block">Gross - Discounts - Returns</span>
                </div>

                <div class="card-surface p-5 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800">
                    <span class="text-xs font-semibold text-zinc-500 uppercase block mb-1">Total Tax (PB1 / VAT)</span>
                    <h4 class="text-xl font-extrabold font-mono text-zinc-900 dark:text-white">Rp {{ number_format((float) $detailedSummary['total_tax'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-zinc-400 mt-1 block">Tax Liabilities</span>
                </div>

                <div class="card-surface p-5 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800">
                    <span class="text-xs font-semibold text-zinc-500 uppercase block mb-1">Total Service Charge</span>
                    <h4 class="text-xl font-extrabold font-mono text-zinc-900 dark:text-white">Rp {{ number_format((float) $detailedSummary['total_service_charge'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-zinc-400 mt-1 block">Hospitality Service Charge</span>
                </div>

                <div class="card-surface p-5 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800">
                    <span class="text-xs font-semibold text-rose-500 uppercase block mb-1">Total Voids</span>
                    <h4 class="text-xl font-extrabold font-mono text-rose-500">Rp {{ number_format((float) $detailedSummary['total_voids'], 0, ',', '.') }}</h4>
                    <span class="text-[11px] text-zinc-400 mt-1 block">{{ $detailedSummary['voided_orders_count'] }} Voided Orders</span>
                </div>

                <div class="card-surface p-5 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800">
                    <span class="text-xs font-semibold text-zinc-500 uppercase block mb-1">Transaction Volume</span>
                    <h4 class="text-xl font-extrabold font-mono text-zinc-900 dark:text-white">{{ $detailedSummary['paid_orders_count'] }} Transactions</h4>
                    <span class="text-[11px] text-zinc-400 mt-1 block">Within selected range</span>
                </div>
            </div>
        </div>

        {{-- Payment Method Breakdown --}}
        @if ($paymentBreakdown && $paymentBreakdown->isNotEmpty())
            <div>
                <h3 class="text-xs font-bold text-zinc-500 uppercase tracking-wider mb-3">Payment Method Breakdown</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @foreach ($paymentBreakdown as $pay)
                        <div class="card-surface p-4 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 flex justify-between items-center shadow-xs">
                            <div>
                                <span class="text-xs text-zinc-600 dark:text-zinc-400 uppercase font-bold block">{{ is_string($pay->payment_method) ? $pay->payment_method : ($pay->payment_method->label() ?? $pay->payment_method->value) }}</span>
                                <span class="text-xs text-zinc-400">{{ $pay->count }} Transactions</span>
                            </div>
                            <span class="text-base font-bold font-mono text-zinc-900 dark:text-white">Rp {{ number_format((float) $pay->total, 0, ',', '.') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Transaction Table -->
        <div class="card-surface overflow-hidden rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
            <div class="px-6 py-4 border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 flex items-center justify-between">
                <h2 class="text-base font-bold text-zinc-900 dark:text-white">Recent Transactions</h2>
                <span class="text-xs text-zinc-400">Displaying latest sales activity</span>
            </div>
            <div class="overflow-x-auto">
                <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/30 dark:bg-zinc-900/30 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="px-6 py-3.5">Order Code</th>
                            <th class="px-6 py-3.5">Date & Time</th>
                            <th class="px-6 py-3.5">Cashier</th>
                            <th class="px-6 py-3.5 text-right">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                        @forelse ($recentOrders as $order)
                        <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                            <td class="px-6 py-4">
                                <span class="text-xs font-semibold text-zinc-900 dark:text-white font-mono">{{ $order->order_code }}</span>
                            </td>
                            <td class="px-6 py-4 text-xs text-zinc-500">{{ \Carbon\Carbon::parse($order->created_at)->format('d-m-Y H:i') }}</td>
                            <td class="px-6 py-4 text-sm text-zinc-700 dark:text-zinc-300">{{ $order->user->name ?? 'Cashier' }}</td>
                            <td class="px-6 py-4 text-right">
                                <span class="text-sm font-bold font-mono tabular-nums text-zinc-900 dark:text-white">Rp {{ number_format((float) $order->order_amount, 0, ',', '.') }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-zinc-400">
                                <span class="material-symbols-rounded text-4xl block mb-2">receipt_long</span>
                                No transactions recorded for this period.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800">
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
                    <p class="text-xs text-zinc-500 -mt-2">This action locks all transactions for the selected business date and generates the consolidated cashier settlement report.</p>

                    <form id="eodForm" action="{{ route('reports.eod-close') }}" method="POST" class="space-y-4">
                        @csrf
                        <div>
                            <x-ui.label for="business_date_input">Business Date *</x-ui.label>
                            <x-ui.input type="date" id="business_date_input" name="business_date" value="{{ $businessDate }}" required />
                        </div>

                        <div>
                            <x-ui.label for="eod_pin_input">Manager / Supervisor PIN *</x-ui.label>
                            <x-ui.input type="password" id="eod_pin_input" name="pin" required maxlength="6" placeholder="4-6 digit PIN" class="font-mono" />
                        </div>

                        <div>
                            <x-ui.label for="eod_notes_input">Closing Notes (Optional)</x-ui.label>
                            <textarea id="eod_notes_input" name="notes" rows="2" placeholder="Operational remarks or cash discrepancy explanations..."
                                class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2.5 text-xs focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-200"></textarea>
                        </div>

                        <div class="p-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-xl text-[11px] text-amber-800 dark:text-amber-300 leading-relaxed">
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
