{{-- Mobile Overlay --}}
<div x-show="sidebarMobileOpen" @click="sidebarMobileOpen = false"
     x-transition.opacity.duration.200ms
     class="fixed inset-0 bg-[#37352F]/40 backdrop-blur-sm z-40 lg:hidden" x-cloak tabindex="-1" aria-hidden="true"></div>

{{-- Mobile Toggle Button --}}
<button x-show="!sidebarMobileOpen" @click="sidebarMobileOpen = true"
        aria-controls="main-sidebar"
        :aria-expanded="sidebarMobileOpen.toString()"
        aria-label="Open sidebar"
        class="lg:hidden fixed top-3 left-4 z-40 p-2 bg-white/90 backdrop-blur border border-[#E9E9E7] rounded-xl text-[#787774] shadow-sm hover:text-[#37352F] focus-visible:ring-2 focus-visible:ring-[#37352F] transition-all duration-200">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>

<aside id="main-sidebar"
       :class="[
           sidebarMobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
           expanded ? 'w-64' : 'w-18'
       ]"
       class="bg-white/80 backdrop-blur-xl border-r border-[#E9E9E7] flex flex-col fixed lg:static h-full z-40 text-[#37352F] transition-all duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] overflow-hidden select-none"
       x-data="{ openPopover: false }"
       @click.outside="sidebarMobileOpen = false">

    {{-- ─── Logo & Collapse Toggle ─── --}}
    <div class="h-15 flex items-center shrink-0 px-4 gap-3 border-b border-transparent"
         :class="expanded ? 'justify-between' : 'justify-center'">

        {{-- Logo (Acts as Expand Toggle when collapsed) --}}
        <a href="{{ route('dashboard') }}" 
           @click="if(!expanded) { $event.preventDefault(); expanded = true; }"
           class="flex items-center min-w-0 group rounded-full" 
           :class="expanded ? 'justify-start' : 'justify-center w-full'"
           :title="!expanded ? 'Expand sidebar' : ''">
            
            <div class="font-bold tracking-tight whitespace-nowrap flex items-center transition-all duration-200 text-xl" :class="expanded ? 'w-auto' : 'justify-center w-full'">
                
                {{-- Gemini-Style Wrapper (No Background by default, circular hover) --}}
                <div class="flex items-center justify-center shrink-0 p-2 rounded-full hover:bg-[#F1F1EF] transition-colors relative" :class="expanded ? 'mr-3' : ''">
                    
                    {{-- 1. Sparkle Logo (Visible normally, hidden on hover ONLY when collapsed) --}}
                    <svg :class="!expanded ? 'block group-hover:hidden' : 'block'" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" class="text-[#37352F] transition-transform duration-600 ease-in-out group-hover:rotate-180">
                        <path d="M8 0a1 1 0 0 1 1 1v5.268l4.562-2.634a1 1 0 1 1 1 1.732L10 8l4.562 2.634a1 1 0 1 1-1 1.732L9 9.732V15a1 1 0 1 1-2 0V9.732l-4.562 2.634a1 1 0 1 1-1-1.732L6 8 1.438 5.366a1 1 0 0 1 1-1.732L7 6.268V1a1 1 0 0 1 1-1z"/>
                    </svg>

                    {{-- 2. Expand Sidebar Icon (Hidden normally, visible on hover ONLY when collapsed) --}}
                    <svg :class="!expanded ? 'hidden group-hover:block' : 'hidden'" width="18" height="18" viewBox="0 0 24 24" fill="none" class="text-[#787774] transition-transform duration-300" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect width="18" height="18" x="3" y="3" rx="2" ry="2"></rect>
                        <path d="M9 3v18"></path>
                        <path d="m14 9 3 3-3 3"></path>
                    </svg>

                </div>
                
                <span :class="expanded ? 'opacity-100 w-auto inline' : 'opacity-0 w-0 hidden'" class="transition-all duration-200 overflow-hidden font-semibold">
                    Yovel Coffee
                </span>
            </div>
        </a>

        {{-- Collapse Toggle (Desktop Only) --}}
        <button @click="expanded = !expanded"
                x-show="expanded"
                x-cloak
                class="hidden lg:flex p-1.5 rounded-lg text-[#9B9A97] hover:text-[#37352F] hover:bg-[#F1F1EF] transition-all duration-200"
                title="Collapse sidebar">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
        </button>
    </div>

    {{-- ─── Navigation ─── --}}
    <nav class="flex-1 py-3 overflow-y-auto overflow-x-hidden scrollbar-none"
         :class="expanded ? 'px-3' : 'px-2'">

        <ul class="space-y-0.5">
            {{-- Section: Menu --}}
            <li :class="expanded ? 'opacity-100 h-auto mt-1 mb-1' : 'opacity-0 h-0 m-0 overflow-hidden'"
                class="px-3 pb-1.5 text-[10px] font-bold text-[#9B9A97] uppercase tracking-[0.08em] transition-all duration-200">
                Menu
            </li>

            {{-- Dashboard --}}
            @if(auth()->check() && auth()->user()->role?->name === 'Administrator')
            <li>
                <a href="{{ route('dashboard') }}"
                   class="group flex items-center rounded-xl text-[13px] font-medium transition-all duration-200 relative overflow-hidden whitespace-nowrap
                          {{ request()->routeIs('dashboard')
                              ? 'bg-[#F1F1EF] text-[#37352F]'
                              : 'text-[#787774] hover:text-[#37352F] hover:bg-[#F7F7F5]' }}"
                   :class="expanded ? 'px-3 py-2.5' : 'px-0 py-2.5 justify-center'"
                   title="Dashboard">
                    @if(request()->routeIs('dashboard'))
                        <span class="absolute left-0 top-1/2 -translate-y-1/2 w-0.75 h-5 bg-[#37352F] rounded-r-full" :class="expanded ? 'opacity-100' : 'opacity-0'"></span>
                    @endif
                    <span class="material-symbols-rounded text-[18px] shrink-0" :class="expanded ? 'w-5 text-center' : ''">space_dashboard</span>
                    <span :class="expanded ? 'opacity-100 w-auto ml-3' : 'opacity-0 w-0 ml-0'"
                          class="transition-all duration-200 overflow-hidden whitespace-nowrap">Dashboard</span>
                </a>
            </li>
            @endif

            {{-- Manajemen Meja (Hanya Administrator) --}}
            @if(auth()->check() && auth()->user()->role?->name === 'Administrator')
            <li>
                <a href="{{ route('tables.index') }}"
                   class="group flex items-center rounded-xl text-[13px] font-medium transition-all duration-200 relative overflow-hidden whitespace-nowrap
                          {{ request()->routeIs('tables.*')
                              ? 'bg-[#F1F1EF] text-[#37352F]'
                              : 'text-[#787774] hover:text-[#37352F] hover:bg-[#F7F7F5]' }}"
                   :class="expanded ? 'px-3 py-2.5' : 'px-0 py-2.5 justify-center'"
                   title="Manajemen Meja">
                    <span class="material-symbols-rounded text-[18px] shrink-0" :class="expanded ? 'w-5 text-center' : ''">table_restaurant</span>
                    <span :class="expanded ? 'opacity-100 w-auto ml-3' : 'opacity-0 w-0 ml-0'" class="transition-all duration-200 overflow-hidden whitespace-nowrap">Meja</span>
                </a>
            </li>
            @endif

            {{-- Transaksi (Hanya Kasir) --}}
            @if(auth()->check() && auth()->user()->role?->name === 'Kasir')
            <li>
                <a href="{{ route('transaction.create') }}"
                   class="group flex items-center rounded-xl text-[13px] font-medium transition-all duration-200 relative overflow-hidden whitespace-nowrap
                          {{ request()->routeIs('transaction.*')
                              ? 'bg-[#F1F1EF] text-[#37352F]'
                              : 'text-[#787774] hover:text-[#37352F] hover:bg-[#F7F7F5]' }}"
                   :class="expanded ? 'px-3 py-2.5' : 'px-0 py-2.5 justify-center'"
                   title="Order">
                    @if(request()->routeIs('transaction.*'))
                        <span class="absolute left-0 top-1/2 -translate-y-1/2 w-0.75 h-5 bg-[#37352F] rounded-r-full" :class="expanded ? 'opacity-100' : 'opacity-0'"></span>
                    @endif
                    <span class="material-symbols-rounded text-[18px] shrink-0" :class="expanded ? 'w-5 text-center' : ''">point_of_sale</span>
                    <span :class="expanded ? 'opacity-100 w-auto ml-3' : 'opacity-0 w-0 ml-0'"
                          class="transition-all duration-200 overflow-hidden whitespace-nowrap">Orders</span>
                </a>
            </li>
            @endif

            {{-- Laporan Penjualan (Hanya Pimpinan) --}}
            @if(auth()->check() && auth()->user()->role?->name === 'Pimpinan')
            <li>
                <a href="{{ route('reports.sales') }}"
                   class="group flex items-center rounded-xl text-[13px] font-medium transition-all duration-200 relative overflow-hidden whitespace-nowrap
                          {{ request()->routeIs('reports.*')
                              ? 'bg-[#F1F1EF] text-[#37352F]'
                              : 'text-[#787774] hover:text-[#37352F] hover:bg-[#F7F7F5]' }}"
                   :class="expanded ? 'px-3 py-2.5' : 'px-0 py-2.5 justify-center'"
                   title="Laporan Penjualan">
                    @if(request()->routeIs('reports.*'))
                        <span class="absolute left-0 top-1/2 -translate-y-1/2 w-0.75 h-5 bg-[#37352F] rounded-r-full" :class="expanded ? 'opacity-100' : 'opacity-0'"></span>
                    @endif
                    <span class="material-symbols-rounded text-[18px] shrink-0" :class="expanded ? 'w-5 text-center' : ''">analytics</span>
                    <span :class="expanded ? 'opacity-100 w-auto ml-3' : 'opacity-0 w-0 ml-0'"
                          class="transition-all duration-200 overflow-hidden whitespace-nowrap">Laporan</span>
                </a>
            </li>
            @endif

            {{-- Catalog (Collapsible Group) - For Admin, Kasir, Pimpinan --}}
            @if(auth()->check() && in_array(auth()->user()->role?->name, ['Administrator', 'Kasir', 'Pimpinan']))
            <li x-data="{ subOpen: {{ request()->routeIs('products.*') || request()->routeIs('categories.*') ? 'true' : 'false' }} }">
                <button @click="subOpen = !subOpen; if(!expanded) expanded = true;"
                        class="group w-full flex items-center rounded-xl text-[13px] font-medium transition-all duration-200 overflow-hidden whitespace-nowrap
                               {{ request()->routeIs('products.*') || request()->routeIs('categories.*')
                                   ? 'text-[#37352F]'
                                   : 'text-[#787774] hover:text-[#37352F] hover:bg-[#F7F7F5]' }}"
                        :class="expanded ? 'px-3 py-2.5 justify-between' : 'px-0 py-2.5 justify-center'"
                        title="Catalog">
                    <div class="flex items-center">
                        <span class="material-symbols-rounded text-[18px] shrink-0" :class="expanded ? 'w-5 text-center' : ''">inventory_2</span>
                        <span :class="expanded ? 'opacity-100 w-auto ml-3' : 'opacity-0 w-0 ml-0'"
                              class="transition-all duration-200 overflow-hidden whitespace-nowrap">Katalog</span>
                    </div>
                    <svg :class="[expanded ? 'opacity-100 w-3.5 ml-2' : 'opacity-0 w-0 ml-0', subOpen ? 'rotate-180' : '']"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         class="shrink-0 transition-all duration-200 text-[#9B9A97]"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <ul x-show="subOpen && expanded" x-collapse x-cloak
                    class="mt-0.5 ml-6.5 pl-3 border-l border-[#E9E9E7] space-y-0.5 whitespace-nowrap">
                    <li>
                        <a href="{{ route('categories.index') }}"
                           class="block px-3 py-1.75 text-[13px] rounded-lg transition-all duration-200
                                  {{ request()->routeIs('categories.*')
                                      ? 'text-[#37352F] bg-[#F1F1EF] font-medium'
                                      : 'text-[#787774] hover:text-[#37352F] hover:bg-[#F7F7F5]' }}">
                            Kategori
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('products.index') }}"
                           class="block px-3 py-1.75 text-[13px] rounded-lg transition-all duration-200
                                  {{ request()->routeIs('products.*')
                                      ? 'text-[#37352F] bg-[#F1F1EF] font-medium'
                                      : 'text-[#787774] hover:text-[#37352F] hover:bg-[#F7F7F5]' }}">
                            Produk
                        </a>
                    </li>
                </ul>
            </li>
            @endif

            {{-- Access (Collapsible Group) - Only for Administrator --}}
            @if(auth()->check() && auth()->user()->role?->name === 'Administrator')
            <li x-data="{ subOpen: {{ request()->routeIs('users.*') || request()->routeIs('roles.*') ? 'true' : 'false' }} }">
                <button @click="subOpen = !subOpen; if(!expanded) expanded = true;"
                        class="group w-full flex items-center rounded-xl text-[13px] font-medium transition-all duration-200 overflow-hidden whitespace-nowrap
                               {{ request()->routeIs('users.*') || request()->routeIs('roles.*')
                                   ? 'text-[#37352F]'
                                   : 'text-[#787774] hover:text-[#37352F] hover:bg-[#F7F7F5]' }}"
                        :class="expanded ? 'px-3 py-2.5 justify-between' : 'px-0 py-2.5 justify-center'"
                        title="Access Control">
                    <div class="flex items-center">
                        <span class="material-symbols-rounded text-[18px] shrink-0" :class="expanded ? 'w-5 text-center' : ''">admin_panel_settings</span>
                        <span :class="expanded ? 'opacity-100 w-auto ml-3' : 'opacity-0 w-0 ml-0'"
                              class="transition-all duration-200 overflow-hidden whitespace-nowrap">Akses</span>
                    </div>
                    <svg :class="[expanded ? 'opacity-100 w-3.5 ml-2' : 'opacity-0 w-0 ml-0', subOpen ? 'rotate-180' : '']"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         class="shrink-0 transition-all duration-200 text-[#9B9A97]"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <ul x-show="subOpen && expanded" x-collapse x-cloak
                    class="mt-0.5 ml-6.5 pl-3 border-l border-[#E9E9E7] space-y-0.5 whitespace-nowrap">
                    <li>
                        <a href="{{ route('roles.index') }}"
                           class="block px-3 py-1.75 text-[13px] rounded-lg transition-all duration-200
                                  {{ request()->routeIs('roles.*')
                                      ? 'text-[#37352F] bg-[#F1F1EF] font-medium'
                                      : 'text-[#787774] hover:text-[#37352F] hover:bg-[#F7F7F5]' }}">
                            Peran
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('users.index') }}"
                           class="block px-3 py-1.75 text-[13px] rounded-lg transition-all duration-200
                                  {{ request()->routeIs('users.*')
                                      ? 'text-[#37352F] bg-[#F1F1EF] font-medium'
                                      : 'text-[#787774] hover:text-[#37352F] hover:bg-[#F7F7F5]' }}">
                            Pengguna
                        </a>
                    </li>
                </ul>
            </li>
            @endif
        </ul>
    </nav>

    {{-- ─── Bottom Profile + Popover ─── --}}
    <div class="shrink-0 mt-auto relative" :class="expanded ? 'p-3' : 'p-2'">

        {{-- User Profile Trigger --}}
        <button @click="openPopover = !openPopover"
                class="w-full flex items-center rounded-xl transition-all duration-200 group relative overflow-hidden whitespace-nowrap"
                :class="[
                    expanded ? 'p-2.5 hover:bg-[#F7F7F5]' : 'p-2 justify-center hover:bg-[#F7F7F5]',
                    openPopover ? 'bg-[#F7F7F5]' : ''
                ]"
                title="{{ auth()->user()->name ?? 'Profile' }}">

            {{-- Avatar --}}
            <div class="w-8 h-8 rounded-md bg-[#F1F1EF] text-[#37352F] border border-[#E9E9E7] flex items-center justify-center font-medium text-[13px] shrink-0 mx-auto md:mx-0">
                {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
            </div>

            {{-- Name & Email --}}
            <div :class="expanded ? 'opacity-100 w-auto ml-3 flex-1 text-left hidden lg:block' : 'opacity-0 w-0 ml-0 hidden'"
                 class="transition-all duration-200 overflow-hidden whitespace-nowrap">
                <p class="text-[13px] font-semibold text-[#37352F] truncate leading-tight">{{ auth()->user()->name ?? 'Administrator' }}</p>
                <p class="text-[11px] text-[#787774] truncate leading-tight mt-0.5">{{ auth()->user()->email ?? 'admin@mail.com' }}</p>
            </div>

            {{-- Chevron --}}
            <svg :class="[expanded ? 'opacity-100 w-3.5 ml-2 hidden lg:block' : 'opacity-0 w-0 ml-0 hidden', openPopover ? 'rotate-180' : '']"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 class="shrink-0 transition-all duration-200 text-[#9B9A97]"><polyline points="6 9 12 15 18 9"/></svg>
        </button>

        {{-- ─── Popover Menu ─── --}}
        <div x-show="openPopover"
             x-cloak
             @click.outside="openPopover = false"
             x-transition:enter="transition-all duration-200 ease-[cubic-bezier(0.16,1,0.3,1)]"
             x-transition:enter-start="opacity-0 scale-95 translate-y-2"
             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
             x-transition:leave="transition-all duration-150 ease-in"
             x-transition:leave-start="opacity-100 scale-100 translate-y-0"
             x-transition:leave-end="opacity-0 scale-95 translate-y-2"
             class="absolute bottom-full mb-2 bg-white rounded-xl shadow-[0_4px_24px_rgba(0,0,0,0.06)] border border-[#E9E9E7] overflow-hidden z-50"
             :class="expanded ? 'left-3 right-3' : 'left-2 w-52 md:left-full md:ml-3'">

            {{-- Popover Header --}}
            <div class="px-4 py-3 border-b border-[#E9E9E7]">
                <p class="text-sm font-semibold text-[#37352F] truncate">{{ auth()->user()->name ?? 'Administrator' }}</p>
                <p class="text-xs text-[#787774] truncate mt-0.5">{{ auth()->user()->email ?? 'admin@mail.com' }}</p>
                @if(auth()->user()->role)
                    <span class="inline-flex items-center mt-2 px-2 py-0.5 rounded-md text-[10px] font-medium bg-[#F7F7F5] text-[#37352F] border border-[#E9E9E7]">
                        {{ auth()->user()->role->name }}
                    </span>
                @endif
            </div>

            {{-- Popover Actions --}}
            <div class="p-1.5">
                <a href="{{ route('profile.edit') }}"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] text-[#787774] hover:text-[#37352F] hover:bg-[#F7F7F5] transition-all duration-150 font-medium">
                    <svg class="w-4 h-4 shrink-0 text-center" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>Profil Saya</span>
                </a>

                <div class="my-1.5 border-t border-[#E9E9E7]"></div>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="flex w-full items-center gap-3 px-3 py-2 rounded-lg text-[13px] text-rose-600 hover:bg-rose-50 transition-all duration-150 font-medium">
                        <svg class="w-4 h-4 shrink-0 text-center" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        <span>Keluar</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
