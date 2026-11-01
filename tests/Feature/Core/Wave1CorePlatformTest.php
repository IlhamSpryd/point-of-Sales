<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Context\TenantContext;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Core\Services\ActivityLogService;
use App\Modules\Core\Services\CashierAuthService;
use App\Modules\Core\Services\TenantProvisioningService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class Wave1CorePlatformTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->clear();
    }

    /**
     * T-CORE-01: Kueri tanpa konteks tenant mengembalikan tepat 0 baris (WHERE 1 = 0).
     */
    public function test_t_core_01_query_without_tenant_context_returns_zero_rows_fail_closed(): void
    {
        $context = app(TenantContext::class);
        $context->clear();

        $this->assertNull($context->getTenantId());
        $this->assertFalse($context->isBypassed());

        // Buat data dengan bypass sementara
        $context->runWithoutTenant(function () {
            $tenant = Tenant::create(['name' => 'Secret Corp']);
            $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Secret Store']);
            $role = Role::create([
                'tenant_id' => $tenant->id,
                'name' => 'Secret Role',
                'role_code' => 'SEC-01',
                'permissions' => ['*'],
            ]);
            $user = User::factory()->create([
                'tenant_id' => $tenant->id,
                'role_id' => $role->id,
                'email' => 'agent@secret.corp',
            ]);
            Device::create([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'device_code' => 'DEV-001',
                'device_name' => 'POS Terminal 1',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'status' => Device::STATUS_PAIRED,
            ]);
            ActivityLog::create([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'user_id' => $user->id,
                'action' => 'secret.action',
                'description' => 'Top secret mutation',
            ]);
        });

        // Pastikan kembali ke kondisi tanpa konteks tenant (fail-closed)
        $context->clear();
        $this->assertNull($context->getTenantId());

        // 1. Verifikasi klausul WHERE 1 = 0 pada SQL builder
        $this->assertStringContainsString('1 = 0', User::query()->toSql());
        $this->assertStringContainsString('1 = 0', Store::query()->toSql());
        $this->assertStringContainsString('1 = 0', Role::query()->toSql());
        $this->assertStringContainsString('1 = 0', Device::query()->toSql());
        $this->assertStringContainsString('1 = 0', ActivityLog::query()->toSql());

        // 2. Eksekusi kueri membuktikan hasil tepat 0 baris
        $this->assertSame(0, User::count());
        $this->assertSame(0, Store::count());
        $this->assertSame(0, Role::count());
        $this->assertSame(0, Device::count());
        $this->assertSame(0, ActivityLog::count());

        $this->assertTrue(User::all()->isEmpty());
        $this->assertTrue(Store::all()->isEmpty());
        $this->assertTrue(Role::all()->isEmpty());
        $this->assertTrue(Device::all()->isEmpty());
        $this->assertTrue(ActivityLog::all()->isEmpty());
    }

    /**
     * T-CORE-02: User cabang A ditolak saat membaca data cabang B (HTTP 403).
     */
    public function test_t_core_02_user_of_branch_a_is_denied_access_to_branch_b_data(): void
    {
        $context = app(TenantContext::class);

        [$tenant, $storeA, $storeB, $userA, $deviceB] = $context->runWithoutTenant(function () {
            $tenant = Tenant::create(['name' => 'Retail Chain Corp']);
            $storeA = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Jakarta']);
            $storeB = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Bandung']);

            $cashierRole = Role::create([
                'tenant_id' => $tenant->id,
                'name' => 'Kasir',
                'role_code' => 'KAS-01',
                'permissions' => ['pos.operate'],
            ]);

            $userA = User::factory()->create([
                'tenant_id' => $tenant->id,
                'role_id' => $cashierRole->id,
                'name' => 'Kasir Jakarta',
                'email' => 'kasir.jkt@retail.com',
            ]);

            // User A HANYA ditugaskan ke Cabang A di tabel pivot user_stores
            $userA->stores()->attach($storeA->id, ['tenant_id' => $tenant->id]);

            $deviceB = Device::create([
                'tenant_id' => $tenant->id,
                'store_id' => $storeB->id,
                'device_code' => 'DEV-BDG-01',
                'device_name' => 'POS Bandung',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'status' => Device::STATUS_PAIRED,
            ]);

            return [$tenant, $storeA, $storeB, $userA, $deviceB];
        });

        // Jalankan di bawah konteks tenant yang bersangkutan
        $context->setTenantId($tenant->id);
        $context->setStoreId($storeA->id);

        $this->actingAs($userA);

        // Kasir Jakarta diizinkan melihat Cabang Jakarta
        $this->assertTrue(Gate::forUser($userA)->allows('view', $storeA));

        // Kasir Jakarta DITOLAK saat mencoba melihat Cabang Bandung (HTTP 403)
        $this->assertFalse(Gate::forUser($userA)->allows('view', $storeB));

        // Kasir Jakarta DITOLAK saat mencoba melihat perangkat milik Cabang Bandung
        $this->assertFalse(Gate::forUser($userA)->allows('view', $deviceB));

        // Memastikan pengecualian otorisasi dilempar saat authorize dipanggil
        $this->expectException(AuthorizationException::class);
        Gate::forUser($userA)->authorize('view', $storeB);
    }

    /**
     * T-CORE-03: Percobaan update kolom tenant_id pada model yang sudah tersimpan melempar RuntimeException.
     */
    public function test_t_core_03_attempting_to_update_tenant_id_throws_runtime_exception(): void
    {
        $context = app(TenantContext::class);

        [$tenantOriginal, $user, $store, $role] = $context->runWithoutTenant(function () {
            $tenant = Tenant::create(['name' => 'Tenant Original']);
            $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Toko 1']);
            $role = Role::create([
                'tenant_id' => $tenant->id,
                'name' => 'Staff',
                'role_code' => 'STF-01',
                'permissions' => ['staff.view'],
            ]);
            $user = User::factory()->create([
                'tenant_id' => $tenant->id,
                'role_id' => $role->id,
                'email' => 'user@original.com',
            ]);

            return [$tenant, $user, $store, $role];
        });

        $context->setTenantId($tenantOriginal->id);

        // 1. Modifikasi tenant_id pada User wajib melempar RuntimeException
        try {
            $user->tenant_id = 8888;
            $user->save();
            $this->fail('Seharusnya melempar RuntimeException saat mengubah tenant_id pada User.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be modified once set', $e->getMessage());
        }

        // 2. Modifikasi tenant_id pada Store wajib melempar RuntimeException
        try {
            $store->tenant_id = 8888;
            $store->save();
            $this->fail('Seharusnya melempar RuntimeException saat mengubah tenant_id pada Store.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be modified once set', $e->getMessage());
        }

        // 3. Modifikasi tenant_id pada Role wajib melempar RuntimeException
        try {
            $role->tenant_id = 8888;
            $role->save();
            $this->fail('Seharusnya melempar RuntimeException saat mengubah tenant_id pada Role.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be modified once set', $e->getMessage());
        }
    }

    /**
     * Pengujian alur TenantProvisioningService.
     */
    public function test_tenant_provisioning_service_creates_full_tenant_stack(): void
    {
        $service = app(TenantProvisioningService::class);

        $result = $service->provisionTenant(
            tenantName: 'Kopi Nusantara Corp',
            ownerName: 'Budi Santoso',
            ownerEmail: 'budi@kopinusantara.id',
            ownerPhone: '08123456789'
        );

        $this->assertInstanceOf(Tenant::class, $result['tenant']);
        $this->assertInstanceOf(Store::class, $result['store']);
        $this->assertInstanceOf(User::class, $result['owner']);
        $this->assertNotEmpty($result['onboarding_token']);

        // Verifikasi kepemilikan tenant
        $this->assertSame('Kopi Nusantara Corp', $result['tenant']->name);
        $this->assertSame((int) $result['tenant']->id, (int) $result['store']->tenant_id);
        $this->assertSame((int) $result['tenant']->id, (int) $result['owner']->tenant_id);

        // Verifikasi User Owner terafiliasi dengan store di user_stores
        $this->assertTrue(
            DB::table('user_stores')
                ->where('tenant_id', $result['tenant']->id)
                ->where('user_id', $result['owner']->id)
                ->where('store_id', $result['store']->id)
                ->exists()
        );

        // Verifikasi Activity Log tercatat
        $this->assertTrue(
            ActivityLog::withoutGlobalScopes()
                ->where('tenant_id', $result['tenant']->id)
                ->where('action', 'tenant.provisioned')
                ->exists()
        );
    }

    /**
     * Pengujian otentikasi kasir via PIN dan penguncian lockout 15 menit pada CashierAuthService.
     */
    public function test_cashier_auth_service_pin_authentication_and_lockout(): void
    {
        $context = app(TenantContext::class);
        $authService = app(CashierAuthService::class);

        [$tenant, $store, $cashier] = $context->runWithoutTenant(function () {
            $tenant = Tenant::create(['name' => 'Resto Mandiri']);
            $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Resto Pusat']);
            $cashier = User::factory()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Kasir Utama',
                'email' => 'kasir@resto.id',
            ]);

            $cashier->stores()->attach($store->id, ['tenant_id' => $tenant->id]);

            return [$tenant, $store, $cashier];
        });

        $context->setTenantId($tenant->id);
        $context->setStoreId($store->id);

        // 1. Set PIN 6-digit valid
        $authService->setPin($cashier, '123456');
        $this->assertNotNull($cashier->fresh()->cashier_pin);

        // 2. Otentikasi sukses dengan PIN benar
        $authenticated = $authService->authenticate($cashier, '123456', $store->id);
        $this->assertSame($cashier->id, $authenticated->id);

        // 3. Masukkan PIN salah sebanyak 4 kali (belum terkunci)
        for ($i = 1; $i <= 4; $i++) {
            try {
                $authService->authenticate($cashier, '999999', $store->id);
                $this->fail('Seharusnya gagal dengan PIN salah.');
            } catch (ValidationException $e) {
                $this->assertFalse($authService->isLockedOut($cashier));
            }
        }

        // 4. Percobaan ke-5 salah memicu penguncian lockout
        try {
            $authService->authenticate($cashier, '999999', $store->id);
            $this->fail('Seharusnya memicu lockout pada percobaan ke-5.');
        } catch (ValidationException $e) {
            $this->assertTrue($authService->isLockedOut($cashier));
            $this->assertStringContainsString('terkunci', $e->getMessage());
        }

        // 5. Verifikasi log audit lockout tercatat
        $this->assertTrue(
            ActivityLog::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('action', 'cashier.pin_lockout')
                ->exists()
        );

        // 6. Reset lockout oleh admin/manager
        $authService->resetLockout($cashier);
        $this->assertFalse($authService->isLockedOut($cashier));
    }

    /**
     * Pengujian sifat append-only ActivityLogService.
     */
    public function test_activity_log_service_enforces_append_only_immutability(): void
    {
        $context = app(TenantContext::class);
        $logService = app(ActivityLogService::class);

        [$tenant, $store] = $context->runWithoutTenant(function () {
            $tenant = Tenant::create(['name' => 'Audit Tenant']);
            $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Audit Store']);

            return [$tenant, $store];
        });
        $context->setTenantId($tenant->id);
        $context->setStoreId($store->id);

        // Buat log
        $log = $logService->log(
            tenantId: $tenant->id,
            action: 'price.adjusted',
            storeId: $store->id,
            description: 'Penyesuaian harga menu spesial'
        );

        $this->assertNotNull($log->id);

        // Upaya pembaruan via Eloquent melempar RuntimeException
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('append-only');
        $log->update(['description' => 'Pencemaran jejak audit']);
    }
}
