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
    }">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Merek Produk (Brand)</h4>
                <p class="text-sm font-medium text-[#787774] mt-1">Kelola merek atau produsen komoditas produk Anda</p>
            </div>

            <x-list-toolbar search-action="{{ route('brands.index') }}" search-placeholder="Cari merek...">
                <x-slot:actions>
                    @if($canManage)
                        <x-button variant="primary" type="button" @click="openCreate()" class="w-full sm:w-auto h-10">
                            <span class="material-symbols-rounded text-[18px]">add</span> Tambah Merek
                        </x-button>
                    @endif
                </x-slot:actions>
            </x-list-toolbar>
        </div>

        @if(session('success'))
            <x-alert type="success" class="mb-4">{{ session('success') }}</x-alert>
        @endif
        @if(session('error'))
            <x-alert type="error" class="mb-4">{{ session('error') }}</x-alert>
        @endif

        <div class="card-surface flex flex-col flex-1 min-h-0">
            <x-data-table>
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Kode</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Nama Merek</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Deskripsi</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Jumlah Produk</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Status</th>
                    @if($canManage)
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                    @endif
                </x-slot:head>

                @forelse($brands as $brand)
                    <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                        <td class="px-6 py-4 font-mono text-xs font-semibold text-apeiron-ink">{{ $brand->code }}</td>
                        <td class="px-6 py-4 text-sm font-semibold text-apeiron-ink">{{ $brand->name }}</td>
                        <td class="px-6 py-4 text-sm text-[#787774]">{{ $brand->description ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm font-medium text-right text-apeiron-ink tabular-nums">{{ $brand->products_count }}</td>
                        <td class="px-6 py-4">
                            @if($brand->is_active)
                                <x-badge type="success">Aktif</x-badge>
                            @else
                                <x-badge type="secondary">Nonaktif</x-badge>
                            @endif
                        </td>
                        @if($canManage)
                            <td class="px-6 py-4 text-right space-x-1">
                                <button type="button" @click="openEdit({{ json_encode($brand) }})"
                                    class="inline-flex items-center justify-center min-w-9 min-h-9 rounded-lg text-primary-500 hover:text-primary-700 hover:bg-primary-100 transition-colors"
                                    aria-label="Ubah merek">
                                    <span class="material-symbols-rounded text-[20px]">edit</span>
                                </button>
                                <form id="delete-brand-{{ $brand->id }}" action="{{ route('brands.destroy', $brand->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-brand-{{ $brand->id }}', '{{ addslashes($brand->name) }}')"
                                        class="inline-flex items-center justify-center min-w-9 min-h-9 rounded-lg text-danger-500 hover:text-danger-700 hover:bg-danger-50 transition-colors"
                                        aria-label="Hapus merek">
                                        <span class="material-symbols-rounded text-[20px]">delete</span>
                                    </button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canManage ? 6 : 5 }}" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                            <div class="flex flex-col items-center justify-center">
                                <span class="material-symbols-rounded text-4xl text-[#C4C3C0] mb-2">verified</span>
                                <p class="font-medium">Belum ada merek terdaftar.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </x-data-table>

            <div class="p-4 border-t border-[#E9E9E7]">
                {{ $brands->links() }}
            </div>
        </div>

        {{-- Modal Tambah / Sunting Merek --}}
        <div x-show="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" x-cloak>
            <div @click.away="showModal = false" class="bg-white rounded-2xl border border-[#E9E9E7] shadow-xl max-w-md w-full p-6">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-[#E9E9E7]">
                    <h3 class="text-base font-bold text-apeiron-ink" x-text="isEdit ? 'Sunting Merek' : 'Tambah Merek Baru'"></h3>
                    <button type="button" @click="showModal = false" class="text-[#9B9A97] hover:text-apeiron-ink">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <form :action="formAction" method="POST" class="space-y-4">
                    @csrf
                    <template x-if="isEdit">
                        <input type="hidden" name="_method" value="PUT">
                    </template>

                    <div>
                        <x-form-label for="brand_code">Kode Merek <span class="text-danger-600">*</span></x-form-label>
                        <input type="text" id="brand_code" name="code" x-model="code" required
                            class="w-full h-10 px-3 rounded-xl border border-[#E9E9E7] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                    </div>

                    <div>
                        <x-form-label for="brand_name">Nama Merek <span class="text-danger-600">*</span></x-form-label>
                        <input type="text" id="brand_name" name="name" x-model="name" required
                            class="w-full h-10 px-3 rounded-xl border border-[#E9E9E7] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                    </div>

                    <div>
                        <x-form-label for="brand_desc">Deskripsi</x-form-label>
                        <textarea id="brand_desc" name="description" x-model="description" rows="3"
                            class="w-full p-3 rounded-xl border border-[#E9E9E7] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink"></textarea>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="brand_is_active" name="is_active" value="1" x-model="isActive"
                            class="rounded border-gray-300 text-apeiron-ink focus:ring-apeiron-ink">
                        <label for="brand_is_active" class="text-sm font-medium text-apeiron-ink">Status Aktif</label>
                    </div>

                    <div class="flex justify-end gap-2 pt-4 border-t border-[#E9E9E7]">
                        <x-button variant="secondary" type="button" @click="showModal = false">Batal</x-button>
                        <x-button variant="primary" type="submit">Simpan</x-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
