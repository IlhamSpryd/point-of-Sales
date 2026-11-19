@php
    /**
     * REGISTRY-DRIVEN COMMAND PALETTE (ADR-NAV-008)
     *
     * The palette indexes exactly what the sidebar would show — no second list,
     * no first-writer-wins deduplication, no gated pages leaking into results.
     * Visibility is projected by NavigationVisibilityResolver, so an item can
     * never route the user to a 403.
     *
     * Aliases are bilingual (English + Indonesian) because Apeiron's labels are
     * English while operators search in Indonesian: "opname", "grn", "aset tetap".
     * Icons are injected as NAMES, not HTML, to keep the payload small.
     */
    /** @var \App\Platform\Navigation\NavigationVisibilityResolver $navResolver */
    $navResolver = app(\App\Platform\Navigation\NavigationVisibilityResolver::class);

    $paletteItems = [];

    foreach ($navResolver->commandPaletteItems(auth()->user()) as $item) {
        $paletteItems[] = [
            'title' => $item['label'],
            'value' => $item['id'],
            'url' => $item['url'],
            'group' => $item['group'],
            'icon' => $item['icon'],
            'haystack' => $item['haystack'],
            'kind' => $item['kind'],
        ];
    }
@endphp

<x-ui.command :items="$paletteItems" :headless="true" />
