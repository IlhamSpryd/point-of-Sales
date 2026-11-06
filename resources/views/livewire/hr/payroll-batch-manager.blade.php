<div class="space-y-6" data-gsap="page-enter">
    <!-- Sub Navigasi Modular -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action Halaman -->
    <x-erp.page-header
        title="Employee Payroll (Payroll Engine)"
        description="Deterministic payroll calculation pipeline, statutory tax (PPh 21 TER PMK 168/2023), BPJS contributions, and GL journal posting.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('hr.employees.index') }}">HR & Payroll</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Payroll</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            @can('create', \App\Modules\HR\Models\PayrollBatch::class)
            <x-ui.button variant="primary" wire:click="openCreateModal" class="gap-2">
                <span class="material-symbols-rounded text-base">add_circle</span>
                <span>Initiate Payroll Batch</span>
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Feedback Alerts -->
    @if (session()->has('success'))
        <x-ui.alert type="success" title="Success" class="rounded-2xl">
            {{ session('success') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert type="danger" title="Error" class="rounded-2xl">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- KPI & Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-erp.stat-card
            title="Total Batches"
            :value="$totalBatchesCount"
            icon="receipt_long"
            trend-label="Lifetime payroll runs"
        />
        <x-erp.stat-card
            title="Finalized Runs"
            :value="$finalizedBatchesCount"
            icon="verified"
            trend-label="Approved and locked"
            variant="success"
        />
        <x-erp.stat-card
            title="Gross Earnings"
            :value="'Rp ' . number_format($totalGrossDisbursed, 0, ',', '.')"
            icon="payments"
            trend-label="Cumulative gross salary"
        />
        <x-erp.stat-card
            title="Net Disbursed"
            :value="'Rp ' . number_format($totalNetDisbursed, 0, ',', '.')"
            icon="account_balance_wallet"
            trend-label="Take-home pay disbursed"
            variant="success"
        />
    </div>

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl border border-border shadow-xs flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Batch Status:</span>
            <x-ui.select wire:model.live="statusFilter" class="w-auto text-xs sm:text-sm">
                <option value="ALL">All Statuses</option>
                @foreach (\App\Modules\HR\Enums\PayrollBatchStatusEnum::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </x-ui.select>
        </div>
    </div>

    <!-- Tabel Batch Penggajian -->
    <div class="card-surface rounded-2xl border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground divide-y divide-border">
                <thead class="bg-muted/50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5">Batch Number</th>
                        <th class="px-6 py-3.5">Payroll Period</th>
                        <th class="px-6 py-3.5">Total Staff</th>
                        <th class="px-6 py-3.5">Total Gross Earnings</th>
                        <th class="px-6 py-3.5">Total Net Pay</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($batches as $batch)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-6 py-4 font-semibold text-foreground text-xs tabular-nums">
                                {{ $batch->batch_number }}
                            </td>
                            <td class="px-6 py-4 text-xs text-muted-foreground tabular-nums">
                                {{ $batch->period_start_date->format('d M') }} - {{ $batch->period_end_date->format('d M Y') }}
                            </td>
                            <td class="px-6 py-4 font-semibold text-foreground tabular-nums">
                                {{ $batch->total_employees }} Staff
                            </td>
                            <td class="px-6 py-4 text-xs tabular-nums text-foreground">
                                Rp {{ number_format((float) $batch->total_gross_amount, 2, ',', '.') }}
                            </td>
                            <td class="px-6 py-4 font-bold text-xs text-primary tabular-nums">
                                Rp {{ number_format((float) $batch->total_net_amount, 2, ',', '.') }}
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border {{ $batch->status->badgeClasses() }}">
                                    {{ $batch->status->label() }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <x-ui.button
                                        variant="secondary"
                                        size="sm"
                                        wire:click="viewDetails({{ $batch->id }})"
                                        class="h-8 px-2.5 text-xs gap-1">
                                        <span class="material-symbols-rounded text-sm">visibility</span>
                                        <span>Details</span>
                                    </x-ui.button>

                                    @if ($batch->status === \App\Modules\HR\Enums\PayrollBatchStatusEnum::DRAFT || $batch->status === \App\Modules\HR\Enums\PayrollBatchStatusEnum::CALCULATED)
                                        <x-ui.button
                                            variant="secondary"
                                            size="sm"
                                            wire:click="calculate({{ $batch->id }})"
                                            class="h-8 px-2.5 text-xs gap-1 bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-100">
                                            <span class="material-symbols-rounded text-sm">calculate</span>
                                            <span>Calculate</span>
                                        </x-ui.button>
                                    @endif

                                    @if ($batch->status === \App\Modules\HR\Enums\PayrollBatchStatusEnum::CALCULATED)
                                        <x-ui.button
                                            variant="primary"
                                            size="sm"
                                            wire:click="finalize({{ $batch->id }})"
                                            class="h-8 px-2.5 text-xs gap-1">
                                            <span class="material-symbols-rounded text-sm">lock</span>
                                            <span>Finalize</span>
                                        </x-ui.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-muted-foreground">
                                <span class="material-symbols-rounded text-4xl block mb-2 text-muted-foreground/50">payments</span>
                                No payroll batches initiated yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-border">
            {{ $batches->links() }}
        </div>
    </div>

    <!-- Modal Inisiasi Batch -->
    <x-ui.modal wire:model="isCreateModalOpen" title="Initiate New Payroll Batch" description="Select payroll period dates, attendance cutoff date, and scheduled disbursement date." maxWidth="md">
        <div class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">Period Start Date</label>
                <input
                    type="date"
                    wire:model="periodStartDate"
                    class="w-full px-3.5 py-2 text-sm bg-background border border-input rounded-xl tabular-nums text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary/20"
                />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">Period End Date</label>
                <input
                    type="date"
                    wire:model="periodEndDate"
                    class="w-full px-3.5 py-2 text-sm bg-background border border-input rounded-xl tabular-nums text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary/20"
                />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">Attendance Cut-off Date</label>
                <input
                    type="date"
                    wire:model="cutoffDate"
                    class="w-full px-3.5 py-2 text-sm bg-background border border-input rounded-xl tabular-nums text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary/20"
                />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">Scheduled Payment Date</label>
                <input
                    type="date"
                    wire:model="paymentDate"
                    class="w-full px-3.5 py-2 text-sm bg-background border border-input rounded-xl tabular-nums text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary/20"
                />
            </div>
        </div>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closeCreateModal">
                Cancel
            </x-ui.button>
            <x-ui.button variant="primary" wire:click="createBatch">
                Create Batch
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Batch Details & Payslip Modal -->
    <x-ui.modal wire:model="isDetailModalOpen" title="Employee Payslips in Batch" description="Breakdown of employee gross earnings, statutory PPh 21 TER PMK 168/2023, BPJS deductions, and take-home pay." maxWidth="4xl">
        <div class="max-h-[60vh] overflow-y-auto card-surface rounded-xl border border-border">
            <table class="w-full text-left text-sm text-foreground divide-y divide-border">
                <thead class="bg-muted/50 text-xs font-semibold text-muted-foreground uppercase">
                    <tr>
                        <th class="px-4 py-2.5">Employee</th>
                        <th class="px-4 py-2.5">Gross Earnings</th>
                        <th class="px-4 py-2.5">PPh 21 TER</th>
                        <th class="px-4 py-2.5">Employee BPJS</th>
                        <th class="px-4 py-2.5">Net Pay</th>
                        <th class="px-4 py-2.5 text-right">Access Token</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-xs tabular-nums">
                    @forelse ($detailRecords as $rec)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-foreground">{{ $rec->full_name_snapshot }}</div>
                                <div class="text-[11px] text-muted-foreground tabular-nums">{{ $rec->employee_number_snapshot }}</div>
                            </td>
                            <td class="px-4 py-3">
                                Rp {{ number_format((float) $rec->gross_earnings_amount, 2, ',', '.') }}
                            </td>
                            <td class="px-4 py-3 text-rose-600 font-medium">
                                - Rp {{ number_format((float) $rec->tax_pph21_amount, 2, ',', '.') }}
                            </td>
                            <td class="px-4 py-3 text-rose-600 font-medium">
                                - Rp {{ number_format((float) ($rec->bpjs_ks_employee_amount + $rec->bpjs_tk_employee_amount), 2, ',', '.') }}
                            </td>
                            <td class="px-4 py-3 font-bold text-emerald-600">
                                Rp {{ number_format((float) $rec->net_pay_amount, 2, ',', '.') }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <span class="text-[11px] text-primary bg-primary/10 px-2 py-0.5 rounded-lg border border-primary/20 tabular-nums">
                                    {{ substr($rec->payslip_token, 0, 8) }}...
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                No payslips calculated for this batch yet. Click Calculate to process.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closeDetailModal">
                Close
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
