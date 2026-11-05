<?php

declare(strict_types=1);

namespace Tests\Feature\Retail;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Retail\Models\ShelfLabel;
use App\Modules\Retail\Services\RetailBarcodeService;
use App\Modules\Retail\Services\RetailExchangeService;
use App\Modules\Retail\Services\ShelfLabelBatchService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class Wave4RetailTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $cashier;

    private Category $category;

    private RetailBarcodeService $barcodeService;

    private ShelfLabelBatchService $shelfLabelService;

    private RetailExchangeService $exchangeService;

    private InventoryLedgerService $inventoryLedgerService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Wave 4 Retail Tenant',
            'slug' => 'wave4-retail-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Retail Flagship Store',
            'code' => 'RTL-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Retail Cashier',
            'permissions' => ['*'],
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
            'password' => Hash::make('password123'),
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->cashier->id,
            'store_id' => $this->store->id,
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Apparel & Fashion',
        ]);

        $this->barcodeService = app(RetailBarcodeService::class);
        $this->shelfLabelService = app(ShelfLabelBatchService::class);
        $this->exchangeService = app(RetailExchangeService::class);
        $this->inventoryLedgerService = app(InventoryLedgerService::class);
    }

    /**
     * T-RTL-01: Scan barcode varian menambahkan varian yang tepat ke keranjang
     * dalam waktu pemrosesan backend sub-50ms.
     */
    public function test_t_rtl_01_variant_barcode_scan_resolves_and_measures_sub_50ms_backend_latency(): void
    {
        $validEan13 = '8992761134013'; // Contoh barcode resmi EAN-13 valid checksum
        $this->assertTrue($this->barcodeService->validateEan13Checksum($validEan13));

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Kaos Polos Premium Cotton',
            'product_price' => 120000,
            'is_active' => true,
        ]);

        $variantM = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'variant_name' => 'Size M - Navy Blue',
            'sku' => 'TSHIRT-M-NAVY',
            'barcode' => $validEan13,
            'price_override' => 125000,
            'is_active' => true,
        ]);

        // Daftarkan ke assortment cabang aktif
        DB::table('store_product_assortments')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $product->id,
            'product_variant_id' => $variantM->id,
            'is_available' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolusi barcode dan benchmark latensi
        $scanResult = $this->barcodeService->resolveBarcode(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            barcode: $validEan13
        );

        $this->assertTrue($scanResult['is_available']);
        $this->assertEquals($product->id, $scanResult['product']->id);
        $this->assertNotNull($scanResult['variant']);
        $this->assertEquals($variantM->id, $scanResult['variant']->id);
        $this->assertEquals(125000, $scanResult['price']);

        // Kriteria Sub-50ms (T-RTL-01)
        $this->assertLessThan(50.0, $scanResult['execution_time_ms'], 'Resolusi barcode melebihi ambang batas 50ms.');
    }

    /**
     * T-RTL-02: Produk di luar assortment cabang aktif ditolak saat pemindaian kasir.
     */
    public function test_t_rtl_02_unassorted_product_is_rejected_with_branch_message(): void
    {
        $barcodeUnassorted = '8992761134022';

        $productExclusive = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Jaket Kulit Domba Eksklusif',
            'product_price' => 1500000,
            'barcode' => $barcodeUnassorted,
            'is_active' => true,
        ]);

        // Tandai produk sebagai TIDAK TERSEDIA di cabang ini (is_available = 0)
        DB::table('store_product_assortments')->insert([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $productExclusive->id,
            'is_available' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak dijual di cabang ini');

        $this->barcodeService->resolveBarcode(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            barcode: $barcodeUnassorted
        );
    }

    /**
     * Antrean cetak label harga rak toko berstatus SHELF_LABEL_PENDING_PRINT dan batch printing.
     */
    public function test_shelf_label_batch_pending_print_workflow(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Sepatu Sneakers Pria',
            'product_price' => 450000,
            'sku' => 'SHOE-001',
            'barcode' => '8993001001010',
            'is_active' => true,
        ]);

        // Perubahan harga memicu antrean SHELF_LABEL_PENDING_PRINT
        $label = $this->shelfLabelService->markProductPendingPrint(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            productId: $product->id,
            oldPrice: 400000,
            newPrice: 450000
        );

        $this->assertEquals(ShelfLabel::STATUS_PENDING, $label->status);
        $this->assertEquals(400000, (float) $label->old_price);
        $this->assertEquals(450000, (float) $label->new_price);

        // Ambil daftar pending
        $pending = $this->shelfLabelService->getPendingLabels($this->tenant->id, $this->store->id);
        $this->assertCount(1, $pending);
        $this->assertEquals('Sepatu Sneakers Pria', $pending[0]['product_name']);

        // Generate batch cetak label
        $batch = $this->shelfLabelService->generatePrintBatch(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            labelIds: [$label->id],
            userId: $this->cashier->id
        );

        $this->assertNotEmpty($batch['batch_number']);
        $this->assertEquals(1, $batch['count']);

        $label->refresh();
        $this->assertEquals(ShelfLabel::STATUS_PRINTED, $label->status);
        $this->assertNotNull($label->printed_at);
        $this->assertEquals($this->cashier->id, $label->printed_by_user_id);
    }

    /**
     * Alur tukar guling ukuran/varian ritel (Retail Exchange):
     * Retur varian lama ke karantina, potong varian baru, selisih bayar = 0 jika harga sama.
     */
    public function test_retail_variant_exchange_workflow(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Celana Chino Slim Fit',
            'product_price' => 200000,
            'is_active' => true,
        ]);

        $variantSize30 = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'variant_name' => 'Size 30',
            'sku' => 'CHINO-30',
            'price_override' => 200000,
            'is_active' => true,
        ]);

        $variantSize32 = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'variant_name' => 'Size 32',
            'sku' => 'CHINO-32',
            'price_override' => 200000, // Harga sama persis
            'is_active' => true,
        ]);

        // Buat pesanan awal yang sudah dibayar (membeli Size 30)
        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'order_type' => 'retail',
            'origin_channel' => 'pos_cashier',
            'order_code' => 'ORD-RTL-'.uniqid(),
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 200000,
            'order_amount' => 200000,
        ]);
        $order->forceFill([
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
        ])->save();

        OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variantSize30->id,
            'product_name_snapshot' => 'Celana Chino Slim Fit - Size 30',
            'qty' => 1,
            'order_price' => 200000,
            'order_subtotal' => 200000,
        ]);

        // Pelanggan datang ingin menukar Size 30 menjadi Size 32 (kekecilan)
        $exchangeResult = $this->exchangeService->exchangeVariant(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            orderId: $order->id,
            oldVariantId: $variantSize30->id,
            newVariantId: $variantSize32->id,
            quantity: 1,
            userId: $this->cashier->id,
            reason: 'Tukar ukuran dari 30 ke 32'
        );

        $this->assertEquals('COMPLETED', $exchangeResult['status']);
        $this->assertEquals(0.00, $exchangeResult['price_difference']); // Selisih bayar = 0 jika harga sama!
    }
}
