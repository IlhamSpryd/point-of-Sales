<?php

declare(strict_types=1);

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\OfflineOrderSyncService;
use App\Services\Context\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineOrderSyncController extends Controller
{
    public function __construct(
        protected OfflineOrderSyncService $syncService
    ) {}

    /**
     * Endpoint API Sinkronisasi Batch Transaksi Kasir Luring (DEC-006 / TR-003).
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'orders' => 'required|array|min:1',
            'orders.*.offline_client_uuid' => 'required|string',
            'orders.*.payment_method' => 'required|string',
            'orders.*.items' => 'required|array|min:1',
            'device_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $tenantId = app(TenantContext::class)->getTenantId() ?? (int) $user->tenant_id;
        $storeId = app(TenantContext::class)->getStoreId() ?? (int) ($user->store_id ?? $request->input('store_id', 0));
        $deviceId = $request->input('device_id');

        if ($storeId <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Active store context is required to synchronize offline orders.',
            ], 422);
        }

        $result = $this->syncService->syncBatch(
            ordersData: $validated['orders'],
            userId: (int) $user->id,
            tenantId: $tenantId,
            storeId: $storeId,
            deviceId: $deviceId ? (int) $deviceId : null
        );

        return response()->json([
            'status' => $result['failed_count'] === 0 ? 'success' : 'partial',
            'data' => $result,
        ]);
    }
}

