<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Core\Context\TenantContext;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use App\Modules\Settings\Models\Store;
use App\Modules\Settings\Models\Tenant;
use App\Modules\Users\Models\Role;
use App\Modules\Users\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompositeForeignKeyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA1;

    private Store $storeA2;

    private Store $storeB1;

    private User $userA;

    private User $userB;

    private Department $deptA;

    private Department $deptB;

    private JobTitle $titleA;

    private JobTitle $titleB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->runWithoutTenant(function () use ($context): void {
            $this->tenantA = Tenant::create([
                'name' => 'Tenant Alpha FK',
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $this->tenantB = Tenant::create([
                'name' => 'Tenant Beta FK',
                'status' => 'active',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
            ]);

            $context->runAs($this->tenantA->id, null, function (): void {
                $this->storeA1 = Store::create([
                    'tenant_id' => $this->tenantA->id,
                    'name' => 'Alpha Cabang 1',
                    'code' => 'ALP-01',
                    'is_active' => true,
                ]);
                $this->storeA2 = Store::create([
                    'tenant_id' => $this->tenantA->id,
                    'name' => 'Alpha Cabang 2',
                    'code' => 'ALP-02',
                    'is_active' => true,
                ]);
                $roleA = Role::create([
                    'tenant_id' => $this->tenantA->id,
                    'name' => 'Owner',
                    'role_code' => 'ROL-OWNER',
                    'permissions' => ['*'],
                    'is_active' => true,
                ]);
                $this->userA = User::create([
                    'tenant_id' => $this->tenantA->id,
                    'role_id' => $roleA->id,
                    'name' => 'Owner Alpha',
                    'email' => 'owner@alpha-fk.test',
                    'password' => 'Secret123!',
                    'is_active' => true,
                ]);
                $this->deptA = Department::create([
                    'tenant_id' => $this->tenantA->id,
                    'department_code' => 'OPS-A',
                    'name' => 'Operations Alpha',
                ]);
                $this->titleA = JobTitle::create([
                    'tenant_id' => $this->tenantA->id,
                    'department_id' => $this->deptA->id,
                    'title_code' => 'MGR-A',
                    'name' => 'Manager Alpha',
                ]);
            });

            $context->runAs($this->tenantB->id, null, function (): void {
                $this->storeB1 = Store::create([
                    'tenant_id' => $this->tenantB->id,
                    'name' => 'Beta Cabang 1',
                    'code' => 'BET-01',
                    'is_active' => true,
                ]);
                $roleB = Role::create([
                    'tenant_id' => $this->tenantB->id,
                    'name' => 'Owner',
                    'role_code' => 'ROL-OWNER',
                    'permissions' => ['*'],
                    'is_active' => true,
                ]);
                $this->userB = User::create([
                    'tenant_id' => $this->tenantB->id,
                    'role_id' => $roleB->id,
                    'name' => 'Owner Beta',
                    'email' => 'owner@beta-fk.test',
                    'password' => 'Secret123!',
                    'is_active' => true,
                ]);
                $this->deptB = Department::create([
                    'tenant_id' => $this->tenantB->id,
                    'department_code' => 'OPS-B',
                    'name' => 'Operations Beta',
                ]);
                $this->titleB = JobTitle::create([
                    'tenant_id' => $this->tenantB->id,
                    'department_id' => $this->deptB->id,
                    'title_code' => 'MGR-B',
                    'name' => 'Manager Beta',
                ]);
            });
        });
    }

    public function test_schema_has_zero_single_column_foreign_keys_between_tenant_tables(): void
    {
        $gaps = DB::select("
            WITH fk AS (
                SELECT k.TABLE_NAME AS t, k.CONSTRAINT_NAME AS c, k.REFERENCED_TABLE_NAME AS rt,
                       GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS cols,
                       COUNT(*) AS n
                FROM information_schema.KEY_COLUMN_USAGE k
                WHERE k.TABLE_SCHEMA = DATABASE()
                  AND k.REFERENCED_TABLE_NAME IS NOT NULL
                GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME
            )
            SELECT s.t, s.cols, s.rt, s.c
            FROM fk s
            WHERE s.n = 1
              AND s.rt != 'tenants'
              AND EXISTS (
                  SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = s.t AND COLUMN_NAME = 'tenant_id'
              )
              AND EXISTS (
                  SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = s.rt AND COLUMN_NAME = 'tenant_id'
              )
              AND NOT EXISTS (
                  SELECT 1 FROM fk f
                  WHERE f.t = s.t AND f.n > 1 AND f.rt = s.rt
                    AND FIND_IN_SET(s.cols, f.cols) AND FIND_IN_SET('tenant_id', f.cols)
              )
        ");

        $this->assertSame([], $gaps, 'Found non-composite foreign keys between tenant-owned tables: '.json_encode($gaps));
    }

    public function test_all_tenant_tables_with_id_have_composite_tenant_id_id_unique_index(): void
    {
        $missing = DB::select("
            SELECT c1.TABLE_NAME AS table_name
            FROM information_schema.COLUMNS c1
            JOIN information_schema.COLUMNS c2
              ON c1.TABLE_SCHEMA = c2.TABLE_SCHEMA AND c1.TABLE_NAME = c2.TABLE_NAME
            WHERE c1.TABLE_SCHEMA = DATABASE()
              AND c1.COLUMN_NAME = 'tenant_id' AND c1.IS_NULLABLE = 'NO'
              AND c2.COLUMN_NAME = 'id'
              AND NOT EXISTS (
                SELECT 1 FROM information_schema.STATISTICS s
                WHERE s.TABLE_SCHEMA = DATABASE()
                  AND s.TABLE_NAME = c1.TABLE_NAME
                  AND s.NON_UNIQUE = 0
                GROUP BY s.INDEX_NAME
                HAVING GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX) = 'tenant_id,id'
              )
        ");

        $this->assertSame([], $missing, 'Found tenant tables missing UNIQUE(tenant_id, id): '.json_encode($missing));
    }

    public function test_cross_tenant_reference_is_rejected_at_database_engine_level(): void
    {
        // Upaya bypass ORM (raw DB::table insert) mengaitkan employee Tenant A ke department Tenant B
        $this->expectException(QueryException::class);

        DB::table('employees')->insert([
            'tenant_id' => $this->tenantA->id,
            'department_id' => $this->deptB->id, // Milik Tenant B!
            'job_title_id' => $this->titleA->id,
            'employee_number' => 'EMP-CROSS-01',
            'full_name' => 'Cross Tenant Intruder',
            'phone_number' => '081299990001',
            'gender' => GenderEnum::MALE->value,
            'hire_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_cross_tenant_store_reference_on_hr_and_finance_is_rejected_by_database(): void
    {
        $this->expectException(QueryException::class);

        DB::table('shift_templates')->insert([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeB1->id, // Milik Tenant B!
            'code' => 'SHF-CROSS',
            'name' => 'Shift Silang',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'break_minutes' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_same_tenant_and_nullable_relations_succeed_and_reject_cross_tenant_when_populated(): void
    {
        $context = app(TenantContext::class);

        $context->runAs($this->tenantA->id, $this->storeA1->id, function (): void {
            // 1. Nullable relation (manager_id = null, user_id = null) valid pada tenant yang sama
            $emp = Employee::create([
                'employee_number' => 'EMP-VALID-01',
                'full_name' => 'Karyawan Sah Alpha',
                'phone_number' => '081233334444',
                'gender' => GenderEnum::FEMALE,
                'department_id' => $this->deptA->id,
                'job_title_id' => $this->titleA->id,
                'manager_id' => null,
                'user_id' => $this->userA->id,
                'hire_date' => '2026-01-01',
            ]);

            $this->assertNotNull($emp->id);
            $this->assertNull($emp->manager_id);
            $this->assertSame($this->userA->id, $emp->user_id);

            // 2. Mengisi nullable relation (user_id) dengan user milik Tenant B via raw SQL wajib ditolak DB
            try {
                DB::table('employees')
                    ->where('id', $emp->id)
                    ->update(['user_id' => $this->userB->id]);
                $this->fail('Database seharusnya menolak update user_id lintas tenant.');
            } catch (QueryException $e) {
                $this->assertSame('23000', $e->getCode());
            }
        });
    }

    public function test_delete_restriction_blocks_raw_deletion_of_referenced_parent(): void
    {
        $context = app(TenantContext::class);

        $context->runAs($this->tenantA->id, $this->storeA1->id, function (): void {
            Employee::create([
                'employee_number' => 'EMP-DEL-01',
                'full_name' => 'Karyawan Penahan Hapus',
                'phone_number' => '081255556666',
                'gender' => GenderEnum::MALE,
                'department_id' => $this->deptA->id,
                'job_title_id' => $this->titleA->id,
                'user_id' => $this->userA->id,
                'hire_date' => '2026-01-01',
            ]);
        });

        // Raw delete pada parent (users / departments) yang masih direferensikan wajib ditolak oleh ON DELETE RESTRICT
        try {
            DB::table('users')->where('id', $this->userA->id)->delete();
            $this->fail('ON DELETE RESTRICT seharusnya menolak penghapusan fisik user yang masih direferensikan employee.');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->getCode());
        }

        try {
            DB::table('departments')->where('id', $this->deptA->id)->delete();
            $this->fail('ON DELETE RESTRICT seharusnya menolak penghapusan fisik department yang masih direferensikan.');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->getCode());
        }
    }

    public function test_branch_isolation_and_tenant_scoped_queries_remain_intact(): void
    {
        $context = app(TenantContext::class);

        $context->runAs($this->tenantA->id, $this->storeA1->id, function (): void {
            DB::table('shift_templates')->insert([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA1->id,
                'shift_code' => 'SHF-A1',
                'name' => 'Pagi A1',
                'start_time' => '07:00:00',
                'end_time' => '15:00:00',
                'unpaid_break_minutes' => 60,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('shift_templates')->insert([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA2->id,
                'shift_code' => 'SHF-A2',
                'name' => 'Siang A2',
                'start_time' => '15:00:00',
                'end_time' => '23:00:00',
                'unpaid_break_minutes' => 60,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertSame(1, Department::count());
            $this->assertSame('Operations Alpha', Department::first()?->name);
        });

        $context->runAs($this->tenantB->id, $this->storeB1->id, function (): void {
            $this->assertSame(1, Department::count());
            $this->assertSame('Operations Beta', Department::first()?->name);
        });
    }
}
