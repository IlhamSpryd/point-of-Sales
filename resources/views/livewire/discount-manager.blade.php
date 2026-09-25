<div class="space-y-6">
    <div class="flex justify-between items-center">
        <h2 class="text-2xl font-bold text-gray-800">Manajemen Diskon & Promo</h2>
        <button wire:click="create()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-4 rounded-lg shadow flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd" />
            </svg>
            Tambah Diskon
        </button>
    </div>

    @if (session()->has('message'))
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded" role="alert">
            <p>{{ session('message') }}</p>
        </div>
    @endif

    <div class="bg-white shadow rounded-lg overflow-hidden border border-gray-200">
        <div class="p-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
            <div class="relative w-72">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                    </svg>
                </div>
                <input wire:model.live.debounce.300ms="search" type="text" class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm transition duration-150 ease-in-out" placeholder="Cari Diskon...">
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama & Kode</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipe & Nilai</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Syarat</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status & Masa Aktif</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($discounts as $discount)
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">{{ $discount->name }}</div>
                                <div class="text-sm text-gray-500 font-mono">{{ $discount->code ?? 'Tanpa Kode' }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    {{ $discount->type === 'percentage' ? $discount->value . '%' : 'Rp ' . number_format($discount->value, 0, ',', '.') }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ $discount->type === 'percentage' && $discount->max_discount_amount ? 'Maks: Rp ' . number_format($discount->max_discount_amount, 0, ',', '.') : '' }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">Min. Beli: Rp {{ number_format($discount->min_purchase_amount, 0, ',', '.') }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $discount->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $discount->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                                @if($discount->valid_from || $discount->valid_until)
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ $discount->valid_from ? $discount->valid_from->format('d M y') : 'Seterusnya' }} - {{ $discount->valid_until ? $discount->valid_until->format('d M y') : 'Seterusnya' }}
                                </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <button wire:click="edit({{ $discount->id }})" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</button>
                                <button wire:click="delete({{ $discount->id }})" wire:confirm="Yakin ingin menghapus diskon ini?" class="text-red-600 hover:text-red-900">Hapus</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">
                                Belum ada diskon yang ditambahkan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-yovel-border shrink-0">
            {{ $discounts->links() }}
        </div>
    </div>

    <!-- Modal Form -->
    @if($isModalOpen)
    <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" wire:click="closeModal()"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                <form wire:submit.prevent="store">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4" id="modal-title">
                            {{ $discountId ? 'Edit Diskon' : 'Tambah Diskon Baru' }}
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Nama Diskon -->
                            <div class="col-span-1 md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Nama Diskon <span class="text-red-500">*</span></label>
                                <input type="text" wire:model="name" class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                @error('name') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <!-- Kode Diskon -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Kode Voucher</label>
                                <input type="text" wire:model="code" placeholder="Kosongkan jika bukan voucher" class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                @error('code') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                                <p class="text-xs text-gray-500 mt-1">Biarkan kosong untuk diskon manual.</p>
                            </div>

                            <!-- Tipe Diskon -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Tipe Diskon <span class="text-red-500">*</span></label>
                                <select wire:model.live="type" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                    <option value="percentage">Persentase (%)</option>
                                    <option value="fixed">Nominal Tetap (Rp)</option>
                                </select>
                                @error('type') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <!-- Nilai Diskon -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Nilai Diskon <span class="text-red-500">*</span></label>
                                <input type="number" wire:model="value" class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                @error('value') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <!-- Maksimal Potongan (Hanya untuk Percentage) -->
                            @if($type === 'percentage')
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Maksimal Potongan (Rp)</label>
                                <input type="number" wire:model="max_discount_amount" class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                @error('max_discount_amount') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                            @endif

                            <!-- Minimal Pembelian -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Minimal Pembelian (Rp)</label>
                                <input type="number" wire:model="min_purchase_amount" class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                @error('min_purchase_amount') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <!-- Tanggal Mulai -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Berlaku Mulai</label>
                                <input type="datetime-local" wire:model="valid_from" class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                @error('valid_from') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <!-- Tanggal Selesai -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Berlaku Sampai</label>
                                <input type="datetime-local" wire:model="valid_until" class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                @error('valid_until') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                            
                            <!-- Status Aktif -->
                            <div class="col-span-1 md:col-span-2">
                                <div class="flex items-start mt-2">
                                    <div class="flex items-center h-5">
                                        <input wire:model="is_active" id="is_active" type="checkbox" class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="is_active" class="font-medium text-gray-700">Diskon Aktif</label>
                                        <p class="text-gray-500">Diskon ini dapat digunakan di kasir.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:ml-3 sm:w-auto sm:text-sm">
                            Simpan
                        </button>
                        <button type="button" wire:click="closeModal()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Batal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif
</div>
