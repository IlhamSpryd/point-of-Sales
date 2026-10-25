<div>
    @include('inventory.partials.sub-nav')
    {{-- Header & Aksi Utama --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Transfer Stok Antarcabang</h4>
            <p class="text-sm font-medium text-apeiron-muted mt-1">
                Perpindahan stok antarcabang 2-tahap (Two-Phase Transfer) dengan akuntabilitas perjalanan (OD-03 & OD-06)
            </p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
            <x-button type="button" variant="primary" wire:click="openCreateModal">
                <span class="material-symbols-rounded text-[18px]">add</span> Buat Permintaan Transfer
            </x-button>
        </div>
    </div>

    {{-- Alert Notifikasi --}}
    @if (session()->has('message'))
        <div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center gap-2">
            <span class="material-symbols-rounded text-emerald-600">check_circle</span>
            {{ session('message') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-center gap-2">
            <span class="material-symbols-rounded text-rose-600">error</span>
            {{ session('error') }}
        </div>
    @endif

    {{-- Filter & Search Bar --}}
    <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mb-4">
        <div class="relative w-full sm:w-80">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari nomor transfer, catatan..."
                   class="form-input w-full pl-10 pr-4 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink placeholder-apeiron-muted focus:outline-none focus:ring-2 focus:ring-apeiron-ink shadow-sm text-sm">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-apeiron-muted">
                <span class="material-symbols-rounded text-[18px]">search</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
            <div class="relative w-full sm:w-44">
                <select wire:model.live="directionFilter"
                        class="form-input w-full pl-4 pr-8 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink focus:outline-none focus:ring-2 focus:ring-apeiron-ink text-sm shadow-sm appearance-none">
                    <option value="ALL">Semua Arah</option>
                    <option value="OUTBOUND">Keluar (Pengirim)</option>
                    <option value="INBOUND">Masuk (Penerima)</option>
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-apeiron-muted">
                    <span class="material-symbols-rounded text-[18px]">swap_horiz</span>
                </div>
            </div>

            <div class="relative w-full sm:w-56">
                <select wire:model.live="statusFilter"
                        class="form-input w-full pl-4 pr-8 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink focus:outline-none focus:ring-2 focus:ring-apeiron-ink text-sm shadow-sm appearance-none">
                    <option value="ALL">Semua Status</option>
                    @foreach ($statusOptions as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-apeiron-muted">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabel Stock Transfers --}}
    <div class="card-surface overflow-hidden w-full shrink-0">
        <div class="overflow-x-auto">
            <x-data-table>
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">No. Dokumen</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Rute Transfer</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Total Item</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Waktu Eksekusi</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Aksi</th>
                </x-slot:head>

                @forelse ($transfers as $transfer)
                    <tr class="hover:bg-apeiron-surface transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm font-bold text-apeiron-ink">{{ $transfer->transfer_number }}</span>
                            <div class="text-xs text-apeiron-muted">Dibuat: {{ $transfer->creator->name ?? 'System' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-apeiron-ink">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold {{ $transfer->from_store_id === $currentStoreId ? 'text-amber-800' : 'text-apeiron-ink' }}">
                                    {{ $transfer->fromStore->name }}
                                </span>
                                <span class="material-symbols-rounded text-apeiron-muted text-[18px]">arrow_forward</span>
                                <span class="font-semibold {{ $transfer->to_store_id === $currentStoreId ? 'text-emerald-800' : 'text-apeiron-ink' }}">
                                    {{ $transfer->toStore->name }}
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <x-badge :variant="$transfer->status->badgeType()">
                                {{ $transfer->status->label() }}
                            </x-badge>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-apeiron-ink text-right">
                            {{ $transfer->items_count }} item
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-apeiron-ink">
                            @if ($transfer->shipped_at)
                                <div>Kirim: <span class="font-medium text-amber-700">{{ $transfer->shipped_at->format('d M H:i') }}</span></div>
                            @endif
                            @if ($transfer->received_at)
                                <div>Terima: <span class="font-medium text-emerald-700">{{ $transfer->received_at->format('d M H:i') }}</span></div>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                            <div class="flex items-center justify-center gap-2">
                                <button type="button" wire:click="viewDetail({{ $transfer->id }})" class="p-1 hover:text-apeiron-ink text-apeiron-muted transition-colors" title="Lihat Rincian">
                                    <span class="material-symbols-rounded text-[20px]">visibility</span>
                                </button>

                                @if ($transfer->status === \App\Enums\StockTransferStatusEnum::Draft && $transfer->from_store_id === $currentStoreId)
                                    <button type="button" wire:click="shipTransfer({{ $transfer->id }})" wire:confirm="Kirim transfer barang ini sekarang? Stok di cabang pengirim akan dipotong." class="p-1 text-amber-600 hover:text-amber-800 transition-colors" title="Kirim Barang (Dispatch)">
                                        <span class="material-symbols-rounded text-[20px]">local_shipping</span>
                                    </button>
                                @endif

                                @if ($transfer->status === \App\Enums\StockTransferStatusEnum::InTransit && $transfer->to_store_id === $currentStoreId)
                                    <button type="button" wire:click="openReceiveModal({{ $transfer->id }})" class="p-1 text-emerald-600 hover:text-emerald-800 transition-colors" title="Terima Barang di Cabang Tujuan">
                                        <span class="material-symbols-rounded text-[20px]">inventory</span>
                                    </button>
                                @endif

                                @if ($transfer->status->canCancel())
                                    <button type="button" wire:click="cancelTransfer({{ $transfer->id }})" wire:confirm="Yakin ingin membatalkan transfer ini? Jika dalam pengiriman, stok akan dikembalikan ke cabang pengirim." class="p-1 text-rose-500 hover:text-rose-700 transition-colors" title="Batalkan Transfer">
                                        <span class="material-symbols-rounded text-[20px]">cancel</span>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-sm text-apeiron-muted">
                            Belum ada dokumen transfer stok antarcabang ditemukan.
                        </td>
                    </tr>
                @endforelse
            </x-data-table>
        </div>

        @if ($transfers->hasPages())
            <div class="px-6 py-4 border-t border-apeiron-border">
                {{ $transfers->links() }}
            </div>
        @endif
    </div>

    {{-- MODAL 1: Form Buat Permintaan Transfer --}}
    @if ($showFormModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/40 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-3xl w-full p-6 shadow-2xl border border-apeiron-border" @click.outside="$wire.closeFormModal()">
                <div class="flex items-center justify-between pb-4 border-b border-apeiron-border mb-4">
                    <h5 class="text-lg font-bold text-apeiron-ink">Buat Permintaan Transfer Stok Baru</h5>
                    <button type="button" wire:click="closeFormModal" class="text-apeiron-muted hover:text-apeiron-ink">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                @if ($errors->has('general'))
                    <div class="mb-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm">
                        {{ $errors->first('general') }}
                    </div>
                @endif

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-apeiron-ink uppercase tracking-wider mb-1">Cabang Tujuan</label>
                        <select wire:model="to_store_id" class="form-input w-full p-3 rounded-xl border border-apeiron-border text-sm">
                            <option value="">-- Pilih Cabang Tujuan Penerima --</option>
                            @foreach ($destinationStores as $dest)
                                <option value="{{ $dest->id }}">{{ $dest->name }}</option>
                            @endforeach
                        </select>
                        @error('to_store_id') <span class="text-xs text-rose-600 font-medium">{{ $message }}</span> @enderror
                    </div>

                    {{-- Dynamic Items Table --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs font-bold text-apeiron-ink uppercase tracking-wider">Item Barang yang Ditransfer</label>
                            <button type="button" wire:click="addItemRow" class="text-xs font-semibold text-apeiron-ink hover:underline flex items-center gap-1">
                                <span class="material-symbols-rounded text-sm">add</span> Tambah Baris
                            </button>
                        </div>

                        <div class="space-y-2 max-h-64 overflow-y-auto">
                            @foreach ($items as $index => $item)
                                <div class="p-3 rounded-2xl bg-apeiron-surface border border-apeiron-border flex flex-col sm:flex-row items-center gap-3 text-sm">
                                    <div class="w-full sm:w-32">
                                        <select wire:model.live="items.{{ $index }}.item_type" class="form-input w-full p-2 rounded-lg border border-apeiron-border text-xs">
                                            <option value="product">Produk</option>
                                            <option value="ingredient">Bahan Baku</option>
                                        </select>
                                    </div>

                                    <div class="w-full sm:flex-1">
                                        @if (($item['item_type'] ?? 'product') === 'product')
                                            <select wire:model="items.{{ $index }}.product_id" class="form-input w-full p-2 rounded-lg border border-apeiron-border text-xs">
                                                <option value="">-- Pilih Produk --</option>
                                                @foreach ($availableProducts as $p)
                                                    <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->code ?? '-' }})</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <select wire:model="items.{{ $index }}.ingredient_id" class="form-input w-full p-2 rounded-lg border border-apeiron-border text-xs">
                                                <option value="">-- Pilih Bahan Baku --</option>
                                                @foreach ($availableIngredients as $ing)
                                                    <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->code ?? '-' }})</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </div>

                                    <div class="w-full sm:w-28">
                                        <input type="number" step="0.0001" min="0.0001" wire:model="items.{{ $index }}.quantity_requested" placeholder="Kuantitas" class="form-input w-full p-2 rounded-lg border border-apeiron-border text-xs text-right font-semibold">
                                    </div>

                                    <button type="button" wire:click="removeItemRow({{ $index }})" class="p-1 text-rose-500 hover:text-rose-700">
                                        <span class="material-symbols-rounded text-[18px]">delete</span>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-apeiron-ink uppercase tracking-wider mb-1">Catatan Dokumen</label>
                        <textarea wire:model="notes" rows="2" placeholder="Tujuan pengiriman, armada, keterangan surat jalan, dll." class="form-input w-full p-3 rounded-xl border border-apeiron-border text-sm"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-apeiron-border">
                    <x-button type="button" variant="secondary" wire:click="closeFormModal">Batal</x-button>
                    <x-button type="button" variant="primary" wire:click="saveTransfer">Simpan Draft Transfer</x-button>
                </div>
            </div>
        </div>
    @endif

    {{-- MODAL 2: Penerimaan Transfer & Resolusi Selisih (OD-03) --}}
    @if ($showReceiveModal && $activeTransfer)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/40 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-4xl w-full p-6 shadow-2xl border border-apeiron-border" @click.outside="$wire.closeReceiveModal()">
                <div class="flex items-center justify-between pb-4 border-b border-apeiron-border mb-4">
                    <div>
                        <h5 class="text-lg font-bold text-apeiron-ink">Penerimaan Transfer Barang: {{ $activeTransfer->transfer_number }}</h5>
                        <p class="text-xs text-apeiron-muted">Dari: {{ $activeTransfer->fromStore->name }} $\to$ Tujuan: {{ $activeTransfer->toStore->name }}</p>
                    </div>
                    <button type="button" wire:click="closeReceiveModal" class="text-apeiron-muted hover:text-apeiron-ink">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <div class="p-3 mb-4 rounded-2xl bg-amber-50 border border-amber-200 text-xs text-amber-900">
                    <strong>Ketentuan Anti-Over-Receipt (OD-03):</strong> Kuantitas yang diterima tidak boleh melebihi kuantitas yang dikirim. Jika fisik barang yang diterima kurang, Anda wajib memilih resolusi penyelesaian selisih (kembalikan ke cabang pengirim atau hapus buku susut perjalanan).
                </div>

                <div class="max-h-80 overflow-y-auto mb-4">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-apeiron-surface text-xs font-semibold text-apeiron-muted uppercase">
                            <tr>
                                <th class="p-3">Item Barang</th>
                                <th class="p-3 text-right">Dikirim</th>
                                <th class="p-3 text-right w-36">Diterima</th>
                                <th class="p-3 w-48">Resolusi Jika Selisih</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-apeiron-border">
                            @foreach ($activeTransfer->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-bold text-apeiron-ink">
                                            {{ $item->item_type === 'product' ? ($item->product?->product_name ?? $item->product?->name) : $item->ingredient?->name }}
                                        </div>
                                        <div class="text-xs text-apeiron-muted uppercase">{{ $item->item_type }}</div>
                                    </td>
                                    <td class="p-3 text-right font-semibold text-apeiron-ink">
                                        {{ number_format((float) $item->quantity_shipped, 2) }}
                                    </td>
                                    <td class="p-3 text-right">
                                        <input type="number" step="0.0001" min="0" max="{{ $item->quantity_shipped }}" wire:model.live="receivedQuantities.{{ $item->id }}" class="form-input w-full p-2 text-right rounded-lg border border-apeiron-border text-sm font-semibold">
                                        @error("receivedQuantities.{$item->id}") <span class="text-[10px] text-rose-600 block">{{ $message }}</span> @enderror
                                    </td>
                                    <td class="p-3">
                                        @php
                                            $currReceived = (float) ($receivedQuantities[$item->id] ?? $item->quantity_shipped);
                                            $hasDiscrepancy = $currReceived < (float) $item->quantity_shipped;
                                        @endphp
                                        @if ($hasDiscrepancy)
                                            <select wire:model="discrepancyResolutions.{{ $item->id }}" class="form-input w-full p-2 rounded-lg border border-amber-300 bg-amber-50 text-xs font-medium">
                                                <option value="NONE">-- Pilih Resolusi --</option>
                                                <option value="RETURN_TO_SOURCE">Kembalikan ke Asal (Return)</option>
                                                <option value="WRITE_OFF_IN_TRANSIT">Hapus Buku (Write-off)</option>
                                            </select>
                                        @else
                                            <span class="text-xs text-emerald-600 font-medium flex items-center gap-1">
                                                <span class="material-symbols-rounded text-sm">check</span> Lengkap
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    <label class="block text-xs font-bold text-apeiron-ink uppercase tracking-wider mb-1">Catatan Penerimaan</label>
                    <input type="text" wire:model="receiveNotes" placeholder="Kondisi barang saat tiba di gudang..." class="form-input w-full p-2 rounded-xl border border-apeiron-border text-xs">
                </div>

                <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-apeiron-border">
                    <x-button type="button" variant="secondary" wire:click="closeReceiveModal">Batal</x-button>
                    <x-button type="button" variant="primary" wire:click="submitReceive">Konfirmasi Penerimaan Barang</x-button>
                </div>
            </div>
        </div>
    @endif

    {{-- MODAL 3: Detail Dokumen Transfer --}}
    @if ($showDetailModal && $selectedTransfer)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/40 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-4xl w-full p-6 shadow-2xl border border-apeiron-border" @click.outside="$wire.closeDetailModal()">
                <div class="flex items-center justify-between pb-4 border-b border-apeiron-border mb-4">
                    <div>
                        <h5 class="text-lg font-bold text-apeiron-ink">Detail Transfer: {{ $selectedTransfer->transfer_number }}</h5>
                        <p class="text-xs text-apeiron-muted">Status: {{ $selectedTransfer->status->label() }}</p>
                    </div>
                    <button type="button" wire:click="closeDetailModal" class="text-apeiron-muted hover:text-apeiron-ink">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6 p-4 rounded-2xl bg-apeiron-surface text-xs">
                    <div>
                        <span class="text-apeiron-muted block">Cabang Pengirim</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedTransfer->fromStore->name }}</span>
                    </div>
                    <div>
                        <span class="text-apeiron-muted block">Cabang Penerima</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedTransfer->toStore->name }}</span>
                    </div>
                    <div>
                        <span class="text-apeiron-muted block">Waktu Kirim</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedTransfer->shipped_at?->format('d M Y H:i') ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-apeiron-muted block">Waktu Terima</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedTransfer->received_at?->format('d M Y H:i') ?? '-' }}</span>
                    </div>
                </div>

                <div class="max-h-80 overflow-y-auto mb-4">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-apeiron-surface text-xs font-semibold text-apeiron-muted uppercase">
                            <tr>
                                <th class="p-3">Item</th>
                                <th class="p-3 text-right">Diminta</th>
                                <th class="p-3 text-right">Dikirim</th>
                                <th class="p-3 text-right">Diterima</th>
                                <th class="p-3 text-right">Carrying Cost</th>
                                <th class="p-3 text-center">Resolusi Selisih</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-apeiron-border">
                            @foreach ($selectedTransfer->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-bold text-apeiron-ink">
                                            {{ $item->item_type === 'product' ? ($item->product?->product_name ?? $item->product?->name) : $item->ingredient?->name }}
                                        </div>
                                        <div class="text-xs text-apeiron-muted uppercase">{{ $item->item_type }}</div>
                                    </td>
                                    <td class="p-3 text-right font-medium text-apeiron-ink">{{ number_format((float) $item->quantity_requested, 2) }}</td>
                                    <td class="p-3 text-right font-medium text-apeiron-ink">{{ number_format((float) $item->quantity_shipped, 2) }}</td>
                                    <td class="p-3 text-right font-semibold text-apeiron-ink">{{ $item->quantity_received !== null ? number_format((float) $item->quantity_received, 2) : '-' }}</td>
                                    <td class="p-3 text-right text-xs font-mono">Rp {{ number_format((float) $item->carrying_unit_cost, 0, ',', '.') }}</td>
                                    <td class="p-3 text-center text-xs">
                                        <span class="px-2 py-1 rounded bg-apeiron-surface border border-apeiron-border">
                                            {{ $item->discrepancy_resolution->value }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-apeiron-border">
                    <x-button type="button" variant="secondary" wire:click="closeDetailModal">Tutup</x-button>
                </div>
            </div>
        </div>
    @endif
</div>
