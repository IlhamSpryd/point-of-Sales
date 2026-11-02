@props([
    'disabled' => false,
])

{{-- Deprecated: use <x-ui.input> or <x-erp.form-field> instead --}}
<x-ui.input @disabled($disabled) {{ $attributes }} />
