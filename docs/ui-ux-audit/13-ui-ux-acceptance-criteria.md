# 13 — UI/UX Acceptance Criteria (terukur)

## A. Token & Visual

| ID | Kriteria | Ukuran |
|---|---|---|
| AC-T1 | Satu file token; tidak ada definisi `--color-*`/`--radius-*` duplikat | grep `@theme` hanya 1 blok aktif |
| AC-T2 | Hardcoded hex utility di `resources/views/components/**` = **0** | grep |
| AC-T3 | Hardcoded hex di views halaman < 50 kemunculan (dari 1.002) & hanya di halaman marketing/investor | grep |
| AC-T4 | Semua sel uang & KPI numeric memakai `tabular-nums` | spot-check + grep `.numeric` adoption ≥ tabel utama |

## B. Komponen

| ID | Kriteria |
|---|---|
| AC-C1 | `x-ui.button/input/label/badge/alert/modal/select` satu-satunya implementasi (form-input/form-label/legacy modal = 0 pemakaian) — grep |
| AC-C2 | Tabel memakai `x-erp.data-table` pada ≥ 15 manager utama; `.data-table` CSS manual hanya fallback |
| AC-C3 | SearchableSelect menggantikan native select di 5 form prioritas; keyboard-only operable (uji manual terdokumentasi) |
| AC-C4 | Hanya ada 1 jalur konfirmasi destruktif; `confirmDelete`/swal partial = 0 referensi |
| AC-C5 | Setiap komponen baru punya test render (jumlah test komponen naik, bukan turun) |

## C. Interaksi & A11y

| ID | Kriteria |
|---|---|
| AC-IX1 | Semua aksi mutasi Livewire punya loading state + disabled saat in-flight (sample 20 aksi) |
| AC-IX2 | Semua modal: focus-trap, ESC, return-focus — uji keyboard manual terdokumentasi |
| AC-AX1 | axe-core: 0 critical/serious violation pada 10 halaman kritis (dashboard, POS, PO, journal, product index, dll.) |
| AC-AX2 | Semua input form punya label eksplisit (bukan placeholder-only) pada form prioritas |

## D. Theme & Responsif

| ID | Kriteria |
|---|---|
| AC-TH1 | Dark mode BOLEH diaktifkan tanpa broken surface: toggle + persist bekerja; 10 halaman kunci lolos kontras (keputusan produk) |
| AC-RS1 | POS flow lengkap operable di tablet 10" landscape; customer self-order di iPhone SE (uji manual terdokumentasi) |
| AC-RS2 | Tidak ada kolom kunci (nomor dokumen, total, status) yang hilang di mobile tanpa substitusi |

## E. Proses

| ID | Kriteria |
|---|---|
| AC-P1 | `php artisan test --parallel` hijau setelah tiap fase (zero regression) |
| AC-P2 | Larastan bersih untuk file tersentuh |
| AC-P3 | SidebarIntegrityTest tetap hijau setiap penambahan modul (incl. RBI) |
| AC-P4 | Tidak ada dependensi npm/composer baru tanpa arsitektur approval |
