<div class="space-y-6" data-gsap="page-enter">
    <!-- Sub Navigasi Modular -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action Halaman -->
    <x-erp.page-header
        title="Sales Commission Summary"
        description="Track sales staff and cashier commissions earned in real time, including automated reversals for returned items.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('hr.employees.index') }}">HR & Payroll</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Sales Commissions</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>
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
            title="Earned Commission"
            :value="'Rp ' . number_format($totalEarned, 0, ',', '.')"
            icon="payments"
            trend-label="Gross calculated sales incentives"
            variant="success"
        />
        <x-erp.stat-card
            title="Reversals / Returns"
            :value="'Rp ' . number_format($totalReversed, 0, ',', '.')"
            icon="history"
            trend-label="Deductions from returned orders"
            delta-variant="danger"
        />
        <x-erp.stat-card
            title="Net Commission"
            :value="'Rp ' . number_format($netCommission, 0, ',', '.')"
            icon="account_balance_wallet"
            trend-label="Payable net sales commissions"
            variant="success"
        />
        <x-erp.stat-card
            title="Total Records"
            :value="$totalRecordsCount"
            icon="receipt_long"
            trend-label="Lifetime commission transactions"
        />
    </div>

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl border border-border shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Employee:</span>
                <x-ui.select wire:model.live="employeeFilter" class="w-auto text-xs sm:text-sm">
                    <option value="">All Employees</option>
                    @foreach ($employees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->full_name }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Status:</span>
                <x-ui.select wire:model.live="statusFilter" class="w-auto text-xs sm:text-sm">
                    <option value="ALL">All Statuses</option>
                    @foreach (\App\Modules\HR\Enums\CommissionRecordStatusEnum::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>
        </div>
    </div>

    <!-- Commission Records Table -->
    <div class="card-surface rounded-2xl border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground divide-y divide-border">
                <thead class="bg-muted/50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5">Employee</th>
                        <th class="px-6 py-3.5">Order Reference</th>
                        <th class="px-6 py-3.5">Calculation Basis</th>
                        <th class="px-6 py-3.5">Commission Amount</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($records as $record)
                        @php
                            $isReversal = $record->type === \App\Modules\HR\Enums\CommissionRecordTypeEnum::REVERSAL
                                || $record->status === \App\Modules\HR\Enums\CommissionRecordStatusEnum::REVERSED
                                || (float) $record->commission_amount < 0;
                            $amountAbs = abs((float) $record->commission_amount);
                            $basisAmount = (float) ($record->calculation_basis_amount ?? $record->basis_amount ?? 0);
                        @endphp
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-6 py-4 font-semibold text-foreground flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-secondary text-secondary-foreground font-bold flex items-center justify-center text-xs shrink-0">
                                    {{ strtoupper(substr($record->employee?->full_name ?? 'EM', 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="font-semibold text-foreground truncate">{{ $record->employee?->full_name ?? 'Staff' }}</div>
                                    <div class="text-xs text-muted-foreground tabular-nums">{{ $record->employee?->employee_number }}</div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-xs text-muted-foreground tabular-nums">
                                #{{ $record->order_id }}
                            </td>
                            <td class="px-6 py-4 text-xs tabular-nums text-foreground">
                                Rp {{ number_format($basisAmount, 2, ',', '.') }}
                            </td>
                            <td class="px-6 py-4 font-bold tabular-nums {{ $isReversal ? 'text-rose-600' : 'text-emerald-600' }}">
                                {{ $isReversal ? '-' : '+' }} Rp {{ number_format($amountAbs, 2, ',', '.') }}
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $commDot = match($record->status) {
                                        \App\Modules\HR\Enums\CommissionRecordStatusEnum::APPROVED, \App\Modules\HR\Enums\CommissionRecordStatusEnum::EARNED, \App\Modules\HR\Enums\CommissionRecordStatusEnum::PAID => 'bg-success',
                                        \App\Modules\HR\Enums\CommissionRecordStatusEnum::PENDING => 'bg-warning',
                                        \App\Modules\HR\Enums\CommissionRecordStatusEnum::PROCESSED_IN_PAYROLL => 'bg-info',
                                        \App\Modules\HR\Enums\CommissionRecordStatusEnum::REVERSED => 'bg-destructive',
                                        default => 'bg-neutral',
                                    };
                                @endphp
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $commDot }}"></span>
                                    {{ $record->status->label() }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs text-muted-foreground tabular-nums">
                                {{ $record->created_at ? $record->created_at->format('d M Y, H:i') : '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-muted-foreground">
                                <span class="material-symbols-rounded text-4xl block mb-2 text-muted-foreground/50">paid</span>
                                No commission transaction records found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-border">
            {{ $records->links() }}
        </div>
    </div>
</div>
