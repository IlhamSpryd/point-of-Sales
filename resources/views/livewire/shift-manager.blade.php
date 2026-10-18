<div x-data="{
    openCloseModal: false,
    expectedCash: {{ $expectedCash ?? 0 }},
    countedCash: 0,
    get discrepancy() {
        return (parseInt(this.countedCash) || 0) - this.expectedCash;
    }
}">
    @if(!$activeShift)
        {{-- Form Buka Shift --}}
        <div class="bg-white rounded-3xl p-8 border border-[#E9E9E7] shadow-sm max-w-lg mx-auto text-center animate-slide-up-fade">
            <div class="w-16 h-16 bg-gray-50 rounded-2xl flex items-center justify-center mx-auto mb-5 border border-gray-100">
                <span class="material-symbols-rounded text-[32px] text-apeiron-ink">lock_open</span>
            </div>
            <h3 class="text-xl font-bold text-apeiron-ink mb-2">Buka Shift Kasir</h3>
            <p class="text-sm text-gray-500 mb-8">Pilih perangkat terminal kasir, masukkan modal awal fisik di laci kasir, dan verifikasi PIN Anda.</p>

            <form wire:submit.prevent="openShift" class="text-left space-y-5">
                {{-- Pilihan Perangkat / Terminal --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Perangkat / Terminal Kasir</label>
                    <div class="relative">
                        <select wire:model="selectedDeviceId" class="w-full px-4 py-3.5 bg-gray-50 border border-[#E9E9E7] rounded-2xl focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all text-sm font-semibold text-apeiron-ink">
                            <option value="">-- Pilih Terminal Perangkat --</option>
                            @foreach($devices as $device)
                                <option value="{{ $device->id }}">
                                    {{ $device->device_name }} ({{ $device->device_code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    @error('deviceId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                {{-- Modal Awal --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Modal Awal Kas Laci</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 font-bold text-gray-400">Rp</span>
                        <input type="number" wire:model="opening_balance" required min="0" class="w-full pl-12 pr-4 py-3.5 bg-gray-50 border border-[#E9E9E7] rounded-2xl focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all font-mono font-bold text-lg" placeholder="0">
                    </div>
                    @error('opening_balance') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                {{-- PIN Kasir --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">PIN Kasir (6 Digit)</label>
                    <div class="relative">
                        <input type="password" wire:model="cashierPin" maxlength="6" class="w-full px-4 py-3.5 bg-gray-50 border border-[#E9E9E7] rounded-2xl focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all font-mono font-bold text-lg tracking-widest text-center" placeholder="••••••">
                    </div>
                    @error('cashierPin') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <button type="submit" wire:loading.attr="disabled" wire:target="openShift" class="w-full disabled:opacity-60 disabled:cursor-wait bg-apeiron-ink hover:bg-[#111111] text-white font-bold py-4 rounded-2xl shadow-md active:scale-[0.98] transition-all flex justify-center items-center gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-apeiron-ink focus-visible:ring-offset-2">
                    <span wire:loading wire:target="openShift" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                    Mulai Sesi Shift
                </button>
            </form>
        </div>
    @else
        {{-- Dasbor Shift Aktif --}}
        <div class="space-y-6 animate-slide-up-fade">

            {{-- Operational Context Header --}}
            <div class="bg-white rounded-3xl p-6 border border-[#E9E9E7] shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600">
                        <span class="material-symbols-rounded text-[28px]">point_of_sale</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-bold text-apeiron-ink">Shift #{{ $activeShift->id }}</h3>
                            @if($activeShift->status === 'handover_pending')
                                <span class="text-xs font-bold bg-amber-50 border border-amber-200 text-amber-700 px-2.5 py-0.5 rounded-full">Serah Terima Tertunda</span>
                            @else
                                <span class="text-xs font-bold bg-emerald-50 border border-emerald-200 text-emerald-700 px-2.5 py-0.5 rounded-full flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Aktif
                                </span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Dibuka oleh <span class="font-semibold text-gray-700">{{ $activeShift->user->name ?? 'Kasir' }}</span> 
                            pada <span class="font-medium text-gray-700">{{ $activeShift->opened_at->format('d M Y, H:i') }}</span>
                            @if($activeShift->device)
                                &bull; Terminal: <span class="font-semibold text-gray-700">{{ $activeShift->device->device_name }} ({{ $activeShift->device->device_code }})</span>
                            @endif
                        </p>
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                    <button type="button" wire:click="$set('isMovementModalOpen', true)" class="px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-xs font-bold text-apeiron-ink hover:bg-gray-50 active:scale-95 transition-all shadow-sm flex items-center gap-1.5">
                        <span class="material-symbols-rounded text-[18px]">account_balance_wallet</span> Kas Masuk / Keluar
                    </button>
                    <button type="button" wire:click="$set('isHandoverModalOpen', true)" class="px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-xs font-bold text-apeiron-ink hover:bg-gray-50 active:scale-95 transition-all shadow-sm flex items-center gap-1.5">
                        <span class="material-symbols-rounded text-[18px]">swap_horiz</span> Serah Terima
                    </button>
                    <button type="button" x-on:click="openCloseModal = true" class="px-4 py-2.5 rounded-xl bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] active:scale-95 transition-all shadow-sm flex items-center gap-1.5">
                        <span class="material-symbols-rounded text-[18px]">lock</span> Tutup Shift
                    </button>
                    @if(auth()->user()?->role?->is_admin || in_array(auth()->user()?->role?->name, ['Owner', 'Manager', 'Supervisor'], true))
                        <button type="button" wire:click="$set('isForceCloseModalOpen', true)" class="px-3 py-2.5 rounded-xl border border-rose-200 bg-rose-50 text-rose-700 text-xs font-bold hover:bg-rose-100 active:scale-95 transition-all flex items-center gap-1">
                            <span class="material-symbols-rounded text-[18px]">gavel</span> Force Close
                        </button>
                    @endif
                </div>
            </div>

            {{-- Pending Handover Panel (Jika ada) --}}
            @if($pendingHandover)
                <div class="bg-amber-50 border border-amber-200 rounded-3xl p-6 shadow-sm">
                    <div class="flex items-start gap-4">
                        <span class="material-symbols-rounded text-[28px] text-amber-600 shrink-0">pending_actions</span>
                        <div class="flex-1">
                            <h4 class="font-bold text-base text-amber-900">Konfirmasi Serah Terima Shift</h4>
                            <p class="text-sm text-amber-800 mt-1">
                                Kasir <span class="font-bold">{{ $pendingHandover->fromUser?->name }}</span> menyerahkan shift kepada <span class="font-bold">{{ $pendingHandover->toUser?->name }}</span>.
                                Fisik kas dihitung saat handover: <span class="font-mono font-bold">Rp {{ number_format($pendingHandover->counted_cash_at_handover, 0, ',', '.') }}</span>
                                @if($pendingHandover->cash_discrepancy != 0)
                                    (Selisih kas: <span class="font-mono font-bold text-rose-600">Rp {{ number_format($pendingHandover->cash_discrepancy, 0, ',', '.') }}</span>)
                                @endif
                            </p>
                            @if($pendingHandover->notes)
                                <p class="text-xs text-amber-700 mt-1 italic">Catatan: {{ $pendingHandover->notes }}</p>
                            @endif

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <div>
                                    <input type="password" wire:model="cashierPin" placeholder="PIN Kasir Penerima" maxlength="6" class="px-3 py-2 bg-white border border-amber-300 rounded-xl text-xs font-mono font-bold text-center tracking-wider focus:ring-amber-500 focus:border-amber-500">
                                </div>
                                @if($pendingHandover->cash_discrepancy != 0)
                                    <div>
                                        <input type="password" wire:model="supervisorPin" placeholder="PIN Supervisor (Ada Selisih)" maxlength="6" class="px-3 py-2 bg-white border border-rose-300 rounded-xl text-xs font-mono font-bold text-center tracking-wider focus:ring-rose-500 focus:border-rose-500">
                                    </div>
                                @endif
                                <button type="button" wire:click="acceptHandover({{ $pendingHandover->id }})" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-sm active:scale-95 transition-all">
                                    Terima Serah Terima
                                </button>
                                <button type="button" wire:click="cancelHandover({{ $pendingHandover->id }})" class="px-4 py-2 border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold rounded-xl active:scale-95 transition-all">
                                    Batalkan
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- KPI Summary Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="bg-white rounded-3xl p-6 border border-[#E9E9E7] shadow-sm">
                    <span class="text-xs font-bold uppercase tracking-wider text-gray-400 block mb-2">Modal Awal</span>
                    <p class="text-xl font-extrabold text-apeiron-ink font-mono">Rp {{ number_format($activeShift->opening_balance, 0, ',', '.') }}</p>
                    <span class="text-xs text-gray-500 mt-1 block">Saldo kas saat shift dibuka</span>
                </div>

                <div class="bg-white rounded-3xl p-6 border border-[#E9E9E7] shadow-sm">
                    <span class="text-xs font-bold uppercase tracking-wider text-emerald-600 block mb-2">Pemasukan Kas Penjualan</span>
                    <p class="text-xl font-extrabold text-emerald-600 font-mono">+ Rp {{ number_format($cashSales, 0, ',', '.') }}</p>
                    <span class="text-xs text-gray-500 mt-1 block">Total transaksi tunai shift ini</span>
                </div>

                @php
                    $totalCashIn = $cashMovements->where('type.value', 'cash_in')->sum('amount');
                    $totalCashOut = $cashMovements->where('type.value', 'cash_out')->sum('amount');
                @endphp
                <div class="bg-white rounded-3xl p-6 border border-[#E9E9E7] shadow-sm">
                    <span class="text-xs font-bold uppercase tracking-wider text-amber-600 block mb-2">Mutasi Kas Laci</span>
                    <p class="text-base font-bold text-apeiron-ink font-mono">
                        <span class="text-emerald-600">+{{ number_format($totalCashIn, 0, ',', '.') }}</span> / 
                        <span class="text-rose-600">-{{ number_format($totalCashOut, 0, ',', '.') }}</span>
                    </p>
                    <span class="text-xs text-gray-500 mt-1 block">Cash-In vs Cash-Out / Drop</span>
                </div>

                <div class="bg-white rounded-3xl p-6 border border-[#E9E9E7] shadow-sm bg-gradient-to-br from-white to-gray-50">
                    <span class="text-xs font-bold uppercase tracking-wider text-apeiron-ink block mb-2">Estimasi Fisik Kas di Laci</span>
                    <p class="text-2xl font-extrabold text-apeiron-ink font-mono">Rp {{ number_format($expectedCash, 0, ',', '.') }}</p>
                    <span class="text-xs text-gray-500 mt-1 block">Saldo wajib saat penutupan</span>
                </div>
            </div>

            {{-- Riwayat Pergerakan Kas Laci (Cash Drawer Movements) --}}
            <div class="bg-white rounded-3xl p-6 border border-[#E9E9E7] shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h4 class="font-bold text-base text-apeiron-ink">Riwayat Pergerakan Kas Laci</h4>
                        <p class="text-xs text-gray-500 mt-0.5">Catatan kas masuk, kas keluar, dan cash drop pada shift aktif.</p>
                    </div>
                    <button type="button" wire:click="$set('isMovementModalOpen', true)" class="px-3.5 py-1.5 rounded-xl bg-gray-100 hover:bg-gray-200 text-xs font-bold text-apeiron-ink transition-all flex items-center gap-1">
                        <span class="material-symbols-rounded text-[16px]">add</span> Tambah Mutasi
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-gray-100 text-gray-400 uppercase tracking-wider font-semibold">
                                <th class="py-3 px-3">Waktu</th>
                                <th class="py-3 px-3">Tipe</th>
                                <th class="py-3 px-3">Kategori</th>
                                <th class="py-3 px-3 text-right">Nominal</th>
                                <th class="py-3 px-3">Alasan / Keterangan</th>
                                <th class="py-3 px-3">Operator</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @forelse($cashMovements as $move)
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="py-3 px-3 text-gray-500">{{ $move->created_at->format('H:i:s') }}</td>
                                    <td class="py-3 px-3">
                                        @if($move->type->value === 'cash_in')
                                            <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 font-bold rounded-md border border-emerald-100">Cash In</span>
                                        @else
                                            <span class="px-2 py-0.5 bg-rose-50 text-rose-700 font-bold rounded-md border border-rose-100">Cash Out</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 font-medium text-gray-700">{{ $move->category->value }}</td>
                                    <td class="py-3 px-3 font-mono font-bold text-right {{ $move->type->value === 'cash_in' ? 'text-emerald-600' : 'text-rose-600' }}">
                                        {{ $move->type->value === 'cash_in' ? '+' : '-' }} Rp {{ number_format($move->amount, 0, ',', '.') }}
                                    </td>
                                    <td class="py-3 px-3 text-gray-600 max-w-xs truncate">{{ $move->reason }}</td>
                                    <td class="py-3 px-3 text-gray-500">{{ $move->createdBy->name ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-8 text-center text-gray-400">Belum ada mutasi kas laci pada shift ini.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Riwayat Serah Terima Shift --}}
            @if($handovers->isNotEmpty())
                <div class="bg-white rounded-3xl p-6 border border-[#E9E9E7] shadow-sm">
                    <h4 class="font-bold text-base text-apeiron-ink mb-1">Riwayat Serah Terima Shift</h4>
                    <p class="text-xs text-gray-500 mb-4">Daftar transfer operasional kasir pada shift ini.</p>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-gray-100 text-gray-400 uppercase tracking-wider font-semibold">
                                    <th class="py-3 px-3">Waktu</th>
                                    <th class="py-3 px-3">Dari Kasir</th>
                                    <th class="py-3 px-3">Ke Kasir</th>
                                    <th class="py-3 px-3 text-right">Kas Dihitung</th>
                                    <th class="py-3 px-3 text-right">Selisih</th>
                                    <th class="py-3 px-3 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach($handovers as $ho)
                                    <tr>
                                        <td class="py-3 px-3 text-gray-500">{{ $ho->initiated_at->format('d/m H:i') }}</td>
                                        <td class="py-3 px-3 font-medium text-gray-700">{{ $ho->fromUser?->name }}</td>
                                        <td class="py-3 px-3 font-medium text-gray-700">{{ $ho->toUser?->name }}</td>
                                        <td class="py-3 px-3 font-mono font-bold text-right">Rp {{ number_format($ho->counted_cash_at_handover, 0, ',', '.') }}</td>
                                        <td class="py-3 px-3 font-mono text-right {{ $ho->cash_discrepancy != 0 ? 'text-rose-600 font-bold' : 'text-gray-500' }}">
                                            Rp {{ number_format($ho->cash_discrepancy, 0, ',', '.') }}
                                        </td>
                                        <td class="py-3 px-3 text-center">
                                            @if($ho->status === 'accepted')
                                                <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 font-bold rounded-md">Diterima</span>
                                            @elseif($ho->status === 'cancelled')
                                                <span class="px-2 py-0.5 bg-gray-100 text-gray-600 font-bold rounded-md">Dibatalkan</span>
                                            @else
                                                <span class="px-2 py-0.5 bg-amber-50 text-amber-700 font-bold rounded-md">Menunggu</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- Modal Catat Pergerakan Kas (Cash-In / Cash-Out / Cash Drop) --}}
        @if($isMovementModalOpen)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4 animate-fade-in">
                <div class="bg-white rounded-3xl p-6 md:p-8 border border-[#E9E9E7] shadow-2xl max-w-md w-full">
                    <h3 class="text-lg font-bold text-apeiron-ink mb-1">Catat Mutasi Kas Laci</h3>
                    <p class="text-xs text-gray-500 mb-6">Pencatatan kas masuk atau kas keluar (expense/drop) secara akurat.</p>

                    <form wire:submit.prevent="recordCashMovement" class="space-y-4">
                        {{-- Tipe Mutasi --}}
                        <div class="grid grid-cols-2 gap-2 p-1 bg-gray-100 rounded-2xl">
                            <label class="flex items-center justify-center py-2 text-xs font-bold rounded-xl cursor-pointer transition-all {{ $movementType === 'cash_in' ? 'bg-white text-apeiron-ink shadow-sm' : 'text-gray-500' }}">
                                <input type="radio" wire:model.live="movementType" value="cash_in" class="sr-only"> Cash In (Masuk)
                            </label>
                            <label class="flex items-center justify-center py-2 text-xs font-bold rounded-xl cursor-pointer transition-all {{ $movementType === 'cash_out' ? 'bg-white text-apeiron-ink shadow-sm' : 'text-gray-500' }}">
                                <input type="radio" wire:model.live="movementType" value="cash_out" class="sr-only"> Cash Out (Keluar/Drop)
                            </label>
                        </div>

                        {{-- Kategori Mutasi --}}
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1">Kategori</label>
                            <select wire:model="movementCategory" class="w-full px-4 py-3 bg-gray-50 border border-[#E9E9E7] rounded-xl text-xs font-semibold text-apeiron-ink">
                                @if($movementType === 'cash_in')
                                    <option value="restock_change">Tambah Uang Kembalian (Restock Change)</option>
                                    <option value="correction">Koreksi Kas Masuk</option>
                                @else
                                    <option value="petty_expense">Pengeluaran Operasional (Petty Expense)</option>
                                    <option value="bank_deposit">Setor Bank / Cash Drop (Bank Deposit)</option>
                                    <option value="owner_withdrawal">Penarikan Pemilik (Owner Withdrawal)</option>
                                    <option value="correction">Koreksi Kas Keluar</option>
                                @endif
                            </select>
                        </div>

                        {{-- Nominal --}}
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1">Nominal (Rp)</label>
                            <input type="number" wire:model="movementAmount" required min="1" class="w-full px-4 py-3 bg-gray-50 border border-[#E9E9E7] rounded-xl font-mono font-bold text-base text-apeiron-ink" placeholder="0">
                            @error('movementAmount') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        {{-- Alasan / Keterangan --}}
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1">Alasan / Keterangan</label>
                            <textarea wire:model="movementReason" required rows="2" class="w-full px-4 py-3 bg-gray-50 border border-[#E9E9E7] rounded-xl text-xs text-apeiron-ink" placeholder="Contoh: Tambah modal pecahan kecil Rp 5.000..."></textarea>
                            @error('movementReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        {{-- PIN Supervisor jika Cash-Out --}}
                        @if($movementType === 'cash_out')
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-rose-600 mb-1">PIN Supervisor (Otorisasi Cash Out)</label>
                                <input type="password" wire:model="movementSupervisorPin" maxlength="6" class="w-full px-4 py-3 bg-rose-50/40 border border-rose-200 rounded-xl text-center font-mono font-bold text-sm tracking-widest text-rose-900" placeholder="••••••">
                                @error('movementSupervisorPin') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>
                        @endif

                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button type="button" wire:click="$set('isMovementModalOpen', false)" class="px-4 py-2.5 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                                Batal
                            </button>
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] shadow-sm">
                                Simpan Mutasi
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        {{-- Modal Inisiasi Serah Terima Shift (Handover) --}}
        @if($isHandoverModalOpen)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4 animate-fade-in">
                <div class="bg-white rounded-3xl p-6 md:p-8 border border-[#E9E9E7] shadow-2xl max-w-md w-full">
                    <h3 class="text-lg font-bold text-apeiron-ink mb-1">Inisiasi Serah Terima Shift</h3>
                    <p class="text-xs text-gray-500 mb-6">Serahkan tanggung jawab kas fisik ke kasir shift berikutnya.</p>

                    <form wire:submit.prevent="initiateHandover" class="space-y-4">
                        {{-- Pilih Kasir Penerima --}}
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1">Kasir Penerima</label>
                            <select wire:model="handoverToUserId" required class="w-full px-4 py-3 bg-gray-50 border border-[#E9E9E7] rounded-xl text-xs font-semibold text-apeiron-ink">
                                <option value="">-- Pilih Kasir Penerima --</option>
                                @foreach($eligibleCashiers as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->role?->name ?? 'Kasir' }})</option>
                                @endforeach
                            </select>
                            @error('handoverToUserId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        {{-- Kas Dihitung saat Handover --}}
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1">Kas Fisik Dihitung (Rp)</label>
                            <input type="number" wire:model="handoverCountedCash" required min="0" class="w-full px-4 py-3 bg-gray-50 border border-[#E9E9E7] rounded-xl font-mono font-bold text-base text-apeiron-ink" placeholder="0">
                            @error('handoverCountedCash') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        {{-- Catatan --}}
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1">Catatan Handover (Opsional)</label>
                            <textarea wire:model="handoverNotes" rows="2" class="w-full px-4 py-3 bg-gray-50 border border-[#E9E9E7] rounded-xl text-xs text-apeiron-ink" placeholder="Catatan operasional..."></textarea>
                        </div>

                        {{-- PIN Kasir Penyerah --}}
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1">PIN Kasir Penyerah</label>
                            <input type="password" wire:model="cashierPin" maxlength="6" class="w-full px-4 py-3 bg-gray-50 border border-[#E9E9E7] rounded-xl text-center font-mono font-bold text-sm tracking-widest" placeholder="••••••">
                            @error('cashierPin') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button type="button" wire:click="$set('isHandoverModalOpen', false)" class="px-4 py-2.5 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                                Batal
                            </button>
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] shadow-sm">
                                Ajukan Serah Terima
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        {{-- Modal Tutup Shift Normal --}}
        <div x-show="openCloseModal" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4 animate-fade-in">
            <div class="bg-white rounded-3xl p-6 md:p-8 border border-[#E9E9E7] shadow-2xl max-w-md w-full">
                <h3 class="text-lg font-bold text-apeiron-ink mb-1">Tutup Sesi Shift Kasir</h3>
                <p class="text-xs text-gray-500 mb-5">Hitung seluruh uang fisik di laci kasir dan masukkan ke sistem.</p>

                <form wire:submit.prevent="closeShift" class="space-y-4">
                    {{-- Rekonsiliasi Kas Live --}}
                    <div class="p-4 bg-gray-50 rounded-2xl border border-gray-100 space-y-2 text-xs">
                        <div class="flex justify-between text-gray-500">
                            <span>Estimasi Kas di Laci:</span>
                            <span class="font-mono font-bold text-apeiron-ink">Rp {{ number_format($expectedCash, 0, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between text-gray-500">
                            <span>Fisik Kas Dihitung:</span>
                            <span class="font-mono font-bold text-apeiron-ink" x-text="'Rp ' + (parseInt(countedCash) || 0).toLocaleString('id-ID')"></span>
                        </div>
                        <div class="flex justify-between pt-2 border-t border-gray-200 font-bold">
                            <span>Selisih Kas:</span>
                            <span class="font-mono" :class="discrepancy === 0 ? 'text-emerald-600' : 'text-rose-600'" x-text="(discrepancy >= 0 ? '+Rp ' : '-Rp ') + Math.abs(discrepancy).toLocaleString('id-ID')"></span>
                        </div>
                    </div>

                    {{-- Total Uang Fisik --}}
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1">Total Uang Fisik di Laci</label>
                        <input type="number" wire:model="closing_balance" x-model="countedCash" required min="0" class="w-full px-4 py-3 bg-gray-50 border border-[#E9E9E7] rounded-xl font-mono font-bold text-base text-apeiron-ink" placeholder="0">
                    </div>

                    {{-- PIN Kasir Aktif --}}
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1">PIN Kasir</label>
                        <input type="password" wire:model="cashierPin" maxlength="6" class="w-full px-4 py-3 bg-gray-50 border border-[#E9E9E7] rounded-xl text-center font-mono font-bold text-sm tracking-widest" placeholder="••••••">
                        @error('cashierPin') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    {{-- Keterangan / Selisih --}}
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1">Keterangan / Alasan Selisih</label>
                        <textarea wire:model="notes" rows="2" class="w-full px-4 py-3 bg-gray-50 border border-[#E9E9E7] rounded-xl text-xs text-apeiron-ink" placeholder="Catatan jika ada selisih..."></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" x-on:click="openCloseModal = false" class="px-4 py-2.5 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                            Batal
                        </button>
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] shadow-sm">
                            Konfirmasi Tutup Shift
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Modal Force Close Shift (Khusus Supervisor / Manager) --}}
        @if($isForceCloseModalOpen)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4 animate-fade-in">
                <div class="bg-white rounded-3xl p-6 md:p-8 border border-rose-200 shadow-2xl max-w-md w-full">
                    <div class="flex items-center gap-3 text-rose-600 mb-3">
                        <span class="material-symbols-rounded text-[28px]">warning</span>
                        <h3 class="text-lg font-bold">Force Close Shift</h3>
                    </div>
                    <p class="text-xs text-gray-600 mb-5">
                        Aksi ini khusus Supervisor/Manager untuk menutup paksa shift yang ditinggalkan kasir atau bermasalah.
                    </p>

                    <form wire:submit.prevent="forceCloseShift" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1">Kas Fisik Terhitung di Laci</label>
                            <input type="number" wire:model="closing_balance" required min="0" class="w-full px-4 py-3 bg-gray-50 border border-[#E9E9E7] rounded-xl font-mono font-bold text-base text-apeiron-ink" placeholder="0">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-rose-600 mb-1">Alasan Penutupan Paksa (Wajib)</label>
                            <textarea wire:model="forceCloseReason" required rows="2" class="w-full px-4 py-3 bg-rose-50/30 border border-rose-200 rounded-xl text-xs text-apeiron-ink" placeholder="Contoh: Kasir sakit mendadak dan shift diambil alih supervisor..."></textarea>
                            @error('forceCloseReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-rose-600 mb-1">PIN Supervisor (Otorisasi)</label>
                            <input type="password" wire:model="forceCloseSupervisorPin" maxlength="6" required class="w-full px-4 py-3 bg-rose-50/50 border border-rose-200 rounded-xl text-center font-mono font-bold text-sm tracking-widest text-rose-900" placeholder="••••••">
                            @error('forceCloseSupervisorPin') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button type="button" wire:click="$set('isForceCloseModalOpen', false)" class="px-4 py-2.5 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                                Batal
                            </button>
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-sm">
                                Eksekusi Force Close
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endif
</div>
