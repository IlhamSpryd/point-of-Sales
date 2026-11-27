<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Context\TenantContext;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Subscription\SubscriptionPortal;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Integration\Contracts\PaymentGatewayInterface;
use App\Modules\Organization\Models\Approval;
use App\Modules\Organization\Models\Device;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Services\CashierPinService;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Services\TransactionService;
use App\Modules\Subscription\Application\Services\SubscriptionBillingOrchestrator;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\ProvisioningRequest;
use App\Modules\Subscription\Models\TenantSubscription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class Wave10ABillingAndCartSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private Device $device;

    private Shift $shift;

    private User $owner;

    private User $cashier;

    private User $supervisor;

    private Category $category;

    private Plan $starterPlan;

    private Plan $enterprisePlan;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Wave10A Sec Tenant '.uniqid(),
            'slug' => 'wave10a-sec-'.uniqid(),
            'status' => 'ACTIVE',
            'access_status' => Tenant::ACCESS_ACTIVE,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'subscription_plan' => 'starter-plan',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Branch',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'role_code' => 'ROLE-OWN',
            'is_system_default' => 1,
            'permissions' => ['*'],
        ]);

        $cashierRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create', 'orders.view', 'sales.create'],
        ]);

        $supervisorRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Supervisor',
            'permissions' => ['orders.create', 'discount.approve'],
        ]);

        $pinService = app(CashierPinService::class);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $ownerRole->id,
            'is_active' => true,
        ]);
        $this->owner->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);
        $pinService->setPin($this->owner, '999999');

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $cashierRole->id,
            'is_active' => true,
        ]);
        $this->cashier->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);
        $pinService->setPin($this->cashier, '111111');

        $this->supervisor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $supervisorRole->id,
            'is_active' => true,
        ]);
        $this->supervisor->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);
        $pinService->setPin($this->supervisor, '654321');

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'device_name' => 'POS Sec 01',
            'device_code' => 'DEV-SEC-'.Str::random(5),
            'device_type' => 'pos_terminal',
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->shift = \App\Modules\Sales\Models\Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'device_id' => $this->device->id,
            'user_id' => $this->cashier->id,
            'status' => 'open',
            'opening_balance' => 100000,
            'opened_at' => now(),
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Beverages Sec',
        ]);

        $this->starterPlan = Plan::firstOrCreate(
            ['code' => 'starter-plan'],
            ['name' => 'Starter Plan', 'price' => 299000.00, 'billing_cycle' => 'monthly', 'is_active' => true]
        );

        $this->enterprisePlan = Plan::firstOrCreate(
            ['code' => 'enterprise-plan'],
            ['name' => 'Enterprise Unlimited', 'price' => 1499000.00, 'billing_cycle' => 'monthly', 'is_active' => true]
        );

        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $this->starterPlan->id,
            'status' => TenantSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(5),
        ]);
    }

    public function test_p0_01_unauthorized_user_blocked_and_self_serve_instant_upgrade_cannot_activate_enterprise_plan(): void
    {
        // 1. Cashier without subscription.manage cannot boot SubscriptionPortal
        Livewire::actingAs($this->cashier)
            ->test(SubscriptionPortal::class)
            ->assertForbidden();

        // 2. Instant self-serve activation method & Blade button must not exist
        $this->assertFalse(method_exists(SubscriptionPortal::class, 'confirmInstantUpgrade'));
        $blade = file_get_contents(resource_path('views/livewire/subscription/subscription-portal.blade.php'));
        $this->assertNotFalse($blade);
        $this->assertStringNotContainsString('confirmInstantUpgrade', $blade);
        $this->assertStringNotContainsString('Aktivasi Instan', $blade);

        // 3. Initiating payment upgrade keeps subscription on Starter until verified settlement
        Livewire::actingAs($this->owner)
            ->test(SubscriptionPortal::class)
            ->set('selectedPlanId', $this->enterprisePlan->id)
            ->set('billingCycle', 'monthly')
            ->call('initiatePaymentUpgrade')
            ->assertHasNoErrors();

        $this->assertSame('starter-plan', $this->tenant->fresh()->subscription_plan);
        $this->assertDatabaseHas('provisioning_requests', [
            'result_tenant_id' => $this->tenant->id,
            'plan_id' => $this->enterprisePlan->id,
            'purchased_billing_cycle' => 'monthly',
            'status' => ProvisioningRequest::STATUS_PENDING,
        ]);
    }

    public function test_p0_01_annual_billing_cycle_persists_12_months_and_gateway_errors_fail_closed(): void
    {
        $this->actingAs($this->owner);

        // Enterprise plan row defaults to monthly, but purchase is annual -> must persist & grant 12 months
        $orchestrator = app(SubscriptionBillingOrchestrator::class);
        $order = $orchestrator->initiateUpgrade($this->tenant->id, $this->enterprisePlan->id, 'annual');

        $this->assertDatabaseHas('provisioning_requests', [
            'id' => $order['provisioning_request_id'],
            'purchased_billing_cycle' => 'annual',
        ]);

        $settled = $orchestrator->settleFromWebhook(
            $order['order_id'],
            'settlement',
            'accept',
            [
                'order_id' => $order['order_id'],
                'gross_amount' => (string) $order['amount']->toDecimal(),
                'transaction_status' => 'settlement',
            ]
        );

        $this->assertTrue($settled);

        $sub = TenantSubscription::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();
        $this->assertTrue(
            $sub->ends_at->greaterThan(now()->addMonths(11)),
            'Annual billing cycle purchase must extend ends_at by 12 months, got '.$sub->ends_at->toDateString()
        );

        // Fail closed (502/503) when Midtrans gateway errors in non-local environment (never snap_token_sim_*)
        $failingGateway = $this->createMock(PaymentGatewayInterface::class);
        $failingGateway->method('createSnapToken')->willThrowException(new RuntimeException('Midtrans connection timeout'));
        $this->app->instance(PaymentGatewayInterface::class, $failingGateway);

        $failingOrchestrator = app(SubscriptionBillingOrchestrator::class);

        $caught = null;
        try {
            $failingOrchestrator->initiateUpgrade($this->tenant->id, $this->enterprisePlan->id, 'monthly');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected fail-closed gateway exception instead of snap_token_sim fallback.');
        $this->assertInstanceOf(HttpException::class, $caught);
        $this->assertContains($caught->getStatusCode(), [502, 503]);
    }

    public function test_p0_01_repeated_sub_webhook_settlement_and_activation_are_idempotent_under_lock(): void
    {
        $this->actingAs($this->owner);
        $orchestrator = app(SubscriptionBillingOrchestrator::class);
        $order = $orchestrator->initiateUpgrade($this->tenant->id, $this->enterprisePlan->id, 'monthly');
        $payload = [
            'order_id' => $order['order_id'],
            'gross_amount' => (string) $order['amount']->toDecimal(),
            'transaction_status' => 'settlement',
        ];

        $this->assertTrue($orchestrator->settleFromWebhook($order['order_id'], 'settlement', 'accept', $payload));
        $this->assertTrue($orchestrator->settleFromWebhook($order['order_id'], 'settlement', 'accept', $payload));

        // Direct duplicate activation call with same payment reference must also be idempotent
        $orchestrator->activatePaidSubscription($this->tenant->id, $this->enterprisePlan->id, $order['order_id']);

        $auditCount = \Illuminate\Support\Facades\DB::table('activity_logs')
            ->where('tenant_id', $this->tenant->id)
            ->where('action', 'subscription.plan.upgraded')
            ->count();

        $this->assertSame(1, $auditCount, 'Duplicate settlement/activation must record exactly one upgrade audit entry.');
    }

    public function test_p0_02_livewire_pos_cart_and_discount_properties_are_locked(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Signature Latte',
            'product_price' => 45000,
            'stock' => 50,
            'is_active' => true,
            'is_open_price' => false,
        ]);

        foreach ([
            ['prop' => 'cart.0.custom_price', 'val' => 1],
            ['prop' => 'itemDiscounts', 'val' => [0 => 45000]],
            ['prop' => 'appliedManualDiscount', 'val' => 45000],
            ['prop' => 'appliedManualDiscountReason', 'val' => 'Tampered'],
            ['prop' => 'manualDiscountApprovalId', 'val' => 999999],
        ] as $case) {
            $component = Livewire::actingAs($this->cashier)
                ->test(CreateOrder::class)
                ->call('addToCart', $product->id);

            $lockedExceptionThrown = false;
            try {
                $component->set($case['prop'], $case['val']);
            } catch (CannotUpdateLockedPropertyException) {
                $lockedExceptionThrown = true;
            }

            $this->assertTrue($lockedExceptionThrown, "Property {$case['prop']} must be #[Locked] against client updates.");
        }
    }

    public function test_p0_02_domain_layer_rejects_tampered_custom_price_and_unapproved_discounts(): void
    {
        $standardProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Fixed Price Steak',
            'product_price' => 150000,
            'stock' => 20,
            'is_active' => true,
            'is_open_price' => false,
        ]);

        $openProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Custom Cake',
            'product_price' => 100000,
            'stock' => 20,
            'is_active' => true,
            'is_open_price' => true,
        ]);

        $txService = app(TransactionService::class);
        $this->actingAs($this->cashier);

        // Case A: custom_price on non-open-price product rejected in domain layer
        try {
            $txService->createTransaction([
                'items' => [[
                    'product_id' => $standardProduct->id,
                    'quantity' => 1,
                    'custom_price' => 1000,
                ]],
                'payment_method' => 'cash',
                'cash_received' => 1000,
                'shift_id' => $this->shift->id,
                'device_id' => $this->device->id,
                'idempotency_key' => (string) Str::uuid(),
            ], $this->cashier->id);
            $this->fail('Expected ValidationException when custom_price is supplied for non-open-price product.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        // Case B: custom_price on open-price product without pos.price.open_entry rejected in domain layer
        try {
            $txService->createTransaction([
                'items' => [[
                    'product_id' => $openProduct->id,
                    'quantity' => 1,
                    'custom_price' => 1000,
                ]],
                'payment_method' => 'cash',
                'cash_received' => 1000,
                'shift_id' => $this->shift->id,
                'device_id' => $this->device->id,
                'idempotency_key' => (string) Str::uuid(),
            ], $this->cashier->id);
            $this->fail('Expected ValidationException when cashier lacks pos.price.open_entry.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        // Case C: item_discount_amount exceeding line subtotal or safe threshold without Approval rejected
        try {
            $txService->createTransaction([
                'items' => [[
                    'product_id' => $standardProduct->id,
                    'quantity' => 1,
                    'item_discount_amount' => 200000,
                ]],
                'payment_method' => 'cash',
                'cash_received' => 0,
                'shift_id' => $this->shift->id,
                'device_id' => $this->device->id,
                'idempotency_key' => (string) Str::uuid(),
            ], $this->cashier->id);
            $this->fail('Expected ValidationException when item_discount_amount exceeds subtotal / threshold.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        // Case D: item_discount_amount exceeding safe threshold (20%) without approval rejected
        try {
            $txService->createTransaction([
                'items' => [[
                    'product_id' => $standardProduct->id,
                    'quantity' => 1,
                    'item_discount_amount' => 30000,
                ]],
                'payment_method' => 'cash',
                'cash_received' => 120000,
                'shift_id' => $this->shift->id,
                'device_id' => $this->device->id,
                'idempotency_key' => (string) Str::uuid(),
            ], $this->cashier->id);
            $this->fail('Expected ValidationException when item_discount_amount exceeds safe threshold without Approval.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        // Case E: manual_discount_amount > safe threshold with forged/under-amount approval rejected
        $smallApproval = Approval::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'requester_user_id' => $this->cashier->id,
            'approver_user_id' => $this->supervisor->id,
            'action_code' => Approval::ACTION_MANUAL_DISCOUNT,
            'amount' => 5000.00,
            'reason' => 'Small approval',
            'status' => Approval::STATUS_APPROVED,
        ]);

        try {
            $txService->createTransaction([
                'items' => [[
                    'product_id' => $standardProduct->id,
                    'quantity' => 1,
                ]],
                'manual_discount_amount' => 50000,
                'approval_id' => $smallApproval->id,
                'payment_method' => 'cash',
                'cash_received' => 100000,
                'shift_id' => $this->shift->id,
                'device_id' => $this->device->id,
                'idempotency_key' => (string) Str::uuid(),
            ], $this->cashier->id);
            $this->fail('Expected ValidationException when manual_discount_amount exceeds Approval amount.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
