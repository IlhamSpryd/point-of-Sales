<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Enums\QuotationStatusEnum;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\QuotationService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationConversionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected QuotationService $quotationService;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected Customer $customer;

    protected Product $product;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->quotationService = app(QuotationService::class);
        $context = app(TenantContext::class);

        $this->tenant = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Concurrency Tenant',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenant->id);
        $this->store = Store::create(['name' => 'Store Concurrency']);
        $context->setStoreId($this->store->id);

        $role = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->user = User::create([
            'name' => 'Concurrency Actor',
            'email' => 'concurrency@test.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        $this->user->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->customer = Customer::create([
            'customer_code' => 'CUST-CONC-01',
            'name' => 'PT Concurrency Sukses',
            'customer_type' => 'COMPANY',
            'phone' => '+628999888777',
            'is_active' => true,
        ]);

        $category = Category::create([
            'category_name' => 'Hardware',
            'category_code' => 'HDW',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Industrial Valve 2 Inch',
            'product_code' => 'VLV-2IN',
            'product_price' => 750000.00,
            'is_active' => true,
            'status' => 'active',
        ]);
    }

    public function test_concurrent_conversion_requests_produce_exactly_one_sales_order(): void
    {
        $quotation = $this->quotationService->createDraft([
            'customer_id' => $this->customer->id,
            'valid_until' => Carbon::today()->addDays(10)->toDateString(),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10.0000',
                    'unit_price' => '700000.00',
                ],
            ],
        ], $this->user);

        $accepted = $this->quotationService->accept($quotation, $this->user);

        $firstSuccess = null;
        $secondException = null;

        // Simulasi transaksi paralel:
        // Transaksi 1 berhasil mengonversi
        try {
            $firstSuccess = $this->quotationService->convertToSalesOrder($accepted, $this->user);
        } catch (\Throwable $e) {
            $this->fail('Transaksi pertama seharusnya berhasil: '.$e->getMessage());
        }

        // Transaksi 2 yang dieksekusi bersamaan / setelah transaksi 1 commit WAJIB ditolak oleh row lock / status check
        try {
            $this->quotationService->convertToSalesOrder($accepted, $this->user);
        } catch (\Throwable $e) {
            $secondException = $e;
        }

        $this->assertNotNull($firstSuccess, 'Sales Order pertama wajib terbentuk.');
        $this->assertInstanceOf(SalesOrder::class, $firstSuccess);

        $this->assertNotNull($secondException, 'Percobaan kedua wajib menghasilkan exception penolakan.');
        $this->assertInstanceOf(DomainException::class, $secondException);
        $this->assertStringContainsString('sudah pernah dikonversi', $secondException->getMessage());

        // Verifikasi database: TEPAT 1 Sales Order yang merujuk ke quotation_id ini
        $totalSalesOrders = SalesOrder::where('tenant_id', $this->tenant->id)
            ->where('quotation_id', $quotation->id)
            ->count();

        $this->assertSame(1, $totalSalesOrders, 'Wajib tepat satu Sales Order yang terbentuk.');

        // Verifikasi status Quotation akhir
        $quotation->refresh();
        $this->assertSame(QuotationStatusEnum::CONVERTED, $quotation->status);
        $this->assertSame($firstSuccess->id, $quotation->converted_sales_order_id);
    }
}
