{{-- ========================================================================= --}}
{{-- EXECUTIVE OVERVIEW HEADER BAR — ZERO PILLS & COMPACT 14" SCALE            --}}
{{-- ========================================================================= --}}
<section class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 pb-3 border-b border-border" aria-label="Executive Overview Header">
    <div>
        <div class="flex items-center gap-2">
            <h1 class="text-[18px] sm:text-[20px] font-semibold tracking-[-0.02em] text-foreground">
                Business Overview
            </h1>
            <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                Executive BI
            </span>
            <span class="text-xs text-muted-foreground hidden sm:inline">
                · {{ auth()->user()?->tenant?->name ?? 'Apeiron Enterprise' }}
            </span>
            <span class="text-[11.5px] text-muted-foreground hidden md:inline-flex items-center gap-1.5 tabular-nums ml-1" aria-label="Last telemetry synchronization time">
                <span class="w-1.5 h-1.5 rounded-full bg-success"></span>
                <span>Updated {{ now()->setTimezone('Asia/Jakarta')->format('H:i') }} WIB</span>
            </span>
        </div>
        <p class="text-[11.5px] text-muted-foreground mt-0.5">
            Consolidated point-of-sale revenue, margin performance, inventory health, and settlement ledger.
        </p>
    </div>

    <!-- Controls: Date Range, Refresh (Icon only 32x32), Target Modal, New POS Order (All in 1 Row) -->
    <div class="flex flex-nowrap items-center gap-2 shrink-0">
        <!-- Date Range Segmented Control (6px radius) -->
        <div role="group" aria-label="Date range selector" class="inline-flex items-center p-0.5 rounded-md bg-secondary border border-border shrink-0">
            @foreach(['today' => 'Today', '7d' => '7D', '14d' => '14D', '30d' => '30D'] as $key => $lbl)
                <a href="{{ route('dashboard', array_merge(request()->query(), ['period' => $key])) }}" wire:navigate
                   class="px-2.5 py-1 rounded text-xs font-medium transition-colors {{ ($executiveData['period'] ?? '30d') === $key ? 'bg-card text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ $lbl }}
                </a>
            @endforeach
        </div>

        <!-- Refresh Action (Icon-only 32x32) -->
        <button type="button"
                onclick="window.location.reload()"
                class="w-8 h-8 rounded-md bg-card border border-border hover:bg-secondary text-foreground inline-flex items-center justify-center transition-colors cursor-pointer shrink-0"
                title="Refresh live telemetry">
            <span class="material-symbols-rounded text-[18px] text-muted-foreground hover:text-foreground">refresh</span>
            <span class="sr-only">Refresh</span>
        </button>

        <!-- Atur Target Cabang Modal Trigger (KPI-PLN-01) -->
        @if(auth()->user()?->role?->name === 'Owner' || (bool) (auth()->user()?->role?->is_admin ?? false) || auth()->user()?->hasPermission('*') || auth()->user()?->hasPermission('stores.manage'))
            <button type="button"
                    @click="openStoreTargetModal()"
                    class="h-8 px-2.5 rounded-md bg-card border border-border hover:bg-secondary text-xs font-medium text-foreground inline-flex items-center gap-1.5 transition-colors cursor-pointer shrink-0"
                    title="Atur Target Cabang Bulanan">
                <span class="material-symbols-rounded text-sm text-foreground">edit_calendar</span>
                <span>Atur Target</span>
            </button>
        @endif

        <!-- Open POS Register Action -->
        <a href="{{ route('transaction.create') }}" wire:navigate
           class="h-8 px-3 rounded-md bg-primary hover:opacity-90 text-primary-foreground text-xs font-medium inline-flex items-center gap-1.5 transition-colors cursor-pointer shrink-0">
            <span class="material-symbols-rounded text-sm">add</span>
            <span>New POS Order</span>
        </a>
    </div>
</section>
