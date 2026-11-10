<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <x-erp.module-sub-nav module="catalog" />

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-foreground tracking-tight">
                Retail Barcode Management
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                Generate EAN-13 barcodes, validate check-digits, and assign barcodes to product variants.
            </p>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3 text-emerald-800 text-sm font-medium">
            <span class="material-symbols-rounded text-emerald-600 text-[20px]">check_circle</span>
            {{ session('message') }}
        </div>
    @endif

    <!-- Barcode Validator Tool Card -->
    <div class="bg-card p-6 rounded-2xl border border-border shadow-sm space-y-4">
        <h3 class="text-base font-semibold text-foreground">
            EAN-13 Checksum Validator
        </h3>
        <div class="flex flex-col sm:flex-row gap-3 items-center">
            <input type="text" wire:model="barcodeToValidate" placeholder="Input 13-digit EAN barcode (e.g. 2001234567890)"
                class="flex-1 bg-background border border-border rounded-xl px-4 py-2 text-sm text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
            <button wire:click="validateBarcode"
                class="px-4 py-2 bg-primary text-primary-foreground text-sm font-semibold rounded-xl hover:bg-primary/90 transition-colors">
                Validate Check-Digit
            </button>
        </div>
        @if ($isValidEan13 !== null)
            <div class="p-3 rounded-xl text-sm font-medium {{ $isValidEan13 ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-red-50 text-red-800 border border-red-200' }}">
                @if ($isValidEan13)
                    <span class="flex items-center gap-2"><span class="material-symbols-rounded text-[20px]">verified</span> Valid EAN-13 barcode format and checksum.</span>
                @else
                    <span class="flex items-center gap-2"><span class="material-symbols-rounded text-[20px]">error</span> Invalid EAN-13 barcode checksum or structure.</span>
                @endif
            </div>
        @endif
    </div>

    <!-- Product Variants Barcode Registry -->
    <div class="bg-card rounded-2xl border border-border shadow-sm overflow-hidden">
        <div class="p-4 border-b border-border flex flex-col sm:flex-row gap-3 justify-between items-center">
            <h3 class="text-base font-semibold text-foreground">Product Variants & Barcodes</h3>
            <div class="w-full sm:w-72">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search SKU, barcode, product..."
                    class="w-full bg-background border border-border rounded-xl px-3 py-1.5 text-sm text-foreground focus:ring-2 focus:ring-primary">
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground">
                <thead class="bg-muted text-muted-foreground font-semibold text-xs uppercase tracking-wider border-b border-border">
                    <tr>
                        <th class="px-6 py-3">Product Name</th>
                        <th class="px-6 py-3">Variant Name</th>
                        <th class="px-6 py-3">SKU</th>
                        <th class="px-6 py-3">Current Barcode</th>
                        <th class="px-6 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($variants as $variant)
                        <tr class="hover:bg-muted/50 transition-colors">
                            <td class="px-6 py-4 font-medium text-foreground">
                                {{ $variant->product?->name ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4 text-foreground">
                                {{ $variant->variant_name }}
                            </td>
                            <td class="px-6 py-4 font-mono text-xs text-muted-foreground">
                                {{ $variant->sku }}
                            </td>
                            <td class="px-6 py-4 font-mono text-xs font-semibold text-foreground">
                                {{ $variant->barcode ?? '-' }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button wire:click="generateInternalBarcodeForVariant({{ $variant->id }})"
                                    class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:text-primary/80">
                                    <span class="material-symbols-rounded text-[16px]">autorenew</span>
                                    Assign Internal Barcode
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-muted-foreground">
                                No product variants found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($variants->hasPages())
            <div class="p-4 border-t border-border">
                {{ $variants->links() }}
            </div>
        @endif
    </div>
</div>
