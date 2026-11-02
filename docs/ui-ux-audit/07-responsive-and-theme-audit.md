# 07 — Responsive & Theme Audit

## RS-01 — Strategi responsif per konteks sudah benar · VERIFIED (kekuatan)

- Admin shell: `h-dvh overflow-hidden`, sidebar → drawer mobile, fluid rem scaling (14→16px), table scroll horizontal dengan affordance gradient, pagination mobile simple.
- POS: layout terkunci tablet-first, cart sidebar → `mobile-cart-drawer` + bottom-sheet `.sheet-panel`, safe-area inset, overscroll/touch guards, iOS zoom guard 16px.
- KDS: breakpoint 3xl(1920)/4xl(2560) + font scaling per TV — pola langka dan tepat.
- Customer self-order: shell terpisah, bottom-sheet, pb-safe.

## RS-02 — `hideOnMobile` tabel: data hilang di ponsel tanpa alternatif · MEDIUM · VERIFIED

- `components/data-table` punya prop `hideOnMobile` (`hidden lg:block`) — di ponsel, kolom penting hilang tanpa card-list substitusi.
- **Arah:** untuk halaman yang dipakai Owner di ponsel, sediakan pola card-list (composed `x-erp.mobile-card-list`) atau jangan hide kolom kunci.

## RS-03 — Dialog/overlay di layar kecil · LOW · PARTIALLY VERIFIED

- Modal maxWidth variants ada; bottom-sheet konsisten untuk POS. Modal form panjang di ponsel perlu cek scroll dalam (REQUIRES BROWSER VALIDATION).

## TH-01 — Dark mode dikonfigurasi tapi tidak aktif & tidak lengkap · HIGH · VERIFIED

- `.dark` token set lengkap ADA di `components.css` (oklch), `@custom-variant dark` terdaftar, 1.409 class `dark:` tersebar di views — TAPI:
  1. Tidak ada toggle/theme switcher & tidak ada pemilihan preferensi (grep `classList.*dark` = nihil di app.js; hanya sidebar preference).
  2. Token di `app.css` (`apeiron-*`, `status-*`, `table-header-bg`, `shell`) **tidak punya** pemetaan `.dark` → komponen yang memakai hardcoded token tersebut tidak akan berubah.
  3. 1.002 hardcoded hex (LI-03) tidak terpengaruh dark sama sekali.
- **Kesimpulan:** mengaktifkan `.dark` hari ini = tampilan rusak sebagian. Ini keputusan produk: (a) tunda dark mode & bersihkan dead class, atau (b) tuntaskan token consolidation dulu (Phase 1 roadmap). Jangan aktifkan sebelum LI-01/LI-03 tuntas.

## TH-02 — Tema KDS terpisah dengan benar · VERIFIED

- `data-theme="kds-dark"` menimpa token apeiron-* — pola theming berbasis data-attribute yang benar; jadikan contoh untuk implementasi dark mode global (same mechanism, bukan `.dark` class ganda).

## TH-03 — Chart & status indicator theme-readiness · INFORMATIONAL

- Belum ada chart library di frontend (report memakai tabel/stat-card). Ketika Gelombang 7 menambah chart, pilih lib yang menghormati CSS var token (atau render SVG server-side dengan token).

## RS-04 — Cross-browser · REQUIRES BROWSER VALIDATION

- Fitur modern dipakai: `h-dvh`, `oklch`, `@custom-variant`, CSS nesting tidak terlihat; backdrop-filter. Aman untuk Chromium/modern Safari; POS terminal lokal umumnya Chromium — verifikasi Safari iOS untuk customer self-order.
