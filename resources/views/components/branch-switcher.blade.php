@props(['fullWidth' => false, 'dark' => false, 'align' => 'left'])

@php
    $isDark = $dark || $fullWidth;
    $user = auth()->user();
    $roleName = $user?->role?->name;
    $isOwner = $roleName === 'Owner' || (bool) ($user?->role?->is_admin ?? false);
    $tenantId = app(\App\Services\Context\TenantContext::class)->getTenantId();
    $cacheKey = 'req_branch_switcher_stores_'.$user?->id.'_'.$tenantId;
    $stores = request()->attributes->get($cacheKey);
    if ($stores === null) {
        $storeResolver = app(\App\Services\Context\StoreResolver::class);
        $allowedStoreIds = $user && $tenantId ? $storeResolver->allowedStoreIdsFor($user, $tenantId) : [];
        $stores = ! empty($allowedStoreIds) 
            ? \App\Models\Store::whereIn('id', $allowedStoreIds)->orderBy('id')->get() 
            : collect();
        request()->attributes->set($cacheKey, $stores);
    }
    
    $currentSession = session('active_store_id');
    $isAll = ($currentSession === 'all' || $currentSession === null) && $isOwner;
    $currentStore = ! $isAll ? $stores->firstWhere('id', (int) $currentSession) : null;
    $activeLabel = $isAll ? 'All Branches (Consolidated)' : ($currentStore?->name ?? ($stores->first()?->name ?? 'Select Branch'));
@endphp

@if(count($stores) > 1 || $isOwner)
<div x-data="{ open: false }" class="relative {{ $fullWidth ? 'w-full block' : 'inline-block' }} text-left" @click.outside="open = false" @keydown.escape.window="open = false">
    {{-- Trigger Button --}}
    <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="true" aria-label="Select active branch"
        class="{{ $isDark ? 'inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium text-shell-foreground bg-shell-accent hover:bg-shell-border hover:text-shell-foreground-active border border-shell-border rounded-lg shadow-xs transition-all duration-150 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-shell-ring cursor-pointer' : 'inline-flex bg-card hover:bg-secondary border-border text-foreground items-center gap-2 px-3 py-1.5 text-xs font-medium rounded-lg border shadow-xs transition-all duration-150 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring cursor-pointer' }} {{ $fullWidth ? 'w-full justify-between' : '' }}">
        <div class="flex items-center gap-2 min-w-0">
            <span class="shrink-0 w-2 h-2 rounded-full {{ $isAll ? 'bg-warning' : 'bg-success' }}"></span>
            <x-ui.icon name="storefront" size="sm" class="{{ $isDark ? 'text-muted-foreground' : 'text-muted-foreground' }} shrink-0" />
            <span class="truncate text-left text-xs {{ $fullWidth ? 'max-w-[145px]' : 'max-w-[120px] sm:max-w-[180px] lg:max-w-[220px]' }}">{{ $activeLabel }}</span>
        </div>
        <x-ui.icon name="expand_more" size="sm" class="{{ $isDark ? 'text-muted-foreground' : 'text-muted-foreground' }} transition-transform duration-150 shrink-0" ::class="open ? 'rotate-180' : ''" />
    </button>

    {{-- Dropdown Menu --}}
    <div x-show="open" x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 translate-y-1"
        class="absolute {{ $align === 'right' ? 'right-0' : 'left-0' }} mt-2 w-76 {{ $isDark ? 'bg-shell text-shell-foreground-active border-shell-border' : 'bg-card text-foreground border-border' }} rounded-xl shadow-2xl border p-1.5 z-50 overflow-hidden">
        
        <div class="px-2.5 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground flex items-center justify-between border-b {{ $isDark ? 'border-shell-border' : 'border-border' }} mb-1">
            <span>Select Branch Scope</span>
            <span class="text-[9px] {{ $isDark ? 'bg-shell-accent text-muted-foreground border-shell-border' : 'bg-secondary text-muted-foreground border-border' }} font-medium px-1.5 py-0.5 rounded border">{{ count($stores) }} Branches</span>
        </div>

        <div class="max-h-72 overflow-y-auto space-y-0.5 pr-1 custom-scrollbar">
            @if($isOwner)
            {{-- All Stores (Consolidation) Option for Owner --}}
            <form action="{{ route('store.switch') }}" method="POST"
                @submit="if (window.apeironPosCartDirty && !confirm('You have an active order in the POS terminal. Switching branches will discard the active cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }">
                @csrf
                <input type="hidden" name="store_id" value="all">
                <button type="submit"
                    class="w-full text-left px-2.5 py-2 rounded-lg text-xs flex items-center justify-between transition-all duration-150 cursor-pointer {{ $isAll ? ($isDark ? 'bg-shell-accent text-shell-foreground-active border border-shell-border' : 'bg-warning-muted border border-warning-border text-warning') : ($isDark ? 'text-shell-foreground hover:bg-shell-accent hover:text-shell-foreground-active' : 'text-muted-foreground hover:bg-secondary hover:text-foreground') }}">
                    <div class="flex items-center gap-2.5 truncate">
                        <x-ui.icon name="domain" size="md" class="{{ $isAll ? 'text-warning' : 'text-warning' }}" />
                        <div class="truncate">
                            <p class="font-bold {{ $isAll ? 'text-warning' : ($isDark ? 'text-shell-foreground-active' : 'text-foreground') }}">All Branches (Consolidated)</p>
                            <p class="text-[10px] {{ $isAll ? 'text-warning' : 'text-muted-foreground' }}">Aggregated total across all {{ count($stores) }} branches</p>
                        </div>
                    </div>
                    @if($isAll)
                        <x-ui.icon name="check_circle" size="sm" class="text-warning" />
                    @endif
                </button>
            </form>
            <div class="border-t {{ $isDark ? 'border-shell-border' : 'border-border' }} my-1"></div>
            @endif

            {{-- Specific Stores List --}}
            @foreach($stores as $st)
                @php $isSelected = ! $isAll && (int)$currentSession === $st->id; @endphp
                <form action="{{ route('store.switch') }}" method="POST"
                    @submit="if (window.apeironPosCartDirty && !confirm('You have an active order in the POS terminal. Switching branches will discard the active cart. Continue?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }">
                    @csrf
                    <input type="hidden" name="store_id" value="{{ $st->id }}">
                    <button type="submit"
                        class="w-full text-left px-2.5 py-2 rounded-lg text-xs flex items-center justify-between transition-all duration-150 cursor-pointer {{ $isSelected ? 'bg-success-muted border border-success-border text-success font-semibold' : ($isDark ? 'text-shell-foreground hover:bg-shell-accent hover:text-shell-foreground-active' : 'text-muted-foreground hover:bg-secondary hover:text-foreground') }}">
                        <div class="flex items-center gap-2.5 truncate">
                            <x-ui.icon name="store" size="sm" class="{{ $isSelected ? 'text-success' : 'text-muted-foreground' }}" />
                            <div class="truncate">
                                <p class="truncate {{ $isSelected ? 'text-success font-semibold' : ($isDark ? 'text-shell-foreground-active' : 'text-foreground') }}">{{ $st->name }}</p>
                                <p class="text-[10px] text-muted-foreground truncate">{{ Str::limit($st->address, 34) }}</p>
                            </div>
                        </div>
                        @if($isSelected)
                            <x-ui.icon name="check_circle" size="sm" class="text-success" />
                        @endif
                    </button>
                </form>
            @endforeach
        </div>
    </div>
</div>
@endif
