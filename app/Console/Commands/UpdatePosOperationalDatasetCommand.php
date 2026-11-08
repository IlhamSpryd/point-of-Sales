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
                            {--tenant=2 : Target tenant ID (default 2: Apeiron Coffee & Kitchen)}
                            {--store=2 : Target primary store ID (default 2: Apeiron Senopati)}
                            {--password=password123 : Uniform password for demo/test accounts}
                            {--dry-run : Simulate execution without persisting changes}';

    protected $description = 'Refresh POS operational dataset with healthy stocks, active shifts today, realistic transactions up to today, and Midtrans Gateway test records.';

    private bool $isDryRun = false;

    public function handle(CashierPinService $pinService): int
    {
        $this->isDryRun = (bool) $this->option('dry-run');
        $tenantId = (int) $this->option('tenant');
        $storeId = (int) $this->option('store');
        $rawPassword = (string) $this->option('password');

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant ID #{$tenantId} tidak ditemukan!");

            return self::FAILURE;
        }

        app(TenantContext::class)->setTenantId($tenantId);

        $store = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $storeId)->first();
        if (! $store) {
            $this->error("Store ID #{$storeId} tidak ditemukan pada Tenant #{$tenantId}!");

            return self::FAILURE;
        }

        app(TenantContext::class)->setStoreId($storeId);

        $this->info('');
        $this->info('╔═══════════════════════════════════════════════════════════════════════════╗');
        $this->info('║       APEIRON POS — OPERATIONAL DATASET REFRESH & RESTOCK ENGINE          ║');
        $this->info("║  Tenant: #{$tenantId} ({$tenant->name}) | Store: #{$storeId} ({$store->name})  ║");
        $this->info('╚═══════════════════════════════════════════════════════════════════════════╝');
        $this->info('');

        if ($this->isDryRun) {
            $this->warn('⚠️  MODE SIMULASI (DRY-RUN): Tidak ada perubahan yang akan disimpan ke basis data.');
        }

        try {
            return DB::transaction(function () use ($tenant, $store, $rawPassword, $pinService) {
                // ── 1. Sinkronisasi Akun Pengujian & Akses Cabang ──────────────────
                $this->info('━━━ 1. Sinkronisasi Akun Pengguna, PIN & Hak Akses Cabang ━━━');
                $users = $this->provisionUsers($tenant, $rawPassword, $pinService);

                // ── 2. Restock Produk & Bahan Baku (Katalog Sehat & Edge Cases) ────
                $this->info('');
                $this->info('━━━ 2. Restock Produk, Bahan Baku & Penyelarasan Saldo Ledger ━━━');
                $stockSummary = $this->restockProductsAndIngredients($tenant);

                // ── 3. Pengelolaan Shift Kasir (Shift Hari Ini Aktif) ──────────────
                $this->info('');
                $this->info('━━━ 3. Penataan Siklus Shift & Pembukaan Shift Kasir Hari Ini ━━━');
                $activeShift = $this->setupActiveShift($tenant, $store, $users['kasir']);

                // ── 4. Transaksi Terkini Sampai Hari Ini & Midtrans Gateway ────────
                $this->info('');
                $this->info('━━━ 4. Pembuatan Transaksi Terkini, Midtrans Gateway & KDS ━━━');
                $ordersSummary = $this->generateRecentTransactions($tenant, $store, $users, $activeShift);

                // ── 5. Ringkasan & Panduan Pengujian ──────────────────────────────
                $this->info('');
                $this->info('━━━ 5. Ringkasan Kredensial & Kesiapan Pengujian ━━━');
                $this->renderSummary($users, $activeShift, $stockSummary, $ordersSummary, $rawPassword);

                if ($this->isDryRun) {
                    $this->warn('Perubahan dibatalkan karena opsi --dry-run.');
                    throw new \RuntimeException('ROLLBACK_DRY_RUN');
                }

                return self::SUCCESS;
            }, 3);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'ROLLBACK_DRY_RUN') {
                return self::SUCCESS;
            }

            throw $e;
        }
    }

    /**
     * @return array<string, User>
     */
    private function provisionUsers(Tenant $tenant, string $rawPassword, CashierPinService $pinService): array
    {
        $tenantId = (int) $tenant->id;
        $hashedPassword = Hash::make($rawPassword);
        $allStores = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();

        $roleOwner = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', 'Owner')->firstOrFail();
        $roleManager = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', 'Manager')->firstOrFail();
        $roleSupervisor = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', 'Supervisor')->firstOrFail();
        $roleKasir = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', 'Kasir')->firstOrFail();

        $accountsToProvision = [
            'kasir' => [
                'email' => 'kasir@apeiron.test',
                'name' => 'Sari Kasir Apeiron',
                'role_id' => $roleKasir->id,
                'pin' => '123456',
                'phone' => '081211112222',
            ],
            'supervisor' => [
                'email' => 'supervisor@apeiron.test',
                'name' => 'Fitri Supervisor Apeiron',
                'role_id' => $roleSupervisor->id,
                'pin' => '654321',
                'phone' => '081233334444',
            ],
            'manager' => [
                'email' => 'manager@apeiron.test',
                'name' => 'Rian Manager Apeiron',
                'role_id' => $roleManager->id,
                'pin' => '654321',
                'phone' => '081255556666',
            ],
            'owner' => [
                'email' => 'ilhamsepriyadi8@gmail.com',
                'name' => 'Ilham Sepriyadi',
                'role_id' => $roleOwner->id,
                'pin' => '112233',
                'phone' => '081277778888',
            ],
        ];

        $provisioned = [];

        foreach ($accountsToProvision as $key => $spec) {
            $user = User::withoutGlobalScopes()->where('email', $spec['email'])->first();

            if ($user === null) {
                $user = new User();
                $user->tenant_id = $tenantId;
                $user->email = $spec['email'];
            }

            $user->tenant_id = $tenantId;
            $user->name = $spec['name'];
            $user->role_id = $spec['role_id'];
            $user->password = $hashedPassword;
            $user->phone_number = $spec['phone'];
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->save();

            $pinService->setPin($user, $spec['pin']);

            // Petakan cabang berdasarkan prinsip tata kelola tier-1:
            // - Kasir: tepat 1 home-branch (cabang utama)
            // - Supervisor: multi-cabang regional (2 cabang pertama)
            // - Manager & Owner: seluruh cabang organisasi
            $targetStores = match ($key) {
                'kasir' => $allStores->take(1),
                'supervisor' => $allStores->take(2),
                default => $allStores,
            };

            DB::table('user_stores')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->whereNotIn('store_id', $targetStores->pluck('id'))
                ->delete();

            foreach ($targetStores as $s) {
                DB::table('user_stores')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'user_id' => $user->id, 'store_id' => $s->id],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }

            $provisioned[$key] = $user;
            $this->line("  ✓ User ready: [{$user->name}] <{$user->email}> | Role: {$user->role->name} | PIN: {$spec['pin']}");
        }

        return $provisioned;
    }

    /**
     * @return array{healthy: int, low: int, out: int, ingredients: int}
     */
    private function restockProductsAndIngredients(Tenant $tenant): array
    {
        $tenantId = (int) $tenant->id;
        $stores = Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
        $now = now();

        $products = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
        $ingredients = Ingredient::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();

        $healthyCount = 0;
        $lowCount = 0;
        $outCount = 0;

        foreach ($products as $index => $product) {
            $stock = 100;

            // Edge cases untuk validasi visual POS:
            // 1 produk low stock (3 pcs) & 1 produk out of stock (0 pcs)
            if ($index === 0 || str_contains(strtolower($product->product_name), 'cold brew')) {
                $stock = 3;
                $lowCount++;
            } elseif ($index === 1 || str_contains(strtolower($product->product_name), 'tiramisu')) {
                $stock = 0;
                $outCount++;
            } else {
                $healthyCount++;
            }

            $product->stock = $stock;
            $product->track_inventory = true;
            $product->is_active = true;
            $product->status = 'active';
            $product->save();

            foreach ($stores as $s) {
                DB::table('product_stock_balances')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'store_id' => $s->id, 'product_id' => $product->id],
                    [
                        'quantity' => $stock,
                        'baseline_quantity' => $stock,
                        'baseline_movement_id' => 0,
                        'min_stock_level' => 5,
                        'reorder_point' => 15,
                        'max_stock_level' => 500,
                        'average_unit_cost' => (float) ($product->product_price * 0.4),
                        'last_unit_cost' => (float) ($product->product_price * 0.4),
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                if ($stock > 0) {
                    $idempotencyKey = "op_restock_prod_{$product->id}_{$s->id}_".date('Ymd');
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
                            'quantity' => $stock,
                            'balance_before' => '0.0000',
                            'balance_after' => (string) $stock,
                            'unit_cost' => (string) ($product->product_price * 0.4),
                            'reason' => 'Operational Restock Batch - Full Ready Catalog',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        }

        // Restock Bahan Baku
        foreach ($ingredients as $ing) {
            $ing->current_stock = '45000.0000';
            $ing->is_active = true;
            $ing->save();

            foreach ($stores as $s) {
                DB::table('ingredient_stock_balances')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'store_id' => $s->id, 'ingredient_id' => $ing->id],
                    [
                        'quantity' => 45000,
                        'baseline_quantity' => 45000,
                        'baseline_movement_id' => 0,
                        'min_stock_level' => 2000,
                        'reorder_point' => 5000,
                        'max_stock_level' => 100000,
                        'average_unit_cost' => $ing->cost_per_unit ?? 0.05,
                        'last_unit_cost' => $ing->cost_per_unit ?? 0.05,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                $idempotencyKey = "op_restock_ing_{$ing->id}_{$s->id}_".date('Ymd');
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
                        'quantity' => 45000,
                        'unit_cost' => $ing->cost_per_unit ?? 0.05,
                        'reason' => 'Operational Restock Batch - Raw Materials',
                        'created_at' => $now,
                    ]);
                }
            }
        }

        // Perpanjang masa berlaku diskon agar aktif
        DB::table('discounts')
            ->where('tenant_id', $tenantId)
            ->update([
                'is_active' => 1,
                'valid_until' => '2027-12-31 23:59:59',
                'updated_at' => $now,
            ]);

        $this->line("  ✓ Products restocked: {$healthyCount} healthy, {$lowCount} low stock, {$outCount} out of stock");
        $this->line("  ✓ Ingredients restocked: {$ingredients->count()} items @ 45.000 units across {$stores->count()} stores");
        $this->line('  ✓ Promo discounts refreshed & activated until 31 Dec 2027');

        return [
            'healthy' => $healthyCount,
            'low' => $lowCount,
            'out' => $outCount,
            'ingredients' => $ingredients->count(),
        ];
    }

    private function setupActiveShift(Tenant $tenant, Store $store, User $cashier): Shift
    {
        $tenantId = (int) $tenant->id;
        $storeId = (int) $store->id;
        $today = Carbon::today();

        // 1. Tutup shift lama yang masih open dari hari-hari sebelumnya
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
            $stale->notes = 'Penutupan otomatis shift masa lalu untuk sinkronisasi dataset operasional.';
            $stale->save();
        }

        // 2. Ambil terminal register utama (Device)
        $device = Device::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('device_type', 'pos_terminal')
            ->first();

        if (! $device) {
            $device = Device::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'device_name' => 'POS Terminal Senopati 01',
                'device_code' => 'POS-SEN-01',
                'device_type' => 'pos_terminal',
                'device_secret' => Str::random(32),
                'status' => 'active',
                'is_active' => true,
            ]);
        }

        // 3. Pastikan tidak ada shift open lain yang memblokir device ini
        Shift::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('status', 'open')
            ->where('id', '!=', 0)
            ->update([
                'status' => 'closed',
                'closed_at' => now()->subHours(4),
                'closing_balance' => 500000,
                'expected_cash' => 500000,
                'cash_difference' => 0,
                'close_type' => 'normal',
            ]);

        // 4. Buka shift kasir hari ini (Aktif & Siap Transaksi)
        $activeShift = Shift::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('user_id', $cashier->id)
            ->where('status', 'open')
            ->first();

        if (! $activeShift) {
            $activeShift = Shift::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'user_id' => $cashier->id,
                'active_cashier_id' => $cashier->id,
                'device_id' => $device->id,
                'opening_balance' => 500000,
                'expected_cash' => 500000,
                'status' => 'open',
                'opened_at' => now()->startOfDay()->addHours(14),
                'notes' => 'Shift Kasir Operasional Aktif Hari Ini (Siap Transaksi POS)',
            ]);
        }

        $this->line("  ✓ Active Shift Ready: Shift #{$activeShift->id} | Kasir: {$cashier->name} | Device: {$device->device_name} | Saldo Awal: Rp ".number_format((float) $activeShift->opening_balance, 0, ',', '.'));

        return $activeShift;
    }

    /**
     * @return array{cash: int, card: int, midtrans_settled: int, midtrans_pending: int, split: int}
     */
    private function generateRecentTransactions(Tenant $tenant, Store $store, array $users, Shift $shift): array
    {
        $tenantId = (int) $tenant->id;
        $storeId = (int) $store->id;
        $cashier = $users['kasir'];

        $coffeeProducts = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('stock', '>', 5)
            ->take(5)
            ->get();

        $table = Table::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('store_id', $storeId)->first();
        $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        $stats = ['cash' => 0, 'card' => 0, 'midtrans_settled' => 0, 'midtrans_pending' => 0, 'split' => 0];

        $existingOrder = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', 'op_trx_cash_'.date('Ymd').'_001')
            ->first();

        if ($existingOrder) {
            $this->line('  ℹ Transaksi pengujian hari ini sudah ada, melewati pembuatan transaksi duplikat.');
            return ['cash' => 1, 'card' => 1, 'midtrans_settled' => 1, 'midtrans_pending' => 1, 'split' => 1];
        }

        // ── TRX 1: Tunai Hari Ini (Exact Cash Received & Change) ──────────────
        $order1 = Order::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'customer_id' => $customer?->id,
            'table_id' => $table?->id,
            'order_type' => 'dine_in',
            'order_code' => 'AP-'.date('Ymd').'-0001',
            'order_date' => date('Y-m-d'),
            'idempotency_key' => 'op_trx_cash_'.date('Ymd').'_001',
            'subtotal_amount' => 66000,
            'discount_amount' => 0,
            'tax_amount' => 7260,
            'service_charge_amount' => 0,
            'order_amount' => 73260,
            'cash_received' => 100000,
            'order_change' => 26740,
            'order_status' => OrderStatus::Paid,
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        $item1 = $coffeeProducts[0] ?? null;
        if ($item1) {
            OrderItem::create([
                'tenant_id' => $tenantId,
                'order_id' => $order1->id,
                'product_id' => $item1->id,
                'qty' => 2,
                'order_price' => $item1->product_price,
                'order_subtotal' => $item1->product_price * 2,
                'preparation_status' => PreparationStatus::Ready,
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(3),
            ]);
        }

        Payment::create([
            'tenant_id' => $tenantId,
            'order_id' => $order1->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 73260,
            'amount_tendered' => 100000,
            'change_amount' => 26740,
            'status' => 'captured',
            'captured_at' => now()->subHours(3),
            'processed_by' => $cashier->id,
            'idempotency_key' => 'op_pay_cash_'.date('Ymd').'_001',
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);
        $stats['cash']++;

        // ── TRX 2: Kartu EDC Hari Ini (Approval Code) ─────────────────────────
        $order2 = Order::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'order_type' => 'takeaway',
            'order_code' => 'AP-'.date('Ymd').'-0002',
            'order_date' => date('Y-m-d'),
            'idempotency_key' => 'op_trx_card_'.date('Ymd').'_002',
            'subtotal_amount' => 76000,
            'discount_amount' => 0,
            'tax_amount' => 8360,
            'service_charge_amount' => 0,
            'order_amount' => 84360,
            'order_status' => OrderStatus::Paid,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $item2 = $coffeeProducts[1] ?? null;
        if ($item2) {
            OrderItem::create([
                'tenant_id' => $tenantId,
                'order_id' => $order2->id,
                'product_id' => $item2->id,
                'qty' => 2,
                'order_price' => $item2->product_price,
                'order_subtotal' => $item2->product_price * 2,
                'preparation_status' => PreparationStatus::Ready,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ]);
        }

        Payment::create([
            'tenant_id' => $tenantId,
            'order_id' => $order2->id,
            'payment_method' => PaymentMethod::Card,
            'amount' => 84360,
            'reference_number' => 'BCA-TRACE-88910',
            'approval_code' => 'APP-BCA-9812',
            'status' => 'captured',
            'captured_at' => now()->subHours(2),
            'processed_by' => $cashier->id,
            'idempotency_key' => 'op_pay_card_'.date('Ymd').'_002',
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
        $stats['card']++;

        // ── TRX 3: Midtrans Gateway Succeeded (Dynamic QRIS / Snap) ───────────
        $order3 = Order::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'order_type' => 'dine_in',
            'table_id' => $table?->id,
            'order_code' => 'AP-'.date('Ymd').'-0003',
            'order_date' => date('Y-m-d'),
            'idempotency_key' => 'op_trx_midtrans_settled_'.date('Ymd').'_003',
            'subtotal_amount' => 100000,
            'discount_amount' => 10000,
            'tax_amount' => 9900,
            'service_charge_amount' => 0,
            'order_amount' => 99900,
            'snap_token' => 'snap-settled-'.Str::random(24),
            'order_status' => OrderStatus::Paid,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $item3 = $coffeeProducts[2] ?? null;
        if ($item3) {
            OrderItem::create([
                'tenant_id' => $tenantId,
                'order_id' => $order3->id,
                'product_id' => $item3->id,
                'qty' => 2,
                'order_price' => 50000,
                'order_subtotal' => 100000,
                'preparation_status' => PreparationStatus::Ready,
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ]);
        }

        PaymentAttempt::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'order_id' => $order3->id,
            'provider' => 'midtrans',
            'payment_method' => 'qris',
            'provider_order_id' => $order3->order_code,
            'provider_transaction_id' => 'MID-TRX-'.date('Ymd').'-0003',
            'amount' => 99900,
            'currency' => 'IDR',
            'status' => PaymentAttemptStatus::Succeeded,
            'settled_at' => now()->subHour(),
            'idempotency_key' => 'op_attempt_settled_'.date('Ymd').'_003',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        Payment::create([
            'tenant_id' => $tenantId,
            'order_id' => $order3->id,
            'payment_method' => PaymentMethod::Qris,
            'amount' => 99900,
            'reference_number' => 'MID-TRX-'.date('Ymd').'-0003',
            'status' => 'captured',
            'captured_at' => now()->subHour(),
            'processed_by' => $cashier->id,
            'idempotency_key' => 'op_pay_midtrans_'.date('Ymd').'_003',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        $stats['midtrans_settled']++;

        // ── TRX 4: Midtrans Gateway PENDING (Siap Diuji Sync oleh Kasir) ───────
        $order4 = Order::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'order_type' => 'takeaway',
            'order_code' => 'AP-'.date('Ymd').'-0004',
            'order_date' => date('Y-m-d'),
            'idempotency_key' => 'op_trx_midtrans_pending_'.date('Ymd').'_004',
            'subtotal_amount' => 35000,
            'discount_amount' => 0,
            'tax_amount' => 3850,
            'service_charge_amount' => 0,
            'order_amount' => 38850,
            'snap_token' => 'snap-pending-'.Str::random(24),
            'order_status' => OrderStatus::Pending,
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
        ]);

        $item4 = $coffeeProducts[3] ?? null;
        if ($item4) {
            OrderItem::create([
                'tenant_id' => $tenantId,
                'order_id' => $order4->id,
                'product_id' => $item4->id,
                'qty' => 1,
                'order_price' => 35000,
                'order_subtotal' => 35000,
                'preparation_status' => PreparationStatus::Pending,
                'created_at' => now()->subMinutes(15),
                'updated_at' => now()->subMinutes(15),
            ]);
        }

        PaymentAttempt::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'order_id' => $order4->id,
            'provider' => 'midtrans',
            'payment_method' => 'qris',
            'provider_order_id' => $order4->order_code,
            'amount' => 38850,
            'currency' => 'IDR',
            'status' => PaymentAttemptStatus::PendingProvider,
            'idempotency_key' => 'op_attempt_pending_'.date('Ymd').'_004',
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
        ]);
        $stats['midtrans_pending']++;

        // ── TRX 5: Pesanan Aktif Kitchen Display System (KDS pending/brewing) ──
        $order5 = Order::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'order_type' => 'dine_in',
            'table_id' => $table?->id,
            'order_code' => 'AP-'.date('Ymd').'-0005',
            'order_date' => date('Y-m-d'),
            'idempotency_key' => 'op_trx_kds_live_'.date('Ymd').'_005',
            'subtotal_amount' => 68000,
            'discount_amount' => 0,
            'tax_amount' => 7480,
            'service_charge_amount' => 0,
            'order_amount' => 75480,
            'order_status' => OrderStatus::Paid,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        if ($item1) {
            OrderItem::create([
                'tenant_id' => $tenantId,
                'order_id' => $order5->id,
                'product_id' => $item1->id,
                'qty' => 1,
                'order_price' => 33000,
                'order_subtotal' => 33000,
                'preparation_status' => PreparationStatus::Brewing, // Sedang disiapkan barista
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(2),
            ]);
        }
        if ($item2) {
            OrderItem::create([
                'tenant_id' => $tenantId,
                'order_id' => $order5->id,
                'product_id' => $item2->id,
                'qty' => 1,
                'order_price' => 35000,
                'order_subtotal' => 35000,
                'preparation_status' => PreparationStatus::Pending, // Masuk antrean dapur
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
            ]);
        }

        Payment::create([
            'tenant_id' => $tenantId,
            'order_id' => $order5->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 75480,
            'amount_tendered' => 100000,
            'change_amount' => 24520,
            'status' => 'captured',
            'captured_at' => now()->subMinutes(5),
            'processed_by' => $cashier->id,
            'idempotency_key' => 'op_pay_cash_kds_'.date('Ymd').'_005',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        // ── TRX 6: Split Payment (Tunai + QRIS) ───────────────────────────────
        $order6 = Order::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'order_type' => 'dine_in',
            'order_code' => 'AP-'.date('Ymd').'-0006',
            'order_date' => date('Y-m-d'),
            'idempotency_key' => 'op_trx_split_'.date('Ymd').'_006',
            'subtotal_amount' => 100000,
            'discount_amount' => 0,
            'tax_amount' => 11000,
            'service_charge_amount' => 0,
            'order_amount' => 111000,
            'cash_received' => 60000,
            'order_change' => 0,
            'order_status' => OrderStatus::Paid,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        if ($item1) {
            OrderItem::create([
                'tenant_id' => $tenantId,
                'order_id' => $order6->id,
                'product_id' => $item1->id,
                'qty' => 3,
                'order_price' => 33333,
                'order_subtotal' => 100000,
                'preparation_status' => PreparationStatus::Ready,
                'created_at' => now()->subMinutes(2),
                'updated_at' => now()->subMinutes(2),
            ]);
        }

        Payment::create([
            'tenant_id' => $tenantId,
            'order_id' => $order6->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 60000,
            'amount_tendered' => 60000,
            'change_amount' => 0,
            'status' => 'captured',
            'captured_at' => now()->subMinutes(2),
            'processed_by' => $cashier->id,
            'idempotency_key' => 'op_pay_split_1_'.date('Ymd').'_006',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        Payment::create([
            'tenant_id' => $tenantId,
            'order_id' => $order6->id,
            'payment_method' => PaymentMethod::Qris,
            'amount' => 51000,
            'reference_number' => 'QRIS-SPLIT-9921',
            'status' => 'captured',
            'captured_at' => now()->subMinutes(2),
            'processed_by' => $cashier->id,
            'idempotency_key' => 'op_pay_split_2_'.date('Ymd').'_006',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        $stats['split']++;

        $this->line("  ✓ Generated recent operational transactions for today (".date('d M Y')."):");
        $this->line("    • {$stats['cash']} Cash transaction with exact cash_received & order_change");
        $this->line("    • {$stats['card']} EDC Card transaction with bank approval code");
        $this->line("    • {$stats['midtrans_settled']} Midtrans Dynamic QRIS settled order");
        $this->line("    • {$stats['midtrans_pending']} Midtrans pending order with snap_token (ready to test cashier sync)");
        $this->line("    • {$stats['split']} Multi-leg Split Payment transaction (Cash + QRIS)");
        $this->line('    • 2 Live preparation items visible on KDS board (/kds)');

        return $stats;
    }

    private function renderSummary(array $users, Shift $shift, array $stock, array $orders, string $rawPassword): void
    {
        $this->table(
            ['Akun Uji', 'Email', 'Password', 'PIN Kasir / Spv', 'Peran (Role)'],
            [
                ['Kasir Utama', $users['kasir']->email, $rawPassword, '123456', 'Kasir POS (Direct to /transaction)'],
                ['Supervisor', $users['supervisor']->email, $rawPassword, '654321', 'Supervisor (Approval Diskon & Void)'],
                ['Manager Cabang', $users['manager']->email, $rawPassword, '654321', 'Manager Operasional'],
                ['Owner Bisnis', $users['owner']->email, $rawPassword, '112233', 'Owner (Full Access)'],
            ]
        );

        $this->table(
            ['Parameter', 'Nilai / Status'],
            [
                ['Shift Aktif Hari Ini', "Shift #{$shift->id} (Status: OPEN, Saldo Awal: Rp 500.000)"],
                ['Katalog Produk Sehat', "{$stock['healthy']} produk (Stok 100 pcs)"],
                ['Edge Case Low Stock', "{$stock['low']} produk (Stok 3 pcs - Badge Kuning)"],
                ['Edge Case Out of Stock', "{$stock['out']} produk (Stok 0 pcs - Badge Merah)"],
                ['Bahan Baku & Resep F&B', "{$stock['ingredients']} bahan (45.000 unit/bahan)"],
                ['Transaksi Hari Ini (2026-11-08)', ($orders['cash'] + $orders['card'] + $orders['midtrans_settled'] + $orders['midtrans_pending'] + $orders['split']).' transaksi baru'],
                ['Live Kitchen Tickets (KDS)', '2 tiket pesanan aktif pada route /kds'],
                ['Pesanan Pending Midtrans', '1 pesanan pending siap diuji via tombol "Sync Midtrans Status"'],
            ]
        );

        $this->info('✅ DATASET OPERASIONAL TELAH BERHASIL DIPERBARUI & SIAP DIUJI SECARA PROFESIONAL!');
    }
}
