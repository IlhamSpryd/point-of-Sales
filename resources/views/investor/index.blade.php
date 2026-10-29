<x-app-layout>
    <div x-data="{
        activeTab: new URLSearchParams(window.location.search).get('tab') || '{{ $activeTab ?? 'overview' }}',
        switchTab(tab) {
            this.activeTab = tab;
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        }
    }" class="space-y-8" data-gsap="page-enter">

        <!-- Top Breadcrumbs & Page Header -->
        <x-erp.page-header
            title="Pusat Visi Produk & Demonstrasi Investor"
            description="Ekshibisi arsitektur ERP terpadu Apeiron System: Single Modular Core dengan F&B dan Retail Industry Packs.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Visi Produk & Roadmap</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <a href="{{ route('transaction.create') }}" target="_blank">
                        <x-ui.button variant="secondary" class="gap-2">
                            <span class="material-symbols-rounded text-base">point_of_sale</span>
                            Buka POS Kasir
                        </x-ui.button>
                    </a>
                    <a href="{{ route('dashboard') }}">
                        <x-ui.button variant="primary" class="gap-2">
                            <span class="material-symbols-rounded text-base">dashboard</span>
                            Kembali ke Operasional
                        </x-ui.button>
                    </a>
                </div>
            </x-slot:actions>
        </x-erp.page-header>

        <!-- Live Tenant & Multi-Branch Real Data Verification Context -->
        <div class="card-surface p-4 flex flex-wrap items-center justify-between gap-4 text-xs">
            <div class="flex flex-wrap items-center gap-6">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-primary-600 text-lg">corporate_fare</span>
                    <div>
                        <span class="text-zinc-400 font-medium block text-[10px] uppercase">Tenant Aktif</span>
                        <span class="font-bold text-apeiron-ink dark:text-zinc-100">{{ $tenant?->name ?? 'Apeiron Enterprise Multi-Tenant' }}</span>
                    </div>
                </div>
                <div class="h-6 w-px bg-[#E5E7EB] dark:bg-zinc-800 hidden sm:block"></div>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-primary-600 text-lg">store</span>
                    <div>
                        <span class="text-zinc-400 font-medium block text-[10px] uppercase">Konteks Cabang</span>
                        <span class="font-bold text-apeiron-ink dark:text-zinc-100">{{ $realMetrics['current_store_name'] }}</span>
                    </div>
                </div>
                <div class="h-6 w-px bg-[#E5E7EB] dark:bg-zinc-800 hidden sm:block"></div>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-emerald-600 text-lg">inventory_2</span>
                    <div>
                        <span class="text-zinc-400 font-medium block text-[10px] uppercase">Total SKU / Produk Terdaftar</span>
                        <span class="font-bold text-apeiron-ink dark:text-zinc-100">{{ $realMetrics['total_products'] }} Item</span>
                    </div>
                </div>
                <div class="h-6 w-px bg-[#E5E7EB] dark:bg-zinc-800 hidden sm:block"></div>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-blue-600 text-lg">receipt_long</span>
                    <div>
                        <span class="text-zinc-400 font-medium block text-[10px] uppercase">Pesanan Tercatat</span>
                        <span class="font-bold text-apeiron-ink dark:text-zinc-100">{{ $realMetrics['total_orders'] }} Transaksi</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200/80 dark:border-emerald-800/80 font-mono font-semibold text-[11px]">
                <span class="size-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                Database Aktif: MySQL Multi-Tenant
            </div>
        </div>

        <!-- Master Navigation Tabs -->
        <div class="flex items-center gap-2 overflow-x-auto pb-2 border-b border-[#E5E7EB] dark:border-zinc-800">
            <button type="button" @click="switchTab('overview')"
                :class="activeTab === 'overview' ? 'bg-[#202123] text-white font-semibold shadow-xs' : 'text-zinc-600 dark:text-zinc-400 hover:text-apeiron-ink hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                class="px-4 py-2.5 rounded-xl text-xs font-semibold whitespace-nowrap transition-all flex items-center gap-2">
                <span class="material-symbols-rounded text-base">architecture</span>
                Visi Arsitektur & Modul
            </button>

            <button type="button" @click="switchTab('journeys')"
                :class="activeTab === 'journeys' ? 'bg-[#202123] text-white font-semibold shadow-xs' : 'text-zinc-600 dark:text-zinc-400 hover:text-apeiron-ink hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                class="px-4 py-2.5 rounded-xl text-xs font-semibold whitespace-nowrap transition-all flex items-center gap-2">
                <span class="material-symbols-rounded text-base">route</span>
                5 Alur Demonstrasi Terpadu
            </button>

            <button type="button" @click="switchTab('domains')"
                :class="activeTab === 'domains' ? 'bg-[#202123] text-white font-semibold shadow-xs' : 'text-zinc-600 dark:text-zinc-400 hover:text-apeiron-ink hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                class="px-4 py-2.5 rounded-xl text-xs font-semibold whitespace-nowrap transition-all flex items-center gap-2">
                <span class="material-symbols-rounded text-base">dataset</span>
                Matriks 12 Domain Produk
            </button>

            <button type="button" @click="switchTab('traceability')"
                :class="activeTab === 'traceability' ? 'bg-[#202123] text-white font-semibold shadow-xs' : 'text-zinc-600 dark:text-zinc-400 hover:text-apeiron-ink hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                class="px-4 py-2.5 rounded-xl text-xs font-semibold whitespace-nowrap transition-all flex items-center gap-2">
                <span class="material-symbols-rounded text-base">checklist</span>
                Keterlacakan 81 PAGE-ID
            </button>

            <button type="button" @click="switchTab('decisions')"
                :class="activeTab === 'decisions' ? 'bg-[#202123] text-white font-semibold shadow-xs' : 'text-zinc-600 dark:text-zinc-400 hover:text-apeiron-ink hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                class="px-4 py-2.5 rounded-xl text-xs font-semibold whitespace-nowrap transition-all flex items-center gap-2">
                <span class="material-symbols-rounded text-base">help_center</span>
                Dua Keputusan Produk
            </button>
        </div>

        <!-- Tab 1: Platform Overview & Architecture -->
        <div x-show="activeTab === 'overview'" x-cloak>
            @include('investor.partials.overview')
        </div>

        <!-- Tab 2: 5 Guided Cross-Module Journeys -->
        <div x-show="activeTab === 'journeys'" x-cloak>
            @include('investor.partials.journeys')
        </div>

        <!-- Tab 3: 12 Strategic Domains Matrix -->
        <div x-show="activeTab === 'domains'" x-cloak>
            @include('investor.partials.domains')
        </div>

        <!-- Tab 4: 81 PAGE-ID Traceability Register -->
        <div x-show="activeTab === 'traceability'" x-cloak>
            @include('investor.partials.traceability')
        </div>

        <!-- Tab 5: Open Product Decisions (A & B) -->
        <div x-show="activeTab === 'decisions'" x-cloak>
            @include('investor.partials.decisions')
        </div>

    </div>
</x-app-layout>
