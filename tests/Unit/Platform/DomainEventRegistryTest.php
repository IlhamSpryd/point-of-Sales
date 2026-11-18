<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Modules\Finance\Domain\Events\JournalPostedV1;
use App\Modules\Purchasing\Domain\Events\GoodsReceivedV1;
use App\Modules\Sales\Domain\Events\OrderCompletedV1;
use App\Modules\Sales\Domain\Events\OrderVoidedV1;
use App\Modules\Subscription\Domain\Events\TenantProvisionedV1;
use App\Platform\Events\DomainEventInterface;
use App\Platform\Events\DomainEventRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DomainEventRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DomainEventRegistry::reset();
        DomainEventRegistry::boot();
    }

    public function test_default_registry_contains_five_canonical_events(): void
    {
        $all = DomainEventRegistry::all();

        $this->assertCount(5, $all);
        $this->assertTrue(DomainEventRegistry::has('sales.order.completed.v1'));
        $this->assertTrue(DomainEventRegistry::has('sales.order.voided.v1'));
        $this->assertTrue(DomainEventRegistry::has('purchasing.goods.received.v1'));
        $this->assertTrue(DomainEventRegistry::has('finance.journal.posted.v1'));
        $this->assertTrue(DomainEventRegistry::has('tenancy.tenant.provisioned.v1'));

        $this->assertSame(OrderCompletedV1::class, DomainEventRegistry::get('sales.order.completed.v1'));
        $this->assertSame(OrderVoidedV1::class, DomainEventRegistry::get('sales.order.voided.v1'));
        $this->assertSame(GoodsReceivedV1::class, DomainEventRegistry::get('purchasing.goods.received.v1'));
        $this->assertSame(JournalPostedV1::class, DomainEventRegistry::get('finance.journal.posted.v1'));
        $this->assertSame(TenantProvisionedV1::class, DomainEventRegistry::get('tenancy.tenant.provisioned.v1'));
    }

    public function test_order_completed_v1_payload_and_contract(): void
    {
        $event = new OrderCompletedV1(
            eventId: 'evt_01J0001',
            tenantId: 10,
            companyId: 2,
            branchId: 5,
            orderId: 1001,
            orderNumber: 'ORD-202611-0001',
            totalAmount: '150000.00',
            paymentMethod: 'CASH',
            lines: [
                ['product_id' => 1, 'product_name' => 'Kopi Susu', 'quantity' => 2, 'unit_price' => '25000.00', 'subtotal' => '50000.00'],
            ],
            occurredAt: '2026-11-18T10:00:00Z'
        );

        $this->assertInstanceOf(DomainEventInterface::class, $event);
        $this->assertSame('sales.order.completed.v1', $event->eventName());
        $this->assertSame(1, $event->schemaVersion());
        $this->assertSame(10, $event->tenantId());
        $this->assertSame('2026-11-18T10:00:00Z', $event->occurredAt());

        $payload = $event->toPayload();
        $this->assertSame('ORD-202611-0001', $payload['order_number']);
        $this->assertSame('150000.00', $payload['total_amount']);
        $this->assertCount(1, $payload['lines']);
    }

    public function test_order_voided_v1_payload_and_contract(): void
    {
        $event = new OrderVoidedV1(
            eventId: 'evt_01J0002',
            tenantId: 10,
            companyId: 2,
            branchId: 5,
            orderId: 1001,
            orderNumber: 'ORD-202611-0001',
            reason: 'Customer cancelled transaction',
            actorId: 42,
            occurredAt: '2026-11-18T10:15:00Z'
        );

        $this->assertSame('sales.order.voided.v1', $event->eventName());
        $this->assertSame(1, $event->schemaVersion());
        $payload = $event->toPayload();
        $this->assertSame('Customer cancelled transaction', $payload['reason']);
        $this->assertSame(42, $payload['actor_id']);
    }

    public function test_goods_received_v1_payload_and_contract(): void
    {
        $event = new GoodsReceivedV1(
            eventId: 'evt_01J0003',
            tenantId: 10,
            companyId: 2,
            branchId: 5,
            grnId: 501,
            grnNumber: 'GRN-202611-001',
            purchaseOrderId: 301,
            lines: [
                ['product_id' => 12, 'quantity_received' => 50, 'unit_cost' => '15000.00'],
            ],
            occurredAt: '2026-11-18T11:00:00Z'
        );

        $this->assertSame('purchasing.goods.received.v1', $event->eventName());
        $payload = $event->toPayload();
        $this->assertSame('GRN-202611-001', $payload['grn_number']);
        $this->assertSame(301, $payload['purchase_order_id']);
    }

    public function test_journal_posted_v1_payload_and_contract(): void
    {
        $event = new JournalPostedV1(
            eventId: 'evt_01J0004',
            tenantId: 10,
            companyId: 2,
            branchId: 5,
            journalEntryId: 901,
            entryNumber: 'JE-202611-001',
            sourceType: 'sales_order',
            sourceId: 1001,
            totalDebit: '150000.00',
            totalCredit: '150000.00',
            lines: [
                ['account_id' => 10, 'account_code' => '1-1000', 'debit' => '150000.00', 'credit' => '0.00'],
                ['account_id' => 40, 'account_code' => '4-1000', 'debit' => '0.00', 'credit' => '150000.00'],
            ],
            occurredAt: '2026-11-18T10:05:00Z'
        );

        $this->assertSame('finance.journal.posted.v1', $event->eventName());
        $payload = $event->toPayload();
        $this->assertSame('150000.00', $payload['total_debit']);
        $this->assertSame('150000.00', $payload['total_credit']);
    }

    public function test_tenant_provisioned_v1_payload_and_contract(): void
    {
        $event = new TenantProvisionedV1(
            eventId: 'evt_01J0005',
            tenantId: 25,
            companyId: 3,
            branchId: 8,
            tenantName: 'Kafe Berkah',
            planCode: 'pro',
            ownerEmail: 'owner@kafeberkah.id',
            occurredAt: '2026-11-18T12:00:00Z'
        );

        $this->assertSame('tenancy.tenant.provisioned.v1', $event->eventName());
        $payload = $event->toPayload();
        $this->assertSame('Kafe Berkah', $payload['tenant_name']);
        $this->assertSame('pro', $payload['plan_code']);
    }

    public function test_registering_invalid_class_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DomainEventRegistry::register(\stdClass::class);
    }
}
