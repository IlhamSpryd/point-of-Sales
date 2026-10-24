@php
    $canManage = in_array(auth()->user()?->role?->name, ['Owner', 'Manager', 'Inventory'], true);
@endphp

<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div x-data="{
        showModal: false,
        isEdit: false,
        formAction: '{{ route('units-of-measure.store') }}',
        uomId: null,
        code: '',
        name: '',
        precisionScale: 0,
        isActive: true,
        openCreate() {
            this.isEdit = false;
            this.formAction = '{{ route('units-of-measure.store') }}';
            this.uomId = null;
            this.code = '';
            this.name = '';
            this.precisionScale = 0;
            this.isActive = true;
            this.showModal = true;
        },
        openEdit(uom) {
            this.isEdit = true;
            this.formAction = '/units-of-measure/' + uom.id;
            this.uomId = uom.id;
            this.code = uom.code;
            this.name = uom.name;
            this.precisionScale = uom.precision_scale;
            this.isActive = Boolean(uom.is_active);
            this.showModal = true;
        }
    }">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Satuan Ukur (Unit of Measure)</h4>
                <p class="text-sm font-medium text-[#787774] mt-1">Kelola unit kuantitas produk (Pcs, Kg, Liter, Box, dll.)</p>
            </div>

            <x-list-toolbar search-action="{{ route('units-of-measure.index') }}" search-placeholder="Cari satuan ukur...">
                <x-slot:actions>
                    @if($canManage)
                        <x-button variant="primary" type="button" @click="openCreate()" class="w-full sm:w-auto h-10">
                            <span class="material-symbols-rounded text-[18px]">add</span> Tambah Satuan Ukur
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
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Nama Satuan</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Presisi Desimal</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Jumlah Produk</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Status</th>
                    @if($canManage)
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                    @endif
                </x-slot:head>

                @forelse($unitsOfMeasure as $uom)
                    <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                        <td class="px-6 py-4 font-mono text-xs font-semibold text-apeiron-ink">{{ $uom->code }}</td>
                        <td class="px-6 py-4 text-sm font-semibold text-apeiron-ink">{{ $uom->name }}</td>
                        <td class="px-6 py-4 text-sm text-[#787774] text-right tabular-nums">{{ $uom->precision_scale }} desimal</td>
                        <td class="px-6 py-4 text-sm font-medium text-right text-apeiron-ink tabular-nums">{{ $uom->products_count }}</td>
                        <td class="px-6 py-4">
                            @if($uom->is_active)
                                <x-badge type="success">Aktif</x-badge>
                            @else
                                <x-badge type="secondary">Nonaktif</x-badge>
                            @endif
                        </td>
                        @if($canManage)
                            <td class="px-6 py-4 text-right space-x-1">
                                <button type="button" @click="openEdit({{ json_encode($uom) }})"
                                    class="inline-flex items-center justify-center min-w-9 min-h-9 rounded-lg text-primary-500 hover:text-primary-700 hover:bg-primary-100 transition-colors"
                                    aria-label="Ubah satuan">
                                    <span class="material-symbols-rounded text-[20px]">edit</span>
                                </button>
                                <form id="delete-uom-{{ $uom->id }}" action="{{ route('units-of-measure.destroy', $uom->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-uom-{{ $uom->id }}', '{{ addslashes($uom->name) }}')"
                                        class="inline-flex items-center justify-center min-w-9 min-h-9 rounded-lg text-danger-500 hover:text-danger-700 hover:bg-danger-50 transition-colors"
                                        aria-label="Hapus satuan">
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
                                <span class="material-symbols-rounded text-4xl text-[#C4C3C0] mb-2">straighten</span>
                                <p class="font-medium">Belum ada satuan ukur terdaftar.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </x-data-table>

            <div class="p-4 border-t border-[#E9E9E7]">
                {{ $unitsOfMeasure->links() }}
            </div>
        </div>

        {{-- Modal Tambah / Sunting UoM --}}
        <div x-show="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" x-cloak>
            <div @click.away="showModal = false" class="bg-white rounded-2xl border border-[#E9E9E7] shadow-xl max-w-md w-full p-6">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-[#E9E9E7]">
                    <h3 class="text-base font-bold text-apeiron-ink" x-text="isEdit ? 'Sunting Satuan Ukur' : 'Tambah Satuan Ukur'"></h3>
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
                        <x-form-label for="uom_code">Kode Satuan (misal: PCS, KG, LTR) <span class="text-danger-600">*</span></x-form-label>
                        <input type="text" id="uom_code" name="code" x-model="code" required maxlength="32"
                            class="w-full h-10 px-3 rounded-xl border border-[#E9E9E7] text-sm uppercase focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                    </div>

                    <div>
                        <x-form-label for="uom_name">Nama Satuan <span class="text-danger-600">*</span></x-form-label>
                        <input type="text" id="uom_name" name="name" x-model="name" required maxlength="100"
                            class="w-full h-10 px-3 rounded-xl border border-[#E9E9E7] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                    </div>

                    <div>
                        <x-form-label for="precision_scale">Presisi Skala Desimal (0 s/d 4)</x-form-label>
                        <input type="number" id="precision_scale" name="precision_scale" x-model="precisionScale" min="0" max="4"
                            class="w-full h-10 px-3 rounded-xl border border-[#E9E9E7] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                        <p class="text-xs text-[#787774] mt-1">Gunakan 0 untuk bilangan bulat (misal Pcs), 2 atau 3 untuk timbangan (Kg, Gram).</p>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="uom_is_active" name="is_active" value="1" x-model="isActive"
                            class="rounded border-gray-300 text-apeiron-ink focus:ring-apeiron-ink">
                        <label for="uom_is_active" class="text-sm font-medium text-apeiron-ink">Status Aktif</label>
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
