<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashierPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class CashierPinServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private function createCashierFixture(): array
    {
        $tenant = Tenant::create([
            'name' => 'Kedai PIN Test',
            'slug' => 'kedai-pin-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        $role = new Role;
        $role->tenant_id = $tenant->id;
        $role->name = 'Kasir';
        $role->permissions = ['pos.order.create'];
        $role->is_active = true;
        $role->save();

        $kasir = new User;
        $kasir->tenant_id = $tenant->id;
        $kasir->role_id = $role->id;
        $kasir->name = 'Kasir PIN';
        $kasir->email = 'kasir.pin@example.test';
        $kasir->email_normalized = 'kasir.pin@example.test';
        $kasir->password_hash = bcrypt('Password123!');
        $kasir->pin_hash = null;
        $kasir->is_active = true;
        $kasir->email_verified_at = now();
        $kasir->save();

        return [$tenant, $kasir];
    }

    public function test_can_set_and_hash_valid_cashier_pin(): void
    {
        [$tenant, $kasir] = $this->createCashierFixture();
        $service = app(CashierPinService::class);

        $this->assertFalse($service->hasPin($kasir));

        $service->setPin($kasir, '123456');

        $kasir->refresh();
        $this->assertTrue($service->hasPin($kasir));
        $this->assertNotEquals('123456', $kasir->pin_hash);
        $this->assertTrue(Hash::check('123456', $kasir->pin_hash));
    }

    public function test_rejects_invalid_pin_format(): void
    {
        [$tenant, $kasir] = $this->createCashierFixture();
        $service = app(CashierPinService::class);

        // Terlalu pendek (3 digit)
        try {
            $service->setPin($kasir, '123');
            $this->fail('Seharusnya gagal: PIN 3 digit terlalu pendek.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('4-6 digit', $e->getMessage());
        }

        // Terlalu panjang (7 digit)
        try {
            $service->setPin($kasir, '1234567');
            $this->fail('Seharusnya gagal: PIN 7 digit terlalu panjang.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('4-6 digit', $e->getMessage());
        }

        // Mengandung huruf
        try {
            $service->setPin($kasir, '123a');
            $this->fail('Seharusnya gagal: PIN mengandung huruf.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('4-6 digit', $e->getMessage());
        }
    }

    public function test_verify_pin_success_and_failure(): void
    {
        [$tenant, $kasir] = $this->createCashierFixture();
        $service = app(CashierPinService::class);

        $service->setPin($kasir, '654321');
        $kasir->refresh();

        // 1. PIN Benar
        $this->assertTrue($service->verifyPin($kasir, '654321'));

        // 2. PIN Salah
        $this->assertFalse($service->verifyPin($kasir, '000000'));
    }

    public function test_brute_force_lockout_after_max_attempts(): void
    {
        [$tenant, $kasir] = $this->createCashierFixture();
        $service = app(CashierPinService::class);

        $service->setPin($kasir, '1234');
        $kasir->refresh();

        // Lakukan 4 percobaan salah berturut-turut (belum lockout)
        for ($i = 0; $i < 4; $i++) {
            $this->assertFalse($service->verifyPin($kasir, '9999'));
            $this->assertFalse($service->isLockedOut($kasir));
        }

        // Percobaan ke-5 yang salah -> langsung kena lockout dan melempar ValidationException
        $lockedOut = false;
        try {
            $service->verifyPin($kasir, '9999');
        } catch (ValidationException $e) {
            $lockedOut = true;
            $this->assertArrayHasKey('pin', $e->errors());
            $this->assertStringContainsString('terkunci', $e->errors()['pin'][0]);
        }
        $this->assertTrue($lockedOut, 'Percobaan ke-5 seharusnya melempar ValidationException.');
        $this->assertTrue($service->isLockedOut($kasir));

        // Percobaan berikutnya meskipun PIN BENAR tetap ditolak karena akun sedang terkunci
        $blockedEvenWithCorrectPin = false;
        try {
            $service->verifyPin($kasir, '1234');
        } catch (ValidationException $e) {
            $blockedEvenWithCorrectPin = true;
        }
        $this->assertTrue($blockedEvenWithCorrectPin);

        // Reset lockout oleh manager/sistem
        $service->resetPinLockout($kasir);
        $this->assertFalse($service->isLockedOut($kasir));

        // Sekarang PIN benar kembali diterima
        $this->assertTrue($service->verifyPin($kasir, '1234'));
    }

    public function test_pin_lockout_records_verified_shift_context_in_audit_log(): void
    {
        [$tenant, $kasir] = $this->createCashierFixture();
        $service = app(CashierPinService::class);

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang PIN',
            'code' => 'PIN-01',
            'is_initial' => true,
            'is_active' => true,
        ]);

        $shift = Shift::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'user_id' => $kasir->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $service->setPin($kasir, '1122');
        $kasir->refresh();

        // Lakukan 5 percobaan salah dengan menyertakan shift context
        for ($i = 0; $i < 4; $i++) {
            $service->verifyPin($kasir, '0000', $shift);
        }

        try {
            $service->verifyPin($kasir, '0000', $shift);
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertTrue($service->isLockedOut($kasir));

        // Audit log mencatat activity dengan shift context
        $log = DB::table('activity_logs')
            ->where('tenant_id', $tenant->id)
            ->where('action', 'cashier.pin_locked_out')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($store->id, $log->store_id);
        $this->assertEquals($kasir->id, $log->user_id);
        $this->assertStringContainsString("Shift #{$shift->id}", $log->description);

        $meta = json_decode($log->metadata, true);
        $this->assertEquals($shift->id, $meta['shift_id']);
        $this->assertEquals('open', $meta['shift_status']);
        $this->assertEquals($store->id, $meta['shift_store_id']);

        // Pastikan PIN tidak pernah ada di log
        $rawLog = json_encode($log);
        $this->assertStringNotContainsString('1122', $rawLog);
        $this->assertStringNotContainsString('0000', $rawLog);
    }

    public function test_pin_lockout_rejects_unauthorized_shift_context_from_another_user(): void
    {
        [$tenant, $kasir] = $this->createCashierFixture();
        $service = app(CashierPinService::class);

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang PIN 2',
            'code' => 'PIN-02',
            'is_initial' => true,
            'is_active' => true,
        ]);

        // Buat shift milik user lain
        $otherKasir = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $otherShift = Shift::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'user_id' => $otherKasir->id,
            'opening_balance' => 50000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $service->setPin($kasir, '4321');
        $kasir->refresh();

        for ($i = 0; $i < 4; $i++) {
            $service->verifyPin($kasir, '9999', $otherShift);
        }

        try {
            $service->verifyPin($kasir, '9999', $otherShift);
        } catch (ValidationException $e) {
            // expected
        }

        $log = DB::table('activity_logs')
            ->where('tenant_id', $tenant->id)
            ->where('action', 'cashier.pin_locked_out')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $meta = json_decode($log->metadata, true);
        // Shift tidak diasosiasikan sebagai shift valid karena mismatch user
        $this->assertArrayNotHasKey('shift_id', $meta);
        $this->assertEquals('mismatch_tenant_or_user', $meta['shift_validation']);
        $this->assertEquals($otherShift->id, $meta['unverified_shift_id']);
    }

    public function test_pin_lockout_resolves_active_open_shift_when_context_is_null(): void
    {
        [$tenant, $kasir] = $this->createCashierFixture();
        $service = app(CashierPinService::class);

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang Auto Shift',
            'code' => 'PIN-03',
            'is_initial' => true,
            'is_active' => true,
        ]);

        $activeShift = Shift::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'user_id' => $kasir->id,
            'opening_balance' => 200000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $service->setPin($kasir, '5566');
        $kasir->refresh();

        // Panggil tanpa context shift (null)
        for ($i = 0; $i < 4; $i++) {
            $service->verifyPin($kasir, '0000');
        }

        try {
            $service->verifyPin($kasir, '0000');
        } catch (ValidationException $e) {
            // expected
        }

        $log = DB::table('activity_logs')
            ->where('tenant_id', $tenant->id)
            ->where('action', 'cashier.pin_locked_out')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $meta = json_decode($log->metadata, true);
        $this->assertEquals($activeShift->id, $meta['shift_id']);
        $this->assertEquals('active_open_shift', $meta['shift_resolved_from']);
    }
}
