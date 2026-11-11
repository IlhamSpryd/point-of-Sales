<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Integration\Services\SanctumScopeGuardService;
use App\Modules\Reports\Services\AiBusinessAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAssistantApiController extends Controller
{
    public function __construct(
        protected SanctumScopeGuardService $guard,
        protected AiBusinessAssistantService $aiService
    ) {}

    /**
     * Mengambil tren penjualan dan analisis pergerakan omzet berbasis AI (Read-Only).
     */
    public function salesTrends(Request $request): JsonResponse
    {
        $this->guard->authorize($request, 'analytics:read');

        /** @var User $user */
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
        $days = (int) $request->input('days', 14);

        $trends = $this->aiService->analyzeSalesTrends($tenantId, $storeId, $days);

        return response()->json([
            'status' => 'success',
            'data' => $trends,
        ]);
    }

    /**
     * Mendeteksi anomali persediaan (Low Stock & Dead Stock) secara proaktif.
     */
    public function inventoryAnomalies(Request $request): JsonResponse
    {
        $this->guard->authorize($request, 'analytics:read');

        /** @var User $user */
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;

        $anomalies = $this->aiService->detectInventoryAnomalies($tenantId, $storeId);

        return response()->json([
            'status' => 'success',
            'data' => $anomalies,
        ]);
    }

    /**
     * Mengambil draf rekomendasi operasional terkendali (Human-in-the-Loop Principle).
     */
    public function recommendations(Request $request): JsonResponse
    {
        $this->guard->authorize($request, 'analytics:read');

        /** @var User $user */
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;

        $recs = $this->aiService->generateActionableRecommendations($tenantId, $storeId);

        return response()->json([
            'status' => 'success',
            'data' => $recs,
        ]);
    }

    /**
     * Menanyakan analitika bisnis dan interpretasi data ke AI Assistant.
     */
    public function query(Request $request): JsonResponse
    {
        $this->guard->authorize($request, 'analytics:read');

        $validated = $request->validate([
            'query' => ['required', 'string', 'max:500'],
            'store_id' => ['nullable', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;

        $response = $this->aiService->askAssistant($tenantId, $validated['query'], $storeId);

        return response()->json([
            'status' => 'success',
            'data' => $response,
        ]);
    }
}
