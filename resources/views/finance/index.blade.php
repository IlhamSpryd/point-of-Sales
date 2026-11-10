<x-app-layout>
    <x-slot:header>
        <x-erp.page-header
            title="Finance & Accounting (General Ledger)"
            description="Dual-entry accounting hub, chart of accounts (COA), cash & bank flow monitoring, and multi-branch general ledger audit trail."
            :breadcrumbs="[['label' => 'Dashboard', 'href' => route('dashboard')], ['label' => 'Finance & Accounting']]"
        />
    </x-slot:header>

    <div class="space-y-6" data-gsap="page-enter">
        @include('finance.partials.sub-nav')

        <!-- KPI Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" data-gsap="kpi-card">
            <x-erp.stat-card
                label="General Ledger Status"
                :value="$activePeriod ? 'Period ' . $activePeriod->period_code : 'No Active Period'"
                :delta="$activePeriod ? 'Status: ' . $activePeriod->status->value : 'Setup Required'"
                :deltaVariant="$activePeriod ? 'positive' : 'warning'"
                icon="account_balance"
            />
            <x-erp.stat-card
                label="Draft / Pending Journals"
                :value="$unpostedJournalsCount . ' Transactions'"
                :delta="$postedJournalsCount . ' Posted'"
                :deltaVariant="$unpostedJournalsCount === 0 ? 'positive' : 'warning'"
                icon="rule"
            />
            <x-erp.stat-card
                label="Active Chart of Accounts (COA)"
                :value="$activeCoaCount . ' Active Accounts'"
                delta="GAAP / PSAK Compliant"
                deltaVariant="positive"
                icon="account_tree"
            />
            <x-erp.stat-card
                label="Outbox Integrity"
                :value="$pendingOutboxCount === 0 ? '100% Synchronized' : $pendingOutboxCount . ' Pending'"
                :delta="$pendingOutboxCount === 0 ? '0 Queued' : 'Dispatch Required'"
                :deltaVariant="$pendingOutboxCount === 0 ? 'positive' : 'warning'"
                icon="verified"
            />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Recent Journal Entries List (2 Cols) -->
            <div class="lg:col-span-2 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-foreground">
                        Recent Transaction Journals
                    </h3>
                    <span class="text-xs text-muted-foreground font-sans">{{ $postedJournalsCount }} Posted Journals</span>
                </div>

                <div class="card-surface rounded-2xl overflow-hidden border border-border shadow-xs">
                    @if($recentJournals->isEmpty())
                        <div class="p-8 text-center space-y-2">
                            <span class="material-symbols-rounded text-3xl text-muted-foreground/60">hourglass_empty</span>
                            <p class="text-sm font-medium text-foreground">No transaction journals recorded yet</p>
                            <p class="text-xs text-muted-foreground max-w-sm mx-auto">
                                Journals are automatically posted upon cashier checkout, goods receipt (GRN) approval, or customer invoice verification.
                            </p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table-standard w-full min-w-full text-left text-sm text-foreground">
                                <thead class="bg-muted/50 text-xs font-semibold uppercase text-muted-foreground border-b border-border">
                                    <tr>
                                        <th class="px-5 py-3">Journal No.</th>
                                        <th class="px-5 py-3">Posting Date</th>
                                        <th class="px-5 py-3">Source Module</th>
                                        <th class="px-5 py-3 text-right">Debit / Credit</th>
                                        <th class="px-5 py-3 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach($recentJournals as $journal)
                                        <tr class="hover:bg-muted/40 transition-colors">
                                            <td class="px-5 py-3 font-sans font-medium text-foreground">
                                                {{ $journal->journal_number }}
                                            </td>
                                            <td class="px-5 py-3 text-xs text-muted-foreground">
                                                {{ $journal->posting_date?->format('d M Y') ?? '-' }}
                                            </td>
                                            <td class="px-5 py-3 text-xs">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-muted text-muted-foreground font-sans text-[11px]">
                                                    {{ $journal->source_module ?? 'General' }}
                                                </span>
                                            </td>
                                            <td class="px-5 py-3 text-right font-sans text-xs tabular-nums text-foreground font-medium">
                                                Rp {{ number_format((float) $journal->total_debit, 2, ',', '.') }}
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <x-ui.badge :variant="$journal->status->value === 'POSTED' ? 'success' : 'neutral'">
                                                    {{ $journal->status->value }}
                                                </x-ui.badge>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Cash & Bank Accounts Sidebar (1 Col) -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-foreground">
                        Cash & Bank Accounts
                    </h3>
                    <span class="text-xs text-muted-foreground font-sans">{{ $cashBankAccounts->count() }} Accounts</span>
                </div>

                <div class="card-surface rounded-2xl p-5 border border-border space-y-3 shadow-xs">
                    @if($cashBankAccounts->isEmpty())
                        <div class="text-center py-6 space-y-2">
                            <span class="material-symbols-rounded text-2xl text-muted-foreground/60">account_balance</span>
                            <p class="text-xs text-muted-foreground">No bank or cash accounts configured.</p>
                        </div>
                    @else
                        @foreach($cashBankAccounts as $account)
                            <div class="p-3 rounded-xl bg-muted/30 border border-border flex items-center justify-between">
                                <div class="space-y-0.5">
                                    <div class="text-xs font-bold text-foreground">
                                        {{ $account->account_name }}
                                    </div>
                                    <div class="text-[11px] font-sans text-muted-foreground">
                                        {{ $account->bank_name ? $account->bank_name . ' • ' : '' }}{{ $account->bank_account_number ?? $account->account_code }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs font-bold font-sans text-foreground tabular-nums">
                                        Rp {{ number_format((float) $account->current_balance, 2, ',', '.') }}
                                    </div>
                                    <span class="text-[10px] text-success font-medium">Active</span>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
