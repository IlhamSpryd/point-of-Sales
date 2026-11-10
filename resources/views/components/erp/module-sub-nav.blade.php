@props([
    'module',
    'ariaLabel' => null,
])

@php
    $user = auth()->user();
    $roleName = $user?->role?->name;
    $canonicalRoles = ['Owner', 'Admin', 'Manager', 'Finance', 'Sales', 'Kasir', 'Waiter', 'Barista', 'Inventory', 'Supervisor', 'Cook'];
    $isCustomRole = $roleName !== null && ! in_array($roleName, $canonicalRoles, true);

    $moduleConfig = config("navigation.sub_nav.{$module}", []);
    $resolvedAriaLabel = $ariaLabel
        ?? ($moduleConfig['aria_label'] ?? (($moduleConfig['label'] ?? ucfirst((string) $module)).' Navigation'));

    $allTabs = $moduleConfig['items'] ?? [];

    $visibleTabs = array_values(array_filter($allTabs, function (array $tab) use ($user, $roleName, $isCustomRole): bool {
        if (empty($tab['route']) || ! \Illuminate\Support\Facades\Route::has($tab['route'])) {
            return false;
        }

        if ($roleName === null) {
            return false;
        }

        $allowedRoles = $tab['roles'] ?? [];
        if (in_array($roleName, $allowedRoles, true)) {
            return true;
        }

        if ($isCustomRole && ! empty($tab['permission']) && $user !== null) {
            return $user->hasPermission((string) $tab['permission']);
        }

        return false;
    }));
@endphp

@if (count($visibleTabs) > 0)
    <div class="w-full pb-3 mb-6 border-b border-border">
        <nav aria-label="{{ $resolvedAriaLabel }}"
            x-data
            x-init="$nextTick(() => {
                const activeTab = $el.querySelector('[aria-current=page]');
                if (activeTab) {
                    const offset = activeTab.offsetLeft - ($el.clientWidth / 2) + (activeTab.clientWidth / 2);
                    $el.scrollTo({ left: Math.max(0, offset), behavior: 'instant' });
                }
            })"
            class="flex items-center gap-1.5 md:gap-2 overflow-x-auto scrollbar-hide py-1">
            @foreach ($visibleTabs as $tab)
                @php
                    $activePatterns = (array) ($tab['active'] ?? $tab['route']);
                    $isActive = request()->routeIs(...$activePatterns);
                @endphp
                <a href="{{ route($tab['route']) }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('You have an active order in the POS terminal. Navigating away will discard the active cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }"
                    @if ($isActive) aria-current="page" @endif
                    class="inline-flex items-center px-3.5 py-2 min-h-[40px] rounded-lg text-xs font-medium whitespace-nowrap transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring {{ $isActive ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground hover:bg-muted' }}">
                    <span>{{ $tab['label'] }}</span>
                </a>
            @endforeach
        </nav>
    </div>
@endif

