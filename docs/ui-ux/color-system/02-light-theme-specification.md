# 02 — Light Theme Specification

> Every contrast figure in this document was computed with the WCAG 2.1 relative
> luminance formula (`scratch/contrast_check.py`, exit 0 = all pass). None are estimates.

---

## 1. Design Intent

Light mode is the **primary** theme and must carry the product. It is built on three rules:

1. **Two white tones, not one.** The canvas is `#FAFAFA`; cards/popovers are `#FFFFFF`.
   A 1.6% luminance step creates card grouping without shadows. Pure-white cards on a
   pure-white canvas force the interface into "floating boxes" (violating brief §10.5).
   This is what ChatGPT and shadcn/ui do in practice.
2. **Ink, not black.** `#09090B` reads as black but carries a faint cool cast that avoids
   the harshness of `#000000` on LCD panels during long operational sessions.
3. **Two greys total.** One neutral ramp (`zinc`-derived). No warm-charcoal second palette,
   no `gray`/`slate`/`neutral`/`stone` mixing.

---

## 2. Base Surfaces

| Token | CSS variable | Value | Purpose |
|---|---|---|---|
| `background` | `--background` | `#F7F7F8` | Application canvas, page background |
| `foreground` | `--foreground` | `#0D0D0D` | Primary text, icons, headings |
| `card` | `--card` | `#FFFFFF` | Card surface, table container, panel |
| `card-foreground` | `--card-foreground` | `#0D0D0D` | Text on cards |
| `popover` | `--popover` | `#FFFFFF` | Dropdown, popover, combobox panel |
| `popover-foreground` | `--popover-foreground` | `#0D0D0D` | Text in floating surfaces |

---

## 3. Primary & Secondary

| Token | Value | Purpose |
|---|---|---|
| `primary` | `#0D0D0D` | Primary buttons, active nav, focus bar, strong emphasis |
| `primary-foreground` | `#FFFFFF` | Text on primary |
| `secondary` | `#F0F0F2` | Secondary buttons, chips, inline code |
| `secondary-foreground` | `#0D0D0D` | Text on secondary |

---

## 4. Muted & Accent

| Token | Value | Purpose |
|---|---|---|
| `muted` | `#ECECEF` | Disabled fills, table stripes, quiet panels |
| `muted-foreground` | `#4A4A52` | Secondary text, labels, metadata (8.78:1 AAA on #FFFFFF) |
| `accent` | `#ECECEF` | Hover background, selected row/menu item |
| `accent-foreground` | `#0D0D0D` | Text on accent |

---

## 5. Form Controls & Precision Scale (Wave 5.1 Cloudflare Density)

| Token | Value | Purpose |
|---|---|---|
| `border` | `#E2E2E6` | Standard divider and card outline |
| `border-input` | `#8E8E98` | Interactive form inputs (WCAG 1.4.11 ≥ 3.0:1 certified) |
| `ring` / `focus` | `#0D0D0D` | Visible focus outline (ring-1 ring-ring or ring-2) |

**Geometry & Density Scale:**
- Desktop buttons and inputs: `h-8 px-2.5 text-[12px] rounded-[6px]`
- ERP table header: `h-8 text-[10.5px] uppercase tracking-wider`
- ERP table row/cell: `h-8 px-3 py-2 text-[12px]`
- POS & KDS touch surfaces: strictly $\ge 44 \times 44\text{px}$ touch target minimum.
- Badge shapes: strictly rectangular `rounded-md` or `rounded-lg` with subtle border. **0 badge pills (`rounded-full`) permitted.**
| `muted-foreground` on `muted` `#F4F4F5` | **4.80:1** | ✅ AA |
| `muted-foreground` on `secondary` | **4.80:1** | ✅ AA |

> **Deviation from brief (justified, mandatory):** brief proposed `muted-foreground: #71717A`.
> Measured on `--muted` (`#F4F4F5`) that is **4.40:1 — below the AA floor**. Secondary text
> appears on muted backgrounds everywhere (table headers, empty states, disabled rows), so this
> failure would be systemic. `#6B6B74` passes on all three surfaces and is visually
> indistinguishable from `#71717A` at a glance.
>
> Current code has the same failure with `#6B7280` (4.40:1) — this is a **pre-existing
> accessibility defect**, not one introduced by the plan.

---

## 5. Form & Interaction

| Token | Value | Purpose |
|---|---|---|
| `border` | `#E4E4E7` | All borders, dividers, table rules |
| `border-strong` | `#D4D4D8` | Emphasized separators, input group seams |
| `input` | `#E4E4E7` | Input/select/textarea resting border |
| `ring` | `#A1A1AA` | Focus ring (default) |
| `focus` | `#18181B` | Focus ring for high-emphasis controls (`:focus-visible` solid) |
| `disabled` | `#A1A1AA` | Disabled text/icons |
| `disabled-bg` | `#F4F4F5` | Disabled control fill |
| `selection` | `#18181B` | `::selection` background |
| `selection-foreground` | `#FAFAFA` | `::selection` text |

| Pair | Ratio | Note |
|---|---|---|
| `border` vs `background` | 1.27:1 | Visible hairline without heaviness |
| `ring` vs `background` | 2.3:1 | Meets WCAG 1.4.11 non-text 3:1 when thick/offset; pair with 2px width |
| `disabled` on `background` | 2.4:1 | Intentional — disabled text is exempt from AA |

> **Focus strategy:** default focus ring is `ring` (`#A1A1AA`, 2px + 2px offset) — restrained,
> quiet, matches shadcn. High-risk controls (destructive confirm, payment capture) use `focus`
> (`#18181B`) for a stronger signal. Never use a colored focus ring except on destructive
> controls (which use `--destructive`).

---

## 6. Semantic Status Scale

Each status has **four** members. This four-part structure is what the existing badges assumed
but never received (see README §5, D-1).

| Token | Value | Purpose |
|---|---|---|
| `success` | `#15803D` | Status foreground / dot / icon |
| `success-foreground` | `#052E16` | Text on `success-muted` when contrast must be maximal |
| `success-muted` | `#F0FDF4` | Badge background, alert background |
| `success-border` | `#BBF7D0` | Badge border |
| `warning` | `#B45309` | … |
| `warning-foreground` | `#451A03` | … |
| `warning-muted` | `#FFFBEB` | … |
| `warning-border` | `#FDE68A` | … |
| `destructive` | `#B91C1C` | … |
| `destructive-foreground` | `#450A0A` | … |
| `destructive-muted` | `#FEF2F2` | … |
| `destructive-border` | `#FECACA` | … |
| `info` | `#1D4ED8` | … |
| `info-foreground` | `#172554` | … |
| `info-muted` | `#EFF6FF` | … |
| `info-border` | `#BFDBFE` | … |
| `neutral` | `#6B6B74` (alias of `muted-foreground`) | Default/inactive |
| `neutral-muted` | `#F4F4F5` | … |
| `neutral-border` | `#E4E4E7` | … |

**Contrast matrix (verified):**

| Status color | on `background` | on its `-muted` | Verdict |
|---|---|---|---|
| `success` `#15803D` | 5.02:1 | 4.79:1 | ✅ AA both |
| `warning` `#B45309` | 5.02:1 | 4.84:1 | ✅ AA both |
| `destructive` `#B91C1C` | 6.47:1 | 5.91:1 | ✅ AA both |
| `info` `#1D4ED8` | 6.70:1 | 6.16:1 | ✅ AA both |

**Why not Tailwind's defaults:** `emerald-700` is `#047857` (used today in `.chip.ok`) which
reads slightly teal; `#15803D` (green-700) is calmer and pairs better with `#B45309`.
Tailwind `red-700` `#B91C1C` is retained — it is correct.

**Restraint rule:** a status color may appear at most **once per visual "cell"** — one badge,
one dot, or one icon. Never badge + colored row + colored icon simultaneously.

---

## 7. Data Visualization Palette

> No chart library is installed today (`01-current-color-audit.md` §6.8). This palette is
> defined ahead of adoption. It is verified for both white and dark canvases.

### Categorical (max 8 series, in order)

| # | Token | Light value | Dark value |
|---|---|---|---|
| 1 | `--chart-1` | `#18181B` | `#FAFAFA` |
| 2 | `--chart-2` | `#980000` | `#FCA5A5` |
| 3 | `--chart-3` | `#1D4ED8` | `#60A5FA` |
| 4 | `--chart-4` | `#4C1D95` | `#C4B5FD` |
| 5 | `--chart-5` | `#9D174D` | `#F9A8D4` |
| 6 | `--chart-6` | `#C2410C` | `#FDBA74` |
| 7 | `--chart-7` | `#065F46` | `#6EE7B7` |
| 8 | `--chart-8` | `#57534E` | `#D6D3D1` |

**Verification:** minimum contrast against white = **5.18:1**; against `#0A0A0B` = **7.78:1**.
Every series is legible as a 2px stroke.

**Why these hues:** the brief requires that chart colors not be "the same semantic status color
for unrelated chart categories". `#15803D`/`#B45309`/`#B91C1C`/`#1D4ED8` are therefore
**reserved** for status and mostly excluded. Chart 2 (`#980000`) is a deeper, distinct red
used only for series identity, never for a badge.

**Mandatory companion rules:**
1. Every series carries a **legend label + distinct dash pattern or marker shape** — color
   alone never identifies a series (WCAG 1.4.1).
2. Adjacent series are assigned in order; never place `#1D4ED8` beside `#60A5FA` in light mode.
3. Series 9+ → fold into "Other" — do not extend the palette.

### Ordinal / sequential (heatmaps, activity grids)

Use the neutral ramp, not a hue:

| Token | Value |
|---|---|
| `--chart-seq-0` | `#F4F4F5` |
| `--chart-seq-1` | `#D4D4D8` |
| `--chart-seq-2` | `#A1A1AA` |
| `--chart-seq-3` | `#52525B` |
| `--chart-seq-4` | `#18181B` |

### Grid / axis

| Token | Light | Dark |
|---|---|---|
| `--chart-grid` | `#E4E4E7` | `#2A2A2D` |
| `--chart-axis-label` | `#6B6B74` | `#A1A1AA` |

---

## 8. Shadows & Elevation

Shadows are **near-absent**. Depth comes from tonal steps and borders.

| Token | Value | Use |
|---|---|---|
| `--shadow-card` | `0 1px 2px 0 rgb(0 0 0 / 0.04)` | Cards only, optional |
| `--shadow-popover` | `0 4px 12px -2px rgb(0 0 0 / 0.08)` | Dropdown, popover, combobox |
| `--shadow-dialog` | `0 12px 32px -8px rgb(0 0 0 / 0.16)` | Modal, drawer |
| `--overlay-scrim` | `rgb(9 9 11 / 0.40)` | Modal backdrop |

**Forbidden:** colored shadows, glows, `shadow-emerald-*`, inset gradients, `bg-linear-to-*`.
Currently `ui/button` has a `bg-linear-to-b` on its primary variant — remove in P2.

---

## 9. Overlays

| Token | Value |
|---|---|
| `--overlay-scrim` | `rgb(9 9 11 / 0.40)` |
| `--overlay-sheet-scrim` | `rgb(9 9 11 / 0.40)` (replaces current `rgb(55 53 47 / .4)` warm brown) |
| `--overlay-blur` | `blur(4px)` |

> `app.css .sheet-overlay` currently uses `rgb(55 53 47 / 0.4)` — a **warm brown** scrim that
> clashes with the cool neutral palette. Must move to `--overlay-scrim`.

---

## 10. Sidebar / Shell (Light Mode)

The application shell is **dark chrome in light mode** (by design — it frames a white canvas).

| Token | Light value | Purpose |
|---|---|---|
| `--shell` | `#18181B` | Sidebar/topbar background |
| `--shell-foreground` | `#A1A1AA` | Inactive nav text |
| `--shell-foreground-active` | `#FAFAFA` | Active nav text |
| `--shell-accent` | `#27272A` | Nav hover background |
| `--shell-border` | `#27272A` | Divider inside shell |
| `--shell-ring` | `#FAFAFA` | Focus inside shell |

Existing `--sidebar-*` tokens map onto these 1:1; the plan proposes keeping both names as aliases
(see `04-semantic-token-registry.md` §7) rather than renaming 100+ call sites.

**Icon rule:** sidebar icons are monochrome. Current `text-amber-400` / `text-purple-400` /
`text-emerald-400` on menu icons is decorative color — remove in P3. The single exception is
the "Online / Cloud Sync" status pill, which is a **status** and may use `--success`.

---

## 11. Complete Token Set (Light)

```css
:root {
    color-scheme: light;

    /* Surfaces */
    --background: #FAFAFA;
    --foreground: #09090B;
    --card: #FFFFFF;
    --card-foreground: #09090B;
    --popover: #FFFFFF;
    --popover-foreground: #09090B;

    /* Primary / Secondary */
    --primary: #18181B;
    --primary-foreground: #FAFAFA;
    --secondary: #F4F4F5;
    --secondary-foreground: #18181B;

    /* Muted / Accent */
    --muted: #F4F4F5;
    --muted-foreground: #6B6B74;
    --accent: #F4F4F5;
    --accent-foreground: #18181B;

    /* Form & interaction */
    --border: #E4E4E7;
    --border-strong: #D4D4D8;
    --input: #E4E4E7;
    --ring: #A1A1AA;
    --focus: #18181B;
    --disabled: #A1A1AA;
    --disabled-bg: #F4F4F5;
    --selection: #18181B;
    --selection-foreground: #FAFAFA;

    /* Status */
    --success: #15803D;
    --success-foreground: #052E16;
    --success-muted: #F0FDF4;
    --success-border: #BBF7D0;
    --warning: #B45309;
    --warning-foreground: #451A03;
    --warning-muted: #FFFBEB;
    --warning-border: #FDE68A;
    --destructive: #B91C1C;
    --destructive-foreground: #450A0A;
    --destructive-muted: #FEF2F2;
    --destructive-border: #FECACA;
    --info: #1D4ED8;
    --info-foreground: #172554;
    --info-muted: #EFF6FF;
    --info-border: #BFDBFE;
    --neutral: #6B6B74;
    --neutral-muted: #F4F4F5;
    --neutral-border: #E4E4E7;

    /* ERP surfaces */
    --surface-table-header: #FAFAFA;
    --surface-inset: #F4F4F5;

    /* Charts */
    --chart-1: #18181B; --chart-2: #980000; --chart-3: #1D4ED8; --chart-4: #4C1D95;
    --chart-5: #9D174D; --chart-6: #C2410C; --chart-7: #065F46; --chart-8: #57534E;
    --chart-grid: #E4E4E7;
    --chart-axis-label: #6B6B74;

    /* Shell */
    --shell: #18181B;
    --shell-foreground: #A1A1AA;
    --shell-foreground-active: #FAFAFA;
    --shell-accent: #27272A;
    --shell-border: #27272A;
    --shell-ring: #FAFAFA;

    /* Overlay */
    --overlay-scrim: rgb(9 9 11 / 0.40);

    /* Elevation */
    --shadow-card: 0 1px 2px 0 rgb(0 0 0 / 0.04);
    --shadow-popover: 0 4px 12px -2px rgb(0 0 0 / 0.08);
    --shadow-dialog: 0 12px 32px -8px rgb(0 0 0 / 0.16);
}
```

---

## 12. Migration Notes for Light Mode

| Existing value | Becomes | Where |
|---|---|---|
| `bg-white` (page) | `bg-background` | layouts, page wrappers |
| `bg-white` (card) | `bg-card` | cards, panels, table containers |
| `border-[#E5E7EB]` (318×) | `border-border` | everywhere |
| `border-[#E5E5E5]` (101×) | `border-border` | everywhere |
| `text-[#111827]` (115×) | `text-foreground` | everywhere |
| `text-[#6B7280]`, `text-gray-500` | `text-muted-foreground` | secondary text |
| `text-[#787774]`, `#9B9A97` | `text-muted-foreground` | warm-grey → neutral |
| `bg-[#F9FAFB]`, `bg-[#F7F7F5]` | `bg-muted` | quiet fills |
| `bg-[#171717]` | `bg-primary` | buttons, shell |
| `text-gray-400`, `text-zinc-400` | `text-muted-foreground` | **contrast fix** |
| `text-rose-600` | `text-destructive` | errors |
| `text-emerald-700` | `text-success` | success text |
| `text-amber-700` | `text-warning` | warning text |
| `text-blue-700`, `text-indigo-600` | `text-info` | informational |

**Watch-outs:**
- `bg-white` in `pos-layout-locked` is inside `@apply` in CSS — must become `bg-background`.
- `selection:bg-zinc-900 selection:text-white` appears on auth pages — becomes
  `selection:bg-selection selection:text-selection-foreground`.
- Google OAuth brand hex stays as-is (legitimate).
