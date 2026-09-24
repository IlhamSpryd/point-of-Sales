<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class MidtransWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected $systemUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup config and seeders
        Config::set('services.midtrans.server_key', 'test_server_key');

        $role = Role::firstOrCreate(
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

    private function generateSignature($orderId, $statusCode, $grossAmount)
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.'test_server_key');
    }

    public function test_rejects_webhook_if_payload_incomplete()
    {
        $response = $this->postJson('/midtrans/notification', [
            'order_id' => 'ORD-123',
            // missing status_code, etc.
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'invalid payload']);
    }

    public function test_rejects_webhook_if_signature_invalid()
    {
        $response = $this->postJson('/midtrans/notification', [
            'order_id' => 'ORD-123',
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'signature_key' => 'invalid_signature_hash',
            'transaction_status' => 'settlement',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'invalid signature']);
    }

    public function test_returns_404_if_order_not_found()
    {
        $orderId = 'ORD-NOT-FOUND';
        $signature = $this->generateSignature($orderId, '200', '100000.00');

        $response = $this->postJson('/midtrans/notification', [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
        ]);

        $response->assertStatus(404);
    }

    public function test_does_not_process_if_gross_amount_mismatch()
    {
        $order = Order::forceCreate([
            'order_code' => 'ORD-MISMATCH-1',
            'idempotency_key' => Str::uuid()->toString(),
            'order_status' => OrderStatus::Pending,
            'order_amount' => 50000,
            'subtotal_amount' => 50000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_method' => 'qris',
            'user_id' => $this->systemUser->id,
            'order_date' => now()->toDateString(),
        ]);

        $signature = $this->generateSignature($order->order_code, '200', '10000.00'); // Mismatch amount

        $response = $this->postJson('/midtrans/notification', [
            'order_id' => $order->order_code,
            'status_code' => '200',
            'gross_amount' => '10000.00',
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
        ]);

        $response->assertStatus(200); // Controller returns 200 OK

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_status' => OrderStatus::Pending->value, // Tidak berubah
        ]);
    }

    public function test_successfully_processes_settlement()
    {
        $order = Order::forceCreate([
            'order_code' => 'ORD-SETTLEMENT-1',
            'idempotency_key' => Str::uuid()->toString(),
            'order_status' => OrderStatus::Pending,
            'order_amount' => 100000,
            'subtotal_amount' => 100000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_method' => 'qris',
            'user_id' => $this->systemUser->id,
            'order_date' => now()->toDateString(),
        ]);

        $signature = $this->generateSignature($order->order_code, '200', '100000.00');

        $response = $this->postJson('/midtrans/notification', [
            'order_id' => $order->order_code,
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_status' => OrderStatus::Paid->value,
        ]);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'payment_method' => 'qris',
        ]);
    }

    public function test_successfully_processes_expire()
    {
        $order = Order::forceCreate([
            'order_code' => 'ORD-EXPIRE-1',
            'idempotency_key' => Str::uuid()->toString(),
            'order_status' => OrderStatus::Pending,
            'order_amount' => 100000,
            'subtotal_amount' => 100000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_method' => 'qris',
            'user_id' => $this->systemUser->id,
            'order_date' => now()->toDateString(),
        ]);

        $signature = $this->generateSignature($order->order_code, '200', '100000.00');

        $response = $this->postJson('/midtrans/notification', [
            'order_id' => $order->order_code,
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'signature_key' => $signature,
            'transaction_status' => 'expire',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_status' => OrderStatus::Expired->value,
        ]);
    }
}
