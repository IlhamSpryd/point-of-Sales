<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Ingredient;
use App\Models\IngredientRestockForecast;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardV2RedesignTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store1;
    private Store $store2;
    private User $ownerUser;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Kedai Kopi Apeiron',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store1 = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Sudirman',
            'code' => 'SDR-01',
            'is_active' => true,
        ]);

        $this->store2 = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Senopati',
            'code' => 'SNP-02',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store1->id);

        $roleOwner = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Owner', 'description' => 'Owner Role']
        );

        $this->ownerUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleOwner->id,
            'name' => 'Budi Santoso',
            'email' => 'budi@apeiron.test',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_owner_can_access_dashboard_with_all_stores(): void
    {
        // Seed some sample data
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store1->id,
            'user_id' => $this->ownerUser->id,
            'order_code' => 'ORD-TEST-001',
            'idempotency_key' => 'IDEMP-TEST-001',
            'order_amount' => 50000,
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Completed,
            'payment_method' => PaymentMethod::Qris,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'payment_method' => 'qris',
            'amount' => 50000,
            'payment_status' => 'success',
            'payment_time' => now(),
        ]);

        $ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Biji Kopi Arabika',
            'unit' => 'kg',
            'current_stock' => 2.5,
            'reorder_level' => 5.0,
            'cost_per_unit' => 150000,
        ]);

        IngredientRestockForecast::create([
            'tenant_id' => $this->tenant->id,
            'ingredient_id' => $ingredient->id,
            'avg_daily_consumption' => 1.2,
            'projected_days_remaining' => 2.0,
            'projected_stockout_at' => now()->addDays(2),
            'suggested_reorder_qty' => 15.0,
            'trend' => 'increasing',
            'computed_at' => now(),
        ]);

        $response = $this->actingAs($this->ownerUser)
            ->withSession(['active_store_id' => 'all'])
            ->get(route('dashboard'));

        $response->assertStatus(200);

        // Check key UI concept C tokens and texts
        $response->assertSee('Total pendapatan');
        $response->assertSee('Budi');
        $response->assertSee('Total transaksi');
        $response->assertSee('Terjual bulan ini');
        $response->assertSee('Stok tipis');
        $response->assertSee('Metode pembayaran');
        $response->assertSee('Prakiraan restock');
        $response->assertSee('Biji Kopi Arabika');
        $response->assertSee('50.000');
        $response->assertSee('Lunas');
    }

    public function test_dashboard_switches_to_single_store(): void
    {
        $response = $this->actingAs($this->ownerUser)
            ->withSession(['active_store_id' => (string) $this->store1->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSee('Cabang Sudirman');
    }

    public function test_dashboard_service_generates_valid_svg_and_metrics(): void
    {
        $this->actingAs($this->ownerUser);
        session(['active_store_id' => 'all']);

        $service = app(DashboardService::class);
        $data = $service->getDashboardMetrics();

        $this->assertArrayHasKey('totalEarnings', $data);
        $this->assertArrayHasKey('totalOrders', $data);
        $this->assertArrayHasKey('chartSvg', $data);
        $this->assertArrayHasKey('recentOrders', $data);
        $this->assertArrayHasKey('paymentBreakdown', $data);
        $this->assertArrayHasKey('restockList', $data);
        $this->assertArrayHasKey('summaryText', $data);

        // Check chartSvg structure
        $this->assertArrayHasKey('desktop_path', $data['chartSvg']);
        $this->assertArrayHasKey('desktop_fill', $data['chartSvg']);
        $this->assertArrayHasKey('desktop_points', $data['chartSvg']);
        $this->assertArrayHasKey('desktop_grid', $data['chartSvg']);
        $this->assertArrayHasKey('mobile_polyline', $data['chartSvg']);
        $this->assertArrayHasKey('mobile_polygon', $data['chartSvg']);
        $this->assertArrayHasKey('mobile_points', $data['chartSvg']);
        $this->assertArrayHasKey('mobile_grid', $data['chartSvg']);
        $this->assertCount(7, $data['chartSvg']['desktop_points']);
        $this->assertCount(7, $data['chartSvg']['mobile_points']);
    }
}
