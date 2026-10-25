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
- Each component may ship curated docs examples in
  `resources/components/{name}/examples/*.blade.php`, declared in the
  `examples` array of `{name}.json` (`title`, `description`, `file`, optional
  `height`). Examples use the preview namespace and must pass the style
  conventions test. They are **not** published to host apps.
- `{name}.json` also declares `slots` and optional `studio` hints
  (`defaults`, `container` with a `{{component}}` placeholder, `height`,
  `card`) that drive the Component Studio playground at `/components/{name}`.

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

---

# Migrating a host app's markup to these components (integration foot-guns)

Hard-won notes from converting a real Laravel + Livewire + Alpine app's
hand-rolled markup (`<button class="btn …">`, `x-show` modals, etc.) to these
components. The recurring theme: **a `<x-component>` tag is parsed by Blade's
component compiler, so it follows different rules than a native HTML element.**
Markup that was valid on a `<button>`/`<div>` can break once it's a `<x-button>`.

## A. Alpine `:`-bindings break on a component → escape with `::` (or `x-bind:`)

- **Symptom:** `Undefined constant "…"` (e.g. `theme`), or a PHP error evaluating
  what looks like JavaScript.
- **Cause:** On a **native** element `:foo="bar"` is left untouched for Alpine.
  On a **component** `:foo="bar"` is Blade's *PHP* bind — Blade evaluates `bar`
  as PHP. So an Alpine expression like `:aria-label="$store.theme.isDark ? 'a' : 'b'"`
  makes Blade read `$store.theme.isDark` as PHP (`. theme .` → undefined constant).
- **Fix:** escape it so Blade emits a literal attribute for Alpine:
  `::aria-label="$store.theme.isDark ? 'a' : 'b'"` (or `x-bind:aria-label="…"`).
- **Note:** `@click`, `x-on:*`, `wire:*` are NOT affected (Blade only special-cases
  the single `:` prefix). Genuine PHP `:` binds (`:href="route('x')"`,
  `:variant="$popular ? 'primary' : 'secondary'"`) are correct and should stay.

## B. No Blade directives or `{{ }}`-with-quotes inside a component tag → use bound `:` expressions

- **Symptom:** `ParseError: syntax error, unexpected token …` in the compiled view,
  or `ComponentTagCompiler::compileOpeningTags(): Argument #1 must be string, null given`.
- **Cause:** Directives like `@disabled(...)` / `@class([...])` inject `<?php … ?>`
  into the middle of the component's attribute-array construction, and `{{ }}`
  echoes containing quotes/ternaries generate invalid PHP for the attribute string.
- **Fix:** express dynamic attributes as bound PHP:
  - `@disabled($isCurrent)` → `:disabled="$isCurrent"`
  - `class="mt-6 w-full {{ $isCurrent ? '!opacity-60' : '' }}"` →
    `:class="'mt-6 w-full'.($isCurrent ? ' !opacity-60' : '')"`
- **Note:** a *simple* echo with no quotes is fine —
  `wire:click="choose({{ $plan->id }})"` works.

## C. Smart/curly quotes as attribute delimiters are fatal

- **Symptom:** `compileOpeningTags(): … null given` (the tag-matching regex returns null).
- **Cause:** an editor or AI agent wrote `variant=”ghost”` (U+201C/U+201D) instead of
  `variant="ghost"`. Blade's component-tag regex can't parse curly delimiters.
- **Fix:** straight ASCII `"` for all delimiters. Curly quotes are only OK as
  *literal display text inside* a value (e.g. `wire:confirm="Delete “{{ $title }}”?"`).
- **Always grep after a bulk/agent conversion:**
  `grep -rnP '[\x{201c}\x{201d}\x{2018}\x{2019}]' resources/views`

## D. Drive overlays from Livewire state, not the component's trigger

For a Livewire-controlled modal/drawer, bind the open state instead of using the
built-in trigger: read the property via `$attributes->whereStartsWith('wire:model')->first()`,
**strip it** (`whereDoesntStartWith('wire:model')`) before spreading attributes onto
the teleported panel, init Alpine from `$wire.entangle('<prop>')`, and suppress the
trigger when controlled. Write the `x-data` open expression with `{!! … !!}` — `{{ }}`
HTML-encodes the quotes in `$wire.entangle('prop')` and breaks Alpine.

## E. Form labels & field metrics won't match by default

`<x-input label="…">` renders the label via `<x-label>` (`text-sm`, `text-foreground`).
If the host app's form labels differ (size/color), either pass bare `<x-input>` and
keep the app's own `<label>`, or restyle `<x-label>` globally. Also: `<x-textarea>`
defaults to `min-h-20` — override with `min-h-0` to match a compact field.

## F. Escaping asymmetry to watch

`card` escapes its polymorphic href (`e($href)`); `button`'s `type="a"` does **not**
escape `href`. Safe for `route()` values, but escape it before exposing the button
to user-controlled URLs. Sanitize any value interpolated into an inline `style`
(e.g. `progress`'s `color` → `var(--dot-{color})`) with an allowlist or
`preg_replace('/[^a-z0-9-]/', '', …)`.

## G. Verification — build & unit tests do NOT catch view render errors

A malformed component tag only fails **at render**. After any migration:

- Run a **full-page smoke sweep** — HTTP GET every affected route and assert 2xx.
  This is the only thing that reliably catches A–C above.
- `npm run build` validates Tailwind classes, not Blade. `php artisan view:cache`
  can catch compile errors but aborts on the first bad/dynamic component, so it's
  unreliable as a gate.
- Keep helper calls (`config()`, `route()`) **out of test file scope** — they run
  during Pest collection before the app boots and abort the whole suite. Put them
  inside `it()`/`beforeEach()` closures.
