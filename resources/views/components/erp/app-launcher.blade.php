@php
    $roleName = auth()->user()?->role?->name;
    $allModules = config('navigation.modules', []);

    $visibleModules = array_values(array_filter($allModules, function ($mod) use ($roleName) {
        if (empty($mod['roles'])) {
            return true;
        }
        return in_array($roleName, $mod['roles'], true);
    }));

    // Detect currently active module
    $currentModuleName = 'Apeiron ERP';
    $currentModuleIcon = 'apps';
    foreach ($visibleModules as $mod) {
        $patterns = (array) ($mod['active_pattern'] ?? []);
        if (!empty($patterns) && request()->routeIs(...$patterns)) {
            $currentModuleName = $mod['short_name'] ?? $mod['name'];
            $currentModuleIcon = $mod['icon'];
            break;
        }
    }
@endphp

<div x-data="{
        launcherOpen: false,
        searchQuery: '',
        toggle() {
            this.launcherOpen = !this.launcherOpen;
            if (this.launcherOpen) {
                this.$nextTick(() => {
                    const input = this.$el.querySelector('input[type=search]');
                    if (input) input.focus();
                });
            }
        },
        close() {
            this.launcherOpen = false;
            this.searchQuery = '';
        }
    }"
    @keydown.escape.window="close()"
    @keydown.alt.a.window.prevent="toggle()"
    class="relative select-none">

    {{-- App Launcher Trigger Button (Odoo-Style Waffle Hub) --}}
    <button type="button"
        @click="toggle()"
        :aria-expanded="launcherOpen.toString()"
        aria-haspopup="dialog"
        aria-label="Open ERP App Launcher"
        title="Open App Launcher (Alt+A)"
        class="inline-flex items-center gap-2 px-2.5 py-1.5 text-xs font-semibold text-shell-foreground bg-shell-accent hover:bg-shell-border hover:text-shell-foreground-active border border-shell-border rounded-lg transition-all focus:outline-none focus:ring-1 focus:ring-shell-ring cursor-pointer group shadow-xs">
        <span class="material-symbols-rounded text-[18px] text-shell-foreground-active transition-transform group-hover:scale-110" aria-hidden="true">apps</span>
        <span class="hidden md:inline-flex items-center gap-1.5 max-w-[130px] truncate text-[12px]">
            <span class="truncate">{{ $currentModuleName }}</span>
        </span>
        <span class="material-symbols-rounded text-xs text-shell-foreground transition-transform duration-200"
              :class="{ 'rotate-180 text-shell-foreground-active': launcherOpen }">expand_more</span>
    </button>

    {{-- Backdrop (Scrim) for Modal --}}
    <div x-show="launcherOpen"
        x-cloak
        x-transition:enter="transition-opacity ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="close()"
        class="fixed inset-0 z-40 bg-black/60 backdrop-blur-[2px]"></div>

    {{-- Odoo-Style App Grid Modal --}}
    <div x-show="launcherOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 -translate-y-2"
        role="dialog"
        aria-modal="true"
        aria-label="ERP Application Launcher"
        class="fixed top-16 left-4 right-4 sm:left-auto sm:right-auto sm:left-1/2 sm:-translate-x-1/2 z-50 w-full sm:max-w-2xl lg:max-w-3xl bg-shell text-shell-foreground border border-shell-border rounded-2xl shadow-2xl overflow-hidden divide-y divide-shell-border">

        {{-- Modal Header & Instant Filter --}}
        <div class="p-3.5 sm:p-4 bg-shell flex items-center gap-3">
            <span class="material-symbols-rounded text-[20px] text-shell-foreground-active shrink-0">search</span>
            <input type="search"
                x-model="searchQuery"
                placeholder="Filter ERP apps & modules (e.g. POS, Inventory, HR, Finance)…"
                class="w-full bg-transparent text-sm text-shell-foreground-active placeholder:text-shell-foreground/60 border-0 focus:outline-none focus:ring-0 p-0" />
            <kbd class="hidden sm:inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] font-mono text-shell-foreground bg-shell-accent border border-shell-border rounded">
                <span>ESC</span> to exit
            </kbd>
        </div>

        {{-- Modular App Grid (Odoo Enterprise Pattern) --}}
        <div class="p-4 sm:p-6 max-h-[65vh] overflow-y-auto scrollbar-hide bg-shell">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                @foreach ($visibleModules as $module)
                    @php
                        $routeExists = \Illuminate\Support\Facades\Route::has($module['route']);
                        $patterns = (array) ($module['active_pattern'] ?? []);
                        $isModActive = !empty($patterns) && request()->routeIs(...$patterns);
                    @endphp
                    <div x-show="!searchQuery || '{{ strtolower($module['name'] . ' ' . $module['short_name'] . ' ' . $module['description'] . ' ' . $module['badge']) }}'.includes(searchQuery.toLowerCase())"
                        class="h-full">
                        @if ($routeExists)
                            <a href="{{ route($module['route']) }}" wire:navigate
                                @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } close();"
                                class="flex flex-col h-full p-3.5 rounded-xl border transition-all duration-150 group cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-shell-ring {{ $isModActive ? 'bg-shell-accent border-shell-foreground-active/40 shadow-sm ring-1 ring-shell-foreground-active/30' : 'bg-shell hover:bg-shell-accent/80 border-shell-border hover:border-shell-border/80' }}">
                                <div class="flex items-start justify-between mb-2.5">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center transition-transform group-hover:scale-105 {{ $isModActive ? 'bg-shell-accent border border-shell-border text-shell-foreground-active' : 'bg-shell-accent/70 text-shell-foreground group-hover:text-shell-foreground-active border border-shell-border/80' }}">
                                        <span class="material-symbols-rounded text-[22px]">{{ $module['icon'] }}</span>
                                    </div>
                                    @if (!empty($module['badge']))
                                        <span class="text-[10px] font-semibold tracking-wide uppercase px-2 py-0.5 rounded-full {{ $isModActive ? 'bg-shell-accent text-shell-foreground-active border border-shell-border' : 'bg-shell-accent text-shell-foreground border border-shell-border' }}">
                                            {{ $module['badge'] }}
                                        </span>
                                    @endif
                                </div>
                                <h3 class="text-xs font-bold text-shell-foreground-active truncate group-hover:text-shell-foreground-active">
                                    {{ $module['name'] }}
                                </h3>
                                <p class="text-[11px] text-shell-foreground line-clamp-2 mt-1 leading-snug">
                                    {{ $module['description'] }}
                                </p>
                            </a>
                        @else
                            <div class="flex flex-col h-full p-3.5 rounded-xl border border-shell-border/50 bg-shell-accent/20 opacity-60 cursor-not-allowed">
                                <div class="flex items-start justify-between mb-2.5">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-shell-accent/40 text-shell-foreground border border-shell-border/40">
                                        <span class="material-symbols-rounded text-[22px]">{{ $module['icon'] }}</span>
                                    </div>
                                    <span class="text-[10px] font-semibold tracking-wide uppercase px-2 py-0.5 rounded-full bg-shell-accent text-shell-foreground border border-shell-border">
                                        Soon
                                    </span>
                                </div>
                                <h3 class="text-xs font-bold text-shell-foreground truncate">
                                    {{ $module['name'] }}
                                </h3>
                                <p class="text-[11px] text-shell-foreground line-clamp-2 mt-1 leading-snug">
                                    {{ $module['description'] }}
                                </p>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Quick Launch Bar at Bottom --}}
        <div class="px-4 py-3 bg-shell flex items-center justify-between text-xs text-shell-foreground">
            <div class="flex items-center gap-3">
                <span class="text-[11px] font-semibold text-shell-foreground uppercase tracking-wider">Fast Lane:</span>
                <a href="{{ route('transaction.create') }}" wire:navigate @click="close()" class="hover:text-shell-foreground-active flex items-center gap-1 font-medium">
                    <span class="material-symbols-rounded text-[14px]">point_of_sale</span> POS
                </a>
                <span class="text-shell-border">•</span>
                <a href="{{ route('orders.index') }}" wire:navigate @click="close()" class="hover:text-shell-foreground-active flex items-center gap-1 font-medium">
                    <span class="material-symbols-rounded text-[14px]">receipt_long</span> Orders
                </a>
                <span class="text-shell-border">•</span>
                <a href="{{ route('kds.index') }}" wire:navigate @click="close()" class="hover:text-shell-foreground-active flex items-center gap-1 font-medium">
                    <span class="material-symbols-rounded text-[14px]">countertops</span> KDS
                </a>
                <span class="text-shell-border">•</span>
                <a href="{{ route('inventory.ingredients') }}" wire:navigate @click="close()" class="hover:text-shell-foreground-active flex items-center gap-1 font-medium">
                    <span class="material-symbols-rounded text-[14px]">warehouse</span> Stock
                </a>
            </div>
            <span class="hidden md:inline text-[11px] text-shell-foreground">Press <kbd class="px-1 py-0.5 font-mono text-[10px] bg-shell-accent border border-shell-border rounded">Alt+A</kbd> anytime</span>
        </div>
    </div>
</div>

