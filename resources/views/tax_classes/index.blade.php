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
    }" class="space-y-6" data-gsap="page-enter">

        <!-- Header -->
        <x-erp.page-header
            title="Kelas Pajak Katalog (Tax Classes)"
            description="Kelola klasifikasi tarif pajak untuk komoditas produk (PPN 11%, Bebas Pajak, dll.) pada transaksi ritel maupun B2B.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('products.index') }}">Katalog</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Kelas Pajak</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                @if($canManage)
                    <x-ui.button variant="primary" type="button" @click="openCreate()" class="gap-2">
                        <span class="material-symbols-rounded text-base">add</span>
                        Tambah Kelas Pajak
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
        <div class="card-surface p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
            <form method="GET" action="{{ route('tax-classes.index') }}" class="relative w-full sm:w-80">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari kelas pajak..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
            </form>
        </div>

        <!-- Table -->
        <div class="card-surface overflow-hidden rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="px-6 py-4">Kode</th>
                            <th class="px-6 py-4">Nama Kelas Pajak</th>
                            <th class="px-6 py-4 text-right">Tarif (%)</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            @if($canManage)
                                <th class="px-6 py-4 text-right">Aksi</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                        @forelse($taxClasses as $tax)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                                <td class="px-6 py-4 font-mono text-xs font-semibold text-apeiron-ink dark:text-white">{{ $tax->code }}</td>
                                <td class="px-6 py-4 text-sm font-semibold text-zinc-900 dark:text-white">{{ $tax->name }}</td>
                                <td class="px-6 py-4 text-sm font-semibold text-right text-zinc-900 dark:text-white font-mono">{{ number_format((float) $tax->rate, 2) }}%</td>
                                <td class="px-6 py-4 text-center">
                                    <x-ui.badge variant="{{ $tax->is_active ? 'success' : 'neutral' }}" size="sm">
                                        {{ $tax->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </x-ui.badge>
                                </td>
                                @if($canManage)
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button type="button" @click="openEdit({{ json_encode($tax) }})"
                                                class="p-1.5 rounded-lg text-zinc-500 hover:text-blue-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                                                title="Ubah kelas pajak">
                                                <span class="material-symbols-rounded text-lg">edit</span>
                                            </button>
                                            <form id="delete-tax-{{ $tax->id }}" action="{{ route('tax-classes.destroy', $tax->id) }}" method="POST" class="inline-block">
                                                @csrf
                                                @method('DELETE')
                                                <button type="button" onclick="confirmDelete('delete-tax-{{ $tax->id }}', '{{ addslashes($tax->name) }}')"
                                                    class="p-1.5 rounded-lg text-zinc-500 hover:text-rose-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                                                    title="Hapus kelas pajak">
                                                    <span class="material-symbols-rounded text-lg">delete</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canManage ? 5 : 4 }}" class="px-6 py-12 text-center text-zinc-400">
                                    <span class="material-symbols-rounded text-4xl block mb-2">percent</span>
                                    Belum ada kelas pajak terdaftar.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($taxClasses->hasPages())
                <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                    {{ $taxClasses->links() }}
                </div>
            @endif
        </div>

        <!-- Modal Tambah / Sunting Kelas Pajak -->
        <x-ui.modal x-model="showModal" maxWidth="md">
            <x-slot:title>
                <span x-text="isEdit ? 'Sunting Kelas Pajak' : 'Tambah Kelas Pajak Baru'"></span>
            </x-slot:title>

            <x-slot:content>
                <form id="taxForm" :action="formAction" method="POST" class="space-y-4">
                    @csrf
                    <template x-if="isEdit">
                        <input type="hidden" name="_method" value="PUT">
                    </template>

                    <div>
                        <x-ui.label for="tax_code">Kode Kelas Pajak *</x-ui.label>
                        <input type="text" id="tax_code" name="code" x-model="code" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 font-mono uppercase focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                    </div>

                    <div>
                        <x-ui.label for="tax_name">Nama Kelas Pajak *</x-ui.label>
                        <input type="text" id="tax_name" name="name" x-model="name" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                    </div>

                    <div>
                        <x-ui.label for="tax_rate">Tarif Pajak (%) *</x-ui.label>
                        <input type="number" id="tax_rate" name="rate" x-model="rate" step="0.0001" min="0" max="100" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 font-mono focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                        <p class="text-[11px] text-zinc-400 mt-1">Contoh: 11.0000 untuk PPN 11%, 0 untuk Bebas Pajak.</p>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="tax_is_active" name="is_active" value="1" x-model="isActive"
                            class="rounded border-zinc-300 text-apeiron-primary shadow-xs focus:ring-apeiron-primary">
                        <label for="tax_is_active" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Status Aktif</label>
                    </div>
                </form>
            </x-slot:content>

            <x-slot:footer>
                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" type="button" @click="showModal = false">
                        Batal
                    </x-ui.button>
                    <x-ui.button variant="primary" type="button" @click="document.getElementById('taxForm').submit()">
                        Simpan
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
