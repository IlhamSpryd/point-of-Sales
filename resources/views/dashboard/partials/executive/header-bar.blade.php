{{-- ========================================================================= --}}
{{-- EXECUTIVE OVERVIEW HEADER BAR                                             --}}
{{-- ========================================================================= --}}
<section class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 pb-4 border-b border-border" aria-label="Executive Overview Header">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="text-xl sm:text-2xl font-semibold tracking-tight text-foreground">
                Business Overview
            </h1>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-primary text-primary-foreground">
                Executive BI
            </span>
            <span class="text-xs text-muted-foreground hidden sm:inline">
                · {{ auth()->user()?->tenant?->name ?? 'Apeiron Enterprise' }}
            </span>
        </div>
        <p class="text-xs sm:text-sm text-muted-foreground mt-0.5">
            Consolidated point-of-sale revenue, margin performance, inventory health, and settlement ledger.
        </p>
    </div>

    <!-- Controls: Updated Timestamp, Date Range, Refresh, Target Modal, POS Action -->
    <div class="flex flex-wrap items-center gap-2">
        <div class="text-xs text-muted-foreground mr-1 tabular-nums flex items-center gap-1.5" aria-label="Last telemetry synchronization time">
            <span class="w-1.5 h-1.5 rounded-full bg-success"></span>
            <span>Updated {{ now()->setTimezone('Asia/Jakarta')->format('H:i') }} WIB</span>
        </div>

        <!-- Date Range Segmented Control (6px radius) -->
        <div role="group" aria-label="Date range selector" class="inline-flex items-center p-0.5 rounded-md bg-secondary border border-border">
            @foreach(['today' => 'Today', '7d' => '7D', '14d' => '14D', '30d' => '30D'] as $key => $lbl)
                <a href="{{ route('dashboard', array_merge(request()->query(), ['period' => $key])) }}" wire:navigate
                   class="px-2.5 py-1 rounded text-xs font-medium transition-colors {{ ($executiveData['period'] ?? '30d') === $key ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ $lbl }}
                </a>
            @endforeach
        </div>

        <!-- Refresh Action -->
        <button type="button"
                onclick="window.location.reload()"
                class="h-8 px-3 rounded-md bg-card border border-border hover:bg-secondary text-xs font-medium text-foreground inline-flex items-center gap-1.5 transition-colors cursor-pointer"
                title="Refresh live telemetry">
            <span class="material-symbols-rounded text-sm text-muted-foreground">refresh</span>
            <span>Refresh</span>
        </button>

        <!-- Atur Target Cabang Modal Trigger (KPI-PLN-01) -->
        @if(auth()->user()?->role?->name === 'Owner' || (bool) (auth()->user()?->role?->is_admin ?? false) || auth()->user()?->hasPermission('*') || auth()->user()?->hasPermission('stores.manage'))
            <button type="button"
                    @click="openStoreTargetModal()"
                    class="h-8 px-3 rounded-md bg-card border border-border hover:bg-secondary text-xs font-medium text-foreground inline-flex items-center gap-1.5 transition-colors cursor-pointer"
                    title="Atur Target Cabang Bulanan">
                <span class="material-symbols-rounded text-sm text-foreground">edit_calendar</span>
                <span>Atur Target Cabang</span>
            </button>
        @endif

        <!-- Open POS Register Action -->
        <a href="{{ route('transaction.create') }}" wire:navigate
           class="h-8 px-3.5 rounded-md bg-primary hover:opacity-90 text-primary-foreground text-xs font-medium inline-flex items-center gap-1.5 transition-colors cursor-pointer">
            <span class="material-symbols-rounded text-sm">add</span>
            <span>New POS Order</span>
        </a>
    </div>
</section>

