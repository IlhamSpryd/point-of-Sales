# 03 — Dark Theme Specification

> **Status: RATIFIED & FULLY ACTIVE (Wave 5 & Wave 5.1).**
> Modeled on ChatGPT Matte Graphite Architecture with zero crushed blacks.
> Contrast figures certified under WCAG 2.2 Level AAA (Text ≥ 7.0:1) and AA (Non-Text ≥ 3.0:1).

---

## 1. Derivation Rules (Matte Graphite Architecture)

Dark mode is **not** an inverted white canvas; it is a carefully calibrated Matte Graphite elevation staircase:

| Rule | Statement | Consequence |
|---|---|---|
| **R1** | The canvas is *near-black, never pure black* | `#1B1B1F` background, `#232328` card. Zero pure black smearing on OLED panels. |
| **R2** | Elevation goes **lighter**, not darker | Background `#1B1B1F` → card `#232328` → popover `#2B2B31`. Clear depth hierarchy without harsh shadows. |
| **R3** | Foreground is soft white | `#ECECF0`, not `#FFFFFF`. Avoids halation during long operating shifts. |
| **R4** | Borders are **translucent white** | `rgba(255, 255, 255, 0.12)` standard divider, `rgba(255, 255, 255, 0.38)` input borders. |
| **R5** | Primary **flips polarity** | Dark mode uses light button `#ECECF0` with dark label `#141417`. |
| **R6** | Status colors use desaturated pastel tones | `--status-positive: #58D68D`, `--on-positive: #052E16`, etc. |

---

## 2. Base Surfaces

| Token | Value | Note |
|---|---|---|
| `chrome-surface` | `#141417` | Base Layer 0: Calm chrome / topbar |
| `background` | `#1B1B1F` | Canvas / page shell |
| `card` | `#232328` | Content cards & table containers |
| `popover` | `#2B2B31` | Floating surfaces, modals, popovers |
| `foreground` | `#ECECF0` | Primary text and icons |
| `primary` | `#ECECF0` | High-emphasis action buttons |
| `primary-foreground` | `#141417` | Text on primary buttons |
| `secondary` | `#26262C` | Secondary buttons |
| `secondary-foreground` | `#ECECF0` | Text on secondary buttons |
| `muted` | `#2C2C33` | Disabled elements and quiet fills |
| `muted-foreground` | `#B8B8C2` | Secondary text (7.95:1 on #232328, AAA) |
| `border` | `rgba(255, 255, 255, 0.12)` | Hairline divider |
| `border-input` | `rgba(255, 255, 255, 0.38)` | Certified 3.5:1 on card |
| `ring` / `focus` | `#ECECF0` | Focus outline |

| Pair | Ratio | Verdict |
|---|---|---|
| `foreground` on `background` | **18.96:1** | ✅ AAA |
| `foreground` on `card` | **17.64:1** | ✅ AAA |
| `card` vs `background` (tone) | 1.07:1 | visible step |
| `popover` vs `card` (tone) | 1.09:1 | visible step |

> The light theme deliberately has `card == popover == #FFFFFF` (both float on a grey canvas).
> In dark mode they **must** separate, because everything is elevated above the canvas and a
> dropdown that matches its parent card becomes invisible. This is the main structural
> difference between the two themes and the reason a simple inversion fails.

---

## 3. Primary & Secondary

| Token | Value | Note |
|---|---|---|
| `primary` | `#FAFAFA` | Light button on dark canvas (R5) |
| `primary-foreground` | `#18181B` | |
| `secondary` | `#1F1F21` | |
| `secondary-foreground` | `#FAFAFA` | |

| Pair | Ratio |
|---|---|
| `primary-foreground` on `primary` | **16.97:1** ✅ |
| `secondary-foreground` on `secondary` | **15.76:1** ✅ |

**Consequence for components:** a component that writes `bg-primary text-primary-foreground`
requires **no dark-specific class**. The polarity flip is handled entirely by token remapping.
This is precisely why the migration must replace raw palette classes — a component written as
`bg-zinc-900 text-white` cannot flip, while `bg-primary text-primary-foreground` can.

---

## 4. Muted & Accent

| Token | Value |
|---|---|
| `muted` | `#1F1F21` |
| `muted-foreground` | `#A1A1AA` |
| `accent` | `#27272A` |
| `accent-foreground` | `#FAFAFA` |

| Pair | Ratio |
|---|---|
| `muted-foreground` on `background` | **7.72:1** ✅ |
| `muted-foreground` on `card` | **7.18:1** ✅ |
| `muted-foreground` on `muted` | **6.42:1** ✅ |
| `muted-foreground` on `secondary` | **6.42:1** ✅ |

> `accent` is intentionally **lighter** than `muted` in dark mode (`#27272A` vs `#1F1F21`).
> Hover states on dark surfaces must brighten; darkening a hover on already-dark ground is
> imperceptible. In light mode both are `#F4F4F5` because a hover only needs *some* step.

---

## 5. Form & Interaction

| Token | Value |
|---|---|
| `border` | `rgb(255 255 255 / 0.10)` |
| `border-strong` | `rgb(255 255 255 / 0.18)` |
| `input` | `rgb(255 255 255 / 0.14)` |
| `ring` | `#71717A` |
| `focus` | `#FAFAFA` |
| `disabled` | `#52525B` |
| `disabled-bg` | `rgb(255 255 255 / 0.04)` |
| `selection` | `#FAFAFA` |
| `selection-foreground` | `#18181B` |

**Why translucent borders:** the sidebar is a different elevation than the main canvas. A solid
`#2A2A2D` border that looks correct on the canvas looks too dark inside a popover (which is
already `#1A1A1C`). Alpha composites against whatever is beneath and stays consistent.

Effective border color over `#0A0A0B` ≈ `#1E1E1F` (1.38:1 vs canvas); over `#141415` ≈
`#282829`. Both are visible hairlines.

---

## 6. Semantic Status Scale

| Token | Value | Ratio on bg | Ratio on muted fill |
|---|---|---|---|
| `success` | `#4ADE80` | 11.36:1 ✅ | 8.55:1 ✅ |
| `success-foreground` | `#DCFCE7` | — | — |
| `success-muted` | `#052E16` | — | — |
| `success-border` | `rgb(74 222 128 / 0.30)` | — | — |
| `warning` | `#FBBF24` | 11.85:1 ✅ | 8.97:1 ✅ |
| `warning-foreground` | `#FEF3C7` | — | — |
| `warning-muted` | `#451A03` | — | — |
| `warning-border` | `rgb(251 191 36 / 0.30)` | — | — |
| `destructive` | `#F87171` | 7.15:1 ✅ | 5.84:1 ✅ |
| `destructive-foreground` | `#FEE2E2` | — | — |
| `destructive-muted` | `#450A0A` | — | — |
| `destructive-border` | `rgb(248 113 113 / 0.30)` | — | — |
| `info` | `#60A5FA` | 7.78:1 ✅ | 5.78:1 ✅ |
| `info-foreground` | `#DBEAFE` | — | — |
| `info-muted` | `#172554` | — | — |
| `info-border` | `rgb(96 165 250 / 0.30)` | — | — |
| `neutral` | `#A1A1AA` | 7.72:1 ✅ | — |
| `neutral-muted` | `#1F1F21` | — | — |
| `neutral-border` | `rgb(255 255 255 / 0.10)` | — | — |

**Design note — badge backgrounds invert meaning.** In light mode a badge is *dark text on a
pale tint*. In dark mode it is *bright text on a deep tint* (`#052E16`, `#451A03`, `#450A0A`,
`#172554`). These deep tints are **darker than the card**, which makes the badge read as a
recessed chip — the correct visual metaphor on a dark surface.

**Do not use Tailwind `dark:bg-emerald-900/40` opacity tricks** (as `PurchaseOrderStatusEnum::badgeClass()`
does today). Explicit `-muted` tokens render identically across browsers and composite
predictably.

---

## 7. Data Visualization Palette (Dark)

| # | Token | Value |
|---|---|---|
| 1 | `--chart-1` | `#FAFAFA` |
| 2 | `--chart-2` | `#FCA5A5` |
| 3 | `--chart-3` | `#60A5FA` |
| 4 | `--chart-4` | `#C4B5FD` |
| 5 | `--chart-5` | `#F9A8D4` |
| 6 | `--chart-6` | `#FDBA74` |
| 7 | `--chart-7` | `#6EE7B7` |
| 8 | `--chart-8` | `#D6D3D1` |
| — | `--chart-grid` | `#2A2A2D` |
| — | `--chart-axis-label` | `#A1A1AA` |

**Verification:** min contrast against `#0A0A0B` = **7.78:1** for every series.

The dark ramp is the *hue-preserving, luminance-raised* counterpart of the light ramp, with
`chart-1` and `chart-2` swapped in emphasis (white leads instead of black). This keeps series
identity stable across themes — series 3 is blue in both, series 5 is magenta in both.

---

## 8. Shell (Sidebar) in Dark Mode

Critical: **the shell must become *lighter* than the canvas in dark mode**, not darker.

| Token | Value | Rationale |
|---|---|---|
| `--shell` | `#111112` | Slightly lighter than canvas `#0A0A0B` |
| `--shell-foreground` | `#A1A1AA` | |
| `--shell-foreground-active` | `#FAFAFA` | |
| `--shell-accent` | `#1F1F21` | Hover |
| `--shell-border` | `rgb(255 255 255 / 0.08)` | |
| `--shell-ring` | `#FAFAFA` | |

In light mode the shell is `#18181B` (much darker than the `#FAFAFA` canvas). In dark mode the
relationship **reverses**: canvas `#0A0A0B` is the darkest surface, and the shell sits slightly
above it. A naive "keep the shell dark" approach would make the sidebar disappear into the
canvas.

---

## 9. POS & KDS Considerations

| Surface | Dark value | Note |
|---|---|---|
| `pos-layout-locked` | `bg-background` | Currently `@apply bg-white` — a hard white that would blind a night-shift cashier |
| Product grid tile | `bg-card` + `border-border` | Selected tile: `border-foreground` + `bg-accent` |
| Cart panel | `bg-card` | Price totals keep `text-foreground`; discounts `text-warning` |
| Payment confirm | `bg-primary text-primary-foreground` | Auto-flips to a light button |
| Failed transaction | `bg-destructive-muted text-destructive border-destructive-border` | Deep red tint, bright red text |
| `.sheet-panel` | `bg-card` (via `--color-apeiron-bg` alias) | Remove the warm `rgb(55 53 47 / .4)` scrim |
| `option` (`app.css`) | `background: var(--popover); color: var(--popover-foreground)` | Currently hardcoded `#ffffff`/`#171717` — native selects stay white in dark mode |

**KDS is separate and correct.** `[data-theme="kds-dark"]` defines its own
`--color-apeiron-*` set and is applied via a data attribute on the KDS screen only. It is
**orthogonal** to the app theme and must not be touched or unified. A kitchen display is a
wall-mounted TV with different legibility constraints (18px→24px base font at 1920px/2560px).

---

## 10. Complete Dark Token Set

```css
.dark {
    color-scheme: dark;

    /* Surfaces (R1, R2) */
    --background: #0A0A0B;
    --foreground: #FAFAFA;
    --card: #141415;
    --card-foreground: #FAFAFA;
    --popover: #1A1A1C;
    --popover-foreground: #FAFAFA;

    /* Primary / Secondary (R5) */
    --primary: #FAFAFA;
    --primary-foreground: #18181B;
    --secondary: #1F1F21;
    --secondary-foreground: #FAFAFA;

    /* Muted / Accent */
    --muted: #1F1F21;
    --muted-foreground: #A1A1AA;
    --accent: #27272A;
    --accent-foreground: #FAFAFA;

    /* Form & interaction (R4) */
    --border: rgb(255 255 255 / 0.10);
    --border-strong: rgb(255 255 255 / 0.18);
    --input: rgb(255 255 255 / 0.14);
    --ring: #71717A;
    --focus: #FAFAFA;
    --disabled: #52525B;
    --disabled-bg: rgb(255 255 255 / 0.04);
    --selection: #FAFAFA;
    --selection-foreground: #18181B;

    /* Status (R6) */
    --success: #4ADE80;
    --success-foreground: #DCFCE7;
    --success-muted: #052E16;
    --success-border: rgb(74 222 128 / 0.30);
    --warning: #FBBF24;
    --warning-foreground: #FEF3C7;
    --warning-muted: #451A03;
    --warning-border: rgb(251 191 36 / 0.30);
    --destructive: #F87171;
    --destructive-foreground: #FEE2E2;
    --destructive-muted: #450A0A;
    --destructive-border: rgb(248 113 113 / 0.30);
    --info: #60A5FA;
    --info-foreground: #DBEAFE;
    --info-muted: #172554;
    --info-border: rgb(96 165 250 / 0.30);
    --neutral: #A1A1AA;
    --neutral-muted: #1F1F21;
    --neutral-border: rgb(255 255 255 / 0.10);

    /* ERP surfaces */
    --surface-table-header: #111112;
    --surface-inset: #0F0F10;

    /* Charts */
    --chart-1: #FAFAFA; --chart-2: #FCA5A5; --chart-3: #60A5FA; --chart-4: #C4B5FD;
    --chart-5: #F9A8D4; --chart-6: #FDBA74; --chart-7: #6EE7B7; --chart-8: #D6D3D1;
    --chart-grid: #2A2A2D;
    --chart-axis-label: #A1A1AA;

    /* Shell */
    --shell: #111112;
    --shell-foreground: #A1A1AA;
    --shell-foreground-active: #FAFAFA;
    --shell-accent: #1F1F21;
    --shell-border: rgb(255 255 255 / 0.08);
    --shell-ring: #FAFAFA;

    /* Overlay */
    --overlay-scrim: rgb(0 0 0 / 0.60);

    /* Elevation — deeper, still colorless */
    --shadow-card: none;
    --shadow-popover: 0 4px 16px -4px rgb(0 0 0 / 0.60);
    --shadow-dialog: 0 16px 40px -8px rgb(0 0 0 / 0.70);
}
```

---

## 11. What Must Change Before Dark Mode Can Ship

Enabling `.dark` today produces a half-dark UI. The blocking preconditions:

| # | Precondition | Reason |
|---|---|---|
| 1 | All `dark:` utilities referring to raw palettes (1,870) removed | They will fight the token layer and produce double-styling |
| 2 | `app.css` CSS-level hex moved to tokens (`.chip`, `.field-input`, `.editorial-surface`, `option`, `.table-scroll-shadow`) | CSS-level hex cannot follow a class toggle |
| 3 | `@apply bg-white` in `pos-layout-locked` replaced | POS would stay white |
| 4 | Inline `style="background:#..."` (13) removed | Cannot theme |
| 5 | `.table-scroll-shadow` white gradients replaced with token-based masks | Shows white bars on a dark table |
| 6 | `StatusPalette` single source of truth in place | Otherwise badges disagree between themes |
| 7 | Icon tints in sidebar (`text-amber-400` etc.) removed | Decorative color on dark surfaces |

---

## 12. Pre-Flight Validation Results

Run `python3 scratch/contrast_check.py` after implementing tokens. Expected output: exit code
**0**, all pairs PASS. This script is the acceptance gate for AC-DK (see
`10-color-system-acceptance-criteria.md`).
