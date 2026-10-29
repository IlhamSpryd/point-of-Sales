<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Sales;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Enums\QuotationStatusEnum;
use App\Modules\Sales\Enums\SalesOrderStatusEnum;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected QuotationService $quotationService;

    protected SalesOrderService $salesOrderService;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected Customer $customer;

    protected Product $product;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->quotationService = app(QuotationService::class);
        $this->salesOrderService = app(SalesOrderService::class);

        $context = app(TenantContext::class);
        $this->tenant = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Quotation Test Tenant',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'name' => 'Quotation Store',
        ]);
        $context->setStoreId($this->store->id);

        $role = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->user = User::create([
            'name' => 'Quotation Tester',
            'email' => 'quotation_tester@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        $this->user->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->customer = Customer::create([
            'customer_code' => 'CUST-QTEST-01',
            'name' => 'PT Mitra B2B Sukses',
            'customer_type' => 'COMPANY',
            'company_name' => 'PT Mitra B2B Sukses',
            'tax_id' => '01.234.567.8-901.000',
            'phone' => '+6281234567890',
            'email' => 'procurement@mitrasukses.co.id',
            'is_active' => true,
        ]);

        $category = Category::create([
            'category_name' => 'Raw Materials',
            'category_code' => 'RAW',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Premium Robusta Green Beans 50kg',
            'product_code' => 'RB-50KG',
            'product_price' => 1250000.00,
            'is_active' => true,
            'status' => 'active',
        ]);
    }

    public function test_can_create_draft_quotation_with_resolved_pricing_and_bcmath_precision(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'issue_date' => Carbon::today()->toDateString(),
            'valid_until' => Carbon::today()->addDays(14)->toDateString(),
            'payment_terms_days' => 30,
            'notes' => 'Penawaran pengadaan bahan baku batch 1',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2.0000',
                    'unit_price' => '1200000.00',
                    'discount_amount' => '50000.00',
                    'tax_rate' => '11.00',
                ],
            ],
        ];

        $quotation = $this->quotationService->createDraft($payload, $this->user);

        $this->assertInstanceOf(Quotation::class, $quotation);
        $this->assertSame(QuotationStatusEnum::DRAFT, $quotation->status);
        $this->assertSame(1, $quotation->version_number);
        $this->assertStringStartsWith('QTN-', $quotation->quotation_number);

        // Subtotal = 2 * 1,200,000 = 2,400,000
        // Discount = 50,000 -> Net = 2,350,000
        // Tax 11% = 2,350,000 * 0.11 = 258,500
        // Total = 2,350,000 + 258,500 = 2,608,500.00
        $this->assertSame('2400000.00', (string) $quotation->subtotal);
        $this->assertSame('50000.00', (string) $quotation->discount_amount);
        $this->assertSame('258500.00', (string) $quotation->tax_amount);
        $this->assertSame('2608500.00', (string) $quotation->total_amount);

        $this->assertCount(1, $quotation->lines);
        $line = $quotation->lines->first();
        $this->assertSame('2.0000', (string) $line->quantity);
        $this->assertSame('2608500.00', (string) $line->line_total);
    }

    public function test_quotation_requires_approval_if_discount_exceeds_threshold(): void
    {
        // 20% discount exceeds 15% threshold
        $payload = [
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1.0000',
                    'unit_price' => '1000000.00',
                    'discount_amount' => '200000.00', // 20% discount
                    'tax_rate' => '0.00',
                ],
            ],
        ];

        $quotation = $this->quotationService->createDraft($payload, $this->user);

        $this->assertTrue($quotation->requires_approval);
        $this->assertSame(QuotationStatusEnum::PENDING_APPROVAL, $quotation->status);
    }

    public function test_approval_lifecycle_transitions(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'requires_approval' => true,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1.0000',
                    'unit_price' => '1000000.00',
                    'discount_amount' => '0.00',
                    'tax_rate' => '0.00',
                ],
            ],
        ];

        $quotation = $this->quotationService->createDraft($payload, $this->user);
        $this->assertSame(QuotationStatusEnum::PENDING_APPROVAL, $quotation->status);

        // Approve
        $approved = $this->quotationService->approve($quotation, $this->user, 'Diskon disetujui direksi');
        $this->assertSame(QuotationStatusEnum::APPROVED, $approved->status);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame($this->user->id, $approved->approved_by_user_id);

        // Customer accepts
        $accepted = $this->quotationService->accept($approved, $this->user);
        $this->assertSame(QuotationStatusEnum::ACCEPTED, $accepted->status);
    }

    public function test_reject_quotation(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1.0000',
                    'unit_price' => '1000000.00',
                ],
            ],
        ];

        $quotation = $this->quotationService->createDraft($payload, $this->user);
        $rejected = $this->quotationService->reject($quotation, $this->user, 'Margin terlalu tipis');

        $this->assertSame(QuotationStatusEnum::REJECTED, $rejected->status);
        $this->assertSame('Margin terlalu tipis', $rejected->rejected_reason);
    }

    public function test_cannot_accept_or_convert_expired_quotation(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'valid_until' => Carbon::yesterday()->toDateString(),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1.0000',
                    'unit_price' => '1000000.00',
                ],
            ],
        ];

        $quotation = $this->quotationService->createDraft($payload, $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('kedaluwarsa');

        $this->quotationService->accept($quotation, $this->user);
    }

    public function test_can_create_revision_and_supersede_original(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1.0000',
                    'unit_price' => '1000000.00',
                ],
            ],
        ];

        $original = $this->quotationService->createDraft($payload, $this->user);
        $this->assertSame(1, $original->version_number);

        $revision = $this->quotationService->createRevision($original, [
            'payment_terms_days' => 45,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '3.0000',
                    'unit_price' => '950000.00',
                ],
            ],
        ], $this->user);

        $original->refresh();
        $this->assertSame(QuotationStatusEnum::SUPERSEDED, $original->status);

        $this->assertSame(QuotationStatusEnum::DRAFT, $revision->status);
        $this->assertSame(2, $revision->version_number);
        $this->assertSame($original->id, $revision->parent_quotation_id);
        $this->assertSame($original->quotation_number, $revision->quotation_number);
        $this->assertSame('2850000.00', (string) $revision->total_amount);
    }

    public function test_atomic_one_time_conversion_to_sales_order(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'valid_until' => Carbon::tomorrow()->toDateString(),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5.0000',
                    'unit_price' => '1200000.00',
                    'discount_amount' => '0.00',
                    'tax_rate' => '11.00',
                ],
            ],
        ];

        $quotation = $this->quotationService->createDraft($payload, $this->user);
        $accepted = $this->quotationService->accept($quotation, $this->user);

        $salesOrder = $this->quotationService->convertToSalesOrder($accepted, $this->user);

        $this->assertInstanceOf(SalesOrder::class, $salesOrder);
        $this->assertSame(SalesOrderStatusEnum::DRAFT, $salesOrder->status);
        $this->assertSame('QUOTATION', $salesOrder->order_source);
        $this->assertSame($accepted->id, $salesOrder->quotation_id);
        $this->assertStringStartsWith('SO-', $salesOrder->so_number);
        $this->assertSame((string) $accepted->total_amount, (string) $salesOrder->total_amount);
        $this->assertCount(1, $salesOrder->lines);

        $accepted->refresh();
        $this->assertSame(QuotationStatusEnum::CONVERTED, $accepted->status);
        $this->assertSame($salesOrder->id, $accepted->converted_sales_order_id);
        $this->assertNotNull($accepted->converted_at);

        // Percobaan konversi kedua WAJIB ditolak (Anti-Duplikasi)
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah pernah dikonversi');

        $this->quotationService->convertToSalesOrder($accepted, $this->user);
    }

    public function test_non_accepted_quotation_cannot_be_converted(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1.0000',
                    'unit_price' => '1000000.00',
                ],
            ],
        ];

        $draft = $this->quotationService->createDraft($payload, $this->user);
        $this->assertSame(QuotationStatusEnum::DRAFT, $draft->status);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Hanya Quotation berstatus ACCEPTED yang dapat dikonversi');

        $this->quotationService->convertToSalesOrder($draft, $this->user);
    }
}
