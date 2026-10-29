<div x-data="{ currentWorkflow: 'workflow-a' }" class="space-y-8" data-gsap="page-enter">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-apeiron-ink dark:text-zinc-100 tracking-tight">
                5 Alur Kerja Demonstrasi Terpadu (Cross-Module Journeys)
            </h2>
            <p class="text-xs md:text-sm text-zinc-500 mt-1">
                Jelajahi alur bisnis nyata Apeiron System end-to-end yang menghubungkan titik penjualan, mutasi stok, biaya HPP, dan pelaporan keuangan.
            </p>
        </div>
        <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 text-xs font-semibold">
            <span class="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
            Data Deterministik Konsisten
        </div>
    </div>

    <!-- Workflow Selector Tabs -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <button type="button" @click="currentWorkflow = 'workflow-a'"
            :class="currentWorkflow === 'workflow-a' ? 'bg-primary-600 text-white shadow-md' : 'card-surface text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
            class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 text-left transition-all flex flex-col justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider block opacity-80">Alur A</span>
                <span class="text-xs font-bold mt-1 block">Penjualan F&B & Resep HPP</span>
            </div>
            <span class="text-[10px] mt-2 inline-flex px-1.5 py-0.5 rounded-md bg-emerald-500/20 text-emerald-300 font-semibold w-max">Tersedia</span>
        </button>

        <button type="button" @click="currentWorkflow = 'workflow-b'"
            :class="currentWorkflow === 'workflow-b' ? 'bg-primary-600 text-white shadow-md' : 'card-surface text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
            class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 text-left transition-all flex flex-col justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider block opacity-80">Alur B</span>
                <span class="text-xs font-bold mt-1 block">Ritel & Pemindaian Barcode</span>
            </div>
            <span class="text-[10px] mt-2 inline-flex px-1.5 py-0.5 rounded-md bg-emerald-500/20 text-emerald-300 font-semibold w-max">Tersedia</span>
        </button>

        <button type="button" @click="currentWorkflow = 'workflow-c'"
            :class="currentWorkflow === 'workflow-c' ? 'bg-primary-600 text-white shadow-md' : 'card-surface text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
            class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 text-left transition-all flex flex-col justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider block opacity-80">Alur C</span>
                <span class="text-xs font-bold mt-1 block">Penerimaan Stok & HPP Rata-rata</span>
            </div>
            <span class="text-[10px] mt-2 inline-flex px-1.5 py-0.5 rounded-md bg-emerald-500/20 text-emerald-300 font-semibold w-max">Tersedia</span>
        </button>

        <button type="button" @click="currentWorkflow = 'workflow-d'"
            :class="currentWorkflow === 'workflow-d' ? 'bg-primary-600 text-white shadow-md' : 'card-surface text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
            class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 text-left transition-all flex flex-col justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider block opacity-80">Alur D</span>
                <span class="text-xs font-bold mt-1 block">Operasional Multi-Cabang</span>
            </div>
            <span class="text-[10px] mt-2 inline-flex px-1.5 py-0.5 rounded-md bg-emerald-500/20 text-emerald-300 font-semibold w-max">Tersedia</span>
        </button>

        <button type="button" @click="currentWorkflow = 'workflow-e'"
            :class="currentWorkflow === 'workflow-e' ? 'bg-primary-600 text-white shadow-md' : 'card-surface text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
            class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 text-left transition-all flex flex-col justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider block opacity-80">Alur E</span>
                <span class="text-xs font-bold mt-1 block">Showcase Fitur F2/F3</span>
            </div>
            <span class="text-[10px] mt-2 inline-flex px-1.5 py-0.5 rounded-md bg-purple-500/20 text-purple-300 font-semibold w-max">Roadmap</span>
        </button>
    </div>

    <!-- Workflow A Container -->
    <div x-show="currentWorkflow === 'workflow-a'" class="space-y-6">
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-zinc-100 dark:border-zinc-800 pb-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <span class="size-1.5 rounded-full bg-emerald-600"></span>
                        AVAILABLE AND VERIFIED
                    </span>
                    <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100 mt-2">
                        Alur A: Penjualan F&B Menuju Pengurangan Resep & Pelaporan Finansial
                    </h3>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        Menguji siklus hidup pesanan dine-in/takeaway, pemilihan modifier ADD/REPLACE, multi-tender payment, pembukuan mutasi stok bahan baku, dan rekap omzet harian.
                    </p>
                </div>
                <a href="{{ route('transaction.create') }}" target="_blank" class="shrink-0">
                    <x-ui.button variant="primary" class="gap-2">
                        <span class="material-symbols-rounded text-base">point_of_sale</span>
                        Buka POS Kasir Live
                    </x-ui.button>
                </a>
            </div>

            <!-- Steps Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pt-2">
                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-primary-600 text-white font-bold text-xs flex items-center justify-center">1</span>
                        <span class="text-[10px] font-mono text-zinc-400">POS Kasir</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Pilih Item & Varian Resep</h4>
                    <p class="text-[11px] text-zinc-500">
                        Kasir memilih menu (misal: "Caramel Latte"), sistem memunculkan modal modifier ADD ("Extra Shot Espresso") dan REPLACE ("Oat Milk Substitution").
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-primary-600 text-white font-bold text-xs flex items-center justify-center">2</span>
                        <span class="text-[10px] font-mono text-zinc-400">Order Context</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Dine-In / Takeaway</h4>
                    <p class="text-[11px] text-zinc-500">
                        Kasir memilih tipe layanan. Untuk Dine-In counter service, nomor meja opsional dapat dicantumkan tanpa memerlukan peta meja visual (Decision A).
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-primary-600 text-white font-bold text-xs flex items-center justify-center">3</span>
                        <span class="text-[10px] font-mono text-zinc-400">Pembayaran</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Checkout Multi-Tender</h4>
                    <p class="text-[11px] text-zinc-500">
                        Mendukung pembayaran Tunai dengan pembulatan Rp100 (OD-21), QRIS Statis, atau kartu debit/kredit dengan validasi server-side.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-primary-600 text-white font-bold text-xs flex items-center justify-center">4</span>
                        <span class="text-[10px] font-mono text-zinc-400">Buku Stok & Omzet</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Dampak Stok & Finansial</h4>
                    <p class="text-[11px] text-zinc-500">
                        Stok biji kopi dan susu berkurang secara atomik di tabel <code class="font-mono text-[10px] text-primary-600">stock_ledgers</code>, tercatat pada laporan shift kasir aktif.
                    </p>
                </div>
            </div>

            <!-- Deep Links -->
            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex flex-wrap items-center gap-3 text-xs">
                <span class="font-semibold text-zinc-400">Tautan Pengujian Operasional:</span>
                <a href="{{ route('transaction.create') }}" class="font-medium text-primary-600 hover:underline">1. Buat Transaksi POS</a>
                <span>•</span>
                <a href="{{ route('orders.index') }}" class="font-medium text-primary-600 hover:underline">2. Riwayat Pesanan</a>
                <span>•</span>
                <a href="{{ route('inventory.ledger') }}" class="font-medium text-primary-600 hover:underline">3. Buku Mutasi Stok</a>
                <span>•</span>
                <a href="{{ route('reports.sales') }}" class="font-medium text-primary-600 hover:underline">4. Laporan Penjualan</a>
            </div>
        </div>
    </div>

    <!-- Workflow B Container -->
    <div x-show="currentWorkflow === 'workflow-b'" class="space-y-6">
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-zinc-100 dark:border-zinc-800 pb-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <span class="size-1.5 rounded-full bg-emerald-600"></span>
                        AVAILABLE AND VERIFIED
                    </span>
                    <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100 mt-2">
                        Alur B: Transaksi Ritel, Pemindaian Barcode & Aturan Harga Grosir
                    </h3>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        Menguji pemindaian barcode USB HID keyboard-wedge (OD-33), kalkulasi diskon kuantitas bertingkat (OD-19), dan mutasi persediaan barang jadi siap jual.
                    </p>
                </div>
                <a href="{{ route('price-lists.index') }}" target="_blank" class="shrink-0">
                    <x-ui.button variant="secondary" class="gap-2">
                        <span class="material-symbols-rounded text-base">price_change</span>
                        Aturan Harga Grosir
                    </x-ui.button>
                </a>
            </div>

            <!-- Steps Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pt-2">
                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center">1</span>
                        <span class="text-[10px] font-mono text-zinc-400">Katalog SKU</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Pencarian & Barcode</h4>
                    <p class="text-[11px] text-zinc-500">
                        Produk ritel (misal: "Tumbler Stainless 500ml" kode barcode 8991234567) discan via barcode scanner atau dicari langsung via search bar kasir.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center">2</span>
                        <span class="text-[10px] font-mono text-zinc-400">Price List Engine</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Evaluasi Preseden Harga</h4>
                    <p class="text-[11px] text-zinc-500">
                        Engine harga mengevaluasi hirarki: Branch+Channel > Branch > Channel > Customer Contract > Tenant Base Price (OD-19).
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center">3</span>
                        <span class="text-[10px] font-mono text-zinc-400">POS Checkout</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Selesai Transaksi</h4>
                    <p class="text-[11px] text-zinc-500">
                        Kasir menuntaskan transaksi ritel. Resep dekomposisi dilewati karena produk berjenis SKU barang jadi siap pakai (finished goods).
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center">4</span>
                        <span class="text-[10px] font-mono text-zinc-400">Stock Opname</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Penyesuaian Fisik Ritel</h4>
                    <p class="text-[11px] text-zinc-500">
                        Persediaan fisik dihitung berkala via Stock Opname (OD-30) dengan batas toleransi selisih dan persetujuan supervisor jika melebihi ambang batas.
                    </p>
                </div>
            </div>

            <!-- Deep Links -->
            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex flex-wrap items-center gap-3 text-xs">
                <span class="font-semibold text-zinc-400">Tautan Pengujian Ritel:</span>
                <a href="{{ route('products.index') }}" class="font-medium text-blue-600 hover:underline">1. Katalog Produk & SKU</a>
                <span>•</span>
                <a href="{{ route('price-lists.index') }}" class="font-medium text-blue-600 hover:underline">2. Aturan Grosir & Tier</a>
                <span>•</span>
                <a href="{{ route('assortments.index') }}" class="font-medium text-blue-600 hover:underline">3. Assortmen Produk Per Cabang</a>
            </div>
        </div>
    </div>

    <!-- Workflow C Container -->
    <div x-show="currentWorkflow === 'workflow-c'" class="space-y-6">
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-zinc-100 dark:border-zinc-800 pb-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <span class="size-1.5 rounded-full bg-emerald-600"></span>
                        AVAILABLE AND VERIFIED
                    </span>
                    <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100 mt-2">
                        Alur C: Penerimaan Barang Langsung & Rekalkulasi HPP Rata-rata Bergerak
                    </h3>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        Membuktikan invariant akuntansi persediaan OD-04: Penerimaan langsung (Direct Goods Receipt PAGE-041) memperbarui biaya pokok HPP moving average seketika tanpa jeda batch.
                    </p>
                </div>
                <a href="{{ route('inventory.goods-receipts') }}" target="_blank" class="shrink-0">
                    <x-ui.button variant="secondary" class="gap-2">
                        <span class="material-symbols-rounded text-base">input</span>
                        Penerimaan Stok Langsung
                    </x-ui.button>
                </a>
            </div>

            <!-- Steps Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pt-2">
                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-purple-600 text-white font-bold text-xs flex items-center justify-center">1</span>
                        <span class="text-[10px] font-mono text-zinc-400">Supplier</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Pilih Pemasok Sah</h4>
                    <p class="text-[11px] text-zinc-500">
                        Petugas gudang memilih supplier terdaftar dari direktori pemasok tenant aktif dan mengisi nomor surat jalan/faktur fisik pengiriman.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-purple-600 text-white font-bold text-xs flex items-center justify-center">2</span>
                        <span class="text-[10px] font-mono text-zinc-400">Direct Receipt</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Input Qty & Harga Beli</h4>
                    <p class="text-[11px] text-zinc-500">
                        Memasukkan kuantitas riil diterima dan harga beli per unit. Input divalidasi presisi 4 desimal per aturan OD-29.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-purple-600 text-white font-bold text-xs flex items-center justify-center">3</span>
                        <span class="text-[10px] font-mono text-zinc-400">Formula OD-04</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Rekalkulasi Otomatis HPP</h4>
                    <p class="text-[11px] text-zinc-500">
                        Sistem menghitung HPP baru: <br>
                        <code class="font-mono text-[10px] text-purple-600">((QtyLama * HPPLama) + (QtyBaru * HargaBaru)) / QtyTotal</code>.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-purple-600 text-white font-bold text-xs flex items-center justify-center">4</span>
                        <span class="text-[10px] font-mono text-zinc-400">Audit Ledger</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Jejak Mutasi Kekal</h4>
                    <p class="text-[11px] text-zinc-500">
                        Baris baru dibuat pada buku besar persediaan (Stock Ledger) yang bersifat append-only tanpa izin penghapusan (OD-25).
                    </p>
                </div>
            </div>

            <!-- Distinction Alert -->
            <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50 text-xs text-amber-800 dark:text-amber-300 flex items-start gap-2">
                <span class="material-symbols-rounded text-base shrink-0 mt-0.5">verified_user</span>
                <div>
                    <strong>Pembeda Domain Operasional MVP vs F2:</strong>
                    Penerimaan langsung di atas (PAGE-041) adalah fitur MVP mandiri. Alur penerimaan formal bertingkat berbasis Purchase Order / 3-Way Match GRN (PAGE-048) dialokasikan pada fase F2 sesuai Master PRD dan tidak disamarkan sebagai fitur MVP.
                </div>
            </div>
        </div>
    </div>

    <!-- Workflow D Container -->
    <div x-show="currentWorkflow === 'workflow-d'" class="space-y-6">
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-zinc-100 dark:border-zinc-800 pb-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <span class="size-1.5 rounded-full bg-emerald-600"></span>
                        AVAILABLE AND VERIFIED
                    </span>
                    <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100 mt-2">
                        Alur D: Tata Kelola Multi-Cabang & Isolasi Konteks Store
                    </h3>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        Menguji keandalan isolasi data cabang OD-02: Pergantian cabang oleh owner bisnis, penetapan hak akses staf kasir terbatas pada 1 gerai, dan katalog cabang spesifik (Store Assortments OD-18).
                    </p>
                </div>
                <a href="{{ route('stores.index') }}" target="_blank" class="shrink-0">
                    <x-ui.button variant="secondary" class="gap-2">
                        <span class="material-symbols-rounded text-base">store</span>
                        Kelola Cabang
                    </x-ui.button>
                </a>
            </div>

            <!-- Steps Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pt-2">
                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-zinc-800 text-white font-bold text-xs flex items-center justify-center">1</span>
                        <span class="text-[10px] font-mono text-zinc-400">Branch Switcher</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Peralihan Konteks Cabang</h4>
                    <p class="text-[11px] text-zinc-500">
                        Owner/Manager dapat beralih cabang secara instan via header branch-switcher. Sesi pengguna terikat ke ID cabang aktif secara aman di TenantContext.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-zinc-800 text-white font-bold text-xs flex items-center justify-center">2</span>
                        <span class="text-[10px] font-mono text-zinc-400">Assortmen (OD-18)</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Ketersediaan Produk Cabang</h4>
                    <p class="text-[11px] text-zinc-500">
                        Produk tertentu dapat diaktifkan atau dinonaktifkan per gerai (misal: menu alkohol hanya ada di outlet Flagship, tidak di outlet Express).
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-zinc-800 text-white font-bold text-xs flex items-center justify-center">3</span>
                        <span class="text-[10px] font-mono text-zinc-400">Security Invariant</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Isolasi Staf Ketat</h4>
                    <p class="text-[11px] text-zinc-500">
                        Kasir yang ditugaskan ke Cabang A tidak dapat melihat transaksi, kas, atau persediaan Cabang B sekalipun URL dimanipulasi secara manual.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-zinc-800 text-white font-bold text-xs flex items-center justify-center">4</span>
                        <span class="text-[10px] font-mono text-zinc-400">Owner Rollup</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Konsolidasi Finansial</h4>
                    <p class="text-[11px] text-zinc-500">
                        Dashboard Owner menampilkan rekap total pendapatan seluruh cabang sekaligus kemampuan membandingkan performa antar outlet secara berdampingan.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Workflow E Container (Roadmap & Prototypes) -->
    <div x-show="currentWorkflow === 'workflow-e'" class="space-y-6">
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-4">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-purple-100 text-purple-800 dark:bg-purple-950/40 dark:text-purple-300">
                    <span class="size-1.5 rounded-full bg-purple-600"></span>
                    ROADMAP PREVIEWS & PROTOTYPES
                </span>
                <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100 mt-2">
                    Alur E: Etalase Kapabilitas Masa Depan (Fase F2 & F3)
                </h3>
                <p class="text-xs text-zinc-500 mt-0.5">
                    Modul-modul strategis yang telah disiapkan dalam arsitektur kode namun berada pada tahapan prototipe berlabel jelas untuk presentasi investor.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Preview 1: KDS Station -->
                <div class="p-5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/30 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-orange-600 flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">desktop_windows</span> KDS Interaktif
                        </span>
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-orange-100 text-orange-800">F2 (OD-32)</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-sm">Layar Sentuh Dapur Real-Time</h4>
                    <p class="text-xs text-zinc-500">
                        Routing tiket pesanan ke station Bar / Kitchen, waktu masak, bump bar, dan notifikasi pesanan siap disajikan.
                    </p>
                    <a href="{{ url('/kds') }}" target="_blank" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:underline">
                        Buka Prototipe KDS &rarr;
                    </a>
                </div>

                <!-- Preview 2: Procure-to-Pay Formal -->
                <div class="p-5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/30 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-blue-600 flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">receipt_long</span> Pengadaan P2P
                        </span>
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-blue-100 text-blue-800">F2 (PRD)</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-sm">Purchase Order & 3-Way Match</h4>
                    <p class="text-xs text-zinc-500">
                        Penerbitan PO formal ke supplier, pencocokan GRN fisik gudang, dan rekonsiliasi faktur pembelian sebelum pembayaran AP.
                    </p>
                    <span class="text-xs text-zinc-400 font-medium italic block">Tersedia di Arsitektur F2</span>
                </div>

                <!-- Preview 3: Table Management & Tabs -->
                <div class="p-5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/30 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-purple-600 flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">table_restaurant</span> Manajemen Meja
                        </span>
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-purple-100 text-purple-800">F3 (OD-37)</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-sm">Peta Meja Visual & Open Tabs</h4>
                    <p class="text-xs text-zinc-500">
                        Denah lantai interaktif, status meja terisi/kosong, tagihan gantung terbuka (open tabs), split/merge table, dan QR self-order.
                    </p>
                    <a href="{{ url('/tables') }}" target="_blank" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:underline">
                        Buka Prototipe Meja &rarr;
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
