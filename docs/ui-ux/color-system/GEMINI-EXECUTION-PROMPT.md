# Gemini IDE — Execution Prompt: Apeiron Color System Implementation

> **Cara pakai:** copy seluruh isi blok di bawah `PROMPT MULAI` ke Gemini IDE.
> Jalankan **per fase**, jangan sekali jalan — P0 → P1 → P2 → P3 → P4 → P5.
> Setiap fase punya gate verifikasi; jangan lanjut sebelum gate lulus.

---

## PROMPT MULAI

Kamu adalah Senior Frontend Engineer pada proyek **Apeiron Systems** (Laravel 13 + Livewire 4 +
Alpine 3 + Tailwind CSS v4 CSS-first). Tugasmu **mengimplementasikan** color system yang sudah
direncanakan. Rencananya sudah selesai dan bersifat **normatif** — jangan mendesain ulang,
jangan mengubah nilai token, jangan menambah dependensi.

### 0. BACA DULU (wajib, berurutan — jangan menulis kode sebelum selesai membaca)

1. `/home/ilhamspryd/Projects/apeironSystem/APEIRON_GEMINI_ENGINEERING_RULES.md`
2. `docs/ui-ux-audit/color-system/README.md` — ringkasan, 5 defect utama, keputusan
3. `docs/ui-ux-audit/color-system/01-current-color-audit.md` — baseline terukur
4. `docs/ui-ux-audit/color-system/04-semantic-token-registry.md` — **NORMA**. Jika ada
   pertentangan dengan dokumen lain, file ini yang menang.
5. Fase yang akan kamu kerjakan: `02` (light), `03` (dark), `05` (komponen), `06` (status),
   `07` (tailwind), `08` (theme switching), `09` (roadmap), `10` (acceptance criteria)

Baca **hanya** dokumen yang relevan dengan fase aktif. Untuk P0 → baca `01` §4 + `04` §2.5 + §4.
Untuk P2 → baca `05` + `06` utuh. Untuk P5 → baca `03` + `08` utuh.

### 1. RUANG LINGKUP KERJA

Root aplikasi: **`/home/ilhamspryd/Projects/apeironSystem/point-of-Sales`**
(`marketingPageApeironSystem/` **di luar scope** — jangan disentuh.)

Stack terverifikasi, **jangan diasumsikan lain**:

- Tailwind **v4.3.3** via `@tailwindcss/vite`. **TIDAK ADA `tailwind.config.js` dan JANGAN
  dibuatkan.** Konfigurasi Tailwind ada di `resources/css/tokens.css` (`@theme inline`).
- Entry CSS: `resources/css/app.css` → `fonts.css`, `tailwindcss`, `tokens.css`.
- `twMerge` via `gehrisandro/tailwind-merge-php` — semua komponen `ui/*` sudah memakainya.
- DevDojo Components: path repo lokal, 37 komponen ter-publish di
  `resources/views/components/ui`. **JANGAN** jalankan `components:add --force`.

### 2. ATURAN KERAS (pelanggaran = pekerjaan ditolak)

| # | Aturan |
|---|---|
| R1 | Semua warna harus lewat token semantik. `bg-card`, `text-muted-foreground`, `border-border` — bukan `bg-zinc-100`, bukan `bg-[#E5E7EB]`. |
| R2 | **JANGAN** menambah hex baru. **JANGAN** mengarang token yang tidak ada di `04`. |
| R3 | Hapus `dark:*` utility — token sudah remap sendiri. Jangan menambah `dark:`. |
| R4 | Jangan mengubah struktur Bladelayout. Diff harus **color-only**, kecuali saat menyatukan komponen di P2 (sebutkan eksplisit di ringkasan). |
| R5 | Jangan menambah dependency Composer/NPM. Jangan mengubah `vite.config.js`. |
| R6 | Jangan mengubah `[data-theme="kds-dark"]` — KDS adalah sumbu terpisah (lihat `08` §6). |
| R7 | Jangan mengubah logika bisnis Livewire/Alpine, hanya kelas warna. |
| R8 | Google OAuth brand hex (`#4285F4`, `#EA4335`, `#FBBC05`, `#34A853`) **dibiarkan** — satu-satunya pengecualian literal. |

### 3. BAHAYA AUTOMASI (baca sebelum memakai codemod/sed)

`text-[12px]` dan `text-[#111827]` punya sintaks bracket yang sama.

- Regex warna **harus** match isi bracket: `\[#`, `\[rgb`, `\[hsl`, `\[oklch`.
- Regex yang hanya match `-\[` akan **menghapus seluruh type scale** (`~900` pemakaian).
- Setelah setiap sweep, jalankan: `rg -o -g '*.blade.php' 'text-\[[0-9]+px\]' resources | wc -l`
  — angkanya **tidak boleh turun** (AC-U9).

Kerjakan per-file/manual atau per-pattern dengan verifikasi, jangan blanket replace.

---

## FASE 0 — UNBLOCK (≈0.5 hari)

**Tujuan:** memperbaiki bug aktif — status badge tidak ter-render.

1. `resources/css/tokens.css` → tambahkan ke `:root` blok status lengkap dari `02` §11:
   `--success-muted`, `--success-foreground`, `--success-border` (dan pasangan untuk
   `warning`, `destructive`, `info`, `neutral`), `--surface-inset`, `--focus`, `--disabled`,
   `--disabled-bg`, `--selection`, `--selection-foreground`.
2. `resources/css/tokens.css` → tambahkan mapping ke `@theme inline` sesuai `07` §3
   (`--color-success-muted: var(--success-muted)` dst.).
3. `resources/css/app.css` → tambahkan `@source '../../app/Support/Color';` dan
   `@source '../../app/Enums';` (WAJIB — jika tidak, class dari PHP tidak di-generate).
4. Buat `app/Support/Color/StatusVariant.php` + `app/Support/Color/StatusPalette.php`
   **persis** seperti `04` §4.1 (map lengkap, `variant()`, `badgeClasses()`, `dotClasses()`).
5. Tiga enum: hapus `badgeClass()`, ubah `badgeType()` menjadi
   `return StatusPalette::variant($this)->value;`
   — `app/Enums/PurchaseOrderStatusEnum.php`, `PoReceivingStatusEnum.php`,
   `PoInvoicingStatusEnum.php`. Ganti call site `badgeClass()` dengan `StatusPalette`.
6. `components/badge.blade.php` + `components/ui/badge/index.blade.php` +
   `components/erp/status-badge.blade.php` + `components/status-text.blade.php` →
   hapus semua `match()` status lokal dan array `$textColors`/`$badgeVariants`/`$dotColors`;
   delegasikan ke `StatusPalette`.

**Gate P0:**

```bash
npm run build
CSS=$(ls public/build/assets/app-*.css | head -1)
grep -q -- ".bg-success-muted" "$CSS" && echo OK || echo MISS
grep -c -- ".bg-success-50\|.bg-danger-50\|.bg-primary-100" "$CSS"   # harus 0
rg 'function badgeClass' app/Enums                                  # harus kosong
rg "match.*'(success|warning|danger|info)'" resources/views/components  # harus 0
./vendor/bin/phpunit --filter DevDojoComponentsIntegrationTest
```

---

## FASE 1 — FOUNDATION

1. Terapkan nilai palet light dari `02` §11 ke `:root` (canvas `#FAFAFA`,
   `muted-foreground` **`#6B6B74`** — bukan `#71717A`, lihat `02` §4).
2. Lengkapi `@theme inline` (surfaces, shell, charts, overlay, shadows) per `07` §3.
3. Tambahkan token shell: `--shell*` dan alias `--sidebar-*` → `--shell*` (`04` §3).
4. **Hapus fallback hex di `app.css`**:
   - `.data-table thead th` → `var(--surface-table-header)` tanpa fallback
   - `.data-table tbody tr:hover` → `var(--accent)` (sekarang `--color-surface-secondary`
     **tidak terdefinisi**, jadi selalu jatuh ke `#F9FAFB`)
5. Pindahkan semua warna CSS-level di `app.css` ke token (lihat `01` §5, `08` §10):
   `.chip.ok/.wt/.danger/.stable`, `.delta-badge`, `.restock-bar i`, `.dot-warning`,
   `.dot-success`, `.field-input*`, `.input-error`, `.label-error`, `.editorial-surface`,
   `option { background: var(--popover); color: var(--popover-foreground) }`,
   `.sheet-overlay` (dari `rgb(55 53 47/.4)` warm-brown → `var(--overlay-scrim)`),
   `.spinner`, `.table-scroll-shadow` (hapus gradient putih).
6. `pos-layout-locked`: `@apply ... bg-white` → `bg-background`.
7. Migrasi 9 file `resources/views/auth/**` penuh ke token (canary; semua `zinc-*` → token).

**Gate P1:**

```bash
python3 scratch/contrast_check.py          # exit 0
rg "'#'" resources/css/app.css             # tidak ada hex tersisa (kecuali none)
rg -g '*.blade.php' '(slate|gray|zinc|rose|emerald|amber|blue)-[0-9]{2,3}' resources/views/auth | wc -l  # 0
npm run build && ./vendor/bin/phpunit
```

---

## FASE 2 — PRIMITIVES (≈3–4 hari)

Sweep `resources/views/components/{ui,erp,header,common}/**` — token-only, hapus `dark:`.

1. `x-ui.button` kanonik: hapus `bg-linear-to-b` pada variant primary. Legacy
   (`x-button`, `x-primary-button`, `x-secondary-button`, `x-danger-button`) → forward ke
   `x-ui.button`, jangan dihapus langsung.
2. `x-ui.input`, `x-ui.textarea`, `x-ui.select`, `x-ui.checkbox`, `x-ui.radio`, `x-ui.switch`,
   `x-ui.label` — ikuti tabel state di `05` §3.
3. `x-ui.card`, `x-ui.alert`, `x-ui.modal`, `x-ui.drawer`, `x-ui.dropdown`, `x-ui.popover`,
   `x-ui.tooltip`, `x-ui.tabs`, `x-ui.skeleton`, `x-ui.toast`, `x-ui.empty-state`,
   `x-ui.pagination`, `x-ui.separator`, `x-ui.kbd`, `x-ui.avatar` — `05` §4–§14.
4. `components/erp/*`: `metric-card`, `stat-card`, `page-header`, `filter-bar`, `form-field`,
   `money-input` — icon holder jadi monokrom (`bg-muted text-muted-foreground`), bukan tinted.

**Gate P2:**

```bash
rg -o -g '*.blade.php' 'dark:' resources/views/components | wc -l                    # 0
rg -o -g '*.blade.php' '(bg|text|border|ring)-(slate|gray|zinc|red|rose|emerald|amber|blue|indigo)-[0-9]{2,3}' resources/views/components | wc -l  # 0
rg -o -g '*.blade.php' 'bg-gradient|bg-linear' resources/views/components | wc -l   # 0
./vendor/bin/phpunit
```

---

## FASE 3 — STRUCTURAL

1. `resources/views/layouts/app.blade.php`: semua hex shell → `shell-*` token
   (`bg-[#171717]`, `#202020`, `#2E2E2E`, `#D4D4D4`, `#A3A3A3`, `#737373` — ~78 hit).
2. Hapus tint dekoratif ikon nav: `text-amber-400`, `text-purple-400`, `text-emerald-400`.
   Pengecualian: pill "Online • Cloud Sync" adalah status nyata → token `success`.
3. Tabel: `x-erp.data-table`, `ui/table/*`, `.data-table`/`.table-standard` di `app.css` (`05` §5).
4. Breadcrumbs, pagination, command palette, notification dropdown, user dropdown.
5. Dashboard + KPI: `resources/views/dashboard.blade.php`,
   `livewire/reports/advanced-analytics-dashboard.blade.php` (162 hit), `reports/sales.blade.php`.

**Gate P3:** template `rg` P2 di atas, dijalankan pada `layouts/`, `dashboard`, `reports` → 0.
Screenshot dashboard dark+light untuk review visual.

---

## FASE 4 — MODULE SWEEPS (≈8–12 hari)

Urutan (lihat `09` §P4). **Satu modul = satu commit** agar bisa di-revert terpisah.

| Prioritas | Modul | Path |
|---|---|---|
| 4.1 | POS / Kasir | `livewire/pos/**`, `livewire/kasir/**`, `transaction/` |
| 4.2 | Inventory | `livewire/inventory/**` |
| 4.3 | Finance | `livewire/finance/**`, `finance/**` |
| 4.4 | Purchasing | `livewire/purchasing/**` |
| 4.5 | Sales | `livewire/sales/**` |
| 4.6 | HR | `livewire/hr/**` |
| 4.7 | Customers / Pricing | `livewire/customers/**`, `livewire/pricing/**` |
| 4.8 | Users / Roles / Devices / Stores | `users/`, `roles/`, `devices/`, `stores/` |
| 4.9 | Sisanya | onboarding, orders, tables, brands, categories, units, tax_classes, price_lists, kds, errors, profile, settings, activity_logs |

Khusus **POS**: uji manual di tablet — jangan hanya mengandalkan test. Perhatikan kontras
product grid (sekarang `gray`, harus `zinc`→token) dan status pembayaran.

**Gate per modul:** 0 palette utility di modul tsb + test modul hijau + screenshot tiap layar.

---

## FASE 5 — DARK MODE (≈4–6 hari)

**PRASYARAT (jangan lanjut jika belum semua 0):**

```bash
rg -o -g '*.blade.php' 'dark:' resources | wc -l                      # 0
rg -o -g '*.blade.php' '(bg|text|border|ring)-(slate|gray|zinc|red|orange|amber|yellow|green|emerald|blue|indigo|violet|purple|pink|rose|neutral|stone)-[0-9]{2,3}' resources | wc -l  # 0
rg -o -g '*.blade.php' '(bg|text|border)-\[#' resources | wc -l       # 0
rg -o -g '*.blade.php' 'bg-white|bg-black|text-white|text-black' resources | wc -l  # 0
```

Lalu:

1. Terapkan blok `.dark` dari `03` §10 ke `tokens.css`.
2. Tambahkan inline no-flash script di `<head>` **sebelum** `@vite(...)` pada
   `layouts/app.blade.php`, `guest.blade.php`, `customer.blade.php` — **persis** `08` §3
   (harus inline + `try/catch` + set `colorScheme`).
3. Buat `resources/js/theme/theme-controller.js` (`08` §4), daftarkan di `app.js` pada
   `alpine:init` mengikuti pola `registerGsapAlpineDirectives`.
4. Toggle UI di sidebar footer + user dropdown (ikon netral, tanpa tint).
5. Validasi semua kriteria AC-DK di `10` §7.

**Gate P5:** `python3 scratch/contrast_check.py` exit 0, semua AC-DK lulus, KDS tidak berubah.

---

## 4. DEFINISI SELESAI (semua fase)

Jalankan seluruh suite di `10-color-system-acceptance-criteria.md`:

- AC-C (kontras) lulus light + dark
- AC-U1…AC-U9 mencapai target (U1=0, U3=0, U9 **tidak turun**)
- AC-K1…AC-K7 (1 badge, 1 button, 1 status authority)
- AC-S (konsistensi status lintas modul)
- AC-M per modul dicentang
- AC-V5: screenshot desaturasi → semua informasi tetap terbaca
- AC-P: bundle CSS **tidak membesar**, 0 dependency baru

## 5. FORMAT LAPORAN AKHIR (wajib)

Setelah tiap fase, laporkan:

1. File yang diubah (jumlah + daftar path utama)
2. Output **mentah** dari command gate, bukan ringkasan "sudah lulus"
3. Nilai sebelum → sesudah untuk metrik gate (mis. `dark:` 1,870 → 0)
4. Jika ada deviasi dari plan: sebutkan + alasan + alternatif yang dipertimbangkan
5. Konfirmasi tidak ada dependency/logika bisnis yang berubah

Jika sebuah gate gagal dan kamu tidak bisa memperbaikinya tanpa melanggar `04`, **berhenti
dan laporkan** — jangan mengakali gate atau mengubah token agar test lulus.

---

## PROMPT SELESAI
