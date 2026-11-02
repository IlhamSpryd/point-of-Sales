@props([
    'value' => null,
    'for' => '',
])

{{-- Deprecated: use <x-ui.label> or <x-erp.form-field> instead --}}
<x-ui.label :for="$for ?: (string) $attributes->get('for')" {{ $attributes }}>
    {{ $value ?? $slot }}
</x-ui.label>
