<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Context\StoreResolver;
use App\Services\Context\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StoreSwitchController extends Controller
{
    /**
     * Mengalihkan cabang aktif (store) dalam sesi pengguna.
     * Owner diizinkan memilih 'all' untuk melihat konsolidasi seluruh cabang.
     */
    public function __invoke(Request $request, StoreResolver $storeResolver): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $tenantId = $user->tenant_id;
        if (! $tenantId) {
            abort(403, 'User does not belong to any tenant.');
        }

        $request->validate([
            'store_id' => ['required', 'string'],
        ]);

        $target = $request->input('store_id');
        $allowed = $storeResolver->allowedStoreIdsFor($user, $tenantId);
        $isOwner = $user->role?->name === 'Owner' || (bool) ($user->role?->is_admin ?? false);

        if ($target === 'all') {
            if (! $isOwner) {
                abort(403, 'Hanya Owner yang dapat mengakses data konsolidasi seluruh cabang.');
            }
            $request->session()->put('active_store_id', 'all');
        } else {
            $storeId = (int) $target;
            if (! in_array($storeId, $allowed, true)) {
                abort(403, 'Anda tidak memiliki hak akses ke cabang ini.');
            }
            $request->session()->put('active_store_id', $storeId);
        }

        // Invalidate cache dashboard agar data ter-refresh seketika
        Cache::forget("pos:dashboard:{$tenantId}:metrics");
        Cache::forget("pos:dashboard:{$tenantId}:all:metrics");
        foreach ($allowed as $sId) {
            Cache::forget("pos:dashboard:{$tenantId}:{$sId}:metrics");
        }

        return back();
    }
}
