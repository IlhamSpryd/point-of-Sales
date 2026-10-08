<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProvisionSystemUsers extends Command
{
    protected $signature = 'tenant:provision-system-users {tenant}';

    protected $description = 'Provision system users (self order, channel) for a tenant';

    public function handle()
    {
        $tenantId = $this->argument('tenant');
        $tenant = Tenant::findOrFail($tenantId);

        app(TenantContext::class)->runAs($tenantId, null, function () use ($tenantId) {
            $selfOrderEmail = $tenantId == 1
                ? config('pos.system_users.self_order_email', 'selforder@system.local')
                : "selforder.t{$tenantId}@system.local";

            $channelEmail = $tenantId == 1
                ? config('pos.system_users.channel_order_email', 'channel@system.local')
                : "channel.t{$tenantId}@system.local";

            $selfOrderUser = User::firstOrCreate(
                ['email' => $selfOrderEmail, 'tenant_id' => $tenantId],
                [
                    'name' => 'Self Order System',
                    'employee_id' => "SYS-SO-{$tenantId}",
                    'password_hash' => bcrypt(Str::random(16)),
                    'role_id' => null, // Assume 1 is a valid fallback role, or use a system role
                    'is_active' => true,
                    'join_date' => now(),
                ]
            );

            $channelUser = User::firstOrCreate(
                ['email' => $channelEmail, 'tenant_id' => $tenantId],
                [
                    'name' => 'Channel Order System',
                    'employee_id' => "SYS-CH-{$tenantId}",
                    'password_hash' => bcrypt(Str::random(16)),
                    'role_id' => null,
                    'is_active' => true,
                    'join_date' => now(),
                ]
            );

            $this->info("Provisioned system users for tenant {$tenantId}: {$selfOrderUser->email}, {$channelUser->email}");
        });
    }
}
