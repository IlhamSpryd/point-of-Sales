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
                        class="h-10 px-3 rounded-xl border border-zinc-200 dark:border-zinc-700 text-sm bg-white dark:bg-zinc-800 font-medium text-zinc-800 dark:text-zinc-200 focus:outline-hidden focus:ring-2 focus:ring-ring/20">
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

        <x-erp.data-table
            :is-empty="$products->isEmpty()"
            empty-title="No products found for this branch."
            empty-description="Configure products to assign pricing and availability for this outlet."
            empty-icon="storefront"
            :colspan="$canManage ? 6 : 5"
        >
            <x-slot:head>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Product</th>
                <th scope="col" class="px-6 py-3.5 text-left text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Category</th>
                <th scope="col" class="px-6 py-3.5 text-right text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Base Price</th>
                <th scope="col" class="px-6 py-3.5 text-center text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Available in Branch</th>
                <th scope="col" class="px-6 py-3.5 text-right text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Branch Price Override</th>
                @if($canManage)
                    <th scope="col" class="px-6 py-3.5 text-right text-2xs font-semibold text-muted-foreground uppercase tracking-wider">Save</th>
                @endif
            </x-slot:head>

            @foreach($products as $prod)
                @php
                    $assortment = $prod->storeAssortments->first();
                    $isAvailable = $assortment ? $assortment->is_available : true;
                    $overridePrice = $assortment ? $assortment->store_price_override : null;
                @endphp
                <tr class="hover:bg-muted/40 transition-colors">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            @if($prod->photo_url)
                                <img src="{{ $prod->photo_url }}" alt="{{ $prod->product_name }}" class="w-10 h-10 rounded-xl object-cover border border-border">
                            @else
                                <div class="w-10 h-10 rounded-xl bg-muted flex items-center justify-center text-muted-foreground">
                                    <span class="material-symbols-rounded">inventory_2</span>
                                </div>
                            @endif
                            <div>
                                <div class="font-semibold text-sm text-foreground">{{ $prod->product_name }}</div>
                                <div class="font-sans text-xs text-muted-foreground">{{ $prod->product_code }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-muted-foreground">{{ $prod->category?->category_name ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm font-medium text-right text-foreground font-sans tabular-nums">
                        Rp {{ number_format((float) $prod->product_price, 0, ',', '.') }}
                    </td>

                    @if($canManage && $selectedStoreId)
                        <form id="form-assortment-{{ $prod->id }}" action="{{ route('assortments.update') }}" method="POST" class="hidden">
                            @csrf
                            <input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
                            <input type="hidden" name="product_id" value="{{ $prod->id }}">
                        </form>

                        <!-- Switch Ketersediaan -->
                        <td class="px-6 py-4">
                            <input type="hidden" name="is_available" value="0" form="form-assortment-{{ $prod->id }}">
                            <label class="inline-flex items-center cursor-pointer gap-2">
                                <input type="checkbox" name="is_available" value="1" {{ $isAvailable ? 'checked' : '' }}
                                    form="form-assortment-{{ $prod->id }}"
                                    class="rounded border-border text-primary shadow-xs focus:ring-ring h-4 w-4">
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $isAvailable ? 'bg-success' : 'bg-destructive' }}"></span>
                                    {{ $isAvailable ? 'Available' : 'Disabled' }}
                                </span>
                            </label>
                        </td>

                        <!-- Branch Price Override -->
                        <td class="px-6 py-4 text-right">
                            <input type="number" name="store_price_override" value="{{ $overridePrice }}" step="0.01" min="0"
                                form="form-assortment-{{ $prod->id }}"
                                placeholder="Use Base Price"
                                class="w-48 inline-block text-xs rounded-lg border border-border bg-background p-2 text-right font-sans tabular-nums text-foreground focus:outline-hidden focus:ring-2 focus:ring-ring/20">
                        </td>

                        <!-- Save Row Button -->
                        <td class="px-6 py-4 text-right">
                            <x-ui.button variant="secondary" size="xs" type="submit" form="form-assortment-{{ $prod->id }}">
                                Save
                            </x-ui.button>
                        </td>
                    @else
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $isAvailable ? 'bg-success' : 'bg-destructive' }}"></span>
                                {{ $isAvailable ? 'Available' : 'Disabled' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right text-sm font-semibold text-foreground font-sans tabular-nums">
                            {{ $overridePrice ? 'Rp ' . number_format((float)$overridePrice, 0, ',', '.') : 'Base Price' }}
                        </td>
                        @if($canManage)
                            <td class="px-6 py-4 text-right text-muted-foreground text-xs">—</td>
                        @endif
                    @endif
                </tr>
            @endforeach

            @if($products->hasPages())
                <x-slot:pagination>
                    {{ $products->links() }}
                </x-slot:pagination>
            @endif
        </x-erp.data-table>
    </div>
</x-app-layout>
