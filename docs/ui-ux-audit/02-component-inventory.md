# 02 — Component Inventory

Legend status: `REUSABLE` (ada & konsisten), `INCONSISTENT`, `DUPLICATED`, `MISSING`,
`REDESIGN`, `A11Y-GAP`.

## 1. Sumber komponen saat ini (terverifikasi)

1. **`resources/views/components/ui/*`** — 37 komponen hasil *publish + fork lokal* dari
   DevDojo Components (nama sumber sama, isi sudah dimodifikasi: alert dapat
   `variant|type|dismissible`, button punya `loader/loading/hotkey/topHighlight`, dst).
   Prefix pemakaian `<x-ui.*>`: **982 pemakaian** di 230 view.
2. **`resources/views/components/erp/*`** — 7 komponen domain ERP buatan sendiri:
   `page-header` (42 pakai), `stat-card` (16), `confirm-dialog` (3), `data-table`,
   `filter-bar`, `money-input`, `status-badge`.
3. **`resources/views/components/*.blade.php`** — 24 komponen root warisan Breeze/awal
   (`form-input`, `form-label`, `input-error`, `data-table`, `modal`, `toast`, dst) —
   **sebagian tumpang tindih dengan ui/erp** (LI-02).
4. **DevDojo package** — hanya menyisakan 5 komponen `studio/*` untuk preview; komponen
   nyata sudah hidup di aplikasi (lihat dokumen 10).

## 2. Frekuensi pemakaian `<x-ui.*>` (grep VERIFIED)

| Komponen | Pemakaian |
|---|---|
| button | 291 |
| label | 150 |
| badge | 123 |
| input | 105 |
| breadcrumbs(.item) | 92+91 |
| alert | 85 |
| modal | 68 |
| select | 45 |
| kbd 7, drawer 7, toast 3, empty-state 2, toggle 1, sidebar 1, table.* 4 | jarang |

Komponen ui yang ADA tapi pemakaiannya nyaris nol: `accordion, avatar, card, checkbox,
command, date-picker, dropdown, empty(2 varian!), number-input, otp, pagination, popover,
progress, radio, separator, skeleton, spinner, switch, table(8 sub), tabs, textarea,
tooltip` → indikasi komponen dibuat/publish tapi **belum diadopsi** view (CI-01, MEDIUM).

## 3. Klasifikasi per komponen (dimensi C brief)

| Komponen | Status | Bukti |
|---|---|---|
| Button | REUSABLE (kuat) | variant/size/disabled/loading/hotkey + twMerge; test render |
| Input/Textarea | INCONSISTENT | `x-ui.input` 105 pakai vs `<x-form-input>` 71 pakai vs raw `<input>` tersebar |
| Label | DUPLICATED | `x-ui.label` 150 vs `x-form-label` 138 |
| Select | INCONSISTENT | 45 `x-ui.select` vs **136 raw `<select>`** (FM-01) |
| Checkbox/Radio/Switch/Toggle | MISSING-ADOPS | komponen ada; view memakai raw input; toggle dipakai 1× |
| Combobox/SearchableSelect | MISSING | tidak ada implementasi; 66 produk & COA dipilih via select/grid manual |
| DatePicker | MISSING-ADOPS | `ui/date-picker` ada; form tanggal masih raw `type="date"/text` |
| Modal/Dialog | REUSABLE | `x-ui.modal` (aria-modal, x-trap.inert.noscroll, ESC) 68 pakai; tambahan `x-modal` legacy 2 pakai (duplikat kecil) |
| Confirm dialog | DUPLICATED-3-SISTEM | `x-erp.confirm-dialog` (3) + `swal-bridge confirmDelete` (9 file) + `wire:confirm` (21) → IX-01 HIGH |
| Drawer/Sheet | REUSABLE | `ui/drawer` 7 pakai + CSS `.sheet-overlay/.sheet-panel` untuk mobile bottom-sheet |
| Dropdown | PARTIAL | `ui/dropdown` ada; banyak menu dibuat manual dengan `x-data`+`x-show` (693 `@click`) |
| Popover/Tooltip | MISSING-ADOPS | ada di ui/, nyaris tidak dipakai |
| Tabs | REUSABLE-ADOPS RENDAH | `ui/tabs` (4 sub-view), dipakai 3×; sisa pakai pattern manual |
| Table/Data table | DUPLICATED | `components/data-table.blade.php` (14 pakai) vs `components/erp/data-table.blade.php` (0 pakai langsung! — hanya empty-state yang dipakai via ui) vs CSS `.data-table/.table-standard` vs `ui/table/*` (4 pakai) → LI-04 HIGH |
| Pagination | REUSABLE | vendor view dikustomisasi (mobile simple + desktop full), hardcoded hex |
| Breadcrumb | REUSABLE | ui/breadcrumbs + item, dipakai luas (183) |
| Sidebar | REUSABLE | ui/sidebar + layouts/navigation, terpusat config |
| Toast | PARTIAL | `components/toast.blade.php` (event `@toast.window`) dipakai 3×; banyak feedback masih flash-alert |
| Alert | REUSABLE | ui/alert 85 pakai, variant+dismissible |
| Skeleton/Spinner | MISSING-ADOPS | ada; view lebih banyak pakai wire:loading inline |
| Empty state | DUPLICATED | `ui/empty-state` (2) + `components/empty-state` (4) — dua file beda isi (CI-02) |
| Badge/Status badge | REUSABLE | ui/badge 123 + erp/status-badge |
| Avatar, Progress, OTP, Kbd, Accordion | MISSING-ADOPS | ada, pemakaian <5 |
| Command palette | MISSING-ADOPS | `ui/command` lengkap (aria, ESC, arrow) tapi **0 pemakaian** — aset menganggur bernilai tinggi (CI-03) |
| MoneyInput | REUSABLE | erp/money-input (prefix Rp, font-mono tabular, aria-invalid) — pemakaian rendah; banyak input uang masih raw number |
| BranchSelector | REUSABLE | components/branch-switcher.blade.php |
| Status badges domain (Payment/Inventory) | MISSING | sebaiknya dibuat composed dari ui/badge (RE-01) |
| Card | INCONSISTENT | ui/card + CSS `.card-surface` + pattern manual `bg-white rounded-* border` di dashboard |

## 4. Catatan arsitektur

- Komponen ui memakai `tailwind-merge-php` (`twMerge`) — konvensi bagus, pertahankan (VERIFIED di button/money-input).
- Button punya integrasi `wire:loading` per-target (`wire:target` prop) — pola anti-double-submit UI yang benar; tidak semua CJK stream pakai (lihat dokumen 06).
- `x-ui.modal` adalah satu-satunya overlay dengan focus-trap penuh; `swal-bridge` juga mengimplementasikan trap+returnFocus; overlay manual lain tidak (IX-02).
