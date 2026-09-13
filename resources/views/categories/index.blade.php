<x-app-layout>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Categories</h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Manage product categories</p>
        </div>
        
        <div class="flex flex-col lg:flex-row gap-3 items-center w-full sm:w-auto">
            <form action="{{ route('categories.index') }}" method="GET" class="w-full sm:w-72 relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400">search</span>
                <x-form-input type="search" name="search" value="{{ request('search') }}" placeholder="Search categories..." class="pl-10 h-10 w-full" />
            </form>
            
            <div class="flex gap-2 w-full sm:w-auto">
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="flex-1 sm:flex-none">
                    <x-button variant="secondary" type="button" class="w-full h-10">
                        <span class="material-symbols-rounded">download</span> Export
                    </x-button>
                </a>
                
                {{-- Tombol tambah/edit/hapus hanya untuk Administrator. Kasir & Pimpinan hanya boleh MELIHAT data (read-only), sesuai role:Administrator,Kasir,Pimpinan pada route ini — jika tombol tetap tampil, klik akan berujung error 403. --}}
                @if(auth()->user()->role?->name === 'Administrator')
                    <a href="{{ route('categories.create') }}" class="flex-1 sm:flex-none">
                        <x-button variant="primary" type="button" class="w-full h-10">
                            <span class="material-symbols-rounded">add</span> Add Category
                        </x-button>
                    </a>
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-6 p-4 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 text-emerald-700 dark:text-emerald-400 text-sm font-medium flex items-center justify-between" x-data="{ show: true }" x-show="show">
            <span><span class="material-symbols-rounded mr-2">check_circle</span> {{ session('success') }}</span>
            <button @click="show = false" class="text-emerald-500 hover:text-emerald-700"><span class="material-symbols-rounded">close</span></button>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6 p-4 rounded-lg bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/20 text-rose-700 dark:text-rose-400 text-sm font-medium flex items-center justify-between" x-data="{ show: true }" x-show="show">
            <span><span class="material-symbols-rounded mr-2">warning</span> {{ session('error') }}</span>
            <button @click="show = false" class="text-rose-500 hover:text-rose-700"><span class="material-symbols-rounded">close</span></button>
        </div>
    @endif

    <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-zinc-50/50 dark:bg-zinc-800/50">
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">ID</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Category Name</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Created At</th>
                        @if(auth()->user()->role?->name === 'Administrator')
                            <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider text-right">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($categories as $category)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50 transition-colors">
                            <td class="px-6 py-4 font-medium text-zinc-900 dark:text-white text-sm">#{{ $category->id }}</td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-zinc-900 dark:text-white text-sm">{{ $category->category_name }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm text-zinc-500">{{ $category->created_at?->format('M d, Y') ?? '—' }}</td>
                            @if(auth()->user()->role?->name === 'Administrator')
                                <td class="px-6 py-4 text-right space-x-2">
                                    <a href="{{ route('categories.edit', $category->id) }}" class="p-1.5 text-zinc-400 hover:text-blue-600 transition-colors inline-block"><span class="material-symbols-rounded">edit</span></a>
                                    <form id="delete-form-{{ $category->id }}" action="{{ route('categories.destroy', $category->id) }}" method="POST" class="inline-block">
                                        @csrf
                                        @method('DELETE')
                                        {{-- Menggunakan type="button" dan onclick untuk memanggil Swal.fire (tidak menggunakan confirm() bawaan) --}}
                                        <button type="button" onclick="confirmDelete('delete-form-{{ $category->id }}', '{{ addslashes($category->category_name) }}')" class="p-1.5 text-zinc-400 hover:text-rose-600 transition-colors"><span class="material-symbols-rounded">delete</span></button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ auth()->user()->role?->name === 'Administrator' ? 4 : 3 }}" class="px-6 py-8 text-center text-zinc-500 text-sm">
                                <div class="flex flex-col items-center justify-center">
                                    <span class="material-symbols-rounded text-4xl mb-3 text-zinc-400">category</span>
                                    <p>No categories found.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $categories->links() }}
        </div>
    </div>
    {{-- Script untuk menampilkan konfirmasi hapus bergaya SweetAlert2 (bukan confirm() bawaan browser)
         agar tampilan tetap konsisten dengan popup sukses transaksi POS. --}}
    <script>
    function confirmDelete(formId, itemName) {
        Swal.fire({
            title: 'Hapus data ini?',
            html: `Anda yakin ingin menghapus <b>${itemName}</b>? Tindakan ini tidak bisa dibatalkan.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#e11d48',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById(formId).submit();
            }
        });
    }
    </script>
</x-app-layout>
