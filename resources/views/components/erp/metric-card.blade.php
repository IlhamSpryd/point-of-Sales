@props([
    'title' => '',
    'value' => '0',
    'change' => null,
    'direction' => 'up',
    'icon' => null,
    'iconVariant' => 'secondary',
    'badge' => null,
    'trendLabel' => null,
])

<x-erp.stat-card
    :title="$title"
    :value="$value"
    :change="$change"
    :change-direction="$direction"
    :icon="$icon"
    :icon-variant="$iconVariant"
    :trend-label="$trendLabel"
    {{ $attributes }}
>
    {{ $slot }}
</x-erp.stat-card>
