<?php

// [OMEGA-NODE6] Uji ACID + race condition checkout (TransactionService::createTransaction) di MySQL/MariaDB NYATA | 2026-09-20
// [OMEGA-NODE6] EXTENSION: race condition pada stok bahan baku (BOM/ingredients), integritas
// ledger payments (split payment exact-sum), dan poin loyalti (loyalty_ledger) dalam skenario
// perebutan stok bersamaan -- lihat blok "BOM CHAOS SUITE" di bagian bawah file. | 2026-09-22

declare(strict_types=1);

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
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class)->group('chaos');

beforeAll(function () {
    $connection = (string) (getenv('DB_CONNECTION') ?: 'sqlite');
    $database = (string) getenv('DB_DATABASE');

    if (! in_array($connection, ['mysql', 'mariadb'], true)) {
        Assert::markTestSkipped(
            'Uji chaos butuh MySQL/MariaDB nyata. Contoh: DB_CONNECTION=mysql DB_DATABASE=yovel_pos_test php artisan test --group=chaos'
        );
    }
});

function chaosProduct(int $stock, int $price = 15_000): Product
{
    $category = Category::create(['category_name' => 'Chaos '.Str::random(10)]);

    return Product::create([
        'category_id' => $category->id,
        'product_name' => 'Chaos Latte '.Str::random(6),
        'product_price' => $price,
        'stock' => $stock,
        'is_active' => true,
    ]);
}

function chaosStock(int $productId): int
{
    return (int) DB::table('products')->where('id', $productId)->value('stock');
}

function chaosPayload(array $qtyByProduct, ?string $idempotencyKey = null): array
{
    $items = [];
    foreach ($qtyByProduct as $productId => $qty) {
        $items[] = ['product_id' => $productId, 'quantity' => $qty, 'extra_price' => 0, 'options' => null];
    }

    return [
        'items' => $items,
        'payment_method' => 'cash',
        'cash_received' => 10_000_000,
        'table_id' => null,
        'idempotency_key' => $idempotencyKey,
    ];
}

function chaosDatabaseName(): string
{
    return (string) config('database.connections.'.config('database.default').'.database');
}

function chaosBarrier(int $workers): float
{
    return microtime(true) + 3.0 + (0.5 * $workers);
}

function chaosWorker(string $database, int $userId, array $payload, float $startAt): Closure
{
    return static function () use ($database, $userId, $payload, $startAt): array {
        $default = config('database.default');
        config(["database.connections.{$default}.database" => $database]);
        DB::purge($default);

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

function chaosRace(array $payloads, int $userId): array
{
    $database = chaosDatabaseName();
    $startAt = chaosBarrier(count($payloads));
    $workers = [];
    foreach ($payloads as $payload) {
        $workers[] = chaosWorker($database, $userId, $payload, $startAt);
    }

    return array_values(Concurrency::driver('process')->run($workers));
}

function chaosExpireWorker(string $database, string $orderCode, float $startAt): Closure
{
    return static function () use ($database, $orderCode, $startAt): array {
        $default = config('database.default');
        config(["database.connections.{$default}.database" => $database]);
        DB::purge($default);

        while (microtime(true) < $startAt) {
            usleep(500);
        }

        try {
            app(TransactionService::class)->updateStatusFromMidtransNotification(
                $orderCode,
                'expire',
                null
            );

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'exception' => $e::class, 'message' => $e->getMessage()];
        }
    };
}

it('lockForUpdate NYATA: baris produk dikunci koneksi lain -> timeout, rollback atomik', function () {
    $product = chaosProduct(stock: 1);
    $user = User::factory()->create();
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
            $service->createTransaction(chaosPayload([$product->id => 1]), $user->id);
        } catch (QueryException $e) {
            $caught = $e;
        }

        expect($caught)->toBeInstanceOf(QueryException::class);
        expect($caught->getMessage())->toContain('Lock wait timeout exceeded');
        expect(strtolower($caught->getSql()))->toContain('for update');
        expect(chaosStock($product->id))->toBe(1);
    } finally {
        if ($holder->transactionLevel() > 0) {
            $holder->rollBack();
        }
        DB::purge('chaos_lock_holder');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
    }

    $order = $service->createTransaction(chaosPayload([$product->id => 1]), $user->id);
    expect($order->order_code)->toStartWith('POS-');
    expect(chaosStock($product->id))->toBe(0);
});

it('rollback total: item ke-2 kurang stok -> stok item ke-1 TIDAK boleh berkurang', function () {
    $a = chaosProduct(stock: 5);
    $b = chaosProduct(stock: 1);
    $user = User::factory()->create();

    $attempt = fn () => app(TransactionService::class)->createTransaction(chaosPayload([$a->id => 2, $b->id => 2]), $user->id);
    expect($attempt)->toThrow(ValidationException::class);
    expect(chaosStock($a->id))->toBe(5);
    expect(chaosStock($b->id))->toBe(1);
});

it('overselling mustahil: stok N direbutkan M proses OS paralel', function (int $stock, int $requests) {
    $product = chaosProduct($stock);
    $user = User::factory()->create();

    $results = collect(chaosRace(array_fill(0, $requests, chaosPayload([$product->id => 1])), $user->id));
    $succeeded = $results->where('ok', true);
    $rejected = $results->where('ok', false);

    expect($succeeded)->toHaveCount($stock);
    expect($rejected)->toHaveCount($requests - $stock);
    expect(chaosStock($product->id))->toBe(0);
    expect(Order::count())->toBe($stock);
})->with([
    'double-submit: stok 1 vs 2 request' => [1, 2],
    'stampede: stok 3 vs 8 request' => [3, 8],
]);

// ═══════════════════════════════════════════════════════════════════════
// [OMEGA-NODE6] BOM CHAOS SUITE -- race condition pada stok BAHAN BAKU
// (ingredients.current_stock), integritas ledger `payments` (split payment
// exact-sum), dan integritas ledger `loyalty_ledger` /
// `customer_loyalty_accounts` saat SATU pelanggan diperebutkan oleh BANYAK
// order bersamaan. Menyasar jalur BARU TransactionService::createTransaction()
// (bukan lagi products.stock, tapi Ingredient::lockForUpdate() + bcmath). | 2026-09-22
// ═══════════════════════════════════════════════════════════════════════

/**
 * Bahan baku (raw material) dengan stok terkontrol. Presisi desimal (bcmath)
 * disamakan persis dengan yang dipakai TransactionService, agar assertion
 * bccomp() di bawah tidak pernah "false positive" akibat pembulatan float.
 */
function chaosIngredient(float $stock, string $unit = 'ml'): Ingredient
{
    return Ingredient::create([
        'name' => 'Chaos Bahan '.Str::random(8),
        'unit' => $unit,
        'current_stock' => $stock,
        'cost_per_unit' => 50,
        'reorder_level' => 0,
        'is_active' => true,
    ]);
}

/**
 * Produk BER-RESEP (BOM): begitu satu ingredient di-attach ke product_ingredients,
 * TransactionService berhenti total menyentuh products.stock untuk produk ini --
 * lihat catatan audit di app/Models/Product.php::scopeAvailableForOrder(). Nilai
 * `stock` pada Product di sini sengaja diabaikan (9999, tidak relevan).
 */
function chaosBomProduct(Ingredient $ingredient, float $qtyRequiredPerUnit, int $price = 20_000): Product
{
    $product = chaosProduct(stock: 9999, price: $price);
    $product->ingredients()->attach($ingredient->id, ['quantity_required' => $qtyRequiredPerUnit]);

    return $product;
}

/**
 * Produk TANPA BOM langsung, tapi memiliki 1 Modifier wajib-pilih yang ber-BOM.
 */
function chaosModifierBomProduct(Ingredient $ingredient, float $qtyRequiredPerUnit, int $price = 20_000): array
{
    $product = chaosProduct(stock: 9999, price: $price);

    $group = ModifierGroup::create([
        'name' => 'Chaos Group '.Str::random(6),
        'selection_type' => 'single',
        'is_required' => true,
    ]);

    $product->modifierGroups()->attach($group->id);

    $modifier = Modifier::create([
        'modifier_group_id' => $group->id,
        'name' => 'Chaos Modifier '.Str::random(6),
        'extra_price' => 0,
        'is_active' => true,
    ]);

    $modifier->ingredients()->attach($ingredient->id, ['quantity_required' => $qtyRequiredPerUnit]);

    return [$product, $modifier];
}

function chaosCustomer(): Customer
{
    return Customer::create([
        'name' => 'Chaos Pelanggan '.Str::random(8),
        'is_active' => true,
    ]);
}

/**
 * SATU-SATUNYA cara aman menghitung total tagihan di dalam test: panggil
 * LANGSUNG method privat TransactionService::calculateOrderTotals() lewat
 * Reflection, alih-alih menduplikasi rumus pajak/pembulatan di sini. Jika
 * rumus produksi berubah suatu saat, test ini TIDAK PERNAH "berbohong hijau"
 * karena lupa disinkronkan -- ia otomatis ikut berubah.
 */
function chaosCalculateTotal(int $subtotal): int
{
    $method = new ReflectionMethod(TransactionService::class, 'calculateOrderTotals');
    $method->setAccessible(true);

    $result = $method->invoke(app(TransactionService::class), $subtotal);

    return (int) $result['total_amount'];
}

/**
 * Pecah total tagihan menjadi 2 leg (cash + qris) yang EXACT-SUM. Sekaligus
 * memaksa setiap order dalam race melewati jalur Split Payment (ledger
 * `payments`), bukan cuma jalur legacy payment_method tunggal.
 */
function chaosSplitLegs(int $total): array
{
    $cash = intdiv($total, 2);

    return [
        ['method' => 'cash', 'amount' => $cash],
        ['method' => 'qris', 'amount' => $total - $cash],
    ];
}

function chaosBomPayload(int $productId, array $legs, ?int $customerId = null): array
{
    return [
        'items' => [
            ['product_id' => $productId, 'quantity' => 1, 'extra_price' => 0, 'options' => null],
        ],
        'payments' => $legs,
        'customer_id' => $customerId,
        'table_id' => null,
    ];
}

function chaosModifierBomPayload(int $productId, int $modifierId, array $legs, ?int $customerId = null): array
{
    return [
        'items' => [
            ['product_id' => $productId, 'quantity' => 1, 'extra_price' => 0, 'options' => [['modifier_id' => $modifierId, 'modifier_name' => 'X', 'price' => 0]]],
        ],
        'payments' => $legs,
        'customer_id' => $customerId,
        'table_id' => null,
    ];
}

it('lockForUpdate NYATA pada Ingredient: baris bahan baku dikunci koneksi lain -> timeout, rollback atomik', function () {
    $ingredient = chaosIngredient(100.0);
    $product = chaosBomProduct($ingredient, qtyRequiredPerUnit: 100.0);
    $user = User::factory()->create();
    $service = app(TransactionService::class);

    $default = config('database.default');
    config(['database.connections.chaos_ingredient_lock_holder' => config("database.connections.{$default}")]);
    $holder = DB::connection('chaos_ingredient_lock_holder');

    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    $holder->beginTransaction();

    try {
        $holder->table('ingredients')->where('id', $ingredient->id)->lockForUpdate()->first();

        $caught = null;
        try {
            $service->createTransaction([
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null]],
                'payment_method' => 'cash',
                'cash_received' => 10_000_000,
            ], $user->id);
        } catch (QueryException $e) {
            $caught = $e;
        }

        expect($caught)->toBeInstanceOf(QueryException::class);
        expect($caught->getMessage())->toContain('Lock wait timeout exceeded');
        expect(strtolower($caught->getSql()))->toContain('for update');
        expect(bccomp((string) $ingredient->fresh()->current_stock, '100.0000', 4))->toBe(0);
    } finally {
        if ($holder->transactionLevel() > 0) {
            $holder->rollBack();
        }
        DB::purge('chaos_ingredient_lock_holder');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
    }

    $order = $service->createTransaction([
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null]],
        'payment_method' => 'cash',
        'cash_received' => 10_000_000,
    ], $user->id);

    expect($order->order_code)->toStartWith('POS-');
    expect(bccomp((string) $ingredient->fresh()->current_stock, '0.0000', 4))->toBe(0);
});

it('BOM rollback total: item kedua kekurangan bahan baku -> ingredient item pertama TIDAK boleh berkurang', function () {
    $ingredientA = chaosIngredient(1000.0, 'g');
    $ingredientB = chaosIngredient(50.0, 'ml'); // sengaja kurang dari kebutuhan (100ml)
    $productA = chaosBomProduct($ingredientA, qtyRequiredPerUnit: 50.0);
    $productB = chaosBomProduct($ingredientB, qtyRequiredPerUnit: 100.0);
    $user = User::factory()->create();

    $payload = [
        'items' => [
            ['product_id' => $productA->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null],
            ['product_id' => $productB->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null],
        ],
    ];

    $attempt = fn () => app(TransactionService::class)->createTransaction($payload, $user->id);

    expect($attempt)->toThrow(ValidationException::class);
    // Two-phase check-then-decrement di TransactionService menjamin ini:
    // SELURUH ingredient divalidasi cukup DULU sebelum satu pun di-decrement.
    expect(bccomp((string) $ingredientA->fresh()->current_stock, '1000.0000', 4))->toBe(0);
    expect(bccomp((string) $ingredientB->fresh()->current_stock, '50.0000', 4))->toBe(0);
    expect(Order::count())->toBe(0);
    expect(IngredientStockMovement::count())->toBe(0);
});

it('BOM overselling mustahil: stok bahan baku terbatas direbutkan banyak transaksi paralel', function (float $ingredientStock, float $qtyRequiredPerUnit, int $requests) {
    $ingredient = chaosIngredient($ingredientStock);
    $product = chaosBomProduct($ingredient, $qtyRequiredPerUnit);
    $user = User::factory()->create();

    $total = chaosCalculateTotal($product->product_price);
    $payload = chaosBomPayload($product->id, chaosSplitLegs($total));

    $results = collect(chaosRace(array_fill(0, $requests, $payload), $user->id));
    $succeeded = $results->where('ok', true);
    $rejected = $results->where('ok', false);

    $expectedSuccess = (int) floor($ingredientStock / $qtyRequiredPerUnit);

    expect($succeeded)->toHaveCount($expectedSuccess);
    expect($rejected)->toHaveCount($requests - $expectedSuccess);
    expect(Order::count())->toBe($expectedSuccess);

    // SELF-ADVERSARIAL CHECK: pastikan yang gagal, gagal karena ALASAN YANG
    // BENAR (stok bahan baku habis) -- bukan exception lain yang diam-diam
    // menyamar sebagai "sukses ditolak" (mis. bug tipe data / SQL error).
    $rejected->each(function (array $r) {
        expect($r['exception'])->toBe(ValidationException::class);
        expect($r['message'])->toContain('tidak mencukupi');
    });

    $remaining = bcsub(
        (string) $ingredientStock,
        bcmul((string) $expectedSuccess, (string) $qtyRequiredPerUnit, 4),
        4
    );
    expect(bccomp((string) $ingredient->fresh()->current_stock, $remaining, 4))->toBe(0);

    expect(IngredientStockMovement::where('ingredient_id', $ingredient->id)->count())->toBe($expectedSuccess);
    IngredientStockMovement::where('ingredient_id', $ingredient->id)->get()->each(function ($movement) use ($qtyRequiredPerUnit) {
        // quantity BERTANDA (signed): konsumsi = negatif, presisi 4 desimal.
        expect(bccomp((string) $movement->quantity, '-'.number_format($qtyRequiredPerUnit, 4, '.', ''), 4))->toBe(0);
    });
})->with([
    'double-submit: bahan baku cukup utk 1 vs 2 request' => [100.0, 100.0, 2],
    'stampede: bahan baku cukup utk 4 vs 10 request' => [400.0, 100.0, 10],
]);

it('BOM race: ledger payments (split-payment) & poin loyalti tetap akurat meski diperebutkan banyak kasir sekaligus', function () {
    $ingredientStock = 400.0;   // cukup untuk TEPAT 4 unit produk
    $qtyRequiredPerUnit = 100.0;
    $requests = 10;             // 10 kasir menembak bersamaan, hanya 4 boleh menang

    $ingredient = chaosIngredient($ingredientStock);
    $product = chaosBomProduct($ingredient, $qtyRequiredPerUnit, price: 20_000);
    $customer = chaosCustomer(); // SATU pelanggan yang sama diserbu oleh SEMUA order sukses
    $user = User::factory()->create();

    $total = chaosCalculateTotal($product->product_price);
    $payload = chaosBomPayload($product->id, chaosSplitLegs($total), $customer->id);

    $results = collect(chaosRace(array_fill(0, $requests, $payload), $user->id));
    $succeeded = $results->where('ok', true);

    $expectedSuccess = (int) floor($ingredientStock / $qtyRequiredPerUnit);
    expect($succeeded)->toHaveCount($expectedSuccess);

    // ── LEDGER PAYMENTS: setiap order yang menang HARUS punya leg pembayaran
    //    yang exact-sum dengan tagihannya sendiri -- tidak boleh ada leg yang
    //    hilang, dobel, atau "bocor" ke order lain akibat proses paralel.
    foreach ($succeeded as $result) {
        $order = Order::where('order_code', $result['order_code'])->with('payments')->firstOrFail();
        $capturedTotal = $order->payments
            ->where('status', PaymentStatusEnum::Captured)
            ->sum(fn ($p) => (int) $p->amount);

        expect($order->payments)->toHaveCount(2);
        expect($capturedTotal)->toBe((int) $order->order_amount);
        expect((int) $order->order_amount)->toBe($total);
    }

    // ── LOYALTY LEDGER: pelanggan yang SAMA menerima poin dari BANYAK order
    //    bersamaan. Customer::lockForUpdate() di grantLoyaltyPoints() WAJIB
    //    menyerialkan seluruh earn agar balance_after berantai sempurna --
    //    inilah skenario "lost update" klasik yang paling sering lolos QA.
    $pointsPerOrder = (int) floor(($product->product_price / 1000) * 1.00);
    $expectedTotalPoints = $pointsPerOrder * $expectedSuccess;

    $ledgerRows = LoyaltyLedger::where('customer_id', $customer->id)->orderBy('id')->get();
    expect($ledgerRows)->toHaveCount($expectedSuccess);

    $runningBalance = 0;
    foreach ($ledgerRows as $row) {
        $runningBalance += $pointsPerOrder;
        expect($row->type)->toBe(LoyaltyLedgerTypeEnum::Earn);
        expect((int) $row->points)->toBe($pointsPerOrder);
        // Jika ini gagal (balance_after meloncat/duplikat), berarti dua
        // transaksi sempat membaca current_points yang SAMA sebelum salah
        // satunya commit -- bukti nyata race condition pada ledger poin.
        expect((int) $row->balance_after)->toBe($runningBalance);
    }

    $account = CustomerLoyaltyAccount::where('customer_id', $customer->id)->first();
    expect($account)->not->toBeNull();
    expect((int) $account->current_points)->toBe($expectedTotalPoints);
    expect((int) $account->lifetime_points_earned)->toBe($expectedTotalPoints);
});

it('ingredient_stock_movements bersifat append-only: UPDATE dan DELETE ditolak oleh trigger database', function () {
    $ingredient = chaosIngredient(500.0);
    $product = chaosBomProduct($ingredient, qtyRequiredPerUnit: 100.0);
    $user = User::factory()->create();

    $order = app(TransactionService::class)->createTransaction([
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null]],
        'payment_method' => 'cash',
        'cash_received' => 10_000_000,
    ], $user->id);

    $movement = IngredientStockMovement::where('order_id', $order->id)->firstOrFail();

    // Query BUILDER mentah (bukan Eloquent) SENGAJA dipakai di sini untuk
    // membuktikan proteksinya ada di lapisan DATABASE (trigger), bukan
    // cuma method update()/delete() yang di-override di Model (defense-in-depth
    // yang sama seharusnya berlaku walau seseorang menembak lewat raw SQL/DBA tool).
    expect(fn () => DB::table('ingredient_stock_movements')->where('id', $movement->id)->update(['quantity' => -999]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('ingredient_stock_movements')->where('id', $movement->id)->delete())
        ->toThrow(QueryException::class);

    expect(IngredientStockMovement::find($movement->id))->not->toBeNull();
});

it('BOM overselling mustahil (Modifier Edition): restoreStockForOrder() PATCH FOR M-03 exactly-once', function () {
    $ingredientStock = 100.0;
    $qtyRequiredPerUnit = 100.0;
    $requests = 5; // 5 notification paralel memperebutkan `expire` (M-03 race condition)

    $ingredient = chaosIngredient($ingredientStock);
    [$product, $modifier] = chaosModifierBomProduct($ingredient, $qtyRequiredPerUnit);
    $user = User::factory()->create();
    $customer = chaosCustomer();

    $total = chaosCalculateTotal($product->product_price);

    // Create an un-paid order (qris)
    $payload = chaosModifierBomPayload($product->id, $modifier->id, [], $customer->id);
    $payload['payment_method'] = 'qris';
    $payload['is_self_order_cash'] = false;
    $payload['cash_received'] = null;

    $order = app(TransactionService::class)->createTransaction($payload, $user->id);

    // Verify stock is consumed
    expect(bccomp((string) $ingredient->fresh()->current_stock, '0.0000', 4))->toBe(0);

    $orderCode = $order->order_code;
    $database = chaosDatabaseName();
    $startAt = chaosBarrier($requests);
    $workers = [];

    for ($i = 0; $i < $requests; $i++) {
        $workers[] = chaosExpireWorker($database, $orderCode, $startAt);
    }

    $results = collect(array_values(Concurrency::driver('process')->run($workers)));

    // Assert ingredient stock is exactly restored ONCE
    expect(bccomp((string) $ingredient->fresh()->current_stock, '100.0000', 4))->toBe(0);

    // There should be exactly 1 deduction and 1 restoration
    expect(IngredientStockMovement::where('order_id', $order->id)->count())->toBe(2);
});
