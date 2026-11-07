@php
    $modules = config('navigation.modules', []);
    $sections = config('navigation.sections', []);

    $activeModule = null;
    $activeItem = null;

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
@endphp

@if ($activeModule || $activeItem)
    <nav aria-label="Context Breadcrumb" class="hidden xl:flex items-center gap-1.5 text-xs select-none">
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
            <span class="text-shell-foreground-active font-semibold truncate max-w-[120px] md:max-w-[180px] lg:max-w-[240px]">
                {{ $activeItem['label'] }}
            </span>
        @endif
    </nav>
@endif

