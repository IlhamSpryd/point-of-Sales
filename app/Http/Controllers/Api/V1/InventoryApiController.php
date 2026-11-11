<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Integration\Services\SanctumScopeGuardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryApiController extends Controller
{
    public function __construct(
        protected SanctumScopeGuardService $guard
    ) {}

    /**
     * Mengambil saldo persediaan produk cabang untuk integrasi pemantauan stok eksternal.
     */
    public function balances(Request $request): JsonResponse
    {
        $this->guard->authorize($request, 'inventory:read');

        /** @var User $user */
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $query = DB::table('product_stock_balances')
            ->join('products', 'products.id', '=', 'product_stock_balances.product_id')
            ->where('product_stock_balances.tenant_id', $tenantId)
            ->select([
                'product_stock_balances.store_id',
                'product_stock_balances.product_id',
                'products.product_code',
                'products.product_name',
                'product_stock_balances.quantity as stock_balance',
                'products.track_inventory',
            ]);

        if ($request->filled('store_id')) {
            $query->where('product_stock_balances.store_id', (int) $request->input('store_id'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_stock_balances.product_id', (int) $request->input('product_id'));
        }

        $perPage = min(100, max(5, (int) $request->input('per_page', 50)));
        $balances = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $balances->items(),
            'pagination' => [
                'current_page' => $balances->currentPage(),
                'last_page' => $balances->lastPage(),
                'per_page' => $balances->perPage(),
                'total' => $balances->total(),
            ],
        ]);
    }
}
