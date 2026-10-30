@php
    $canManage = in_array(auth()->user()?->role?->name, ['Owner', 'Manager', 'Inventory'], true);
@endphp

<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div class="space-y-6" data-gsap="page-enter">
        <!-- Header -->
        <x-erp.page-header
            title="Branch Assortments & Price Overrides"
            description="Configure branch-level product availability and branch-specific pricing without duplicating master catalog records.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item href="{{ route('products.index') }}">Catalog</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Branch Assortments</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <form method="GET" action="{{ route('assortments.index') }}" class="flex items-center gap-2">
                    <span class="text-xs font-semibold text-zinc-500 whitespace-nowrap">Select Branch:</span>
                    <select id="store_filter" name="store_id" onchange="this.form.submit()"
                        class="h-10 px-3 rounded-xl border border-zinc-200 dark:border-zinc-700 text-sm bg-white dark:bg-zinc-800 font-medium text-zinc-800 dark:text-zinc-200 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                        @foreach ($stores as $s)
                            <option value="{{ $s->id }}" {{ (string)$selectedStoreId === (string)$s->id ? 'selected' : '' }}>
                                {{ $s->name }}
                            </option>
                        @endforeach
                    </select>
                </form>
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

        <div class="card-surface overflow-hidden rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
            <div class="overflow-x-auto">
                <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="px-6 py-4">Product</th>
                            <th class="px-6 py-4">Category</th>
                            <th class="px-6 py-4 text-right">Base Price</th>
                            <th class="px-6 py-4 text-center">Available in Branch</th>
                            <th class="px-6 py-4 text-right">Branch Price Override</th>
                            @if($canManage)
                                <th class="px-6 py-4 text-right">Save</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                        @forelse($products as $prod)
                            @php
                                $assortment = $prod->storeAssortments->first();
                                $isAvailable = $assortment ? $assortment->is_available : true;
                                $overridePrice = $assortment ? $assortment->store_price_override : null;
                            @endphp
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        @if($prod->product_photo)
                                            <img src="{{ asset('storage/' . $prod->product_photo) }}" alt="{{ $prod->product_name }}" class="w-10 h-10 rounded-xl object-cover border border-zinc-200 dark:border-zinc-700">
                                        @else
                                            <div class="w-10 h-10 rounded-xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-zinc-400">
                                                <span class="material-symbols-rounded">inventory_2</span>
                                            </div>
                                        @endif
                                        <div>
                                            <div class="font-semibold text-sm text-apeiron-ink dark:text-white">{{ $prod->product_name }}</div>
                                            <div class="font-mono text-xs text-zinc-400">{{ $prod->product_code }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-zinc-600 dark:text-zinc-400">{{ $prod->category?->category_name ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm font-medium text-right text-zinc-900 dark:text-white font-mono tabular-nums">
                                    Rp {{ number_format((float) $prod->product_price, 0, ',', '.') }}
                                </td>

                                @if($canManage && $selectedStoreId)
                                    <form id="form-assortment-{{ $prod->id }}" action="{{ route('assortments.update') }}" method="POST" class="hidden">
                                        @csrf
                                        <input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
                                        <input type="hidden" name="product_id" value="{{ $prod->id }}">
                                    </form>

                                    <!-- Switch Ketersediaan -->
                                    <td class="px-6 py-4 text-center">
                                        <input type="hidden" name="is_available" value="0" form="form-assortment-{{ $prod->id }}">
                                        <label class="inline-flex items-center justify-center cursor-pointer gap-2">
                                            <input type="checkbox" name="is_available" value="1" {{ $isAvailable ? 'checked' : '' }}
                                                form="form-assortment-{{ $prod->id }}"
                                                class="rounded border-zinc-300 text-apeiron-primary shadow-xs focus:ring-apeiron-primary h-4 w-4">
                                            <x-ui.badge variant="{{ $isAvailable ? 'success' : 'danger' }}" size="xs">
                                                {{ $isAvailable ? 'Available' : 'Disabled' }}
                                            </x-ui.badge>
                                        </label>
                                    </td>

                                    <!-- Override Harga Cabang -->
                                    <td class="px-6 py-4 text-right">
                                        <input type="number" name="store_price_override" value="{{ $overridePrice }}" step="0.01" min="0"
                                            form="form-assortment-{{ $prod->id }}"
                                            placeholder="Use Base Price"
                                            class="w-48 inline-block text-xs rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 text-right font-mono tabular-nums text-zinc-800 dark:text-zinc-100 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20">
                                    </td>

                                    <!-- Tombol Simpan Baris -->
                                    <td class="px-6 py-4 text-right">
                                        <x-ui.button variant="secondary" size="xs" type="submit" form="form-assortment-{{ $prod->id }}">
                                            Save
                                        </x-ui.button>
                                    </td>
                                @else
                                    <td class="px-6 py-4 text-center">
                                        <x-ui.badge variant="{{ $isAvailable ? 'success' : 'danger' }}" size="sm">
                                            {{ $isAvailable ? 'Available' : 'Disabled' }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm font-semibold text-zinc-900 dark:text-white font-mono tabular-nums">
                                        {{ $overridePrice ? 'Rp ' . number_format((float)$overridePrice, 0, ',', '.') : 'Base Price' }}
                                    </td>
                                    @if($canManage)
                                        <td class="px-6 py-4 text-right text-zinc-400 text-xs">—</td>
                                    @endif
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canManage ? 6 : 5 }}" class="px-6 py-12 text-center text-zinc-400">
                                    <span class="material-symbols-rounded text-4xl block mb-2">storefront</span>
                                    No products found for this branch.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                {{ $products->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
