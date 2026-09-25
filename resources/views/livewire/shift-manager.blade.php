<div>
    @if(!$activeShift)
        {{-- Form Buka Shift --}}
        <div class="bg-white rounded-3xl p-8 border border-[#E9E9E7] shadow-sm max-w-lg mx-auto text-center animate-slide-up-fade">
            <div class="w-16 h-16 bg-gray-50 rounded-2xl flex items-center justify-center mx-auto mb-5 border border-gray-100">
                <span class="material-symbols-rounded text-[32px] text-yovel-ink">lock_open</span>
            </div>
            <h3 class="text-xl font-bold text-yovel-ink mb-2">Buka Shift Kasir</h3>
            <p class="text-sm text-gray-500 mb-8">Masukkan jumlah modal kas (uang kembalian) awal yang ada di laci kasir saat ini.</p>

            <form wire:submit.prevent="openShift" class="text-left space-y-5">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Modal Awal (Tunai)</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 font-bold text-gray-400">Rp</span>
                        <input type="number" wire:model="opening_balance" required min="0" class="w-full pl-12 pr-4 py-3.5 bg-gray-50 border border-[#E9E9E7] rounded-2xl focus:ring-2 focus:ring-[#37352F] focus:border-yovel-ink transition-all font-mono font-bold text-lg" placeholder="0">
                    </div>
                    @error('opening_balance') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
                <button type="submit" wire:loading.attr="disabled" wire:target="openShift" class="w-full disabled:opacity-60 disabled:cursor-wait bg-yovel-ink text-white font-bold py-4 rounded-2xl shadow-md hover:bg-yovel-ink active:scale-[0.98] transition-all flex justify-center items-center gap-2">
                    <span wire:loading wire:target="openShift" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                    Mulai Bekerja
                </button>
            </form>
        </div>
    @else
        {{-- Dasbor Shift Aktif --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 animate-slide-up-fade">
            
            <!-- Info Shift Berjalan -->
            <div class="bg-white rounded-3xl p-6 md:p-8 border border-[#E9E9E7] shadow-sm flex flex-col">
                <div class="flex items-center justify-between mb-8">
                    <h3 class="text-lg font-bold text-yovel-ink flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-green-500 animate-pulse"></span> Shift Anda
                    </h3>
                    <span class="text-xs font-bold bg-green-50 border border-green-100 text-green-700 px-3 py-1 rounded-lg">Aktif</span>
                </div>

                <dl class="space-y-4 text-sm flex-1">
                    <div class="flex justify-between border-b border-gray-100 pb-3">
                        <dt class="text-gray-500 font-medium">Waktu Mulai</dt>
                        <dd class="font-bold">{{ $activeShift->opened_at->format('d M Y, H:i') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-gray-100 pb-3">
                        <dt class="text-gray-500 font-medium">Modal Awal</dt>
                        <dd class="font-mono font-bold">Rp {{ number_format($activeShift->opening_balance, 0, ',', '.') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-gray-100 pb-3">
                        <dt class="text-gray-500 font-medium">Pemasukan Tunai Hari Ini</dt>
                        <dd class="font-mono font-bold text-green-600">+ Rp {{ number_format($cashSales, 0, ',', '.') }}</dd>
                    </div>
                    <div class="flex justify-between pt-4 mt-auto">
                        <dt class="font-bold text-yovel-ink text-base">Estimasi Uang Fisik</dt>
                        <dd class="text-xl font-extrabold tabular-nums text-yovel-ink">Rp {{ number_format($expectedCash, 0, ',', '.') }}</dd>
                    </div>
                </dl>
                <div class="mt-6 p-4 bg-amber-50 border border-amber-100 rounded-2xl text-xs text-amber-700 font-medium flex gap-2">
                    <span class="material-symbols-rounded text-[16px]">info</span>
                    Transaksi QRIS & E-Wallet tidak dihitung di sini karena masuk otomatis ke rekening bank.
                </div>
            </div>

            <!-- Form Tutup Shift -->
            <div class="bg-white rounded-3xl p-6 md:p-8 border border-[#E9E9E7] shadow-sm">
                <h3 class="text-lg font-bold text-yovel-ink mb-2">Tutup Shift</h3>
                <p class="text-sm text-gray-500 mb-6">Hitung uang fisik di laci kasir secara teliti dan masukkan ke sistem.</p>

                <form wire:submit.prevent="closeShift" class="space-y-5">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Total Uang Fisik di Laci</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-4 font-bold text-gray-400">Rp</span>
                            <input type="number" wire:model="closing_balance" required min="0" class="w-full pl-12 pr-4 py-3.5 bg-gray-50 border border-[#E9E9E7] rounded-2xl focus:ring-2 focus:ring-[#37352F] transition-all font-mono font-bold text-lg" placeholder="0">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Keterangan / Selisih (Opsional)</label>
                        <textarea wire:model="notes" rows="3" class="w-full px-4 py-3 bg-gray-50 border border-[#E9E9E7] rounded-2xl focus:ring-2 focus:ring-[#37352F] transition-all text-sm" placeholder="Contoh: Ada selisih kurang Rp 2.000 karena tidak ada receh..."></textarea>
                    </div>
                    <button type="button"
                        x-on:click="
                            Swal.fire({
                                title: 'Akhiri Sesi Kasir?',
                                text: 'Data shift tidak dapat diubah setelah ditutup.',
                                icon: 'warning',
                                showCancelButton: true,
                                confirmButtonColor: '#37352F',
                                cancelButtonColor: '#6B7280',
                                confirmButtonText: 'Ya, Tutup Shift',
                                cancelButtonText: 'Batal',
                                reverseButtons: true,
                                customClass: { popup: 'rounded-2xl' },
                            }).then((result) => { if (result.isConfirmed) $el.closest('form').requestSubmit(); })
                        "
                        class="w-full bg-white border-2 border-yovel-ink text-yovel-ink font-bold py-4 disabled:opacity-60 disabled:cursor-wait rounded-2xl shadow-sm hover:bg-gray-50 active:scale-[0.98] transition-all flex justify-center items-center gap-2 mt-2">
                        Akhiri Sesi Kasir
                    </button>
                </form>
            </div>
        </div>
    @endif
</div>
