@props([
    'name' => 'Starter',
    'price' => 'Rp 0',
    'period' => '/bulan',
    'description' => '',
    'features' => [],
    'isCurrent' => false,
    'isPopular' => false,
])

<div {{ $attributes->twMerge('rounded-2xl border p-6 flex flex-col justify-between gap-6 shadow-xs transition-all relative select-none ' . (
    $isCurrent
        ? 'border-primary ring-2 ring-ring bg-card'
        : ($isPopular ? 'border-foreground bg-card shadow-md' : 'border-border bg-card')
)) }}>
    @if ($isPopular)
        <span class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-primary text-primary-foreground shadow-xs">
            Paling Populer
        </span>
    @endif

    <div>
        {{-- Plan Header --}}
        <div class="flex items-center justify-between mb-2">
            <h3 class="text-lg font-bold text-foreground tracking-tight">
                {{ $name }}
            </h3>
            @if ($isCurrent)
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-success-muted text-success border border-success-border">
                    Paket Aktif
                </span>
            @endif
        </div>

        @if ($description)
            <p class="text-xs text-muted-foreground leading-normal mb-4">
                {{ $description }}
            </p>
        @endif

        {{-- Price Display --}}
        <div class="flex items-baseline gap-1 my-4 pb-4 border-b border-border">
            <span class="text-3xl font-black text-foreground tracking-tight numeric font-mono">
                {{ $price }}
            </span>
            <span class="text-xs text-muted-foreground font-medium">
                {{ $period }}
            </span>
        </div>

        {{-- Features Checklist --}}
        <ul class="space-y-2.5 text-xs text-foreground my-2">
            @foreach ($features as $feat)
                <li class="flex items-start gap-2">
                    <span class="material-symbols-rounded text-base text-success shrink-0 mt-0.5">check_circle</span>
                    <span class="leading-tight">{{ $feat }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- Action Button --}}
    <div class="pt-4 border-t border-border">
        @if ($isCurrent)
            <button type="button" disabled
                class="w-full h-11 rounded-xl text-xs font-bold bg-muted text-muted-foreground border border-border cursor-not-allowed flex items-center justify-center">
                Paket Anda Saat Ini
            </button>
        @else
            <button type="button"
                class="w-full h-11 rounded-xl text-xs font-bold bg-primary text-primary-foreground hover:bg-primary active:scale-95 transition-all shadow-xs cursor-pointer flex items-center justify-center gap-1.5">
                <span>Pilih Paket {{ $name }}</span>
                <span class="material-symbols-rounded text-base">arrow_forward</span>
            </button>
        @endif
    </div>
</div>
