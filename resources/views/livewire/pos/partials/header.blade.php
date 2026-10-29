<div class="sticky top-0 z-30 bg-white/95 backdrop-blur-sm shrink-0 flex flex-col border-b border-gray-100">
    <!-- Bagian Header: Menampilkan informasi singkat -->
    <header class="flex justify-between items-center px-4 lg:px-6 h-[72px] shrink-0">
        <div class="flex items-center gap-3">
            {{-- Indikator Perangkat / Shift Aktif (Klik langsung ke Shift Index) --}}
            <a href="{{ route('shifts.index') }}"
                title="Kelola Shift Kasir"
                class="hidden xl:flex items-center gap-2 px-3 py-1.5 bg-gray-50 hover:bg-gray-100 border border-gray-200/80 rounded-xl text-xs font-bold text-gray-700 transition-colors">
                <span class="w-2 h-2 rounded-full {{ $this->activeShift && $this->activeShift->status === 'open' ? 'bg-emerald-500 animate-pulse' : 'bg-rose-500' }}"></span>
                <span>{{ $this->activeShift?->device?->device_name ?? 'POS Terminal' }}</span>
                <span class="text-gray-300">|</span>
                <span class="font-mono">Shift #{{ $this->activeShift?->id ?? '-' }}</span>
            </a>

            <!-- Tipe Pesanan & Meja (Desktop style) -->
            <div class="hidden sm:flex items-center gap-3">
                <div class="flex gap-1 p-1 bg-gray-100/80 rounded-xl border border-gray-200/50">
                    <label class="flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="orderType" value="dine_in" class="peer sr-only">
                        <span class="px-4 py-1.5 rounded-lg text-[13px] font-bold transition-all duration-200 peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-apeiron-ink text-gray-500 hover:text-apeiron-ink">
                            Dine-In
                        </span>
                    </label>
                    <label class="flex items-center cursor-pointer">
                        <input type="radio" wire:model.live="orderType" value="takeaway" class="peer sr-only">
                        <span class="px-4 py-1.5 rounded-lg text-[13px] font-bold transition-all duration-200 peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-apeiron-ink text-gray-500 hover:text-apeiron-ink">
                            Takeaway
                        </span>
                    </label>
                </div>

                @if ($orderType === 'dine_in' && $this->isTablesEnabled)
                    <div class="relative">
                        <select wire:model="tableId"
                            class="bg-gray-100/80 border border-gray-200/50 text-gray-700 font-bold text-[13px] rounded-xl hover:bg-gray-200/50 hover:text-apeiron-ink focus:bg-white focus:border-gray-300 focus:ring-0 block pl-4 pr-9 py-1.5 transition-all duration-200 min-h-[36px] appearance-none cursor-pointer">
                            <option value="">-- Pilih Meja --</option>
                            @foreach ($this->activeTables as $table)
                                @php
                                    $isAvailable = in_array($table->operational_status, ['available', 'reserved']);
                                    $statusLabel = match($table->operational_status) {
                                        'occupied'   => ' (Terisi)',
                                        'cleaning'   => ' (Dibersihkan)',
                                        'reserved'   => ' (Reservasi)',
                                        'available'  => '',
                                        default      => ' (' . ucfirst($table->operational_status) . ')',
                                    };
                                @endphp
                                <option value="{{ $table->id }}" @disabled(! $isAvailable)>
                                    Meja {{ $table->table_name }}{{ $statusLabel }}
                                </option>
                            @endforeach
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-gray-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <!-- Search Bar & Parked Cart Buttons -->
        <div class="flex items-center gap-2">
            <div class="relative flex-1 sm:flex-none sm:w-56 xl:w-72">
                <x-search-input wire:model.live.debounce.300ms="search" id="search-input"
                    placeholder="Cari produk / barcode..." />
                <div class="hidden md:flex absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none items-center gap-1">
                    <x-ui.kbd>F2</x-ui.kbd>
                </div>
            </div>

            {{-- Tombol Tahan Keranjang (F8) --}}
            <button type="button" wire:click="$set('isParkModalOpen', true)" :disabled="{{ count($cart) === 0 ? 'true' : 'false' }}"
                class="disabled:opacity-40 disabled:cursor-not-allowed px-3 py-2 rounded-xl border border-gray-200 bg-white text-xs font-bold text-gray-700 hover:bg-gray-50 active:scale-95 transition-all flex items-center gap-1.5 shadow-sm"
                title="Tahan Keranjang Saat Ini (Pintasan: F8)">
                <span class="material-symbols-rounded text-[18px]">pause_circle</span>
                <span class="hidden md:inline">Tahan</span>
                <x-ui.kbd class="hidden xl:inline-flex">F8</x-ui.kbd>
            </button>

            {{-- Tombol Daftar Keranjang Tertunda (F9) --}}
            <button type="button" wire:click="$set('isParkedListOpen', true)"
                class="relative px-3 py-2 rounded-xl border border-gray-200 bg-white text-xs font-bold text-gray-700 hover:bg-gray-50 active:scale-95 transition-all flex items-center gap-1.5 shadow-sm"
                title="Daftar Keranjang Tertunda (Pintasan: F9)">
                <span class="material-symbols-rounded text-[18px]">inbox</span>
                <span class="hidden md:inline">Tertunda</span>
                <span class="text-xs">({{ $this->parkedCarts->count() }})</span>
                <x-ui.kbd class="hidden xl:inline-flex">F9</x-ui.kbd>
                @if($this->parkedCarts->count() > 0)
                    <span class="absolute -top-1 -right-1 w-2.5 h-2.5 bg-rose-500 rounded-full animate-pulse"></span>
                @endif
            </button>
        </div>
    </header>

    <!-- Mobile Fallback for Order Type & Table (visible only on small screens) -->
    <div class="sm:hidden px-4 pb-3 flex flex-col gap-2">
        <div class="flex gap-1 p-1 bg-gray-100/80 rounded-xl border border-gray-200/50">
            <label class="flex-1 flex items-center cursor-pointer">
                <input type="radio" wire:model.live="orderType" value="dine_in" class="peer sr-only">
                <span class="w-full text-center px-3 py-1.5 rounded-lg text-xs font-bold transition-all duration-200 peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-apeiron-ink text-gray-500 hover:text-apeiron-ink">
                    Dine-In
                </span>
            </label>
            <label class="flex-1 flex items-center cursor-pointer">
                <input type="radio" wire:model.live="orderType" value="takeaway" class="peer sr-only">
                <span class="w-full text-center px-3 py-1.5 rounded-lg text-xs font-bold transition-all duration-200 peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-apeiron-ink text-gray-500 hover:text-apeiron-ink">
                    Takeaway
                </span>
            </label>
        </div>
        @if ($orderType === 'dine_in' && $this->isTablesEnabled)
            <div class="relative">
                <select wire:model="tableId"
                    class="w-full bg-gray-100/80 border border-gray-200/50 text-gray-700 font-bold text-xs rounded-xl hover:bg-gray-200/50 hover:text-apeiron-ink focus:bg-white focus:border-gray-300 focus:ring-0 block pl-3 pr-8 py-1.5 transition-all duration-200 min-h-[36px] appearance-none cursor-pointer">
                    <option value="">-- Pilih Meja --</option>
                    @foreach ($this->activeTables as $table)
                        @php
                            $isAvailable = in_array($table->operational_status, ['available', 'reserved']);
                            $statusLabel = match($table->operational_status) {
                                'occupied'   => ' (Terisi)',
                                'cleaning'   => ' (Dibersihkan)',
                                'reserved'   => ' (Reservasi)',
                                'available'  => '',
                                default      => ' (' . ucfirst($table->operational_status) . ')',
                            };
                        @endphp
                        <option value="{{ $table->id }}" @disabled(! $isAvailable)>
                            Meja {{ $table->table_name }}{{ $statusLabel }}
                        </option>
                    @endforeach
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2.5 text-gray-400">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
            </div>
        @endif
    </div>

    <!-- Filter Categories (Notion Style horizontal chip bar) -->
    <div class="px-4 pb-3 lg:px-6 shrink-0">
        <div class="w-full overflow-hidden">
            <div class="flex gap-2 overflow-x-auto pb-1 pt-0.5 px-0.5 scrollbar-hide items-center relative snap-x snap-mandatory"
                x-data="{ activeCategory: 'all' }">
                <!-- Tombol Semua -->
                <a href="#top-products" @click="activeCategory = 'all'"
                    :class="activeCategory === 'all' ? 'bg-apeiron-ink text-white shadow-sm' :
                        'bg-white text-gray-500 hover:bg-gray-50 hover:text-apeiron-ink border border-gray-200'"
                    class="snap-start shrink-0 inline-flex items-center justify-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-apeiron-ink active:scale-95">
                    <span class="tracking-tight">Semua</span>
                    <span :class="activeCategory === 'all' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600'"
                        class="px-1.5 py-0.5 rounded-md text-[10px] font-black transition-colors">
                        {{ $this->categories->sum(fn($cat) => $cat->products->count()) }}
                    </span>
                </a>

                @foreach ($this->categories as $category)
                    @if ($category->products->count() > 0)
                        <a href="#cat-{{ $category->id }}" @click="activeCategory = {{ $category->id }}"
                            :class="activeCategory === {{ $category->id }} ?
                                'bg-apeiron-ink text-white shadow-sm' :
                                'bg-white text-gray-500 hover:bg-gray-50 hover:text-apeiron-ink border border-gray-200'"
                            class="snap-start shrink-0 inline-flex items-center justify-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-apeiron-ink active:scale-95">
                            <span class="tracking-tight">{{ $category->category_name }}</span>
                            <span :class="activeCategory === {{ $category->id }} ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600'"
                                class="px-1.5 py-0.5 rounded-md text-[10px] font-black transition-colors">
                                {{ $category->products->count() }}
                            </span>
                        </a>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
</div>
