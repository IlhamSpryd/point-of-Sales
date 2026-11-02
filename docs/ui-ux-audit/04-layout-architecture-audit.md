# 04 — Layout Architecture Audit

## LA-01 — Shell tunggal sudah tepat untuk 18 modul · VERIFIED (kekuatan)

- `layouts/app.blade.php` (sidebar + topbar + main scroll area, `pos-layout-locked h-dvh`) adalah satu-satunya shell admin; semua halaman module masuk via `<x-app-layout>`.
- Navigasi **satu sumber kebenaran**: `config/navigation.php` dengan kontrak `label/icon/route/active/roles/scope` + test `tests/Feature/Navigation/SidebarIntegrityTest.php` memaksa sinkron dengan routes & role middleware. Ini pola terbaik di repo — pertahankan dan jadikan template untuk modul baru.
- Sidebar collapse persist di localStorage; mobile drawer dengan fokus awal ke item pertama (aria-controls ada).

## LA-02 — Breadcrumb + PageHeader belum diseragamkan · MEDIUM · VERIFIED

- `x-ui.breadcrumbs` (183 pemakaian) dan `x-erp.page-header` (42) ada, tapi banyak Livewire manager membuat header manual (`<h1>` inline + tombol aksi) tanpa page-header → judul, aksi utama, dan breadcrumb tidak konsisten posisinya antara halaman Controller vs Livewire.
- **Arah:** wajibkan `x-erp.page-header` di semua halaman index Livewire (props: title, description, actions slot, breadcrumb).

## LA-03 — Halaman Livewire manager memakai pola "full-page component + toolbar sendiri" · VERIFIED

- 30+ manager Livewire full-page (POS, inventory 8, purchasing 5, finance 4, HR 6, sales 4, customers 4, pricing 3) masing-masing menyusun: header manual + filter bar manual + tabel. Inkonsistensi padding container & judul antar-manager (LI terkait).
- **Arah:** buat satu layout partial `erp.manager-shell` (page-header + filter-bar + data-table slot) — menghapus ratusan baris boilerplate.

## LA-04 — Nested navigation & modul baru belum punya ruang · INFORMATIONAL

- `config/navigation.php` saat ini 7 section flat (Overview, Operations, dst). Modul Finance/HR/Purchasing sudah masuk. RBI (Gelombang 7) belum punya entri — akan ditambahkan; struktur mendukung tanpa refactor.

## LA-05 — Scroll region & tinggi layar · VERIFIED (kekuatan, 1 catatan)

- `h-dvh overflow-hidden` + area konten scroll internal — cocok untuk POS/tablet. `table-scroll-shadow` memberi affordance scroll horizontal tabel.
- Catatan: halaman panjang (form onboarding) memakai container `min-h-full` berbeda — pastikan satu pola scroll.

## LA-06 — Dashboard layout berlebih dekorasi · MEDIUM (duplikat LI-09)

- Padding p-5→p-9, rounded-3xl, sub-navigation pills — deviasi dari shell lain. Sarankan pakai page-header + stat-card grid standar.

## LA-07 — Guest/customer/KDS shell terpisah dengan benar · VERIFIED

- `layouts/guest.blade.php`, `layouts/customer.blade.php`, KDS shell dengan tema data-attribute — pemisahan konteks tepat; jangan dipaksa satu shell.

## LA-08 — Tidak ada layout khusus print selain receipt · LOW

- Receipt POS sudah ada (`transaction/receipt.blade.php`, ESC/POS + QZ). Laporan PDF ekspor non-faktur belum punya template print-friendly (terkait roadmap Gelombang 7).
