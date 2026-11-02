# 06 — Interaction & Accessibility Audit

## IX-01 — Tiga sistem konfirmasi paralel · HIGH · VERIFIED

1. `x-erp.confirm-dialog` (Alpine, event `erp-confirm`, 3 pemakaian)
2. `swal-bridge.js` + `partials/sweetalert-confirm-delete` → `window.confirmDelete(formId)` (9 file, pola form submit klasik)
3. `wire:confirm` native Livewire (21 pemakaian)
- **Dampak:** UX destruktif tidak konsisten; swal-bridge memakai hex non-token (`#BE123C`, rounded-2xl) sehingga tampak berbeda; dua API berbeda untuk developer.
- **Arah:** `x-erp.confirm-dialog` sebagai satu-satunya (extended: dukung mode form-submit `confirmSubmit(formId)` sehingga menggantikan confirmDelete); deprecate swal-bridge setelah migrasi (ia adalah aset offline-first bagus — jadalkan penghapusan, bukan langsung).

## IX-02 — Focus management hanya di modal & swal · HIGH · REQUIRES BROWSER VALIDATION (sebagian VERIFIED)

- VERIFIED: `ui/modal` memakai `x-trap.inert.noscroll` + aria-modal + labelledby; swal-bridge implement trap + returnFocus manual.
- VERIFIED: drawer mobile sidebar melakukan fokus awal; ESC window listener 12 titik.
- Gap: dropdown manual (banyak) tidak memakai roving tabindex; popover tanpa trap. Sidebar drawer punya aria-controls; overflow menu di tabel manager perlu audit per-halaman.

## IX-03 — Double-submit protection baik di POS, renggang di manager · MEDIUM · VERIFIED

- POS checkout: idempotency key server-side (`CreateOrder::$idempotencyKey`) + disabled state.
- Button component: `wire:loading` swap + disabled opacity — bagus saat dipakai dengan `wire:target`.
- Namun manager lain memanggil aksi via tombol tanpa `loader`/`wire:target` spesifik → user bisa klik 2× sebelum round-trip.
- **Arah:** konvensi: semua action button Livewire wajib `loading` + target eksplisit.

## IX-04 — Toast vs flash alert · MEDIUM · VERIFIED

- Toast component ada (event-driven, 3 pemakaian) tapi mayoritas feedback masih session flash `x-ui.alert` di atas halaman — di Livewire full-page, flash tidak selalu ter-render ulang setelah aksi (perlu dispatch browser event).
- **Arah:** standarkan `$this->dispatch('toast', ...)` untuk semua aksi Livewire; flash hanya untuk redirect penuh.

## IX-05 — Keyboard: shortcut POS ada; global palette menganggur · VERIFIED

- POS partials punya `keyboard-shortcuts.blade.php`; `ui/command` (command palette) lengkap + aria tapi 0 pemakaian — aktifkan untuk navigasi cepat ERP (Ctrl+K) = quick win.

## IX-06 — Live region untuk hasil asinkron · LOW · VERIFIED

- 31 `role="status"/aria-live/aria-busy` — sebagian besar di komponen; hasil pencarian & export toast belum semuanya diumumkan ke screen reader.

## AX-01 — Semantic & landmark baik di shell · VERIFIED

- Skip-link di app & guest layout (WCAG 2.4.1), `<nav role>` pagination, aria-label section dashboard.

## AX-02 — Form label & error announcement · MEDIUM · VERIFIED

- `x-ui.label`/`form-label` ada; namun banyak raw input di Livewire manager tanpa label eksplisit (hanya placeholder) → screen reader kehilangan konteks (perlu sweep; jumlah pasti REQUIRES BROWSER VALIDATION dengan axe).

## AX-03 — Kontras warna status · PARTIALLY VERIFIED

- Token status dipilih dari Tailwind 700-level (`emerald-700` etc.) untuk teks — kontras AA di light ok (dokumentasi komentar CSS menyatakan WCAG AA). Badge memakai 50/600 kombinasi — perlu cek pada badge kecil (REQUIRES BROWSER VALIDATION).

## AX-04 — Touch target · VERIFIED (positif) + 1 catatan

- Fluid rem menjaga target ≥ aman (floor 14px html), iOS zoom guard, `.touch-fast`, safe-area. Catatan: icon button `icon-sm` = 32px < 44px ideal untuk tablet kasir — sediakan size `icon-touch` (44px) untuk mode kasir.

## IX-07 — UI security & operational safety (dimensi 8) · VERIFIED sebagian

- Positif: `@can` dipakai konsisten di manager (contoh PO: `@can('create', PurchaseOrder::class)`); otorisasi tetap backend (Policy/permission middleware) — frontend visibility bukan boundary. Idempotency pembayaran ada.
- Gap: **bulk action belum punya konfirmasi kedua + audit trail UI** (RE-02, MEDIUM-HIGH saat diaktifkan luas); nominal & diskon di modal manual-discount perlu batasan tampilan (max discount) yang konsisten dengan policy backend — VERIFIED ada guard backend, UI perlu menampilkan batas (REQUIRES BROWSER VALIDATION).
