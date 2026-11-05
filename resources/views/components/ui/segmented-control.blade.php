@props([
    'options' => [],
    'value' => null,
    'size' => 'md',
])

@php
    $normalizedOptions = [];
    foreach ($options as $key => $opt) {
        if (is_array($opt)) {
            $normalizedOptions[] = [
                'value' => (string) ($opt['value'] ?? $key),
                'label' => (string) ($opt['label'] ?? $opt['value'] ?? $key),
                'badge' => $opt['badge'] ?? null,
            ];
        } else {
            $normalizedOptions[] = [
                'value' => (string) (is_numeric($key) ? $opt : $key),
                'label' => (string) $opt,
                'badge' => null,
            ];
        }
    }

    $wireModel = $attributes->whereStartsWith('wire:model')->first();
    $initialValue = $value ?? ($normalizedOptions[0]['value'] ?? '');

    $sizeClasses = match ($size) {
        'sm' => 'p-0.5 text-[11px]',
        'lg' => 'p-1.5 text-sm',
        default => 'p-1 text-xs',
    };

    $buttonPadding = match ($size) {
        'sm' => 'px-2 py-1',
        'lg' => 'px-4 py-2',
        default => 'px-3 py-1.5',
    };
@endphp

<div
    x-data="{
        selected: @if($wireModel) @entangle($attributes->wire('model')) @else @js($initialValue) @endif,
        select(val) {
            this.selected = val;
            this.$dispatch('input', val);
            this.$dispatch('change', val);
        }
    }"
    role="tablist"
    aria-label="Pilihan tampilan"
    {{ $attributes->whereDoesntStartWith('wire:model')->twMerge('inline-flex items-center gap-1 bg-muted rounded-xl border border-border select-none ' . $sizeClasses) }}>
    @foreach ($normalizedOptions as $opt)
        <button
            type="button"
            role="tab"
            @click="select('{{ $opt['value'] }}')"
            :aria-selected="(selected === '{{ $opt['value'] }}').toString()"
            :class="selected === '{{ $opt['value'] }}'
                ? 'bg-card text-foreground font-semibold shadow-xs border border-border'
                : 'text-muted-foreground hover:text-foreground hover:bg-background/50 border border-transparent'"
            class="relative rounded-lg font-medium transition-all duration-150 cursor-pointer flex items-center gap-1.5 whitespace-nowrap {{ $buttonPadding }}">
            <span>{{ $opt['label'] }}</span>
            @if (!empty($opt['badge']))
                <span class="px-1.5 py-0.2 text-[10px] rounded-full bg-secondary text-primary font-bold">
                    {{ $opt['badge'] }}
                </span>
            @endif
        </button>
    @endforeach
</div>
