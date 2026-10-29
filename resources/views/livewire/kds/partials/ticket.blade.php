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
    $canProcess = in_array(auth()->user()?->role?->name, ['Owner', 'Manager', 'Barista', 'Cook'], true);
@endphp

<article wire:key="{{ $variant }}-{{ $item->id }}" data-gsap="kds-card"
         class="rounded-2xl border border-gray-200 bg-white p-4 shadow-xs transition-all duration-200 hover:shadow-md hover:border-gray-300">

    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-mono text-[10px] 3xl:text-xs font-bold uppercase tracking-widest text-gray-400">{{ $order->order_code }}</p>
            <p class="mt-0.5 truncate text-base font-black text-apeiron-ink leading-tight">{{ $place }}</p>
        </div>
        <x-ui.badge :variant="$isTakeaway ? 'primary' : 'secondary'">
            {{ $isTakeaway ? 'Takeaway' : 'Dine-in' }}
        </x-ui.badge>
    </div>

    <div class="mt-2.5 flex items-baseline gap-2">
        <span class="rounded-lg border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-black text-apeiron-ink tabular-nums">{{ $item->qty }}×</span>
        <span class="text-sm 3xl:text-base font-bold leading-snug text-apeiron-ink">{{ $item->product->product_name }}</span>
    </div>

    @if ($variant !== 'ready' && $options->isNotEmpty())
        <div class="mt-2 flex flex-wrap gap-1">
            @foreach ($options as $opt)
                <span class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-[11px] font-semibold text-gray-600">{{ $opt['name'] ?? $opt['modifier_name'] ?? '' }}</span>
            @endforeach
        </div>
    @endif

    @if ($variant !== 'ready' && $item->notes)
        <p class="mt-2 flex gap-1.5 rounded-xl border border-amber-200 bg-amber-50/60 px-3 py-1.5 text-xs font-medium text-amber-900">
            <span class="material-symbols-rounded text-[14px] text-amber-700 mt-0.5">sticky_note_2</span>
            <span>{{ $item->notes }}</span>
        </p>
    @endif

    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
        @if ($showSla)
            <x-ui.badge :variant="match($sla) {
                'critical' => 'destructive',
                'warning' => 'warning',
                default => 'secondary'
            }" class="{{ $sla === 'critical' ? 'animate-pulse' : '' }}">
                <span class="material-symbols-rounded text-[13px] mr-1">schedule</span>
                {{ $item->slaMinutesElapsed() }} mnt
            </x-ui.badge>
        @endif

        @if ($variant !== 'pending')
            <span class="inline-flex items-center gap-1 text-gray-500 text-[11px]">
                <span class="material-symbols-rounded text-[13px]">person</span>
                {{ $item->processedBy->name ?? '-' }}
            </span>
        @endif

        @if ($variant === 'ready')
            <span class="inline-flex items-center gap-1 text-emerald-600 font-bold text-[11px]">
                <span class="material-symbols-rounded text-[13px]">check</span>
                {{ $item->updated_at->format('H:i') }}
            </span>
        @endif
    </div>

    @if ($variant === 'pending')
        @if ($canProcess)
            <button type="button" wire:click="claim({{ $item->id }})" wire:loading.attr="disabled" wire:target="claim({{ $item->id }})"
                    x-on:click="window.erpMotion?.pulseHighlight($el)"
                    class="mt-3.5 flex min-h-10 w-full items-center justify-center rounded-xl bg-apeiron-ink text-xs font-bold text-white shadow-sm transition-all duration-200 hover:bg-[#111111] active:scale-[0.98] disabled:cursor-wait disabled:opacity-60">
                <span wire:loading.remove wire:target="claim({{ $item->id }})" class="flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-[16px]">skillet</span> Mulai Racik
                </span>
                <span wire:loading wire:target="claim({{ $item->id }})" class="flex items-center gap-1.5">
                    <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span> Memproses…
                </span>
            </button>
        @endif
    @elseif ($variant === 'brewing')
        @if ($isMine || $canOverride)
            <div class="mt-3.5 flex gap-2">
                @if ($isMine)
                    <button type="button" wire:click="markReady({{ $item->id }})" wire:loading.attr="disabled" wire:target="markReady({{ $item->id }})"
                            x-on:click="window.erpMotion?.pulseHighlight($el)"
                            class="flex min-h-10 flex-1 items-center justify-center rounded-xl bg-emerald-600 text-xs font-bold text-white shadow-sm transition-all duration-200 hover:bg-emerald-700 active:scale-[0.98] disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="markReady({{ $item->id }})" class="flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-[16px]">check_circle</span> Selesai
                        </span>
                        <span wire:loading wire:target="markReady({{ $item->id }})" class="flex items-center gap-1.5">
                            <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span> Memproses…
                        </span>
                    </button>
                @endif
                <button type="button" wire:click="release({{ $item->id }})" wire:loading.attr="disabled" wire:target="release({{ $item->id }})"
                        x-on:click="window.erpMotion?.pulseHighlight($el)"
                        title="Lepaskan item ini" aria-label="Lepaskan item ini"
                        class="flex min-h-10 min-w-10 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-600 shadow-sm transition-all duration-200 hover:bg-gray-50 active:scale-[0.98] disabled:cursor-wait disabled:opacity-60 {{ $isMine ? '' : 'flex-1 gap-1.5 text-xs font-bold' }}">
                    <span wire:loading.remove wire:target="release({{ $item->id }})" class="flex items-center gap-1">
                        <span class="material-symbols-rounded text-[18px]">undo</span>
                        @unless ($isMine) Lepaskan @endunless
                    </span>
                    <span wire:loading wire:target="release({{ $item->id }})" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-gray-400 border-t-gray-700"></span>
                </button>
            </div>
        @else
            <p class="mt-3.5 text-center text-xs font-medium text-gray-400">Sedang dikerjakan {{ $item->processedBy->name ?? 'staf lain' }}</p>
        @endif
    @elseif ($variant === 'ready')
        @if ($canProcess && ($isMine || $canOverride))
            <button type="button" wire:click="recall({{ $item->id }})" wire:loading.attr="disabled" wire:target="recall({{ $item->id }})"
                    x-on:click="window.erpMotion?.pulseHighlight($el)"
                    class="mt-3.5 flex min-h-10 w-full items-center justify-center rounded-xl border border-gray-200 bg-white shadow-sm transition-all duration-200 hover:bg-gray-50 active:scale-[0.98] disabled:cursor-wait disabled:opacity-60 text-xs font-bold text-gray-700">
                <span wire:loading.remove wire:target="recall({{ $item->id }})" class="flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-[18px]">undo</span> Tarik Kembali (Recall)
                </span>
                <span wire:loading wire:target="recall({{ $item->id }})" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-gray-400 border-t-gray-700"></span>
            </button>
        @endif
    @endif
</article>
