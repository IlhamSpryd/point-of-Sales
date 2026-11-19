<?php

declare(strict_types=1);

namespace Tests\Feature\Kasir;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Decision 0.4 / OQ-09 — Waiter Order-Only Mode (Dine-In).
 *
 * A Waiter must be able to compose dine-in orders and dispatch them to the
 * kitchen, but must NEVER capture payment, void an order, or issue a refund.
 * The block must be enforced by the backend policy — not only by hiding the
 * button in the Blade view.
 */
class WaiterOrderOnlyModeTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $waiter;

    private User $kasir;

    protected function afterSubclassSetUp(): void
    {
        $context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Waiter Order-Only Tenant',
            'slug' => 'waiter-order-only-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Waiter Order-Only Store',
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);

        $waiterRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Waiter',
            'permissions' => ['orders.create', 'orders.view', 'tables.view'],
        ]);

        $kasirRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create', 'orders.view', 'payments.create', 'shifts.own'],
        ]);

        $this->waiter = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $waiterRole->id,
        ]);

        $this->kasir = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $kasirRole->id,
        ]);

        foreach ([$this->waiter, $this->kasir] as $user) {
            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant->id,
                'user_id' => $user->id,
                'store_id' => $this->store->id,
            ]);
        }
    }

    public function test_waiter_reaches_the_pos_terminal(): void
    {
        $this->actingAs($this->waiter)
            ->get(route('transaction.create'))
            ->assertOk();
    }

    public function test_waiter_can_compose_an_order(): void
    {
        $this->assertTrue(
            Gate::forUser($this->waiter)->allows('create', Order::class),
            'Waiter must be allowed to compose a dine-in order.'
        );
    }

    public function test_waiter_is_blocked_from_payment_capture(): void
    {
        $this->assertFalse(
            Gate::forUser($this->waiter)->allows('capturePayment', Order::class),
            'Waiter must be denied payment capture (segregation of duties).'
        );
    }

    public function test_kasir_is_allowed_to_capture_payment(): void
    {
        $this->assertTrue(
            Gate::forUser($this->kasir)->allows('capturePayment', Order::class),
            'Kasir must retain payment capture authority.'
        );
    }

    public function test_waiter_cannot_post_a_paid_transaction(): void
    {
        $response = $this->actingAs($this->waiter)
            ->postJson(route('transaction.store'), [
                'items' => [],
                'payment_method' => 'cash',
            ]);

        $this->assertTrue(
            in_array($response->status(), [403, 422], true),
            "Waiter transaction POST must be rejected server-side, got {$response->status()}."
        );
    }

    public function test_waiter_is_blocked_from_order_void_route(): void
    {
        $this->actingAs($this->waiter)
            ->post(route('orders.void', ['orderNumber' => 'INV-NONEXISTENT']))
            ->assertForbidden();
    }

    public function test_waiter_is_blocked_from_order_return_route(): void
    {
        $this->actingAs($this->waiter)
            ->post(route('orders.return', ['orderNumber' => 'INV-NONEXISTENT']))
            ->assertForbidden();
    }

    public function test_waiter_sees_the_kitchen_dispatch_button_not_checkout(): void
    {
        $response = $this->actingAs($this->waiter)->get(route('transaction.create'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('Kirim ke Dapur (KDS)', $html);
        $this->assertStringContainsString('btn-dispatch-kitchen', $html);
        // The cashier checkout CTAs live in the @else branches the Waiter branch
        // bypasses entirely (Decision 0.4 / OQ-09).
        $this->assertStringNotContainsString('id="btn-bayar"', $html);
        $this->assertStringNotContainsString('id="btn-proses-bayar"', $html);
    }
}
