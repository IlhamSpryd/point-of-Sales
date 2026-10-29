<div class="space-y-8" data-gsap="page-enter">
    <!-- Executive Banner -->
    <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-linear-to-br from-zinc-900 via-zinc-800 to-zinc-900 p-8 text-white shadow-xl relative overflow-hidden">
        <div class="absolute -right-12 -top-12 w-64 h-64 bg-primary-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="relative z-10 max-w-4xl space-y-4">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 text-xs font-semibold backdrop-blur-xs text-primary-200">
                <span class="material-symbols-rounded text-sm">hub</span>
                <span>Arsitektur ERP Modular Tunggal • Single Modular Core</span>
            </div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                Apeiron System: Platform Operasional F&B & Ritel Terpadu
            </h1>
            <p class="text-sm md:text-base text-zinc-300 leading-relaxed">
                Apeiron dibangun atas satu fondasi multi-tenant yang kokoh, memadukan fleksibilitas operasional POS kasir berkecepatan tinggi dengan ketelitian tata kelola ERP multi-cabang. Platform ini melayani bisnis F&B (Restoran, Kafe, Bar), Ritel Modern (Boutique, Minimarket, Roastery), maupun model bisnis campuran (Mixed Outlet) dalam satu basis data dan tata kelola akun terpusat.
            </p>
            <div class="pt-2 flex flex-wrap items-center gap-4 text-xs font-medium text-zinc-400">
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-400"></span> Multi-Tenant DB (OD-01)</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-blue-400"></span> Store Scoping (OD-02)</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-purple-400"></span> Moving Average HPP (OD-04)</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-400"></span> Offline IndexedDB Queue (OD-05)</span>
            </div>
        </div>
    </div>

    <!-- Architectural Pillars Matrix -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Platform Core -->
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 flex flex-col justify-between">
            <div class="space-y-3">
                <div class="size-11 rounded-xl bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-200 flex items-center justify-center">
                    <span class="material-symbols-rounded text-2xl">dns</span>
                </div>
                <h3 class="font-bold text-apeiron-ink dark:text-zinc-100 text-base">Platform Core</h3>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Pondasi multi-tenant tunggal, isolasi data berbasis tenant_id, otorisasi RBAC granular, logging jejak audit 7 tahun (OD-25), dan registrasi terminal fisik kasir (OD-31).
                </p>
            </div>
            <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between text-xs">
                <span class="font-semibold text-emerald-600">Status: Aktif & Terverifikasi</span>
                <a href="{{ route('settings.index') }}" class="text-primary-600 hover:underline">Kelola &rarr;</a>
            </div>
        </div>

        <!-- F&B Industry Pack -->
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 flex flex-col justify-between">
            <div class="space-y-3">
                <div class="size-11 rounded-xl bg-orange-50 text-orange-600 dark:bg-orange-950/40 dark:text-orange-400 flex items-center justify-center">
                    <span class="material-symbols-rounded text-2xl">restaurant</span>
                </div>
                <h3 class="font-bold text-apeiron-ink dark:text-zinc-100 text-base">F&B Industry Pack</h3>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Resep & BOM bahan baku (OD-04), varian modifier ADD/REPLACE (OD-17), routing dapur KDS (OD-32), opsi Dine-In / Takeaway, dan penanganan waste/spoilage (OD-11).
                </p>
            </div>
            <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between text-xs">
                <span class="font-semibold text-emerald-600">Status: MVP Operasional</span>
                <a href="{{ route('transaction.create') }}" class="text-orange-600 hover:underline">Layar POS &rarr;</a>
            </div>
        </div>

        <!-- Retail Industry Pack -->
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 flex flex-col justify-between">
            <div class="space-y-3">
                <div class="size-11 rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-400 flex items-center justify-center">
                    <span class="material-symbols-rounded text-2xl">shopping_cart</span>
                </div>
                <h3 class="font-bold text-apeiron-ink dark:text-zinc-100 text-base">Retail Industry Pack</h3>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Manajemen SKU barang jadi, scanner barcode USB HID (OD-33), aturan harga grosir bertingkat (OD-19), matriks assortmen cabang (OD-18), dan stock opname fisik (OD-30).
                </p>
            </div>
            <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between text-xs">
                <span class="font-semibold text-emerald-600">Status: MVP Operasional</span>
                <a href="{{ route('price-lists.index') }}" class="text-blue-600 hover:underline">Aturan Harga &rarr;</a>
            </div>
        </div>

        <!-- Shared ERP & Back Office -->
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 flex flex-col justify-between">
            <div class="space-y-3">
                <div class="size-11 rounded-xl bg-purple-50 text-purple-600 dark:bg-purple-950/40 dark:text-purple-400 flex items-center justify-center">
                    <span class="material-symbols-rounded text-2xl">account_balance</span>
                </div>
                <h3 class="font-bold text-apeiron-ink dark:text-zinc-100 text-base">Shared ERP & Back Office</h3>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Buku besar mutasi stok permanen, penerimaan barang langsung (PAGE-041), rekap kasir & blind shift closing (OD-10), laporan omzet & EOD (OD-36), serta roadmap P2P & GL.
                </p>
            </div>
            <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between text-xs">
                <span class="font-semibold text-purple-600">Status: F2/F3 Prototyped</span>
                <a href="{{ route('inventory.ledger') }}" class="text-purple-600 hover:underline">Buku Stok &rarr;</a>
            </div>
        </div>
    </div>

    <!-- Deep Dive: Mixed F&B & Retail Tenant Architecture (Section 5.8) -->
    <div class="card-surface p-6 md:p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-zinc-100 dark:border-zinc-800">
            <div>
                <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100">
                    Kapabilitas Tenant Campuran: F&B + Ritel Tanpa Batasan Dikotomi
                </h3>
                <p class="text-xs md:text-sm text-zinc-500 mt-1">
                    Arsitektur Apeiron tidak membatasi akun secara kaku ke satu tipe bisnis permanen. Satu tenant dapat mengoperasikan unit bisnis yang berbeda-beda di tiap cabangnya.
                </p>
            </div>
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-zinc-100 dark:bg-zinc-800 text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                <span class="material-symbols-rounded text-sm">tune</span>
                Branch Capability Model
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-sm">
            <div class="p-5 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-[#E5E7EB] dark:border-zinc-700/80 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-orange-600">coffee</span>
                    <h4 class="font-bold text-apeiron-ink dark:text-zinc-200">Outlet Resto / Kafe</h4>
                </div>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Fokus pada kecepatan POS kasir, menu racikan resep otomatis, printer dapur / kitchen station, dan pencatatan sisa bahan baku espresso/susu.
                </p>
                <div class="text-[11px] font-medium text-orange-700 bg-orange-100/50 px-2.5 py-1 rounded-md inline-block">
                    Contoh: Apeiron Cafe Senopati
                </div>
            </div>

            <div class="p-5 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-[#E5E7EB] dark:border-zinc-700/80 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-blue-600">storefront</span>
                </div>
                <h4 class="font-bold text-apeiron-ink dark:text-zinc-200">Outlet Ritel / Merchandise</h4>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Fokus pada pemindaian barcode cepat, harga grosir tiering, barang jadi siap jual tanpa dekomposisi resep, dan kontrol stok SKU.
                </p>
                <div class="text-[11px] font-medium text-blue-700 bg-blue-100/50 px-2.5 py-1 rounded-md inline-block">
                    Contoh: Apeiron Roastery & Merch
                </div>
            </div>

            <div class="p-5 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-[#E5E7EB] dark:border-zinc-700/80 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-purple-600">domain_add</span>
                    <h4 class="font-bold text-apeiron-ink dark:text-zinc-200">Outlet Campuran (Hybrid)</h4>
                </div>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Kasir F&B yang juga menjual tumbler, biji kopi kemasan, kaos, dan snack kemasan. Transaksi memotong stok bahan baku resep sekaligus stok SKU ritel dalam satu transaksi.
                </p>
                <div class="text-[11px] font-medium text-purple-700 bg-purple-100/50 px-2.5 py-1 rounded-md inline-block">
                    Contoh: Apeiron Flagship Store
                </div>
            </div>
        </div>
    </div>

    <!-- Scalability from 1 Outlet to Multi-Branch Enterprise -->
    <div class="card-surface p-6 md:p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-6">
        <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100">
            Jalur Skalabilitas: Dari 1 Gerai Menuju Puluhan Cabang Terintegrasi
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 relative">
            <div class="p-5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800">Tahap 1: Gerai Tunggal</span>
                    <span class="text-xs text-zinc-400 font-mono">1 Outlet</span>
                </div>
                <h4 class="font-bold text-apeiron-ink dark:text-white text-sm">Operasional Harian Efisien</h4>
                <ul class="text-xs text-zinc-500 space-y-1.5 list-disc pl-4">
                    <li>Kasir POS responsif tablet / desktop</li>
                    <li>Resep & stok bahan baku otomatis</li>
                    <li>Laporan kas harian & tutup shift kasir</li>
                    <li>Mendukung cetak struk ESC-POS lokal</li>
                </ul>
            </div>

            <div class="p-5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">Tahap 2: Multi-Cabang (Saat Ini)</span>
                    <span class="text-xs text-zinc-400 font-mono">2 - 10 Outlet</span>
                </div>
                <h4 class="font-bold text-apeiron-ink dark:text-white text-sm">Manajemen Terpusat Multi-Branch</h4>
                <ul class="text-xs text-zinc-500 space-y-1.5 list-disc pl-4">
                    <li>Katalog pusat dengan Store Assortment (OD-18)</li>
                    <li>Harga berbeda per cabang & kanal (OD-19)</li>
                    <li>Isolasi data staf per cabang (OD-02)</li>
                    <li>Laporan konsolidasi level owner bisnis</li>
                </ul>
            </div>

            <div class="p-5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-purple-100 text-purple-800">Tahap 3: Enterprise & Waralaba</span>
                    <span class="text-xs text-zinc-400 font-mono">> 10 Outlet</span>
                </div>
                <h4 class="font-bold text-apeiron-ink dark:text-white text-sm">Central Kitchen & P2P Supply Chain</h4>
                <ul class="text-xs text-zinc-500 space-y-1.5 list-disc pl-4">
                    <li>Dapur sentral (Central Kitchen) & transfer stok antar cabang</li>
                    <li>Procure-to-Pay formal dengan PO & 3-way match (F2)</li>
                    <li>Akuntansi buku besar ganda (General Ledger F3)</li>
                    <li>Integrasi agregator online (GoFood/GrabFood F3)</li>
                </ul>
            </div>
        </div>
    </div>
</div>
