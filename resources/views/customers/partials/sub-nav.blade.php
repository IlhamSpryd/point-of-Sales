@php
    $roleName = auth()->user()?->role?->name;

    $allCustomerTabs = [
        [
            'label' => 'Customer Directory',
            'route' => 'customers.index',
            'active' => 'customers.index',
            'icon' => 'person',
            'roles' => ['Owner', 'Manager'],
        ],
        [
            'label' => 'Customer Groups',
            'route' => 'customers.groups.index',
            'active' => 'customers.groups.*',
            'icon' => 'groups',
            'roles' => ['Owner', 'Manager'],
        ],
        [
            'label' => 'B2B Credit Control',
            'route' => 'customers.credit.index',
            'active' => 'customers.credit.*',
            'icon' => 'credit_score',
            'roles' => ['Owner', 'Manager'],
        ],
        [
            'label' => 'Loyalty, Vouchers & Gift Cards',
            'route' => 'customers.instruments.index',
            'active' => 'customers.instruments.*',
            'icon' => 'card_giftcard',
            'roles' => ['Owner', 'Manager'],
        ],
    ];

    $visibleCustomerTabs = array_filter($allCustomerTabs, function ($tab) use ($roleName) {
        return in_array($roleName, $tab['roles'], true);
    });
@endphp

<div class="w-full pb-3 mb-6 border-b border-[#E5E7EB]">
    <nav aria-label="Customer & Loyalty Navigation"
        x-data
        x-init="$nextTick(() => {
            const activeTab = $el.querySelector('[aria-current=page]');
            if (activeTab) {
                const offset = activeTab.offsetLeft - ($el.clientWidth / 2) + (activeTab.clientWidth / 2);
                $el.scrollTo({ left: Math.max(0, offset), behavior: 'instant' });
            }
        })"
        class="flex items-center gap-1.5 md:gap-2 overflow-x-auto scrollbar-hide py-1">
        @foreach ($visibleCustomerTabs as $tab)
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
            @endif
        @endforeach
    </nav>
</div>
