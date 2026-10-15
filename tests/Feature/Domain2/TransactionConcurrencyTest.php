<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\LoyaltyLedgerTypeEnum;
use App\Enums\PaymentStatusEnum;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Models\LoyaltyLedger;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

/**
 * Domain 2: Transaction Concurrency & ACID Integrity Test Suite.
 *
 * Menguji pencegahan race-condition, two-phase lock validation,
 * integritas ACID mutasi stok & ledger pada database MySQL terisolasi.
 */
class TransactionConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected bool $optOutFromDefaultTenant = true;

    private int $tenantId;

    private int $storeId;

    private int $userId;

    private int $shiftId;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        $tenant = Tenant::create(['name' => 'Chaos Tenant '.Str::random(6), 'readiness_status' => 'active']);
        $this->tenantId = (int) $tenant->id;

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Chaos Store '.Str::random(6),
        ]);
        $this->storeId = (int) $store->id;

        $context->setTenantId($this->tenantId);
        $context->setStoreId($this->storeId);

        $user = User::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Chaos Cashier '.Str::random(6),
            'email' => 'cashier-'.uniqid().'@chaos.test',
            'password' => 'password',
        ]);
        $user->stores()->attach($this->storeId, ['tenant_id' => $this->tenantId]);
        $this->userId = (int) $user->id;

        $shift = Shift::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'user_id' => $this->userId,
            'status' => 'open',
            'opening_balance' => 100000,
            'opened_at' => now(),
        ]);
        $this->shiftId = (int) $shift->id;
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function createProduct(int $stock, int $price = 15000): Product
    {
        $category = Category::create([
            'tenant_id' => $this->tenantId,
            'category_name' => 'Chaos '.Str::random(8),
        ]);

        return Product::create([
            'tenant_id' => $this->tenantId,
            'category_id' => $category->id,
            'product_name' => 'Chaos Latte '.Str::random(6),
            'product_price' => $price,
            'stock' => $stock,
        ]);
    }

    private function createIngredient(float $stock, string $unit = 'ml'): Ingredient
    {
        return Ingredient::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Chaos Bahan '.Str::random(8),
            'unit' => $unit,
            'current_stock' => $stock,
            'cost_per_unit' => 50,
            'reorder_level' => 0,
        ]);
    }

    private function createBomProduct(Ingredient $ingredient, float $qtyRequiredPerUnit, int $price = 20000): Product
    {
        $product = $this->createProduct(stock: 9999, price: $price);
        $product->ingredients()->attach($ingredient->id, ['quantity_required' => $qtyRequiredPerUnit]);

        return $product;
    }

    private function createModifierBomProduct(Ingredient $ingredient, float $qtyRequiredPerUnit, int $price = 20000): array
    {
        $product = $this->createProduct(stock: 9999, price: $price);

        $group = ModifierGroup::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Chaos Group '.Str::random(6),
            'selection_type' => 'single',
            'is_required' => true,
        ]);

        $product->modifierGroups()->attach($group->id);

        $modifier = Modifier::create([
            'tenant_id' => $this->tenantId,
            'modifier_group_id' => $group->id,
            'name' => 'Chaos Modifier '.Str::random(6),
            'extra_price' => 0,
        ]);

        $modifier->ingredients()->attach($ingredient->id, ['quantity_required' => $qtyRequiredPerUnit]);

        return [$product, $modifier];
    }

    private function createCustomer(): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Chaos Pelanggan '.Str::random(8),
        ]);
    }

    private function getStock(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('stock');
    }

    private function calculateOrderTotal(int $subtotal): int
    {
        $method = new ReflectionMethod(TransactionService::class, 'calculateOrderTotals');
        $method->setAccessible(true);
        $result = $method->invoke(app(TransactionService::class), $subtotal);

        return (int) $result['total_amount'];
    }

    private function splitPaymentLegs(int $total): array
    {
        $cash = intdiv($total, 2);

        return [
            ['method' => 'cash', 'amount' => $cash],
            ['method' => 'qris', 'amount' => $total - $cash],
        ];
    }

    private function buildPayload(array $qtyByProduct, ?string $idempotencyKey = null): array
    {
        $items = [];
        foreach ($qtyByProduct as $productId => $qty) {
            $items[] = ['product_id' => $productId, 'quantity' => $qty, 'extra_price' => 0, 'options' => null];
        }

        return [
            'items' => $items,
            'payment_method' => 'cash',
            'cash_received' => 10000000,
            'shift_id' => $this->shiftId,
            'table_id' => null,
            'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
        ];
    }

    private function makeWorker(string $database, int $tenantId, int $storeId, int $userId, array $payload, float $startAt): Closure
    {
        return static function () use ($database, $tenantId, $storeId, $userId, $payload, $startAt): array {
            $default = config('database.default');
            config(["database.connections.{$default}.database" => $database]);
            DB::purge($default);

            app(TenantContext::class)->setTenantId($tenantId);
            app(TenantContext::class)->setStoreId($storeId);

            while (microtime(true) < $startAt) {
                usleep(500);
            }

            try {
                $order = app(TransactionService::class)->createTransaction($payload, $userId);

                return ['ok' => true, 'order_code' => $order->order_code, 'exception' => null, 'message' => null];
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'order_code' => null,
                    'exception' => $e::class,
                    'message' => $e instanceof ValidationException
                        ? (string) collect($e->errors())->flatten()->first()
                        : $e->getMessage(),
                ];
            }
        };
    }

    private function runParallelRace(array $payloads): array
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $startAt = microtime(true) + 2.0 + (0.3 * count($payloads));
        $workers = [];

        foreach ($payloads as $p) {
            if (empty($p['idempotency_key'])) {
                $p['idempotency_key'] = (string) Str::uuid();
            }
            $workers[] = $this->makeWorker($database, $this->tenantId, $this->storeId, $this->userId, $p, $startAt);
        }

        return array_values(Concurrency::driver('process')->run($workers));
    }

    /**
     * Test 1: lockForUpdate nyata pada baris produk -> timeout & rollback atomik.
     */
    public function test_lock_for_update_timeout_and_atomic_rollback(): void
    {
        $product = $this->createProduct(stock: 1);
        $service = app(TransactionService::class);

        $default = config('database.default');
        config(['database.connections.chaos_lock_holder' => config("database.connections.{$default}")]);
        $holder = DB::connection('chaos_lock_holder');

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $holder->beginTransaction();

        try {
            $holder->table('products')->where('id', $product->id)->lockForUpdate()->first();
            $caught = null;
            try {
                $service->createTransaction($this->buildPayload([$product->id => 1]), $this->userId);
            } catch (QueryException $e) {
                $caught = $e;
            }

            $this->assertInstanceOf(QueryException::class, $caught);
            $this->assertStringContainsString('Lock wait timeout exceeded', $caught->getMessage());
            $this->assertStringContainsString('for update', strtolower($caught->getSql()));
            $this->assertSame(1, $this->getStock($product->id));
        } finally {
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
            DB::purge('chaos_lock_holder');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $order = $service->createTransaction($this->buildPayload([$product->id => 1]), $this->userId);
        $this->assertStringStartsWith('POS-', $order->order_code);
        $this->assertSame(0, $this->getStock($product->id));
    }

    /**
     * Test 2: Rollback total ketika item kedua kekurangan stok.
     */
    public function test_rollback_total_when_second_item_insufficient_stock(): void
    {
        $a = $this->createProduct(stock: 5);
        $b = $this->createProduct(stock: 1);

        $this->expectException(ValidationException::class);

        try {
            app(TransactionService::class)->createTransaction(
                $this->buildPayload([$a->id => 2, $b->id => 2]),
                $this->userId
            );
        } finally {
            $this->assertSame(5, $this->getStock($a->id));
            $this->assertSame(1, $this->getStock($b->id));
        }
    }

    /**
     * Test 3: lockForUpdate nyata pada baris Ingredient -> timeout & rollback atomik.
     */
    public function test_ingredient_lock_for_update_timeout_and_atomic_rollback(): void
    {
        $ingredient = $this->createIngredient(100.0);
        $product = $this->createBomProduct($ingredient, qtyRequiredPerUnit: 100.0);
        $service = app(TransactionService::class);

        $default = config('database.default');
        config(['database.connections.chaos_ing_holder' => config("database.connections.{$default}")]);
        $holder = DB::connection('chaos_ing_holder');

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $holder->beginTransaction();

        try {
            $holder->table('ingredients')->where('id', $ingredient->id)->lockForUpdate()->first();
            $caught = null;
            try {
                $service->createTransaction([
                    'items' => [['product_id' => $product->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null]],
                    'payment_method' => 'cash',
                    'cash_received' => 10000000,
                    'shift_id' => $this->shiftId,
                    'idempotency_key' => (string) Str::uuid(),
                ], $this->userId);
            } catch (QueryException $e) {
                $caught = $e;
            }

            $this->assertInstanceOf(QueryException::class, $caught);
            $this->assertStringContainsString('Lock wait timeout exceeded', $caught->getMessage());
            $this->assertSame(0, bccomp((string) $ingredient->fresh()->current_stock, '100.0000', 4));
        } finally {
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
            DB::purge('chaos_ing_holder');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $order = $service->createTransaction([
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null]],
            'payment_method' => 'cash',
            'cash_received' => 10000000,
            'shift_id' => $this->shiftId,
            'idempotency_key' => (string) Str::uuid(),
        ], $this->userId);

        $this->assertStringStartsWith('POS-', $order->order_code);
        $this->assertSame(0, bccomp((string) $ingredient->fresh()->current_stock, '0.0000', 4));
    }

    /**
     * Test 4: BOM rollback total: item kedua kekurangan bahan baku.
     */
    public function test_bom_rollback_total_when_second_ingredient_insufficient(): void
    {
        $ingredientA = $this->createIngredient(1000.0, 'g');
        $ingredientB = $this->createIngredient(50.0, 'ml'); // kurang dari kebutuhan 100ml
        $productA = $this->createBomProduct($ingredientA, qtyRequiredPerUnit: 50.0);
        $productB = $this->createBomProduct($ingredientB, qtyRequiredPerUnit: 100.0);

        $payload = [
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null],
                ['product_id' => $productB->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null],
            ],
            'payment_method' => 'cash',
            'cash_received' => 10000000,
            'shift_id' => $this->shiftId,
            'idempotency_key' => (string) Str::uuid(),
        ];

        try {
            app(TransactionService::class)->createTransaction($payload, $this->userId);
            $this->fail('Harus melempar ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tidak mencukupi', $e->getMessage());
        }

        $this->assertSame(0, bccomp((string) $ingredientA->fresh()->current_stock, '1000.0000', 4));
        $this->assertSame(0, bccomp((string) $ingredientB->fresh()->current_stock, '50.0000', 4));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, IngredientStockMovement::count());
    }

    /**
     * Test 5: ingredient_stock_movements append-only: UPDATE & DELETE ditolak database trigger.
     */
    public function test_ingredient_stock_movements_append_only_triggers(): void
    {
        $ingredient = $this->createIngredient(500.0);
        $product = $this->createBomProduct($ingredient, qtyRequiredPerUnit: 100.0);

        $order = app(TransactionService::class)->createTransaction([
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null]],
            'payment_method' => 'cash',
            'cash_received' => 10000000,
            'shift_id' => $this->shiftId,
            'idempotency_key' => (string) Str::uuid(),
        ], $this->userId);

        $movement = IngredientStockMovement::where('order_id', $order->id)->firstOrFail();

        $updateCaught = false;
        try {
            DB::table('ingredient_stock_movements')->where('id', $movement->id)->update(['quantity' => -999]);
        } catch (QueryException $e) {
            $updateCaught = true;
        }
        $this->assertTrue($updateCaught, 'Trigger database harus menolak UPDATE pada ingredient_stock_movements');

        $deleteCaught = false;
        try {
            DB::table('ingredient_stock_movements')->where('id', $movement->id)->delete();
        } catch (QueryException $e) {
            $deleteCaught = true;
        }
        $this->assertTrue($deleteCaught, 'Trigger database harus menolak DELETE pada ingredient_stock_movements');

        $this->assertNotNull(IngredientStockMovement::find($movement->id));
    }

    /**
     * Test 6: Overselling mustahil pada stok biasa yang diperebutkan proses paralel.
     */
    public function test_overselling_parallel_race(): void
    {
        $stock = 2;
        $requests = 5;
        $product = $this->createProduct($stock);

        $payloads = [];
        for ($i = 0; $i < $requests; $i++) {
            $payloads[] = $this->buildPayload([$product->id => 1]);
        }

        $results = collect($this->runParallelRace($payloads));
        $succeeded = $results->where('ok', true);
        $rejected = $results->where('ok', false);

        $this->assertCount($stock, $succeeded);
        $this->assertCount($requests - $stock, $rejected);
        $this->assertSame(0, $this->getStock($product->id));
        $this->assertSame($stock, Order::count());
    }

    /**
     * Test 7: BOM Overselling mustahil: stok bahan baku terbatas direbutkan proses paralel.
     */
    public function test_bom_overselling_parallel_race(): void
    {
        $ingredientStock = 200.0;
        $qtyRequiredPerUnit = 100.0;
        $requests = 5;

        $ingredient = $this->createIngredient($ingredientStock);
        $product = $this->createBomProduct($ingredient, $qtyRequiredPerUnit);

        $total = $this->calculateOrderTotal($product->product_price);
        $payloads = [];
        for ($i = 0; $i < $requests; $i++) {
            $payloads[] = [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null],
                ],
                'payments' => $this->splitPaymentLegs($total),
                'shift_id' => $this->shiftId,
                'idempotency_key' => (string) Str::uuid(),
            ];
        }

        $results = collect($this->runParallelRace($payloads));
        $succeeded = $results->where('ok', true);
        $rejected = $results->where('ok', false);

        $expectedSuccess = (int) floor($ingredientStock / $qtyRequiredPerUnit);
        $this->assertCount($expectedSuccess, $succeeded);
        $this->assertCount($requests - $expectedSuccess, $rejected);
        $this->assertSame($expectedSuccess, Order::count());

        $this->assertSame(0, bccomp((string) $ingredient->fresh()->current_stock, '0.0000', 4));
        $this->assertSame($expectedSuccess, IngredientStockMovement::where('ingredient_id', $ingredient->id)->count());
    }

    /**
     * Test 8: BOM Split Payment & Loyalty Ledger akurat saat pelanggan diperebutkan transaksi paralel.
     */
    public function test_bom_split_payment_and_loyalty_race(): void
    {
        $ingredientStock = 200.0;
        $qtyRequiredPerUnit = 100.0;
        $requests = 4;

        $ingredient = $this->createIngredient($ingredientStock);
        $product = $this->createBomProduct($ingredient, $qtyRequiredPerUnit, price: 20000);
        $customer = $this->createCustomer();

        $total = $this->calculateOrderTotal($product->product_price);
        $payloads = [];
        for ($i = 0; $i < $requests; $i++) {
            $payloads[] = [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null],
                ],
                'payments' => $this->splitPaymentLegs($total),
                'customer_id' => $customer->id,
                'shift_id' => $this->shiftId,
                'idempotency_key' => (string) Str::uuid(),
            ];
        }

        $results = collect($this->runParallelRace($payloads));
        $succeeded = $results->where('ok', true);
        $expectedSuccess = 2;

        $this->assertCount($expectedSuccess, $succeeded);

        // Verifikasi setiap order sukses memiliki split payment exact-sum
        foreach ($succeeded as $res) {
            $order = Order::where('order_code', $res['order_code'])->with('payments')->firstOrFail();
            $capturedTotal = $order->payments
                ->where('status', PaymentStatusEnum::Captured)
                ->sum(fn ($p) => (int) $p->amount);

            $this->assertCount(2, $order->payments);
            $this->assertSame((int) $order->order_amount, $capturedTotal);
            $this->assertSame($total, (int) $order->order_amount);
        }

        // Verifikasi Loyalty Ledger urut & tidak hilang (lost updates)
        $pointsPerOrder = (int) floor(($product->product_price / 1000) * 1.00);
        $ledgerRows = LoyaltyLedger::where('customer_id', $customer->id)->orderBy('id')->get();
        $this->assertCount($expectedSuccess, $ledgerRows);

        $running = 0;
        foreach ($ledgerRows as $row) {
            $running += $pointsPerOrder;
            $this->assertSame(LoyaltyLedgerTypeEnum::Earn, $row->type);
            $this->assertSame($pointsPerOrder, (int) $row->points);
            $this->assertSame($running, (int) $row->balance_after);
        }

        $account = CustomerLoyaltyAccount::where('customer_id', $customer->id)->first();
        $this->assertNotNull($account);
        $this->assertSame($pointsPerOrder * $expectedSuccess, (int) $account->current_points);
    }
}
