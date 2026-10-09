<?php

namespace Tests\Feature\Tenancy;

use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

/**
 * Phase 9 — bukti bahwa business unique kini PER TENANT, dan bahwa
 * orders_tenant_idempotency_key_unique (nama wajib mengandung "idempotency_key")
 * tetap memicu jalur duplicate-detection lama.
 */
#[Group('tenancy-baseline')]
class TenantScopedUniqueTest extends TestCase
{
    use RefreshDatabase, TwoTenantFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (! str_contains(DB::connection()->getDatabaseName(), 'test')) {
            $this->markTestSkipped('Not test database');
        }
        app(TenantContext::class)->runWithoutTenant(fn () => $this->setupTwoTenants());
    }

    private function inTenant(int $tenantId, callable $cb)
    {
        return app(TenantContext::class)->runAs($tenantId, null, $cb);
    }

    public function test_same_category_name_allowed_in_two_tenants(): void
    {
        $a = $this->inTenant($this->tenantA->id, fn () => DB::table('categories')->insert([
            'tenant_id' => $this->tenantA->id, 'category_name' => 'Minuman', 'category_code' => 'C-A',
        ]));
        $b = $this->inTenant($this->tenantB->id, fn () => DB::table('categories')->insert([
            'tenant_id' => $this->tenantB->id, 'category_name' => 'Minuman', 'category_code' => 'C-B',
        ]));

        $this->assertTrue($a && $b);
    }

    public function test_same_discount_code_allowed_in_two_tenants(): void
    {
        foreach ([$this->tenantA->id, $this->tenantB->id] as $tid) {
            DB::table('discounts')->insert([
                'tenant_id' => $tid, 'code' => 'PROMO10', 'name' => 'P', 'type' => 'fixed',
                'value' => 1000, 'min_purchase_amount' => 0, 'is_active' => 1,
            ]);
        }

        $this->assertSame(2, DB::table('discounts')->where('code', 'PROMO10')->count());
    }

    public function test_same_customer_phone_allowed_in_two_tenants(): void
    {
        foreach ([$this->tenantA->id, $this->tenantB->id] as $tid) {
            DB::table('customers')->insert([
                'tenant_id' => $tid, 'name' => 'Cust', 'phone' => '081234567890', 'is_active' => 1,
            ]);
        }

        $this->assertSame(2, DB::table('customers')->where('phone', '081234567890')->count());
    }

    public function test_same_table_name_allowed_in_two_tenants(): void
    {
        foreach ([$this->tenantA->id, $this->tenantB->id] as $tid) {
            DB::table('tables')->insert([
                'tenant_id' => $tid, 'store_id' => $tid === $this->tenantA->id ? $this->storeA->id : $this->storeB->id,
                'table_name' => 'M-01', 'table_code' => 'TC-'.$tid, 'capacity' => 2, 'area' => 'Main', 'is_active' => 1,
                'secure_token' => 'tok-'.$tid, 'operational_status' => 'available',
            ]);
        }

        $this->assertSame(2, DB::table('tables')->where('table_name', 'M-01')->count());
    }

    public function test_same_idempotency_key_across_tenants_succeeds(): void
    {
        DB::table('orders')->insert([
            'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id, 'order_code' => 'X-A', 'idempotency_key' => 'SHARED-KEY',
            'order_date' => now()->toDateString(), 'order_status' => 'pending', 'payment_method' => 'cash',
            'subtotal_amount' => 1000, 'order_amount' => 1000,
        ]);
        DB::table('orders')->insert([
            'tenant_id' => $this->tenantB->id, 'store_id' => $this->storeB->id,
            'user_id' => $this->userBBarista->id, 'order_code' => 'X-B', 'idempotency_key' => 'SHARED-KEY',
            'order_date' => now()->toDateString(), 'order_status' => 'pending', 'payment_method' => 'cash',
            'subtotal_amount' => 1000, 'order_amount' => 1000,
        ]);

        $this->assertSame(2, DB::table('orders')->where('idempotency_key', 'SHARED-KEY')->count());
    }

    public function test_duplicate_idempotency_key_within_tenant_is_blocked_and_names_the_column(): void
    {
        $row = [
            'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id, 'order_code' => 'DUP-A', 'idempotency_key' => 'DUP-KEY',
            'order_date' => now()->toDateString(), 'order_status' => 'pending', 'payment_method' => 'cash',
            'subtotal_amount' => 1000, 'order_amount' => 1000,
        ];
        DB::table('orders')->insert($row);

        // Nama index HARUS mengandung "idempotency_key" agar str_contains() di
        // TransactionController/CheckoutController tetap mendeteksi duplicate.
        try {
            DB::table('orders')->insert(array_merge($row, ['order_code' => 'DUP-A2']));
            $this->fail('Duplicate idempotency_key within a tenant should have been rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('idempotency_key', $e->getMessage());
        }
    }

    public function test_unique_index_name_contains_idempotency_key(): void
    {
        $rows = DB::select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0',
            ['orders']
        );
        $names = array_map(fn ($r) => $r->INDEX_NAME, $rows);
        $this->assertContains('orders_tenant_idempotency_key_unique', $names);
        $this->assertTrue(collect($names)->contains(fn ($n) => str_contains($n, 'idempotency_key')));
    }
}
