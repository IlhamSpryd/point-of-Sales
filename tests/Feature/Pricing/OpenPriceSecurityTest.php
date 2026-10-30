<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpenPriceSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Category $category;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Open Price Security Tenant',
            'slug' => 'open-price-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Keamanan',
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Open Price Category',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);
    }

    private function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'stock' => 10,
        ], $attributes));
    }

    private function createShiftForUser(User $user): Shift
    {
        return Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $user->id,
            'start_time' => now(),
            'starting_cash' => 100000,
            'status' => 'open',
        ]);
    }

    public function test_custom_price_rejected_if_product_is_not_open_price(): void
    {
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create', 'pos.price.open_entry'],
        ]);

        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $shift = $this->createShiftForUser($user);

        $standardProduct = $this->createProduct([
            'product_name' => 'Kopi Standar',
            'product_price' => 20000,
            'is_open_price' => false,
        ]);

        $payload = [
            'shift_id' => $shift->id,
            'payment_method' => 'cash',
            'cash_received' => 50000,
            'idempotency_key' => Str::uuid()->toString(),
            'items' => [
                [
                    'product_id' => $standardProduct->id,
                    'quantity' => 1,
                    'custom_price' => 15000, // Mencoba manipulasi harga manual
                ],
            ],
        ];

        $response = $this->actingAs($user)
            ->withSession(['current_tenant_id' => $this->tenant->id, 'current_store_id' => $this->store->id])
            ->postJson(route('transaction.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.custom_price']);
    }

    public function test_custom_price_rejected_if_user_lacks_open_entry_permission(): void
    {
        // Kasir tanpa izin 'pos.price.open_entry'
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create'],
        ]);

        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $shift = $this->createShiftForUser($user);

        $openProduct = $this->createProduct([
            'product_name' => 'Jasa Servis Mesin Kopi',
            'product_price' => 0,
            'is_open_price' => true,
        ]);

        $payload = [
            'shift_id' => $shift->id,
            'payment_method' => 'cash',
            'cash_received' => 100000,
            'idempotency_key' => Str::uuid()->toString(),
            'items' => [
                [
                    'product_id' => $openProduct->id,
                    'quantity' => 1,
                    'custom_price' => 80000,
                ],
            ],
        ];

        $response = $this->actingAs($user)
            ->withSession(['current_tenant_id' => $this->tenant->id, 'current_store_id' => $this->store->id])
            ->postJson(route('transaction.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.custom_price']);
    }

    public function test_custom_price_accepted_for_open_price_product_with_authorized_user(): void
    {
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create', 'pos.price.open_entry'],
        ]);

        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $shift = $this->createShiftForUser($user);

        $openProduct = $this->createProduct([
            'product_name' => 'Kue Pengantin Custom',
            'product_price' => 0,
            'is_open_price' => true,
        ]);

        $payload = [
            'shift_id' => $shift->id,
            'payment_method' => 'cash',
            'cash_received' => 500000,
            'idempotency_key' => Str::uuid()->toString(),
            'items' => [
                [
                    'product_id' => $openProduct->id,
                    'quantity' => 1,
                    'custom_price' => 450000,
                ],
            ],
        ];

        $response = $this->actingAs($user)
            ->withSession(['current_tenant_id' => $this->tenant->id, 'current_store_id' => $this->store->id])
            ->postJson(route('transaction.store'), $payload);

        $response->assertSuccessful();
    }
}
