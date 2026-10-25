# DevDojo Components — Authoring Rules

Rules for building and editing components in this package. **Follow them exactly**
so every component stays fully themeable and visually consistent. A Pest test
(`tests/Feature/StyleConventionsTest.php`) enforces the color and radius rules —
run `composer test` before finishing.

The theme tokens live in `resources/css/components.css` (`:root` + `.dark` +
the `@theme inline` map). Changing a token must re-skin every component — that
only works if components reference tokens instead of hard-coded values.

---

## 0. Match KatanaUI (design source of truth)

These components are ported from **KatanaUI**. Before creating or editing a
component, open the matching Katana source and mirror it:

```
packages/katanaui/katana/resources/views/components/katana/{name}.blade.php
packages/katanaui/katana/resources/views/components/katana/{name}.yml
```

Match Katana's **markup structure, prop names + defaults, slot names, variant
list, sizes, icons, and the intent of each class** so the two libraries stay
visually and behaviorally consistent. Don't redesign a component or invent a
different API — port it faithfully, then apply **only** these adaptations:

- Swap Katana's hard-coded colors for our theme tokens (rules §1) — e.g.
  Katana's `bg-white`/`text-stone-900` → `bg-card`/`text-card-foreground`,
  `bg-stone-100` → `bg-secondary`. Keep semantic status palettes (rose/blue/
  green/amber) as-is.
- Swap radius values for our radius tokens (rules §2) — e.g.
  `rounded-[var(--radius-medium)]` → `rounded-medium`.

When a component visibly differs from Katana (missing icon, different spacing,
different variant), treat it as a bug and align it to Katana. If you add a
feature Katana doesn't have, keep it additive and off by default.

## 1. Colors — always use the theme tokens

Use the semantic color tokens below; **never** hard-code a Tailwind color family
(`gray-*`, `neutral-*`, `stone-*`, `zinc-*`, `slate-*`) or a hex value for
structural UI.

Available tokens (use as `bg-*`, `text-*`, `border-*`, `ring-*`, `fill-*`, …):

| Token | Token |
|-------|-------|
| `background` / `foreground` | `card` / `card-foreground` |
| `popover` / `popover-foreground` | `primary` / `primary-foreground` |
| `secondary` / `secondary-foreground` | `muted` / `muted-foreground` |
| `accent` / `accent-foreground` | `destructive` / `destructive-foreground` |
| `border` · `input` · `ring` | |

- Use opacity modifiers for subtle shades: `border-foreground/10`,
  `text-foreground/50`, `bg-primary/90`. Don't introduce a new gray for this.
- **Dark mode is automatic** — tokens swap via the `.dark` class. Do **not** add
  a `dark:` override when a token already adapts. Only reach for `dark:` for
  things genuinely outside the token system.
- Need a new structural color? Add a token to `resources/css/components.css`
  (`:root`, `.dark`, and the `@theme inline` map) — never hard-code it.

### Sanctioned color exceptions

These are the **only** places non-token colors are allowed:

1. **Semantic status palettes** — `alert` and `toast` may use `red`/`rose`/
   `green`/`blue`/`amber`/`yellow` for their `success`/`warning`/`info`/
   `destructive` variants.
2. **Theme-independent surfaces** — fixed `black`/`white` for the toast's glass
   background, the modal scrim (`bg-black/60`), control thumbs (toggle
   `bg-white`), and `text-white` sitting on a colored fill.

## 2. Radius — always use the radius tokens

Use **only**: `rounded-small`, `rounded-medium`, `rounded-large`, or
`rounded-full` (circles/pills only).

**Never** use bare `rounded` or Tailwind's built-in scale (`rounded-sm`,
`rounded-md`, `rounded-lg`, `rounded-xl`, `rounded-2xl`, …). Those are fixed or
map to Tailwind's own `--radius-*` and will **not** respond to this package's
tokens.

Conventions:

| Use | Token |
|-----|-------|
| Buttons, inputs, cards (md), alerts, modal/dropdown/popover panels, toast | `rounded-medium` |
| Small chrome: checkbox box, tooltip, close buttons, menu items | `rounded-small` |
| Large surfaces: large card, toast on desktop | `rounded-large` |
| True circles & pills: radio dot, toggle, avatars | `rounded-full` |

## 3. Class merging

So consumers can override styles:

- Root element: `{{ $attributes->twMerge('…') }}`
- Targetable inner elements: `{{ $attributes->twMergeFor('slotKey', '…') }}`
  (consumer passes `slotKey:class="…"`)
- Forwarding the remaining bag: `{{ $attributes->withoutTwMergeClasses()->except(['class']) }}`

## 4. Component structure

- Each component is an **anonymous index component**:
  `resources/components/{name}/index.blade.php` plus a `{name}.json` metadata
  file (description, category, `props`, `dependencies`).
- Reference sibling components with the preview namespace —
  `<x-components.button />`. `php artisan components:add` rewrites these to
  `<x-button />` on publish, so never hard-code `<x-button>` inside a source
  component.
- Declare every prop with `@props([...])` and keep `{name}.json` in sync.

## 5. Spacing & interactivity

- Prefer `gap-*` over margins between siblings (Tailwind v4).
- Interactivity is Alpine.js. For teleported components (`<template x-teleport>`),
  put setup in `x-init` (the `init()` x-data method does **not** run inside a
  teleport), and never let an `x-init` expression evaluate to a function — Alpine
  auto-invokes it. Wrap such setup in an IIFE.

## Pre-finish checklist

- [ ] No `gray/neutral/stone/zinc/slate` or hex colors — tokens only
- [ ] No bare `rounded` or `rounded-(sm|md|lg|xl|2xl|3xl)` — only `small/medium/large/full`
- [ ] Root element uses `$attributes->twMerge(...)`
- [ ] Looks correct in light **and** dark (via tokens, not `dark:` patches)
- [ ] `{name}.json` updated
- [ ] `composer test` passes (style conventions test included)
