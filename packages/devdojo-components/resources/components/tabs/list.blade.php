{{-- The segmented control that holds the tab buttons.
     Arrow keys move between tabs (wrapping); Home/End jump to the first/last tab. --}}
<div
    role="tablist"
    x-data="{
        focusTab(target) {
            const tabs = Array.from($el.querySelectorAll('[role=tab]'));
            if (! tabs.length) return;
            const current = tabs.indexOf(document.activeElement);
            const index = target === 'first' ? 0
                : target === 'last' ? tabs.length - 1
                : (Math.max(current, 0) + (target === 'next' ? 1 : tabs.length - 1)) % tabs.length;
            tabs[index].click();
            tabs[index].focus();
        },
    }"
    @keydown.arrow-right.prevent="focusTab('next')"
    @keydown.arrow-left.prevent="focusTab('prev')"
    @keydown.home.prevent="focusTab('first')"
    @keydown.end.prevent="focusTab('last')"
    {{ $attributes->twMerge('inline-flex items-center gap-1 rounded-medium bg-secondary p-1') }}
>
    {{ $slot }}
</div>
