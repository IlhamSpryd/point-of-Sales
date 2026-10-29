<div class="space-y-6" data-gsap="page-enter">
    @include('purchasing.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Penerimaan Barang (Goods Received Notes)"
        description="Dokumentasikan penerimaan fisik barang dari vendor berdasarkan Pesanan Pembelian (PO), verifikasi surat jalan, dan catat penambahan stok secara akurat.">
        <x-slot:actions>
            @can('create', \App\Modules\Purchasing\Models\GoodsReceivedNote::class)
            <x-ui.button wire:click="openCreateModal" icon="input" variant="primary">
                Terima Barang (GRN Baru)
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
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-zinc-200/80 dark:border-zinc-800">
        <div class="flex-1 max-w-md">
            <x-ui.input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Cari nomor GRN, surat jalan, atau nomor PO..."
                icon="search" />
        </div>
        <div class="flex items-center gap-3">
            <x-ui.select wire:model.live="statusFilter">
                <option value="">Semua Status</option>
                <option value="DRAFT">Draf</option>
                <option value="POSTED">Diposting (Stok Masuk)</option>
                <option value="REVERSED">Dibatalkan (Reversed)</option>
                <option value="CANCELLED">Dibatalkan</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-surface rounded-2xl overflow-hidden border border-zinc-200/80 dark:border-zinc-800 shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-300">
                <thead class="bg-zinc-50 dark:bg-zinc-900/50 text-xs font-semibold uppercase text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-6 py-4">Nomor & Tanggal GRN</th>
                        <th class="px-6 py-4">Referensi PO & Surat Jalan</th>
                        <th class="px-6 py-4">Pemasok</th>
                        <th class="px-6 py-4">Gudang / Cabang</th>
                        <th class="px-6 py-4 text-center">Faktur (3-Way)</th>
                        <th class="px-6 py-4 text-center">Status GRN</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($grns as $grn)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40 transition-colors">
                        <td class="px-6 py-4">
                            <div class="font-bold text-zinc-900 dark:text-white font-mono">{{ $grn->grn_number }}</div>
                            <div class="text-xs text-zinc-500">{{ \Carbon\Carbon::parse($grn->received_date)->format('d M Y') }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-medium text-zinc-800 dark:text-zinc-200 font-mono">{{ $grn->purchaseOrder?->po_number ?? 'PO #' . $grn->purchase_order_id }}</div>
                            <div class="text-xs text-zinc-500 font-mono">SJ: {{ $grn->delivery_order_number }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $grn->purchaseOrder?->supplier?->name ?? 'Vendor' }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-zinc-700 dark:text-zinc-300 font-medium">{{ $grn->store?->name ?? 'Semua Cabang' }}</span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @php
                                $matchVariant = match($grn->matching_status?->value ?? (string) $grn->matching_status) {
                                    'MATCHED', 'matched' => 'success',
                                    'PARTIALLY_MATCHED', 'partial' => 'warning',
                                    default => 'neutral'
                                };
                            @endphp
                            <x-ui.badge :variant="$matchVariant">
                                {{ $grn->matching_status?->label() ?? $grn->matching_status ?? 'UNMATCHED' }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @php
                                $grnStatusVal = $grn->status?->value ?? (string) $grn->status;
                                $statusVariant = match($grnStatusVal) {
                                    'POSTED', 'posted' => 'success',
                                    'REVERSED', 'reversed' => 'danger',
                                    'CANCELLED', 'cancelled' => 'neutral',
                                    default => 'warning'
                                };
                            @endphp
                            <x-ui.badge :variant="$statusVariant">
                                {{ $grn->status?->label() ?? $grnStatusVal }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <x-ui.button
                                    size="icon"
                                    variant="ghost"
                                    wire:click="viewGrn({{ $grn->id }})"
                                    title="Lihat Detail GRN"
                                    aria-label="Detail">
                                    <span class="material-symbols-rounded text-base text-zinc-600 dark:text-zinc-400">visibility</span>
                                </x-ui.button>
                                @if($grn->isDraft())
                                    <x-ui.button
                                        size="icon"
                                        variant="ghost"
                                        wire:click="postGrn({{ $grn->id }})"
                                        title="Posting Penerimaan (Stok Masuk)"
                                        aria-label="Posting">
                                        <span class="material-symbols-rounded text-base text-emerald-600">publish</span>
                                    </x-ui.button>
                                @endif
                                @if(($grn->status?->value === 'POSTED' || $grn->status === 'posted') && ! $grn->isReversed())
                                    <x-ui.button
                                        size="icon"
                                        variant="ghost"
                                        wire:click="confirmReverseGrn({{ $grn->id }})"
                                        title="Batalkan GRN (Reverse Stok)"
                                        aria-label="Reverse">
                                        <span class="material-symbols-rounded text-base text-rose-500">undo</span>
                                    </x-ui.button>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center">
                                <span class="material-symbols-rounded text-5xl text-zinc-300 dark:text-zinc-700">move_to_inbox</span>
                                <h3 class="mt-2 text-sm font-semibold text-zinc-900 dark:text-white">Belum ada dokumen penerimaan barang</h3>
                                <p class="mt-1 text-xs text-zinc-500">Terima pengiriman dari vendor berdasarkan Pesanan Pembelian yang telah diterbitkan.</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($grns->hasPages())
        <div class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-800">
            {{ $grns->links() }}
        </div>
        @endif
    </div>

    <!-- Modal Buat GRN Baru -->
    <x-ui.modal wire:model="showCreateModal" maxWidth="4xl">
        <x-slot:title>
            Penerimaan Barang Fisik Baru (Goods Received Note)
        </x-slot:title>

        <x-slot:content>
            <form id="createGrnForm" wire:submit="saveDraftGrn" class="space-y-6">
                <!-- Info Header -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-800">
                    <div class="md:col-span-2">
                        <x-ui.label for="selectedPoId">Pilih Pesanan Pembelian (PO) *</x-ui.label>
                        <x-ui.select id="selectedPoId" wire:model.live="selectedPoId" required>
                            <option value="">-- Pilih PO Diterbitkan --</option>
                            @foreach($availablePos as $po)
                                <option value="{{ $po->id }}">{{ $po->po_number }} &bull; {{ $po->supplier?->name }} (Rp {{ number_format((float) $po->total_amount, 0) }})</option>
                            @endforeach
                        </x-ui.select>
                        <x-input-error :messages="$errors->get('selectedPoId')" class="mt-1" />
                    </div>

                    <div>
                        <x-ui.label for="receivedDate">Tanggal Penerimaan *</x-ui.label>
                        <x-ui.input type="date" id="receivedDate" wire:model="receivedDate" required />
                        <x-input-error :messages="$errors->get('receivedDate')" class="mt-1" />
                    </div>

                    <div>
                        <x-ui.label for="deliveryOrderNumber">Nomor Surat Jalan Vendor *</x-ui.label>
                        <x-ui.input type="text" id="deliveryOrderNumber" wire:model="deliveryOrderNumber" placeholder="SJ-2026/0129" required />
                        <x-input-error :messages="$errors->get('deliveryOrderNumber')" class="mt-1" />
                    </div>

                    <div>
                        <x-ui.label for="carrierName">Ekspedisi / Pengirim</x-ui.label>
                        <x-ui.input type="text" id="carrierName" wire:model="carrierName" placeholder="Kurir Vendor / JNE Trucking" />
                    </div>

                    <div>
                        <x-ui.label for="vehicleNumber">Plat Nomor Kendaraan</x-ui.label>
                        <x-ui.input type="text" id="vehicleNumber" wire:model="vehicleNumber" placeholder="B 1234 XYZ" />
                    </div>
                </div>

                <!-- Lines Table -->
                @if(count($grnLines) > 0)
                <div class="space-y-3">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500">Pemeriksaan Kuantitas Fisik Barang</h4>
                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded-xl">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-zinc-100 dark:bg-zinc-800/80 font-semibold text-zinc-600 dark:text-zinc-300">
                                <tr>
                                    <th class="px-3 py-2">Nama Barang</th>
                                    <th class="px-3 py-2 w-20">Satuan</th>
                                    <th class="px-3 py-2 w-24 text-right">Dipesan</th>
                                    <th class="px-3 py-2 w-24 text-right">Sisa PO</th>
                                    <th class="px-3 py-2 w-28 text-right">Diterima Fisik</th>
                                    <th class="px-3 py-2 w-28 text-right">Diterima (QC Pass)</th>
                                    <th class="px-3 py-2 w-28 text-right">Ditolak (Rusak)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach($grnLines as $i => $line)
                                <tr>
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $line['item_name'] }}</div>
                                        <div class="text-[10px] text-zinc-400 uppercase">{{ $line['item_type'] }}</div>
                                    </td>
                                    <td class="px-3 py-2 font-mono uppercase">{{ $line['purchase_uom'] }}</td>
                                    <td class="px-3 py-2 text-right font-medium text-zinc-500">{{ (float) $line['ordered_qty'] }}</td>
                                    <td class="px-3 py-2 text-right font-medium text-amber-600">{{ (float) $line['remaining_qty'] }}</td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.0001" min="0" wire:model="grnLines.{{ $i }}.received_quantity" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 text-right font-medium" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.0001" min="0" wire:model="grnLines.{{ $i }}.accepted_quantity" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 text-right font-semibold text-emerald-600" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.0001" min="0" wire:model="grnLines.{{ $i }}.rejected_quantity" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 text-right text-rose-500" />
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @elseif($selectedPoId)
                <div class="p-6 text-center text-xs text-zinc-400 border border-dashed rounded-xl">
                    Seluruh item pada PO ini telah diterima secara penuh.
                </div>
                @endif

                <div>
                    <x-ui.label for="notes">Catatan Penerimaan</x-ui.label>
                    <textarea id="notes" wire:model="notes" rows="2" placeholder="Kondisi kemasan, nomor segel, catatan petugas gudang..." class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm"></textarea>
                </div>
            </form>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showCreateModal', false)">Batal</x-ui.button>
                <x-ui.button type="submit" form="createGrnForm" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>Simpan Draf GRN</span>
                    <span wire:loading>Menyimpan...</span>
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Detail GRN -->
    <x-ui.modal wire:model="showDetailModal" maxWidth="3xl">
        <x-slot:title>
            @if($selectedGrn)
                <div>
                    <span class="font-mono">{{ $selectedGrn->grn_number }}</span>
                    <span class="ml-2">
                        <x-ui.badge variant="success">{{ $selectedGrn->status?->label() ?? $selectedGrn->status }}</x-ui.badge>
                    </span>
                </div>
            @else
                Detail Penerimaan Barang
            @endif
        </x-slot:title>

        <x-slot:content>
            @if($selectedGrn)
            <div class="space-y-6">
                <!-- Info Header -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-800 text-xs">
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Referensi PO</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5 font-mono">{{ $selectedGrn->purchaseOrder?->po_number }}</div>
                        <div class="text-zinc-500">{{ $selectedGrn->purchaseOrder?->supplier?->name }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Surat Jalan Vendor</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5 font-mono">{{ $selectedGrn->delivery_order_number }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Tanggal Terima</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ \Carbon\Carbon::parse($selectedGrn->received_date)->format('d M Y') }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Gudang / Cabang</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedGrn->store?->name }}</div>
                    </div>
                </div>

                <!-- Lines Table -->
                <div class="space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500">Rincian Barang Diterima</h4>
                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded-xl">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-zinc-50 dark:bg-zinc-900/50 font-semibold text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <th class="px-4 py-2.5">Item</th>
                                    <th class="px-4 py-2.5">Satuan</th>
                                    <th class="px-4 py-2.5 text-right">Diterima Fisik</th>
                                    <th class="px-4 py-2.5 text-right">QC Pass (Masuk Stok)</th>
                                    <th class="px-4 py-2.5 text-right">Ditolak</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach($selectedGrn->lines as $line)
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <div class="font-medium text-zinc-800 dark:text-zinc-200">
                                            {{ $line->purchasable?->product_name ?? $line->purchasable?->name ?? 'Item #' . $line->purchasable_id }}
                                        </div>
                                        <div class="text-[10px] text-zinc-400 uppercase">{{ $line->purchasable_type }}</div>
                                    </td>
                                    <td class="px-4 py-2.5 font-mono uppercase">{{ $line->purchase_uom }}</td>
                                    <td class="px-4 py-2.5 text-right font-medium">{{ (float) $line->received_quantity }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold text-emerald-600">{{ (float) $line->accepted_quantity }}</td>
                                    <td class="px-4 py-2.5 text-right text-rose-500">{{ (float) $line->rejected_quantity }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($selectedGrn->notes)
                <div class="text-xs p-3 rounded-lg bg-zinc-50 dark:bg-zinc-900/40 text-zinc-600 dark:text-zinc-400 border border-zinc-200 dark:border-zinc-800">
                    <span class="font-bold">Catatan:</span> {{ $selectedGrn->notes }}
                </div>
                @endif
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-between">
                <div>
                    @if($selectedGrn && ($selectedGrn->status?->value === 'POSTED' || $selectedGrn->status === 'posted') && ! $selectedGrn->isReversed())
                        <x-ui.button type="button" variant="danger" wire:click="confirmReverseGrn({{ $selectedGrn->id }})">
                            Batalkan GRN (Reverse Stok)
                        </x-ui.button>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    @if($selectedGrn && $selectedGrn->isDraft())
                        <x-ui.button type="button" variant="primary" wire:click="postGrn({{ $selectedGrn->id }})">
                            Posting GRN (Stok Masuk)
                        </x-ui.button>
                    @endif
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showDetailModal', false)">Tutup</x-ui.button>
                </div>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Konfirmasi Reverse GRN -->
    <x-ui.modal wire:model="showReverseModal" maxWidth="md">
        <x-slot:title>
            Batalkan Penerimaan Barang (Reverse GRN)
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-3">
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Apakah Anda yakin ingin membatalkan GRN ini? Stok barang yang sebelumnya telah masuk akan dikurangkan kembali dari buku besar gudang.
                </p>
                <div>
                    <x-ui.label for="reverseReason">Alasan Pembatalan / Reversal *</x-ui.label>
                    <x-ui.input type="text" id="reverseReason" wire:model="reverseReason" placeholder="Contoh: Kesalahan nomor surat jalan / barang salah kirim" required />
                    <x-input-error :messages="$errors->get('reverseReason')" class="mt-1" />
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showReverseModal', false)">Tutup</x-ui.button>
                <x-ui.button type="button" variant="danger" wire:click="executeReverseGrn">Proses Reversal</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
