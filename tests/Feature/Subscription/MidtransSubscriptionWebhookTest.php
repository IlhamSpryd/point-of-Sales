<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Core\Context\TenantContext;
use App\Enums\OrderStatus;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Services\SystemUserResolver;
use App\Modules\Sales\Models\Order;
use App\Modules\Subscription\Application\Services\SubscriptionBillingOrchestrator;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\ProvisioningRequest;
use App\Modules\Subscription\Models\TenantSubscription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end Feature Test: Midtrans Subscription Webhook Settlement Wiring (EXT-01, ADR-113, R-11).
 */
class MidtransSubscriptionWebhookTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    private User $systemUser;

    private Plan $starterPlan;

    private Plan $enterprisePlan;

    private SubscriptionBillingOrchestrator $orchestrator;

    private const SERVER_KEY = 'test_midtrans_server_key_12345';

    protected function afterSubclassSetUp(): void
    {
        Config::set('services.midtrans.server_key', self::SERVER_KEY);

        $this->tenant = Tenant::create([
            'name' => 'Subscription Webhook Corp '.uniqid(),
            'slug' => 'sub-wh-'.uniqid(),
            'status' => 'ACTIVE',
            'access_status' => Tenant::ACCESS_ACTIVE,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'subscription_plan' => 'starter-plan',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'HQ Store',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        \Illuminate\Support\Facades\Artisan::call('tenant:provision-system-users', ['tenant' => $this->tenant->id]);

        $this->user = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Billing Admin',
            'email' => 'admin.'.uniqid().'@subwh.test',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->systemUser = User::firstOrCreate(
            ['email' => SystemUserResolver::selfOrder($this->tenant->id)->email],
            [
                'name' => 'System Order',
                'password' => bcrypt('password'),
                'role_id' => $role->id,
                'is_active' => true,
                'tenant_id' => $this->tenant->id,
            ]
        );

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
            'metadata' => ['initial' => true],
        ]);

        $this->orchestrator = app(SubscriptionBillingOrchestrator::class);
    }

    private function generateSignature(string $orderId, string $statusCode, string $grossAmount): string
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.self::SERVER_KEY);
    }

    public function test_valid_settlement_webhook_activates_target_plan_and_writes_audit(): void
    {
        $this->actingAs($this->user);
        $upgrade = $this->orchestrator->initiateUpgrade($this->tenant->id, $this->enterprisePlan->id, 'monthly');
        $orderId = $upgrade['order_id'];
        $grossAmount = '1499000.00';
        $signature = $this->generateSignature($orderId, '200', $grossAmount);

        $response = $this->postJson('/midtrans/notification', [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-tx-'.Str::random(10),
            'payment_type' => 'bank_transfer',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'ok',
                'subscription_settled' => true,
            ]);

        // Tenant subscription must now be on Enterprise plan
        $sub = TenantSubscription::where('tenant_id', $this->tenant->id)->latest('id')->first();
        $this->assertNotNull($sub);
        $this->assertSame($this->enterprisePlan->id, $sub->plan_id);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $sub->status);

        // Tenant model subscription_plan must match
        $freshTenant = $this->tenant->fresh();
        $this->assertSame($this->enterprisePlan->code, $freshTenant->subscription_plan);

        // ProvisioningRequest must be completed
        $this->assertDatabaseHas('provisioning_requests', [
            'result_tenant_id' => $this->tenant->id,
            'plan_id' => $this->enterprisePlan->id,
            'status' => ProvisioningRequest::STATUS_COMPLETED,
        ]);

        // Audit log entry must be recorded
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'subscription.plan.upgraded',
            'subject_type' => 'tenant_subscription',
        ]);
    }

    public function test_duplicate_replay_of_settlement_webhook_is_idempotent(): void
    {
        $this->actingAs($this->user);
        $upgrade = $this->orchestrator->initiateUpgrade($this->tenant->id, $this->enterprisePlan->id, 'monthly');
        $orderId = $upgrade['order_id'];
        $grossAmount = '1499000.00';
        $signature = $this->generateSignature($orderId, '200', $grossAmount);

        $payload = [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-tx-'.Str::random(10),
            'payment_type' => 'bank_transfer',
        ];

        // First delivery
        $res1 = $this->postJson('/midtrans/notification', $payload);
        $res1->assertStatus(200)->assertJson(['message' => 'ok', 'subscription_settled' => true]);

        $initialSubsCount = TenantSubscription::where('tenant_id', $this->tenant->id)->count();

        // Duplicate delivery (replay)
        $res2 = $this->postJson('/midtrans/notification', $payload);
        $res2->assertStatus(200)->assertJson(['message' => 'ok', 'subscription_settled' => true]);

        // Must not create extra subscription records
        $this->assertSame($initialSubsCount, TenantSubscription::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_invalid_sha512_signature_is_rejected_with_403_and_leaves_subscription_untouched(): void
    {
        $this->actingAs($this->user);
        $upgrade = $this->orchestrator->initiateUpgrade($this->tenant->id, $this->enterprisePlan->id, 'monthly');
        $orderId = $upgrade['order_id'];

        $response = $this->postJson('/midtrans/notification', [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => '1499000.00',
            'signature_key' => 'tampered_invalid_signature_hex_value',
            'transaction_status' => 'settlement',
        ]);

        $response->assertStatus(403)->assertJson(['message' => 'invalid signature']);

        // Subscription remains on starter plan
        $sub = TenantSubscription::where('tenant_id', $this->tenant->id)->latest('id')->first();
        $this->assertSame($this->starterPlan->id, $sub->plan_id);
    }

    public function test_pending_expire_deny_statuses_do_not_activate_target_plan(): void
    {
        $this->actingAs($this->user);

        // 1. Pending status
        $upgradePending = $this->orchestrator->initiateUpgrade($this->tenant->id, $this->enterprisePlan->id, 'monthly');
        $orderIdPending = $upgradePending['order_id'];
        $sigPending = $this->generateSignature($orderIdPending, '201', '1499000.00');

        $resPending = $this->postJson('/midtrans/notification', [
            'order_id' => $orderIdPending,
            'status_code' => '201',
            'gross_amount' => '1499000.00',
            'signature_key' => $sigPending,
            'transaction_status' => 'pending',
        ]);

        $resPending->assertStatus(200)->assertJson(['message' => 'ok', 'subscription_settled' => false]);
        $sub = TenantSubscription::where('tenant_id', $this->tenant->id)->latest('id')->first();
        $this->assertSame($this->starterPlan->id, $sub->plan_id);

        // 2. Deny status
        $upgradeDeny = $this->orchestrator->initiateUpgrade($this->tenant->id, $this->enterprisePlan->id, 'monthly');
        $orderIdDeny = $upgradeDeny['order_id'];
        $sigDeny = $this->generateSignature($orderIdDeny, '202', '1499000.00');

        $resDeny = $this->postJson('/midtrans/notification', [
            'order_id' => $orderIdDeny,
            'status_code' => '202',
            'gross_amount' => '1499000.00',
            'signature_key' => $sigDeny,
            'transaction_status' => 'deny',
        ]);

        $resDeny->assertStatus(200)->assertJson(['message' => 'ok', 'subscription_settled' => false]);
        $this->assertDatabaseHas('provisioning_requests', [
            'correlation_id' => $orderIdDeny,
            'status' => ProvisioningRequest::STATUS_FAILED,
        ]);

        // 3. Expire status
        $upgradeExpire = $this->orchestrator->initiateUpgrade($this->tenant->id, $this->enterprisePlan->id, 'monthly');
        $orderIdExpire = $upgradeExpire['order_id'];
        $sigExpire = $this->generateSignature($orderIdExpire, '202', '1499000.00');

        $resExpire = $this->postJson('/midtrans/notification', [
            'order_id' => $orderIdExpire,
            'status_code' => '202',
            'gross_amount' => '1499000.00',
            'signature_key' => $sigExpire,
            'transaction_status' => 'expire',
        ]);

        $resExpire->assertStatus(200)->assertJson(['message' => 'ok', 'subscription_settled' => false]);
    }

    public function test_regular_pos_order_webhooks_continue_functioning_normally(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_code' => 'ORD-POS-'.Str::random(8),
            'idempotency_key' => Str::uuid()->toString(),
            'order_status' => OrderStatus::Pending,
            'order_amount' => 75000,
            'subtotal_amount' => 75000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_method' => 'qris',
            'user_id' => $this->systemUser->id,
            'order_date' => now()->toDateString(),
        ]);

        $signature = $this->generateSignature($order->order_code, '200', '75000.00');

        $response = $this->postJson('/midtrans/notification', [
            'order_id' => $order->order_code,
            'status_code' => '200',
            'gross_amount' => '75000.00',
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-tx-pos-'.Str::random(8),
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_status' => OrderStatus::Paid->value,
        ]);
    }
}
