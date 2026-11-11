<?php

namespace Database\Seeders\Pos;

use Database\Seeders\Pos\Concerns\StampsTenantOnBulkInsert;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    use StampsTenantOnBulkInsert;

    public function run(): void
    {
        $now = now();

        $roles = [
            [
                'role_code' => 'ROLE-OWN',
                'name' => 'Owner',
                'description' => 'Pemilik bisnis — akses penuh organisasi',
                'permissions' => json_encode(['*']),
                'dashboard_archetype' => 'executive',
                'is_system_default' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role_code' => 'ROLE-MGR',
                'name' => 'Manager',
                'description' => 'Manajer operasional cabang',
                'permissions' => json_encode([
                    'dashboard',
                    'dashboard.metric.*',
                    'dashboard.action.*',
                    'reports',
                    'reports.*',
                    'orders.*',
                    'users.view',
                    'shifts.*',
                    'inventory.*',
                    'purchasing.*',
                    'customers.*',
                    'products.*',
                    'pricing.*',
                    'discounts.*',
                    'finance.*',
                    'hr.*',
                    'stores.switch',
                ]),
                'dashboard_archetype' => 'branch_ops',
                'is_system_default' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role_code' => 'ROLE-KSR',
                'name' => 'Kasir',
                'description' => 'Kasir point of sale frontline',
                'permissions' => json_encode([
                    'dashboard',
                    'dashboard.metric.revenue',
                    'dashboard.metric.cash_variance',
                    'orders.create',
                    'orders.view',
                    'payments.create',
                    'shifts.own',
                ]),
                'dashboard_archetype' => 'cashier_workspace',
                'is_system_default' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role_code' => 'ROLE-WTR',
                'name' => 'Waiter',
                'description' => 'Pelayan restoran & lantai',
                'permissions' => json_encode(['dashboard', 'orders.create', 'orders.view', 'tables.view']),
                'dashboard_archetype' => 'kds_floor',
                'is_system_default' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role_code' => 'ROLE-BRS',
                'name' => 'Barista',
                'description' => 'Barista pembuat minuman & KDS',
                'permissions' => json_encode(['dashboard', 'orders.view', 'kds.view', 'kds.update', 'kds.access']),
                'dashboard_archetype' => 'kds_floor',
                'is_system_default' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role_code' => 'ROLE-INV',
                'name' => 'Inventory',
                'description' => 'Staff inventaris gudang & supply chain',
                'permissions' => json_encode([
                    'dashboard',
                    'dashboard.metric.inventory_valuation',
                    'inventory.*',
                    'ingredients.*',
                    'purchasing.*',
                    'products.view',
                    'pricing.view',
                ]),
                'dashboard_archetype' => 'inventory_supply',
                'is_system_default' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role_code' => 'ROLE-SPV',
                'name' => 'Supervisor',
                'description' => 'Supervisor shift & otorisasi kasir',
                'permissions' => json_encode([
                    'dashboard',
                    'dashboard.metric.revenue',
                    'dashboard.metric.cash_variance',
                    'dashboard.action.approve_void_discount',
                    'orders.*',
                    'shifts.*',
                    'reports.daily',
                    'void.approve',
                    'pos.discount.override_approve',
                    'stores.switch',
                ]),
                'dashboard_archetype' => 'branch_ops',
                'is_system_default' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role_code' => 'ROLE-KCK',
                'name' => 'Cook',
                'description' => 'Koki dapur & display KDS',
                'permissions' => json_encode(['dashboard', 'orders.view', 'kds.view', 'kds.update', 'kds.access']),
                'dashboard_archetype' => 'kds_floor',
                'is_system_default' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role_code' => 'ROLE-FIN',
                'name' => 'Finance',
                'description' => 'Staf keuangan & akuntansi buku besar',
                'permissions' => json_encode([
                    'dashboard',
                    'dashboard.metric.revenue',
                    'dashboard.metric.profit_margin',
                    'dashboard.metric.finance_gl',
                    'finance.*',
                    'sales.orders.*',
                    'sales.invoices.*',
                    'purchasing.invoice.*',
                    'customers.view',
                    'customers.credit.*',
                    'reports.financial.*',
                ]),
                'dashboard_archetype' => 'finance_ledger',
                'is_system_default' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role_code' => 'ROLE-SLS',
                'name' => 'Sales',
                'description' => 'Staf penjualan B2B & pipeline faktur',
                'permissions' => json_encode([
                    'dashboard',
                    'dashboard.metric.revenue',
                    'sales.*',
                    'customers.*',
                    'products.view',
                    'pricing.view',
                ]),
                'dashboard_archetype' => 'sales_b2b',
                'is_system_default' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'role_code' => 'ROLE-ADM',
                'name' => 'Admin',
                'description' => 'Administrator organisasi & tata kelola',
                'permissions' => json_encode([
                    'dashboard',
                    'dashboard.*',
                    'reports.*',
                    'orders.*',
                    'users.*',
                    'roles.*',
                    'stores.*',
                    'settings.*',
                    'shifts.*',
                    'inventory.*',
                    'purchasing.*',
                    'customers.*',
                    'products.*',
                    'pricing.*',
                    'finance.*',
                    'hr.*',
                ]),
                'dashboard_archetype' => 'executive',
                'is_system_default' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $tenantId = null;
        try {
            $tenantId = app(\App\Services\Context\TenantContext::class)->getTenantId();
        } catch (\Throwable) {
            $tenantId = null;
        }

        if ($tenantId === null) {
            $tenantId = DB::table('tenants')->orderBy('id')->value('id') ?? 1;
        }

        foreach ($roles as $role) {
            $role['tenant_id'] = $role['tenant_id'] ?? $tenantId;

            DB::table('roles')->updateOrInsert(
                [
                    'tenant_id' => $role['tenant_id'],
                    'name' => $role['name'],
                ],
                $role
            );
        }
    }
}
