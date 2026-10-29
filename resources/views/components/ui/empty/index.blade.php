<x-ui.empty-state {{ $attributes }}>
    @isset($icon)
        <x-slot:icon>{{ $icon }}</x-slot:icon>
    @endisset
    {{ $slot }}
</x-ui.empty-state>
