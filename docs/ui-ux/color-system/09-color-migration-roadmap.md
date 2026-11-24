# 09 — Color Migration Roadmap

> Sequenced by **dependency**, not by module importance. A module cannot be migrated before the
> primitives it uses are correct, or the work is repeated.

---

## 0. Dependency Graph

```
P0  Fix broken tokens (D-1)            ← blocks everything; 1 file
 │
P1  Token completion + canvas/borders   ← blocks all visual work
 │
P2  Primitive components + StatusPalette ← blocks all module work
 │
P3  Structural surfaces (table, nav, sidebar, dashboard)
 │
P4  Module sweeps (finance, purchasing, sales, HR, POS, …)
 │
P5  Dark mode activation + validation
```

**Anti-pattern to avoid:** migrating a module page in P1 "because it's small". A page built from
broken primitives will be migrated again once the primitives change.

---

## P0 — Unblock (0.5 day)

**Goal:** stop the active bug. Nothing else.

| # | Task | Files | Risk |
|---|---|---|---|
| 0.1 | Add the status scale tokens (`success-muted/-foreground/-border`, three siblings each) to `:root` | `resources/css/tokens.css` | Low — additive |
| 0.2 | Add `@source '../../app/Enums'` so enum-returned classes are scanned | `resources/css/app.css` | Low |
| 0.3 | Delete `badgeClass()` from 3 enums; replace call sites with `StatusPalette` | `app/Enums/*`, callers | Medium — behaviour-visible |

**Why first:** every badge in the application is currently mis-rendered (`01` §4). The fix is
one CSS block and touches no layout.

**Exit gate:** `grep '.bg-success-muted' $CSS` succeeds; a "Paid" badge shows a green pill.

**Rollback:** revert the CSS block — no functional coupling.

---

## P1 — Foundation (2–3 days)

**Goal:** the token layer is complete, correct, and verified. No module work yet.

| # | Task | Detail |
|---|---|---|
| 1.1 | Adopt light palette values | `02` §11 — canvas `#FAFAFA`, `muted-foreground` `#6B6B74` |
| 1.2 | Complete `@theme inline` | `07` §3 |
| 1.3 | Add shell tokens | `shell`, `shell-*`, alias existing `--sidebar-*` |
| 1.4 | Add chart tokens | `chart-1..8`, `grid`, `axis` |
| 1.5 | Add overlay/shadow/selection tokens | `02` §8–9 |
| 1.6 | Remove hex fallbacks in `app.css` | `.data-table thead th`, `tbody tr:hover` (`--color-surface-secondary` **does not exist**) |
| 1.7 | Move `app.css` CSS-level colors to tokens | `.chip*`, `.delta-badge`, `.restock-bar i`, `.dot-warning`, `.dot-success`, `.field-input*`, `.input-error`, `.label-error`, `.editorial-surface`, `option`, `.sheet-overlay`, `.spinner` |
| 1.8 | `pos-layout-locked`: `bg-white` → `bg-background` | same file |
| 1.9 | Run contrast script | must exit 0 |
| 1.10 | Migrate auth pages (9 files, 268 hits) | first real consumer; smallest surface |

**Dependencies:** P0 complete.
**Exit gate:** all of `10` AC-T1 … AC-T6 pass for auth pages and the token layer.
**Rollback:** token file revert; auth pages committed separately.

> **Why auth here:** it is a closed 9-file surface with no Livewire state, no data tables, and
> no Alpine complexity. It is the ideal canary — if the palette looks wrong, it is wrong
> everywhere and you find out cheaply.

---

## P2 — Primitives & Status (3–4 days)

**Goal:** every reusable component is token-pure and there is exactly one status authority.

| # | Task | Files |
|---|---|---|
| 2.1 | Create `App\Support\Color\StatusPalette` + `StatusVariant` | new |
| 2.2 | Unify badges: `x-ui.badge` is canonical; `x-badge` + `x-erp.status-badge` + `x-status-text` forward to it | `components/ui/badge`, `components/badge`, `components/erp/status-badge`, `components/status-text` |
| 2.3 | Unify buttons: `x-ui.button` canonical; delete `x-button`, `x-primary-button`, `x-secondary-button`, `x-danger-button` (forward temporarily) | 5 files |
| 2.4 | `x-ui.button` primary: remove `bg-linear-to-b` | 1 line |
| 2.5 | `x-ui.input` / `x-form-input`: remove `dark:` classes, use `border-input` / `focus` | 2 files |
| 2.6 | `x-ui.card`, `x-ui.alert`, `x-ui.modal`, `x-ui.drawer`, `x-ui.dropdown`, `x-ui.popover`, `x-ui.tooltip`, `x-ui.tabs`, `x-ui.switch`, `x-ui.checkbox`, `x-ui.radio`, `x-ui.skeleton`, `x-ui.toast`, `x-ui.empty-state`, `x-ui.pagination`, `x-ui.separator`, `x-ui.kbd`, `x-ui.avatar` — token sweep, delete `dark:` | ~18 files |
| 2.7 | `components/erp/*`: `metric-card`, `stat-card`, `page-header`, `filter-bar`, `form-field`, `money-input` | ~6 files |
| 2.8 | Add render tests | existing `DevDojoComponentsIntegrationTest` pattern |

**Dependencies:** P1.
**Exit gate:** `rg 'dark:' resources/views/components` → 0;
`rg -- '(bg|text|border)-(slate|gray|zinc|red|rose|emerald|amber|blue)-' resources/views/components` → 0.
**Rollback:** components are individually revertable; `StatusPalette` is additive.

---

## P3 — Structural Surfaces (2–3 days)

**Goal:** the frame of the application is monochrome.

| # | Task | Files |
|---|---|---|
| 3.1 | Sidebar / topbar → `shell-*` tokens; remove `#171717`, `#202020`, `#2E2E2E`, `#D4D4D4`, `#A3A3A3`, `#737373` | `layouts/app.blade.php` (~78 hits) |
| 3.2 | Remove decorative nav icon tints (`text-amber-400`, `text-purple-400`, `text-emerald-400`) | same |
| 3.3 | `x-erp.data-table` + `ui/table/*` + `app.css` `.data-table` → tokens | 3 locations |
| 3.4 | Breadcrumbs, pagination, command palette, notification dropdown, user dropdown | ~6 files |
| 3.5 | Dashboard: `livewire/dashboard`, `reports/*` KPI cards, `advanced-analytics-dashboard` (162 hits) | ~4 files |
| 3.6 | Merge radius violations (`rounded-3xl` on cards → `rounded-xl`) while touching cards | opportunistic |

**Dependencies:** P2 (cards, badges, tables must be correct first).
**Exit gate:** dashboard + one full CRUD module screenshot-pass the visual review in `10`.
**Rollback:** per-file.

---

## P4 — Module Sweeps (8–12 days)

Ordered by density × operational risk. **Each module is a separate commit** so any regression
can be reverted independently.

| Wave | Module | Hits | Notes |
|---|---|---|---|
| 4.1 | **POS / Kasir** | **267** | Highest operational risk. Migrate `pos/partials/*` first (cart, summary, discount modal), then `kasir/create-order` |
| 4.2 | **Inventory** | **147** | Stock badges → `StatusPalette`; restock bar tokens |
| 4.3 | **Finance** | **799** | Journal, COA, reconciliation workbench, accounting periods |
| 4.4 | **Purchasing** | **511** | PO, GRN, supplier, returns, invoices |
| 4.5 | **Sales** | **446** | Orders, quotations, AR manager |
| 4.6 | **HR** | **366** | Payroll, attendance, leave, scheduler |
| 4.7 | **Customers / Pricing** | **679** | Customer manager, loyalty, vouchers, promotions |
| 4.8 | **Users / Roles / Devices / Stores** | **639** | Admin surfaces |
| 4.9 | **Everything else** | ~1,251 | onboarding, orders, tables, brands, categories, units, tax classes, price lists, KDS, errors, profile, settings, activity logs, assortments, components leftovers |

**Dependencies:** P3 complete for `data-table`, `page-header`, `shell`, and badge primitives.
**Per-module gate:** the module's existing feature tests stay green; the module has 0 palette
utilities; a screenshot of every screen in the module was reviewed in light mode.

**Rollback:** per-commit, per-module.

> **POS must be manually exercised.** `01` §6.6 notes `pos-layout-locked` uses `@apply bg-white`
> and the POS grids use `gray` while the rest uses `zinc`. A purely mechanical sweep here can
> easily change the perceived contrast of the product grid for a cashier who has memorized the
> layout. Budget a manual tablet pass.

---

## P5 — Dark Mode & Validation (4–6 days)

**Goal:** activate the second theme and prove nothing regressed.

| # | Task | Detail |
|---|---|---|
| 5.1 | Verify the P0–P4 gates | CR-1 … CR-5 all zero |
| 5.2 | Add the no-flash inline script | `08` §3 — 3 layouts |
| 5.3 | Add `theme-controller.js` + registration | `08` §4, §7 |
| 5.4 | Add the toggle UI | sidebar footer + user dropdown |
| 5.5 | Dark contrast audit | `scratch/contrast_check.py` exit 0 |
| 5.6 | Remove all remaining `dark:` utilities | should already be 0 |
| 5.7 | Visual regression, light + dark | see `10` §8 |
| 5.8 | Cross-module consistency review | one screen per module, side by side |
| 5.9 | KDS regression | confirm `[data-theme="kds-dark"]` untouched |

**Dependencies:** all prior phases.
**Exit gate:** `10` complete.
**Rollback:** the toggle is a single commit; ship dark behind a setting if needed.

---

## Cross-Phase Concerns

### Testing
Every phase must keep the PHPUnit suite green before moving on. Existing suites that matter:
`DevDojoComponentsIntegrationTest`, `DashboardV2RedesignTest`,
`DashboardMetricsAndButtonsTest`, `SidebarIntegrityTest`, `CustomErrorPagesTest`,
`OrderLifecycleTransitionTest`, `LivewireTenancyIsolationTest`, plus the 10 operational-screen
render tests added in `a58f17a`.

### Static analysis
`./vendor/bin/phpstan analyse` must report 0 errors for `app/Support/Color` and every touched enum.

### Commit hygiene
One phase → one or more commits, never one commit spanning phases. Colour work is easy to spot
in review when it is isolated.

### Automation hazard (repeated for emphasis)
`text-[12px]` and `text-[#111827]` share a bracket syntax. Any codemod must match
`#[0-9a-fA-F]{3,8}` / `rgb(` / `hsl(` / `oklch(` **inside** the bracket, never merely the
presence of a bracket. A naive regex will delete the entire type scale (`01` §3.2).

### Suggested sequencing if capacity is limited
If only 3 phases are affordable: **P0 → P1 → P2**. That fixes the active bug, gives a coherent
token system, and makes every primitive correct. Modules would still look mixed, but no screen
would be *broken*.

---

## Effort Summary

| Phase | Duration | Cumulative |
|---|---|---|
| P0 | 0.5 d | 0.5 d |
| P1 | 2–3 d | 3.5 d |
| P2 | 3–4 d | 7.5 d |
| P3 | 2–3 d | 10.5 d |
| P4 | 8–12 d | 22.5 d |
| P5 | 4–6 d | 28.5 d |

Estimates assume one engineer working full-time with no context switching. P4 dominates because
of breadth (259 files), not difficulty — it is mechanical once P2 and P3 are stable.
