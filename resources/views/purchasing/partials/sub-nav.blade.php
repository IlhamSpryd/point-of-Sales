@php
    $roleName = auth()->user()?->role?->name;

    $allPurchasingTabs = [
        [
            'label' => 'Suppliers',
            'route' => 'purchasing.suppliers.index',
            'active' => 'purchasing.suppliers.*',
            'icon' => 'local_shipping',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Purchase Orders (PO)',
            'route' => 'purchasing.orders.index',
            'active' => 'purchasing.orders.*',
            'icon' => 'shopping_bag',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Goods Receipt (GRN)',
            'route' => 'purchasing.grns.index',
            'active' => 'purchasing.grns.*',
            'icon' => 'input',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Supplier Invoices (3-Way)',
            'route' => 'purchasing.invoices.index',
            'active' => 'purchasing.invoices.*',
            'icon' => 'receipt',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Purchase Returns',
            'route' => 'purchasing.returns.index',
            'active' => 'purchasing.returns.*',
            'icon' => 'keyboard_return',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
    ];

    $visiblePurchasingTabs = array_filter($allPurchasingTabs, function ($tab) use ($roleName) {
        return in_array($roleName, $tab['roles'], true);
    });
@endphp

<div class="w-full pb-3 mb-6 border-b border-[#E5E7EB]">
    <nav aria-label="Purchasing Navigation"
        x-data
        x-init="$nextTick(() => {
            const activeTab = $el.querySelector('[aria-current=page]');
            if (activeTab) {
                const offset = activeTab.offsetLeft - ($el.clientWidth / 2) + (activeTab.clientWidth / 2);
                $el.scrollTo({ left: Math.max(0, offset), behavior: 'instant' });
            }
        })"
        class="flex items-center gap-1.5 md:gap-2 overflow-x-auto scrollbar-hide py-1">
        @foreach ($visiblePurchasingTabs as $tab)
            @php
                $routeExists = \Illuminate\Support\Facades\Route::has($tab['route']);
                $isActive = $routeExists && request()->routeIs($tab['active']);
            @endphp
            @if ($routeExists)
                <a href="{{ route($tab['route']) }}" wire:navigate
                    @if ($isActive) aria-current="page" @endif
                    style="{{ $isActive ? 'background-color: #202020; color: #ffffff;' : '' }}"
                    class="inline-flex items-center gap-2 px-3.5 py-2 min-h-[40px] rounded-xl text-xs font-semibold whitespace-nowrap transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-apeiron-ink {{ $isActive ? 'bg-apeiron-ink text-white shadow-sm' : 'text-[#787774] hover:text-apeiron-ink hover:bg-[#F7F7F5]' }}">
                    <span class="material-symbols-rounded text-[18px] shrink-0 {{ $isActive ? 'text-white' : '' }}">{{ $tab['icon'] }}</span>
                    <span class="{{ $isActive ? 'text-white' : '' }}">{{ $tab['label'] }}</span>
                </a>
            @else
                <div aria-disabled="true" title="{{ $tab['label'] }} — Coming in Phase 6"
                    class="inline-flex items-center gap-2 px-3.5 py-2 min-h-[40px] rounded-xl text-xs font-medium whitespace-nowrap text-[#C4C3C0] cursor-not-allowed">
                    <span class="material-symbols-rounded text-[18px] shrink-0 text-[#C4C3C0]">{{ $tab['icon'] }}</span>
                    <span>{{ $tab['label'] }}</span>
                    <span class="text-[9px] font-bold uppercase tracking-wider text-[#9B9A97] bg-[#F1F1EF] border border-[#E5E7EB] rounded px-1.5 py-0.5">Soon</span>
                </div>
            @endif
        @endforeach
    </nav>
</div>
