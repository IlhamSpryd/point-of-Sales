<div class="space-y-6" data-gsap="page-enter">
    <!-- Sub Navigasi Modular -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action Halaman -->
    <x-erp.page-header
        title="Employee Leave & Absence Management"
        description="Manage annual leave, sick leave, time-off requests, and track employee leave balances.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('hr.employees.index') }}">HR & Payroll</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Leave Management</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            <div class="flex items-center gap-1 bg-muted p-1 rounded-xl border border-border">
                <button
                    type="button"
                    wire:click="$set('activeTab', 'REQUESTS')"
                    class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer {{ $activeTab === 'REQUESTS' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    Leave Requests
                </button>
                <button
                    type="button"
                    wire:click="$set('activeTab', 'BALANCES')"
                    class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer {{ $activeTab === 'BALANCES' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    Leave Balances
                </button>
            </div>
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
            title="Pending Approval"
            :value="$pendingCount"
            icon="pending_actions"
            trend-label="Awaiting supervisor review"
            delta-variant="warning"
        />
        <x-erp.stat-card
            title="Approved Requests"
            :value="$approvedCount"
            icon="task_alt"
            trend-label="Granted leave applications"
            variant="success"
        />
        <x-erp.stat-card
            title="Tracked Balances"
            :value="$totalBalanceCount"
            icon="account_balance_wallet"
            trend-label="Employee balance ledgers"
        />
        <x-erp.stat-card
            title="Total Days Taken"
            :value="$totalDaysApproved"
            icon="event_available"
            trend-label="Total cumulative leave days"
        />
    </div>

    @if ($activeTab === 'REQUESTS')
        <!-- Filter Bar -->
        <div class="card-surface p-4 rounded-2xl border border-border shadow-xs flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Request Status:</span>
                <x-ui.select wire:model.live="statusFilter" class="w-auto text-xs sm:text-sm">
                    <option value="ALL">All Statuses</option>
                    @foreach (\App\Modules\HR\Enums\LeaveRequestStatusEnum::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>
        </div>

        <!-- Leave Requests Table -->
        <div class="card-surface rounded-2xl border border-border shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-foreground divide-y divide-border">
                    <thead class="bg-muted/50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-3.5">Request & Employee</th>
                            <th class="px-6 py-3.5">Leave Type</th>
                            <th class="px-6 py-3.5">Date Range</th>
                            <th class="px-6 py-3.5">Duration</th>
                            <th class="px-6 py-3.5">Status</th>
                            <th class="px-6 py-3.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($requests as $req)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="font-bold text-foreground tabular-nums text-xs">#{{ $req->id }}</div>
                                    <div class="text-sm font-semibold text-foreground">{{ $req->employee?->full_name ?? 'Employee' }}</div>
                                    <div class="text-[11px] text-muted-foreground tabular-nums">{{ $req->employee?->employee_number }}</div>
                                </td>
                                <td class="px-6 py-4 font-medium text-foreground">
                                    {{ $req->policy?->policy_name ?? 'General Leave' }}
                                </td>
                                <td class="px-6 py-4 text-xs text-muted-foreground tabular-nums">
                                    {{ $req->start_date->format('d M Y') }} - {{ $req->end_date->format('d M Y') }}
                                </td>
                                <td class="px-6 py-4 font-semibold text-foreground tabular-nums">
                                    {{ (float) $req->total_days }} Days
                                </td>
                                <td class="px-6 py-4">
                                    @php
                                        $leaveDot = match($req->status) {
                                            \App\Modules\HR\Enums\LeaveRequestStatusEnum::APPROVED => 'bg-success',
                                            \App\Modules\HR\Enums\LeaveRequestStatusEnum::PENDING => 'bg-warning',
                                            \App\Modules\HR\Enums\LeaveRequestStatusEnum::REJECTED => 'bg-destructive',
                                            default => 'bg-neutral',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $leaveDot }}"></span>
                                        {{ $req->status->label() }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    @if ($req->status === \App\Modules\HR\Enums\LeaveRequestStatusEnum::PENDING)
                                        <x-ui.table-actions>
                                            <x-ui.table-action-item icon="check" wire:click="approve({{ $req->id }})" aria-label="Approve Leave Request">
                                                Approve Request
                                            </x-ui.table-action-item>
                                            <x-ui.table-action-item icon="close" variant="destructive" wire:click="reject({{ $req->id }})" aria-label="Reject Leave Request">
                                                Reject Request
                                            </x-ui.table-action-item>
                                        </x-ui.table-actions>
                                    @else
                                        <span class="text-xs text-muted-foreground">Completed</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-muted-foreground">
                                    <span class="material-symbols-rounded text-4xl block mb-2 text-muted-foreground/50">event_available</span>
                                    No leave requests in queue.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-border">
                {{ $requests->links() }}
            </div>
        </div>
    @else
        <!-- Staff Leave Balances Table -->
        <div class="card-surface rounded-2xl border border-border shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-foreground divide-y divide-border">
                    <thead class="bg-muted/50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-3.5">Employee</th>
                            <th class="px-6 py-3.5">Leave Policy</th>
                            <th class="px-6 py-3.5">Year</th>
                            <th class="px-6 py-3.5">Allocated Days</th>
                            <th class="px-6 py-3.5">Used Days</th>
                            <th class="px-6 py-3.5">Remaining Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($balances as $bal)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-6 py-4 font-semibold text-foreground">
                                    {{ $bal->employee?->full_name ?? 'Employee' }}
                                </td>
                                <td class="px-6 py-4 font-medium text-foreground">
                                    {{ $bal->policy?->policy_name ?? 'Leave' }}
                                </td>
                                <td class="px-6 py-4 text-xs text-muted-foreground tabular-nums">
                                    {{ $bal->year }}
                                </td>
                                <td class="px-6 py-4 tabular-nums text-foreground">
                                    {{ (float) $bal->allocated_days }} Days
                                </td>
                                <td class="px-6 py-4 text-rose-600 font-semibold tabular-nums">
                                    {{ (float) $bal->used_days }} Days
                                </td>
                                <td class="px-6 py-4 text-emerald-600 font-bold tabular-nums">
                                    {{ (float) $bal->remaining_days }} Days
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-muted-foreground">
                                    <span class="material-symbols-rounded text-4xl block mb-2 text-muted-foreground/50">account_balance_wallet</span>
                                    No staff leave balances recorded yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
