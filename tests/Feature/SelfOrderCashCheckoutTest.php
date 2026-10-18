<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class SelfOrderCashCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });
    }

    public function test_self_order_cash_produces_pending_order_without_validation_error(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->update(['quantity' => 10]);
        $this->productA->update(['stock' => 10]);

        $order = app(TransactionService::class)->createTransaction([
            'idempotency_key' => Str::uuid()->toString(),
            'items' => [['product_id' => $this->productA->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null]],
            'payment_method' => 'cash',
            'cash_received' => null,
            'is_self_order_cash' => true,
            'table_id' => null,
        ], $this->userAOwner->id);

        $this->assertEquals(OrderStatus::Pending, $order->order_status);
        $this->assertNull($order->cash_received);
        $this->assertNull($order->snap_token);
    }
}
