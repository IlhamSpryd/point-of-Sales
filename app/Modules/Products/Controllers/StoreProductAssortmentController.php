<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductAssortmentRequest;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreProductAssortment;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use App\Services\MenuCacheService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreProductAssortmentController extends Controller
{
    public function __construct(
        protected MenuCacheService $menuCache,
        protected ?AuditTrailService $auditTrail = null
    ) {
        $this->auditTrail ??= app(AuditTrailService::class);
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', StoreProductAssortment::class);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        $selectedStoreId = $request->filled('store_id')
            ? (int) $request->store_id
            : $stores->first()?->id;

        $query = Product::query()
            ->with(['category', 'variants', 'storeAssortments' => function ($q) use ($selectedStoreId) {
                if ($selectedStoreId) {
                    $q->where('store_id', $selectedStoreId);
                }
            }])
            ->where('is_active', true)
            ->latest('id');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('product_name', 'like', "%{$search}%");
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->paginate(20)->withQueryString();

        return view('assortments.index', compact('products', 'stores', 'selectedStoreId'));
    }

    public function update(StoreProductAssortmentRequest $request): RedirectResponse
    {
        $this->authorize('update', StoreProductAssortment::class);

        $validated = $request->validated();
        $tenantId = app(TenantContext::class)->requireTenantId();
        $storeId = (int) $validated['store_id'];
        $productId = (int) $validated['product_id'];
        $variantId = ! empty($validated['product_variant_id']) ? (int) $validated['product_variant_id'] : null;

        $assortment = StoreProductAssortment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId), fn ($q) => $q->whereNull('product_variant_id'))
            ->first();

        $oldValues = $assortment ? $assortment->toArray() : null;

        if (! $assortment) {
            $assortment = StoreProductAssortment::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'is_available' => (bool) $validated['is_available'],
                'store_price_override' => $validated['store_price_override'] ?? null,
                'allowed_channels' => $validated['allowed_channels'] ?? null,
            ]);
        } else {
            $assortment->update([
                'is_available' => (bool) $validated['is_available'],
                'store_price_override' => $validated['store_price_override'] ?? null,
                'allowed_channels' => $validated['allowed_channels'] ?? null,
            ]);
        }

        $this->auditTrail->log(
            tenantId: $tenantId,
            action: 'store_assortment.updated',
            userId: auth()->id(),
            storeId: $storeId,
            subjectType: StoreProductAssortment::class,
            subjectId: $assortment->id,
            description: "Asortimen produk ID {$productId} untuk cabang ID {$storeId} diperbarui (Tersedia: ".($assortment->is_available ? 'Ya' : 'Tidak').').',
            oldValues: $oldValues,
            newValues: $assortment->toArray()
        );

        $this->menuCache->flush($tenantId, $storeId);

        return back()->with('success', 'Asortimen cabang berhasil disimpan.');
    }
}
