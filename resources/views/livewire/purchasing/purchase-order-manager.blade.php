<div class="space-y-6" data-gsap="page-enter">
    @include('purchasing.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Pesanan Pembelian (Purchase Orders)"
        description="Kelola siklus pesanan pengadaan barang ke vendor, pengajuan persetujuan anggaran, dan status penerimaan operasional.">
        <x-slot:actions>
            @can('create', \App\Modules\Purchasing\Models\PurchaseOrder::class)
            <x-ui.button wire:click="openCreateModal" icon="add" variant="primary">
                Buat Pesanan Baru
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Messages -->
    @if (session()->has('message'))
        <x-ui.alert variant="success" icon="check_circle">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="danger" icon="error">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
        <div class="flex-1 max-w-md">
            <x-ui.input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Cari nomor PO atau nama pemasok..."
                icon="search" />
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.select wire:model.live="supplierFilter">
                <option value="">Semua Pemasok</option>
                @foreach($suppliers as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select wire:model.live="statusFilter">
                <option value="">Semua Status</option>
                <option value="DRAFT">Draf</option>
                <option value="PENDING_APPROVAL">Menunggu Persetujuan</option>
                <option value="APPROVED">Disetujui</option>
                <option value="ISSUED">Diterbitkan (Issued)</option>
                <option value="PARTIALLY_RECEIVED">Diterima Parsial</option>
                <option value="COMPLETED">Selesai</option>
                <option value="CANCELLED">Dibatalkan</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-surface rounded-2xl overflow-hidden border border-[#E5E7EB] dark:border-zinc-800 shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-300">
                <thead class="bg-zinc-50 dark:bg-zinc-900/50 text-xs font-semibold uppercase text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-6 py-4">Nomor & Tanggal PO</th>
                        <th class="px-6 py-4">Pemasok</th>
                        <th class="px-6 py-4">Toko / Cabang</th>
                        <th class="px-6 py-4 text-right">Total Nilai</th>
                        <th class="px-6 py-4 text-center">Penerimaan (GRN)</th>
                        <th class="px-6 py-4 text-center">Faktur (3-Way)</th>
                        <th class="px-6 py-4 text-center">Status PO</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($purchaseOrders as $po)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40 transition-colors">
                        <td class="px-6 py-4">
                            <div class="font-bold text-zinc-900 dark:text-white font-mono">{{ $po->po_number }}</div>
                            <div class="text-xs text-zinc-500">{{ \Carbon\Carbon::parse($po->order_date)->format('d M Y') }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $po->supplier?->name ?? 'Vendor #' . $po->supplier_id }}</div>
                            <div class="text-xs text-zinc-400">{{ $po->supplier?->phone ?: $po->supplier?->email }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-zinc-700 dark:text-zinc-300 font-medium">{{ $po->store?->name ?? 'Semua Cabang' }}</span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="font-bold text-zinc-900 dark:text-white">Rp {{ number_format((float) $po->total_amount, 2) }}</div>
                            <div class="text-[11px] text-zinc-400">Pajak: Rp {{ number_format((float) $po->tax_amount, 2) }}</div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @php
                                $recVariant = match($po->receiving_status?->value ?? (string) $po->receiving_status) {
                                    'FULL', 'received' => 'success',
                                    'PARTIAL', 'partially_received' => 'warning',
                                    default => 'neutral'
                                };
                            @endphp
                            <x-ui.badge :variant="$recVariant">
                                {{ $po->receiving_status?->label() ?? $po->receiving_status ?? 'UNRECEIVED' }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @php
                                $invVariant = match($po->invoicing_status?->value ?? (string) $po->invoicing_status) {
                                    'FULL', 'invoiced' => 'success',
                                    'PARTIAL', 'partially_invoiced' => 'warning',
                                    default => 'neutral'
                                };
                            @endphp
                            <x-ui.badge :variant="$invVariant">
                                {{ $po->invoicing_status?->label() ?? $po->invoicing_status ?? 'UNINVOICED' }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @php
                                $poStatusVal = $po->status?->value ?? (string) $po->status;
                                $statusVariant = match($poStatusVal) {
                                    'APPROVED', 'approved' => 'info',
                                    'ISSUED', 'issued' => 'primary',
                                    'COMPLETED', 'completed' => 'success',
                                    'CANCELLED', 'cancelled' => 'danger',
                                    'PENDING_APPROVAL', 'pending_approval' => 'warning',
                                    default => 'neutral'
                                };
                            @endphp
                            <x-ui.badge :variant="$statusVariant">
                                {{ $po->status?->label() ?? $poStatusVal }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <x-ui.button
                                    size="icon"
                                    variant="ghost"
                                    wire:click="viewPo({{ $po->id }})"
                                    title="Lihat Detail PO"
                                    aria-label="Detail">
                                    <span class="material-symbols-rounded text-base text-zinc-600 dark:text-zinc-400">visibility</span>
                                </x-ui.button>
                                @if($po->isDraft())
                                    <x-ui.button
                                        size="icon"
                                        variant="ghost"
                                        wire:click="submitPo({{ $po->id }})"
                                        title="Ajukan Persetujuan"
                                        aria-label="Ajukan">
                                        <span class="material-symbols-rounded text-base text-amber-600">send</span>
                                    </x-ui.button>
                                @endif
                                @if($po->status?->value === 'APPROVED' || $po->status === 'approved')
                                    <x-ui.button
                                        size="icon"
                                        variant="ghost"
                                        wire:click="releasePo({{ $po->id }})"
                                        title="Rilis ke Pemasok (Issue)"
                                        aria-label="Rilis">
                                        <span class="material-symbols-rounded text-base text-emerald-600">check_circle</span>
                                    </x-ui.button>
                                @endif
                                @if($po->canBeCancelled())
                                    <x-ui.button
                                        size="icon"
                                        variant="ghost"
                                        wire:click="confirmCancelPo({{ $po->id }})"
                                        title="Batalkan PO"
                                        aria-label="Batal">
                                        <span class="material-symbols-rounded text-base text-rose-500">cancel</span>
                                    </x-ui.button>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center">
                                <span class="material-symbols-rounded text-5xl text-zinc-300 dark:text-zinc-700">receipt_long</span>
                                <h3 class="mt-2 text-sm font-semibold text-zinc-900 dark:text-white">Belum ada pesanan pembelian</h3>
                                <p class="mt-1 text-xs text-zinc-500">Buat pesanan pembelian baru untuk memulai proses pengadaan barang vendor.</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($purchaseOrders->hasPages())
        <div class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-800">
            {{ $purchaseOrders->links() }}
        </div>
        @endif
    </div>

    <!-- Modal Buat Draf PO Baru -->
    <x-ui.modal wire:model="showCreateModal" maxWidth="4xl">
        <x-slot:title>
            Buat Pesanan Pembelian Langsung (Direct PO)
        </x-slot:title>

        <x-slot:content>
            <form id="createPoForm" wire:submit="savePurchaseOrder" class="space-y-6">
                <!-- Header Info -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-800">
                    <div>
                        <x-ui.label for="supplierId">Pemasok *</x-ui.label>
                        <x-ui.select id="supplierId" wire:model.live="supplierId" required>
                            <option value="">-- Pilih Pemasok --</option>
                            @foreach($suppliers as $s)
                                <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->code }})</option>
                            @endforeach
                        </x-ui.select>
                        <x-input-error :messages="$errors->get('supplierId')" class="mt-1" />
                    </div>

                    <div>
                        <x-ui.label for="storeId">Cabang Tujuan *</x-ui.label>
                        <x-ui.select id="storeId" wire:model="storeId" required>
                            @foreach($stores as $st)
                                <option value="{{ $st->id }}">{{ $st->name }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-input-error :messages="$errors->get('storeId')" class="mt-1" />
                    </div>

                    <div>
                        <x-ui.label for="orderDate">Tanggal Pesanan *</x-ui.label>
                        <x-ui.input type="date" id="orderDate" wire:model="orderDate" required />
                        <x-input-error :messages="$errors->get('orderDate')" class="mt-1" />
                    </div>

                    <div>
                        <x-ui.label for="expectedDeliveryDate">Target Pengiriman</x-ui.label>
                        <x-ui.input type="date" id="expectedDeliveryDate" wire:model="expectedDeliveryDate" />
                        <x-input-error :messages="$errors->get('expectedDeliveryDate')" class="mt-1" />
                    </div>
                </div>

                <!-- Lines Table -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500">Daftar Item Pesanan</h4>
                        <x-ui.button type="button" size="sm" variant="secondary" wire:click="addItem" icon="add">
                            Tambah Baris
                        </x-ui.button>
                    </div>

                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded-xl">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-zinc-100 dark:bg-zinc-800/80 font-semibold text-zinc-600 dark:text-zinc-300">
                                <tr>
                                    <th class="px-3 py-2 w-28">Tipe</th>
                                    <th class="px-3 py-2">Item Master</th>
                                    <th class="px-3 py-2 w-24">Satuan</th>
                                    <th class="px-3 py-2 w-20">Konversi</th>
                                    <th class="px-3 py-2 w-24 text-right">Kuantitas</th>
                                    <th class="px-3 py-2 w-32 text-right">Harga Satuan</th>
                                    <th class="px-3 py-2 w-10 text-center"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach($items as $i => $item)
                                <tr>
                                    <td class="px-3 py-2">
                                        <select wire:model.live="items.{{ $i }}.purchasable_type" wire:change="onItemTypeChanged({{ $i }})" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1">
                                            <option value="product">Produk Ritel</option>
                                            <option value="ingredient">Bahan Baku</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <select wire:model.live="items.{{ $i }}.purchasable_id" wire:change="onItemSelected({{ $i }})" required class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1">
                                            <option value="">-- Pilih Item --</option>
                                            @if(($item['purchasable_type'] ?? 'product') === 'product')
                                                @foreach($products as $p)
                                                    <option value="{{ $p->id }}">{{ $p->product_name }}</option>
                                                @endforeach
                                            @else
                                                @foreach($ingredients as $ing)
                                                    <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>
                                                @endforeach
                                            @endif
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" wire:model="items.{{ $i }}.purchase_uom" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 uppercase" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.0001" wire:model="items.{{ $i }}.uom_conversion_factor" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.0001" min="0.0001" wire:model="items.{{ $i }}.ordered_qty" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 text-right font-medium" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.01" min="0" wire:model="items.{{ $i }}.unit_price" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 text-right font-medium" />
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <button type="button" wire:click="removeItem({{ $i }})" class="p-1 text-zinc-400 hover:text-rose-500">
                                            <span class="material-symbols-rounded text-sm">close</span>
                                        </button>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <x-ui.label for="notes">Catatan PO</x-ui.label>
                    <textarea id="notes" wire:model="notes" rows="2" placeholder="Keterangan pengiriman, kontak gudang, syarat pembayaran..." class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm"></textarea>
                </div>
            </form>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showCreateModal', false)">Batal</x-ui.button>
                <x-ui.button type="submit" form="createPoForm" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>Simpan Draf PO</span>
                    <span wire:loading>Menyimpan...</span>
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Detail PO -->
    <x-ui.modal wire:model="showDetailModal" maxWidth="3xl">
        <x-slot:title>
            @if($selectedPo)
                <div>
                    <span class="font-mono">{{ $selectedPo->po_number }}</span>
                    <span class="ml-2">
                        <x-ui.badge variant="primary">{{ $selectedPo->status?->label() ?? $selectedPo->status }}</x-ui.badge>
                    </span>
                </div>
            @else
                Detail Pesanan Pembelian
            @endif
        </x-slot:title>

        <x-slot:content>
            @if($selectedPo)
            <div class="space-y-6">
                <!-- Info Header -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-800 text-xs">
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Pemasok</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedPo->supplier?->name }}</div>
                        <div class="text-zinc-500 font-mono">{{ $selectedPo->supplier?->code }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Toko / Cabang</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedPo->store?->name }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Tanggal PO</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ \Carbon\Carbon::parse($selectedPo->order_date)->format('d M Y') }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Target Kirim</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">
                            {{ $selectedPo->expected_delivery_date ? \Carbon\Carbon::parse($selectedPo->expected_delivery_date)->format('d M Y') : '-' }}
                        </div>
                    </div>
                </div>

                <!-- Lines Table -->
                <div class="space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500">Rincian Baris Barang</h4>
                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded-xl">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-zinc-50 dark:bg-zinc-900/50 font-semibold text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <th class="px-4 py-2.5">Item</th>
                                    <th class="px-4 py-2.5">Satuan</th>
                                    <th class="px-4 py-2.5 text-right">Dipesan</th>
                                    <th class="px-4 py-2.5 text-right">Diterima</th>
                                    <th class="px-4 py-2.5 text-right">Harga Satuan</th>
                                    <th class="px-4 py-2.5 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach($selectedPo->lines as $line)
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <div class="font-medium text-zinc-800 dark:text-zinc-200">
                                            {{ $line->purchasable?->product_name ?? $line->purchasable?->name ?? 'Item #' . $line->purchasable_id }}
                                        </div>
                                        <div class="text-[10px] text-zinc-400 uppercase">{{ $line->purchasable_type }}</div>
                                    </td>
                                    <td class="px-4 py-2.5 font-mono uppercase">{{ $line->purchase_uom }}</td>
                                    <td class="px-4 py-2.5 text-right font-medium">{{ (float) $line->ordered_qty }}</td>
                                    <td class="px-4 py-2.5 text-right font-medium text-emerald-600">{{ (float) $line->received_qty }}</td>
                                    <td class="px-4 py-2.5 text-right">Rp {{ number_format((float) $line->unit_price, 2) }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold">Rp {{ number_format((float) $line->line_total, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-zinc-50 dark:bg-zinc-900/50 font-semibold border-t border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <td colspan="5" class="px-4 py-2 text-right text-zinc-500">Total Nilai PO:</td>
                                    <td class="px-4 py-2 text-right text-zinc-900 dark:text-white font-bold text-sm">
                                        Rp {{ number_format((float) $selectedPo->total_amount, 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                @if($selectedPo->notes)
                <div class="text-xs p-3 rounded-lg bg-zinc-50 dark:bg-zinc-900/40 text-zinc-600 dark:text-zinc-400 border border-zinc-200 dark:border-zinc-800">
                    <span class="font-bold">Catatan:</span> {{ $selectedPo->notes }}
                </div>
                @endif
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-between">
                <div>
                    @if($selectedPo && $selectedPo->canBeCancelled())
                        <x-ui.button type="button" variant="danger" wire:click="confirmCancelPo({{ $selectedPo->id }})">
                            Batalkan PO
                        </x-ui.button>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    @if($selectedPo && $selectedPo->isDraft())
                        <x-ui.button type="button" variant="primary" wire:click="submitPo({{ $selectedPo->id }})">
                            Ajukan Persetujuan
                        </x-ui.button>
                    @endif
                    @if($selectedPo && ($selectedPo->status?->value === 'APPROVED' || $selectedPo->status === 'approved'))
                        <x-ui.button type="button" variant="primary" wire:click="releasePo({{ $selectedPo->id }})">
                            Rilis ke Vendor (Issue)
                        </x-ui.button>
                    @endif
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showDetailModal', false)">Tutup</x-ui.button>
                </div>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Konfirmasi Batal PO -->
    <x-ui.modal wire:model="showCancelModal" maxWidth="md">
        <x-slot:title>
            Konfirmasi Pembatalan PO
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-3">
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Apakah Anda yakin ingin membatalkan Pesanan Pembelian ini? Aksi ini tidak dapat dibatalkan.
                </p>
                <div>
                    <x-ui.label for="cancelReason">Alasan Pembatalan</x-ui.label>
                    <x-ui.input type="text" id="cancelReason" wire:model="cancelReason" placeholder="Contoh: Kesalahan kuantitas / vendor kehabisan stok" />
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showCancelModal', false)">Tutup</x-ui.button>
                <x-ui.button type="button" variant="danger" wire:click="cancelPo">Batalkan PO</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
