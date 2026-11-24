<?php

declare(strict_types=1);

namespace Tests\Feature\Uat;

use App\Core\Context\TenantContext;
use App\Enums\ApprovalStatusEnum;
use App\Enums\KitchenStation;
use App\Enums\OrderStatus;
use App\Events\KdsOrderCreated;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Workflow\UnifiedApprovalInbox;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Inventory\Models\ProductStockBalance;
use App\Modules\Organization\Models\ActivityLog;
use App\Modules\Organization\Models\Company;
use App\Modules\Organization\Models\Device;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Services\RoleService;
use App\Modules\Purchasing\Models\PurchaseApprovalRequest;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Services\TransactionService;
use App\Modules\Subscription\Application\Services\SubscriptionBillingOrchestrator;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\ProvisioningRequest;
use App\Modules\Subscription\Models\ProvisioningStep;
use App\Modules\Subscription\Models\TenantSubscription;
use App\Platform\Authorization\AbilityEvaluator;
use App\Platform\Navigation\NavigationRegistry;
use App\Platform\Navigation\NavigationVisibilityResolver;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Enterprise Production User Acceptance Testing (UAT) Suite (`v1.6.1-uat-verified`).
 *
 * Empirically validates all 6 Core Commercial & Operational Business Journeys end-to-end:
 * - UAT-01: Commercial S2S Marketing Provisioning Journey (EXT-03, ADR-117)
 * - UAT-02: Tier-1 9x19x45 Navigation & RBAC Scope Governance Journey (AUTH-02, OQ-01, OQ-19)
 * - UAT-03: F&B Frontline Waiter Order-Only & Real-Time KDS Broadcast Journey (OQ-09)
 * - UAT-04: POS Checkout, Double-Entry GL Outbox & Offline Cash Idempotent Replay Journey (EXT-05, FIN-M7-01)
 * - UAT-05: Supervisor Branch-Scoped Maker-Checker Approval Inbox Journey (OQ-10, EXT-04)
 * - UAT-06: Self-Serve Midtrans Webhook Upgrade & UU PDP Tenant Lifecycle Journey (EXT-01, EXT-06)
 */
class EnterpriseProductionUatTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private string $provisioningSecret = 'enterprise-uat-s2s-hmac-secret-64char-length-key-2026-production01';

    private const MIDTRANS_SERVER_KEY = 'uat_midtrans_server_key_enterprise_2026';

    protected function afterSubclassSetUp(): void
    {
        Config::set('services.provisioning.secret_current', $this->provisioningSecret);
        Config::set('services.provisioning.secret_previous', null);
        Config::set('services.provisioning.timestamp_window', 300);
        Config::set('services.provisioning.nonce_retention', 1800);
        Config::set('services.midtrans.server_key', self::MIDTRANS_SERVER_KEY);
    }

    /**
     * Helper to compute exact HMAC-SHA256 signature matching VerifyProvisioningSignature and Cloudflare Pages register.ts.
     */
    private function computeProvisioningSignature(string $method, string $path, string $timestamp, string $nonce, string $rawBody, string $secret): string
    {
        $bodyHash = hash('sha256', $rawBody);
        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $bodyHash,
        ]);

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Helper to prepare valid headers for signed provisioning request.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function makeProvisioningHeaders(string $endpoint, array $payload, string $nonce, string $timestamp): array
    {
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = $this->computeProvisioningSignature(
            'POST',
            $endpoint,
            $timestamp,
            $nonce,
            $rawBody,
            $this->provisioningSecret
        );

        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Idempotency-Key' => 'uat-idem-'.Str::random(24),
            'X-Signature' => $signature,
        ];
    }

    /**
     * Helper to create a fully provisioned tenant fixture for UAT isolation.
     *
     * @return array{tenant: Tenant, company: Company, store: Store, owner: User}
     */
    private function createProvisionedTenant(string $tenantName = 'UAT Tenant'): array
    {
        $tenant = Tenant::create([
            'name' => $tenantName.' '.uniqid(),
            'slug' => 'uat-'.uniqid(),
            'status' => 'ACTIVE',
            'access_status' => Tenant::ACCESS_ACTIVE,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'subscription_plan' => 'starter-plan',
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'company_code' => 'CMP-'.strtoupper(Str::random(4)),
            'company_name' => $tenant->name.' Entity',
            'legal_name' => $tenant->name.' PT',
            'is_active' => true,
            'is_default' => true,
        ]);

        $store = Store::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Outlet '.uniqid(),
            'is_active' => true,
        ]);

        app(RoleService::class)->provisionDefaultRoles($tenant->id);

        $ownerRole = Role::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Owner')
            ->firstOrFail();

        $owner = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Owner Admin',
            'email' => 'owner.'.uniqid().'@uat.test',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $ownerRole->id,
            'store_id' => $store->id,
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'store_id' => $store->id,
        ]);

        return ['tenant' => $tenant, 'company' => $company, 'store' => $store, 'owner' => $owner];
    }

    /**
     * Helper to create a role-assigned user under a specific tenant.
     */
    private function createUserWithRole(Tenant $tenant, Store $store, string $roleName, string $namePrefix = 'Staff'): User
    {
        $role = Role::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('name', $roleName)
            ->firstOrFail();

        $user = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => "{$namePrefix} {$roleName}",
            'email' => strtolower($roleName).'.'.uniqid().'@uat.test',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'store_id' => $store->id,
            'is_active' => true,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'store_id' => $store->id,
        ]);

        return $user;
    }

    // =========================================================================
    // UAT-01: Commercial S2S Marketing Provisioning Journey
    // =========================================================================

    public function test_uat_01_commercial_s2s_marketing_provisioning_journey(): void
    {
        $endpoint = '/api/v1/provisioning/registrations';
        $unique = strtolower(Str::random(6));
        $payload = [
            'tenant_name' => 'Restoran UAT Nusantara '.$unique,
            'owner_name' => 'Bambang Sudirman',
            'owner_email' => 'bambang.'.$unique.'@nusantara.test',
            'owner_phone' => '+6281298765432',
        ];

        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $headers = $this->makeProvisioningHeaders($endpoint, $payload, $nonce, $timestamp);

        // 1. Send valid signed provisioning registration
        $response = $this->postJson($endpoint, $payload, $headers);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Provisioning berhasil. Email onboarding akan dikirim ke Owner.',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'tenant_id',
                'owner_user_id',
                'store_id',
                'onboarding' => ['email_sent_to'],
            ]);

        $responseData = $response->json();
        $tenantId = (int) $responseData['tenant_id'];
        $ownerId = (int) $responseData['owner_user_id'];
        $storeId = (int) $responseData['store_id'];

        // 2. Verify deterministic tenant, owner, and store persistence
        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'name' => 'Restoran UAT Nusantara '.$unique,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $ownerId,
            'tenant_id' => $tenantId,
            'email' => 'bambang.'.$unique.'@nusantara.test',
        ]);

        $this->assertDatabaseHas('stores', [
            'id' => $storeId,
            'tenant_id' => $tenantId,
        ]);

        // 2b. Verify onboarding token generated per EXT-03
        $this->assertNotEmpty($responseData['onboarding']['onboarding_token'] ?? null, 'Onboarding token must be generated in testing environment.');
        $this->assertDatabaseHas('owner_onboarding_tokens', [
            'tenant_id' => $tenantId,
            'user_id' => $ownerId,
        ]);

        // 3. Verify all provisioning steps marked COMPLETED
        $idempotencyKey = $headers['X-Idempotency-Key'];
        $provisioningReq = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($provisioningReq);
        $this->assertSame(ProvisioningRequest::STATUS_COMPLETED, $provisioningReq->status);
        $this->assertSame($tenantId, (int) $provisioningReq->result_tenant_id);

        $steps = ProvisioningStep::where('provisioning_request_id', $provisioningReq->id)->get();
        $this->assertGreaterThanOrEqual(4, $steps->count());
        $completedSteps = $steps->where('status', ProvisioningStep::STATUS_COMPLETED)->pluck('step_key')->all();

        $this->assertContains(ProvisioningStep::STEP_CREATE_TENANT, $completedSteps);
        $this->assertContains(ProvisioningStep::STEP_CREATE_COMPANY_AND_BRANCH, $completedSteps);
        $this->assertContains(ProvisioningStep::STEP_SEED_ROLES_AND_OWNER, $completedSteps);
        $this->assertContains(ProvisioningStep::STEP_ACTIVATE_SUBSCRIPTION_PLAN, $completedSteps);

        // 4. Verify 11 canonical default roles seeded
        $seededRolesCount = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $this->assertSame(11, $seededRolesCount, 'All 11 canonical default roles must be seeded upon tenant provisioning.');

        // 5. Verify nonce stored in anti-replay table
        $this->assertDatabaseHas('provisioning_nonces', [
            'nonce' => $nonce,
        ]);

        // 6. Anti-replay verification: identical nonce replay must be rejected fail-closed
        $replayPayload = [
            'tenant_name' => 'Replay Attempt '.$unique,
            'owner_name' => 'Replay Attacker',
            'owner_email' => 'attacker.'.$unique.'@hostile.test',
        ];
        $replayHeaders = $this->makeProvisioningHeaders($endpoint, $replayPayload, $nonce, (string) now()->getTimestamp());
        $replayResponse = $this->postJson($endpoint, $replayPayload, $replayHeaders);

        // Verify rejected with 409 (nonce_replayed) or 401
        $this->assertTrue(
            in_array($replayResponse->status(), [401, 409], true),
            "Replayed nonce must be rejected with 401 or 409, got {$replayResponse->status()}."
        );
        $this->assertFalse($replayResponse->json('success'));
    }

    // =========================================================================
    // UAT-02: Tier-1 9x19x45 Navigation & RBAC Scope Governance Journey
    // =========================================================================

    public function test_uat_02_tier1_navigation_and_rbac_scope_governance_journey(): void
    {
        $fixture = $this->createProvisionedTenant('Nav Governance Tenant');
        $tenant = $fixture['tenant'];
        $store = $fixture['store'];
        $owner = $fixture['owner'];

        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        $admin = $this->createUserWithRole($tenant, $store, 'Admin', 'Alex');
        $sales = $this->createUserWithRole($tenant, $store, 'Sales', 'Sara');
        $finance = $this->createUserWithRole($tenant, $store, 'Finance', 'Farhan');

        $registry = app(NavigationRegistry::class);
        $resolver = app(NavigationVisibilityResolver::class);
        $abilityEvaluator = app(AbilityEvaluator::class);

        // 1. Owner sees the compact 10-hub sidebar and reaches finance.fixed-assets.index (HTTP 200)
        $ownerSections = $resolver->sidebar($owner);
        $ownerHubCount = array_sum(array_map(static fn (array $s): int => count($s['hubs']), $ownerSections));
        $this->assertSame(10, $ownerHubCount, 'Owner must see exactly 10 Calm Hubs.');

        $this->actingAs($owner)
            ->get(route('finance.fixed-assets.index'))
            ->assertStatus(200);

        // 2. Admin reaches IT/Organization/Users/Settings routes (HTTP 200)
        $this->actingAs($admin)->get(route('users.index'))->assertStatus(200);
        $this->actingAs($admin)->get(route('roles.index'))->assertStatus(200);
        $this->actingAs($admin)->get(route('settings.index'))->assertStatus(200);
        $this->actingAs($admin)->get(route('stores.index'))->assertStatus(200);
        $this->actingAs($admin)->get(route('devices.index'))->assertStatus(200);

        // Admin is strictly blocked (SoD) from Subscription Billing, GL Posting/Period Lock, Payroll Approval
        $this->assertFalse(
            $abilityEvaluator->can($admin, 'subscription.billing.view'),
            'Admin must be blocked from subscription billing view.'
        );
        $this->assertFalse(
            $abilityEvaluator->can($admin, 'finance.journal.post'),
            'Admin must be blocked from finance journal post (SoD).'
        );
        $this->assertFalse(
            $abilityEvaluator->can($admin, 'finance.close_period'),
            'Admin must be blocked from finance close period (SoD).'
        );
        $this->assertFalse(
            $abilityEvaluator->can($admin, 'workforce.payroll.approve'),
            'Admin must be blocked from payroll approval (SoD).'
        );

        // Admin cannot edit Owner account or Owner role
        $ownerRole = Role::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', 'Owner')->firstOrFail();
        $this->assertFalse(
            $admin->can('update', $owner),
            'Admin must be forbidden from updating Owner account.'
        );
        $this->assertFalse(
            $admin->can('update', $ownerRole),
            'Admin must be forbidden from updating Owner role.'
        );

        // Admin HTTP route level 403 blocks (Subscription Billing & Owner editing)
        $this->actingAs($admin)->get(route('subscription.portal'))->assertStatus(403);
        $this->actingAs($admin)->get(route('users.edit', $owner->id))->assertStatus(403);
        $this->actingAs($admin)->get(route('roles.edit', $ownerRole->id))->assertStatus(403);

        // 3. Sales reaches Customer Directory (customers.index, HTTP 200)
        $this->actingAs($sales)->get(route('customers.index'))->assertStatus(200);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pelanggan UAT B2B',
            'phone' => '08111222333',
            'credit_limit' => 10000000.00,
            'is_active' => true,
        ]);

        // Sales is blocked from deleting customer or mutating credit_limit
        $this->assertFalse(
            $sales->can('delete', $customer),
            'Sales role must be forbidden from deleting customers.'
        );
        $this->assertFalse(
            $sales->can('manageCreditLimit', $customer),
            'Sales role must be forbidden from mutating credit limit.'
        );

        Livewire::actingAs($sales)
            ->test(\App\Livewire\Customers\CustomerManager::class)
            ->call('delete', $customer->id, app(\App\Modules\Customers\Services\CustomerService::class))
            ->assertStatus(403);

        // 4. Finance reaches Finance & Supplier Invoices without hitting 403 breadcrumb dead-ends
        $this->actingAs($finance)->get(route('finance.index'))->assertStatus(200);
        $this->actingAs($finance)->get(route('purchasing.invoices.index'))->assertStatus(200);

        $crumbs = $resolver->breadcrumbsForRoute('finance.index', $finance);
        foreach ($crumbs as $crumb) {
            if (isset($crumb['url'])) {
                $this->assertStringNotContainsString(
                    '/dashboard',
                    $crumb['url'],
                    'Finance breadcrumb must never redirect to 403-restricted executive dashboard.'
                );
            }
        }
    }

    // =========================================================================
    // UAT-03: F&B Frontline Waiter Order-Only & Real-Time KDS Broadcast Journey
    // =========================================================================

    public function test_uat_03_frontline_waiter_order_only_and_realtime_kds_broadcast_journey(): void
    {
        $fixture = $this->createProvisionedTenant('F&B KDS Bistro');
        $tenant = $fixture['tenant'];
        $store = $fixture['store'];

        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        $waiter = $this->createUserWithRole($tenant, $store, 'Waiter', 'Wayan');
        $cook = $this->createUserWithRole($tenant, $store, 'Cook', 'Chef Juna');

        // Create cross-tenant intruder in isolated tenant context
        $foreignTenant = null;
        $foreignStore = null;
        $intruderCook = null;

        app(TenantContext::class)->runWithoutTenant(function () use (&$foreignTenant, &$foreignStore, &$intruderCook) {
            $foreignTenant = Tenant::create([
                'name' => 'Foreign Cafe '.uniqid(),
                'slug' => 'foreign-'.uniqid(),
                'status' => 'ACTIVE',
                'access_status' => Tenant::ACCESS_ACTIVE,
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            ]);
            $foreignStore = Store::withoutGlobalScopes()->create([
                'tenant_id' => $foreignTenant->id,
                'name' => 'Foreign Branch',
                'is_active' => true,
            ]);
            app(RoleService::class)->provisionDefaultRoles($foreignTenant->id);

            $foreignRole = Role::withoutGlobalScopes()
                ->where('tenant_id', $foreignTenant->id)
                ->where('name', 'Cook')
                ->firstOrFail();

            $intruderCook = User::withoutGlobalScopes()->create([
                'tenant_id' => $foreignTenant->id,
                'name' => 'Spy Cook',
                'email' => 'spy.cook.'.uniqid().'@foreign.test',
                'email_verified_at' => now(),
                'password' => bcrypt('password'),
                'role_id' => $foreignRole->id,
                'store_id' => $foreignStore->id,
                'is_active' => true,
            ]);
        });

        // Restore active tenant context
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        // 1. Waiter accesses POS (pos.index / transaction.create) in Order-Only mode
        $this->actingAs($waiter)
            ->withSession(['active_store_id' => $store->id])
            ->get(route('transaction.create'))
            ->assertStatus(200);

        // 2. Waiter attempting payment checkout via Livewire submitOrder is strictly rejected (403)
        $this->withSession(['active_store_id' => $store->id]);
        Livewire::actingAs($waiter)
            ->test(CreateOrder::class)
            ->call('submitOrder', [
                ['method' => 'cash', 'amount' => 50000, 'amount_tendered' => 50000],
            ])
            ->assertStatus(403);

        // 3. Waiter attempting order void or cash drawer open is forbidden (403)
        $this->assertFalse(
            $waiter->hasPermission('orders.void'),
            'Waiter must not have permission to void orders.'
        );
        $this->assertFalse(
            $waiter->hasPermission('pos.cash_drawer.open'),
            'Waiter must not have permission to open cash drawer.'
        );

        // 4. Dine-in order dispatches KdsOrderCreated to tenant+store private channel
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Makanan Utama',
            'slug' => 'makanan-utama-'.uniqid(),
            'kitchen_station' => KitchenStation::Kitchen,
        ]);

        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Nasi Goreng Spesial',
            'product_code' => 'NASGOR-'.uniqid(),
            'product_price' => 45000.00,
            'product_type' => 'standard',
            'kitchen_station' => KitchenStation::Kitchen,
            'is_active' => true,
        ]);

        Event::fake([KdsOrderCreated::class]);

        ProductStockBalance::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $product->id],
            ['quantity' => 50, 'reserved_quantity' => 0]
        );

        $transactionService = app(TransactionService::class);
        $waiterOrder = $transactionService->createTransaction([
            'store_id' => $store->id,
            'order_type' => 'dine_in',
            'payment_method' => 'qris',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 45000,
                ],
            ],
            'idempotency_key' => 'kds-event-test-'.uniqid(),
        ], $waiter->id);

        $this->assertNotNull($waiterOrder);
        Event::assertDispatched(KdsOrderCreated::class, function (KdsOrderCreated $event) use ($waiterOrder, $tenant, $store) {
            $channels = $event->broadcastOn();

            return (int) $event->orderId === (int) $waiterOrder->id
                && (int) $event->tenantId === (int) $tenant->id
                && (int) $event->storeId === (int) $store->id
                && count($channels) === 1
                && $channels[0]->name === "private-kds.tenant.{$tenant->id}.store.{$store->id}"
                && $event->broadcastAs() === 'kds.order.created';
        });

        // 5. Cross-tenant user attempting to authorize on Tenant A's KDS channel is denied (false)
        $channelDefinitions = Broadcast::getChannels();
        $this->assertArrayHasKey('kds.tenant.{tenantId}.store.{storeId}', $channelDefinitions);
        $channelAuthCallback = $channelDefinitions['kds.tenant.{tenantId}.store.{storeId}'];

        // Authorized cook in Tenant A Store 1 is granted
        $this->assertTrue(
            $channelAuthCallback($cook, $tenant->id, $store->id),
            'Authorized cook in Tenant A must be authorized for store KDS channel.'
        );

        // Intruder cook from Tenant B is strictly denied
        $this->assertNotNull($intruderCook);
        $this->assertFalse(
            $channelAuthCallback($intruderCook, $tenant->id, $store->id),
            'Cross-tenant intruder must be strictly denied from KDS channel.'
        );
    }

    // =========================================================================
    // UAT-04: POS Checkout, Double-Entry GL Outbox & Offline Cash Idempotent Replay Journey
    // =========================================================================

    public function test_uat_04_pos_checkout_double_entry_gl_outbox_and_offline_cash_idempotent_replay_journey(): void
    {
        $fixture = $this->createProvisionedTenant('Retail POS Operations');
        $tenant = $fixture['tenant'];
        $store = $fixture['store'];

        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        $cashier = $this->createUserWithRole($tenant, $store, 'Kasir', 'Budi');

        $device = Device::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'device_name' => 'POS Terminal 01',
            'device_code' => 'DEV-POS-'.uniqid(),
            'device_type' => 'pos',
            'is_active' => true,
        ]);

        // 1. Seed COA, default posting rules, and open accounting period
        app(ChartOfAccountsSeeder::class)->seedForTenant($tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($tenant->id);

        AccountingPeriod::create([
            'tenant_id' => $tenant->id,
            'period_code' => now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'fiscal_year' => (int) now()->format('Y'),
            'period_number' => (int) now()->format('n'),
            'status' => AccountingPeriodStatusEnum::OPEN->value,
            'is_adjustment_period' => false,
        ]);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'user_id' => $cashier->id,
            'active_cashier_id' => $cashier->id,
            'starting_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'product_price' => 50000.00,
            'standard_cost' => 30000.00,
            'stock' => 100,
            'track_inventory' => true,
        ]);

        ProductStockBalance::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $product->id],
            ['quantity' => 100, 'reserved_quantity' => 0]
        );

        // Part A: Normal Online Checkout with Financial Outbox & Balanced GL Journal
        $transactionService = app(TransactionService::class);
        $order = $transactionService->createTransaction([
            'store_id' => $store->id,
            'shift_id' => $shift->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 50000,
                ],
            ],
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => 111000,
                    'amount_tendered' => 111000,
                ],
            ],
            'idempotency_key' => 'UAT-ONLINE-CHECKOUT-'.uniqid(),
        ], $cashier->id);

        $this->assertNotNull($order);
        $this->assertSame(OrderStatus::Paid->value, $order->order_status->value ?? $order->order_status);

        // Verify stock decremented by 2
        $stockAfter = (int) ProductStockBalance::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->value('quantity');
        $this->assertSame(98, $stockAfter);

        // Verify FinancialEventOutbox processed
        $outbox = FinancialEventOutbox::where('tenant_id', $tenant->id)
            ->where('event_type', 'pos.order.paid')
            ->where('source_id', $order->id)
            ->first();

        $this->assertNotNull($outbox, 'FinancialEventOutbox must be created.');
        $this->assertSame('PROCESSED', $outbox->status->value ?? $outbox->status);

        // Verify balanced double-entry journal entry: SUM(debit) === SUM(credit)
        $journal = JournalEntry::find($outbox->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertSame(JournalStatusEnum::POSTED->value, $journal->status->value);
        $this->assertEquals($journal->total_debit, $journal->total_credit);
        $this->assertTrue(bccomp($journal->total_debit, '0.00', 2) > 0);

        // Part B: Offline Cash Order Sync & Idempotent Replay
        $offlineUuid = 'UAT-OFFLINE-UUID-'.(string) Str::uuid();
        $offlineSyncPayload = [
            'store_id' => $store->id,
            'device_id' => $device->id,
            'orders' => [
                [
                    'offline_client_uuid' => $offlineUuid,
                    'payment_method' => 'cash',
                    'order_amount' => 50000,
                    'subtotal_amount' => 50000,
                    'cash_received' => 100000,
                    'order_change' => 50000,
                    'items' => [
                        ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 25000, 'subtotal' => 50000],
                    ],
                ],
            ],
        ];

        // 1st sync submission: must succeed and report synced_count = 1
        $syncRes1 = $this->actingAs($cashier)
            ->withSession(['active_store_id' => $store->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $offlineSyncPayload);

        $syncRes1->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.synced_count', 1)
            ->assertJsonPath('data.failed_count', 0);

        $offlineOrderId = $syncRes1->json('data.results.0.order_id');
        $this->assertNotNull($offlineOrderId);

        // Stock before was 98, now deducted by 2 -> 96
        $stockAfterOffline1 = (int) ProductStockBalance::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->value('quantity');
        $this->assertSame(96, $stockAfterOffline1);

        // 2nd sync submission (idempotent replay): must return duplicate_skipped without double deduction
        $syncRes2 = $this->actingAs($cashier)
            ->withSession(['active_store_id' => $store->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $offlineSyncPayload);

        $syncRes2->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.synced_count', 1)
            ->assertJsonPath('data.failed_count', 0);

        $this->assertSame('already_synced', $syncRes2->json('data.results.0.status'));
        $this->assertSame('duplicate_skipped', $syncRes2->json('data.results.0.sync_status'));
        $this->assertSame($offlineOrderId, $syncRes2->json('data.results.0.order_id'));

        // Zero duplicate deduction: stock must strictly remain 96
        $stockAfterOffline2 = (int) ProductStockBalance::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->value('quantity');
        $this->assertSame(96, $stockAfterOffline2);

        // Part C: Non-cash offline order fails validation
        $invalidNonCashPayload = [
            'payment_method' => 'qris',
            'store_id' => $store->id,
            'device_id' => $device->id,
            'orders' => [
                [
                    'offline_client_uuid' => (string) Str::uuid(),
                    'payment_method' => 'qris',
                    'order_amount' => 50000,
                    'subtotal_amount' => 50000,
                    'items' => [
                        ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50000, 'subtotal' => 50000],
                    ],
                ],
            ],
        ];

        $syncResInvalid = $this->actingAs($cashier)
            ->withSession(['active_store_id' => $store->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $invalidNonCashPayload);

        $syncResInvalid->assertStatus(422);
    }

    // =========================================================================
    // UAT-05: Supervisor Branch-Scoped Maker-Checker Approval Inbox Journey
    // =========================================================================

    public function test_uat_05_supervisor_branch_scoped_maker_checker_approval_inbox_journey(): void
    {
        $fixture = $this->createProvisionedTenant('Maker-Checker Corporate');
        $tenant = $fixture['tenant'];
        $storeA = $fixture['store'];

        $storeB = Store::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Branch B - West Outlet',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($storeA->id);

        $kasirMaker = $this->createUserWithRole($tenant, $storeA, 'Kasir', 'Maker Kasir');
        $supervisorA = $this->createUserWithRole($tenant, $storeA, 'Supervisor', 'Surya Store A');

        // Create operational approval request in Store A (Order Void Request)
        $operationalRequestA = PurchaseApprovalRequest::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'approvable_type' => 'OrderVoid',
            'approvable_id' => 701,
            'rule_id' => null,
            'step_order' => 1,
            'status' => ApprovalStatusEnum::Pending,
            'submitted_by_user_id' => $kasirMaker->id,
        ]);

        // 1. Self-approval by requester is blocked by MakerCheckerGuard (SoD violation)
        Livewire::actingAs($kasirMaker)
            ->test(UnifiedApprovalInbox::class)
            ->call('approve', $operationalRequestA->id)
            ->assertSee('Pelanggaran Pemisahan Tugas (SoD)');

        $operationalRequestA->refresh();
        $this->assertSame(ApprovalStatusEnum::Pending, $operationalRequestA->status);
        $this->assertNull($operationalRequestA->approved_by_user_id);

        // 2. Supervisor of Store A attempting to approve Store B's request is strictly rejected
        $kasirStoreB = User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Kasir Store B',
            'email' => 'kasir.b.'.uniqid().'@uat.test',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => Role::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', 'Kasir')->firstOrFail()->id,
            'store_id' => $storeB->id,
            'is_active' => true,
        ]);
        DB::table('user_stores')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $kasirStoreB->id,
            'store_id' => $storeB->id,
        ]);

        $operationalRequestB = PurchaseApprovalRequest::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'approvable_type' => 'OrderVoid',
            'approvable_id' => 702,
            'rule_id' => null,
            'step_order' => 1,
            'status' => ApprovalStatusEnum::Pending,
            'submitted_by_user_id' => $kasirStoreB->id,
        ]);

        // Supervisor A (assigned to Store A) attempting to approve Store B's request is blocked
        Livewire::actingAs($supervisorA)
            ->test(UnifiedApprovalInbox::class)
            ->assertDontSee("#{$operationalRequestB->id}")
            ->call('approve', $operationalRequestB->id)
            ->assertSee('Supervisor hanya dapat menyetujui permohonan pada cabang yang ditugaskan');

        $operationalRequestB->refresh();
        $this->assertSame(ApprovalStatusEnum::Pending, $operationalRequestB->status);

        // 3. Supervisor from another tenant attempting to approve Tenant A's request is strictly rejected
        $foreignTenant = null;
        $foreignStore = null;
        $foreignSupervisor = null;

        app(TenantContext::class)->runWithoutTenant(function () use (&$foreignTenant, &$foreignStore, &$foreignSupervisor) {
            $foreignTenant = Tenant::create([
                'name' => 'Foreign Corp '.uniqid(),
                'slug' => 'foreign-corp-'.uniqid(),
                'status' => 'ACTIVE',
                'access_status' => Tenant::ACCESS_ACTIVE,
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            ]);
            $foreignStore = Store::withoutGlobalScopes()->create([
                'tenant_id' => $foreignTenant->id,
                'name' => 'Foreign Store',
                'is_active' => true,
            ]);
            app(RoleService::class)->provisionDefaultRoles($foreignTenant->id);

            $foreignRole = Role::withoutGlobalScopes()
                ->where('tenant_id', $foreignTenant->id)
                ->where('name', 'Supervisor')
                ->firstOrFail();

            $foreignSupervisor = User::withoutGlobalScopes()->create([
                'tenant_id' => $foreignTenant->id,
                'name' => 'Foreign Supervisor',
                'email' => 'sup.foreign.'.uniqid().'@corp.test',
                'email_verified_at' => now(),
                'password' => bcrypt('password'),
                'role_id' => $foreignRole->id,
                'store_id' => $foreignStore->id,
                'is_active' => true,
            ]);
            DB::table('user_stores')->insert([
                'tenant_id' => $foreignTenant->id,
                'user_id' => $foreignSupervisor->id,
                'store_id' => $foreignStore->id,
            ]);
        });

        app(TenantContext::class)->setTenantId($foreignTenant->id);
        app(TenantContext::class)->setStoreId($foreignStore->id);

        Livewire::actingAs($foreignSupervisor)
            ->test(UnifiedApprovalInbox::class)
            ->call('approve', $operationalRequestA->id)
            ->assertSee("Permintaan persetujuan #{$operationalRequestA->id} tidak ditemukan");

        // Restore Tenant A context
        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($storeA->id);

        // 4. Supervisor A legitimately approves Store A request in their assigned branch
        Livewire::actingAs($supervisorA)
            ->test(UnifiedApprovalInbox::class)
            ->call('approve', $operationalRequestA->id)
            ->assertHasNoErrors()
            ->assertSee("Permintaan persetujuan #{$operationalRequestA->id} berhasil disetujui.");

        $operationalRequestA->refresh();
        $this->assertSame(ApprovalStatusEnum::Approved, $operationalRequestA->status);
        $this->assertSame($supervisorA->id, $operationalRequestA->approved_by_user_id);
        $this->assertNotNull($operationalRequestA->decided_at);

        // 4. Verify append-only activity_logs audit entry created
        $auditLog = ActivityLog::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'workflow.approval.approved')
            ->where('user_id', $supervisorA->id)
            ->first();

        $this->assertNotNull($auditLog, 'Audit log for workflow approval must be recorded.');
        $this->assertStringContainsString((string) $operationalRequestA->id, (string) $auditLog->description);
    }

    // =========================================================================
    // UAT-06: Self-Serve Midtrans Webhook Upgrade & UU PDP Tenant Lifecycle Journey
    // =========================================================================

    public function test_uat_06_self_serve_midtrans_webhook_upgrade_and_uu_pdp_tenant_lifecycle_journey(): void
    {
        $fixture = $this->createProvisionedTenant('SaaS Midtrans & Lifecycle Corp');
        $tenant = $fixture['tenant'];
        $store = $fixture['store'];
        $owner = $fixture['owner'];

        app(TenantContext::class)->setTenantId($tenant->id);
        app(TenantContext::class)->setStoreId($store->id);

        Artisan::call('tenant:provision-system-users', ['tenant' => $tenant->id]);

        $starterPlan = Plan::firstOrCreate(
            ['code' => 'starter-plan'],
            ['name' => 'Starter Plan', 'is_active' => true, 'price_monthly' => 299000]
        );

        $enterprisePlan = Plan::firstOrCreate(
            ['code' => 'enterprise-plan'],
            ['name' => 'Enterprise Plan', 'is_active' => true, 'price_monthly' => 1499000]
        );

        TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $starterPlan->id,
            'status' => TenantSubscription::STATUS_ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'renews_at' => now()->addMonth(),
        ]);

        // Part A: Midtrans Webhook Upgrade
        $orchestrator = app(SubscriptionBillingOrchestrator::class);
        $this->actingAs($owner);
        $upgrade = $orchestrator->initiateUpgrade($tenant->id, $enterprisePlan->id, 'monthly');
        $orderId = $upgrade['order_id'];
        $grossAmount = '1499000.00';
        $signature = hash('sha512', $orderId.'200'.$grossAmount.self::MIDTRANS_SERVER_KEY);

        $webhookPayload = [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-uat-tx-'.Str::random(10),
            'payment_type' => 'bank_transfer',
        ];

        // 1st delivery of settlement webhook: atomically upgrades plan
        $whRes1 = $this->postJson('/midtrans/notification', $webhookPayload);
        $whRes1->assertStatus(200)
            ->assertJson([
                'message' => 'ok',
                'subscription_settled' => true,
            ]);

        $latestSub = TenantSubscription::where('tenant_id', $tenant->id)->latest('id')->first();
        $this->assertNotNull($latestSub);
        $this->assertSame($enterprisePlan->id, $latestSub->plan_id);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $latestSub->status);
        $this->assertSame($enterprisePlan->code, $tenant->fresh()->subscription_plan);

        // 2nd delivery (idempotent replay): must succeed without duplicating subscription records
        $subsCountBefore = TenantSubscription::where('tenant_id', $tenant->id)->count();
        $whRes2 = $this->postJson('/midtrans/notification', $webhookPayload);
        $whRes2->assertStatus(200);
        $this->assertSame($subsCountBefore, TenantSubscription::where('tenant_id', $tenant->id)->count());

        // Part B: Tenant Archival Read-Only Guard
        $tenant->update([
            'status' => Tenant::STATUS_ARCHIVED,
            'access_status' => Tenant::ACCESS_ARCHIVED,
        ]);

        // GET request to /dashboard is allowed for archived tenant
        $this->actingAs($owner)->get('/dashboard')->assertSuccessful();

        // POST mutation is strictly blocked with 403 TENANT_ARCHIVED
        $mutateRes = $this->actingAs($owner)->postJson('/stores', [
            'name' => 'Illegal Outlet Creation in Archived Tenant',
        ]);
        $mutateRes->assertStatus(403);
        $mutateRes->assertJsonPath('error', 'TENANT_ARCHIVED');

        // DELETE mutation is also strictly blocked with 403 TENANT_ARCHIVED
        $deleteRes = $this->actingAs($owner)->deleteJson('/stores/'.$store->id);
        $deleteRes->assertStatus(403);
        $deleteRes->assertJsonPath('error', 'TENANT_ARCHIVED');

        // Part C: UU PDP PII Purge & 10-Year Fiscal Record Retention
        $customer = DB::table('customers')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Hendro Wijaya',
            'customer_type' => 'INDIVIDUAL',
            'phone' => '081233445566',
            'email' => 'hendro.wijaya@corporate.test',
            'tax_id' => '02.345.678.9-888.000',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Historical fiscal order that MUST be preserved for 10-year retention
        $fiscalOrderId = DB::table('orders')->insertGetId([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'order_code' => 'FISCAL-ORD-'.uniqid(),
            'idempotency_key' => 'FISCAL-IDEMP-'.uniqid(),
            'order_date' => now()->subMonths(6),
            'user_id' => $owner->id,
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 500000.00,
            'subtotal_amount' => 500000.00,
            'created_at' => now()->subMonths(6),
            'updated_at' => now()->subMonths(6),
        ]);

        // Historical fiscal journal entry that MUST be preserved for 10-year retention
        $fiscalPeriod = AccountingPeriod::create([
            'tenant_id' => $tenant->id,
            'period_code' => now()->subMonths(6)->format('Y-m'),
            'start_date' => now()->subMonths(6)->startOfMonth()->toDateString(),
            'end_date' => now()->subMonths(6)->endOfMonth()->toDateString(),
            'fiscal_year' => (int) now()->subMonths(6)->format('Y'),
            'period_number' => (int) now()->subMonths(6)->format('n'),
            'status' => AccountingPeriodStatusEnum::OPEN->value,
            'is_adjustment_period' => false,
        ]);

        $fiscalJournalId = DB::table('journal_entries')->insertGetId([
            'tenant_id' => $tenant->id,
            'company_id' => $fixture['company']->id,
            'store_id' => $store->id,
            'accounting_period_id' => $fiscalPeriod->id,
            'journal_number' => 'JE-FISCAL-'.uniqid(),
            'idempotency_key' => 'FISCAL-JE-'.uniqid(),
            'posting_date' => now()->subMonths(6)->toDateString(),
            'journal_type' => 'GENERAL',
            'status' => JournalStatusEnum::POSTED->value,
            'source_module' => 'finance',
            'source_type' => 'manual',
            'currency_code' => 'IDR',
            'total_debit' => 500000.00,
            'total_credit' => 500000.00,
            'created_at' => now()->subMonths(6),
            'updated_at' => now()->subMonths(6),
        ]);

        // Execute purge command via Artisan
        $exitCode = Artisan::call('tenant:purge-archived', [
            'tenant_id' => $tenant->id,
            '--confirm' => true,
        ]);
        $this->assertSame(0, $exitCode);

        $purgedTenant = Tenant::findOrFail($tenant->id);
        $this->assertSame(Tenant::STATUS_PURGED, $purgedTenant->status);
        $this->assertSame(Tenant::ACCESS_PURGED, $purgedTenant->access_status);

        // Verify Owner User PII anonymization
        $purgedUser = DB::table('users')->where('id', $owner->id)->first();
        $this->assertNotNull($purgedUser);
        $this->assertStringStartsWith('Anonymized User', (string) $purgedUser->name);
        $this->assertStringContainsString('@anonymized.internal', (string) $purgedUser->email);
        $this->assertNull($purgedUser->phone_number);
        $this->assertSame('*', $purgedUser->password);
        $this->assertEquals(0, $purgedUser->is_active);

        // Verify Customer PII anonymization
        $purgedCustomer = DB::table('customers')->where('id', $customer)->first();
        $this->assertNotNull($purgedCustomer);
        $this->assertStringStartsWith('Anonymized Customer', (string) $purgedCustomer->name);
        $this->assertNull($purgedCustomer->phone);
        $this->assertNull($purgedCustomer->tax_id);
        $this->assertStringContainsString('@anonymized.internal', (string) $purgedCustomer->email);

        // Verify 10-Year Fiscal Order and Journal Entry are PRESERVED intact
        $fiscalOrder = DB::table('orders')->where('id', $fiscalOrderId)->first();
        $this->assertNotNull($fiscalOrder, 'Historical orders must be preserved for 10-year fiscal compliance.');
        $this->assertEquals('500000.00', $fiscalOrder->order_amount);

        $fiscalJournal = DB::table('journal_entries')->where('id', $fiscalJournalId)->first();
        $this->assertNotNull($fiscalJournal, 'Historical journal entries must be preserved for 10-year fiscal compliance.');
        $this->assertEquals('500000.00', $fiscalJournal->total_debit);
        $this->assertEquals('500000.00', $fiscalJournal->total_credit);
    }
}
