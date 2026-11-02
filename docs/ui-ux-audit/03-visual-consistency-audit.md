# 03 — Visual Consistency Audit

## LI-01 — Dua sistem desain token paralel · HIGH · VERIFIED

- **File:** `resources/css/app.css` (@theme Tailwind v4) vs `resources/css/components.css` (`:root` + `@theme inline`).
- **Bukti:** `app.css` mendefinisikan `--radius-control: 8px; --radius-card: 12px; --radius-dialog: 16px` dan gray scale campuran **neutral (#f5f5f5…) + Tailwind gray (#1f2937…)**. `components.css` mendefinisikan `--radius-small: 6px; --radius-medium: 8px; --radius-large: 12px` dan gray/zinc Tailwind. Keduanya mendefinisikan `--color-border` dengan nilai sama tapi jalur berbeda; `--color-primary` di app.css = skala charcoal, di components.css `--primary: #202123`.
- **Dampak:** developer tidak tahu token mana yang "resmi"; `rounded-medium` (34 pemakaian) vs `rounded-lg` (391) menghasilkan radius 8px via jalur berbeda; dark mode `.dark` hanya menimpa token components.css.
- **Arah:** konsolidasi ke SATU file token (rekomendasi `tokens.css`) yang di-import app.css; petakan alias lama → baru; hapus duplikat.

## LI-02 — Warisan komponen form ganda · HIGH · VERIFIED

- `<x-form-input>`/`<x-form-label>` (Breeze-era, 71/138 pemakaian) vs `<x-ui.input>`/`<x-ui.label>` (105/150). Keduanya hidup dan tampak berbeda (error state, spacing).
- **Arah:** jadikan ui/* satu-satunya; form-input/form-label menjadi alias deprecate.

## LI-03 — 1.002 hardcoded hex utility · HIGH · VERIFIED

- Grep `text-\[#|bg-\[#|border-\[#` = **1.002 kemunculan**; ditambah `#F7F7F5/#9B9A97/#202020` di 26 file yang TIDAK ada di token manapun (palette "warm charcoal" berbeda dari `#171717/#202123` resmi).
- Konsentrasi: `dashboard.blade.php`, pagination vendor, swal-bridge inline, partial stat-card, invoice/receipt.
- **Dampak:** dark mode & rebrand mustahil tanpa sweep manual; dua nuansa netral bersaing.
- **Arah:** migrasi bertahap ke token semantik (`bg-surface`, `text-foreground`, `border-border`); mulai dari komponen reusable lalu halaman high-traffic.

## LI-04 — Empat implementasi tabel paralel · HIGH · VERIFIED

1. CSS global `.data-table`/`.table-standard` (app.css ~100 baris, lengkap: sticky header, alignment-guarantee, tabular-nums)
2. `components/data-table.blade.php` (14 pakai, sticky head, hardcoded `#E5E7EB/#F9FAFB`)
3. `components/erp/data-table.blade.php` (toolbar+empty-state built-in, **0 pemakaian**)
4. `ui/table/*` (8 sub-komponen shadcn-style, 4 pemakaian)
- **Arah:** satukan menjadi `x-erp.data-table` (composed) yang wrap ui/table primitives; CSS global tetap sebagai fallback styling.

## LI-05 — Radius tidak konsisten lintas halaman · MEDIUM · VERIFIED

- Distribusi: `rounded-xl` 649, `rounded-lg` 391, `rounded-2xl` 296, `rounded-full` 164, `rounded-md` 78, `rounded-medium` 34, `rounded-3xl` 15 (dashboard KPI `lg:rounded-3xl` melanggar aturan radius card 12px), `rounded-[5px]` 10.
- **Arah:** tetapkan peta: control=lg(8), card=xl(12), overlay=2xl(16); larang rounded-3xl untuk card enterprise.

## LI-06 — Halaman investor & halaman customer memakai bahasa visual berbeda · LOW · PARTIALLY VERIFIED

- `investor/index.blade.php` + partials dan shell customer memakai hex berbeda (`#37352F`, `#202020`).
- **Arah:** izinkan brand page berbeda, tapi dokumentasikan sebagai "marketing theme" eksplisit, bukan kebocoran token.

## LI-07 — Tipografi numeric belum diseragamkan · MEDIUM · VERIFIED

- CSS sudah menyediakan `tabular-nums` untuk `.data-table`, tapi angka KPI dashboard memakai `font-extrabold text-3xl lg:text-4xl` tanpa tabular; beberapa nominal di Livewire manager memakai format `number_format` inline berbeda-berbeda (partition per file perlu sweep).
- **Arah:** utility `.numeric` (mono+tabular) untuk semua sel uang & KPI.

## LI-08 — Ikon: satu sistem, pemuatan manual · LOW · VERIFIED

- Material Symbols Rounded self-hosted, 6 nama ikon via grep terbatas (pola `<span class="material-symbols-rounded">name</span>` tersebar ratusan titik; variation settings dikunci di layout).
- Tidak ada komponen `<x-icon name=>` — string ikon duplikat & tidak tervalidasi (typo = kotak kosong).
- **Arah:** buat primitive `<x-ui.icon>` dengan whitelist nama dari ligature yang benar-benar dipakai.

## LI-09 — Density dashboard terlalu "marketing" · MEDIUM · VERIFIED

- `dashboard.blade.php`: card p-6/rounded-3xl/shadow-xs + hover border transition + badge tren — dekoratif vs operasional; kontras dengan density manager Livewire (compact).
- **Arah:** gunakan `x-erp.stat-card` (16 pemakaian sudah benar) sebagai satu-satunya pola KPI.

## LI-10 — Gradient & shadow masih minimal (positif) · INFORMATIONAL · VERIFIED

- Hanya 6 `bg-linear-*`, 41 file memakai shadow-lg+/backdrop-blur (mayoritas overlay & sheet — sah). Tidak ada pola glassmorphism masif. Selaras arah shadcn.

## LI-11 — Dark token tanpa pemetaan penuh · HIGH · VERIFIED (detail di 07)

- `.dark` hanya ada di components.css; token app.css (apeiron-*, status-*, table-header-bg) tidak punya varian gelap → jika `.dark` diaktifkan, komponen non-DevDojo tampil broken.
