@props([
    'tab' => null,
])

@php
    $tabKey = \Illuminate\Support\Str::slug((string) $tab) ?: substr(md5((string) $tab), 0, 8);
@endphp

{{-- A single tab button. The first one rendered becomes active by default. --}}
<button
    type="button"
    role="tab"
    x-init="if (activeTab === null) activeTab = @js($tab)"
    @click="activeTab = @js($tab)"
    :id="$id('dd-tabs') + '-tab-{{ $tabKey }}'"
    :aria-controls="$id('dd-tabs') + '-panel-{{ $tabKey }}'"
    :aria-selected="activeTab === @js($tab) ? 'true' : 'false'"
    :tabindex="activeTab === @js($tab) ? 0 : -1"
    :class="activeTab === @js($tab) ? 'bg-background text-foreground shadow-xs' : 'text-foreground/60 hover:text-foreground'"
    {{ $attributes->twMerge('inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-small px-3 py-1.5 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-ring') }}
>
    {{ $slot }}
</button>
