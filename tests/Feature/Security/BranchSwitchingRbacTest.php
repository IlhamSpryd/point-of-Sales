<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class BranchSwitchingRbacTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;
    private Store $store1;
    private Store $store2;
    private Store $store3;
    private User $owner;
    private User $supervisor;
    private User $cashier;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Kedai Kopi Apeiron Test',
            'slug' => 'kedai-apeiron-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store1 = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Apeiron Senopati',
            'code' => 'SNP-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => true,
        ]);

        $this->store2 = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Apeiron Kemang',
            'code' => 'KMG-02',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => false,
        ]);

        $this->store3 = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Apeiron SCBD',
            'code' => 'SBD-03',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
            'is_initial' => false,
        ]);

        $ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
            'is_active' => true,
        ]);

        $supervisorRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Supervisor',
            'permissions' => ['orders.*', 'shifts.*', 'stores.switch'],
            'is_active' => true,
        ]);

        $cashierRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create', 'orders.view', 'payments.create', 'shifts.own'],
            'is_active' => true,
        ]);

        $this->owner = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $ownerRole->id,
            'name' => 'Owner Bisnis',
            'email' => 'owner.'.uniqid().'@example.test',
            'email_normalized' => 'owner@example.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->supervisor = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $supervisorRole->id,
            'name' => 'Supervisor Cabang',
            'email' => 'spv.'.uniqid().'@example.test',
            'email_normalized' => 'spv@example.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $cashierRole->id,
            'name' => 'Kasir Counter',
            'email' => 'cashier.'.uniqid().'@example.test',
            'email_normalized' => 'cashier@example.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Attach stores:
        // Cashier: strictly store 1
        $this->cashier->stores()->attach([$this->store1->id], ['tenant_id' => $this->tenant->id]);

        // Supervisor: store 1 and store 2
        $this->supervisor->stores()->attach([$this->store1->id, $this->store2->id], ['tenant_id' => $this->tenant->id]);

        // Owner: all stores
        $this->owner->stores()->attach([$this->store1->id, $this->store2->id, $this->store3->id], ['tenant_id' => $this->tenant->id]);
    }

    public function test_cashier_cannot_switch_stores_and_receives_403(): void
    {
        $this->actingAs($this->cashier);
        $this->withSession(['active_store_id' => $this->store1->id]);

        $response = $this->post(route('store.switch'), [
            'store_id' => (string) $this->store2->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_cashier_cannot_switch_even_if_multiple_stores_in_pivot(): void
    {
        // Temporarily give cashier another store in pivot, but no switch permission
        $this->cashier->stores()->attach([$this->store2->id], ['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->cashier);
        $this->withSession(['active_store_id' => $this->store1->id]);

        $response = $this->post(route('store.switch'), [
            'store_id' => (string) $this->store2->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_supervisor_with_active_open_shift_cannot_switch_stores(): void
    {
        Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store1->id,
            'user_id' => $this->supervisor->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($this->supervisor);
        $this->withSession(['active_store_id' => $this->store1->id]);

        $response = $this->from('/transaction')->post(route('store.switch'), [
            'store_id' => (string) $this->store2->id,
        ]);

        $response->assertSessionHasErrors('store_id');
    }

    public function test_supervisor_without_active_shift_can_switch_to_assigned_store(): void
    {
        $this->actingAs($this->supervisor);
        $this->withSession(['active_store_id' => $this->store1->id]);

        $response = $this->post(route('store.switch'), [
            'store_id' => (string) $this->store2->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('active_store_id', $this->store2->id);
    }

    public function test_supervisor_cannot_switch_to_unassigned_store(): void
    {
        $this->actingAs($this->supervisor);
        $this->withSession(['active_store_id' => $this->store1->id]);

        // store 3 is not assigned to supervisor
        $response = $this->post(route('store.switch'), [
            'store_id' => (string) $this->store3->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_supervisor_cannot_switch_to_consolidated_all_stores(): void
    {
        $this->actingAs($this->supervisor);
        $this->withSession(['active_store_id' => $this->store1->id]);

        $response = $this->post(route('store.switch'), [
            'store_id' => 'all',
        ]);

        $response->assertStatus(403);
    }

    public function test_owner_can_switch_to_any_store_and_to_consolidated_all(): void
    {
        $this->actingAs($this->owner);
        $this->withSession(['active_store_id' => $this->store1->id]);

        $response = $this->post(route('store.switch'), [
            'store_id' => (string) $this->store3->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('active_store_id', $this->store3->id);

        $responseAll = $this->post(route('store.switch'), [
            'store_id' => 'all',
        ]);

        $responseAll->assertRedirect();
        $responseAll->assertSessionHas('active_store_id', 'all');
    }

    public function test_branch_switcher_blade_renders_static_badge_for_single_store_cashier(): void
    {
        $this->actingAs($this->cashier);
        $this->withSession(['active_store_id' => $this->store1->id]);

        $rendered = Blade::render('<x-branch-switcher />');

        // Cashier must see static badge
        $this->assertStringContainsString('Apeiron Senopati', $rendered);
        $this->assertStringContainsString('Workstation registered to', $rendered);

        // Must NOT render interactive dropdown
        $this->assertStringNotContainsString('Select Branch Scope', $rendered);
        $this->assertStringNotContainsString('x-data="{ open: false', $rendered);
    }

    public function test_branch_switcher_blade_renders_locked_pill_when_shift_is_active(): void
    {
        $shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store1->id,
            'user_id' => $this->supervisor->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($this->supervisor);
        $this->withSession(['active_store_id' => $this->store1->id]);

        $rendered = Blade::render('<x-branch-switcher />');

        // Must render locked mode with shift number
        $this->assertStringContainsString('Branch locked: Active sales shift', $rendered);
        $this->assertStringContainsString('#'.$shift->id, $rendered);
        $this->assertStringNotContainsString('Select Branch Scope', $rendered);
    }

    public function test_branch_switcher_blade_renders_interactive_dropdown_for_owner(): void
    {
        $this->actingAs($this->owner);
        $this->withSession(['active_store_id' => $this->store1->id]);

        $rendered = Blade::render('<x-branch-switcher />');

        // Owner must see full interactive dropdown
        $this->assertStringContainsString('Select Branch Scope', $rendered);
        $this->assertStringContainsString('All Branches (Consolidated)', $rendered);
        $this->assertStringContainsString('Apeiron Senopati', $rendered);
        $this->assertStringContainsString('Apeiron Kemang', $rendered);
        $this->assertStringContainsString('Apeiron SCBD', $rendered);
    }
}
