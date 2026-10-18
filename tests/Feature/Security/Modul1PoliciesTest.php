<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\ExportTask;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class Modul1PoliciesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private function createTenantHierarchy(): array
    {
        $tenant = Tenant::create([
            'name' => 'Kedai Policy',
            'slug' => 'kedai-policy-' . uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        $store1 = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang 1',
            'code' => 'CB-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        $store2 = Store::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cabang 2',
            'code' => 'CB-02',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => false,
        ]);

        $ownerRole = new Role();
        $ownerRole->tenant_id = $tenant->id;
        $ownerRole->name = 'Owner';
        $ownerRole->permissions = ['*'];
        $ownerRole->is_active = true;
        $ownerRole->save();

        $kasirRole = new Role();
        $kasirRole->tenant_id = $tenant->id;
        $kasirRole->name = 'Kasir';
        $kasirRole->permissions = ['pos.order.create'];
        $kasirRole->is_active = true;
        $kasirRole->save();

        $owner = new User();
        $owner->tenant_id = $tenant->id;
        $owner->role_id = $ownerRole->id;
        $owner->name = 'Owner Policy';
        $owner->email = 'owner.policy@example.test';
        $owner->email_normalized = 'owner.policy@example.test';
        $owner->password_hash = bcrypt('Password123!');
        $owner->is_active = true;
        $owner->email_verified_at = now();
        $owner->save();

        $kasir = new User();
        $kasir->tenant_id = $tenant->id;
        $kasir->role_id = $kasirRole->id;
        $kasir->name = 'Kasir Policy';
        $kasir->email = 'kasir.policy@example.test';
        $kasir->email_normalized = 'kasir.policy@example.test';
        $kasir->password_hash = bcrypt('Password123!');
        $kasir->is_active = true;
        $kasir->email_verified_at = now();
        $kasir->save();

        // Kasir hanya ditugaskan di Cabang 1
        $kasir->stores()->attach($store1->id, ['tenant_id' => $tenant->id]);

        return [$tenant, $store1, $store2, $owner, $kasir];
    }

    public function test_store_policy_permissions(): void
    {
        [$tenant, $store1, $store2, $owner, $kasir] = $this->createTenantHierarchy();

        // 1. Owner memiliki izin penuh
        $this->assertTrue(Gate::forUser($owner)->allows('viewAny', Store::class));
        $this->assertTrue(Gate::forUser($owner)->allows('view', $store1));
        $this->assertTrue(Gate::forUser($owner)->allows('create', Store::class));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $store1));
        $this->assertTrue(Gate::forUser($owner)->allows('switch', $store2));

        // Cabang initial (utama) tidak boleh dihapus
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $store1));
        // Cabang non-initial boleh dihapus oleh owner
        $this->assertTrue(Gate::forUser($owner)->allows('delete', $store2));

        // 2. Kasir dibatasi
        $this->assertFalse(Gate::forUser($kasir)->allows('create', Store::class));
        $this->assertFalse(Gate::forUser($kasir)->allows('update', $store1));
        $this->assertFalse(Gate::forUser($kasir)->allows('delete', $store2));

        // Kasir hanya boleh switch dan view ke cabang yang ditugaskan (Cabang 1)
        $this->assertTrue(Gate::forUser($kasir)->allows('view', $store1));
        $this->assertTrue(Gate::forUser($kasir)->allows('switch', $store1));

        // Kasir tidak boleh switch ke cabang yang tidak ditugaskan (Cabang 2)
        $this->assertFalse(Gate::forUser($kasir)->allows('view', $store2));
        $this->assertFalse(Gate::forUser($kasir)->allows('switch', $store2));
    }

    public function test_user_invitation_policy_permissions(): void
    {
        [$tenant, $store1, $store2, $owner, $kasir] = $this->createTenantHierarchy();

        $invitation = UserInvitation::create([
            'tenant_id' => $tenant->id,
            'name' => 'Invitee Test',
            'email' => 'invitee@example.test',
            'email_normalized' => 'invitee@example.test',
            'role_id' => $kasir->role_id,
            'invited_by_user_id' => $owner->id,
            'token_hash' => hash('sha256', 'sample-token'),
            'status' => UserInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        // 1. Owner berhak mengelola undangan
        $this->assertTrue(Gate::forUser($owner)->allows('viewAny', UserInvitation::class));
        $this->assertTrue(Gate::forUser($owner)->allows('view', $invitation));
        $this->assertTrue(Gate::forUser($owner)->allows('create', UserInvitation::class));
        $this->assertTrue(Gate::forUser($owner)->allows('resend', $invitation));
        $this->assertTrue(Gate::forUser($owner)->allows('revoke', $invitation));

        // 2. Kasir tidak berhak
        $this->assertFalse(Gate::forUser($kasir)->allows('viewAny', UserInvitation::class));
        $this->assertFalse(Gate::forUser($kasir)->allows('create', UserInvitation::class));
        $this->assertFalse(Gate::forUser($kasir)->allows('resend', $invitation));
        $this->assertFalse(Gate::forUser($kasir)->allows('revoke', $invitation));
    }

    public function test_export_task_policy_permissions(): void
    {
        [$tenant, $store1, $store2, $owner, $kasir] = $this->createTenantHierarchy();

        $context = app(\App\Services\Context\TenantContext::class);

        $taskByKasir = $context->runAs($tenant->id, $store1->id, function () use ($tenant, $kasir) {
            return ExportTask::create([
                'tenant_id' => $tenant->id,
                'requested_by' => $kasir->id,
                'type' => 'sales_summary',
                'status' => \App\Enums\ExportTaskStatus::Completed,
                'file_path' => "exports/tenant_{$tenant->id}/sales.csv",
            ]);
        });

        // 1. Owner berhak download task siapa pun dalam tenant
        $this->assertTrue(Gate::forUser($owner)->allows('download', $taskByKasir));

        // 2. Kasir berhak download task miliknya sendiri
        $this->assertTrue(Gate::forUser($kasir)->allows('download', $taskByKasir));

        // 3. Kasir tidak berhak download task milik user lain
        $taskByOwner = $context->runAs($tenant->id, $store1->id, function () use ($tenant, $owner) {
            return ExportTask::create([
                'tenant_id' => $tenant->id,
                'requested_by' => $owner->id,
                'type' => 'sales_summary',
                'status' => \App\Enums\ExportTaskStatus::Completed,
                'file_path' => "exports/tenant_{$tenant->id}/owner_sales.csv",
            ]);
        });

        $this->assertFalse(Gate::forUser($kasir)->allows('download', $taskByOwner));
    }
}
