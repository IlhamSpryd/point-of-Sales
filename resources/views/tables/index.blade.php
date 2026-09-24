<x-app-layout>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Manajemen Meja</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Kelola meja & QR Code self-order</p>
        </div>
        <x-list-toolbar search-action="{{ route('tables.index') }}" search-placeholder="Cari meja...">
            <x-slot:actions>
                <a href="{{ route('tables.create') }}" class="flex-1 sm:flex-none" wire:navigate>
                    <x-button variant="primary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded text-[18px]">add</span> Tambah Meja
                    </x-button>
                </a>
            </x-slot:actions>
        </x-list-toolbar>
    </div>

    @if(session('success'))
        <x-alert type="success">{{ session('success') }}</x-alert>
    @endif
    @if(session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    <div class="card-surface flex flex-col flex-1 min-h-0">
        {{-- Desktop/tablet-landscape: existing table --}}
        <div class="hidden lg:block overflow-auto flex-1 table-scroll-shadow">
            <table class="data-table relative w-full">
                <thead class="sticky top-0 z-10 shadow-sm">
                    <tr class="bg-[#F7F7F5]">
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">ID Meja</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">Info Meja</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">Status</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-left">QR Code</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E9E9E7]">
                    @forelse($tables as $table)
                        <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                            <td class="px-6 py-4 font-medium text-[#37352F] text-sm whitespace-nowrap">{{ $table->table_code }}</td>
                            <td class="px-6 py-4">
                                <div class="font-bold text-[#37352F] text-sm">{{ $table->table_name }}</div>
                                <div class="text-xs text-[#787774] mt-1 flex items-center gap-2">
                                    <span class="inline-flex items-center gap-1"><span class="material-symbols-rounded text-[14px]">chair</span> {{ $table->capacity }} pax</span>
                                    <span>&bull;</span>
                                    <span class="inline-flex items-center gap-1"><span class="material-symbols-rounded text-[14px]">map</span> {{ $table->area }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col gap-2">
                                    <div>
                                        <x-badge :type="$table->is_active ? 'success' : 'secondary'">
                                            {{ $table->is_active ? 'Aktif' : 'Nonaktif' }}
                                        </x-badge>
                                    </div>
                                    <div>
                                        @php
                                            $opLabels = [
                                                'available' => 'Kosong',
                                                'occupied' => 'Terisi',
                                                'cleaning' => 'Pembersihan',
                                                'reserved' => 'Dipesan',
                                            ];
                                        @endphp
                                        <x-badge :type="match($table->operational_status) {
                                            'available' => 'success', 'occupied' => 'danger', 'cleaning' => 'warning', 'reserved' => 'info',
                                        }">{{ $opLabels[$table->operational_status] }}</x-badge>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="bg-white p-2 rounded-xl border border-[#E9E9E7] inline-block shadow-sm">
                                    {!! Cache::remember('pos:qr:table:'.$table->id, 86400, fn() => (string) QrCode::size(80)->generate(rtrim(config('app.url'), '/') . route('customer.menu.index', ['token' => $table->secure_token], false))) !!}
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="{{ route('tables.edit', $table->secure_token) }}" class="inline-flex items-center justify-center min-w-11 min-h-11 rounded-lg text-primary-400 hover:text-primary-700 hover:bg-primary-100 transition-colors duration-200 active:scale-90" aria-label="Ubah meja" wire:navigate>
                                    <span class="material-symbols-rounded text-[20px]">edit</span>
                                </a>
                                <form id="delete-form-{{ $table->id }}" action="{{ route('tables.destroy', $table->secure_token) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-form-{{ $table->id }}', '{{ $table->table_name }}')" class="inline-flex items-center justify-center min-w-11 min-h-11 rounded-lg text-primary-400 hover:text-danger-600 hover:bg-danger-50 transition-colors duration-200 active:scale-90" aria-label="Hapus">
                                        <span class="material-symbols-rounded text-[20px]">delete</span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-14 h-14 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                                        <span class="material-symbols-rounded text-[28px] text-[#C4C3C0]">table_restaurant</span>
                                    </div>
                                    <p class="font-medium">Belum ada meja terdaftar.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile/tablet-portrait: card list --}}
        <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
            @forelse($tables as $table)
                <div class="card-surface p-4">
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex flex-col">
                            <span class="font-bold text-[#37352F] text-sm">{{ $table->table_name }}</span>
                            <span class="text-xs text-[#787774]">{{ $table->table_code }}</span>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <x-badge :type="$table->is_active ? 'success' : 'secondary'">
                                {{ $table->is_active ? 'Aktif' : 'Nonaktif' }}
                            </x-badge>
                            @php
                                $opLabels = [
                                    'available' => 'Kosong',
                                    'occupied' => 'Terisi',
                                    'cleaning' => 'Pembersihan',
                                    'reserved' => 'Dipesan',
                                ];
                            @endphp
                            <x-badge :type="match($table->operational_status) {
                                'available' => 'success', 'occupied' => 'danger', 'cleaning' => 'warning', 'reserved' => 'info',
                            }">{{ $opLabels[$table->operational_status] }}</x-badge>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-4 pt-3 border-t border-[#E9E9E7]">
                        <div class="bg-white p-2 rounded-xl border border-[#E9E9E7] shadow-sm shrink-0">
                            {!! Cache::remember('pos:qr:table:'.$table->id, 86400, fn() => (string) QrCode::size(60)->generate(rtrim(config('app.url'), '/') . route('customer.menu.index', ['token' => $table->secure_token], false))) !!}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-[#37352F] flex items-center gap-1.5 mb-1"><span class="material-symbols-rounded text-[16px] text-[#787774]">chair</span> {{ $table->capacity }} pax</div>
                            <div class="text-sm font-medium text-[#37352F] flex items-center gap-1.5"><span class="material-symbols-rounded text-[16px] text-[#787774]">map</span> {{ $table->area }}</div>
                        </div>
                        <div class="flex flex-col gap-1 shrink-0">
                            <a href="{{ route('tables.edit', $table->secure_token) }}" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-primary-400 hover:text-primary-700 hover:bg-primary-100 transition-colors duration-200" aria-label="Ubah meja" wire:navigate>
                                <span class="material-symbols-rounded text-[18px]">edit</span>
                            </a>
                            <form id="delete-form-mobile-{{ $table->id }}" action="{{ route('tables.destroy', $table->secure_token) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="button" onclick="confirmDelete('delete-form-mobile-{{ $table->id }}', '{{ $table->table_name }}')" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-primary-400 hover:text-danger-600 hover:bg-danger-50 transition-colors duration-200" aria-label="Hapus">
                                    <span class="material-symbols-rounded text-[18px]">delete</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center text-[#9B9A97] text-sm flex flex-col items-center justify-center">
                    <div class="w-12 h-12 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                        <span class="material-symbols-rounded text-[24px] text-[#C4C3C0]">table_restaurant</span>
                    </div>
                    <p class="font-medium">Belum ada meja terdaftar.</p>
                </div>
            @endforelse
        </div>
        <div class="p-4 border-t border-[#E9E9E7] shrink-0">{{ $tables->links() }}</div>
    </div>
</x-app-layout>
