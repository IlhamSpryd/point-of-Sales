# Apeiron Systems — UI/UX Audit & Design System Architecture

> Audit dokumentatif (read-only). Tidak ada kode aplikasi, dependensi, atau konfigurasi
> yang diubah dalam paket ini. Semua temuan berbasis inspeksi langsung repository pada
> branch `chore/refactor-modular-architecture`, HEAD `eacbb04`.

## 1. Scope

Audit mencakup seluruh implementasi UI di `point-of-Sales/`:

| Area | Bukti inspeksi |
|---|---|
| Blade templates | 230 file `resources/views/**/*.blade.php` |
| Livewire views | 57 file `resources/views/livewire/**` (51 mengandung Alpine/Livewire state) |
| Komponen reusable | 24 root components + 37 sub-komponen `components/ui/*` + 7 `components/erp/*` |
| DevDojo Components | 41 komponen sumber di `packages/devdojo-components/resources/components` |
| CSS | `resources/css/app.css` (799 baris), `components.css` (152 baris), `fonts.css` (45 baris) |
| JS | `app.js` (23 baris) + `navigation/`, `motion/`, `animations/`, `vendor/swal-bridge.js` |
| Layouts | `app`, `guest`, `customer`, `navigation` |
| Ikon | Material Symbols Rounded (self-hosted woff2), 134 file memakai class ikon |
| Navigasi | `config/navigation.php` (7 section, single source of truth + test integritas) |

Stack **terverifikasi** (bukan asumsi):

- Laravel `^13.17`, Livewire `^4.4`, PHP `^8.3` (composer.json)
- **Tailwind CSS v4.3.3** via `@tailwindcss/vite` — CSS-first config, **tidak ada `tailwind.config.js`** (token di `@theme` dalam `app.css`)
- Alpine.js `^3.4.2` — dipasok bundel oleh Livewire core, bukan CDN terpisah
- DevDojo Components `^1.0` sebagai path-repository lokal (`packages/devdojo-components`)
- `gehrisandro/tailwind-merge-php` (twMerge) dipakai di komponen
- Vite 8, GSAP 3 (motion), self-hosted SweetAlert2-compatible bridge (`swal-bridge.js`)
- Font: Plus Jakarta Sans + JetBrains Mono (self-hosted, offline-first)

## 2. Metodologi

1. **Stage 1 — Recon:** verifikasi dependency (composer.json/package.json), konfigurasi Tailwind v4, struktur view/CSS/JS.
2. **Stage 2 — Inventory:** pemetaan 102 view unik yang dirender dari routes + controller, 230 file blade, frekuensi pemakaian tiap komponen (grep hit-count).
3. **Stage 3 — Evidence-based audit:** setiap temuan menyertakan path file & bukti grep/reading. Klasifikasi VERIFIED / PARTIALLY VERIFIED / REQUIRES BROWSER VALIDATION / RECOMMENDATION.
4. **Stage 4 — Design system proposal:** arsitektur token & komponen shadcn-inspired yang kompatibel Blade+Livewire+Alpine (tanpa React).
5. **Stage 5 — Roadmap & risk register** berbasis dependensi antar-temuan.
6. **Stage 6 — Validasi silang** antar dokumen.

## 3. Ringkasan Eksekutif

### Penilaian umum

UI Apeiron berada pada **kematangan menengah-tinggi (3.2/5)** — jauh di atas rata-rata
aplikasi Laravel pada umumnya, dengan fondasi yang secara arsitektur **sudah 70% shadcn-inspired**:

- Token semantik shadcn-style (`--background/--foreground/--card/--primary/--muted/--ring`...) sudah ada di `components.css` (light + dark oklch).
- 37 komponen primitif `x-ui.*` yang sudah dipublikasikan dan dimodifikasi lokal (982 pemakaian `<x-ui.*>`), lengkap dengan twMerge, variant API, aria, dan `x-trap` pada modal.
- Layout shell tunggal (`layouts/app.blade.php`) + navigasi terpusat di `config/navigation.php` yang divalidasi test otomatis.
- Motion tokens + `prefers-reduced-motion` handling, safe-area handling, fluid rem scaling, anti iOS auto-zoom — detail operasional POS yang jarang ditemukan.

### Celah utama (detail di dokumen terkait)

1. **Dua sistem token paralel** (`app.css @theme` vs `components.css :root`) dengan nilai radius/gray yang tidak identik → LI-01 (HIGH).
2. **1.002 hardcoded hex class** (`text-[#...]`, `bg-[#...]`) tersebar di 230 view, terkonsentrasi di view page-level & dashboard → LI-03 (HIGH).
3. **Tiga mekanisme konfirmasi destruktif paralel**: `x-erp.confirm-dialog`, `swal-bridge.js` (`confirmDelete`), `wire:confirm` → IX-01 (HIGH).
4. **Dark mode dikonfigurasi tapi tidak pernah diaktifkan** — tidak ada toggle, tidak ada pemetaan `.dark` untuk token `app.css`, 1.409 class `dark:` tersebar tanpa jaminan konsistensi → TH-01 (HIGH).
5. **136 `<select>` native vs 45 `x-ui.select`** — tidak ada combobox/searchable-select enterprise untuk form ERP (pilih produk 66-item, pilih akun COA) → FM-01 (HIGH).
6. **DevDojo = vendor "publish-once"**: komponen yang dipakai sudah di-fork ke `resources/views/components/ui` dan dimodifikasi lokal; paket hanya menyisakan studio/preview + 5 komponen studio → DD (evaluasi di dokumen 10).

### Kekuatan yang harus dipertahankan

- `ReportService` guard range export, idempotency key di checkout POS, `wire:loading` inline pada button, skip-link WCAG di semua layout, pagination vendor sudah dikustomisasi.

## 4. Struktur Dokumen

| File | Isi |
|---|---|
| `01-screen-inventory.md` | Inventaris layar & status implementasi |
| `02-component-inventory.md` | Inventaris & kondisi komponen reusable |
| `03-visual-consistency-audit.md` | Inkonsistensi visual |
| `04-layout-architecture-audit.md` | Arsitektur layout & navigasi |
| `05-forms-and-data-tables-audit.md` | Form & tabel data |
| `06-interaction-and-accessibility-audit.md` | Interaksi & aksesibilitas |
| `07-responsive-and-theme-audit.md` | Responsif & light/dark mode |
| `08-design-system-proposal.md` | Proposal design system (shadcn-inspired) |
| `09-component-architecture-proposal.md` | Arsitektur komponen & API convention |
| `10-devdojo-evaluation.md` | Strategi DevDojo: retain/adapt/replace |
| `11-ui-ux-priority-roadmap.md` | Roadmap remediasi |
| `12-ui-ux-risk-register.md` | Register risiko migrasi |
| `13-ui-ux-acceptance-criteria.md` | Kriteria penerimaan terukur |

## 5. Statistik Temuan

| Severity | Jumlah |
|---|---|
| CRITICAL | 2 |
| HIGH | 11 |
| MEDIUM | 14 |
| LOW | 6 |
| INFORMATIONAL | 5 |
| **Total** | **38** |

Distribusi verifikasi: 24 VERIFIED, 6 PARTIALLY VERIFIED, 4 REQUIRES BROWSER VALIDATION, 4 RECOMMENDATION.

## 6. Batasan Audit

- Tidak ada pengujian browser nyata (screenshot/axe-core) — semua temuan aksesibilitas visual & interaksi ditandai `REQUIRES BROWSER VALIDATION` bila belum pasti.
- Temuan keamanan UI terbatas pada lapisan presentasi; otorisasi backend dianggap sumber kebenaran (tidak diaudit di sini).
