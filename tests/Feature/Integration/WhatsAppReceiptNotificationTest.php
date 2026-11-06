<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Jobs\SendWhatsAppReceiptJob;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Integration\Models\WhatsAppMessageOutbox;
use App\Modules\Integration\Services\WhatsAppNotificationService;
use App\Modules\Sales\Models\Order;
use App\Services\Context\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppReceiptNotificationTest extends TestCase
{
    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected Store $storeA;
    protected User $userA;
    protected Customer $customerA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->tenantA = Tenant::create([
                'name' => 'WA Tenant A',
                'slug' => 'wa-tenant-a-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->tenantB = Tenant::create([
                'name' => 'WA Tenant B',
                'slug' => 'wa-tenant-b-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->storeA = Store::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Store A',
                'code' => 'WA-A-'.uniqid(),
                'status' => 'active',
            ]);

            $this->userA = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'User A',
                'email' => 'wa.user.'.uniqid().'@test.com',
                'password' => bcrypt('password'),
                'role' => 'CASHIER',
                'is_active' => true,
            ]);

            $this->customerA = Customer::withoutTenantScope()->create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Budi Santoso',
                'phone' => '081234567890',
                'customer_type' => 'INDIVIDUAL',
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);
    }

    /**
     * Test 1: Normalisasi nomor handphone Indonesia ke standar internasional E.164 (+62).
     */
    public function test_phone_number_normalization_e164(): void
    {
        /** @var WhatsAppNotificationService $service */
        $service = app(WhatsAppNotificationService::class);

        $this->assertEquals('+6281234567890', $service->normalizePhoneNumber('081234567890'));
        $this->assertEquals('+6281234567890', $service->normalizePhoneNumber('6281234567890'));
        $this->assertEquals('+6281234567890', $service->normalizePhoneNumber('+6281234567890'));
        $this->assertEquals('+6281234567890', $service->normalizePhoneNumber('0812-3456-7890'));
        $this->assertEquals('+6281234567890', $service->normalizePhoneNumber('81234567890'));
    }

    /**
     * Test 2: queueReceipt membuat baris outbox dengan status pending dan men-dispatch job asinkron.
     */
    public function test_queue_receipt_creates_outbox_record_with_normalized_phone_and_dispatches_job(): void
    {
        Queue::fake();

        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userA->id,
            'customer_id' => $this->customerA->id,
            'order_code' => 'ORD-WA-'.Str::random(6),
            'idempotency_key' => Str::uuid()->toString(),
            'order_date' => now()->toDateString(),
            'order_type' => 'DINE_IN',
            'order_status' => 'paid',
            'subtotal_amount' => 50000,
            'tax_amount' => 5500,
            'order_amount' => 55500,
            'order_change' => 0,
        ]);

        /** @var WhatsAppNotificationService $service */
        $service = app(WhatsAppNotificationService::class);
        $outbox = $service->queueReceipt($order, '081234567890');

        $this->assertNotNull($outbox);
        $this->assertEquals($this->tenantA->id, $outbox->tenant_id);
        $this->assertEquals('+6281234567890', $outbox->recipient_phone);
        $this->assertEquals(WhatsAppMessageOutbox::STATUS_PENDING, $outbox->status);
        $this->assertEquals('RECEIPT', $outbox->message_type);
        $this->assertEquals('55500', $outbox->payload['total_amount']);

        Queue::assertPushed(SendWhatsAppReceiptJob::class, function (SendWhatsAppReceiptJob $job) use ($outbox) {
            return $job->outboxId === $outbox->id;
        });
    }

    /**
     * Test 3: SendWhatsAppReceiptJob memproses pesan via gateway provider resmi secara sukses.
     */
    public function test_send_whatsapp_receipt_job_processes_item_via_gateway_successfully(): void
    {
        Http::fake([
            '*/whatsapp/send_template' => Http::response([
                'status' => 'success',
                'data' => [
                    'message_id' => 'qiscus_msg_test_12345',
                ],
            ], 200),
        ]);

        $outbox = WhatsAppMessageOutbox::create([
            'tenant_id' => $this->tenantA->id,
            'recipient_phone' => '+6281234567890',
            'message_type' => 'RECEIPT',
            'template_id' => 'pos_digital_receipt_v1',
            'payload' => [
                'order_code' => 'ORD-WA-001',
                'customer_name' => 'Budi Santoso',
                'store_name' => 'Store A',
                'total_amount' => '55500.00',
                'receipt_url' => 'https://pos.apeiron.test/r/ORD-WA-001',
            ],
            'status' => WhatsAppMessageOutbox::STATUS_PENDING,
            'attempts' => 0,
        ]);

        $job = new SendWhatsAppReceiptJob($outbox->id);
        $job->handle(app(WhatsAppNotificationService::class));

        $outbox->refresh();
        $this->assertEquals(WhatsAppMessageOutbox::STATUS_SENT, $outbox->status);
        $this->assertNotNull($outbox->sent_at);
        $this->assertEquals('qiscus_msg_test_12345', $outbox->provider_message_id);
        $this->assertNull($outbox->last_error);
    }

    /**
     * Test 4: Kegagalan gateway eksternal terisolasi, mencatat error dan menaikkan attempts tanpa mengorbankan transaksi bisnis.
     */
    public function test_bsp_failure_is_isolated_and_updates_outbox_attempts_without_throwing(): void
    {
        Http::fake([
            '*/whatsapp/send_template' => Http::response([
                'error' => 'Internal Provider Server Error',
            ], 500),
        ]);

        $outbox = WhatsAppMessageOutbox::create([
            'tenant_id' => $this->tenantA->id,
            'recipient_phone' => '+6281234567890',
            'message_type' => 'RECEIPT',
            'template_id' => 'pos_digital_receipt_v1',
            'payload' => [
                'order_code' => 'ORD-WA-FAIL',
                'total_amount' => '50000.00',
            ],
            'status' => WhatsAppMessageOutbox::STATUS_PENDING,
            'attempts' => 0,
        ]);

        /** @var WhatsAppNotificationService $service */
        $service = app(WhatsAppNotificationService::class);
        $result = $service->processOutboxItem($outbox->id);

        $this->assertFalse($result);

        $outbox->refresh();
        $this->assertEquals(1, $outbox->attempts);
        $this->assertEquals(WhatsAppMessageOutbox::STATUS_PENDING, $outbox->status);
        $this->assertNotNull($outbox->last_error);
        $this->assertStringContainsString('500', $outbox->last_error);
    }

    /**
     * Test 5: Multi-tenancy isolation fail-closed pada outbox WhatsApp.
     */
    public function test_multi_tenant_isolation_on_whatsapp_outbox(): void
    {
        $context = app(TenantContext::class);

        // Buat record di Tenant A
        $context->setTenantId($this->tenantA->id);
        $outboxA = WhatsAppMessageOutbox::create([
            'tenant_id' => $this->tenantA->id,
            'recipient_phone' => '+6281234567890',
            'message_type' => 'RECEIPT',
            'payload' => ['order_code' => 'TENANT-A-ORD'],
            'status' => WhatsAppMessageOutbox::STATUS_PENDING,
        ]);

        // Buat record di Tenant B
        $context->setTenantId($this->tenantB->id);
        $outboxB = WhatsAppMessageOutbox::create([
            'tenant_id' => $this->tenantB->id,
            'recipient_phone' => '+6289999999999',
            'message_type' => 'RECEIPT',
            'payload' => ['order_code' => 'TENANT-B-ORD'],
            'status' => WhatsAppMessageOutbox::STATUS_PENDING,
        ]);

        // Query dari Tenant A: hanya melihat Tenant A
        $context->setTenantId($this->tenantA->id);
        $tenantAResults = WhatsAppMessageOutbox::pluck('id')->all();
        $this->assertContains($outboxA->id, $tenantAResults);
        $this->assertNotContains($outboxB->id, $tenantAResults);

        // Query dari Tenant B: hanya melihat Tenant B
        $context->setTenantId($this->tenantB->id);
        $tenantBResults = WhatsAppMessageOutbox::pluck('id')->all();
        $this->assertContains($outboxB->id, $tenantBResults);
        $this->assertNotContains($outboxA->id, $tenantBResults);
    }
}
