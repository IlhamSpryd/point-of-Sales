<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Core\Context\TenantContext;
use App\Modules\Customers\Models\Customer;
use App\Modules\Organization\Application\Services\TenantLifecyclePurgeService;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Workforce\Models\Employee;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies tenant lifecycle governance: archived read-only guard and UU PDP compliant PII purge (EXT-06, STR-05).
 */
class TenantLifecycleArchivalAndPurgeTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $activeTenant;

    private Tenant $archivedTenant;

    private Tenant $purgedTenant;

    private User $archivedUser;

    private User $activeUser;

    private Store $archivedStore;

    private TenantLifecyclePurgeService $purgeService;

    protected function afterSubclassSetUp(): void
    {
        $context = app(TenantContext::class);
        $context->clear();

        $this->activeTenant = Tenant::create([
            'name' => 'Active Operations Ltd '.uniqid(),
            'slug' => 'active-'.uniqid(),
            'status' => 'ACTIVE',
            'access_status' => Tenant::ACCESS_ACTIVE,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
        ]);

        $this->archivedTenant = Tenant::create([
            'name' => 'Archived Retail Corp '.uniqid(),
            'slug' => 'archived-'.uniqid(),
            'status' => Tenant::STATUS_ARCHIVED,
            'access_status' => Tenant::ACCESS_ARCHIVED,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
        ]);

        $this->purgedTenant = Tenant::create([
            'name' => 'Purged Enterprise '.uniqid(),
            'slug' => 'purged-'.uniqid(),
            'status' => Tenant::STATUS_PURGED,
            'access_status' => Tenant::ACCESS_PURGED,
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
        ]);

        $context->runAs($this->archivedTenant->id, null, function () {
            $roleArchived = Role::create([
                'tenant_id' => $this->archivedTenant->id,
                'name' => 'Owner',
                'permissions' => ['*'],
            ]);

            $this->archivedStore = Store::withoutGlobalScopes()->create([
                'tenant_id' => $this->archivedTenant->id,
                'name' => 'Archived Outlet',
                'is_active' => true,
            ]);

            $this->archivedUser = User::withoutGlobalScopes()->create([
                'tenant_id' => $this->archivedTenant->id,
                'name' => 'Archived Owner',
                'email' => 'owner.archived.'.uniqid().'@example.com',
                'email_verified_at' => now(),
                'password' => bcrypt('password'),
                'role_id' => $roleArchived->id,
                'is_active' => true,
            ]);
        });

        $context->runAs($this->activeTenant->id, null, function () {
            $roleActive = Role::create([
                'tenant_id' => $this->activeTenant->id,
                'name' => 'Owner',
                'permissions' => ['*'],
            ]);

            $this->activeUser = User::withoutGlobalScopes()->create([
                'tenant_id' => $this->activeTenant->id,
                'name' => 'Active Owner',
                'email' => 'owner.active.'.uniqid().'@example.com',
                'email_verified_at' => now(),
                'password' => bcrypt('password'),
                'role_id' => $roleActive->id,
                'is_active' => true,
            ]);
        });

        $this->purgeService = app(TenantLifecyclePurgeService::class);
    }

    public function test_archived_tenant_allows_safe_read_operations_in_middleware(): void
    {
        $response = $this->actingAs($this->archivedUser)
            ->get('/dashboard');

        // Archived tenant should be allowed to view dashboard/export historical data
        $response->assertSuccessful();
    }

    public function test_archived_tenant_blocks_state_mutating_requests(): void
    {
        $response = $this->actingAs($this->archivedUser)
            ->postJson('/stores', [
                'name' => 'Illegal Store Mutation',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'TENANT_ARCHIVED');
        $this->assertStringContainsString('ARCHIVED (Read-Only)', (string) $response->json('message'));
    }

    public function test_purged_tenant_is_completely_blocked_with_forbidden(): void
    {
        $purgedUser = app(TenantContext::class)->runAs($this->purgedTenant->id, null, function () {
            return User::withoutGlobalScopes()->create([
                'tenant_id' => $this->purgedTenant->id,
                'name' => 'Purged User',
                'email' => 'user.purged.'.uniqid().'@example.com',
                'email_verified_at' => now(),
                'password' => bcrypt('password'),
                'is_active' => true,
            ]);
        });

        $response = $this->actingAs($purgedUser)
            ->get('/dashboard');

        $response->assertStatus(403);
    }

    public function test_purge_service_refuses_to_purge_non_archived_tenant(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Hanya tenant berstatus ARCHIVED yang dapat di-purge');

        $this->purgeService->purgeTenant($this->activeTenant->id);
    }

    public function test_purge_service_anonymizes_pii_and_preserves_records_per_uu_pdp(): void
    {
        // Populate customer and employee for archived tenant
        $customer = DB::table('customers')->insertGetId([
            'tenant_id' => $this->archivedTenant->id,
            'name' => 'Budi Santoso',
            'customer_type' => 'INDIVIDUAL',
            'phone' => '081234567890',
            'email' => 'budi.santoso@example.com',
            'tax_id' => '01.234.567.8-999.000',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deptId = DB::table('departments')->insertGetId([
            'tenant_id' => $this->archivedTenant->id,
            'name' => 'General Operations',
            'department_code' => 'OPS-'.Str::random(4),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $jobTitleId = DB::table('job_titles')->insertGetId([
            'tenant_id' => $this->archivedTenant->id,
            'name' => 'Staff',
            'title_code' => 'STF-'.Str::random(4),
            'department_id' => $deptId,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employee = DB::table('employees')->insertGetId([
            'tenant_id' => $this->archivedTenant->id,
            'full_name' => 'Dewi Lestari',
            'employee_number' => 'EMP-001',
            'phone_number' => '089876543210',
            'email' => 'dewi.lestari@company.internal',
            'gender' => 'FEMALE',
            'marital_status' => 'SINGLE',
            'employment_type' => 'PERMANENT_PKWTT',
            'employment_status' => 'ACTIVE',
            'hire_date' => now()->subYears(2)->toDateString(),
            'residential_address' => 'Jl. Sudirman No. 12, Jakarta',
            'emergency_contact_name' => 'Ayah Dewi',
            'emergency_contact_phone' => '081111222333',
            'department_id' => $deptId,
            'job_title_id' => $jobTitleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Execute purge
        $purgedTenant = $this->purgeService->purgeTenant($this->archivedTenant->id);

        $this->assertSame(Tenant::STATUS_PURGED, $purgedTenant->status);
        $this->assertSame(Tenant::ACCESS_PURGED, $purgedTenant->access_status);

        // Verify User PII anonymization
        $userRow = DB::table('users')->where('id', $this->archivedUser->id)->first();
        $this->assertNotNull($userRow);
        $this->assertStringStartsWith('Anonymized User', (string) $userRow->name);
        $this->assertStringContainsString('@anonymized.internal', (string) $userRow->email);
        $this->assertNull($userRow->phone_number);
        $this->assertSame('*', $userRow->password);
        $this->assertEquals(0, $userRow->is_active);
        $this->assertNotNull($userRow->deleted_at);

        // Verify Customer PII anonymization
        $custRow = DB::table('customers')->where('id', $customer)->first();
        $this->assertNotNull($custRow);
        $this->assertStringStartsWith('Anonymized Customer', (string) $custRow->name);
        $this->assertNull($custRow->phone);
        $this->assertNull($custRow->tax_id);
        $this->assertStringContainsString('@anonymized.internal', (string) $custRow->email);
        $this->assertEquals(0, $custRow->is_active);

        // Verify Employee PII anonymization
        $empRow = DB::table('employees')->where('id', $employee)->first();
        $this->assertNotNull($empRow);
        $this->assertStringStartsWith('Anonymized Employee', (string) $empRow->full_name);
        $this->assertSame('0000000000', $empRow->phone_number);
        $this->assertNull($empRow->residential_address);
        $this->assertNull($empRow->emergency_contact_name);
        $this->assertSame('TERMINATED', $empRow->employment_status);
        $this->assertNotNull($empRow->deleted_at);

        // Verify Immutable Audit Writer log
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $this->archivedTenant->id,
            'action' => 'tenant.purged',
            'subject_type' => 'tenant',
            'subject_id' => $this->archivedTenant->id,
        ]);
    }

    public function test_artisan_command_purges_archived_tenant_with_confirm_flag(): void
    {
        $this->artisan('tenant:purge-archived', [
            'tenant_id' => $this->archivedTenant->id,
            '--confirm' => true,
        ])
            ->expectsOutputToContain('berhasil di-purge dan data PII telah di-anonimkan')
            ->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_PURGED, $this->archivedTenant->fresh()->status);
    }
}
