<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <!-- Sub Navigasi Modular -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-rounded text-indigo-600 text-[28px]">event_busy</span>
                Manajemen Cuti & Izin Karyawan
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Verifikasi permohonan cuti tahunan, sakit, izin khusus, dan pemantauan saldo cuti staf.
            </p>
        </div>
        <div class="flex items-center gap-1 bg-gray-100 p-1 rounded-xl">
            <button wire:click="$set('activeTab', 'REQUESTS')"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer {{ $activeTab === 'REQUESTS' ? 'bg-white text-gray-900 shadow-xs' : 'text-gray-500 hover:text-gray-900' }}">
                Permohonan Cuti
            </button>
            <button wire:click="$set('activeTab', 'BALANCES')"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer {{ $activeTab === 'BALANCES' ? 'bg-white text-gray-900 shadow-xs' : 'text-gray-500 hover:text-gray-900' }}">
                Saldo Cuti Staf
            </button>
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

    @if ($activeTab === 'REQUESTS')
        <!-- Filter Bar -->
        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Status Permohonan:</span>
                <select wire:model.live="statusFilter" class="text-xs font-semibold px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-xl text-gray-800 outline-none focus:bg-white focus:border-indigo-500">
                    <option value="ALL">Semua Status</option>
                    <option value="PENDING">Menunggu Persetujuan (PENDING)</option>
                    <option value="APPROVED">Disetujui (APPROVED)</option>
                    <option value="REJECTED">Ditolak (REJECTED)</option>
                    <option value="CANCELLED">Dibatalkan (CANCELLED)</option>
                </select>
            </div>
        </div>

        <!-- Tabel Permohonan Cuti -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-700 divide-y divide-gray-200">
                    <thead class="bg-gray-50 text-xs font-bold text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-3.5">Nomor & Karyawan</th>
                            <th class="px-6 py-3.5">Jenis Cuti</th>
                            <th class="px-6 py-3.5">Rentang Tanggal</th>
                            <th class="px-6 py-3.5">Durasi</th>
                            <th class="px-6 py-3.5">Status</th>
                            <th class="px-6 py-3.5 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($requests as $req)
                            <tr class="hover:bg-gray-50/60 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="font-bold text-gray-900 font-mono text-xs">#{{ $req->id }}</div>
                                    <div class="text-sm text-gray-800">{{ $req->employee?->full_name ?? 'Karyawan' }}</div>
                                </td>
                                <td class="px-6 py-4 font-medium text-gray-900">
                                    {{ $req->policy?->policy_name ?? 'Cuti Umum' }}
                                </td>
                                <td class="px-6 py-4 font-mono text-xs text-gray-600">
                                    {{ $req->start_date->format('d M Y') }} s/d {{ $req->end_date->format('d M Y') }}
                                </td>
                                <td class="px-6 py-4 font-semibold text-gray-900">
                                    {{ (float) $req->total_days }} Hari
                                </td>
                                <td class="px-6 py-4">
                                    @php
                                        $badgeColor = match($req->status->value) {
                                            'APPROVED' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                            'PENDING' => 'bg-amber-50 text-amber-700 border-amber-200',
                                            'REJECTED' => 'bg-rose-50 text-rose-700 border-rose-200',
                                            default => 'bg-gray-100 text-gray-700 border-gray-200'
                                        };
                                    @endphp
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border {{ $badgeColor }}">
                                        {{ $req->status->value }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    @if ($req->status === \App\Modules\HR\Enums\LeaveRequestStatusEnum::PENDING)
                                        <div class="flex items-center justify-end gap-2">
                                            <button wire:click="approve({{ $req->id }})"
                                                class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold transition-colors cursor-pointer">
                                                Setujui
                                            </button>
                                            <button wire:click="reject({{ $req->id }})"
                                                class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-lg text-xs font-semibold transition-colors cursor-pointer">
                                                Tolak
                                            </button>
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-400">Selesai</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                                    <span class="material-symbols-rounded text-4xl block mb-2 text-gray-300">event_available</span>
                                    Tidak ada permohonan cuti pada antrean.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-200">
                {{ $requests->links() }}
            </div>
        </div>
    @else
        <!-- Tabel Saldo Cuti Staf -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-700 divide-y divide-gray-200">
                    <thead class="bg-gray-50 text-xs font-bold text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-3.5">Karyawan</th>
                            <th class="px-6 py-3.5">Kebijakan Cuti</th>
                            <th class="px-6 py-3.5">Tahun</th>
                            <th class="px-6 py-3.5">Hak Cuti</th>
                            <th class="px-6 py-3.5">Terpakai</th>
                            <th class="px-6 py-3.5">Sisa Saldo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($balances as $bal)
                            <tr class="hover:bg-gray-50/60 transition-colors">
                                <td class="px-6 py-4 font-semibold text-gray-900">
                                    {{ $bal->employee?->full_name ?? 'Karyawan' }}
                                </td>
                                <td class="px-6 py-4 font-medium text-gray-800">
                                    {{ $bal->policy?->policy_name ?? 'Cuti' }}
                                </td>
                                <td class="px-6 py-4 font-mono text-xs">
                                    {{ $bal->year }}
                                </td>
                                <td class="px-6 py-4">
                                    {{ (float) $bal->allocated_days }} Hari
                                </td>
                                <td class="px-6 py-4 text-rose-600 font-semibold">
                                    {{ (float) $bal->used_days }} Hari
                                </td>
                                <td class="px-6 py-4 text-emerald-600 font-bold">
                                    {{ (float) $bal->remaining_days }} Hari
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                                    Belum ada catatan saldo cuti staf yang terdaftar.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
