# 07 — Tailwind Integration Plan

> **Stack verified:** Tailwind **v4.3.3** via `@tailwindcss/vite`, Livewire 4.4, Alpine 3.4,
> Laravel 13.17. **There is no `tailwind.config.js`** — v4 is CSS-first. Do not create one.

---

## 1. Current Wiring (Verified)

`vite.config.js`

```js
import tailwindcss from '@tailwindcss/vite';
export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.js'], refresh: true }),
        tailwindcss(),
    ],
});
```

`resources/css/app.css` (order matters)

```css
@import './fonts.css';
@import 'tailwindcss';
@import './tokens.css';
@custom-variant dark (&:where(.dark, .dark *));
@source '../views';
@source '../**/*.blade.php';
@source '../../storage/framework/views';
@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
```

`resources/css/tokens.css` ends with a single `@theme inline { … }` block mapping
`--color-X: var(--X)` for every semantic token.

**This is correct and requires no structural change.** The plan only adds tokens.

---

## 2. Target File Responsibilities

| File | Owns | Must NOT contain |
|---|---|---|
| `fonts.css` | `@font-face` | colors |
| `app.css` | imports, `@custom-variant`, `@layer base`, **behavioral** utilities (POS layout, type scale, animation) | hardcoded colors |
| `tokens.css` | `:root`, `.dark`, `[data-theme="kds-dark"]`, `@theme inline` | selectors for concrete components |
| `components.css` | **deleted** (empty, only 12 lines; superseded by `tokens.css`) | — |

**Rule:** `app.css` may reference `var(--token)` but never a literal color. Currently it violates
this in ~15 places (`.chip.ok/wt/danger/stable`, `.field-input:focus`, `.spinner`, `option`,
`.table-scroll-shadow`, `.sheet-overlay`, `.editorial-surface`, `.restock-bar i`, `.dot-warning`,
`.dot-success`, `.delta-badge`).

---

## 3. `@theme inline` — Complete Mapping

Extend the existing block. `@theme inline` is essential here: it makes the utility reference
the variable **at use site** (`background-color: var(--background)`), so `.dark` remapping works
without rebuilding. Using `@theme` (non-inline) would bake values at build time and break theming.

```css
@theme inline {
    /* Fonts */
    --font-sans: var(--font-sans);
    --font-brand: var(--font-brand);
    --font-mono: var(--font-mono);

    /* Surfaces */
    --color-background: var(--background);
    --color-foreground: var(--foreground);
    --color-card: var(--card);
    --color-card-foreground: var(--card-foreground);
    --color-popover: var(--popover);
    --color-popover-foreground: var(--popover-foreground);
    --color-surface-inset: var(--surface-inset);
    --color-surface-table-header: var(--surface-table-header);

    /* Primary / Secondary / Muted / Accent */
    --color-primary: var(--primary);
    --color-primary-foreground: var(--primary-foreground);
    --color-secondary: var(--secondary);
    --color-secondary-foreground: var(--secondary-foreground);
    --color-muted: var(--muted);
    --color-muted-foreground: var(--muted-foreground);
    --color-accent: var(--accent);
    --color-accent-foreground: var(--accent-foreground);

    /* Form & interaction */
    --color-border: var(--border);
    --color-border-strong: var(--border-strong);
    --color-input: var(--input);
    --color-ring: var(--ring);
    --color-focus: var(--focus);
    --color-disabled: var(--disabled);
    --color-disabled-bg: var(--disabled-bg);
    --color-selection: var(--selection);
    --color-selection-foreground: var(--selection-foreground);

    /* Status */
    --color-success: var(--success);
    --color-success-foreground: var(--success-foreground);
    --color-success-muted: var(--success-muted);
    --color-success-border: var(--success-border);
    --color-warning: var(--warning);
    --color-warning-foreground: var(--warning-foreground);
    --color-warning-muted: var(--warning-muted);
    --color-warning-border: var(--warning-border);
    --color-destructive: var(--destructive);
    --color-destructive-foreground: var(--destructive-foreground);
    --color-destructive-muted: var(--destructive-muted);
    --color-destructive-border: var(--destructive-border);
    --color-info: var(--info);
    --color-info-foreground: var(--info-foreground);
    --color-info-muted: var(--info-muted);
    --color-info-border: var(--info-border);
    --color-neutral: var(--neutral);
    --color-neutral-muted: var(--neutral-muted);
    --color-neutral-border: var(--neutral-border);

    /* Charts (variables only; not intended as utilities) */
    --color-chart-1: var(--chart-1);
    --color-chart-2: var(--chart-2);
    --color-chart-3: var(--chart-3);
    --color-chart-4: var(--chart-4);
    --color-chart-5: var(--chart-5);
    --color-chart-6: var(--chart-6);
    --color-chart-7: var(--chart-7);
    --color-chart-8: var(--chart-8);
    --color-chart-grid: var(--chart-grid);
    --color-chart-axis-label: var(--chart-axis-label);

    /* Shell */
    --color-shell: var(--shell);
    --color-shell-foreground: var(--shell-foreground);
    --color-shell-foreground-active: var(--shell-foreground-active);
    --color-shell-accent: var(--shell-accent);
    --color-shell-border: var(--shell-border);
    --color-shell-ring: var(--shell-ring);

    /* Shape (unchanged) */
    --radius-control: var(--radius-control);
    --radius-card: var(--radius-card);
    --radius-dialog: var(--radius-dialog);
    --radius-sheet: var(--radius-sheet);
    --radius-pill: var(--radius-pill);

    /* Breakpoints (unchanged) */
    --breakpoint-3xl: 1920px;
    --breakpoint-4xl: 2560px;
}
```

### 3.1 Why `*%-border` and not `*%-200`

`border-success-border` reads as "the success border token". A `border-success-200` alias would
reintroduce a Tailwind-scale mental model onto a semantic token and invite `border-success-300`
by analogy — which does not exist and would silently render nothing (exactly the D-1 bug).

---

## 4. Dark Variant Registration

Keep as-is:

```css
@custom-variant dark (&:where(.dark, .dark *));
```

Notes:

- In Tailwind v4 the `dark:` variant must be registered with `@custom-variant`; there is no
  `darkMode` config key. Current code is correct.
- The selector `&:where(.dark, .dark *)` gives **zero specificity**, so utilities win over base
  styles predictably. Do not change it to `&:is(.dark *)` (specificity shifts).
- After migration, **no `dark:` utility should remain**. The variable handles it.
  The `@custom-variant` registration is kept because KDS/third-party edge cases may still need it.

---

## 5. Source Detection

Current `@source` directives cover Blade views, compiled views, and vendor pagination.
**An additional source is required** for PHP status classes:

```css
@source '../../app/Support/Color';
@source '../../app/Enums';
```

Reason: `StatusPalette::badgeClasses()` returns class strings as PHP literals. Tailwind's scanner
reads raw files; if it never sees `bg-success-muted`, that utility is never generated and badges
render unstyled — **the same failure mode as D-1**. Adding `app/Enums` also covers the three
enums until `badgeClass()` is removed.

> **Verification step after adding:** build, then grep the emitted CSS for
> `.bg-success-muted`, `.text-warning`, `.border-info-border`. All three must exist.

---

## 6. CSS Variable Definition Order

`tokens.css` must define `:root` variables **before** the `@theme inline` block. Within
`tokens.css`, `.dark` comes after `:root` so the cascade is deterministic regardless of
source order across files.

```
tokens.css
 1. :root            (light values)
 2. .dark            (dark overrides)
 3. [data-theme=kds-dark]
 4. @media prefers-reduced-motion
 5. @theme inline    ← always last, reads only
```

---

## 7. Handling `@apply`

`@apply` with token utilities works in v4 (`@apply bg-card border-border`). But:

| Location | Current | Action |
|---|---|---|
| `pos-layout-locked` | `@apply … bg-white` | → `@apply … bg-background` |
| Any future | `@apply bg-zinc-100` | forbidden |

**Preference:** write `background-color: var(--background)` directly in CSS rather than
`@apply bg-background`. Fewer layers, identical output, and no risk of a utility being purged
before `@apply` resolves it.

---

## 8. `twMerge` Compatibility

`gehrisandro/tailwind-merge-php` is installed and every `ui/*` component merges via
`$attributes->twMerge(...)`. Two implications:

1. **Token utilities merge correctly** (`bg-card` vs `bg-muted` are the same group).
2. **Unknown utilities are preserved verbatim** — which is exactly why `bg-success-50`
   silently survived: `twMerge` does not validate that a class exists in Tailwind.
   Adding the `-muted/-foreground/-border` scale gives `twMerge` real classes to reason about.

No changes to `twMerge` configuration are required.

---

## 9. Build & Verification Commands

```bash
# 1. Build
npm run build

# 2. Confirm every new utility was emitted
CSS=$(ls public/build/assets/app-*.css | head -1)
for c in bg-success-muted text-success border-success-border \
         bg-destructive-muted bg-warning-muted bg-info-muted \
         bg-neutral-muted border-border bg-surface-inset \
         bg-shell text-shell-foreground; do
  grep -q -- ".$c" "$CSS" && echo "OK   $c" || echo "MISS $c"
done

# 3. Confirm undefined families are gone
grep -c -- ".bg-success-50\|.bg-danger-50\|.bg-primary-100" "$CSS"   # expect 0

# 4. Confirm no theme-blind literals remain in generated CSS
grep -o -- "background-color:#fff" "$CSS" | wc -l                   # expect 0
```

> Step 2 is the single most important check in the entire migration. It catches the class of bug
> that D-1 represents — a class name that *looks* right, compiles to nothing, and fails silently.

---

## 10. Bundle Hygiene

| Action | Effect |
|---|---|
| Remove all 1,870 `dark:*` utilities | ~15–25 KB less CSS before gzip |
| Remove arbitrary hex utilities | ~8 KB less |
| Shared status tokens replace per-module color maps | no measurable size change |
| Adding ~45 tokens | ~2 KB |

Net effect: **the migration reduces CSS size.** No dependency is added, no build step changes.

---

## 11. What This Plan Explicitly Does Not Do

- Does **not** create `tailwind.config.js` (would conflict with v4 CSS-first).
- Does **not** add `@tailwindcss/forms` usage beyond its current registration.
- Does **not** introduce a CSS-in-JS, PostCSS plugin, design-token JSON pipeline, or Style
  Dictionary. The CSS custom properties *are* the token pipeline; a second representation
  would become a second source of truth.
- Does **not** convert `tokens.css` to oklch for the light theme. Hex is legible, stable across
  browsers, and testable by the contrast script. oklch remains in use only where it already
  exists (KDS).
