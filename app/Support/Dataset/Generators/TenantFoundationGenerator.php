<?php

declare(strict_types=1);

namespace App\Support\Dataset\Generators;

use App\Modules\Users\Services\EmployeeIdGenerator;
use App\Support\Dataset\CatalogFixtures;
use App\Support\Dataset\DatasetConfig;
use App\Support\Dataset\DatasetGenerationException;
use App\Support\Dataset\DatasetWriter;
use App\Support\Dataset\SeededRandom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Membuat fondasi tenant demo: tenant, subscription, cabang, role, user,
 * device, setting, dan pivot user_stores.
 *
 * Aturan keamanan:
 *  - Tidak ada ID hardcode (tenant/store/role di-resolve by key/kode).
 *  - Tidak ada TRUNCATE, tidak melepaskan FK/trigger.
 *  - Idempoten: rerun tidak membuat duplikat (lookup by marker/email/kode).
 */
final class TenantFoundationGenerator
{
    public function __construct(
        private readonly DatasetConfig $config,
        private readonly DatasetWriter $writer,
    ) {}

    /**
     * @param  array<string,mixed>  $account
     * @return array<string,mixed> konteks akun (tenant, stores, roles, users, devices)
     */
    public function generate(array $account): array
    {
        $email = (string) $account['email'];
        $rng = (new SeededRandom($this->config->seed))->derive('tenant:'.$email);

        // ── 1. Tenant ────────────────────────────────────────────────
        $markerKey = $this->config->marker('tenant:'.Str::slug($account['tenant_slug']));
        $tenant = DB::table('tenants')
            ->where(fn ($q) => $q->where('provisioning_idempotency_key', $markerKey)
                ->orWhere('slug', $account['tenant_slug']))
            ->first();

        $industry = (string) ($account['industry'] ?? 'fnb');

        if (! $tenant) {
            $tenantId = $this->writer->insertGetId('tenants', [
                'name' => $account['tenant_name'],
                'slug' => $account['tenant_slug'],
                'primary_industry' => $industry,
                'status' => 'active',
                'onboarding_status' => 'completed',
                'onboarding_completed_at' => $this->config->startDate->subDays(14),
                'access_status' => 'active',
                'subscription_status' => 'active',
                'subscription_plan' => $this->config->planCode,
                'country_code' => 'ID',
                'provisioning_idempotency_key' => $markerKey,
                'readiness_status' => 'ready',
                'readiness_checked_at' => now(),
                'created_at' => $this->config->startDate->subDays(14),
                'updated_at' => now(),
            ]);
            $this->writer->info("    • tenant #{$tenantId} dibuat ({$account['tenant_name']})");
        } else {
            $tenantId = (int) $tenant->id;
            DB::table('tenants')->where('id', $tenantId)->update(['primary_industry' => $industry]);
            $this->writer->info("    • tenant #{$tenantId} sudah ada ({$account['tenant_name']}) — reuse");
        }

        // ── 2. Subscription (plan sesuai config agar kuota cabang terpenuhi) ──
        $planId = (int) (DB::table('plans')->where('code', $this->config->planCode)->value('id') ?? 0);
        if ($planId > 0) {
            $hasSub = DB::table('tenant_subscriptions')->where('tenant_id', $tenantId)->exists();
            if (! $hasSub) {
                DB::table('tenant_subscriptions')->insert([
                    'tenant_id' => $tenantId,
                    'plan_id' => $planId,
                    'status' => 'active',
                    'starts_at' => $this->config->startDate->subDays(14),
                    'ends_at' => $this->config->endDate->addYear(),
                    'trial_ends_at' => null,
                    'metadata' => json_encode(['source' => 'dataset-generator']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } else {
            $this->writer->warn("    ! plan '{$this->config->planCode}' tidak ditemukan; kuota default dipakai.");
        }

        // ── 3. Legal Company (Tier 2 DEC-001) ────────────────────────
        $companyId = $this->ensureCompany($tenantId, $account);

        // ── 4. Roles (by role_code, bukan ID) ────────────────────────
        $roles = $this->ensureRoles($tenantId);

        // ── 5. Stores (Tier 3 DEC-001) ───────────────────────────────
        $stores = $this->ensureStores($tenantId, $account, $rng);

        // ── 6. Warehouses (Tier 4 DEC-001) ───────────────────────────
        $warehouses = $this->ensureWarehouses($tenantId, $companyId, $stores);

        // ── 7. Operational Units ─────────────────────────────────────
        $opUnits = $this->ensureOperationalUnits($tenantId, $stores, $industry);

        // ── 8. Tenant & Store Capabilities ───────────────────────────
        $this->ensureCapabilities($tenantId, $stores, $industry);

        // ── 9. Owner accounts (akun demo) ────────────────────────────
        $owners = $this->ensureOwners($tenantId, $account, $roles, $stores);

        // ── 10. Staff per store ──────────────────────────────────────
        $staff = $this->ensureStaff($tenantId, $account, $roles, $stores, $rng);

        // ── 11. Devices per store (Tier 5 DEC-001) ───────────────────
        $devices = $this->ensureDevices($tenantId, $stores, $opUnits, $rng);

        // ── 12. Settings baseline ────────────────────────────────────
        $this->ensureSettings($tenantId);

        // ── 13. IAM: user_stores & user_store_roles ──────────────────
        $this->ensureUserStoreRoles($tenantId, $stores, $staff, $owners, $roles);

        foreach ($owners as $ownerId) {
            foreach ($stores as $store) {
                $exists = DB::table('user_stores')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $ownerId)
                    ->where('store_id', $store['id'])
                    ->exists();
                if (! $exists) {
                    DB::table('user_stores')->insert([
                        'tenant_id' => $tenantId,
                        'user_id' => $ownerId,
                        'store_id' => $store['id'],
                        'created_at' => $this->config->startDate->subDays(10),
                        'updated_at' => $this->config->startDate->subDays(10),
                    ]);
                }
            }
        }

        return [
            'tenant_id' => $tenantId,
            'email' => $email,
            'owners' => $owners,
            'roles' => $roles,
            'stores' => $stores,
            'staff' => $staff,
            'devices' => $devices,
        ];
    }

    /** @return array<string,int> role_code => id */
    private function ensureRoles(int $tenantId): array
    {
        $defaults = [
            'ROL-OWNER' => ['Owner', 'Pemilik bisnis — akses penuh', ['*']],
            'ROL-MANAGER' => ['Manager', 'Manajer operasional cabang', ['dashboard', 'reports', 'orders.*', 'users.view', 'shifts.*', 'inventory.*', 'discounts.*']],
            'ROL-KASIR' => ['Kasir', 'Kasir point of sale', ['orders.create', 'orders.view', 'payments.create', 'shifts.own']],
            'ROL-WAITER' => ['Waiter', 'Pelayan restoran', ['orders.create', 'orders.view', 'tables.view']],
            'ROL-BARISTA' => ['Barista', 'Barista pembuat minuman', ['orders.view', 'kds.view', 'kds.update']],
            'ROL-INVENTORY' => ['Inventory', 'Staff inventaris gudang', ['inventory.*', 'ingredients.*']],
            'ROL-SUPERVISOR' => ['Supervisor', 'Supervisor shift', ['orders.*', 'shifts.*', 'reports.daily', 'void.approve']],
            'ROL-COOK' => ['Cook', 'Koki dapur', ['orders.view', 'kds.view', 'kds.update']],
            'ROL-SALES' => ['Sales Associate', 'Pramuniaga & Layanan Toko Ritel', ['orders.create', 'orders.view', 'products.view', 'inventory.view']],
        ];

        $result = [];
        foreach ($defaults as $code => [$name, $desc, $perms]) {
            $existing = DB::table('roles')
                ->where('tenant_id', $tenantId)
                ->where(fn ($q) => $q->where('role_code', $code)->orWhere('name', $name))
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $result[$code] = (int) $existing->id;

                continue;
            }

            $result[$code] = (int) DB::table('roles')->insertGetId([
                'tenant_id' => $tenantId,
                'role_code' => $code,
                'name' => $name,
                'description' => $desc,
                'permissions' => json_encode($perms),
                'is_active' => 1,
                'created_at' => $this->config->startDate->subDays(14),
                'updated_at' => $this->config->startDate->subDays(14),
            ]);
        }

        return $result;
    }

    /**
     * @return array<int, array{id:int,name:string,code:string,char:string,opened_at:CarbonImmutable}>
     */
    private function ensureStores(int $tenantId, array $account, SeededRandom $rng): array
    {
        $limit = min(count($account['stores']), $this->config->volumeInt('max_stores', count($account['stores'])));
        $result = [];

        foreach (array_slice($account['stores'], 0, $limit) as $idx => $storeDef) {
            $existing = DB::table('stores')
                ->where('tenant_id', $tenantId)
                ->where('code', $storeDef['code'])
                ->whereNull('deleted_at')
                ->first();

            // Sebagian cabang dibuka belakangan bila staggered opening aktif.
            $openedAt = $this->config->startDate;
            if ($this->config->volume['staggered_store_opening'] ?? true) {
                if ($idx >= 4) {
                    $openedAt = $this->config->startDate->addMonthsNoOverflow(min(9, ($idx - 3) * 2));
                } elseif ($idx === 3) {
                    $openedAt = $this->config->startDate->addMonthsNoOverflow(3);
                }
            }

            if ($existing) {
                $storeId = (int) $existing->id;
            } else {
                $storeId = $this->writer->insertGetId('stores', [
                    'tenant_id' => $tenantId,
                    'name' => $storeDef['name'],
                    'code' => $storeDef['code'],
                    'timezone' => $this->config->period['timezone'] ?? 'Asia/Jakarta',
                    'business_date_cutoff' => '00:00',
                    'default_channel' => $idx % 2 === 0 ? 'dine_in' : 'takeaway',
                    'phone' => $storeDef['phone'] ?? null,
                    'is_active' => 1,
                    'is_initial' => $idx === 0 ? 1 : 0,
                    'address' => $storeDef['address'] ?? null,
                    'created_at' => $openedAt,
                    'updated_at' => $openedAt,
                ]);
            }

            $result[] = [
                'id' => $storeId,
                'name' => $storeDef['name'],
                'code' => $storeDef['code'],
                'char' => $storeDef['char'] ?? 'cafe',
                'opened_at' => $openedAt,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string,int>  $roles
     * @param  array<int,array{id:int}>  $stores
     * @return array<int,int> daftar user id owner
     */
    private function ensureOwners(int $tenantId, array $account, array $roles, array $stores): array
    {
        if (! $this->config->hasPassword()) {
            throw new DatasetGenerationException(
                'Password demo wajib diberikan via --password atau env DATASET_DEMO_PASSWORD. '
                .'Password tidak disimpan di config maupun log.'
            );
        }

        $hash = Hash::make((string) $this->config->password);
        $ownerId = $this->upsertUser($tenantId, [
            'email' => $account['email'],
            'name' => $account['name'],
            'role_id' => $roles['ROL-OWNER'],
            'password_hash' => $hash,
            'created_at' => $this->config->startDate->subDays(30),
        ]);

        return [$ownerId];
    }

    private function ensureCompany(int $tenantId, array $account): int
    {
        $industry = (string) ($account['industry'] ?? 'fnb');
        $isFnb = $industry === 'fnb';
        $code = $isFnb ? 'CMP-FNB-01' : 'CMP-RTL-02';
        $name = $isFnb ? 'PT Apeiron Kuliner Nusantara' : 'PT Sagara Retail Megatama';
        $npwp = $isFnb ? '00.000.000.0-001.000' : '00.000.000.0-002.000';
        $address = $isFnb
            ? 'Jl. Senopati No. 42, Kebayoran Baru, Jakarta Selatan'
            : 'Grand Indonesia West Mall Lt. 2, Jl. M.H. Thamrin No. 1, Jakarta Pusat';

        $existing = DB::table('companies')->where('tenant_id', $tenantId)->first();
        if ($existing) {
            return (int) $existing->id;
        }

        return (int) DB::table('companies')->insertGetId([
            'tenant_id' => $tenantId,
            'company_code' => $code,
            'company_name' => $name,
            'legal_name' => $name,
            'npwp' => $npwp,
            'tax_address' => $address,
            'is_active' => 1,
            'created_at' => $this->config->startDate->subDays(20),
            'updated_at' => $this->config->startDate->subDays(20),
        ]);
    }

    private function ensureWarehouses(int $tenantId, int $companyId, array $stores): array
    {
        $warehouseIds = [];
        $whCode = sprintf('WH-CENTRAL-%d', $tenantId);
        $existingCentral = DB::table('warehouses')->where('tenant_id', $tenantId)->where('warehouse_code', $whCode)->first();
        if ($existingCentral) {
            $warehouseIds['CENTRAL'] = (int) $existingCentral->id;
        } else {
            $warehouseIds['CENTRAL'] = (int) DB::table('warehouses')->insertGetId([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'store_id' => null,
                'warehouse_code' => $whCode,
                'warehouse_name' => 'Gudang Pusat Distribusi & Logistik',
                'warehouse_type' => 'CENTRAL',
                'address' => 'Kawasan Industri Pulogadung Blok B, Jakarta Timur',
                'is_active' => 1,
                'created_at' => $this->config->startDate->subDays(20),
                'updated_at' => $this->config->startDate->subDays(20),
            ]);
        }

        foreach ($stores as $store) {
            $code = sprintf('WH-%s', $store['code']);
            $existing = DB::table('warehouses')->where('tenant_id', $tenantId)->where('warehouse_code', $code)->first();
            if ($existing) {
                $warehouseIds[$store['code']] = (int) $existing->id;
                continue;
            }

            $warehouseIds[$store['code']] = (int) DB::table('warehouses')->insertGetId([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'store_id' => $store['id'],
                'warehouse_code' => $code,
                'warehouse_name' => sprintf('Gudang Stok %s', $store['name']),
                'warehouse_type' => 'RETAIL',
                'address' => $store['name'] . ', ' . ($store['city'] ?? 'Jakarta'),
                'is_active' => 1,
                'created_at' => $store['opened_at'] ?? $this->config->startDate->subDays(10),
                'updated_at' => $store['opened_at'] ?? $this->config->startDate->subDays(10),
            ]);
        }

        return $warehouseIds;
    }

    private function ensureOperationalUnits(int $tenantId, array $stores, string $industry): array
    {
        $opUnits = [];
        $isFnb = $industry === 'fnb';

        $unitTemplates = $isFnb
            ? [
                ['code' => 'DINING', 'name' => 'Dining Floor & Tables', 'type' => 'fnb_dining', 'default' => 1],
                ['code' => 'BAR', 'name' => 'Bar & Beverage Counter', 'type' => 'fnb_bar', 'default' => 0],
                ['code' => 'KITCHEN', 'name' => 'Main Kitchen & Prep', 'type' => 'fnb_kitchen', 'default' => 0],
                ['code' => 'TAKEAWAY', 'name' => 'Takeaway & Pickup Counter', 'type' => 'takeaway', 'default' => 0],
            ]
            : [
                ['code' => 'CHECKOUT', 'name' => 'Cashier Checkout Counter', 'type' => 'checkout_retail', 'default' => 1],
                ['code' => 'SERVICE', 'name' => 'Customer Service & Returns', 'type' => 'service_desk', 'default' => 0],
                ['code' => 'PICKUP', 'name' => 'Omnichannel Order Pickup', 'type' => 'customer_pickup', 'default' => 0],
            ];

        foreach ($stores as $store) {
            foreach ($unitTemplates as $tmpl) {
                $code = sprintf('%s-%s', $store['code'], $tmpl['code']);
                $existing = DB::table('operational_units')
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $store['id'])
                    ->where('code', $code)
                    ->first();

                if ($existing) {
                    $opUnits[$store['id']][$tmpl['code']] = (int) $existing->id;
                    continue;
                }

                $opUnits[$store['id']][$tmpl['code']] = (int) DB::table('operational_units')->insertGetId([
                    'tenant_id' => $tenantId,
                    'store_id' => $store['id'],
                    'name' => sprintf('%s - %s', $store['name'], $tmpl['name']),
                    'code' => $code,
                    'unit_type' => $tmpl['type'],
                    'is_default' => $tmpl['default'],
                    'is_active' => 1,
                    'created_at' => $store['opened_at'] ?? $this->config->startDate->subDays(10),
                    'updated_at' => $store['opened_at'] ?? $this->config->startDate->subDays(10),
                ]);
            }
        }

        return $opUnits;
    }

    private function ensureCapabilities(int $tenantId, array $stores, string $industry): void
    {
        $isFnb = $industry === 'fnb';
        $coreCaps = ['core.pos_cashier', 'core.inventory_ledger', 'core.multi_branch'];
        $fnbCaps = ['fnb.table_management', 'fnb.kitchen_display', 'fnb.recipe_management', 'fnb.waiter_ordering'];
        $retailCaps = ['retail.barcode_scanning', 'retail.stock_transfer', 'retail.product_variants', 'retail.shipping_fulfillment'];

        $activeCaps = array_merge($coreCaps, $isFnb ? $fnbCaps : $retailCaps);

        foreach ($activeCaps as $cap) {
            $exists = DB::table('tenant_capabilities')->where('tenant_id', $tenantId)->where('capability_code', $cap)->exists();
            if (! $exists) {
                DB::table('tenant_capabilities')->insert([
                    'tenant_id' => $tenantId,
                    'capability_code' => $cap,
                    'is_enabled' => 1,
                    'configuration' => json_encode(['source' => 'industry_template', 'tier' => 'enterprise']),
                    'created_at' => $this->config->startDate->subDays(14),
                    'updated_at' => $this->config->startDate->subDays(14),
                ]);
            }

            foreach ($stores as $store) {
                $sExists = DB::table('store_capabilities')->where('tenant_id', $tenantId)->where('store_id', $store['id'])->where('capability_code', $cap)->exists();
                if (! $sExists) {
                    DB::table('store_capabilities')->insert([
                        'tenant_id' => $tenantId,
                        'store_id' => $store['id'],
                        'capability_code' => $cap,
                        'is_enabled' => 1,
                        'configuration' => null,
                        'created_at' => $store['opened_at'] ?? $this->config->startDate->subDays(10),
                        'updated_at' => $store['opened_at'] ?? $this->config->startDate->subDays(10),
                    ]);
                }
            }
        }
    }

    private function ensureUserStoreRoles(int $tenantId, array $stores, array $staff, array $owners, array $roles): void
    {
        $ownerRoleId = $roles['ROL-OWNER'] ?? array_values($roles)[0];

        foreach ($owners as $ownerId) {
            foreach ($stores as $store) {
                $exists = DB::table('user_store_roles')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $ownerId)
                    ->where('store_id', $store['id'])
                    ->where('role_id', $ownerRoleId)
                    ->exists();

                if (! $exists) {
                    DB::table('user_store_roles')->insert([
                        'tenant_id' => $tenantId,
                        'user_id' => $ownerId,
                        'store_id' => $store['id'],
                        'role_id' => $ownerRoleId,
                        'valid_from' => $this->config->startDate->subDays(30),
                        'valid_to' => null,
                        'is_active' => 1,
                        'created_at' => $this->config->startDate->subDays(30),
                        'updated_at' => $this->config->startDate->subDays(30),
                    ]);
                }
            }
        }

        foreach ($staff as $member) {
            $roleId = $roles[$member['role']] ?? null;
            if (! $roleId) {
                continue;
            }

            $exists = DB::table('user_store_roles')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $member['id'])
                ->where('store_id', $member['store_id'])
                ->where('role_id', $roleId)
                ->exists();

            if (! $exists) {
                DB::table('user_store_roles')->insert([
                    'tenant_id' => $tenantId,
                    'user_id' => $member['id'],
                    'store_id' => $member['store_id'],
                    'role_id' => $roleId,
                    'valid_from' => $member['hired_at'] ?? $this->config->startDate,
                    'valid_to' => null,
                    'is_active' => 1,
                    'created_at' => $member['hired_at'] ?? $this->config->startDate,
                    'updated_at' => $member['hired_at'] ?? $this->config->startDate,
                ]);
            }
        }
    }

    /**
     * @param  array<string,int>  $roles
     * @param  array<int,array{id:int,code:string,char:string,opened_at:CarbonImmutable}>  $stores
     * @return array<int, array{id:int,store_id:int,role:string,name:string,employee_id:?string,hired_at:CarbonImmutable,slot:int}>
     */
    private function ensureStaff(int $tenantId, array $account, array $roles, array $stores, SeededRandom $rng): array
    {
        $industry = (string) ($account['industry'] ?? 'fnb');
        $isFnb = $industry === 'fnb';
        $perStore = max(3, $this->config->volumeInt('employees_per_store', 7));
        $rolePlan = $isFnb
            ? [
                ['code' => 'ROL-MANAGER', 'label' => 'Manager'],
                ['code' => 'ROL-SUPERVISOR', 'label' => 'Supervisor'],
                ['code' => 'ROL-KASIR', 'label' => 'Kasir'],
                ['code' => 'ROL-KASIR', 'label' => 'Kasir'],
                ['code' => 'ROL-BARISTA', 'label' => 'Barista'],
                ['code' => 'ROL-COOK', 'label' => 'Cook'],
                ['code' => 'ROL-WAITER', 'label' => 'Waiter'],
                ['code' => 'ROL-INVENTORY', 'label' => 'Inventory'],
            ]
            : [
                ['code' => 'ROL-MANAGER', 'label' => 'Store Manager'],
                ['code' => 'ROL-SUPERVISOR', 'label' => 'Floor Supervisor'],
                ['code' => 'ROL-KASIR', 'label' => 'Kasir Retail'],
                ['code' => 'ROL-KASIR', 'label' => 'Kasir Retail'],
                ['code' => 'ROL-SALES', 'label' => 'Sales Associate'],
                ['code' => 'ROL-SALES', 'label' => 'Sales Associate'],
                ['code' => 'ROL-INVENTORY', 'label' => 'Staff Inventory Gudang'],
            ];

        while (count($rolePlan) < $perStore) {
            $rolePlan[] = $isFnb ? ['code' => 'ROL-KASIR', 'label' => 'Kasir'] : ['code' => 'ROL-SALES', 'label' => 'Sales Associate'];
        }
        $rolePlan = array_slice($rolePlan, 0, $perStore);

        $first = CatalogFixtures::firstNames();
        $last = CatalogFixtures::lastNames();
        $emailSuffix = Str::slug($account['tenant_slug']);
        $staff = [];
        $seq = 0;

        foreach ($stores as $storeIdx => $store) {
            foreach ($rolePlan as $slotIdx => $roleDef) {
                $seq++;
                $fn = $first[$rng->int(0, count($first) - 1)];
                $ln = $last[$rng->int(0, count($last) - 1)];
                $name = "{$fn} {$ln}";
                $email = sprintf('ds.staff.%s.%02d@example.invalid', substr($emailSuffix, 0, 12), $seq);
                $hiredAt = $store['opened_at']->subDays($rng->int(2, 30));

                // Individual distinct synthetic PIN per cashier/staff
                $staffPin = sprintf('%06d', (($seq * 17483 + 100000) % 900000) + 100000);
                $pinHash = Hash::make($staffPin);

                $id = $this->upsertUser($tenantId, [
                    'email' => $email,
                    'name' => $name,
                    'role_id' => $roles[$roleDef['code']] ?? array_values($roles)[0],
                    'password_hash' => Hash::make(Str::random(32)), // staf tidak dapat login biasa
                    'pin_hash' => $pinHash,
                    // Nomor HP unik per pegawai (unique index tenant+phone).
                    'phone_number' => sprintf('0813%04d%04d', (int) $seq % 10000, ($seq * 7 + (int) $store['id']) % 10000),
                    'created_at' => $hiredAt,
                ]);

                if (! DB::table('user_stores')->where('tenant_id', $tenantId)
                    ->where('user_id', $id)->where('store_id', $store['id'])->exists()) {
                    DB::table('user_stores')->insert([
                        'tenant_id' => $tenantId,
                        'user_id' => $id,
                        'store_id' => $store['id'],
                        'created_at' => $hiredAt,
                        'updated_at' => $hiredAt,
                    ]);
                }

                $staff[] = [
                    'id' => $id,
                    'store_id' => $store['id'],
                    'role' => $roleDef['code'],
                    'name' => $name,
                    'employee_id' => null,
                    'hired_at' => $hiredAt,
                    'slot' => $slotIdx,
                ];
            }
        }

        return $staff;
    }

    /**
     * @param  array<int, array{id:int,code:string,opened_at?:CarbonImmutable}>  $stores
     * @param  array<int, array<string, int>>  $opUnits
     * @return array<int, array{id:int,store_id:int,code:string}>
     */
    private function ensureDevices(int $tenantId, array $stores, array $opUnits, SeededRandom $rng): array
    {
        $devices = [];
        foreach ($stores as $storeIdx => $store) {
            $primaryOpUnit = $opUnits[$store['id']]['CHECKOUT'] ?? $opUnits[$store['id']]['DINING'] ?? null;

            foreach ([1, 2] as $n) {
                $code = sprintf('POS-%02d', $n);
                $existing = DB::table('devices')
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $store['id'])
                    ->where('device_code', $code)
                    ->first();

                if ($existing) {
                    $devices[] = ['id' => (int) $existing->id, 'store_id' => $store['id'], 'code' => $code];

                    continue;
                }

                $id = (int) DB::table('devices')->insertGetId([
                    'tenant_id' => $tenantId,
                    'store_id' => $store['id'],
                    'operational_unit_id' => $primaryOpUnit,
                    'device_code' => $code,
                    'device_name' => sprintf('%s POS %d', substr($store['code'], 0, 3), $n),
                    'device_type' => 'pos_terminal',
                    'pairing_token' => Str::random(48),
                    'pairing_token_expires_at' => $this->config->startDate->subDays(5),
                    'device_fingerprint' => hash('sha256', $tenantId.'|'.$store['id'].'|'.$code),
                    'is_active' => 1,
                    'status' => 'paired',
                    'last_seen_at' => $this->config->endDate,
                    'metadata' => json_encode(['source' => 'dataset-generator']),
                    'created_at' => $store['opened_at'] ?? $this->config->startDate,
                    'updated_at' => $this->config->endDate,
                ]);
                $devices[] = ['id' => $id, 'store_id' => $store['id'], 'code' => $code];
            }
        }

        return $devices;
    }

    private function ensureSettings(int $tenantId): void
    {
        $settings = [
            ['business_name', 'Apeiron POS Demo', 'string', 'Nama usaha'],
            ['default_sales_channel', 'DINE_IN', 'string', 'Kanal penjualan default'],
            ['tax_enabled', '1', 'boolean', 'Aktifkan pajak'],
            ['tax_rate', '0.11', 'string', 'Tarif PPN'],
            ['rounding_behavior', 'ROUND_NEAREST', 'string', 'Perilaku pembulatan'],
            ['rounding_value', '100', 'integer', 'Kelipatan pembulatan'],
            ['service_charge_rate', '0', 'string', 'Service charge'],
            ['receipt_width_mm', '58', 'integer', 'Lebar struk'],
        ];

        foreach ($settings as [$key, $value, $type, $desc]) {
            $exists = DB::table('settings')
                ->where('tenant_id', $tenantId)->where('key', $key)->exists();
            if ($exists) {
                continue;
            }
            DB::table('settings')->insert([
                'tenant_id' => $tenantId,
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'description' => $desc,
                'created_at' => $this->config->startDate->subDays(14),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Upsert user dengan memenuhi invariant NOT NULL (email_normalized, employee_id).
     *
     * @param  array<string,mixed>  $data
     */
    private function upsertUser(int $tenantId, array $data): int
    {
        $email = (string) $data['email'];
        $normalized = strtolower(trim($email));

        $existing = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('email_normalized', $normalized)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            if (! empty($data['password_hash'])) {
                DB::table('users')->where('id', $existing->id)->update([
                    'password' => $data['password_hash'],
                    'password_hash' => $data['password_hash'],
                    'updated_at' => now(),
                ]);
            }

            return (int) $existing->id;
        }

        // employee_id wajib unik global → gunakan generator deterministik.
        $employeeId = EmployeeIdGenerator::next($tenantId);

        $now = $data['created_at'] ?? now();

        return (int) DB::table('users')->insertGetId([
            'tenant_id' => $tenantId,
            'role_id' => $data['role_id'] ?? null,
            'employee_id' => $employeeId,
            'name' => $data['name'],
            'phone_number' => $data['phone_number'] ?? null,
            'email' => $email,
            'email_normalized' => $normalized,
            'email_verified_at' => $now,
            'password' => $data['password_hash'] ?? null,
            'password_hash' => $data['password_hash'] ?? null,
            'pin_hash' => $data['pin_hash'] ?? null,
            'is_active' => 1,
            'join_date' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
