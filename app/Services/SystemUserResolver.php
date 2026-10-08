<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class SystemUserResolver
{
    public static function selfOrder(int $tenantId): User
    {
        $email = $tenantId === 1
            ? config('pos.system_users.self_order_email', 'selforder@system.local')
            : "selforder.t{$tenantId}@system.local";

        $user = User::where('email', $email)->where('tenant_id', $tenantId)->first();

        if (! $user) {
            Log::critical("System user missing: Self order user not found for tenant {$tenantId} (email: {$email})");
            abort(500, 'System user missing. Please contact support.');
        }

        return $user;
    }

    public static function channel(int $tenantId): User
    {
        $email = $tenantId === 1
            ? config('pos.system_users.channel_order_email', 'channel@system.local')
            : "channel.t{$tenantId}@system.local";

        $user = User::where('email', $email)->where('tenant_id', $tenantId)->first();

        if (! $user) {
            Log::critical("System user missing: Channel order user not found for tenant {$tenantId} (email: {$email})");
            abort(500, 'System user missing. Please contact support.');
        }

        return $user;
    }
}
