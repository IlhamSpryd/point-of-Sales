@php
    $canManage = in_array(auth()->user()?->role?->name, ['Owner', 'Manager', 'Inventory'], true);
@endphp

<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div x-data="{
        showModal: false,
        isEdit: false,
        formAction: '{{ route('tax-classes.store') }}',
        taxId: null,
        code: '',
        name: '',
        rate: '0.0000',
        isActive: true,
        openCreate() {
            this.isEdit = false;
            this.formAction = '{{ route('tax-classes.store') }}';
            this.taxId = null;
            this.code = '';
            this.name = '';
            this.rate = '0.0000';
            this.isActive = true;
            this.showModal = true;
        },
        openEdit(tax) {
            this.isEdit = true;
            this.formAction = '/tax-classes/' + tax.id;
            this.taxId = tax.id;
            this.code = tax.code;
            this.name = tax.name;
            this.rate = tax.rate;
            this.isActive = Boolean(tax.is_active);
            this.showModal = true;
        }
    }">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Kelas Pajak Katalog (Tax Classes)</h4>
                <p class="text-sm font-medium text-[#787774] mt-1">Kelola klasifikasi tarif pajak untuk komoditas produk (PPN 11%, Bebas Pajak, dll.)</p>
            </div>

            <x-list-toolbar search-action="{{ route('tax-classes.index') }}" search-placeholder="Cari kelas pajak...">
                <x-slot:actions>
                    @if($canManage)
                        <x-button variant="primary" type="button" @click="openCreate()" class="w-full sm:w-auto h-10">
                            <span class="material-symbols-rounded text-[18px]">add</span> Tambah Kelas Pajak
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
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Nama Kelas Pajak</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Tarif (%)</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Status</th>
                    @if($canManage)
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                    @endif
                </x-slot:head>

                @forelse($taxClasses as $tax)
                    <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                        <td class="px-6 py-4 font-mono text-xs font-semibold text-apeiron-ink">{{ $tax->code }}</td>
                        <td class="px-6 py-4 text-sm font-semibold text-apeiron-ink">{{ $tax->name }}</td>
                        <td class="px-6 py-4 text-sm font-semibold text-right text-apeiron-ink tabular-nums">{{ number_format((float) $tax->rate, 2) }}%</td>
                        <td class="px-6 py-4">
                            @if($tax->is_active)
                                <x-badge type="success">Aktif</x-badge>
                            @else
                                <x-badge type="secondary">Nonaktif</x-badge>
                            @endif
                        </td>
                        @if($canManage)
                            <td class="px-6 py-4 text-right space-x-1">
                                <button type="button" @click="openEdit({{ json_encode($tax) }})"
                                    class="inline-flex items-center justify-center min-w-9 min-h-9 rounded-lg text-primary-500 hover:text-primary-700 hover:bg-primary-100 transition-colors"
                                    aria-label="Ubah kelas pajak">
                                    <span class="material-symbols-rounded text-[20px]">edit</span>
                                </button>
                                <form id="delete-tax-{{ $tax->id }}" action="{{ route('tax-classes.destroy', $tax->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-tax-{{ $tax->id }}', '{{ addslashes($tax->name) }}')"
                                        class="inline-flex items-center justify-center min-w-9 min-h-9 rounded-lg text-danger-500 hover:text-danger-700 hover:bg-danger-50 transition-colors"
                                        aria-label="Hapus kelas pajak">
                                        <span class="material-symbols-rounded text-[20px]">delete</span>
                                    </button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canManage ? 5 : 4 }}" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                            <div class="flex flex-col items-center justify-center">
                                <span class="material-symbols-rounded text-4xl text-[#C4C3C0] mb-2">percent</span>
                                <p class="font-medium">Belum ada kelas pajak terdaftar.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </x-data-table>

            <div class="p-4 border-t border-[#E9E9E7]">
                {{ $taxClasses->links() }}
            </div>
        </div>

        {{-- Modal Tambah / Sunting Kelas Pajak --}}
        <div x-show="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" x-cloak>
            <div @click.away="showModal = false" class="bg-white rounded-2xl border border-[#E9E9E7] shadow-xl max-w-md w-full p-6">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-[#E9E9E7]">
                    <h3 class="text-base font-bold text-apeiron-ink" x-text="isEdit ? 'Sunting Kelas Pajak' : 'Tambah Kelas Pajak'"></h3>
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
                        <x-form-label for="tax_code">Kode Pajak <span class="text-danger-600">*</span></x-form-label>
                        <input type="text" id="tax_code" name="code" x-model="code" required maxlength="50"
                            class="w-full h-10 px-3 rounded-xl border border-[#E9E9E7] text-sm uppercase focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                    </div>

                    <div>
                        <x-form-label for="tax_name">Nama Pajak <span class="text-danger-600">*</span></x-form-label>
                        <input type="text" id="tax_name" name="name" x-model="name" required maxlength="120"
                            class="w-full h-10 px-3 rounded-xl border border-[#E9E9E7] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                    </div>

                    <div>
                        <x-form-label for="tax_rate">Tarif Persentase (%) <span class="text-danger-600">*</span></x-form-label>
                        <input type="number" id="tax_rate" name="rate" x-model="rate" step="0.0001" min="0" max="100" required
                            class="w-full h-10 px-3 rounded-xl border border-[#E9E9E7] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="tax_is_active" name="is_active" value="1" x-model="isActive"
                            class="rounded border-gray-300 text-apeiron-ink focus:ring-apeiron-ink">
                        <label for="tax_is_active" class="text-sm font-medium text-apeiron-ink">Status Aktif</label>
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
