<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Modules\Integration\Services\SanctumScopeGuardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductApiController extends Controller
{
    public function __construct(
        protected SanctumScopeGuardService $guard
    ) {}

    /**
     * Mengambil katalog produk tenant untuk integrasi e-commerce atau sinkronisasi n8n.
     */
    public function index(Request $request): JsonResponse
    {
        $this->guard->authorize($request, 'products:read');

        /** @var User $user */
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $query = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['category', 'variants' => fn ($q) => $q->where('is_active', true)]);

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('product_name', 'like', "%{$s}%")
                    ->orWhere('product_code', 'like', "%{$s}%")
                    ->orWhere('barcode', 'like', "%{$s}%");
            });
        }

        $perPage = min(100, max(5, (int) $request->input('per_page', 25)));
        $products = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $products->items(),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }
}
