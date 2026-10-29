{{--
    SIDEBAR DINAMIS BERBASIS ROLE & SCOPE — Apeiron System
    Task: APEIRON-NAV-IMPL-001 (Owner Navigation Redesign)

    Sumber kebenaran tunggal menu: config/navigation.php.
    Visibilitas menu BUKAN keamanan: keamanan tetap di middleware `role:` pada routes/web.php
    dan Model Policies pada app/Policies/*.
    Item yang route-nya belum terdaftar tampil nonaktif dengan label "Segera",
    sehingga sidebar tidak pernah melempar RouteNotFoundException.

    @see config/navigation.php — definisi menu & scope metadata
    @see tests/Feature/Navigation/SidebarIntegrityTest.php — validasi otomatis
--}}
@php
    $roleName = auth()->user()?->role?->name;
    $tenantId = app(\App\Services\Context\TenantContext::class)->getTenantId();
    $storeId = app(\App\Services\Context\TenantContext::class)->getStoreId();
    $tablesEnabled = ($tenantId && $storeId)
        ? app(\App\Services\EntitlementService::class)->isTablesEnabled((int) $tenantId, (int) $storeId)
        : true;

    $menu = config('navigation.sections');
    $visibleMenu = collect($menu)
        ->map(fn($items) => array_values(array_filter($items, function ($i) use ($roleName, $tablesEnabled) {
            if (! in_array($roleName, $i['roles'], true)) {
                return false;
            }
            if (($i['route'] ?? '') === 'tables.index' && ! $tablesEnabled) {
                return false;
            }
            return true;
        })))
        ->filter(fn($items) => count($items) > 0);

    // HomeController (route('home')) adalah sumber kebenaran tunggal
    // untuk "kemana role ini pulang" — tidak pernah 403.
    $homeUrl = route('home');
@endphp

{{-- Overlay drawer mobile. Menutup drawer cukup lewat overlay ini. --}}
<div x-show="sidebarMobileOpen" @click="sidebarMobileOpen = false" x-transition.opacity.duration.200ms
    class="fixed inset-0 bg-black/60 backdrop-blur-[2px] z-40 lg:hidden" x-cloak aria-hidden="true"></div>

{{-- Tombol buka drawer (mobile). Touch target 44px dengan keyboard focus indicator. --}}
<button type="button" x-show="!sidebarMobileOpen" @click="sidebarMobileOpen = true; $nextTick(() => { const first = document.querySelector('#main-sidebar a, #main-sidebar button'); if (first) first.focus(); })"
    id="mobile-menu-btn"
    aria-controls="main-sidebar"
    :aria-expanded="sidebarMobileOpen.toString()" aria-label="Buka menu navigasi utama"
    class="lg:hidden fixed top-2 left-2 z-40 w-[40px] h-[40px] flex items-center justify-center text-white bg-[#171717] hover:bg-[#262626] border border-[#2E2E2E] active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white transition-all duration-200 rounded-lg cursor-pointer shadow-xs">
    <span class="material-symbols-rounded text-[22px]">menu</span>
</button>

{{--
    `wide` = sidebar tampil lebar (desktop expanded ATAU drawer mobile terbuka).
    Ini memperbaiki drawer mobile yang ikut "terlipat" bila preferensi desktop tersimpan collapsed.
--}}
<aside id="main-sidebar" x-data="{ openPopover: false, wide: true }" x-effect="wide = expanded || sidebarMobileOpen"
    role="region" aria-label="Sidebar Navigasi Utama"
    @keydown.escape.window="if (sidebarMobileOpen) { sidebarMobileOpen = false; $nextTick(() => document.getElementById('mobile-menu-btn')?.focus()); }"
    @keydown.tab="
        if (sidebarMobileOpen) {
            const focusables = Array.from($el.querySelectorAll('a[href], button:not([disabled])'));
            if (!focusables.length) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if ($event.shiftKey && document.activeElement === first) {
                $event.preventDefault();
                last.focus();
            } else if (!$event.shiftKey && document.activeElement === last) {
                $event.preventDefault();
                first.focus();
            }
        }
    "
    :class="[
        sidebarMobileOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full lg:translate-x-0',
        wide ? 'w-72 lg:w-64' : 'w-18'
    ]"
    class="fixed lg:relative z-40 h-full flex flex-col bg-[#171717] text-[#D4D4D4] select-none transition-all duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] border-r border-[#262626]">

    {{-- Logo + tombol lipat --}}
    <div class="h-14 flex items-center shrink-0 transition-all duration-200" :class="wide ? 'justify-between px-4 gap-3' : 'justify-center px-0'">
        <a href="{{ $homeUrl }}" wire:navigate
            @click="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (!wide) { $event.preventDefault(); expanded = true; }"
            :title="!wide ? 'Buka sidebar' : ''"
            class="group flex items-center justify-center min-w-0 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">
            <span
                class="flex items-center justify-center shrink-0 w-9 h-9 rounded-lg group-hover:bg-[#262626] transition-colors">
                <img src="{{ asset('assets/images/logo.png') }}" alt="Apeiron System Logo" :class="!wide ? 'group-hover:hidden' : ''" class="h-7 w-auto object-contain transition-transform duration-300 group-hover:scale-105">
                <svg :class="!wide ? 'hidden group-hover:block' : 'hidden'" width="18" height="18"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" class="text-[#A3A3A3]" aria-hidden="true">
                    <rect width="18" height="18" x="3" y="3" rx="2" ry="2"></rect>
                    <path d="M9 3v18"></path>
                    <path d="m14 9 3 3-3 3"></path>
                </svg>
            </span>
            <span :class="wide ? 'opacity-100 ml-2.5' : 'hidden'"
                class="font-sans text-base tracking-tight whitespace-nowrap overflow-hidden transition-all duration-200">
                <span class="font-bold text-white">Apeiron</span><span class="font-medium text-[#737373] ml-1">System</span>
            </span>
        </a>

        {{-- Lipat sidebar (desktop) --}}
        <button type="button" @click="expanded = false" x-show="expanded" x-cloak title="Lipat sidebar"
            aria-label="Lipat sidebar"
            class="hidden lg:flex w-8 h-8 items-center justify-center rounded-lg text-[#737373] hover:text-white hover:bg-[#262626] active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white transition-all duration-200 cursor-pointer">
            <span class="material-symbols-rounded text-[19px]">left_panel_close</span>
        </button>

        {{-- Tutup drawer (mobile) --}}
        <button type="button" @click="sidebarMobileOpen = false; $nextTick(() => document.getElementById('mobile-menu-btn')?.focus())" x-show="sidebarMobileOpen" x-cloak
            aria-label="Tutup menu navigasi"
            class="lg:hidden w-[38px] h-[38px] flex items-center justify-center rounded-lg text-[#A3A3A3] hover:bg-[#262626] hover:text-white active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white transition-all duration-200 cursor-pointer">
            <span class="material-symbols-rounded text-[20px]">close</span>
        </button>
    </div>

    {{-- Branch Switcher (Sidebar) --}}
    <div x-show="wide" class="px-3 pb-2 pt-1 border-b border-[#262626]">
        <x-branch-switcher :fullWidth="true" />
    </div>
    <div x-show="!wide" x-cloak class="py-2 flex justify-center border-b border-[#262626]">
        <button type="button" @click="expanded = true" title="Cabang Aktif (Klik untuk ubah)"
            aria-label="Buka pilihan cabang"
            class="w-9 h-9 flex items-center justify-center rounded-lg text-emerald-400 hover:bg-[#262626] transition-colors relative cursor-pointer">
            <span class="material-symbols-rounded text-[20px]">storefront</span>
            <span class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-emerald-500 ring-2 ring-[#171717]"></span>
        </button>
    </div>

    {{-- Mode Visi Produk & Demo Investor (Khusus Owner & Manager) --}}
    @if (in_array($roleName, ['Owner', 'Manager'], true))
        @php
            $isInvestorActive = request()->routeIs('investor.demo');
        @endphp
        <div class="px-3 pt-2 pb-1">
            <a href="{{ route('investor.demo') }}" wire:navigate
                title="Mode Visi Produk & Demonstrasi Investor"
                :class="wide ? 'px-2.5 py-1.5 justify-between' : 'w-9 h-9 p-0 justify-center mx-auto'"
                class="group flex items-center rounded-lg text-[12px] font-medium transition-all duration-150 border {{ $isInvestorActive ? 'bg-[#2D2D2D] text-white border-amber-400/40 shadow-xs font-semibold' : 'bg-[#1E1E1E] text-[#A3A3A3] border-[#2A2A2A] hover:border-[#383838] hover:text-white' }}">
                <span class="flex items-center gap-2 overflow-hidden">
                    <span class="material-symbols-rounded text-[18px] shrink-0 {{ $isInvestorActive ? 'text-amber-400' : 'text-[#737373] group-hover:text-amber-400 transition-colors' }}">explore</span>
                    <span :class="wide ? 'inline truncate' : 'hidden'">Visi Produk & Roadmap</span>
                </span>
                <span x-show="wide" class="text-[9px] font-medium uppercase tracking-wider shrink-0 {{ $isInvestorActive ? 'bg-amber-400/20 text-amber-300 border border-amber-400/30' : 'bg-[#2A2A2A] text-[#737373] border border-[#333333]' }} rounded px-1.5 py-0.5">
                    Investor
                </span>
            </a>
        </div>
    @endif

    {{-- Navigasi --}}
    <nav class="flex-1 py-2 overflow-y-auto overflow-x-hidden scrollbar-hide" :class="wide ? 'px-3' : 'px-0'"
        aria-label="Navigasi utama">
        @foreach ($visibleMenu as $section => $items)
            @php
                $isWorkstation = ($section === 'Kasir & Operasional' || $section === 'Pusat Kerja');
            @endphp
            <div class="{{ $loop->first ? '' : ($isWorkstation ? 'mt-4 pt-2.5 border-t border-[#262626]' : 'mt-3.5') }}">
                <div x-show="wide" class="px-2.5 pb-1 flex items-center justify-between">
                    <p class="text-[11px] font-semibold text-[#737373] tracking-[0.04em] whitespace-nowrap uppercase">
                        {{ $section }}
                    </p>
                    @if($isWorkstation)
                        <span class="text-[9px] font-medium text-[#737373] bg-[#222222] border border-[#2E2E2E] rounded px-1.5 py-0.2 select-none">
                            Workstation
                        </span>
                    @endif
                </div>
                @unless ($loop->first)
                    <div x-show="!wide" x-cloak class="w-6 mx-auto my-1.5 border-t border-[#262626]"></div>
                @endunless

                <ul class="space-y-0.5">
                    @foreach ($items as $item)
                        @php
                            $routeExists = \Illuminate\Support\Facades\Route::has($item['route']);
                            $activePatterns = (array) $item['active'];
                            $isActive = $routeExists && request()->routeIs(...$activePatterns);
                            $scope = $item['scope'] ?? 'store';
                            $rowBase =
                                'group relative flex items-center rounded-lg text-[13px] whitespace-nowrap transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-white';
                        @endphp
                        <li class="flex justify-center w-full">
                            @if ($routeExists)
                                <a href="{{ route($item['route']) }}" wire:navigate
                                    @click="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); $event.stopImmediatePropagation(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }"
                                    @if ($isActive) aria-current="page" @endif
                                    title="{{ $item['label'] }} ({{ $scope === 'store' ? 'Cabang Aktif' : 'Tenant' }})"
                                    :class="wide ? 'w-full px-2.5 min-h-[36px] justify-start before:left-0 before:top-2 before:bottom-2 before:w-1.5 before:rounded-r' : 'w-9 h-9 justify-center px-0 before:hidden'"
                                    class="{{ $rowBase }} {{ $isActive ? 'bg-[#2D2D2D] text-white font-semibold border border-white/10 shadow-xs before:absolute before:bg-[#202020] before:!bg-white' : 'text-[#A3A3A3] hover:text-white hover:bg-[#202020]' }}">
                                    <span
                                        class="material-symbols-rounded text-[19px] shrink-0 text-center flex items-center justify-center {{ $isActive ? 'text-white' : 'text-[#737373] group-hover:text-white transition-colors' }}">{{ $item['icon'] }}</span>
                                    <span :class="wide ? 'opacity-100 ml-2.5 flex-1 truncate' : 'hidden'"
                                        class="overflow-hidden transition-all duration-150">{{ $item['label'] }}</span>
                                    @if ($scope === 'store')
                                        <span x-show="wide"
                                            class="ml-auto text-[9px] font-medium {{ $isActive ? 'text-zinc-300 bg-[#383838] border border-white/10' : 'text-[#737373] bg-[#222222] border border-[#2E2E2E]' }} rounded px-1.5 py-0.5 shrink-0">
                                            Cabang
                                        </span>
                                    @elseif ($scope === 'tenant')
                                        <span x-show="wide"
                                            class="ml-auto text-[9px] font-medium {{ $isActive ? 'text-zinc-300 bg-[#383838] border border-white/10' : 'text-[#737373] bg-[#222222] border border-[#2E2E2E]' }} rounded px-1.5 py-0.5 shrink-0">
                                            Tenant
                                        </span>
                                    @endif
                                </a>
                            @else
                                <div aria-disabled="true" title="{{ $item['label'] }} — segera hadir"
                                    :class="wide ? 'w-full px-2.5 min-h-[36px] justify-start' : 'w-9 h-9 justify-center px-0'"
                                    class="{{ $rowBase }} text-[#525252] cursor-not-allowed">
                                    <span
                                        class="material-symbols-rounded text-[19px] shrink-0 text-center flex items-center justify-center text-[#525252]">{{ $item['icon'] }}</span>
                                    <span :class="wide ? 'opacity-100 ml-2.5 flex-1 truncate' : 'hidden'"
                                        class="overflow-hidden transition-all duration-150">{{ $item['label'] }}</span>
                                    <span x-show="wide"
                                        class="ml-auto text-[9px] font-medium uppercase tracking-wider text-[#525252] bg-[#202020] border border-[#2E2E2E] rounded px-1.5 py-0.5">Segera</span>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    {{-- Profil + popover --}}
    <div class="shrink-0 mt-auto relative border-t border-[#262626]" :class="wide ? 'p-2.5' : 'py-2.5 px-0 flex justify-center'">
        <button type="button" @click="openPopover = !openPopover" :aria-expanded="openPopover.toString()"
            aria-haspopup="true"
            aria-label="Menu akun pengguna"
            class="flex items-center rounded-lg transition-all duration-150 whitespace-nowrap min-h-[38px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white cursor-pointer"
            :class="[wide ? 'w-full p-2 hover:bg-[#202020]' : 'w-9 h-9 justify-center p-0 hover:bg-[#202020]', openPopover ? 'bg-[#262626]' : '']"
            title="{{ auth()->user()->name ?? 'Profil' }}">
            <div
                class="w-7 h-7 rounded-full bg-[#262626] text-white border border-[#333333] flex items-center justify-center font-semibold text-[11px] shrink-0">
                {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
            </div>
            <div :class="wide ? 'opacity-100 ml-2.5 flex-1 text-left' : 'hidden'"
                class="overflow-hidden transition-all duration-150">
                <p class="text-[13px] font-medium text-white truncate leading-tight">{{ auth()->user()->name ?? 'Pengguna' }}
                </p>
                <p class="text-[11px] text-[#737373] truncate leading-tight mt-0.5">{{ $roleName ?? 'Tanpa peran' }}
                </p>
            </div>
            <span x-show="wide"
                class="material-symbols-rounded text-[18px] text-[#737373] transition-transform duration-200"
                :class="openPopover ? 'rotate-180' : ''">expand_less</span>
        </button>

        <div x-show="openPopover" x-cloak @click.outside="openPopover = false"
            @keydown.escape.window="openPopover = false"
            role="menu"
            aria-label="Menu profil dan pengaturan pengguna"
            x-transition:enter="transition-all duration-150 ease-[cubic-bezier(0.16,1,0.3,1)]"
            x-transition:enter-start="opacity-0 scale-95 translate-y-1"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="transition-all duration-100 ease-in"
            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
            x-transition:leave-end="opacity-0 scale-95 translate-y-1"
            class="absolute bottom-full mb-2 bg-[#1E1E1E] text-white rounded-xl shadow-xl border border-[#333333] overflow-hidden z-50"
            :class="wide ? 'left-2.5 right-2.5' : 'left-2 w-56 md:left-full md:ml-2'">
            <div class="px-3.5 py-2.5 border-b border-[#2E2E2E]">
                <p class="text-xs font-semibold text-white truncate">{{ auth()->user()->name ?? 'Pengguna' }}</p>
                <p class="text-[11px] text-[#A3A3A3] truncate mt-0.5">{{ auth()->user()->email ?? '' }}</p>
                @if ($roleName)
                    <span
                        class="inline-flex items-center mt-1.5 px-2 py-0.5 rounded text-[10px] font-medium bg-[#2A2A2A] text-[#D4D4D4] border border-[#383838]">{{ $roleName }}</span>
                @endif
            </div>
            <div class="p-1">
                <a href="{{ route('profile.edit') }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }"
                    role="menuitem"
                    class="flex items-center gap-2.5 px-2.5 min-h-[36px] rounded-lg text-xs font-medium text-[#D4D4D4] hover:text-white hover:bg-[#2A2A2A] active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white transition-all duration-150">
                    <span class="material-symbols-rounded text-[17px]">person</span> Profil Saya
                </a>
                @if(auth()->user()->role?->name === 'Owner')
                <a href="{{ route('settings.index') }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }"
                    role="menuitem"
                    class="flex items-center gap-2.5 px-2.5 min-h-[36px] rounded-lg text-xs font-medium text-[#D4D4D4] hover:text-white hover:bg-[#2A2A2A] active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white transition-all duration-150">
                    <span class="material-symbols-rounded text-[17px]">settings</span> Pengaturan
                </a>
                @endif
                <form method="POST" action="{{ route('logout') }}"
                    @submit="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Keluar sistem akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }">
                    @csrf
                    <button type="submit"
                        role="menuitem"
                        class="flex w-full items-center gap-2.5 px-2.5 min-h-[36px] rounded-lg text-xs font-medium text-rose-400 hover:text-rose-300 hover:bg-rose-950/30 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 transition-all duration-150 cursor-pointer">
                        <span class="material-symbols-rounded text-[17px]">logout</span> Keluar
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
