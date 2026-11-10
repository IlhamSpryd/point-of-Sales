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
        <x-erp.filter-bar search-action="{{ route('units-of-measure.index') }}" search-placeholder="Search units of measure..." />

        <!-- Table -->
        <x-erp.data-table
            :is-empty="$unitsOfMeasure->isEmpty()"
            empty-title="No units of measure registered."
            empty-description="Register units of measure (Pcs, Kg, Liter, Box) for inventory tracking."
            empty-icon="straighten"
            :colspan="$canManage ? 6 : 5"
        >
            <x-slot:head>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Code</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Unit Name</th>
                <th scope="col" class="px-6 py-3.5 text-right text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Decimal Precision</th>
                <th scope="col" class="px-6 py-3.5 text-right text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Products</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Status</th>
                @if($canManage)
                    <th scope="col" class="px-6 py-3.5 text-right text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Actions</th>
                @endif
            </x-slot:head>

            @foreach($unitsOfMeasure as $uom)
                <tr class="hover:bg-muted/40 transition-colors">
                    <td class="px-6 py-4 font-sans text-xs font-semibold text-foreground">{{ $uom->code }}</td>
                    <td class="px-6 py-4 text-sm font-semibold text-foreground">{{ $uom->name }}</td>
                    <td class="px-6 py-4 text-sm text-muted-foreground text-right font-sans tabular-nums">{{ $uom->precision_scale }} decimals</td>
                    <td class="px-6 py-4 text-sm font-medium text-right text-foreground font-sans tabular-nums">{{ $uom->products_count }}</td>
                    <td class="px-6 py-4">
                        <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                            <span class="w-1.5 h-1.5 rounded-full {{ $uom->is_active ? 'bg-success' : 'bg-neutral' }}"></span>
                            {{ $uom->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    @if($canManage)
                        <td class="px-6 py-4 text-right">
                            <x-ui.table-actions>
                                <x-ui.table-action-item icon="edit" @click="openEdit({{ json_encode($uom) }})">
                                    Edit
                                </x-ui.table-action-item>
                                <x-ui.table-action-item icon="delete" variant="destructive" onclick="confirmSubmit('delete-uom-{{ $uom->id }}', '{{ addslashes($uom->name) }}')">
                                    Delete
                                </x-ui.table-action-item>
                            </x-ui.table-actions>
                            <form id="delete-uom-{{ $uom->id }}" action="{{ route('units-of-measure.destroy', $uom->id) }}" method="POST" class="hidden">
                                @csrf
                                @method('DELETE')
                            </form>
                        </td>
                    @endif
                </tr>
            @endforeach

            @if($unitsOfMeasure->hasPages())
                <x-slot:pagination>
                    {{ $unitsOfMeasure->links() }}
                </x-slot:pagination>
            @endif
        </x-erp.data-table>

        <!-- Add / Edit Unit of Measure Modal -->
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
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 font-sans uppercase focus:outline-hidden focus:ring-2 focus:ring-ring/20">
                    </div>

                    <div>
                        <x-ui.label for="uom_name">Unit Name *</x-ui.label>
                        <input type="text" id="uom_name" name="name" x-model="name" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-ring/20">
                    </div>

                    <div>
                        <x-ui.label for="precision_scale">Decimal Precision (Decimal Places)</x-ui.label>
                        <input type="number" id="precision_scale" name="precision_scale" x-model="precisionScale" min="0" max="4" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 font-sans focus:outline-hidden focus:ring-2 focus:ring-ring/20">
                        <p class="text-[11px] text-zinc-400 mt-1">e.g. 0 for Pcs, 2 or 3 for Kg / Gram.</p>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="uom_is_active" name="is_active" value="1" x-model="isActive"
                            class="rounded border-zinc-300 text-primary shadow-xs focus:ring-ring">
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
