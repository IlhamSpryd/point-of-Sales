{{-- MODAL PEMILIHAN VARIAN PRODUK RITEL (TASK-P0-01) --}}
@if ($selectingVariantProductId && isset($selectingVariantProduct) && !$selectingProductId)
    <x-ui.modal
        :open="true"
        id="variant-picker"
        maxWidth="lg"
        onClose="$wire.closeVariantPicker()"
    >
        <x-slot:header>
            <div class="flex items-center gap-3">
                @if ($selectingVariantProduct->photo_url)
                    <img src="{{ $selectingVariantProduct->photo_url }}"
                        alt="{{ $selectingVariantProduct->product_name }}"
                        class="w-12 h-12 rounded-lg object-cover bg-muted border border-border">
                @else
                    <div class="w-12 h-12 rounded-lg bg-muted border border-border flex items-center justify-center text-muted-foreground">
                        <span class="material-symbols-rounded text-[24px]">style</span>
                    </div>
                @endif
                <div>
                    <h3 id="variant-picker-title" class="text-base font-semibold text-foreground">
                        {{ $selectingVariantProduct->product_name }}
                    </h3>
                    <p class="text-xs text-muted-foreground">Pilih varian produk untuk ditambahkan ke keranjang</p>
                </div>
            </div>
        </x-slot:header>

        <!-- Body Modal (Variants Grid) -->
        <div class="space-y-2.5 text-xs py-1">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                @foreach ($selectingVariantProduct->variants as $variant)
                    @php
                        $varPrice = $variant->price_override ?? $selectingVariantProduct->product_price;
                    @endphp
                    <button type="button"
                        wire:click="selectVariant({{ $variant->id }})"
                        x-on:click="window.erpMotion?.pulseHighlight($el)"
                        class="group p-3 rounded-xl border border-border hover:border-foreground bg-background hover:bg-muted text-left transition-all duration-150 flex flex-col justify-between gap-2 shadow-2xs focus-visible:ring-2 focus-visible:ring-ring outline-none">
                        <div>
                            <div class="font-semibold text-sm text-foreground group-hover:text-foreground">
                                {{ $variant->variant_name }}
                            </div>
                            @if ($variant->sku)
                                <div class="text-[11px] text-muted-foreground font-mono mt-0.5">
                                    SKU: {{ $variant->sku }}
                                </div>
                            @endif
                            @if ($variant->attributeValues && $variant->attributeValues->isNotEmpty())
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach ($variant->attributeValues as $val)
                                        <span class="px-1.5 py-0.5 rounded bg-muted text-[10px] text-muted-foreground font-medium">
                                            {{ $val->value ?? $val->name }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t border-border mt-1">
                            <span class="text-xs font-semibold text-foreground font-sans tabular-nums">
                                Rp {{ number_format((float) $varPrice, 0, ',', '.') }}
                            </span>
                            <span class="text-[11px] font-medium text-primary flex items-center gap-0.5">
                                <span>Pilih</span>
                                <span class="material-symbols-rounded text-[14px]">arrow_forward</span>
                            </span>
                        </div>
                    </button>
                @endforeach
            </div>
        </div>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" wire:click="closeVariantPicker">
                    Batal
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
@endif
