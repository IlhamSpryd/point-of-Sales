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
            title="Tax Classes"
            description="Manage tax rate classifications for product commodities (VAT 11%, Tax-Exempt, etc.) across retail and B2B transactions.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('products.index') }}">Catalog</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Tax Classes</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                @if($canManage)
                    <x-ui.button variant="primary" type="button" @click="openCreate()" class="gap-2">
                        <span class="material-symbols-rounded text-base">add</span>
                        Add Tax Class
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
        <x-erp.filter-bar search-action="{{ route('tax-classes.index') }}" search-placeholder="Search tax classes..." />

        <!-- Table -->
        <x-erp.data-table
            :is-empty="$taxClasses->isEmpty()"
            empty-title="No tax classes registered."
            empty-description="Register tax rate classifications (VAT 11%, Tax-Exempt) for sales."
            empty-icon="percent"
            :colspan="$canManage ? 5 : 4"
        >
            <x-slot:head>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Code</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Tax Class Name</th>
                <th scope="col" class="px-6 py-3.5 text-right text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Rate (%)</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Status</th>
                @if($canManage)
                    <th scope="col" class="px-6 py-3.5 text-right text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Actions</th>
                @endif
            </x-slot:head>

            @foreach($taxClasses as $tax)
                <tr class="hover:bg-muted/40 transition-colors">
                    <td class="px-6 py-4 font-sans text-xs font-semibold text-foreground">{{ $tax->code }}</td>
                    <td class="px-6 py-4 text-sm font-semibold text-foreground">{{ $tax->name }}</td>
                    <td class="px-6 py-4 text-sm font-semibold text-right text-foreground font-sans tabular-nums">{{ number_format((float) $tax->rate, 2) }}%</td>
                    <td class="px-6 py-4">
                        <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                            <span class="w-1.5 h-1.5 rounded-full {{ $tax->is_active ? 'bg-success' : 'bg-neutral' }}"></span>
                            {{ $tax->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    @if($canManage)
                        <td class="px-6 py-4 text-right">
                            <x-ui.table-actions>
                                <x-ui.table-action-item icon="edit" @click="openEdit({{ json_encode($tax) }})">
                                    Edit
                                </x-ui.table-action-item>
                                <x-ui.table-action-item icon="delete" variant="destructive" onclick="confirmSubmit('delete-tax-{{ $tax->id }}', '{{ addslashes($tax->name) }}')">
                                    Delete
                                </x-ui.table-action-item>
                            </x-ui.table-actions>
                            <form id="delete-tax-{{ $tax->id }}" action="{{ route('tax-classes.destroy', $tax->id) }}" method="POST" class="hidden">
                                @csrf
                                @method('DELETE')
                            </form>
                        </td>
                    @endif
                </tr>
            @endforeach

            @if($taxClasses->hasPages())
                <x-slot:pagination>
                    {{ $taxClasses->links() }}
                </x-slot:pagination>
            @endif
        </x-erp.data-table>

        <!-- Add / Edit Tax Class Modal -->
        <x-ui.modal x-model="showModal" maxWidth="md">
            <x-slot:title>
                <span x-text="isEdit ? 'Edit Tax Class' : 'Add New Tax Class'"></span>
            </x-slot:title>

            <x-slot:content>
                <form id="taxForm" :action="formAction" method="POST" class="space-y-4">
                    @csrf
                    <template x-if="isEdit">
                        <input type="hidden" name="_method" value="PUT">
                    </template>

                    <div>
                        <x-ui.label for="tax_code">Tax Class Code *</x-ui.label>
                        <input type="text" id="tax_code" name="code" x-model="code" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 font-sans uppercase focus:outline-hidden focus:ring-2 focus:ring-ring/20">
                    </div>

                    <div>
                        <x-ui.label for="tax_name">Tax Class Name *</x-ui.label>
                        <input type="text" id="tax_name" name="name" x-model="name" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-ring/20">
                    </div>

                    <div>
                        <x-ui.label for="tax_rate">Tax Rate (%) *</x-ui.label>
                        <input type="number" id="tax_rate" name="rate" x-model="rate" step="0.0001" min="0" max="100" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 font-sans focus:outline-hidden focus:ring-2 focus:ring-ring/20">
                        <p class="text-[11px] text-zinc-400 mt-1">e.g. 11.0000 for 11% VAT, 0 for Tax-Exempt.</p>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="tax_is_active" name="is_active" value="1" x-model="isActive"
                            class="rounded border-zinc-300 text-primary shadow-xs focus:ring-ring">
                        <label for="tax_is_active" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Active Status</label>
                    </div>
                </form>
            </x-slot:content>

            <x-slot:footer>
                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" type="button" @click="showModal = false">
                        Cancel
                    </x-ui.button>
                    <x-ui.button variant="primary" type="button" @click="document.getElementById('taxForm').submit()">
                        Save
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
