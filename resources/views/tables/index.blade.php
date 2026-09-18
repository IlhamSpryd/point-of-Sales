<x-app-layout>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Manajemen Meja</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Kelola meja & QR Code self-order</p>
        </div>
        <a href="{{ route('tables.create') }}">
            <x-button variant="primary" type="button">
                <span class="material-symbols-rounded text-[18px]">add</span> Tambah Meja
            </x-button>
        </a>
    </div>

    @if(session('success'))
        <x-alert type="success">{{ session('success') }}</x-alert>
    @endif
    @if(session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    <div class="card-surface flex flex-col flex-1 min-h-0">
        <div class="overflow-auto flex-1">
            <table class="data-table relative">
                <thead class="sticky top-0 z-10 shadow-sm">
                    <tr class="bg-[#F7F7F5]">
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Nomor Meja</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">QR Code</th>
                        <th class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E9E9E7]">
                    @forelse($tables as $table)
                        <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                            <td class="px-6 py-4 font-medium text-[#37352F] text-sm">{{ $table->table_number }}</td>
                            <td class="px-6 py-4">
                                <x-badge :type="$table->status === 'active' ? 'success' : 'secondary'">
                                    {{ $table->status === 'active' ? 'Aktif' : 'Nonaktif' }}
                                </x-badge>
                            </td>
                            <td class="px-6 py-4">
                                <div class="bg-white p-2 rounded-xl border border-[#E9E9E7] inline-block shadow-sm">
                                    {!! QrCode::size(100)->generate(rtrim(config('app.url'), '/') . route('customer.menu.index', ['token' => $table->secure_token], false)) !!}
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="{{ route('tables.edit', $table->id) }}" class="p-1.5 text-[#9B9A97] hover:text-[#37352F] transition-colors duration-200 inline-block"><span class="material-symbols-rounded">edit</span></a>
                                <form id="delete-form-{{ $table->id }}" action="{{ route('tables.destroy', $table->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-form-{{ $table->id }}', '{{ $table->table_number }}')" class="p-1.5 text-[#9B9A97] hover:text-rose-600 transition-colors duration-200"><span class="material-symbols-rounded">delete</span></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
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
        <div class="p-4 border-t border-[#E9E9E7] shrink-0">{{ $tables->links() }}</div>
    </div>
</x-app-layout>
