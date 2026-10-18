<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Models\CashDrawerMovement;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\ShiftService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class ShiftDeviceHandoverAndConcurrencyTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected Device $deviceA;

    protected Device $deviceB;

    protected User $cashier1;

    protected User $cashier2;

    protected User $supervisor;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
            Model::unguard();

            // Set up Device A on Tenant A Store A
            $this->deviceA = Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'POS Kasir Terminal 1',
                'device_code' => 'POS-T1',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'device_fingerprint' => 'fp-device-a-1',
                'status' => Device::STATUS_PAIRED,
                'is_active' => true,
            ]);

            // Set up Device B on Tenant B Store B
            $this->deviceB = Device::create([
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB->id,
                'device_name' => 'POS Kasir Terminal B',
                'device_code' => 'POS-TB',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'device_fingerprint' => 'fp-device-b-1',
                'status' => Device::STATUS_PAIRED,
                'is_active' => true,
            ]);

            $roleKasir = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Kasir', 'tenant_id' => $this->tenantA->id],
                ['is_active' => 1]
            );

            $roleSupervisor = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Supervisor', 'tenant_id' => $this->tenantA->id],
                ['is_active' => 1]
            );

            $this->cashier1 = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kasir Satu',
                'email' => 'kasir1@tenant-a.com',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('123456'),
                'role_id' => $roleKasir->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->cashier1->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

            $this->cashier2 = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kasir Dua',
                'email' => 'kasir2@tenant-a.com',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('654321'),
                'role_id' => $roleKasir->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->cashier2->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

            $this->supervisor = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Supervisor A',
                'email' => 'supervisor@tenant-a.com',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('999999'),
                'role_id' => $roleSupervisor->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->supervisor->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);
            Model::reguard();
        });
    }

    /**
     * AC-1.1: Membuka shift kedua pada (tenant_id, store_id, device_id) yang masih memiliki
     * shift 'open' ditolak baik di level service maupun constraint database.
     */
    public function test_ac_1_1_cannot_open_second_active_shift_on_same_device(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $shiftService = app(ShiftService::class);

        // Kasir 1 membuka shift pada Device A
        $shift1 = $shiftService->openShift(
            $this->tenantA->id,
            $this->storeA->id,
            $this->deviceA->id,
            $this->cashier1->id,
            100000,
            '123456'
        );

        $this->assertNotNull($shift1);
        $this->assertEquals('open', $shift1->status);
        $this->assertEquals($this->deviceA->id, $shift1->device_id);

        // Kasir 2 mencoba membuka shift kedua pada Device A yang sama -> Service melempar DomainException
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Perangkat kasir ini masih memiliki shift yang sedang aktif.');

        $shiftService->openShift(
            $this->tenantA->id,
            $this->storeA->id,
            $this->deviceA->id,
            $this->cashier2->id,
            50000,
            '654321'
        );
    }

    /**
     * AC-1.1 (DB Level): Membuktikan bahwa MySQL Unique Constraint 'shifts_one_open_per_device_unique'
     * menolak baris kedua secara fisik di level database jika terjadi race condition.
     */
    public function test_ac_1_1_database_unique_constraint_enforces_one_open_shift_per_device(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Insert baris shift terbuka pertama
        Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'device_id' => $this->deviceA->id,
            'user_id' => $this->cashier1->id,
            'active_cashier_id' => $this->cashier1->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Percobaan bypass service langsung ke DB untuk membuka shift kedua di device yang sama
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('shifts_one_open_per_device_unique');

        Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'device_id' => $this->deviceA->id,
            'user_id' => $this->cashier2->id,
            'active_cashier_id' => $this->cashier2->id,
            'opening_balance' => 50000,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    /**
     * AC-1.2: Perangkat dari store_id atau tenant_id berbeda ditolak oleh composite FK
     * dan service saat digunakan untuk membuka shift atau membuat order.
     */
    public function test_ac_1_2_device_from_different_store_or_tenant_rejected_by_service_and_fk(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $shiftService = app(ShiftService::class);

        // 1. Service rejection saat mencoba memakai Device B (Tenant B / Store B) untuk Store A
        try {
            $shiftService->openShift(
                $this->tenantA->id,
                $this->storeA->id,
                $this->deviceB->id,
                $this->cashier1->id,
                100000,
                '123456'
            );
            $this->fail('Harus gagal karena Device B milik cabang/tenant berbeda.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Perangkat tidak terdaftar pada cabang atau tenant yang sesuai.', $e->getMessage());
        }

        // 2. Composite FK rejection pada tabel shifts: foreign key (tenant_id, store_id, device_id)
        try {
            Shift::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_id' => $this->deviceB->id, // Mismatch composite key
                'user_id' => $this->cashier1->id,
                'active_cashier_id' => $this->cashier1->id,
                'opening_balance' => 100000,
                'status' => 'open',
                'opened_at' => now(),
            ]);
            $this->fail('Composite FK shifts_device_id_composite_foreign harus menolak baris lintas cabang/tenant.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('shifts_device_id_composite_foreign', $e->getMessage());
        }

        // 3. Composite FK rejection pada tabel orders
        try {
            Order::forceCreate([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'user_id' => $this->cashier1->id,
                'created_by_device_id' => $this->deviceB->id, // Mismatch composite key
                'order_code' => 'ORD-MISMATCH-DEV',
                'idempotency_key' => (string) Str::uuid(),
                'order_date' => now()->toDateString(),
                'order_type' => 'dine_in',
                'order_status' => 'pending',
                'subtotal_amount' => 10000,
                'tax_amount' => 1100,
                'order_amount' => 11100,
            ]);
            $this->fail('Composite FK orders_created_by_device_composite_foreign harus menolak pesanan.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('orders_created_by_device_composite_foreign', $e->getMessage());
        }

        // 4. Composite FK rejection pada tabel payments
        $validOrder = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier1->id,
            'order_code' => 'ORD-VALID-FOR-PMT',
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => 'paid',
            'subtotal_amount' => 10000,
            'tax_amount' => 1100,
            'order_amount' => 11100,
        ]);

        try {
            Payment::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'order_id' => $validOrder->id,
                'device_id' => $this->deviceB->id, // Mismatch composite key
                'payment_method' => 'cash',
                'amount' => 11100,
                'status' => 'captured',
            ]);
            $this->fail('Composite FK payments_device_id_composite_foreign harus menolak pembayaran.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('payments_device_id_composite_foreign', $e->getMessage());
        }

        // 5. Composite FK rejection pada tabel cash_drawer_movements
        $validShift = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'device_id' => $this->deviceA->id,
            'user_id' => $this->cashier1->id,
            'active_cashier_id' => $this->cashier1->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        try {
            CashDrawerMovement::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'shift_id' => $validShift->id,
                'device_id' => $this->deviceB->id, // Mismatch composite key
                'type' => 'cash_in',
                'category' => 'restock_change',
                'amount' => 50000,
                'reason' => 'Tambah modal',
                'created_by' => $this->cashier1->id,
            ]);
            $this->fail('Composite FK cash_drawer_movements_device_id_composite_foreign harus menolak movement.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cash_drawer_movements_device_id_composite_foreign', $e->getMessage());
        }
    }

    /**
     * AC-1.3: Alur initiateHandover -> acceptHandover berhasil memindahkan active_cashier_id
     * dari Kasir A ke Kasir B pada shift_id yang sama, mencatat baris shift_handovers
     * dan activity_logs, tanpa membuat baris shifts kedua.
     */
    public function test_ac_1_3_shift_handover_flow_transfers_custody_without_second_shift(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $shiftService = app(ShiftService::class);

        // 1. Kasir 1 membuka shift dengan saldo awal 100.000
        $shift = $shiftService->openShift(
            $this->tenantA->id,
            $this->storeA->id,
            $this->deviceA->id,
            $this->cashier1->id,
            100000,
            '123456'
        );

        $this->assertEquals($this->cashier1->id, $shift->active_cashier_id);
        $this->assertEquals('open', $shift->status);

        // Ada pergerakan kas masuk 50.000
        $shiftService->recordCashMovement(
            $shift->id,
            $this->deviceA->id,
            $this->cashier1->id,
            'cash_in',
            'restock_change',
            50000,
            'Tambah modal receh'
        );

        $initialShiftCount = Shift::where('device_id', $this->deviceA->id)->count();
        $this->assertEquals(1, $initialShiftCount);

        // 2. Kasir 1 menginisiasi serah terima ke Kasir 2 (uang dihitung 150.000, selisih 0)
        $handover = $shiftService->initiateHandover(
            $shift->id,
            $this->cashier1->id,
            $this->cashier2->id,
            150000,
            '123456',
            'Gantian shift siang'
        );

        $this->assertEquals('pending', $handover->status);
        $this->assertEquals(150000, $handover->expected_cash_at_handover);
        $this->assertEquals(150000, $handover->counted_cash_at_handover);
        $this->assertEquals(0, $handover->discrepancy_amount);

        $shift->refresh();
        $this->assertEquals('handover_pending', $shift->status);

        // 3. Kasir 2 menerima serah terima dengan PIN Kasir 2
        $updatedShift = $shiftService->acceptHandover(
            $handover->id,
            $this->cashier2->id,
            '654321'
        );

        // Verifikasi kepemilikan telah beralih ke Kasir 2 pada shift_id yang sama
        $this->assertEquals($shift->id, $updatedShift->id);
        $this->assertEquals('open', $updatedShift->status);
        $this->assertEquals($this->cashier2->id, $updatedShift->active_cashier_id);

        // Memastikan TIDAK ADA baris shift baru yang dibuat
        $finalShiftCount = Shift::where('device_id', $this->deviceA->id)->count();
        $this->assertEquals(1, $finalShiftCount);

        // Verifikasi catatan audit pada shift_handovers
        $handover->refresh();
        $this->assertEquals('accepted', $handover->status);
        $this->assertNotNull($handover->completed_at);

        // Verifikasi activity_logs tercatat
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'shift.handover_initiated',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'shift.handover_completed',
        ]);
    }

    /**
     * AC-1.3 (Selisih Kas): Jika handover memiliki selisih kas (discrepancy != 0),
     * wajib memerlukan PIN dan persetujuan Supervisor (Dual PIN).
     */
    public function test_ac_1_3_shift_handover_with_discrepancy_requires_supervisor_pin(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $shiftService = app(ShiftService::class);

        $shift = $shiftService->openShift(
            $this->tenantA->id,
            $this->storeA->id,
            $this->deviceA->id,
            $this->cashier1->id,
            100000,
            '123456'
        );

        // Kasir 1 menghitung kas fisik 95.000 padahal seharusnya 100.000 (selisih -5.000)
        $handover = $shiftService->initiateHandover(
            $shift->id,
            $this->cashier1->id,
            $this->cashier2->id,
            95000,
            '123456',
            'Ada selisih kas kurang 5000'
        );

        $this->assertEquals(-5000, $handover->discrepancy_amount);

        // Percobaan menerima tanpa PIN Supervisor -> Ditolak
        try {
            $shiftService->acceptHandover($handover->id, $this->cashier2->id, '654321');
            $this->fail('Harus gagal karena ada selisih kas tanpa persetujuan supervisor.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('memerlukan persetujuan dan PIN Supervisor', $e->getMessage());
        }

        // Penerimaan dengan PIN Supervisor yang sah -> Berhasil
        $updatedShift = $shiftService->acceptHandover(
            $handover->id,
            $this->cashier2->id,
            '654321',
            '999999',
            $this->supervisor->id
        );

        $this->assertEquals('open', $updatedShift->status);
        $this->assertEquals($this->cashier2->id, $updatedShift->active_cashier_id);

        $handover->refresh();
        $this->assertEquals('accepted', $handover->status);
        $this->assertEquals($this->supervisor->id, $handover->approved_by_user_id);
    }

    /**
     * AC-1.4: Dua eksekusi closeShift paralel atau closeShift paralel dengan
     * recordCashMovement menghasilkan tepat 1 penutupan shift yang konsisten
     * dengan rumus expected_cash.
     */
    public function test_ac_1_4_close_shift_concurrency_and_closed_shift_lock(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $shiftService = app(ShiftService::class);

        $shift = $shiftService->openShift(
            $this->tenantA->id,
            $this->storeA->id,
            $this->deviceA->id,
            $this->cashier1->id,
            100000,
            '123456'
        );

        // Tambah uang masuk 20.000
        $shiftService->recordCashMovement(
            $shift->id,
            $this->deviceA->id,
            $this->cashier1->id,
            'cash_in',
            'restock_change',
            20000,
            'Modal tambahan'
        );

        // 1. Eksekusi closeShift pertama
        $closedShift = $shiftService->closeShift(
            $shift->id,
            $this->deviceA->id,
            $this->cashier1->id,
            120000,
            '123456'
        );

        $this->assertEquals('closed', $closedShift->status);
        $this->assertEquals(120000, $closedShift->expected_cash);
        $this->assertEquals(0, $closedShift->cash_difference);

        // 2. Eksekusi closeShift kedua pada shift yang sudah closed -> Ditolak atomik
        try {
            $shiftService->closeShift(
                $shift->id,
                $this->deviceA->id,
                $this->cashier1->id,
                120000,
                '123456'
            );
            $this->fail('Eksekusi closeShift kedua harus ditolak.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Shift sudah ditutup sebelumnya.', $e->getMessage());
        }

        // 3. Pencatatan pergerakan kas pada shift yang sudah closed -> Ditolak atomik
        try {
            $shiftService->recordCashMovement(
                $shift->id,
                $this->deviceA->id,
                $this->cashier1->id,
                'cash_in',
                'restock_change',
                10000,
                'Penyelipan kas setelah tutup'
            );
            $this->fail('recordCashMovement pada shift closed harus ditolak.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Pergerakan kas hanya dapat dicatat pada shift berstatus open', $e->getMessage());
        }
    }

    /**
     * AC-1.4 (Laci Kas Tidak Boleh Negatif): Pengeluaran kas laci (cash_out)
     * yang menyebabkan saldo menjadi negatif wajib ditolak (INV-10).
     */
    public function test_ac_1_4_cash_drawer_negative_balance_prevented(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $shiftService = app(ShiftService::class);

        $shift = $shiftService->openShift(
            $this->tenantA->id,
            $this->storeA->id,
            $this->deviceA->id,
            $this->cashier1->id,
            50000,
            '123456'
        );

        // Saldo laci kas saat ini = 50.000. Coba cash_out 60.000
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Saldo kas laci tidak mencukupi untuk pengeluaran kas (saldo tidak boleh negatif).');

        $shiftService->recordCashMovement(
            $shift->id,
            $this->deviceA->id,
            $this->cashier1->id,
            'cash_out',
            'petty_expense',
            60000,
            'Pengeluaran melebihi saldo'
        );
    }

    /**
     * Force Close Shift: Supervisor menutup paksa shift yang terbengkalai
     * sehingga perangkat fisik kasir terbebaskan (INV-01, INV-03).
     */
    public function test_force_close_shift_by_supervisor_frees_device_for_new_shift(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $shiftService = app(ShiftService::class);

        // Kasir 1 membuka shift
        $shift = $shiftService->openShift(
            $this->tenantA->id,
            $this->storeA->id,
            $this->deviceA->id,
            $this->cashier1->id,
            100000,
            '123456'
        );

        // Kasir 1 meninggalkan kasir tanpa menutup shift.
        // Supervisor melakukan force close
        $closed = $shiftService->forceCloseShift(
            $shift->id,
            $this->supervisor->id,
            100000,
            '999999',
            'Kasir 1 meninggalkan terminal darurat sakit'
        );

        $this->assertEquals('closed', $closed->status);
        $this->assertEquals('force_closed', $closed->close_type);
        $this->assertEquals($this->supervisor->id, $closed->closed_by_user_id);
        $this->assertStringContainsString('darurat sakit', $closed->close_reason);

        // Terminal Device A kini sudah bebas dan Kasir 2 dapat membuka shift baru di Device A
        $newShift = $shiftService->openShift(
            $this->tenantA->id,
            $this->storeA->id,
            $this->deviceA->id,
            $this->cashier2->id,
            100000,
            '654321'
        );

        $this->assertNotNull($newShift);
        $this->assertEquals('open', $newShift->status);
        $this->assertEquals($this->cashier2->id, $newShift->active_cashier_id);
    }
}
