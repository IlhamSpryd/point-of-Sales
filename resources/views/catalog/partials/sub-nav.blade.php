@php
    $roleName = auth()->user()?->role?->name;

    $allCatalogTabs = [
        [
            'label' => 'Produk',
            'route' => 'products.index',
            'active' => 'products.*',
            'icon' => 'inventory_2',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Kategori',
            'route' => 'categories.index',
            'active' => 'categories.*',
            'icon' => 'category',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Merek',
            'route' => 'brands.index',
            'active' => 'brands.*',
            'icon' => 'verified',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Satuan Ukur',
            'route' => 'units-of-measure.index',
            'active' => 'units-of-measure.*',
            'icon' => 'straighten',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Varian & Modifier',
            'route' => 'inventory.modifiers',
            'active' => 'inventory.modifiers',
            'icon' => 'tune',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Kelas Pajak',
            'route' => 'tax-classes.index',
            'active' => 'tax-classes.*',
            'icon' => 'percent',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Asortimen Cabang',
            'route' => 'assortments.index',
            'active' => 'assortments.*',
            'icon' => 'storefront',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Daftar Harga',
            'route' => 'price-lists.index',
            'active' => 'price-lists.*',
            'icon' => 'price_change',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
    ];

    $visibleCatalogTabs = array_filter($allCatalogTabs, function ($tab) use ($roleName) {
        return in_array($roleName, $tab['roles'], true) && \Illuminate\Support\Facades\Route::has($tab['route']);
    });
@endphp

<div class="w-full pb-3 mb-6 border-b border-[#E5E7EB]">
    <nav aria-label="Sub-navigasi Katalog Produk"
        x-data
        x-init="$nextTick(() => {
            const activeTab = $el.querySelector('[aria-current=page]');
            if (activeTab) {
                const offset = activeTab.offsetLeft - ($el.clientWidth / 2) + (activeTab.clientWidth / 2);
                $el.scrollTo({ left: Math.max(0, offset), behavior: 'instant' });
            }
        })"
        class="flex items-center gap-1.5 md:gap-2 overflow-x-auto scrollbar-hide py-1">
        @foreach ($visibleCatalogTabs as $tab)
            @php
                $isActive = request()->routeIs($tab['active']);
            @endphp
            <a href="{{ route($tab['route']) }}" wire:navigate
                @click="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }"
                @if ($isActive) aria-current="page" @endif
                style="{{ $isActive ? 'background-color: #202020; color: #ffffff;' : '' }}"
                class="inline-flex items-center gap-2 px-3.5 py-2 min-h-[40px] rounded-xl text-xs font-semibold whitespace-nowrap transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-apeiron-ink {{ $isActive ? 'catalog-nav-tab-active bg-apeiron-ink text-white shadow-sm' : 'catalog-nav-tab-inactive text-[#787774] hover:text-apeiron-ink hover:bg-[#F7F7F5]' }}">
                <span class="material-symbols-rounded text-[18px] shrink-0 {{ $isActive ? 'text-white' : '' }}">{{ $tab['icon'] }}</span>
                <span class="{{ $isActive ? 'text-white' : '' }}">{{ $tab['label'] }}</span>
            </a>
        @endforeach
    </nav>
</div>
