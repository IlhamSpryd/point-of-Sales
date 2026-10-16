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
    $menu = config('navigation.sections');
    $visibleMenu = collect($menu)
        ->map(fn($items) => array_values(array_filter($items, fn($i) => in_array($roleName, $i['roles'], true))))
        ->filter(fn($items) => count($items) > 0);

    // HomeController (route('home')) adalah sumber kebenaran tunggal
    // untuk "kemana role ini pulang" — tidak pernah 403.
    $homeUrl = route('home');
@endphp

{{-- Overlay drawer mobile. Menutup drawer cukup lewat overlay ini. --}}
<div x-show="sidebarMobileOpen" @click="sidebarMobileOpen = false" x-transition.opacity.duration.200ms
    class="fixed inset-0 bg-apeiron-ink/40 backdrop-blur-sm z-40 lg:hidden" x-cloak aria-hidden="true"></div>

{{-- Tombol buka drawer (mobile). Touch target 44px dengan keyboard focus indicator. --}}
<button type="button" x-show="!sidebarMobileOpen" @click="sidebarMobileOpen = true; $nextTick(() => { const first = document.querySelector('#main-sidebar a, #main-sidebar button'); if (first) first.focus(); })"
    id="mobile-menu-btn"
    aria-controls="main-sidebar"
    :aria-expanded="sidebarMobileOpen.toString()" aria-label="Buka menu navigasi utama"
    class="lg:hidden fixed top-1.5 left-2 z-40 w-[44px] h-[44px] flex items-center justify-center text-apeiron-ink hover:bg-[#F7F7F5] active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F] transition-all duration-200 rounded-xl cursor-pointer">
    <span class="material-symbols-rounded text-[26px]">menu</span>
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
    class="fixed lg:relative z-40 h-full flex flex-col bg-white text-apeiron-ink select-none transition-all duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]">

    {{-- Logo + tombol lipat --}}
    <div class="h-14 flex items-center shrink-0 transition-all duration-200" :class="wide ? 'justify-between px-4 gap-3' : 'justify-center px-0'">
        <a href="{{ $homeUrl }}" wire:navigate
            @click="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; } if (!wide) { $event.preventDefault(); expanded = true; }"
            :title="!wide ? 'Buka sidebar' : ''"
            class="group flex items-center justify-center min-w-0 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F]">
            <span
                class="flex items-center justify-center shrink-0 w-10 h-10 rounded-full group-hover:bg-[#F7F7F5] transition-colors">
                <img src="{{ asset('assets/images/logo.png') }}" alt="Apeiron System Logo" :class="!wide ? 'group-hover:hidden' : ''" class="h-8 w-auto object-contain transition-transform duration-500 group-hover:scale-110">
                <svg :class="!wide ? 'hidden group-hover:block' : 'hidden'" width="18" height="18"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" class="text-[#787774]" aria-hidden="true">
                    <rect width="18" height="18" x="3" y="3" rx="2" ry="2"></rect>
                    <path d="M9 3v18"></path>
                    <path d="m14 9 3 3-3 3"></path>
                </svg>
            </span>
            <span :class="wide ? 'opacity-100 ml-2' : 'hidden'"
                class="font-brand text-xl whitespace-nowrap overflow-hidden transition-all duration-200">
                <span class="font-bold">Apeiron</span><span class="font-normal text-[#787774] ml-0.5">POS</span>
            </span>
        </a>

        {{-- Lipat sidebar (desktop) --}}
        <button type="button" @click="expanded = false" x-show="expanded" x-cloak title="Lipat sidebar"
            aria-label="Lipat sidebar"
            class="hidden lg:flex w-9 h-9 items-center justify-center rounded-lg text-[#9B9A97] hover:text-apeiron-ink hover:bg-[#F7F7F5] active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F] transition-all duration-200 cursor-pointer">
            <span class="material-symbols-rounded text-[20px]">left_panel_close</span>
        </button>

        {{-- Tutup drawer (mobile) --}}
        <button type="button" @click="sidebarMobileOpen = false; $nextTick(() => document.getElementById('mobile-menu-btn')?.focus())" x-show="sidebarMobileOpen" x-cloak
            aria-label="Tutup menu navigasi"
            class="lg:hidden w-[44px] h-[44px] flex items-center justify-center rounded-xl text-[#787774] hover:bg-[#F7F7F5] active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F] transition-all duration-200 cursor-pointer">
            <span class="material-symbols-rounded text-[22px]">close</span>
        </button>
    </div>

    {{-- Branch Switcher (Sidebar) --}}
    <div x-show="wide" class="px-3 pb-2 pt-1 border-b border-[#F1F1EF]">
        <x-branch-switcher :fullWidth="true" />
    </div>

    {{-- Navigasi --}}
    <nav class="flex-1 py-3 overflow-y-auto overflow-x-hidden scrollbar-hide" :class="wide ? 'px-3' : 'px-0'"
        aria-label="Navigasi utama">
        @foreach ($visibleMenu as $section => $items)
            @php
                $isTerminalSection = ($section === 'Terminal Kerja');
            @endphp
            <div class="{{ $loop->first ? '' : ($isTerminalSection ? 'mt-5 pt-3 border-t border-[#F1F1EF]' : 'mt-4') }}">
                <div x-show="wide" class="px-3 pb-1 flex items-center justify-between">
                    <p class="text-[11px] font-semibold text-[#6B6A66] tracking-[0.02em] whitespace-nowrap uppercase">
                        {{ $section }}
                    </p>
                    @if($isTerminalSection)
                        <span class="text-[9px] font-bold text-amber-700 bg-amber-50 border border-amber-200/60 rounded px-1.5 py-0.2 select-none">
                            Workstation
                        </span>
                    @endif
                </div>
                @unless ($loop->first)
                    <div x-show="!wide" x-cloak class="w-8 mx-auto my-2 border-t border-[#F1F1EF]"></div>
                @endunless

                <ul class="space-y-1">
                    @foreach ($items as $item)
                        @php
                            $routeExists = \Illuminate\Support\Facades\Route::has($item['route']);
                            $isActive = $routeExists && request()->routeIs($item['active']);
                            $scope = $item['scope'] ?? 'store';
                            $rowBase =
                                'group relative flex items-center rounded-xl text-[13px] whitespace-nowrap transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#37352F]';
                        @endphp
                        <li class="flex justify-center w-full">
                            @if ($routeExists)
                                <a href="{{ route($item['route']) }}" wire:navigate
                                    @click="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); $event.stopImmediatePropagation(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }"
                                    @if ($isActive) aria-current="page" @endif
                                    title="{{ $item['label'] }} ({{ $scope === 'store' ? 'Cabang Aktif' : 'Tenant' }})"
                                    :class="wide ? 'w-full px-2.5 min-h-[40px] justify-start before:left-0 before:top-2 before:bottom-2 before:w-1 before:rounded-r' : 'w-10 h-10 justify-center px-0 before:hidden'"
                                    class="{{ $rowBase }} {{ $isActive ? 'bg-[#F1F1EF] text-[#202020] font-semibold before:absolute before:bg-[#202020]' : 'text-[#6B6A66] hover:text-[#202020] hover:bg-[#F7F7F5]' }}">
                                    <span
                                        class="material-symbols-rounded text-[20px] shrink-0 text-center flex items-center justify-center {{ $isActive ? 'text-[#202020]' : 'text-[#787774] group-hover:text-[#202020]' }}">{{ $item['icon'] }}</span>
                                    <span :class="wide ? 'opacity-100 ml-2.5 flex-1 truncate' : 'hidden'"
                                        class="overflow-hidden transition-all duration-150">{{ $item['label'] }}</span>
                                    @if ($scope === 'store')
                                        <span x-show="wide"
                                            class="ml-auto text-[9px] font-medium text-[#787774] bg-[#F1F1EF] border border-[#E9E9E7]/60 rounded px-1.5 py-0.5 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity duration-150">
                                            Cabang
                                        </span>
                                    @elseif ($scope === 'tenant')
                                        <span x-show="wide"
                                            class="ml-auto text-[9px] font-medium text-[#9B9A97] bg-white border border-[#E9E9E7]/60 rounded px-1.5 py-0.5 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity duration-150">
                                            Tenant
                                        </span>
                                    @endif
                                </a>
                            @else
                                <div aria-disabled="true" title="{{ $item['label'] }} — segera hadir"
                                    :class="wide ? 'w-full px-2.5 min-h-[40px] justify-start' : 'w-10 h-10 justify-center px-0'"
                                    class="{{ $rowBase }} text-[#C4C3C0] cursor-not-allowed">
                                    <span
                                        class="material-symbols-rounded text-[20px] shrink-0 text-center flex items-center justify-center text-[#C4C3C0]">{{ $item['icon'] }}</span>
                                    <span :class="wide ? 'opacity-100 ml-2.5 flex-1 truncate' : 'hidden'"
                                        class="overflow-hidden transition-all duration-150">{{ $item['label'] }}</span>
                                    <span x-show="wide"
                                        class="ml-auto text-[9px] font-bold uppercase tracking-wider text-[#9B9A97] bg-[#F1F1EF] border border-[#E9E9E7] rounded-md px-1.5 py-0.5">Segera</span>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    {{-- Profil + popover --}}
    <div class="shrink-0 mt-auto relative border-t border-[#F1F1EF]" :class="wide ? 'p-3' : 'py-3 px-0 flex justify-center'">
        <button type="button" @click="openPopover = !openPopover" :aria-expanded="openPopover.toString()"
            aria-haspopup="true"
            aria-label="Menu akun pengguna"
            class="flex items-center rounded-xl transition-all duration-150 whitespace-nowrap min-h-[40px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F] cursor-pointer"
            :class="[wide ? 'w-full p-2 hover:bg-[#F7F7F5]' : 'w-10 h-10 justify-center p-0 hover:bg-[#F7F7F5]', openPopover ? 'bg-[#F1F1EF]' : '']"
            title="{{ auth()->user()->name ?? 'Profil' }}">
            <div
                class="w-7 h-7 rounded-full bg-[#F1F1EF] text-apeiron-ink border border-[#E9E9E7] flex items-center justify-center font-semibold text-[12px] shrink-0">
                {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
            </div>
            <div :class="wide ? 'opacity-100 ml-2.5 flex-1 text-left' : 'hidden'"
                class="overflow-hidden transition-all duration-150">
                <p class="text-[13px] font-semibold text-[#202020] truncate leading-tight">{{ auth()->user()->name ?? 'Pengguna' }}
                </p>
                <p class="text-[11px] text-[#6B6A66] truncate leading-tight mt-0.5">{{ $roleName ?? 'Tanpa peran' }}
                </p>
            </div>
            <span x-show="wide"
                class="material-symbols-rounded text-[18px] text-[#9B9A97] transition-transform duration-200"
                :class="openPopover ? 'rotate-180' : ''">expand_less</span>
        </button>

        <div x-show="openPopover" x-cloak @click.outside="openPopover = false"
            @keydown.escape.window="openPopover = false"
            role="menu"
            aria-label="Menu profil dan pengaturan pengguna"
            x-transition:enter="transition-all duration-200 ease-[cubic-bezier(0.16,1,0.3,1)]"
            x-transition:enter-start="opacity-0 scale-95 translate-y-2"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="transition-all duration-150 ease-in"
            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
            x-transition:leave-end="opacity-0 scale-95 translate-y-2"
            class="absolute bottom-full mb-2 bg-white rounded-2xl shadow-[0_4px_24px_rgba(0,0,0,0.08)] border border-[#E9E9E7] overflow-hidden z-50"
            :class="wide ? 'left-3 right-3' : 'left-2 w-56 md:left-full md:ml-3'">
            <div class="px-4 py-3 border-b border-[#E9E9E7]">
                <p class="text-sm font-semibold truncate">{{ auth()->user()->name ?? 'Pengguna' }}</p>
                <p class="text-xs text-[#787774] truncate mt-0.5">{{ auth()->user()->email ?? '' }}</p>
                @if ($roleName)
                    <span
                        class="inline-flex items-center mt-2 px-2 py-0.5 rounded-md text-[10px] font-medium bg-[#F7F7F5] border border-[#E9E9E7]">{{ $roleName }}</span>
                @endif
            </div>
            <div class="p-1.5">
                <a href="{{ route('profile.edit') }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }"
                    role="menuitem"
                    class="flex items-center gap-3 px-3 min-h-[44px] rounded-xl text-[13px] font-medium text-[#787774] hover:text-apeiron-ink hover:bg-[#F7F7F5] active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F] transition-all duration-150">
                    <span class="material-symbols-rounded text-[18px]">person</span> Profil Saya
                </a>
                @if(auth()->user()->role?->name === 'Owner')
                <a href="{{ route('settings.index') }}" wire:navigate
                    @click="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }"
                    role="menuitem"
                    class="flex items-center gap-3 px-3 min-h-[44px] rounded-xl text-[13px] font-medium text-[#787774] hover:text-apeiron-ink hover:bg-[#F7F7F5] active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F] transition-all duration-150">
                    <span class="material-symbols-rounded text-[18px]">settings</span> Pengaturan
                </a>
                @endif
                <form method="POST" action="{{ route('logout') }}"
                    @submit="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Keluar sistem akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }">
                    @csrf
                    <button type="submit"
                        role="menuitem"
                        class="flex w-full items-center gap-3 px-3 min-h-[44px] rounded-xl text-[13px] font-medium text-rose-600 hover:bg-rose-50 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 transition-all duration-150 cursor-pointer">
                        <span class="material-symbols-rounded text-[18px]">logout</span> Keluar
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
