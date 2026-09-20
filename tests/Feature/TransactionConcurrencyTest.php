<?php

// [OMEGA-NODE6] Uji ACID + race condition checkout (TransactionService::createTransaction) di MySQL/MariaDB NYATA | 2026-09-20

declare(strict_types=1);

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class)->group('chaos');

beforeAll(function () {
    $connection = (string) (getenv('DB_CONNECTION') ?: 'sqlite');$database = (string) getenv('DB_DATABASE');

    if (! in_array($connection, ['mysql', 'mariadb'], true)) {
        \PHPUnit\Framework\Assert::markTestSkipped(
            'Uji chaos butuh MySQL/MariaDB nyata. Contoh: DB_CONNECTION=mysql DB_DATABASE=yovel_pos_test php artisan test --group=chaos'
        );
    }
});

function chaosProduct(int $stock, int$price = 15_000): Product
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
    foreach ($qtyByProduct as$productId => $qty) {$items[] = ['product_id' => $productId, 'quantity' =>$qty, 'extra_price' => 0, 'options' => null];
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

function chaosWorker(string $database, int$userId, array $payload, float$startAt): Closure
{
    return static function () use ($database, $userId,$payload, $startAt): array {$default = config('database.default');
        config(["database.connections.{$default}.database" => $database]);         \Illuminate\Support\Facades\DB::purge($default);

        while (microtime(true) < $startAt) {
            usleep(500);
        }

        try {
            $order = app(\App\Services\TransactionService::class)->createTransaction($payload,$userId);
            return ['ok' => true, 'order_code' => $order->order_code, 'exception' => null, 'message' => null];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'order_code' => null,
                'exception' => $e::class,
                'message' => $e instanceof \Illuminate\Validation\ValidationException
                    ? (string) collect($e->errors())->flatten()->first()
                    : $e->getMessage(),
            ];
        }
    };
}

function chaosRace(array $payloads, int$userId): array
{
    $database = chaosDatabaseName();$startAt = chaosBarrier(count($payloads));$workers = [];
    foreach ($payloads as $payload) {$workers[] = chaosWorker($database,$userId, $payload,$startAt);
    }
    return array_values(Concurrency::driver('process')->run($workers));
}

it('lockForUpdate NYATA: baris produk dikunci koneksi lain -> timeout, rollback atomik', function () {
    $product = chaosProduct(stock: 1);
    $user = User::factory()->create();$service = app(TransactionService::class);

    $default = config('database.default');
    config(['database.connections.chaos_lock_holder' => config("database.connections.{$default}")]);
    $holder = DB::connection('chaos_lock_holder');

    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    $holder->beginTransaction();

    try {
        $holder->table('products')->where('id', $product->id)->lockForUpdate()->first();$caught = null;
        try {
            $service->createTransaction(chaosPayload([$product->id => 1]),$user->id);
        } catch (QueryException $e) {
            $caught =$e;
        }

        expect($caught)->toBeInstanceOf(QueryException::class);
        expect($caught->getMessage())->toContain('Lock wait timeout exceeded');
        expect(strtolower($caught->getSql()))->toContain('for update');
        expect(chaosStock($product->id))->toBe(1);
    } finally {
        if ($holder->transactionLevel() > 0) {$holder->rollBack();
        }
        DB::purge('chaos_lock_holder');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
    }

    $order =$service->createTransaction(chaosPayload([$product->id => 1]),$user->id);
    expect($order->order_code)->toStartWith('POS-');
    expect(chaosStock($product->id))->toBe(0);
});

it('rollback total: item ke-2 kurang stok -> stok item ke-1 TIDAK boleh berkurang', function () {
    $a = chaosProduct(stock: 5);
    $b = chaosProduct(stock: 1);$user = User::factory()->create();

    $attempt = fn () => app(TransactionService::class)->createTransaction(chaosPayload([$a->id => 2, $b->id => 2]),$user->id);
    expect($attempt)->toThrow(ValidationException::class);
    expect(chaosStock($a->id))->toBe(5);
    expect(chaosStock($b->id))->toBe(1);
});

it('overselling mustahil: stok N direbutkan M proses OS paralel', function (int $stock, int $requests) {$product = chaosProduct($stock);$user = User::factory()->create();

    $results = collect(chaosRace(array_fill(0,$requests, chaosPayload([$product->id => 1])),$user->id));
    $succeeded =$results->where('ok', true);
    $rejected =$results->where('ok', false);

    expect($succeeded)->toHaveCount($stock);
    expect($rejected)->toHaveCount($requests -$stock);
    expect(chaosStock($product->id))->toBe(0);
    expect(Order::count())->toBe($stock);
})->with([
    'double-submit: stok 1 vs 2 request' => [1, 2],
    'stampede: stok 3 vs 8 request' => [3, 8],
]);
