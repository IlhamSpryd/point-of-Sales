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

## Phase 2 — Core Interaction (2–3 minggu)
1. `x-erp.form-field` + migrasi form-input/form-label → ui (LI-02, FM-03).
2. `x-erp.searchable-select` + replace native select pada 5 form tertinggi (FM-01).
3. Satukan confirm dialog (IX-01): tambah mode form-submit, sweep 9 file swal, deprecate partial.
4. Standarkan toast dispatch untuk aksi Livewire (IX-04).
5. `x-erp.dirty-guard` untuk form panjang (FM-05).
6. Konvensi button loading/target di semua aksi Livewire (IX-03).
7. Aktifkan command palette Ctrl+K navigasi (IX-05) — aset sudah ada.

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
