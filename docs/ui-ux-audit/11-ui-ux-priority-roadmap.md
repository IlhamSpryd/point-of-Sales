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

## Phase 3 — Enterprise Data Interfaces (COMPLETED — 2026-11-02)
1. [x] **`x-erp.data-table` composed + traits `WithSorting/WithBulkActions` (DT-01, LI-04)**: Dibuat `components/erp/data-table.blade.php` dengan sticky thead, sorting indicator otomatis, skeleton loading rows saat `wire:loading` (`loadingTarget` + `skeletonRows`), auto pagination via `:rows`, mobile fallback (`hideOnMobile`), dan W3C a11y empty state. Legacy `components/data-table.blade.php` dihapus setelah 0 referensi (LI-04).
2. [x] **Migrasi seluruh tabel & manager (DT-01, DT-02, DT-03)**: Total 18+ view dimigrasikan: POS order-history, PO manager, GRN manager, 8 manager inventory (ingredient, ledger, opname, transfer, adjustment, receipt, supplier return, modifier), journal entry, customer manager, tables, price lists, roles, users, invitations, categories, dan products.
3. [x] **Standarisasi empty/skeleton state tabel (DT-03)**: Integrasi `<x-ui.empty-state>` pada `:is-empty` atau `$rows->isEmpty()` dengan `colspan` dinamis dan skeleton pulsing rows.
4. [x] **Bulk actions + isolasi tenant (RE-02, RK-07)**: Trait `WithBulkActions` dan `WithSorting` bertindak murni di level UI state Livewire (`$selected`, `$selectAll`, `$sortField`, `$sortDirection`) tanpa menyentuh tenant scoping DB.
5. [x] **Mobile card-list untuk halaman `hideOnMobile` (RS-02)**: Dipertahankan dan distandarkan menggunakan semantic token (`bg-card`, `border-border`, `text-foreground`, `text-muted-foreground`).
6. [x] **Verifikasi**: PHPUnit 25 tests (`DevDojoComponentsIntegrationTest` 23/23 PASS, `LivewireTenancyIsolationTest` 2/2 PASS); Larastan 0 errors pada concerns dan purchasing managers.

## Phase 4 — ERP Module Consistency (COMPLETED — 2026-11-02)
1. [x] **Dashboard refactor ke `x-erp.page-header` + `x-erp.stat-card` (LA-06, LI-09)**: `dashboard.blade.php` direfaktor menghilangkan padding oversized (p-9) dan rounded-3xl menjadi enterprise density (`p-4 sm:p-6 lg:p-8`, `rounded-xl`), header disatukan ke `<x-erp.page-header>`, 3 KPI utama memakai `<x-erp.stat-card>`, dan seluruh arbitrary hex di histogram/spline chart, feature cards, serta operational details disubstitusi dengan token semantik (`card-surface`, `bg-background`, `border-border`, `text-foreground`, `text-muted-foreground`). Teruji via `DashboardV2RedesignTest` + `DashboardMetricsAndButtonsTest` 8/8 PASS (173 assertions).
2. [x] **Manager shell L4 `<x-erp.shell>` (LA-02, LA-03)**: Dibuat `resources/views/components/erp/shell.blade.php` menggabungkan page-header, breadcrumbs, filter slot, dan table body untuk standardisasi scaffolding manager Livewire. Teruji via `DevDojoComponentsIntegrationTest`.
3. [x] **Finance, HR, dan Purchasing sweep hex & token (LI-03, LA-02)**: Seluruh arbitrary hex (`border-[#E5E7EB] dark:border-zinc-800`, `bg-[#171717]`, dsb) di `livewire/finance/` (accounting period, COA, journal entry, bank reconciliation), `livewire/purchasing/` (GRN, PO, supplier, purchase return, supplier invoice), dan `livewire/hr/` (sub-nav) dibersihkan tuntas ke 0 hit.
4. [x] **POS touch target audit & size `icon-touch` (AX-04, RS-03)**: Menambahkan varian size `icon-touch` (`size-11 min-w-11 min-h-11 p-0 rounded-xl`) pada `<x-ui.button>` untuk memenuhi standar WCAG 2.5.5 / 2.5.8 touch target (>= 44x44px) bagi tablet POS dan kasir.
5. [x] **RBI (Modul 15) alignment**: Menggunakan token SSOT dan struktur komponen L1–L4 tanpa mengganggu proses paralel Wave 7.
6. [x] **Verifikasi**: PHPUnit 33 tests (`DevDojoComponentsIntegrationTest` 25/25 PASS, `DashboardV2RedesignTest` + `DashboardMetricsAndButtonsTest` 8/8 PASS); Larastan 0 errors.

## Phase 5 — Quality & Acceptance Checklist (COMPLETED — 2026-11-02)
1. [x] **Audit & Acceptance Criteria Checklist (13-ui-ux-acceptance-criteria.md)**: Seluruh kriteria AC-T1 s.d. AC-T4 (Token/Visual), AC-C1 s.d. AC-C5 (Komponen), AC-IX1 s.d. AC-IX2 & AC-AX1 s.d. AC-AX2 (Interaksi/A11y), AC-TH1 & AC-RS1 s.d. AC-RS2 (Theme/Responsif), serta AC-P1 s.d. AC-P4 (Proses) telah diverifikasi dan berstatus **PASSED**.
2. [x] **A11y & Touch Standards (AX-04, RS-01)**: Ditambahkan ukuran `icon-touch` (44x44px) memenuhi WCAG 2.5.5 pada `<x-ui.button>`, W3C Combobox ARIA 1.2 pada `<x-erp.searchable-select>`, dan skip-links/landmarks tervalidasi.
3. [x] **Zero Regressions & Static Analysis (AC-P1, AC-P2)**: PHPUnit test suite pada seluruh test yang disentuh lulus 100% (DevDojoComponents, Tenancy, Error Pages, Purchasing, Dashboard, POS lifecycle, Sidebar integrity); Larastan 0 errors.
4. [x] **Zero New Dependencies (AC-P4)**: 0 paket composer/npm baru ditambahkan.

