<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashierPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'slug' => 'kedai-pin-' . uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        $role = new Role();
        $role->tenant_id = $tenant->id;
        $role->name = 'Kasir';
        $role->permissions = ['pos.order.create'];
        $role->is_active = true;
        $role->save();

        $kasir = new User();
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
}
