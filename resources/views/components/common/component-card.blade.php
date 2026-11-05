@props([
    'title' => '',
    'desc' => '',
])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-border bg-card shadow-xs transition-all duration-200']) }}>
    <!-- Card Header -->
    @if ($title || isset($action))
        <div class="px-6 py-5 flex items-center justify-between gap-4">
            <div>
                <h3 class="text-base font-semibold text-foreground tracking-tight">
                    {{ $title }}
                </h3>
                @if ($desc)
                    <p class="mt-1 text-sm text-muted-foreground leading-normal">
                        {{ $desc }}
                    </p>
                @endif
            </div>
            @if (isset($action))
                <div class="shrink-0">
                    {{ $action }}
                </div>
            @endif
        </div>
    @endif

    <!-- Card Body -->
    <div class="p-4 sm:p-6 {{ ($title || isset($action)) ? 'border-t border-border' : '' }}">
        {{ $slot }}
    </div>
</div>
