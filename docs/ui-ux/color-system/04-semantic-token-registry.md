# 04 — Semantic Token Registry

> The single normative reference. Every other document derives from this one.
> If a value here disagrees with a value elsewhere, **this file wins**.

---

## 1. Naming Conventions

### 1.1 CSS variable names

```
--{property-or-concept}[-{variant}][-{state}]
```

| Layer | Prefix | Example | Allowed in views? |
|---|---|---|---|
| L0 Primitives | `--rr-*` (raw ramp) | `--rr-gray-500` | ❌ never |
| L1 Semantic | none | `--background`, `--success` | ✅ **yes** |
| L2 Component | `--{component}-{property}` | `--badge-success-bg` | ⚠️ components only |
| L3 Domain | n/a (PHP) | `StatusPalette::variant()` | n/a |

### 1.2 Tailwind utility names

The mapping `--color-X: var(--X)` inside `@theme inline` yields `bg-X`, `text-X`, `border-X`,
`ring-X`, `divide-X`, `fill-X`, `stroke-X`, `outline-X`, `decoration-X`, `placeholder-X`,
`caret-X`, `accent-X`.

**Rules**

| Rule | Statement |
|---|---|
| **N1** | A view may only use utilities whose name matches an L1 token. |
| **N2** | Never use a Tailwind palette utility (`bg-zinc-100`) outside the primitive layer. |
| **N3** | Never use an arbitrary value (`bg-[#E5E7EB]`) — no exceptions except third-party brand marks. |
| **N4** | Never use `bg-white` / `bg-black` / `text-white` / `text-black`. Use `bg-card`, `bg-background`, `text-foreground`, `text-primary-foreground`. |
| **N5** | State is expressed with **tokens**, never with opacity tricks on a palette color (e.g. `bg-success/10` is forbidden; use `bg-success-muted`). |
| **N6** | A `dark:` utility is forbidden once tokens are complete. The token remaps itself. |
| **N7** | Component-level component colors (L2) must be derived from L1, never invented. |

### 1.3 Naming suffix vocabulary

| Suffix | Meaning | Example |
|---|---|---|
| *(none)* | Primary role color | `--success` |
| `-foreground` | Text/icon color **on** that surface | `--success-foreground` |
| `-muted` | De-emphasized background variant | `--success-muted` |
| `-border` | Border variant | `--success-border` |
| `-strong` | Emphasized variant | `--border-strong` |
| `-active` | Active/selected state | `--shell-foreground-active` |

**Banned suffixes:** `-light`, `-dark`, `-50`/`-100`/`-700` (Tailwind ramp naming on a
semantic token is an anti-pattern — it invites the reader to treat it as a scalable palette).

> **Direct consequence:** the existing classes `bg-success-50`, `text-success-700`,
> `border-success-600/20` must be **deleted**, not defined. Defining them would cement a
> ramp API that the design rejects.

---

## 2. Complete Registry

Legend: **L** = light value, **D** = dark value, **U** = utility name(s), **Use** = where it belongs.

### 2.1 Surfaces

| Token | L | D | Utilities | Use |
|---|---|---|---|---|
| `background` | `#F7F7F8` | `#1B1B1F` | `bg-background` | `<body>`, page wrappers |
| `foreground` | `#0D0D0D` | `#ECECF0` | `text-foreground`, `border-foreground` | Body text, strong borders, active indicators |
| `card` | `#FFFFFF` | `#232328` | `bg-card` | Cards, table containers, panels |
| `card-foreground` | `#0D0D0D` | `#ECECF0` | `text-card-foreground` | Text on cards |
| `popover` | `#FFFFFF` | `#2B2B31` | `bg-popover` | Dropdowns, popovers, combobox, tooltips |
| `popover-foreground` | `#0D0D0D` | `#ECECF0` | `text-popover-foreground` | Text in floating surfaces |
| `surface-inset` | `#F0F0F2` | `#19191D` | `bg-surface-inset` | Code blocks, read-only fields, nested wells |
| `surface-table-header` | `#FAFAFA` | `#1E1E22` | `bg-surface-table-header` | `<thead>` |

### 2.2 Primary / Secondary

| Token | L | D | Utilities | Use |
|---|---|---|---|---|
| `primary` | `#0D0D0D` | `#ECECF0` | `bg-primary`, `text-primary`, `border-primary` | Primary buttons, active tab underline, checkbox fill |
| `primary-foreground` | `#FFFFFF` | `#141417` | `text-primary-foreground` | Label on primary |
| `secondary` | `#F0F0F2` | `#26262C` | `bg-secondary` | Secondary buttons, chips |
| `secondary-foreground` | `#0D0D0D` | `#ECECF0` | `text-secondary-foreground` | |

### 2.3 Muted / Accent

| Token | L | D | Utilities | Use |
|---|---|---|---|---|
| `muted` | `#ECECEF` | `#2C2C33` | `bg-muted` | Table stripes, disabled fills, quiet panels |
| `muted-foreground` | `#4A4A52` | `#B8B8C2` | `text-muted-foreground` | Secondary text, labels, metadata, table headers |
| `accent` | `#ECECEF` | `#32323A` | `bg-accent` | **Hover** and **selected** backgrounds only |
| `accent-foreground` | `#0D0D0D` | `#ECECF0` | `text-accent-foreground` | Text on hover/selected |

> **Discipline:** `accent` means *transient interaction state*, never *decoration*.
> Using `bg-accent` for a static panel is a misuse — use `bg-muted`.

### 2.4 Form & Interaction

| Token | L | D | Utilities | Use |
|---|---|---|---|---|
| `border` | `#E2E2E6` | `rgba(255, 255, 255, 0.12)` | `border-border`, `divide-border` | All borders, table rules, separators |
| `border-strong` | `#C8C8D0` | `rgba(255, 255, 255, 0.22)` | `border-border-strong` | Emphasis seams, input groups |
| `border-input` / `input` | `#8E8E98` | `rgba(255, 255, 255, 0.38)` | `border-input` | Resting border of input/select/textarea (WCAG 1.4.11 ≥ 3.0:1) |
| `ring` / `focus` | `#0D0D0D` | `#ECECF0` | `ring-ring`, `ring-focus` | Default and high-emphasis focus ring |
| `disabled` | `#9E9EA8` | `#585862` | `text-disabled` | Disabled text/icons |
| `disabled-bg` | `#F4F4F6` | `rgba(255, 255, 255, 0.05)` | `bg-disabled-bg` | Disabled control fill |
| `selection` | `#0D0D0D` | `#ECECF0` | `selection:bg-selection` | `::selection` |
| `selection-foreground` | `#FFFFFF` | `#141417` | `selection:text-selection-foreground` | `::selection` text |

### 2.5 Status Semantics (Editorial Desaturated, AAA Certified ≥ 7.0:1)

| Token | L | D | Utilities |
|---|---|---|---|
| `positive` / `success` | `#14532D` | `#58D68D` | `text-positive`, `bg-positive`, `border-positive` |
| `positive-foreground` | `#052E16` | `#022C22` | `text-positive-foreground` |
| `positive-muted` | `#F0FDF4` | `#064E3B` | `bg-positive-muted` |
| `positive-border` | `#BBF7D0` | `rgba(88, 214, 141, 0.35)` | `border-positive-border` |
| `on-positive` | `#FFFFFF` | `#052E16` | Text on solid positive background |
| `warning` | `#78350F` | `#E5B942` | `text-warning`, `bg-warning` |
| `warning-foreground` | `#451A03` | `#451A03` | `text-warning-foreground` |
| `warning-muted` | `#FFFBEB` | `#78350F` | `bg-warning-muted` |
| `warning-border` | `#FDE68A` | `rgba(229, 185, 66, 0.35)` | `border-warning-border` |
| `on-warning` | `#FFFFFF` | `#271200` | Text on solid warning background |
| `destructive` | `#991B1B` | `#F89B9B` | `text-destructive`, `bg-destructive` |
| `destructive-foreground` | `#450A0A` | `#450A0A` | `text-destructive-foreground` |
| `destructive-muted` | `#FEF2F2` | `#7F1D1D` | `bg-destructive-muted` |
| `destructive-border` | `#FECACA` | `rgba(248, 155, 155, 0.35)` | `border-destructive-border` |
| `on-destructive` | `#FFFFFF` | `#2B0606` | Text on solid destructive background |
| `info` | `#1E40AF` | `#93C5FD` | `text-info`, `bg-info` |
| `info-foreground` | `#172554` | `#172554` | `text-info-foreground` |
| `info-muted` | `#EFF6FF` | `#1E3A8A` | `bg-info-muted` |
| `info-border` | `#BFDBFE` | `rgba(147, 197, 253, 0.35)` | `border-info-border` |
| `on-info` | `#FFFFFF` | `#0F1E4A` | Text on solid info background |
| `neutral` | `#4A4A52` | `#B8B8C2` | `text-neutral` |
| `neutral-muted` | `#F0F0F2` | `#26262C` | `bg-neutral-muted` |
| `neutral-border` | `#E2E2E6` | `rgba(255, 255, 255, 0.12)` | `border-neutral-border` |

### 2.6 Data Visualization

| Token | L | D |
|---|---|---|
| `chart-1` | `#18181B` | `#FAFAFA` |
| `chart-2` | `#980000` | `#FCA5A5` |
| `chart-3` | `#1D4ED8` | `#60A5FA` |
| `chart-4` | `#4C1D95` | `#C4B5FD` |
| `chart-5` | `#9D174D` | `#F9A8D4` |
| `chart-6` | `#C2410C` | `#FDBA74` |
| `chart-7` | `#065F46` | `#6EE7B7` |
| `chart-8` | `#57534E` | `#D6D3D1` |
| `chart-grid` | `#E4E4E7` | `#2A2A2D` |
| `chart-axis-label` | `#6B6B74` | `#A1A1AA` |
| `chart-seq-0..4` | `#F4F4F5` … `#18181B` | invert ramp |

Charts are consumed **via CSS variable, not utility class**, because chart libraries need
resolved color strings:

```js
getComputedStyle(document.documentElement).getPropertyValue('--chart-1')
```

### 2.7 Shell

| Token | L | D |
|---|---|---|
| `shell` | `#18181B` | `#111112` |
| `shell-foreground` | `#A1A1AA` | `#A1A1AA` |
| `shell-foreground-active` | `#FAFAFA` | `#FAFAFA` |
| `shell-accent` | `#27272A` | `#1F1F21` |
| `shell-border` | `#27272A` | `rgb(255 255 255 / 0.08)` |
| `shell-ring` | `#FAFAFA` | `#FAFAFA` |

### 2.8 Overlay & Elevation

| Token | L | D |
|---|---|---|
| `overlay-scrim` | `rgb(9 9 11 / 0.40)` | `rgb(0 0 0 / 0.60)` |
| `shadow-card` | `0 1px 2px 0 rgb(0 0 0 / .04)` | `none` |
| `shadow-popover` | `0 4px 12px -2px rgb(0 0 0 / .08)` | `0 4px 16px -4px rgb(0 0 0 / .60)` |
| `shadow-dialog` | `0 12px 32px -8px rgb(0 0 0 / .16)` | `0 16px 40px -8px rgb(0 0 0 / .70)` |

---

## 3. Deprecation Map

Old name → new token. **Aliases are kept for one release**, then deleted.

| Deprecated | Replacement | Occurrences (approx) |
|---|---|---|
| `bg-apeiron-bg` | `bg-card` | ~40 |
| `text-apeiron-ink` | `text-foreground` | ~60 |
| `text-apeiron-muted` | `text-muted-foreground` | ~30 |
| `text-apeiron-muted-700` `#4B5563` | `text-muted-foreground` | 11 |
| `border-apeiron-border` | `border-border` | ~20 |
| `bg-apeiron-surface` | `bg-secondary` | ~10 |
| `bg-table-header-bg` | `bg-surface-table-header` | ~15 |
| `bg-status-success` | `bg-success` | few |
| `bg-status-warning` | `bg-warning` | few |
| `bg-status-error` | `bg-destructive` | few |
| `bg-status-info` | `bg-info` | few |
| `bg-status-draft` | `bg-neutral-muted` / `text-neutral` | few |
| `bg-shell` | `bg-shell` (kept, re-pointed) | ~10 |
| `--sidebar-background` | `--shell` | alias |
| `--sidebar-foreground` | `--shell-foreground` | alias |
| `--sidebar-primary` | `--shell-foreground-active` | alias |
| `--sidebar-primary-foreground` | `--shell` | alias |
| `--sidebar-accent` | `--shell-accent` | alias |
| `--sidebar-accent-foreground` | `--shell-foreground-active` | alias |
| `--sidebar-border` | `--shell-border` | alias |
| `--sidebar-ring` | `--shell-ring` | alias |

### 3.1 Deleted (never aliased)

| Class family | Reason |
|---|---|
| `bg-success-{50..900}`, `text-success-{…}`, `border-success-{…}` | Never defined; scale naming rejected by N-suffix rule (§1.3) |
| `bg-danger-*`, `text-danger-*`, `border-danger-*` | Same; plus `danger` duplicates `destructive` |
| `bg-info-{50..900}`, `bg-warning-{…}`, `bg-primary-{…}` | Same |
| `bg-linear-to-*`, `from-*`, `to-*`, `via-*` | Gradients forbidden |
| `shadow-{palette}-*` | Colored shadows forbidden |
| `dark:*` (all 1,870) | Redundant once tokens are complete (N6) |

> **Exception — do not delete:** `border-[#4285F4]` etc. Google OAuth brand marks
> (`fill="#4285F4"`, `#EA4335`, `#FBBC05`, `#34A853`) are third-party trademarks and must
> remain literal.

---

## 4. Status → Variant Resolution (PHP API)

Three enum APIs and four duplicate `match()` cascades exist today (README §5, D-3). Replace
all of them with one class.

### 4.1 Proposed class

```php
<?php
declare(strict_types=1);

namespace App\Support\Color;

enum StatusVariant: string
{
    case Success     = 'success';
    case Warning     = 'warning';
    case Destructive = 'destructive';
    case Info        = 'info';
    case Neutral     = 'neutral';
}

final class StatusPalette
{
    /** Canonical status-string → variant map. THE single source of truth. */
    private const MAP = [
        // success
        'active' => 'success', 'paid' => 'success', 'completed' => 'success',
        'approved' => 'success', 'posted' => 'success', 'matched' => 'success',
        'received' => 'success', 'fulfilled' => 'success', 'settled' => 'success',
        'ready' => 'success', 'in_stock' => 'success', 'balanced' => 'success',

        // warning
        'pending' => 'warning', 'pending_approval' => 'warning',
        'partially_paid' => 'warning', 'partially_received' => 'warning',
        'partially_fulfilled' => 'warning', 'in_transit' => 'warning',
        'low_stock' => 'warning', 'on_hold' => 'warning', 'overdue' => 'warning',
        'draft_approval' => 'warning', 'counting' => 'warning', 'closing' => 'warning',

        // destructive
        'cancelled' => 'destructive', 'void' => 'destructive', 'voided' => 'destructive',
        'rejected' => 'destructive', 'failed' => 'destructive',
        'disputed' => 'destructive', 'expired' => 'destructive',
        'blocked' => 'destructive', 'suspended' => 'destructive',
        'out_of_stock' => 'destructive', 'variance_flagged' => 'destructive',
        'unbalanced' => 'destructive',

        // info
        'issued' => 'info', 'sent' => 'info', 'confirmed' => 'info',
        'converted' => 'info', 'dispatched' => 'info', 'released' => 'info',
        'in_progress' => 'info',

        // neutral
        'draft' => 'neutral', 'inactive' => 'neutral', 'archived' => 'neutral',
        'closed' => 'neutral', 'amended' => 'neutral', 'refunded' => 'neutral',
        'unknown' => 'neutral',
    ];

    public static function variant(string|\BackedEnum|null $status, ?StatusVariant $fallback = null): StatusVariant
    {
        if ($status === null) {
            return $fallback ?? StatusVariant::Neutral;
        }
        $key = strtolower(trim(
            $status instanceof \BackedEnum ? (string) $status->value : $status
        ));

        return isset(self::MAP[$key])
            ? StatusVariant::from(self::MAP[$key])
            : ($fallback ?? StatusVariant::Neutral);
    }

    /** Full Tailwind class set for a badge — identical for every module. */
    public static function badgeClasses(StatusVariant|string $variant): string
    {
        $v = $variant instanceof StatusVariant ? $variant->value : $variant;

        return match ($v) {
            'success'     => 'bg-success-muted text-success border-success-border',
            'warning'     => 'bg-warning-muted text-warning border-warning-border',
            'destructive' => 'bg-destructive-muted text-destructive border-destructive-border',
            'info'        => 'bg-info-muted text-info border-info-border',
            default       => 'bg-neutral-muted text-neutral border-neutral-border',
        };
    }

    public static function dotClasses(StatusVariant|string $variant): string
    {
        $v = $variant instanceof StatusVariant ? $variant->value : $variant;

        return match ($v) {
            'success'     => 'bg-success',
            'warning'     => 'bg-warning',
            'destructive' => 'bg-destructive',
            'info'        => 'bg-info',
            default       => 'bg-neutral',
        };
    }
}
```

### 4.2 Enum contract change

Enums currently expose two competing methods. The target contract:

| Method | Status | Action |
|---|---|---|
| `label(): string` | **keep** | Human-readable label. Already present on most enums. |
| `badgeType(): string` | **keep, delegate** | Change body to `return StatusPalette::variant($this)->value;` — preserves any caller. |
| `badgeClass(): string` | **delete** | Returns raw palette (60 hits). Replace callers with `StatusPalette::badgeClasses(StatusPalette::variant($enum))`. |
| `isFinal()` / `isTerminal()` | keep | Unrelated to color. |

Files requiring `badgeClass()` removal:
`app/Enums/PurchaseOrderStatusEnum.php`, `app/Enums/PoReceivingStatusEnum.php`,
`app/Enums/PoInvoicingStatusEnum.php`.

### 4.3 Component contract

| Component | Becomes |
|---|---|
| `x-ui.badge` | **the** badge implementation: `variant` ∈ {success, warning, destructive, info, neutral, primary, outline}; classes from `StatusPalette::badgeClasses()` |
| `x-badge` | forwarded alias to `x-ui.badge`, deprecated |
| `x-erp.status-badge` | thin wrapper: resolve variant via `StatusPalette::variant()`, render `x-ui.badge` |
| `x-status-text` | thin wrapper: resolve variant, render `text-{variant}` |

After this, exactly **one** place decides a status color.

---

## 5. Consumption Rules (Enforceable)

| ID | Rule | CI check |
|---|---|---|
| **CR-1** | Zero palette utilities outside `tokens.css` | `rg '-(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|green|emerald|blue|indigo|violet|purple|rose)-[0-9]{2,3}' resources/views` → 0 |
| **CR-2** | Zero arbitrary color brackets | `rg '(bg\|text\|border\|ring\|fill\|stroke)-\[#'` → 0 |
| **CR-3** | Zero `dark:` utilities | `rg 'dark:' resources/views` → 0 |
| **CR-4** | Zero `bg-white\|bg-black\|text-white\|text-black` | `rg 'bg-white\|bg-black'` → 0 |
| **CR-5** | Zero gradients | `rg '(bg-gradient\|bg-linear\|from-[a-z]+-[0-9])'` → 0 |
| **CR-6** | No undefined semantic families | every `(bg\|text\|border\|ring)-(success\|warning\|info\|destructive\|neutral\|primary\|secondary\|muted\|accent)-[a-z]` must resolve to a real token |

> **Automation hazard (repeated from `01` §3.2):** CR-2 must exclude
> `text-[Npx]`, `text-[Nrem]`, `rounded-[Npx]`, `w-[…]`, `h-[…]`, `grid-cols-[…]` etc.
> Only match `#`, `rgb(`, `hsl(`, `oklch(` inside brackets.

---

## 6. Registry Governance

| Change | Requires |
|---|---|
| Adding an L1 token | Update this file + light + dark spec + contrast script |
| Adding an L2 token | Justify why L1 cannot express it |
| Adding a status string | Add to `StatusPalette::MAP` only — never to a component |
| Adding a chart series | Must pass the luminance-separation check |
| Any new hex literal | Rejected by CR-2 unless a brand mark in an allowlist |

**Allowlist for literal colors:** Google OAuth (`#4285F4`, `#EA4335`, `#FBBC05`, `#34A853`),
favicon/manifest colors, e-mail templates (out of scope).
