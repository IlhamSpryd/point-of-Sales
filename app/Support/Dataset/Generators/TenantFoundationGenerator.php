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

        if (! $tenant) {
            $tenantId = $this->writer->insertGetId('tenants', [
                'name' => $account['tenant_name'],
                'slug' => $account['tenant_slug'],
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

        // ── 3. Roles (by role_code, bukan ID) ────────────────────────
        $roles = $this->ensureRoles($tenantId);

        // ── 4. Stores ────────────────────────────────────────────────
        $stores = $this->ensureStores($tenantId, $account, $rng);

        // ── 5. Owner accounts (akun demo) ────────────────────────────
        $owners = $this->ensureOwners($tenantId, $account, $roles, $stores);

        // ── 6. Staff per store ───────────────────────────────────────
        $staff = $this->ensureStaff($tenantId, $account, $roles, $stores, $rng);

        // ── 7. Devices per store ─────────────────────────────────────
        $devices = $this->ensureDevices($tenantId, $stores, $rng);

        // ── 8. Settings baseline ─────────────────────────────────────
        $this->ensureSettings($tenantId);

        // ── 9. user_stores pivot untuk owner ─────────────────────────
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

    /**
     * @param  array<string,int>  $roles
     * @param  array<int,array{id:int,code:string,char:string,opened_at:CarbonImmutable}>  $stores
     * @return array<int, array{id:int,store_id:int,role:string,name:string,employee_id:?string,hired_at:CarbonImmutable,slot:int}>
     */
    private function ensureStaff(int $tenantId, array $account, array $roles, array $stores, SeededRandom $rng): array
    {
        $perStore = max(3, $this->config->volumeInt('employees_per_store', 7));
        $rolePlan = [
            ['code' => 'ROL-MANAGER', 'label' => 'Manager'],
            ['code' => 'ROL-SUPERVISOR', 'label' => 'Supervisor'],
            ['code' => 'ROL-KASIR', 'label' => 'Kasir'],
            ['code' => 'ROL-KASIR', 'label' => 'Kasir'],
            ['code' => 'ROL-BARISTA', 'label' => 'Barista'],
            ['code' => 'ROL-COOK', 'label' => 'Cook'],
            ['code' => 'ROL-WAITER', 'label' => 'Waiter'],
            ['code' => 'ROL-INVENTORY', 'label' => 'Inventory'],
        ];
        while (count($rolePlan) < $perStore) {
            $rolePlan[] = ['code' => 'ROL-KASIR', 'label' => 'Kasir'];
        }
        $rolePlan = array_slice($rolePlan, 0, $perStore);

        $first = CatalogFixtures::firstNames();
        $last = CatalogFixtures::lastNames();
        $pinHash = Hash::make($this->config->cashierPin);
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

                $id = $this->upsertUser($tenantId, [
                    'email' => $email,
                    'name' => $name,
                    'role_id' => $roles[$roleDef['code']],
                    'password_hash' => Hash::make(Str::random(32)), // staf tidak dapat login
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
     * @return array<int, array{id:int,store_id:int,code:string}>
     */
    private function ensureDevices(int $tenantId, array $stores, SeededRandom $rng): array
    {
        $devices = [];
        foreach ($stores as $storeIdx => $store) {
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
