<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <div class="flex items-center gap-3">
                <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">{{ $device->device_name }}</h4>
                @if($device->status === 'paired')
                    <x-badge type="success">Terhubung (Paired)</x-badge>
                @elseif($device->status === 'pending')
                    <x-badge type="warning">Menunggu Pairing</x-badge>
                @else
                    <x-badge type="danger">Dicabut (Revoked)</x-badge>
                @endif
            </div>
            <p class="text-sm font-medium text-[#787774] mt-1 font-mono">Kode: {{ $device->device_code }} &bull; Cabang: {{ $device->store?->name }}</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('devices.edit', $device) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-apeiron-ink text-sm font-medium rounded-xl transition-all duration-200 shadow-sm active:scale-95" wire:navigate>
                <span class="material-symbols-rounded text-[18px]">edit</span> Ubah
            </a>
            <a href="{{ route('devices.index') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-apeiron-ink text-sm font-medium rounded-xl transition-all duration-200 shadow-sm active:scale-95" wire:navigate>
                <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
            </a>
        </div>
    </div>

    @if(session('success'))
        <x-alert type="success">{{ session('success') }}</x-alert>
    @endif
    @if(session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Kartu Status Pairing & Token --}}
        <div class="lg:col-span-2 space-y-6">
            @if($device->status === 'pending')
                <div class="card-surface p-6 md:p-8 border-2 border-amber-200 bg-amber-50/40">
                    <div class="flex items-start gap-4">
                        <div class="p-3 bg-amber-100 text-amber-800 rounded-2xl">
                            <span class="material-symbols-rounded text-3xl">key</span>
                        </div>
                        <div class="flex-1">
                            <h5 class="text-lg font-bold text-apeiron-ink">Token Pairing Terminal Fisik</h5>
                            <p class="text-sm text-[#787774] mt-1">
                                Masukkan kode berikut pada terminal kasir fisik Anda untuk mengautentikasi dan mengikat perangkat ini ke toko.
                            </p>

                            <div class="mt-6 flex flex-col sm:flex-row sm:items-center gap-4">
                                <div class="px-6 py-4 bg-white rounded-2xl border-2 border-dashed border-amber-300 font-mono text-2xl md:text-3xl font-extrabold tracking-widest text-apeiron-ink text-center shadow-sm select-all">
                                    {{ $device->pairing_token ?? 'KEDALUWARSA' }}
                                </div>
                                
                                <form action="{{ route('devices.regenerate-token', $device) }}" method="POST" onsubmit="return confirm('Terbitkan ulang token pairing baru? Token lama akan hangus.');">
                                    @csrf
                                    <x-button type="submit" variant="secondary" class="h-12 px-4 whitespace-nowrap">
                                        <span class="material-symbols-rounded text-[18px]">refresh</span> Terbitkan Ulang
                                    </x-button>
                                </form>
                            </div>

                            @if($device->pairing_token_expires_at)
                                <p class="text-xs text-[#787774] mt-3 flex items-center gap-1.5 font-medium">
                                    <span class="material-symbols-rounded text-[16px] text-amber-600">timer</span>
                                    Berlaku hingga: {{ $device->pairing_token_expires_at->translatedFormat('d F Y H:i') }} ({{ $device->pairing_token_expires_at->diffForHumans() }})
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            @elseif($device->status === 'paired')
                <div class="card-surface p-6 md:p-8 border-2 border-emerald-200 bg-emerald-50/30">
                    <div class="flex items-start gap-4">
                        <div class="p-3 bg-emerald-100 text-emerald-800 rounded-2xl">
                            <span class="material-symbols-rounded text-3xl">verified</span>
                        </div>
                        <div class="flex-1">
                            <h5 class="text-lg font-bold text-apeiron-ink">Perangkat Terhubung & Terotorisasi</h5>
                            <p class="text-sm text-[#787774] mt-1">
                                Terminal fisik kasir ini telah terikat secara aman dengan tanda tangan kriptografi/fingerprint unik.
                            </p>

                            <div class="mt-6 space-y-3 font-mono text-xs">
                                <div class="p-3 bg-white rounded-xl border border-[#E9E9E7]">
                                    <span class="text-[#787774] block text-[11px] uppercase font-sans font-semibold mb-1">Device Fingerprint</span>
                                    <span class="text-apeiron-ink break-all select-all">{{ $device->device_fingerprint }}</span>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div class="p-3 bg-white rounded-xl border border-[#E9E9E7]">
                                        <span class="text-[#787774] block text-[11px] uppercase font-sans font-semibold mb-1">Alamat IP Terakhir</span>
                                        <span class="text-apeiron-ink">{{ $device->ip_address ?? '-' }}</span>
                                    </div>
                                    <div class="p-3 bg-white rounded-xl border border-[#E9E9E7]">
                                        <span class="text-[#787774] block text-[11px] uppercase font-sans font-semibold mb-1">Aktif Terakhir</span>
                                        <span class="text-apeiron-ink">{{ $device->last_seen_at ? $device->last_seen_at->translatedFormat('d M Y H:i:s') : 'Belum tercatat' }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 pt-4 border-t border-emerald-200 flex justify-end">
                                <form action="{{ route('devices.revoke', $device) }}" method="POST" onsubmit="return confirm('Pencabutan akses akan memutuskan koneksi terminal fisik kasir ini seketika. Lanjutkan?');">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-rose-600 bg-white hover:bg-rose-50 border border-rose-200 text-sm font-semibold transition-colors duration-200 shadow-sm active:scale-95">
                                        <span class="material-symbols-rounded text-[18px]">phonelink_erase</span> Putuskan Sambungan (Revoke)
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div class="card-surface p-6 md:p-8 border-2 border-rose-200 bg-rose-50/30">
                    <div class="flex items-start gap-4">
                        <div class="p-3 bg-rose-100 text-rose-800 rounded-2xl">
                            <span class="material-symbols-rounded text-3xl">block</span>
                        </div>
                        <div class="flex-1">
                            <h5 class="text-lg font-bold text-apeiron-ink">Akses Perangkat Dicabut (Revoked)</h5>
                            <p class="text-sm text-[#787774] mt-1">
                                Perangkat fisik ini saat ini dinonaktifkan dan tidak dapat digunakan untuk memproses transaksi kasir.
                            </p>

                            <div class="mt-6 pt-4 border-t border-rose-200 flex justify-end">
                                <form action="{{ route('devices.regenerate-token', $device) }}" method="POST" onsubmit="return confirm('Terbitkan token pairing baru untuk mengaktifkan kembali perangkat ini?');">
                                    @csrf
                                    <x-button type="submit" variant="primary">
                                        <span class="material-symbols-rounded text-[18px]">replay</span> Aktifkan Kembali & Terbitkan Token
                                    </x-button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Panduan Teknis Kasir --}}
            <div class="card-surface p-6 space-y-4">
                <h5 class="text-sm font-bold text-apeiron-ink uppercase tracking-wider">Panduan Pemasangan Kasir Fisik</h5>
                <ol class="list-decimal list-inside text-sm text-[#787774] space-y-2">
                    <li>Buka aplikasi Apeiron POS atau buka browser di terminal fisik kasir Anda.</li>
                    <li>Pada layar inisialisasi terminal, pilih menu <strong class="text-apeiron-ink">"Sambungkan Terminal / Pairing Device"</strong>.</li>
                    <li>Ketikkan token pairing 12-karakter yang tercantum di atas.</li>
                    <li>Sistem secara otomatis akan mendeteksi spesifikasi browser/mesin fisik dan menguncinya secara mutlak ke cabang <strong class="text-apeiron-ink">{{ $device->store?->name }}</strong>.</li>
                </ol>
            </div>
        </div>

        {{-- Kartu Rincian Spesifikasi --}}
        <div class="space-y-6">
            <div class="card-surface p-6 space-y-4">
                <h5 class="text-sm font-bold text-apeiron-ink uppercase tracking-wider">Rincian Perangkat</h5>
                
                <div class="space-y-3 text-sm">
                    <div>
                        <span class="text-xs text-[#787774] block">Nama Perangkat</span>
                        <span class="font-medium text-apeiron-ink">{{ $device->device_name }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-[#787774] block">Kode Unik</span>
                        <span class="font-mono font-medium text-apeiron-ink">{{ $device->device_code }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-[#787774] block">Tipe Perangkat</span>
                        <span class="font-medium text-apeiron-ink">
                            {{ match($device->device_type) {
                                'pos_terminal' => 'Terminal POS Kasir',
                                'kds_display' => 'Display Dapur (KDS)',
                                'waiter_tablet' => 'Tablet Pelayan',
                                default => $device->device_type,
                            } }}
                        </span>
                    </div>
                    <div>
                        <span class="text-xs text-[#787774] block">Cabang (Store)</span>
                        <span class="font-medium text-apeiron-ink">{{ $device->store?->name }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-[#787774] block">Status Keaktifan</span>
                        <x-badge :type="$device->is_active ? 'success' : 'secondary'">
                            {{ $device->is_active ? 'Aktif' : 'Nonaktif' }}
                        </x-badge>
                    </div>
                    <div>
                        <span class="text-xs text-[#787774] block">Didaftarkan Pada</span>
                        <span class="text-apeiron-ink">{{ $device->created_at?->translatedFormat('d F Y H:i') }}</span>
                    </div>
                </div>

                <div class="pt-4 border-t border-[#E9E9E7]">
                    <form action="{{ route('devices.destroy', $device) }}" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data perangkat ini secara permanen?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-rose-600 bg-rose-50 hover:bg-rose-100 text-xs font-semibold transition-colors duration-200">
                            <span class="material-symbols-rounded text-[18px]">delete</span> Hapus Data Perangkat
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
