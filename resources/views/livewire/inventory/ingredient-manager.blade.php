<div>
    @if($showModal)
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-yovel-ink tracking-tight">
                {{ $isEditing ? 'Ubah Bahan Baku' : 'Tambah Bahan Baku' }}
            </h4>
            <p class="text-sm font-medium text-yovel-muted mt-1">
                {{ $isEditing ? 'Perbarui informasi data bahan baku' : 'Tambahkan bahan baku baru ke dalam inventaris' }}
            </p>
        </div>
        <x-button type="button" variant="secondary" wire:click="$set('showModal', false)">
            <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
        </x-button>
    </div>

    <div class="card-surface overflow-hidden w-full shrink-0">
        <form wire:submit.prevent="save" class="p-6 md:p-8 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="ingredient_code">Kode Bahan (Opsional)</x-form-label>
                    <x-form-input type="text" id="ingredient_code" wire:model="ingredient_code" class="{{ $errors->has('ingredient_code') ? 'input-error' : '' }}" placeholder="Contoh: RM-001" />
                    @error('ingredient_code') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>
                
                <div>
                    <x-form-label for="name">Nama Bahan <span class="text-rose-500">*</span></x-form-label>
                    <x-form-input type="text" id="name" wire:model="name" required class="{{ $errors->has('name') ? 'input-error' : '' }}" placeholder="Masukkan nama bahan baku" />
                    @error('name') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <x-form-label for="unit">Satuan Dasar <span class="text-rose-500">*</span></x-form-label>
                    <x-form-input type="text" id="unit" wire:model="unit" placeholder="g, ml, pcs..." required class="{{ $errors->has('unit') ? 'input-error' : '' }}" />
                    @error('unit') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-form-label for="cost_per_unit">Harga per Satuan (Rp) <span class="text-rose-500">*</span></x-form-label>
                    <x-form-input type="number" id="cost_per_unit" step="0.01" wire:model="cost_per_unit" required class="{{ $errors->has('cost_per_unit') ? 'input-error' : '' }}" placeholder="0" />
                    @error('cost_per_unit') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-form-label for="reorder_level">Batas Minimum (Reorder Level) <span class="text-rose-500">*</span></x-form-label>
                    <x-form-input type="number" id="reorder_level" step="0.0001" wire:model="reorder_level" required class="{{ $errors->has('reorder_level') ? 'input-error' : '' }}" placeholder="0" />
                    @error('reorder_level') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center">
                <label class="flex items-center cursor-pointer">
                    <div class="relative">
                        <input type="checkbox" wire:model="is_active" class="sr-only">
                        <div class="block bg-primary-200 w-10 h-6 rounded-full transition-colors duration-300"></div>
                        <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform duration-300 shadow-sm"></div>
                    </div>
                    <style>
                        input[type="checkbox"]:checked ~ .block { background-color: #10b981; }
                        input[type="checkbox"]:checked ~ .dot { transform: translateX(100%); }
                    </style>
                    <div class="ml-3 text-sm font-semibold text-[#37352F]">
                        Bahan Baku Aktif
                    </div>
                </label>
            </div>

            <div class="pt-4 border-t border-yovel-border flex justify-end gap-3">
                <x-button type="button" variant="secondary" wire:click="$set('showModal', false)">
                    Batal
                </x-button>
                <x-button type="submit" variant="primary">
                    <span class="material-symbols-rounded text-[18px]">save</span> Simpan
                </x-button>
            </div>
        </form>
    </div>
    @else
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-yovel-ink tracking-tight">Manajemen Bahan Baku</h4>
            <p class="text-sm font-medium text-yovel-muted mt-1">Kelola data bahan baku dan sesuaikan stok (Bill of Materials)</p>
        </div>
        <div class="flex items-center gap-3 w-full sm:w-auto">
            <div class="w-full sm:w-64">
                <x-search-input wire:model.live.debounce.300ms="search" placeholder="Cari bahan baku..." />
            </div>
            <x-button type="button" variant="primary" wire:click="create">
                <span class="material-symbols-rounded text-[18px]">add</span> Tambah
            </x-button>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="mb-6 p-4 rounded-lg bg-emerald-50 border border-emerald-200 flex items-start gap-3">
            <span class="material-symbols-rounded text-emerald-600 mt-0.5">check_circle</span>
            <div>
                <h5 class="text-sm font-semibold text-emerald-800">Berhasil</h5>
                <p class="text-sm text-emerald-600 mt-0.5">{{ session('success') }}</p>
            </div>
        </div>
    @endif

    <div class="card-surface flex flex-col flex-1 min-h-0 w-full shrink-0">
        {{-- Desktop/tablet-landscape: existing table --}}
        <div class="hidden lg:block overflow-x-auto flex-1 table-scroll-shadow">
            <table class="data-table relative w-full text-left">
                <thead class="sticky top-0 z-10 shadow-sm">
                    <tr class="bg-yovel-surface">
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider">Bahan Baku</th>
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider">Stok Saat Ini</th>
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider">Harga/Unit</th>
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-yovel-border bg-white">
                    @forelse ($ingredients as $item)
                        <tr class="hover:bg-yovel-surface/50 transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <span class="text-sm font-semibold text-yovel-ink">{{ $item->name }}</span>
                                    <span class="text-xs text-yovel-muted">{{ $item->ingredient_code ?? '-' }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium {{ $item->current_stock <= $item->reorder_level ? 'text-rose-600' : 'text-yovel-ink' }}">
                                        {{ number_format($item->current_stock, 2) }} {{ $item->unit }}
                                    </span>
                                    @if($item->current_stock <= $item->reorder_level)
                                        <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-700" title="Stok Menipis">!</span>
                                    @endif
                                </div>
                                <div class="text-[10px] text-yovel-muted mt-0.5">Min: {{ number_format($item->reorder_level, 2) }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm text-yovel-ink">Rp {{ number_format($item->cost_per_unit, 0, ',', '.') }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($item->is_active)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200/50">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Aktif
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-yovel-surface text-yovel-muted border border-yovel-border">
                                        <span class="w-1.5 h-1.5 rounded-full bg-yovel-muted"></span> Nonaktif
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right space-x-2">
                                <button wire:click="adjustStock({{ $item->id }})" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-emerald-600 hover:bg-emerald-50 transition-colors" title="Sesuaikan Stok">
                                    <span class="material-symbols-rounded text-[18px]">inventory</span>
                                </button>
                                <button wire:click="edit({{ $item->id }})" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-blue-600 hover:bg-blue-50 transition-colors" title="Ubah">
                                    <span class="material-symbols-rounded text-[18px]">edit</span>
                                </button>
                                <button type="button"
                                    data-swal-delete
                                    data-swal-title="Hapus Bahan Baku?"
                                    data-swal-text="Bahan baku &quot;{{ $item->name }}&quot; akan dihapus permanen."
                                    data-wire-action="delete({{ $item->id }})"
                                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-rose-600 hover:bg-rose-50 transition-colors" title="Hapus">
                                    <span class="material-symbols-rounded text-[18px]">delete</span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <x-empty-state icon="inventory_2" title="Belum Ada Bahan Baku" description="Tambahkan bahan baku baru untuk mulai mengelola inventory dan resep produk." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile/tablet-portrait: card list --}}
        <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
            @forelse($ingredients as $item)
                <div class="card-surface p-4">
                    <div class="flex justify-between items-start mb-2">
                        <div class="flex flex-col">
                            <span class="font-bold text-yovel-ink text-sm">{{ $item->name }}</span>
                            <span class="text-xs text-yovel-muted">{{ $item->ingredient_code ?? '-' }}</span>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            @if($item->is_active)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200/50">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Aktif
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium bg-yovel-surface text-yovel-muted border border-yovel-border">
                                    <span class="w-1.5 h-1.5 rounded-full bg-yovel-muted"></span> Nonaktif
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center justify-between mt-3 pt-3 border-t border-yovel-border">
                        <div class="flex-1">
                            <span class="text-[11px] font-semibold text-yovel-muted uppercase tracking-wider block mb-0.5">Stok: <span class="{{ $item->current_stock <= $item->reorder_level ? 'text-rose-600' : 'text-yovel-ink' }}">{{ number_format($item->current_stock, 2) }} {{ $item->unit }}</span></span>
                            <span class="text-xs text-yovel-ink">Rp {{ number_format($item->cost_per_unit, 0, ',', '.') }} / {{ $item->unit }}</span>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <button wire:click="adjustStock({{ $item->id }})" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-emerald-600 hover:bg-emerald-50 transition-colors" title="Sesuaikan Stok">
                                <span class="material-symbols-rounded text-[18px]">inventory</span>
                            </button>
                            <button wire:click="edit({{ $item->id }})" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-blue-600 hover:bg-blue-50 transition-colors" title="Ubah">
                                <span class="material-symbols-rounded text-[18px]">edit</span>
                            </button>
                            <button type="button"
                                data-swal-delete
                                data-swal-title="Hapus Bahan Baku?"
                                data-swal-text="Bahan baku &quot;{{ $item->name }}&quot; akan dihapus permanen."
                                data-wire-action="delete({{ $item->id }})"
                                class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-rose-600 hover:bg-rose-50 transition-colors" title="Hapus">
                                <span class="material-symbols-rounded text-[18px]">delete</span>
                            </button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center">
                    <x-empty-state icon="inventory_2" title="Belum Ada Bahan Baku" description="Tambahkan bahan baku baru untuk mulai mengelola inventory dan resep produk." />
                </div>
            @endforelse
        </div>
        @if($ingredients->hasPages())
            <div class="p-4 border-t border-yovel-border shrink-0">
                {{ $ingredients->links() }}
            </div>
        @endif
    </div>
    @endif

    <!-- Modal Adjust Stock -->
    @if($showAdjustModal)
    <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm transition-opacity" aria-hidden="true" wire:click="$set('showAdjustModal', false)"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="relative z-10 inline-block align-bottom bg-white rounded-xl text-left shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg w-full border border-yovel-border max-h-[90vh] overflow-y-auto">
                <form wire:submit.prevent="saveAdjustment">
                    <div class="bg-white px-6 pt-6 pb-6">
                        <div class="mb-5 flex justify-between items-center">
                            <h3 class="text-lg leading-6 font-bold text-yovel-ink" id="modal-title">
                                Sesuaikan Stok
                            </h3>
                            <button type="button" wire:click="$set('showAdjustModal', false)" class="text-yovel-muted hover:text-yovel-ink">
                                <span class="material-symbols-rounded text-[20px]">close</span>
                            </button>
                        </div>
                        
                        <div class="space-y-6">
                            <div>
                                <x-form-label for="adjustType">Tipe Mutasi <span class="text-rose-500">*</span></x-form-label>
                                <div class="relative">
                                    <select id="adjustType" wire:model="adjustType" class="form-input w-full px-4 py-2.5 rounded-xl border border-yovel-border bg-white text-yovel-ink appearance-none focus:outline-none focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink transition-all duration-200 shadow-sm {{ $errors->has('adjustType') ? 'input-error' : '' }}">
                                        <option value="purchase_receipt">Barang Masuk (Pembelian)</option>
                                        <option value="adjustment">Koreksi Manual (+ / -)</option>
                                        <option value="waste">Barang Rusak / Waste (-)</option>
                                    </select>
                                    <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-yovel-muted">
                                        <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                                    </div>
                                </div>
                                @error('adjustType') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <x-form-label for="adjustQuantity">Jumlah <span class="text-rose-500">*</span></x-form-label>
                                <x-form-input type="number" id="adjustQuantity" step="0.0001" wire:model="adjustQuantity" placeholder="Gunakan minus (-) untuk pengurangan jika tipe Koreksi Manual" required class="{{ $errors->has('adjustQuantity') ? 'input-error' : '' }}" />
                                @error('adjustQuantity') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <x-form-label for="adjustReason">Keterangan / Alasan (Opsional)</x-form-label>
                                <textarea id="adjustReason" wire:model="adjustReason" rows="3" class="form-input w-full px-4 py-2.5 rounded-xl border border-yovel-border bg-white text-yovel-ink placeholder-yovel-muted focus:outline-none focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink transition-all duration-200 shadow-sm {{ $errors->has('adjustReason') ? 'input-error' : '' }}" placeholder="Catatan tambahan..."></textarea>
                                @error('adjustReason') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="bg-yovel-surface px-6 py-4 border-t border-yovel-border flex justify-end gap-3 rounded-b-xl">
                        <x-button type="button" variant="secondary" wire:click="$set('showAdjustModal', false)">
                            Batal
                        </x-button>
                        <x-button type="submit" variant="primary">
                            Simpan Perubahan
                        </x-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif
</div>
