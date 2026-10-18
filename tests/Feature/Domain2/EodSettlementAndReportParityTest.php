<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Models\Device;
use App\Models\EodSettlement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashierPinService;
use App\Services\Context\TenantContext;
use App\Services\ReportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * EodSettlementAndReportParityTest
 *
 * Menguji kepatuhan terhadap:
 * - AC-5.2: closeEndOfDay gagal dengan pesan error spesifik apabila masih ada 1 shift
 *           yang berstatus terbuka atau payment attempt berstatus 'initiated'/timeout.
 * - AC-5.3: Snapshot agregat EodSettlement, output ReportService (getDetailedSalesSummary),
 *           dan query tabel sumber memiliki angka gross_sales, total_discounts,
 *           total_returns_refunds, dan net_sales yang 100% identik (paritas mutlak).
 * - Immutability trigger: baris EodSettlement berstatus 'closed' dilarang keras diubah atau dihapus.
 */
class EodSettlementAndReportParityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private int $tenantId;

    private int $storeId;

    private int $deviceId;

    private int $userId;

    private string $managerPin = '123456';

    private ReportService $reportService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        $tenant = Tenant::create(['name' => 'EOD Tenant '.Str::random(6), 'readiness_status' => 'active']);
        $this->tenantId = (int) $tenant->id;

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'EOD Store '.Str::random(6),
        ]);
        $this->storeId = (int) $store->id;

        $context->setTenantId($this->tenantId);
        $context->setStoreId($this->storeId);

        $device = Device::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'device_name' => 'POS-EOD-Device',
            'device_code' => 'DEV-EOD-'.strtoupper(Str::random(6)),
            'device_type' => 'pos',
            'is_active' => true,
        ]);
        $this->deviceId = (int) $device->id;

        $role = Role::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Store Manager',
            'permissions' => ['reports.view', 'reports.export', 'orders.create', 'shifts.close', 'eod.close'],
        ]);

        $user = User::create([
            'tenant_id' => $this->tenantId,
            'role_id' => $role->id,
            'name' => 'Manager EOD '.Str::random(4),
            'email' => 'manager-'.uniqid().'@eod.test',
            'password' => 'password',
        ]);
        $user->stores()->attach($this->storeId, ['tenant_id' => $this->tenantId]);
        $this->userId = (int) $user->id;

        // Set PIN supervisor
        app(CashierPinService::class)->setPin($user, $this->managerPin);

        $this->reportService = app(ReportService::class);
    }

    /**
     * AC-5.2: closeEndOfDay gagal dengan pesan error spesifik jika masih ada shift terbuka di cabang.
     */
    public function test_eod_fails_if_store_has_unclosed_shifts_ac_5_2(): void
    {
        $businessDate = Carbon::now()->toDateString();

        // Buat shift yang belum ditutup (status = open)
        Shift::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'user_id' => $this->userId,
            'device_id' => $this->deviceId,
            'opened_at' => Carbon::parse($businessDate.' 08:00:00'),
            'status' => 'open',
            'opening_balance' => 100000,
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->reportService->closeEndOfDay(
                $this->tenantId,
                $this->storeId,
                $businessDate,
                $this->userId,
                $this->managerPin
            );
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('eod', $e->errors());
            $this->assertStringContainsString('masih ada shift yang belum ditutup', $e->errors()['eod'][0]);

            throw $e;
        }
    }

    /**
     * AC-5.2: closeEndOfDay gagal jika masih ada payment attempt berstatus 'initiated'/unknown_timeout.
     */
    public function test_eod_fails_if_store_has_pending_payment_attempts(): void
    {
        $businessDate = Carbon::now()->toDateString();

        // Buat shift yang sudah ditutup
        $shift = Shift::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'user_id' => $this->userId,
            'device_id' => $this->deviceId,
            'opened_at' => Carbon::parse($businessDate.' 08:00:00'),
            'closed_at' => Carbon::parse($businessDate.' 16:00:00'),
            'status' => 'closed',
            'opening_balance' => 100000,
            'closing_balance' => 100000,
            'expected_cash' => 100000,
            'cash_difference' => 0,
        ]);

        // Buat order dan payment attempt yang menggantung
        $order = Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'user_id' => $this->userId,
            'shift_id' => $shift->id,
            'order_code' => 'ORD-ATTEMPT-'.Str::random(6),
            'order_date' => $businessDate,
            'order_type' => 'takeaway',
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'order_amount' => 50000,
            'cash_received' => 0,
            'order_change' => 0,
            'order_status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Qris->value,
            'idempotency_key' => (string) Str::uuid(),
            'print_count' => 0,
        ]);

        PaymentAttempt::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'device_id' => $this->deviceId,
            'shift_id' => $shift->id,
            'order_id' => $order->id,
            'provider' => 'midtrans',
            'payment_method' => 'qris',
            'amount' => 50000,
            'currency' => 'IDR',
            'status' => PaymentAttemptStatus::UnknownTimeout->value,
            'idempotency_key' => 'att-hanging-'.Str::random(8),
            'initiated_by_user_id' => $this->userId,
            'created_at' => Carbon::parse($businessDate.' 12:00:00'),
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->reportService->closeEndOfDay(
                $this->tenantId,
                $this->storeId,
                $businessDate,
                $this->userId,
                $this->managerPin
            );
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('eod', $e->errors());
            $this->assertStringContainsString('Masih ada transaksi pembayaran yang berstatus pending/timeout', $e->errors()['eod'][0]);

            throw $e;
        }
    }

    /**
     * AC-5.3: Paritas 100% antara EodSettlement, ReportService, dan baris data sumber (termasuk refund & diskon).
     */
    public function test_eod_settlement_aggregation_and_report_parity_ac_5_3(): void
    {
        $businessDate = Carbon::now()->toDateString();

        // 1. Shift tertutup
        $shift = Shift::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'user_id' => $this->userId,
            'device_id' => $this->deviceId,
            'opened_at' => Carbon::parse($businessDate.' 08:00:00'),
            'closed_at' => Carbon::parse($businessDate.' 16:00:00'),
            'status' => 'closed',
            'opening_balance' => 200000,
            'closing_balance' => 500000,
            'expected_cash' => 200000,
            'cash_difference' => 300000,
        ]);

        // 2. Order 1: Cash, ada diskon
        // subtotal 100.000, discount 10.000, tax 11.000, total 101.000
        $order1 = Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'user_id' => $this->userId,
            'shift_id' => $shift->id,
            'order_code' => 'ORD-PAR-1',
            'order_date' => $businessDate,
            'order_type' => 'dine_in',
            'subtotal_amount' => 100000,
            'discount_amount' => 10000,
            'tax_amount' => 11000,
            'service_charge_amount' => 0,
            'order_amount' => 101000,
            'cash_received' => 150000,
            'order_change' => 49000,
            'order_status' => OrderStatus::Paid->value,
            'payment_method' => PaymentMethod::Cash->value,
            'idempotency_key' => (string) Str::uuid(),
            'print_count' => 1,
            'created_at' => Carbon::parse($businessDate.' 10:00:00'),
        ]);

        $pay1 = Payment::forceCreate([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'device_id' => $this->deviceId,
            'shift_id' => $shift->id,
            'order_id' => $order1->id,
            'payment_method' => 'cash',
            'amount' => 101000,
            'status' => 'captured',
            'processed_by' => $this->userId,
            'idempotency_key' => 'pay-par-1',
            'created_at' => Carbon::parse($businessDate.' 10:00:00'),
        ]);

        // 3. Order 2: Non-cash (QRIS), tanpa diskon, ada service charge
        // subtotal 200.000, discount 0, tax 22.000, service 10.000, total 232.000
        $order2 = Order::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'user_id' => $this->userId,
            'shift_id' => $shift->id,
            'order_code' => 'ORD-PAR-2',
            'order_date' => $businessDate,
            'order_type' => 'dine_in',
            'subtotal_amount' => 200000,
            'discount_amount' => 0,
            'tax_amount' => 22000,
            'service_charge_amount' => 10000,
            'order_amount' => 232000,
            'cash_received' => 232000,
            'order_change' => 0,
            'order_status' => OrderStatus::Paid->value,
            'payment_method' => PaymentMethod::Qris->value,
            'idempotency_key' => (string) Str::uuid(),
            'print_count' => 1,
            'created_at' => Carbon::parse($businessDate.' 12:00:00'),
        ]);

        $pay2 = Payment::forceCreate([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'device_id' => $this->deviceId,
            'shift_id' => $shift->id,
            'order_id' => $order2->id,
            'payment_method' => 'qris',
            'amount' => 232000,
            'status' => 'captured',
            'processed_by' => $this->userId,
            'idempotency_key' => 'pay-par-2',
            'created_at' => Carbon::parse($businessDate.' 12:00:00'),
        ]);

        // 4. Ada refund sebagian pada Order 1 sebesar 20.000 (status = completed)
        PaymentRefund::forceCreate([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'order_id' => $order1->id,
            'payment_id' => $pay1->id,
            'device_id' => $this->deviceId,
            'shift_id' => $shift->id,
            'order_return_id' => null,
            'idempotency_key' => 'ref-par-1',
            'refund_number' => 'REF-001',
            'amount' => 20000,
            'currency' => 'IDR',
            'original_payment_method' => 'cash',
            'refund_method' => 'cash',
            'request_status' => PaymentRefund::REQUEST_COMPLETED,
            'financial_status' => PaymentRefund::FINANCIAL_SETTLED,
            'provider_status' => PaymentRefund::PROVIDER_NOT_APPLICABLE,
            'reason' => 'Item rusak kemasan',
            'requested_by_user_id' => $this->userId,
            'approved_by_user_id' => $this->userId,
            'settled_at' => Carbon::parse($businessDate.' 14:00:00'),
            'created_at' => Carbon::parse($businessDate.' 14:00:00'),
        ]);

        // 5. Eksekusi EOD Settlement
        $settlement = $this->reportService->closeEndOfDay(
            $this->tenantId,
            $this->storeId,
            $businessDate,
            $this->userId,
            $this->managerPin,
            'Penutupan EOD Operasional Sukses'
        );

        $this->assertInstanceOf(EodSettlement::class, $settlement);
        $this->assertEquals('closed', $settlement->status);

        // 6. Ambil ringkasan dari ReportService
        $summary = $this->reportService->getDetailedSalesSummary(
            Carbon::parse($businessDate)->startOfDay(),
            Carbon::parse($businessDate)->endOfDay(),
            $this->storeId
        );

        // 7. Hitung manual dari tabel sumber
        $expectedGrossSales = (float) Order::where('tenant_id', $this->tenantId)
            ->where('store_id', $this->storeId)
            ->whereDate('order_date', $businessDate)
            ->where('order_status', OrderStatus::Paid->value)
            ->sum('subtotal_amount'); // 100.000 + 200.000 = 300.000

        $expectedDiscounts = (float) Order::where('tenant_id', $this->tenantId)
            ->where('store_id', $this->storeId)
            ->whereDate('order_date', $businessDate)
            ->where('order_status', OrderStatus::Paid->value)
            ->sum('discount_amount'); // 10.000

        $expectedTax = (float) Order::where('tenant_id', $this->tenantId)
            ->where('store_id', $this->storeId)
            ->whereDate('order_date', $businessDate)
            ->where('order_status', OrderStatus::Paid->value)
            ->sum('tax_amount'); // 11.000 + 22.000 = 33.000

        $expectedServiceCharge = (float) Order::where('tenant_id', $this->tenantId)
            ->where('store_id', $this->storeId)
            ->whereDate('order_date', $businessDate)
            ->where('order_status', OrderStatus::Paid->value)
            ->sum('service_charge_amount'); // 10.000

        $expectedRefunds = (float) PaymentRefund::where('tenant_id', $this->tenantId)
            ->where('store_id', $this->storeId)
            ->whereDate('created_at', $businessDate)
            ->where('request_status', 'completed')
            ->sum('amount'); // 20.000

        // net_sales = gross_sales - total_discounts - total_returns_refunds
        $expectedNetSales = $expectedGrossSales - $expectedDiscounts - $expectedRefunds;
        // 300.000 - 10.000 - 20.000 = 270.000

        // 8. Verifikasi Paritas 100% (AC-5.3)
        $this->assertEquals($expectedGrossSales, (float) $settlement->gross_sales);
        $this->assertEquals($expectedGrossSales, (float) $summary['gross_sales']);

        $this->assertEquals($expectedDiscounts, (float) $settlement->total_discounts);
        $this->assertEquals($expectedDiscounts, (float) $summary['total_discounts']);

        $this->assertEquals($expectedTax, (float) $settlement->total_tax);
        $this->assertEquals($expectedTax, (float) $summary['total_tax']);

        $this->assertEquals($expectedServiceCharge, (float) $settlement->total_service_charge);
        $this->assertEquals($expectedServiceCharge, (float) $summary['total_service_charge']);

        $this->assertEquals($expectedRefunds, (float) $settlement->total_returns_refunds);
        $this->assertEquals($expectedRefunds, (float) $summary['total_returns_refunds']);

        $this->assertEquals($expectedNetSales, (float) $settlement->net_sales);
        $this->assertEquals($expectedNetSales, (float) $summary['net_sales']);

        $this->assertEquals(2, $summary['paid_orders_count']);
    }

    /**
     * Memastikan database trigger melindungi EodSettlement tertutup dari modifikasi atau penghapusan.
     */
    public function test_eod_immutability_trigger_prevents_updates_and_deletes(): void
    {
        $businessDate = Carbon::now()->toDateString();

        Shift::create([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'user_id' => $this->userId,
            'device_id' => $this->deviceId,
            'opened_at' => Carbon::parse($businessDate.' 08:00:00'),
            'closed_at' => Carbon::parse($businessDate.' 16:00:00'),
            'status' => 'closed',
            'opening_balance' => 50000,
            'closing_balance' => 50000,
            'expected_cash' => 50000,
            'cash_difference' => 0,
        ]);

        $settlement = $this->reportService->closeEndOfDay(
            $this->tenantId,
            $this->storeId,
            $businessDate,
            $this->userId,
            $this->managerPin
        );

        $this->assertEquals('closed', $settlement->status);

        // Coba manipulasi gross_sales via direct query
        $updateBlocked = false;
        try {
            DB::table('eod_settlements')
                ->where('id', $settlement->id)
                ->update(['gross_sales' => 99999999]);
        } catch (QueryException $e) {
            $updateBlocked = true;
            $this->assertStringContainsString('EOD settlement yang sudah berstatus closed tidak dapat diubah (immutable)', $e->getMessage());
        }

        $this->assertTrue($updateBlocked, 'Trigger trg_eod_settlements_immutable_update harus mencegah perubahan pada settlement yang sudah closed.');

        // Coba hapus via direct query
        $deleteBlocked = false;
        try {
            DB::table('eod_settlements')
                ->where('id', $settlement->id)
                ->delete();
        } catch (QueryException $e) {
            $deleteBlocked = true;
            $this->assertStringContainsString('Penghapusan data EOD settlement dilarang (immutable audit record)', $e->getMessage());
        }

        $this->assertTrue($deleteBlocked, 'Trigger trg_eod_settlements_no_delete harus mencegah penghapusan pada settlement.');
    }
}
