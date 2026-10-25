@props(['class' => ''])

<div {{ $attributes->merge(['class' => 'flex-1 overflow-auto gap-2' . $class]) }}>
    {{ $slot }}
</div>
