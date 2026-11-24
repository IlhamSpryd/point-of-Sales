# 10 — Color System Acceptance Criteria

> Every criterion is **measurable by a command or a counted observation**. No criterion says
> "looks good" — if it cannot be verified by a tool or a reviewer following a written procedure,
> it does not belong here.

Baseline (measured 2026-11-03, commit `a58f17a`) is stated with each metric so progress is visible.

---

## 1. Token Layer (AC-T)

| ID | Criterion | Command | Baseline | Target | Phase |
|---|---|---|---|---|---|
| **AC-T1** | Single `@theme` block exists | `rg -c '@theme' resources/css/*.css` | 1 | **1** | — (already met) |
| **AC-T2** | Every declared `--color-X` in `@theme` resolves to a defined variable | manual cross-check vs `:root` | partial | **100%** | P1 |
| **AC-T3** | Zero undefined semantic families | built CSS contains no class referencing an unpainted token | 163 usages broken | **0** | P0 |
| **AC-T4** | All status tokens defined in both `:root` and `.dark` | `rg -- '--success-muted\|--warning-muted\|--destructive-muted\|--info-muted' resources/css/tokens.css` | 0 | **8** (4 × 2) | P0/P1 |
| **AC-T5** | Alpha channels used only where specified | `rg 'rgb\(255 255 255 /' resources/css/tokens.css` | — | only `.dark` borders | P1 |
| **AC-T6** | `components.css` is empty or deleted | `wc -l resources/css/components.css` | 12 | **0** | P1 |

---

## 2. Contrast (AC-C)

Run `python3 scratch/contrast_check.py` → **exit code 0**. This is the authoritative gate.

| ID | Criterion | Minimum | Values |
|---|---|---|---|
| **AC-C1** | `foreground` on `background` | 4.5:1 | light 19.90 ✅ / dark 18.96 ✅ |
| **AC-C2** | `muted-foreground` on `background`, `card`, `muted` | 4.5:1 | light 5.28/5.28/4.80 ✅ · dark 7.72/7.18/6.42 ✅ |
| **AC-C3** | `primary-foreground` on `primary` | 4.5:1 | 16.97 both ✅ |
| **AC-C4** | Every status color on `background` **and** on its `-muted` | 4.5:1 | all ≥ 4.79 ✅ |
| **AC-C5** | Border visible against background | ≥ 1.2:1 | light 1.27 ✅ / dark 1.38 ✅ |
| **AC-C6** | Card distinguishable from background | > 1.0:1 (tone) | light 1.04 · dark 1.07 |
| **AC-C7** | Chart series legible on canvas | ≥ 4.5:1 | light min 5.18 ✅ / dark min 7.78 ✅ |
| **AC-C8** | Chart series distinguishable *from each other* | every pair either luminance Δ ≥ 1.3 or hue Δ ≥ 60° | see `02` §7 note |

> **AC-C8 is the weakest link.** Pairs `#1D4ED8`↔`#4C1D95` measure 1.63 and `#4C1D95`↔`#9D174D`
> 1.39 — acceptable, but a chart placing all three adjacent should also use marker shapes.
> This is why legend + marker rules are non-negotiable.

**Measured / sampled failures in the current UI (must be fixed):**

| Location | Current | Ratio | Fix |
|---|---|---|---|
| POS secondary text `text-gray-400` | 2.5:1 | ❌ | `text-muted-foreground` |
| Auth footer `text-zinc-400` | 2.8:1 | ❌ | `text-muted-foreground` |
| Table cells `text-gray-500` | 4.3:1 | ⚠️ | `text-muted-foreground` |
| `--muted-foreground` `#6B7280` on `#F3F4F6` | 4.4:1 | ❌ | `#6B6B74` |

---

## 3. Utility Discipline (AC-U)

All commands run from `point-of-Sales/` and are **scoped to `resources/` and `app/`** so
that generated build output (`public/build/assets/*.css`) is never counted.
**Each must return 0.**

```bash
rg -o -g '*.blade.php' \
  '\b(?:bg|text|border|ring|divide|fill|stroke|from|via|to|outline|shadow|decoration|placeholder|caret|accent)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-[0-9]{2,3}' \
  resources | wc -l
```

| ID | Criterion | Command | Baseline | Target |
|---|---|---|---|---|
| **AC-U1** | Zero palette utilities in views | above | **6,186** | **0** |
| **AC-U2** | Zero arbitrary color brackets | `rg -o -g '*.blade.php' '(?:bg\|text\|border\|ring\|fill\|stroke\|shadow\|from\|to\|via)-\[#\|rgb\|hsl\|oklch'` | ~600 | **0** |
| **AC-U3** | Zero `dark:` utilities | `rg -o -g '*.blade.php' 'dark:' resources \| wc -l` | **1,870** | **0** |
| **AC-U4** | Zero `bg-white` / `bg-black` / `text-white` / `text-black` | `rg -o -g '*.blade.php' 'bg-white\|bg-black\|text-white\|text-black'` | several | **0** |
| **AC-U5** | Zero gradients | `rg -o -g '*.blade.php' 'bg-gradient\|bg-linear\|bg-radial'` | 7 | **0** |
| **AC-U6** | Zero colored shadows | `rg 'shadow-(?:red\|green\|blue\|amber\|emerald\|rose\|zinc)-\|shadow-\[#'` | 0 | **0** (already met) |
| **AC-U7** | Zero inline color styles | `rg -o -g '*.blade.php' 'style="[^"]*(color\|background)'` | 13 | **0** |
| **AC-U8** | Literal hex limited to the allowlist | `rg -o -g '*.blade.php' '#[0-9a-fA-F]{3,8}'` → only Google OAuth 4 values | 1,303 | **≤ 4 distinct** |
| **AC-U9** | Type-scale brackets **not** damaged | `rg -o -g '*.blade.php' 'text-\[[0-9]+px\]' \| wc -l` | ~900 | **unchanged or greater** |

> **AC-U9 exists to catch a specific automation failure.** A codemod that treats every
> `text-[…]` as a color will strip the type scale. This counter must not drop.

---

## 4. Component Integrity (AC-K)

| ID | Criterion | Measure | Baseline | Target |
|---|---|---|---|---|
| **AC-K1** | Exactly one badge implementation | count of files matching `badge` in `components/` that emit color classes | 4 | **1** |
| **AC-K2** | Exactly one button implementation | count of button components emitting color classes | 4 | **1** |
| **AC-K3** | No component contains a status `match()` | `rg "match.*'(success|warning|danger|info)'" resources/views/components` | 4 files | **0** |
| **AC-K4** | No enum contains color classes | `rg 'function badgeClass' app/Enums` | 3 | **0** |
| **AC-K5** | Every interactive component defines 6 states | manual checklist per `05` §17 | partial | **100% of L1** |
| **AC-K6** | Focus ring present on every interactive element | `rg -L 'focus-visible' resources/views/components/ui/**` | — | **0 files** |
| **AC-K7** | No decorative color on icons | manual review; zero `text-{semantic}` on nav/menu icons | several | **0** |

---

## 5. Status Consistency (AC-S)

| ID | Criterion | Measure | Target |
|---|---|---|---|
| **AC-S1** | `StatusPalette::variant()` is the only resolver | reflective test over all statuses | **pass** |
| **AC-S2** | Same status = same variant in every module | unit test `('paid','success')`, `('approved','success')`, `('draft','neutral')`, `('released','info')` | **pass** |
| **AC-S3** | `approved` resolves to `success`, not `info` | explicit test | **pass** |
| **AC-S4** | Every rendered status has a text label | manual: no dot-only statuses in tables | **0 violations** |
| **AC-S5** | No tinted table rows used to express status | manual review of purchasing/HR/inventory | **0** |
| **AC-S6** | Debit/Credit are never color-coded | manual review of finance tables | **0** |

---

## 6. Module Coverage (AC-M)

Each module is "done" when all three hold.

| ID | Criterion |
|---|---|
| **AC-M1** | Module has 0 palette utilities and 0 arbitrary color brackets |
| **AC-M2** | Module's feature test suite is green |
| **AC-M3** | Every screen in the module was visually reviewed in **light mode** (and dark, after P5) — screenshot attached to the PR |

| Module | Baseline hits | Status |
|---|---|---|
| Auth | 268 | ☐ |
| POS / Kasir | 267 | ☐ |
| Inventory | 147 | ☐ |
| Finance | 799 | ☐ |
| Purchasing | 511 | ☐ |
| Sales | 446 | ☐ |
| HR | 366 | ☐ |
| Customers / Pricing | 679 | ☐ |
| Users / Roles / Devices / Stores | 639 | ☐ |
| Reports / Dashboard | 561 | ☐ |
| Remainder | ~1,251 | ☐ |

---

## 7. Theme Switching (AC-DK)

| ID | Criterion | Method | Target |
|---|---|---|---|
| **AC-DK1** | No flash of incorrect theme on hard reload | 120 fps screen recording of first 300 ms | **0 light frames in dark mode** |
| **AC-DK2** | Preference persists across hard reload | manual | pass |
| **AC-DK3** | Preference persists across `wire:navigate` | navigate 5 routes | class retained |
| **AC-DK4** | `system` mode follows OS change live | toggle OS appearance | updates ≤ 100 ms |
| **AC-DK5** | Storage-blocked (private mode) does not throw | Safari private | no console error |
| **AC-DK6** | Native form controls follow theme | open a `<select>` in dark | panel is dark |
| **AC-DK7** | Scrollbars follow theme | dark page, scroll | dark scrollbar |
| **AC-DK8** | KDS unaffected by user preference | set dark, open KDS | KDS unchanged |
| **AC-DK9** | No white surfaces in dark mode | `rg 'bg-white\|#fff'` on rendered dark pages | 0 |

---

## 8. Visual Quality Review (AC-V)

Five reviewable statements, judged from screenshots of the dashboard, one table page, the POS,
and the auth screen, in both themes.

| ID | Statement | Pass condition |
|---|---|---|
| **AC-V1** | White/near-white dominates light mode | ≥ 85% of the pixel area is ≥ 90% luminance |
| **AC-V2** | Cards read as grouped surfaces, not floating boxes | no more than 2 elevation treatments visible per screen |
| **AC-V3** | Colour appears only for state | ≤ 5 distinct hues per screen, excluding the logo |
| **AC-V4** | Hierarchy is carried by type, not hue | headings and body differ by weight/size, and their colors are `foreground` / `muted-foreground` only |
| **AC-V5** | Nobody needs colour to read the screen | full screen understood in greyscale |

**AC-V5 procedure:** desaturate the screenshot to greyscale; all information must remain
distinguishable. This single test catches nearly every violation in `05` and `06`.

---

## 9. Performance (AC-P)

| ID | Criterion | Baseline | Target |
|---|---|---|---|
| **AC-P1** | CSS bundle ≤ baseline | current `app-*.css` | **≤ current, ideally −10%** |
| **AC-P2** | No new NPM dependency | `package.json` diff | **0 packages** |
| **AC-P3** | No new Composer dependency | `composer.json` diff | **0 packages** |
| **AC-P4** | Tailwind build time not materially worse | `npm run build` | ≤ +10% |

---

## 10. Process (AC-X)

| ID | Criterion |
|---|---|
| **AC-X1** | Each phase lands as its own commit(s); no commit spans two phases |
| **AC-X2** | PHPStan 0 errors on touched PHP |
| **AC-X3** | `vendor/bin/pint --test` clean |
| **AC-X4** | All existing tests still pass after each phase |
| **AC-X5** | Every new token documented in `04` before it is used in a view |
| **AC-X6** | No Blade markup restructuring — color-only diffs. Structural changes are permitted only where a component is being unified (P2) and must be called out in the PR |

> **AC-X6 matters for review quality:** a diff that changes color classes and also reflows layout
> cannot be reviewed for color regressions.

---

## 11. Definition of Done

The color system is complete when:

1. AC-C (contrast) passes in both themes.
2. AC-U1 … AC-U9 all report their target values.
3. AC-K1 … AC-K7 pass — one badge, one button, one status authority.
4. Every module in AC-M is checked.
5. Dark mode passes AC-DK.
6. AC-V5 (greyscale legibility) passes on all reviewed screens.
7. AC-P confirms no size or dependency regression.

At that point the interface is **monochrome by construction**: there is no remaining path by
which a developer can introduce a color that the design system has not sanctioned.
