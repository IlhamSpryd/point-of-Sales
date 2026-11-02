# 08 — Design System Proposal (shadcn-inspired, Blade/Livewire/Alpine-native)

> Prinsip: Apeiron **sudah 70% berada di jalur shadcn**. Proposal ini BUKAN rewrites,
> melainkan konsolidasi + pelengkapan. Zero dependensi baru. No React.

## A. Design Tokens (satu sumber kebenaran)

Buat `resources/css/tokens.css` — digabung dari `app.css @theme` + `components.css`,
dengan struktur 3 lapis shadcn: **primitives → semantic → component**.

### A.1 Semantic colors (light)

```css
:root {
  /* Semantic (shadcn-compatible names) */
  --background:        #F7F7F8;
  --foreground:        #202123;
  --card:              #FFFFFF;
  --card-foreground:   #202123;
  --popover:           #FFFFFF;
  --popover-foreground:#202123;
  --primary:           #171717;   /* charcoal ink */
  --primary-foreground:#FFFFFF;
  --secondary:         #F3F4F6;
  --secondary-foreground:#202123;
  --muted:             #F3F4F6;
  --muted-foreground:  #6B7280;
  --accent:            #F3F4F6;
  --accent-foreground: #202123;
  --destructive:       #B91C1C;
  --destructive-foreground:#FFFFFF;
  --success:           #047857;
  --warning:           #B45309;
  --info:              #1D4ED8;
  --border:            #E5E7EB;
  --input:             #E5E7EB;
  --ring:              #171717;
  /* ERP-specific semantic (bukan milik shadcn, dipertahankan) */
  --surface-table-header:#F9FAFB;
  --shell:             #171717;   /* sidebar/dark chrome */
}
.dark { /* pemetaan oklch — pindahkan dari components.css + tambah ERP tokens */ }
[data-theme="kds-dark"] { /* tetap, sudah benar */ }
```

### A.2 Typography

| Token | Nilai |
|---|---|
| `--font-sans` | Plus Jakarta Sans (self-hosted) |
| `--font-mono` | JetBrains Mono (self-hosted) |
| Display/KPI | 28–36px / 700–800 / tracking-tight / **tabular-nums** |
| Heading H1 | 20–24px / 700 |
| Heading H2 | 16px / 600 |
| Body | 13–14px (admin-ui 14px base fluid) / 400–500 |
| Caption/label | 11–12px / 500, uppercase-tracking untuk table header (sudah ada) |
| Numeric (wajib) | font-mono + tabular-nums untuk semua sel uang |

### A.3 Spacing & Radius & Elevation & Motion

- Spacing: Tailwind default 4px scale (sudah dipakai); komponen mengunci paddings: control h-9, table cell py-3 px-4, card p-5.
- Radius: `--radius-control: 8px` (input/button), `--radius-card: 12px`, `--radius-overlay: 16px`, pill utk badge. Alias lama (`rounded-medium/large/small`) jadi deprecated.
- Elevation (restrained): `shadow-xs` (card), `shadow-sm` (popover/dropdown), `shadow-lg` (modal/sheet) — sudah cocok, dokumentasikan.
- Motion (sudah ada di app.css — pertahankan & pindah ke tokens.css): `--dur-micro:120ms`, `--dur-fast:200ms`, `--dur-medium:350ms`, `--ease-out`/`--ease-ui`, zero-out saat `prefers-reduced-motion`.

### A.4 Integrasi Tailwind v4

```css
@theme inline {
  --color-background: var(--background);
  --color-foreground: var(--foreground);
  /* ... satu-satunya @theme; app.css tidak lagi mendefinisikan duplikat */
}
```
- Dark mode strategy: **class-based** (`.dark` di `<html>`, persist localStorage + `prefers-color-scheme` initial) — sudah dipersiapkan oleh `@custom-variant`.
- Class conflict: tetap `twMerge()` di semua komponen (konvensi existing).
- CSS organization: `tokens.css` → `app.css` (base + utility khusus POS) → `components.css` hanya sebagai alias deprecated sementara, lalu dihapus.

## B. Component Architecture (4 lapis)

| Lapis | Isi | Lokasi |
|---|---|---|
| L1 Primitive | button, input, textarea, select, checkbox, radio, switch, label, badge, separator, icon, skeleton, spinner, kbd, avatar | `components/ui/*` (sudah ada — sebagian perlu adopsi) |
| L2 Composed | form-field, searchable-select, combobox, data-table, filter-bar, confirm-dialog, page-header, stat-card, toast-region, dirty-guard, command-palette, date-range-picker | `components/erp/*` |
| L3 Domain | product-selector (POS grid API), money-input (ada), branch-selector (ada), payment-status-badge, inventory-status-badge, stock-level-indicator | `components/erp/<domain>/` |
| L4 Page | manager-shell (page-header+toolbar+table), dashboard-grid | `components/erp/shells/` |

Aturan: L1 bebas logika bisnis (nol); L2 boleh menerima contract data via props/slot;
L3 boleh tahu enum domain; L4 hanya komposisi.

## C. Component API Standards (representatif, bukan implementasi)

```blade
{{-- L1: konsisten variant/size/state --}}
<x-ui.button variant="primary|secondary|outline|ghost|destructive|link"
             size="xs|sm|md|lg|icon|icon-sm|icon-touch"
             :loading="$loading" loading-target="saveOrder" hotkey="mod+s">

{{-- L2: form-field menormalkan label/error/hint/required --}}
<x-erp.form-field label="Supplier" required error={{ $errors->first('supplier_id') }} hint="...">
  <x-erp.searchable-select wire:model="supplier_id" :options="$suppliers" searchable />
</x-erp.form-field>

{{-- L2: data-table deklaratif --}}
<x-erp.data-table :columns="[
    ['key'=>'order_no','label'=>'No. Order','sortable'=>true],
    ['key'=>'total','label'=>'Total','numeric'=>true,'sortable'=>true],
    ['key'=>'status','label'=>'Status','badge'=>true],
  ]" :rows="$orders" :meta="$ordersMeta" bulk-route="orders.bulk">
```

Standar API: nama props English-kebab; setiap komponen punya `variant/size/disabled/loading`;
 atribut extra melewati `$attributes->twMerge(...)`; a11y attrs bawaan (bukan beban pemakai).

## D. Interaction Primitives (Radix-inspired behavior, implementasi Alpine)

| Primitive | Perilaku wajib |
|---|---|
| Dialog | x-trap.inert.noscroll, ESC close, aria-modal, return focus, body scroll lock |
| Alert dialog | destructive styling terpisah, confirm butuh intent (bukan enter-blind) |
| Dropdown | open/close, arrow-key roving, ESC, click-outside, aria-expanded/haspopup |
| Popover | anchored, ESC, click-outside, aria |
| Select/Combobox | listbox pattern, typeahead, aria-activedescendant, clearable, async load |
| Tabs | arrow-key navigation, aria-selected/tabpanel |
| Toast | role=status, auto-dismiss 5s, dismiss button, max-3 stack |
| Command | sudah ada di ui/command — aktifkan Ctrl+K |

## E. Iconography

- Tetap Material Symbols Rounded (satu lib, self-hosted, offline POS).
- Buat `<x-ui.icon name="receipt_long" size="sm|md|lg"/>` — memusatkan variation settings, mencegah typo ligature, dan menjadi registry ikon terpakai.
