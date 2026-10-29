@props(['fullWidth' => false, 'dark' => false])

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
    $activeLabel = $isAll ? 'Semua Cabang (Konsolidasi)' : ($currentStore?->name ?? ($stores->first()?->name ?? 'Pilih Cabang'));
@endphp

@if(count($stores) > 1 || $isOwner)
<div x-data="{ open: false }" class="relative {{ $fullWidth ? 'w-full block' : 'inline-block' }} text-left" @click.outside="open = false" @keydown.escape.window="open = false">
    {{-- Trigger Button --}}
    <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="true" aria-label="Pilih cabang aktif"
        class="{{ $isDark ? 'justify-between bg-[#202020] hover:bg-[#262626] border-[#2E2E2E] text-white' : 'inline-flex bg-white hover:bg-[#F9FAFB] border-[#E5E7EB] text-[#202123]' }} items-center gap-2 px-3 py-1.5 text-xs font-medium rounded-lg border shadow-xs transition-all duration-150 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white cursor-pointer {{ $fullWidth ? 'w-full' : '' }}">
        <div class="flex items-center gap-2 min-w-0">
            <span class="shrink-0 w-2 h-2 rounded-full {{ $isAll ? 'bg-amber-400' : 'bg-emerald-400' }}"></span>
            <span class="material-symbols-rounded text-[17px] {{ $isDark ? 'text-[#A3A3A3]' : 'text-[#6B7280]' }} shrink-0">storefront</span>
            <span class="truncate text-left text-xs {{ $fullWidth ? 'max-w-[145px]' : 'max-w-[180px] sm:max-w-[240px]' }}">{{ $activeLabel }}</span>
        </div>
        <span class="material-symbols-rounded text-[16px] text-[#737373] transition-transform duration-150 shrink-0" :class="open ? 'rotate-180' : ''">expand_more</span>
    </button>

    {{-- Dropdown Menu --}}
    <div x-show="open" x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 translate-y-1"
        class="absolute {{ $fullWidth ? 'left-0' : 'right-0' }} mt-2 w-76 {{ $isDark ? 'bg-[#1E1E1E] text-white border-[#333333]' : 'bg-white text-[#202123] border-[#E5E7EB]' }} rounded-xl shadow-xl border p-1.5 z-50 overflow-hidden">
        
        <div class="px-2.5 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-[#737373] flex items-center justify-between border-b {{ $isDark ? 'border-[#2E2E2E]' : 'border-[#E5E7EB]' }} mb-1">
            <span>Pilih Cakupan Cabang</span>
            <span class="text-[9px] {{ $isDark ? 'bg-[#2A2A2A] text-[#A3A3A3] border-[#383838]' : 'bg-[#F3F4F6] text-[#6B7280] border-[#E5E7EB]' }} font-medium px-1.5 py-0.5 rounded border">{{ count($stores) }} Cabang</span>
        </div>

        <div class="max-h-72 overflow-y-auto space-y-0.5 pr-1 custom-scrollbar">
            @if($isOwner)
            {{-- All Stores (Konsolidasi) Option for Owner --}}
            <form action="{{ route('store.switch') }}" method="POST"
                @submit="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah cabang akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }">
                @csrf
                <input type="hidden" name="store_id" value="all">
                <button type="submit"
                    class="w-full text-left px-2.5 py-2 rounded-lg text-xs flex items-center justify-between transition-all duration-150 cursor-pointer {{ $isAll ? ($isDark ? 'bg-[#2A2A2A] text-white border border-[#383838]' : 'bg-amber-50 border border-amber-200 text-amber-950') : ($isDark ? 'text-[#D4D4D4] hover:bg-[#262626] hover:text-white' : 'text-[#6B7280] hover:bg-[#F3F4F6] hover:text-[#202123]') }}">
                    <div class="flex items-center gap-2.5 truncate">
                        <span class="material-symbols-rounded text-[20px] {{ $isAll ? 'text-amber-600' : 'text-amber-500' }}">domain</span>
                        <div class="truncate">
                            <p class="font-bold {{ $isAll ? ($isDark ? 'text-amber-300' : 'text-amber-950') : ($isDark ? 'text-white' : 'text-apeiron-ink') }}">Semua Cabang (Konsolidasi)</p>
                            <p class="text-[10px] {{ $isAll ? ($isDark ? 'text-amber-400/80' : 'text-amber-700/80') : 'text-[#9B9A97]' }}">Total akumulasi seluruh {{ count($stores) }} cabang</p>
                        </div>
                    </div>
                    @if($isAll)
                        <span class="material-symbols-rounded text-[18px] text-amber-500">check_circle</span>
                    @endif
                </button>
            </form>
            <div class="border-t {{ $isDark ? 'border-[#2E2E2E]' : 'border-[#E5E7EB]' }} my-1"></div>
            @endif

            {{-- Specific Stores List --}}
            @foreach($stores as $st)
                @php $isSelected = ! $isAll && (int)$currentSession === $st->id; @endphp
                <form action="{{ route('store.switch') }}" method="POST"
                    @submit="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah cabang akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }">
                    @csrf
                    <input type="hidden" name="store_id" value="{{ $st->id }}">
                    <button type="submit"
                        class="w-full text-left px-2.5 py-2 rounded-lg text-xs flex items-center justify-between transition-all duration-150 cursor-pointer {{ $isSelected ? ($isDark ? 'bg-[#2A2A2A] text-white border border-[#383838] font-semibold' : 'bg-emerald-50 border border-emerald-200 text-emerald-950 font-semibold') : ($isDark ? 'text-[#D4D4D4] hover:bg-[#262626] hover:text-white' : 'text-[#6B7280] hover:bg-[#F3F4F6] hover:text-[#202123]') }}">
                        <div class="flex items-center gap-2.5 truncate">
                            <span class="material-symbols-rounded text-[18px] {{ $isSelected ? 'text-emerald-400' : 'text-[#737373]' }}">store</span>
                            <div class="truncate">
                                <p class="truncate {{ $isSelected ? 'text-white font-semibold' : ($fullWidth ? 'text-[#D4D4D4]' : 'text-[#202123]') }}">{{ $st->name }}</p>
                                <p class="text-[10px] text-[#737373] truncate">{{ Str::limit($st->address, 34) }}</p>
                            </div>
                        </div>
                        @if($isSelected)
                            <span class="material-symbols-rounded text-[17px] text-emerald-400">check_circle</span>
                        @endif
                    </button>
                </form>
            @endforeach
        </div>
    </div>
</div>
@endif
