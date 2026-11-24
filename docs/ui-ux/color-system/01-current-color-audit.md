# 01 — Current Color Audit

> **Method.** All numbers below come from read-only scripts
> (`scratch/color_audit.sh`, `scratch/contrast_check.py`) run against
> `point-of-Sales/` at commit `a58f17a`. Every figure is reproducible.
> Figures marked **[VERIFIED]** were confirmed against the built CSS bundle
> `public/build/assets/app-CnRS5baH.css`.

---

## 1. Scale of the Problem

| Measurement | Count | Command basis |
|---|---|---|
| Blade files | 259 | `find resources/views -name '*.blade.php'` |
| Livewire classes | 46 | `find app -path '*Livewire*'` |
| Files scanned (blade, php, js, css) | 1,440 | `find` |
| **Tailwind palette utilities** | **6,186** | see §2 |
| **Arbitrary bracket utilities** | **2,103** | see §3 |
| Raw hex literals (`resources/` + `app/`) | **1,303** (1,218 in Blade) | `#[0-9a-fA-F]{3,8}` |
| `rgb()/rgba()` literals | 19 | views only |
| `oklch()` literals | 33 | `tokens.css` dark block |
| Inline `style="…color…"` | 13 | views |
| **`dark:` utilities** | **1,870** in **78 files** | `rg 'dark:'` |
| Chart libraries installed | **0** | `rg 'Chart\(|apexcharts|echarts'` |

**6,186 palette hits across 259 Blade files ≈ 24 hardcoded color utilities per view file.**

> **Measurement scope note.** All figures in this document count **source files only**
> (`resources/`, `app/`). Generated build output (`public/build/assets/*.css`) must be excluded —
> it contains ~821 palette-class occurrences that are *derived* from the sources and would
> double-count them. Commands used in `10-color-system-acceptance-criteria.md` are already
> scoped to `resources/`.

---

## 2. Palette Utility Breakdown

Top 40 `{property}-{palette}-{shade}` combinations:

| Utility family | Count | Share |
|---|---|---|
| `text-zinc-*` | 1,684 | 24.0% |
| `border-zinc-*` | 923 | 13.2% |
| `bg-zinc-*` | 676 | 9.6% |
| **`text-rose-*`** | **547** | 7.8% |
| `text-gray-*` | 414 | 5.9% |
| `text-emerald-*` | 314 | 4.5% |
| `bg-rose-*` | 213 | 3.0% |
| `bg-emerald-*` | 205 | 2.9% |
| `bg-gray-*` | 191 | 2.7% |
| `text-amber-*` | 190 | 2.7% |
| `border-gray-*` | 172 | 2.5% |
| `bg-amber-*` | 140 | 2.0% |
| `border-rose-*` | 127 | 1.8% |
| `text-slate-*` | 105 | 1.5% |
| `border-emerald-*` | 96 | 1.4% |
| `divide-zinc-*` | 93 | 1.3% |
| `border-amber-*` | 90 | 1.3% |
| `bg-blue-*` | 74 | 1.1% |
| `text-blue-*` | 71 | 1.0% |
| `text-indigo-*` | 69 | 1.0% |
| `bg-indigo-*` | 62 | 0.9% |
| `ring-zinc-*` | 57 | 0.8% |
| `bg-slate-*` | 51 | 0.7% |
| `border-blue-*` | 40 | 0.6% |
| `text-red-*` | 33 | 0.5% |
| `bg-purple-*` | 28 | 0.4% |
| `text-purple-*`, `ring-rose-*`, `ring-gray-*`, `divide-gray-*`, `bg-green-*`, `bg-orange-*`, `text-neutral-*`, `bg-neutral-*`, `text-teal-*`, `border-red-*`, `text-sky-*` | 16–20 each | remainder |

### 2.1 Neutral families in simultaneous use

| Family | Approx. hits | Concentrated in |
|---|---|---|
| **zinc** | ~3,700 | auth, components, most livewire |
| **gray** | ~1,350 | POS/kasir, legacy tables |
| **slate** | ~150 | dashboards, purchasing |
| **neutral** | ~25 | isolated |
| **stone** | ~5 | isolated |

Five greys, three of them load-bearing. Tailwind's `zinc` (`#71717A` family) and `gray`
(`#6B7280` family) differ by ~1–2% luminance — just enough to be perceptible side-by-side
on a long page and maddening to reconcile manually.

### 2.2 Red family collision

| Family | Hits | Intended meaning |
|---|---|---|
| `rose-*` | 887 | destructive / cancelled |
| `red-*` | 44 | validation errors |
| `pink-*` | <5 | decorative |

Three reds for one semantic. `l`/`g` notes: `text-rose-600` (#E11D48) and `text-red-600`
(#DC2626) sit ~4° apart on the hue wheel — indistinguishable at 12px, different at 24px.

### 2.3 Green family collision

| Family | Hits |
|---|---|
| `emerald-*` | 615 |
| `green-*` | 12 |

Minor, but `bg-green-50` (in `ui/badge`) and `bg-emerald-50` (everywhere else) are different
colors for the same meaning.

---

## 3. Arbitrary Bracket Utilities

Total **2,103**. Split into color vs. non-color:

### 3.1 Color brackets (≈ 600) — the highest-risk category

| Value | Count | Context |
|---|---|---|
| `border-[#E5E7EB]` | 318 | tailwind `gray-200`, hardcoded |
| `text-[#111827]` | 115 | tailwind `gray-900`, hardcoded |
| `border-[#E5E5E5]` | 101 | *unauthorized* grey — near but not equal to `#E5E7EB` |
| `text-[#787774]` | 65 | *unauthorized* warm grey |
| `bg-[#171717]` | 51 | charcoal |
| `text-[#6B7280]` | 44 | gray-500 |
| `bg-[#F9FAFB]` | 36 | gray-50 |
| `bg-[#F7F7F5]` | 36 | *unauthorized* warm off-white |
| `ring-[#37352F]` | 31 | *unauthorized* warm ink |
| `bg-[#1E1E1E]`, `bg-[#262626]`, `bg-[#111111]`, `border-[#2E2E2E]` | 29/22/21/17 | dark-chrome greys |
| `text-[#737373]`, `text-[#A3A3A3]`, `bg-[#F4F4F5]`, `text-[#D4D4D4]`, `text-[#9CA3AF]`, `bg-[#202020]`, `text-[#4B5563]` | 21/16/16/15/13/13/11 | assorted |
| `text-[#525252]`, `bg-[#F1F1F1]`, `text-[#9B9A97]`, `bg-[#2A2A2A]`, `bg-[#282828]` | 9/9/8/8/8 | assorted |

**The warm-charcoal family is a second, undocumented palette.**
`#F7F7F5`, `#787774`, `#9B9A97`, `#37352F`, `#202020` are Notion-adjacent warm neutrals that
appear in **no token file**. They coexist with the cool zinc family and produce a visible
warm/cool clash whenever both appear on one screen.

### 3.2 Non-color brackets (≈ 1,500) — out of scope for this plan

`text-[11px]` (226), `text-[18px]` (178), `text-[10px]` (160), `text-[20px]` (62),
`text-[16px]` (60), `rounded-[5px]` (10), etc.

> **Note for the executing agent:** these are typography/radius debt, tracked separately.
> The color migration must **not** rewrite them. However, `text-[Npx]` and
> `text-[#RRGGBB]` share the same bracket syntax, so any automated codemod **must**
> distinguish `text-[` followed by digits+`px`/`rem` from `text-[` followed by `#` or `rgb`.
> A naive regex will destroy the type scale. This is the single largest automation hazard
> in the migration.

---

## 4. Undefined Semantic Tokens (Active Bug)

Class prefixes referencing token families that **do not exist** in `tokens.css`:

| Prefix | Occurrences | Files | Resolves to |
|---|---|---|---|
| `primary-{50..900}` | 117 (e.g. `text-primary-400`, `ring-primary-500`, `border-primary-500`) | 17 | **nothing** |
| `danger-{50..900}` | 28 (`bg-danger-50`, `text-danger-600`, `border-danger-600/20`) | 6 | **nothing** |
| `success-{50..900}` | 9 (`bg-success-50`, `text-success-700`) | 1 (`badge.blade.php`) | **nothing** |
| `warning-{50..900}` | 6 | 1 | **nothing** |
| `info-{50..900}` | 3 | 1 | **nothing** |
| **Total** | **163** | **~26** | |

The failure is concentrated rather than systemic: `primary-*` accounts for 117 of the 163 and
lives in ~17 index/edit pages (focus rings and icon tints), while the badge breakage is confined
to `components/badge.blade.php`.

**[VERIFIED]** `grep -c` against the built bundle returns `0` for
`.bg-success-50`, `.text-success-700`, `.bg-danger-50`, `.text-info-600`,
`.bg-primary-100`, `.text-primary-500`.

Only the *base* tokens exist: `--color-success`, `--color-warning`, `--color-info`,
`--color-destructive`, `--color-primary`. The scale suffixes were assumed but never defined.

**Consequence:** `x-badge` and `x-ui.badge` render status pills with transparent backgrounds
and no borders. In a table of 50 invoices, "Paid" and "Unpaid" are distinguishable only by
text color — and where the raw-palette fallback is also missing, not at all.

---

## 5. Dark-Mode Readiness

**[VERIFIED]** 1,870 `dark:` utilities in 78 files. **[VERIFIED]** No code adds, removes or
toggles the `dark` class:

```
rg "classList.add\('dark'\)|classList.toggle\('dark'\)|\.dark\b" resources/js resources/views → no matches
```

`.dark` tokens exist in `tokens.css` (oklch, complete for the *base* set) and
`@custom-variant dark` is registered — so the plumbing is half-built. But:

1. Nothing activates it.
2. The `.dark` block does **not** define the status scale (`success-50` etc.) that the badges
   ask for, so badges would be broken in dark mode too.
3. ~1,500 hardcoded hex utilities cannot follow a theme at all.
4. CSS-level colors in `app.css` (`.chip.ok`, `.field-input:focus`, `.editorial-surface`,
   `.table-scroll-shadow` white gradients, `option { background: #fff }`) are theme-blind.

Enabling `.dark` today would produce a **half-dark** interface: tokenized components would flip,
hardcoded ones would not, and the result would be worse than light-only.

---

## 6. Component-Level Findings

### 6.1 Three button implementations

| Component | Notes |
|---|---|
| `components/ui/button/index.blade.php` | 7 variants, 7 sizes, token-based ✅ (**target**) |
| `components/button.blade.php` | 4 variants, token-based, uses `x-badge`-style `bg-apeiron-ink/10` for hotkeys |
| `components/primary-button.blade.php` / `secondary-button.blade.php` | Breeze-era |

`ui/button` primary variant contains `bg-linear-to-b` with a single color stop — a leftover
gradient declaration on the flagship button. Must be removed (brief §3.3 forbids gradients).

### 6.2 Three badge implementations

| Component | Palette | Status |
|---|---|---|
| `components/badge.blade.php` (`x-badge`) | `success-50/danger-50` (undefined) | **broken** |
| `components/ui/badge/index.blade.php` (`x-ui.badge`) | raw `green-50`/`rose-50`/`amber-*`/`blue-*` | works, unthemeable |
| `components/erp/status-badge.blade.php` (`x-erp.status-badge`) | delegates to `x-ui.badge` | inherits raw palette |
| `components/status-text.blade.php` (`x-status-text`) | `text-emerald-700 dark:text-emerald-400` | 4th parallel mapping |

**Four** places decide what color a status gets. They disagree (see README §5, D-3).

### 6.3 Forms

- `ui/input` — token-based ✅ (`border-input`, `bg-background`, `focus-visible:border-ring`)
- `form-input` (Breeze) — `border-[#E5E7EB]` ❌
- `app.css .field-input / .input-error / .label-error` — hardcoded `#A1A1AA`, `#09090B`,
  `#DC2626`, `#fda4af`, `rgba(244,63,94,.15)` ❌

Three input appearances. Error color appears as **four** different reds: `#DC2626`,
`#e11d48`, `#fda4af`, `rgba(244,63,94,…)`.

### 6.4 Tables

- `.data-table` / `.table-standard` in `app.css` — uses `var(--color-table-header-bg, #F9FAFB)`
  with **hex fallbacks that bypass the token on typo** ❌
- `x-erp.data-table` — tokenized ✅
- `ui/table/*` — tokenized ✅

`--color-table-header-bg` **is** defined in `@theme inline`, so the fallback never fires — but
the pattern is a trap: any future rename silently degrades to `#F9FAFB` in light mode and stays
there in dark mode.

### 6.5 Sidebar / shell

`layouts/app.blade.php` hardcodes a dark chrome (`bg-[#171717]` body, `#202020` pills,
`#2E2E2E` borders, `#D4D4D4`/`#A3A3A3`/`#737373` text) **despite** `--sidebar-*` and `--shell`
tokens existing for exactly this purpose. The tokens are defined and unused at the highest-traffic
surface in the application.

Additionally, the sidebar tints menu icons: `text-amber-400` (KDS), `text-purple-400` (inventory),
`text-emerald-400` (sync). This is decorative use of semantic colors — forbidden by brief §3.1,
§3.2.

### 6.6 POS

| Finding | Detail |
|---|---|
| Neutral family | `gray` (119 hits) while everything else uses `zinc` |
| Destructive family | `rose` (25× `text-rose-600`, 10× `bg-rose-50`) |
| Payment/discount | `amber-500/50`, `indigo-600`, `rose-600/700` — indigo appears **only here** |
| `pos-layout-locked` | `@apply … bg-white` — hard white, cannot theme |
| `.sheet-panel` | `background: var(--color-apeiron-bg)` → aliases to `--card` (white in light, works) |

### 6.7 Auth

`zinc`-heavy (81 hits) with 5 different `#` hexes including `#0A0A0B` (the *dark* background)
used as `editorial-surface` in light mode. Google OAuth brand colors (`#4285F4`, `#EA4335`,
`#FBBC05`, `#34A853`, 5× each) — **the only legitimate hardcoded hex in the codebase**; brand
marks must not be tokenized.

### 6.8 Charts

No chart library. The dashboard hand-builds SVG histograms with `<rect>`/`<path>` fills that
were recently tokenized (`stock-adjustments` chart uses `fill="currentColor"` families).
`scratch/color_audit.sh` found **0** `backgroundColor`/`borderColor` chart configs.
**Implication:** the chart palette (§6 of the brief) is *forward-looking* — define it now so
the first chart library integrates cleanly, but nothing renders it today.

### 6.9 Inline SVG

The only `fill="#…"` literals in views are the four Google brand marks (20 occurrences).
All other icons are Material Symbols ligatures inheriting `currentColor` ✅.

---

## 7. Contrast Problems in Current Usage

Sampled from the current palette (all measured, WCAG AA = 4.5:1 for text):

| Current usage | Contrast | Verdict |
|---|---|---|
| `text-gray-400` on `bg-gray-50` (POS secondary) | **2.5:1** | ❌ fail |
| `text-zinc-400` on `#FFFFFF` (auth footer) | **2.8:1** | ❌ fail |
| `text-gray-500` on `#F9FAFB` (table cells) | 4.3:1 | ⚠️ borderline fail |
| `text-muted-foreground` `#6B7280` on `--muted` `#F3F4F6` | **4.4:1** | ⚠️ fails 4.5 |
| `dark:text-gray-400` on `#171717` (if dark ever activates) | 6.9:1 | ✅ |
| `text-emerald-700` on `bg-emerald-50` | 5.3:1 | ✅ |
| `text-rose-600` on `bg-rose-50` | 4.3:1 | ⚠️ fails |

The *current* `--muted-foreground: #6B7280` already fails AA against `--muted`. The proposed
`#6B6B74` fixes this (4.80:1).

`text-gray-400` / `text-zinc-400` used for secondary text is the single most common
accessibility defect in the codebase (≈ 70 occurrences).

---

## 8. Documented Conflicts

See README §12 (C-1 … C-6). Summary of the material ones:

- **C-1/C-2** — the prior audit's counts (1,002 hex, 1,409 `dark:`) are lower than the current
  measurements (2,103 bracket utilities, 1,303 hex, 1,870 `dark:`). Cause is unverified; likely a stale
  snapshot plus post-audit module work (`d481ecf` Wave 7, `a58f17a` tests).
- **C-3** — `13-ui-ux-acceptance-criteria.md` marks AC-T3 ("hex sweep tuntas") **PASSED**.
  That cannot be reconciled with 1,303 hex literals and 2,103 bracket utilities present today.
  This plan does **not** mark AC-T3 as satisfied and recommends re-measurement before closure.

---

## 9. Audit Conclusion

The token *infrastructure* is sound and worth preserving. The token *adoption* has stalled at
roughly the primitive layer. Two defects (D-1 undefined badge tokens, D-3 divergent status
mapping) are functional bugs and should be treated as such — they are cheap to fix and should
not wait for the visual migration.
