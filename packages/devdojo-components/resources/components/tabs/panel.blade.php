@props([
    'tab' => null,
])

@php
    $tabKey = \Illuminate\Support\Str::slug((string) $tab) ?: substr(md5((string) $tab), 0, 8);
@endphp

{{-- The content shown while its matching tab is active. --}}
<div
    role="tabpanel"
    :id="$id('dd-tabs') + '-panel-{{ $tabKey }}'"
    :aria-labelledby="$id('dd-tabs') + '-tab-{{ $tabKey }}'"
    x-show="activeTab === @js($tab)"
    x-cloak
    {{ $attributes->twMerge('mt-3') }}
>
    {{ $slot }}
</div>
