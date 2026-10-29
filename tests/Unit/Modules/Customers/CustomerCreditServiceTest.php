<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Customers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\CreditEvaluationStatusEnum;
use App\Modules\Customers\Enums\CreditStatusEnum;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Sales\Enums\SalesOrderStatusEnum;
use App\Modules\Sales\Services\SalesOrderService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CustomerCreditService $creditService;

    protected SalesOrderService $salesOrderService;

    protected Tenant $tenant;

    protected Store $store;

    protected User $owner;

    protected User $cashier;

    protected Customer $customer;

    protected Product $product;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->creditService = app(CustomerCreditService::class);
        $this->salesOrderService = app(SalesOrderService::class);

        $context = app(TenantContext::class);
        $this->tenant = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Credit Management Test Tenant',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'name' => 'HQ Store Credit Test',
        ]);
        $context->setStoreId($this->store->id);

        $ownerRole = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $cashierRole = Role::firstOrCreate(
            ['name' => 'Kasir', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Cashier', 'is_admin' => false, 'permissions' => []]
        );

        $this->owner = User::create([
            'name' => 'Finance Owner',
            'email' => 'finance_owner@example.com',
            'password' => bcrypt('password'),
            'role_id' => $ownerRole->id,
            'is_active' => true,
        ]);
        $this->owner->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->cashier = User::create([
            'name' => 'Retail Cashier',
            'email' => 'cashier_credit@example.com',
            'password' => bcrypt('password'),
            'role_id' => $cashierRole->id,
            'is_active' => true,
        ]);
        $this->cashier->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->customer = Customer::create([
            'customer_code' => 'CUST-CREDIT-001',
            'name' => 'PT Distribusi Utama Niaga',
            'customer_type' => 'COMPANY',
            'company_name' => 'PT Distribusi Utama Niaga',
            'tax_id' => '01.555.444.3-222.000',
            'phone' => '+6281288887777',
            'email' => 'finance@dun.co.id',
            'is_active' => true,
        ]);

        $category = Category::create([
            'category_name' => 'Beverage Ingredients',
            'category_code' => 'BEV-ING',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Dairy Milk Full Cream 1L Box 12',
            'product_code' => 'MILK-FC-12',
            'product_price' => 200000.00,
            'is_active' => true,
            'status' => 'active',
        ]);
    }

    public function test_evaluates_credit_exposure_correctly_under_and_over_limit(): void
    {
        // Atur limit 10.000.000, termin 30 hari
        $this->creditService->updateCreditLimit($this->customer->id, '10000000.00', $this->owner, 'Pemberian plafon awal');
        $this->creditService->updateCreditTerms($this->customer->id, 30, 7, $this->owner, 'Termin Net 30');

        // 1. Order 5.000.000 (< 80%) -> ALLOWED
        $res1 = $this->creditService->evaluateCreditExposure($this->tenant->id, $this->customer->id, '5000000.00');
        $this->assertSame(CreditEvaluationStatusEnum::ALLOWED, $res1->status);
        $this->assertTrue($res1->allowed);
        $this->assertSame('10000000.00', $res1->availableCredit);
        $this->assertSame('5000000.00', $res1->projectedTotalExposure);

        // 2. Order 8.500.000 (>= 80%) -> SOFT_WARNING
        $res2 = $this->creditService->evaluateCreditExposure($this->tenant->id, $this->customer->id, '8500000.00');
        $this->assertSame(CreditEvaluationStatusEnum::SOFT_WARNING, $res2->status);
        $this->assertTrue($res2->allowed);
        $this->assertStringContainsString('lebih dari 80%', (string) $res2->reason);

        // 3. Order 12.000.000 (> Limit 10.000.000) -> HARD_BLOCKED
        $res3 = $this->creditService->evaluateCreditExposure($this->tenant->id, $this->customer->id, '12000000.00');
        $this->assertSame(CreditEvaluationStatusEnum::HARD_BLOCKED, $res3->status);
        $this->assertFalse($res3->allowed);
        $this->assertStringContainsString('melebihi batas kredit', (string) $res3->reason);
    }

    public function test_evaluates_credit_exposure_blocks_when_account_is_on_hold_or_suspended(): void
    {
        $this->creditService->updateCreditLimit($this->customer->id, '20000000.00', $this->owner, 'Plafon 20jt');

        // Status ON_HOLD
        $this->creditService->setCreditStatus($this->customer->id, CreditStatusEnum::ON_HOLD, $this->owner, 'Keterlambatan konfirmasi transfer');
        $resHold = $this->creditService->evaluateCreditExposure($this->tenant->id, $this->customer->id, '1000000.00');
        $this->assertSame(CreditEvaluationStatusEnum::HARD_BLOCKED, $resHold->status);
        $this->assertStringContainsString('ON_HOLD', (string) $resHold->reason);

        // Status SUSPENDED
        $this->creditService->setCreditStatus($this->customer->id, CreditStatusEnum::SUSPENDED, $this->owner, 'Sengketa hukum');
        $resSuspended = $this->creditService->evaluateCreditExposure($this->tenant->id, $this->customer->id, '1000000.00');
        $this->assertSame(CreditEvaluationStatusEnum::HARD_BLOCKED, $resSuspended->status);
        $this->assertStringContainsString('SUSPENDED', (string) $resSuspended->reason);
    }

    public function test_evaluates_credit_exposure_blocks_when_customer_has_zero_credit_limit_for_credit_order(): void
    {
        // Credit limit default adalah 0
        $res = $this->creditService->evaluateCreditExposure($this->tenant->id, $this->customer->id, '500000.00');
        $this->assertSame(CreditEvaluationStatusEnum::HARD_BLOCKED, $res->status);
        $this->assertFalse($res->allowed);
        $this->assertStringContainsString('tidak memiliki plafon kredit', (string) $res->reason);
    }

    public function test_reserves_and_releases_order_exposure(): void
    {
        $this->creditService->updateCreditLimit($this->customer->id, '10000000.00', $this->owner, 'Plafon 10jt');

        $so = $this->salesOrderService->createOrder([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'payment_terms_days' => 30,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '10.0000', 'unit_price' => '200000.00'], // Total 2.000.000
            ],
        ], $this->owner);

        // Konfirmasi SO -> Mereservasi eksposur kredit
        $confirmed = $this->salesOrderService->confirmOrder($so, $this->owner);
        $this->assertSame(SalesOrderStatusEnum::CONFIRMED, $confirmed->status);
        $this->assertSame('2000000.00', (string) $confirmed->reserved_credit_amount);

        $account = CustomerCreditAccount::where('customer_id', $this->customer->id)->first();
        $this->assertSame('2000000.00', (string) $account->reserved_exposure);
        $this->assertSame('8000000.00', (string) $account->getAvailableCredit());

        // Batalkan SO -> Melepaskan eksposur kredit
        $cancelled = $this->salesOrderService->cancelOrder($confirmed, $this->owner, 'Customer membatalkan tender');
        $this->assertSame(SalesOrderStatusEnum::CANCELLED, $cancelled->status);
        $this->assertSame('0.00', (string) $cancelled->reserved_credit_amount);

        $account->refresh();
        $this->assertSame('0.00', (string) $account->reserved_exposure);
        $this->assertSame('10000000.00', (string) $account->getAvailableCredit());

        // Verifikasi log audit tercatat
        $this->assertDatabaseHas('customer_credit_logs', [
            'customer_id' => $this->customer->id,
            'event_type' => 'EXPOSURE_RESERVED',
        ]);
        $this->assertDatabaseHas('customer_credit_logs', [
            'customer_id' => $this->customer->id,
            'event_type' => 'EXPOSURE_RELEASED',
        ]);
    }

    public function test_credit_override_allows_confirmation_for_authorized_user_and_records_audit_log(): void
    {
        // Plafon kredit 1.000.000
        $this->creditService->updateCreditLimit($this->customer->id, '1000000.00', $this->owner, 'Plafon ketat 1jt');

        // Order bernilai 2.000.000 (melebihi limit)
        $so = $this->salesOrderService->createOrder([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'payment_terms_days' => 30,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '10.0000', 'unit_price' => '200000.00'],
            ],
        ], $this->owner);

        // 1. Konfirmasi biasa tanpa override -> DITOLAK
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('melebihi batas kredit');
        $this->salesOrderService->confirmOrder($so, $this->owner, false);
    }

    public function test_authorized_owner_can_override_credit_and_confirm_order(): void
    {
        // Plafon kredit 1.000.000
        $this->creditService->updateCreditLimit($this->customer->id, '1000000.00', $this->owner, 'Plafon ketat 1jt');

        // Order bernilai 2.000.000
        $so = $this->salesOrderService->createOrder([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'payment_terms_days' => 30,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '10.0000', 'unit_price' => '200000.00'],
            ],
        ], $this->owner);

        // 2. Konfirmasi dengan override oleh Owner -> BERHASIL
        $confirmed = $this->salesOrderService->confirmOrder($so, $this->owner, true, 'Otorisasi direksi untuk pelanggan prioritas');

        $this->assertSame(SalesOrderStatusEnum::CONFIRMED, $confirmed->status);
        $this->assertSame($this->owner->id, $confirmed->credit_override_approved_by);
        $this->assertSame('Otorisasi direksi untuk pelanggan prioritas', $confirmed->credit_override_reason);

        // Verifikasi log CREDIT_OVERRIDE dicatat di database
        $this->assertDatabaseHas('customer_credit_logs', [
            'customer_id' => $this->customer->id,
            'event_type' => 'CREDIT_OVERRIDE',
            'actor_user_id' => $this->owner->id,
        ]);
    }

    public function test_unauthorized_user_cannot_override_credit(): void
    {
        // Plafon kredit 1.000.000
        $this->creditService->updateCreditLimit($this->customer->id, '1000000.00', $this->owner, 'Plafon ketat 1jt');

        $so = $this->salesOrderService->createOrder([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'payment_terms_days' => 30,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '10.0000', 'unit_price' => '200000.00'],
            ],
        ], $this->owner);

        // Kasir mencoba override -> Ditolak karena tidak punya izin 'credit.override'
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak memiliki wewenang');
        $this->salesOrderService->confirmOrder($so, $this->cashier, true, 'Kasir mencoba override');
    }
}
