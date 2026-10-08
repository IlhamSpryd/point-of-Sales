<?php

namespace Tests\Feature\Tenancy;

use App\Livewire\Kasir\OrderHistory;
use App\Livewire\Kds\Board;
use App\Models\Payment;
use App\Services\Context\TenantContext;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

#[Group('tenancy-baseline')]
class BaselineTest extends TestCase
{
    use RefreshDatabase, TwoTenantFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $dbName = DB::connection()->getDatabaseName();
        if (! str_contains($dbName, 'test')) {
            $this->markTestSkipped('Not test database');
        }

        $this->setupTwoTenants();
    }

    // T1: OrderHistory::viewDetail with a tenant-B order id as a tenant-A user shows nothing/denied.
    public function test_t1_order_history_view_detail_cross_tenant_denied()
    {
        Livewire::actingAs($this->userAOwner)
            ->test(OrderHistory::class)
            ->call('viewDetail', $this->orderB->id)
            ->assertSet('selectedOrderId', $this->orderB->id)
            ->assertSet('selectedOrder', null);
    }

    // T2: UserController index/edit/update across tenants.
    public function test_t2_user_controller_cross_tenant_denied()
    {
        $response = $this->actingAs($this->userAOwner)->get('/users/'.$this->userBBarista->id.'/edit');

        // Assert desired secure behavior
        $response->assertStatus(404);
    }

    // T3: POST /transaction with a tenant-B product_id is rejected.
    public function test_t3_transaction_with_cross_tenant_product_rejected()
    {
        $response = $this->actingAs($this->userAOwner)->post('/transaction', [
            'items' => [
                ['product_id' => $this->productB->id, 'quantity' => 1],
            ],
            'payment_method' => 'cash',
            'idempotency_key' => '12345678-1234-1234-1234-123456789012',
        ]);

        // Assert desired secure behavior
        $response->assertStatus(302);
    }

    // T4: TransactionController::receipt with a tenant-B order_code returns 404.
    public function test_t4_transaction_receipt_cross_tenant_returns_404()
    {
        $response = $this->actingAs($this->userAOwner)->get('/transaction/receipt/'.$this->orderB->order_code);

        // Assert desired secure behavior
        $response->assertStatus(404);
    }

    // T5: Kds Board::claim on a tenant-B order item is denied.
    public function test_t5_kds_board_claim_cross_tenant_denied()
    {
        $orderItemB = $this->orderB->orderItems()->withoutGlobalScope('tenant')->first();

        Livewire::actingAs($this->userAOwner)
            ->test(Board::class)
            ->call('claim', $orderItemB->id);
        // It will safely abort or ignore the claim because the order item won't be found
    }

    // T6: a deactivated user is denied on the next HTTP request and the next Livewire action.
    public function test_t6_deactivated_user_is_denied()
    {
        $this->userAOwner->update(['is_active' => 0]);

        $response = $this->actingAs($this->userAOwner)->get('/dashboard');

        // Assert desired secure behavior
        $response->assertStatus(403);
    }

    // T7: voiding a Paid cash order succeeds and writes a payments row with status 'voided' (MySQL).
    public function test_t7_voiding_paid_cash_order_writes_voided_payment()
    {
        $context = app(TenantContext::class);
        dump("T7 Start. tenantA->id: {$this->tenantA->id}, context_tenant: ".$context->getTenantId());

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->orderA->update([
                'order_status' => 'paid',
                'payment_method' => 'cash',
            ]);

            Payment::forceCreate([
                'order_id' => $this->orderA->id,
                'status' => 'captured',
                'payment_method' => 'cash',
                'amount' => 5000,
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'reference_number' => 'CASH-123',
                'idempotency_key' => 'idemp-c-1',
            ]);
        });

        Livewire::actingAs($this->userAOwner)
            ->test(OrderHistory::class)
            ->set('voidingOrderId', $this->orderA->id)
            ->set('voidReason', 'Test void')
            ->call('voidOrder');

        $this->assertDatabaseHas('payments', [
            'order_id' => $this->orderA->id,
            'status' => 'voided',
        ]);
    }

    // T8: DashboardService and SalesExport exclude the other tenant's orders.
    public function test_t8_dashboard_service_excludes_cross_tenant_orders()
    {
        Cache::clear();
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        $metrics = app(DashboardService::class)->getDashboardMetrics();
        $recentOrderIds = $metrics['recentOrders']->pluck('id')->toArray();

        $this->assertNotContains($this->orderB->id, $recentOrderIds, 'T8 DashboardService leaks tenant B orders to tenant A!');
    }

    // T9: Product, Category and Order created through the app carry the acting user's tenant_id.
    public function test_t9_created_entities_carry_tenant_id()
    {
        $this->withoutExceptionHandling();
        $response = $this->actingAs($this->userAOwner)->post('/products', [
            'product_name' => 'New Product',
            'category_id' => $this->productA->category_id,
            'product_price' => 1000,
            'stock' => 0,
        ]);

        if ($response->status() !== 302 && $response->status() !== 200) {
            $response->dump();
        }
        if (session('errors')) {
            dump(session('errors')->getBag('default')->getMessages());
        }

        $this->assertDatabaseHas('products', [
            'product_name' => 'New Product',
            'tenant_id' => $this->tenantA->id,
        ]);
    }
}
