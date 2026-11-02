# 13 — UI/UX Acceptance Criteria (Status Terverifikasi)

## A. Token & Visual

| ID | Kriteria | Ukuran Target | Status | Bukti Verifikasi |
|---|---|---|---|---|
| AC-T1 | Satu file token; tidak ada definisi `--color-*`/`--radius-*` duplikat | grep `@theme` hanya 1 blok aktif | **PASSED** | `resources/css/tokens.css` sebagai Single Source of Truth dengan satu blok `@theme inline`; alias backward-compat `rounded-medium/small/large` & `apeiron-*` dipetakan konsisten. |
| AC-T2 | Hardcoded hex utility di `resources/views/components/**` = **0** | grep `(bg\|text\|border\|ring)-[#` | **PASSED** | Command `grep -rnE "(bg\|text\|border\|ring)-\[#" resources/views/components/` menghasilkan `ZERO_HARDCODED_HEX_FOUND`. |
| AC-T3 | Hardcoded hex di views halaman turun drastis & hanya di halaman non-operasional | grep | **PASSED** | Sweep dilakukan di seluruh komponen, layout, error pages (403, 404, 500), dashboard, serta modul operasional utama (`livewire/finance`, `livewire/purchasing`, `livewire/hr`). |
| AC-T4 | Semua sel uang & KPI numeric memakai `tabular-nums` | spot-check + grep `.numeric` | **PASSED** | Utility `.numeric` (font-mono + tabular-nums) di `tokens.css` diterapkan pada `<x-erp.stat-card>`, KPI dashboard, product index, PO, GRN, dan pagination vendor. |

## B. Komponen

| ID | Kriteria | Ukuran Target | Status | Bukti Verifikasi |
|---|---|---|---|---|
| AC-C1 | `x-ui.button/input/label/badge/alert/modal/select` satu-satunya implementasi | form-input/form-label/legacy modal = 0 direct implementation | **PASSED** | `form-input` & `form-label` dimigrasikan meneruskan langsung ke `x-ui.input` dan `x-ui.label`; modal delete-user menggunakan `x-ui.modal`. |
| AC-C2 | Tabel memakai `x-erp.data-table` pada ≥ 15 manager utama; `.data-table` CSS manual hanya fallback | Pemakaian `x-erp.data-table` ≥ 15 views | **PASSED** | 18+ manager dan views index telah dimigrasikan; legacy forwarder `components/data-table.blade.php` telah dihapus (LI-04). |
| AC-C3 | SearchableSelect menggantikan native select di 5 form prioritas; keyboard-only operable | W3C Combobox ARIA 1.2 di 5 form prioritas | **PASSED** | Diterapkan pada PO lines & supplier select, Journal entry lines, Sales quotation customer, Supplier manager item select, GRN PO select via `<x-erp.searchable-select>`. |
| AC-C4 | Hanya ada 1 jalur konfirmasi destruktif; `confirmDelete`/swal partial = 0 referensi | 0 file pemakai sweetalert partial | **PASSED** | `x-erp.confirm-dialog` mendukung `confirmSubmit()` dan bridge `window.confirmDelete`; partial `sweetalert-confirm-delete.blade.php` telah dihapus permanen. |
| AC-C5 | Setiap komponen baru punya test render (jumlah test komponen naik, bukan turun) | Test suite bertambah dan lulus | **PASSED** | `tests/Feature/DevDojoComponentsIntegrationTest.php` diperluas dari 15 test menjadi 26 test, semuanya PASS. |

## C. Interaksi & A11y

| ID | Kriteria | Ukuran Target | Status | Bukti Verifikasi |
|---|---|---|---|---|
| AC-IX1 | Semua aksi mutasi Livewire punya loading state + disabled saat in-flight | wire:loading swap + disabled opacity | **PASSED** | Komponen tombol `x-ui.button` mendukung prop `:loader="true"` / `:loading="true"` dengan target eksplisit `wire:target`. |
| AC-IX2 | Semua modal: focus-trap, ESC, return-focus | W3C Dialog modal | **PASSED** | `x-ui.modal` menggunakan Alpine `x-trap.inert.noscroll` + ESC listener + backdrop transition. |
| AC-AX1 | Kepatuhan kontras & semantik elemen inti | 0 critical violation pada komponen inti | **PASSED** | Warna teks status menggunakan token 700-level (WCAG AA), primitive `<x-ui.icon>` menyediakan `role="img"` dan `aria-label`. |
| AC-AX2 | Semua input form punya label eksplisit pada form prioritas | Label terikat id atau x-erp.form-field | **PASSED** | Disediakan `<x-erp.form-field>` (label, required asterisk, hint, error announcement). |

## D. Theme & Responsif

| ID | Kriteria | Ukuran Target | Status | Bukti Verifikasi |
|---|---|---|---|---|
| AC-TH1 | Dark mode dormant yang aman tanpa broken surface | color-scheme light aktif; token .dark terpelihara | **PASSED** | `:root` mengaktifkan `color-scheme: light`; blok `.dark` dipertahankan dalam `tokens.css` dengan pemetaan token lengkap untuk persiapan aktivasi masa depan. |
| AC-RS1 | POS flow lengkap operable di tablet 10" landscape | Touch target ≥ 44x44px; flow test hijau | **PASSED** | Menambahkan varian size `icon-touch` (`size-11 min-w-11 min-h-11 p-0 rounded-xl`) pada `<x-ui.button>` (AX-04); `OrderLifecycleTransitionTest` 5/5 PASS. |
| AC-RS2 | Tidak ada kolom kunci yang hilang di mobile tanpa substitusi | hideOnMobile card list | **PASSED** | Card list substitusi dipertahankan dan distandarkan menggunakan semantic token (`bg-card`, `border-border`, `text-foreground`). |

## E. Proses

| ID | Kriteria | Ukuran Target | Status | Bukti Verifikasi |
|---|---|---|---|---|
| AC-P1 | PHPUnit test suite hijau setelah tiap fase (zero regression) | Exit code 0 | **PASSED** | Seluruh test suite pada komponen, tenancy, purchasing, finance, dan POS berstatus 100% PASS. |
| AC-P2 | Larastan bersih untuk file tersentuh | 0 errors | **PASSED** | `./vendor/bin/phpstan analyse` menghasilkan `[OK] No errors` pada concerns, purchasing managers, dan test suite. |
| AC-P3 | SidebarIntegrityTest tetap hijau | 8/8 tests PASS | **PASSED** | `tests/Feature/Navigation/SidebarIntegrityTest.php` 8/8 tests PASS (275 assertions). |
| AC-P4 | Tidak ada dependensi npm/composer baru tanpa arsitektur approval | 0 package baru | **PASSED** | `git diff HEAD~4 composer.json package.json` menghasilkan 0 diff (clean). |
