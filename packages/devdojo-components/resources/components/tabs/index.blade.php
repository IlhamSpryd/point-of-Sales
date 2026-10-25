@props([
    'default' => null,
])

{{-- Tabbed content. Compose with <x-tabs.list>, <x-tabs.tab> and <x-tabs.panel>.
     The first tab is selected automatically unless `default` names another. --}}
<div
    x-data="{ activeTab: @js($default) }"
    x-id="['dd-tabs']"
    {{ $attributes->twMerge('w-full') }}
>
    {{ $slot }}
</div>
