# 11 — UI/UX Priority Roadmap

Urutan disesuaikan dengan dependensi aktual (token → komponen → halaman), bukan urutan baku brief.

## Phase 0 — Guardrail (COMPLETED — 2026-11-02)
1. [x] Matikan/flag `preview` DevDojo di non-local (`config/components.php` via `COMPONENTS_PREVIEW`) (DD-R2).
2. [x] Dokumentasikan konvensi di CLAUDE/AGENTS & APEIRON_GEMINI_ENGINEERING_RULES: token resmi, larangan hex baru (Zero Hardcoded Hex Policy).
3. [x] Buat error pages kustom 403/404/500 mengikuti design token (HI-04) — `resources/views/errors/{layout,403,404,500}.blade.php` teruji via `CustomErrorPagesTest` (3/3 PASS).

## Phase 1 — Foundation (COMPLETED — 2026-11-02)
1. [x] **Konsolidasi token** → `resources/css/tokens.css` sebagai Single Source of Truth; `@theme inline` tunggal (AC-T1); alias deprecated dipertahankan (`rounded-medium/small/large`, `apeiron-*`) (LI-01).
2. [x] **Sweep hardcoded hex di komponen reusable**: 0 hardcoded hex di `resources/views/components/**`, pagination vendor `tailwind.blade.php`, dan `swal-bridge.js` (AC-T2, LI-03).
3. [x] **Primitive `<x-ui.icon>`**: variation settings terpusat, support sizes xs/sm/md/lg/xl/2xl, a11y role/aria-label (LI-08), teruji di `DevDojoComponentsIntegrationTest`.
4. [x] **Utility `.numeric`**: font-mono + tabular-nums di `tokens.css`, diterapkan di `stat-card`, KPI dashboard, dan vendor pagination (LI-07).
5. [x] **Dark mode dormant**: token set `.dark` dipertahankan dalam `tokens.css` namun dinonaktifkan (`color-scheme: light`) sampai AC-TH1 (TH-01).
6. [x] **Verifikasi**: PHPUnit 35 tests, 411 assertions PASS; Larastan 0 errors pada file tersentuh.

## Phase 2 — Core Interaction (COMPLETED — 2026-11-02)
1. [x] **`x-erp.form-field` + migrasi legacy inputs**: `form-field.blade.php` (label/required asterisk + sr-only/error/hint wrapper) sesuai spesifikasi 09 §4; legacy `form-input` & `form-label` dimigrasikan meneruskan ke `ui.input` & `ui.label`; modal delete-user dimigrasikan ke `ui.modal` (LI-02, FM-03, AC-C1).
2. [x] **`x-erp.searchable-select`**: Alpine listbox, W3C ARIA 1.2 Combobox pattern (`role="combobox"`, `role="listbox"`, `role="option"`, `aria-selected`), roving keyboard navigation, debounce 300ms, clearable, token styling. Mengganti native `<select>` pada 5 form prioritas: PO lines & supplier select, Journal entry lines, Sales quotation customer, Supplier manager item select, GRN PO select (FM-01).
3. [x] **Satukan confirm dialog (IX-01)**: `x-erp.confirm-dialog` mendukung mode form-submit (`confirmSubmit(formId, itemName, options)`) + bridge `window.confirmDelete`. 9 file pemakai sweetalert disweep ke `confirmSubmit`; `resources/views/partials/sweetalert-confirm-delete.blade.php` dihapus setelah 0 referensi.
4. [x] **Standarisasi toast dispatch (IX-04)**: Dibuat trait `app/Livewire/Concerns/InteractsWithToast.php` (`toast()`, `toastSuccess()`, `toastError()`, `toastWarning()`, `toastInfo()`) yang mendispatch browser event `toast` untuk ditangkap `<x-ui.toast>`.
5. [x] **`x-erp.dirty-guard` (FM-05)**: Guard navigasi tab close/reload (`beforeunload`) dan Livewire SPA transition (`livewire:navigate`), diterapkan pada form panjang: PO, GRN, Sales Quotation, dan Journal Entry.
6. [x] **Command palette global Ctrl+K / Cmd+K (IX-05)**: Diaktifkan via `x-erp.command-palette` di `layouts/app.blade.php`, mengonsumsi `config('navigation.sections')` berbasis peran tenant secara dinamis dan aman, dengan guard anti-bentrok shortcut POS terminal modal.
7. [x] **Verifikasi**: PHPUnit 29 tests (21 komponen + isolasi tenant, 8 supplier management) PASS; Larastan 0 errors pada file tersentuh.

## Phase 3 — Enterprise Data Interfaces (2–3 minggu)
1. `x-erp.data-table` composed + traits `WithDataTable/WithBulkActions` (DT-01).
2. Migrasi manager besar dulu: POS order-history, PO, GRN, inventory 8 manager, journal entry.
3. Standarkan empty/skeleton/error state tabel (DT-03).
4. Bulk actions + konfirmasi kedua + audit-trail badge (RE-02).
5. Mobile card-list untuk halaman yang `hideOnMobile` (RS-02).

## Phase 4 — ERP Module Consistency (berjalan paralel Gelombang 7)
1. Dashboard refactor ke page-header + stat-card (LA-06/LI-09).
2. RBI (Modul 15) langsung dibangun di atas design system baru — jangan tunggu semua modul.
3. Finance/HR/Purchasing sweep hex & komponen legacy.
4. POS: audit icon-touch size, tester tablet.

## Phase 5 — Quality (berkelanjutan)
1. axe-core smoke via browser-automation skill pada 10 halaman kritis (AX-02 dsb).
2. Visual regression (opsional: screenshot diff Playwright) untuk komponen ui.
3. Uji Safari iOS customer flow, tablet POS, KDS TV 1080p/4K.
4. Metrik penerimaan → dokumen 13.

Estimasi total ~8–10 minggu efektif part-time, atau 3–4 minggu full-focus. Bisa dihentikan aman di akhir tiap phase (token konsolidasi adalah satu-satunya langkah "all-or-nothing" — rencanakan freeze CSS semalam).
