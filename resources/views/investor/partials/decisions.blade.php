<div class="space-y-8" data-gsap="page-enter">
    <!-- Header -->
    <div>
        <h2 class="text-2xl font-bold text-apeiron-ink dark:text-zinc-100 tracking-tight">
            Dua Keputusan Produk Terbuka (Open Product Decisions)
        </h2>
        <p class="text-xs md:text-sm text-zinc-500 mt-1">
            Status analisis dan rekomendasi teknis untuk dua keputusan arsitektur produk yang diidentifikasi dalam audit final Apeiron POS.
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Decision A Card -->
        <div class="card-surface p-6 md:p-8 rounded-xl border border-zinc-200 dark:border-zinc-800 space-y-6 flex flex-col justify-between">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="inline-flex px-2.5 py-1 rounded-md text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-200/60 dark:bg-amber-950/40 dark:text-amber-300">
                        KEPUTUSAN A: DINE-IN TABLE NUMBER
                    </span>
                    <span class="text-xs font-mono text-zinc-400">PRD Ref: OD-37</span>
                </div>

                <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100">
                    Penanganan Nomor Meja pada Layar Kasir POS MVP
                </h3>

                <div class="space-y-3 text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed">
                    <p>
                        <strong>Latar Belakang:</strong> Master PRD menetapkan bahwa manajemen denah meja visual lengkap, status okupansi real-time, open tabs gantung, serta split/merge table dialokasikan pada fase <strong>F3 (OD-37)</strong>. Namun, operasional gerai kafe/restoran cepat saji membutuhkan identifikasi nomor meja saat mengantar pesanan (counter service).
                    </p>
                    <p>
                        <strong>Implementasi Aman Saat Ini:</strong>
                    </p>
                    <ul class="list-disc pl-5 space-y-1 text-zinc-500">
                        <li>Dine-In didukung secara operasional sebagai tipe pesanan counter-service.</li>
                        <li>Properti meja dipertahankan <em>opsional (nullable)</em> pada skema Livewire dan order model.</li>
                        <li>Tidak memaksakan validasi wajib nomor meja saat checkout kasir.</li>
                        <li>Tidak memicu ketergantungan pada tabel okupansi atau status denah lantai fisik.</li>
                    </ul>
                </div>
            </div>

            <div class="p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700/80 space-y-2">
                <div class="flex items-center gap-2 text-xs font-bold text-apeiron-ink dark:text-zinc-200">
                    <span class="material-symbols-rounded text-emerald-600 text-base">check_circle</span>
                    Rekomendasi Arsitek & Status Eksekusi:
                </div>
                <p class="text-xs text-zinc-500">
                    Diterapkan sebagai field opsional non-blocking di POS. Prototipe visual denah meja F3 dialokasikan terpisah di <code class="font-mono text-primary-600">/tables</code> tanpa mengganggu kestabilan kasir MVP. Menunggu persetujuan Product Owner untuk pembakuan kebijakan.
                </p>
            </div>
        </div>

        <!-- Decision B Card -->
        <div class="card-surface p-6 md:p-8 rounded-xl border border-zinc-200 dark:border-zinc-800 space-y-6 flex flex-col justify-between">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="inline-flex px-2.5 py-1 rounded-md text-xs font-semibold bg-blue-50 text-blue-800 border border-blue-200/60 dark:bg-blue-950/40 dark:text-blue-300">
                        KEPUTUSAN B: STORE ASSORTMENTS
                    </span>
                    <span class="text-xs font-mono text-zinc-400">PRD Ref: OD-18</span>
                </div>

                <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100">
                    Penempatan Navigasi Matriks Assortmen Cabang
                </h3>

                <div class="space-y-3 text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed">
                    <p>
                        <strong>Latar Belakang:</strong> Fitur Assortmen Toko (OD-18) mengendalikan ketersediaan produk per-gerai (active/inactive per branch). Terdapat dua opsi penempatan: meleburnya ke dalam sub-navigasi tab katalog produk atau mempertahankannya sebagai menu mandiri.
                    </p>
                    <p>
                        <strong>Implementasi Aman Saat Ini:</strong>
                    </p>
                    <ul class="list-disc pl-5 space-y-1 text-zinc-500">
                        <li>Assortmen dipertahankan sebagai menu mandiri di Pilar 3 (Katalog & Resep) pada navigasi operasional ERP.</li>
                        <li>Memiliki rute mandiri <code class="font-mono text-[11px] text-primary-600">assortments.index</code> yang mendukung matriks seleksi cepat.</li>
                        <li>Konfigurasi terdaftar terpusat di <code class="font-mono text-[11px] text-primary-600">config/navigation.php</code> sehingga mudah dipindahkan ke tab konteks bila dikehendaki di masa depan.</li>
                    </ul>
                </div>
            </div>

            <div class="p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700/80 space-y-2">
                <div class="flex items-center gap-2 text-xs font-bold text-apeiron-ink dark:text-zinc-200">
                    <span class="material-symbols-rounded text-emerald-600 text-base">check_circle</span>
                    Rekomendasi Arsitek & Status Eksekusi:
                </div>
                <p class="text-xs text-zinc-500">
                    Dipertahankan sebagai destinasi mandiri di bawah Pilar Katalog & Resep. Pengujian hak akses untuk peran Owner, Manager, dan Inventory terverifikasi 100% pada suite pengujian sidebar.
                </p>
            </div>
        </div>
    </div>
</div>
