# 10 — DevDojo Components Evaluation

## 1. Fakta footprint (VERIFIED)

- Instalasi: **path-repository lokal** `packages/devdojo-components` (composer.json `repositories`), versi `^1.0` — dikembangkan/difork internal, bukan CDN vendor luar.
- Isi paket: tooling **Studio** (preview/gallery lokal `src/Http/StudioController.php`, 5 komponen `studio/*`, route `/components` local-only) + **Publisher** (`components:add` menyalin komponen dari `resources/components` ke aplikasi dengan rewrite prefix `<x-ui.`).
- 41 komponen sumber tersedia; **37 telah di-publish** ke `resources/views/components/ui` — dan yang dipakai produksi adalah **salinan yang sudah dimodifikasi lokal** (diff terverifikasi pada `alert`, `badge`, dst: penambahan props `variant|type|dismissible`, style token Apeiron).
- Tema DevDojo di-publish sebagai `resources/css/components.css` (token shadcn-style + `.dark` oklch) — sudah di-import app.css.
- Test integrasi ada: `tests/Feature/DevDojoComponentsIntegrationTest.php` (14 test) — memverifikasi render komponen ui hasil publish.

## 2. Kesesuaian dengan design system tujuan

**Sangat selaras.** Komponen DevDojo yang dipakai sudah (a) ber-nama semantic token shadcn (`bg-card`, `border-border`, `muted-foreground`), (b) memakai twMerge, (c) punya aria/x-trap pada modal. Bedanya dengan shadcn murni hanya implementasi (Blade vs React) — itu sesuai batasan proyek.

## 3. Keputusan per kategori

| Kategori | Komponen | Keputusan |
|---|---|---|
| Retain apa adanya | button, modal, breadcrumbs, label, badge, alert, input, select, kbd, drawer | **RETAIN** — sudah difork & stabil, 900+ pemakaian |
| Retain + modifikasi lanjutan | select (tambah clearable), tabs, toast (standarkan dispatch API) | **ADAPT** |
| Wrap (belum diadopsi) | command, date-picker, dropdown, popover, tooltip, skeleton, switch, checkbox, radio, number-input, avatar, table primitives | **ADOPT-VIA-WRAP** — bungkus dalam composed erp/* (searchable-select membungkus select/input; date-range-picker membungkus date-picker) |
| Ganti | sweetalert flow (bukan DevDojo, tapi terkait) → confirm-dialog tunggal; `components/data-table` lama → erp.data-table | **REPLACE (internal)** |
| Tidak dipakai, biarkan | studio/*, monaco-editor, tiptap, infinite-canvas, rating, slider, splitter | **KEEP-DORMANT** — hanya tersedia via studio; tidak masuk bundle produksi (verify di build) |

## 4. Risiko migrasi

1. **Publisher menimpa** jika `components:add --force` dijalankan ulang → modifikasi lokal hilang. Mitigasi: dokumen "jangan jalankan --force"; atau pin: pindahkan komponen aktif keluar dari mekanisme publish (mereka sudah di app).
2. `preview: true` mendaftarkan namespace preview — matikan di produksi via config (`'preview' => env(...)`) untuk menghindari route `/components` bocor (LOW, local-only saat ini).
3. Token duplikat (components.css vs app.css) — diselesaikan di LI-01; setelah konsolidasi, components.css boleh dihapus total karena komponen hidup di app (tidak lagi dibaca dari vendor).

## 5. Kesimpulan

Jangan keluar dari DevDojo. Perannya bergeser: dari "library komponen" menjadi **tooling studio + sumber publish awal**; kebenaran komponen kini ada di aplikasi. Relasi ini murah (path repo) dan test-nya sudah mengunci perilaku render — pertahankan keduanya.
