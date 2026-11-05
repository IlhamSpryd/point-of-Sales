@php
    $user = auth()->user();
    $roleName = $user?->role?->name ?? 'Staff';
    $initials = strtoupper(substr($user?->name ?? 'U', 0, 1));
@endphp

<div x-data="{ open: false }" class="relative inline-block" {{ $attributes }}>
    {{-- Trigger Button (TailAdmin User Dropdown Style) --}}
    <button type="button"
        @click="open = !open"
        :aria-expanded="open.toString()"
        aria-haspopup="true"
        aria-label="Menu akun pengguna"
        class="flex items-center gap-2.5 p-1 rounded-lg text-left transition-all hover:bg-shell-accent focus:outline-none focus:ring-1 focus:ring-shell-ring cursor-pointer select-none">
        
        {{-- Avatar with Online Dot --}}
        <div class="relative w-8 h-8 rounded-full bg-shell-accent text-shell-foreground-active border border-shell-border flex items-center justify-center font-bold text-xs shrink-0">
            <span>{{ $initials }}</span>
            <span class="absolute bottom-0 right-0 w-2 h-2 rounded-full bg-success ring-2 ring-shell" aria-hidden="true"></span>
        </div>

        {{-- Name & Role --}}
        <div class="hidden sm:block text-left leading-tight min-w-0">
            <div class="text-xs font-semibold text-shell-foreground-active truncate max-w-[130px]">{{ $user?->name ?? 'User' }}</div>
            <div class="text-[10px] text-shell-foreground truncate">{{ $roleName }}</div>
        </div>

        {{-- Chevron Indicator --}}
        <span class="material-symbols-rounded text-sm text-shell-foreground transition-transform duration-200 hidden sm:inline-block"
            :class="open ? 'rotate-180 text-shell-foreground-active' : ''"
            aria-hidden="true">expand_more</span>
    </button>

    {{-- Dropdown Content Panel (TailAdmin Style) --}}
    <div x-show="open"
        x-cloak
        @click.outside="open = false"
        @keydown.escape.window="open = false"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 translate-y-1"
        class="absolute right-0 mt-2 w-64 rounded-xl bg-shell border border-shell-border shadow-2xl z-50 overflow-hidden text-xs text-shell-foreground">
        
        {{-- Profile Header Summary --}}
        <div class="px-4 py-3 border-b border-shell-border bg-shell">
            <p class="text-xs font-semibold text-shell-foreground-active truncate">{{ $user?->name ?? 'User' }}</p>
            <p class="text-[11px] text-shell-foreground truncate mt-0.5">{{ $user?->email ?? '' }}</p>
            <div class="mt-2 flex items-center gap-1.5">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-shell-accent text-success border border-success-border/30">
                    <span class="w-1.5 h-1.5 rounded-full bg-success mr-1.5"></span>
                    {{ $roleName }}
                </span>
            </div>
        </div>

        {{-- Menu Links --}}
        <div class="p-1.5 space-y-0.5">
            @if (\Illuminate\Support\Facades\Route::has('profile.edit'))
                <a href="{{ route('profile.edit') }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } open = false;"
                    class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium text-shell-foreground hover:text-shell-foreground-active hover:bg-shell-accent transition-colors">
                    <span class="material-symbols-rounded text-base text-shell-foreground">account_circle</span>
                    <span>My Profile</span>
                </a>
            @endif

            @if ($roleName === 'Owner' && \Illuminate\Support\Facades\Route::has('settings.index'))
                <a href="{{ route('settings.index') }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } open = false;"
                    class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium text-shell-foreground hover:text-shell-foreground-active hover:bg-shell-accent transition-colors">
                    <span class="material-symbols-rounded text-base text-shell-foreground">settings</span>
                    <span>System Settings</span>
                </a>
            @endif

            <div class="my-1 border-t border-shell-border"></div>

            {{-- Sign Out Action --}}
            <form method="POST" action="{{ route('logout') }}"
                @submit="if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Signing out will cancel the current cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }">
                @csrf
                <button type="submit"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium text-destructive hover:text-destructive hover:bg-destructive-muted/30 transition-colors cursor-pointer text-left">
                    <span class="material-symbols-rounded text-base">logout</span>
                    <span>Sign Out</span>
                </button>
            </form>
        </div>
    </div>
</div>
