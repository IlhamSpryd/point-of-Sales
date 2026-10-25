# LAPORAN AUDIT UI/UX SISTEM APEIRON POS
## Evaluasi Desain Antarmuka, Arsitektur UX, Aksesibilitas, dan Konsistensi SaaS Enterprise

---

## 1. Kontrol Dokumen

| Atribut | Rincian |
|---|---|
| **ID Dokumen** | `APEIRON-AUDIT-UIUX-001` |
| **Versi Dokumen** | `1.0.0` |
| **Status Dokumen** | `SELESAI (IMPLEMENTED & AUDITED)` |
| **Tanggal Audit** | 25 Oktober 2026 |
| **Peran Auditor** | Senior UI/UX Designer, UX Architect, & Senior Frontend Engineer |
| **Ruang Lingkup Aplikasi** | Dashboard, Kerangka Utama (Layout & Navigasi), Komponen Antarmuka Bersama, dan Konsistensi Visual |
| **Basis Kode** | Laravel 13, Livewire 4, Alpine.js 3, Tailwind CSS v4 (@theme directive) |

---

## 2. Ringkasan Eksekutif Kondisi UI/UX

Apeiron POS merupakan platform Point of Sale (POS) multi-tenant modern dengan arsitektur berbasis modular. Sebelum audit ini dilaksanakan, antarmuka telah mengadopsi pendekatan tipografi *editorial-clean* bergaya Notion/Linear dengan palet netral (monochrome ink). Namun, pengujian empiris dan inspeksi kode langsung menemukan sejumlah kendala UI/UX kritis dan sistemik pada lapisan penyajian:

1. **Kegagalan Rendering Paginasi Akibat Token Usang (`yovel-*`)**: Komponen paginasi bawaan Livewire dan Laravel merujuk pada token lama yang tidak terdefinisi pada Tailwind v4 `@theme`, menyebabkan kontrol paginasi kehilangan border, background hover, dan kontras teks di seluruh aplikasi.
2. **Ketiadaan Aksesibilitas Tabel pada Perangkat Mobile/Tablet**: Komponen bersama `<x-data-table>` secara kaku mengimplementasikan class `hidden lg:block` pada root pembungkusnya. Dari 24 layar yang menggunakannya, 18 layar tidak menyediakan tampilan kartu mobile alternatif, mengakibatkan data tabel hilang total pada perangkat berlayar di bawah 1024px.
3. **Kekosongan Hirarki Navigasi & Aksi Operasional pada Dashboard**: Header dashboard tidak memiliki breadcrumb, tidak menampilkan status konteks cabang secara langsung, tidak menampilkan notifikasi flash pesan sesi (`session('success')`/`session('error')`), serta minim pintasan alur operasional utama kasir/manajer.
4. **Pola Transisi Tanpa Indikator Loading**: Perpindahan halaman SPA melalui `wire:navigate` tidak disertai visual progress bar, mengurangi kepastian respons sistem bagi pengguna.
5. **Kurangnya Standar Aksesibilitas (WCAG AA)**: Ketiadaan *skip link* navigasi (`#main-content`), penutupan dropdown via keyboard Escape, serta ketiadaan komponen breadcrumb bersama.

Seluruh temuan telah diidentifikasi, diklasifikasikan tingkat keparahannya, dan diperbaiki secara bertahap tanpa menyentuh lapisan *business logic*, skema database, akuntansi stok, maupun remediasi konkurensi A0/A8.

---

## 3. Matriks Temuan Audit UI/UX

| ID Temuan | Komponen / Layar | Tingkat Keparahan | Dampak terhadap Pengguna | Lokasi Kode | Rekomendasi Perbaikan | Status |
|---|---|---|---|---|---|---|
| **UX-001** | Vendor Pagination (`livewire/tailwind`, `pagination/tailwind`, `livewire/simple-tailwind`) | **Kritis** | Paginasi pada semua tabel data kehilangan styling warna, border, dan hover karena menggunakan class lama `yovel-*` yang tidak ada di CSS. | `resources/views/vendor/livewire/tailwind.blade.php`<br>`resources/views/vendor/pagination/tailwind.blade.php`<br>`resources/views/vendor/livewire/simple-tailwind.blade.php` | Migrasikan seluruh class token usang ke token resmi Apeiron (`text-[#6B6A66]`, `border-[#E9E9E7]`, `bg-[#202020]`, `bg-[#F1F1EF]`). | **Selesai** |
| **UX-002** | Komponen Bersama `<x-data-table>` | **Kritis** | Tabel data pada 18 modul (Cabang, Diskon, Pajak, Satuan, Perangkat, Log Aktivitas, dll.) hilang total pada perangkat tablet/mobile (< 1024px) akibat hardcoded `hidden lg:block`. | `resources/views/components/data-table.blade.php:1` | Ubah container menjadi `overflow-x-auto w-full custom-scrollbar` dan sediakan opsi `:hideOnMobile="true"` hanya untuk view yang memiliki alternatif card-list. | **Selesai** |
| **UX-003** | Topbar & Header Dashboard | **Sedang** | Ketiadaan breadcrumb hierarkis, tag konteks cabang aktif di header, dan ketiadaan tombol aksi cepat menuju terminal kasir utama. | `resources/views/dashboard.blade.php:4-15` | Pasang `<x-breadcrumb>`, tambahkan badge status cabang aktif, dan sediakan tombol aksi "Buka Kasir" di topbar. | **Selesai** |
| **UX-004** | Dashboard Flash Message Handling | **Tinggi** | Pengalihan sesi dengan pesan sukses/error (seperti setelah switch cabang atau ubah konfigurasi) tidak ditampilkan di dashboard (pesan ditelan secara senyap). | `resources/views/dashboard.blade.php` | Pasang penangkap flash message `<x-alert type="success/error/warning">` pada bagian atas konten dashboard. | **Selesai** |
| **UX-005** | Pusat Kerja & Pintasan Operasional Dashboard | **Sedang** | Dashboard bersifat pasif metrik; pengguna (Owner/Manager) harus membuka menu sidebar panjang untuk mengakses Kasir, Pesanan, Inventori, dan Laporan Penjualan. | `resources/views/dashboard.blade.php:20-50` | Tambahkan grid pintasan "Pusat Kerja Operasional" (Kasir POS, Riwayat Pesanan, Inventori & Stok, Laporan Penjualan). | **Selesai** |
| **UX-006** | Empty State Transaksi & Restock Dashboard | **Sedang** | Tampilan empty state default saat belum ada data transaksi atau prakiraan stok minim aksi lanjutan dan tidak membimbing pengguna baru. | `resources/views/dashboard.blade.php:218-223` | Sediakan empty state terstruktur dengan ikon representatif, teks panduan jelas, dan tombol call-to-action "Buat Transaksi Baru". | **Selesai** |
| **UX-007** | Indikator Loading Transisi Halaman `wire:navigate` | **Sedang** | Pengguna tidak mendapat umpan balik visual saat mengklik link SPA `wire:navigate` hingga request selesai di-render. | `resources/views/layouts/app.blade.php:48-52` | Pasang top loading progress bar reaktif berbasis event `livewire:navigating` dan `livewire:navigated`. | **Selesai** |
| **UX-008** | Aksesibilitas Keyboard (Skip Navigation Link) | **Rendah** | Pengguna assistive technology / keyboard harus menekan tombol Tab berkali-kali melewati seluruh sidebar sebelum mencapai area kerja utama. | `resources/views/layouts/app.blade.php:48` | Tambahkan skip navigation link tersembunyi yang muncul saat fokus: `<a href="#main-content">Lewati ke konten utama</a>`. | **Selesai** |
| **UX-009** | Aksesibilitas Keyboard Dropdown (Escape Key) | **Rendah** | Menu dropdown tidak dapat ditutup menggunakan tombol Escape keyboard. | `resources/views/components/dropdown.blade.php:16` | Tambahkan listener `@keydown.escape.window="open = false"` pada root Alpine dropdown. | **Selesai** |
| **UX-010** | Komponen Alert & Toast Varian Semantik | **Sedang** | `components/alert.blade.php` hanya mendukung ternary success/error biner (tipe warning dan info terdistorsi menjadi merah). `components/toast.blade.php` memakai class tidak valid (`text-success-800`). | `resources/views/components/alert.blade.php:8-16`<br>`resources/views/components/toast.blade.php:28` | Perbaiki mapping varian `success`, `error/danger`, `warning`, `info` dengan warna semantik standar Tailwind (`emerald`, `rose`, `amber`, `sky`). | **Selesai** |
| **UX-011** | Teks Jumlah Cabang Hardcoded di Branch Switcher | **Rendah** | Informasi teks opsi "Semua Cabang (Konsolidasi)" terkunci pada string "Total akumulasi seluruh 8 cabang", tidak mencerminkan data aktual tenant. | `resources/views/components/branch-switcher.blade.php:66` | Ganti menjadi string dinamis `Total akumulasi seluruh {{ count($stores) }} cabang`. | **Selesai** |
| **UX-012** | Ketiadaan Komponen Breadcrumb Terstandarisasi | **Sedang** | Aplikasi tidak memiliki komponen breadcrumb bersama sehingga halaman-halaman tidak konsisten dalam menampilkan hierarki lokasi. | `resources/views/components/breadcrumb.blade.php` (baru) | Buat komponen `<x-breadcrumb>` reusable berstandar WAI-ARIA `aria-label="Breadcrumb"`. | **Selesai** |
| **UX-013** | Penyelarasan Ketinggian Komponen Pencarian | **Rendah** | Komponen `search-input` memiliki tinggi `h-11` (44px) yang sedikit tidak rata saat disandingkan dengan tombol toolbar `h-10` (40px). | `resources/views/components/search-input.blade.php:6` | Selaraskan ketinggian container input menjadi `h-10` dengan border dan padding yang proporsional. | **Selesai** |
| **UX-014** | Kelengkapan Varian Status Badge Transaksi | **Rendah** | Komponen `<x-badge>` belum memiliki alias pemetaan status transaksi operasional (`paid`, `completed`, `pending`, `cancelled`, `void`). | `resources/views/components/badge.blade.php:19-35` | Tambahkan alias status operasional POS ke dalam variants dan dotColors badge. | **Selesai** |
| **UX-015** | Ketergantungan Font & Ikon Eksternal (Google Fonts CDN) | **Sedang** | Ketergantungan terhadap `fonts.googleapis.com` dan `fonts.gstatic.com` menyebabkan ikon dan tipografi gagal tampil saat workstation kasir offline atau jaringan terputus. | `resources/css/app.css:1`<br>`resources/views/layouts/app.blade.php`<br>`resources/views/layouts/guest.blade.php`<br>`resources/views/layouts/customer.blade.php`<br>`resources/views/kds/index.blade.php`<br>`resources/views/customer/menu/index.blade.php` | Unduh aset WOFF2 lokal (`material-symbols-rounded`, `plus-jakarta-sans`, `playfair-display`) ke `public/fonts/`, definisikan `@font-face` lokal di `resources/css/fonts.css`, daftarkan ke cache Service Worker (`public/sw.js`), dan gunakan preload lokal. | **Selesai** |

---

## 4. Evaluasi Rinci Berdasarkan Layar & Komponen

### 4.1. P0: Dashboard (`resources/views/dashboard.blade.php`)
- **Hierarki Visual**: Metrik primer (Total Pendapatan) dan kurva 7 hari disajikan secara jelas di bagian atas, diikuti 3 kartu KPI sekunder (Total Transaksi, Terjual Bulan Ini, Stok Tipis), serta 2 kolom analitik operasional (Transaksi Terbaru & Distribusi Pembayaran / Prakiraan Restock).
- **Aksi Cepat Operasional**: Menambahkan 4 tautan utama ke alur kerja harian (Kasir, Riwayat Pesanan, Inventori Bahan Baku, dan Laporan Penjualan) agar pengguna tidak terisolasi hanya pada metrik pasif.
- **Interaktivitas & Konteks**: Kartu KPI kini berfungsi sebagai navigasi aktif menuju modul terkait (misal klik Total Transaksi langsung membuka riwayat pesanan).
- **Integritas Bisnis**: Seluruh data dan metrik berasal langsung dari agregasi valid `DashboardService` tanpa manipulasi data buatan atau angka fiktif. Seluruh teks jangkar pengujian (`DashboardV2RedesignTest`) dipertahankan 100%.

### 4.2. P0: Kerangka Utama & Navigasi (`layouts/app.blade.php`, `layouts/navigation.blade.php`)
- **Aksesibilitas**: Menambahkan skip navigation link `#main-content` untuk memfasilitasi pengguna papan ketik.
- **Feedback Navigasi**: Progress bar tipis (2px) warna gelap ink terpasang di bagian atas layar untuk memberikan sinyal instan saat transisi SPA `wire:navigate` sedang memproses permintaan.
- **Konsistensi Sidebar**: Sidebar mendukung mode terbuka penuh (desktop `w-64`, mobile drawer `w-72`) dan mode ciut desktop (`w-18`). Branch switcher terintegrasi rapi dengan tooltip native dan tombol pintas saat diciutkan.

### 4.3. P1: Komponen Antarmuka Bersama (`resources/views/components/`)
- **Komponen Tabel (`<x-data-table>`)**: Menghilangkan batasan `hidden lg:block` default, mengaktifkan `overflow-x-auto` dengan scrollbar halus, dan mempertahankan `:hideOnMobile="true"` secara eksplisit pada 6 view yang memiliki kartu mobile kustom (`tables/index`, `roles/index`, `order-history`, `ingredient-manager`, `users/index`, `categories/index`).
- **Komponen Alert & Toast**: Menyempurnakan pemetaan palet warna `emerald` (sukses), `rose` (bahaya/error), `amber` (peringatan), dan `sky` (informasi) dengan ikon Material Symbols yang konsisten.
- **Komponen Paginasi**: Menghilangkan seluruh token `yovel-*` yang mati pada Tailwind v4 dan menggantinya dengan styling modern, rapi, dan kontras WCAG AA.
- **Komponen Breadcrumb**: Komponen baru `<x-breadcrumb>` menyediakan navigasi hierarkis ramah aksesibilitas dengan dukungan penuh ikon dan link SPA.

---

## 5. Kesimpulan Audit

Seluruh temuan P0 dan P1 berhasil diidentifikasi, dimitigasi, dan diverifikasi. Tidak ada pelanggaran batas bisnis, isolasi multi-tenant, atau konflik dengan pekerjaan remediasi konkurensi A0/A8. Antarmuka Apeiron POS kini memiliki stabilitas visual, aksesibilitas, dan responsivitas yang selaras dengan standar SaaS enterprise modern.
