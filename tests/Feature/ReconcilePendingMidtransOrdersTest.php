<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReconcilePendingMidtransOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected $systemUser;

    protected function setUp(): void
    {
        parent::setUp();
        
        Config::set('services.midtrans.server_key', 'test_server_key');
        Config::set('services.midtrans.is_production', false);
        
        $role = \App\Models\Role::firstOrCreate(
            ['role_code' => 'ROL-001'],
            [
                'name' => 'Admin',
                'permissions' => [],
                'is_active' => true,
            ]
        );
        $this->systemUser = User::factory()->create([
            'email' => config('pos.self_order_system_email', 'system@yovel.com'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    public function test_skips_recently_created_orders()
    {
        $order = Order::create([
            'order_code' => 'ORD-RECENT-1',
            'order_status' => OrderStatus::Pending,
            'order_amount' => 100000,
            'sub_total' => 100000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_method' => 'qris',
            'user_id' => $this->systemUser->id,
            'snap_token' => 'snap_123',
            'order_date' => now()->toDateString(),
            'created_at' => now()->subMinutes(10), // Not stale yet (needs to be > 30 mins)
        ]);

        $this->artisan('orders:reconcile-midtrans')
             ->expectsOutputToContain('0 Midtrans orders direkonsiliasi.')
             ->assertSuccessful();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_status' => OrderStatus::Pending->value,
        ]);
    }

    public function test_skips_cash_orders()
    {
        $order = Order::create([
            'order_code' => 'ORD-CASH-1',
            'order_status' => OrderStatus::Pending,
            'order_amount' => 100000,
            'sub_total' => 100000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_method' => 'cash',
            'user_id' => $this->systemUser->id,
            'snap_token' => 'snap_123',
            'order_date' => now()->toDateString(),
            'created_at' => now()->subMinutes(40), 
        ]);

        $this->artisan('orders:reconcile-midtrans')
             ->expectsOutputToContain('0 Midtrans orders direkonsiliasi.')
             ->assertSuccessful();
    }
}
