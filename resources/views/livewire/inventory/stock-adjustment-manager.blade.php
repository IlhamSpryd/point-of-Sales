<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    {{-- Header & Aksi Utama --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Penyesuaian Stok (Stock Adjustment)</h4>
            <p class="text-sm font-medium text-apeiron-muted mt-1">
                Koreksi stok langsung terstruktur: Kerusakan (Damage), Kedaluwarsa (Expired), Susut (Shrinkage), & Limbah Produksi
            </p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
            <x-ui.button type="button" variant="primary" wire:click="openCreateModal" x-on:click="window.erpMotion?.pulseHighlight($el)">
                <span class="material-symbols-rounded text-[18px]">add</span> Buat Penyesuaian Baru
            </x-ui.button>
        </div>
    </div>

    {{-- Alert Notifikasi --}}
    @if (session()->has('message'))
        <x-ui.alert variant="success" class="mb-4">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="destructive" class="mb-4">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    {{-- Filter & Search Bar --}}
    <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mb-4">
        <div class="relative w-full sm:w-80">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari nomor penyesuaian, catatan..."
                   class="form-input w-full pl-10 pr-4 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink placeholder-apeiron-muted focus:outline-none focus:ring-2 focus:ring-apeiron-ink shadow-sm text-sm">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-apeiron-muted">
                <span class="material-symbols-rounded text-[18px]">search</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
            <div class="relative w-full sm:w-44">
                <select wire:model.live="statusFilter"
                        class="form-input w-full pl-4 pr-8 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink focus:outline-none focus:ring-2 focus:ring-apeiron-ink text-sm shadow-sm appearance-none">
                    <option value="ALL">Semua Status</option>
                    <option value="DRAFT">Draft</option>
                    <option value="POSTED">Sudah Diposting</option>
                    <option value="CANCELLED">Dibatalkan</option>
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-apeiron-muted">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>

            <div class="relative w-full sm:w-52">
                <select wire:model.live="reasonFilter"
                        class="form-input w-full pl-4 pr-8 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink focus:outline-none focus:ring-2 focus:ring-apeiron-ink text-sm shadow-sm appearance-none">
                    <option value="ALL">Semua Alasan</option>
                    @foreach ($reasons as $r)
                        <option value="{{ $r->value }}">{{ $r->label() }}</option>
                    @endforeach
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-apeiron-muted">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabel Stock Adjustments --}}
    <div class="card-surface overflow-hidden w-full shrink-0">
        <div class="overflow-x-auto">
            <x-data-table>
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">No. Dokumen</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Waktu Penyesuaian</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Alasan (Reason Code)</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Total Item</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Total Nilai</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Aksi</th>
                </x-slot:head>

                @forelse ($adjustments as $adjustment)
                    <tr class="hover:bg-apeiron-surface transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm font-bold text-apeiron-ink">{{ $adjustment->adjustment_number }}</span>
                            <div class="text-xs text-apeiron-muted">Dibuat: {{ $adjustment->creator->name ?? 'System' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-apeiron-ink">
                            {{ $adjustment->adjusted_at->format('d M Y H:i') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-apeiron-ink">
                            <span class="font-semibold">{{ $adjustment->reason_code->label() }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <x-ui.badge :variant="$adjustment->status->badgeType()">
                                {{ $adjustment->status->label() }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-apeiron-ink">
                            {{ $adjustment->items->count() }} item
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-apeiron-ink">
                            Rp {{ number_format((float) $adjustment->calculateTotalValue(), 2, ',', '.') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium space-x-2">
                            <button wire:click="viewDetails({{ $adjustment->id }})" class="text-apeiron-ink hover:text-apeiron-muted transition-colors" title="Lihat Detail">
                                <span class="material-symbols-rounded text-[18px]">visibility</span>
                            </button>

                            @if($adjustment->isDraft())
                                <button wire:click="post({{ $adjustment->id }})" wire:confirm="Posting penyesuaian stok ini ke saldo cabang?" class="text-emerald-600 hover:text-emerald-800 transition-colors" title="Posting ke Stok">
                                    <span class="material-symbols-rounded text-[18px]">task_alt</span>
                                </button>
                                <button wire:click="cancel({{ $adjustment->id }})" wire:confirm="Batalkan draft penyesuaian stok ini?" class="text-rose-500 hover:text-rose-700 transition-colors" title="Batalkan">
                                    <span class="material-symbols-rounded text-[18px]">cancel</span>
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-sm text-apeiron-muted">
                            Belum ada dokumen penyesuaian stok yang tercatat.
                        </td>
                    </tr>
                @endforelse
            </x-data-table>
        </div>
        @if ($adjustments->hasPages())
            <div class="p-4 border-t border-apeiron-border">
                {{ $adjustments->links() }}
            </div>
        @endif
    </div>

    {{-- Modal Buat / Edit Draft --}}
    <x-ui.modal wire:model="showFormModal" maxWidth="4xl">
        <x-slot:header>
            <h3 class="text-lg font-bold text-apeiron-ink">
                {{ $isEditing ? 'Edit Draft Penyesuaian Stok' : 'Buat Penyesuaian Stok Baru' }}
            </h3>
        </x-slot:header>
        <x-slot:content>
            <div class="py-2 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-apeiron-muted mb-1">Kode Alasan (Reason Code) *</label>
                        <select wire:model.live="reason_code"
                                class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                            @foreach ($reasons as $r)
                                <option value="{{ $r->value }}">{{ $r->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-apeiron-muted mb-1">Waktu Penyesuaian *</label>
                        <input type="datetime-local" wire:model="adjusted_at"
                               class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-apeiron-muted mb-1">Catatan Dokumen</label>
                    <textarea wire:model="notes" rows="2" placeholder="Catatan atau keterangan penyesuaian..."
                              class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm"></textarea>
                </div>

                {{-- Daftar Baris Item --}}
                <div class="pt-2">
                    <div class="flex items-center justify-between mb-2">
                        <h5 class="text-sm font-bold text-apeiron-ink">Daftar Item Disesuaikan</h5>
                        <button type="button" wire:click="addItemLine" class="text-xs font-semibold text-emerald-600 hover:text-emerald-800 flex items-center gap-1">
                            <span class="material-symbols-rounded text-[16px]">add_circle</span> Tambah Baris
                        </button>
                    </div>

                    <div class="space-y-3">
                        @foreach ($items as $index => $item)
                            <div class="p-3 rounded-2xl border border-apeiron-border bg-slate-50/50 space-y-2">
                                <div class="grid grid-cols-12 gap-2 items-center">
                                    <div class="col-span-12 sm:col-span-2">
                                        <select wire:model.live="items.{{ $index }}.item_type" class="w-full text-xs py-1.5 px-2 rounded-lg border border-apeiron-border bg-white">
                                            <option value="product">Produk</option>
                                            <option value="ingredient">Bahan Baku</option>
                                        </select>
                                    </div>

                                    <div class="col-span-12 sm:col-span-4">
                                        @if ($item['item_type'] === 'product')
                                            <select wire:model="items.{{ $index }}.product_id" class="w-full text-xs py-1.5 px-2 rounded-lg border border-apeiron-border bg-white">
                                                <option value="">Pilih Produk...</option>
                                                @foreach ($products as $prod)
                                                    <option value="{{ $prod->id }}">{{ $prod->product_name }} ({{ $prod->product_code ?: 'No Code' }})</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <select wire:model="items.{{ $index }}.ingredient_id" class="w-full text-xs py-1.5 px-2 rounded-lg border border-apeiron-border bg-white">
                                                <option value="">Pilih Bahan Baku...</option>
                                                @foreach ($ingredients as $ing)
                                                    <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </div>

                                    <div class="col-span-6 sm:col-span-2">
                                        <select wire:model="items.{{ $index }}.direction" class="w-full text-xs py-1.5 px-2 rounded-lg border border-apeiron-border bg-white font-semibold">
                                            <option value="OUT">Keluar (OUT)</option>
                                            <option value="IN">Masuk (IN)</option>
                                        </select>
                                    </div>

                                    <div class="col-span-6 sm:col-span-3">
                                        <input type="number" step="0.0001" min="0.0001" wire:model="items.{{ $index }}.quantity" placeholder="Qty" title="Kuantitas"
                                               class="w-full text-xs py-1.5 px-2 rounded-lg border border-apeiron-border bg-white text-right font-medium">
                                    </div>

                                    <div class="col-span-12 sm:col-span-1 text-right">
                                        <button type="button" wire:click="removeItemLine({{ $index }})" class="text-rose-500 hover:text-rose-700">
                                            <span class="material-symbols-rounded text-[18px]">delete</span>
                                        </button>
                                    </div>
                                </div>

                                <div class="grid grid-cols-12 gap-2 text-xs">
                                    <div class="col-span-12 sm:col-span-6">
                                        <input type="text" wire:model="items.{{ $index }}.reason_note" placeholder="Catatan spesifik baris (opsional)"
                                               class="w-full text-xs py-1 px-2 rounded-lg border border-apeiron-border bg-white placeholder-slate-400">
                                    </div>
                                    <div class="col-span-12 sm:col-span-6">
                                        <input type="number" step="0.0001" min="0" wire:model="items.{{ $index }}.unit_cost_snapshot" placeholder="Biaya satuan snapshot (0 = otomatis WAC)"
                                               class="w-full text-xs py-1 px-2 rounded-lg border border-apeiron-border bg-white text-right text-slate-700">
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-apeiron-border flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showFormModal', false)">
                    Batal
                </x-ui.button>
                <x-ui.button type="button" variant="primary" wire:click="saveDraft" x-on:click="window.erpMotion?.pulseHighlight($el)">
                    Simpan Draft
                </x-ui.button>
            </div>
        </x-slot:content>
    </x-ui.modal>

    {{-- Modal Detail Penyesuaian --}}
    <x-ui.modal wire:model="showDetailModal" maxWidth="4xl">
        <x-slot:header>
            @if ($selectedAdjustment)
                <div>
                    <h3 class="text-lg font-bold text-apeiron-ink">Detail Penyesuaian: {{ $selectedAdjustment->adjustment_number }}</h3>
                    <p class="text-xs text-apeiron-muted">Cabang: {{ $selectedAdjustment->store->name ?? '-' }} | Dibuat: {{ $selectedAdjustment->created_at->format('d M Y H:i') }}</p>
                </div>
            @endif
        </x-slot:header>
        <x-slot:content>
            @if ($selectedAdjustment)
                <div class="py-2 space-y-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 rounded-2xl bg-slate-50 text-xs">
                        <div>
                            <span class="text-apeiron-muted block">Status:</span>
                            <x-ui.badge :variant="$selectedAdjustment->status->badgeType()">
                                {{ $selectedAdjustment->status->label() }}
                            </x-ui.badge>
                        </div>
                        <div>
                            <span class="text-apeiron-muted block">Kode Alasan:</span>
                            <span class="font-semibold text-apeiron-ink">{{ $selectedAdjustment->reason_code->label() }}</span>
                        </div>
                        <div>
                            <span class="text-apeiron-muted block">Waktu Penyesuaian:</span>
                            <span class="font-semibold text-apeiron-ink">{{ $selectedAdjustment->adjusted_at->format('d M Y H:i') }}</span>
                        </div>
                        <div>
                            <span class="text-apeiron-muted block">Diposting Oleh:</span>
                            <span class="font-semibold text-apeiron-ink">{{ $selectedAdjustment->poster->name ?? '-' }}</span>
                        </div>
                    </div>

                    @if($selectedAdjustment->notes)
                        <div class="text-xs text-apeiron-ink p-3 rounded-xl bg-slate-50 border border-apeiron-border">
                            <span class="font-semibold block text-apeiron-muted">Catatan:</span>
                            {{ $selectedAdjustment->notes }}
                        </div>
                    @endif

                    <div class="overflow-x-auto border border-apeiron-border rounded-2xl">
                        <table class="min-w-full divide-y divide-apeiron-border text-xs">
                            <thead class="bg-slate-50 text-apeiron-muted uppercase">
                                <tr>
                                    <th class="px-4 py-3 text-left">Tipe</th>
                                    <th class="px-4 py-3 text-left">Nama Item</th>
                                    <th class="px-4 py-3 text-center">Arah</th>
                                    <th class="px-4 py-3 text-right">Kuantitas</th>
                                    <th class="px-4 py-3 text-right">Biaya Satuan</th>
                                    <th class="px-4 py-3 text-right">Subtotal</th>
                                    <th class="px-4 py-3 text-left">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-apeiron-border">
                                @foreach ($selectedAdjustment->items as $item)
                                    <tr>
                                        <td class="px-4 py-2 font-medium capitalize">{{ $item->item_type }}</td>
                                        <td class="px-4 py-2 font-semibold text-apeiron-ink">{{ $item->item_name }}</td>
                                        <td class="px-4 py-2 text-center font-bold {{ $item->direction === 'IN' ? 'text-emerald-600' : 'text-rose-600' }}">
                                            {{ $item->direction }}
                                        </td>
                                        <td class="px-4 py-2 text-right font-medium">{{ number_format((float) $item->quantity, 4) }}</td>
                                        <td class="px-4 py-2 text-right font-medium">Rp {{ number_format((float) $item->unit_cost_snapshot, 2, ',', '.') }}</td>
                                        <td class="px-4 py-2 text-right font-bold text-apeiron-ink">Rp {{ number_format((float) $item->subtotal, 2, ',', '.') }}</td>
                                        <td class="px-4 py-2 text-apeiron-muted">{{ $item->reason_note ?: '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-slate-50 font-bold">
                                <tr>
                                    <td colspan="5" class="px-4 py-3 text-right text-apeiron-ink uppercase">Total Nilai Penyesuaian:</td>
                                    <td class="px-4 py-3 text-right text-apeiron-ink">Rp {{ number_format((float) $selectedAdjustment->calculateTotalValue(), 2, ',', '.') }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="pt-4 border-t border-apeiron-border flex justify-end">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showDetailModal', false)">
                        Tutup
                    </x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>
</div>
