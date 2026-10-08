<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\RoleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tenant:provision-roles {tenant}')]
#[Description('Provision the 8 default roles (names + permissions) for a tenant')]
class ProvisionTenantRoles extends Command
{
    public function handle(RoleService $roleService): int
    {
        $tenant = Tenant::findOrFail($this->argument('tenant'));

        $roleService->provisionDefaultRoles($tenant);

        $this->info("Provisioned default roles for tenant {$tenant->id} ({$tenant->name}).");

        return self::SUCCESS;
    }
}
