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
            title="Product Brands"
            description="Manage product brands and manufacturers for catalog classification and reporting.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('products.index') }}">Catalog</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Brands</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                @if($canManage)
                    <x-ui.button variant="primary" type="button" @click="openCreate()" class="gap-2">
                        <span class="material-symbols-rounded text-base">add</span>
                        Add Brand
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
        <x-erp.filter-bar search-action="{{ route('brands.index') }}" search-placeholder="Search brands..." />

        <!-- Table -->
        <x-erp.data-table
            :is-empty="$brands->isEmpty()"
            empty-title="No product brands registered."
            empty-description="Register brands to classify products across your catalog."
            empty-icon="label"
            :colspan="$canManage ? 6 : 5"
        >
            <x-slot:head>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Code</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Brand Name</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Description</th>
                <th scope="col" class="px-6 py-3.5 text-right text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Products</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Status</th>
                @if($canManage)
                    <th scope="col" class="px-6 py-3.5 text-right text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Actions</th>
                @endif
            </x-slot:head>

            @foreach($brands as $brand)
                <tr class="hover:bg-muted/40 transition-colors">
                    <td class="px-6 py-4 font-sans text-xs font-semibold text-foreground">{{ $brand->code }}</td>
                    <td class="px-6 py-4 text-sm font-semibold text-foreground">{{ $brand->name }}</td>
                    <td class="px-6 py-4 text-sm text-muted-foreground">{{ $brand->description ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm font-medium text-right text-foreground font-sans tabular-nums">{{ $brand->products_count }}</td>
                    <td class="px-6 py-4">
                        <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                            <span class="w-1.5 h-1.5 rounded-full {{ $brand->is_active ? 'bg-success' : 'bg-neutral' }}"></span>
                            {{ $brand->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    @if($canManage)
                        <td class="px-6 py-4 text-right">
                            <x-ui.table-actions>
                                <x-ui.table-action-item icon="edit" @click="openEdit({{ json_encode($brand) }})">
                                    Edit
                                </x-ui.table-action-item>
                                <x-ui.table-action-item icon="delete" variant="destructive" onclick="confirmSubmit('delete-brand-{{ $brand->id }}', '{{ addslashes($brand->name) }}')">
                                    Delete
                                </x-ui.table-action-item>
                            </x-ui.table-actions>
                            <form id="delete-brand-{{ $brand->id }}" action="{{ route('brands.destroy', $brand->id) }}" method="POST" class="hidden">
                                @csrf
                                @method('DELETE')
                            </form>
                        </td>
                    @endif
                </tr>
            @endforeach

            @if($brands->hasPages())
                <x-slot:pagination>
                    {{ $brands->links() }}
                </x-slot:pagination>
            @endif
        </x-erp.data-table>

        <!-- Add / Edit Brand Modal -->
        <x-ui.modal x-model="showModal" maxWidth="md">
            <x-slot:title>
                <span x-text="isEdit ? 'Edit Brand' : 'Add New Brand'"></span>
            </x-slot:title>

            <x-slot:content>
                <form id="brandForm" :action="formAction" method="POST" class="space-y-4">
                    @csrf
                    <template x-if="isEdit">
                        <input type="hidden" name="_method" value="PUT">
                    </template>

                    <div>
                        <x-ui.label for="brand_code">Brand Code *</x-ui.label>
                        <input type="text" id="brand_code" name="code" x-model="code" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 font-sans uppercase focus:outline-hidden focus:ring-2 focus:ring-ring/20">
                    </div>

                    <div>
                        <x-ui.label for="brand_name">Brand Name *</x-ui.label>
                        <input type="text" id="brand_name" name="name" x-model="name" required
                            class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-ring/20">
                    </div>

                    <div>
                        <x-ui.label for="brand_desc">Description</x-ui.label>
                        <textarea id="brand_desc" name="description" x-model="description" rows="3"
                            class="w-full p-2.5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-sm focus:outline-hidden focus:ring-2 focus:ring-ring/20"></textarea>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="brand_is_active" name="is_active" value="1" x-model="isActive"
                            class="rounded border-zinc-300 text-primary shadow-xs focus:ring-ring">
                        <label for="brand_is_active" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Active Status</label>
                    </div>
                </form>
            </x-slot:content>

            <x-slot:footer>
                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" type="button" @click="showModal = false">
                        Cancel
                    </x-ui.button>
                    <x-ui.button variant="primary" type="button" @click="document.getElementById('brandForm').submit()">
                        Save
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
