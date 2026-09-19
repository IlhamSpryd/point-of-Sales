@php
    // Variabel dari pemanggil: $item (OrderItem), $variant ('pending' | 'brewing' | 'ready')
    $order = $item->order;
    $isTakeaway = $order->order_type?->value === 'takeaway';
    $place = $order->table?->table_name ?? ($isTakeaway ? 'Takeaway' : 'Kasir');
    $options = collect($item->options ?? []);
    $showSla = $variant !== 'ready';
    $sla = $showSla ? $item->slaLevel() : 'ok';
    $isMine = (int) $item->processed_by === (int) auth()->id();
    $canOverride = in_array(auth()->user()?->role?->name, ['Owner', 'Manager'], true);
@endphp

<article wire:key="{{ $variant }}-{{ $item->id }}"
         class="animate-slide-up-fade rounded-2xl border border-yovel-border bg-white p-4 shadow-sm transition-shadow duration-200 hover:shadow-md">

    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-mono text-[10px] font-bold uppercase tracking-widest text-yovel-muted">{{ $order->order_code }}</p>
            <p class="mt-1 truncate text-lg font-extrabold leading-tight">{{ $place }}</p>
        </div>
        <span @class([
            'shrink-0 rounded-lg px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider',
            'bg-yovel-ink text-white' => $isTakeaway,
            'border border-yovel-border text-yovel-muted' => ! $isTakeaway,
        ])>{{ $isTakeaway ? 'Takeaway' : 'Dine-in' }}</span>
    </div>

    <div class="mt-3 flex items-baseline gap-2">
        <span class="rounded-lg border border-yovel-border bg-yovel-bg px-2 py-0.5 text-sm font-extrabold tabular-nums">{{ $item->qty }}×</span>
        <span class="text-base font-bold leading-snug">{{ $item->product->product_name }}</span>
    </div>

    @if ($variant !== 'ready' && $options->isNotEmpty())
        <div class="mt-2 flex flex-wrap gap-1.5">
            @foreach ($options as $opt)
                <span class="rounded-lg border border-yovel-border bg-yovel-surface px-2 py-0.5 text-xs font-semibold">{{ $opt['name'] ?? $opt['modifier_name'] ?? '' }}</span>
            @endforeach
        </div>
    @endif

    @if ($variant !== 'ready' && $item->notes)
        <p class="mt-2 flex gap-1.5 rounded-xl border border-yovel-ink/15 bg-yovel-bg px-3 py-2 text-xs font-medium">
            <span class="material-symbols-rounded mt-px text-[14px]">sticky_note_2</span>
            <span>{{ $item->notes }}</span>
        </p>
    @endif

    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
        @if ($showSla)
            <span @class([
                'inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 font-bold',
                'border-yovel-border bg-yovel-bg text-yovel-muted' => $sla === 'ok',
                'border-amber-200 bg-amber-50 text-amber-700' => $sla === 'warning',
                'animate-pulse border-rose-200 bg-rose-50 text-rose-700' => $sla === 'critical',
            ])>
                <span class="material-symbols-rounded text-[14px]">schedule</span>
                {{ $item->slaMinutesElapsed() }} menit
            </span>
        @endif

        @if ($variant !== 'pending')
            <span class="inline-flex items-center gap-1 text-yovel-muted">
                <span class="material-symbols-rounded text-[14px]">person</span>
                {{ $item->processedBy->name ?? '-' }}
            </span>
        @endif

        @if ($variant === 'ready')
            <span class="inline-flex items-center gap-1 text-yovel-muted">
                <span class="material-symbols-rounded text-[14px]">check</span>
                {{ $item->updated_at->format('H:i') }}
            </span>
        @endif
    </div>

    @if ($variant === 'pending')
        <button type="button" wire:click="claim({{ $item->id }})" wire:loading.attr="disabled" wire:target="claim({{ $item->id }})"
                class="mt-4 flex min-h-12 w-full items-center justify-center rounded-xl bg-yovel-ink text-sm font-bold text-white shadow-sm transition-all duration-200 hover:bg-black active:scale-[0.97] disabled:cursor-wait disabled:opacity-60">
            <span wire:loading.remove wire:target="claim({{ $item->id }})" class="flex items-center gap-2">
                <span class="material-symbols-rounded text-[18px]">skillet</span> Mulai Racik
            </span>
            <span wire:loading wire:target="claim({{ $item->id }})" class="flex items-center gap-2">
                <span class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span> Memproses…
            </span>
        </button>
    @elseif ($variant === 'brewing')
        @if ($isMine || $canOverride)
            <div class="mt-4 flex gap-2">
                @if ($isMine)
                    <button type="button" wire:click="markReady({{ $item->id }})" wire:loading.attr="disabled" wire:target="markReady({{ $item->id }})"
                            class="flex min-h-12 flex-1 items-center justify-center rounded-xl bg-yovel-ink text-sm font-bold text-white shadow-sm transition-all duration-200 hover:bg-black active:scale-[0.97] disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="markReady({{ $item->id }})" class="flex items-center gap-2">
                            <span class="material-symbols-rounded text-[18px]">check_circle</span> Selesai
                        </span>
                        <span wire:loading wire:target="markReady({{ $item->id }})" class="flex items-center gap-2">
                            <span class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span> Memproses…
                        </span>
                    </button>
                @endif
                <button type="button" wire:click="release({{ $item->id }})" wire:loading.attr="disabled" wire:target="release({{ $item->id }})"
                        title="Lepaskan item ini" aria-label="Lepaskan item ini"
                        class="flex min-h-12 min-w-12 items-center justify-center rounded-xl border border-yovel-border bg-white shadow-sm transition-all duration-200 hover:bg-yovel-bg active:scale-[0.97] disabled:cursor-wait disabled:opacity-60 {{ $isMine ? '' : 'flex-1 gap-2 text-sm font-bold' }}">
                    <span wire:loading.remove wire:target="release({{ $item->id }})" class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[20px]">undo</span>
                        @unless ($isMine) Lepaskan @endunless
                    </span>
                    <span wire:loading wire:target="release({{ $item->id }})" class="h-4 w-4 animate-spin rounded-full border-2 border-yovel-ink/30 border-t-yovel-ink"></span>
                </button>
            </div>
        @else
            <p class="mt-4 text-center text-xs font-medium text-yovel-muted">Sedang dikerjakan {{ $item->processedBy->name ?? 'staf lain' }}</p>
        @endif
    @endif
</article>
