# Apeiron POS — Owner Navigation Implementation Plan
**Task ID:** `APEIRON-NAV-IMPL-001`  
**Author:** Senior Laravel + Livewire Architect, SaaS UX Engineer, Accessibility Specialist  
**Primary Reference:** `navigationAudit.md` (`APEIRON-UX-NAV-A0-2026-001`)  
**Date:** 2026-10-16  
**Status:** IMPLEMENTATION PLAN — READY FOR EXECUTION  

---

## 1. Verified Repository Baseline

- **Repository Root:** `/home/ilhamspryd/Projects/apeironSystem/point-of-Sales`
- **Application Stack:** Laravel 11/12 (`laravel/framework ^13.17`), Livewire 3/4 (`livewire/livewire ^4.4`), Alpine.js (bundled with Livewire), Tailwind CSS (Vite pipeline), MySQL (`yovel_pos_test` in testing environment).
- **Git Branch:** `main`
- **Git HEAD Commit SHA:** `9b831af63d6e648602ef344a8f0786fbc3b712d8`
- **Working-Tree Status:** Clean (`nothing to commit, working tree clean`). Zero uncommitted, staged, or untracked modifications.
- **Reference Document Locations:**
  - `doc/navigationAudit.md` (A0 audit report, 739 lines)
  - `doc/ApeironSystems-FinalMaster-PRD.docx` (Final Master PRD Canonical Baseline)
  - `point-of-Sales/tests/Feature/Navigation/SidebarIntegrityTest.php` (Existing navigation regression test suite)
  - `point-of-Sales/tests/Feature/Domain4/UiUxDesignSystemAuditTest.php` (UI/UX design system test suite)

---

## 2. Executive Summary of Audit Findings & Repository Validation

The audit `navigationAudit.md` identified several key architectural and UX issues in the navigation system. Our independent verification against the live codebase yielded the following findings:

1. **P0-NAV-01 (Multi-Tab Store Context in Livewire):** The audit flagged that switching stores in `x-branch-switcher` mutates session store context globally across browser tabs. In `RequiresTenantContext`, the session store ID is resolved on every Livewire request. We must preserve this existing mechanism while adding clear scope indicators and preventing silent cross-store mutations.
2. **P1-NAV-02 (Split/Duplicate Order & Shift Navigation):** **AUDIT INFERENCE REFUTED BY CODE INSPECTION.** The audit believed `orders/index.blade.php` and `livewire/kasir/order-history.blade.php` were duplicate competing routes, as well as `shifts/index.blade.php` and `livewire/shift-manager.blade.php`. In reality, `orders/index.blade.php` is the controller view that simply embeds `<livewire:kasir.order-history />`, and `shifts/index.blade.php` is the controller view that embeds `<livewire:shift-manager />`. There is no duplicate route. The only routes are `orders.index` and `shifts.index`.
3. **P1-NAV-03 (Missing Policy Classes for Admin Modules):** The repository has 7 policies in `app/Policies/`. Admin modules (`UserController`, `RoleController`, `SettingController`, `ActivityLogController`, `ReportController`) are guarded server-side by `RoleMiddleware` (`role:Owner` or `role:Owner,Manager`). While not an open vulnerability because middleware rejects unauthorized access with HTTP 403, implementing dedicated Policies adds defense-in-depth and satisfies best practices.
4. **P1-NAV-04 (Unsaved POS Cart Destruction):** Verified. If a cashier has items in `$cart` in `Kasir\CreateOrder` and navigates away via the sidebar or switches branch via `StoreSwitchController`, the in-memory cart is lost without warning. A confirmation safeguard is needed when the cart is dirty.
5. **P1-NAV-05 (Post-Onboarding Branch Management Gap):** Verified. There is currently no `StoreController` for managing branches post-onboarding. Per Section 4.5 and Section 8 of the prompt, creating a brand-new multi-branch CRUD module is out of scope for a navigation task and is tracked as a separate follow-up task.
6. **P2-NAV-06 (Tenant vs. Branch Scope Ambiguity):** Verified. The sidebar currently displays branch-scoped models (`ScopedToStore`) and tenant-wide models (`ScopedToTenant`) without visual differentiation.
7. **P2-NAV-07 (Dual Desktop/Mobile Navigation Markup):** Partially verified. While Breeze legacy components existed (`x-nav-link`, `x-responsive-nav-link`), `layouts/navigation.blade.php` already loops over a single collection (`$visibleMenu`). However, the drawer and rail behavior can be made significantly more robust and accessible.
8. **P2-NAV-08 (WCAG 2.2 AA Landmarks and Focus Trap):** Verified. Mobile drawer lacks focus trapping and restoration, and active navigation items lack a geometric non-color indicator bar.

---

## 3. Finding-by-Finding Traceability Matrix

| Finding ID | Audit Summary | Codebase Verification Evidence | Decision | Implementation Target |
|:---|:---|:---|:---|:---|
| **P0-NAV-01** | Multi-tab store context race in Livewire | `RequiresTenantContext.php` resolves session store ID. | Implement | Add visual scope indicators and guard store switching during active transaction. |
| **P1-NAV-02** | Split/duplicate Order & Shift routes | Refuted: `orders/index.blade.php` wraps `OrderHistory`; `shifts/index.blade.php` wraps `ShiftManager`. Only 1 route each. | Resolve by documentation | Clarify unified IA in `config/navigation.php` and route matrix. |
| **P1-NAV-03** | Missing Policy classes for Admin controllers | Server-side auth enforced by `RoleMiddleware`. Zero policies in `app/Policies/` for User, Role, Setting, ActivityLog. | Implement | Add `UserPolicy`, `RolePolicy`, `SettingPolicy`, `ActivityLogPolicy` in `app/Policies/`. |
| **P1-NAV-04** | Unsaved cart destruction on navigation | Verified: `Kasir\CreateOrder` has in-memory `$cart`; navigation or branch switch discards without prompt. | Implement | Add `window.apeironPosCartDirty` lifecycle, `beforeunload` protection, and confirmation modal on navigation/switch. |
| **P1-NAV-05** | Missing post-onboarding Store CRUD UI | Verified: `Store` model and `StoreSwitchController` exist, but no `StoreController` CRUD views exist. | Defer (Out of Scope) | Document as P1 Product Gap for future sprint; do not create placeholder route. |
| **P2-NAV-06** | Tenant vs. Branch scope ambiguity | Verified: No visual indication of `ScopedToStore` vs `ScopedToTenant`. | Implement | Add `'scope' => 'store'` and `'scope' => 'tenant'` to `config/navigation.php` with subtle visual scope badges. |
| **P2-NAV-07** | Dual navigation markup / drift | `layouts/navigation.blade.php` renders from `$visibleMenu`. | Implement | Standardize unified responsive component, clean up legacy unused links. |
| **P2-NAV-08** | WCAG 2.2 AA a11y & focus trap | Mobile drawer lacks focus trap; active items lack 3px left indicator bar. | Implement | Add 3px accent bar, drawer focus trap, `aria-current="page"`, `aria-expanded`, and Escape handling. |

---

## 4. Actual Files and Components Responsible for Navigation

1. **Configuration:**
   - `config/navigation.php` — Single source of truth for navigation sections, items, routes, roles, active patterns, and scopes.
2. **Layouts & Blade Views:**
   - `resources/views/layouts/navigation.blade.php` — Primary responsive sidebar, mobile off-canvas drawer, user profile popover, and desktop rail.
   - `resources/views/layouts/app.blade.php` — Master application shell, viewport lock, main scroll area, and global scripts.
   - `resources/views/components/branch-switcher.blade.php` — Branch selection dropdown in sidebar and mobile drawer.
   - `resources/views/components/nav-link.blade.php` — Reusable accessible navigation link component with active indicator and scope badges.
3. **Livewire & Controllers:**
   - `app/Livewire/Kasir/CreateOrder.php` & `resources/views/livewire/kasir/create-order.blade.php` — POS cashier terminal with active cart lifecycle events.
   - `app/Http/Controllers/StoreSwitchController.php` — Store context switching endpoint.
4. **Policies:**
   - `app/Policies/UserPolicy.php` — Policy for User/Staff management.
   - `app/Policies/RolePolicy.php` — Policy for Role/RBAC management.
   - `app/Policies/SettingPolicy.php` — Policy for Business Settings.
   - `app/Policies/ActivityLogPolicy.php` — Policy for Activity Logs.
5. **Tests:**
   - `tests/Feature/Navigation/SidebarIntegrityTest.php` — Verification of route registration, role middleware parity, IA structure, and a11y contracts.

---

## 5. Verified Route Names for Proposed Menu Items

All 18 navigation items map directly to real, registered routes in `routes/web.php`:

| # | Group | Menu Label | Route Name | HTTP Method & URI | Controller / Livewire Component |
|---|:---|:---|:---|:---|:---|
| 1 | Overview | Dashboard | `dashboard` | `GET /dashboard` | `DashboardController@index` |
| 2 | Sales & Orders | Riwayat Pesanan | `orders.index` | `GET /orders` | `TransactionController@index` (`OrderHistory`) |
| 3 | Sales & Orders | Laporan Penjualan | `reports.sales` | `GET /reports/sales` | `ReportController@index` |
| 4 | Sales & Orders | Diskon & Promo | `discounts.index` | `GET /discounts` | `DiscountController@index` (`DiscountManager`) |
| 5 | Catalog & Inventory | Produk | `products.index` | `GET /products` | `ProductController@index` |
| 6 | Catalog & Inventory | Kategori | `categories.index` | `GET /categories` | `CategoryController@index` |
| 7 | Catalog & Inventory | Varian & Modifier | `inventory.modifiers` | `GET /inventory/modifiers` | `ModifierManager` |
| 8 | Catalog & Inventory | Bahan Baku | `inventory.ingredients` | `GET /inventory/ingredients` | `IngredientManager` |
| 9 | Catalog & Inventory | Riwayat Stok | `inventory.ledger` | `GET /inventory/ledger` | `IngredientLedger` |
| 10 | Store Operations | Shift Kasir | `shifts.index` | `GET /shifts` | `ShiftController@index` (`ShiftManager`) |
| 11 | Store Operations | Meja & QR | `tables.index` | `GET /tables` | `TableController@index` |
| 12 | Store Operations | Integrasi Channel | `integrations.channel-mapping` | `GET /integrations/channel-mapping` | `ChannelMappingManager` |
| 13 | Organization & Settings | Pengguna | `users.index` | `GET /users` | `UserController@index` |
| 14 | Organization & Settings | Peran | `roles.index` | `GET /roles` | `RoleController@index` |
| 15 | Organization & Settings | Pengaturan | `settings.index` | `GET /settings` | `SettingController@index` |
| 16 | Organization & Settings | Audit Log | `activity-logs.index` | `GET /activity-logs` | `ActivityLogController@index` |
| 17 | Terminal Kerja | Kasir (POS) | `transaction.create` | `GET /transaction` | `CreateOrder` |
| 18 | Terminal Kerja | Dapur (KDS) | `kds.index` | `GET /kds` | `Board` |

---

## 6. Role/Permission Visibility Rules & Server Authorization

| Route Name | Authorized Roles in `routes/web.php` | Server-Side Enforcement Mechanism |
|:---|:---|:---|
| `dashboard` | Owner, Manager | `RoleMiddleware` (`role:Owner,Manager`) |
| `orders.index` | Owner, Manager, Kasir, Waiter | `RoleMiddleware` (`role:Owner,Manager,Kasir,Waiter`), `OrderPolicy` |
| `reports.sales` | Owner, Manager | `RoleMiddleware` (`role:Owner,Manager`) |
| `discounts.index` | Owner, Manager | `RoleMiddleware` (`role:Owner,Manager`), `DiscountPolicy` |
| `products.index` | Owner, Manager, Inventory | `RoleMiddleware` (`role:Owner,Manager,Inventory`), `ProductPolicy` |
| `categories.index` | Owner, Manager, Inventory | `RoleMiddleware` (`role:Owner,Manager,Inventory`), `CategoryPolicy` |
| `inventory.modifiers`| Owner, Manager, Inventory | `RoleMiddleware` (`role:Owner,Manager,Inventory`), `RequiresTenantContext` |
| `inventory.ingredients`| Owner, Manager, Inventory | `RoleMiddleware` (`role:Owner,Manager,Inventory`), `IngredientPolicy` |
| `inventory.ledger` | Owner, Manager, Inventory | `RoleMiddleware` (`role:Owner,Manager,Inventory`), `IngredientPolicy` |
| `shifts.index` | Owner, Manager, Kasir, Supervisor | `RoleMiddleware` (`role:Owner,Manager,Kasir,Supervisor`), `ShiftPolicy` |
| `tables.index` | Owner, Manager | `RoleMiddleware` (`role:Owner,Manager`), `TablePolicy` |
| `integrations.channel-mapping` | Owner, Manager | `RoleMiddleware` (`role:Owner,Manager`), `RequiresTenantContext` |
| `users.index` | Owner | `RoleMiddleware` (`role:Owner`), `UserPolicy` (new) |
| `roles.index` | Owner | `RoleMiddleware` (`role:Owner`), `RolePolicy` (new) |
| `settings.index` | Owner | `RoleMiddleware` (`role:Owner`), `SettingPolicy` (new) |
| `activity-logs.index` | Owner, Manager | `RoleMiddleware` (`role:Owner,Manager`), `ActivityLogPolicy` (new) |
| `transaction.create` | Owner, Manager, Kasir | `RoleMiddleware` (`role:Owner,Manager,Kasir`), `RequiresTenantContext` |
| `kds.index` | Owner, Manager, Kasir, Barista, Waiter, Cook | `RoleMiddleware` (`role:Owner,Manager,Kasir,Barista,Waiter,Cook`), `RequiresTenantContext` |

---

## 7. Tenant-Wide vs. Branch-Scoped Classification

To eliminate operator confusion during branch switching:
- **Tenant-Scoped (`tenant`):** Data applies across all branches. Switching the active branch in `x-branch-switcher` does not change this data.
  - *Items:* `dashboard` (consolidated mode), `reports.sales` (consolidated mode), `products.index`, `categories.index`, `inventory.modifiers`, `users.index`, `roles.index`, `settings.index`, `activity-logs.index`, `integrations.channel-mapping`.
- **Branch-Scoped (`store`):** Data is strictly isolated to the active branch (`ScopedToStore`). Switching branches reloads the dataset.
  - *Items:* `orders.index`, `shifts.index`, `tables.index`, `inventory.ingredients`, `inventory.ledger`, `discounts.index`, `transaction.create`, `kds.index`.

Visual cues in the sidebar:
- In expanded mode: Small subtle badge pill (`Cabang` vs `Tenant`) on hover or right slot.
- Grouping: Operational terminal section clearly separated from administrative configuration.

---

## 8. Proposed Final Information Architecture

The menu is structured into 6 clear conceptual sections:

```text
Apeiron POS Navigation Hierarchy
│
├── [Header / Context: Active Branch Switcher (StoreSwitchController) & User Profile]
│
├── 1. Overview (Ringkasan)
│   └── Dashboard [scope: tenant/store]
│
├── 2. Penjualan & Pesanan (Sales & Orders)
│   ├── Riwayat Pesanan [scope: store]
│   ├── Laporan Penjualan [scope: tenant]
│   └── Diskon & Promo [scope: store]
│
├── 3. Katalog & Inventori (Catalog & Inventory)
│   ├── Produk [scope: tenant]
│   ├── Kategori [scope: tenant]
│   ├── Varian & Modifier [scope: tenant]
│   ├── Bahan Baku [scope: store]
│   └── Riwayat Stok [scope: store]
│
├── 4. Operasional Toko (Store Operations)
│   ├── Shift Kasir [scope: store]
│   ├── Meja & QR [scope: store]
│   └── Integrasi Channel [scope: tenant]
│
├── 5. Organisasi & Pengaturan (Organization & Settings)
│   ├── Pengguna [scope: tenant]
│   ├── Peran [scope: tenant]
│   ├── Audit Log [scope: tenant]
│   └── Pengaturan [scope: tenant]
│
└── 6. Terminal Kerja (Workstation Terminals)
    ├── Kasir (POS) [scope: store]
    └── Dapur (KDS) [scope: store]
```

---

## 9. Desktop, Collapsed, Tablet, and Mobile Behavior

1. **Desktop Expanded (`xl` >= 1280px):**
   - Width: `w-64` (256px), fixed left sidebar.
   - Section headers visible, full item labels visible, scope badges visible, branch switcher full-width.
2. **Laptop / Tablet Landscape (`lg` 1024px–1279px):**
   - User-toggleable collapsed rail (`w-18` / 72px) or expanded (`w-64` / 256px).
   - Saved in `localStorage.getItem('sidebarExpanded')`.
   - When collapsed: icons centered, labels visually hidden with standard native `title` tooltip, dedicated toggle button in sidebar header.
3. **Mobile & Tablet Portrait (`< 1024px`):**
   - Off-canvas drawer (`w-72 max-w-[85vw]`), hidden with `-translate-x-full`.
   - Backdrop overlay (`bg-apeiron-ink/40 backdrop-blur-sm`).
   - Triggered by floating top-left menu button (`h-[44px] w-[44px]`, touch-target compliant).
   - Dismissible via Escape key, close button, or backdrop click.
   - Body scroll locked when drawer is open.

---

## 10. Accessibility Requirements (WCAG 2.2 AA)

1. **Navigation Landmark:** `<nav aria-label="Navigasi utama">`
2. **Current Page Indication:** `aria-current="page"` rendered on active route link.
3. **Visual Active State (Non-Color Cue):** 3px vertical accent bar on the left edge (`before:absolute before:left-0 before:top-1.5 before:bottom-1.5 before:w-1 before:bg-[#202020] before:rounded-r`).
4. **Interactive Controls:** All buttons and interactive links have minimum touch targets of 44x44px on mobile and >= 36px on desktop.
5. **Mobile Drawer Focus Management:**
   - `aria-controls="main-sidebar"` and `:aria-expanded="sidebarMobileOpen.toString()"` on toggle button.
   - Focus trapping in open drawer (`@keydown.tab` cycle).
   - Escape key dismisses drawer (`@keydown.escape.window`).
   - Focus restoration to menu button on close.
6. **Contrast:** Text contrast exceeds 4.5:1 against neutral background.

---

## 11. POS Cart and State Preservation Strategy

1. **Cart Dirty State Tracking:**
   - In `Kasir\CreateOrder`, when items are in `$cart` (`count($this->cart) > 0`), the component emits/maintains state where `window.apeironPosCartDirty = true`.
   - When cart is emptied or transaction is successfully submitted, `window.apeironPosCartDirty = false`.
2. **Navigation Interception:**
   - `beforeunload` listener: Warns user on browser tab close or full refresh if `window.apeironPosCartDirty === true`.
   - Livewire `wire:navigate` / link clicks: Intercepts navigation leaving the POS cashier screen if `window.apeironPosCartDirty === true` and displays confirmation prompt: *"Anda memiliki pesanan aktif di keranjang kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?"*.
   - Branch Switcher: Form submission in `components/branch-switcher.blade.php` checks `window.apeironPosCartDirty` and prompts confirmation before switching branches.

---

## 12. Regression Risks & Mitigations

| Risk | Mitigation |
|:---|:---|
| Breaking existing route names or tests | **Zero route renaming.** All route names remain identical (`dashboard`, `orders.index`, `products.index`, `transaction.create`, etc.). |
| Breaking `SidebarIntegrityTest` role matching | Exact parity between `roles` in `config/navigation.php` and `role:` route middleware. |
| Breaking `MenuCacheService` | Verified `MenuCacheService` is for product catalog cache, not sidebar menu. No mutations to `MenuCacheService`. |
| Breaking Livewire or Alpine initialization | Avoid duplicate Alpine instances. Use existing Livewire 3/Alpine directives without adding external libraries. |
| Discarding active cashier orders | Protected by `window.apeironPosCartDirty` lifecycle safeguards. |

---

## 13. Exact Files to Modify / Create

1. `config/navigation.php` — Restructure into 6 groups with `scope`, preserving all 18 routes and roles.
2. `resources/views/layouts/navigation.blade.php` — Refactor sidebar with 3px active indicator bar, scope badges, mobile drawer focus trap, accessible collapse toggle.
3. `resources/views/components/branch-switcher.blade.php` — Integrate cart dirty check before branch switch submission.
4. `resources/views/livewire/kasir/create-order.blade.php` & `app/Livewire/Kasir/CreateOrder.php` — Maintain `window.apeironPosCartDirty` state.
5. `app/Policies/UserPolicy.php` — Dedicated policy for User model.
6. `app/Policies/RolePolicy.php` — Dedicated policy for Role model.
7. `app/Policies/SettingPolicy.php` — Dedicated policy for Setting model.
8. `app/Policies/ActivityLogPolicy.php` — Dedicated policy for ActivityLog model.
9. `tests/Feature/Navigation/SidebarIntegrityTest.php` — Extended tests for 6 groups, scopes, role filtering, and a11y DOM contracts.

---

## 14. Verification & Testing Plan

1. **Automated Feature Tests:**
   - Run `php artisan test tests/Feature/Navigation/SidebarIntegrityTest.php`.
   - Run `php artisan test tests/Feature/Domain4/UiUxDesignSystemAuditTest.php`.
   - Run `php artisan test tests/Feature/Domain3/FrontendArchitectureAuditTest.php`.
   - Run `php artisan test tests/Feature/CheckoutGuardTest.php`.
2. **Authorization & RBAC Matrix Verification:**
   - Verify Owner sees all 18 items.
   - Verify Manager sees 14 items.
   - Verify Kasir, Waiter, and Inventory staff see only permitted items.
   - Verify direct URL access to restricted routes returns HTTP 403.
3. **Accessibility & Interaction Verification:**
   - Verify `aria-current="page"` on active links.
   - Verify drawer focus management and Escape key handling.
   - Verify collapsed rail toggle and active indicator bar.

---

## 15. Deferred Findings Outside Current Scope

1. **Post-Onboarding Branch CRUD UI (`P1-NAV-05`):** Creating full Store CRUD views and controller (`StoreController`) is an extensive business domain feature deferred to a dedicated branch-management task.
2. **Customers & Loyalty Management UI:** Backend models exist, but dedicated customer management UI is deferred to a future Customer/Loyalty sprint.
3. **Purchasing & Suppliers Module:** Backend models and migrations do not exist; candidate for future ERP procurement sprint.
