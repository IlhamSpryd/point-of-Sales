<div class="space-y-8" data-gsap="page-enter">
    <!-- Lifecycle Legend Banner -->
    <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-zinc-100 dark:border-zinc-800 pb-3">
            <h3 class="text-base font-bold text-apeiron-ink dark:text-zinc-100">
                Standar Taksonomi Status Siklus Hidup (Master Audit Legend)
            </h3>
            <span class="text-xs text-zinc-400 font-mono">100% Sesuai Spesifikasi Audit §6.1</span>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 text-xs">
            <div class="p-2.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/50 flex flex-col justify-between">
                <span class="font-bold text-emerald-800 dark:text-emerald-300">AVAILABLE AND VERIFIED</span>
                <span class="text-[11px] text-zinc-500 mt-1">Perilaku kode telah diuji secara teknis & lulus invariant</span>
            </div>
            <div class="p-2.5 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800/50 flex flex-col justify-between">
                <span class="font-bold text-blue-800 dark:text-blue-300">IMPLEMENTED BUT NOT VERIFIED</span>
                <span class="text-[11px] text-zinc-500 mt-1">Kode ada & berfungsi, verifikasi menyeluruh sedang berjalan</span>
            </div>
            <div class="p-2.5 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50 flex flex-col justify-between">
                <span class="font-bold text-amber-800 dark:text-amber-300">IN DEVELOPMENT</span>
                <span class="text-[11px] text-zinc-500 mt-1">Implementasi ada namun berada pada tingkat prototipe</span>
            </div>
            <div class="p-2.5 rounded-lg bg-indigo-50 dark:bg-indigo-950/30 border border-indigo-200 dark:border-indigo-800/50 flex flex-col justify-between">
                <span class="font-bold text-indigo-800 dark:text-indigo-300">PLANNED — F2</span>
                <span class="text-[11px] text-zinc-500 mt-1">Disetujui untuk rilis Fase 2 (PO/GRN, KDS interaktif)</span>
            </div>
            <div class="p-2.5 rounded-lg bg-purple-50 dark:bg-purple-950/30 border border-purple-200 dark:border-purple-800/50 flex flex-col justify-between">
                <span class="font-bold text-purple-800 dark:text-purple-300">PLANNED — F3</span>
                <span class="text-[11px] text-zinc-500 mt-1">Disetujui untuk rilis Fase 3 (Peta meja visual, QR self-order)</span>
            </div>
            <div class="p-2.5 rounded-lg bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 flex flex-col justify-between">
                <span class="font-bold text-zinc-700 dark:text-zinc-300">FUTURE ROADMAP</span>
                <span class="text-[11px] text-zinc-500 mt-1">Kapabilitas jangka panjang di luar rilis saat ini</span>
            </div>
            <div class="p-2.5 rounded-lg bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-800/50 flex flex-col justify-between">
                <span class="font-bold text-rose-800 dark:text-rose-300">DECISION REQUIRED</span>
                <span class="text-[11px] text-zinc-500 mt-1">Memerlukan persetujuan produk resmi (Decision A / B)</span>
            </div>
        </div>
    </div>

    <!-- 12 Strategic Domains Matrix Table -->
    <div class="card-surface overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
        <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50">
            <h3 class="font-bold text-base text-apeiron-ink dark:text-zinc-100">
                Matriks Cakupan Produk 12 Domain Strategis Apeiron
            </h3>
            <p class="text-xs text-zinc-500 mt-0.5">Pemetaan menyeluruh seluruh kapabilitas platform terhadap Master PRD dan Claude Module Vision</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-800 text-xs font-semibold text-zinc-500 uppercase tracking-wider bg-zinc-50/30 dark:bg-zinc-900/30">
                        <th class="px-6 py-3.5">Domain Bisnis</th>
                        <th class="px-6 py-3.5">Cakupan Modul</th>
                        <th class="px-6 py-3.5">Status Siklus Hidup</th>
                        <th class="px-6 py-3.5">Fase PRD</th>
                        <th class="px-6 py-3.5">Navigasi / Akses</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <!-- Domain 1: Platform Core -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            1. Platform Core
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Multi-tenancy tunggal (OD-01), Branch Scoping (OD-02), Autentikasi TOTP 2FA (OD-27), RBAC 34 izin, Audit Trail 7 tahun (OD-25).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                Available & Verified
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('settings.index') }}" class="text-primary-600 hover:underline">Pengaturan</a>
                        </td>
                    </tr>

                    <!-- Domain 2: Commerce Core -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            2. Commerce Core
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Layar POS Kasir cepat, katalog menu/produk, checkout tunai/nontunai, kalkulasi pajak/service charge (OD-03), struk fisik WhatsApp (OD-35).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                Available & Verified
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('transaction.create') }}" class="text-primary-600 hover:underline">Kasir POS</a>
                        </td>
                    </tr>

                    <!-- Domain 3: F&B Industry Pack -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            3. F&B Industry Pack
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Resep & Bill of Materials (BOM), modifier ADD/REPLACE (OD-17), pesanan Dine-In & Takeaway, approval waste/spoilage > Rp50.000 (OD-11).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                Available & Verified
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('products.index') }}" class="text-primary-600 hover:underline">Katalog Menu</a>
                        </td>
                    </tr>

                    <!-- Domain 4: Retail Industry Pack -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            4. Retail Industry Pack
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Katalog SKU barang jadi, scanner barcode USB HID (OD-33), aturan harga grosir bertingkat (OD-19), assortmen cabang (OD-18), stock opname (OD-30).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                Available & Verified
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('price-lists.index') }}" class="text-primary-600 hover:underline">Price Lists</a>
                        </td>
                    </tr>

                    <!-- Domain 5: Inventory & Supply -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            5. Inventori & Suplai
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Penerimaan langsung (PAGE-041), HPP Moving Average (OD-04), buku besar stok kekal (Stock Ledger), direktori supplier, retur fisik (PAGE-045).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                Available & Verified
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('inventory.ledger') }}" class="text-primary-600 hover:underline">Buku Stok</a>
                        </td>
                    </tr>

                    <!-- Domain 6: Analytics & Financial -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            6. Analitik & Finansial
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Laporan penjualan & EOD (OD-36), shift blind count (OD-10), refund pro-rata per-item net (OD-06), pembulatan tunai Rp100 (OD-21).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                Available & Verified
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('reports.sales') }}" class="text-primary-600 hover:underline">Laporan Omzet</a>
                        </td>
                    </tr>

                    <!-- Domain 7: CRM & Loyalty -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            7. CRM & Loyalitas
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Direktori pelanggan walk-in/member, riwayat pembelian, tier loyalitas untuk aturan diskon harga otomatis.
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-blue-700 dark:text-blue-400">
                                Implemented
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP / F2</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('customers.index') }}" class="text-primary-600 hover:underline">Pelanggan</a>
                        </td>
                    </tr>

                    <!-- Domain 8: KDS & Kitchen Routing -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            8. Kitchen Display (KDS)
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Routing pesanan interaktif layar sentuh per-station dapur (Bar / Kitchen / Dessert), bump bar, timing durasi masak.
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-indigo-700 dark:text-indigo-400">
                                Planned — F2
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">F2 (OD-32)</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ url('/kds') }}" class="text-indigo-600 hover:underline">Prototipe KDS</a>
                        </td>
                    </tr>

                    <!-- Domain 9: Table Management -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            9. Table Management
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Peta denah visual meja restoran, open tabs, pemisahan/penggabungan tagihan meja (split/merge bill), QR pemesanan mandiri pelanggan.
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-purple-700 dark:text-purple-400">
                                Planned — F3
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">F3 (OD-37)</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ url('/tables') }}" class="text-purple-600 hover:underline">Prototipe Meja</a>
                        </td>
                    </tr>

                    <!-- Domain 10: Procure-to-Pay (P2P) -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            10. Pengadaan Formal (P2P)
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Surat Pesanan Pembelian (Purchase Order PAGE-047), GRN multi-stage (PAGE-048), pencocokan 3-way match, faktur AP debit note (PAGE-050).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-indigo-700 dark:text-indigo-400">
                                Planned — F2
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">F2 (PRD)</td>
                        <td class="px-6 py-4 text-xs text-zinc-400">Roadmap</td>
                    </tr>

                    <!-- Domain 11: General Ledger & Akuntansi -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            11. Keuangan & General Ledger
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Bagan akun (COA), jurnal umum ganda (Double-Entry GL), neraca saldo, rekonsiliasi bank otomatis, laporan laba rugi komprehensif.
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                Future Roadmap
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">F3+</td>
                        <td class="px-6 py-4 text-xs text-zinc-400">Roadmap</td>
                    </tr>

                    <!-- Domain 12: Integrasi & Omnichannel -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            12. Integrasi & Offline Sync
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Antrean transaksi lokal IndexedDB saat offline (OD-05), QZ Tray silent raw ESC-POS (OD-24), agregator delivery online (GoFood/GrabFood/ShopeeFood).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-amber-700 dark:text-amber-400">
                                In Development
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP / F2</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('devices.index') }}" class="text-primary-600 hover:underline">Perangkat</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
