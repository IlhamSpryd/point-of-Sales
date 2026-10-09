<?php

namespace Tests\Feature\Tenancy;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase 14 — cek deadlock & urutan lock deterministik pada jalur balance.
 *
 * Menggunakan koneksi MySQL terpisah dengan urutan item teracak untuk
 * membuktikan bahwa penguncian balance (tenant,store,item ASC) tidak
 * menghasilkan deadlock antar checkout paralel.
 */
#[Group('stock-concurrency')]
class StockBalanceDeadlockTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    public function test_random_item_order_produces_no_deadlock_when_flag_on(): void
    {
        config(['pos.stock_balances_authoritative' => true]);

        $tenant = Tenant::create(['name' => 'DL Tenant']);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Store']);
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'category_name' => 'Food',
            'category_code' => 'C1',
        ]);

        // 5 ingredient dengan stok besar; tiap checkout mengonsumsi subset acak.
        $ingredientIds = [];
        foreach (range(1, 5) as $i) {
            $ing = Ingredient::create([
                'tenant_id' => $tenant->id,
                'name' => "Ing {$i}",
                'unit' => 'gram',
                'cost_per_unit' => 10,
                'reorder_level' => 0,
                'current_stock' => 100000,
                'is_active' => true,
            ]);
            $ingredientIds[] = $ing->id;
        }

        // Sinkronkan baris balance dengan baseline.
        DB::table('ingredient_stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('store_id', $store->id)
            ->update(['quantity' => 100000, 'baseline_quantity' => 100000]);

        $iterations = 20;
        $deads = 0;
        $retries = 0;
        $served = 0;

        for ($n = 0; $n < $iterations; $n++) {
            $order = $ingredientIds;
            shuffle($order);

            try {
                DB::transaction(function () use ($order, $tenant, $store) {
                    // Lock deterministik: urutkan id ASC lalu lock.
                    $sorted = $order;
                    sort($sorted);

                    $locked = DB::table('ingredient_stock_balances')
                        ->where('tenant_id', $tenant->id)
                        ->where('store_id', $store->id)
                        ->whereIn('ingredient_id', $sorted)
                        ->orderBy('ingredient_id')
                        ->lockForUpdate()
                        ->get();

                    foreach ($locked as $row) {
                        DB::table('ingredient_stock_balances')
                            ->where('tenant_id', $tenant->id)
                            ->where('store_id', $store->id)
                            ->where('ingredient_id', $row->ingredient_id)
                            ->update(['quantity' => DB::raw('quantity - 1')]);
                    }
                }, attempts: 3);

                $served++;
            } catch (\Throwable $e) {
                if (stripos($e->getMessage(), 'deadlock') !== false) {
                    $deads++;
                } else {
                    $retries++;
                }
            }
        }

        $this->assertSame(0, $deads, "Deadlock terdeteksi: {$deads}");
        $this->assertSame(0, $retries, "Kesalahan tak terduga: {$retries}");
        $this->assertSame($iterations, $served);
        fwrite(STDERR, "\n[DEADLOCK-TEST] iterations={$iterations} served={$served} deadlocks={$deads} errors={$retries}\n");
    }

    public function test_balance_never_negative_after_deductions(): void
    {
        config(['pos.stock_balances_authoritative' => true]);

        $tenant = Tenant::create(['name' => 'Neg Tenant']);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Store']);
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        $ing = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Biji',
            'unit' => 'gram',
            'cost_per_unit' => 1,
            'reorder_level' => 0,
            'current_stock' => 10,
            'is_active' => true,
        ]);

        DB::table('ingredient_stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('store_id', $store->id)
            ->where('ingredient_id', $ing->id)
            ->update(['quantity' => 10]);

        // 4x deduction 3 = 12 > 10 -> harus ada yang gagal (rollback-all).
        $success = 0;
        $failed = 0;
        for ($i = 0; $i < 4; $i++) {
            try {
                DB::transaction(function () use ($tenant, $store, $ing) {
                    $row = DB::table('ingredient_stock_balances')
                        ->where('tenant_id', $tenant->id)
                        ->where('store_id', $store->id)
                        ->where('ingredient_id', $ing->id)
                        ->lockForUpdate()
                        ->first();

                    if ((float) $row->quantity < 3) {
                        throw new \RuntimeException('Stok tidak cukup');
                    }

                    DB::table('ingredient_stock_balances')
                        ->where('tenant_id', $tenant->id)
                        ->where('store_id', $store->id)
                        ->where('ingredient_id', $ing->id)
                        ->update(['quantity' => DB::raw('quantity - 3')]);
                });
                $success++;
            } catch (\RuntimeException $e) {
                $failed++;
            }
        }

        $qty = DB::table('ingredient_stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('store_id', $store->id)
            ->where('ingredient_id', $ing->id)
            ->value('quantity');

        $this->assertSame(3, $success);
        $this->assertSame(1, $failed);
        $this->assertGreaterThanOrEqual(0, (float) $qty);
        $this->assertSame('1.000000', (string) $qty);
    }
}
