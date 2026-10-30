<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <!-- Sub Navigasi Modular -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-rounded text-indigo-600 text-[28px]">paid</span>
                Rekapitulasi Komisi Penjualan Staf
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Pelacakan perolehan komisi staf kasir berbasis order POS real-time serta pembalikan otomatis akibat retur barang.
            </p>
        </div>
    </div>

    <!-- Ringkasan Statistik Moneter -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm">
            <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total Komisi Diperoleh</div>
            <div class="text-xl font-extrabold text-emerald-600 font-mono">
                Rp {{ number_format($totalEarned, 2, ',', '.') }}
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm">
            <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total Dibatalkan / Retur</div>
            <div class="text-xl font-extrabold text-rose-600 font-mono">
                Rp {{ number_format($totalReversed, 2, ',', '.') }}
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm">
            <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Komisi Bersih (Net)</div>
            <div class="text-xl font-extrabold text-indigo-600 font-mono">
                Rp {{ number_format($netCommission, 2, ',', '.') }}
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Karyawan:</span>
                <select wire:model.live="employeeFilter" class="text-xs font-semibold px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-gray-800 outline-none focus:bg-white focus:border-indigo-500">
                    <option value="">Semua Karyawan</option>
                    @foreach ($employees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->full_name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Status:</span>
                <select wire:model.live="statusFilter" class="text-xs font-semibold px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-gray-800 outline-none focus:bg-white focus:border-indigo-500">
                    <option value="ALL">Semua Status</option>
                    <option value="EARNED">Diperoleh (EARNED)</option>
                    <option value="REVERSED">Dibatalkan / Retur (REVERSED)</option>
                    <option value="PAID">Sudah Dicairkan (PAID)</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Tabel Catatan Komisi -->
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-gray-700 divide-y divide-gray-200">
                <thead class="bg-gray-50 text-xs font-bold text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5">Karyawan</th>
                        <th class="px-6 py-3.5">Referensi Order</th>
                        <th class="px-6 py-3.5">Dasar Perhitungan</th>
                        <th class="px-6 py-3.5">Nilai Komisi</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5">Tanggal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($records as $record)
                        <tr class="hover:bg-gray-50/60 transition-colors">
                            <td class="px-6 py-4 font-semibold text-gray-900">
                                {{ $record->employee?->full_name ?? 'Staf' }}
                            </td>
                            <td class="px-6 py-4 font-mono text-xs text-gray-600">
                                #{{ $record->order_id }}
                            </td>
                            <td class="px-6 py-4 font-mono text-xs">
                                Rp {{ number_format((float) $record->basis_amount, 2, ',', '.') }}
                            </td>
                            <td class="px-6 py-4 font-bold font-mono {{ $record->status->value === 'REVERSED' ? 'text-rose-600' : 'text-emerald-600' }}">
                                {{ $record->status->value === 'REVERSED' ? '-' : '+' }} Rp {{ number_format((float) $record->commission_amount, 2, ',', '.') }}
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $badge = match($record->status->value) {
                                        'EARNED' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'REVERSED' => 'bg-rose-50 text-rose-700 border-rose-200',
                                        'PAID' => 'bg-blue-50 text-blue-700 border-blue-200',
                                        default => 'bg-gray-100 text-gray-700 border-gray-200'
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border {{ $badge }}">
                                    {{ $record->status->value }}
                                </span>
                            </td>
                            <td class="px-6 py-4 font-mono text-xs text-gray-500">
                                {{ $record->created_at ? $record->created_at->format('d M Y, H:i') : '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                                <span class="material-symbols-rounded text-4xl block mb-2 text-gray-300">receipt_long</span>
                                Tidak ada rekaman transaksi komisi staf ditemukan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-gray-200">
            {{ $records->links() }}
        </div>
    </div>
</div>
