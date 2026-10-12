<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class SystemUserResolver
{
    public static function selfOrderEmail(int $tenantId): string
    {
        return $tenantId === 1
            ? (config('pos.system_users.self_order_email') ?? config('pos.self_order_system_email', 'selforder@system.local'))
            : "selforder.t{$tenantId}@system.local";
    }

    public static function channelEmail(int $tenantId): string
    {
        return $tenantId === 1
            ? (config('pos.system_users.channel_order_email') ?? config('pos.channel_order_system_email', 'channel@system.local'))
            : "channel.t{$tenantId}@system.local";
    }

    public static function selfOrder(int $tenantId): User
    {
        $email = static::selfOrderEmail($tenantId);

        $user = User::withoutGlobalScope('tenant')->where('email', $email)->where('tenant_id', $tenantId)->first();

        if (! $user) {
            Log::critical("System user missing: Self order user not found for tenant {$tenantId} (email: {$email})");
            abort(500, 'System user missing. Please contact support.');
        }

        return $user;
    }

    public static function channel(int $tenantId): User
    {
        $email = static::channelEmail($tenantId);

        $user = User::withoutGlobalScope('tenant')->where('email', $email)->where('tenant_id', $tenantId)->first();

        if (! $user) {
            Log::critical("System user missing: Channel order user not found for tenant {$tenantId} (email: {$email})");
            abort(500, 'System user missing. Please contact support.');
        }

        return $user;
    }
}
