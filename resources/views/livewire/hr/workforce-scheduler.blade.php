<div class="space-y-6" data-gsap="page-enter">
    <!-- Sub Navigasi Modular -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action Halaman -->
    <x-erp.page-header
        title="Workforce Schedules & Shift Roster"
        description="Weekly workforce scheduling, shift rosters, and outlet staff assignments.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('hr.employees.index') }}">HR & Payroll</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Schedules & Shifts</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button variant="secondary" size="sm" wire:click="previousWeek" class="h-9 px-2.5">
                    <span class="material-symbols-rounded text-base">chevron_left</span>
                </x-ui.button>
                <div class="text-xs sm:text-sm font-semibold text-foreground px-3 py-1.5 bg-muted rounded-xl border border-border tabular-nums">
                    Week: {{ \Carbon\Carbon::parse($weekStartDate)->format('d M Y') }}
                </div>
                <x-ui.button variant="secondary" size="sm" wire:click="nextWeek" class="h-9 px-2.5">
                    <span class="material-symbols-rounded text-base">chevron_right</span>
                </x-ui.button>
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
            title="Scheduled Staff"
            :value="$totalScheduledEmployees"
            icon="groups"
            trend-label="Active workforce roster"
        />
        <x-erp.stat-card
            title="Assigned Shifts"
            :value="$totalAssignedShifts"
            icon="calendar_month"
            trend-label="Total weekly allocations"
            variant="success"
        />
        <x-erp.stat-card
            title="Shift Templates"
            :value="$totalShiftTemplates"
            icon="schedule"
            trend-label="Available operational shifts"
        />
        <x-erp.stat-card
            title="Active Outlets"
            :value="$totalStores"
            icon="storefront"
            trend-label="Branches in schedule"
        />
    </div>

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 border border-border">
        <div class="flex items-center gap-3">
            <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Branch:</span>
            <x-ui.select wire:model.live="selectedStoreId" class="w-auto text-xs sm:text-sm">
                @foreach ($stores as $store)
                    <option value="{{ $store->id }}">{{ $store->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="text-xs text-muted-foreground flex items-center gap-1.5">
            <span class="material-symbols-rounded text-base text-primary">touch_app</span>
            <span>Click any cell slot to assign or reassign an employee shift</span>
        </div>
    </div>

    <!-- Kalender Roster Mingguan -->
    <div class="card-surface rounded-2xl border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground divide-y divide-border">
                <thead class="bg-muted/50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                    <tr>
                        <th class="px-5 py-3.5 w-64">Employee Name</th>
                        @foreach ($days as $day)
                            <th class="px-3 py-3.5 text-center {{ $day->isToday() ? 'bg-primary/10 text-primary font-bold' : '' }}">
                                <div>{{ $day->format('D') }}</div>
                                <div class="text-xs font-normal tabular-nums">{{ $day->format('d/m') }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($employees as $emp)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-5 py-3.5 font-semibold text-foreground flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-full bg-secondary text-secondary-foreground font-bold flex items-center justify-center text-xs shrink-0">
                                    {{ strtoupper(substr($emp->full_name, 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="truncate max-w-[180px] font-medium text-foreground">{{ $emp->full_name }}</div>
                                    <div class="text-[11px] text-muted-foreground truncate">{{ $emp->jobTitle?->title ?? 'Staff' }}</div>
                                </div>
                            </td>
                            @foreach ($days as $day)
                                @php
                                    $dateStr = $day->toDateString();
                                    $sched = $schedules->first(fn($s) => $s->employee_id === $emp->id && $s->schedule_date->toDateString() === $dateStr);
                                @endphp
                                <td class="px-2 py-2 text-center align-middle {{ $day->isToday() ? 'bg-primary/5' : '' }}">
                                    @if ($sched)
                                        <button wire:click="openAssignModal({{ $emp->id }}, '{{ $dateStr }}')"
                                            class="w-full px-2.5 py-1.5 bg-primary/10 hover:bg-primary/20 border border-primary/20 rounded-xl text-xs font-medium text-primary transition-colors cursor-pointer text-left">
                                            <div class="font-semibold truncate">{{ $sched->shiftTemplate?->name ?? 'Shift' }}</div>
                                            <div class="text-[10px] text-muted-foreground tabular-nums">
                                                {{ substr($sched->shiftTemplate?->start_time ?? '', 0, 5) }} - {{ substr($sched->shiftTemplate?->end_time ?? '', 0, 5) }}
                                            </div>
                                        </button>
                                    @else
                                        <button wire:click="openAssignModal({{ $emp->id }}, '{{ $dateStr }}')"
                                            class="w-full py-2.5 rounded-xl border border-dashed border-border hover:border-primary/50 hover:bg-muted/50 text-muted-foreground/60 hover:text-primary transition-colors text-xs cursor-pointer flex items-center justify-center">
                                            <span class="material-symbols-rounded text-base">add</span>
                                        </button>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-muted-foreground">
                                <span class="material-symbols-rounded text-4xl block mb-2 text-muted-foreground/50">calendar_month</span>
                                No active employees found in directory to schedule.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Penetapan Shift -->
    <x-ui.modal wire:model="isAssignModalOpen" title="Assign Work Shift" description="Select shift template for employee schedule assignment." maxWidth="md">
        <div class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">Schedule Date</label>
                <input type="text" value="{{ $assignDate }}" readonly class="w-full px-3.5 py-2 text-sm bg-muted border border-input rounded-xl tabular-nums text-muted-foreground cursor-not-allowed" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">Select Shift Pattern</label>
                <x-ui.select wire:model="assignShiftTemplateId" class="w-full text-sm">
                    <option value="">Select Shift...</option>
                    @foreach ($shiftTemplates as $tmpl)
                        <option value="{{ $tmpl->id }}">{{ $tmpl->name }} ({{ substr($tmpl->start_time, 0, 5) }} - {{ substr($tmpl->end_time, 0, 5) }})</option>
                    @endforeach
                </x-ui.select>
            </div>
        </div>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closeAssignModal">
                Cancel
            </x-ui.button>
            <x-ui.button variant="primary" wire:click="saveScheduleAssignment">
                Save Shift
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
