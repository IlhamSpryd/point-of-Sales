@props([
    'title' => '',
    'sku' => '',
    'barcode' => '',
    'price' => '0',
    'originalPrice' => null,
    'uom' => 'Pcs',
    'promoTag' => null,
])

<div {{ $attributes->twMerge('rounded-xl border border-border bg-card p-3 shadow-xs select-none max-w-xs transition-all hover:border-border') }}>
    {{-- Top Row: Category / Promo Tag --}}
    <div class="flex items-center justify-between gap-1 mb-1">
        <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground truncate">
            {{ $uom }}
        </span>
        @if ($promoTag)
            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-destructive text-destructive-foreground">
                {{ $promoTag }}
            </span>
        @endif
    </div>

    {{-- Product Title --}}
    <h4 class="text-sm font-bold text-foreground line-clamp-2 leading-snug tracking-tight mb-2">
        {{ $title }}
    </h4>

    {{-- Price Display --}}
    <div class="mb-3">
        @if ($originalPrice)
            <span class="text-[11px] text-muted-foreground line-through numeric font-sans block">
                {{ $originalPrice }}
            </span>
        @endif
        <div class="text-2xl font-black text-foreground tracking-tight numeric font-sans">
            {{ $price }}
        </div>
    </div>

    {{-- Barcode Simulation / Representation Box --}}
    <div class="pt-2 border-t border-border flex flex-col items-center">
        {{-- Stylized SVG Barcode Lines --}}
        <svg class="w-full h-8 text-foreground" viewBox="0 0 160 32" preserveAspectRatio="none" fill="currentColor" aria-hidden="true">
            <rect x="0" y="0" width="3" height="32"/>
            <rect x="5" y="0" width="2" height="32"/>
            <rect x="10" y="0" width="4" height="32"/>
            <rect x="17" y="0" width="2" height="32"/>
            <rect x="22" y="0" width="5" height="32"/>
            <rect x="30" y="0" width="2" height="32"/>
            <rect x="35" y="0" width="3" height="32"/>
            <rect x="42" y="0" width="6" height="32"/>
            <rect x="51" y="0" width="2" height="32"/>
            <rect x="56" y="0" width="4" height="32"/>
            <rect x="63" y="0" width="2" height="32"/>
            <rect x="68" y="0" width="5" height="32"/>
            <rect x="76" y="0" width="3" height="32"/>
            <rect x="82" y="0" width="2" height="32"/>
            <rect x="87" y="0" width="4" height="32"/>
            <rect x="94" y="0" width="6" height="32"/>
            <rect x="103" y="0" width="2" height="32"/>
            <rect x="108" y="0" width="3" height="32"/>
            <rect x="114" y="0" width="5" height="32"/>
            <rect x="122" y="0" width="2" height="32"/>
            <rect x="127" y="0" width="4" height="32"/>
            <rect x="134" y="0" width="3" height="32"/>
            <rect x="140" y="0" width="5" height="32"/>
            <rect x="148" y="0" width="2" height="32"/>
            <rect x="153" y="0" width="4" height="32"/>
            <rect x="158" y="0" width="2" height="32"/>
        </svg>

        {{-- Barcode Digits & SKU --}}
        <div class="w-full flex items-center justify-between text-[10px] font-sans text-muted-foreground mt-1">
            <span>{{ $barcode }}</span>
            <span>{{ $sku }}</span>
        </div>
    </div>
</div>
