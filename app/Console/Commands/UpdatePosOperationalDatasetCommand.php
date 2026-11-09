<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PreparationStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashierPinService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UpdatePosOperationalDatasetCommand extends Command
{
    protected $signature = 'pos:dataset:refresh-operational
                            {--tenant= : Target tenant ID (1 for F&B, 2 for Retail, or omit for both)}
                            {--password=M3m3x123!@# : Uniform password for demo/test accounts}
                            {--dry-run : Simulate execution without persisting changes}';

    protected $description = 'Refresh POS operational dataset with healthy stocks, active shifts today (2026-11-09), work schedules, and Midtrans Gateway test records for both F&B and Retail tenants.';

    private bool $isDryRun = false;

    public function handle(CashierPinService $pinService): int
    {
        $this->isDryRun = (bool) $this->option('dry-run');
        $rawPassword = (string) $this->option('password');
        $targetTenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;

        $this->info('');
        $this->info('╔═══════════════════════════════════════════════════════════════════════════════════════════╗');
        $this->info('║       APEIRON POS — OPERATIONAL DATASET REFRESH, RESTOCK & 2026 SHIFT ENGINE              ║');
        $this->info('║  Simulasi Operasional Aktif 2026 (Hari Ini: 09 November 2026) | Multi-Tenant F&B & Retail  ║');
        $this->info('╚═══════════════════════════════════════════════════════════════════════════════════════════╝');
        $this->info('');

        if ($this->isDryRun) {
            $this->warn('⚠️  MODE SIMULASI (DRY-RUN): Tidak ada perubahan yang akan disimpan ke basis data.');
        }

        $query = Tenant::query();
        if ($targetTenantId) {
            $query->where('id', $targetTenantId);
        } else {
            $query->whereIn('id', [1, 2])->orWhereIn('slug', ['fnb-demo', 'retail-demo']);
        }
        $tenants = $query->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->error('Tidak ada tenant yang cocok ditemukan!');
            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $this->info("\n==========================================================================");
            $this->info("MEMPROSES TENANT #{$tenant->id}: {$tenant->name} ({$tenant->primary_industry})");
            $this->info("==========================================================================");

            app(TenantContext::class)->setTenantId((int) $tenant->id);
            app(TenantContext::class)->setStoreId(null);

            DB::transaction(function () use ($tenant, $rawPassword, $pinService) {
                // 1. Sinkronisasi Kredensial, PIN & Hak Akses Toko
                $this->info('━━━ 1. Sinkronisasi Akun Pengguna, PIN Kasir (123456) & Hak Akses Toko ━━━');
                $users = $this->provisionTenantUsers($tenant, $rawPassword, $pinService);

                // 2. Restock Menyeluruh Produk & Bahan Baku ke Status Sehat
                $this->info('');
                $this->info('━━━ 2. Restock Seluruh Produk & Bahan Baku (Katalog 100% Siap Jual di POS) ━━━');
                $stockSummary = $this->restockProductsAndIngredients($tenant);

                // 3. Setup Template Shift & Jadwal Shift Karyawan (work_schedules 2026)
                $this->info('');
                $this->info('━━━ 3. Penataan Template Shift & Jadwal Kerja Karyawan (November 2026) ━━━');
                $scheduleCount = $this->setupWorkSchedules($tenant);

                // 4. Pembukaan Shift Kasir Hari Ini (2026-11-09) di Seluruh Cabang
                $this->info('');
                $this->info('━━━ 4. Pembukaan Shift Kasir Aktif Hari Ini (2026-11-09) di Seluruh Cabang ━━━');
                $activeShifts = $this->setupActiveShiftsForStores($tenant, $users);

                // 5. Pembuatan Transaksi Operasional Hari Ini & Midtrans Gateway
                $this->info('');
                $this->info('━━━ 5. Pembuatan Transaksi Operasional Terkini (Hari Ini) & Gateway ━━━');
                $ordersSummary = $this->generateRecentTransactions($tenant, $users, $activeShifts);

                // 6. Sinkronisasi Periode Akuntansi 2026
                $this->info('');
                $this->info('━━━ 6. Penataan Periode Akuntansi 2026 (2026-11 OPEN) ━━━');
                $this->setupAccountingPeriods2026($tenant);

                // 7. Ringkasan
                $this->info('');
                $this->renderTenantSummary($tenant, $users, $activeShifts, $stockSummary, $scheduleCount, $ordersSummary, $rawPassword);
            });
        }

        $this->info("\n✅ DATASET OPERASIONAL 2026 TELAH BERHASIL DIPERBARUI & 100% SIAP DITEST DI POS!");
        return self::SUCCESS;
    }

    /**
     * @return array<string, User>
     */
    private function provisionTenantUsers(Tenant $tenant, string $rawPassword, CashierPinService $pinService): array
    {
        $tenantId = (int) $tenant->id;
        $hashedPassword = Hash::make($rawPassword);
        $allStores = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();

        $roleOwner = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', 'Owner')->first();
        $roleManager = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', 'Manager')->first();
        $roleKasir = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', 'Kasir')->first();

        // 1. Sinkronisasi Owner Utama
        $ownerEmail = $tenantId === 1 ? 'ilhamsepriyadi8@gmail.com' : 'ilhamsepriyadi0@gmail.com';
        $ownerUser = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('email', $ownerEmail)->first();

        if ($ownerUser) {
            $ownerUser->password = $hashedPassword;
            $ownerUser->is_active = true;
            $ownerUser->email_verified_at = now();
            $ownerUser->save();
            $pinService->setPin($ownerUser, '123456');

            // Berikan hak akses Owner ke SEMUA cabang
            foreach ($allStores as $s) {
                DB::table('user_stores')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'user_id' => $ownerUser->id, 'store_id' => $s->id],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
            $this->line("  ✓ Owner terverifikasi: [{$ownerUser->name}] <{$ownerUser->email}> | Password: {$rawPassword} | PIN: 123456");
        }

        // 2. Sinkronisasi seluruh akun staf dan kasir
        $staffUsers = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
        foreach ($staffUsers as $usr) {
            $usr->password = $hashedPassword;
            $usr->is_active = true;
            $usr->save();
            $pinService->setPin($usr, '123456');
        }
        $this->line("  ✓ Seluruh {$staffUsers->count()} pengguna Tenant #{$tenantId} diselaraskan: Password = {$rawPassword} | PIN = 123456");

        // 3. Siapkan akun kasir demo eksplisit
        $demoKasirEmail = $tenantId === 1 ? 'kasir.fnb@apeiron.test' : 'kasir.retail@apeiron.test';
        $demoKasirName = $tenantId === 1 ? 'Sari Kasir F&B' : 'Budi Kasir Retail';
        $demoKasir = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('email', $demoKasirEmail)->first();

        if (! $demoKasir) {
            $demoKasir = new User();
            $demoKasir->tenant_id = $tenantId;
            $demoKasir->email = $demoKasirEmail;
        }

        $demoKasir->name = $demoKasirName;
        $demoKasir->role_id = $roleKasir?->id ?? 3;
        $demoKasir->password = $hashedPassword;
        $demoKasir->phone_number = '0812' . sprintf('%08d', mt_rand(10000000, 99999999));
        $demoKasir->is_active = true;
        $demoKasir->email_verified_at = now();
        $demoKasir->save();
        $pinService->setPin($demoKasir, '123456');

        // Petakan kasir demo ke seluruh cabang agar mudah ditest
        foreach ($allStores as $s) {
            DB::table('user_stores')->updateOrInsert(
                ['tenant_id' => $tenantId, 'user_id' => $demoKasir->id, 'store_id' => $s->id],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
        $this->line("  ✓ Kasir Demo Siap: [{$demoKasir->name}] <{$demoKasir->email}> | PIN: 123456");

        return [
            'owner' => $ownerUser ?? $staffUsers->first(),
            'kasir' => $demoKasir,
        ];
    }

    /**
     * @return array{healthy: int, ingredients: int, stores: int}
     */
    private function restockProductsAndIngredients(Tenant $tenant): array
    {
        $tenantId = (int) $tenant->id;
        $stores = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
        $now = now();
        $isRetail = ($tenant->primary_industry === 'retail');
        $targetProductStock = $isRetail ? 250 : 150;

        $products = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
        $ingredients = Ingredient::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();

        foreach ($products as $product) {
            $product->stock = $targetProductStock;
            $product->track_inventory = true;
            $product->is_active = true;
            $product->status = 'active';
            $product->save();

            foreach ($stores as $s) {
                DB::table('product_stock_balances')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'store_id' => $s->id, 'product_id' => $product->id],
                    [
                        'quantity' => $targetProductStock,
                        'baseline_quantity' => $targetProductStock,
                        'baseline_movement_id' => 0,
                        'min_stock_level' => 10,
                        'reorder_point' => 30,
                        'max_stock_level' => 1000,
                        'average_unit_cost' => (float) ($product->product_price * 0.45),
                        'last_unit_cost' => (float) ($product->product_price * 0.45),
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                $idempotencyKey = "op_restock_prod_{$product->id}_{$s->id}_20261109";
                $exists = DB::table('stock_movements')
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->exists();

                if (! $exists) {
                    DB::table('stock_movements')->insert([
                        'tenant_id' => $tenantId,
                        'idempotency_key' => $idempotencyKey,
                        'store_id' => $s->id,
                        'product_id' => $product->id,
                        'type' => 'purchase_receipt',
                        'quantity' => $targetProductStock,
                        'balance_before' => '0.0000',
                        'balance_after' => (string) $targetProductStock,
                        'unit_cost' => (string) ($product->product_price * 0.45),
                        'reason' => 'Restock Operasional 2026 - Siap Uji POS',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        // Restock Bahan Baku (F&B)
        if (! $isRetail && $ingredients->isNotEmpty()) {
            foreach ($ingredients as $ing) {
                $ing->current_stock = '50000.0000';
                $ing->is_active = true;
                $ing->save();

                foreach ($stores as $s) {
                    DB::table('ingredient_stock_balances')->updateOrInsert(
                        ['tenant_id' => $tenantId, 'store_id' => $s->id, 'ingredient_id' => $ing->id],
                        [
                            'quantity' => 50000,
                            'baseline_quantity' => 50000,
                            'baseline_movement_id' => 0,
                            'min_stock_level' => 2000,
                            'reorder_point' => 5000,
                            'max_stock_level' => 150000,
                            'average_unit_cost' => $ing->cost_per_unit ?? 0.05,
                            'last_unit_cost' => $ing->cost_per_unit ?? 0.05,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );

                    $idempotencyKey = "op_restock_ing_{$ing->id}_{$s->id}_20261109";
                    $exists = DB::table('ingredient_stock_movements')
                        ->where('tenant_id', $tenantId)
                        ->where('idempotency_key', $idempotencyKey)
                        ->exists();

                    if (! $exists) {
                        DB::table('ingredient_stock_movements')->insert([
                            'tenant_id' => $tenantId,
                            'idempotency_key' => $idempotencyKey,
                            'store_id' => $s->id,
                            'ingredient_id' => $ing->id,
                            'type' => 'purchase_receipt',
                            'quantity' => 50000,
                            'unit_cost' => $ing->cost_per_unit ?? 0.05,
                            'reason' => 'Restock Bahan Baku Dapur & Bar 2026',
                            'created_at' => $now,
                        ]);
                    }
                }
            }
        }

        // Perpanjang masa berlaku diskon dan voucher agar aktif di 2026 & 2027
        DB::table('discounts')->where('tenant_id', $tenantId)->update([
            'is_active' => 1,
            'valid_until' => '2027-12-31 23:59:59',
            'updated_at' => $now,
        ]);

        DB::table('vouchers')->where('tenant_id', $tenantId)->update([
            'status' => 'ACTIVE',
            'valid_until' => '2027-12-31 23:59:59',
            'updated_at' => $now,
        ]);

        $this->line("  ✓ {$products->count()} produk direstock (@ {$targetProductStock} pcs per cabang, total {$stores->count()} cabang).");
        if (! $isRetail) {
            $this->line("  ✓ {$ingredients->count()} bahan baku direstock (@ 50.000 unit per cabang).");
        }
        $this->line("  ✓ Diskon & Voucher promo diperpanjang aktif hingga 31 Desember 2027.");

        return [
            'healthy' => $products->count(),
            'ingredients' => $ingredients->count(),
            'stores' => $stores->count(),
        ];
    }

    private function setupWorkSchedules(Tenant $tenant): int
    {
        $tenantId = (int) $tenant->id;
        $stores = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
        $employees = Employee::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();

        if ($employees->isEmpty()) {
            return 0;
        }

        // 1. Pastikan setiap store memiliki 3 template shift standar
        $templateMap = []; // store_id => [template_id_pagi, template_id_mid, template_id_malam]

        foreach ($stores as $s) {
            $tplPagi = DB::table('shift_templates')->updateOrInsert(
                ['tenant_id' => $tenantId, 'store_id' => $s->id, 'shift_code' => "PAGI-{$s->id}"],
                [
                    'name' => 'Shift Pagi Operasional (07:00 - 15:30)',
                    'start_time' => '07:00:00',
                    'end_time' => '15:30:00',
                    'cross_midnight' => 0,
                    'unpaid_break_minutes' => 60,
                    'grace_period_minutes' => 15,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $pagiId = DB::table('shift_templates')->where('tenant_id', $tenantId)->where('store_id', $s->id)->where('shift_code', "PAGI-{$s->id}")->value('id');

            $tplMid = DB::table('shift_templates')->updateOrInsert(
                ['tenant_id' => $tenantId, 'store_id' => $s->id, 'shift_code' => "MID-{$s->id}"],
                [
                    'name' => 'Shift Middle Peak Hour (10:00 - 18:30)',
                    'start_time' => '10:00:00',
                    'end_time' => '18:30:00',
                    'cross_midnight' => 0,
                    'unpaid_break_minutes' => 60,
                    'grace_period_minutes' => 15,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $midId = DB::table('shift_templates')->where('tenant_id', $tenantId)->where('store_id', $s->id)->where('shift_code', "MID-{$s->id}")->value('id');

            $tplMalam = DB::table('shift_templates')->updateOrInsert(
                ['tenant_id' => $tenantId, 'store_id' => $s->id, 'shift_code' => "MLM-{$s->id}"],
                [
                    'name' => 'Shift Malam Closing (14:30 - 23:00)',
                    'start_time' => '14:30:00',
                    'end_time' => '23:00:00',
                    'cross_midnight' => 0,
                    'unpaid_break_minutes' => 60,
                    'grace_period_minutes' => 15,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $malamId = DB::table('shift_templates')->where('tenant_id', $tenantId)->where('store_id', $s->id)->where('shift_code', "MLM-{$s->id}")->value('id');

            $templateMap[$s->id] = [$pagiId, $midId, $malamId];
        }

        // 2. Jadwalkan seluruh karyawan dari 01 November 2026 s/d 15 November 2026
        $scheduleRows = [];
        $startDate = Carbon::parse('2026-11-01');
        $endDate = Carbon::parse('2026-11-15');

        foreach ($employees as $empIdx => $emp) {
            $empStoreId = (int) ($emp->store_id ?? $stores->first()->id);
            if (! isset($templateMap[$empStoreId])) {
                $empStoreId = (int) $stores->first()->id;
            }
            $storeTemplates = $templateMap[$empStoreId];

            $currDate = $startDate->copy();
            while ($currDate->lte($endDate)) {
                $dateStr = $currDate->toDateString();
                // Pilih template bergiliran berdasarkan (employee_id + day_of_month)
                $tplIdx = ($emp->id + $currDate->day) % count($storeTemplates);
                $selectedTplId = $storeTemplates[$tplIdx];

                DB::table('work_schedules')->updateOrInsert(
                    [
                        'tenant_id' => $tenantId,
                        'employee_id' => $emp->id,
                        'schedule_date' => $dateStr,
                    ],
                    [
                        'store_id' => $empStoreId,
                        'shift_template_id' => $selectedTplId,
                        'status' => 'SCHEDULED',
                        'notes' => 'Jadwal Reguler November 2026',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                $currDate->addDay();
            }
        }

        $totalSchedules = DB::table('work_schedules')->where('tenant_id', $tenantId)->whereBetween('schedule_date', ['2026-11-01', '2026-11-15'])->count();
        $this->line("  ✓ {$totalSchedules} jadwal kerja aktif terkonfigurasi untuk {$employees->count()} karyawan (Rentang: 01 s/d 15 November 2026, termasuk hari ini 09 Nov).");

        return $totalSchedules;
    }

    /**
     * @return array<int, Shift> store_id => Shift
     */
    private function setupActiveShiftsForStores(Tenant $tenant, array $users): array
    {
        $tenantId = (int) $tenant->id;
        $stores = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
        $today = Carbon::today();
        $activeShifts = [];

        // 1. Tutup shift usang dari hari-hari sebelumnya yang masih berstatus 'open'
        $staleShifts = Shift::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->where('opened_at', '<', $today)
            ->get();

        foreach ($staleShifts as $stale) {
            $stale->status = 'closed';
            $stale->closed_at = now()->subDay()->toDateTimeString();
            $stale->expected_cash = $stale->opening_balance;
            $stale->closing_balance = $stale->opening_balance;
            $stale->cash_difference = 0;
            $stale->close_type = 'normal';
            $stale->notes = 'Penutupan otomatis shift masa lalu.';
            $stale->save();
        }

        // 2. Di setiap store, pastikan ada device POS dan shift aktif hari ini (2026-11-09)
        foreach ($stores as $store) {
            app(TenantContext::class)->setTenantId($tenantId);
            app(TenantContext::class)->setStoreId($store->id);

            $device = Device::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $store->id)
                ->where('device_type', 'pos_terminal')
                ->first();

            if (! $device) {
                $device = Device::create([
                    'tenant_id' => $tenantId,
                    'store_id' => $store->id,
                    'device_name' => "POS Terminal {$store->name}",
                    'device_code' => "POS-{$store->code}-01",
                    'device_type' => 'pos_terminal',
                    'device_secret' => Str::random(32),
                    'status' => 'active',
                    'is_active' => true,
                ]);
            }

            // Ambil kasir yang bertugas di store ini
            $cashierUser = User::withoutGlobalScopes()
                ->join('roles as r', 'r.id', '=', 'users.role_id')
                ->join('user_stores as us', 'us.user_id', '=', 'users.id')
                ->where('users.tenant_id', $tenantId)
                ->where('us.store_id', $store->id)
                ->where('r.name', 'Kasir')
                ->select('users.*')
                ->first();

            if (! $cashierUser) {
                $cashierUser = $users['kasir'];
            }

            // Periksa apakah shift hari ini sudah ada dan aktif
            $shift = Shift::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $store->id)
                ->where('device_id', $device->id)
                ->where('status', 'open')
                ->first();

            if (! $shift) {
                // Tutup shift lain pada user yang sama jika ada di store ini
                Shift::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $store->id)
                    ->where('user_id', $cashierUser->id)
                    ->where('status', 'open')
                    ->update([
                        'status' => 'closed',
                        'closed_at' => now()->subMinutes(30),
                        'closing_balance' => 500000,
                        'expected_cash' => 500000,
                        'cash_difference' => 0,
                    ]);

                $shift = Shift::create([
                    'tenant_id' => $tenantId,
                    'store_id' => $store->id,
                    'user_id' => $cashierUser->id,
                    'active_cashier_id' => $cashierUser->id,
                    'device_id' => $device->id,
                    'opening_balance' => 500000,
                    'expected_cash' => 500000,
                    'status' => 'open',
                    'opened_at' => Carbon::parse('2026-11-09 07:00:00'),
                    'notes' => "Shift Kasir Operasional Hari Ini ({$store->name}) - Siap Transaksi POS",
                ]);
            }

            $activeShifts[$store->id] = $shift;
            $this->line("  ✓ Shift #{$shift->id} AKTIF di Store [{$store->name}] | Kasir: {$cashierUser->name} | Device: {$device->device_name} | Modal Kas: Rp 500.000");
        }

        app(TenantContext::class)->setStoreId(null);

        return $activeShifts;
    }

    private function generateRecentTransactions(Tenant $tenant, array $users, array $activeShifts): array
    {
        $tenantId = (int) $tenant->id;
        $mainStore = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        if (! $mainStore || ! isset($activeShifts[$mainStore->id])) {
            return ['count' => 0];
        }

        $storeId = (int) $mainStore->id;
        $shift = $activeShifts[$storeId];
        $cashier = $users['kasir'];
        $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $table = Table::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('store_id', $storeId)->first();

        // Tetapkan context store & tenant agar observer / assigns store berjalan mulus
        app(\App\Core\Context\TenantContext::class)->setTenantId($tenantId);
        app(\App\Core\Context\TenantContext::class)->setStoreId($storeId);

        $sampleProducts = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('stock', '>', 5)
            ->take(5)
            ->get();

        if ($sampleProducts->isEmpty()) {
            return ['count' => 0];
        }

        $idempotencyCheck = "op_trx_cash_{$tenantId}_20261109_001";
        if (Order::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyCheck)->exists()) {
            return ['count' => 5];
        }

        // TRX 1: Tunai Hari Ini (09 Nov 2026)
        $p1 = $sampleProducts[0];
        $price1 = (int) $p1->product_price;
        $order1 = Order::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'customer_id' => $customer?->id,
            'table_id' => $table?->id,
            'order_type' => $tenant->primary_industry === 'retail' ? 'takeaway' : 'dine_in',
            'order_code' => 'ORD-20261109-001-' . $tenantId,
            'order_date' => '2026-11-09',
            'idempotency_key' => $idempotencyCheck,
            'subtotal_amount' => $price1 * 2,
            'discount_amount' => 0,
            'tax_amount' => (int) round($price1 * 2 * 0.11),
            'service_charge_amount' => 0,
            'order_amount' => (int) round($price1 * 2 * 1.11),
            'cash_received' => (int) ceil(($price1 * 2 * 1.11) / 50000) * 50000,
            'order_change' => ((int) ceil(($price1 * 2 * 1.11) / 50000) * 50000) - (int) round($price1 * 2 * 1.11),
            'order_status' => OrderStatus::Paid,
            'created_at' => Carbon::parse('2026-11-09 09:30:00'),
            'updated_at' => Carbon::parse('2026-11-09 09:30:00'),
        ]);

        OrderItem::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'order_id' => $order1->id,
            'product_id' => $p1->id,
            'qty' => 2,
            'order_price' => $price1,
            'order_subtotal' => $price1 * 2,
            'preparation_status' => PreparationStatus::Ready,
            'created_at' => Carbon::parse('2026-11-09 09:30:00'),
            'updated_at' => Carbon::parse('2026-11-09 09:30:00'),
        ]);

        Payment::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'order_id' => $order1->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => $order1->order_amount,
            'amount_tendered' => $order1->cash_received,
            'change_amount' => $order1->order_change,
            'status' => 'captured',
            'captured_at' => Carbon::parse('2026-11-09 09:30:00'),
            'processed_by' => $cashier->id,
            'idempotency_key' => "op_pay_cash_{$tenantId}_20261109_001",
            'created_at' => Carbon::parse('2026-11-09 09:30:00'),
            'updated_at' => Carbon::parse('2026-11-09 09:30:00'),
        ]);

        // TRX 2: Midtrans QRIS Settled Hari Ini
        $p2 = $sampleProducts[1] ?? $p1;
        $price2 = (int) $p2->product_price;
        $order2 = Order::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'order_type' => 'takeaway',
            'order_code' => 'ORD-20261109-002-' . $tenantId,
            'order_date' => '2026-11-09',
            'idempotency_key' => "op_trx_qris_{$tenantId}_20261109_002",
            'subtotal_amount' => $price2,
            'discount_amount' => 0,
            'tax_amount' => (int) round($price2 * 0.11),
            'service_charge_amount' => 0,
            'order_amount' => (int) round($price2 * 1.11),
            'order_status' => OrderStatus::Paid,
            'created_at' => Carbon::parse('2026-11-09 10:15:00'),
            'updated_at' => Carbon::parse('2026-11-09 10:15:00'),
        ]);

        OrderItem::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'order_id' => $order2->id,
            'product_id' => $p2->id,
            'qty' => 1,
            'order_price' => $price2,
            'order_subtotal' => $price2,
            'preparation_status' => PreparationStatus::Ready,
            'created_at' => Carbon::parse('2026-11-09 10:15:00'),
            'updated_at' => Carbon::parse('2026-11-09 10:15:00'),
        ]);

        Payment::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'order_id' => $order2->id,
            'payment_method' => PaymentMethod::Qris,
            'amount' => $order2->order_amount,
            'reference_number' => 'MID-QRIS-20261109-' . sprintf('%04d', $tenantId),
            'status' => 'captured',
            'captured_at' => Carbon::parse('2026-11-09 10:15:00'),
            'processed_by' => $cashier->id,
            'idempotency_key' => "op_pay_qris_{$tenantId}_20261109_002",
            'created_at' => Carbon::parse('2026-11-09 10:15:00'),
            'updated_at' => Carbon::parse('2026-11-09 10:15:00'),
        ]);

        $this->line("  ✓ Transaksi operasional hari ini (09 Nov 2026) berhasil dibuat di Cabang Utama (Cash & QRIS)");

        app(TenantContext::class)->setStoreId(null);

        return ['count' => 2];
    }

    private function setupAccountingPeriods2026(Tenant $tenant): void
    {
        $tenantId = (int) $tenant->id;
        $periods = [];
        for ($m = 1; $m <= 12; $m++) {
            $periodCode = sprintf('2026-%02d', $m);
            $startDate = Carbon::create(2026, $m, 1)->startOfMonth()->toDateString();
            $endDate = Carbon::create(2026, $m, 1)->endOfMonth()->toDateString();
            // Periode Januari s/d Oktober 2026 CLOSED, November 2026 OPEN
            $status = ($m <= 10) ? 'CLOSED' : 'OPEN';

            DB::table('accounting_periods')->updateOrInsert(
                ['tenant_id' => $tenantId, 'period_code' => $periodCode],
                [
                    'fiscal_year' => 2026,
                    'period_number' => $m,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => $status,
                    'is_adjustment_period' => 0,
                    'closed_at' => ($status === 'CLOSED') ? $endDate . ' 23:59:59' : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
        $this->line("  ✓ Periode Akuntansi 2026 disinkronisasi: 2026-01 s/d 2026-10 CLOSED, 2026-11 OPEN (Aktif).");
    }

    private function renderTenantSummary(Tenant $tenant, array $users, array $activeShifts, array $stock, int $schedules, array $orders, string $rawPassword): void
    {
        $this->table(
            ['Parameter Uji', 'Status / Nilai'],
            [
                ['Tenant ID & Nama', "#{$tenant->id} - {$tenant->name} ({$tenant->primary_industry})"],
                ['Akun Owner Login', $users['owner']->email . " (Password: {$rawPassword} | PIN: 123456)"],
                ['Akun Kasir Demo', $users['kasir']->email . " (Password: {$rawPassword} | PIN: 123456)"],
                ['Katalog Produk Siap Jual', "{$stock['healthy']} produk (Stok {$stock['healthy']} pcs/cabang)"],
                ['Bahan Baku Dapur (F&B)', "{$stock['ingredients']} bahan baku (50.000 unit/cabang)"],
                ['Shift Kasir Aktif Hari Ini (2026-11-09)', count($activeShifts) . " cabang memiliki shift register OPEN (Saldo Awal Rp 500.000)"],
                ['Jadwal Kerja Karyawan (work_schedules)', "{$schedules} jadwal kerja aktif terpasang (01 s/d 15 Nov 2026)"],
                ['Periode Finansial Berjalan', 'Periode 2026-11: OPEN (Buku Besar & Jurnal Aktif)'],
            ]
        );
    }
}
