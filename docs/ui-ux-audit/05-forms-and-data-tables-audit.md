# 05 — Forms & Data Tables Audit

## FM-01 — Native `<select>` mendominasi form ERP · HIGH · VERIFIED

- **136 raw `<select>`** vs **45 `x-ui.select`**. Di `purchase-order-manager.blade.php` pemilihan item PO memakai select polos dengan `wire:model.live` + reload penuh.
- Dampak operasional: pemilihan produk/COA/supplier dari puluhan-ratusan opsi tanpa pencarian; operator perlu scroll panjang; salah pilih mudah terjadi.
- **Arah:** composed `x-erp.searchable-select` (Alpine: input filter + listbox keyboard-navigable; wire:model kompatibel; Livewire lazy search via `wire:model` deferred + debounce untuk >100 opsi). Prioritas: PO lines, journal entry lines, customer di sales order.

## FM-02 — Money/numeric input belum seragam · HIGH · VERIFIED

- `x-erp.money-input` benar (prefix Rp, text-right, font-mono tabular, aria-invalid) tapi adopsi rendah; banyak field uang memakai raw `type="number"` tanpa format, dan spinner dimatikan global via CSS (app.css) — ok, tapi tidak ada thousand separator di banyak form.
- **Arah:** wajibkan money-input untuk semua nominal; pertimbangkan input formatting ringan (Alpine, tanpa lib baru) dengan penyimpanan string mentah.

## FM-03 — Validation feedback konsisten di komponen, tidak di raw input · MEDIUM · VERIFIED

- `money-input` & komponen ui menampilkan `$errors->first()` + `aria-invalid`; raw input di manager Livewire tidak menampilkan inline error lokal (`items.0.product_id`) — error bag hanya muncul kalau komponen menanganinya.
- **Arah:** pola wajib: setiap field Livewire bersarang (`items.0.x`) memakai helper `errorKey()` yang sudah ada di money-input → jadikan trait/helper bersama.

## FM-04 — Debounce tidak seragam · LOW · VERIFIED

- 31× `debounce.300ms`, 1× `400ms`, 86× `wire:model.live` tanpa debounce (setiap keystroke = request).
- **Arah:** standar: search 300–400ms; kolom form live-validate 500ms; dokumentasikan.

## FM-05 — Unsaved-changes warning belum ada di form panjang · MEDIUM · REQUIRES BROWSER VALIDATION

- Tidak ditemukan `beforeunload`/dirty-state guard di form Livewire (PO, journal entry, payroll). Resiko kehilangan kerja operator.
- **Arah:** composed `x-erp.dirty-guard` (Alpine: bandingkan snapshot wire:model vs initial; intercept navigasi Livewire + beforeunload).

## FM-06 — Tidak ada multi-step form explorer · INFORMATIONAL

- Onboarding 2 langkah berdiri sendiri (ok). Form ERP kompleks (payroll batch) satu halaman panjang — acceptable untuk operator ahli, cukup beri section grouping.

## DT-01 — Enterprise data-table architecture DIPERLUKAN · HIGH · VERIFIED

Bukti gabungan (LI-04): 4 implementasi tabel, kolom sorting hanya di sebagian manager, sticky header hanya di `components/data-table`, bulk action hampir tidak ada (hanya pattern select-all di 1–2 manager), export terpusat di `x-livewire.async-export-button` (bagus).
- **Arah:** satu `x-erp.data-table` composed: props `columns` (key,label,align,numeric,sortable), slot row, integrasi `x-search-input`, filter-bar, pagination vendor, empty-state, sticky header, density compact; sorting/bulk via Livewire table concern bersama (hati-hati: buat trait `WithSorting`, `WithBulkActions` yang dipakai manager, bukan paket baru).

## DT-02 — Angka & alignment tabel · MEDIUM · VERIFIED

- CSS specificity guard sudah menjamin `.text-right` menang di `.data-table`; `tabular-nums` hanya otomatis di td.text-right — kolom uang di manager yang tidak memakai class `.data-table` (tabel manual) tidak dapat jaminan ini.
- **Arah:** semua tabel wajib lewat komponen; numeric kolom diberi `numeric: true` → otomatis `text-right tabular-nums font-mono`.

## DT-03 — Empty/loading/error state tabel · MEDIUM · PARTIALLY VERIFIED

- `erp/data-table` punya empty state bagus tapi 0 pemakaian; manager Livewire lain menampilkan "tidak ada data" baris polos; loading state memakai wire:loading parcial (21 file) tanpa skeleton tabel.
- **Arah:** skeleton baris standar (ui/skeleton) saat `wire:loading` pertama; empty-state wajib.

## DT-04 — Export UX sudah benar (positif) · VERIFIED

- `AsyncExportButton` + `ExportTask` queue — mencegah blocking & memory blow-up; guard rentang 366 hari di ReportService. Pertahankan sebagai pola resmi export.

## DT-05 — Pagination konsisten · VERIFIED

- Vendor views dikustom (mobile simple/desktop) — hardcoded hex perlu migrasi token (LI-03) tapi strukturnya benar.
