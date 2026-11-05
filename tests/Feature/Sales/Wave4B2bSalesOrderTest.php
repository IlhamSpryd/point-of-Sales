<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\CreditStatusEnum;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Sales\Enums\CustomerInvoiceStatusEnum;
use App\Modules\Sales\Enums\SalesOrderFulfillmentStatusEnum;
use App\Modules\Sales\Enums\SalesOrderStatusEnum;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Services\B2BSalesOrderService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Wave4B2bSalesOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected User $salesUser;

    protected Customer $customer;

    protected Product $product;

    protected B2BSalesOrderService $b2bSalesService;

    protected InventoryLedgerService $inventoryLedgerService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);

        $this->tenant = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'B2B Wholesale Hub',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Wholesale Depot Surabaya',
            'code' => 'WHS-SBY',
        ]);
        $context->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'sales_rep',
            'display_name' => 'Sales Representative',
            'is_admin' => false,
        ]);

        $this->salesUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'name' => 'Andi Sales',
            'email' => 'andi.sales@example.com',
            'password' => bcrypt('secret123'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->salesUser->id,
            'store_id' => $this->store->id,
        ]);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'customer_code' => 'B2B-CUST-001',
            'name' => 'PT Makmur Jaya Abadi',
            'customer_type' => 'COMPANY',
            'company_name' => 'PT Makmur Jaya Abadi',
            'tax_id' => '01.234.567.8-901.000',
            'phone' => '081298765432',
            'email' => 'purchasing@makmurjaya.co.id',
            'is_active' => true,
        ]);

        // Default Credit Account: Limit Rp 50,000,000
        CustomerCreditAccount::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'credit_limit' => 50000000.00,
            'payment_terms_days' => 30,
            'grace_period_days' => 7,
            'credit_status' => CreditStatusEnum::ACTIVE,
            'reserved_exposure' => 0.00,
            'invoiced_exposure' => 0.00,
            'unapplied_credit_balance' => 0.00,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Industrial Materials',
            'category_code' => 'IND-MAT',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Semen Portland Komposit 50kg',
            'product_price' => 75000.00,
            'is_active' => true,
        ]);

        $this->b2bSalesService = app(B2BSalesOrderService::class);
        $this->inventoryLedgerService = app(InventoryLedgerService::class);
    }

    /**
     * T-SLS-01: Konfirmasi Sales Order menambah reserved_quantity tanpa mengurangi stok fisik sebelum pengiriman.
     */
    public function test_t_sls_01_confirming_sales_order_increments_reserved_quantity_without_decrementing_physical_stock(): void
    {
        // 1. Inisialisasi stok fisik di gudang sebanyak 100 sak
        $this->inventoryLedgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            itemType: 'product',
            itemId: $this->product->id,
            quantityDelta: 100.0000,
            type: StockMovementType::InitialSync,
            unitCost: 60000.00,
            reason: 'Inisialisasi stok gudang B2B'
        );

        $initialBalance = DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($initialBalance);
        $this->assertEquals(100.0, (float) $initialBalance->quantity);
        $this->assertEquals(0.0, (float) $initialBalance->reserved_quantity);

        // 2. Buat Sales Order untuk 25 sak semen (@ Rp 75.000 = Rp 1.875.000)
        $orderPayload = [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'payment_terms_days' => 30,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 25.0000,
                    'unit_price' => 75000.00,
                    'description' => 'Semen Portland Komposit 50kg',
                ],
            ],
        ];

        $salesOrder = $this->b2bSalesService->createOrder($orderPayload, $this->salesUser);
        $this->assertEquals(SalesOrderStatusEnum::DRAFT, $salesOrder->status);

        // 3. Konfirmasi Sales Order dengan reservasi stok gudang
        $confirmedSO = $this->b2bSalesService->confirmOrderWithReservation($salesOrder, $this->salesUser);

        $this->assertEquals(SalesOrderStatusEnum::CONFIRMED, $confirmedSO->status);

        // 4. Verifikasi T-SLS-01:
        // reserved_quantity bertambah sebesar 25.0000
        // quantity fisik TETAP 100.0000 (tidak berkurang sebelum pengiriman fisik)
        $balanceAfterConfirm = DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($balanceAfterConfirm);
        $this->assertEquals(100.0, (float) $balanceAfterConfirm->quantity, 'Stok fisik TIDAK boleh berkurang saat konfirmasi SO.');
        $this->assertEquals(25.0, (float) $balanceAfterConfirm->reserved_quantity, 'reserved_quantity WAJIB bertambah saat konfirmasi SO.');
    }

    /**
     * T-SLS-02: Pelanggan yang melampaui limit kredit ditolak saat pembuatan Sales Order baru kecuali di-override.
     */
    public function test_t_sls_02_customer_exceeding_credit_limit_is_rejected_unless_overridden(): void
    {
        // 1. Set limit kredit pelanggan ke batas rendah Rp 2.000.000
        CustomerCreditAccount::where('tenant_id', $this->tenant->id)
            ->where('customer_id', $this->customer->id)
            ->update([
                'credit_limit' => 2000000.00,
                'reserved_exposure' => 1500000.00, // Sudah terpakai Rp 1.500.000
                'invoiced_exposure' => 0.00,
            ]);

        // Sisa plafon hanya Rp 500.000.
        // Coba buat SO baru senilai 10 sak @ Rp 75.000 = Rp 750.000 (Melampaui limit Rp 2.000.000)
        $exceedingPayload = [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'payment_terms_days' => 30,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10.0000,
                    'unit_price' => 75000.00,
                    'description' => 'Semen Portland Komposit 50kg',
                ],
            ],
        ];

        // 2. Upaya pembuatan SO tanpa override WAJIB ditolak (DomainException)
        try {
            $this->b2bSalesService->createOrder($exceedingPayload, $this->salesUser, allowOverride: false);
            $this->fail('Seharusnya melempar DomainException karena melampaui limit kredit.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Pelanggan telah melampaui batas plafon kredit', $e->getMessage());
        }

        // 3. Upaya pembuatan SO DENGAN override resmi harus berhasil
        $overriddenSO = $this->b2bSalesService->createOrder(
            $exceedingPayload,
            $this->salesUser,
            allowOverride: true,
            overrideReason: 'Disetujui oleh Direktur Komersial - Proyek Strategis'
        );

        $this->assertNotNull($overriddenSO->id);
        $this->assertEquals($this->salesUser->id, $overriddenSO->credit_override_approved_by);
        $this->assertEquals('Disetujui oleh Direktur Komersial - Proyek Strategis', $overriddenSO->credit_override_reason);
    }

    /**
     * Alur Pengiriman Barang (Fulfillment):
     * Memotong stok fisik riil dan melepaskan reservasi stok (reserved_quantity).
     */
    public function test_sales_order_fulfillment_releases_reservation_and_deducts_physical_stock(): void
    {
        // Setup stok awal: 100 sak
        $this->inventoryLedgerService->recordMovement(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            itemType: 'product',
            itemId: $this->product->id,
            quantityDelta: 100.0000,
            type: StockMovementType::InitialSync,
            unitCost: 60000.00,
            reason: 'Inisialisasi stok'
        );

        // Buat & konfirmasi SO untuk 20 sak
        $orderPayload = [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'payment_terms_days' => 30,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 20.0000,
                    'unit_price' => 75000.00,
                    'description' => 'Semen Portland Komposit 50kg',
                ],
            ],
        ];

        $salesOrder = $this->b2bSalesService->createOrder($orderPayload, $this->salesUser);
        $confirmedSO = $this->b2bSalesService->confirmOrderWithReservation($salesOrder, $this->salesUser);

        $line = $confirmedSO->lines()->firstOrFail();

        // Eksekusi pemenuhan (fulfillment) pengiriman 20 sak
        $fulfillmentLines = [
            [
                'line_id' => $line->id,
                'quantity' => 20.0000,
            ],
        ];

        $posOrder = $this->b2bSalesService->fulfillOrder($confirmedSO, $fulfillmentLines, $this->salesUser);

        $this->assertInstanceOf(Order::class, $posOrder);

        // Cek saldo stok di product_stock_balances:
        // reserved_quantity kembali ke 0.000000
        // physical quantity berkurang dari 100 menjadi 80.000000
        $balance = DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals(80.0, (float) $balance->quantity, 'Stok fisik harus berkurang setelah pengiriman barang.');
        $this->assertEquals(0.0, (float) $balance->reserved_quantity, 'reserved_quantity harus dilepas setelah pengiriman.');

        $confirmedSO->refresh();
        $this->assertEquals(SalesOrderFulfillmentStatusEnum::FULFILLED, $confirmedSO->fulfillment_status);
    }

    /**
     * Penerbitan Faktur Piutang Tempo AR Net 30.
     */
    public function test_b2b_sales_order_issues_ar_net_30_customer_invoice(): void
    {
        $orderPayload = [
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'payment_terms_days' => 30,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10.0000,
                    'unit_price' => 75000.00,
                    'description' => 'Semen Portland Komposit 50kg',
                ],
            ],
        ];

        $salesOrder = $this->b2bSalesService->createOrder($orderPayload, $this->salesUser);
        $confirmedSO = $this->b2bSalesService->confirmOrderWithReservation($salesOrder, $this->salesUser);

        // Terbitkan Faktur Piutang Net 30
        $invoice = $this->b2bSalesService->issueInvoiceForOrder($confirmedSO, $this->salesUser, paymentTermsDays: 30);

        $this->assertInstanceOf(CustomerInvoice::class, $invoice);
        $this->assertEquals(CustomerInvoiceStatusEnum::POSTED, $invoice->status);
        $this->assertEquals(30, $invoice->payment_terms_days);
        $this->assertEquals('750000.00', (string) $invoice->total_amount);
        $this->assertEquals('750000.00', (string) $invoice->balance_due);
    }
}
