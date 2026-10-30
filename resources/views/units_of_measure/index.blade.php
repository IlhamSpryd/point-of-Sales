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
    }" class="space-y-6" data-gsap="page-enter">

        <!-- Header -->
        <x-erp.page-header
            title="Units of Measure"
            description="Manage product quantity units (Pcs, Kg, Liter, Box, etc.) and decimal precision for inventory tracking.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('products.index') }}">Catalog</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Units of Measure</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                @if($canManage)
                    <x-ui.button variant="primary" type="button" @click="openCreate()" class="gap-2">
                        <span class="material-symbols-rounded text-base">add</span>
                        Add Unit of Measure
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-erp.page-header>

        @if(session('success'))
            <x-ui.alert type="success" title="Success" class="rounded-2xl">
                {{ session('success') }}
            </x-ui.alert>
        @endif
        @if(session('error'))
            <x-ui.alert type="danger" title="Error" class="rounded-2xl">
                {{ session('error') }}
            </x-ui.alert>
        @endif

        <!-- Filter & Search Toolbar -->
        <div class="card-surface p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
            <form method="GET" action="{{ route('units-of-measure.index') }}" class="relative w-full sm:w-80">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search units of measure..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
            </form>
        </div>

        <!-- Table -->
        <div class="card-surface overflow-hidden rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
            <div class="overflow-x-auto">
                <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="px-6 py-4">Code</th>
                            <th class="px-6 py-4">Unit Name</th>
                            <th class="px-6 py-4 text-right">Decimal Precision</th>
                            <th class="px-6 py-4 text-right">Products</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            @if($canManage)
                                <th class="px-6 py-4 text-right">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                        @forelse($unitsOfMeasure as $uom)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                                <td class="px-6 py-4 font-mono text-xs font-semibold text-apeiron-ink dark:text-white">{{ $uom->code }}</td>
                                <td class="px-6 py-4 text-sm font-semibold text-zinc-900 dark:text-white">{{ $uom->name }}</td>
                                <td class="px-6 py-4 text-sm text-zinc-500 text-right font-mono tabular-nums">{{ $uom->precision_scale }} decimals</td>
                                <td class="px-6 py-4 text-sm font-medium text-right text-zinc-900 dark:text-white font-mono tabular-nums">{{ $uom->products_count }}</td>
                                <td class="px-6 py-4 text-center">
                                    <x-ui.badge variant="{{ $uom->is_active ? 'success' : 'neutral' }}" size="sm">
                                        {{ $uom->is_active ? 'Active' : 'Inactive' }}
                                    </x-ui.badge>
                                </td>
                                @if($canManage)
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button type="button" @click="openEdit({{ json_encode($uom) }})"
                                                class="p-1.5 rounded-lg text-zinc-500 hover:text-blue-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                                                title="Edit unit">
                                                <span class="material-symbols-rounded text-lg">edit</span>
                                            </button>
                                            <form id="delete-uom-{{ $uom->id }}" action="{{ route('units-of-measure.destroy', $uom->id) }}" method="POST" class="inline-block">
                                                @csrf
                                                @method('DELETE')
                                                <button type="button" onclick="confirmDelete('delete-uom-{{ $uom->id }}', '{{ addslashes($uom->name) }}')"
                                                    class="p-1.5 rounded-lg text-zinc-500 hover:text-rose-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                                                    title="Delete unit">
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
                                    <span class="material-symbols-rounded text-4xl block mb-2">straighten</span>
                                    No units of measure registered.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($unitsOfMeasure->hasPages())
                <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                    {{ $unitsOfMeasure->links() }}
                </div>
            @endif
        </div>

        <!-- Modal Tambah / Sunting Satuan Ukur -->
        <x-ui.modal x-model="showModal" maxWidth="md">
            <x-slot:title>
                <span x-text="isEdit ? 'Edit Unit of Measure' : 'Add New Unit of Measure'"></span>
            </x-slot:title>

            <x-slot:content>
                <form id="uomForm" :action="formAction" method="POST" class="space-y-4">
                    @csrf
                    <template x-if="isEdit">
                        <input type="hidden" name="_method" value="PUT">
                    </template>

                    <div>
                        <x-ui.label for="uom_code">Unit Code *</x-ui.label>
                        <input type="text" id="uom_code" name="code" x-model="code" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 font-mono uppercase focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                    </div>

                    <div>
                        <x-ui.label for="uom_name">Unit Name *</x-ui.label>
                        <input type="text" id="uom_name" name="name" x-model="name" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                    </div>

                    <div>
                        <x-ui.label for="precision_scale">Decimal Precision (Decimal Places)</x-ui.label>
                        <input type="number" id="precision_scale" name="precision_scale" x-model="precisionScale" min="0" max="4" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 font-mono focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                        <p class="text-[11px] text-zinc-400 mt-1">e.g. 0 for Pcs, 2 or 3 for Kg / Gram.</p>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="uom_is_active" name="is_active" value="1" x-model="isActive"
                            class="rounded border-zinc-300 text-apeiron-primary shadow-xs focus:ring-apeiron-primary">
                        <label for="uom_is_active" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Active Status</label>
                    </div>
                </form>
            </x-slot:content>

            <x-slot:footer>
                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" type="button" @click="showModal = false">
                        Cancel
                    </x-ui.button>
                    <x-ui.button variant="primary" type="button" @click="document.getElementById('uomForm').submit()">
                        Save
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
