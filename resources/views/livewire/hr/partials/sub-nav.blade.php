@php
    $allHrTabs = [
        [
            'label' => 'Employees',
            'route' => 'hr.employees.index',
            'active' => 'hr.employees.*',
            'icon' => 'badge',
        ],
        [
            'label' => 'Schedules & Shifts',
            'route' => 'hr.schedules.index',
            'active' => 'hr.schedules.*',
            'icon' => 'calendar_month',
        ],
        [
            'label' => 'Attendance & Overtime',
            'route' => 'hr.attendance.index',
            'active' => 'hr.attendance.*',
            'icon' => 'schedule',
        ],
        [
            'label' => 'Leave Management',
            'route' => 'hr.leaves.index',
            'active' => 'hr.leaves.*',
            'icon' => 'event_busy',
        ],
        [
            'label' => 'Sales Commissions',
            'route' => 'hr.commissions.index',
            'active' => 'hr.commissions.*',
            'icon' => 'payments',
        ],
        [
            'label' => 'Payroll',
            'route' => 'hr.payroll.index',
            'active' => 'hr.payroll.*',
            'icon' => 'account_balance_wallet',
        ],
    ];
@endphp

<div class="w-full pb-3 mb-6 border-b border-border">
    <nav aria-label="HR & Payroll Navigation"
        x-data
        x-init="$nextTick(() => {
            const activeTab = $el.querySelector('[aria-current=page]');
            if (activeTab) {
                const offset = activeTab.offsetLeft - ($el.clientWidth / 2) + (activeTab.clientWidth / 2);
                $el.scrollTo({ left: Math.max(0, offset), behavior: 'instant' });
            }
        })"
        class="flex items-center gap-1.5 md:gap-2 overflow-x-auto scrollbar-hide py-1">
        @foreach ($allHrTabs as $tab)
            @php
                $routeExists = \Illuminate\Support\Facades\Route::has($tab['route']);
                $isActive = $routeExists && request()->routeIs($tab['active']);
            @endphp
            @if ($routeExists)
                <a href="{{ route($tab['route']) }}" wire:navigate
                    @if ($isActive) aria-current="page" @endif
                    class="inline-flex items-center gap-2 px-3.5 py-2 min-h-[40px] rounded-xl text-xs font-semibold whitespace-nowrap transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring {{ $isActive ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground hover:bg-muted' }}">
                    <span class="material-symbols-rounded text-[18px] shrink-0 {{ $isActive ? 'text-primary-foreground' : 'text-muted-foreground' }}">{{ $tab['icon'] }}</span>
                    <span>{{ $tab['label'] }}</span>
                </a>
            @endif
        @endforeach
    </nav>
</div>
