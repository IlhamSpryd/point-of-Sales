<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditTrailServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private function createTenantFixture(): array
    {
        $tenant = Tenant::create([
            'name' => 'Kedai Audit',
            'slug' => 'kedai-audit-' . uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang Audit',
            'code' => 'AUD-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        $role = new Role();
        $role->tenant_id = $tenant->id;
        $role->name = 'Owner';
        $role->permissions = ['*'];
        $role->is_active = true;
        $role->save();

        $unique = uniqid();
        $user = new User();
        $user->tenant_id = $tenant->id;
        $user->role_id = $role->id;
        $user->name = 'Owner Audit';
        $user->email = "audit.owner.{$unique}@example.test";
        $user->email_normalized = "audit.owner.{$unique}@example.test";
        $user->password_hash = bcrypt('Password123!');
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        return [$tenant, $store, $user];
    }

    public function test_can_log_activity_with_explicit_user_and_store(): void
    {
        [$tenant, $store, $user] = $this->createTenantFixture();
        $service = app(AuditTrailService::class);

        $id = $service->log(
            tenantId: $tenant->id,
            action: 'test.action_executed',
            userId: $user->id,
            storeId: $store->id,
            description: 'Uji coba aksi audit berhasil.',
            metadata: ['foo' => 'bar']
        );

        $this->assertNotNull($id);
        $this->assertDatabaseHas('activity_logs', [
            'id' => $id,
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'action' => 'test.action_executed',
        ]);
    }

    public function test_store_auto_resolves_when_store_id_is_null(): void
    {
        [$tenant, $store, $user] = $this->createTenantFixture();
        $service = app(AuditTrailService::class);

        // Jangan sertakan store_id
        $id = $service->log(
            tenantId: $tenant->id,
            action: 'test.auto_resolve_store',
            userId: $user->id
        );

        $this->assertNotNull($id);
        $this->assertDatabaseHas('activity_logs', [
            'id' => $id,
            'tenant_id' => $tenant->id,
            'store_id' => $store->id, // Otomatis mengarah ke cabang pertama milik tenant
            'user_id' => $user->id,
        ]);
    }

    public function test_cross_tenant_user_id_falls_back_to_null_and_records_in_metadata(): void
    {
        [$tenantA, $storeA, $userA] = $this->createTenantFixture();
        [$tenantB, $storeB, $userB] = $this->createTenantFixture();

        $service = app(AuditTrailService::class);

        // Mencoba mencatat audit untuk Tenant A tetapi menyertakan User B (mismatch)
        $id = $service->log(
            tenantId: $tenantA->id,
            action: 'test.cross_tenant_prevented',
            userId: $userB->id
        );

        $this->assertNotNull($id);
        
        $log = DB::table('activity_logs')->where('id', $id)->first();
        // user_id harus NULL agar tidak melanggar foreign key komposit (tenant_id, user_id)
        $this->assertNull($log->user_id);
        $this->assertEquals($tenantA->id, $log->tenant_id);

        $metadata = json_decode($log->metadata, true);
        $this->assertTrue($metadata['user_tenant_mismatch']);
        $this->assertEquals($userB->id, $metadata['unverified_actor_user_id']);
    }

    public function test_immutability_triggers_prevent_update_and_delete(): void
    {
        [$tenant, $store, $user] = $this->createTenantFixture();
        $service = app(AuditTrailService::class);

        $id = $service->log(
            tenantId: $tenant->id,
            action: 'immutable.test',
            userId: $user->id,
            storeId: $store->id
        );

        $this->assertNotNull($id);

        // 1. UPDATE harus ditolak oleh trigger trg_activity_logs_before_update (SIGNAL SQLSTATE 45000)
        $updateFailed = false;
        try {
            DB::table('activity_logs')->where('id', $id)->update(['action' => 'tampered']);
        } catch (\Throwable $e) {
            $updateFailed = true;
            $this->assertStringContainsString('activity_logs bersifat append-only: UPDATE dilarang', $e->getMessage());
        }
        $this->assertTrue($updateFailed, 'Trigger append-only seharusnya mencegah UPDATE.');

        // 2. DELETE harus ditolak oleh trigger trg_activity_logs_before_delete
        $deleteFailed = false;
        try {
            DB::table('activity_logs')->where('id', $id)->delete();
        } catch (\Throwable $e) {
            $deleteFailed = true;
            $this->assertStringContainsString('activity_logs bersifat append-only: DELETE dilarang', $e->getMessage());
        }
        $this->assertTrue($deleteFailed, 'Trigger append-only seharusnya mencegah DELETE.');
    }

    public function test_sensitive_credentials_are_redacted_in_old_and_new_values_and_metadata(): void
    {
        [$tenant, $store, $user] = $this->createTenantFixture();
        $service = app(AuditTrailService::class);

        $sentinelPassword = 'SECRET_PASSWORD_SENTINEL_XYZ_987';
        $sentinelPin = 'PIN_SENTINEL_999999';
        $sentinelToken = 'TOKEN_PAIRING_SENTINEL_ABC_123';
        $sentinelSecret = 'TOTP_SECRET_SENTINEL_456';
        $sentinelOtp = 'OTP_SENTINEL_654321';
        $sentinelRecovery = 'RECOVERY_SENTINEL_CODE_1';

        $oldValues = [
            'name' => 'Old Kasir Name',
            'email' => 'kasir@example.com',
            'password' => $sentinelPassword,
            'pin_hash' => $sentinelPin,
            'pairing_token' => $sentinelToken,
            'pairing_token_expires_at' => '2026-12-31 23:59:59',
            'nested' => [
                'two_factor_secret' => $sentinelSecret,
                'twoFactorRecoveryCodes' => [$sentinelRecovery],
                'deep' => [
                    'OTP' => $sentinelOtp,
                    'is_active' => true,
                ],
            ],
        ];

        $newValues = [
            'name' => 'New Kasir Name',
            'Password' => $sentinelPassword,
            'cashier_pin' => $sentinelPin,
            'twoFactorSecret' => $sentinelSecret,
            'apiKey' => 'APIKEY_SENTINEL_LIVE_999',
        ];

        $metadata = [
            'client' => 'web-pos',
            'authToken' => 'BEARER_SENTINEL_TOKEN_XYZ',
            'safe_info' => 'operational_log',
        ];

        $id = $service->log(
            tenantId: $tenant->id,
            action: 'user.security_update',
            userId: $user->id,
            storeId: $store->id,
            description: 'Uji sanitasi data sensitif pada audit log.',
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata
        );

        $this->assertNotNull($id);

        $log = DB::table('activity_logs')->where('id', $id)->first();
        $this->assertNotNull($log);

        $rawRowJson = json_encode($log);

        // Buktikan tidak ada sentinel rahasia yang tersimpan di kolom database
        $this->assertStringNotContainsString($sentinelPassword, $rawRowJson);
        $this->assertStringNotContainsString($sentinelPin, $rawRowJson);
        $this->assertStringNotContainsString($sentinelToken, $rawRowJson);
        $this->assertStringNotContainsString($sentinelSecret, $rawRowJson);
        $this->assertStringNotContainsString($sentinelOtp, $rawRowJson);
        $this->assertStringNotContainsString($sentinelRecovery, $rawRowJson);
        $this->assertStringNotContainsString('APIKEY_SENTINEL_LIVE_999', $rawRowJson);
        $this->assertStringNotContainsString('BEARER_SENTINEL_TOKEN_XYZ', $rawRowJson);

        // Buktikan field non-sensitif tetap utuh
        $storedOld = json_decode($log->old_values, true);
        $this->assertEquals('Old Kasir Name', $storedOld['name']);
        $this->assertEquals('kasir@example.com', $storedOld['email']);
        $this->assertEquals('2026-12-31 23:59:59', $storedOld['pairing_token_expires_at']);
        $this->assertEquals('[REDACTED]', $storedOld['password']);
        $this->assertEquals('[REDACTED]', $storedOld['pin_hash']);
        $this->assertEquals('[REDACTED]', $storedOld['pairing_token']);
        $this->assertEquals('[REDACTED]', $storedOld['nested']['two_factor_secret']);
        $this->assertEquals('[REDACTED]', $storedOld['nested']['twoFactorRecoveryCodes']);
        $this->assertEquals('[REDACTED]', $storedOld['nested']['deep']['OTP']);
        $this->assertTrue($storedOld['nested']['deep']['is_active']);

        $storedNew = json_decode($log->new_values, true);
        $this->assertEquals('New Kasir Name', $storedNew['name']);
        $this->assertEquals('[REDACTED]', $storedNew['Password']);
        $this->assertEquals('[REDACTED]', $storedNew['cashier_pin']);
        $this->assertEquals('[REDACTED]', $storedNew['twoFactorSecret']);
        $this->assertEquals('[REDACTED]', $storedNew['apiKey']);

        $storedMeta = json_decode($log->metadata, true);
        $this->assertEquals('web-pos', $storedMeta['client']);
        $this->assertEquals('operational_log', $storedMeta['safe_info']);
        $this->assertEquals('[REDACTED]', $storedMeta['authToken']);
    }

    public function test_handles_null_empty_and_non_sensitive_payloads_cleanly(): void
    {
        [$tenant, $store, $user] = $this->createTenantFixture();
        $service = app(AuditTrailService::class);

        $id = $service->log(
            tenantId: $tenant->id,
            action: 'clean.payload_test',
            userId: $user->id,
            storeId: $store->id,
            oldValues: null,
            newValues: [],
            metadata: null
        );

        $this->assertNotNull($id);

        $log = DB::table('activity_logs')->where('id', $id)->first();
        $this->assertNull($log->old_values);
        $this->assertEquals('[]', $log->new_values);
        $this->assertNull($log->metadata);
    }
}

