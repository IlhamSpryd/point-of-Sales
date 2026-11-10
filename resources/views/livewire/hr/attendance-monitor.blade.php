<div class="space-y-6" data-gsap="page-enter">
    <!-- Sub Navigasi Modular -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action Halaman -->
    <x-erp.page-header
        title="Staff Attendance & Overtime Monitoring"
        description="Monitor employee clock-in/out times, punctuality, tardiness, and manual attendance adjustments.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('hr.employees.index') }}">HR & Payroll</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Attendance & Overtime</x-ui.breadcrumbs.item>
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
            title="Total Attendance"
            :value="$totalAttendance"
            icon="co_present"
            trend-label="Recorded on selected date"
        />
        <x-erp.stat-card
            title="Present Staff"
            :value="$presentCount"
            icon="verified"
            trend-label="On duty / present"
            variant="success"
        />
        <x-erp.stat-card
            title="Late Arrivals"
            :value="$lateCount"
            icon="schedule"
            trend-label="Tardiness detected"
            delta-variant="warning"
        />
        <x-erp.stat-card
            title="Time Corrections"
            :value="$correctedCount"
            icon="edit_note"
            trend-label="Manually adjusted records"
        />
    </div>

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-border">
        <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Branch:</span>
                <x-ui.select wire:model.live="selectedStoreId" class="w-auto text-xs sm:text-sm">
                    <option value="">All Branches</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}">{{ $store->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Date:</span>
                <input
                    type="date"
                    wire:model.live="dateFilter"
                    class="px-3 py-1.5 text-xs sm:text-sm rounded-xl border border-input bg-background text-foreground tabular-nums focus:outline-hidden focus:ring-2 focus:ring-primary/20 transition"
                />
            </div>

            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Status:</span>
                <x-ui.select wire:model.live="statusFilter" class="w-auto text-xs sm:text-sm">
                    <option value="ALL">All Statuses</option>
                    @foreach (\App\Modules\HR\Enums\AttendanceStatusEnum::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>
        </div>
    </div>

    <!-- Attendance Records Table -->
    <div class="card-surface rounded-2xl border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground divide-y divide-border">
                <thead class="bg-muted/50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5">Employee</th>
                        <th class="px-6 py-3.5">Clock-in Time</th>
                        <th class="px-6 py-3.5">Clock-out Time</th>
                        <th class="px-6 py-3.5">Attendance Status</th>
                        <th class="px-6 py-3.5">Notes / Corrections</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($records as $rec)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-6 py-4 font-semibold text-foreground flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-secondary text-secondary-foreground font-bold flex items-center justify-center text-xs shrink-0">
                                    {{ strtoupper(substr($rec->employee?->full_name ?? 'EM', 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="font-semibold text-foreground truncate">{{ $rec->employee?->full_name ?? 'Staff' }}</div>
                                    <div class="text-xs text-muted-foreground tabular-nums">{{ $rec->employee?->employee_number }}</div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-xs text-foreground tabular-nums">
                                {{ $rec->clock_in_at ? $rec->clock_in_at->format('d M Y, H:i') : '-' }}
                            </td>
                            <td class="px-6 py-4 text-xs text-foreground tabular-nums">
                                {{ $rec->clock_out_at ? $rec->clock_out_at->format('d M Y, H:i') : '-' }}
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $attDot = match($rec->status) {
                                        \App\Modules\HR\Enums\AttendanceStatusEnum::PRESENT => 'bg-success',
                                        \App\Modules\HR\Enums\AttendanceStatusEnum::LATE, \App\Modules\HR\Enums\AttendanceStatusEnum::EARLY_LEAVE => 'bg-warning',
                                        \App\Modules\HR\Enums\AttendanceStatusEnum::CORRECTED => 'bg-info',
                                        \App\Modules\HR\Enums\AttendanceStatusEnum::ABSENT, \App\Modules\HR\Enums\AttendanceStatusEnum::SICK => 'bg-destructive',
                                        default => 'bg-neutral',
                                    };
                                @endphp
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $attDot }}"></span>
                                    {{ $rec->status ? $rec->status->label() : 'Pending' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs text-muted-foreground max-w-xs truncate">
                                {{ $rec->notes ?: '-' }}
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <x-ui.table-actions>
                                    <x-ui.table-action-item icon="edit" wire:click="openCorrectionModal({{ $rec->id }})" aria-label="Attendance Time Correction">
                                        Correction
                                    </x-ui.table-action-item>
                                </x-ui.table-actions>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-muted-foreground">
                                <span class="material-symbols-rounded text-4xl block mb-2 text-muted-foreground/50">event_busy</span>
                                No attendance records found for the selected date and branch.
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

    <!-- Attendance Time Correction Modal -->
    <x-ui.modal wire:model="isCorrectionModalOpen" title="Attendance Time Correction" description="Correct employee clock-in/out timestamps with administrative audit notes." maxWidth="md">
        <div class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">Clock-in Time</label>
                <input
                    type="datetime-local"
                    wire:model="correctedClockIn"
                    class="w-full px-3.5 py-2 text-sm bg-background border border-input rounded-xl tabular-nums text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary/20"
                />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">Clock-out Time</label>
                <input
                    type="datetime-local"
                    wire:model="correctedClockOut"
                    class="w-full px-3.5 py-2 text-sm bg-background border border-input rounded-xl tabular-nums text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary/20"
                />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">Correction Reason (Required)</label>
                <textarea
                    wire:model="correctionReason"
                    rows="3"
                    placeholder="e.g. Biometric system outage / Forgot to clock out / Network issue"
                    class="w-full px-3.5 py-2 text-sm bg-background border border-input rounded-xl text-foreground placeholder:text-muted-foreground focus:outline-hidden focus:ring-2 focus:ring-primary/20"
                ></textarea>
            </div>
        </div>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closeCorrectionModal">
                Cancel
            </x-ui.button>
            <x-ui.button variant="primary" wire:click="saveCorrection">
                Save Correction
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
