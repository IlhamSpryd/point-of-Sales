# Apeiron Systems — Color System & Theming Implementation Plan

> **Status:** RATIFIED & FULLY IMPLEMENTED (Wave 5 & Wave 5.1).
> **Authoritative Specification:** `resources/css/tokens.css` & `docs/audit/APEIRON_WAVE5_COLOR_CALIBRATION_REPORT.md`.
> **Scope:** `/home/ilhamspryd/Projects/apeironSystem/point-of-Sales` (the ERP application).
> **Implementation Note:** All arbitrary hex bracket utilities and raw palette overrides were eliminated in Wave 5/5.1. The system enforces strict design token mapping (`bg-background`, `text-foreground`, `bg-card`, `border-border`, `text-muted-foreground`, `bg-primary`, `text-primary-foreground`, `bg-accent`, `ring-ring`, `border-input`).

---

## 1. Executive Summary

Apeiron Systems **already has the correct architecture** for a ChatGPT / shadcn/ui–style
monochromatic interface. The problem is not the design direction — it is **enforcement**.

`resources/css/tokens.css` defines a well-structured, shadcn-compatible semantic token set
(primitives → semantic → component), and it is already wired into Tailwind v4 via a single
`@theme inline` block. Components such as `x-ui.button`, `x-ui.card`, `x-ui.input` and
`x-ui.modal` consume those tokens correctly.

However, the layer **above** the primitives has drifted badly. Measured across the repository:

| Metric | Measured value | Implication |
|---|---|---|
| Tailwind palette utilities (`bg-zinc-*`, `text-rose-*`, …) | **6,186** | The token system is bypassed more often than it is used |
| Arbitrary bracket utilities (`border-[#E5E7EB]`, `text-[11px]`, …) | **2,103** | Colors *and* type scale are hardcoded at call sites |
| `dark:` variant utilities | **1,870** across **78** files | Prepared for dark mode that is never activated |
| Semantic colors that render **nothing at all** | `success-*`, `danger-*`, `info-*`, `warning-*`, `primary-*` | Badges are visually broken today (see §5) |
| Neutral families in simultaneous use | **zinc, gray, slate, neutral, stone** | Three different "grays" appear on the same screen |

The single most important finding is **not cosmetic**. `resources/views/components/badge.blade.php`
and `resources/views/components/ui/badge/index.blade.php` build classes such as
`bg-success-50`, `text-success-700`, `bg-danger-50`, `bg-primary-100`. **None of these tokens exist
in `tokens.css`, and none of them are emitted into the built CSS bundle** (verified against
`public/build/assets/app-CnRS5baH.css`). These utilities resolve to nothing, so the affected
status badges today render with **no background and no border** — only whatever text color
survives from the raw-palette fallback path.

### The recommendation in one paragraph

Keep the existing token file and architecture. **Do not rebuild it.** Instead:

1. **Complete** the token registry — add the missing status *scale* tokens (`success-muted`,
   `success-foreground`, …, which the components already assume exist).
2. **Adopt** the values in `02-light-theme-specification.md` as the canonical light palette;
   they are contrast-verified, not eyeballed.
3. **Centralize status → color resolution** in one PHP class so the three enum APIs
   (`badgeType()`, `badgeClass()`, and the cascades inside two competing badge components)
   collapse into a single source of truth.
4. **Migrate** the 6,186 palette utilities and 2,103 arbitrary values in dependency order —
   primitives first, then composed components, then modules.
5. **Activate dark mode last**, after migration, because turning on `.dark` today would break
   roughly half the surfaces (the `dark:` classes exist but the tokens underneath the hex
   values do not).

### Deliberate departures from the brief

The brief proposed `--background: #FFFFFF`. This plan recommends **`#FFFFFF` for `card`/`popover`
and `#FAFAFA` for the app `background`**. A pure-white canvas with pure-white cards makes card
edges invisible except for a hairline border, which pushes the UI toward "all floating boxes" —
explicitly rejected by §10.6 of the brief. A 1-step tonal gap between canvas and card is what
ChatGPT and shadcn/ui actually do. See `02-light-theme-specification.md` §2 for the rationale.
The brief explicitly allowed this: *"These values are initial design references, not mandatory
final values."*

---

## 2. Repository & Stack (Verified)

| Aspect | Finding | Method |
|---|---|---|
| Application root | `point-of-Sales/` (sibling `marketingPageApeironSystem/` is a separate marketing site, out of scope) | directory inspection |
| Framework | Laravel 13.17, PHP 8.3 | `composer.json` |
| UI layer | Livewire 4.4, Alpine.js 3.4 | `composer.json`, `package.json` |
| **Tailwind** | **v4.3.3 via `@tailwindcss/vite`** — CSS-first, **no `tailwind.config.js` exists** | `package.json`, absence of config file |
| CSS entry | `resources/css/app.css` → imports `fonts.css`, `tailwindcss`, `tokens.css` | `app.css` |
| Token file | `resources/css/tokens.css` (261 lines), single `@theme inline` block | read |
| Legacy CSS | `resources/css/components.css` (12 lines) | read |
| Dark-mode variant | `@custom-variant dark (&:where(.dark, .dark *))` | `app.css:5` |
| Livewire classes | 46 | `find` |
| Blade views | 259 | `find` |
| Primary buttons | `x-ui.button`, `x-button`, `x-primary-button` (3!) | component inventory |
| Status badges | `x-erp.status-badge`, `x-badge`, `x-ui.badge` (3!) | component inventory |
| Domain enums | 41 files in `app/Enums` | `find` |
| Charts | **None installed** — dashboard uses hand-built SVG/div bars | `rg` for chart libs |
| DevDojo | Local path repo `packages/devdojo-components`, 37 components published into `resources/views/components/ui` | `composer.json`, docs |

---

## 3. Existing Theme Architecture (Verified)

```
resources/css/app.css
├── @import './fonts.css'
├── @import 'tailwindcss'
├── @import './tokens.css'        ← SSOT
├── @custom-variant dark (...)    ← class-based, but nothing ever adds .dark
└── @layer base / utilities       ← POS shell, tables, sheet, chips, type scale

resources/css/tokens.css
├── :root                         ← light semantic tokens (hex)
├── .dark                         ← dark semantic tokens (oklch)  ← DORMANT
├── [data-theme="kds-dark"]       ← KDS kitchen-display theme (works, orthogonal)
└── @theme inline                 ← maps every token into Tailwind utilities
```

There are **four parallel color channels** in play:

1. **Semantic tokens** (`bg-card`, `text-muted-foreground`) — correct, but minority usage.
2. **Tailwind palette** (`bg-zinc-100`, `text-rose-600`) — majority usage, bypasses theming.
3. **Arbitrary hex** (`border-[#E5E7EB]`) — 600+ occurrences, theming-impossible.
4. **Legacy `apeiron-*` aliases** (`text-apeiron-ink`) — mapped, but semantically meaningless.

Plus a fifth: **CSS-level hardcoded values** inside `app.css`'s `.chip`, `.field-input`,
`.delta-badge`, and `.editorial-surface` utilities, which encode hex directly and therefore
cannot follow dark mode at all.

---

## 4. Design Principles (Adopted, Unchanged)

These come from the brief and are not negotiable. The rest of the plan serves them.

1. Neutral monochrome dominates; color is reserved for state.
2. Hierarchy comes from **weight, size, and spacing** — not hue.
3. Borders are hairline and consistent; shadows are near-absent.
4. Cards are *grouped surfaces*, not floating boxes — achieved by tonal canvas/card separation.
5. Status is never communicated by color alone — always color + label (+ icon or dot).
6. One status vocabulary shared by every module.
7. No gradients, no colored shadows, no decorative saturated fills.

---

## 5. The Five Blocking Defects

These must be understood before any migration work begins, because two of them are
**active bugs**, not style preferences.

### D-1 · Status badges render with no styling (ACTIVE BUG)

`components/badge.blade.php` and `components/ui/badge/index.blade.php` reference
`bg-success-50`, `text-success-700`, `border-success-600/20`, `bg-danger-50`, `bg-info-50`,
`bg-primary-100`, `text-primary-500`. **Verified:** `grep -c '--color-success-50:' resources/css/tokens.css`
→ `0`. **Verified:** none of these selectors exist in the built CSS bundle.

Result: every "success / paid / completed" badge loses its pill treatment. The UI silently
degrades into colored text. This is fixable *independently* of the rest of the migration by
adding the full status scale (§6 / `04-semantic-token-registry.md`).

### D-2 · Three competing badge implementations with three different palettes

| Component | Status classes used |
|---|---|
| `x-badge` | `bg-success-50` / `bg-danger-50` (**undefined**) |
| `x-ui.badge` | `bg-green-50 text-green-600 border-green-200/80`, `bg-rose-50 text-rose-800` |
| `x-erp.status-badge` | delegates to `x-ui.badge` |

`x-badge` **and** `x-ui.badge` both exist, look different, and are both in production.

### D-3 · Three competing enum color APIs

| API | Files | Emits |
|---|---|---|
| `badgeType()` → string | 13 enums | semantic variant name (correct approach) |
| `badgeClass()` → class list | `PurchaseOrderStatusEnum`, `PoReceivingStatusEnum`, `PoInvoicingStatusEnum` | 60 raw palette utilities incl. `dark:` |
| Hardcoded cascades | `x-ui.badge`, `x-erp.status-badge`, `x-status-text` | string `match()` maps that **disagree** |

Confirmed disagreement: `x-erp.status-badge` maps `'issued'` → `info`; `x-status-text` maps
`'issued'` → `info`; but `x-ui.badge` has no `issued` branch at all and falls through to
`secondary`. Meanwhile `PurchaseOrderStatusEnum::Approved` is `blue` via `badgeClass()` but
`success` via `badgeType()`. **The same business status is a different colour depending on
which component renders it.**

### D-4 · `.dark` can never activate, yet 1,870 `dark:` classes exist

No code anywhere in `resources/js` or `resources/views` adds or toggles the `dark` class
(verified: `rg "classList.*dark|toggle\('dark'"` → no matches). `color-scheme: light` is pinned
in `:root`. All 1,870 `dark:` utilities are dead weight in the bundle.

### D-5 · Neutral-family collision

`resources/views/auth/**` uses **zinc** (81 hits). `resources/views/livewire/pos/**` uses **gray**
(119 hits). `resources/views/**` mixes **slate**, **neutral** and **stone** (150+ hits).
The auth screen and the POS screen therefore have measurably different greys for
"the same" secondary text.

---

## 6. Target Architecture (Proposed)

Four layers, one direction of dependency:

```
L0  PRIMITIVES     raw ramps, never used in views        --gray-*, --blue-* for reference only
L1  SEMANTIC       background/card/border/success/…      ← the ONLY thing views may use
L2  COMPONENT      --button-primary-bg, --badge-*        only where L1 cannot express a variant
L3  DOMAIN         status → variant via ONE PHP class     app/Support/Color/StatusPalette.php
```

Views consume **L1 only** (`bg-card`, `text-muted-foreground`). L2 exists for the handful of
cases where a component needs a derived value (e.g. `--badge-success-bg` shared by 3 badge
components so they cannot drift). L3 removes the string-matching duplication described in D-3.

---

## 7. Proposed Palettes (Contrast-Verified)

All pairs below were computed with the WCAG 2.1 relative-luminance formula
(`scratch/contrast_check.py`, exit code 0 = all pass). **Nothing here is estimated.**

### Light mode

| Token | Value | Contrast | Usage |
|---|---|---|---|
| `background` | `#FAFAFA` | — | App canvas |
| `foreground` | `#09090B` | 19.9:1 on bg | Primary text |
| `card` | `#FFFFFF` | — | Cards, popovers, dialogs |
| `primary` | `#18181B` | 16.97:1 vs fg | Primary buttons |
| `primary-foreground` | `#FAFAFA` | 16.97:1 | Text on primary |
| `secondary` / `muted` / `accent` | `#F4F4F5` | 16.12:1 vs `#18181B` | Hover, selected, muted fills |
| `muted-foreground` | **`#6B6B74`** | 5.28 / 4.80 / 4.93 | Secondary text (bg/muted/card) |
| `border` / `input` | `#E4E4E7` | 1.27 vs bg | Hairlines |
| `ring` | `#A1A1AA` | — | Focus ring |
| `success` | `#15803D` | 5.02 / 4.79 | Completed, paid, in-stock |
| `warning` | `#B45309` | 5.02 / 4.84 | Pending, low-stock, overdue |
| `destructive` | `#B91C1C` | 6.47 / 5.91 | Delete, failed, out-of-stock |
| `info` | `#1D4ED8` | 6.70 / 6.16 | Informational, in-transit |

> **Change vs. brief:** `muted-foreground` is `#6B6B74`, not the brief's `#71717A`.
> `#71717A` measures **4.40:1** on `#F4F4F5` — below the WCAG AA 4.5:1 floor. `#6B6B74`
> passes on every surface. This is the only deviation from the proposed values in §2 of the brief.

### Dark mode

| Token | Value | Contrast |
|---|---|---|
| `background` | `#0A0A0B` | — |
| `foreground` | `#FAFAFA` | 18.96:1 |
| `card` | `#141415` | 1.07 vs bg (elevation step) |
| `primary` | `#FAFAFA` | (inverted: light button on dark canvas) |
| `primary-foreground` | `#18181B` | 16.97:1 |
| `muted` / `secondary` | `#1F1F21` | 15.76:1 vs fg |
| `muted-foreground` | `#A1A1AA` | 7.72 / 6.42 |
| `border` | `#2A2A2D` | 1.38 vs bg |
| `success` | `#4ADE80` | 11.36 / 8.55 |
| `warning` | `#FBBF24` | 11.85 / 8.97 |
| `destructive` | `#F87171` | 7.15 / 5.84 |
| `info` | `#60A5FA` | 7.78 / 5.78 |

Dark mode is **not an inversion**: `primary` flips polarity (light button, dark label), while
status colors *brighten* rather than invert so they retain semantic identity. Full derivation
in `03-dark-theme-specification.md`.

---

## 8. Migration Scope (Measured)

| Area | Palette hits | Blade files | Priority |
|---|---|---|---|
| `livewire/finance` | 730 | — | P4 |
| `livewire/purchasing` | 511 | — | P4 |
| `livewire/sales` | 446 | — | P4 |
| `livewire/reports` | 399 | — | P4 |
| `livewire/hr` | 366 | — | P4 |
| `livewire/customers` | 366 | — | P4 |
| `livewire/pricing` | 313 | — | P4 |
| `livewire/inventory` | 147 | — | P4 |
| `livewire/kasir` (POS) | 137 | — | P4 |
| `livewire/pos` (partials) | 124 | — | P4 |
| `components/erp` | 204 | — | **P2** |
| `components/ui` | 104 | — | **P2** |
| `auth` | 268 | 9 | **P1** |
| `roles`, `users`, `devices` | 541 | 11 | P3 |
| **Total views** | **6,186** | 259 | — |

Highest-density single files (migrate first within their phase):

1. `livewire/reports/advanced-analytics-dashboard.blade.php` — 162
2. `livewire/shift-manager.blade.php` — 123
3. `livewire/kasir/order-history.blade.php` — 104
4. `livewire/sales/accounts-receivable-manager.blade.php` — 94
5. `livewire/finance/journal-entry-manager.blade.php` — 93

---

## 9. Dark Mode Recommendation

**Recommendation: ship Light-only in Phases 1–4; activate dark mode as an opt-in, user-selectable
theme in Phase 5, with `system` as the default.**

Trade-offs:

| Option | Pros | Cons |
|---|---|---|
| Light only, delete `dark:` | Smallest bundle, zero ambiguity | Throws away 1,870 already-written classes; POS night-shift operators lose a genuine need |
| Light + dark simultaneously | Cleanest mental model | Doubles review surface for every one of the 259 views; 4,000+ hardcoded values can't follow the theme |
| **Light first, dark in P5 (recommended)** | Each phase has one axis of change; dark work becomes mechanical token migration | Two release beats; temporary dead CSS in bundle |

The POS and KDS contexts make dark mode a real operational requirement (night shifts,
kitchen displays), so deleting it is not acceptable. Sequencing it last is the only safe path.

---

## 10. Roadmap Summary

| Phase | Scope | Exit gate |
|---|---|---|
| **P1** | Token completion (D-1), canvas/card separation, borders, inputs, auth screens | 0 undefined token classes in built CSS; auth fully tokenized |
| **P2** | Primitives: button, badge, input, card, alert, modal, badge unification (D-2, D-3) | Single badge implementation; single `StatusPalette` |
| **P3** | Tables, sidebar, navigation, dashboard, data-heavy surfaces | Sidebar/dashboards on tokens only |
| **P4** | All ERP modules: finance, purchasing, sales, HR, POS, inventory, pricing, reports | 0 palette utilities outside `L0` |
| **P5** | Dark mode activation, contrast audit, visual regression | `10-color-system-acceptance-criteria.md` all green |

Detailed sequencing, dependencies, and rollback strategy: `09-color-migration-roadmap.md`.

---

## 11. Document Index

| # | Document | Purpose |
|---|---|---|
| 00 | `README.md` | This file — strategy, findings, summary |
| 01 | `01-current-color-audit.md` | Measured state of colour usage, per module |
| 02 | `02-light-theme-specification.md` | All light tokens + contrast table |
| 03 | `03-dark-theme-specification.md` | All dark tokens + derivation rules |
| 04 | `04-semantic-token-registry.md` | Naming conventions, full registry, PHP API |

> **Measurement note:** every count in this directory is **source-only** (`resources/`, `app/`).
> Generated CSS in `public/build/` is deliberately excluded, as it re-emits palette classes
> derived from the sources and would inflate the totals by ~820.
| 05 | `05-component-color-mapping.md` | Every component × every state |
| 06 | `06-erp-status-color-mapping.md` | One status vocabulary for all modules |
| 07 | `07-tailwind-integration-plan.md` | Tailwind v4 wiring, file layout |
| 08 | `08-theme-switching-architecture.md` | Toggle, persistence, no-flash boot |
| 09 | `09-color-migration-roadmap.md` | Phased execution plan |
| 10 | `10-color-system-acceptance-criteria.md` | Measurable pass/fail gates |

---

## 12. Conflicts With Existing Documentation

The existing audit at `point-of-Sales/docs/ui-ux-audit/` is **not overwritten** by this plan.
Where the two disagree, this document records the conflict and defers on policy:

| ID | Existing doc says | This audit measures | Resolution |
|---|---|---|---|
| C-1 | `03-visual-consistency-audit.md` LI-03: "1.002 hardcoded hex" | **2,103** bracket utilities, **1,303** raw hex occurrences (source only) | Neither overwrites the other; LI-03's count is stale relative to commit `a58f17a`. Plan uses measured values; recommend LI-03 be re-measured before closure. |
| C-2 | `07-responsive-and-theme-audit.md` TH-01: "1.409 class `dark:`" | **1,870** across 78 files | Same cause as C-1. |
| C-3 | `13-ui-ux-acceptance-criteria.md` AC-T2/AC-T3/AC-T4: **PASSED** ("0 hardcoded hex", "hex sweep tuntas") | 1,303 hex remain; 2,103 bracket utilities remain | **Conflict.** Either the sweep was scoped to `components/**` only (AC-T2 wording suggests so) or the PASSED status is optimistic. This plan treats AC-T2 as *partially true* (`components/` still holds 72 bracket-hex hits) and AC-T3 as **not sustained**. Do not close AC-T3 without re-measurement. |
| C-4 | `08-design-system-proposal.md` A.1: `--background: #F7F7F8` | Recommends `#FAFAFA` | Superseded by contrast-verified values in `02`. `#F7F7F8` remains acceptable if the team prefers a warmer canvas; only `muted-foreground` must change for AA. |
| C-5 | `08-design-system-proposal.md` A.4: dark mode "class-based … persist localStorage" | Correct in intent; never implemented | This plan adopts it and specifies the concrete implementation in `08`. |
| C-6 | `10-devdojo-evaluation.md`: components "ber-nama semantic token shadcn" | True for `ui/card`, `ui/input`, `ui/button`; **false** for `ui/badge` (raw green/rose palette) | Compatibility is real but uneven. See §13. |

---

## 13. DevDojo Compatibility (Verified)

| Aspect | Finding | Impact |
|---|---|---|
| Installation | Local path repo (`packages/devdojo-components`), not a remote package | Zero network risk; vendoring is safe |
| Adoption | 37 of 41 components published into `resources/views/components/ui` | Components are **owned by the app**, not by the package |
| Token usage | `ui/input`, `ui/card`, `ui/button`, `ui/modal`, `ui/label` already use `bg-card`, `border-border`, `text-muted-foreground` | **Fully compatible** — will follow theme automatically |
| Raw palette leakage | `ui/badge` uses `bg-green-50`, `bg-rose-50`, `bg-amber-*`, `bg-blue-*` | Must be fixed in P2 |
| `twMerge` | Every `ui/*` component merges via `$attributes->twMerge(...)` | Class overrides still work after migration |
| Publisher risk | `components:add --force` would overwrite local modifications | Documented already in `10-devdojo-evaluation.md`; no new risk |

**Assessment: green.** DevDojo requires no replacement, no wrapper, and no new dependency.
Exactly one DevDojo-authored component (`ui/badge`) needs its palette replaced with tokens.

---

## 14. Constraints Honored

- No Blade, Livewire, Alpine, CSS, JS, Tailwind, Composer or NPM file was modified.
- No DevDojo component was replaced or removed.
- No color class was mass-replaced.
- No dependency was added.
- The only files written are the eleven documents in this directory plus two read-only
  analysis scripts under `point-of-Sales/scratch/` (`color_audit.sh`, `contrast_check.py`),
  provided so the numbers in this plan can be reproduced.
