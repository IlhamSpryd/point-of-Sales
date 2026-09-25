<div x-data @keydown.escape.window="$wire.closeDetail()">

    {{-- ==================== RINGKASAN ==================== --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-6">
        <div class="card-surface p-6">
            <div class="flex justify-between items-start mb-3">
                <span class="text-sm font-semibold text-[#787774]">Total Transaksi (Sesuai Filter)</span>
                <span class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center">
                    <span class="material-symbols-rounded text-[18px] text-blue-600">receipt_long</span>
                </span>
            </div>
            <h3 class="text-2xl font-extrabold text-yovel-ink tracking-tight">
                {{ number_format($this->summary->total_count) }}</h3>
        </div>
        <div class="card-surface p-6">
            <div class="flex justify-between items-start mb-3">
                <span class="text-sm font-semibold text-[#787774]">Total Nilai Transaksi Lunas</span>
                <span class="w-9 h-9 rounded-xl bg-emerald-50 flex items-center justify-center">
                    <span class="material-symbols-rounded text-[18px] text-emerald-600">payments</span>
                </span>
            </div>
            <h3 class="text-2xl font-extrabold text-yovel-ink tracking-tight">Rp
                {{ number_format($this->summary->total_paid_amount, 0, ',', '.') }}</h3>
        </div>
    </div>

    {{-- ==================== TOOLBAR FILTER ==================== --}}
    <div class="card-surface p-4 lg:p-5 mb-6 flex flex-col gap-4">
        <div class="flex flex-col lg:flex-row gap-3 lg:items-center lg:justify-between">
            <div class="w-full lg:max-w-xs">
                <x-search-input wire:model.live.debounce.400ms="search" placeholder="Cari kode pesanan." />
            </div>

            <div class="relative w-full lg:w-64">
                <select wire:model.live="statusFilter"
                    class="w-full min-h-[44px] px-4 pr-10 rounded-xl border border-[#E9E9E7] bg-white text-sm text-yovel-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-yovel-ink transition-all duration-200 shadow-sm cursor-pointer">
                    <option value="all">Semua Status</option>
                    @foreach ($this->statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-[#9B9A97]">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>

            <div wire:loading.flex wire:target="search,statusFilter,datePreset,startDate,endDate"
                class="hidden items-center gap-2 text-xs text-[#9B9A97] shrink-0">
                <span
                    class="h-3.5 w-3.5 rounded-full border-2 border-yovel-ink/30 border-t-[#37352F] animate-spin"></span>
                Memuat...
            </div>

            @if ($search !== '' || $statusFilter !== 'all' || $datePreset !== 'today')
                <button type="button" wire:click="resetFilters"
                    class="shrink-0 inline-flex items-center justify-center gap-1.5 min-h-[44px] px-4 rounded-xl border border-[#E9E9E7] bg-white text-sm font-semibold text-[#787774] hover:text-rose-600 hover:border-rose-200 hover:bg-rose-50 transition-all duration-200 active:scale-95">
                    <span class="material-symbols-rounded text-[18px]">filter_alt_off</span> Reset Filter
                </button>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-[#E9E9E7]">
            <span class="text-xs font-bold uppercase tracking-wider text-[#9B9A97] mr-1">Periode:</span>
            @foreach ([
        'today' => 'Hari Ini',
        'yesterday' => 'Kemarin',
        'week' => 'Minggu Ini',
        'month' => 'Bulan Ini',
        'custom' => 'Kustom',
    ] as $value => $label)
                <button type="button" wire:click="$set('datePreset', '{{ $value }}')"
                    class="min-h-[44px] px-4 rounded-xl text-sm font-semibold transition-all duration-200 active:scale-95 {{ $datePreset === $value ? 'bg-yovel-ink text-white shadow-sm' : 'bg-[#F7F7F5] text-[#787774] hover:bg-[#F1F1EF] hover:text-yovel-ink' }}">
                    {{ $label }}
                </button>
            @endforeach

            @if ($datePreset === 'custom')
                <div class="flex items-center gap-2 ml-1">
                    <input type="date" wire:model.live="startDate"
                        class="min-h-[44px] px-3 rounded-xl border border-[#E9E9E7] bg-white text-sm text-yovel-ink focus:outline-none focus:ring-2 focus:ring-[#37352F] shadow-sm">
                    <span class="text-[#9B9A97] text-sm">s/d</span>
                    <input type="date" wire:model.live="endDate"
                        class="min-h-[44px] px-3 rounded-xl border border-[#E9E9E7] bg-white text-sm text-yovel-ink focus:outline-none focus:ring-2 focus:ring-[#37352F] shadow-sm">
                </div>
            @else
                <span class="text-xs font-medium text-[#9B9A97] ml-1">({{ $this->dateRangeLabel }})</span>
            @endif
        </div>
    </div>

    {{-- ==================== TABEL RIWAYAT ==================== --}}
    <div class="card-surface flex flex-col flex-1 min-h-0">
        {{-- Desktop/tablet-landscape: existing table --}}
        <div class="hidden lg:block overflow-auto flex-1 table-scroll-shadow">
            <table class="data-table relative">
                <thead class="sticky top-0 z-10 shadow-sm">
                    <tr class="bg-[#F7F7F5]">
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Kode Pesanan
                        </th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Kasir &amp;
                            Tipe</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Item</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Pelanggan
                        </th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Pembayaran
                        </th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">
                            Total Tagihan</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">
                            Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E9E9E7]">
                    @forelse ($this->orders as $order)
                        <tr wire:key="order-{{ $order->id }}"
                            class="hover:bg-[#F7F7F5] transition-colors duration-200">
                            <td class="px-6 py-4">
                                <div class="font-mono font-bold text-yovel-ink text-sm">{{ $order->order_code }}</div>
                                <div class="text-xs text-[#9B9A97] mt-0.5">
                                    {{ $order->created_at->format('d M Y, H:i') }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium text-yovel-ink">
                                    {{ $order->user->name ?? 'Self-Order' }}</div>
                                <div class="text-xs text-[#787774] mt-0.5 flex items-center gap-1">
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
                                    @if ($order->order_type?->value === 'takeaway')
                                        Takeaway
                                    @elseif($order->order_type?->value === 'delivery')
                                        Delivery
                                    @elseif($order->order_type?->value === 'self_order')
                                        Self-Order
                                    @else
                                        {{ $order->table->table_name ?? 'Dine-In' }}
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-yovel-ink max-w-[220px] truncate">
                                    {{ $order->orderItems->pluck('product.product_name')->filter()->take(2)->implode(', ') }}
                                    @if ($order->order_items_count > 2)
                                        <span class="text-[#9B9A97]">+{{ $order->order_items_count - 2 }}
                                            lainnya</span>
                                    @endif
                                </div>
                                <div class="text-xs text-[#9B9A97] mt-0.5">{{ $order->order_items_count }} item</div>
                            </td>
                            <td class="px-6 py-4 text-sm text-[#787774]">
                                {{ $order->customer->name ?? '—' }}
                            </td>
                            <td class="px-6 py-4">
                                <span
                                    class="inline-flex items-center gap-1 text-xs font-semibold text-yovel-ink bg-[#F1F1EF] border border-[#E9E9E7] rounded-lg px-2 py-1 whitespace-nowrap">
                                    <span
                                        class="material-symbols-rounded text-[13px]">{{ $order->payment_method?->value === 'cash' ? 'payments' : 'qr_code_2' }}</span>
                                    {{ $order->payment_method?->label() ?? '-' }}
                                </span>
                                @if ($order->payments->count() > 1)
                                    <span
                                        class="block text-[10px] font-bold text-blue-600 mt-1 uppercase tracking-wider">Split
                                        {{ $order->payments->count() }} Metode</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <span class="text-sm font-bold text-yovel-ink tabular-nums">Rp
                                    {{ number_format($order->order_amount, 0, ',', '.') }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <x-badge :type="$this->statusBadgeType($order->order_status)">
                                    {{ $order->order_status->label() }}
                                </x-badge>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" wire:click="viewDetail({{ $order->id }})"
                                        title="Lihat Detail"
                                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg text-[#9B9A97] hover:text-yovel-ink hover:bg-[#F1F1EF] transition-colors duration-200 active:scale-90">
                                        <span class="material-symbols-rounded text-[20px]">visibility</span>
                                    </button>
                                    @if (in_array($order->order_status->value, ['paid', 'completed'], true))
                                        <a href="{{ route('transaction.receipt', $order->order_code) }}"
                                            target="_blank" rel="noopener" title="Cetak Ulang Struk"
                                            class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg text-[#9B9A97] hover:text-yovel-ink hover:bg-[#F1F1EF] transition-colors duration-200 active:scale-90">
                                            <span class="material-symbols-rounded text-[20px]">print</span>
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-16 text-center text-[#9B9A97] text-sm">
                                <div class="flex flex-col items-center justify-center">
                                    <div
                                        class="w-14 h-14 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                                        <span
                                            class="material-symbols-rounded text-[28px] text-[#C4C3C0]">receipt_long</span>
                                    </div>
                                    <p class="font-medium">Tidak ada transaksi ditemukan pada periode/filter ini.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile/tablet-portrait: card list --}}
        <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
            @forelse ($this->orders as $order)
                <div wire:key="order-card-{{ $order->id }}"
                    class="card-surface p-4 cursor-pointer hover:bg-[#F7F7F5] transition-colors duration-200"
                    wire:click="viewDetail({{ $order->id }})">
                    <div class="flex justify-between items-start">
                        <span class="font-mono font-bold text-sm text-yovel-ink">{{ $order->order_code }}</span>
                        <x-badge :type="$this->statusBadgeType($order->order_status)">{{ $order->order_status->label() }}</x-badge>
                    </div>
                    <div class="flex justify-between items-end mt-3">
                        <div class="flex flex-col gap-1">
                            <span class="text-xs text-yovel-muted">{{ $order->created_at->format('d M, H:i') }}</span>
                            <span
                                class="text-xs font-semibold text-yovel-ink">{{ $order->user->name ?? 'Self-Order' }}</span>
                        </div>
                        <span class="font-bold tabular-nums text-yovel-ink">Rp
                            {{ number_format($order->order_amount, 0, ',', '.') }}</span>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center text-[#9B9A97] text-sm flex flex-col items-center justify-center">
                    <div class="w-12 h-12 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                        <span class="material-symbols-rounded text-[24px] text-[#C4C3C0]">receipt_long</span>
                    </div>
                    <p class="font-medium">Tidak ada transaksi ditemukan.</p>
                </div>
            @endforelse
        </div>
        <div class="p-4 border-t border-yovel-border shrink-0">
            {{ $this->orders->links() }}
        </div>
    </div>

    {{-- ==================== MODAL DETAIL ==================== --}}
    @if ($this->selectedOrder)
        @php $detail = $this->selectedOrder; @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-yovel-ink/40 backdrop-blur-sm p-4"
            wire:click.self="closeDetail">
            <div
                class="bg-white rounded-2xl w-full max-w-2xl max-h-[90vh] flex flex-col shadow-2xl border border-[#E9E9E7]">

                <div class="px-6 py-4 border-b border-[#E9E9E7] flex justify-between items-start shrink-0">
                    <div>
                        <h3 class="font-bold text-lg text-yovel-ink font-mono">{{ $detail->order_code }}</h3>
                        <p class="text-sm text-[#787774] mt-0.5">{{ $detail->created_at->format('d F Y, H:i') }} WIB
                        </p>
                    </div>
                    <button type="button" wire:click="closeDetail"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-xl text-[#9B9A97] hover:text-yovel-ink hover:bg-[#F7F7F5] transition-all duration-200 active:scale-90">
                        <span class="material-symbols-rounded text-[22px]">close</span>
                    </button>
                </div>

                <div class="p-6 overflow-y-auto flex-1 custom-scrollbar space-y-6">
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-xs font-semibold text-[#9B9A97] uppercase tracking-wider mb-1">Kasir</p>
                            <p class="font-medium text-yovel-ink">{{ $detail->user->name ?? 'Self-Order' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-[#9B9A97] uppercase tracking-wider mb-1">Tipe Pesanan
                            </p>
                            <p class="font-medium text-yovel-ink">
                                @if ($detail->order_type?->value === 'takeaway')
                                    Takeaway
                                @elseif($detail->order_type?->value === 'delivery')
                                    Delivery
                                @elseif($detail->order_type?->value === 'self_order')
                                    Self-Order
                                @else
                                    Dine-In · {{ $detail->table->table_name ?? '-' }}
                                @endif
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-[#9B9A97] uppercase tracking-wider mb-1">Status</p>
                            <x-badge :type="$this->statusBadgeType($detail->order_status)">
                                {{ $detail->order_status->label() }}
                            </x-badge>
                        </div>
                        @if ($detail->voided_at)
                            <div>
                                <p class="text-xs font-semibold text-rose-500 uppercase tracking-wider mb-1">Dibatalkan
                                    (Void)</p>
                                <p class="font-medium text-rose-600 text-xs">oleh {{ $detail->voidedBy->name ?? '-' }}
                                    · {{ $detail->voided_at->format('d M Y H:i') }}</p>
                                @if ($detail->void_reason)
                                    <p class="text-xs text-[#787774] italic mt-0.5">"{{ $detail->void_reason }}"</p>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($detail->customer)
                        <div class="bg-[#F7F7F5] rounded-xl p-4 border border-[#E9E9E7]">
                            <p class="text-xs font-bold text-[#9B9A97] uppercase tracking-wider mb-2">Pelanggan &amp;
                                Loyalty</p>
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold text-sm text-yovel-ink truncate">
                                        {{ $detail->customer->name }}</p>
                                    <p class="text-xs text-[#787774]">{{ $detail->customer->phone ?? '-' }}</p>
                                </div>
                                @if ($detail->customer->loyaltyAccount)
                                    <div class="text-right shrink-0">
                                        <p class="text-xs font-semibold text-[#787774]">
                                            {{ $detail->customer->loyaltyAccount->currentTier->name ?? 'Reguler' }}</p>
                                        <p class="text-sm font-bold text-yovel-ink">
                                            {{ number_format($detail->customer->loyaltyAccount->current_points ?? 0) }}
                                            poin</p>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div>
                        <p class="text-xs font-bold text-[#9B9A97] uppercase tracking-wider mb-2">Item Pesanan</p>
                        <ul class="divide-y divide-[#E9E9E7] border border-[#E9E9E7] rounded-xl overflow-hidden">
                            @foreach ($detail->orderItems as $item)
                                <li class="p-3 flex justify-between items-start gap-3 bg-white">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-yovel-ink">{{ $item->qty }}×
                                            {{ $item->product->product_name ?? 'Produk Dihapus' }}</p>
                                        @if (!empty($item->options))
                                            <p class="text-xs text-[#787774] mt-0.5">
                                                {{ collect($item->options)->pluck('name')->filter()->implode(', ') }}
                                            </p>
                                        @endif
                                        @if ($item->notes)
                                            <p class="text-xs italic text-[#9B9A97] mt-0.5">Catatan:
                                                {{ $item->notes }}</p>
                                        @endif
                                    </div>
                                    <span class="text-sm font-bold text-yovel-ink tabular-nums shrink-0">Rp
                                        {{ number_format($item->order_subtotal, 0, ',', '.') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div>
                        <p class="text-xs font-bold text-[#9B9A97] uppercase tracking-wider mb-2">Rincian Pembayaran
                        </p>
                        @if ($detail->payments->isNotEmpty())
                            <ul class="space-y-2">
                                @foreach ($detail->payments as $payment)
                                    <li
                                        class="flex items-center justify-between bg-white border border-[#E9E9E7] rounded-xl px-4 py-3 gap-3">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <span
                                                class="material-symbols-rounded text-[18px] text-[#787774] shrink-0">{{ $payment->payment_method->value === 'cash' ? 'payments' : 'credit_card' }}</span>
                                            <div class="min-w-0">
                                                <p class="text-sm font-semibold text-yovel-ink">
                                                    {{ $payment->payment_method->label() }}</p>
                                                @if ($payment->reference_number)
                                                    <p class="text-[11px] text-[#9B9A97] font-mono truncate">Ref:
                                                        {{ $payment->reference_number }}</p>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <p class="text-sm font-bold text-yovel-ink tabular-nums">Rp
                                                {{ number_format((float) $payment->amount, 0, ',', '.') }}</p>
                                            <p
                                                class="text-[11px] font-medium {{ $payment->status->value === 'captured' ? 'text-emerald-600' : 'text-[#9B9A97]' }}">
                                                {{ $payment->status->label() }}</p>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-sm text-[#9B9A97] italic">Belum ada catatan pembayaran granular untuk
                                pesanan ini.</p>
                        @endif
                    </div>

                    <div class="bg-[#F7F7F5] rounded-xl p-4 border border-[#E9E9E7] text-sm space-y-1.5">
                        <div class="flex justify-between text-[#787774]"><span>Subtotal</span><span
                                class="tabular-nums">Rp
                                {{ number_format($detail->subtotal_amount, 0, ',', '.') }}</span></div>
                        @if ($detail->discount_amount > 0)
                            <div class="flex justify-between text-rose-600"><span>Diskon</span><span
                                    class="tabular-nums">- Rp
                                    {{ number_format($detail->discount_amount, 0, ',', '.') }}</span></div>
                        @endif
                        <div class="flex justify-between text-[#787774]"><span>Pajak</span><span
                                class="tabular-nums">Rp {{ number_format($detail->tax_amount, 0, ',', '.') }}</span>
                        </div>
                        <div
                            class="flex justify-between font-bold text-yovel-ink text-base pt-2 mt-1 border-t border-[#E9E9E7]">
                            <span>Total Tagihan</span><span class="tabular-nums">Rp
                                {{ number_format($detail->order_amount, 0, ',', '.') }}</span></div>
                    </div>
                </div>

                <div class="p-4 border-t border-[#E9E9E7] bg-white shrink-0 flex gap-3">
                    <button type="button" wire:click="closeDetail"
                        class="flex-1 min-h-[44px] rounded-xl border border-[#E9E9E7] bg-white text-sm font-semibold text-[#787774] hover:bg-[#F7F7F5] transition-all duration-200 active:scale-95">
                        Tutup
                    </button>
                    @if (
                        $detail->order_status->value === 'paid' &&
                            (auth()->user()->role->hasPermission('can_void_order') ||
                                in_array(auth()->user()->role->name, ['Owner', 'Manager'])))
                        <button type="button" wire:click="$set('voidingOrderId', {{ $detail->id }})"
                            class="flex-1 min-h-[44px] inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 text-white text-sm font-bold hover:bg-rose-700 transition-all duration-200 active:scale-95 shadow-sm">
                            <span class="material-symbols-rounded text-[18px]">cancel</span> Batalkan (Void)
                        </button>
                    @endif
                    @if (in_array($detail->order_status->value, ['paid', 'completed'], true))
                        <a href="{{ route('transaction.receipt', $detail->order_code) }}" target="_blank"
                            rel="noopener"
                            class="flex-1 min-h-[44px] inline-flex items-center justify-center gap-2 rounded-xl bg-yovel-ink text-white text-sm font-bold hover:bg-yovel-ink transition-all duration-200 active:scale-95 shadow-sm">
                            <span class="material-symbols-rounded text-[18px]">print</span> Cetak Ulang Struk
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ==================== MODAL VOID ==================== --}}
    @if ($this->voidingOrderId)
        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-yovel-ink/40 backdrop-blur-sm p-4">
            <div class="bg-white rounded-2xl w-full max-w-md p-6 shadow-2xl border border-[#E9E9E7]">
                <h3 class="font-bold text-lg text-rose-600 mb-2">Batalkan (Void) Pesanan</h3>
                <p class="text-sm text-[#787774] mb-4">Aksi ini akan mengubah status pesanan menjadi Void dan
                    mengembalikan semua stok secara otomatis. Silakan tuliskan alasan pembatalan.</p>

                <form wire:submit.prevent="voidOrder">
                    <textarea wire:model="voidReason" rows="3" required minlength="5"
                        placeholder="Alasan pembatalan (min. 5 karakter)..."
                        class="w-full rounded-xl border border-[#E9E9E7] p-3 text-sm focus:ring-2 focus:ring-rose-500 focus:border-rose-500 mb-4 shadow-sm"></textarea>

                    <div class="flex justify-end gap-3">
                        <button type="button" wire:click="$set('voidingOrderId', null)"
                            class="px-4 py-2 rounded-xl border border-[#E9E9E7] text-sm font-semibold text-[#787774] hover:bg-[#F7F7F5]">
                            Kembali
                        </button>
                        <button type="submit"
                            wire:loading.attr="disabled" wire:target="voidOrder"
                            class="px-4 py-2 rounded-xl bg-rose-600 text-white text-sm font-bold hover:bg-rose-700 shadow-sm disabled:opacity-50 flex items-center gap-2">
                            <span wire:loading wire:target="voidOrder" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                            <span wire:loading.remove wire:target="voidOrder">Konfirmasi Void</span>
                            <span wire:loading wire:target="voidOrder">Memproses...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
