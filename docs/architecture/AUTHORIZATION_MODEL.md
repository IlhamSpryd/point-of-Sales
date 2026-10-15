# APEIRON POS — AUTHORIZATION & RBAC MODEL

1. **Role Scoping:** Roles belong to a specific `tenant_id` (`roles.tenant_id NOT NULL`). Assigning a user to a role requires `user.tenant_id === role.tenant_id`.
2. **Branch Access:**
   - Owner / Admin: Access to all branches within the tenant.
   - Manager / Staff / Cashier / Barista: Branch access granted via `user_stores` pivot table (`tenant_id, user_id, store_id`).
3. **Route & Livewire Guarding:**
   - Web routes require `['auth', 'tenant.context', 'enforce.email.verification']`.
   - Livewire components implement `RequiresTenantContext` and initialize `bootRequiresTenantContext()`.
   - Critical component properties (`$tenantId`, `$storeId`, `$orderId`, `$shiftId`) are `#[Locked]`.
