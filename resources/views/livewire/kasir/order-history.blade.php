<div x-data @keydown.escape.window="$wire.closeDetail()">

    {{-- ==================== RINGKASAN ==================== --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div data-gsap="kpi-card">
            <x-erp.stat-card
                title="Total Transaksi (Sesuai Filter)"
                value="{{ number_format($this->summary->total_count) }}"
                icon="receipt_long"
                description="Jumlah seluruh pesanan pada periode terpilih"
            />
        </div>
        <div data-gsap="kpi-card">
            <x-erp.stat-card
                title="Total Nilai Transaksi Lunas"
                value="Rp {{ number_format($this->summary->total_paid_amount, 0, ',', '.') }}"
                icon="payments"
                description="Total perputaran omzet pesanan berstatus lunas"
            />
        </div>
    </div>

    {{-- ==================== TOOLBAR FILTER ==================== --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-4 lg:p-5 mb-6 flex flex-col gap-4 shadow-xs">
        <div class="flex flex-col lg:flex-row gap-3 lg:items-center lg:justify-between">
            <div class="w-full lg:max-w-xs">
                <x-search-input wire:model.live.debounce.400ms="search" placeholder="Cari kode pesanan..." />
            </div>

            <div class="relative w-full lg:w-64">
                <select wire:model.live="statusFilter"
                    class="w-full min-h-[42px] px-3.5 pr-9 rounded-xl border border-gray-200 bg-white text-xs font-bold text-gray-700 appearance-none focus:outline-none focus:ring-1 focus:ring-gray-900 focus:border-apeiron-ink transition-all shadow-xs cursor-pointer">
                    <option value="all">Semua Status</option>
                    @foreach ($this->statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none text-gray-400">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>

            <div wire:loading.flex wire:target="search,statusFilter,datePreset,startDate,endDate"
                class="hidden items-center gap-2 text-xs text-gray-400 shrink-0">
                <span class="h-3.5 w-3.5 rounded-full border-2 border-apeiron-ink/30 border-t-apeiron-ink animate-spin"></span>
                Memuat data...
            </div>

            @if ($search !== '' || $statusFilter !== 'all' || $datePreset !== 'today')
                <button type="button" wire:click="resetFilters"
                    class="shrink-0 inline-flex items-center justify-center gap-1.5 min-h-[40px] px-3.5 rounded-xl border border-gray-200 bg-white text-xs font-bold text-gray-600 hover:text-rose-600 hover:border-rose-200 hover:bg-rose-50 transition-all active:scale-95 shadow-xs">
                    <span class="material-symbols-rounded text-[16px]">filter_alt_off</span> Reset Filter
                </button>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-gray-100">
            <span class="text-xs font-bold uppercase tracking-wider text-gray-500 mr-1">Periode:</span>
            @foreach ([
                'today' => 'Hari Ini',
                'yesterday' => 'Kemarin',
                'week' => 'Minggu Ini',
                'month' => 'Bulan Ini',
                'custom' => 'Kustom',
            ] as $value => $label)
                <button type="button" wire:click="$set('datePreset', '{{ $value }}')"
                    class="min-h-[36px] px-3.5 rounded-xl text-xs font-bold transition-all active:scale-95 {{ $datePreset === $value ? 'bg-apeiron-ink text-white shadow-xs' : 'bg-gray-100 text-gray-600 hover:bg-gray-200/70 hover:text-apeiron-ink' }}">
                    {{ $label }}
                </button>
            @endforeach

            @if ($datePreset === 'custom')
                <div class="flex items-center gap-2 ml-1">
                    <input type="date" wire:model.live="startDate"
                        class="min-h-[36px] px-3 rounded-xl border border-gray-200 bg-white text-xs text-apeiron-ink focus:outline-none focus:ring-1 focus:ring-gray-900 shadow-xs">
                    <span class="text-gray-400 text-xs font-semibold">s/d</span>
                    <input type="date" wire:model.live="endDate"
                        class="min-h-[36px] px-3 rounded-xl border border-gray-200 bg-white text-xs text-apeiron-ink focus:outline-none focus:ring-1 focus:ring-gray-900 shadow-xs">
                </div>
            @else
                <span class="text-xs font-medium text-gray-400 ml-1">({{ $this->dateRangeLabel }})</span>
            @endif
        </div>
    </div>

    {{-- ==================== TABEL RIWAYAT ==================== --}}
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col flex-1 min-h-0 overflow-hidden">
        {{-- Desktop Table --}}
        <div class="hidden lg:block overflow-auto flex-1">
            <x-data-table>
                <x-slot:head>
                    <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-left">Kode Pesanan</th>
                    <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-left">Kasir &amp; Tipe</th>
                    <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-left">Ringkasan Item</th>
                    <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-left">Pelanggan</th>
                    <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-left">Pembayaran</th>
                    <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-right">Total Tagihan</th>
                    <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-left">Status</th>
                    <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-right">Aksi</th>
                </x-slot:head>
                @forelse ($this->orders as $order)
                    @php
                        $stVal = $order->order_status->value ?? (string) $order->order_status;
                        $stVariant = match($stVal) {
                            'paid', 'completed' => 'success',
                            'pending' => 'warning',
                            'void', 'cancelled' => 'destructive',
                            default => 'secondary'
                        };
                    @endphp
                    <tr wire:key="order-{{ $order->id }}" class="hover:bg-gray-50/70 transition-colors">
                        <td class="px-6 py-4">
                            <div class="font-mono font-bold text-apeiron-ink text-xs">{{ $order->order_code }}</div>
                            <div class="text-[11px] text-gray-400 mt-0.5">{{ $order->created_at->format('d M Y, H:i') }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-xs font-bold text-apeiron-ink">{{ $order->user->name ?? 'Self-Order' }}</div>
                            <div class="text-[11px] text-gray-500 mt-0.5 flex items-center gap-1">
                                <span class="material-symbols-rounded text-[13px]">
                                    @if ($order->order_type?->value === 'takeaway')
                                        shopping_bag
                                    @elseif($order->order_type?->value === 'delivery')
                                        local_shipping
                                    @elseif($order->order_type?->value === 'self_order')
                                        touch_app
                                    @else
                                        table_restaurant
                                    @endif
                                </span>
                                <span>
                                    @if ($order->order_type?->value === 'takeaway')
                                        Takeaway
                                    @elseif($order->order_type?->value === 'delivery')
                                        Delivery
                                    @elseif($order->order_type?->value === 'self_order')
                                        Self-Order
                                    @else
                                        {{ $order->table->table_name ?? 'Dine-In' }}
                                    @endif
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-xs text-gray-700 max-w-[200px] truncate font-medium">
                                {{ $order->orderItems->pluck('product.product_name')->filter()->take(2)->implode(', ') }}
                                @if ($order->order_items_count > 2)
                                    <span class="text-gray-400">+{{ $order->order_items_count - 2 }} lainnya</span>
                                @endif
                            </div>
                            <div class="text-[11px] text-gray-400 mt-0.5">{{ $order->order_items_count }} item</div>
                        </td>
                        <td class="px-6 py-4 text-xs font-medium text-gray-600">
                            {{ $order->customer->name ?? '—' }}
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-gray-700 bg-gray-100 border border-gray-200 rounded-lg px-2 py-0.5 whitespace-nowrap">
                                <span class="material-symbols-rounded text-[13px]">{{ $order->payment_method?->value === 'cash' ? 'payments' : 'qr_code_2' }}</span>
                                {{ $order->payment_method?->label() ?? '-' }}
                            </span>
                            @if ($order->payments->count() > 1)
                                <span class="block text-[10px] font-bold text-blue-600 mt-0.5 uppercase tracking-wider">
                                    Split {{ $order->payments->count() }} Metode
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-right">
                            <span class="text-xs font-black text-apeiron-ink tabular-nums">
                                Rp {{ number_format($order->order_amount, 0, ',', '.') }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <x-ui.badge :variant="$stVariant">
                                {{ $order->order_status->label() }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button type="button" wire:click="viewDetail({{ $order->id }})"
                                    x-on:click="window.erpMotion?.pulseHighlight($el)"
                                    title="Lihat Detail" aria-label="Lihat Detail Pesanan"
                                    class="size-8 flex items-center justify-center rounded-lg text-gray-500 hover:text-apeiron-ink hover:bg-gray-100 transition-colors active:scale-90">
                                    <span class="material-symbols-rounded text-[18px]">visibility</span>
                                </button>
                                @if (in_array($stVal, ['paid', 'completed'], true))
                                    <a href="{{ route('transaction.receipt', $order->order_code) }}"
                                        target="_blank" rel="noopener" title="Cetak Ulang Struk" aria-label="Cetak Ulang Struk"
                                        x-on:click="window.erpMotion?.pulseHighlight($el)"
                                        class="size-8 flex items-center justify-center rounded-lg text-gray-500 hover:text-apeiron-ink hover:bg-gray-100 transition-colors active:scale-90">
                                        <span class="material-symbols-rounded text-[18px]">print</span>
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-14 text-center text-gray-400 text-xs">
                            <div class="flex flex-col items-center justify-center">
                                <div class="w-12 h-12 rounded-2xl bg-gray-50 flex items-center justify-center mb-2 border border-gray-100">
                                    <span class="material-symbols-rounded text-[24px] text-gray-400">receipt_long</span>
                                </div>
                                <p class="font-bold text-gray-600">Tidak ada transaksi ditemukan.</p>
                                <p class="text-gray-400 mt-0.5">Coba sesuaikan filter status atau periode pencarian.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </x-data-table>
        </div>

        {{-- Mobile Cards --}}
        <div class="lg:hidden flex flex-col gap-2.5 p-4 overflow-y-auto flex-1">
            @forelse ($this->orders as $order)
                @php
                    $stVal = $order->order_status->value ?? (string) $order->order_status;
                    $stVariant = match($stVal) {
                        'paid', 'completed' => 'success',
                        'pending' => 'warning',
                        'void', 'cancelled' => 'destructive',
                        default => 'secondary'
                    };
                @endphp
                <div wire:key="order-card-{{ $order->id }}"
                    class="p-4 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 transition-colors cursor-pointer shadow-xs"
                    wire:click="viewDetail({{ $order->id }})">
                    <div class="flex justify-between items-start">
                        <span class="font-mono font-bold text-xs text-apeiron-ink">{{ $order->order_code }}</span>
                        <x-ui.badge :variant="$stVariant">{{ $order->order_status->label() }}</x-ui.badge>
                    </div>
                    <div class="flex justify-between items-end mt-2.5 pt-2 border-t border-gray-100">
                        <div class="flex flex-col">
                            <span class="text-[11px] text-gray-400">{{ $order->created_at->format('d M, H:i') }}</span>
                            <span class="text-xs font-bold text-gray-700">{{ $order->user->name ?? 'Self-Order' }}</span>
                        </div>
                        <span class="font-black tabular-nums text-sm text-apeiron-ink">
                            Rp {{ number_format($order->order_amount, 0, ',', '.') }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center text-gray-400 text-xs flex flex-col items-center justify-center">
                    <div class="w-12 h-12 rounded-2xl bg-gray-50 flex items-center justify-center mb-2 border border-gray-100">
                        <span class="material-symbols-rounded text-[24px] text-gray-400">receipt_long</span>
                    </div>
                    <p class="font-bold text-gray-600">Tidak ada transaksi ditemukan.</p>
                </div>
            @endforelse
        </div>

        <div class="p-4 border-t border-gray-100 shrink-0">
            {{ $this->orders->links() }}
        </div>
    </div>

    {{-- ==================== MODAL DETAIL PESANAN ==================== --}}
    @if ($this->selectedOrder)
        @php $detail = $this->selectedOrder; @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 backdrop-blur-sm p-4 animate-fade-in"
            role="dialog" aria-modal="true" aria-labelledby="order-detail-title"
            wire:click.self="closeDetail">
            <div class="bg-white rounded-3xl w-full max-w-2xl max-h-[90vh] flex flex-col shadow-2xl border border-gray-200 overflow-hidden animate-slide-up-fade">

                <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-start shrink-0 bg-white">
                    <div>
                        <h3 id="order-detail-title" class="font-black text-lg text-apeiron-ink font-mono">{{ $detail->order_code }}</h3>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $detail->created_at->format('d F Y, H:i') }} WIB</p>
                    </div>
                    <button type="button" wire:click="closeDetail" aria-label="Tutup rincian pesanan"
                        class="size-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-apeiron-ink hover:bg-gray-100 transition-colors">
                        <span class="material-symbols-rounded text-[20px]">close</span>
                    </button>
                </div>

                <div class="p-6 overflow-y-auto flex-1 custom-scrollbar space-y-5 bg-white">
                    <div class="grid grid-cols-2 gap-4 text-xs">
                        <div>
                            <p class="font-bold uppercase tracking-wider text-gray-400 mb-1">Kasir</p>
                            <p class="font-bold text-apeiron-ink text-sm">{{ $detail->user->name ?? 'Self-Order' }}</p>
                        </div>
                        <div>
                            <p class="font-bold uppercase tracking-wider text-gray-400 mb-1">Tipe Pesanan</p>
                            <p class="font-bold text-apeiron-ink text-sm">
                                @if ($detail->order_type?->value === 'takeaway')
                                    Takeaway
                                @elseif($detail->order_type?->value === 'delivery')
                                    Delivery
                                @elseif($detail->order_type?->value === 'self_order')
                                    Self-Order
                                @else
                                    Dine-In &bull; {{ $detail->table->table_name ?? '-' }}
                                @endif
                            </p>
                        </div>
                        <div>
                            <p class="font-bold uppercase tracking-wider text-gray-400 mb-1">Status</p>
                            @php
                                $dStVal = $detail->order_status->value ?? (string) $detail->order_status;
                                $dStVariant = match($dStVal) {
                                    'paid', 'completed' => 'success',
                                    'pending' => 'warning',
                                    'void', 'cancelled' => 'destructive',
                                    default => 'secondary'
                                };
                            @endphp
                            <x-ui.badge :variant="$dStVariant">
                                {{ $detail->order_status->label() }}
                            </x-ui.badge>
                        </div>
                        @if ($detail->voided_at)
                            <div>
                                <p class="font-bold uppercase tracking-wider text-rose-600 mb-1">Dibatalkan (Void)</p>
                                <p class="font-bold text-rose-600 text-xs">oleh {{ $detail->voidedBy->name ?? '-' }} &bull; {{ $detail->voided_at->format('d M Y H:i') }}</p>
                                @if ($detail->void_reason)
                                    <p class="text-xs text-gray-500 italic mt-0.5">"{{ $detail->void_reason }}"</p>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($detail->customer)
                        <div class="bg-gray-50 rounded-xl p-3.5 border border-gray-200">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1.5">Pelanggan</p>
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-bold text-xs text-apeiron-ink truncate">{{ $detail->customer->name }}</p>
                                    <p class="text-[11px] text-gray-500">{{ $detail->customer->phone ?? '-' }}</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Item Pesanan</p>
                        <ul class="divide-y divide-gray-100 border border-gray-200 rounded-xl overflow-hidden text-xs">
                            @foreach ($detail->orderItems as $item)
                                @php
                                    $returnedQty = $detail->orderReturns->flatMap->items->where('order_item_id', $item->id)->sum('quantity_returned');
                                @endphp
                                <li class="p-3 flex justify-between items-start gap-3 bg-white">
                                    <div class="min-w-0">
                                        <p class="font-bold text-apeiron-ink">
                                            {{ $item->qty }}&times; {{ $item->product->product_name ?? 'Produk Dihapus' }}
                                            @if ($returnedQty > 0)
                                                <x-ui.badge variant="warning" class="ml-1 text-[10px]">
                                                    Diretur {{ $returnedQty }}x
                                                </x-ui.badge>
                                            @endif
                                        </p>
                                        @if (!empty($item->options))
                                            <p class="text-[11px] text-gray-500 mt-0.5">
                                                {{ collect($item->options)->pluck('name')->filter()->implode(', ') }}
                                            </p>
                                        @endif
                                        @if ($item->notes)
                                            <p class="text-[11px] italic text-gray-400 mt-0.5">Catatan: {{ $item->notes }}</p>
                                        @endif
                                    </div>
                                    <span class="font-black text-apeiron-ink tabular-nums shrink-0">
                                        Rp {{ number_format($item->order_subtotal, 0, ',', '.') }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    {{-- Summary Total --}}
                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-200 text-xs space-y-1.5">
                        <div class="flex justify-between text-gray-600"><span>Subtotal</span><span class="tabular-nums font-semibold">Rp {{ number_format($detail->subtotal_amount, 0, ',', '.') }}</span></div>
                        @if ($detail->discount_amount > 0)
                            <div class="flex justify-between text-rose-600 font-semibold"><span>Diskon</span><span class="tabular-nums">- Rp {{ number_format($detail->discount_amount, 0, ',', '.') }}</span></div>
                        @endif
                        <div class="flex justify-between text-gray-600"><span>Pajak</span><span class="tabular-nums font-semibold">Rp {{ number_format($detail->tax_amount, 0, ',', '.') }}</span></div>
                        @if ($detail->service_charge_amount > 0)
                            <div class="flex justify-between text-gray-600"><span>Service Charge</span><span class="tabular-nums font-semibold">Rp {{ number_format($detail->service_charge_amount, 0, ',', '.') }}</span></div>
                        @endif
                        <div class="flex justify-between font-black text-apeiron-ink text-sm pt-2 mt-1 border-t border-gray-200">
                            <span>Total Tagihan</span><span class="tabular-nums">Rp {{ number_format($detail->order_amount, 0, ',', '.') }}</span>
                        </div>
                    </div>
                </div>

                <div class="p-4 border-t border-gray-100 bg-white shrink-0 flex flex-wrap gap-2 justify-end">
                    <button type="button" wire:click="closeDetail"
                        class="min-h-[40px] px-4 rounded-xl border border-gray-200 bg-white text-xs font-semibold text-gray-600 hover:bg-gray-50 transition-all active:scale-95">
                        Tutup
                    </button>
                    @if (in_array($detail->order_status->value, ['paid', 'completed'], true))
                        <button type="button" wire:click="openReturnModal({{ $detail->id }})"
                            x-on:click="window.erpMotion?.pulseHighlight($el)"
                            class="min-h-[40px] px-4 inline-flex items-center justify-center gap-1.5 rounded-xl bg-amber-600 text-white text-xs font-bold hover:bg-amber-700 transition-all active:scale-95 shadow-xs">
                            <span class="material-symbols-rounded text-[16px]">keyboard_return</span> Retur &amp; Refund
                        </button>
                    @endif
                    @if ($detail->order_status->value === 'paid' && auth()->user()->can('void', $detail))
                        <button type="button" wire:click="$set('voidingOrderId', {{ $detail->id }})"
                            x-on:click="window.erpMotion?.pulseHighlight($el)"
                            class="min-h-[40px] px-4 inline-flex items-center justify-center gap-1.5 rounded-xl bg-rose-600 text-white text-xs font-bold hover:bg-rose-700 transition-all active:scale-95 shadow-xs">
                            <span class="material-symbols-rounded text-[16px]">cancel</span> Batalkan (Void)
                        </button>
                    @endif
                    @if (in_array($detail->order_status->value, ['paid', 'completed'], true))
                        <button type="button" wire:click="requestReprint({{ $detail->id }})"
                            x-on:click="window.erpMotion?.pulseHighlight($el)"
                            class="min-h-[40px] px-4 inline-flex items-center justify-center gap-1.5 rounded-xl bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] transition-all active:scale-95 shadow-xs">
                            <span class="material-symbols-rounded text-[16px]">print</span>
                            <span>{{ (($detail->print_count ?? 0) > 0) ? 'Cetak Ulang (#' . (($detail->print_count ?? 0) + 1) . ')' : 'Cetak Struk' }}</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ==================== MODAL VOID PESANAN ==================== --}}
    @if ($this->voidingOrderId)
        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/40 backdrop-blur-sm p-4 animate-fade-in"
            role="alertdialog" aria-modal="true" aria-labelledby="void-modal-title"
            @keydown.escape.stop.prevent="$wire.set('voidingOrderId', null)">
            <div class="bg-white rounded-3xl w-full max-w-md p-6 shadow-2xl border border-gray-200 animate-slide-up-fade">
                <div class="flex items-center gap-2 text-rose-600 mb-2">
                    <span class="material-symbols-rounded text-[24px]">cancel</span>
                    <h3 id="void-modal-title" class="font-bold text-base">Batalkan (Void) Pesanan</h3>
                </div>
                <p class="text-xs text-gray-500 mb-4">
                    Aksi ini akan mengubah status pesanan menjadi Void dan mengembalikan stok secara otomatis. Memerlukan alasan pembatalan dan verifikasi PIN Supervisor.
                </p>

                <form wire:submit.prevent="voidOrder" class="space-y-4">
                    <div>
                        <label for="void-reason-input" class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Alasan Pembatalan</label>
                        <textarea id="void-reason-input" wire:model="voidReason" rows="3" required minlength="5"
                            placeholder="Alasan pembatalan (minimal 5 karakter)..."
                            class="w-full rounded-xl border border-gray-200 p-3 text-xs focus:ring-1 focus:ring-rose-500 focus:border-rose-500 outline-none resize-none shadow-xs"></textarea>
                        @error('voidReason') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="void-pin-input" class="block text-xs font-bold uppercase tracking-wider text-rose-600 mb-1.5">PIN Supervisor / Manager</label>
                        <input type="password" id="void-pin-input" wire:model="supervisorPin" required maxlength="6"
                            placeholder="PIN Supervisor (6 digit)"
                            class="w-full rounded-xl border border-rose-200 bg-rose-50/40 px-3 py-2 text-center font-mono font-bold text-sm tracking-widest text-rose-900 focus:ring-1 focus:ring-rose-500 outline-none shadow-xs">
                        @error('supervisorPin') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-gray-100">
                        <button type="button" wire:click="$set('voidingOrderId', null)"
                            class="px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                            Batal
                        </button>
                        <button type="submit"
                            wire:loading.attr="disabled" wire:target="voidOrder"
                            x-on:click="window.erpMotion?.pulseHighlight($el)"
                            class="px-4 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold hover:bg-rose-700 shadow-sm disabled:opacity-50 flex items-center gap-2">
                            <span wire:loading wire:target="voidOrder" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                            <span wire:loading.remove wire:target="voidOrder">Konfirmasi Void</span>
                            <span wire:loading wire:target="voidOrder">Memproses...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ==================== MODAL RETUR & REFUND ==================== --}}
    @if ($showReturnModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/40 backdrop-blur-sm p-4 overflow-y-auto animate-fade-in"
            role="dialog" aria-modal="true" aria-labelledby="return-modal-title">
            <div class="bg-white rounded-3xl w-full max-w-2xl max-h-[90vh] flex flex-col shadow-2xl border border-gray-200 my-auto overflow-hidden animate-slide-up-fade">
                <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center shrink-0 bg-white">
                    <div>
                        <h3 id="return-modal-title" class="font-bold text-base text-apeiron-ink flex items-center gap-2">
                            <span class="material-symbols-rounded text-amber-600">keyboard_return</span> Pengajuan Retur &amp; Refund
                        </h3>
                        <p class="text-xs text-gray-500">Pilih item yang diretur, disposisi inventori, dan metode pengembalian dana</p>
                    </div>
                    <button type="button" wire:click="closeReturnModal" class="size-8 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100">
                        <span class="material-symbols-rounded text-[20px]">close</span>
                    </button>
                </div>

                <div class="p-6 overflow-y-auto flex-1 space-y-4">
                    @if ($returnErrorMessage)
                        <div class="p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold">
                            {{ $returnErrorMessage }}
                        </div>
                    @endif

                    {{-- Item selection table --}}
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">1. Pilih Item &amp; Disposisi</h4>
                        <div class="space-y-2.5">
                            @foreach ($returnItems as $id => $item)
                                <div class="p-3 rounded-xl border border-gray-200 bg-gray-50/50 space-y-2">
                                    <div class="flex items-start justify-between gap-3">
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" wire:model.live="returnItems.{{ $id }}.selected"
                                                class="rounded border-gray-300 text-apeiron-ink focus:ring-apeiron-ink">
                                            <span class="text-xs font-bold text-apeiron-ink">{{ $item['product_name'] }}</span>
                                        </label>
                                        <span class="text-xs text-gray-500">Sisa: <strong class="text-gray-800">{{ $item['remaining_qty'] }}</strong> pcs</span>
                                    </div>

                                    @if (!empty($item['selected']))
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 pt-2 border-t border-gray-200 text-xs">
                                            <div>
                                                <label class="block text-[11px] font-bold text-gray-500 mb-1">Jumlah Retur</label>
                                                <input type="number" wire:model.live="returnItems.{{ $id }}.qty"
                                                    min="1" max="{{ $item['remaining_qty'] }}"
                                                    class="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs font-bold">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-bold text-gray-500 mb-1">Disposisi Inventori</label>
                                                <select wire:model.live="returnItems.{{ $id }}.disposition"
                                                    class="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs font-bold">
                                                    @if (empty($item['is_recipe_product']))
                                                        <option value="restock">Restock (Kembali Layak Jual)</option>
                                                    @endif
                                                    <option value="quarantine">Karantina (Pemeriksaan)</option>
                                                    <option value="write_off_waste">Write-Off (Buang/Rusak)</option>
                                                </select>
                                                @if (!empty($item['is_recipe_product']))
                                                    <span class="text-[10px] text-amber-700 block mt-0.5 font-medium">*Resep F&B: Larangan Restock</span>
                                                @endif
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-bold text-gray-500 mb-1">Alasan Disposisi</label>
                                                <input type="text" wire:model="returnItems.{{ $id }}.disposition_reason"
                                                    placeholder="Opsional (cth: basi, salah saji)"
                                                    class="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs">
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Refund Allocations --}}
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">2. Alokasi Pengembalian Dana (Refund)</h4>
                        <div class="space-y-2.5">
                            @foreach ($refundAllocations as $pId => $alloc)
                                <div class="p-3 rounded-xl border border-gray-200 bg-gray-50/50 space-y-2 text-xs">
                                    <div class="flex justify-between items-center">
                                        <span class="font-bold text-gray-700">Pembayaran #{{ $pId }} (Metode Asal: <strong class="uppercase">{{ $alloc['orig_method'] }}</strong>)</span>
                                        <span class="text-gray-500">Maksimal: <strong class="text-gray-800">Rp {{ number_format($alloc['max_amount'], 0, ',', '.') }}</strong></span>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1">
                                        <div>
                                            <label class="block text-[11px] font-bold text-gray-500 mb-1">Nominal Refund (Rp)</label>
                                            <input type="number" wire:model.live="refundAllocations.{{ $pId }}.amount"
                                                min="1" max="{{ $alloc['max_amount'] }}"
                                                class="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs font-bold">
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-bold text-gray-500 mb-1">Metode Pengembalian</label>
                                            <select wire:model.live="refundAllocations.{{ $pId }}.refund_method"
                                                class="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs font-bold">
                                                <option value="{{ $alloc['orig_method'] }}">Asal ({{ ucfirst($alloc['orig_method']) }})</option>
                                                @if ($alloc['orig_method'] !== 'cash')
                                                    <option value="cash">Tunai (Cash Refund Exception)</option>
                                                @endif
                                            </select>
                                        </div>
                                    </div>
                                    @if ($alloc['refund_method'] === 'cash' && $alloc['orig_method'] !== 'cash')
                                        <div>
                                            <label class="block text-[11px] text-rose-600 font-bold mb-1">Alasan Pengecualian Refund Tunai *</label>
                                            <input type="text" wire:model="refundAllocations.{{ $pId }}.method_override_reason"
                                                placeholder="Wajib diisi (cth: EDC offline, permintaan darurat pelanggan)"
                                                class="w-full rounded-lg border border-rose-300 px-2 py-1.5 text-xs">
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Form Footer Inputs --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-gray-200">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Alasan Retur / Refund *</label>
                            <input type="text" wire:model="returnReason" placeholder="Minimal 5 karakter..."
                                class="w-full rounded-xl border border-gray-200 px-3 py-2 text-xs shadow-xs">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-rose-600 mb-1">PIN Supervisor / Manager *</label>
                            <input type="password" wire:model="returnSupervisorPin" maxlength="6" placeholder="PIN Otorisasi (6 digit)"
                                class="w-full rounded-xl border border-rose-200 bg-rose-50/40 px-3 py-2 text-xs text-center font-mono font-bold tracking-widest text-rose-900 shadow-xs">
                        </div>
                    </div>
                </div>

                <div class="p-4 border-t border-gray-100 bg-white shrink-0 flex justify-end gap-2">
                    <button type="button" wire:click="closeReturnModal"
                        class="px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                        Batal
                    </button>
                    <button type="button" wire:click="processReturn"
                        wire:loading.attr="disabled" wire:target="processReturn"
                        x-on:click="window.erpMotion?.pulseHighlight($el)"
                        class="px-5 py-2 rounded-xl bg-amber-600 text-white text-xs font-bold hover:bg-amber-700 shadow-sm disabled:opacity-50 flex items-center gap-2">
                        <span wire:loading wire:target="processReturn" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                        <span wire:loading.remove wire:target="processReturn">Proses Retur &amp; Refund</span>
                        <span wire:loading wire:target="processReturn">Memproses...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ==================== MODAL REPRINT STRUK ==================== --}}
    @if ($showReprintModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/40 backdrop-blur-sm p-4 animate-fade-in"
            role="dialog" aria-modal="true" aria-labelledby="reprint-modal-title">
            <div class="bg-white rounded-3xl w-full max-w-md p-6 shadow-2xl border border-gray-200 animate-slide-up-fade">
                <div class="flex items-center gap-2 text-apeiron-ink mb-1">
                    <span class="material-symbols-rounded text-[22px]">print</span>
                    <h3 id="reprint-modal-title" class="font-bold text-base">
                        Cetak Ulang Struk (#{{ $reprintOrderPrintCount + 1 }})
                    </h3>
                </div>
                <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900 mb-4">
                    Struk ini sudah dicetak <strong>{{ $reprintOrderPrintCount }} kali</strong>. Struk berikutnya akan diberi tanda watermark <strong>*** COPY / CETAK ULANG #{{ $reprintOrderPrintCount + 1 }} ***</strong> dan tercatat di audit log.
                </div>

                <div class="space-y-3 mb-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Alasan Cetak Ulang *</label>
                        <input type="text" wire:model="reprintReason" placeholder="Cth: Kertas macet, permintaan kasir/pelanggan..."
                            class="w-full rounded-xl border border-gray-200 px-3 py-2 text-xs shadow-xs">
                        @error('reprintReason') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    @if ($reprintOrderPrintCount >= 2)
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-rose-600 mb-1">PIN Supervisor *</label>
                            <input type="password" wire:model="reprintSupervisorPin" maxlength="6" placeholder="PIN Supervisor untuk cetak ulang &gt;2x"
                                class="w-full rounded-xl border border-rose-200 bg-rose-50/40 px-3 py-2 text-center font-mono font-bold text-xs tracking-widest text-rose-900 shadow-xs">
                            @error('reprintSupervisorPin') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    @endif
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-gray-100">
                    <button type="button" wire:click="closeReprintModal"
                        class="px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                        Batal
                    </button>
                    <button type="button" wire:click="confirmReprint"
                        wire:loading.attr="disabled" wire:target="confirmReprint"
                        x-on:click="window.erpMotion?.pulseHighlight($el)"
                        class="px-5 py-2 rounded-xl bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] shadow-sm disabled:opacity-50 flex items-center gap-2">
                        <span wire:loading wire:target="confirmReprint" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                        <span wire:loading.remove wire:target="confirmReprint">Cetak Sekarang</span>
                        <span wire:loading wire:target="confirmReprint">Memproses...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
