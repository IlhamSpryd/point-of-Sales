{{-- [OMEGA-NODE2] Tambah pemilihan pelanggan (Loyalty) + modal Split Payment
     (Alpine.js murni, exact-sum ke TransactionService). Skenario Tarik
     Pesanan sengaja TIDAK diubah -- lihat SYNC ALERT Node 1. | 2026-09-22 --}}
<div id="livewire-pos-root" data-testid="lw-pos-root" dusk="lw-pos-root" x-data="{
    focusSearch() {
            const input = document.getElementById('search-input');
            if (input) input.focus();
        },
        triggerPay() {
            const btn = document.getElementById('btn-bayar');
            if (btn && !btn.disabled) btn.click();
        }
}"
    @keydown.window.f2.prevent="focusSearch()" @keydown.window.f4.prevent="triggerPay()"
    class="flex flex-col h-[calc(100vh-64px)] lg:h-screen overflow-hidden min-h-0 bg-white">

    <!-- Struktur Utama: Layout Kiri (Produk) & Kanan (Keranjang) -->
    <div class="flex flex-col lg:flex-row flex-1 w-full overflow-hidden relative min-h-0 bg-gray-50/50">

        <!-- PANEL KIRI: Pencarian, Kategori & Daftar Produk -->
        <section
            class="flex-1 min-w-0 min-h-0 flex flex-col bg-white border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto custom-scrollbar relative">

            <div class="sticky top-0 z-30 bg-white/95 backdrop-blur-sm shrink-0 flex flex-col">
                <!-- Bagian Header: Menampilkan informasi singkat -->
                <header
                    class="flex justify-between items-center px-4 lg:px-6 h-[72px] border-b border-gray-100 shrink-0">
                    <div class="flex items-center gap-4">

                        <!-- Tipe Pesanan & Meja (Seamless style) -->
                        <div class="hidden sm:flex items-center gap-3">
                            <div class="flex gap-1 p-1 bg-gray-100/80 rounded-xl border border-gray-200/50">
                                <label class="flex items-center cursor-pointer">
                                    <input type="radio" wire:model.live="orderType" value="dine_in"
                                        class="peer sr-only">
                                    <span
                                        class="px-4 py-1.5 rounded-lg text-[13px] font-bold transition-all duration-200 peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-yovel-ink text-gray-500 hover:text-yovel-ink">
                                        Dine-In
                                    </span>
                                </label>
                                <label class="flex items-center cursor-pointer">
                                    <input type="radio" wire:model.live="orderType" value="takeaway"
                                        class="peer sr-only">
                                    <span
                                        class="px-4 py-1.5 rounded-lg text-[13px] font-bold transition-all duration-200 peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-yovel-ink text-gray-500 hover:text-yovel-ink">
                                        Takeaway
                                    </span>
                                </label>
                            </div>

                            @if ($orderType === 'dine_in')
                                <div class="relative">
                                    <select wire:model="tableId"
                                        class="bg-gray-100/80 border border-gray-200/50 text-gray-700 font-bold text-[13px] rounded-xl hover:bg-gray-200/50 hover:text-yovel-ink focus:bg-white focus:border-gray-300 focus:ring-0 block pl-4 pr-9 py-1.5 transition-all duration-200 min-h-[36px] appearance-none cursor-pointer">
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
                                            <option value="{{ $table->id }}"
                                                @disabled(! $isAvailable)>
                                                Meja {{ $table->table_name }}{{ $statusLabel }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div
                                        class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-gray-400">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                                d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Search Bar -->
                    <div class="flex-1 sm:flex-none sm:w-64 xl:w-72">
                        <x-search-input wire:model.live.debounce.300ms="search" id="search-input"
                            placeholder="Cari produk / scan / kode POS-... (Tarik Pesanan)" />
                    </div>
                </header>

                <!-- Mobile Fallback for Order Type & Table (visible only on small screens) -->
                <div class="sm:hidden p-4 border-b border-gray-100 shrink-0 flex flex-col gap-3">
                    <div class="flex gap-1 p-1 bg-gray-100/80 rounded-xl border border-gray-200/50">
                        <label class="flex-1 flex items-center cursor-pointer">
                            <input type="radio" wire:model.live="orderType" value="dine_in" class="peer sr-only">
                            <span
                                class="w-full text-center px-4 py-2 rounded-lg text-[13px] font-bold transition-all duration-200 peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-yovel-ink text-gray-500 hover:text-yovel-ink">
                                Dine-In
                            </span>
                        </label>
                        <label class="flex-1 flex items-center cursor-pointer">
                            <input type="radio" wire:model.live="orderType" value="takeaway" class="peer sr-only">
                            <span
                                class="w-full text-center px-4 py-2 rounded-lg text-[13px] font-bold transition-all duration-200 peer-checked:bg-white peer-checked:shadow-sm peer-checked:text-yovel-ink text-gray-500 hover:text-yovel-ink">
                                Takeaway
                            </span>
                        </label>
                    </div>
                    @if ($orderType === 'dine_in')
                        <div class="relative">
                            <select wire:model="tableId"
                                class="w-full bg-gray-100/80 border border-gray-200/50 text-gray-700 font-bold text-[13px] rounded-xl hover:bg-gray-200/50 hover:text-yovel-ink focus:bg-white focus:border-gray-300 focus:ring-0 block pl-4 pr-9 py-2 transition-all duration-200 min-h-[40px] appearance-none cursor-pointer">
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
                                    <option value="{{ $table->id }}"
                                        @disabled(! $isAvailable)>
                                        Meja {{ $table->table_name }}{{ $statusLabel }}
                                    </option>
                                @endforeach
                            </select>
                            <div
                                class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-gray-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                        d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Navigasi Bar Lengket (Sticky) untuk Cari & Filter -->
                <div class="px-4 pb-4 lg:px-6 lg:pb-5 shrink-0">
                    <!-- Filter Categories (Seamless Google Chip Style) -->
                    <div class="w-full overflow-hidden">
                        <div class="flex gap-2 overflow-x-auto pb-2 pt-1 px-1 scrollbar-hide items-center relative"
                            x-data="{ activeCategory: 'all' }">
                            <!-- Tombol Semua -->
                            <a href="#top-products" @click="activeCategory = 'all'"
                                :class="activeCategory === 'all' ? 'bg-yovel-ink text-white hover:bg-yovel-ink' :
                                    'bg-gray-100 text-gray-700 hover:bg-gray-200 hover:text-yovel-ink'"
                                class="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2 rounded-full text-[13px] font-medium transition-[transform,background-color,color] duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 active:scale-95 shadow-sm border border-transparent">
                                <span class="tracking-tight">Semua</span>
                                <span
                                    :class="activeCategory === 'all' ? 'bg-white/20 text-white' : 'bg-white text-gray-500'"
                                    class="px-1.5 py-0.5 rounded-full text-[10px] font-bold shadow-sm transition-colors">
                                    {{ $this->categories->sum(fn($cat) => $cat->products->count()) }}
                                </span>
                            </a>

                            @foreach ($this->categories as $category)
                                @if ($category->products->count() > 0)
                                    <a href="#cat-{{ $category->id }}" @click="activeCategory = {{ $category->id }}"
                                        :class="activeCategory === {{ $category->id }} ?
                                            'bg-yovel-ink text-white hover:bg-yovel-ink' :
                                            'bg-gray-100 text-gray-700 hover:bg-gray-200 hover:text-yovel-ink'"
                                        class="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2 rounded-full text-[13px] font-medium transition-[transform,background-color,color] duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 active:scale-95 shadow-sm border border-transparent">
                                        <span class="tracking-tight">{{ $category->category_name }}</span>
                                        <span
                                            :class="activeCategory === {{ $category->id }} ? 'bg-white/20 text-white' :
                                                'bg-white text-gray-500'"
                                            class="px-1.5 py-0.5 rounded-full text-[10px] font-bold shadow-sm transition-colors">
                                            {{ $category->products->count() }}
                                        </span>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>

                <!-- Area Grid: Menampilkan kumpulan galeri produk -->
                <div id="top-products" class="p-4 lg:p-6 lg:pt-2 scroll-mt-32">
                    @foreach ($this->categories as $category)
                        @if ($category->products->count() > 0)
                            <div id="cat-{{ $category->id }}" class="scroll-mt-32 mb-4">

                                <div
                                    class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-3 lg:gap-4">
                                    @foreach ($category->products as $product)
                                        @php
                                            $inCartQty = collect($this->cartLines)
                                                ->where('product_id', $product->id)
                                                ->sum('qty');
                                        @endphp
                                        <div wire:click="{{ $product->modifierGroups->count() > 0 ? "openModifierPicker({$product->id})" : "addToCartDirectly({$product->id})" }}"
                                            role="button" tabindex="0"
                                            class="group bg-white rounded-2xl border border-gray-200 shadow-[0_2px_8px_-2px_rgba(0,0,0,0.05)] hover:border-yovel-ink hover:shadow-md hover:-translate-y-1 cursor-pointer active:scale-[0.98] transition-all duration-200 flex flex-col h-full relative outline-none focus-visible:ring-2 focus-visible:ring-gray-900">

                                            @if ($inCartQty > 0)
                                                <div class="absolute -top-2 -right-2 z-20 flex">
                                                    <span
                                                        class="bg-white border-2 border-white text-yovel-ink text-[11px] font-bold min-w-[24px] h-6 px-1 rounded-full flex items-center justify-center shadow-md shadow-yovel-ink/30">
                                                        {{ $inCartQty }}
                                                    </span>
                                                </div>
                                            @endif

                                            <div
                                                class="w-full aspect-square sm:aspect-[4/3] bg-gray-50 relative overflow-hidden group-hover:opacity-95 transition-opacity rounded-t-2xl shrink-0 border-b border-gray-100 flex items-center justify-center">
                                                @if ($product->image)
                                                    <!-- Jika ada foto produk -->
                                                    <img src="{{ Storage::url($product->image) }}"
                                                        class="w-full h-full object-cover">
                                                @else
                                                    <svg class="h-12 w-12 text-gray-300" fill="none"
                                                        viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="1.5"
                                                            d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z">
                                                        </path>
                                                    </svg>
                                                @endif
                                            </div>

                                            <div class="p-3 lg:p-4 flex flex-col flex-1 relative rounded-b-2xl">
                                                <h3
                                                    class="text-[13px] lg:text-[14px] font-bold text-yovel-ink mb-3 leading-tight truncate">
                                                    {{ $product->product_name }}</h3>

                                                <div class="mt-auto flex flex-col gap-3">
                                                    <div class="flex items-end justify-between">
                                                        <span
                                                            class="block text-[14px] lg:text-[15px] font-black text-yovel-ink">Rp
                                                            {{ number_format($product->product_price, 0, ',', '.') }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
        </section>

        <!-- PANEL KANAN: Daftar Keranjang Belanja -->
        <section
            class="w-full lg:w-96 xl:w-[400px] bg-white flex flex-col shrink-0 h-[42vh] sm:h-[46vh] md:h-[52vh] lg:h-full lg:border-l border-gray-200 z-30 shadow-[inset_1px_0_10px_rgba(0,0,0,0.02)]">
            <!-- Judul Keranjang & Customer Selection -->
            <div class="p-4 lg:p-5 border-b border-gray-200 flex flex-col shrink-0 bg-white">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <h2 class="text-xl font-bold text-yovel-ink leading-none">Keranjang</h2>
                        <span
                            class="bg-yovel-ink text-white text-[11px] font-bold px-2.5 py-0.5 rounded-full transition-all duration-300 transform">{{ count($this->cartLines) }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($pendingOrderCode)
                            <span title="Self Order Code"
                                class="text-[11px] font-semibold text-amber-600 bg-amber-50 px-3 py-1 rounded-full uppercase tracking-wider border border-amber-200 flex items-center gap-1 shadow-sm">
                                #{{ $pendingOrderCode }}
                                <button wire:click="cancelPendingOrderMode"
                                    class="hover:text-amber-800 hover:bg-amber-100 rounded-full p-0.5 transition-colors ml-1">
                                    <span class="material-symbols-rounded text-[14px] block">close</span>
                                </button>
                            </span>
                        @endif

                        <button type="button"
                            x-on:click="
                                Swal.fire({
                                    title: 'Kosongkan Keranjang?',
                                    text: 'Semua item akan dihapus dari keranjang.',
                                    icon: 'warning',
                                    showCancelButton: true,
                                    confirmButtonColor: '#BE123C',
                                    cancelButtonColor: '#6B7280',
                                    confirmButtonText: 'Ya, Kosongkan',
                                    cancelButtonText: 'Batal',
                                    reverseButtons: true,
                                    customClass: { popup: 'rounded-2xl' },
                                }).then((result) => { if (result.isConfirmed) $wire.clearCart(); })
                            "
                            title="Kosongkan Keranjang"
                            @if (count($this->cartLines) === 0) disabled @endif
                            class="{{ count($this->cartLines) === 0 ? 'opacity-30 cursor-not-allowed' : 'hover:bg-rose-50 hover:text-rose-600 cursor-pointer' }} text-gray-400 p-2 rounded-lg transition-colors flex items-center justify-center outline-none">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                </div>

                @unless ($pendingOrderId)
                    <div class="relative" x-data="{ showResults: false }">
                        @if ($customerId)
                            <div
                                class="flex items-center justify-between gap-2 bg-gray-50 rounded-xl px-4 py-3 border border-gray-200 shadow-sm">
                                <span class="flex items-center gap-2 text-[13px] font-bold text-gray-700 truncate">
                                    <span class="material-symbols-rounded text-[18px] text-gray-400">person</span>
                                    {{ $customerName }}
                                </span>
                                <button type="button" wire:click="clearCustomer"
                                    class="text-gray-400 hover:text-rose-500 transition-colors">
                                    <span class="material-symbols-rounded text-[18px] block">close</span>
                                </button>
                            </div>
                        @else
                            <x-search-input wire:model.live.debounce.300ms="customerSearch"
                                x-on:focus="showResults = true" x-on:click.outside="showResults = false"
                                placeholder="Tambah pelanggan (opsional)..." icon="person_add" />

                            @if (mb_strlen(trim($customerSearch)) >= 2)
                                <div x-show="showResults" x-cloak wire:loading.class="opacity-50"
                                    wire:target="customerSearch"
                                    class="absolute z-40 mt-1 w-full bg-white rounded-xl border border-gray-200 shadow-xl overflow-hidden max-h-56 overflow-y-auto custom-scrollbar">
                                    @forelse ($this->customerResults as $c)
                                        <button type="button" wire:key="customer-result-{{ $c->id }}"
                                            wire:click="selectCustomer({{ $c->id }}, '{{ addslashes($c->name) }}')"
                                            x-on:click="showResults = false"
                                            class="w-full flex items-center justify-between gap-2 px-4 py-3 text-left hover:bg-gray-50 transition-colors border-b border-gray-100 last:border-0">
                                            <span class="text-[13px] font-bold text-yovel-ink">{{ $c->name }}</span>
                                            <span
                                                class="text-[11px] font-medium text-gray-400">{{ $c->phone ?? '-' }}</span>
                                        </button>
                                    @empty
                                        <div class="px-4 py-4 text-[13px] text-gray-400 text-center">Pelanggan tidak
                                            ditemukan.</div>
                                    @endforelse
                                </div>
                            @endif
                        @endif
                    </div>
                @endunless
            </div>

            <!-- Area Keranjang List & Kalkulasi dipisah jadi absolute inset-0 -->
            <div class="relative flex-1 w-full min-h-0 bg-gray-50/50">

                <div class="absolute inset-0 flex flex-col">
                    <!-- AREA PRODUK YANG DI-SCROLL -->
                    <div class="flex-1 overflow-y-auto custom-scrollbar p-4 lg:p-5 space-y-3">
                        @if (count($this->cartLines) === 0)
                            <div class="h-full flex flex-col justify-center items-center opacity-70">
                                <span
                                    class="material-symbols-rounded text-[56px] text-gray-300 mb-3 block">local_mall</span>
                                <p class="text-[14px] font-semibold text-yovel-ink">Keranjang Kosong</p>
                            </div>
                        @else
                            @foreach ($this->cartLines as $line)
                                <div
                                    class="flex gap-3 p-3 bg-white rounded-xl border border-gray-200 shadow-sm relative group transition-all duration-200 hover:border-gray-300">
                                    <button type="button" wire:click="removeFromCart({{ $line['index'] }})"
                                        class="absolute -top-2 -right-2 bg-white rounded-full p-1 border border-gray-200 text-gray-400 hover:text-rose-500 hover:border-rose-200 shadow-sm transition-opacity opacity-0 group-hover:opacity-100 focus:opacity-100">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                    <div class="flex-1 flex flex-col justify-between">
                                        <div class="flex justify-between items-start">
                                            <h4
                                                class="text-[13.5px] font-semibold text-yovel-ink line-clamp-2 pr-2 leading-tight">
                                                {{ $line['product_name'] }}</h4>
                                        </div>
                                        @if (count($line['options']) > 0 || $line['notes'])
                                            <p class="text-[11px] text-gray-500 mt-1.5 leading-snug">
                                                {{ collect($line['options'])->pluck('name')->join(', ') }}
                                                @if ($line['notes'])
                                                    <span
                                                        class="block text-gray-400 mt-0.5 italic">{{ $line['notes'] }}</span>
                                                @endif
                                            </p>
                                        @endif
                                        <div
                                            class="flex items-end justify-between mt-2 pt-1.5 border-t border-gray-50">
                                            <div>
                                                <span class="text-[14px] font-black text-yovel-ink whitespace-nowrap">Rp
                                                    {{ number_format($line['order_price'], 0, ',', '.') }}</span>
                                            </div>
                                            <div
                                                class="flex items-center gap-1.5 bg-gray-50 border border-gray-200 rounded-full px-2 py-0.5 shadow-sm">
                                                <span
                                                    class="text-[12px] font-bold text-gray-700 w-5 text-center leading-none">{{ $line['qty'] }}x</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>

                    <!-- AREA KALKULASI: TETAP DI BAWAH -->
                    <div
                        class="p-4 border-t border-gray-200 bg-white shrink-0 z-10 shadow-[0_-4px_20px_rgba(0,0,0,0.03)] flex flex-col gap-3">

                        @if (count($this->activeDiscounts) > 0)
                            <div class="relative">
                                <select wire:model.live="selectedDiscountId"
                                    class="w-full h-10 pl-4 pr-9 bg-gray-100/80 border border-gray-200/50 rounded-xl outline-none text-[13px] font-bold text-gray-700 hover:bg-gray-200/50 hover:text-yovel-ink focus:bg-white focus:border-gray-300 focus:ring-0 appearance-none transition-all duration-200 cursor-pointer">
                                    <option value="">+ Tambah Diskon (Opsional)</option>
                                    @foreach ($this->activeDiscounts as $d)
                                        <option value="{{ $d->id }}">{{ $d->name }}
                                            ({{ $d->type === 'percentage' ? $d->value . '%' : 'Rp ' . number_format($d->value, 0, ',', '.') }})
                                        </option>
                                    @endforeach
                                </select>
                                <div
                                    class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-gray-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                            d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>
                        @endif

                            <div class="flex flex-col gap-1.5 p-3 rounded-xl border border-gray-100 bg-gray-50/60 shadow-inner">
                            <div class="flex justify-between items-center">
                                <span class="text-xs text-gray-500 font-medium">Subtotal</span>
                                <span class="text-xs font-medium text-gray-600 tabular-nums">Rp
                                    {{ number_format($this->subtotal, 0, ',', '.') }}</span>
                            </div>
                            @if ($this->discountAmount > 0)
                                <div class="flex justify-between items-center">
                                    <span class="text-xs text-gray-500 font-medium">Diskon</span>
                                    <span class="text-xs font-medium text-rose-600 tabular-nums">- Rp
                                        {{ number_format($this->discountAmount, 0, ',', '.') }}</span>
                                </div>
                            @endif
                            <div class="flex justify-between items-center">
                                <span class="text-xs text-gray-500 font-medium">Pajak
                                    ({{ rtrim(rtrim(number_format(config('pos.tax_rate', 0.11) * 100, 1), '0'), '.') }}%)</span>
                                <span class="text-xs font-medium text-gray-600 tabular-nums">Rp
                                    {{ number_format($this->taxAmount, 0, ',', '.') }}</span>
                            </div>
                        </div>

                        <div class="flex justify-between items-baseline px-1 pt-1">
                            <span class="text-sm font-semibold text-gray-700">Total Pembayaran</span>
                            <span class="text-2xl font-black text-yovel-ink tabular-nums tracking-tight">Rp
                                {{ number_format($this->totalAmount, 0, ',', '.') }}</span>
                        </div>

                        @if ($pendingOrderId)
                            <div class="relative mt-2">
                                <span
                                    class="absolute inset-y-0 left-0 flex items-center pl-4 text-yovel-ink text-sm font-bold">Rp</span>
                                <input type="number" wire:model.live="cashReceived"
                                    class="bg-white border border-gray-200 text-yovel-ink rounded-xl focus:outline-none focus:ring-1 focus:ring-gray-900 w-full pl-11 pr-4 py-3 font-black text-lg transition-all shadow-sm"
                                    placeholder="Uang diterima (Tunai)">
                            </div>
                            {{-- Shortcut Tunai Pas (Finding #11) --}}
                            <div class="flex gap-2 mt-1.5">
                                <button type="button"
                                    x-on:click="$wire.set('cashReceived', {{ (int) $this->totalAmount }})"
                                    class="flex-1 h-9 text-xs font-bold rounded-lg bg-yovel-ink text-white hover:bg-yovel-ink transition-all active:scale-95 shadow-sm">
                                    Tunai Pas
                                </button>
                                <button type="button"
                                    x-on:click="$wire.set('cashReceived', 50000 * Math.ceil({{ (int) $this->totalAmount }} / 50000))"
                                    class="flex-1 h-9 text-xs font-bold rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 transition-all active:scale-95 border border-gray-200">
                                    Bulatkan 50K
                                </button>
                                <button type="button"
                                    x-on:click="$wire.set('cashReceived', 100000 * Math.ceil({{ (int) $this->totalAmount }} / 100000))"
                                    class="flex-1 h-9 text-xs font-bold rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 transition-all active:scale-95 border border-gray-200">
                                    Bulatkan 100K
                                </button>
                            </div>
                            <div class="flex justify-between items-center px-1 mt-1 mb-1">
                                <span class="text-xs font-semibold text-gray-500">Kembalian</span>
                                <span
                                    class="font-bold text-sm {{ $this->changeAmount >= 0 ? 'text-emerald-600' : 'text-yovel-ink' }}">
                                    Rp {{ number_format($this->changeAmount, 0, ',', '.') }}
                                </span>
                            </div>

                            @error('cart')
                                <div
                                    class="p-2 bg-rose-50 text-rose-600 rounded-lg text-[11px] font-bold flex items-center gap-1.5">
                                    <span class="material-symbols-rounded text-[14px]">error</span> {{ $message }}
                                </div>
                            @enderror

                            <button wire:click="submitOrder" id="btn-bayar"
                                wire:loading.attr="disabled" wire:target="submitOrder"
                                class="w-full h-12 bg-yovel-ink hover:bg-yovel-ink text-white rounded-xl font-black text-[14px] transition-all duration-200 shadow-md active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                                :disabled="count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && !$tableId)">
                                <span wire:loading wire:target="submitOrder" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                                <span wire:loading.remove wire:target="submitOrder">Bayar Sekarang</span>
                                <span wire:loading wire:target="submitOrder">Memproses...</span>
                            </button>
                        @else
                            @error('cart')
                                <div
                                    class="p-2 bg-rose-50 text-rose-600 rounded-lg text-[11px] font-bold flex items-center gap-1.5">
                                    <span class="material-symbols-rounded text-[14px]">error</span> {{ $message }}
                                </div>
                            @enderror

                            <button type="button" id="btn-bayar"
                                class="w-full h-12 bg-yovel-ink hover:bg-yovel-ink text-white rounded-xl font-black text-[14px] transition-all duration-200 shadow-md active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                                @click="$dispatch('open-checkout-modal', { total: {{ (int) $this->totalAmount }} }); $dispatch('open-modal', 'checkout-payment')"
                                :disabled="count($this->cartLines) === 0 || !$orderType || ($orderType === 'dine_in' && !$tableId)">
                                Proses Pembayaran
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    </div>

    {{-- MODAL pemilihan modifier --}}
    @if ($selectingProductId && isset($selectingProduct))
        <div x-data="{
                localMods: @entangle('pendingModifierIds'),
                localQty: @entangle('pendingQty'),
                toggleMod(modId, type, allGroupModIds) {
                    if (type === 'single') {
                        this.localMods = this.localMods.filter(id => !allGroupModIds.includes(id));
                        this.localMods.push(modId);
                    } else {
                        if (this.localMods.includes(modId)) {
                            this.localMods = this.localMods.filter(id => id !== modId);
                        } else {
                            this.localMods.push(modId);
                        }
                    }
                }
            }"
            class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-yovel-ink/20 backdrop-blur-sm"
            wire:transition>
            <div
                class="bg-white sm:rounded-3xl rounded-t-3xl p-0 w-full max-w-md max-h-[90vh] flex flex-col shadow-2xl overflow-hidden">
                <!-- Header Modal -->
                <div class="px-6 py-5 flex justify-between items-start bg-white z-10 shrink-0">
                    <div>
                        <h3 class="font-black text-[22px] leading-tight text-yovel-ink tracking-tight">
                            {{ $selectingProduct->product_name }}</h3>
                        <p class="text-[15px] font-semibold text-gray-500 mt-1">Rp
                            {{ number_format($selectingProduct->product_price, 0, ',', '.') }}</p>
                    </div>
                    <button wire:click="closeModifierPicker"
                        class="p-2 rounded-full text-gray-400 hover:text-yovel-ink hover:bg-gray-100 transition-all duration-200 active:scale-95 -mr-2 -mt-1">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Body Modal (Modifiers) -->
                <div class="px-6 overflow-y-auto flex-1 bg-white space-y-6 pb-6 custom-scrollbar">
                    @foreach ($selectingProduct->modifierGroups as $group)
                        <div class="py-1">
                            <div class="flex justify-between items-baseline mb-3">
                                <h4 class="font-bold text-[15px] text-yovel-ink">{{ $group->name }}</h4>
                                <span class="text-[12px] text-gray-400 font-semibold">
                                    {{ $group->is_required ? ($group->selection_type === 'single' ? 'Wajib pilih 1' : 'Wajib pilih') : 'Opsional' }}
                                </span>
                            </div>

                            <div class="grid grid-cols-2 gap-2.5">
                                @php
                                    $allModIds = $group->modifiers->pluck('id')->toJson();
                                @endphp
                                @foreach ($group->modifiers as $mod)
                                    <label :class="localMods.includes({{ $mod->id }}) ? 'bg-yovel-ink border-yovel-ink shadow-md shadow-yovel-ink/10' : 'bg-gray-50/80 border-transparent hover:bg-gray-100'"
                                           class="flex-col min-h-[56px] rounded-2xl p-3 flex justify-center items-center cursor-pointer transition-all duration-200 border">
                                        <input type="button"
                                            x-on:click="toggleMod({{ $mod->id }}, '{{ $group->selection_type }}', {{ $allModIds }})"
                                            class="sr-only">
                                        <span :class="localMods.includes({{ $mod->id }}) ? 'text-white' : 'text-gray-700'"
                                              class="font-bold text-[14px] leading-tight text-center">
                                            {{ $mod->name }}
                                        </span>
                                        @if ($mod->extra_price > 0)
                                            <span :class="localMods.includes({{ $mod->id }}) ? 'text-gray-400' : 'text-gray-400'"
                                                  class="text-[12px] font-bold mt-0.5">+Rp {{ number_format($mod->extra_price, 0, ',', '.') }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <div class="pt-2">
                        <label class="block font-bold text-[15px] text-yovel-ink mb-3">Catatan Tambahan</label>
                        <textarea wire:model="pendingNotes"
                            class="w-full bg-gray-50/80 border-transparent rounded-2xl text-[14px] font-medium focus:border-gray-200 focus:bg-white focus:ring-4 focus:ring-gray-100 transition-all duration-200 px-4 py-3.5 resize-none placeholder:text-gray-400"
                            rows="2" placeholder="Contoh: Kurangi es, jangan pakai sedotan..."></textarea>
                    </div>
                </div>

                <!-- Footer Modal -->
                <div class="px-6 py-5 bg-white flex items-center justify-between gap-4 shrink-0 shadow-[0_-4px_20px_-10px_rgba(0,0,0,0.05)]">
                    <div class="flex items-center gap-3 bg-gray-50/80 rounded-2xl p-1.5 border border-gray-100">
                        <button type="button" x-on:click="localQty = Math.max(1, localQty - 1)"
                            class="w-10 h-10 flex items-center justify-center bg-white rounded-xl shadow-sm text-yovel-ink font-bold hover:bg-gray-50 active:scale-95 transition-all duration-200">
                            <span class="material-symbols-rounded text-[20px]">remove</span>
                        </button>
                        <span class="w-6 text-center font-black text-[15px] text-yovel-ink" x-text="localQty"></span>
                        <button type="button" x-on:click="localQty++"
                            class="w-10 h-10 flex items-center justify-center bg-white rounded-xl shadow-sm text-yovel-ink font-bold hover:bg-gray-50 active:scale-95 transition-all duration-200">
                            <span class="material-symbols-rounded text-[20px]">add</span>
                        </button>
                    </div>
                    <button wire:click="confirmAddToCart"
                        class="flex-1 min-h-[52px] bg-yovel-ink hover:bg-[#2A2823] text-white rounded-2xl font-black text-[15px] shadow-lg shadow-yovel-ink/20 transition-all duration-200 active:scale-[0.98]">
                        Tambahkan
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- [OMEGA-NODE2] Split Payment Modal --}}
    <x-modal name="checkout-payment" max-width="lg" focusable>
        <div x-data="checkoutPayment()" x-init="init()" class="flex flex-col max-h-[90vh]">
            <div
                class="px-6 py-5 border-b border-gray-100 flex items-center justify-between shrink-0 bg-white rounded-t-3xl relative z-10 shadow-sm">
                <h3 class="font-black text-xl text-yovel-ink tracking-tight flex items-center gap-2">
                    <span class="material-symbols-rounded text-[24px]">payments</span> Pembayaran
                </h3>
                <button type="button" x-on:click="$dispatch('close')" aria-label="Tutup"
                    class="min-w-[44px] min-h-[44px] flex items-center justify-center rounded-xl text-gray-400 hover:bg-gray-100 hover:text-yovel-ink transition-all duration-200 active:scale-90">
                    <span class="material-symbols-rounded text-[24px]">close</span>
                </button>
            </div>

            <div class="p-6 overflow-y-auto flex-1 custom-scrollbar space-y-6 bg-gray-50">
                <!-- Ringkasan tagihan -->
                <div
                    class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm space-y-3 relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-gray-50 rounded-bl-full -z-10 opacity-50"></div>

                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500 font-semibold">Total Tagihan</span>
                        <span class="font-bold text-yovel-ink tabular-nums"
                            x-text="'Rp ' + formatRupiah(total)"></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500 font-semibold">Sudah Dibayar</span>
                        <span class="font-bold text-emerald-600 tabular-nums"
                            x-text="'Rp ' + formatRupiah(paidTotal)"></span>
                    </div>
                    <div class="flex justify-between items-baseline pt-3 border-t border-gray-100 mt-1">
                        <span class="font-bold text-yovel-ink">Sisa Tagihan</span>
                        <span class="text-3xl font-black tabular-nums tracking-tighter"
                            :class="remaining > 0 ? 'text-rose-600' : 'text-emerald-600'"
                            x-text="'Rp ' + formatRupiah(remaining)"></span>
                    </div>
                </div>

                <!-- Daftar leg yang sudah ditambahkan -->
                <div x-show="legs.length > 0" x-cloak class="space-y-3">
                    <template x-for="leg in legs" :key="leg.uid">
                        <div
                            class="flex items-center justify-between gap-3 bg-white rounded-2xl border border-gray-200 p-4 shadow-sm group hover:border-gray-300 transition-colors">
                            <div class="min-w-0 flex items-center gap-4">
                                <div
                                    class="w-10 h-10 rounded-xl bg-gray-50 flex items-center justify-center border border-gray-100 shrink-0 text-gray-600">
                                    <template x-if="leg.method === 'cash'"><span
                                            class="material-symbols-rounded text-[20px]">payments</span></template>
                                    <template x-if="leg.method === 'qris'"><span
                                            class="material-symbols-rounded text-[20px]">qr_code_2</span></template>
                                    <template x-if="leg.method === 'ewallet'"><span
                                            class="material-symbols-rounded text-[20px]">account_balance_wallet</span></template>
                                    <template x-if="leg.method === 'card'"><span
                                            class="material-symbols-rounded text-[20px]">credit_card</span></template>
                                </div>
                                <div>
                                    <span class="block text-[14px] font-bold text-yovel-ink mb-0.5"
                                        x-text="leg.label"></span>
                                    <span class="block text-[12px] font-medium text-gray-500 tabular-nums"
                                        x-text="'Rp ' + formatRupiah(leg.amount) + (leg.changeShown > 0 ? ' · Kembali Rp ' + formatRupiah(leg.changeShown) : '')"></span>
                                </div>
                            </div>
                            <button type="button" x-on:click="removeLeg(leg.uid)" aria-label="Hapus metode ini"
                                class="min-w-[40px] min-h-[40px] flex items-center justify-center rounded-xl text-gray-400 hover:text-rose-500 hover:bg-rose-50 transition-all duration-200 active:scale-90 shrink-0 border border-transparent hover:border-rose-100">
                                <span class="material-symbols-rounded text-[20px]">delete</span>
                            </button>
                        </div>
                    </template>
                </div>

                <!-- Form tambah metode -->
                <div x-show="remaining > 0" x-cloak
                    class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm space-y-5">
                    <div>
                        <span class="block text-[11px] font-bold uppercase tracking-widest text-gray-500 mb-3">Metode
                            Pembayaran</span>
                        <div class="grid grid-cols-4 gap-2.5">
                            <button type="button" x-on:click="method = 'cash'"
                                :class="method === 'cash' ?
                                    'bg-yovel-ink text-white border-yovel-ink shadow-md shadow-yovel-ink/20' :
                                    'bg-white text-gray-500 border-gray-200 hover:bg-gray-50 shadow-sm'"
                                class="min-h-[64px] rounded-xl border-[1.5px] flex flex-col items-center justify-center gap-1.5 text-[11px] font-bold transition-all duration-200 active:scale-95">
                                <span class="material-symbols-rounded text-[24px]">payments</span> Tunai
                            </button>
                            <button type="button" x-on:click="method = 'qris'"
                                :class="method === 'qris' ?
                                    'bg-yovel-ink text-white border-yovel-ink shadow-md shadow-yovel-ink/20' :
                                    'bg-white text-gray-500 border-gray-200 hover:bg-gray-50 shadow-sm'"
                                class="min-h-[64px] rounded-xl border-[1.5px] flex flex-col items-center justify-center gap-1.5 text-[11px] font-bold transition-all duration-200 active:scale-95">
                                <span class="material-symbols-rounded text-[24px]">qr_code_2</span> QRIS
                            </button>
                            <button type="button" x-on:click="method = 'ewallet'"
                                :class="method === 'ewallet' ?
                                    'bg-yovel-ink text-white border-yovel-ink shadow-md shadow-yovel-ink/20' :
                                    'bg-white text-gray-500 border-gray-200 hover:bg-gray-50 shadow-sm'"
                                class="min-h-[64px] rounded-xl border-[1.5px] flex flex-col items-center justify-center gap-1.5 text-[11px] font-bold transition-all duration-200 active:scale-95">
                                <span class="material-symbols-rounded text-[24px]">account_balance_wallet</span>
                                E-Wallet
                            </button>
                            <button type="button" x-on:click="method = 'card'"
                                :class="method === 'card' ?
                                    'bg-yovel-ink text-white border-yovel-ink shadow-md shadow-yovel-ink/20' :
                                    'bg-white text-gray-500 border-gray-200 hover:bg-gray-50 shadow-sm'"
                                class="min-h-[64px] rounded-xl border-[1.5px] flex flex-col items-center justify-center gap-1.5 text-[11px] font-bold transition-all duration-200 active:scale-95">
                                <span class="material-symbols-rounded text-[24px]">credit_card</span> Kartu
                            </button>
                        </div>
                    </div>

                    <div>
                        <span class="block text-[11px] font-bold uppercase tracking-widest text-gray-500 mb-2"
                            x-text="method === 'cash' ? 'Uang Diterima' : 'Nominal Dibayar'"></span>
                        <div class="relative">
                            <span
                                class="absolute inset-y-0 left-0 flex items-center pl-5 font-bold text-gray-400 text-xl">Rp</span>
                            <input type="text" inputmode="numeric" :value="amountFormatted"
                                x-on:input="setAmountInput($event)"
                                class="w-full min-h-[64px] pl-14 pr-4 bg-gray-50 border border-gray-200 rounded-xl focus:ring-1 focus:ring-gray-900 focus:border-yovel-ink focus:bg-white outline-none text-2xl font-black text-yovel-ink transition-all duration-200 shadow-[inset_0_1px_2px_rgba(0,0,0,0.05)]"
                                placeholder="0">
                        </div>
                        <p x-show="method === 'cash' && changePreview > 0" x-cloak
                            class="mt-2 text-sm font-semibold text-emerald-600">
                            Kembalian: <span x-text="'Rp ' + formatRupiah(changePreview)"></span>
                        </p>
                    </div>

                    <div class="flex gap-2.5">
                        <button type="button" x-on:click="fillRemaining()"
                            class="flex-1 min-h-[48px] text-[13px] font-black rounded-xl bg-yovel-ink text-white hover:bg-yovel-ink shadow-md shadow-yovel-ink/20 transition-all duration-200 active:scale-95">PAS</button>
                        <template x-if="method === 'cash'">
                            <button type="button" x-on:click="addQuick(50000)"
                                class="flex-1 min-h-[48px] text-[13px] font-bold rounded-xl bg-white text-gray-700 hover:bg-gray-50 border border-gray-200 shadow-sm transition-all duration-200 active:scale-95">+50K</button>
                        </template>
                        <template x-if="method === 'cash'">
                            <button type="button" x-on:click="addQuick(100000)"
                                class="flex-1 min-h-[48px] text-[13px] font-bold rounded-xl bg-white text-gray-700 hover:bg-gray-50 border border-gray-200 shadow-sm transition-all duration-200 active:scale-95">+100K</button>
                        </template>
                    </div>

                    <p x-show="errorMsg" x-cloak
                        class="text-sm font-medium text-rose-600 bg-rose-50 p-3 rounded-xl border border-rose-100"
                        x-text="errorMsg"></p>

                    <button type="button" x-on:click="addLeg()"
                        class="w-full min-h-[56px] rounded-xl bg-gray-50 text-yovel-ink font-bold text-[14px] border border-gray-200 hover:bg-gray-100 hover:border-gray-300 transition-all duration-200 active:scale-[0.98] flex items-center justify-center gap-2 shadow-sm">
                        <span class="material-symbols-rounded text-[20px]">add_circle</span> Tambah Metode Pembayaran
                    </button>
                </div>

                @error('cart')
                    <div
                        class="p-4 bg-rose-50 text-rose-700 border border-rose-200 rounded-xl text-sm font-semibold flex items-start gap-2 shadow-sm">
                        <span class="material-symbols-rounded text-[20px] mt-0.5 text-rose-500">error</span>
                        {{ $message }}
                    </div>
                @enderror
            </div>

            <div class="p-6 border-t border-gray-200 bg-white shrink-0 rounded-b-3xl">
                <button type="button" x-on:click="submit()"
                    :disabled="remaining > 0 || legs.length === 0 || submitting"
                    class="w-full min-h-[64px] rounded-2xl bg-yovel-ink hover:bg-yovel-ink text-white font-black text-lg shadow-xl shadow-yovel-ink/20 transition-all duration-300 active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                    <span x-show="submitting"
                        class="h-5 w-5 animate-spin rounded-full border-2 border-white/30 border-t-white"
                        aria-hidden="true"></span>
                    <span x-text="submitting ? 'Memproses...' : 'Selesaikan Transaksi'"></span>
                </button>
            </div>
        </div>
    </x-modal>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('checkoutPayment', () => ({
                total: 0,
                legs: [],
                method: 'cash',
                amountRaw: 0,
                amountFormatted: '',
                submitting: false,
                errorMsg: '',
                idempotencyKey: null,

                init() {
                    window.addEventListener('open-checkout-modal', (e) => {
                        this.total = e.detail.total;
                        this.legs = [];
                        this.method = 'cash';
                        this.amountRaw = 0;
                        this.amountFormatted = '';
                        this.errorMsg = '';
                        this.submitting = false;
                        this.idempotencyKey = (window.crypto && window.crypto.randomUUID) ?
                            window.crypto.randomUUID() :
                            (Date.now().toString(36) + Math.random().toString(36).slice(2));
                    });
                },

                get paidTotal() {
                    return this.legs.reduce((s, l) => s + l.amount, 0);
                },
                get remaining() {
                    return Math.max(0, this.total - this.paidTotal);
                },
                get changePreview() {
                    if (this.method !== 'cash') return 0;
                    return Math.max(0, (this.amountRaw || 0) - this.remaining);
                },

                formatRupiah(n) {
                    return new Intl.NumberFormat('id-ID').format(Math.round(n || 0));
                },

                setAmountInput(e) {
                    const raw = e.target.value.replace(/[^0-9]/g, '');
                    this.amountRaw = raw === '' ? 0 : parseInt(raw, 10);
                    this.amountFormatted = raw === '' ? '' : this.formatRupiah(this.amountRaw);
                },

                fillRemaining() {
                    this.amountRaw = this.remaining;
                    this.amountFormatted = this.formatRupiah(this.remaining);
                },

                addQuick(amount) {
                    if (this.method !== 'cash') this.method = 'cash';
                    this.amountRaw = (this.amountRaw || 0) + amount;
                    this.amountFormatted = this.formatRupiah(this.amountRaw);
                },

                methodLabel(m) {
                    return {
                        cash: 'Tunai',
                        qris: 'QRIS',
                        ewallet: 'E-Wallet',
                        card: 'Kartu'
                    } [m] ?? m;
                },

                addLeg() {
                    this.errorMsg = '';
                    if (!this.amountRaw || this.amountRaw <= 0) {
                        this.errorMsg = 'Masukkan nominal pembayaran.';
                        return;
                    }
                    if (this.remaining <= 0) {
                        this.errorMsg = 'Tagihan sudah lunas.';
                        return;
                    }
                    const applied = Math.min(this.amountRaw, this.remaining);
                    const change = Math.max(0, this.amountRaw - this.remaining);
                    this.legs.push({
                        uid: Date.now() + Math.random(),
                        method: this.method,
                        label: this.methodLabel(this.method),
                        amount: applied,
                        changeShown: this.method === 'cash' ? change : 0,
                    });
                    this.amountRaw = 0;
                    this.amountFormatted = '';
                },

                removeLeg(uid) {
                    this.legs = this.legs.filter(l => l.uid !== uid);
                },

                async submit() {
                    if (this.remaining > 0 || this.legs.length === 0 || this.submitting) return;
                    this.submitting = true;
                    const payload = this.legs.map(l => ({
                        method: l.method,
                        amount: l.amount
                    }));
                    try {
                        await $wire.call('submitOrder', payload, this.idempotencyKey);
                    } finally {
                        this.submitting = false;
                    }
                },
            }));
        });

        document.addEventListener('livewire:init', () => {
            Livewire.on('transaction-success', async (e) => {
                const orderCode = e[0].orderCode;
                const isCash = e[0].isCash;

                Swal.fire({
                    title: 'Memproses Struk...',
                    text: 'Menghubungkan ke printer',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                if (isCash && {{ config('pos.auto_open_drawer') ? 'true' : 'false' }}) {
                    await window.YovelPrint.kickDrawer(orderCode);
                }

                const printed = await window.YovelPrint.printReceipt(orderCode);

                if (!printed) {
                    window.open(`/transaction/receipt/${orderCode}?autoprint=1`, '_blank',
                        'width=400,height=600');
                }

                window.location.reload();
            });
        });
    </script>
</div>

<style>
    .scrollbar-hide::-webkit-scrollbar {
        display: none;
    }

    .scrollbar-hide {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }

    .custom-scrollbar {
        scrollbar-width: thin;
        scrollbar-color: #d1d5db transparent;
    }

    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: #d1d5db;
        border-radius: 9999px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background-color: #9ca3af;
    }

    @@media (prefers-reduced-motion: reduce) {
        * {
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
            scroll-behavior: auto !important;
        }
    }
</style>
