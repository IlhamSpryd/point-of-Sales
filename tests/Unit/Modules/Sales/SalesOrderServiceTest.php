<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Sales;

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Sales\Enums\SalesOrderFulfillmentStatusEnum;
use App\Modules\Sales\Enums\SalesOrderStatusEnum;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;

    protected InventoryLedgerService $inventoryLedgerService;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected Customer $customer;

    protected Product $productA;

    protected Product $productB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->salesOrderService = app(SalesOrderService::class);
        $this->inventoryLedgerService = app(InventoryLedgerService::class);

        $context = app(TenantContext::class);
        $this->tenant = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'B2B Sales Order Test Tenant',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'name' => 'Main Distribution Hub',
        ]);
        $context->setStoreId($this->store->id);

        $role = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->user = User::create([
            'name' => 'B2B Sales Manager',
            'email' => 'sales_manager@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        $this->user->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->customer = Customer::create([
            'customer_code' => 'CUST-B2B-WHOLESALE',
            'name' => 'PT Makmur Jaya Perkasa',
            'customer_type' => 'COMPANY',
            'company_name' => 'PT Makmur Jaya Perkasa',
            'tax_id' => '01.999.888.7-654.000',
            'phone' => '+6281198765432',
            'email' => 'procurement@makmurjaya.co.id',
            'is_active' => true,
        ]);

        $category = Category::create([
            'category_name' => 'Wholesale Packaging',
            'category_code' => 'WHS-PKG',
        ]);

        $this->productA = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Corrugated Box Size L',
            'product_code' => 'BOX-SIZE-L',
            'product_price' => 15000.00,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->productB = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Bubble Wrap Roll 100m',
            'product_code' => 'BBL-100M',
            'product_price' => 120000.00,
            'is_active' => true,
            'status' => 'active',
        ]);

        // Inisialisasi stok fisik di ledger M4
        $this->inventoryLedgerService->recordMovement(
            tenantId: (int) $this->tenant->id,
            storeId: (int) $this->store->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '500.0000',
            type: StockMovementType::InitialSync,
            unitCost: '10000.00',
            reason: 'Seeding stock test A',
            userId: $this->user->id
        );

        $this->inventoryLedgerService->recordMovement(
            tenantId: (int) $this->tenant->id,
            storeId: (int) $this->store->id,
            itemType: 'product',
            itemId: (int) $this->productB->id,
            quantityDelta: '200.0000',
            type: StockMovementType::InitialSync,
            unitCost: '80000.00',
            reason: 'Seeding stock test B',
            userId: $this->user->id
        );
    }

    public function test_can_create_sales_order_manually_with_customer_po_number_and_pricing(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'customer_po_number' => 'PO-MJP-2027-0099',
            'payment_terms_days' => 30,
            'notes' => 'Pengiriman B2B reguler',
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => '20.0000',
                    'unit_price' => '14000.00',
                    'tax_rate' => '11.00',
                ],
            ],
        ];

        $so = $this->salesOrderService->createOrder($payload, $this->user);

        $this->assertInstanceOf(SalesOrder::class, $so);
        $this->assertSame(SalesOrderStatusEnum::DRAFT, $so->status);
        $this->assertSame(SalesOrderFulfillmentStatusEnum::UNFULFILLED, $so->fulfillment_status);
        $this->assertSame('PO-MJP-2027-0099', $so->customer_po_number);
        $this->assertSame(30, $so->payment_terms_days);
        $this->assertSame('280000.00', (string) $so->subtotal);
        $this->assertSame('30800.00', (string) $so->tax_amount);
        $this->assertSame('310800.00', (string) $so->total_amount);

        $this->assertCount(1, $so->lines);
        $line = $so->lines->first();
        $this->assertSame('20.0000', (string) $line->ordered_qty);
        $this->assertSame('0.0000', (string) $line->reserved_qty);
        $this->assertSame('0.0000', (string) $line->fulfilled_qty);
    }

    public function test_sales_order_creation_validates_minimum_order_quantity_moq(): void
    {
        $payloadUnderMOQ = [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => '5.0000',
                    'moq' => '10.0000',
                ],
            ],
        ];

        $this->expectException(ValidationException::class);
        $this->salesOrderService->createOrder($payloadUnderMOQ, $this->user);
    }

    public function test_can_confirm_sales_order_and_reserves_stock_quantities(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => '15.0000',
                ],
                [
                    'product_id' => $this->productB->id,
                    'quantity' => '8.0000',
                ],
            ],
        ];

        $so = $this->salesOrderService->createOrder($payload, $this->user);

        // Sebelum konfirmasi: reserved_qty masih 0
        foreach ($so->lines as $line) {
            $this->assertSame('0.0000', (string) $line->reserved_qty);
        }

        $confirmed = $this->salesOrderService->confirmOrder($so, $this->user);

        $this->assertSame(SalesOrderStatusEnum::CONFIRMED, $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertSame($this->user->id, $confirmed->confirmed_by_user_id);

        $confirmed->load('lines');
        $this->assertSame('15.0000', (string) $confirmed->lines->firstWhere('product_id', $this->productA->id)->reserved_qty);
        $this->assertSame('8.0000', (string) $confirmed->lines->firstWhere('product_id', $this->productB->id)->reserved_qty);

        // Jejak audit konfirmasi tercatat
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'sales_order.confirm',
            'subject_id' => $confirmed->id,
        ]);
    }

    public function test_cannot_confirm_sales_order_in_invalid_status(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => '10.0000'],
            ],
        ];

        $so = $this->salesOrderService->createOrder($payload, $this->user);
        $confirmed = $this->salesOrderService->confirmOrder($so, $this->user);

        // Konfirmasi kedua kali harus ditolak
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Hanya Sales Order berstatus DRAFT atau PENDING_CREDIT_APPROVAL yang dapat dikonfirmasi');
        $this->salesOrderService->confirmOrder($confirmed, $this->user);
    }

    public function test_fulfill_order_validates_remaining_quantity_and_rejects_over_fulfillment(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => '10.0000'],
            ],
        ];

        $so = $this->salesOrderService->createOrder($payload, $this->user);
        $confirmed = $this->salesOrderService->confirmOrder($so, $this->user);

        $lineId = $confirmed->lines->first()->id;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('melebihi sisa pesanan');

        // Mencoba fulfill 12 padahal pesanan hanya 10
        $this->salesOrderService->fulfillOrder($confirmed, [
            ['line_id' => $lineId, 'quantity' => '12.0000'],
        ], $this->user);
    }

    public function test_fulfill_order_partial_and_full_reduces_reserved_qty_and_updates_status(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => '20.0000'],
                ['product_id' => $this->productB->id, 'quantity' => '10.0000'],
            ],
        ];

        $so = $this->salesOrderService->createOrder($payload, $this->user);
        $confirmed = $this->salesOrderService->confirmOrder($so, $this->user);

        $lineA = $confirmed->lines->firstWhere('product_id', $this->productA->id);
        $lineB = $confirmed->lines->firstWhere('product_id', $this->productB->id);

        // 1. Partial Fulfillment tahap 1: Fulfill 8 unit Product A
        $execOrder1 = $this->salesOrderService->fulfillOrder($confirmed, [
            ['line_id' => $lineA->id, 'quantity' => '8.0000'],
        ], $this->user);

        $this->assertNotNull($execOrder1);
        $this->assertSame($confirmed->id, $execOrder1->sales_order_id);

        $confirmed->refresh();
        $this->assertSame(SalesOrderStatusEnum::PARTIALLY_FULFILLED, $confirmed->status);
        $this->assertSame(SalesOrderFulfillmentStatusEnum::PARTIALLY_FULFILLED, $confirmed->fulfillment_status);

        $freshLineA = $confirmed->lines()->find($lineA->id);
        $this->assertSame('8.0000', (string) $freshLineA->fulfilled_qty);
        $this->assertSame('12.0000', (string) $freshLineA->reserved_qty);
        $this->assertSame('12.0000', (string) $freshLineA->getRemainingQty());

        // 2. Partial Fulfillment tahap 2: Fulfill sisa Product A (12) dan separuh Product B (5)
        $execOrder2 = $this->salesOrderService->fulfillOrder($confirmed, [
            ['line_id' => $lineA->id, 'quantity' => '12.0000'],
            ['line_id' => $lineB->id, 'quantity' => '5.0000'],
        ], $this->user);

        $confirmed->refresh();
        $this->assertSame(SalesOrderStatusEnum::PARTIALLY_FULFILLED, $confirmed->status);

        $freshLineA2 = $confirmed->lines()->find($lineA->id);
        $freshLineB2 = $confirmed->lines()->find($lineB->id);

        $this->assertSame('20.0000', (string) $freshLineA2->fulfilled_qty);
        $this->assertSame('0.0000', (string) $freshLineA2->reserved_qty);
        $this->assertSame('0.0000', (string) $freshLineA2->getRemainingQty());

        $this->assertSame('5.0000', (string) $freshLineB2->fulfilled_qty);
        $this->assertSame('5.0000', (string) $freshLineB2->reserved_qty);
        $this->assertSame('5.0000', (string) $freshLineB2->getRemainingQty());

        // 3. Final Fulfillment: Fulfill sisa Product B (5)
        $execOrder3 = $this->salesOrderService->fulfillOrder($confirmed, [
            ['line_id' => $lineB->id, 'quantity' => '5.0000'],
        ], $this->user);

        $confirmed->refresh();
        $this->assertSame(SalesOrderStatusEnum::FULFILLED, $confirmed->status);
        $this->assertSame(SalesOrderFulfillmentStatusEnum::FULFILLED, $confirmed->fulfillment_status);

        $freshLineB3 = $confirmed->lines()->find($lineB->id);
        $this->assertSame('10.0000', (string) $freshLineB3->fulfilled_qty);
        $this->assertSame('0.0000', (string) $freshLineB3->reserved_qty);
        $this->assertSame('0.0000', (string) $freshLineB3->getRemainingQty());
    }

    public function test_cancel_order_releases_reserved_stock_and_marks_cancelled_or_closed(): void
    {
        // Kasus 1: Dibatalkan tanpa pemenuhan sama sekali -> CANCELLED
        $payload1 = [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => '10.0000'],
            ],
        ];

        $so1 = $this->salesOrderService->createOrder($payload1, $this->user);
        $confirmed1 = $this->salesOrderService->confirmOrder($so1, $this->user);

        $cancelled1 = $this->salesOrderService->cancelOrder($confirmed1, $this->user, 'Customer membatalkan proyek');
        $this->assertSame(SalesOrderStatusEnum::CANCELLED, $cancelled1->status);
        $this->assertSame('Customer membatalkan proyek', $cancelled1->cancellation_reason);
        $this->assertSame($this->user->id, $cancelled1->cancelled_by_user_id);

        $line1 = $cancelled1->lines->first();
        $this->assertSame('0.0000', (string) $line1->reserved_qty);
        $this->assertSame('10.0000', (string) $line1->cancelled_qty);

        // Kasus 2: Dibatalkan setelah ada pemenuhan parsial -> CLOSED
        $payload2 = [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => '10.0000'],
            ],
        ];

        $so2 = $this->salesOrderService->createOrder($payload2, $this->user);
        $confirmed2 = $this->salesOrderService->confirmOrder($so2, $this->user);

        // Fulfill 4 unit
        $this->salesOrderService->fulfillOrder($confirmed2, [
            ['line_id' => $confirmed2->lines->first()->id, 'quantity' => '4.0000'],
        ], $this->user);

        // Batalkan sisa 6 unit
        $closed2 = $this->salesOrderService->cancelOrder($confirmed2, $this->user, 'Sisa 6 unit tidak dibutuhkan pelanggan');
        $this->assertSame(SalesOrderStatusEnum::CLOSED, $closed2->status);

        $line2 = $closed2->lines->first();
        $this->assertSame('4.0000', (string) $line2->fulfilled_qty);
        $this->assertSame('6.0000', (string) $line2->cancelled_qty);
        $this->assertSame('0.0000', (string) $line2->reserved_qty);
        $this->assertSame('0.0000', (string) $line2->getRemainingQty());
    }

    public function test_create_repeat_order_copies_items_and_re_resolves_current_catalog_pricing(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'customer_po_number' => 'PO-ORIGINAL-001',
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => '10.0000',
                    'unit_price' => '15000.00',
                ],
            ],
        ];

        $origSO = $this->salesOrderService->createOrder($payload, $this->user);

        // Perbarui harga dasar produk di katalog
        $this->productA->product_price = 18000.00;
        $this->productA->save();

        // Buat Repeat Order
        $repeatSO = $this->salesOrderService->createRepeatOrder($origSO, $this->user);

        $this->assertInstanceOf(SalesOrder::class, $repeatSO);
        $this->assertSame($origSO->id, $repeatSO->source_repeat_order_id);
        $this->assertSame('REPEAT_ORDER', $repeatSO->order_source);
        $this->assertSame(SalesOrderStatusEnum::DRAFT, $repeatSO->status);

        // Verifikasi harga dihitung ulang ke harga katalog terbaru (18000)
        $repeatLine = $repeatSO->lines->first();
        $this->assertSame('10.0000', (string) $repeatLine->ordered_qty);
        $this->assertSame('18000.00', (string) $repeatLine->unit_price);
        $this->assertSame('180000.00', (string) $repeatLine->line_total);
    }

    public function test_create_repeat_order_fails_if_product_is_inactive(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => '10.0000',
                ],
            ],
        ];

        $origSO = $this->salesOrderService->createOrder($payload, $this->user);

        // Nonaktifkan produk
        $this->productA->is_active = false;
        $this->productA->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah tidak aktif');

        $this->salesOrderService->createRepeatOrder($origSO, $this->user);
    }
}
