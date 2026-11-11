<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">Fixed Assets & Depreciation</h1>
            <p class="text-sm text-muted-foreground mt-1">Pengelolaan aset tetap fisik, kapitalisasi biaya, dan jadwal depresiasi buku besar.</p>
        </div>
        <div class="flex items-center gap-3">
            <x-ui.button wire:click="openCreateModal" variant="primary" class="inline-flex items-center gap-2">
                <span class="material-symbols-rounded text-base">add</span>
                <span>Daftar Aset Baru</span>
            </x-ui.button>
        </div>
    </div>

    <!-- Flash Messages -->
    @if (session()->has('success'))
        <div class="p-4 rounded-xl bg-success/10 border border-success/20 text-success text-sm flex items-center gap-2">
            <span class="material-symbols-rounded text-base">check_circle</span>
            <span>{{ session('success') }}</span>
        </div>
    @endif
    @if (session()->has('error'))
        <div class="p-4 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive text-sm flex items-center gap-2">
            <span class="material-symbols-rounded text-base">error</span>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <!-- Filter & Toolbar -->
    <div class="p-4 rounded-2xl bg-card border border-border flex flex-col md:flex-row md:items-center justify-between gap-4 shadow-xs">
        <div class="flex-1 max-w-md">
            <div class="relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground text-lg">search</span>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari kode atau nama aset..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-border bg-background focus:outline-hidden focus:ring-2 focus:ring-ring/20 text-foreground">
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.select wire:model.live="statusFilter" class="w-auto text-sm">
                <option value="">Semua Status</option>
                <option value="DRAFT">Draft</option>
                <option value="ACTIVE">Aktif (Depresiasi Berjalan)</option>
                <option value="FULLY_DEPRECIATED">Depresiasi Penuh</option>
                <option value="DISPOSED">Telah Didisposisi</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-hidden rounded-2xl border border-border bg-card shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="border-b border-border bg-muted/40 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                        <th class="py-3.5 px-6">Kode & Nama Aset</th>
                        <th class="py-3.5 px-6">Kategori & Cabang</th>
                        <th class="py-3.5 px-6">Tgl Perolehan</th>
                        <th class="py-3.5 px-6 text-right">Nilai Perolehan</th>
                        <th class="py-3.5 px-6 text-right">Nilai Buku Bersih</th>
                        <th class="py-3.5 px-6">Status</th>
                        <th class="py-3.5 px-6 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($assets as $asset)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="py-4 px-6">
                                <div class="font-medium text-foreground">{{ $asset->asset_name }}</div>
                                <div class="text-xs text-muted-foreground font-mono mt-0.5">{{ $asset->asset_code }}</div>
                            </td>
                            <td class="py-4 px-6">
                                <div class="text-foreground">{{ $asset->category?->category_name ?? '—' }}</div>
                                <div class="text-xs text-muted-foreground">{{ $asset->store?->name ?? 'Semua Cabang' }}</div>
                            </td>
                            <td class="py-4 px-6 text-foreground font-mono">
                                {{ \Carbon\Carbon::parse($asset->acquisition_date)->format('d/m/Y') }}
                            </td>
                            <td class="py-4 px-6 text-right tabular-nums font-medium text-foreground">
                                Rp {{ number_format((float) $asset->acquisition_cost, 2, ',', '.') }}
                            </td>
                            <td class="py-4 px-6 text-right tabular-nums font-semibold text-foreground">
                                Rp {{ number_format((float) $asset->net_book_value, 2, ',', '.') }}
                            </td>
                            <td class="py-4 px-6">
                                @php
                                    $dotColor = match($asset->status->value) {
                                        'ACTIVE' => 'bg-success',
                                        'DRAFT' => 'bg-warning',
                                        'DISPOSED' => 'bg-destructive',
                                        default => 'bg-muted-foreground',
                                    };
                                @endphp
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $dotColor }}"></span>
                                    {{ $asset->status->value }}
                                </span>
                            </td>
                            <td class="py-4 px-6 text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-2">
                                    <button type="button" wire:click="viewDetails({{ $asset->id }})" class="p-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors" title="Lihat Jadwal Depresiasi">
                                        <span class="material-symbols-rounded text-base">calendar_month</span>
                                    </button>
                                    @if ($asset->status->value === 'DRAFT')
                                        <button type="button" wire:click="activateAsset({{ $asset->id }})" wire:confirm="Aktivasi aset tetap ini? Entri jurnal perolehan dan depresiasi akan dijadwalkan." class="px-2.5 py-1 rounded-lg bg-primary text-primary-foreground text-xs font-semibold hover:opacity-90 transition-opacity">
                                            Aktivasi
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center text-muted-foreground">
                                Belum ada aset tetap yang terdaftar. Klik tombol "Daftar Aset Baru" untuk menambahkan aset pertama Anda.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($assets->hasPages())
            <div class="p-4 border-t border-border">
                {{ $assets->links() }}
            </div>
        @endif
    </div>

    <!-- Modal Registrasi Aset Baru -->
    @if ($showCreateModal)
        <x-ui.modal wire:model="showCreateModal" title="Daftarkan Aset Tetap Baru" maxWidth="2xl" onClose="$wire.set('showCreateModal', false)">
            <div class="space-y-4 text-sm">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="assetCode">Kode Aset *</x-ui.label>
                        <x-ui.input id="assetCode" wire:model="assetCode" />
                        @error('assetCode') <span class="text-xs text-destructive">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-ui.label for="categoryId">Kategori Aset *</x-ui.label>
                        <x-ui.select id="categoryId" wire:model="categoryId">
                            <option value="">Pilih Kategori</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->category_name }}</option>
                            @endforeach
                        </x-ui.select>
                        @error('categoryId') <span class="text-xs text-destructive">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <x-ui.label for="assetName">Nama Aset Tetap *</x-ui.label>
                    <x-ui.input id="assetName" wire:model="assetName" placeholder="e.g. Mesin Espresso La Marzocco Linea PB" />
                    @error('assetName') <span class="text-xs text-destructive">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <x-ui.label for="acquisitionDate">Tanggal Perolehan *</x-ui.label>
                        <x-ui.input type="date" id="acquisitionDate" wire:model="acquisitionDate" />
                        @error('acquisitionDate') <span class="text-xs text-destructive">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-ui.label for="acquisitionCost">Biaya Perolehan (IDR) *</x-ui.label>
                        <x-ui.input type="number" step="0.01" id="acquisitionCost" wire:model="acquisitionCost" placeholder="15000000" />
                        @error('acquisitionCost') <span class="text-xs text-destructive">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-ui.label for="salvageValue">Nilai Residu *</x-ui.label>
                        <x-ui.input type="number" step="0.01" id="salvageValue" wire:model="salvageValue" />
                        @error('salvageValue') <span class="text-xs text-destructive">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="usefulLifeMonths">Masa Manfaat (Bulan) *</x-ui.label>
                        <x-ui.input type="number" id="usefulLifeMonths" wire:model="usefulLifeMonths" />
                        @error('usefulLifeMonths') <span class="text-xs text-destructive">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-ui.label for="locationNote">Lokasi / Keterangan</x-ui.label>
                        <x-ui.input id="locationNote" wire:model="locationNote" placeholder="e.g. Barista Counter Store Central" />
                    </div>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex justify-end gap-3 w-full">
                    <x-ui.button type="button" variant="outline" wire:click="$set('showCreateModal', false)">Batal</x-ui.button>
                    <x-ui.button type="button" variant="primary" wire:click="saveAsset">Simpan Sebagai Draft</x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <!-- Modal Detail & Jadwal Depresiasi -->
    @if ($showDetailModal && $selectedAsset)
        <x-ui.modal wire:model="showDetailModal" title="{{ $selectedAsset->asset_name }}" maxWidth="3xl" onClose="$wire.set('showDetailModal', false)">
            <div class="space-y-4">
                <p class="text-xs text-muted-foreground font-mono">{{ $selectedAsset->asset_code }} &bull; Nilai Perolehan: Rp {{ number_format((float) $selectedAsset->acquisition_cost, 2, ',', '.') }}</p>

                <div class="space-y-2">
                    <div class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Jadwal Depresiasi Terjadwal</div>
                    <div class="max-h-72 overflow-y-auto border border-border rounded-xl">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-muted/40 border-b border-border text-muted-foreground font-semibold">
                                <tr>
                                    <th class="p-2.5">Bulan Ke</th>
                                    <th class="p-2.5">Tanggal Buku</th>
                                    <th class="p-2.5 text-right">Beban Depresiasi</th>
                                    <th class="p-2.5 text-right">Akumulasi Depresiasi</th>
                                    <th class="p-2.5 text-right">Nilai Buku Akhir</th>
                                    <th class="p-2.5">Status Posting</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($selectedAsset->schedules as $sched)
                                    <tr class="hover:bg-muted/20">
                                        <td class="p-2.5">{{ $sched->period_month }} / {{ $sched->period_year }}</td>
                                        <td class="p-2.5 font-mono">{{ $sched->scheduled_date }}</td>
                                        <td class="p-2.5 text-right tabular-nums">Rp {{ number_format((float) $sched->depreciation_amount, 2, ',', '.') }}</td>
                                        <td class="p-2.5 text-right tabular-nums">Rp {{ number_format((float) $sched->accumulated_depreciation_after, 2, ',', '.') }}</td>
                                        <td class="p-2.5 text-right tabular-nums font-semibold">Rp {{ number_format((float) $sched->book_value_after, 2, ',', '.') }}</td>
                                        <td class="p-2.5">
                                            <span class="inline-flex items-center gap-1 text-[11px] font-medium text-foreground">
                                                <span class="w-1.5 h-1.5 rounded-full {{ $sched->status->value === 'POSTED' ? 'bg-success' : 'bg-muted-foreground' }}"></span>
                                                {{ $sched->status->value }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="p-6 text-center text-muted-foreground">
                                            Jadwal depresiasi belum digenerate. Klik "Aktivasi" pada daftar aset untuk menjadwalkan depresiasi.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex justify-end w-full">
                    <x-ui.button type="button" variant="outline" wire:click="$set('showDetailModal', false)">Tutup</x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
