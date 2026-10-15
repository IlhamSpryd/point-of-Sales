<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Jobs\ProcessWebhookOrderJob;
use App\Models\Category;
use App\Models\ChannelOrderLog;
use App\Models\Ingredient;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [D2-BE-002 / D2-BE-005] StrictInventoryAndWebhookRejectionTest.
 *
 * Membuktikan:
 *  1. ProcessWebhookOrderJob (GoFood/GrabFood) MENOLAK pesanan secara atomik
 *     saat stok Product/Ingredient (BOM produk & BOM modifier) kurang;
 *     tidak ada stok negatif; ChannelOrderLog dicatat failed tanpa Order parsial.
 *  2. Kompensasi stok saat void IDEMPOTEN (void/restore berulang tidak
 *     mengembalikan stok dua kali).
 */
class StrictInventoryAndWebhookRejectionTest extends TestCase
{
    use RefreshDatabase;

    /** Test ini mengelola konteks tenant-nya sendiri. */
    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;
    protected Store $store;
    protected User $cashier;
    protected Shift $shift;
    protected Product $product;
    protected Ingredient $ingredient;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->clear();
        $this->tenant = Tenant::create([
            'name' => 'Stock Tenant',
            'readiness_status' => 'active',
        ]);
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Stock Store',
        ]);
        $context->setStoreId($this->store->id);
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => [],
        ]);
        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);
        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Beverage',
        ]);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Latte',
            'product_price' => 20000,
            'stock' => 10,
        ]);
        $this->ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Susu UHT',
            'unit' => 'ml',
            'cost_per_unit' => 20,
            'current_stock' => '500.0000',
        ]);
    }

    public function test_webhook_rejects_order_when_product_stock_insufficient(): void
    {
        $this->product->update(['stock' => 1]); // butuh qty 2 -> ditolak
        $log = ChannelOrderLog::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'gofood',
            'external_order_id' => 'GF-STOCK-1',
            'status' => 'pending',
            'payload' => [
                'order_id' => 'GF-STOCK-1',
                'items' => [
                    ['id' => 'SKU-LATTE', 'quantity' => 2],
                ],
            ],
        ]);
        DB::table('channel_product_mappings')->insert([
            'tenant_id' => $this->tenant->id,
            'provider' => 'gofood',
            'external_product_id' => 'SKU-LATTE',
            'product_id' => $this->product->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new ProcessWebhookOrderJob($log, (int) $this->tenant->id, (int) $this->store->id))->handle(app(TransactionService::class));

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertNotSame('pending', $log->status);
        // Pesanan ditolak karena stok/validasi — pesan kegagalan tercatat di log.
        $this->assertNotSame('completed', $log->status);
        $this->assertNull($log->order_id);
        $this->assertSame(1, (int) $this->product->fresh()->stock); // tidak minus
    }

    public function test_webhook_rejects_order_when_bom_ingredient_insufficient(): void
    {
        // Produk ber-BOM: 1 Latte butuh 200ml susu; tersedia 100ml, pesan 2 -> butuh 400ml -> ditolak.
        ProductIngredient::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'ingredient_id' => $this->ingredient->id,
            'quantity_required' => 200,
        ]);
        $this->ingredient->update(['current_stock' => '100.0000']);
        $log = ChannelOrderLog::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'grabfood',
            'external_order_id' => 'GR-BOM-1',
            'status' => 'pending',
            'payload' => [
                'order_id' => 'GR-BOM-1',
                'items' => [
                    ['id' => 'SKU-LATTE', 'quantity' => 2],
                ],
            ],
        ]);
        DB::table('channel_product_mappings')->insert([
            'tenant_id' => $this->tenant->id,
            'provider' => 'grabfood',
            'external_product_id' => 'SKU-LATTE',
            'product_id' => $this->product->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new ProcessWebhookOrderJob($log, (int) $this->tenant->id, (int) $this->store->id))->handle(app(TransactionService::class));

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertNotSame('completed', $log->status);
        $this->assertNull($log->order_id);
        // Stok bahan tidak negatif.
        $this->assertTrue(bccomp((string) $this->ingredient->fresh()->current_stock, '0.0000', 4) >= 0);
    }

    public function test_webhook_rejects_order_when_modifier_bom_insufficient(): void
    {
        // BOM di sisi modifier: extra shot butuh 50ml susu per qty.
        ProductIngredient::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'ingredient_id' => $this->ingredient->id,
            'quantity_required' => 100,
        ]);
        $group = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Extra',
            'is_required' => false,
            'selection_type' => 'multiple',
        ]);
        $modifier = Modifier::create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $group->id,
            'name' => 'Extra Shot',
            'extra_price' => 5000,
        ]);
        DB::table('modifier_ingredients')->insert([
            'tenant_id' => $this->tenant->id,
            'modifier_id' => $modifier->id,
            'ingredient_id' => $this->ingredient->id,
            'quantity_required' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // 100ml cukup untuk produk saja (100ml), tapi dengan modifier 2x butuh 200+100 = 300ml.
        $this->ingredient->update(['current_stock' => '150.0000']);

        $log = ChannelOrderLog::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'gofood',
            'external_order_id' => 'GF-MOD-1',
            'status' => 'pending',
            'payload' => [
                'order_id' => 'GF-MOD-1',
                'items' => [
                    ['id' => 'SKU-LATTE', 'quantity' => 2],
                ],
            ],
        ]);
        DB::table('channel_product_mappings')->insert([
            'tenant_id' => $this->tenant->id,
            'provider' => 'gofood',
            'external_product_id' => 'SKU-LATTE',
            'product_id' => $this->product->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Webhook normalizer default tidak menyertakan modifier; validasi BOM modifier
        // diuji langsung lewat TransactionService (jalur yang sama dipakai job).
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        try {
            app(TransactionService::class)->createTransaction([
                'shift_id' => $this->shift->id,
                'items' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'options' => [
                        ['modifier_id' => $modifier->id, 'extra_price' => 5000],
                    ],
                ]],
                'payments' => [['method' => 'ewallet', 'amount' => 66600]],
            ], $this->cashier->id);
            $this->fail('Modifier BOM shortage must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('tidak mencukupi', collect($e->errors())->flatten()->first());
        }

        $this->assertTrue(bccomp((string) $this->ingredient->fresh()->current_stock, '0.0000', 4) >= 0);
    }

    public function test_void_stock_compensation_is_idempotent(): void
    {
        // Produk TANPA BOM: stok legacy 10 -> jual 3 -> 7 -> void -> 10 (sekali saja).
        $service = app(TransactionService::class);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        $order = $service->createTransaction([
            'shift_id' => $this->shift->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 66600]],
        ], $this->cashier->id);

        $this->product->refresh();
        $this->assertSame(7, (int) $this->product->stock);

        $service->voidOrder($order, 'Test void idempoten', (int) $this->cashier->id);
        $this->product->refresh();
        $this->assertSame(10, (int) $this->product->stock);

        // Panggil kompensasi stok berulang -> TIDAK mengembalikan dua kali.
        $reflection = new \ReflectionMethod($service, 'restoreStockForOrder');
        $reflection->setAccessible(true);
        $order->refresh();
        $reflection->invoke($service, $order);
        $reflection->invoke($service, $order);

        $this->product->refresh();
        $this->assertSame(10, (int) $this->product->stock, 'Stok tidak boleh dikembalikan dua kali.');
    }
}
