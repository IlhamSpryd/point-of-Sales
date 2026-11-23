<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Context\TenantContext;
use App\Livewire\Customers\LoyaltyAndValueInstrumentManager;
use App\Livewire\Kds\Board;
use App\Modules\Customers\Models\Customer;
use App\Modules\Integration\Models\WhatsAppMessageOutbox;
use App\Modules\Integration\Services\WhatsAppNotificationService;
use App\Modules\Sales\Models\PaymentInbox;
use App\Platform\Outbox\OutboxReaperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class Wave4BackendSecurityAuditTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        $this->setupTwoTenants();
    }

    /**
     * BE-SEC-01 [P0]: User Tenant A tidak dapat menerbitkan store credit untuk Customer Tenant B (404/IDOR closed).
     */
    public function test_user_cannot_issue_store_credit_to_customer_of_another_tenant(): void
    {
        $context = app(TenantContext::class);

        // Buat customer di Tenant B
        $customerB = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Customer Budi B',
            'phone' => '+6281299990001',
            'customer_type' => 'INDIVIDUAL',
            'is_active' => true,
        ]);

        // Login sebagai Owner Tenant A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->userAOwner);

        Livewire::test(LoyaltyAndValueInstrumentManager::class)
            ->set('store_credit_customer_id', $customerB->id)
            ->set('store_credit_amount', '50000')
            ->call('issueStoreCredit')
            ->assertStatus(404);
    }

    /**
     * BE-TEN-01 [P0]: TenantContext memory leak tidak terjadi pada queue worker setelah eksekusi WhatsApp outbox.
     */
    public function test_tenant_context_is_completely_cleared_after_whatsapp_job(): void
    {
        $tenantContext = app(TenantContext::class);
        $tenantContext->clear();
        $this->assertNull($tenantContext->getTenantId(), 'TenantContext harus berawal dari null pada proses worker');

        Http::fake([
            '*' => Http::response([
                'messages' => [['id' => 'wamid.HBgL'.uniqid()]],
            ], 200),
        ]);

        $outbox = WhatsAppMessageOutbox::withoutTenantScope()->create([
            'tenant_id' => $this->tenantA->id,
            'recipient_phone' => '+6281234567890',
            'message_type' => 'RECEIPT',
            'template_id' => 'pos_digital_receipt_v1',
            'payload' => [
                'order_code' => 'ORD-TEST-WA',
                'customer_name' => 'Ahmad',
                'total_amount' => '75000',
            ],
            'status' => WhatsAppMessageOutbox::STATUS_PENDING,
            'attempts' => 0,
        ]);

        $service = app(WhatsAppNotificationService::class);
        $result = $service->processOutboxItem($outbox->id);

        $this->assertTrue($result);
        $this->assertNull($tenantContext->getTenantId(), 'TenantContext memory leak terdeteksi setelah eksekusi WhatsApp job.');
    }

    /**
     * BE-CONC-01 [P1]: OutboxReaperService mereap payment_inbox yang stuck in-flight (> 5 menit) menjadi failed.
     */
    public function test_outbox_reaper_cleans_up_stuck_payment_inbox(): void
    {
        // 1. Inbox stuck > 5 menit
        $stuckInbox = PaymentInbox::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'provider' => 'midtrans',
            'idempotency_key' => 'idemp-stuck-'.uniqid(),
            'event_type' => 'settlement',
            'payload' => ['order_id' => '123'],
            'status' => 'processing',
        ]);
        PaymentInbox::withoutGlobalScopes()->where('id', $stuckInbox->id)->update([
            'updated_at' => now()->subMinutes(10),
        ]);

        // 2. Inbox aktif baru jalan 1 menit
        $freshInbox = PaymentInbox::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'provider' => 'midtrans',
            'idempotency_key' => 'idemp-fresh-'.uniqid(),
            'event_type' => 'settlement',
            'payload' => ['order_id' => '124'],
            'status' => 'processing',
        ]);
        PaymentInbox::withoutGlobalScopes()->where('id', $freshInbox->id)->update([
            'updated_at' => now()->subMinutes(1),
        ]);

        /** @var OutboxReaperService $reaper */
        $reaper = app(OutboxReaperService::class);
        $reapedCount = $reaper->reapStuckPaymentInboxes(5);

        $this->assertSame(1, $reapedCount);

        $this->assertSame('failed', $stuckInbox->fresh()->status);
        $this->assertStringContainsString('Processing timeout', (string) $stuckInbox->fresh()->error_message);
        $this->assertSame('processing', $freshInbox->fresh()->status);
    }

    /**
     * BE-SEC-02 [P1] & BE-WS-01 [P1]: KDS Board mengunci properti identitas dan otorisasi channel kds.tenant.{id}.
     */
    public function test_kds_board_locks_identity_properties_and_channel_authorization(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->userAOwner);

        // KDS Board locks $tenantId and $storeId
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Board::class)
            ->set('tenantId', $this->tenantB->id);
    }

    /**
     * BE-WS-01 [P1]: Channel kds.tenant.{tenantId} mengotorisasi user tenant sama dan menolak tenant lain.
     */
    public function test_kds_channel_authorization_isolates_tenants(): void
    {
        // User A Owner di Tenant A
        $this->actingAs($this->userAOwner);
        app(TenantContext::class)->setTenantId($this->tenantA->id);

        // Verify channel kds.tenant.{id}
        // Channel callback evaluation
        $channelCallback = Broadcast::getChannels()['kds.tenant.{tenantId}'] ?? null;
        $this->assertNotNull($channelCallback, 'Channel kds.tenant.{tenantId} belum terdaftar di routes/channels.php');

        $canAccessOwnTenant = $channelCallback($this->userAOwner, $this->tenantA->id);
        $this->assertTrue((bool) $canAccessOwnTenant, 'User berhak mengakses channel KDS tenant-nya sendiri');

        $canAccessOtherTenant = $channelCallback($this->userAOwner, $this->tenantB->id);
        $this->assertFalse((bool) $canAccessOtherTenant, 'User dilarang mengakses channel KDS tenant lain');
    }

    /**
     * BE-API-01 [P2]: API Inventory balances menolak akses store_id di luar cabang/tenant user (403).
     */
    public function test_inventory_api_rejects_unauthorized_store_access(): void
    {
        $this->actingAs($this->userAOwner);
        app(TenantContext::class)->setTenantId($this->tenantA->id);

        // User A meminta stok store milik Tenant B
        $response = $this->getJson("/api/v1/inventory/balances?store_id={$this->storeB->id}");
        $response->assertStatus(403);
        $response->assertJson(['status' => 'error']);

        // User A meminta stok store miliknya sendiri
        $responseOk = $this->getJson("/api/v1/inventory/balances?store_id={$this->storeA->id}");
        $responseOk->assertStatus(200);
        $responseOk->assertJson(['status' => 'success']);
    }

    /**
     * BE-CACHE-01 [P2]: WaiterCallController menggunakan namespace tenant pada cache key rate limiter.
     */
    public function test_waiter_call_rate_limiter_is_namespaced_by_tenant(): void
    {
        // Buat meja di Tenant A
        $tableIdA = DB::table('tables')->insertGetId([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'table_name' => 'Meja 01',
            'table_code' => 'T01',
            'secure_token' => (string) \Illuminate\Support\Str::uuid(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession([
            'current_table_id' => $tableIdA,
            'current_tenant_id' => $this->tenantA->id,
            'current_store_id' => $this->storeA->id,
            'current_table_name' => 'Meja 01',
        ]);

        Cache::flush();

        $response1 = $this->postJson('/waiter-call');
        $response1->assertStatus(200);

        // Pastikan cache key yang terbentuk memiliki format waiter_call_t{tenantId}_table_{tableId}
        $expectedKey = "waiter_call_t{$this->tenantA->id}_table_{$tableIdA}";
        $this->assertTrue(Cache::has($expectedKey), "Cache key {$expectedKey} harus terdaftar di Cache");
    }
}
