<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <x-erp.module-sub-nav module="inventory" />

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-[18px] leading-tight font-bold text-foreground tracking-tight">
                Discrete Manufacturing Orders (MO) & Kitchen Prep
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                Perencanaan & eksekusi produksi barang jadi dan bahan olahan (kitchen prep) dengan pemotongan stok resep BOM otomatis.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="$set('isCreateModalOpen', true)"
                class="inline-flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary/90 text-primary-foreground text-sm font-semibold rounded-xl shadow-xs transition-colors">
                <span class="material-symbols-rounded text-[20px]">add</span>
                Buat Perintah Kerja (MO)
            </button>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="p-4 bg-positive border border-positive/20 rounded-xl flex items-center gap-3 text-positive text-sm font-medium">
            <span class="material-symbols-rounded text-positive text-[20px]">check_circle</span>
            {{ session('message') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="p-4 bg-destructive/10 border border-destructive/20 rounded-xl flex items-center gap-3 text-destructive text-sm font-medium">
            <span class="material-symbols-rounded text-destructive text-[20px]">error</span>
            {{ session('error') }}
        </div>
    @endif

    <!-- Search & Filter Bar -->
    <x-erp.filter-bar
        search-placeholder="Cari nomor MO, nama produk, atau bahan prep..."
        search-model="search"
        :reset-method="null"
    >
        <x-slot:filters>
            <select wire:model.live="statusFilter"
                class="bg-card border border-border text-foreground text-xs rounded-xl px-3 py-2 font-semibold focus:outline-none focus:ring-1 focus:ring-ring">
                <option value="ALL">Semua Status</option>
                <option value="PLANNED">Direncanakan (Planned)</option>
                <option value="IN_PROGRESS">Dalam Proses (In Progress)</option>
                <option value="COMPLETED">Selesai (Completed)</option>
            </select>
        </x-slot:filters>
    </x-erp.filter-bar>

    <!-- Orders Table -->
    <div class="bg-card rounded-2xl border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground">
                <thead class="bg-muted/50 text-muted-foreground font-semibold text-2xs uppercase tracking-wider border-b border-border">
                    <tr>
                        <th scope="col" class="px-6 py-3.5">Nomor Perintah Kerja</th>
                        <th scope="col" class="px-6 py-3.5">Target Produk / Prep Item</th>
                        <th scope="col" class="px-6 py-3.5">Target Jumlah</th>
                        <th scope="col" class="px-6 py-3.5">Jumlah Selesai</th>
                        <th scope="col" class="px-6 py-3.5">Status</th>
                        <th scope="col" class="px-6 py-3.5 text-right">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($orders as $mo)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-6 py-4 font-mono font-medium text-xs text-foreground">
                                {{ $mo->order_number }}
                            </td>
                            <td class="px-6 py-4 font-medium text-foreground">
                                @if ($mo->destination_type === 'ingredient')
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-rounded text-warning text-[18px]">blender</span>
                                        <span>{{ $mo->destinationIngredient?->name ?? 'Prep #' . $mo->destination_ingredient_id }}</span>
                                        <span class="text-2xs text-warning font-semibold">(Prep)</span>
                                    </div>
                                @else
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-rounded text-primary text-[18px]">inventory_2</span>
                                        <span>{{ $mo->product?->product_name ?? $mo->product?->name ?? 'N/A' }}</span>
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 font-semibold text-foreground">
                                {{ number_format((float) $mo->planned_quantity, 2) }}
                            </td>
                            <td class="px-6 py-4 font-semibold text-foreground">
                                {{ number_format((float) $mo->produced_quantity, 2) }}
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $moDot = match($mo->status) {
                                        'COMPLETED' => 'bg-positive',
                                        'IN_PROGRESS' => 'bg-accent',
                                        'PLANNED' => 'bg-warning',
                                        'CANCELLED' => 'bg-destructive',
                                        default => 'bg-neutral',
                                    };
                                    $moLabel = match($mo->status) {
                                        'COMPLETED' => 'Selesai',
                                        'IN_PROGRESS' => 'Dalam Proses',
                                        'PLANNED' => 'Direncanakan',
                                        default => $mo->status,
                                    };
                                @endphp
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $moDot }}"></span>
                                    {{ $moLabel }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                @if ($mo->status === 'PLANNED')
                                    <x-ui.table-actions>
                                        <x-ui.table-action-item icon="play_arrow" wire:click="startOrder({{ $mo->id }})" aria-label="Mulai Perintah Kerja">
                                            Mulai Produksi
                                        </x-ui.table-action-item>
                                    </x-ui.table-actions>
                                @elseif ($mo->status === 'IN_PROGRESS')
                                    <x-ui.table-actions>
                                        <x-ui.table-action-item icon="check" wire:click="completeOrder({{ $mo->id }})" aria-label="Selesaikan & Potong Bahan">
                                            Selesaikan & Potong BOM
                                        </x-ui.table-action-item>
                                    </x-ui.table-actions>
                                @else
                                    <span class="text-xs text-muted-foreground">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-muted-foreground">
                                <span class="material-symbols-rounded text-[32px] block mb-2 text-muted-foreground">precision_manufacturing</span>
                                Belum ada perintah kerja manufaktur. Klik "Buat Perintah Kerja (MO)" untuk memulai.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($orders->hasPages())
            <div class="p-4 border-t border-border">
                {{ $orders->links() }}
            </div>
        @endif
    </div>

    <!-- Create MO Modal -->
    @if ($isCreateModalOpen)
        <x-ui.modal wire:model="isCreateModalOpen" title="Buat Perintah Kerja Manufaktur (MO)" maxWidth="md" onClose="$wire.set('isCreateModalOpen', false)">
            <div class="space-y-4">
                {{-- Toggle Order Type: Product vs Prep --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1.5">Jenis Hasil Produksi</label>
                    <div class="grid grid-cols-2 gap-2 p-1 bg-muted rounded-xl text-xs font-semibold">
                        <button type="button" wire:click="$set('orderType', 'product')"
                            class="py-1.5 rounded-lg transition-colors {{ $orderType === 'product' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                            Barang Jadi (Produk)
                        </button>
                        <button type="button" wire:click="$set('orderType', 'prep')"
                            class="py-1.5 rounded-lg transition-colors {{ $orderType === 'prep' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                            Bahan Olahan (Kitchen Prep)
                        </button>
                    </div>
                </div>

                @if ($orderType === 'product')
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">Target Produk</label>
                        <select wire:model="selectedProductId" class="w-full bg-card border border-border rounded-xl text-sm p-2 text-foreground focus:ring-1 focus:ring-ring">
                            <option value="">-- Pilih Produk Jadi --</option>
                            @foreach ($products as $prod)
                                <option value="{{ $prod->id }}">{{ $prod->product_name ?? $prod->name }}</option>
                            @endforeach
                        </select>
                        @error('selectedProductId') <span class="text-2xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                    </div>
                @else
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">Target Bahan Olahan (Prep Item)</label>
                        <select wire:model="selectedIngredientId" class="w-full bg-card border border-border rounded-xl text-sm p-2 text-foreground focus:ring-1 focus:ring-ring">
                            <option value="">-- Pilih Bahan Olahan --</option>
                            @foreach ($ingredients as $ing)
                                <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>
                            @endforeach
                        </select>
                        @error('selectedIngredientId') <span class="text-2xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                    </div>
                @endif

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Target Jumlah Produksi</label>
                    <input type="number" step="0.0001" wire:model="plannedQuantity" min="0.0001" class="w-full bg-card border border-border rounded-xl text-sm p-2 text-foreground focus:ring-1 focus:ring-ring">
                    @error('plannedQuantity') <span class="text-2xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Tanggal Rencana Mulai</label>
                    <input type="date" wire:model="plannedStartDate" class="w-full bg-card border border-border rounded-xl text-sm p-2 text-foreground focus:ring-1 focus:ring-ring">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Catatan / Instruksi Kerja</label>
                    <textarea wire:model="notes" rows="2" class="w-full bg-card border border-border rounded-xl text-sm p-2 text-foreground focus:ring-1 focus:ring-ring" placeholder="Catatan opsional..."></textarea>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex justify-end gap-2 w-full">
                    <button type="button" wire:click="$set('isCreateModalOpen', false)" class="px-4 py-2 text-muted-foreground hover:text-foreground rounded-xl text-sm font-medium transition-colors">
                        Batal
                    </button>
                    <button type="button" wire:click="createOrder" class="px-4 py-2 bg-primary text-primary-foreground rounded-xl text-sm font-semibold hover:bg-primary/90 transition-colors shadow-xs">
                        Simpan Perintah Kerja
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
