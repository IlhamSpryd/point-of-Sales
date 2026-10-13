<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Exceptions\OwnershipTransferException;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\OwnershipTransferService;
use App\Services\RoleService;
use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class OwnershipTransferServiceTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    private OwnershipTransferService $service;

    private User $candidateA;

    private Role $ownerRoleA;

    private Role $managerRoleA;

    private function createTestUser(array $attributes): User
    {
        $tenantId = $attributes['tenant_id'] ?? $this->tenantA->id;

        return app(TenantContext::class)->runAs($tenantId, null, function () use ($attributes, $tenantId) {
            $user = new User($attributes);
            $user->tenant_id = $tenantId;
            $user->save();

            return $user;
        });
    }

    private function createTestRole(array $attributes): Role
    {
        $tenantId = $attributes['tenant_id'] ?? $this->tenantA->id;

        return app(TenantContext::class)->runAs($tenantId, null, function () use ($attributes, $tenantId) {
            $role = new Role($attributes);
            $role->tenant_id = $tenantId;
            $role->save();

            return $role;
        });
    }

    protected function refreshTestDatabase(): void
    {
        if (! \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated) {
            $this->migrateDatabases();

            $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);

            $this->updateLocalCacheOfInMemoryDatabases();

            \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;
        }

        // Jalankan DDL pengujian SEBELUM beginDatabaseTransaction() agar tidak merusak Savepoint MySQL InnoDB
        if (! Schema::hasColumn('tenants', 'owner_user_id')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->unsignedBigInteger('owner_user_id')->nullable()->after('name');
            });
        }

        $this->beginDatabaseTransaction();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupTwoTenants();
        \Illuminate\Database\Eloquent\Model::reguard();
        $this->service = app(OwnershipTransferService::class);

        // Provisioning 8 standard canonical roles untuk Tenant A & B
        app(RoleService::class)->provisionDefaultRoles($this->tenantA);
        app(RoleService::class)->provisionDefaultRoles($this->tenantB);

        $this->ownerRoleA = Role::withoutTenantScope()
            ->where('tenant_id', $this->tenantA->id)
            ->where('role_code', 'ROL-OWNER')
            ->firstOrFail();

        // Pastikan ROL-OWNER memiliki canonical full access permissions [*]
        $this->ownerRoleA->permissions = ['*'];
        $this->ownerRoleA->save();

        $this->managerRoleA = Role::withoutTenantScope()
            ->where('tenant_id', $this->tenantA->id)
            ->where('role_code', 'ROL-MANAGER')
            ->firstOrFail();

        // Inisialisasi User A sebagai legal owner sah Tenant A
        $this->userAOwner->role_id = $this->ownerRoleA->id;
        $this->userAOwner->save();

        $this->tenantA->owner_user_id = $this->userAOwner->id;
        $this->tenantA->save();

        // Buat kandidat owner sah di Tenant A dengan role Manager
        $this->candidateA = $this->createTestUser([
            'name' => 'Manager Candidate A',
            'email' => 'manager.candidate@tenanta.com',
            'password' => bcrypt('secret123'),
            'tenant_id' => $this->tenantA->id,
            'role_id' => $this->managerRoleA->id,
            'is_active' => true,
        ]);
    }

    /**
     * T-01: User biasa (kasir/manager) tidak dapat melakukan transfer kepemilikan.
     */
    public function test_t01_regular_user_cannot_transfer_ownership(): void
    {
        $this->expectException(OwnershipTransferException::class);
        $this->expectExceptionMessage('Aktor tidak terotorisasi');

        // $this->candidateA adalah Manager (bukan legal owner, bukan superadmin)
        $this->service->transferOwnership(
            $this->tenantA,
            $this->candidateA,
            $this->candidateA
        );
    }

    /**
     * T-02: Tenant tanpa legal owner (owner_user_id IS NULL) tidak dapat diklaim oleh user biasa.
     */
    public function test_t02_unowned_tenant_cannot_be_claimed_by_regular_user(): void
    {
        $this->tenantA->owner_user_id = null;
        $this->tenantA->save();

        $this->expectException(OwnershipTransferException::class);
        $this->expectExceptionMessage('Tenant tanpa legal owner hanya dapat direkonsiliasi oleh Super-Admin terotorisasi.');

        $this->service->transferOwnership(
            $this->tenantA,
            $this->candidateA,
            $this->candidateA
        );
    }

    /**
     * T-03: Super-Admin yang tidak terotorisasi (tanpa permission/flag) ditolak.
     */
    public function test_t03_unauthorized_superadmin_is_rejected(): void
    {
        // Buat role SuperAdmin tanpa permission transfer kepemilikan
        $unauthorizedAdminRole = $this->createTestRole([
            'tenant_id' => $this->tenantA->id,
            'name' => 'SuperAdmin',
            'role_code' => 'ROL-SUPERADMIN',
            'permissions' => ['dashboard.view'], // Tidak memiliki permission '*' atau 'tenant.transfer_ownership'
            'is_active' => true,
        ]);

        $unauthorizedAdminUser = $this->createTestUser([
            'name' => 'Unauthorized Admin',
            'email' => 'unauth.admin@system.local',
            'password' => bcrypt('secret123'),
            'tenant_id' => $this->tenantA->id,
            'role_id' => $unauthorizedAdminRole->id,
            'is_active' => true,
        ]);

        $this->expectException(OwnershipTransferException::class);
        $this->expectExceptionMessage('Super-Admin tidak memiliki otorisasi untuk mentransfer kepemilikan tenant.');

        $this->service->transferOwnership(
            $this->tenantA,
            $unauthorizedAdminUser,
            $this->candidateA
        );
    }

    /**
     * T-04: Kandidat lintas tenant ditolak secara fail-closed.
     */
    public function test_t04_cross_tenant_candidate_is_rejected(): void
    {
        // User B Barista milik Tenant B
        $this->expectException(OwnershipTransferException::class);
        $this->expectExceptionMessage('cross-tenant violation');

        $this->service->transferOwnership(
            $this->tenantA,
            $this->userAOwner,
            $this->userBBarista
        );
    }

    /**
     * T-05: Kandidat nonaktif atau soft-deleted ditolak.
     */
    public function test_t05_inactive_or_soft_deleted_candidate_is_rejected(): void
    {
        // Skenario 1: Kandidat nonaktif
        $this->candidateA->is_active = false;
        $this->candidateA->save();

        try {
            $this->service->transferOwnership(
                $this->tenantA,
                $this->userAOwner,
                $this->candidateA
            );
            $this->fail('Expected exception for inactive candidate');
        } catch (OwnershipTransferException $e) {
            $this->assertStringContainsString('harus berstatus aktif dan belum dihapus', $e->getMessage());
        }

        // Skenario 2: Kandidat soft-deleted
        $this->candidateA->is_active = true;
        $this->candidateA->save();
        $this->candidateA->delete(); // Soft delete

        try {
            $this->service->transferOwnership(
                $this->tenantA,
                $this->userAOwner,
                $this->candidateA
            );
            $this->fail('Expected exception for soft-deleted candidate');
        } catch (OwnershipTransferException $e) {
            $this->assertStringContainsString('harus berstatus aktif dan belum dihapus', $e->getMessage());
        }
    }

    /**
     * T-06: Role wajib tidak tersedia atau tidak valid; seluruh perubahan rollback.
     */
    public function test_t06_missing_required_role_rolls_back_all_mutations(): void
    {
        // Nonaktifkan role ROL-OWNER pada tenant
        $this->ownerRoleA->is_active = false;
        $this->ownerRoleA->save();

        // Gunakan Super-Admin terotorisasi sebagai aktor agar validasi aktor lulus
        // dan tes secara terisolasi membuktikan kegagalan resolusi ROL-OWNER pada target tenant
        $authorizedAdminRole = $this->createTestRole([
            'tenant_id' => $this->tenantA->id,
            'name' => 'SuperAdmin',
            'role_code' => 'ROL-SUPERADMIN',
            'permissions' => ['tenant.transfer_ownership'],
            'is_active' => true,
        ]);

        $authorizedAdminUser = $this->createTestUser([
            'name' => 'Authorized Admin T06',
            'email' => 'auth.admin.t06@system.local',
            'password' => bcrypt('secret123'),
            'tenant_id' => $this->tenantA->id,
            'role_id' => $authorizedAdminRole->id,
            'is_active' => true,
        ]);

        $initialOwnerId = $this->tenantA->owner_user_id;
        $initialCandidateRoleId = $this->candidateA->role_id;

        try {
            $this->service->transferOwnership(
                $this->tenantA,
                $authorizedAdminUser,
                $this->candidateA
            );
            $this->fail('Expected exception for inactive ROL-OWNER');
        } catch (OwnershipTransferException $e) {
            $this->assertStringContainsString('Role kanonikal ROL-OWNER tidak ditemukan atau tidak aktif', $e->getMessage());
        }

        // Buktikan rollback atomik penuh
        $this->assertEquals($initialOwnerId, $this->tenantA->fresh()->owner_user_id);
        $this->assertEquals($initialCandidateRoleId, $this->candidateA->fresh()->role_id);
    }

    /**
     * T-07: Kegagalan audit log menyebabkan rollback seluruh mutasi bisnis.
     */
    public function test_t07_audit_insert_failure_causes_complete_rollback(): void
    {
        $initialOwnerId = $this->tenantA->owner_user_id;
        $initialUserARoleId = $this->userAOwner->role_id;
        $initialCandidateRoleId = $this->candidateA->role_id;

        try {
            $this->service->transferOwnership(
                $this->tenantA,
                $this->userAOwner,
                $this->candidateA,
                'Transfer intended but audit will fail',
                ['simulate_audit_failure' => true]
            );
            $this->fail('Expected exception from audit failure');
        } catch (OwnershipTransferException $e) {
            $this->assertStringContainsString('Simulated Audit Ledger Failure', $e->getMessage());
        }

        // Verifikasi atomicity database: owner_user_id dan role tetap ke state awal
        $this->assertEquals($initialOwnerId, $this->tenantA->fresh()->owner_user_id);
        $this->assertEquals($initialUserARoleId, $this->userAOwner->fresh()->role_id);
        $this->assertEquals($initialCandidateRoleId, $this->candidateA->fresh()->role_id);
    }

    /**
     * T-08: Exception di tengah transaksi tidak meninggalkan state parsial.
     */
    public function test_t08_mid_transaction_exception_leaves_zero_partial_state(): void
    {
        // Berikan target demosi role yang tidak ada untuk memicu exception setelah lock
        $initialOwnerId = $this->tenantA->owner_user_id;

        try {
            $this->service->transferOwnership(
                $this->tenantA,
                $this->userAOwner,
                $this->candidateA,
                'Transfer with invalid demotion role',
                ['demote_to_role_code' => 'ROL-NONEXISTENT']
            );
            $this->fail('Expected exception for missing demotion role');
        } catch (OwnershipTransferException $e) {
            $this->assertStringContainsString('Role target demosi ROL-NONEXISTENT tidak ditemukan', $e->getMessage());
        }

        // Buktikan tidak ada baris yang tersimpan
        $this->assertEquals($initialOwnerId, $this->tenantA->fresh()->owner_user_id);
        $this->assertEquals($this->ownerRoleA->id, $this->userAOwner->fresh()->role_id);
        $this->assertEquals($this->managerRoleA->id, $this->candidateA->fresh()->role_id);
    }

    /**
     * T-09: Otorisasi diperiksa ulang setelah tenant di-lock; simulasi stale state ditolak.
     */
    public function test_t09_stale_actor_state_is_rejected_after_row_lock(): void
    {
        // Aktor User A mengira dia masih owner
        $staleActor = $this->userAOwner;

        // Di database, ubah owner_user_id tenant ke kandidat A sebelum service dipanggil
        DB::table('tenants')->where('id', $this->tenantA->id)->update([
            'owner_user_id' => $this->candidateA->id,
        ]);

        $this->expectException(OwnershipTransferException::class);
        $this->expectExceptionMessage('Aktor tidak terotorisasi');

        // Service melakukan lockForUpdate dan mendeteksi stale actor
        $this->service->transferOwnership(
            $this->tenantA,
            $staleActor,
            $this->candidateA
        );
    }

    /**
     * T-10: Dua transfer berurutan tidak menghasilkan owner atau role yang inkonsisten.
     */
    public function test_t10_serialized_transfers_maintain_exactly_one_owner_invariant(): void
    {
        // Transfer 1: Dari User A ke Candidate A
        $this->service->transferOwnership(
            $this->tenantA,
            $this->userAOwner,
            $this->candidateA,
            'First valid transfer'
        );

        $this->assertEquals($this->candidateA->id, $this->tenantA->fresh()->owner_user_id);
        $this->assertEquals($this->ownerRoleA->id, $this->candidateA->fresh()->role_id);
        $this->assertEquals($this->managerRoleA->id, $this->userAOwner->fresh()->role_id);

        // Buat kandidat ketiga
        $candidate3 = $this->createTestUser([
            'name' => 'Third Candidate',
            'email' => 'third.candidate@tenanta.com',
            'password' => bcrypt('secret123'),
            'tenant_id' => $this->tenantA->id,
            'role_id' => $this->managerRoleA->id,
            'is_active' => true,
        ]);

        // Transfer 2 yang mencoba memakai User A (yang sudah didemosi) harus ditolak
        try {
            $this->service->transferOwnership(
                $this->tenantA,
                $this->userAOwner,
                $candidate3,
                'Second transfer with demoted old owner'
            );
            $this->fail('Demoted old owner must not be authorized');
        } catch (OwnershipTransferException $e) {
            $this->assertStringContainsString('Aktor tidak terotorisasi', $e->getMessage());
        }

        // Transfer 2 yang sah memakai Candidate A (owner saat ini) ke Candidate 3 berhasil
        $this->service->transferOwnership(
            $this->tenantA,
            $this->candidateA,
            $candidate3,
            'Second valid transfer by new legal owner'
        );

        $this->assertEquals($candidate3->id, $this->tenantA->fresh()->owner_user_id);
        $this->assertEquals($this->ownerRoleA->id, $candidate3->fresh()->role_id);
        $this->assertEquals($this->managerRoleA->id, $this->candidateA->fresh()->role_id);

        // Invarian: Tepat 1 active user dengan ROL-OWNER di Tenant A
        $activeOwnersCount = User::where('tenant_id', $this->tenantA->id)
            ->where('role_id', $this->ownerRoleA->id)
            ->whereNull('deleted_at')
            ->count();
        $this->assertEquals(1, $activeOwnersCount);
    }

    /**
     * T-11: Transfer ke owner yang sama memiliki perilaku idempotent atau ditolak eksplisit.
     */
    public function test_t11_transfer_to_same_owner_behavior(): void
    {
        // Kebijakan default: ditolak eksplisit
        try {
            $this->service->transferOwnership(
                $this->tenantA,
                $this->userAOwner,
                $this->userAOwner
            );
            $this->fail('Expected exception for transferring to current owner');
        } catch (OwnershipTransferException $e) {
            $this->assertStringContainsString('Kandidat sudah merupakan legal owner aktif untuk tenant ini', $e->getMessage());
        }

        // Kebijakan idempotent jika flag diaktifkan
        $this->service->transferOwnership(
            $this->tenantA,
            $this->userAOwner,
            $this->userAOwner,
            'Idempotent no-op transfer',
            ['idempotent' => true]
        );

        $this->assertEquals($this->userAOwner->id, $this->tenantA->fresh()->owner_user_id);
    }

    /**
     * T-12: Kondisi owner lama sudah tidak ada (soft-deleted atau null) ditangani secara eksplisit.
     */
    public function test_t12_missing_or_deleted_old_owner_handled_cleanly(): void
    {
        // Soft delete old owner
        $this->userAOwner->delete();

        // Super-admin terotorisasi melakukan transfer rekonsiliasi ke candidate A
        $authorizedAdminRole = $this->createTestRole([
            'tenant_id' => $this->tenantA->id,
            'name' => 'SuperAdmin',
            'role_code' => 'ROL-SUPERADMIN',
            'permissions' => ['tenant.transfer_ownership'],
            'is_active' => true,
        ]);

        $authorizedAdminUser = $this->createTestUser([
            'name' => 'Authorized Admin',
            'email' => 'auth.admin@system.local',
            'password' => bcrypt('secret123'),
            'tenant_id' => $this->tenantA->id,
            'role_id' => $authorizedAdminRole->id,
            'is_active' => true,
        ]);

        $this->service->transferOwnership(
            $this->tenantA,
            $authorizedAdminUser,
            $this->candidateA,
            'Reconciliation of tenant with missing old owner'
        );

        $this->assertEquals($this->candidateA->id, $this->tenantA->fresh()->owner_user_id);
        $this->assertEquals($this->ownerRoleA->id, $this->candidateA->fresh()->role_id);

        // Invarian: Tepat 1 user aktif memiliki ROL-OWNER
        $activeOwnersCount = User::where('tenant_id', $this->tenantA->id)
            ->where('role_id', $this->ownerRoleA->id)
            ->whereNull('deleted_at')
            ->count();
        $this->assertEquals(1, $activeOwnersCount);

        // Pastikan audit trail tercatat dengan benar
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->tenantA->id,
            'action' => 'tenant.ownership_transferred',
            'user_id' => $authorizedAdminUser->id,
        ]);
    }

    /**
     * B-01: Regression test: mass assignment tidak dapat mengubah owner_user_id pada Tenant.
     */
    public function test_mass_assignment_cannot_alter_owner_user_id(): void
    {
        $initialOwnerId = $this->tenantA->owner_user_id;
        $this->assertEquals($this->userAOwner->id, $initialOwnerId);

        // Percobaan 1: update mass assignment
        $this->tenantA->update([
            'name' => 'Renamed Tenant',
            'owner_user_id' => $this->candidateA->id,
        ]);

        $this->tenantA->refresh();
        $this->assertEquals('Renamed Tenant', $this->tenantA->name);
        $this->assertEquals($initialOwnerId, $this->tenantA->owner_user_id);
        $this->assertNotEquals($this->candidateA->id, $this->tenantA->owner_user_id);

        // Percobaan 2: create mass assignment
        $newTenant = Tenant::create([
            'name' => 'Unowned Test Tenant',
            'owner_user_id' => $this->candidateA->id,
        ]);

        $this->assertNull($newTenant->fresh()->owner_user_id);

        // Verifikasi statis bahwa owner_user_id tidak terdaftar di $fillable
        $this->assertNotContains('owner_user_id', (new Tenant())->getFillable());

        // Verifikasi relasi owner() tetap berfungsi dengan integritas utuh
        $this->assertInstanceOf(User::class, $this->tenantA->owner);
        $this->assertEquals($this->userAOwner->id, $this->tenantA->owner->id);
    }

    /**
     * A-01: Aktor yang permission-nya telah dicabut dari database ditolak secara fail-closed.
     */
    public function test_actor_with_revoked_permission_is_rejected(): void
    {
        // Cabut permission '*' dan 'tenant.transfer_ownership' dari role owner di database
        $this->ownerRoleA->permissions = ['reports.view', 'dashboard.view'];
        $this->ownerRoleA->save();

        $this->expectException(OwnershipTransferException::class);
        $this->expectExceptionMessage('Permission transfer kepemilikan telah dicabut dari role aktor.');

        $this->service->transferOwnership(
            $this->tenantA,
            $this->userAOwner,
            $this->candidateA
        );
    }

    /**
     * A-02: Aktor yang dinonaktifkan atau dihapus ditolak secara fail-closed pasca-lock.
     */
    public function test_inactive_or_deleted_actor_is_rejected(): void
    {
        // Kasus 1: Aktor nonaktif
        $this->userAOwner->is_active = false;
        $this->userAOwner->save();

        try {
            $this->service->transferOwnership(
                $this->tenantA,
                $this->userAOwner,
                $this->candidateA
            );
            $this->fail('Expected exception for inactive actor');
        } catch (OwnershipTransferException $e) {
            $this->assertStringContainsString('Aktor tidak valid, nonaktif, atau telah dihapus.', $e->getMessage());
        }

        // Kasus 2: Aktor soft-deleted
        $this->userAOwner->is_active = true;
        $this->userAOwner->save();
        $this->userAOwner->delete();

        try {
            $this->service->transferOwnership(
                $this->tenantA,
                $this->userAOwner,
                $this->candidateA
            );
            $this->fail('Expected exception for soft-deleted actor');
        } catch (OwnershipTransferException $e) {
            $this->assertStringContainsString('Aktor tidak valid, nonaktif, atau telah dihapus.', $e->getMessage());
        }
    }

    /**
     * D-01: Demosi ke ROL-OWNER dilarang keras untuk mencegah multi-owner invariant violation.
     */
    public function test_demoting_to_rol_owner_is_rejected(): void
    {
        $this->expectException(OwnershipTransferException::class);
        $this->expectExceptionMessage('Role target demosi tidak boleh ROL-OWNER.');

        $this->service->transferOwnership(
            $this->tenantA,
            $this->userAOwner,
            $this->candidateA,
            'Attempt illegal demotion to ROL-OWNER',
            ['demote_to_role_code' => 'ROL-OWNER']
        );
    }

    /**
     * D-02: Rekonsiliasi legacy owner mencatat seluruh transisi role ke audit trail.
     */
    public function test_reconcile_legacy_owners_audits_all_role_transitions(): void
    {
        // Buat user anomali kedua yang juga memegang ROL-OWNER
        $anomalyOwner = $this->createTestUser([
            'name' => 'Legacy Anomaly Owner',
            'email' => 'anomaly@tenanta.com',
            'password' => bcrypt('secret123'),
            'tenant_id' => $this->tenantA->id,
            'role_id' => $this->ownerRoleA->id,
            'is_active' => true,
        ]);

        $this->service->transferOwnership(
            $this->tenantA,
            $this->userAOwner,
            $this->candidateA,
            'Reconcile all legacy anomalies',
            ['reconcile_legacy_owners' => true]
        );

        // Pastikan invariant terpenuhi: tepat 1 user aktif memiliki ROL-OWNER
        $activeOwnersCount = User::where('tenant_id', $this->tenantA->id)
            ->where('role_id', $this->ownerRoleA->id)
            ->whereNull('deleted_at')
            ->count();
        $this->assertEquals(1, $activeOwnersCount);

        // Pastikan userAOwner dan anomalyOwner keduanya didemosi ke ROL-MANAGER
        $this->assertEquals($this->managerRoleA->id, $this->userAOwner->fresh()->role_id);
        $this->assertEquals($this->managerRoleA->id, $anomalyOwner->fresh()->role_id);
        $this->assertEquals($this->ownerRoleA->id, $this->candidateA->fresh()->role_id);

        // Verifikasi audit log metadata role_transitions
        $auditLog = DB::table('activity_logs')
            ->where('tenant_id', $this->tenantA->id)
            ->where('action', 'tenant.ownership_transferred')
            ->latest('id')
            ->first();

        $this->assertNotNull($auditLog);
        $metadata = json_decode($auditLog->metadata, true);
        $this->assertIsArray($metadata['role_transitions']);
        $this->assertCount(3, $metadata['role_transitions']); // demotion old owner, demotion anomaly, promotion new owner

        $reconciledAnomaly = collect($metadata['role_transitions'])->firstWhere('user_id', $anomalyOwner->id);
        $this->assertNotNull($reconciledAnomaly);
        $this->assertEquals('legacy_anomaly_reconciliation', $reconciledAnomaly['type']);
        $this->assertEquals('ROL-OWNER', $reconciledAnomaly['from_role']);
        $this->assertEquals('ROL-MANAGER', $reconciledAnomaly['to_role']);

        $previousOwnerDemotion = collect($metadata['role_transitions'])->firstWhere('user_id', $this->userAOwner->id);
        $this->assertNotNull($previousOwnerDemotion);
        $this->assertEquals('previous_owner_demotion', $previousOwnerDemotion['type']);
    }

    /**
     * E-01: Audit log mematuhi skema activity_logs, merekam konteks tenant-level dan store resolution strategy.
     */
    public function test_audit_trail_metadata_and_store_resolution(): void
    {
        $this->service->transferOwnership(
            $this->tenantA,
            $this->userAOwner,
            $this->candidateA,
            'Audit verification reason',
            ['store_id' => $this->storeA->id]
        );

        $log = DB::table('activity_logs')
            ->where('tenant_id', $this->tenantA->id)
            ->where('action', 'tenant.ownership_transferred')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->storeA->id, $log->store_id);
        $this->assertEquals($this->userAOwner->id, $log->user_id);
        $this->assertEquals(Tenant::class, $log->subject_type);
        $this->assertEquals($this->tenantA->id, $log->subject_id);

        $metadata = json_decode($log->metadata, true);
        $this->assertEquals('Audit verification reason', $metadata['reason']);
        $this->assertEquals($this->userAOwner->id, $metadata['actor_id']);
        $this->assertEquals($this->userAOwner->id, $metadata['old_owner_id']);
        $this->assertEquals($this->candidateA->id, $metadata['new_owner_id']);
        $this->assertTrue($metadata['is_tenant_level_action']);
        $this->assertEquals('explicit_option', $metadata['store_resolution_strategy']);
        $this->assertNotEmpty($metadata['transferred_at']);
    }

    /**
     * C-01: Verifikasi urutan query lockForUpdate (Tenant -> Actor -> NewOwner -> OldOwner)
     * dan pembuktian keterbatasan lingkungan single-process.
     */
    public function test_concurrency_locking_contract_and_row_lock_order(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains(strtoupper($query->sql), 'FOR UPDATE')) {
                $queries[] = $query->sql;
            }
        });

        $this->service->transferOwnership(
            $this->tenantA,
            $this->userAOwner,
            $this->candidateA,
            'Lock inspection transfer'
        );

        // Pastikan query FOR UPDATE dieksekusi dengan urutan yang tepat
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('`tenants`', $queries[0], 'Lock 1 harus mengunci tabel tenants');
        $this->assertStringContainsString('`users`', $queries[1], 'Lock 2 harus mengunci tabel users (actor)');
        $this->assertStringContainsString('`users`', $queries[2], 'Lock 3 harus mengunci tabel users (new owner)');
        $this->assertStringContainsString('`users`', $queries[3], 'Lock 4 harus mengunci tabel users (old owner)');
    }

    /**
     * F-01: Service gagal secara aman jika kolom tenants.owner_user_id belum tersedia pada skema.
     */
    public function test_fails_safely_if_owner_user_id_column_missing(): void
    {
        Schema::shouldReceive('hasColumn')
            ->with('tenants', 'owner_user_id')
            ->andReturn(false);

        $this->expectException(OwnershipTransferException::class);
        $this->expectExceptionMessage('Kolom tenants.owner_user_id belum tersedia pada skema database');

        $this->service->transferOwnership(
            $this->tenantA,
            $this->userAOwner,
            $this->candidateA
        );
    }
}
