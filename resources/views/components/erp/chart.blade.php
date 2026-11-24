@props([
    'id' => null,
    'options' => [],
    'option' => null,
    'height' => 'h-[220px] sm:h-[240px]',
])

@php
    $chartId = $id ?? 'chart-' . uniqid();
    $finalOptions = !empty($options) ? $options : ($option ?? []);
@endphp

<div
    {{ $attributes->merge(['class' => 'relative w-full overflow-hidden']) }}
    x-data="erpChart({ chartId: @js($chartId), options: @js($finalOptions) })"
    wire:ignore
>
    <div
        x-ref="container"
        id="{{ $chartId }}"
        class="w-full {{ $height }}"
    ></div>
</div>
