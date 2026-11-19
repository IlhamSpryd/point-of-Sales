<?php

declare(strict_types=1);

namespace App\Modules\Sales\Controllers;

use App\Core\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\OfflineOrderSyncService;
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
            'payment_method' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && strtolower((string) $value) !== 'cash') {
                    $fail('Offline transactions strictly restrict payment method to CASH only (DEC-006).');
                }
            }],
            'orders' => 'required|array|min:1',
            'orders.*.offline_client_uuid' => 'required|string',
            'orders.*.payment_method' => 'required|string',
            'orders.*.items' => 'required|array|min:1',
            'device_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $tenantId = app(TenantContext::class)->getTenantId() ?? (int) $user->tenant_id;

        // Strict Tenant Isolation: Reject explicit cross-tenant spoofing attempts
        if ($request->has('tenant_id') && (int) $request->input('tenant_id') !== $tenantId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cross-tenant payload rejected.',
            ], 422);
        }

        // Validate explicit store_id if provided in payload
        if ($request->has('store_id')) {
            $inputStoreId = (int) $request->input('store_id');
            $storeBelongsToTenant = \App\Modules\Organization\Models\Store::withoutGlobalScopes()
                ->where('id', $inputStoreId)
                ->where('tenant_id', $tenantId)
                ->exists();

            if (! $storeBelongsToTenant) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Store does not belong to active tenant.',
                ], 422);
            }
        }

        $storeId = app(TenantContext::class)->getStoreId() ?? (int) ($user->store_id ?? $request->input('store_id', 0));

        if ($storeId <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Active store context is required to synchronize offline orders.',
            ], 422);
        }

        // Validate that resolved store belongs to authoritative tenant
        $storeValid = \App\Modules\Organization\Models\Store::withoutGlobalScopes()
            ->where('id', $storeId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $storeValid) {
            return response()->json([
                'status' => 'error',
                'message' => 'Store does not belong to active tenant.',
            ], 422);
        }

        $deviceId = $request->input('device_id');

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
