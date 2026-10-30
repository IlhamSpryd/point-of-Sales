<x-app-layout>
    <div x-data="{ createStoreDrawer: {{ $errors->any() ? 'true' : 'false' }} }" class="space-y-6" data-gsap="page-enter">
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
        <div class="card-surface p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
            <form method="GET" action="{{ route('stores.index') }}" class="relative w-full sm:w-80">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search stores..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
            </form>
        </div>

        <!-- Table -->
        <div class="card-surface overflow-hidden rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
            <div class="overflow-x-auto">
                <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="px-6 py-4">Code</th>
                            <th class="px-6 py-4">Store Info</th>
                            <th class="px-6 py-4">Timezone</th>
                            <th class="px-6 py-4">Statistics</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                        @forelse($stores as $store)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                                <td class="px-6 py-4 font-mono font-medium text-apeiron-ink dark:text-white text-sm whitespace-nowrap">
                                    {{ $store->code }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-zinc-900 dark:text-white text-sm">{{ $store->name }}</span>
                                        @if($store->is_initial)
                                            <x-ui.badge variant="info" size="xs">Primary Branch</x-ui.badge>
                                        @endif
                                    </div>
                                    <div class="text-xs text-zinc-400 mt-1 flex flex-col gap-0.5">
                                        @if($store->address)
                                            <span class="inline-flex items-center gap-1">
                                                <span class="material-symbols-rounded text-[14px]">location_on</span> {{ $store->address }}
                                            </span>
                                        @endif
                                        @if($store->phone)
                                            <span class="inline-flex items-center gap-1 font-mono">
                                                <span class="material-symbols-rounded text-[14px]">call</span> {{ $store->phone }}
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-zinc-700 dark:text-zinc-300 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 font-medium text-xs">
                                        <span class="material-symbols-rounded text-[16px] text-zinc-400">schedule</span>
                                        {{ $store->timezone }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm whitespace-nowrap">
                                    <div class="flex items-center gap-3 text-xs text-zinc-500">
                                        <span class="inline-flex items-center gap-1" title="Table Count">
                                            <span class="material-symbols-rounded text-[16px]">table_restaurant</span> {{ $store->tables_count }} tables
                                        </span>
                                        <span>&bull;</span>
                                        <span class="inline-flex items-center gap-1" title="Device Count">
                                            <span class="material-symbols-rounded text-[16px]">devices</span> {{ $store->devices_count }} devices
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center whitespace-nowrap">
                                    <x-ui.badge variant="{{ $store->is_active ? 'success' : 'neutral' }}" size="sm">
                                        {{ $store->is_active ? 'Active' : 'Inactive' }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4 text-right space-x-1 whitespace-nowrap">
                                    <a href="{{ route('stores.edit', $store) }}" class="inline-flex items-center justify-center p-1.5 rounded-lg text-zinc-500 hover:text-blue-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition" aria-label="Edit store" wire:navigate>
                                        <span class="material-symbols-rounded text-lg">edit</span>
                                    </a>

                                    @if(! $store->is_initial)
                                        <form action="{{ route('stores.destroy', $store) }}" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete store {{ $store->name }}? Stores can only be deleted if there are no active shifts or connected devices.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex items-center justify-center p-1.5 rounded-lg text-zinc-500 hover:text-rose-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition" aria-label="Delete store">
                                                <span class="material-symbols-rounded text-lg">delete</span>
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-zinc-400 text-sm">
                                    <span class="material-symbols-rounded text-4xl block mb-2">store</span>
                                    No stores registered yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($stores->hasPages())
                <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                    {{ $stores->links() }}
                </div>
            @endif
        </div>

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
