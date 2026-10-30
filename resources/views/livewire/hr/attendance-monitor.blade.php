<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <!-- Sub Navigasi Modular -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-rounded text-indigo-600 text-[28px]">schedule</span>
                Pemantauan Absensi & Lembur Staf
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Catatan jam masuk/pulang kerja, keterlambatan, istirahat, serta koreksi jam kerja staf.
            </p>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3 text-emerald-800 text-sm font-medium">
            <span class="material-symbols-rounded text-emerald-600 text-[20px]">check_circle</span>
            {{ session('success') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="p-4 bg-rose-50 border border-rose-200 rounded-xl flex items-center gap-3 text-rose-800 text-sm font-medium">
            <span class="material-symbols-rounded text-rose-600 text-[20px]">error</span>
            {{ session('error') }}
        </div>
    @endif

    <!-- Filter Bar -->
    <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Cabang:</span>
                <select wire:model.live="selectedStoreId" class="text-xs font-semibold px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-gray-800 outline-none focus:bg-white focus:border-indigo-500">
                    <option value="">Semua Cabang</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}">{{ $store->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Tanggal:</span>
                <input type="date" wire:model.live="dateFilter" class="text-xs font-semibold px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-xl text-gray-800 outline-none focus:bg-white focus:border-indigo-500" />
            </div>

            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Status:</span>
                <select wire:model.live="statusFilter" class="text-xs font-semibold px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-gray-800 outline-none focus:bg-white focus:border-indigo-500">
                    <option value="ALL">Semua Status</option>
                    <option value="ON_TIME">Tepat Waktu (ON_TIME)</option>
                    <option value="LATE">Terlambat (LATE)</option>
                    <option value="OVERTIME">Lembur (OVERTIME)</option>
                    <option value="EARLY_DEPARTURE">Pulang Cepat (EARLY_DEPARTURE)</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Tabel Catatan Absensi -->
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-gray-700 divide-y divide-gray-200">
                <thead class="bg-gray-50 text-xs font-bold text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5">Karyawan</th>
                        <th class="px-6 py-3.5">Waktu Masuk</th>
                        <th class="px-6 py-3.5">Waktu Pulang</th>
                        <th class="px-6 py-3.5">Status Kehadiran</th>
                        <th class="px-6 py-3.5">Catatan / Koreksi</th>
                        <th class="px-6 py-3.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($records as $rec)
                        <tr class="hover:bg-gray-50/60 transition-colors">
                            <td class="px-6 py-4 font-semibold text-gray-900 flex items-center gap-2">
                                <div class="w-8 h-8 rounded-full bg-indigo-50 text-indigo-700 font-bold flex items-center justify-center text-xs">
                                    {{ strtoupper(substr($rec->employee?->full_name ?? 'EM', 0, 2)) }}
                                </div>
                                <div>
                                    <div class="font-semibold text-gray-900">{{ $rec->employee?->full_name ?? 'Staf' }}</div>
                                    <div class="text-xs text-gray-500 font-mono">{{ $rec->employee?->employee_number }}</div>
                                </div>
                            </td>
                            <td class="px-6 py-4 font-mono text-xs">
                                {{ $rec->clock_in_at ? $rec->clock_in_at->format('d M Y, H:i') : '-' }}
                            </td>
                            <td class="px-6 py-4 font-mono text-xs">
                                {{ $rec->clock_out_at ? $rec->clock_out_at->format('d M Y, H:i') : '-' }}
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $statusBadge = match($rec->status?->value ?? '') {
                                        'ON_TIME' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'LATE' => 'bg-amber-50 text-amber-700 border-amber-200',
                                        'OVERTIME' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                        default => 'bg-gray-100 text-gray-700 border-gray-200'
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border {{ $statusBadge }}">
                                    {{ $rec->status?->value ?? 'PENDING' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs text-gray-500 max-w-xs truncate">
                                {{ $rec->notes ?: '-' }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button wire:click="openCorrectionModal({{ $rec->id }})"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-100 text-gray-700 transition-colors cursor-pointer">
                                    Koreksi
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                                <span class="material-symbols-rounded text-4xl block mb-2 text-gray-300">event_busy</span>
                                Tidak ada rekaman absensi pada tanggal dan cabang yang dipilih.
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

    <!-- Modal Koreksi Waktu Absensi -->
    @if ($isCorrectionModalOpen)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-5">
                <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                    <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-rounded text-indigo-600">edit_attributes</span>
                        Koreksi Waktu Absensi Staf
                    </h3>
                    <button wire:click="closeCorrectionModal" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Waktu Masuk (Clock-in)</label>
                        <input type="datetime-local" wire:model="correctedClockIn" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500 font-mono" />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Waktu Pulang (Clock-out)</label>
                        <input type="datetime-local" wire:model="correctedClockOut" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500 font-mono" />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Alasan Koreksi (Wajib)</label>
                        <textarea wire:model="correctionReason" rows="2" placeholder="mis. Mesin POS mati listrik / Lupa tap keluar" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t border-gray-100">
                    <button type="button" wire:click="closeCorrectionModal" class="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-800 cursor-pointer">
                        Batal
                    </button>
                    <button type="button" wire:click="saveCorrection" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-sm transition-colors cursor-pointer">
                        Simpan Koreksi
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
