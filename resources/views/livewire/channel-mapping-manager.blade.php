<div class="space-y-6" data-gsap="page-enter">
    <x-erp.module-sub-nav module="pos-ops" />

    <!-- Header -->
    <x-erp.page-header
        title="Channel Integration (Channel Mapping)"
        description="Map external delivery platform SKUs (GrabFood, GoFood) to internal product inventory.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('products.index') }}">Catalog</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Channel Integrations</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>
    </x-erp.page-header>

    @if (session()->has('message'))
        <x-ui.alert type="success" title="Success" class="rounded-2xl">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Form Pemetaan Baru -->
        <div class="card-surface p-6 rounded-2xl border border-border lg:col-span-1 h-fit shadow-xs">
            <h2 class="text-sm font-bold text-foreground mb-5 tracking-tight border-b border-border pb-3">
                Add New Mapping
            </h2>

            <form wire:submit="saveMapping" class="flex flex-col gap-4">
                <div>
                    <x-ui.label for="mapping-provider">Channel Provider *</x-ui.label>
                    <x-ui.select id="mapping-provider" wire:model="provider">
                        <option value="grabfood">GrabFood</option>
                        <option value="gofood">GoFood</option>
                    </x-ui.select>
                </div>

                <div>
                    <x-ui.label for="mapping-external-id">Provider SKU (External Product ID) *</x-ui.label>
                    <x-ui.input id="mapping-external-id" type="text" wire:model="externalProductId" placeholder="e.g., GF-1234 or SKU-GF-CHICKEN" class="font-sans text-sm" />
                    @error('externalProductId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <x-ui.label for="mapping-product">Internal Product *</x-ui.label>
                    <x-ui.select id="mapping-product" wire:model="productId">
                        <option value="">-- Select Internal Product --</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}">{{ $product->product_name }}</option>
                        @endforeach
                    </x-ui.select>
                    @error('productId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <x-ui.button type="submit" variant="primary" class="mt-2 w-full justify-center" wire:loading.attr="disabled" wire:target="saveMapping">
                    <span wire:loading wire:target="saveMapping" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white mr-1.5"></span>
                    Save Mapping
                </x-ui.button>
            </form>
        </div>

        <!-- Channel Mapping Table -->
        <div class="card-surface overflow-hidden rounded-2xl border border-border lg:col-span-2 flex flex-col shadow-xs">
            <div class="px-6 py-4 border-b border-border bg-muted/30 flex items-center justify-between">
                <h2 class="text-sm font-bold text-foreground tracking-tight">
                    Active Channel Mappings
                </h2>
                <span class="text-xs text-zinc-400">Total: {{ $mappings->total() }} SKUs</span>
            </div>
            <div class="overflow-x-auto flex-1">
                <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-border dark:border-zinc-800 bg-zinc-50/30 dark:bg-zinc-900/30 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="px-6 py-3.5">Provider</th>
                            <th class="px-6 py-3.5">External SKU</th>
                            <th class="px-6 py-3.5">Internal Product</th>
                            <th class="px-6 py-3.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border dark:divide-zinc-800/70">
                        @forelse($mappings as $mapping)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                                <td class="px-6 py-4">
                                    <span class="text-xs text-foreground font-medium font-sans">
                                        {{ strtoupper($mapping->provider) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 font-sans font-semibold text-zinc-900 dark:text-white text-xs">
                                    {{ $mapping->external_product_id }}
                                </td>
                                <td class="px-6 py-4 text-sm text-zinc-700 dark:text-zinc-300">
                                    {{ $mapping->product->product_name ?? 'Product Deleted' }}
                                </td>
                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    <x-ui.table-actions>
                                        <x-ui.table-action-item icon="delete" variant="destructive"
                                            wire:click="deleteMapping({{ $mapping->id }})"
                                            wire:confirm="SKU {{ $mapping->external_product_id }} will be deleted. New orders with this external SKU will fail until re-mapped. Continue?"
                                            aria-label="Delete Mapping">
                                            Delete Mapping
                                        </x-ui.table-action-item>
                                    </x-ui.table-actions>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-zinc-400">
                                    <span class="material-symbols-rounded text-4xl block mb-2">link_off</span>
                                    No external channel SKU mappings registered.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($mappings->hasPages())
                <div class="p-4 border-t border-border dark:border-zinc-800">
                    {{ $mappings->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
