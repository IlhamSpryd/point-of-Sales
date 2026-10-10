<?php

namespace Tests\Support;

use App\Enums\PaymentMethodEnum;
use App\Enums\PaymentStatusEnum;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;

trait TwoTenantFixture
{
    public $tenantA;

    public $tenantB;

    public $storeA;

    public $storeB;

    public $storeA2;

    public $userAOwner;

    public $userBBarista;

    public $productA;

    public $productB;

    public $orderA;

    public $orderB;

    protected function setupTwoTenants()
    {
        app(TenantContext::class)->clear();
        app(TenantContext::class)->runWithoutTenant(function () {
            Model::unguard();

            $this->tenantA = Tenant::create(['name' => 'Tenant A']);
            $this->tenantB = Tenant::create(['name' => 'Tenant B']);

            $this->storeA = Store::create(['tenant_id' => $this->tenantA->id, 'name' => 'Store A']);
            $this->storeB = Store::create(['tenant_id' => $this->tenantB->id, 'name' => 'Store B']);
            // Cabang kedua untuk Tenant A — dipakai menguji isolasi antar-store.
            $this->storeA2 = Store::create(['tenant_id' => $this->tenantA->id, 'name' => 'Store A2']);

            // Role kini tenant-scoped (TenantScope melempar exception tanpa konteks) —
            // fixture menggunakan escape hatch eksplisit karena ini setup sistem dua tenant.
            $roleOwner = Role::withoutTenantScope()->firstOrCreate(['name' => 'Owner', 'tenant_id' => $this->tenantA->id], ['is_active' => 1, 'permissions' => ['can_void_order']]);
            $roleBarista = Role::withoutTenantScope()->firstOrCreate(['name' => 'Barista', 'tenant_id' => $this->tenantB->id], ['is_active' => 1]);

            $this->userAOwner = User::create([
                'name' => 'Owner A',
                'email' => 'ownerA@test.com',
                'email_verified_at' => now(),
                'tenant_id' => $this->tenantA->id,
                'role_id' => $roleOwner->id,
                'is_active' => 1,
            ]);

            $this->userBBarista = User::create([
                'name' => 'Barista B',
                'email' => 'baristaB@test.com',
                'email_verified_at' => now(),
                'tenant_id' => $this->tenantB->id,
                'role_id' => $roleBarista->id,
                'is_active' => 1,
            ]);

            // Phase 11: Kasir A hanya ditugaskan ke Store A (bukan Store A2).
            $this->userAOwner->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);
            $this->userBBarista->stores()->attach($this->storeB->id, ['tenant_id' => $this->tenantB->id]);

            Artisan::call('tenant:provision-system-users', ['tenant' => $this->tenantA->id]);
            Artisan::call('tenant:provision-system-users', ['tenant' => $this->tenantB->id]);

            $catA = Category::withoutTenantScope()->create(['category_name' => 'Cat A', 'tenant_id' => $this->tenantA->id]);
            $catB = Category::withoutTenantScope()->create(['category_name' => 'Cat B', 'tenant_id' => $this->tenantB->id]);

            $this->productA = Product::withoutTenantScope()->create([
                'product_name' => 'Prod A',
                'tenant_id' => $this->tenantA->id,
                'category_id' => $catA->id,
                'is_active' => 1,
            ]);

            $this->productB = Product::withoutTenantScope()->create([
                'product_name' => 'Prod B',
                'tenant_id' => $this->tenantB->id,
                'category_id' => $catB->id,
                'is_active' => 1,
            ]);

            $this->orderA = Order::create([
                'user_id' => $this->userAOwner->id,
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'order_code' => 'ORD-A-1',
                'idempotency_key' => 'idemp-a-1',
                'order_date' => now()->toDateString(),
                'order_status' => 'pending',
                'payment_method' => 'cash',
                'subtotal_amount' => 5000,
                'order_amount' => 5000,
            ]);

            $this->orderB = Order::create([
                'user_id' => $this->userBBarista->id,
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB->id,
                'order_code' => 'ORD-B-1',
                'idempotency_key' => 'idemp-b-1',
                'order_date' => now()->toDateString(),
                'order_status' => 'paid',
                'payment_method' => 'cash',
                'subtotal_amount' => 5000,
                'order_amount' => 5000,
            ]);

            OrderItem::create([
                'order_id' => $this->orderA->id,
                'product_id' => $this->productA->id,
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'qty' => 1,
                'preparation_status' => 'pending',
            ]);

            OrderItem::create([
                'order_id' => $this->orderB->id,
                'product_id' => $this->productB->id,
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB->id,
                'qty' => 1,
                'preparation_status' => 'pending',
            ]);

            Payment::create([
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB->id,
                'order_id' => $this->orderB->id,
                'payment_method' => PaymentMethodEnum::Cash,
                'amount' => 5000,
                'status' => PaymentStatusEnum::Captured,
                'captured_at' => now(),
                'processed_by' => $this->userBBarista->id,
                'idempotency_key' => 'payment-test-B-1',
            ]);
        });
    }
}
