@php
    $canManage = in_array(auth()->user()?->role?->name, ['Owner', 'Manager', 'Inventory'], true);
@endphp

<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div x-data="{
        showModal: false,
        isEdit: false,
        formAction: '{{ route('brands.store') }}',
        brandId: null,
        code: '',
        name: '',
        description: '',
        isActive: true,
        openCreate() {
            this.isEdit = false;
            this.formAction = '{{ route('brands.store') }}';
            this.brandId = null;
            this.code = '';
            this.name = '';
            this.description = '';
            this.isActive = true;
            this.showModal = true;
        },
        openEdit(brand) {
            this.isEdit = true;
            this.formAction = '/brands/' + brand.id;
            this.brandId = brand.id;
            this.code = brand.code;
            this.name = brand.name;
            this.description = brand.description || '';
            this.isActive = Boolean(brand.is_active);
            this.showModal = true;
        }
    }" class="space-y-6" data-gsap="page-enter">

        <!-- Header -->
        <x-erp.page-header
            title="Merek Produk (Brands)"
            description="Kelola merek atau produsen komoditas produk Anda untuk kategorisasi dan pelaporan.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('products.index') }}">Katalog</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Merek</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                @if($canManage)
                    <x-ui.button variant="primary" type="button" @click="openCreate()" class="gap-2">
                        <span class="material-symbols-rounded text-base">add</span>
                        Tambah Merek
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-erp.page-header>

        @if(session('success'))
            <x-ui.alert type="success" title="Berhasil" class="rounded-2xl">
                {{ session('success') }}
            </x-ui.alert>
        @endif
        @if(session('error'))
            <x-ui.alert type="danger" title="Terjadi Kesalahan" class="rounded-2xl">
                {{ session('error') }}
            </x-ui.alert>
        @endif

        <!-- Filter & Search Toolbar -->
        <div class="card-surface p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 border border-zinc-200/80 dark:border-zinc-800">
            <form method="GET" action="{{ route('brands.index') }}" class="relative w-full sm:w-80">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari merek..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
            </form>
        </div>

        <!-- Table -->
        <div class="card-surface overflow-hidden rounded-2xl border border-zinc-200/80 dark:border-zinc-800 shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200/80 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="px-6 py-4">Kode</th>
                            <th class="px-6 py-4">Nama Merek</th>
                            <th class="px-6 py-4">Deskripsi</th>
                            <th class="px-6 py-4 text-right">Jumlah Produk</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            @if($canManage)
                                <th class="px-6 py-4 text-right">Aksi</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800/70">
                        @forelse($brands as $brand)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                                <td class="px-6 py-4 font-mono text-xs font-semibold text-apeiron-ink dark:text-white">{{ $brand->code }}</td>
                                <td class="px-6 py-4 text-sm font-semibold text-zinc-900 dark:text-white">{{ $brand->name }}</td>
                                <td class="px-6 py-4 text-sm text-zinc-500">{{ $brand->description ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm font-medium text-right text-zinc-900 dark:text-white font-mono">{{ $brand->products_count }}</td>
                                <td class="px-6 py-4 text-center">
                                    <x-ui.badge variant="{{ $brand->is_active ? 'success' : 'neutral' }}" size="sm">
                                        {{ $brand->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </x-ui.badge>
                                </td>
                                @if($canManage)
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button type="button" @click="openEdit({{ json_encode($brand) }})"
                                                class="p-1.5 rounded-lg text-zinc-500 hover:text-blue-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                                                title="Ubah merek">
                                                <span class="material-symbols-rounded text-lg">edit</span>
                                            </button>
                                            <form id="delete-brand-{{ $brand->id }}" action="{{ route('brands.destroy', $brand->id) }}" method="POST" class="inline-block">
                                                @csrf
                                                @method('DELETE')
                                                <button type="button" onclick="confirmDelete('delete-brand-{{ $brand->id }}', '{{ addslashes($brand->name) }}')"
                                                    class="p-1.5 rounded-lg text-zinc-500 hover:text-rose-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                                                    title="Hapus merek">
                                                    <span class="material-symbols-rounded text-lg">delete</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canManage ? 6 : 5 }}" class="px-6 py-12 text-center text-zinc-400">
                                    <span class="material-symbols-rounded text-4xl block mb-2">verified</span>
                                    Belum ada merek produk terdaftar.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($brands->hasPages())
                <div class="p-4 border-t border-zinc-200/80 dark:border-zinc-800">
                    {{ $brands->links() }}
                </div>
            @endif
        </div>

        <!-- Modal Tambah / Sunting Merek -->
        <x-ui.modal x-model="showModal" maxWidth="md">
            <x-slot:title>
                <span x-text="isEdit ? 'Sunting Merek' : 'Tambah Merek Baru'"></span>
            </x-slot:title>

            <x-slot:content>
                <form id="brandForm" :action="formAction" method="POST" class="space-y-4">
                    @csrf
                    <template x-if="isEdit">
                        <input type="hidden" name="_method" value="PUT">
                    </template>

                    <div>
                        <x-ui.label for="brand_code">Kode Merek *</x-ui.label>
                        <input type="text" id="brand_code" name="code" x-model="code" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 font-mono uppercase focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                    </div>

                    <div>
                        <x-ui.label for="brand_name">Nama Merek *</x-ui.label>
                        <input type="text" id="brand_name" name="name" x-model="name" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                    </div>

                    <div>
                        <x-ui.label for="brand_desc">Deskripsi</x-ui.label>
                        <textarea id="brand_desc" name="description" x-model="description" rows="3"
                            class="w-full p-2.5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-sm focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20"></textarea>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="brand_is_active" name="is_active" value="1" x-model="isActive"
                            class="rounded border-zinc-300 text-apeiron-primary shadow-xs focus:ring-apeiron-primary">
                        <label for="brand_is_active" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Status Aktif</label>
                    </div>
                </form>
            </x-slot:content>

            <x-slot:footer>
                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" type="button" @click="showModal = false">
                        Batal
                    </x-ui.button>
                    <x-ui.button variant="primary" type="button" @click="document.getElementById('brandForm').submit()">
                        Simpan
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
