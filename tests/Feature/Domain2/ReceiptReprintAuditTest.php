<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ReceiptPrintLog;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\Printing\ReceiptPrinterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ReceiptReprintAuditTest
 *
 * Menguji kepatuhan terhadap:
 * - AC-5.1: Mencetak struk kedua kali menaikkan print_count menjadi 2,
 *           menampilkan indikator cetak ulang pada output struk, mencatat
 *           ReceiptPrintLog + ActivityLog, dan tidak mengubah tabel finansial/stok.
 * - Proteksi cetak struk: hanya order berstatus Paid yang dapat dicetak struk resminya.
 */
class ReceiptReprintAuditTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private int $tenantId;

    private int $storeId;

    private int $deviceId;

    private int $userId;

    private int $shiftId;

    private ReceiptPrinterService $printerService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        $tenant = Tenant::create(['name' => 'Printer Tenant '.Str::random(6), 'readiness_status' => 'active']);
        $this->tenantId = (int) $tenant->id;

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Printer Store '.Str::random(6),
        ]);
        $this->storeId = (int) $store->id;

        $context->setTenantId($this->tenantId);
        $context->setStoreId($this->storeId);

        $device = Device::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'device_name' => 'POS-Printer-Term',
            'device_code' => 'DEV-'.strtoupper(Str::random(8)),
            'device_type' => 'pos',
            'is_active' => true,
        ]);
        $this->deviceId = (int) $device->id;

        $role = Role::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Cashier',
            'permissions' => ['orders.create', 'orders.print_receipt'],
        ]);

        $user = User::create([
            'tenant_id' => $this->tenantId,
            'role_id' => $role->id,
            'name' => 'Cashier Print '.Str::random(4),
            'email' => 'cashier-'.uniqid().'@printer.test',
            'password' => 'password',
        ]);
        $user->stores()->attach($this->storeId, ['tenant_id' => $this->tenantId]);
        $this->userId = (int) $user->id;

        $shift = Shift::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'device_id' => $this->deviceId,
            'user_id' => $this->userId,
            'status' => 'open',
            'opening_balance' => 200000,
            'opened_at' => now(),
        ]);
        $this->shiftId = (int) $shift->id;

        $this->printerService = app(ReceiptPrinterService::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function createPaidOrder(int $amount = 50000): Order
    {
        $category = Category::create([
            'tenant_id' => $this->tenantId,
            'category_name' => 'Food '.Str::random(4),
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenantId,
            'category_id' => $category->id,
            'product_name' => 'Nasi Goreng',
            'product_price' => $amount,
            'stock' => 10,
            'is_active' => true,
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'user_id' => $this->userId,
            'shift_id' => $this->shiftId,
            'created_by_device_id' => $this->deviceId,
            'order_code' => 'ORD-PRINT-'.strtoupper(Str::random(6)),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => $amount,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'order_amount' => $amount,
            'cash_received' => $amount,
            'order_change' => 0,
            'order_status' => OrderStatus::Paid->value,
            'payment_method' => PaymentMethod::Cash->value,
            'idempotency_key' => (string) Str::uuid(),
            'print_count' => 0,
        ]);

        OrderItem::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1,
            'order_price' => $amount,
            'order_subtotal' => $amount,
            'net_line_total' => $amount,
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'device_id' => $this->deviceId,
            'shift_id' => $this->shiftId,
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => $amount,
            'status' => 'captured',
            'processed_by' => $this->userId,
            'idempotency_key' => 'pay-print-'.Str::random(8),
        ]);

        return $order;
    }

    /**
     * AC-5.1: Cetak pertama kali vs cetak kedua kali (reprint).
     */
    public function test_first_print_and_reprint_lifecycle_ac_5_1(): void
    {
        $order = $this->createPaidOrder(50000);
        $this->assertEquals(0, $order->print_count);

        // Cetakan Pertama
        $print1 = $this->printerService->generatePrintPayload(
            $order,
            $this->userId,
            $this->deviceId,
            $this->shiftId
        );

        $this->assertEquals(1, $print1['print_sequence']);
        $this->assertFalse($print1['is_reprint']);
        $this->assertStringNotContainsString('COPY / CETAK ULANG', $print1['receipt_text']);

        // Order print_count naik menjadi 1
        $this->assertEquals(1, $order->fresh()->print_count);

        // Tercatat 1 log cetak
        $log1 = ReceiptPrintLog::where('order_id', $order->id)->first();
        $this->assertNotNull($log1);
        $this->assertEquals(1, $log1->print_sequence);
        $this->assertFalse($log1->is_reprint);

        // Snapshot finansial sebelum reprint
        $orderAmountBefore = $order->fresh()->order_amount;
        $paymentsCountBefore = Payment::where('order_id', $order->id)->count();

        // Cetakan Kedua (Reprint)
        $print2 = $this->printerService->generatePrintPayload(
            $order,
            $this->userId,
            $this->deviceId,
            $this->shiftId,
            'Kertas printer macet'
        );

        $this->assertEquals(2, $print2['print_sequence']);
        $this->assertTrue($print2['is_reprint']);
        // Menampilkan label CETAK ULANG #2
        $this->assertStringContainsString('*** COPY / CETAK ULANG #2 ***', $print2['receipt_text']);

        // Order print_count naik menjadi 2
        $this->assertEquals(2, $order->fresh()->print_count);

        // Tercatat log kedua dengan alasan reprint
        $log2 = ReceiptPrintLog::where('order_id', $order->id)->where('print_sequence', 2)->first();
        $this->assertNotNull($log2);
        $this->assertTrue($log2->is_reprint);
        $this->assertEquals('Kertas printer macet', $log2->reprint_reason);

        // Memastikan tabel finansial sama sekali tidak berubah
        $this->assertEquals($orderAmountBefore, $order->fresh()->order_amount);
        $this->assertEquals($paymentsCountBefore, Payment::where('order_id', $order->id)->count());

        // Memastikan ActivityLog tercatat
        $activity = ActivityLog::where('action', 'receipt.reprinted')->first();
        $this->assertNotNull($activity);
        $this->assertStringContainsString('Kertas printer macet', $activity->description);
    }

    /**
     * Memastikan struk pesanan yang belum lunas (Pending) ditolak untuk cetak resmi.
     */
    public function test_cannot_print_receipt_for_unpaid_order(): void
    {
        $order = Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'user_id' => $this->userId,
            'shift_id' => $this->shiftId,
            'order_code' => 'ORD-PENDING-'.Str::random(6),
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'subtotal_amount' => 30000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'order_amount' => 30000,
            'cash_received' => 0,
            'order_change' => 0,
            'order_status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Cash->value,
            'idempotency_key' => (string) Str::uuid(),
            'print_count' => 0,
        ]);

        $this->expectException(ValidationException::class);

        $this->printerService->generatePrintPayload(
            $order,
            $this->userId,
            $this->deviceId,
            $this->shiftId
        );
    }
}
