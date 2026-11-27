<?php

namespace Database\Seeders;

use App\Core\Context\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Tier1DemoSeeder — Deterministik, realistis, dan cepat (< 6 s) untuk demo & Playwright E2E.
 *
 * Blueprint: `docs/audit/APEIRON_WAVE10_TIER1_ARCHITECTURE_AND_BC_AUDIT.md` §3.3.
 *
 * Karakteristik:
 *  - Deterministik (mt_srand 20261126), single `Hash::make()` call untuk semua user demo.
 *  - 2 tenant x 2 branch (Tenant A flagship multi-branch, Tenant B isolation canary).
 *  - Stok otoritatif di `product_stock_balances` / `ingredient_stock_balances` per branch.
 *  - 30 hari transaksi (~900 order), shift aktif + 60 shift tertutup, ledger loyalitas
 *    dengan rantai SHA-256 utuh (tanpa drop trigger), 2 accounting period, dan 1 payroll batch.
 *  - Safety Guard: menolak berjalan pada database berakhiran `_test` (mis. `yovel_pos_test`)
 *    kecuali di-opt-in eksplisit via `TIER1_SEEDER_ALLOW_TEST_DB=true`.
 *
 * Usage: php artisan db:seed --class=Tier1DemoSeeder
 */
class Tier1DemoSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'password123';

    public const DETERMINISTIC_SEED = 20261126;

    private string $hash = '';

    private string $defaultPinHash = '';

    private string $supervisorPinHash = '';

    /** @var array<string, int> */
    private array $counts = [];

    public function run(): void
    {
        $this->assertSafeDatabase();

        mt_srand(self::DETERMINISTIC_SEED);

        $startedAt = microtime(true);

        DB::connection()->disableQueryLog();

        // Hitung bcrypt hash minimal untuk menjaga SLA seeding < 10 detik.
        $this->hash = Hash::make(self::DEMO_PASSWORD);
        $this->defaultPinHash = password_hash('123456', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->supervisorPinHash = password_hash('135790', PASSWORD_BCRYPT, ['cost' => 4]);

        $context = app(TenantContext::class);
        $previous = [
            'tenant' => $context->getTenantId(),
            'company' => $context->getCompanyId(),
            'store' => $context->getStoreId(),
        ];

        try {
            $tenantA = $this->seedTenantA($context);
            $this->seedTenantB($context);
        } finally {
            if ($previous['tenant'] !== null) {
                $context->setTenantId((int) $previous['tenant']);
                $context->setCompanyId($previous['company'] !== null ? (int) $previous['company'] : null);
                $context->setStoreId($previous['store'] !== null ? (int) $previous['store'] : null);
            } else {
                $context->clear();
            }
        }

        $elapsed = round(microtime(true) - $startedAt, 2);

        if ($this->command !== null) {
            $this->command->info('✓ Tier1DemoSeeder selesai dalam '.$elapsed.'s (Tenant A #'.$tenantA['tenant'].')');
            $this->command->table(
                ['Tabel', 'Baris'],
                collect($this->counts)->map(fn (int $c, string $t): array => [$t, number_format($c)])->values()->all()
            );
        }
    }

    /**
     * Safety Guard: cegah kontaminasi database PHPUnit.
     */
    private function assertSafeDatabase(): void
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (str_ends_with($database, '_test') && ! $this->isOptedInForTestDatabase()) {
            throw new \RuntimeException(
                "Tier1DemoSeeder menolak berjalan pada database uji [{$database}]. Gunakan yovel_pos_demo / yovel_pos_e2e, atau opt-in eksplisit."
            );
        }

        if (! app()->environment(['local', 'testing', 'e2e'])) {
            throw new \RuntimeException('Tier1DemoSeeder hanya boleh berjalan di environment local/testing/e2e.');
        }
    }

    private function isOptedInForTestDatabase(): bool
    {
        return (bool) config('pos.allow_tier1_demo_test_db', false);
    }

    /**
     * @return array{tenant: int, stores: array<string, int>, users: array<string, int>}
     */
    private function seedTenantA(TenantContext $context): array
    {
        $now = now();
        $tenantId = $this->insertAndGetId('tenants', [
            'name' => 'Kopi Senja Nusantara',
            'slug' => 'kopi-senja',
            'status' => 'active',
            'onboarding_status' => 'completed',
            'onboarding_completed_at' => $now,
            'access_status' => 'active',
            'subscription_status' => 'active',
            'subscription_plan' => 'enterprise',
            'country_code' => 'ID',
            'primary_industry' => 'fnb',
            'business_subtype' => 'cafe',
            'readiness_status' => 'ready',
            'config_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $context->setTenantId($tenantId);

        $companyId = $this->insertAndGetId('companies', [
            'tenant_id' => $tenantId,
            'company_code' => 'KS-CMP-01',
            'company_name' => 'PT Kopi Senja Nusantara',
            'legal_name' => 'PT Kopi Senja Nusantara',
            'npwp' => '01.234.567.8-901.000',
            'tax_address' => 'Jl. Sunda No. 1, Jakarta Pusat',
            'is_active' => true,
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $context->setCompanyId($companyId);

        $storeJkt = $this->insertAndGetId('stores', [
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'name' => 'Flagship Jakarta',
            'code' => 'STR-JKT-01',
            'timezone' => 'Asia/Jakarta',
            'store_vertical' => 'fnb',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'pos_cashier',
            'phone' => '021-5550101',
            'is_active' => true,
            'is_initial' => true,
            'address' => 'Jl. Sunda No. 1, Jakarta Pusat',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $storeBdg = $this->insertAndGetId('stores', [
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'name' => 'Cabang Bandung',
            'code' => 'STR-BDG-02',
            'timezone' => 'Asia/Jakarta',
            'store_vertical' => 'fnb',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'pos_cashier',
            'phone' => '022-5550202',
            'is_active' => true,
            'is_initial' => false,
            'address' => 'Jl. Braga No. 22, Bandung',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $context->setStoreId($storeJkt);

        $roles = $this->seedRoles($tenantId, $now);
        $users = $this->seedUsers($tenantId, $storeJkt, $roles, $now, 'tenant-a', [$storeJkt, $storeBdg]);
        $this->seedSubscriptionAndSettings($tenantId, 'enterprise', $now);
        $posDeviceId = $this->seedDevices($tenantId, $storeJkt, $now);
        $this->seedTablesAndDiscounts($tenantId, $storeJkt, $now);
        $this->seedCashBankAccounts($tenantId, $storeJkt, $now);

        $catalog = $this->seedCatalog($tenantId, $users['admin'], $now);
        $this->seedBalances($tenantId, $storeJkt, $storeBdg, $catalog, $now);

        $customers = $this->seedCustomers($tenantId, $now);
        $shiftOpen = $this->seedOpenShift($tenantId, $storeJkt, $posDeviceId, $users['kasir'], $now);

        $periods = $this->seedAccountingPeriods($tenantId, $now);
        $this->seedThirtyDayOrders($tenantId, $storeJkt, $storeBdg, $shiftOpen, $posDeviceId, $users, $catalog, $customers, $now);

        $this->seedLoyaltyLedger($tenantId, $storeJkt, $customers, $now);
        $this->seedPayroll($tenantId, $companyId, $storeJkt, 'Flagship Jakarta', $periods['open'], $users, $now);

        return [
            'tenant' => $tenantId,
            'stores' => ['jkt' => $storeJkt, 'bdg' => $storeBdg],
            'users' => $users,
        ];
    }

    private function seedTenantB(TenantContext $context): void
    {
        $now = now();

        $tenantId = $this->insertAndGetId('tenants', [
            'name' => 'Warung Mba Rina',
            'slug' => 'warung-mba-rina',
            'status' => 'active',
            'onboarding_status' => 'completed',
            'onboarding_completed_at' => $now,
            'access_status' => 'active',
            'subscription_status' => 'active',
            'subscription_plan' => 'starter',
            'country_code' => 'ID',
            'primary_industry' => 'retail',
            'business_subtype' => 'grocery',
            'readiness_status' => 'ready',
            'config_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $context->setTenantId($tenantId);

        $companyId = $this->insertAndGetId('companies', [
            'tenant_id' => $tenantId,
            'company_code' => 'WMR-CMP-01',
            'company_name' => 'Warung Mba Rina',
            'legal_name' => 'Warung Mba Rina',
            'is_active' => true,
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $context->setCompanyId($companyId);

        $storeId = $this->insertAndGetId('stores', [
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'name' => 'Warung Mba Rina Pusat',
            'code' => 'STR-WMR-01',
            'timezone' => 'Asia/Jakarta',
            'store_vertical' => 'retail',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'pos_cashier',
            'is_active' => true,
            'is_initial' => true,
            'address' => 'Jl. Malioboro No. 7, Yogyakarta',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $context->setStoreId($storeId);

        $roles = $this->seedRoles($tenantId, $now);
        $ownerId = $this->seedUsers($tenantId, $storeId, $roles, $now, 'tenant-b', [$storeId])['admin'];
        $this->seedSubscriptionAndSettings($tenantId, 'starter', $now);

        $categoryId = $this->insertAndGetId('categories', [
            'tenant_id' => $tenantId,
            'category_name' => 'Sembako',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $firstTenantBProductId = 0;
        foreach ([['Beras 5kg', 68000], ['Minyak Goreng 2L', 38000]] as $index => [$name, $price]) {
            $productId = $this->insertAndGetId('products', [
                'tenant_id' => $tenantId,
                'category_id' => $categoryId,
                'product_code' => 'WMR-'.($index + 1),
                'product_name' => $name,
                'product_price' => $price,
                'stock' => 50,
                'track_inventory' => true,
                'is_active' => true,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($index === 0) {
                $firstTenantBProductId = $productId;
            }

            $this->insertRow('product_stock_balances', [
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'warehouse_id' => null,
                'product_id' => $productId,
                'quantity' => 50,
                'reserved_quantity' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $tenantBOrderId = $this->insertAndGetId('orders', [
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'user_id' => $ownerId,
            'order_code' => 'WMR-DEMO-0001',
            'idempotency_key' => 'tier1-demo-tenant-b-order-1',
            'order_date' => $now->toDateString(),
            'order_type' => 'takeaway',
            'origin_channel' => 'pos_cashier',
            'order_channel' => 'pos_cashier',
            'subtotal_amount' => 68000,
            'dpp_amount' => 68000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'rounding_adjustment' => 0,
            'order_amount' => 68000,
            'cash_received' => 68000,
            'order_change' => 0,
            'order_status' => 'paid',
            'order_lifecycle_status' => 'confirmed',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'payment_method' => 'cash',
            'payment_timing' => 'paid',
            'created_at' => $now->copy()->subDay(),
            'updated_at' => $now->copy()->subDay(),
        ]);

        $this->insertRow('order_items', [
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'order_id' => $tenantBOrderId,
            'product_id' => $firstTenantBProductId,
            'product_name_snapshot' => 'Beras 5kg',
            'sku_snapshot' => 'WMR-1',
            'qty' => 1,
            'order_price' => 68000,
            'order_subtotal' => 68000,
            'hpp_snapshot' => 0,
            'net_line_total' => 68000,
            'item_discount_amount' => 0,
            'allocated_order_discount_amount' => 0,
            'options' => json_encode([]),
            'preparation_status' => 'ready',
            'created_at' => $now->copy()->subDay(),
            'updated_at' => $now->copy()->subDay(),
        ]);

        $this->insertRow('accounting_periods', [
            'tenant_id' => $tenantId,
            'period_code' => $now->format('Y-m'),
            'fiscal_year' => (int) $now->format('Y'),
            'period_number' => (int) $now->format('n'),
            'start_date' => $now->copy()->startOfMonth()->toDateString(),
            'end_date' => $now->copy()->endOfMonth()->toDateString(),
            'status' => 'OPEN',
            'is_adjustment_period' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->counts['tenant_b_owner'] = $ownerId;
        $this->counts['tenant_b_sample_order'] = $tenantBOrderId;
    }

    /**
     * @return array<string, int>
     */
    private function seedRoles(int $tenantId, \Carbon\CarbonInterface $now): array
    {
        $definitions = [
            'Owner' => ['ROLE-OWN', ['*'], true, 'executive'],
            'Manager' => ['ROL-MANAGER', ['dashboard', 'reports', 'reports.*', 'orders.*', 'users.view', 'shifts.*', 'inventory.*', 'purchasing.*', 'customers.*', 'products.*', 'pricing.*', 'discounts.*', 'finance.*', 'hr.*', 'pos.price.open_entry', 'pos.discount.manual_apply'], true, 'executive'],
            'Kasir' => ['ROL-KASIR', ['orders.create', 'orders.view', 'payments.create', 'shifts.own', 'tables.view'], true, 'cashier'],
            'Supervisor' => ['ROL-SUPERVISOR', ['orders.*', 'shifts.*', 'reports.daily', 'void.approve', 'discount.approve', 'pos.discount.override_approve', 'pos.price.open_entry'], true, 'cashier'],
            'Finance' => ['ROL-FINANCE', ['finance.*', 'sales.orders.*', 'sales.invoices.*', 'reports.*', 'orders.view'], true, 'finance'],
            'Cook' => ['ROL-COOK', ['orders.view', 'kds.view', 'kds.update', 'kds.access'], true, 'kitchen'],
            'FloorLead' => ['ROL-FLOORLEAD', ['dashboard', 'orders.view', 'orders.create', 'customers.view'], false, 'branch_ops'],
        ];

        $roles = [];
        foreach ($definitions as $name => [$roleCode, $permissions, $isSystemDefault, $archetype]) {
            $roles[$name] = $this->insertAndGetId('roles', [
                'tenant_id' => $tenantId,
                'role_code' => $roleCode,
                'name' => $name,
                'description' => $name.' role',
                'permissions' => json_encode($permissions),
                'dashboard_archetype' => $archetype,
                'is_system_default' => $isSystemDefault,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $roles;
    }

    /**
     * @param  array<string, int>  $roles
     * @param  array<int, int>  $storeIds
     * @return array<string, int>
     */
    private function seedUsers(int $tenantId, int $primaryStoreId, array $roles, \Carbon\CarbonInterface $now, string $suffix = 'tenant-a', array $storeIds = []): array
    {
        $definitions = [
            'admin' => ['Owner', 'admin@pos.test', 'Ayu Prameswari'],
            'manager' => ['Manager', 'manager@pos.test', 'Bagas Wicaksono'],
            'kasir' => ['Kasir', 'kasir@pos.test', 'Citra Maharani'],
            'kasir2' => ['Kasir', 'kasir2@pos.test', 'Dimas Saputra'],
            'supervisor' => ['Supervisor', 'supervisor@pos.test', 'Eka Pratiwi'],
            'finance' => ['Finance', 'finance@pos.test', 'Fajar Nugroho'],
            'cook' => ['Cook', 'cook@pos.test', 'Gita Lestari'],
            'staff' => ['FloorLead', 'staff@pos.test', 'Hana Kartika'],
            'selforder' => ['Kasir', 'selforder@system.local', 'Self-Order Kiosk'],
        ];

        $multiBranchKeys = ['admin', 'manager', 'supervisor', 'finance'];
        $users = [];
        $sequence = 1;
        foreach ($definitions as $key => [$roleName, $email, $name]) {
            $email = $suffix === 'tenant-b' ? str_replace('@pos.test', '.b@pos.test', $email) : $email;
            if ($suffix === 'tenant-b' && $key === 'selforder') {
                $email = 'selforder.b@system.local';
            }

            $users[$key] = $this->insertAndGetId('users', [
                'tenant_id' => $tenantId,
                'employee_id' => sprintf('YVL-EMP-%d-%04d', $tenantId, $sequence++),
                'name' => $name,
                'email' => $email,
                'email_normalized' => strtolower($email),
                'email_verified_at' => $now,
                'password' => $this->hash,
                'password_hash' => $this->hash,
                'role_id' => $roles[$roleName],
                'pin_hash' => $key === 'supervisor' ? $this->supervisorPinHash : $this->defaultPinHash,
                'join_date' => $now->copy()->subYear()->toDateString(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $assignedStores = ($storeIds !== [] && in_array($key, $multiBranchKeys, true))
                ? $storeIds
                : [$primaryStoreId];

            foreach ($assignedStores as $assignedStoreId) {
                DB::table('user_stores')->insert([
                    'tenant_id' => $tenantId,
                    'user_id' => $users[$key],
                    'store_id' => $assignedStoreId,
                ]);
            }
        }

        return $users;
    }

    private function seedSubscriptionAndSettings(int $tenantId, string $planCode, \Carbon\CarbonInterface $now): void
    {
        $planId = DB::table('plans')->where('code', $planCode)->value('id')
            ?? DB::table('plans')->orderBy('id')->value('id');

        if ($planId !== null) {
            $this->insertRow('tenant_subscriptions', [
                'tenant_id' => $tenantId,
                'plan_id' => (int) $planId,
                'status' => 'active',
                'starts_at' => $now->copy()->subMonth(),
                'ends_at' => $now->copy()->addYear(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $settings = [
            ['tax_rate', '0.11', 'float'],
            ['service_charge_rate', '0', 'float'],
            ['rounding_behavior', 'ROUND_NEAREST', 'string'],
            ['rounding_value', '100', 'integer'],
            ['receipt_width_mm', '58', 'integer'],
        ];

        foreach ($settings as [$key, $value, $type]) {
            $this->insertRow('settings', [
                'tenant_id' => $tenantId,
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        (new ChartOfAccountsSeeder)->seedForTenant($tenantId);
    }

    private function seedTablesAndDiscounts(int $tenantId, int $storeId, \Carbon\CarbonInterface $now): void
    {
        foreach (['T01', 'T02', 'T03', 'T04'] as $idx => $code) {
            $this->insertRow('tables', [
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'table_code' => $code,
                'table_name' => 'Meja '.($idx + 1),
                'capacity' => $idx < 2 ? 2 : 4,
                'area' => $idx < 2 ? 'Indoor' : 'Outdoor',
                'is_active' => true,
                'operational_status' => 'available',
                'secure_token' => sprintf('00000000-0000-4000-8000-%012d', $storeId * 100 + $idx + 1),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->insertRow('discounts', [
            'tenant_id' => $tenantId,
            'store_id' => null,
            'code' => 'SENJA10',
            'name' => 'Promo Kopi Senja 10%',
            'type' => 'percentage',
            'value' => 10,
            'max_discount_amount' => 50000,
            'min_purchase_amount' => 30000,
            'is_stackable' => false,
            'scope' => 'order',
            'is_active' => true,
            'valid_from' => $now->copy()->subMonth(),
            'valid_until' => $now->copy()->addYear(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedDevices(int $tenantId, int $storeId, \Carbon\CarbonInterface $now): int
    {
        $devices = [
            ['POS-JKT-01', 'Kasir Utama', 'pos_terminal'],
            ['POS-JKT-02', 'Kasir Bar', 'pos_terminal'],
            ['POS-JKT-03', 'Kasir Takeaway', 'pos_terminal'],
            ['EDC-JKT-01', 'EDC BCA', 'edc'],
        ];

        $firstPosDeviceId = 0;
        foreach ($devices as [$code, $name, $type]) {
            $deviceId = $this->insertAndGetId('devices', [
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'device_code' => $code,
                'device_name' => $name,
                'device_type' => $type,
                'is_active' => true,
                'status' => 'active',
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($firstPosDeviceId === 0) {
                $firstPosDeviceId = $deviceId;
            }
        }

        return $firstPosDeviceId;
    }

    private function seedCashBankAccounts(int $tenantId, int $storeId, \Carbon\CarbonInterface $now): void
    {
        $coaMap = DB::table('chart_of_accounts')
            ->where('tenant_id', $tenantId)
            ->whereIn('account_code', ['1101', '1110'])
            ->pluck('id', 'account_code')
            ->all();

        $accounts = [
            ['CASH-JKT', 'Kas Kecil Jakarta', 'PETTY_CASH', '1101', null, null, null],
            ['BANK-JKT', 'BCA Operasional', 'BANK_ACCOUNT', '1110', 'BCA', '1234567890', 'PT Kopi Senja Nusantara'],
        ];

        foreach ($accounts as [$code, $name, $type, $coaCode, $bankName, $bankNumber, $bankHolder]) {
            $this->insertRow('cash_bank_accounts', [
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'account_code' => $code,
                'account_name' => $name,
                'account_type' => $type,
                'gl_account_id' => (int) ($coaMap[$coaCode] ?? 0),
                'bank_name' => $bankName,
                'bank_account_number' => $bankNumber,
                'bank_account_holder' => $bankHolder,
                'currency_code' => 'IDR',
                'opening_balance' => 5000000,
                'current_balance' => 5000000,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array{products: array<int, array{id: int, name: string, price: int, track: bool}>, ingredients: array<string, int>}
     */
    private function seedCatalog(int $tenantId, int $ownerId, \Carbon\CarbonInterface $now): array
    {
        $categories = [];
        foreach (['Kopi', 'Non-Kopi', 'Makanan', 'Retail'] as $name) {
            $categories[$name] = $this->insertAndGetId('categories', [
                'tenant_id' => $tenantId,
                'category_name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $ingredientDefs = [
            ['ING-BJI-KOPI', 'Biji Kopi Arabika', 'gram', 5000, 250],
            ['ING-SUSU', 'Susu Segar', 'ml', 12000, 20],
            ['ING-GULA', 'Gula Aren', 'gram', 4000, 18],
            ['ING-MATCHA', 'Matcha Powder', 'gram', 1200, 320],
            ['ING-TEPUNG', 'Tepung Terigu', 'gram', 8000, 14],
        ];

        $ingredients = [];
        foreach ($ingredientDefs as [$code, $name, $unit, $stock, $cost]) {
            $ingredients[$code] = $this->insertAndGetId('ingredients', [
                'tenant_id' => $tenantId,
                'ingredient_code' => $code,
                'name' => $name,
                'unit' => $unit,
                'current_stock' => $stock,
                'cost_per_unit' => $cost,
                'reorder_level' => 1000,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 16 item F&B (11 berbasis BOM resep), 6 item retail SKU, 1 open-price, 1 nonaktif.
        $fnb = [
            ['KOPI-SUSU', 'Kopi Susu Senja', 24000, 'Kopi'],
            ['AMERICANO', 'Americano', 20000, 'Kopi'],
            ['CAPPUCCINO', 'Cappuccino', 28000, 'Kopi'],
            ['LATTE', 'Cafe Latte', 26000, 'Kopi'],
            ['ESPRESSO', 'Espresso', 18000, 'Kopi'],
            ['MATCHA-LATTE', 'Matcha Latte', 30000, 'Non-Kopi'],
            ['CHOCO-ICE', 'Chocolate Ice', 27000, 'Non-Kopi'],
            ['LEMON-TEA', 'Lemon Tea', 22000, 'Non-Kopi'],
            ['NASGOR', 'Nasi Goreng Senja', 35000, 'Makanan'],
            ['MIEGOR', 'Mie Goreng Spesial', 33000, 'Makanan'],
            ['AYAM-GEPREK', 'Ayam Geprek', 32000, 'Makanan'],
            ['ROTI-BAKAR', 'Roti Bakar Keju', 25000, 'Makanan'],
            ['PISANG-GORENG', 'Pisang Goreng', 21000, 'Makanan'],
            ['KENTANG', 'Kentang Goreng', 23000, 'Makanan'],
            ['SANDWICH', 'Sandwich Telur', 29000, 'Makanan'],
            ['AIR-MINERAL', 'Air Mineral', 8000, 'Non-Kopi'],
        ];

        $products = [];
        $sequence = 1;
        foreach ($fnb as [$code, $name, $price, $category]) {
            $productId = $this->insertAndGetId('products', [
                'tenant_id' => $tenantId,
                'category_id' => $categories[$category],
                'product_code' => $code,
                'product_name' => $name,
                'product_price' => $price,
                'stock' => 0,
                'track_inventory' => false,
                'is_open_price' => false,
                'is_active' => true,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $products[] = ['id' => $productId, 'name' => $name, 'price' => $price, 'track' => false];

            if ($sequence <= 11) {
                $recipe = match ($code) {
                    'KOPI-SUSU' => [['ING-BJI-KOPI', 18], ['ING-SUSU', 150], ['ING-GULA', 20]],
                    'AMERICANO', 'ESPRESSO' => [['ING-BJI-KOPI', 18]],
                    'CAPPUCCINO', 'LATTE' => [['ING-BJI-KOPI', 18], ['ING-SUSU', 160]],
                    'MATCHA-LATTE' => [['ING-MATCHA', 12], ['ING-SUSU', 160]],
                    'ROTI-BAKAR', 'SANDWICH' => [['ING-TEPUNG', 90]],
                    default => [['ING-TEPUNG', 60]],
                };

                foreach ($recipe as [$ingredientCode, $qty]) {
                    $this->insertRow('product_ingredients', [
                        'tenant_id' => $tenantId,
                        'product_id' => $productId,
                        'ingredient_id' => $ingredients[$ingredientCode],
                        'quantity_required' => $qty,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $sequence++;
        }

        $retail = [
            ['RT-BEANS-250', 'House Blend Beans 250g', 95000],
            ['RT-DRIPBOX', 'Drip Bag Box', 65000],
            ['RT-TUMBLER', 'Tumbler Senja', 150000],
            ['RT-TOTE', 'Tote Bag Senja', 85000],
            ['RT-MUG', 'Mug Keramik', 75000],
            ['RT-BEANS-1K', 'House Blend Beans 1kg', 320000],
        ];

        foreach ($retail as $index => [$code, $name, $price]) {
            $barcode = '8990001000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $productId = $this->insertAndGetId('products', [
                'tenant_id' => $tenantId,
                'category_id' => $categories['Retail'],
                'product_code' => $code,
                'barcode' => $barcode,
                'product_name' => $name,
                'product_price' => $price,
                'stock' => 0,
                'track_inventory' => true,
                'is_open_price' => false,
                'is_active' => true,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($index < 2) {
                $this->insertRow('product_variants', [
                    'tenant_id' => $tenantId,
                    'product_id' => $productId,
                    'variant_name' => 'Standard Pack',
                    'sku' => $code.'-STD',
                    'barcode' => $barcode,
                    'price_override' => $price,
                    'is_active' => true,
                    'sort_order' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $products[] = ['id' => $productId, 'name' => $name, 'price' => $price, 'track' => true];
        }

        // Open-price item (1) + nonaktif item (1)
        $products[] = ['id' => $this->insertAndGetId('products', [
            'tenant_id' => $tenantId,
            'category_id' => $categories['Makanan'],
            'product_code' => 'OPEN-PRICE',
            'product_name' => 'Menu Custom (Open Price)',
            'product_price' => 0,
            'stock' => 0,
            'track_inventory' => false,
            'is_open_price' => true,
            'is_active' => true,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]), 'name' => 'Menu Custom (Open Price)', 'price' => 0, 'track' => false];

        $products[] = ['id' => $this->insertAndGetId('products', [
            'tenant_id' => $tenantId,
            'category_id' => $categories['Non-Kopi'],
            'product_code' => 'DISCONTINUED',
            'product_name' => 'Menu Discontinued',
            'product_price' => 19000,
            'stock' => 0,
            'track_inventory' => false,
            'is_open_price' => false,
            'is_active' => false,
            'status' => 'inactive',
            'created_at' => $now,
            'updated_at' => $now,
        ]), 'name' => 'Menu Discontinued', 'price' => 19000, 'track' => false];

        // 4 ModifierGroups (Ukuran, Ice Level, Gula, Extra) + tautkan ke 6 produk kopi/non-kopi pertama.
        $groups = [
            'Ukuran' => [['Regular', 0], ['Large', 5000]],
            'Ice Level' => [['Normal Ice', 0], ['Less Ice', 0], ['No Ice', 0]],
            'Gula' => [['Normal', 0], ['Less Sugar', 0], ['No Sugar', 0]],
            'Extra' => [['Extra Shot', 8000], ['Oat Milk', 6000]],
        ];
        $groupIds = [];
        foreach ($groups as $groupName => $modifiers) {
            $groupId = $this->insertAndGetId('modifier_groups', [
                'tenant_id' => $tenantId,
                'name' => $groupName,
                'selection_type' => $groupName === 'Extra' ? 'multiple' : 'single',
                'is_required' => false,
                'min_select' => 0,
                'max_select' => $groupName === 'Extra' ? 2 : 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $groupIds[] = $groupId;

            foreach ($modifiers as $modIndex => [$modName, $extra]) {
                $this->insertRow('modifiers', [
                    'tenant_id' => $tenantId,
                    'modifier_group_id' => $groupId,
                    'name' => $modName,
                    'extra_price' => $extra,
                    'is_default' => $groupName !== 'Extra' && $modIndex === 0,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (array_slice($products, 0, 6) as $fnbProduct) {
            foreach ($groupIds as $groupId) {
                $this->insertRow('modifier_group_product', [
                    'tenant_id' => $tenantId,
                    'product_id' => $fnbProduct['id'],
                    'modifier_group_id' => $groupId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->counts['products'] = count($products);
        $this->counts['ingredients'] = count($ingredients);

        return ['products' => $products, 'ingredients' => $ingredients];
    }

    /**
     * @param  array{products: array<int, array{id: int, name: string, price: int, track: bool}>, ingredients: array<string, int>}  $catalog
     */
    private function seedBalances(int $tenantId, int $storeJkt, int $storeBdg, array $catalog, \Carbon\CarbonInterface $now): void
    {
        // Stok per-branch BERBEDA (bukan hasil kloning stok global). 2 item retail terakhir low-stock di JKT.
        $trackedIndex = 0;
        foreach ($catalog['products'] as $product) {
            if (! $product['track']) {
                continue;
            }

            $jktQty = $trackedIndex >= 4 ? 3 : 42 + $trackedIndex;
            $bdgQty = 15 + ($trackedIndex * 3);

            $this->insertRow('product_stock_balances', [
                'tenant_id' => $tenantId,
                'store_id' => $storeJkt,
                'warehouse_id' => null,
                'product_id' => $product['id'],
                'quantity' => $jktQty,
                'reserved_quantity' => 0,
                'min_stock_level' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->insertRow('product_stock_balances', [
                'tenant_id' => $tenantId,
                'store_id' => $storeBdg,
                'warehouse_id' => null,
                'product_id' => $product['id'],
                'quantity' => $bdgQty,
                'reserved_quantity' => 0,
                'min_stock_level' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('products')
                ->where('tenant_id', $tenantId)
                ->where('id', $product['id'])
                ->update(['stock' => $jktQty]);

            $trackedIndex++;
        }

        foreach ($catalog['ingredients'] as $ingredientCode => $ingredientId) {
            $jktQty = $ingredientCode === 'ING-MATCHA' ? 200 : 5000;
            $bdgQty = $ingredientCode === 'ING-MATCHA' ? 1500 : 2500;

            foreach ([[$storeJkt, $jktQty], [$storeBdg, $bdgQty]] as [$storeId, $qty]) {
                $this->insertRow('ingredient_stock_balances', [
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'ingredient_id' => $ingredientId,
                    'quantity' => $qty,
                    'reserved_quantity' => 0,
                    'min_stock_level' => 1000,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private function seedCustomers(int $tenantId, \Carbon\CarbonInterface $now): array
    {
        $tiers = [];
        foreach ([['Bronze', 0], ['Silver', 500], ['Gold', 2000]] as [$name, $min]) {
            $tiers[$name] = $this->insertAndGetId('loyalty_tiers', [
                'tenant_id' => $tenantId,
                'tier_code' => strtoupper(substr($name, 0, 3)),
                'name' => $name,
                'min_points' => $min,
                'points_multiplier' => 1.0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $customers = [];
        $names = ['Hendra', 'Indah', 'Joko', 'Kirana', 'Lukman', 'Maya', 'Nanda', 'Oki', 'Putri', 'Rizky', 'Sari', 'Teguh'];
        foreach ($names as $index => $name) {
            $customers[] = $this->insertAndGetId('customers', [
                'tenant_id' => $tenantId,
                'customer_code' => 'CUST-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'name' => $name,
                'customer_type' => 'individual',
                'phone' => '0812'.str_pad((string) (300000 + $index), 8, '0', STR_PAD_LEFT),
                'email' => strtolower($name).'@customer.test',
                'join_date' => $now->copy()->subMonths(6)->toDateString(),
                'is_active' => true,
                'is_loyalty_eligible' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $customers;
    }

    private function seedOpenShift(int $tenantId, int $storeId, int $deviceId, int $kasirUserId, \Carbon\CarbonInterface $now): int
    {
        return $this->insertAndGetId('shifts', [
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'device_id' => $deviceId,
            'user_id' => $kasirUserId,
            'active_cashier_id' => $kasirUserId,
            'opening_balance' => 500000,
            'status' => 'open',
            'opened_at' => $now->copy()->startOfDay()->addHours(8),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array{closed: int, open: int}
     */
    private function seedAccountingPeriods(int $tenantId, \Carbon\CarbonInterface $now): array
    {
        $previous = $now->copy()->subMonthNoOverflow();

        $closedId = $this->insertAndGetId('accounting_periods', [
            'tenant_id' => $tenantId,
            'period_code' => $previous->format('Y-m'),
            'fiscal_year' => (int) $previous->format('Y'),
            'period_number' => (int) $previous->format('n'),
            'start_date' => $previous->copy()->startOfMonth()->toDateString(),
            'end_date' => $previous->copy()->endOfMonth()->toDateString(),
            'status' => 'CLOSED',
            'is_adjustment_period' => false,
            'closed_at' => $now->copy()->startOfMonth(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $openId = $this->insertAndGetId('accounting_periods', [
            'tenant_id' => $tenantId,
            'period_code' => $now->format('Y-m'),
            'fiscal_year' => (int) $now->format('Y'),
            'period_number' => (int) $now->format('n'),
            'start_date' => $now->copy()->startOfMonth()->toDateString(),
            'end_date' => $now->copy()->endOfMonth()->toDateString(),
            'status' => 'OPEN',
            'is_adjustment_period' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['closed' => $closedId, 'open' => $openId];
    }

    /**
     * @param  array<string, int>  $users
     * @param  array{products: array<int, array{id: int, name: string, price: int, track: bool}>, ingredients: array<string, int>}  $catalog
     * @param  array<int, int>  $customers
     */
    private function seedThirtyDayOrders(
        int $tenantId,
        int $storeJkt,
        int $storeBdg,
        int $openShiftId,
        int $posDeviceId,
        array $users,
        array $catalog,
        array $customers,
        \Carbon\CarbonInterface $now
    ): void {
        $products = array_values(array_filter($catalog['products'], fn (array $p): bool => (int) $p['price'] > 0 && $p['name'] !== 'Menu Discontinued'));
        $productCount = count($products);
        $customerCount = count($customers);

        // 60 shift tertutup (2 shift/hari x 30 hari) + 1 shift aktif di JKT.
        $closedShiftIds = [];
        for ($day = 29; $day >= 0; $day--) {
            foreach ([['08:00', '16:00'], ['16:00', '23:00']] as $slot => [$from, $to]) {
                $shiftStart = $now->copy()->subDays($day)->setTime((int) substr($from, 0, 2), 0);

                $closedShiftIds[] = $this->insertAndGetId('shifts', [
                    'tenant_id' => $tenantId,
                    'store_id' => $storeJkt,
                    'device_id' => $posDeviceId,
                    'user_id' => $slot === 0 ? $users['kasir'] : $users['kasir2'],
                    'active_cashier_id' => null,
                    'opening_balance' => 500000,
                    'closing_balance' => 500000 + (($day * 37 + $slot * 11) % 900) * 1000,
                    'expected_cash' => 500000 + (($day * 37 + $slot * 11) % 900) * 1000,
                    'cash_difference' => 0,
                    'status' => 'closed',
                    'opened_at' => $shiftStart,
                    'closed_at' => $shiftStart->copy()->setTime((int) substr($to, 0, 2), 0),
                    'closed_by_user_id' => $users['manager'],
                    'created_at' => $shiftStart,
                    'updated_at' => $shiftStart,
                ]);
            }
        }

        $orders = [];
        $orderItems = [];
        $payments = [];
        $stockMovements = [];

        // ~30 order/hari x 30 hari = 900 order historis (Cash, QRIS, Card, Split Cash+Card, Void).
        for ($i = 0; $i < 900; $i++) {
            $dayOffset = 29 - intdiv($i, 30);
            $createdAt = $now->copy()->subDays($dayOffset)->setTime(9 + ($i % 12), ($i * 7) % 60);

            $storeId = $i % 10 === 9 ? $storeBdg : $storeJkt;
            $shiftId = $storeId === $storeJkt ? $closedShiftIds[$dayOffset * 2 + (($i % 12) >= 6 ? 1 : 0)] : null;

            $first = $products[$i % $productCount];
            $second = $products[($i * 3 + 5) % $productCount];
            $qtyFirst = 1 + ($i % 2);
            $subtotal = $first['price'] * $qtyFirst + $second['price'];
            $status = $i % 97 === 0 ? 'void' : 'paid';
            $isSplit = $status === 'paid' && $i % 19 === 0;
            $paymentMethod = $isSplit ? 'cash' : ($i % 5 === 0 ? 'qris' : ($i % 7 === 0 ? 'card' : 'cash'));
            $cashTendered = $paymentMethod === 'cash' && ! $isSplit ? $subtotal + (($i % 3) * 10000) : $subtotal;

            $orders[] = [
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'user_id' => $i % 3 === 0 ? $users['kasir2'] : $users['kasir'],
                'shift_id' => $shiftId,
                'created_by_device_id' => $storeId === $storeJkt ? $posDeviceId : null,
                'customer_id' => $i % 4 === 0 ? $customers[$i % $customerCount] : null,
                'order_code' => 'DEMO-'.str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
                'idempotency_key' => 'tier1-demo-order-'.$i,
                'order_date' => $createdAt->toDateString(),
                'order_type' => 'takeaway',
                'origin_channel' => 'pos_cashier',
                'order_channel' => 'pos_cashier',
                'subtotal_amount' => $subtotal,
                'dpp_amount' => $subtotal,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'service_charge_amount' => 0,
                'rounding_adjustment' => 0,
                'order_amount' => $subtotal,
                'cash_received' => $paymentMethod === 'cash' ? $cashTendered : null,
                'order_change' => $paymentMethod === 'cash' ? $cashTendered - $subtotal : 0,
                'order_status' => $status,
                'order_lifecycle_status' => $status === 'paid' ? 'confirmed' : 'cancelled',
                'payment_status' => $status === 'paid' ? 'paid' : 'unpaid',
                'fulfillment_status' => 'fulfilled',
                'payment_method' => $paymentMethod,
                'payment_timing' => 'paid',
                'void_reason' => $status === 'void' ? 'Demo void scenario' : null,
                'voided_by' => $status === 'void' ? $users['supervisor'] : null,
                'voided_at' => $status === 'void' ? $createdAt : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                '_items' => [
                    [$first, $qtyFirst],
                    [$second, 1],
                ],
                '_payment' => [$paymentMethod, $subtotal, $cashTendered, $isSplit],
                '_store' => $storeId,
            ];
        }

        // + 2 Order Pending QRIS Midtrans (untuk skenario rekonsiliasi status QRIS di E2E).
        foreach ([0, 1] as $offset) {
            $pendingTime = $now->copy()->subMinutes(20 - ($offset * 5));
            $first = $products[$offset];
            $subtotal = $first['price'];
            $orders[] = [
                'tenant_id' => $tenantId,
                'store_id' => $storeJkt,
                'user_id' => $users['kasir'],
                'shift_id' => $openShiftId,
                'created_by_device_id' => $posDeviceId,
                'customer_id' => $customers[$offset],
                'order_code' => 'DEMO-QRIS-'.str_pad((string) ($offset + 1), 3, '0', STR_PAD_LEFT),
                'idempotency_key' => 'tier1-demo-qris-pending-'.$offset,
                'order_date' => $pendingTime->toDateString(),
                'order_type' => 'takeaway',
                'origin_channel' => 'pos_cashier',
                'order_channel' => 'pos_cashier',
                'subtotal_amount' => $subtotal,
                'dpp_amount' => $subtotal,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'service_charge_amount' => 0,
                'rounding_adjustment' => 0,
                'order_amount' => $subtotal,
                'cash_received' => null,
                'order_change' => 0,
                'order_status' => 'pending',
                'order_lifecycle_status' => 'submitted',
                'payment_status' => 'awaiting_payment',
                'fulfillment_status' => 'unfulfilled',
                'payment_method' => 'qris',
                'payment_timing' => 'pay_first',
                'void_reason' => null,
                'voided_by' => null,
                'voided_at' => null,
                'created_at' => $pendingTime,
                'updated_at' => $pendingTime,
                '_items' => [
                    [$first, 1],
                ],
                '_payment' => null,
                '_store' => $storeJkt,
            ];
        }

        // Bulk insert orders, lalu item, payment, stock_movement & pending PaymentAttempt.
        $orderRows = array_map(fn (array $o): array => array_diff_key($o, ['_items' => 1, '_payment' => 1, '_store' => 1]), $orders);
        foreach (array_chunk($orderRows, 200) as $chunk) {
            DB::table('orders')->insert($chunk);
        }

        $codes = array_column($orderRows, 'order_code');
        $orderIdByCode = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereIn('order_code', $codes)
            ->pluck('id', 'order_code')
            ->all();

        $returnCandidateOrderId = null;
        $returnCandidateShiftId = null;
        $returnCandidateProduct = null;

        foreach ($orders as $index => $order) {
            $orderId = (int) $orderIdByCode[$order['order_code']];
            $orderCreatedAt = $order['created_at'];

            foreach ($order['_items'] as [$product, $qty]) {
                $orderItems[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => $order['_store'],
                    'order_id' => $orderId,
                    'product_id' => $product['id'],
                    'product_name_snapshot' => $product['name'],
                    'sku_snapshot' => (string) $product['id'],
                    'qty' => $qty,
                    'order_price' => $product['price'],
                    'order_subtotal' => $product['price'] * $qty,
                    'hpp_snapshot' => 0,
                    'net_line_total' => $product['price'] * $qty,
                    'item_discount_amount' => 0,
                    'allocated_order_discount_amount' => 0,
                    'options' => json_encode([]),
                    'preparation_status' => $order['order_status'] === 'pending' ? 'pending' : 'ready',
                    'created_at' => $orderCreatedAt,
                    'updated_at' => $orderCreatedAt,
                ];

                if (
                    $returnCandidateOrderId === null
                    && $order['_store'] === $storeJkt
                    && $order['order_status'] === 'paid'
                    && $order['_payment'][0] === 'cash'
                    && $order['_payment'][3] === false
                    && $order['shift_id'] !== null
                ) {
                    $returnCandidateOrderId = $orderId;
                    $returnCandidateShiftId = (int) $order['shift_id'];
                    $returnCandidateProduct = $product;
                }
            }

            if ($order['order_status'] === 'paid') {
                [$method, $amount, $tendered, $isSplit] = $order['_payment'];

                if ($isSplit) {
                    $firstHalf = intdiv($amount, 2);
                    $secondHalf = $amount - $firstHalf;

                    $payments[] = [
                        'tenant_id' => $tenantId,
                        'store_id' => $order['_store'],
                        'order_id' => $orderId,
                        'shift_id' => $order['shift_id'],
                        'device_id' => $order['created_by_device_id'],
                        'payment_method' => 'cash',
                        'amount' => $firstHalf,
                        'amount_tendered' => $firstHalf,
                        'change_amount' => 0,
                        'status' => 'captured',
                        'captured_at' => $orderCreatedAt,
                        'processed_by' => $order['user_id'],
                        'idempotency_key' => 'tier1-demo-payment-'.$index.'-1',
                        'created_at' => $orderCreatedAt,
                        'updated_at' => $orderCreatedAt,
                    ];
                    $payments[] = [
                        'tenant_id' => $tenantId,
                        'store_id' => $order['_store'],
                        'order_id' => $orderId,
                        'shift_id' => $order['shift_id'],
                        'device_id' => $order['created_by_device_id'],
                        'payment_method' => 'card',
                        'amount' => $secondHalf,
                        'amount_tendered' => $secondHalf,
                        'change_amount' => 0,
                        'status' => 'captured',
                        'captured_at' => $orderCreatedAt,
                        'processed_by' => $order['user_id'],
                        'idempotency_key' => 'tier1-demo-payment-'.$index.'-2',
                        'created_at' => $orderCreatedAt,
                        'updated_at' => $orderCreatedAt,
                    ];
                } else {
                    $payments[] = [
                        'tenant_id' => $tenantId,
                        'store_id' => $order['_store'],
                        'order_id' => $orderId,
                        'shift_id' => $order['shift_id'],
                        'device_id' => $order['created_by_device_id'],
                        'payment_method' => $method,
                        'amount' => $amount,
                        'amount_tendered' => $tendered,
                        'change_amount' => max(0, $tendered - $amount),
                        'status' => 'captured',
                        'captured_at' => $orderCreatedAt,
                        'processed_by' => $order['user_id'],
                        'idempotency_key' => 'tier1-demo-payment-'.$index,
                        'created_at' => $orderCreatedAt,
                        'updated_at' => $orderCreatedAt,
                    ];
                }
            }

            // Stock movement hanya untuk produk retail ter-track pada order terbayar.
            if ($order['order_status'] === 'paid') {
                foreach ($order['_items'] as [$product, $qty]) {
                    if (! $product['track']) {
                        continue;
                    }
                    $stockMovements[] = [
                        'tenant_id' => $tenantId,
                        'store_id' => $order['_store'],
                        'warehouse_id' => null,
                        'product_id' => $product['id'],
                        'order_id' => $orderId,
                        'type' => 'sale',
                        'quantity' => -$qty,
                        'balance_before' => 0,
                        'balance_after' => 0,
                        'unit_cost' => 0,
                        'reason' => 'Demo sale',
                        'idempotency_key' => 'tier1-demo-sm-'.$index.'-'.$product['id'],
                        'created_at' => $orderCreatedAt,
                        'updated_at' => $orderCreatedAt,
                    ];
                }
            }
        }

        foreach (array_chunk($orderItems, 300) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }

        foreach (array_chunk($payments, 300) as $chunk) {
            DB::table('payments')->insert($chunk);
        }

        foreach (array_chunk($stockMovements, 300) as $chunk) {
            DB::table('stock_movements')->insert($chunk);
        }

        // 2 PaymentAttempt Pending QRIS (skenario rekonsiliasi QRIS di Playwright).
        $pendingOrders = array_values(array_filter($orders, fn (array $o): bool => $o['order_status'] === 'pending'));
        foreach ($pendingOrders as $offset => $order) {
            $orderId = (int) $orderIdByCode[$order['order_code']];
            $this->insertRow('payment_attempts', [
                'tenant_id' => $tenantId,
                'store_id' => $order['_store'],
                'order_id' => $orderId,
                'payment_id' => null,
                'device_id' => $posDeviceId,
                'shift_id' => $openShiftId,
                'idempotency_key' => 'tier1-demo-attempt-'.$offset,
                'provider' => 'midtrans',
                'payment_method' => 'qris',
                'provider_order_id' => $order['order_code'].'-ATT'.($offset + 1),
                'amount' => $order['order_amount'],
                'currency' => 'IDR',
                'status' => 'pending_provider',
                'initiated_by_user_id' => $order['user_id'],
                'created_at' => $order['created_at'],
                'updated_at' => $order['created_at'],
            ]);
        }

        // 1 Partial Return + settled cash refund untuk riwayat retur & rekonsiliasi.
        if ($returnCandidateOrderId !== null && $returnCandidateShiftId !== null && $returnCandidateProduct !== null) {
            $candidateItemId = (int) DB::table('order_items')
                ->where('tenant_id', $tenantId)
                ->where('order_id', $returnCandidateOrderId)
                ->where('product_id', $returnCandidateProduct['id'])
                ->value('id');
            $candidatePaymentId = (int) DB::table('payments')
                ->where('tenant_id', $tenantId)
                ->where('order_id', $returnCandidateOrderId)
                ->value('id');

            if ($candidateItemId > 0 && $candidatePaymentId > 0) {
                $refundPrice = (int) $returnCandidateProduct['price'];
                $returnedAt = $now->copy()->subDay();

                $returnId = $this->insertAndGetId('order_returns', [
                    'tenant_id' => $tenantId,
                    'store_id' => $storeJkt,
                    'order_id' => $returnCandidateOrderId,
                    'shift_id' => $returnCandidateShiftId,
                    'device_id' => $posDeviceId,
                    'return_number' => 'RET-DEMO-0001',
                    'idempotency_key' => 'tier1-demo-return-1',
                    'status' => 'completed',
                    'return_type' => 'partial',
                    'subtotal_returned' => $refundPrice,
                    'discount_reversed' => 0,
                    'tax_returned' => 0,
                    'service_charge_returned' => 0,
                    'total_refundable_amount' => $refundPrice,
                    'reason' => 'Demo partial return item',
                    'requested_by_user_id' => $users['kasir'],
                    'approved_by_user_id' => $users['supervisor'],
                    'completed_at' => $returnedAt,
                    'created_at' => $returnedAt,
                    'updated_at' => $returnedAt,
                ]);

                $this->insertRow('order_return_items', [
                    'tenant_id' => $tenantId,
                    'store_id' => $storeJkt,
                    'order_return_id' => $returnId,
                    'order_item_id' => $candidateItemId,
                    'product_id' => $returnCandidateProduct['id'],
                    'quantity_returned' => 1,
                    'unit_price_snapshot' => $refundPrice,
                    'line_discount_reversed' => 0,
                    'line_writeoff_or_refund_amount' => $refundPrice,
                    'disposition' => 'restock',
                    'disposition_reason' => 'Damaged packaging replaced',
                    'created_at' => $returnedAt,
                    'updated_at' => $returnedAt,
                ]);

                $this->insertRow('payment_refunds', [
                    'tenant_id' => $tenantId,
                    'store_id' => $storeJkt,
                    'order_id' => $returnCandidateOrderId,
                    'payment_id' => $candidatePaymentId,
                    'order_return_id' => $returnId,
                    'shift_id' => $returnCandidateShiftId,
                    'device_id' => $posDeviceId,
                    'idempotency_key' => 'tier1-demo-refund-1',
                    'refund_number' => 'RFD-DEMO-0001',
                    'amount' => $refundPrice,
                    'currency' => 'IDR',
                    'original_payment_method' => 'cash',
                    'refund_method' => 'cash',
                    'request_status' => 'completed',
                    'provider_status' => 'not_applicable',
                    'financial_status' => 'settled',
                    'reason' => 'Demo partial return item',
                    'requested_by_user_id' => $users['kasir'],
                    'approved_by_user_id' => $users['supervisor'],
                    'settled_at' => $returnedAt,
                    'created_at' => $returnedAt,
                    'updated_at' => $returnedAt,
                ]);
            }
        }

        $this->counts['orders'] = count($orders);
        $this->counts['order_items'] = count($orderItems);
        $this->counts['payments'] = count($payments);
        $this->counts['payment_attempts'] = count($pendingOrders);
        $this->counts['shifts'] = count($closedShiftIds) + 1;
    }

    /**
     * @param  array<int, int>  $customers
     */
    private function seedLoyaltyLedger(int $tenantId, int $storeId, array $customers, \Carbon\CarbonInterface $now): void
    {
        $earned = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('order_status', 'paid')
            ->whereNotNull('customer_id')
            ->selectRaw('id, customer_id, order_amount, created_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $balances = [];
        $rows = [];

        foreach ($earned as $order) {
            $customerId = (int) $order->customer_id;
            $points = (int) intdiv((int) $order->order_amount, 10000);

            if ($points <= 0) {
                continue;
            }

            $balances[$customerId] = ($balances[$customerId] ?? 0) + $points;

            // `trg_loyalty_ledger_hash_chain` & `trg_loyalty_ledger_sync_account`
            // menjaga rantai SHA-256 dan menyinkronkan `customer_loyalty_accounts` otomatis.
            $rows[] = [
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'customer_id' => $customerId,
                'order_id' => $order->id,
                'type' => 'earn',
                'points' => $points,
                'balance_after' => $balances[$customerId],
                'reference' => 'Order '.$order->id,
                'prev_hash' => null,
                'entry_hash' => str_repeat('0', 64),
                'created_at' => $order->created_at,
            ];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('loyalty_ledger')->insert($chunk);
        }

        $this->counts['loyalty_ledger'] = count($rows);
    }

    /**
     * @param  array<string, int>  $users
     */
    private function seedPayroll(
        int $tenantId,
        int $companyId,
        int $primaryStoreId,
        string $primaryStoreName,
        int $openPeriodId,
        array $users,
        \Carbon\CarbonInterface $now
    ): void {
        $departmentId = $this->insertAndGetId('departments', [
            'tenant_id' => $tenantId,
            'parent_id' => null,
            'department_code' => 'OPS',
            'name' => 'Operasional',
            'description' => 'Operasional outlet',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $jobTitleId = $this->insertAndGetId('job_titles', [
            'tenant_id' => $tenantId,
            'department_id' => $departmentId,
            'title_code' => 'JT-OPS',
            'name' => 'Store Crew & Leadership',
            'grade_level' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $staff = ['kasir', 'kasir2', 'supervisor', 'cook', 'manager', 'finance'];
        $staffNames = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_map(fn (string $k): int => $users[$k], $staff))
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $employees = [];
        foreach ($staff as $index => $userKey) {
            $userId = $users[$userKey];
            $userRow = $staffNames[$userId] ?? null;
            $fullName = $userRow !== null ? (string) $userRow->name : ucfirst($userKey);
            $email = $userRow !== null ? (string) $userRow->email : $userKey.'@pos.test';
            $empNumber = 'EMP-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);

            $employeeId = $this->insertAndGetId('employees', [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'user_id' => $userId,
                'employee_number' => $empNumber,
                'full_name' => $fullName,
                'email' => $email,
                'phone_number' => '0813'.str_pad((string) (700000 + $index), 8, '0', STR_PAD_LEFT),
                'gender' => in_array($userKey, ['kasir', 'supervisor', 'cook'], true) ? 'FEMALE' : 'MALE',
                'marital_status' => 'SINGLE',
                'religion' => 'ISLAM',
                'department_id' => $departmentId,
                'job_title_id' => $jobTitleId,
                'employment_type' => $index < 2 ? 'CONTRACT_PKWT' : 'PERMANENT',
                'employment_status' => 'ACTIVE',
                'hire_date' => $now->copy()->subYear()->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $employees[] = [
                'id' => $employeeId,
                'number' => $empNumber,
                'name' => $fullName,
                'base' => 4500000 + ($index * 750000),
            ];
        }

        $prevMonth = $now->copy()->subMonthNoOverflow();
        $batchId = $this->insertAndGetId('payroll_batches', [
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'batch_number' => 'PAY-'.$prevMonth->format('Ym'),
            'period_start_date' => $prevMonth->copy()->startOfMonth()->toDateString(),
            'period_end_date' => $prevMonth->copy()->endOfMonth()->toDateString(),
            'cutoff_date' => $prevMonth->copy()->endOfMonth()->subDays(2)->toDateString(),
            'payment_scheduled_date' => $now->copy()->startOfMonth()->toDateString(),
            'status' => 'FINALIZED',
            'total_employees' => count($employees),
            'total_gross_amount' => 0,
            'total_tax_amount' => 0,
            'total_bpjs_employer' => 0,
            'total_bpjs_employee' => 0,
            'total_other_deductions' => 0,
            'total_net_amount' => 0,
            'created_by_user_id' => $users['finance'],
            'approved_by_user_id' => $users['admin'],
            'finalized_at' => $now->copy()->startOfMonth(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $gross = 0;
        foreach ($employees as $index => $emp) {
            $base = $emp['base'];
            $gross += $base;

            $this->insertRow('payroll_records', [
                'tenant_id' => $tenantId,
                'payroll_batch_id' => $batchId,
                'employee_id' => $emp['id'],
                'employee_number_snapshot' => $emp['number'],
                'full_name_snapshot' => $emp['name'],
                'department_name_snapshot' => 'Operasional',
                'job_title_snapshot' => 'Store Crew & Leadership',
                'primary_store_id' => $primaryStoreId,
                'primary_store_name_snapshot' => $primaryStoreName,
                'ptkp_status_snapshot' => 'TK/0',
                'tax_calculation_method_snapshot' => 'GROSS',
                'bank_name_snapshot' => 'BCA',
                'bank_account_number_snapshot' => '11223344'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'basic_salary_amount' => $base,
                'gross_earnings_amount' => $base,
                'taxable_gross_amount' => $base,
                'tax_pph21_amount' => 0,
                'bpjs_ks_employee_amount' => 0,
                'bpjs_tk_employee_amount' => 0,
                'net_pay_amount' => $base,
                'payslip_token' => hash('sha256', 'tier1-demo-payslip-'.$tenantId.'-'.$emp['id']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('payroll_batches')->where('id', $batchId)->update([
            'total_gross_amount' => $gross,
            'total_net_amount' => $gross,
        ]);

        // Header jurnal GL seimbang (POSTED) pada periode akuntansi OPEN agar lolos trigger FIN-M7-08.
        $this->insertRow('journal_entries', [
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'store_id' => $primaryStoreId,
            'accounting_period_id' => $openPeriodId,
            'journal_number' => 'JE-PAY-'.$prevMonth->format('Ym'),
            'posting_date' => $now->copy()->startOfMonth()->toDateString(),
            'journal_type' => 'SYSTEM_AUTO',
            'status' => 'POSTED',
            'source_module' => 'WORKFORCE',
            'source_type' => 'PAYROLL_ACCRUAL',
            'source_id' => $batchId,
            'source_number' => 'PAY-'.$prevMonth->format('Ym'),
            'idempotency_key' => 'tier1-demo-je-payroll-'.$tenantId,
            'currency_code' => 'IDR',
            'notes' => 'Payroll accrual '.$prevMonth->format('F Y'),
            'total_debit' => $gross,
            'total_credit' => $gross,
            'created_by_user_id' => $users['finance'],
            'approved_by_user_id' => $users['admin'],
            'posted_by_user_id' => $users['finance'],
            'posted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->counts['employees'] = count($employees);
        $this->counts['payroll_records'] = count($employees);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insertRow(string $table, array $values): void
    {
        DB::table($table)->insert($values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insertAndGetId(string $table, array $values): int
    {
        return (int) DB::table($table)->insertGetId($values);
    }
}
