<div>
    @include('inventory.partials.sub-nav')
    {{-- Header & Aksi Utama --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Stock Opname & Rekonsiliasi Fisik</h4>
            <p class="text-sm font-medium text-apeiron-muted mt-1">
                Penghitungan fisik periodik dengan Snapshot Sequence Pinning & Drift Gate (OD-04)
            </p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
            <x-button type="button" variant="primary" wire:click="openCreateModal">
                <span class="material-symbols-rounded text-[18px]">add</span> Mulai Sesi Opname Baru
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
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari nomor opname, catatan..."
                   class="form-input w-full pl-10 pr-4 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink placeholder-apeiron-muted focus:outline-none focus:ring-2 focus:ring-apeiron-ink shadow-sm text-sm">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-apeiron-muted">
                <span class="material-symbols-rounded text-[18px]">search</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
            <div class="relative w-full sm:w-60">
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

    {{-- Tabel Stock Opnames --}}
    <div class="card-surface overflow-hidden w-full shrink-0">
        <div class="overflow-x-auto">
            <x-data-table>
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">No. Dokumen</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Snapshot Pinned</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Total Item</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Petugas / Approver</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Aksi</th>
                </x-slot:head>

                @forelse ($opnames as $opname)
                    <tr class="hover:bg-apeiron-surface transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm font-bold text-apeiron-ink">{{ $opname->opname_number }}</span>
                            <div class="text-xs text-apeiron-muted">Dibuat: {{ $opname->created_at->format('d M Y H:i') }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-apeiron-ink">
                            @if ($opname->snapshot_taken_at)
                                <span class="font-medium text-emerald-700">{{ $opname->snapshot_taken_at->format('d M Y H:i') }}</span>
                            @else
                                <span class="text-xs text-apeiron-muted italic">Belum dimulai</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <x-badge :variant="$opname->status->badgeType()">
                                {{ $opname->status->label() }}
                            </x-badge>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-apeiron-ink text-right">
                            {{ $opname->items_count }} item
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-apeiron-ink">
                            <div>Hitung: <span class="font-medium">{{ $opname->submitter->name ?? '-' }}</span></div>
                            <div>Approve: <span class="font-medium">{{ $opname->approver->name ?? '-' }}</span></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                            <div class="flex items-center justify-center gap-2">
                                <button type="button" wire:click="viewDetail({{ $opname->id }})" class="p-1 hover:text-apeiron-ink text-apeiron-muted transition-colors" title="Lihat Rincian">
                                    <span class="material-symbols-rounded text-[20px]">visibility</span>
                                </button>

                                @if ($opname->status === \App\Enums\StockOpnameStatusEnum::Draft)
                                    <button type="button" wire:click="startCounting({{ $opname->id }})" class="p-1 text-amber-600 hover:text-amber-800 transition-colors" title="Mulai Hitung (Pin Snapshot)">
                                        <span class="material-symbols-rounded text-[20px]">play_circle</span>
                                    </button>
                                @endif

                                @if ($opname->status === \App\Enums\StockOpnameStatusEnum::Counting)
                                    <button type="button" wire:click="openCountModal({{ $opname->id }})" class="p-1 text-blue-600 hover:text-blue-800 transition-colors" title="Isi Hasil Hitung Fisik">
                                        <span class="material-symbols-rounded text-[20px]">edit_note</span>
                                    </button>
                                    <button type="button" wire:click="submitForApproval({{ $opname->id }})" class="p-1 text-emerald-600 hover:text-emerald-800 transition-colors" title="Ajukan Persetujuan">
                                        <span class="material-symbols-rounded text-[20px]">send</span>
                                    </button>
                                @endif

                                @if ($opname->status === \App\Enums\StockOpnameStatusEnum::PendingApproval)
                                    <button type="button" wire:click="approveOpname({{ $opname->id }})" class="p-1 text-emerald-700 hover:text-emerald-900 transition-colors" title="Setujui Rekonsiliasi (Drift Gate Enforced)">
                                        <span class="material-symbols-rounded text-[20px]">check_circle</span>
                                    </button>
                                @endif

                                @if ($opname->status->canCancel())
                                    <button type="button" wire:click="cancelOpname({{ $opname->id }})" wire:confirm="Yakin ingin membatalkan sesi stock opname ini?" class="p-1 text-rose-500 hover:text-rose-700 transition-colors" title="Batalkan Sesi">
                                        <span class="material-symbols-rounded text-[20px]">cancel</span>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-sm text-apeiron-muted">
                            Belum ada dokumen sesi stock opname ditemukan.
                        </td>
                    </tr>
                @endforelse
            </x-data-table>
        </div>

        @if ($opnames->hasPages())
            <div class="px-6 py-4 border-t border-apeiron-border">
                {{ $opnames->links() }}
            </div>
        @endif
    </div>

    {{-- MODAL 1: Form Buat Sesi Opname --}}
    @if ($showFormModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/40 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl border border-apeiron-border" @click.outside="$wire.closeFormModal()">
                <div class="flex items-center justify-between pb-4 border-b border-apeiron-border mb-4">
                    <h5 class="text-lg font-bold text-apeiron-ink">Mulai Sesi Stock Opname Baru</h5>
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
                    <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-xs">
                        <p class="font-bold mb-1">Mekanisme Cut-Off & Sequence Pinning (OD-04):</p>
                        <p>Setelah sesi dibuat dan hitungan dimulai, saldo buku dan movement id akan di-pin. Transaksi penjualan kasir tetap dapat berlangsung, namun sistem akan memeriksa mutasi sela saat persetujuan akhir.</p>
                    </div>

                    <div class="flex items-center gap-3">
                        <input type="checkbox" id="all_items" wire:model.live="all_items" class="rounded border-apeiron-border text-apeiron-ink focus:ring-apeiron-ink">
                        <label for="all_items" class="text-sm font-semibold text-apeiron-ink cursor-pointer">
                            Hitung Seluruh Item Katalog Cabang (Produk & Bahan Baku)
                        </label>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-apeiron-ink uppercase tracking-wider mb-1">Catatan Dokumen</label>
                        <textarea wire:model="notes" rows="2" placeholder="Tujuan opname, area gudang, shift, dll." class="form-input w-full p-3 rounded-xl border border-apeiron-border text-sm"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-apeiron-border">
                    <x-button type="button" variant="secondary" wire:click="closeFormModal">Batal</x-button>
                    <x-button type="button" variant="primary" wire:click="saveOpname">Buat Draft Opname</x-button>
                </div>
            </div>
        </div>
    @endif

    {{-- MODAL 2: Input Hasil Hitung Fisik (Counting) --}}
    @if ($showCountModal && $activeOpname)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/40 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-4xl w-full p-6 shadow-2xl border border-apeiron-border" @click.outside="$wire.closeCountModal()">
                <div class="flex items-center justify-between pb-4 border-b border-apeiron-border mb-4">
                    <div>
                        <h5 class="text-lg font-bold text-apeiron-ink">Input Hasil Hitung Fisik #{{ $activeOpname->opname_number }}</h5>
                        <p class="text-xs text-apeiron-muted">Waktu Snapshot: {{ $activeOpname->snapshot_taken_at?->format('d M Y H:i:s') }}</p>
                    </div>
                    <button type="button" wire:click="closeCountModal" class="text-apeiron-muted hover:text-apeiron-ink">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <div class="max-h-96 overflow-y-auto mb-4">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-apeiron-surface text-xs font-semibold text-apeiron-muted uppercase">
                            <tr>
                                <th class="p-3">Item / Komoditas</th>
                                <th class="p-3 text-right">Saldo Snapshot</th>
                                <th class="p-3 text-right w-44">Hasil Fisik (Counted)</th>
                                <th class="p-3">Catatan / Alasan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-apeiron-border">
                            @foreach ($activeOpname->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-bold text-apeiron-ink">
                                            {{ $item->item_type === 'product' ? ($item->product?->product_name ?? $item->product?->name) : $item->ingredient?->name }}
                                        </div>
                                        <div class="text-xs text-apeiron-muted uppercase">{{ $item->item_type }}</div>
                                    </td>
                                    <td class="p-3 text-right font-medium text-apeiron-ink">
                                        {{ number_format((float) $item->snapshot_on_hand, 2) }}
                                    </td>
                                    <td class="p-3 text-right">
                                        <input type="number" step="0.0001" min="0" wire:model="countedQuantities.{{ $item->id }}" placeholder="0.00" class="form-input w-full p-2 text-right rounded-lg border border-apeiron-border text-sm font-semibold">
                                    </td>
                                    <td class="p-3">
                                        <input type="text" wire:model="countNotes.{{ $item->id }}" placeholder="Opsional..." class="form-input w-full p-2 rounded-lg border border-apeiron-border text-xs">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-apeiron-border">
                    <x-button type="button" variant="secondary" wire:click="closeCountModal">Tutup</x-button>
                    <x-button type="button" variant="primary" wire:click="saveCounts">Simpan Hitungan</x-button>
                </div>
            </div>
        </div>
    @endif

    {{-- MODAL 3: Drift Detection Alert & Item-Level Resolution (OD-04) --}}
    @if ($showDriftModal && !empty($driftedItems))
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-3xl w-full p-6 shadow-2xl border-2 border-rose-500">
                <div class="flex items-start gap-4 mb-4 pb-4 border-b border-rose-200">
                    <div class="p-3 rounded-2xl bg-rose-100 text-rose-700">
                        <span class="material-symbols-rounded text-3xl">warning</span>
                    </div>
                    <div>
                        <h5 class="text-lg font-bold text-rose-900">Drift Terdeteksi! (Penjualan / Mutasi Sela Terjadi)</h5>
                        <p class="text-xs text-rose-700 mt-1">
                            Sesuai keputusan arsitektur <strong>OD-04</strong>, persetujuan otomatis diblokir karena saldo buku telah bergeser sejak snapshot diambil. Manajer wajib memilih resolusi per-item:
                        </p>
                    </div>
                </div>

                <div class="space-y-4 max-h-96 overflow-y-auto mb-6">
                    @foreach ($driftedItems as $d)
                        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-xs text-apeiron-ink flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <div class="font-bold text-sm text-apeiron-ink">{{ $d['name'] }}</div>
                                <div class="text-apeiron-muted uppercase">{{ $d['item_type'] }} #{{ $d['item_id'] }}</div>
                                <div class="mt-2 grid grid-cols-3 gap-2">
                                    <div>Snapshot Buku: <strong class="text-amber-800">{{ $d['snapshot_on_hand'] }}</strong></div>
                                    <div>Saldo Terkini: <strong class="text-rose-800">{{ $d['current_on_hand'] }}</strong></div>
                                    <div>Pergeseran Sela: <strong>{{ $d['drift_delta'] }}</strong></div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <button type="button" wire:click="resolveDriftItem({{ $d['item_id'] }}, 'RE_SNAPSHOT')" class="px-3 py-2 bg-white hover:bg-apeiron-surface text-apeiron-ink font-semibold rounded-xl border border-apeiron-border shadow-sm">
                                    Hitung Ulang (Re-Snapshot)
                                </button>
                                <button type="button" wire:click="resolveDriftItem({{ $d['item_id'] }}, 'REBASE_OVERRIDE')" class="px-3 py-2 bg-rose-600 hover:bg-rose-700 text-white font-semibold rounded-xl shadow-sm">
                                    Rebase Override
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-apeiron-border">
                    <x-button type="button" variant="secondary" wire:click="$set('showDriftModal', false)">Tutup & Tinjau Nanti</x-button>
                </div>
            </div>
        </div>
    @endif

    {{-- MODAL 4: Detail View Sesi Opname --}}
    @if ($showDetailModal && $selectedOpname)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/40 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-4xl w-full p-6 shadow-2xl border border-apeiron-border" @click.outside="$wire.closeDetailModal()">
                <div class="flex items-center justify-between pb-4 border-b border-apeiron-border mb-4">
                    <div>
                        <h5 class="text-lg font-bold text-apeiron-ink">Detail Sesi Stock Opname: {{ $selectedOpname->opname_number }}</h5>
                        <p class="text-xs text-apeiron-muted">Status: {{ $selectedOpname->status->label() }}</p>
                    </div>
                    <button type="button" wire:click="closeDetailModal" class="text-apeiron-muted hover:text-apeiron-ink">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6 p-4 rounded-2xl bg-apeiron-surface text-xs">
                    <div>
                        <span class="text-apeiron-muted block">Snapshot Diambil</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedOpname->snapshot_taken_at?->format('d M Y H:i') ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-apeiron-muted block">Diajukan</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedOpname->submitted_at?->format('d M Y H:i') ?? '-' }} ({{ $selectedOpname->submitter->name ?? '-' }})</span>
                    </div>
                    <div>
                        <span class="text-apeiron-muted block">Disetujui</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedOpname->approved_at?->format('d M Y H:i') ?? '-' }} ({{ $selectedOpname->approver->name ?? '-' }})</span>
                    </div>
                    <div>
                        <span class="text-apeiron-muted block">Catatan</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedOpname->notes ?? '-' }}</span>
                    </div>
                </div>

                <div class="max-h-96 overflow-y-auto mb-4">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-apeiron-surface text-xs font-semibold text-apeiron-muted uppercase">
                            <tr>
                                <th class="p-3">Item</th>
                                <th class="p-3 text-right">Snapshot Buku</th>
                                <th class="p-3 text-right">Hitung Fisik</th>
                                <th class="p-3 text-right">Selisih (Variance)</th>
                                <th class="p-3 text-center">Resolusi Drift</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-apeiron-border">
                            @foreach ($selectedOpname->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-bold text-apeiron-ink">
                                            {{ $item->item_type === 'product' ? ($item->product?->product_name ?? $item->product?->name) : $item->ingredient?->name }}
                                        </div>
                                        <div class="text-xs text-apeiron-muted uppercase">{{ $item->item_type }}</div>
                                    </td>
                                    <td class="p-3 text-right font-medium text-apeiron-ink">
                                        {{ number_format((float) $item->snapshot_on_hand, 2) }}
                                    </td>
                                    <td class="p-3 text-right font-medium text-apeiron-ink">
                                        {{ $item->counted_quantity !== null ? number_format((float) $item->counted_quantity, 2) : '-' }}
                                    </td>
                                    <td class="p-3 text-right font-bold {{ (float) $item->variance_quantity < 0 ? 'text-rose-600' : ((float) $item->variance_quantity > 0 ? 'text-emerald-600' : 'text-apeiron-muted') }}">
                                        {{ $item->variance_quantity !== null ? number_format((float) $item->variance_quantity, 2) : '-' }}
                                    </td>
                                    <td class="p-3 text-center text-xs">
                                        <span class="px-2 py-1 rounded bg-apeiron-surface border border-apeiron-border">
                                            {{ $item->drift_resolution_mode->value }}
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
