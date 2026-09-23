<div>
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Manajemen Bahan Baku</h4>
            <p class="text-sm font-medium text-[#9B9A97] mt-1">Kelola data bahan baku dan sesuaikan stok (Bill of Materials)</p>
        </div>
        <div class="flex items-center gap-3 w-full sm:w-auto">
            <div class="relative w-full sm:w-64">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-[#C4C3C0]">
                    <span class="material-symbols-rounded text-[18px]">search</span>
                </span>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari bahan baku..." 
                       class="form-input w-full pl-10 pr-4 py-2 rounded-lg border border-[#E9E9E7] bg-white text-[#37352F] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-gray-900 transition-all duration-200 shadow-sm">
            </div>
            <button wire:click="create" class="flex-shrink-0 inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white hover:bg-gray-800 text-sm font-medium rounded-lg transition-all duration-200 shadow-sm active:scale-95">
                <span class="material-symbols-rounded text-[18px]">add</span> Tambah
            </button>
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

    <div class="bg-white border border-[#E9E9E7] rounded-lg shadow-sm overflow-hidden w-full">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-[#E9E9E7] bg-[#F7F7F5]">
                        <th class="px-6 py-4 text-xs font-semibold text-[#9B9A97] uppercase tracking-wider">Bahan Baku</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#9B9A97] uppercase tracking-wider">Stok Saat Ini</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#9B9A97] uppercase tracking-wider">Harga/Unit</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#9B9A97] uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#9B9A97] uppercase tracking-wider text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse ($ingredients as $item)
                        <tr class="hover:bg-[#F7F7F5]/50 transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <span class="text-sm font-semibold text-[#37352F]">{{ $item->name }}</span>
                                    <span class="text-xs text-[#C4C3C0]">{{ $item->ingredient_code ?? '-' }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium {{ $item->current_stock <= $item->reorder_level ? 'text-rose-600' : 'text-[#37352F]' }}">
                                        {{ number_format($item->current_stock, 2) }} {{ $item->unit }}
                                    </span>
                                    @if($item->current_stock <= $item->reorder_level)
                                        <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-700" title="Stok Menipis">!</span>
                                    @endif
                                </div>
                                <div class="text-[10px] text-[#C4C3C0] mt-0.5">Min: {{ number_format($item->reorder_level, 2) }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm text-[#37352F]">Rp {{ number_format($item->cost_per_unit, 0, ',', '.') }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($item->is_active)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200/50">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Aktif
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-[#F1F1EF] text-[#787774] border border-[#E9E9E7]">
                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span> Nonaktif
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
                                <button wire:click="delete({{ $item->id }})" wire:confirm="Yakin ingin menghapus bahan baku ini?" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-rose-600 hover:bg-rose-50 transition-colors" title="Hapus">
                                    <span class="material-symbols-rounded text-[18px]">delete</span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-16 h-16 mb-4 rounded-full bg-[#F7F7F5] flex items-center justify-center border border-[#E9E9E7]">
                                        <span class="material-symbols-rounded text-3xl text-[#C4C3C0]">inventory_2</span>
                                    </div>
                                    <h3 class="text-base font-semibold text-[#37352F] mb-1">Belum Ada Bahan Baku</h3>
                                    <p class="text-sm text-[#9B9A97] max-w-sm mx-auto">Tambahkan bahan baku baru untuk mulai mengelola inventory dan resep produk.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($ingredients->hasPages())
            <div class="px-6 py-4 border-t border-[#E9E9E7]">
                {{ $ingredients->links() }}
            </div>
        @endif
    </div>

    <!-- Modal CRUD -->
    @if($showModal)
    <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm transition-opacity" aria-hidden="true" wire:click="$set('showModal', false)"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg w-full border border-[#E9E9E7]">
                <form wire:submit.prevent="save">
                    <div class="bg-white px-6 pt-6 pb-6">
                        <div class="mb-5 flex justify-between items-center">
                            <h3 class="text-lg leading-6 font-bold text-[#37352F]" id="modal-title">
                                {{ $isEditing ? 'Ubah Bahan Baku' : 'Tambah Bahan Baku Baru' }}
                            </h3>
                            <button type="button" wire:click="$set('showModal', false)" class="text-[#C4C3C0] hover:text-[#9B9A97]">
                                <span class="material-symbols-rounded text-[20px]">close</span>
                            </button>
                        </div>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-semibold text-[#787774] mb-1">Kode Bahan (Opsional)</label>
                                <input type="text" wire:model="ingredient_code" class="form-input w-full px-4 py-2 rounded-lg border border-[#C4C3C0] focus:ring-2 focus:ring-gray-900 focus:border-gray-900 text-sm">
                                @error('ingredient_code') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold text-[#787774] mb-1">Nama Bahan <span class="text-rose-500">*</span></label>
                                <input type="text" wire:model="name" class="form-input w-full px-4 py-2 rounded-lg border border-[#C4C3C0] focus:ring-2 focus:ring-gray-900 focus:border-gray-900 text-sm" required>
                                @error('name') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-[#787774] mb-1">Satuan Dasar <span class="text-rose-500">*</span></label>
                                    <input type="text" wire:model="unit" placeholder="g, ml, pcs..." class="form-input w-full px-4 py-2 rounded-lg border border-[#C4C3C0] focus:ring-2 focus:ring-gray-900 focus:border-gray-900 text-sm" required>
                                    @error('unit') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-[#787774] mb-1">Harga per Satuan <span class="text-rose-500">*</span></label>
                                    <input type="number" step="0.01" wire:model="cost_per_unit" class="form-input w-full px-4 py-2 rounded-lg border border-[#C4C3C0] focus:ring-2 focus:ring-gray-900 focus:border-gray-900 text-sm" required>
                                    @error('cost_per_unit') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-[#787774] mb-1">Batas Minimum (Reorder Level) <span class="text-rose-500">*</span></label>
                                <input type="number" step="0.0001" wire:model="reorder_level" class="form-input w-full px-4 py-2 rounded-lg border border-[#C4C3C0] focus:ring-2 focus:ring-gray-900 focus:border-gray-900 text-sm" required>
                                @error('reorder_level') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>

                            <div class="pt-2">
                                <label class="flex items-center cursor-pointer">
                                    <div class="relative">
                                        <input type="checkbox" wire:model="is_active" class="sr-only">
                                        <div class="block bg-[#E9E9E7] w-10 h-6 rounded-full transition-colors duration-300" :class="{ 'bg-emerald-500': @entangle('is_active') }"></div>
                                        <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform duration-300 shadow-sm" :class="{ 'transform translate-x-4': @entangle('is_active') }"></div>
                                    </div>
                                    <div class="ml-3 text-sm font-semibold text-[#787774]">
                                        Bahan Baku Aktif
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="bg-[#F7F7F5] px-6 py-4 border-t border-[#E9E9E7] flex justify-end gap-3 rounded-b-2xl">
                        <button type="button" wire:click="$set('showModal', false)" class="px-4 py-2 bg-white border border-[#C4C3C0] rounded-lg text-sm font-medium text-[#787774] hover:bg-[#F7F7F5] transition-colors">
                            Batal
                        </button>
                        <button type="submit" class="px-4 py-2 bg-gray-900 border border-transparent rounded-lg text-sm font-medium text-white hover:bg-gray-800 transition-colors">
                            Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

    <!-- Modal Adjust Stock -->
    @if($showAdjustModal)
    <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm transition-opacity" aria-hidden="true" wire:click="$set('showAdjustModal', false)"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg w-full border border-[#E9E9E7]">
                <form wire:submit.prevent="saveAdjustment">
                    <div class="bg-white px-6 pt-6 pb-6">
                        <div class="mb-5 flex justify-between items-center">
                            <h3 class="text-lg leading-6 font-bold text-[#37352F]" id="modal-title">
                                Sesuaikan Stok
                            </h3>
                            <button type="button" wire:click="$set('showAdjustModal', false)" class="text-[#C4C3C0] hover:text-[#9B9A97]">
                                <span class="material-symbols-rounded text-[20px]">close</span>
                            </button>
                        </div>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-semibold text-[#787774] mb-1">Tipe Mutasi <span class="text-rose-500">*</span></label>
                                <select wire:model="adjustType" class="form-select w-full px-4 py-2 rounded-lg border border-[#C4C3C0] focus:ring-2 focus:ring-gray-900 focus:border-gray-900 text-sm">
                                    <option value="purchase_receipt">Barang Masuk (Pembelian)</option>
                                    <option value="adjustment">Koreksi Manual (+ / -)</option>
                                    <option value="waste">Barang Rusak / Waste (-)</option>
                                </select>
                                @error('adjustType') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-[#787774] mb-1">Jumlah <span class="text-rose-500">*</span></label>
                                <input type="number" step="0.0001" wire:model="adjustQuantity" placeholder="Gunakan minus (-) untuk pengurangan jika tipe Koreksi Manual" class="form-input w-full px-4 py-2 rounded-lg border border-[#C4C3C0] focus:ring-2 focus:ring-gray-900 focus:border-gray-900 text-sm" required>
                                @error('adjustQuantity') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-[#787774] mb-1">Keterangan / Alasan (Opsional)</label>
                                <textarea wire:model="adjustReason" rows="3" class="form-textarea w-full px-4 py-2 rounded-lg border border-[#C4C3C0] focus:ring-2 focus:ring-gray-900 focus:border-gray-900 text-sm" placeholder="Catatan tambahan..."></textarea>
                                @error('adjustReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="bg-[#F7F7F5] px-6 py-4 border-t border-[#E9E9E7] flex justify-end gap-3 rounded-b-2xl">
                        <button type="button" wire:click="$set('showAdjustModal', false)" class="px-4 py-2 bg-white border border-[#C4C3C0] rounded-lg text-sm font-medium text-[#787774] hover:bg-[#F7F7F5] transition-colors">
                            Batal
                        </button>
                        <button type="submit" class="px-4 py-2 bg-gray-900 border border-transparent rounded-lg text-sm font-medium text-white hover:bg-gray-800 transition-colors">
                            Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif
</div>
