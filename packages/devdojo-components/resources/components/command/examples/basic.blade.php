@php
    $items = [
        ['group' => 'Suggestions', 'title' => 'Calendar', 'value' => 'calendar'],
        ['group' => 'Suggestions', 'title' => 'Search Docs', 'value' => 'docs'],
        ['group' => 'Settings', 'title' => 'Profile', 'value' => 'profile', 'shortcut' => '⌘P'],
        ['group' => 'Settings', 'title' => 'Billing', 'value' => 'billing', 'shortcut' => '⌘B'],
    ];
@endphp

<div class="flex min-h-72 w-full items-center justify-center">
    <x-components.command :items="$items" />
</div>
