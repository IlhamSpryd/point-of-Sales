{{-- Operational Context Banner --}}
@if(!$this->activeShift)
    <div class="bg-rose-600 text-white px-4 py-2 text-xs font-bold flex items-center justify-between shrink-0 shadow-sm z-40">
        <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-[18px]">lock</span>
            <span>Terminal POS Belum Membuka Shift: Kasir wajib membuka shift sebelum dapat memproses transaksi penjualan.</span>
        </div>
        <a href="{{ route('shifts.index') }}" class="underline hover:text-white/80 bg-white/20 px-3 py-1 rounded-lg transition-colors">Buka Shift Kasir</a>
    </div>
@elseif($this->activeShift->status === 'handover_pending')
    <div class="bg-amber-600 text-white px-4 py-2 text-xs font-bold flex items-center justify-between shrink-0 shadow-sm z-40">
        <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-[18px]">pending_actions</span>
            <span>Shift Sedang Dalam Proses Serah Terima (Handover): Konfirmasi penerimaan shift atau batalkan serah terima terlebih dahulu.</span>
        </div>
        <a href="{{ route('shifts.index') }}" class="underline hover:text-white/80 bg-white/20 px-3 py-1 rounded-lg transition-colors">Ke Manajemen Shift</a>
    </div>
@endif
