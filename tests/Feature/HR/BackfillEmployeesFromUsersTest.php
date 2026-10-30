<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Jobs\SyncUserToEmployeeJob;
use App\Modules\HR\Models\Employee;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BackfillEmployeesFromUsersTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private TenantContext $context;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Tenant Backfill Test',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);
    }

    public function test_backfill_command_creates_employees_from_users(): void
    {
        $this->context->setTenantId($this->tenant->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Barista',
            'display_name' => 'Head Barista',
            'permissions' => ['pos.order.create'],
        ]);

        $user1 = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Barista Andre',
            'email' => 'andre@test.local',
            'password' => bcrypt('secret123'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $user2 = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Barista Bella',
            'email' => 'bella@test.local',
            'password' => bcrypt('secret123'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->assertSame(0, Employee::count());

        $this->artisan('hr:backfill-employees-from-users', ['--tenant' => $this->tenant->id])
            ->assertSuccessful();

        $this->assertSame(2, Employee::count());

        $emp1 = Employee::where('user_id', $user1->id)->first();
        $this->assertNotNull($emp1);
        $this->assertSame('Barista Andre', $emp1->full_name);
        $this->assertNotEmpty($emp1->employee_number);
        $this->assertNotNull($emp1->taxProfile);

        $emp2 = Employee::where('user_id', $user2->id)->first();
        $this->assertNotNull($emp2);
        $this->assertSame('Barista Bella', $emp2->full_name);
        $this->assertNotSame($emp1->employee_number, $emp2->employee_number);
    }

    public function test_backfill_command_dry_run_does_not_persist(): void
    {
        $this->context->setTenantId($this->tenant->id);

        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir Demo',
            'email' => 'kasir@demo.local',
            'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);

        $this->artisan('hr:backfill-employees-from-users', [
            '--tenant' => $this->tenant->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, Employee::count());
    }

    public function test_sync_user_to_employee_job_synchronizes_profile(): void
    {
        $this->context->setTenantId($this->tenant->id);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Nama Lama',
            'email' => 'lama@test.local',
            'phone_number' => '081111111111',
            'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);

        $this->artisan('hr:backfill-employees-from-users', ['--tenant' => $this->tenant->id])
            ->assertSuccessful();

        $employee = Employee::where('user_id', $user->id)->first();
        $this->assertSame('Nama Lama', $employee->full_name);

        // Update user
        $user->name = 'Nama Baru Terkini';
        $user->email = 'baru@test.local';
        $user->phone_number = '089999999999';
        $user->is_active = false;
        $user->save();

        // Dispatch sync job
        (new SyncUserToEmployeeJob($user->id))->handle();

        $freshEmp = $employee->fresh();
        $this->assertSame('Nama Baru Terkini', $freshEmp->full_name);
        $this->assertSame('baru@test.local', $freshEmp->email);
        $this->assertSame('089999999999', $freshEmp->phone_number);
        $this->assertSame(EmploymentStatusEnum::SUSPENDED, $freshEmp->employment_status);
    }
}
