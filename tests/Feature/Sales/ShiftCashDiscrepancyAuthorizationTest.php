<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Device;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Services\ShiftManagementService;
use App\Modules\Sales\Services\ShiftService;
use App\Services\CashierPinService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftCashDiscrepancyAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private Store $storeB;

    private User $cashierA;

    private User $supervisorA;

    private User $supervisorB;

    private Device $deviceA;

    private ShiftManagementService $shiftManagementService;

    private ShiftService $shiftService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            // 1. Tenant A setup
            $this->tenantA = Tenant::create([
                'name' => 'Strict Cash Tenant A',
                'slug' => 'strict-cash-a-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->storeA = Store::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Store A Central',
                'code' => 'STR-A-'.uniqid(),
                'is_active' => true,
            ]);

            $roleCashier = Role::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kasir',
                'permissions' => ['pos.order', 'pos.shift'],
            ]);

            $roleSupervisor = Role::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Supervisor',
                'permissions' => ['*'],
            ]);

            $this->cashierA = User::factory()->create([
                'tenant_id' => $this->tenantA->id,
                'role_id' => $roleCashier->id,
                'password' => Hash::make('password123'),
            ]);

            $this->supervisorA = User::factory()->create([
                'tenant_id' => $this->tenantA->id,
                'role_id' => $roleSupervisor->id,
                'password' => Hash::make('password123'),
            ]);

            DB::table('user_stores')->insert([
                ['tenant_id' => $this->tenantA->id, 'user_id' => $this->cashierA->id, 'store_id' => $this->storeA->id],
                ['tenant_id' => $this->tenantA->id, 'user_id' => $this->supervisorA->id, 'store_id' => $this->storeA->id],
            ]);

            $pinService = app(CashierPinService::class);
            $pinService->setPin($this->cashierA, '111111');
            $pinService->setPin($this->supervisorA, '999999');

            $this->deviceA = Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'Terminal A01',
                'device_code' => 'TRM-A01-'.uniqid(),
                'device_type' => 'pos_terminal',
                'device_secret' => 'secret-a01',
                'is_active' => true,
            ]);

            // 2. Tenant B setup (Cross-Tenant Guard)
            $this->tenantB = Tenant::create([
                'name' => 'Strict Cash Tenant B',
                'slug' => 'strict-cash-b-'.uniqid(),
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->storeB = Store::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Store B Central',
                'code' => 'STR-B-'.uniqid(),
                'is_active' => true,
            ]);

            $roleSupervisorB = Role::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Supervisor',
                'permissions' => ['*'],
            ]);

            $this->supervisorB = User::factory()->create([
                'tenant_id' => $this->tenantB->id,
                'role_id' => $roleSupervisorB->id,
                'password' => Hash::make('password123'),
            ]);

            DB::table('user_stores')->insert([
                ['tenant_id' => $this->tenantB->id, 'user_id' => $this->supervisorB->id, 'store_id' => $this->storeB->id],
            ]);

            $pinService->setPin($this->supervisorB, '888888');
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $this->shiftManagementService = app(ShiftManagementService::class);
        $this->shiftService = app(ShiftService::class);
    }

    /**
     * DEC-005 / TR-005 Test 1:
     * Pengeluaran kas melebihi saldo laci kas wajib ditolak jika tanpa otorisasi PIN supervisor.
     */
    public function test_cash_out_exceeding_drawer_balance_is_rejected_without_supervisor_pin(): void
    {
        $shift = $this->shiftManagementService->openShift(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            userId: $this->cashierA->id,
            openingCash: 50000,
            deviceId: $this->deviceA->id,
            cashierPin: '111111'
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Drawer cash balance is insufficient for this cash out (balance cannot be negative without supervisor PIN authorization).');

        // Kasir mencoba cash_out Rp 75.000 sementara saldo laci kas hanya Rp 50.000 tanpa PIN supervisor
        $this->shiftManagementService->recordCashMovement(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            shiftId: $shift->id,
            userId: $this->cashierA->id,
            type: 'cash_out',
            amount: 75000,
            reason: 'Pengeluaran kas kecil darurat tanpa supervisor',
            supervisorPin: null
        );
    }

    /**
     * DEC-005 / TR-005 Test 2:
     * Pengeluaran kas melebihi saldo laci kas diizinkan jika disertai otorisasi PIN supervisor valid.
     */
    public function test_cash_out_exceeding_drawer_balance_is_allowed_with_valid_supervisor_pin(): void
    {
        $shift = $this->shiftManagementService->openShift(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            userId: $this->cashierA->id,
            openingCash: 50000,
            deviceId: $this->deviceA->id,
            cashierPin: '111111'
        );

        $movement = $this->shiftManagementService->recordCashMovement(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            shiftId: $shift->id,
            userId: $this->cashierA->id,
            type: 'cash_out',
            amount: 75000,
            reason: 'Penyesuaian kas resmi diotorisasi supervisor',
            supervisorPin: '999999',
            supervisorUserId: $this->supervisorA->id
        );

        $this->assertNotNull($movement);
        $this->assertEquals(75000, $movement->amount);
        $this->assertEquals('cash_out', is_object($movement->type) ? $movement->type->value : $movement->type);
        $this->assertEquals($this->supervisorA->id, $movement->approved_by);
    }

    /**
     * DEC-005 / TR-005 Test 3:
     * Penutupan shift dengan selisih kas fisik melebihi toleransi wajib membutuhkan PIN supervisor.
     */
    public function test_close_shift_with_discrepancy_exceeding_tolerance_requires_supervisor_pin(): void
    {
        $shift = $this->shiftManagementService->openShift(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            userId: $this->cashierA->id,
            openingCash: 100000,
            deviceId: $this->deviceA->id,
            cashierPin: '111111'
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Supervisor PIN authorization is required to close this shift.');

        // Saldo sistem 100.000, kas fisik dihitung kasir 70.000 (selisih 30.000 > toleransi 10.000) tanpa PIN
        $this->shiftManagementService->closeShiftWithBlindCount(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            shiftId: $shift->id,
            userId: $this->cashierA->id,
            countedCash: 70000,
            notes: 'Uang fisik kurang 30rb',
            supervisorPin: null,
            tolerance: 10000
        );
    }

    /**
     * DEC-005 / TR-005 Test 4:
     * Penutupan shift dengan selisih kas yang disetujui supervisor mencatat variance_approved_by dan variance_reason.
     */
    public function test_close_shift_with_discrepancy_records_variance_approved_by_and_reason(): void
    {
        $shift = $this->shiftManagementService->openShift(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            userId: $this->cashierA->id,
            openingCash: 100000,
            deviceId: $this->deviceA->id,
            cashierPin: '111111'
        );

        $result = $this->shiftManagementService->closeShiftWithBlindCount(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            shiftId: $shift->id,
            userId: $this->cashierA->id,
            countedCash: 70000,
            notes: 'Disetujui selisih karena kembalian salah',
            supervisorPin: '999999',
            tolerance: 10000
        );

        /** @var Shift $closedShift */
        $closedShift = $result['shift']->fresh();

        $this->assertEquals('closed', $closedShift->status);
        $this->assertEquals(70000, $closedShift->closing_balance);
        $this->assertEquals(100000, $closedShift->expected_cash);
        $this->assertEquals(-30000, $closedShift->cash_difference);
        $this->assertEquals($this->supervisorA->id, $closedShift->variance_approved_by);
        $this->assertEquals('Disetujui selisih karena kembalian salah', $closedShift->variance_reason);
        $this->assertNotNull($closedShift->varianceApprover);
        $this->assertEquals($this->supervisorA->id, $closedShift->varianceApprover->id);
    }

    /**
     * DEC-005 / TR-005 Test 5:
     * Penutupan shift dengan selisih kas dalam batas toleransi dapat ditutup tanpa PIN supervisor.
     */
    public function test_close_shift_within_tolerance_closes_without_supervisor_pin(): void
    {
        $shift = $this->shiftManagementService->openShift(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            userId: $this->cashierA->id,
            openingCash: 100000,
            deviceId: $this->deviceA->id,
            cashierPin: '111111'
        );

        // Selisih 5.000 (<= toleransi 10.000)
        $result = $this->shiftManagementService->closeShiftWithBlindCount(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            shiftId: $shift->id,
            userId: $this->cashierA->id,
            countedCash: 95000,
            notes: 'Selisih kecil 5rb',
            supervisorPin: null,
            tolerance: 10000
        );

        /** @var Shift $closedShift */
        $closedShift = $result['shift']->fresh();

        $this->assertEquals('closed', $closedShift->status);
        $this->assertEquals(95000, $closedShift->closing_balance);
        $this->assertEquals(100000, $closedShift->expected_cash);
        $this->assertEquals(-5000, $closedShift->cash_difference);
        $this->assertNull($closedShift->variance_approved_by);
    }

    /**
     * DEC-005 / TR-005 Test 6:
     * PIN supervisor dari tenant lain wajib ditolak secara fail-closed (Multi-Tenancy Guard).
     */
    public function test_cross_tenant_supervisor_pin_is_strictly_rejected(): void
    {
        $shift = $this->shiftManagementService->openShift(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            userId: $this->cashierA->id,
            openingCash: 100000,
            deviceId: $this->deviceA->id,
            cashierPin: '111111'
        );

        $this->expectException(ValidationException::class);

        // Mencoba menutup shift Tenant A menggunakan PIN Supervisor Tenant B ('888888')
        $this->shiftManagementService->closeShiftWithBlindCount(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            shiftId: $shift->id,
            userId: $this->cashierA->id,
            countedCash: 50000,
            notes: 'Percobaan otorisasi lintas tenant',
            supervisorPin: '888888',
            tolerance: 10000
        );
    }
}
