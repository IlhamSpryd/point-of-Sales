@props(['fullWidth' => false])

@php
    $user = auth()->user();
    $roleName = $user?->role?->name;
    $isOwner = $roleName === 'Owner' || (bool) ($user?->role?->is_admin ?? false);
    $tenantId = app(\App\Services\Context\TenantContext::class)->getTenantId();
    $storeResolver = app(\App\Services\Context\StoreResolver::class);
    $allowedStoreIds = $user && $tenantId ? $storeResolver->allowedStoreIdsFor($user, $tenantId) : [];
    $stores = ! empty($allowedStoreIds) 
        ? \App\Models\Store::whereIn('id', $allowedStoreIds)->orderBy('id')->get() 
        : collect();
    
    $currentSession = session('active_store_id');
    $isAll = ($currentSession === 'all' || $currentSession === null) && $isOwner;
    $currentStore = ! $isAll ? $stores->firstWhere('id', (int) $currentSession) : null;
    $activeLabel = $isAll ? 'Semua Cabang (Konsolidasi)' : ($currentStore?->name ?? ($stores->first()?->name ?? 'Pilih Cabang'));
@endphp

@if(count($stores) > 1 || $isOwner)
<div x-data="{ open: false }" class="relative {{ $fullWidth ? 'w-full block' : 'inline-block' }} text-left" @click.outside="open = false" @keydown.escape.window="open = false">
    {{-- Trigger Button --}}
    <button type="button" @click="open = !open" :aria-expanded="open.toString()"
        class="{{ $fullWidth ? 'w-full justify-between' : 'inline-flex' }} items-center gap-2.5 px-3.5 py-2.5 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-apeiron-ink text-sm font-semibold rounded-xl shadow-sm transition-all duration-200 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F] cursor-pointer">
        <div class="flex items-center gap-2.5 min-w-0">
            <span class="shrink-0 w-2.5 h-2.5 rounded-full {{ $isAll ? 'bg-amber-500 shadow-[0_0_8px_rgba(245,158,11,0.6)] animate-pulse' : 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]' }}"></span>
            <span class="material-symbols-rounded text-[20px] text-[#787774] shrink-0">storefront</span>
            <span class="truncate text-left text-xs sm:text-sm {{ $fullWidth ? 'max-w-[150px]' : 'max-w-[180px] sm:max-w-[240px]' }}">{{ $activeLabel }}</span>
        </div>
        <span class="material-symbols-rounded text-[18px] text-[#9B9A97] transition-transform duration-200 shrink-0" :class="open ? 'rotate-180' : ''">expand_more</span>
    </button>

    {{-- Dropdown Menu --}}
    <div x-show="open" x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 translate-y-1"
        class="absolute {{ $fullWidth ? 'left-0' : 'right-0' }} mt-2 w-80 bg-white/95 backdrop-blur-xl rounded-2xl shadow-[0_12px_36px_rgba(0,0,0,0.12)] border border-[#E9E9E7] p-2 z-50 overflow-hidden">
        
        <div class="px-3 py-2 text-[11px] font-bold uppercase tracking-wider text-[#9B9A97] flex items-center justify-between border-b border-[#F1F1EF] mb-1">
            <span>Pilih Cakupan Cabang</span>
            <span class="text-[10px] bg-[#F1F1EF] text-[#787774] font-semibold px-2 py-0.5 rounded-full">{{ count($stores) }} Cabang</span>
        </div>

        <div class="max-h-72 overflow-y-auto space-y-1 pr-1 custom-scrollbar">
            @if($isOwner)
            {{-- All Stores (Konsolidasi) Option for Owner --}}
            <form action="{{ route('store.switch') }}" method="POST">
                @csrf
                <input type="hidden" name="store_id" value="all">
                <button type="submit"
                    class="w-full text-left px-3 py-2.5 rounded-xl text-xs flex items-center justify-between transition-all duration-150 cursor-pointer {{ $isAll ? 'bg-amber-50/80 border border-amber-200 text-amber-950 shadow-xs' : 'text-[#787774] hover:bg-[#F7F7F5] hover:text-apeiron-ink' }}">
                    <div class="flex items-center gap-2.5 truncate">
                        <span class="material-symbols-rounded text-[20px] {{ $isAll ? 'text-amber-600' : 'text-amber-500' }}">domain</span>
                        <div class="truncate">
                            <p class="font-bold {{ $isAll ? 'text-amber-950' : 'text-apeiron-ink' }}">Semua Cabang (Konsolidasi)</p>
                            <p class="text-[10px] {{ $isAll ? 'text-amber-700/80' : 'text-[#9B9A97]' }}">Total akumulasi seluruh 8 cabang</p>
                        </div>
                    </div>
                    @if($isAll)
                        <span class="material-symbols-rounded text-[18px] text-amber-600">check_circle</span>
                    @endif
                </button>
            </form>
            <div class="border-t border-[#F1F1EF] my-1"></div>
            @endif

            {{-- Specific Stores List --}}
            @foreach($stores as $st)
                @php $isSelected = ! $isAll && (int)$currentSession === $st->id; @endphp
                <form action="{{ route('store.switch') }}" method="POST">
                    @csrf
                    <input type="hidden" name="store_id" value="{{ $st->id }}">
                    <button type="submit"
                        class="w-full text-left px-3 py-2 rounded-xl text-xs flex items-center justify-between transition-all duration-150 cursor-pointer {{ $isSelected ? 'bg-emerald-50/80 border border-emerald-200 text-emerald-950 shadow-xs font-semibold' : 'text-[#787774] hover:bg-[#F7F7F5] hover:text-apeiron-ink' }}">
                        <div class="flex items-center gap-2.5 truncate">
                            <span class="material-symbols-rounded text-[19px] {{ $isSelected ? 'text-emerald-600' : 'text-[#9B9A97]' }}">store</span>
                            <div class="truncate">
                                <p class="truncate {{ $isSelected ? 'text-emerald-950 font-bold' : 'text-apeiron-ink font-medium' }}">{{ $st->name }}</p>
                                <p class="text-[10px] text-[#9B9A97] truncate">{{ Str::limit($st->address, 34) }}</p>
                            </div>
                        </div>
                        @if($isSelected)
                            <span class="material-symbols-rounded text-[18px] text-emerald-600">check_circle</span>
                        @endif
                    </button>
                </form>
            @endforeach
        </div>
    </div>
</div>
@endif
