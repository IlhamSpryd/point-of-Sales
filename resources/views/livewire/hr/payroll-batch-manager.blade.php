<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <!-- Sub Navigasi Modular -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-rounded text-indigo-600 text-[28px]">account_balance_wallet</span>
                Penggajian Karyawan (Payroll Engine)
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Kalkulasi 16-langkah deterministik, kepatuhan PPh 21 TER, iuran BPJS TK & BPJS KS, serta pembukuan jurnal GL.
            </p>
        </div>
        <div>
            <button wire:click="openCreateModal"
                class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-sm transition-colors cursor-pointer">
                <span class="material-symbols-rounded text-[18px]">add_circle</span>
                Inisiasi Batch Penggajian
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

    <!-- Filter Bar -->
    <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Status Batch:</span>
            <select wire:model.live="statusFilter" class="text-xs font-semibold px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-xl text-gray-800 outline-none focus:bg-white focus:border-indigo-500">
                <option value="ALL">Semua Status</option>
                <option value="DRAFT">Draf (DRAFT)</option>
                <option value="CALCULATED">Terkalkulasi (CALCULATED)</option>
                <option value="FINALIZED">Terkunci (FINALIZED)</option>
                <option value="PAID">Tuntas Dibayar (PAID)</option>
            </select>
        </div>
    </div>

    <!-- Tabel Batch Penggajian -->
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-gray-700 divide-y divide-gray-200">
                <thead class="bg-gray-50 text-xs font-bold text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5">Nomor Batch</th>
                        <th class="px-6 py-3.5">Periode Gaji</th>
                        <th class="px-6 py-3.5">Total Staf</th>
                        <th class="px-6 py-3.5">Total Penghasilan Bruto</th>
                        <th class="px-6 py-3.5">Total Gaji Bersih (Net)</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($batches as $batch)
                        <tr class="hover:bg-gray-50/60 transition-colors">
                            <td class="px-6 py-4 font-mono font-bold text-gray-900 text-xs">
                                {{ $batch->batch_number }}
                            </td>
                            <td class="px-6 py-4 font-mono text-xs text-gray-600">
                                {{ $batch->period_start_date->format('d M') }} s/d {{ $batch->period_end_date->format('d M Y') }}
                            </td>
                            <td class="px-6 py-4 font-semibold text-gray-900">
                                {{ $batch->total_employees }} Orang
                            </td>
                            <td class="px-6 py-4 font-mono text-xs">
                                Rp {{ number_format((float) $batch->total_gross_amount, 2, ',', '.') }}
                            </td>
                            <td class="px-6 py-4 font-mono font-bold text-xs text-indigo-700">
                                Rp {{ number_format((float) $batch->total_net_amount, 2, ',', '.') }}
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $badge = match($batch->status->value) {
                                        'FINALIZED' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                        'PAID' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'CALCULATED' => 'bg-blue-50 text-blue-700 border-blue-200',
                                        default => 'bg-gray-100 text-gray-700 border-gray-200'
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border {{ $badge }}">
                                    {{ $batch->status->value }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button wire:click="viewDetails({{ $batch->id }})"
                                        class="px-2.5 py-1 text-xs font-semibold rounded-lg border border-gray-200 hover:bg-gray-100 text-gray-700 transition-colors cursor-pointer">
                                        Rincian
                                    </button>

                                    @if ($batch->status === \App\Modules\HR\Enums\PayrollBatchStatusEnum::DRAFT || $batch->status === \App\Modules\HR\Enums\PayrollBatchStatusEnum::CALCULATED)
                                        <button wire:click="calculate({{ $batch->id }})"
                                            class="px-2.5 py-1 text-xs font-semibold rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 transition-colors cursor-pointer">
                                            Kalkulasi
                                        </button>
                                    @endif

                                    @if ($batch->status === \App\Modules\HR\Enums\PayrollBatchStatusEnum::CALCULATED)
                                        <button wire:click="finalize({{ $batch->id }})"
                                            class="px-2.5 py-1 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition-colors cursor-pointer">
                                            Kunci & Finalisasi
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                                <span class="material-symbols-rounded text-4xl block mb-2 text-gray-300">payments</span>
                                Belum ada batch penggajian yang diinisiasi.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-gray-200">
            {{ $batches->links() }}
        </div>
    </div>

    <!-- Modal Inisiasi Batch -->
    @if ($isCreateModalOpen)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-5">
                <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                    <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-rounded text-indigo-600">calendar_add_on</span>
                        Inisiasi Periode Penggajian Baru
                    </h3>
                    <button wire:click="closeCreateModal" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Awal Periode Kerja</label>
                        <input type="date" wire:model="periodStartDate" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500 font-mono" />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Akhir Periode Kerja</label>
                        <input type="date" wire:model="periodEndDate" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500 font-mono" />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Tanggal Cut-off Absensi</label>
                        <input type="date" wire:model="cutoffDate" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500 font-mono" />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Jadwal Tanggal Pembayaran (Transfer)</label>
                        <input type="date" wire:model="paymentDate" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500 font-mono" />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t border-gray-100">
                    <button type="button" wire:click="closeCreateModal" class="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-800 cursor-pointer">
                        Batal
                    </button>
                    <button type="button" wire:click="createBatch" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-sm transition-colors cursor-pointer">
                        Buat Batch
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Modal Rincian Batch & Slip Gaji -->
    @if ($isDetailModalOpen)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl max-w-4xl w-full p-6 shadow-2xl space-y-5">
                <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                    <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-rounded text-indigo-600">receipt</span>
                        Daftar Slip Gaji Karyawan pada Batch Ini
                    </h3>
                    <button wire:click="closeDetailModal" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <div class="max-h-[60vh] overflow-y-auto">
                    <table class="w-full text-left text-sm text-gray-700 divide-y divide-gray-200">
                        <thead class="bg-gray-50 text-xs font-bold text-gray-500 uppercase">
                            <tr>
                                <th class="px-4 py-2.5">Karyawan</th>
                                <th class="px-4 py-2.5">Penghasilan Bruto</th>
                                <th class="px-4 py-2.5">PPh 21</th>
                                <th class="px-4 py-2.5">BPJS Pekerja</th>
                                <th class="px-4 py-2.5">Gaji Bersih (Net)</th>
                                <th class="px-4 py-2.5 text-right">Akses Slip</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 font-mono text-xs">
                            @forelse ($detailRecords as $rec)
                                <tr>
                                    <td class="px-4 py-3 font-sans">
                                        <div class="font-bold text-gray-900">{{ $rec->full_name_snapshot }}</div>
                                        <div class="text-[10px] text-gray-500 font-mono">{{ $rec->employee_number_snapshot }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        Rp {{ number_format((float) $rec->gross_earnings_amount, 2, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-3 text-rose-600">
                                        - Rp {{ number_format((float) $rec->tax_pph21_amount, 2, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-3 text-rose-600">
                                        - Rp {{ number_format((float) ($rec->bpjs_ks_employee_amount + $rec->bpjs_tk_employee_amount), 2, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-3 font-bold text-emerald-700">
                                        Rp {{ number_format((float) $rec->net_pay_amount, 2, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-sans">
                                        <span class="text-[10px] text-indigo-600 font-mono bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200">
                                            Token: {{ substr($rec->payslip_token, 0, 8) }}...
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-gray-400 font-sans">
                                        Belum ada kalkulasi slip gaji pada batch ini. Silakan klik Kalkulasi terlebih dahulu.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end pt-3 border-t border-gray-100">
                    <button type="button" wire:click="closeDetailModal" class="px-4 py-2 text-sm font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-xl transition-colors cursor-pointer">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
