<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePriceListRequest;
use App\Http\Requests\UpdatePriceListRequest;
use App\Models\Customer;
use App\Models\LoyaltyTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Store;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use App\Services\MenuCacheService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PriceListController extends Controller
{
    public function __construct(
        protected MenuCacheService $menuCache,
        protected ?AuditTrailService $auditTrail = null
    ) {
        $this->auditTrail ??= app(AuditTrailService::class);
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PriceList::class);

        $query = PriceList::query()
            ->with(['customer', 'loyaltyTier', 'store'])
            ->withCount('items')
            ->orderBy('priority', 'asc')
            ->latest('id');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $priceLists = $query->paginate(15)->withQueryString();

        $customers = Customer::orderBy('name')->get(['id', 'name', 'phone']);
        $loyaltyTiers = LoyaltyTier::all();
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        $products = Product::where('is_active', true)->with('variants')->orderBy('product_name')->get();

        return view('price_lists.index', compact('priceLists', 'customers', 'loyaltyTiers', 'stores', 'products'));
    }

    public function create(): View
    {
        $this->authorize('create', PriceList::class);

        $customers = Customer::orderBy('name')->get(['id', 'name', 'phone']);
        $loyaltyTiers = LoyaltyTier::all();
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        $products = Product::where('is_active', true)->with('variants')->orderBy('product_name')->get();

        return view('price_lists.create', compact('customers', 'loyaltyTiers', 'stores', 'products'));
    }

    public function store(StorePriceListRequest $request): RedirectResponse
    {
        $this->authorize('create', PriceList::class);

        return DB::transaction(function () use ($request) {
            $validated = $request->validated();
            $itemsData = $validated['items'] ?? [];
            unset($validated['items']);

            $tenantId = app(TenantContext::class)->requireTenantId();
            $priceList = PriceList::create($validated);

            foreach ($itemsData as $item) {
                PriceListItem::create([
                    'tenant_id' => $tenantId,
                    'price_list_id' => $priceList->id,
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'min_quantity' => (int) ($item['min_quantity'] ?? 1),
                    'price' => (float) $item['price'],
                ]);
            }

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'price_list.created',
                userId: auth()->id(),
                storeId: $priceList->store_id,
                subjectType: PriceList::class,
                subjectId: $priceList->id,
                description: "Daftar Harga '{$priceList->name}' ({$priceList->type}) berhasil dibuat dengan ".count($itemsData).' item.',
                oldValues: null,
                newValues: $priceList->toArray()
            );

            $this->menuCache->flush($tenantId, $priceList->store_id);

            return redirect()->route('price-lists.index')->with('success', 'Daftar harga berhasil dibuat.');
        });
    }

    public function edit(PriceList $priceList): View
    {
        $this->authorize('update', $priceList);

        $priceList->load(['items.product', 'items.variant']);
        $customers = Customer::orderBy('name')->get(['id', 'name', 'phone']);
        $loyaltyTiers = LoyaltyTier::all();
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        $products = Product::where('is_active', true)->with('variants')->orderBy('product_name')->get();

        return view('price_lists.edit', compact('priceList', 'customers', 'loyaltyTiers', 'stores', 'products'));
    }

    public function update(UpdatePriceListRequest $request, PriceList $priceList): RedirectResponse
    {
        $this->authorize('update', $priceList);

        return DB::transaction(function () use ($request, $priceList) {
            $validated = $request->validated();
            $itemsData = $validated['items'] ?? null;
            unset($validated['items']);

            $tenantId = app(TenantContext::class)->requireTenantId();
            $oldValues = $priceList->toArray();

            $priceList->update($validated);

            if ($itemsData !== null) {
                $priceList->items()->delete();
                foreach ($itemsData as $item) {
                    PriceListItem::create([
                        'tenant_id' => $tenantId,
                        'price_list_id' => $priceList->id,
                        'product_id' => $item['product_id'],
                        'product_variant_id' => $item['product_variant_id'] ?? null,
                        'min_quantity' => (int) ($item['min_quantity'] ?? 1),
                        'price' => (float) $item['price'],
                    ]);
                }
            }

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'price_list.updated',
                userId: auth()->id(),
                storeId: $priceList->store_id,
                subjectType: PriceList::class,
                subjectId: $priceList->id,
                description: "Daftar Harga '{$priceList->name}' berhasil diperbarui.",
                oldValues: $oldValues,
                newValues: $priceList->toArray()
            );

            $this->menuCache->flush($tenantId, $priceList->store_id);

            return redirect()->route('price-lists.index')->with('success', 'Daftar harga berhasil diperbarui.');
        });
    }

    public function destroy(PriceList $priceList): RedirectResponse
    {
        $this->authorize('delete', $priceList);

        return DB::transaction(function () use ($priceList) {
            $tenantId = app(TenantContext::class)->requireTenantId();
            $plName = $priceList->name;
            $plId = $priceList->id;
            $storeId = $priceList->store_id;

            $priceList->items()->delete();
            $priceList->delete();

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'price_list.deleted',
                userId: auth()->id(),
                storeId: $storeId,
                subjectType: PriceList::class,
                subjectId: $plId,
                description: "Daftar Harga '{$plName}' berhasil dihapus."
            );

            $this->menuCache->flush($tenantId, $storeId);

            return redirect()->route('price-lists.index')->with('success', 'Daftar harga berhasil dihapus.');
        });
    }
}
