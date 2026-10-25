@props(['size' => null, 'minSize' => null])

{{-- A single resizable pane. The dd-split-pane class is the hook the parent
     splitter queries to wire up Split.js; data-size sets its initial percentage. --}}
<div
    @if ($size) data-size="{{ $size }}" @endif
    @if (! is_null($minSize)) data-min-size="{{ $minSize }}" @endif
    {{ $attributes->twMerge('dd-split-pane h-full w-full min-h-0 min-w-0') }}
    wire:ignore.self
>
    {{ $slot }}
</div>
