<!-- Top Workspace Context Header -->
<header class="flex flex-col gap-3 pb-3 mb-2 shrink-0 border-b border-[#E5E5E5]/70">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-col gap-0.5">
            <div class="flex items-center gap-2.5">
                <h1 class="font-headline-lg text-[22px] leading-7 font-semibold text-[#111827] tracking-tight">POS / Kasir</h1>
                @if ($this->activeShift && $this->activeShift->status === 'open')
                    <a href="{{ route('shifts.index') }}" title="Kelola Shift Kasir"
                        class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-medium border border-emerald-200 hover:bg-emerald-100 transition-colors">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="font-mono">Shift #{{ $this->activeShift->id }}</span>
                    </a>
                @endif
            </div>
            <p class="font-body-sm text-[13px] leading-5 text-[#6B7280]">Kelola pesanan dan transaksi penjualan cepat.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <!-- Segmented Dine-In / Takeaway Toggle -->
            <div class="inline-flex p-1 bg-[#F1F1F1] rounded-lg border border-[#E5E5E5] h-10 items-center">
                <button type="button" wire:click="setOrderType('dine_in')"
                    class="px-3.5 py-1.5 rounded-[6px] {{ $orderType === 'dine_in' ? 'bg-[#171717] text-white shadow-sm' : 'text-[#6B7280] hover:text-[#111827]' }} font-label-md text-[13px] font-medium transition-all cursor-pointer">
                    Dine-In
                </button>
                <button type="button" wire:click="setOrderType('takeaway')"
                    class="px-3.5 py-1.5 rounded-[6px] {{ $orderType === 'takeaway' ? 'bg-[#171717] text-white shadow-sm' : 'text-[#6B7280] hover:text-[#111827]' }} font-label-md text-[13px] font-medium transition-all cursor-pointer">
                    Takeaway
                </button>
            </div>

            <div class="h-6 w-px bg-[#E5E5E5] hidden sm:block"></div>

            <!-- Order / Shift ID badge -->
            <div class="flex items-center gap-1.5 px-3 h-10 bg-white border border-[#E5E5E5] rounded-lg text-[#6B7280]">
                <span class="material-symbols-rounded text-[16px]">receipt_long</span>
                <span class="font-label-md text-[12px] font-mono text-[#111827] font-medium">
                    {{ $pendingOrderCode ? '#' . $pendingOrderCode : ($this->activeShift ? '#SHIFT-' . str_pad((string)$this->activeShift->id, 3, '0', STR_PAD_LEFT) : '#POS-ONLINE') }}
                </span>
            </div>

            <!-- Hold Pesanan & Table Selector -->
            <div class="flex items-center gap-2">
                <button type="button" wire:click="$set('isParkedListOpen', true)"
                    class="flex items-center gap-1.5 px-3 h-10 rounded-lg bg-white border border-[#E5E5E5] hover:bg-[#F9FAFB] transition-colors text-[#111827] font-label-md text-[13px] font-medium shadow-sm"
                    title="Daftar Pesanan Ditahan (Hold Antrean F9)">
                    <span class="material-symbols-rounded text-[17px] text-[#6B7280]">pause_circle</span>
                    <span>Hold Pesanan</span>
                    <span class="px-1.5 py-0.5 rounded bg-[#171717] text-white text-[10px] font-bold font-mono">{{ $this->parkedCarts->count() }}</span>
                </button>

                @if ($orderType === 'dine_in' && $this->isTablesEnabled)
                    <div class="relative">
                        <select wire:model.live="tableId"
                            class="h-10 pl-9 pr-8 bg-white border border-[#E5E5E5] hover:bg-[#F9FAFB] transition-colors text-[#111827] font-label-md text-[13px] font-medium rounded-lg shadow-sm appearance-none cursor-pointer focus:outline-none focus:border-[#171717]">
                            <option value="">-- Daftar Meja --</option>
                            @foreach ($this->activeTables as $table)
                                @php
                                    $isAvailable = in_array($table->operational_status, ['available', 'reserved']);
                                    $statusLabel = match($table->operational_status) {
                                        'occupied'   => ' (Terisi)',
                                        'cleaning'   => ' (Pembersihan)',
                                        'reserved'   => ' (Dipesan)',
                                        'available'  => '',
                                        default      => ' (' . ucfirst($table->operational_status) . ')',
                                    };
                                @endphp
                                <option value="{{ $table->id }}" @disabled(! $isAvailable)>
                                    Meja {{ $table->table_name }}{{ $statusLabel }}
                                </option>
                            @endforeach
                        </select>
                        <span class="material-symbols-rounded absolute left-2.5 top-1/2 -translate-y-1/2 text-[17px] text-[#6B7280] pointer-events-none">table_restaurant</span>
                        <span class="material-symbols-rounded absolute right-2 top-1/2 -translate-y-1/2 text-[16px] text-gray-400 pointer-events-none">expand_more</span>
                    </div>
                @endif
            </div>
        </div>
    </div>
</header>
