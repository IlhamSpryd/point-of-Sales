# APEIRON POS — 3-TIER ENTITY OWNERSHIP & ISOLATION ARCHITECTURE CONTRACT

## 1. Executive Overview

This document specifies the canonical architectural contract governing entity ownership, multi-tenancy isolation boundaries, database constraints, and lifecycle context propagation across Apeiron POS.

The architecture strictly follows a **3-Tier Ownership Model** enforced at multiple layers:
1. **Database Schema & Constraints (Layer 0 - Hard Boundary):** Composite Primary Keys, Composite Foreign Keys `(tenant_id, ...)` with `RESTRICT` on delete, and database triggers.
2. **Eloquent Global Scopes & Traits (Layer 1 - ORM Boundary):** Fail-closed tenant and store scopes (`TenantScope`, `StoreScope`, `DiscountStoreScope`).
3. **Application & Request Context (Layer 2 - Service Boundary):** In-memory context services (`TenantContext`, `StoreResolver`), locked Livewire state, and zero-leak asynchronous execution.

---

## 2. The 3-Tier Ownership Model Specification

### Tier 1 — Global / Platform-Owned (No `tenant_id`)
Entities that exist at the platform infrastructure level across all tenants.
- **Tables:** `tenants`, `permissions`, `provisioning_nonces`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `password_reset_tokens`.
- **Special Platform Records:**
  - `provisioning_requests`: Idempotency ledger for incoming onboarding requests. Contains `result_tenant_id` once provisioning completes.
  - `owner_onboarding_tokens`: Single-use tokens for initial owner password setup, binding `tenant_id` and `user_id`.
  - `email_outbox`: Transactional outbox pattern for asynchronous email dispatch; tracks `tenant_id` for tenant attribution.
- **Model Rules:** Must NOT use `ScopedToTenant` or `AssignsTenant`. Bypasses tenant scoping implicitly.

### Tier 2 — Tenant-Owned Cross-Branch (`tenant_id NOT NULL`, Direct `store_id` Omitted)
Entities that belong to a single tenant and are managed at the organizational/tenant level. Cross-branch access is governed by associative mapping tables.
- **Tables:** `stores`, `users` (branch access mapped via `user_stores`), `roles`, `role_permissions`, `employee_id_sequences`, `customers`, `loyalty_tiers`, `customer_loyalty_accounts`, `loyalty_ledger`.
- **Catalog Master Tables (Tenant-Wide):** `categories`, `products`, `modifier_groups`, `modifiers`, `modifier_group_product`, `ingredients`, `product_ingredients`, `modifier_ingredients`, `ingredient_restock_forecasts`, `channel_product_mappings`, `channel_order_logs`.
- **Model Rules:**
  - MUST apply `ScopedToTenant` and `AssignsTenant`.
  - `tenant_id` is IMMUTABLE once assigned; updates to `tenant_id` must throw `\RuntimeException`.
  - `User` authenticates before tenant context resolution, so `User` implements custom `resolveRouteBinding` and `AssignsTenant` without applying `ScopedToTenant` directly.

### Tier 3A — Store-Owned within Tenant (`tenant_id NOT NULL` AND `store_id NOT NULL`)
Transactional and operational records strictly bound to a specific physical outlet/store within a tenant.
- **Tables:** `tables`, `shifts`, `orders`, `order_items`, `order_item_modifiers`, `payments`, `stock_movements`, `ingredient_stock_movements`, `product_stock_balances`, `ingredient_stock_balances`, `cash_drawer_movements`, `activity_logs`, `export_tasks` (per `2026_12_01_000000_branch_ownership.php`).
- **Database Constraints:**
  - `store_id` is `BIGINT UNSIGNED NOT NULL` with no default.
  - Composite Foreign Key: `FOREIGN KEY (tenant_id, store_id) REFERENCES stores (tenant_id, id) ON DELETE RESTRICT ON UPDATE CASCADE`.
  - Additional composite foreign keys binding related tenant records (e.g., `(tenant_id, order_id)`, `(tenant_id, product_id)`).
- **Model Rules:**
  - MUST apply `ScopedToTenant`, `AssignsTenant`, `ScopedToStore`, and `AssignsStore`.
  - Both `tenant_id` and `store_id` are IMMUTABLE once created.
  - Cross-store queries require explicit, audited owner-bypass via `allStores()`.

### Tier 3B — Hybrid Tenant/Store-Owned (`tenant_id NOT NULL`, `store_id NULLABLE`)
Entities that can apply either globally across all branches of a tenant or to a specific branch.
- **Tables:** `discounts` (and `settings` when configured with branch overrides).
- **Semantics:**
  - When `store_id IS NULL`: The discount or setting is tenant-wide and applies to all branches of that tenant.
  - When `store_id IS NOT NULL`: The discount or setting applies exclusively to that specific branch.
- **Query Boolean Grouping Contract (MANDATORY):**
  Queries MUST wrap store conditions in explicit parentheses to prevent cross-tenant data leakage:
  ```sql
  WHERE tenant_id = ? AND (store_id = ? OR store_id IS NULL)
  ```
- **Eloquent Scope Implementation:**
  ```php
  $builder->where($model->qualifyColumn('tenant_id'), $tenantId)
          ->where(function (Builder $query) use ($model, $storeId) {
              $query->where($model->qualifyColumn('store_id'), $storeId)
                    ->orWhereNull($model->qualifyColumn('store_id'));
          });
  ```

---

## 3. Fail-Closed Global Scoping Policy

1. **Unset Context Fail-Closed:**
   If `TenantContext::getTenantId()` is `null` and `TenantContext::isBypassed()` is `false`, `TenantScope` and `StoreScope` MUST inject `$builder->whereRaw('1 = 0')`.
   Queries executed outside an authenticated tenant context without explicit bypass MUST return 0 records, never leaking records from other tenants.

2. **Explicit Bypass Auditing:**
   The only permitted bypass for `TenantScope` is `TenantContext::runWithoutTenant(Closure $cb)` or `ScopedToTenant::withoutTenantScope()`, which emits structured security warnings to the audit log.

3. **Store Switching Validation:**
   When switching active branch (`StoreSwitchController`), the system verifies:
   - `stores.tenant_id === auth()->user()->tenant_id`
   - User has explicit branch assignment in `user_stores` (or possesses Owner / Admin platform privileges).

---

## 4. Cache & Queue Isolation Contracts

1. **Cache Key Dimensions:**
   - Store-level data: `tenant:{$tenantId}:store:{$storeId}:{key}`
   - Tenant-level data: `tenant:{$tenantId}:{key}`
   - Operations without an active tenant context MUST throw `\RuntimeException`.

2. **Queue Worker Context Safety:**
   - Every queued job must carry explicit `$tenantId` (and `$storeId` if applicable).
   - Logic must execute within `TenantContext::runAs($tenantId, $storeId, ...)` with a mandatory `finally` block ensuring context restoration so long-lived queue workers never bleed state between jobs.
