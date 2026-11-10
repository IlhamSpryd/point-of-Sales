<x-app-layout>
    <div x-data="{ createStoreDrawer: {{ $errors->any() ? 'true' : 'false' }} }" class="space-y-6" data-gsap="page-enter">
        <x-erp.module-sub-nav module="organization" />

        <!-- Header -->
        <x-erp.page-header
            title="Store & Branch Management"
            description="Manage stores, operating timezones, and outlet operational statuses across your business.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Stores</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <x-ui.button variant="primary" type="button" @click="createStoreDrawer = true" class="gap-2">
                    <span class="material-symbols-rounded text-base">add_business</span>
                    Add Store
                </x-ui.button>
            </x-slot:actions>
        </x-erp.page-header>

        @if(session('success'))
            <x-ui.alert type="success" title="Success" class="rounded-2xl">
                {{ session('success') }}
            </x-ui.alert>
        @endif
        @if(session('error'))
            <x-ui.alert type="danger" title="Error Encountered" class="rounded-2xl">
                {{ session('error') }}
            </x-ui.alert>
        @endif

        <!-- Filter & Search Toolbar -->
        <x-erp.filter-bar search-action="{{ route('stores.index') }}" search-placeholder="Search stores..." />

        <!-- Table -->
        <x-erp.data-table
            :is-empty="$stores->isEmpty()"
            empty-title="No stores registered yet."
            empty-description="Register stores and branches to manage sales and inventory across outlets."
            empty-icon="store"
            :colspan="6"
        >
            <x-slot:head>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Code</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Store Info</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Timezone</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Statistics</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Status</th>
                <th scope="col" class="px-6 py-3.5 text-right text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Actions</th>
            </x-slot:head>

            @foreach($stores as $store)
                <tr class="hover:bg-muted/40 transition-colors">
                    <td class="px-6 py-4 font-sans font-medium text-foreground text-sm whitespace-nowrap">
                        {{ $store->code }}
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-foreground text-sm">{{ $store->name }}</span>
                            @if($store->is_initial)
                                <span class="text-xs text-muted-foreground font-medium">(Primary Branch)</span>
                            @endif
                        </div>
                        <div class="text-xs text-muted-foreground mt-1 flex flex-col gap-0.5">
                            @if($store->address)
                                <span class="inline-flex items-center gap-1">
                                    <span class="material-symbols-rounded text-[14px]">location_on</span> {{ $store->address }}
                                </span>
                            @endif
                            @if($store->phone)
                                <span class="inline-flex items-center gap-1 font-sans">
                                    <span class="material-symbols-rounded text-[14px]">call</span> {{ $store->phone }}
                                </span>
                            @endif
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-foreground whitespace-nowrap">
                        <span class="inline-flex items-center gap-1 font-medium text-xs text-muted-foreground">
                            <span class="material-symbols-rounded text-[16px]">schedule</span>
                            {{ $store->timezone }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm whitespace-nowrap">
                        <div class="flex items-center gap-3 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-1" title="Table Count">
                                <span class="material-symbols-rounded text-[16px]">table_restaurant</span> {{ $store->tables_count }} tables
                            </span>
                            <span>&bull;</span>
                            <span class="inline-flex items-center gap-1" title="Device Count">
                                <span class="material-symbols-rounded text-[16px]">devices</span> {{ $store->devices_count }} devices
                            </span>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                            <span class="w-1.5 h-1.5 rounded-full {{ $store->is_active ? 'bg-success' : 'bg-neutral' }}"></span>
                            {{ $store->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-right whitespace-nowrap">
                        <x-ui.table-actions>
                            <x-ui.table-action-item icon="edit" href="{{ route('stores.edit', $store) }}">
                                Edit Store
                            </x-ui.table-action-item>
                            @if(! $store->is_initial)
                                <x-ui.table-action-item icon="delete" variant="destructive" onclick="if(confirm('Are you sure you want to delete store {{ addslashes($store->name) }}? Stores can only be deleted if there are no active shifts or connected devices.')) document.getElementById('delete-store-{{ $store->id }}').submit()">
                                    Delete Store
                                </x-ui.table-action-item>
                            @endif
                        </x-ui.table-actions>
                        @if(! $store->is_initial)
                            <form id="delete-store-{{ $store->id }}" action="{{ route('stores.destroy', $store) }}" method="POST" class="hidden">
                                @csrf
                                @method('DELETE')
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach

            @if($stores->hasPages())
                <x-slot:pagination>
                    {{ $stores->links() }}
                </x-slot:pagination>
            @endif
        </x-erp.data-table>

        {{-- Contextual Drawer for Create Store --}}
        <x-ui.drawer x-model="createStoreDrawer" maxWidth="lg">
            <x-slot:header>
                <span>Add New Store</span>
            </x-slot:header>

            <form action="{{ route('stores.store') }}" method="POST" id="createStoreDrawerForm" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Store / Branch Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}" required placeholder="e.g., Apeiron Coffee Downtown" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Store Code (Optional)</label>
                    <input type="text" name="code" value="{{ old('code') }}" placeholder="Auto-generated if left blank (STR-001)" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Operational Timezone <span class="text-rose-500">*</span></label>
                    <select name="timezone" required class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                        <option value="Asia/Jakarta" selected>WIB — Western Indonesia Time (Asia/Jakarta)</option>
                        <option value="Asia/Makassar">WITA — Central Indonesia Time (Asia/Makassar)</option>
                        <option value="Asia/Jayapura">WIT — Eastern Indonesia Time (Asia/Jayapura)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Contact Phone Number</label>
                    <input type="tel" name="phone" value="{{ old('phone') }}" placeholder="08123456789" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1">Physical Store Address</label>
                    <textarea name="address" rows="3" placeholder="e.g., 45 Downtown Boulevard, Suite 100..." class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-primary-500/20 focus:outline-none">{{ old('address') }}</textarea>
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" @click="createStoreDrawer = false">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" type="submit" form="createStoreDrawerForm">
                    Save Store
                </x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    </div>
</x-app-layout>
