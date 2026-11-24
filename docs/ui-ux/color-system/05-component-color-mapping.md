# 05 — Component Color Mapping

> Every reusable component × every state. A component must never invent a color; it composes
> tokens from `04-semantic-token-registry.md`.
>
> **Reading the tables:** "Dark" describes *what happens automatically* when `.dark` is applied.
> If a row says "auto-inverts", the component needs **no** `dark:` class.

---

## 0. Global State Rules

| State | Light | Dark | Rule |
|---|---|---|---|
| **Hover** | `bg-accent` | `bg-accent` | Hover always uses `accent`, never an ad-hoc tint |
| **Focus-visible** | `ring-2 ring-ring ring-offset-2 ring-offset-background` | same | Standard. High-risk controls use `ring-focus` |
| **Active / pressed** | `bg-accent` + `opacity-90` or slight translate | same | No color change beyond accent |
| **Selected** | `bg-accent text-accent-foreground` | same | Plus a non-color cue (checkmark, border) |
| **Disabled** | `opacity-50 cursor-not-allowed` (not `text-disabled` on top of opacity) | same | Never use a lighter palette shade |
| **Invalid** | `border-destructive ring-destructive/20` | same | Plus an icon + message (never color alone) |

**Transitions:** `transition-colors duration-150` only. No color *animation* beyond a fade.

---

## 1. Buttons

**Reference implementation:** `resources/views/components/ui/button/index.blade.php` (7 variants).

| Variant | Default | Hover | Focus | Disabled | Dark |
|---|---|---|---|---|---|
| **primary** | `bg-primary text-primary-foreground border-transparent` | `bg-primary/90` | `ring-2 ring-ring` | `opacity-60` | **auto-inverts** (light button, dark text) |
| **secondary** | `bg-card text-foreground border-border` | `bg-secondary` | `ring-2 ring-ring` | `opacity-60` | card darkens to `#141415`, border hairline |
| **outline** | `bg-transparent text-foreground border-border` | `bg-secondary` | `ring-2 ring-ring` | `opacity-60` | auto |
| **ghost** | `bg-transparent text-foreground` | `bg-accent` | `ring-2 ring-ring` | `opacity-60` | auto |
| **destructive** | `bg-destructive text-destructive-foreground` | `bg-destructive/90` | `ring-2 ring-destructive/40` | `opacity-60` | brightens to `#F87171` |
| **link** | `text-foreground underline-offset-4` | `underline` | `ring-2 ring-ring` | `opacity-60` | auto |

**Required fixes to the current implementation**

| Issue | Fix |
|---|---|
| Primary variant contains `bg-linear-to-b` (a single-stop gradient) | Remove — gradients forbidden |
| 3 button implementations exist (`ui.button`, `button`, `primary/secondary-button`) | Forward legacy to `ui.button`; delete |
| Hotkey `<kbd>` uses `bg-apeiron-ink/10` | `bg-foreground/10 text-foreground` or `bg-muted` |

**Sizes** (`xs/sm/md/lg/icon/icon-sm/icon-touch`) do not change color — only geometry.

**Loading state:** keep the current label, swap the leading icon for a spinner; spinner color is
`currentColor`. Never dim the whole button below `opacity-75` during load (reads as disabled).

---

## 2. Badges

**One implementation: `x-ui.badge`.** All states below derive from `StatusPalette`.

| Variant | Default | Hover | Dark |
|---|---|---|---|
| success | `bg-success-muted text-success border-success-border` | none (badges are not interactive) | deep-green chip, bright text |
| warning | `bg-warning-muted text-warning border-warning-border` | none | deep-amber chip |
| destructive | `bg-destructive-muted text-destructive border-destructive-border` | none | deep-red chip |
| info | `bg-info-muted text-info border-info-border` | none | deep-blue chip |
| neutral | `bg-neutral-muted text-neutral border-neutral-border` | none | `#1F1F21` chip |
| primary | `bg-primary text-primary-foreground` | none | **auto-inverts** |
| outline | `bg-transparent text-foreground border-border` | none | auto |

**Shape:** `rounded-md px-2 py-0.5 text-xs font-medium`; `pill` → `rounded-full`.

**Dot:** `size-1.5 rounded-full` in the variant's solid color (`bg-success` etc.).
The dot must be **additive**, not a replacement for the label.

**Non-negotiable:** a badge always renders text. `dot` alone + color alone fails WCAG 1.4.1.

---

## 3. Form Controls

### 3.1 Input / Textarea / Number input

| State | Classes |
|---|---|
| Default | `bg-background border-input text-foreground placeholder:text-muted-foreground` |
| Hover | `border-border-strong` |
| Focus | `border-foreground ring-1 ring-foreground` *(or `ring-ring` for low-emphasis)* |
| Disabled | `bg-disabled-bg text-disabled cursor-not-allowed` |
| Read-only | `bg-surface-inset text-muted-foreground` |
| Invalid | `border-destructive ring-1 ring-destructive/30` |
| Dark | auto-inverts (background `#0A0A0B`, border translucent white) |

**Sizes:** `h-9` default, `h-8` sm, `h-10` lg, `h-11` touch (POS).

**Conflict to resolve:** `app.css` `.field-input:focus` uses `border-color:#09090B; box-shadow:0 0 0 1px #09090B`
— visually correct but hardcoded. Replace with `border-foreground ring-1 ring-foreground`.
`.input-error` uses `#fda4af` + `rgba(244,63,94,.15)` — a *pink*, inconsistent with
`--destructive:#B91C1C`. Unify to `border-destructive ring-destructive/30`.

### 3.2 Select / Combobox

| State | Trigger | Panel |
|---|---|---|
| Default | `bg-background border-input` | — |
| Open | `border-foreground ring-1 ring-foreground` | `bg-popover border-border shadow-popover` |
| Option hover | — | `bg-accent text-accent-foreground` |
| Option selected | — | `bg-accent` + leading check icon |
| Disabled | `bg-disabled-bg text-disabled` | — |

**Native `<option>`:** `app.css` hardcodes `background:#ffffff;color:#171717`. Must become
`background: var(--popover); color: var(--popover-foreground)` — otherwise native select
dropdowns stay white in dark mode (a guaranteed broken surface).

### 3.3 Checkbox / Radio / Switch

| Element | Unchecked | Checked | Disabled | Focus |
|---|---|---|---|---|
| Checkbox | `border-input bg-background` | `bg-primary border-primary text-primary-foreground` | `bg-disabled-bg border-border` | `ring-2 ring-ring` |
| Radio | `border-input bg-background` | `border-primary` + `bg-primary` dot | `bg-disabled-bg` | `ring-2 ring-ring` |
| Switch (off) | `bg-input` | — | `opacity-50` | `ring-2 ring-ring` |
| Switch (on) | — | `bg-primary` | `opacity-50` | `ring-2 ring-ring` |

Toggled states use `primary`, **never** `success` — a toggle is a control, not a status result.

### 3.4 Labels, hints, validation messages

| Element | Class |
|---|---|
| Label | `text-foreground text-sm font-medium` |
| Required asterisk | `text-destructive` |
| Hint | `text-muted-foreground text-xs` |
| Error | `text-destructive text-xs` + `role="alert"` |
| Error icon | `text-destructive` (icon **and** text — never text alone) |

---

## 4. Cards

| Part | Light | Dark |
|---|---|---|
| Surface | `bg-card border border-border rounded-xl` | `bg-card` `#141415`, border `rgb(255 255 255/.10)` |
| Shadow | `shadow-card` (optional) | `none` — tone provides separation |
| Header | `bg-transparent border-b border-border` | auto |
| Title | `text-foreground font-semibold` | auto |
| Description | `text-muted-foreground text-sm` | auto |
| Footer | `bg-muted/…` **forbidden** — use `border-t border-border` only | auto |
| Interactive | `hover:border-border-strong` **not** `hover:bg-accent` | auto |

**Anti-pattern to remove:** `components/erp/metric-card` and dashboard KPI cards currently mix
`bg-white` + `shadow` + `rounded-3xl` + tinted icon backgrounds. Target: `bg-card`, `rounded-xl`,
`shadow-card` at most, and a **monochrome** icon holder (`bg-muted text-muted-foreground`).
Tinted icon squares are decorative color (brief §3.1).

---

## 5. Tables

| Element | Light | Dark |
|---|---|---|
| Container | `bg-card border border-border rounded-xl overflow-hidden` | auto |
| `<thead>` | `bg-surface-table-header text-muted-foreground text-xs font-semibold uppercase tracking-wide` | `#111112` |
| `<th>` border | `border-b border-border` | hairline |
| `<td>` | `text-foreground border-b border-border` | auto |
| Row hover | `bg-accent` | auto |
| Row selected | `bg-accent` + `border-l-2 border-foreground` | auto |
| Row disabled | `opacity-60` | auto |
| Numeric cells | `text-right tabular-nums` (`.numeric`) | auto |
| Footer aggregation | `bg-muted font-semibold border-t border-border` | auto |
| Empty state | `bg-card` + `text-muted-foreground` + icon `text-muted-foreground/60` | auto |

**Required fixes**

1. `app.css` `.data-table thead th` uses `var(--color-table-header-bg, #F9FAFB)` — the hex
   fallback means a token rename silently degrades. Remove all fallbacks.
2. `app.css` `.data-table tbody tr:hover` uses `var(--color-surface-secondary, #F9FAFB)` —
   `--color-surface-secondary` is **not defined**. Hover today resolves to `#F9FAFB` in both
   themes. Must become `var(--accent)`.
3. `.table-scroll-shadow` uses **white** gradients. Replace with a token-based mask
   (`mask-image` with `background`/`foreground`) or simply remove — it shows white bars on
   dark tables.
4. Do **not** tint rows by status (`bg-rose-50` row for cancelled). Use a status badge cell.

---

## 6. Alerts / Callouts

| Variant | Surface | Border | Icon+Title | Dark |
|---|---|---|---|---|
| info | `bg-info-muted` | `border-info-border` | `text-info` | deep-blue |
| success | `bg-success-muted` | `border-success-border` | `text-success` | deep-green |
| warning | `bg-warning-muted` | `border-warning-border` | `text-warning` | deep-amber |
| destructive | `bg-destructive-muted` | `border-destructive-border` | `text-destructive` | deep-red |

Body text inside an alert is `text-foreground`, **not** the variant color — only the icon and
title carry hue. This keeps long messages readable.

---

## 7. Dialogs / Modals / Drawers / Sheets

| Part | Light | Dark |
|---|---|---|
| Scrim | `bg-[var(--overlay-scrim)] backdrop-blur-[var(--overlay-blur)]` | darker |
| Panel | `bg-card border border-border rounded-2xl shadow-dialog` | `#141415` |
| Header | `border-b border-border` | auto |
| Title | `text-foreground text-base font-semibold` | auto |
| Description | `text-muted-foreground text-sm` | auto |
| Footer | `border-t border-border bg-card` | auto |
| Destructive confirm | Title `text-foreground`; action button `variant="destructive"` | — |
| Sheet (`pos`) | `bg-card rounded-t-2xl` | auto |

**Fix:** `app.css .sheet-overlay` uses `rgb(55 53 47 / 0.4)` — a **warm brown** scrim.
Replace with `var(--overlay-scrim)` (neutral).
`.sheet-panel` uses `var(--color-apeiron-bg)` → aliases to `--card`; acceptable, but rename to
`var(--card)` for clarity.

---

## 8. Dropdowns / Popovers / Tooltips

| Element | Light | Dark |
|---|---|---|
| Surface | `bg-popover border border-border rounded-lg shadow-popover` | `#1A1A1C` |
| Item (rest) | `text-popover-foreground` | auto |
| Item (hover/focus) | `bg-accent text-accent-foreground` | auto |
| Item (disabled) | `text-disabled` | auto |
| Item destructive | `text-destructive hover:bg-destructive-muted` | auto |
| Separator | `bg-border` | auto |
| Group label | `text-muted-foreground text-xs uppercase` | auto |
| Tooltip | `bg-foreground text-background text-xs` (inverted) | inverts again → dark tooltip on light? **No:** keep `bg-foreground text-background` so it auto-inverts correctly |

**Dark-mode note:** `popover` must be **lighter** than `card` (`#1A1A1C` vs `#141415`).
A dropdown rendered on `card` that shares its color becomes invisible.

---

## 9. Tabs

| State | Light | Dark |
|---|---|---|
| List background | `bg-muted rounded-lg p-1` | auto |
| Trigger (rest) | `text-muted-foreground` | auto |
| Trigger (hover) | `text-foreground` | auto |
| Trigger (active) | `bg-card text-foreground shadow-xs` | auto (light chip on dark) |
| Underline tabs (line variant) | active `border-b-2 border-foreground` | auto |
| Disabled | `text-disabled` | auto |

Active tab is **never** colored (`border-blue-500` etc.) — it is `foreground`, i.e. ink.

---

## 10. Navigation

| Element | Light | Dark |
|---|---|---|
| Topbar | `bg-background border-b border-border` | auto |
| Nav link (rest) | `text-muted-foreground` | auto |
| Nav link (hover) | `text-foreground bg-accent` | auto |
| Nav link (active page) | `text-foreground font-medium` + `bg-accent` | auto |
| Breadcrumbs (link) | `text-muted-foreground hover:text-foreground` | auto |
| Breadcrumbs (current) | `text-foreground` | auto |
| Separator | `text-muted-foreground/60` | auto |

### 10.1 Sidebar (shell)

| Element | Light | Dark |
|---|---|---|
| Background | `bg-shell` `#18181B` | `bg-shell` `#111112` (**lighter than canvas**) |
| Section label | `text-shell-foreground` uppercase `text-xs` | auto |
| Item (rest) | `text-shell-foreground` | auto |
| Item (hover) | `bg-shell-accent text-shell-foreground-active` | auto |
| Item (active) | `bg-shell-accent text-shell-foreground-active font-medium` + leading `border-l-2` or dot | auto |
| Divider | `border-shell-border` | auto |
| Icon | `text-shell-foreground` (inherits) | auto |

**Required fixes in `layouts/app.blade.php`:** every hex here (`bg-[#171717]`, `#202020`,
`#2E2E2E`, `#D4D4D4`, `#A3A3A3`, `#737373`) must become `shell-*` tokens — they already exist.

**Decorative icon tints must go:** `text-amber-400`, `text-purple-400`, `text-emerald-400` on
nav icons are decorative. Exception: the "Online • Cloud Sync" pill is a genuine **status** →
`bg-success-muted text-success border-success-border`, though on the dark shell use
`text-success` + `bg-success/10` is discouraged; prefer `text-success` text with a `bg-success` dot.

---

## 11. Pagination

| Element | Class |
|---|---|
| Container | `border border-border rounded-lg bg-card` |
| Page (rest) | `text-muted-foreground hover:bg-accent hover:text-foreground` |
| Page (current) | `bg-primary text-primary-foreground` — or, more restrained: `border border-border text-foreground font-medium` |
| Disabled | `text-disabled` |
| Focus | `ring-2 ring-ring` |

**Recommendation:** use the restrained option (`border + font-medium`) for the current page.
A solid black page-number chip is louder than the data it navigates and violates brief §10.7.

The vendor pagination view (`vendor/pagination/tailwind.blade.php`) was already tokenized in
Phase 1 — keep it, verify it uses `bg-primary` only for the current page.

---

## 12. Toasts

| Variant | Border-l / Icon | Surface | Dark |
|---|---|---|---|
| success | `text-success` icon + 2px left border `border-success` | `bg-popover` | auto |
| warning | `text-warning` | `bg-popover` | auto |
| destructive | `text-destructive` | `bg-popover` | auto |
| info | `text-info` | `bg-popover` | auto |

Toast surface stays neutral (`bg-popover`); only the icon and left rule carry the variant.
A fully tinted toast background is a decorative-color violation.

---

## 13. Skeleton Loaders

| Element | Light | Dark |
|---|---|---|
| Block | `bg-muted rounded-md animate-pulse` | `#1F1F21` |
| Text line | `bg-muted h-3 rounded` | auto |

**Never** use a colored skeleton (`bg-zinc-200` is fine only as an alias for `bg-muted`).
Skeletons must never shift hue on error — replace with the error state instead.

---

## 14. Empty States

| Part | Class |
|---|---|
| Container | `bg-card` |
| Icon | `text-muted-foreground/40` (or `text-muted-foreground`) |
| Title | `text-foreground font-medium` |
| Description | `text-muted-foreground text-sm` |
| Action | `x-ui.button variant="secondary"` or `outline` |

Restraint rule: an empty state on a first-use screen (e.g. "No products yet") is **not** an
error and must not use `text-warning` or `text-destructive`.

---

## 15. Charts

| Part | Light | Dark |
|---|---|---|
| Series | `var(--chart-1..8)` in order | dark ramp |
| Axis labels | `--chart-axis-label` `#6B6B74` | `#A1A1AA` |
| Grid lines | `--chart-grid` `#E4E4E7`, 1px | `#2A2A2D` |
| Tooltip | `bg-popover border border-border text-popover-foreground` | auto |
| Bar hover | `opacity-80` | same |
| Reference/target line | `stroke: var(--muted-foreground)` dashed | auto |

**Hard rules:** no gradients under bars, no glow, no colored drop shadows, and every series has
a legend label + marker/dash style. No chart library is installed yet — no chart work in P1–P4.

---

## 16. Status Indicator Primitives

Three primitives, deliberately distinct:

| Primitive | Meaning | Color source | Example |
|---|---|---|---|
| **Badge** | A discrete record's state | `StatusPalette` | Invoice "Paid" |
| **Status dot** | Ambient system health | solid variant color | "Online • Cloud Sync" |
| **Stock bar** | Continuous quantity | `bg-muted` track + `bg-foreground` fill; `bg-warning`/`bg-destructive` at thresholds | Restock bar |

Current `.restock-bar i` uses `#787774` (warm grey) — must become `bg-foreground` or
`bg-muted-foreground`. Current `.dot-warning` `#C88A2E` / `.dot-success` `#047857` must become
`bg-warning` / `bg-success`.

---

## 17. Component Migration Checklist

For each component when touched:

1. Replace every palette utility with a token utility.
2. Delete every `dark:` utility.
3. Remove gradient / colored-shadow declarations.
4. Confirm all six states exist (default, hover, focus, active, disabled, error).
5. Confirm the component's color source is a token, not a `match()` in the component.
6. Screenshot light + dark; compare against `10-color-system-acceptance-criteria.md`.
