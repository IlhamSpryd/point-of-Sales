<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <x-erp.module-sub-nav module="catalog" />

    <!-- Header Page & Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-foreground tracking-tight">
                Retail Shelf Price Labels
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                Generate and print retail shelf price ticketing and barcode labels for products.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="$set('isBatchModalOpen', true)"
                class="inline-flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary/90 text-primary-foreground text-sm font-semibold rounded-xl shadow-sm transition-colors">
                <span class="material-symbols-rounded text-[20px]">add</span>
                Generate Labels
            </button>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3 text-emerald-800 text-sm font-medium">
            <span class="material-symbols-rounded text-emerald-600 text-[20px]">check_circle</span>
            {{ session('message') }}
        </div>
    @endif

    <!-- Filter & Search Bar -->
    <x-erp.filter-bar
        search-placeholder="Search batch, barcode, product..."
        search-model="search"
        :reset-method="null"
    >
        <x-slot:filters>
            <select wire:model.live="statusFilter"
                class="bg-card border border-border text-foreground text-xs rounded-xl px-3 py-2 font-semibold focus:outline-none focus:ring-1 focus:ring-ring">
                <option value="ALL">All Status</option>
                <option value="PENDING">Pending Print</option>
                <option value="PRINTED">Printed</option>
            </select>
        </x-slot:filters>
    </x-erp.filter-bar>

    <!-- Labels Table -->
    <div class="card-surface rounded-2xl border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground">
                <thead class="bg-muted/50 text-muted-foreground font-semibold text-2xs uppercase tracking-wider border-b border-border">
                    <tr>
                        <th class="px-6 py-3.5">Batch Number</th>
                        <th class="px-6 py-3.5">Product Name</th>
                        <th class="px-6 py-3.5">Barcode</th>
                        <th class="px-6 py-3.5">Price Snapshot</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($labels as $label)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-6 py-4 font-mono font-medium text-xs text-foreground">
                                {{ $label->batch_number }}
                            </td>
                            <td class="px-6 py-4 font-medium text-foreground">
                                {{ $label->product?->product_name ?? $label->product?->name ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4 font-mono text-muted-foreground text-xs">
                                {{ $label->variant?->barcode ?? $label->product?->barcode ?? '-' }}
                            </td>
                            <td class="px-6 py-4 font-semibold text-foreground">
                                Rp {{ number_format((float) $label->new_price, 0, ',', '.') }}
                            </td>
                            <td class="px-6 py-4">
                                @if ($label->status === \App\Modules\Retail\Models\ShelfLabel::STATUS_PRINTED || $label->status === 'printed')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-2xs font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                        Printed
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-2xs font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                        Pending
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="{{ route('retail.shelf-labels.print-batch', $label->batch_number) }}" target="_blank"
                                    class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:text-primary/80">
                                    <span class="material-symbols-rounded text-base">print</span>
                                    Print Sheet
                                </a>
                                @if ($label->status === \App\Modules\Retail\Models\ShelfLabel::STATUS_PENDING || $label->status === 'pending')
                                    <button wire:click="markBatchPrinted('{{ $label->batch_number }}')"
                                        class="inline-flex items-center gap-1 text-xs font-semibold text-muted-foreground hover:text-foreground">
                                        <span class="material-symbols-rounded text-base">check_circle</span>
                                        Mark Done
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-muted-foreground">
                                <span class="material-symbols-rounded text-4xl block mb-2 text-muted-foreground/50">label_off</span>
                                No shelf labels found. Click "Generate Labels" to create new price tags.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($labels->hasPages())
            <div class="p-4 border-t border-border">
                {{ $labels->links() }}
            </div>
        @endif
    </div>

    <!-- Modal Generate Label -->
    @if ($isBatchModalOpen)
        <x-ui.modal wire:model="isBatchModalOpen" title="Generate Shelf Labels" maxWidth="md" onClose="$wire.set('isBatchModalOpen', false)">
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Select Product</label>
                    <select wire:model="selectedProductId" class="w-full bg-card border border-border rounded-xl text-sm p-2 text-foreground focus:ring-1 focus:ring-ring">
                        <option value="">-- Choose Product --</option>
                        @foreach ($products as $prod)
                            <option value="{{ $prod->id }}">{{ $prod->product_name ?? $prod->name }} ({{ $prod->barcode ?? 'No Barcode' }})</option>
                        @endforeach
                    </select>
                    @error('selectedProductId') <span class="text-2xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Label Quantity</label>
                    <input type="number" wire:model="quantity" min="1" max="100" class="w-full bg-card border border-border rounded-xl text-sm p-2 text-foreground focus:ring-1 focus:ring-ring">
                    @error('quantity') <span class="text-2xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Label Type</label>
                    <select wire:model="labelType" class="w-full bg-card border border-border rounded-xl text-sm p-2 text-foreground focus:ring-1 focus:ring-ring">
                        <option value="STANDARD">Standard Shelf Tag (60x30mm)</option>
                        <option value="COMPACT">Compact Tag (40x20mm)</option>
                        <option value="PROMO">Promotional / Sale Highlight</option>
                    </select>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex justify-end gap-2 w-full">
                    <button type="button" wire:click="$set('isBatchModalOpen', false)" class="px-4 py-2 text-muted-foreground hover:text-foreground rounded-xl text-sm font-medium transition-colors">
                        Cancel
                    </button>
                    <button type="button" wire:click="createLabel" class="px-4 py-2 bg-primary text-primary-foreground rounded-xl text-sm font-semibold hover:bg-primary/90 transition-colors shadow-xs">
                        Create Labels
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
