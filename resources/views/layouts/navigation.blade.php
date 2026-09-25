{{--
    SIDEBAR DINAMIS BERBASIS ROLE — Yovel Coffee

    Sumber kebenaran tunggal menu: config/navigation.php.
    Visibilitas menu BUKAN keamanan: keamanan tetap di middleware `role:` pada routes/web.php.
    Item yang route-nya belum terdaftar tampil nonaktif dengan label "Segera",
    sehingga sidebar tidak pernah melempar RouteNotFoundException.

    @see config/navigation.php — definisi menu
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
    class="fixed inset-0 bg-yovel-ink/40 backdrop-blur-sm z-40 lg:hidden" x-cloak aria-hidden="true"></div>

{{-- Tombol buka drawer (mobile). Touch target 44px. --}}
<button type="button" x-show="!sidebarMobileOpen" @click="sidebarMobileOpen = true" aria-controls="main-sidebar"
    :aria-expanded="sidebarMobileOpen.toString()" aria-label="Buka menu"
    class="lg:hidden fixed top-1.5 left-2 z-40 w-[44px] h-[44px] flex items-center justify-center text-yovel-ink hover:bg-[#F7F7F5] active:scale-95 focus-visible:ring-2 focus-visible:ring-[#37352F] transition-all duration-200 rounded-xl">
    <span class="material-symbols-rounded text-[26px]">menu</span>
</button>

{{--
    `wide` = sidebar tampil lebar (desktop expanded ATAU drawer mobile terbuka).
    Ini memperbaiki drawer mobile yang ikut "terlipat" bila preferensi desktop tersimpan collapsed.
    overflow-hidden DIHAPUS dari <aside> agar popover profil tidak terpotong saat sidebar dilipat.
--}}
<aside id="main-sidebar" x-data="{ openPopover: false, wide: true }" x-effect="wide = expanded || sidebarMobileOpen"
    :class="[
        sidebarMobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
        wide ? 'w-72 lg:w-64' : 'w-18'
    ]"
    class="fixed lg:relative z-40 h-full flex flex-col bg-white/80 backdrop-blur-xl border-r border-[#E9E9E7] text-yovel-ink select-none transition-all duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]">

    {{-- Logo + tombol lipat --}}
    <div class="h-15 flex items-center shrink-0 px-4 gap-3" :class="wide ? 'justify-between' : 'justify-center'">
        <a href="{{ $homeUrl }}" wire:navigate @click="if (!wide) { $event.preventDefault(); expanded = true; }"
            :title="!wide ? 'Buka sidebar' : ''"
            class="group flex items-center min-w-0 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F]">
            <span
                class="flex items-center justify-center shrink-0 w-10 h-10 rounded-full group-hover:bg-[#F7F7F5] transition-colors">
                <svg :class="!wide ? 'group-hover:hidden' : ''" width="18" height="18" viewBox="0 0 16 16"
                    fill="currentColor" class="text-yovel-ink transition-transform duration-500 group-hover:rotate-180"
                    aria-hidden="true">
                    <path
                        d="M8 0a1 1 0 0 1 1 1v5.268l4.562-2.634a1 1 0 1 1 1 1.732L10 8l4.562 2.634a1 1 0 1 1-1 1.732L9 9.732V15a1 1 0 1 1-2 0V9.732l-4.562 2.634a1 1 0 1 1-1-1.732L6 8 1.438 5.366a1 1 0 0 1 1-1.732L7 6.268V1a1 1 0 0 1 1-1z" />
                </svg>
                <svg :class="!wide ? 'hidden group-hover:block' : 'hidden'" width="18" height="18"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" class="text-[#787774]" aria-hidden="true">
                    <rect width="18" height="18" x="3" y="3" rx="2" ry="2"></rect>
                    <path d="M9 3v18"></path>
                    <path d="m14 9 3 3-3 3"></path>
                </svg>
            </span>
            <span :class="wide ? 'opacity-100 ml-2' : 'opacity-0 w-0 hidden'"
                class="font-brand text-xl whitespace-nowrap overflow-hidden transition-all duration-200">
                <span class="font-bold">Yovel</span><span class="font-normal text-[#787774] ml-0.5">Coffee</span>
            </span>
        </a>

        {{-- Lipat sidebar (desktop) --}}
        <button type="button" @click="expanded = false" x-show="expanded" x-cloak title="Lipat sidebar"
            aria-label="Lipat sidebar"
            class="hidden lg:flex w-9 h-9 items-center justify-center rounded-lg text-[#9B9A97] hover:text-yovel-ink hover:bg-[#F7F7F5] active:scale-95 transition-all duration-200">
            <span class="material-symbols-rounded text-[20px]">left_panel_close</span>
        </button>
        {{-- Tutup drawer (mobile) --}}
        <button type="button" @click="sidebarMobileOpen = false" x-show="sidebarMobileOpen" x-cloak
            aria-label="Tutup menu"
            class="lg:hidden w-[44px] h-[44px] flex items-center justify-center rounded-xl text-[#787774] hover:bg-[#F7F7F5] active:scale-95 transition-all duration-200">
            <span class="material-symbols-rounded text-[22px]">close</span>
        </button>
    </div>

    {{-- Navigasi --}}
    <nav class="flex-1 py-3 overflow-y-auto overflow-x-hidden scrollbar-hide" :class="wide ? 'px-3' : 'px-2'"
        aria-label="Navigasi utama">
        @foreach ($visibleMenu as $section => $items)
            <div class="{{ $loop->first ? '' : 'mt-5' }}">
                <p x-show="wide"
                    class="px-3 pb-1.5 text-[10px] font-bold text-[#9B9A97] uppercase tracking-[0.08em] whitespace-nowrap">
                    {{ $section }}</p>
                @unless ($loop->first)
                    <div x-show="!wide" x-cloak class="mx-3 mb-2 border-t border-[#E9E9E7]"></div>
                @endunless

                <ul class="space-y-0.5">
                    @foreach ($items as $item)
                        @php
                            $routeExists = \Illuminate\Support\Facades\Route::has($item['route']);
                            $isActive = $routeExists && request()->routeIs($item['active']);
                            $rowBase =
                                'group relative flex items-center rounded-xl text-[13px] font-medium whitespace-nowrap min-h-[44px] transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#37352F]';
                        @endphp
                        <li>
                            @if ($routeExists)
                                <a href="{{ route($item['route']) }}" wire:navigate
                                    @if ($isActive) aria-current="page" @endif
                                    title="{{ $item['label'] }}" :class="wide ? 'px-3' : 'justify-center px-0'"
                                    class="{{ $rowBase }} {{ $isActive ? 'bg-[#F1F1EF] text-yovel-ink' : 'text-[#787774] hover:text-yovel-ink hover:bg-[#F7F7F5] active:scale-[0.98]' }}">
                                    @if ($isActive)
                                        <span
                                            class="absolute left-0 top-1/2 -translate-y-1/2 w-0.75 h-5 bg-yovel-ink rounded-r-full"></span>
                                    @endif
                                    <span
                                        class="material-symbols-rounded text-[20px] shrink-0 w-5 text-center">{{ $item['icon'] }}</span>
                                    <span :class="wide ? 'opacity-100 ml-3' : 'opacity-0 w-0 ml-0'"
                                        class="overflow-hidden transition-all duration-200">{{ $item['label'] }}</span>
                                </a>
                            @else
                                <div aria-disabled="true" title="{{ $item['label'] }} — segera hadir"
                                    :class="wide ? 'px-3' : 'justify-center px-0'"
                                    class="{{ $rowBase }} text-[#C4C3C0] cursor-not-allowed">
                                    <span
                                        class="material-symbols-rounded text-[20px] shrink-0 w-5 text-center">{{ $item['icon'] }}</span>
                                    <span :class="wide ? 'opacity-100 ml-3' : 'opacity-0 w-0 ml-0'"
                                        class="overflow-hidden transition-all duration-200">{{ $item['label'] }}</span>
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
    <div class="shrink-0 mt-auto relative border-t border-[#E9E9E7]" :class="wide ? 'p-3' : 'p-2'">
        <button type="button" @click="openPopover = !openPopover" :aria-expanded="openPopover.toString()"
            class="w-full flex items-center rounded-xl transition-all duration-200 whitespace-nowrap min-h-[44px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F]"
            :class="[wide ? 'p-2 hover:bg-[#F7F7F5]' : 'p-1.5 justify-center hover:bg-[#F7F7F5]', openPopover ? 'bg-[#F7F7F5]' :
                ''
            ]"
            title="{{ auth()->user()->name ?? 'Profil' }}">
            <div
                class="w-8 h-8 rounded-lg bg-[#F1F1EF] text-yovel-ink border border-[#E9E9E7] flex items-center justify-center font-semibold text-[13px] shrink-0">
                {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
            </div>
            <div :class="wide ? 'opacity-100 ml-3 flex-1 text-left' : 'opacity-0 w-0 ml-0 hidden'"
                class="overflow-hidden transition-all duration-200">
                <p class="text-[13px] font-semibold truncate leading-tight">{{ auth()->user()->name ?? 'Pengguna' }}
                </p>
                <p class="text-[11px] text-[#787774] truncate leading-tight mt-0.5">{{ $roleName ?? 'Tanpa peran' }}
                </p>
            </div>
            <span x-show="wide"
                class="material-symbols-rounded text-[18px] text-[#9B9A97] transition-transform duration-200"
                :class="openPopover ? 'rotate-180' : ''">expand_less</span>
        </button>

        <div x-show="openPopover" x-cloak @click.outside="openPopover = false"
            @keydown.escape.window="openPopover = false"
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
                    class="flex items-center gap-3 px-3 min-h-[44px] rounded-xl text-[13px] font-medium text-[#787774] hover:text-yovel-ink hover:bg-[#F7F7F5] active:scale-[0.98] transition-all duration-150">
                    <span class="material-symbols-rounded text-[18px]">person</span> Profil Saya
                </a>
                @if(auth()->user()->role?->name === 'Owner')
                <a href="{{ route('settings.index') }}" wire:navigate
                    class="flex items-center gap-3 px-3 min-h-[44px] rounded-xl text-[13px] font-medium text-[#787774] hover:text-yovel-ink hover:bg-[#F7F7F5] active:scale-[0.98] transition-all duration-150">
                    <span class="material-symbols-rounded text-[18px]">tune</span> Pengaturan
                </a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                        class="flex w-full items-center gap-3 px-3 min-h-[44px] rounded-xl text-[13px] font-medium text-rose-600 hover:bg-rose-50 active:scale-[0.98] transition-all duration-150">
                        <span class="material-symbols-rounded text-[18px]">logout</span> Keluar
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
