<x-app-layout>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Manajemen Meja</h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Kelola meja & QR Code self-order</p>
        </div>
        <a href="{{ route('tables.create') }}">
            <x-button variant="primary" type="button">
                <span class="material-symbols-rounded">add</span> Tambah Meja
            </x-button>
        </a>
    </div>

    @if(session('success'))
        <x-alert type="success">{{ session('success') }}</x-alert>
    @endif
    @if(session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-zinc-50/50 dark:bg-zinc-800/50">
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase">Nomor Meja</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase">Status</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase">QR Code</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($tables as $table)
                        <tr>
                            <td class="px-6 py-4 font-medium text-sm">{{ $table->table_number }}</td>
                            <td class="px-6 py-4">
                                <x-badge :type="$table->status === 'active' ? 'success' : 'secondary'">
                                    {{ $table->status === 'active' ? 'Aktif' : 'Nonaktif' }}
                                </x-badge>
                            </td>
                            <td class="px-6 py-4">
                                {{--
                                    Generate QR langsung di view: encode URL menu pelanggan
                                    yang membawa secure_token milik meja ini. Kita paksa menggunakan
                                    config('app.url') agar QR Code selalu mengarah ke Ngrok meskipun 
                                    admin membuka dashboard dari localhost.
                                --}}
                                {!! QrCode::size(100)->generate(rtrim(config('app.url'), '/') . route('self-order.menu', ['table' => $table->secure_token], false)) !!}
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="{{ route('tables.edit', $table->id) }}" class="p-1.5 text-zinc-400 hover:text-blue-600 inline-block"><span class="material-symbols-rounded">edit</span></a>
                                <form id="delete-form-{{ $table->id }}" action="{{ route('tables.destroy', $table->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-form-{{ $table->id }}', '{{ $table->table_number }}')" class="p-1.5 text-zinc-400 hover:text-rose-600"><span class="material-symbols-rounded">delete</span></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-zinc-500 text-sm">Belum ada meja terdaftar.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">{{ $tables->links() }}</div>
    </div>
</x-app-layout>
