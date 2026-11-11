<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\Category;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\DTOs\TaxInvoiceDocumentDto;
use App\Modules\Finance\DTOs\TaxInvoiceItemDto;
use App\Modules\Finance\Services\CoreTaxDjpGateway;
use App\Modules\Finance\Services\CoreTaxJsonGenerator;
use App\Modules\Finance\Services\CoreTaxSchemaValidator;
use App\Modules\Finance\Services\CoreTaxXmlGenerator;
use App\Modules\Integration\Models\WebhookSubscription;
use App\Modules\Integration\Models\WhatsAppMessageOutbox;
use App\Modules\Integration\Services\OutboundWebhookDispatcherService;
use App\Modules\Integration\Services\WhatsAppNotificationService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessAutomationFase3Test extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenant;
    protected Company $company;
    protected Store $store;
    protected User $apiUser;
    protected Category $category;
    protected Product $product;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        $this->company = Company::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'company_code' => 'CMP-FASE3'],
            [
                'company_name' => 'PT Apeiron Otomasi Bisnis',
                'legal_name' => 'PT Apeiron Otomasi Bisnis Indonesia',
                'npwp' => '012345678901234',
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $roleManager = Role::firstOrCreate(
            ['name' => 'Manager'],
            ['description' => 'Store Manager', 'is_admin' => false]
        );

        $this->apiUser = User::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->apiUser->update([
            'role_id' => $roleManager->id,
            'store_id' => $this->store->id,
            'email_verified_at' => now(),
            'onboarding_completed' => true,
        ]);
        $this->apiUser->stores()->sync([
            $this->store->id => ['tenant_id' => $this->tenant->id],
        ]);

        $this->category = Category::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Automated Goods',
            'category_code' => 'CAT-AUTO-'.rand(100, 999),
            'is_active' => true,
        ]);

        $this->product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'API Integration Device',
            'product_code' => 'PRD-AUTO-'.rand(100, 999),
            'barcode' => '8991234567891',
            'base_price' => 250000.00,
            'cost_price' => 150000.00,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        DB::table('product_stock_balances')->updateOrInsert(
            [
                'tenant_id' => $this->tenant->id,
                'store_id' => $this->store->id,
                'product_id' => $this->product->id,
            ],
            [
                'quantity' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);
    }

    /**
     * Slice 1: Outbound Webhook Subscriptions & HMAC-SHA256 Signed Dispatch Engine.
     */
    public function test_outbound_webhook_subscription_and_signed_hmac_dispatch(): void
    {
        Http::fake([
            'https://webhook.site/receiver' => Http::response(['received' => true], 200),
        ]);

        $secretKey = 'whsec_test_secret_key_1234567890';
        $subscription = WebhookSubscription::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'name' => 'External n8n Flow Subscription',
            'target_url' => 'https://webhook.site/receiver',
            'secret_key' => $secretKey,
            'subscribed_events' => ['order.completed', 'inventory.depleted'],
            'is_active' => true,
        ]);

        $dispatcher = app(OutboundWebhookDispatcherService::class);
        $payload = [
            'order_code' => 'ORD-AUTO-001',
            'total_amount' => '250000.00',
            'customer_name' => 'Apeiron VIP Enterprise',
        ];

        $dispatchedResults = $dispatcher->dispatchToSubscribers(
            tenantId: $this->tenant->id,
            event: 'order.completed',
            data: $payload,
            storeId: $this->store->id
        );

        $this->assertCount(1, $dispatchedResults);
        $res = $dispatchedResults[0];
        $this->assertEquals($subscription->id, $res['subscription_id']);
        $this->assertTrue($res['success']);
        $this->assertEquals(200, $res['status']);

        // Verifikasi log tersimpan di database
        $this->assertDatabaseHas('webhook_delivery_logs', [
            'subscription_id' => $subscription->id,
            'event' => 'order.completed',
            'status' => 'delivered',
            'response_status' => 200,
        ]);

        // Verifikasi pengiriman HTTP dan header HMAC-SHA256
        Http::assertSent(function ($request) use ($secretKey, $dispatcher) {
            $hasTarget = $request->url() === 'https://webhook.site/receiver';
            $signature = $request->header('X-Apeiron-Signature')[0] ?? '';
            $timestamp = (int) ($request->header('X-Apeiron-Timestamp')[0] ?? 0);
            $event = $request->header('X-Apeiron-Event')[0] ?? '';

            $isSignatureValid = $dispatcher->verifySignature((string) $request->body(), $signature, $timestamp, $secretKey);

            return $hasTarget && $isSignatureValid && $event === 'order.completed';
        });

        // Verifikasi event yang tidak dilanggan TIDAK terkirim
        $ignoredResults = $dispatcher->dispatchToSubscribers(
            tenantId: $this->tenant->id,
            event: 'supplier.created',
            data: ['supplier' => 'PT Mitra Sejati'],
            storeId: $this->store->id
        );
        $this->assertCount(0, $ignoredResults);
    }

    /**
     * Slice 2: Public Scoped Sanctum REST API Endpoints (/v1/orders, /v1/products, /v1/inventory/balances).
     */
    public function test_scoped_sanctum_api_orders_products_and_inventory_balances(): void
    {
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->apiUser->id,
            'order_code' => 'ORD-REST-001',
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'order_type' => 'retail',
            'order_status' => 'paid',
            'subtotal_amount' => 250000.00,
            'total_amount' => 250000.00,
            'cash_received' => 250000.00,
        ]);

        Sanctum::actingAs($this->apiUser, ['orders:read', 'products:read', 'inventory:read']);

        // 1. GET /api/v1/orders
        $ordersResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/api/v1/orders');

        $ordersResponse->assertStatus(200);
        $ordersResponse->assertJsonStructure([
            'status',
            'data',
            'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $this->assertEquals('OK', $ordersResponse->json('status'));
        $this->assertNotEmpty($ordersResponse->json('data'));

        // 2. GET /api/v1/orders/{id}
        $singleOrderResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/api/v1/orders/{$order->id}");

        $singleOrderResponse->assertStatus(200);
        $singleOrderResponse->assertJson([
            'status' => 'success',
            'data' => [
                'id' => $order->id,
                'order_code' => 'ORD-REST-001',
            ],
        ]);

        // 3. GET /api/v1/products
        $productsResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/api/v1/products?search=API Integration');

        $productsResponse->assertStatus(200);
        $productsResponse->assertJsonStructure([
            'status',
            'data',
            'pagination',
        ]);
        $this->assertNotEmpty($productsResponse->json('data'));

        // 4. GET /api/v1/inventory/balances
        $inventoryResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson("/api/v1/inventory/balances?product_id={$this->product->id}");

        $inventoryResponse->assertStatus(200);
        $inventoryResponse->assertJsonStructure([
            'status',
            'data',
            'pagination',
        ]);
        $balances = $inventoryResponse->json('data');
        $this->assertCount(1, $balances);
        $this->assertEquals(100, (int) $balances[0]['stock_balance']);
    }

    /**
     * Slice 2 & Security: Token Scope Enforcement & Fail-Closed Multi-Tenancy Protection.
     */
    public function test_scoped_sanctum_api_blocks_missing_scopes_and_cross_tenant_token(): void
    {
        // 1. Missing required scope ('inventory:read' instead of 'orders:read')
        Sanctum::actingAs($this->apiUser, ['inventory:read']);

        $responseWrongScope = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/api/v1/orders');

        $responseWrongScope->assertStatus(403);
        $this->assertStringContainsString('Forbidden: Token lacks required scope [orders:read]', (string) $responseWrongScope->json('message'));

        // 2. Cross-Tenant Token Tampering Attempt (Header X-Tenant-ID mismatch)
        Sanctum::actingAs($this->apiUser, ['orders:read']);

        $responseCrossTenant = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => '999999',
        ])->getJson('/api/v1/orders');

        $responseCrossTenant->assertStatus(403);
        $this->assertEquals('Cross-tenant token usage detected.', $responseCrossTenant->json('message'));
    }

    /**
     * Slice 2: Webhook Subscriptions Management REST API (CRUD).
     */
    public function test_webhook_subscriptions_management_rest_api(): void
    {
        Sanctum::actingAs($this->apiUser, ['webhooks:manage']);

        // 1. POST /api/v1/webhooks/subscriptions (Create)
        $createResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson('/api/v1/webhooks/subscriptions', [
            'name' => 'CRM Real-Time Pipeline',
            'target_url' => 'https://crm.external-enterprise.com/webhooks',
            'subscribed_events' => ['order.completed', 'customer.created'],
            'secret_key' => 'super_secret_webhook_key_123',
            'store_id' => $this->store->id,
        ]);

        $createResponse->assertStatus(201);
        $createdId = (int) $createResponse->json('data.id');
        $this->assertGreaterThan(0, $createdId);

        // 2. GET /api/v1/webhooks/subscriptions (Index)
        $indexResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/api/v1/webhooks/subscriptions');

        $indexResponse->assertStatus(200);
        $this->assertCount(1, $indexResponse->json('data'));

        // 3. DELETE /api/v1/webhooks/subscriptions/{id} (Destroy)
        $deleteResponse = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->deleteJson("/api/v1/webhooks/subscriptions/{$createdId}");

        $deleteResponse->assertStatus(200);
        $this->assertDatabaseMissing('webhook_subscriptions', [
            'id' => $createdId,
        ]);
    }

    /**
     * Slice 3: Offline POS Mode Synchronization with Cash-Only Restriction (DEC-006) and Idempotency.
     */
    public function test_offline_pos_sync_batch_with_cash_and_idempotency(): void
    {
        $shift = Shift::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->apiUser->id,
            'active_cashier_id' => $this->apiUser->id,
            'status' => 'open',
            'opened_at' => now(),
            'starting_cash' => 500000,
        ]);

        $clientUuid = (string) Str::uuid();

        $offlineBatchPayload = [
            'device_id' => null,
            'store_id' => $this->store->id,
            'orders' => [
                [
                    'offline_client_uuid' => $clientUuid,
                    'order_type' => 'takeaway',
                    'payment_method' => 'cash',
                    'cash_received' => 250000,
                    'shift_id' => $shift->id,
                    'items' => [
                        [
                            'product_id' => $this->product->id,
                            'quantity' => 1,
                            'notes' => 'Offline synced sale',
                            'options' => [],
                        ],
                    ],
                ],
            ],
        ];

        // Jalankan sinkronisasi pertama (Harus berhasil disinkronkan)
        $firstSyncResponse = $this->actingAs($this->apiUser)
            ->withSession(['active_store_id' => $this->store->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $offlineBatchPayload);

        $firstSyncResponse->assertStatus(200);
        $firstSyncResponse->assertJson([
            'status' => 'success',
            'data' => [
                'synced_count' => 1,
                'failed_count' => 0,
            ],
        ]);
        $this->assertEquals('synced', $firstSyncResponse->json('data.results.0.status'));

        // Jalankan sinkronisasi kedua dengan UUID yang sama (Harus idempoten: already_synced)
        $duplicateSyncResponse = $this->actingAs($this->apiUser)
            ->withSession(['active_store_id' => $this->store->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $offlineBatchPayload);

        $duplicateSyncResponse->assertStatus(200);
        $this->assertEquals(1, $duplicateSyncResponse->json('data.synced_count'));
        $this->assertEquals('already_synced', $duplicateSyncResponse->json('data.results.0.status'));

        // Jalankan transaksi offline non-tunai (DEC-006: harus ditolak)
        $invalidPaymentPayload = [
            'store_id' => $this->store->id,
            'orders' => [
                [
                    'offline_client_uuid' => (string) Str::uuid(),
                    'order_type' => 'takeaway',
                    'payment_method' => 'qris',
                    'items' => [
                        [
                            'product_id' => $this->product->id,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $rejectedResponse = $this->actingAs($this->apiUser)
            ->withSession(['active_store_id' => $this->store->id])
            ->postJson(route('api.v1.pos.sync-offline-orders'), $invalidPaymentPayload);

        $rejectedResponse->assertStatus(200);
        $this->assertEquals('partial', $rejectedResponse->json('status'));
        $this->assertEquals(1, $rejectedResponse->json('data.failed_count'));
        $this->assertStringContainsString('CASH only', (string) $rejectedResponse->json('data.results.0.error'));
    }

    /**
     * Slice 4: WhatsApp Business API & Digital Receipt Dispatcher (TR-006 / DEC-007).
     */
    public function test_whatsapp_digital_receipt_outbox_queueing_on_checkout(): void
    {
        Queue::fake();

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->apiUser->id,
            'order_code' => 'ORD-WA-AUTO-'.rand(100, 999),
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => 'paid',
            'subtotal_amount' => 125000.00,
            'total_amount' => 125000.00,
        ]);

        /** @var WhatsAppNotificationService $waService */
        $waService = app(WhatsAppNotificationService::class);

        // Verifikasi normalisasi nomor telepon Indonesia (E.164)
        $normalizedPhone = $waService->normalizePhoneNumber('0812-9876-5432');
        $this->assertEquals('+6281298765432', $normalizedPhone);

        // Antrekan pengiriman struk digital
        $outbox = $waService->queueReceipt(
            order: $order,
            recipientPhone: '0812-9876-5432',
            receiptUrl: 'https://pos.apeiron.id/receipt/ORD-WA-001'
        );

        $this->assertInstanceOf(WhatsAppMessageOutbox::class, $outbox);
        $this->assertEquals(WhatsAppMessageOutbox::STATUS_PENDING, $outbox->status);
        $this->assertEquals('+6281298765432', $outbox->recipient_phone);
        $this->assertEquals($this->tenant->id, $outbox->tenant_id);
        $this->assertEquals($order->id, $outbox->payload['order_id']);

        $this->assertDatabaseHas('whatsapp_message_outbox', [
            'id' => $outbox->id,
            'status' => 'pending',
            'recipient_phone' => '+6281298765432',
        ]);
    }

    /**
     * Slice 5: CoreTax DJP Tax Integration Layer (NPWP/NIK Validation & Schema Serialization).
     */
    public function test_coretax_djp_invoice_xml_json_payload_and_nsfp(): void
    {
        $validator = new CoreTaxSchemaValidator();
        $xmlGen = new CoreTaxXmlGenerator($validator);
        $jsonGen = new CoreTaxJsonGenerator($validator);
        $gateway = new CoreTaxDjpGateway($validator, $xmlGen, $jsonGen);

        // 1. Validasi Format NPWP (15 digit) dan NIK/CoreTax (16 digit)
        $this->assertTrue($gateway->validateTaxId('012345678901234'));
        $this->assertTrue($gateway->validateTaxId('0123456789012345'));
        $this->assertFalse($gateway->validateTaxId('12345')); // Terlalu pendek
        $this->assertFalse($gateway->validateTaxId('0123456789012345678')); // Terlalu panjang

        $this->assertEquals('01.234.567.8-901.234', $gateway->formatTaxId('012345678901234'));

        // 2. Pembuatan Dokumen Faktur Pajak Elektronik CoreTax DJP
        $itemDto = new TaxInvoiceItemDto(
            code: 'PRD-AUTO-01',
            name: 'Cloud POS Subscription Tablet',
            unitPrice: '200000.00',
            quantity: '1.0000',
            totalPrice: '200000.00',
            discountAmount: '0.00',
            dpp: '200000.00',
            ppn: '22000.00'
        );

        $docDto = new TaxInvoiceDocumentDto(
            tenantId: $this->tenant->id,
            invoiceId: 101,
            invoiceNumber: 'INV-COR-2027-001',
            taxInvoiceSerialNumber: '010.000-27.00000001',
            invoiceDate: '15/04/2027',
            taxPeriodMonth: 4,
            taxPeriodYear: 2027,
            transactionType: '01',
            isReplacement: false,
            counterpartyName: 'PT Klien Utama Korporat',
            counterpartyTaxId: '012345678901000',
            counterpartyAddress: 'Jl. Jendral Sudirman Kav. 52, Jakarta Selatan',
            dppAmount: '200000.00',
            ppnAmount: '22000.00',
            ppnbmAmount: '0.00',
            reference: 'INV-COR-2027-001',
            items: [$itemDto]
        );

        // 3. Serialisasi ke Dokumen XML CoreTax DJP
        $xml = $gateway->generateXml($docDto);
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('010.000-27.00000001', $xml);
        $this->assertStringContainsString('PT Klien Utama Korporat', $xml);

        // 4. Serialisasi ke Dokumen REST API JSON CoreTax DJP v1
        $jsonPayload = $gateway->generateJson($docDto);
        $this->assertEquals('CoreTax_DJP_REST_v1', $jsonPayload['protocol']);
        $this->assertEquals('010.000-27.00000001', $jsonPayload['header']['tax_invoice_serial_number']);
        $jsonString = $jsonGen->generateJson($docDto);
        $this->assertStringContainsString('CoreTax_DJP_REST_v1', $jsonString);

        // 5. Validasi Payload Sinkronisasi
        $syncResult = $gateway->syncWithCoreTax($docDto);
        $this->assertEquals('prepared', $syncResult['status']);
        $this->assertEquals('validated', $syncResult['validation_status']);
        $this->assertEquals('CoreTax_DJP_REST_v1', $syncResult['protocol']);
        $this->assertTrue($syncResult['ready_for_pjap_dispatch']);
    }
}
