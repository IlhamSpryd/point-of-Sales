<!-- Top Workspace Context Header -->
<header class="flex flex-col gap-3 pb-3 mb-2 shrink-0 border-b border-border/70">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-col gap-0.5">
            <div class="flex items-center gap-2.5">
                <h1 class="font-headline-lg text-[22px] leading-7 font-semibold text-foreground tracking-tight">POS Terminal</h1>
                @if ($this->activeShift && $this->activeShift->status === 'open')
                    <a href="{{ route('shifts.index') }}" title="Manage Cashier Shift"
                        class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-medium border border-emerald-200 hover:bg-emerald-100 transition-colors">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="font-sans tabular-nums">Shift #{{ $this->activeShift->id }}</span>
                    </a>
                @endif
            </div>
            <p class="font-body-sm text-[13px] leading-5 text-muted-foreground">Manage orders and process point-of-sale transactions.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <!-- Segmented Dine-In / Takeaway Toggle -->
            <div class="inline-flex p-1 bg-muted rounded-lg border border-border h-10 items-center">
                <button type="button" wire:click="setOrderType('dine_in')"
                    class="px-3.5 py-1.5 rounded-[6px] {{ $orderType === 'dine_in' ? 'bg-primary text-white shadow-sm' : 'text-muted-foreground hover:text-foreground' }} font-label-md text-[13px] font-medium transition-all cursor-pointer">
                    Dine-In
                </button>
                <button type="button" wire:click="setOrderType('takeaway')"
                    class="px-3.5 py-1.5 rounded-[6px] {{ $orderType === 'takeaway' ? 'bg-primary text-white shadow-sm' : 'text-muted-foreground hover:text-foreground' }} font-label-md text-[13px] font-medium transition-all cursor-pointer">
                    Takeaway
                </button>
            </div>

            <div class="h-6 w-px bg-secondary hidden sm:block"></div>

            <!-- Order / Shift ID badge -->
            <div class="flex items-center gap-1.5 px-3 h-10 bg-white border border-border rounded-lg text-muted-foreground">
                <span class="material-symbols-rounded text-[16px]">receipt_long</span>
                <span class="font-label-md text-[12px] font-sans tabular-nums text-foreground font-medium">
                    {{ $pendingOrderCode ? '#' . $pendingOrderCode : ($this->activeShift ? '#SHIFT-' . str_pad((string)$this->activeShift->id, 3, '0', STR_PAD_LEFT) : '#POS-ONLINE') }}
                </span>
            </div>

            <!-- Offline / Online Status Pill (DEC-006 / TR-003) -->
            <div x-data="{ online: navigator.onLine, pendingCount: 0 }"
                 x-init="
                     window.addEventListener('pos-connectivity-changed', (e) => { online = e.detail.online; });
                     window.addEventListener('offline-queue-updated', (e) => { pendingCount = e.detail.count; });
                     if (window.ApeironOfflinePos) { window.ApeironOfflinePos.getPendingCount().then(c => pendingCount = c); }
                 "
                 class="flex items-center gap-1.5 px-3 h-10 border rounded-lg text-xs font-medium transition-all"
                 :class="online ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-300 animate-pulse'">
                <span class="w-2 h-2 rounded-full" :class="online ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                <span x-text="online ? 'Online' : 'Offline Mode'"></span>
                <template x-if="pendingCount > 0">
                    <span class="ml-1 px-1.5 py-0.5 rounded-full bg-amber-500 text-white text-[10px] font-bold" x-text="pendingCount + ' antrean'"></span>
                </template>
            </div>

            <!-- Parked Orders & Table Selector -->
            <div class="flex items-center gap-2">
                <button type="button" wire:click="$set('isParkedListOpen', true)"
                    class="flex items-center gap-1.5 px-3 h-10 rounded-lg bg-white border border-border hover:bg-muted transition-colors text-foreground font-label-md text-[13px] font-medium shadow-sm"
                    title="Parked Orders List (F9)">
                    <span class="material-symbols-rounded text-[17px] text-muted-foreground">pause_circle</span>
                    <span>Parked Orders</span>
                    <span class="px-1.5 py-0.5 rounded bg-primary text-white text-[10px] font-bold font-sans tabular-nums">{{ $this->parkedCarts->count() }}</span>
                </button>

                @if ($orderType === 'dine_in' && $this->isTablesEnabled)
                    <div class="relative">
                        <select wire:model.live="tableId"
                            class="h-10 pl-9 pr-8 bg-white border border-border hover:bg-muted transition-colors text-foreground font-label-md text-[13px] font-medium rounded-lg shadow-sm appearance-none cursor-pointer focus:outline-none focus:border-foreground">
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
                        <span class="material-symbols-rounded absolute left-2.5 top-1/2 -translate-y-1/2 text-[17px] text-muted-foreground pointer-events-none">table_restaurant</span>
                        <span class="material-symbols-rounded absolute right-2 top-1/2 -translate-y-1/2 text-[16px] text-gray-400 pointer-events-none">expand_more</span>
                    </div>
                @endif
            </div>
        </div>
    </div>
</header>
