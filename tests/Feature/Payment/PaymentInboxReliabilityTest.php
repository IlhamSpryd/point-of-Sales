<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\OrderFulfillmentStatus;
use App\Enums\OrderLifecycleStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentInbox;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use App\Services\Payment\PaymentInboxDispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PaymentInboxReliabilityTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;
    private Store $store;
    private PaymentInboxDispatcher $inboxDispatcher;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Apeiron Payment Tenant',
            'slug' => 'apeiron-pay-'.uniqid(),
            'status' => 'active',
            'country_code' => 'ID',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Flagship Store',
            'is_active' => true,
        ]);

        $this->inboxDispatcher = app(PaymentInboxDispatcher::class);
    }

    /**
     * Test Matrix #5: Midtrans Duplicate Callback Deduplication
     * Webhook settlement already processed; duplicate delivery arrives.
     * System acknowledges with 200 OK without re-executing stock or journal mutations.
     */
    public function test_midtrans_duplicate_webhook_is_handled_idempotently_via_payment_inbox(): void
    {
        $idempotencyKey = 'midtrans_trans_'.uniqid().'_settlement';
        $payload = [
            'transaction_id' => 'trans-'.uniqid(),
            'order_id' => 'ORD-TEST-001',
            'gross_amount' => '150000.00',
            'transaction_status' => 'settlement',
        ];

        $executionCounter = 0;
        $processor = function () use (&$executionCounter) {
            $executionCounter++;
        };

        // 1. First delivery
        $result1 = $this->inboxDispatcher->dispatch(
            tenantId: $this->tenant->id,
            provider: 'midtrans',
            idempotencyKey: $idempotencyKey,
            eventType: 'settlement',
            payload: $payload,
            processor: $processor
        );

        $this->assertSame('success', $result1['status']);
        $this->assertFalse($result1['duplicate']);
        $this->assertSame(1, $executionCounter);

        $inboxRecord = PaymentInbox::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        $this->assertNotNull($inboxRecord);
        $this->assertSame('processed', $inboxRecord->status);
        $this->assertNotNull($inboxRecord->processed_at);

        // 2. Second duplicate delivery
        $result2 = $this->inboxDispatcher->dispatch(
            tenantId: $this->tenant->id,
            provider: 'midtrans',
            idempotencyKey: $idempotencyKey,
            eventType: 'settlement',
            payload: $payload,
            processor: $processor
        );

        $this->assertSame('already_processed', $result2['status']);
        $this->assertTrue($result2['duplicate']);
        // Processor MUST NOT be executed again!
        $this->assertSame(1, $executionCounter);
    }

    /**
     * Test Bounded State Machines Transitions:
     * OrderLifecycle, PaymentState, and FulfillmentState update accurately together.
     */
    public function test_bounded_state_machines_update_synchronously_with_order(): void
    {
        $user = \App\Models\User::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier State Machine',
            'email' => 'csm.'.uniqid().'@apeiron.test',
            'password' => bcrypt('secret123'),
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $user->id,
            'order_code' => 'ORD-SM-'.uniqid(),
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 50000,
            'order_amount' => 50000,
            'order_status' => OrderStatus::Pending,
            'order_lifecycle_status' => OrderLifecycleStatus::Submitted,
            'payment_status' => OrderPaymentStatus::AwaitingPayment,
            'fulfillment_status' => OrderFulfillmentStatus::Unfulfilled,
        ]);

        $this->assertSame(OrderLifecycleStatus::Submitted, $order->order_lifecycle_status);
        $this->assertSame(OrderPaymentStatus::AwaitingPayment, $order->payment_status);
        $this->assertSame(OrderFulfillmentStatus::Unfulfilled, $order->fulfillment_status);

        // Transition on payment settlement
        $order->transitionPayment(OrderPaymentStatus::Paid);
        $order->transitionOrderLifecycle(OrderLifecycleStatus::Confirmed);
        $order->transitionFulfillment(OrderFulfillmentStatus::InPreparation);

        $order->refresh();
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderLifecycleStatus::Confirmed, $order->order_lifecycle_status);
        $this->assertSame(OrderFulfillmentStatus::InPreparation, $order->fulfillment_status);
    }

    /**
     * Failure handling: PaymentInbox records failed status when processor throws exception
     */
    public function test_payment_inbox_captures_failure_state_on_unhandled_exception(): void
    {
        $idempotencyKey = 'midtrans_trans_fail_'.uniqid();
        $payload = ['order_id' => 'ORD-FAIL'];

        $failingProcessor = function () {
            throw new \RuntimeException('Database constraint or external error occurred during settlement.');
        };

        $threw = false;
        try {
            $this->inboxDispatcher->dispatch(
                tenantId: $this->tenant->id,
                provider: 'midtrans',
                idempotencyKey: $idempotencyKey,
                eventType: 'settlement',
                payload: $payload,
                processor: $failingProcessor
            );
        } catch (\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw);

        $inboxRecord = PaymentInbox::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        $this->assertNotNull($inboxRecord);
        $this->assertSame('failed', $inboxRecord->status);
        $this->assertStringContainsString('Database constraint or external error', (string) $inboxRecord->error_message);
    }

    /**
     * Recovery: A failed webhook can be retried and successfully processed.
     */
    public function test_payment_inbox_allows_retry_after_transient_failure(): void
    {
        $idempotencyKey = 'midtrans_trans_retry_'.uniqid();
        $payload = ['order_id' => 'ORD-RETRY-01'];

        // 1. First attempt fails
        $shouldFail = true;
        $processor = function () use (&$shouldFail) {
            if ($shouldFail) {
                throw new \RuntimeException('Transient connection glitch');
            }
        };

        try {
            $this->inboxDispatcher->dispatch(
                tenantId: $this->tenant->id,
                provider: 'midtrans',
                idempotencyKey: $idempotencyKey,
                eventType: 'settlement',
                payload: $payload,
                processor: $processor
            );
        } catch (\RuntimeException $e) {
            // expected
        }

        $inboxRecord = PaymentInbox::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        $this->assertSame('failed', $inboxRecord->status);

        // 2. Second attempt (retry) succeeds
        $shouldFail = false;
        $retryResult = $this->inboxDispatcher->dispatch(
            tenantId: $this->tenant->id,
            provider: 'midtrans',
            idempotencyKey: $idempotencyKey,
            eventType: 'settlement',
            payload: $payload,
            processor: $processor
        );

        $this->assertSame('success', $retryResult['status']);
        $this->assertFalse($retryResult['duplicate']);

        $inboxRecord->refresh();
        $this->assertSame('processed', $inboxRecord->status);
        $this->assertNull($inboxRecord->error_message);
        $this->assertNotNull($inboxRecord->processed_at);
    }
}
