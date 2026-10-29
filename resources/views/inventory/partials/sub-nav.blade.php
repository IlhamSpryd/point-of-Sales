@php
    $roleName = auth()->user()?->role?->name;

    $allInventoryTabs = [
        [
            'label' => 'Bahan Baku',
            'route' => 'inventory.ingredients',
            'active' => 'inventory.ingredients*',
            'icon' => 'egg_alt',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Penerimaan Barang',
            'route' => 'inventory.goods-receipts',
            'active' => 'inventory.goods-receipts*',
            'icon' => 'input',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Penyesuaian Stok',
            'route' => 'inventory.adjustments',
            'active' => 'inventory.adjustments*',
            'icon' => 'tune',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Stock Opname',
            'route' => 'inventory.opnames',
            'active' => 'inventory.opnames*',
            'icon' => 'fact_check',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Transfer Antarcabang',
            'route' => 'inventory.transfers',
            'active' => 'inventory.transfers*',
            'icon' => 'local_shipping',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Retur Supplier',
            'route' => 'inventory.supplier-returns',
            'active' => 'inventory.supplier-returns*',
            'icon' => 'keyboard_return',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Riwayat Mutasi',
            'route' => 'inventory.ledger',
            'active' => 'inventory.ledger*',
            'icon' => 'history',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
    ];

    $visibleInventoryTabs = array_filter($allInventoryTabs, function ($tab) use ($roleName) {
        return in_array($roleName, $tab['roles'], true) && \Illuminate\Support\Facades\Route::has($tab['route']);
    });
@endphp

<div class="w-full pb-3 mb-6 border-b border-[#E5E7EB]">
    <nav aria-label="Sub-navigasi Manajemen Inventori"
        x-data
        x-init="$nextTick(() => {
            const activeTab = $el.querySelector('[aria-current=page]');
            if (activeTab) {
                const offset = activeTab.offsetLeft - ($el.clientWidth / 2) + (activeTab.clientWidth / 2);
                $el.scrollTo({ left: Math.max(0, offset), behavior: 'instant' });
            }
        })"
        class="flex items-center gap-1.5 md:gap-2 overflow-x-auto scrollbar-hide py-1">
        @foreach ($visibleInventoryTabs as $tab)
            @php
                $isActive = request()->routeIs($tab['active']);
            @endphp
            <a href="{{ route($tab['route']) }}" wire:navigate
                @click="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }"
                @if ($isActive) aria-current="page" @endif
                style="{{ $isActive ? 'background-color: #202020; color: #ffffff;' : '' }}"
                class="inline-flex items-center gap-2 px-3.5 py-2 min-h-[40px] rounded-xl text-xs font-semibold whitespace-nowrap transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-apeiron-ink {{ $isActive ? 'inventory-nav-tab-active bg-apeiron-ink text-white shadow-sm' : 'inventory-nav-tab-inactive text-[#787774] hover:text-apeiron-ink hover:bg-[#F7F7F5]' }}">
                <span class="material-symbols-rounded text-[18px] shrink-0 {{ $isActive ? 'text-white' : '' }}">{{ $tab['icon'] }}</span>
                <span class="{{ $isActive ? 'text-white' : '' }}">{{ $tab['label'] }}</span>
            </a>
        @endforeach
    </nav>
</div>
