@php
    $modules = config('navigation.modules', []);
    $sections = config('navigation.sections', []);
    $subNavs = config('navigation.sub_nav', []);

    $activeModule = null;
    $activeItem = null;
    $activeSubItem = null;

    // Detect active module and active menu item
    foreach ($modules as $mod) {
        $patterns = (array) ($mod['active_pattern'] ?? []);
        if (!empty($patterns) && request()->routeIs(...$patterns)) {
            $activeModule = $mod;
            break;
        }
    }

    foreach ($sections as $sectionName => $items) {
        foreach ($items as $item) {
            if (\Illuminate\Support\Facades\Route::has($item['route']) && request()->routeIs(...(array) $item['active'])) {
                $activeItem = $item;
                break 2;
            }
        }
    }

    foreach ($subNavs as $subModuleKey => $subModuleConfig) {
        foreach ($subModuleConfig['items'] ?? [] as $subItem) {
            if (!empty($subItem['route']) && \Illuminate\Support\Facades\Route::has($subItem['route']) && request()->routeIs(...(array) ($subItem['active'] ?? $subItem['route']))) {
                $activeSubItem = $subItem;
                break 2;
            }
        }
    }
@endphp

@if ($activeModule || $activeItem || $activeSubItem)
    <nav aria-label="Context Breadcrumb" class="hidden md:flex items-center gap-1.5 text-xs select-none">
        <a href="{{ route('home') }}" wire:navigate
            class="text-shell-foreground hover:text-shell-foreground-active transition-colors flex items-center">
            <span class="material-symbols-rounded text-[16px]">home</span>
        </a>

        @if ($activeModule)
            <span class="text-shell-foreground/40 font-sans text-[11px] select-none">•</span>
            @if (\Illuminate\Support\Facades\Route::has($activeModule['route']))
                <a href="{{ route($activeModule['route']) }}" wire:navigate
                    class="text-shell-foreground hover:text-shell-foreground-active transition-colors font-medium">
                    {{ $activeModule['short_name'] ?? $activeModule['name'] }}
                </a>
            @else
                <span class="text-shell-foreground">{{ $activeModule['short_name'] ?? $activeModule['name'] }}</span>
            @endif
        @endif

        @if ($activeItem && (!$activeModule || $activeItem['label'] !== ($activeModule['short_name'] ?? $activeModule['name'])))
            <span class="text-shell-foreground/40 font-sans text-[11px] select-none">/</span>
            @if ($activeSubItem && $activeSubItem['route'] !== $activeItem['route'] && $activeSubItem['label'] !== $activeItem['label'])
                <a href="{{ route($activeItem['route']) }}" wire:navigate
                    class="text-shell-foreground hover:text-shell-foreground-active transition-colors font-medium truncate max-w-[120px] lg:max-w-[180px]">
                    {{ $activeItem['label'] }}
                </a>
            @else
                <span class="text-shell-foreground-active font-semibold truncate max-w-[120px] md:max-w-[180px] lg:max-w-[240px]">
                    {{ $activeItem['label'] }}
                </span>
            @endif
        @endif

        @if ($activeSubItem && (!$activeItem || ($activeSubItem['route'] !== $activeItem['route'] && $activeSubItem['label'] !== $activeItem['label'])))
            <span class="text-shell-foreground/40 font-sans text-[11px] select-none">/</span>
            <span class="text-shell-foreground-active font-semibold truncate max-w-[120px] md:max-w-[180px] lg:max-w-[240px]">
                {{ $activeSubItem['label'] }}
            </span>
        @endif
    </nav>
@endif
