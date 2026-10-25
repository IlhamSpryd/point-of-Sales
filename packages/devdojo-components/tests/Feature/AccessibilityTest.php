<?php

use Illuminate\Support\Facades\Blade;

it('renders tabs with the full WAI-ARIA tabs pattern', function () {
    $html = Blade::render(<<<'BLADE'
        <x-components.tabs>
            <x-components.tabs.list>
                <x-components.tabs.tab tab="account">Account</x-components.tabs.tab>
                <x-components.tabs.tab tab="Team Settings">Team</x-components.tabs.tab>
            </x-components.tabs.list>
            <x-components.tabs.panel tab="account">A</x-components.tabs.panel>
            <x-components.tabs.panel tab="Team Settings">B</x-components.tabs.panel>
        </x-components.tabs>
        BLADE);

    expect($html)
        // Instance-scoped ids link each tab to its panel (and back).
        ->toContain("x-id=\"['dd-tabs']\"")
        ->toContain(':id="$id(\'dd-tabs\') + \'-tab-account\'"')
        ->toContain(':aria-controls="$id(\'dd-tabs\') + \'-panel-account\'"')
        ->toContain(':id="$id(\'dd-tabs\') + \'-panel-team-settings\'"')
        ->toContain(':aria-labelledby="$id(\'dd-tabs\') + \'-tab-team-settings\'"')
        // Roving tabindex: only the active tab is in the tab order.
        ->toContain(':tabindex="activeTab ===')
        // Arrow/Home/End keyboard support on the tablist.
        ->toContain('@keydown.arrow-right.prevent')
        ->toContain('@keydown.arrow-left.prevent')
        ->toContain('@keydown.home.prevent')
        ->toContain('@keydown.end.prevent');
});

it('renders the modal as a dialog labelled by its header', function () {
    $html = Blade::render(<<<'BLADE'
        <x-components.modal id="confirm-thing">
            <x-slot:header>Confirm changes</x-slot:header>
            <x-slot:content>Are you sure?</x-slot:content>
        </x-components.modal>
        BLADE);

    expect($html)->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('aria-labelledby="confirm-thing-title"')
        ->toContain('<h2 id="confirm-thing-title"');
});

it('omits aria-labelledby on a headerless modal', function () {
    $html = Blade::render(<<<'BLADE'
        <x-components.modal>
            <x-slot:content>Body only.</x-slot:content>
        </x-components.modal>
        BLADE);

    expect($html)->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->not->toContain('aria-labelledby');
});

it('renders the toggle as a switch with bound aria-checked', function () {
    $html = Blade::render('<x-components.toggle label="Notifications" checked />');

    expect($html)->toContain('type="checkbox"')
        ->toContain('role="switch"')
        ->toContain('aria-checked="true"')
        ->toContain('x-bind:aria-checked="checked ? \'true\' : \'false\'"');

    expect(Blade::render('<x-components.toggle label="Notifications" />'))
        ->toContain('aria-checked="false"');
});

it('renders the toast stack as a polite live region with a real dismiss button', function () {
    $html = Blade::render('<x-components.toast />');

    expect($html)->toContain('aria-live="polite"')
        // A single polite region — no per-toast role="alert" double announcement.
        ->not->toContain('role="alert"')
        ->toContain('aria-label="Dismiss notification"')
        ->toContain('<button type="button" x-on:click="removeToast(toast.id)"');
});
