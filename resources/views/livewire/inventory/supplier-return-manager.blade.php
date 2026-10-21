<div>
    @include('inventory.partials.sub-nav')
    {{-- Header & Aksi Utama --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Retur Pembelian Supplier (Supplier Return)</h4>
            <p class="text-sm font-medium text-apeiron-muted mt-1">
                Kelola pengembalian produk & bahan baku rusak/cacat ke vendor dengan pemotongan stok otomatis (SUPPLIER_RETURN_OUT)
            </p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
            <x-button type="button" variant="primary" wire:click="openCreateModal">
                <span class="material-symbols-rounded text-[18px]">add</span> Buat Retur Baru
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
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari nomor retur, nama vendor..."
                   class="form-input w-full pl-10 pr-4 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink placeholder-apeiron-muted focus:outline-none focus:ring-2 focus:ring-apeiron-ink shadow-sm text-sm">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-apeiron-muted">
                <span class="material-symbols-rounded text-[18px]">search</span>
            </div>
        </div>

        <div class="flex items-center gap-3 w-full sm:w-auto">
            <div class="relative w-full sm:w-48">
                <select wire:model.live="statusFilter"
                        class="form-input w-full pl-4 pr-8 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink focus:outline-none focus:ring-2 focus:ring-apeiron-ink text-sm shadow-sm appearance-none">
                    <option value="ALL">Semua Status</option>
                    <option value="DRAFT">Draft</option>
                    <option value="POSTED">Sudah Diposting</option>
                    <option value="REVERSED">Direverse</option>
                    <option value="CANCELLED">Dibatalkan</option>
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-apeiron-muted">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabel Supplier Returns --}}
    <div class="card-surface overflow-hidden w-full shrink-0">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="border-b border-apeiron-border bg-apeiron-surface text-apeiron-muted uppercase tracking-wider text-[11px] font-bold">
                        <th class="px-6 py-4">Nomor Retur</th>
                        <th class="px-6 py-4">Tanggal Retur</th>
                        <th class="px-6 py-4">Pemasok / Vendor</th>
                        <th class="px-6 py-4">Referensi Dokumen</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Pembuat / Pengesah</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-apeiron-border">
                    @forelse ($returns as $ret)
                        <tr class="hover:bg-apeiron-surface/40 transition-colors">
                            <td class="px-6 py-4 font-bold text-apeiron-ink">
                                {{ $ret->return_number }}
                            </td>
                            <td class="px-6 py-4 text-apeiron-muted text-xs">
                                {{ $ret->returned_at->format('d M Y H:i') }}
                            </td>
                            <td class="px-6 py-4 font-medium text-apeiron-ink">
                                {{ $ret->supplier_name ?? '-' }}
                            </td>
                            <td class="px-6 py-4 text-xs text-apeiron-muted">
                                @if($ret->goodsReceipt)
                                    <span class="inline-flex items-center gap-1 font-mono text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded">
                                        <span class="material-symbols-rounded text-[14px]">receipt_long</span>
                                        {{ $ret->goodsReceipt->receipt_number }}
                                    </span>
                                @elseif($ret->supplier_reference)
                                    {{ $ret->supplier_reference }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <x-badge :variant="$ret->status->badgeType()">
                                    {{ $ret->status->label() }}
                                </x-badge>
                            </td>
                            <td class="px-6 py-4 text-xs text-apeiron-muted">
                                <div>Dibuat: {{ $ret->creator?->name ?? 'System' }}</div>
                                @if($ret->poster)
                                    <div class="text-[11px] text-emerald-600">Diposting: {{ $ret->poster->name }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button wire:click="viewDetail({{ $ret->id }})" title="Lihat Detail"
                                            class="p-1.5 text-apeiron-muted hover:text-apeiron-ink hover:bg-apeiron-surface rounded-lg transition-colors">
                                        <span class="material-symbols-rounded text-[18px]">visibility</span>
                                    </button>

                                    @if($ret->isDraft())
                                        <button wire:click="postReturn({{ $ret->id }})" title="Posting Retur"
                                                wire:confirm="Apakah Anda yakin ingin memposting retur ini? Stok fisik akan langsung dipotong dari cabang."
                                                class="p-1.5 text-emerald-600 hover:text-emerald-800 hover:bg-emerald-50 rounded-lg transition-colors">
                                            <span class="material-symbols-rounded text-[18px]">send</span>
                                        </button>
                                        <button wire:click="cancelReturn({{ $ret->id }})" title="Batalkan Draft"
                                                wire:confirm="Yakin ingin membatalkan draft retur ini?"
                                                class="p-1.5 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-lg transition-colors">
                                            <span class="material-symbols-rounded text-[18px]">cancel</span>
                                        </button>
                                    @endif

                                    @if($ret->isPosted() && auth()->user()->role?->name === 'Owner')
                                        <button wire:click="openReverseModal({{ $ret->id }})" title="Pembalikan Retur (Reversal)"
                                                class="p-1.5 text-amber-600 hover:text-amber-800 hover:bg-amber-50 rounded-lg transition-colors">
                                            <span class="material-symbols-rounded text-[18px]">undo</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-apeiron-muted">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <span class="material-symbols-rounded text-[40px] text-apeiron-muted/60">keyboard_return</span>
                                    <p class="font-medium text-sm">Belum ada dokumen retur pembelian.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($returns->hasPages())
            <div class="px-6 py-4 border-t border-apeiron-border bg-apeiron-surface">
                {{ $returns->links() }}
            </div>
        @endif
    </div>

    {{-- Modal Form Draft Retur --}}
    @if ($showFormModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/40 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl shadow-2xl border border-apeiron-border max-w-4xl w-full p-6 animate-scale-up max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between border-b border-apeiron-border pb-4 mb-4">
                    <h3 class="text-lg font-bold text-apeiron-ink">
                        {{ $isEditing ? 'Perbarui Draft Retur Supplier' : 'Buat Retur Pembelian ke Supplier' }}
                    </h3>
                    <button type="button" wire:click="closeFormModal" class="text-apeiron-muted hover:text-apeiron-ink">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                @if ($errors->has('general'))
                    <div class="mb-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs">
                        {{ $errors->first('general') }}
                    </div>
                @endif

                <form wire:submit="saveDraft" class="flex-1 overflow-y-auto pr-1 flex flex-col gap-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-apeiron-ink uppercase mb-1">Nama Pemasok / Vendor</label>
                            <input type="text" wire:model="supplier_name" placeholder="Misal: PT Aneka Logistik"
                                   class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                            @error('supplier_name') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-apeiron-ink uppercase mb-1">Rujukan Dokumen GRN (Opsional)</label>
                            <select wire:model="goods_receipt_id" class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                                <option value="">-- Tanpa Rujukan GRN --</option>
                                @foreach($recentReceipts as $rec)
                                    <option value="{{ $rec->id }}">{{ $rec->receipt_number }} ({{ $rec->supplier_reference ?? 'Vendor' }})</option>
                                @endforeach
                            </select>
                            @error('goods_receipt_id') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-apeiron-ink uppercase mb-1">Tanggal Retur</label>
                            <input type="datetime-local" wire:model="returned_at"
                                   class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                            @error('returned_at') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-apeiron-ink uppercase mb-1">Alasan Retur</label>
                            <input type="text" wire:model="reason" placeholder="Contoh: Barang cacat, kedaluwarsa, atau kemasan rusak"
                                   class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                            @error('reason') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-apeiron-ink uppercase mb-1">Catatan Tambahan</label>
                            <input type="text" wire:model="notes" placeholder="Catatan internal pengiriman balik..."
                                   class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                            @error('notes') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    {{-- Daftar Item yang Diretur --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h5 class="text-sm font-bold text-apeiron-ink">Item Barang Retur</h5>
                            <button type="button" wire:click="addItemRow" class="text-xs font-bold text-apeiron-ink hover:underline flex items-center gap-1">
                                <span class="material-symbols-rounded text-[16px]">add</span> Tambah Baris
                            </button>
                        </div>

                        <div class="border border-apeiron-border rounded-2xl overflow-hidden">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-apeiron-surface text-apeiron-muted font-bold border-b border-apeiron-border">
                                    <tr>
                                        <th class="px-3 py-2">Tipe</th>
                                        <th class="px-3 py-2">Item Barang</th>
                                        <th class="px-3 py-2 w-28">Kuantitas</th>
                                        <th class="px-3 py-2 w-32">Biaya Satuan (Rp)</th>
                                        <th class="px-3 py-2">Keterangan</th>
                                        <th class="px-3 py-2 w-10"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-apeiron-border">
                                    @foreach ($items as $index => $item)
                                        <tr class="align-top">
                                            <td class="p-2">
                                                <select wire:model.live="items.{{ $index }}.item_type" class="w-full text-xs rounded-lg border-apeiron-border">
                                                    <option value="product">Produk</option>
                                                    <option value="ingredient">Bahan Baku</option>
                                                </select>
                                            </td>
                                            <td class="p-2">
                                                @if (($item['item_type'] ?? '') === 'product')
                                                    <select wire:model="items.{{ $index }}.product_id" class="w-full text-xs rounded-lg border-apeiron-border">
                                                        <option value="">-- Pilih Produk --</option>
                                                        @foreach ($products as $prod)
                                                            <option value="{{ $prod->id }}">{{ $prod->product_name }}</option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <select wire:model="items.{{ $index }}.ingredient_id" class="w-full text-xs rounded-lg border-apeiron-border">
                                                        <option value="">-- Pilih Bahan Baku --</option>
                                                        @foreach ($ingredients as $ing)
                                                            <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>
                                                        @endforeach
                                                    </select>
                                                @endif
                                            </td>
                                            <td class="p-2">
                                                <input type="number" step="0.0001" min="0.0001" wire:model="items.{{ $index }}.quantity"
                                                       class="w-full text-xs rounded-lg border-apeiron-border">
                                            </td>
                                            <td class="p-2">
                                                <input type="number" step="0.01" min="0" wire:model="items.{{ $index }}.unit_cost"
                                                       placeholder="0.00" class="w-full text-xs rounded-lg border-apeiron-border">
                                            </td>
                                            <td class="p-2">
                                                <input type="text" wire:model="items.{{ $index }}.reason" placeholder="Alasan retur baris"
                                                       class="w-full text-xs rounded-lg border-apeiron-border">
                                            </td>
                                            <td class="p-2 text-center">
                                                <button type="button" wire:click="removeItemRow({{ $index }})" class="text-rose-500 hover:text-rose-700">
                                                    <span class="material-symbols-rounded text-[18px]">delete</span>
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-apeiron-border">
                        <x-button type="button" variant="secondary" wire:click="closeFormModal">Batal</x-button>
                        <x-button type="submit" variant="primary">Simpan Draft</x-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal Detail Retur --}}
    @if ($showDetailModal && $selectedReturn)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/40 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl shadow-2xl border border-apeiron-border max-w-3xl w-full p-6 animate-scale-up max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between border-b border-apeiron-border pb-4 mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-apeiron-ink">Detail Retur #{{ $selectedReturn->return_number }}</h3>
                        <p class="text-xs text-apeiron-muted">Pemasok: {{ $selectedReturn->supplier_name ?? '-' }}</p>
                    </div>
                    <button type="button" wire:click="closeDetailModal" class="text-apeiron-muted hover:text-apeiron-ink">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto pr-1 flex flex-col gap-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-apeiron-surface p-4 rounded-2xl text-xs">
                        <div>
                            <span class="text-apeiron-muted block">Status:</span>
                            <x-badge :variant="$selectedReturn->status->badgeType()">
                                {{ $selectedReturn->status->label() }}
                            </x-badge>
                        </div>
                        <div>
                            <span class="text-apeiron-muted block">Tanggal:</span>
                            <span class="font-bold text-apeiron-ink">{{ $selectedReturn->returned_at->format('d M Y H:i') }}</span>
                        </div>
                        <div>
                            <span class="text-apeiron-muted block">Total Nilai:</span>
                            <span class="font-bold text-apeiron-ink">Rp {{ number_format((float) $selectedReturn->calculateTotalValue(), 2) }}</span>
                        </div>
                        <div>
                            <span class="text-apeiron-muted block">Pembuat:</span>
                            <span class="font-bold text-apeiron-ink">{{ $selectedReturn->creator?->name ?? 'System' }}</span>
                        </div>
                    </div>

                    @if($selectedReturn->reason)
                        <div class="text-xs p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800">
                            <strong>Alasan Retur:</strong> {{ $selectedReturn->reason }}
                        </div>
                    @endif

                    <h5 class="text-sm font-bold text-apeiron-ink">Rincian Barang yang Diretur</h5>
                    <div class="border border-apeiron-border rounded-2xl overflow-hidden">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-apeiron-surface text-apeiron-muted font-bold border-b border-apeiron-border">
                                <tr>
                                    <th class="px-4 py-2">Item Barang</th>
                                    <th class="px-4 py-2 text-right">Kuantitas</th>
                                    <th class="px-4 py-2 text-right">Biaya Satuan (Rp)</th>
                                    <th class="px-4 py-2 text-right">Total (Rp)</th>
                                    <th class="px-4 py-2">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-apeiron-border">
                                @foreach ($selectedReturn->items as $it)
                                    <tr>
                                        <td class="px-4 py-2.5 font-bold text-apeiron-ink">
                                            {{ $it->getItemName() }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-mono font-bold">
                                            {{ number_format((float) $it->quantity, 4) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-mono">
                                            {{ number_format((float) $it->unit_cost, 2) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-mono font-bold text-emerald-700">
                                            {{ number_format((float) bcmul((string) $it->quantity, (string) $it->unit_cost, 2), 2) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-apeiron-muted">
                                            {{ $it->reason ?? '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex items-center justify-end pt-4 border-t border-apeiron-border mt-4">
                    <x-button type="button" variant="secondary" wire:click="closeDetailModal">Tutup</x-button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Reversal --}}
    @if ($showReverseModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/40 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl shadow-2xl border border-apeiron-border max-w-md w-full p-6 animate-scale-up">
                <h3 class="text-lg font-bold text-apeiron-ink mb-2">Pembalikan Retur (Reversal)</h3>
                <p class="text-xs text-apeiron-muted mb-4">
                    Pembalikan retur akan mengembalikan kuantitas barang ke saldo cabang aktif. Aksi ini permanen dan tidak dapat dibatalkan.
                </p>

                <div class="mb-4">
                    <label class="block text-xs font-bold text-apeiron-ink uppercase mb-1">Alasan Pembalikan</label>
                    <textarea wire:model="reversal_reason" rows="3" placeholder="Jelaskan alasan pembalikan..."
                              class="form-input w-full p-3 rounded-xl border border-apeiron-border text-sm"></textarea>
                    @error('reversal_reason') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="flex items-center justify-end gap-3">
                    <x-button type="button" variant="secondary" wire:click="closeReverseModal">Batal</x-button>
                    <x-button type="button" variant="danger" wire:click="submitReverse">Proses Reversal</x-button>
                </div>
            </div>
        </div>
    @endif
</div>
