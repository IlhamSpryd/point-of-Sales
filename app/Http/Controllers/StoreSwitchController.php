<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Services\Context\StoreResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

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

        // Cegah beralih cabang jika masih memiliki shift aktif di cabang lain
        $openShift = Shift::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        if ($openShift) {
            $targetStoreId = $target === 'all' ? null : (int) $target;
            if ($target === 'all' || $targetStoreId !== (int) $openShift->store_id) {
                throw ValidationException::withMessages([
                    'store_id' => 'Tidak dapat beralih cabang saat masih memiliki shift aktif. Silakan tutup shift terlebih dahulu.',
                ]);
            }
        }

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

        // Invalidate cache dashboard agar data ter-refresh seketika.
        // D1-DB-008: kunci mengikuti format DashboardService (tenant + scope),
        // tidak ada kunci global lintas-tenant.
        Cache::forget("pos:dashboard:{$tenantId}:all:metrics");
        foreach ($allowed as $sId) {
            Cache::forget("pos:dashboard:{$tenantId}:{$sId}:metrics");
        }
        Cache::forget("pos:dashboard:{$tenantId}:default:metrics");

        return back();
    }
}
