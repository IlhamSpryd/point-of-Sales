<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Modules\Integration\Services\SanctumScopeGuardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderApiController extends Controller
{
    public function __construct(
        protected SanctumScopeGuardService $guard
    ) {}

    /**
     * Mengambil daftar pesanan penjualan terfilter untuk integrasi eksternal (n8n/ERP).
     */
    public function index(Request $request): JsonResponse
    {
        $this->guard->authorize($request, 'orders:read');

        /** @var User $user */
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $query = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['orderItems', 'table', 'customer']);

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->input('store_id'));
        }

        if ($request->filled('status')) {
            $query->where('order_status', (string) $request->input('status'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('order_date', '>=', (string) $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('order_date', '<=', (string) $request->input('to_date'));
        }

        $perPage = min(100, max(5, (int) $request->input('per_page', 25)));
        $orders = $query->latest('id')->paginate($perPage);

        return response()->json([
            'status' => 'OK',
            'data' => $orders->items(),
            'orders' => $orders->items(),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Mengambil detail pesanan tunggal beserta rincian item.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->guard->authorize($request, 'orders:read');

        /** @var User $user */
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $order = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['orderItems.product', 'table', 'customer', 'payments'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ]);
    }
}
