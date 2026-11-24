<!-- Top Workspace Context Header (Integrated in Catalog Column) -->
<header class="flex flex-wrap items-center justify-between gap-3 pb-2.5 mb-2.5 shrink-0 border-b border-border/70">
    <div class="flex items-center gap-2.5 min-w-0">
        <h1 class="text-[18px] font-semibold tracking-[-0.02em] leading-tight text-foreground whitespace-nowrap">POS Terminal</h1>
        @if ($this->activeShift && $this->activeShift->status === 'open')
            <a href="{{ route('shifts.index') }}" title="Manage Cashier Shift"
                class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-muted text-foreground text-xs font-medium border border-border hover:bg-secondary transition-colors whitespace-nowrap">
                <span class="w-1.5 h-1.5 rounded-full bg-positive"></span>
                <span class="font-sans tabular-nums">Shift #{{ $this->activeShift->id }}</span>
            </a>
        @endif
        <div class="hidden xl:flex items-center gap-1 px-2.5 py-1 bg-card border border-border rounded-lg text-muted-foreground whitespace-nowrap">
            <span class="material-symbols-rounded text-[14px]">receipt_long</span>
            <span class="text-xs font-sans tabular-nums text-foreground font-medium">
                {{ $pendingOrderCode ? '#' . $pendingOrderCode : ($this->activeShift ? '#SHIFT-' . str_pad((string)$this->activeShift->id, 3, '0', STR_PAD_LEFT) : '#POS-ONLINE') }}
            </span>
        </div>
    </div>

    <div class="flex items-center gap-2 shrink-0">
        <!-- Segmented Channel / Order Type Toggle -->
        @if ($this->isTablesEnabled)
            <div class="inline-flex p-0.5 bg-muted rounded-lg border border-border h-9 items-center">
                <button type="button" wire:click="setOrderType('dine_in')"
                    class="px-3 py-1 rounded-md {{ $orderType === 'dine_in' ? 'bg-foreground text-background font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }} text-xs font-medium transition-all cursor-pointer">
                    Dine-In
                </button>
                <button type="button" wire:click="setOrderType('takeaway')"
                    class="px-3 py-1 rounded-md {{ $orderType === 'takeaway' ? 'bg-foreground text-background font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }} text-xs font-medium transition-all cursor-pointer">
                    Takeaway
                </button>
            </div>
        @else
            <div class="inline-flex p-0.5 bg-muted rounded-lg border border-border h-9 items-center">
                <button type="button" wire:click="setOrderType('counter_retail')"
                    class="px-3 py-1 rounded-md {{ ($orderType === 'counter_retail' || $orderType === 'dine_in') ? 'bg-foreground text-background font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }} text-xs font-medium transition-all cursor-pointer">
                    Counter POS
                </button>
                <button type="button" wire:click="setOrderType('takeaway')"
                    class="px-3 py-1 rounded-md {{ $orderType === 'takeaway' ? 'bg-foreground text-background font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }} text-xs font-medium transition-all cursor-pointer">
                    Delivery / Pickup
                </button>
            </div>
        @endif

        @if ($orderType === 'dine_in' && $this->isTablesEnabled)
            <div class="relative">
                <select wire:model.live="tableId"
                    class="h-9 pl-8 pr-7 bg-card border border-border hover:bg-muted transition-colors text-foreground text-xs font-medium rounded-lg shadow-2xs appearance-none cursor-pointer focus:outline-none focus:border-foreground focus:ring-1 focus:ring-ring">
                    <option value="">-- Select Table --</option>
                    @foreach ($this->activeTables as $table)
                        @php
                            $isAvailable = in_array($table->operational_status, ['available', 'reserved']);
                            $statusLabel = match($table->operational_status) {
                                'occupied'   => ' (Occupied)',
                                'cleaning'   => ' (Cleaning)',
                                'reserved'   => ' (Reserved)',
                                'available'  => '',
                                default      => ' (' . ucfirst($table->operational_status) . ')',
                            };
                        @endphp
                        <option value="{{ $table->id }}" @disabled(! $isAvailable)>
                            Table {{ $table->table_name }}{{ $statusLabel }}
                        </option>
                    @endforeach
                </select>
                <span class="material-symbols-rounded absolute left-2 top-1/2 -translate-y-1/2 text-[16px] text-muted-foreground pointer-events-none">table_restaurant</span>
                <span class="material-symbols-rounded absolute right-1.5 top-1/2 -translate-y-1/2 text-[16px] text-muted-foreground pointer-events-none">expand_more</span>
            </div>
        @endif

        <!-- Parked Orders & List Button -->
        <button type="button" wire:click="$set('isParkedListOpen', true)"
            class="flex items-center gap-1.5 px-3 h-9 rounded-lg bg-card border border-border hover:bg-muted transition-colors text-foreground text-xs font-medium shadow-2xs cursor-pointer"
            title="Parked Orders List (F9)">
            <span class="material-symbols-rounded text-[16px] text-muted-foreground">pause_circle</span>
            <span class="hidden sm:inline">Parked</span>
            <span class="px-1.5 py-0.5 rounded bg-foreground text-background text-xs font-bold font-sans tabular-nums">{{ $this->parkedCarts->count() }}</span>
        </button>
    </div>
</header>
