<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <!-- Header Page & Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-rounded text-blue-600 text-[28px]">confirmation_number</span>
                Manajemen Kupon & Voucher Belanja
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Kelola voucher belanja transaksi, batas pemakaian per pelanggan, dan pelacakan kuota real-time dua fase.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="openCreateModal"
                class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-xl shadow-sm transition-colors">
                <span class="material-symbols-rounded text-[18px]">add</span>
                Buat Voucher Baru
            </button>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3 text-emerald-800 text-sm font-medium">
            <span class="material-symbols-rounded text-emerald-600 text-[20px]">check_circle</span>
            {{ session('success') }}
        </div>
    @endif

    <!-- Filter & Search Bar -->
    <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="w-full sm:w-80 relative">
            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                <span class="material-symbols-rounded text-[18px]">search</span>
            </span>
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="Cari kode atau nama voucher..."
                class="w-full pl-9 pr-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl focus:bg-white focus:border-blue-500 focus:ring-0 outline-none transition-colors" />
        </div>

        <div class="flex items-center gap-2 w-full sm:w-auto">
            <select wire:model.live="statusFilter"
                class="text-xs font-semibold px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-gray-700 outline-none focus:bg-white focus:border-blue-500">
                <option value="ALL">Semua Status</option>
                <option value="ACTIVE">Aktif</option>
                <option value="INACTIVE">Tidak Aktif</option>
                <option value="EXPIRED">Kedaluwarsa / Kuota Habis</option>
            </select>
        </div>
    </div>

    <!-- Tabel Voucher -->
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-gray-700 divide-y divide-gray-200">
                <thead class="bg-gray-50 text-xs font-bold text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5">Kode & Nama Voucher</th>
                        <th class="px-6 py-3.5">Nilai Diskon</th>
                        <th class="px-6 py-3.5">Min. Belanja</th>
                        <th class="px-6 py-3.5">Realisasi Kuota</th>
                        <th class="px-6 py-3.5">Masa Berlaku</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($vouchers as $voucher)
                        @php
                            $typeVal = $voucher->discount_type instanceof \App\Modules\Customers\Enums\VoucherDiscountTypeEnum ? $voucher->discount_type->value : (string) $voucher->discount_type;
                            $statusVal = $voucher->status instanceof \App\Modules\Customers\Enums\VoucherStatusEnum ? $voucher->status->value : (string) $voucher->status;
                            $percentUsed = $voucher->max_uses > 0 ? min(100, round(($voucher->used_count / $voucher->max_uses) * 100)) : 0;
                        @endphp
                        <tr class="hover:bg-gray-50/80 transition-colors">
                            <td class="px-6 py-4">
                                <div class="font-mono font-bold text-blue-600 flex items-center gap-1.5">
                                    <span class="material-symbols-rounded text-[16px] text-gray-400">confirmation_number</span>
                                    {{ $voucher->code }}
                                </div>
                                <div class="text-xs font-medium text-gray-800 mt-0.5">{{ $voucher->name }}</div>
                            </td>
                            <td class="px-6 py-4 font-bold text-gray-900">
                                @if ($typeVal === 'PERCENTAGE')
                                    {{ (float) $voucher->discount_value }}%
                                    @if ($voucher->max_discount_amount)
                                        <div class="text-[11px] font-normal text-gray-400">Maks Rp {{ number_format((float) $voucher->max_discount_amount, 0, ',', '.') }}</div>
                                    @endif
                                @else
                                    Rp {{ number_format((float) $voucher->discount_value, 0, ',', '.') }}
                                @endif
                            </td>
                            <td class="px-6 py-4 text-xs font-medium text-gray-600">
                                Rp {{ number_format((float) $voucher->min_order_amount, 0, ',', '.') }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-between text-xs font-medium text-gray-600 mb-1">
                                    <span>{{ $voucher->used_count }} / {{ $voucher->max_uses > 0 ? $voucher->max_uses : '∞' }}</span>
                                    @if ($voucher->max_uses > 0)
                                        <span class="text-[10px] text-gray-400">{{ $percentUsed }}%</span>
                                    @endif
                                </div>
                                @if ($voucher->max_uses > 0)
                                    <div class="w-28 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                        <div class="h-full {{ $percentUsed >= 100 ? 'bg-rose-500' : ($percentUsed >= 80 ? 'bg-amber-500' : 'bg-blue-600') }}" style="width: {{ $percentUsed }}%"></div>
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-xs text-gray-500">
                                <div>{{ $voucher->starts_at ? \Carbon\Carbon::parse($voucher->starts_at)->format('d M Y') : 'Sekarang' }}</div>
                                <div class="text-[11px] text-gray-400">s/d {{ $voucher->expires_at ? \Carbon\Carbon::parse($voucher->expires_at)->format('d M Y') : 'Selamanya' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                @if ($statusVal === 'ACTIVE')
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">AKTIF</span>
                                @elseif ($statusVal === 'INACTIVE')
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-600">NON-AKTIF</span>
                                @else
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800">HABIS/EXPIRED</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right space-x-1">
                                <button type="button" wire:click="openEditModal({{ $voucher->id }})"
                                    title="Edit Voucher"
                                    class="p-1.5 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors">
                                    <span class="material-symbols-rounded text-[20px]">edit</span>
                                </button>
                                <button type="button" wire:click="toggleStatus({{ $voucher->id }})"
                                    title="Ubah Status"
                                    class="p-1.5 {{ $statusVal === 'ACTIVE' ? 'text-amber-600 hover:text-amber-800 hover:bg-amber-50' : 'text-emerald-600 hover:text-emerald-800 hover:bg-emerald-50' }} rounded-lg transition-colors">
                                    <span class="material-symbols-rounded text-[20px]">
                                        {{ $statusVal === 'ACTIVE' ? 'pause_circle' : 'play_circle' }}
                                    </span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                                <span class="material-symbols-rounded text-[40px] text-gray-300 block mb-2">confirmation_number</span>
                                Belum ada voucher belanja yang dibuat.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-gray-100">
            {{ $vouchers->links() }}
        </div>
    </div>

    <!-- Modal Form Create / Edit Voucher -->
    @if ($isFormModalOpen)
        <div class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
            <div class="bg-white rounded-2xl max-w-xl w-full p-6 space-y-5 shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                    <h3 class="text-lg font-bold text-gray-900">
                        {{ $editingVoucherId ? 'Ubah Voucher Belanja' : 'Buat Voucher Belanja Baru' }}
                    </h3>
                    <button wire:click="$set('isFormModalOpen', false)" class="text-gray-400 hover:text-gray-600 p-1 rounded-lg">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <form wire:submit="save" class="space-y-4">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-xs font-bold text-gray-700">Kode Voucher</label>
                            <button type="button" wire:click="generateCode" class="text-xs text-blue-600 hover:text-blue-800 font-semibold flex items-center gap-1">
                                <span class="material-symbols-rounded text-[14px]">autorenew</span> Generate Acak
                            </button>
                        </div>
                        <input type="text" wire:model="code" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl uppercase font-mono font-bold" required />
                        @error('code') <span class="text-rose-600 text-xs">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Nama Voucher</label>
                        <input type="text" wire:model="name" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl" placeholder="Contoh: Voucher Diskon 20K Member" required />
                        @error('name') <span class="text-rose-600 text-xs">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Tipe Diskon</label>
                            <select wire:model.live="discountType" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl font-medium">
                                <option value="PERCENTAGE">Persentase (%)</option>
                                <option value="FIXED_AMOUNT">Nominal Tetap (Rp)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Nilai Diskon</label>
                            <input type="number" step="0.01" wire:model="discountValue" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl" required />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Min. Belanja (Rp)</label>
                            <input type="number" wire:model="minOrderAmount" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl" min="0" />
                        </div>
                        @if ($discountType === 'PERCENTAGE')
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Maks. Diskon (Rp)</label>
                                <input type="number" wire:model="maxDiscountAmount" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl" placeholder="Opsional" />
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Maks Kuota Global</label>
                            <input type="number" wire:model="maxUses" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl" min="0" placeholder="0 = Tak terbatas" />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Maks per Pelanggan</label>
                            <input type="number" wire:model="maxUsesPerCustomer" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl" min="1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Mulai Berlaku</label>
                            <input type="datetime-local" wire:model="startsAt" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl" required />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Kedaluwarsa Pada</label>
                            <input type="datetime-local" wire:model="expiresAt" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl" required />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-gray-100">
                        <button type="button" wire:click="$set('isFormModalOpen', false)"
                            class="px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 rounded-xl transition-colors">
                            Batal
                        </button>
                        <button type="submit"
                            class="px-5 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-xl shadow-sm transition-colors">
                            Simpan Voucher
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
