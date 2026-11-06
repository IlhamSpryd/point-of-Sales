@props([
    'title' => '',
    'subtitle' => null,
    'periods' => ['Today', '7 Days', 'This Month', 'This Year'],
    'activePeriod' => 'This Month',
    'legends' => [],
])

<div {{ $attributes->twMerge('rounded-2xl border border-border bg-card p-5 sm:p-6 shadow-xs select-none space-y-4') }}
    x-data="{ currentPeriod: @js($activePeriod) }">
    
    {{-- Card Header: Title & Period Switcher (TailAdmin Statistics Chart Style) --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-border pb-4">
        <div>
            <h3 class="text-base font-bold text-foreground tracking-tight">
                {{ $title }}
            </h3>
            @if ($subtitle)
                <p class="text-xs text-muted-foreground mt-0.5 leading-normal">
                    {{ $subtitle }}
                </p>
            @endif
        </div>

        <div class="flex items-center gap-2 self-start sm:self-center">
            @if (!empty($periods))
                <div class="inline-flex items-center gap-0.5 p-1 bg-muted rounded-xl border border-border text-xs">
                    @foreach ($periods as $p)
                        <button
                            type="button"
                            @click="currentPeriod = '{{ $p }}'; $dispatch('period-changed', '{{ $p }}')"
                            :class="currentPeriod === '{{ $p }}'
                                ? 'bg-card text-foreground font-semibold shadow-xs'
                                : 'text-muted-foreground hover:text-foreground'"
                            class="px-2.5 py-1 rounded-lg text-xs font-medium transition-all duration-150 cursor-pointer">
                            {{ $p }}
                        </button>
                    @endforeach
                </div>
            @endif

            @if (isset($action))
                <div>
                    {{ $action }}
                </div>
            @endif
        </div>
    </div>

    {{-- Chart Slot Container --}}
    <div class="relative min-h-[220px] w-full flex items-center justify-center">
        {{ $slot }}
    </div>

    {{-- Interactive Legends Row --}}
    @if (!empty($legends))
        <div class="flex flex-wrap items-center justify-center gap-4 pt-3 border-t border-border text-xs">
            @foreach ($legends as $leg)
                <div class="flex items-center gap-1.5 font-medium text-muted-foreground">
                    <span class="size-2.5 rounded-full shrink-0 {{ $leg['color'] ?? 'bg-primary' }}" aria-hidden="true"></span>
                    <span>{{ $leg['label'] }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
