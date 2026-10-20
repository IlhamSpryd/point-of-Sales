<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Models\Brand;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BrandController extends Controller
{
    public function __construct(
        protected ?AuditTrailService $auditTrail = null
    ) {
        $this->auditTrail ??= app(AuditTrailService::class);
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Brand::class);

        $query = Brand::query()->withCount('products')->latest('id');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $brands = $query->paginate(15)->withQueryString();

        return view('brands.index', compact('brands'));
    }

    public function store(StoreBrandRequest $request): RedirectResponse
    {
        $this->authorize('create', Brand::class);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $brand = Brand::create($request->validated());

        $this->auditTrail->log(
            tenantId: $tenantId,
            action: 'brand.created',
            userId: auth()->id(),
            storeId: app(TenantContext::class)->getStoreId(),
            subjectType: Brand::class,
            subjectId: $brand->id,
            description: "Merek '{$brand->name}' ({$brand->code}) berhasil ditambahkan.",
            oldValues: null,
            newValues: $brand->only(['code', 'name', 'is_active'])
        );

        return redirect()->route('brands.index')->with('success', 'Merek produk berhasil ditambahkan.');
    }

    public function update(UpdateBrandRequest $request, Brand $brand): RedirectResponse
    {
        $this->authorize('update', $brand);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $oldValues = $brand->only(['code', 'name', 'is_active', 'description']);

        $brand->update($request->validated());

        $this->auditTrail->log(
            tenantId: $tenantId,
            action: 'brand.updated',
            userId: auth()->id(),
            storeId: app(TenantContext::class)->getStoreId(),
            subjectType: Brand::class,
            subjectId: $brand->id,
            description: "Merek '{$brand->name}' berhasil diperbarui.",
            oldValues: $oldValues,
            newValues: $brand->only(['code', 'name', 'is_active', 'description'])
        );

        return redirect()->route('brands.index')->with('success', 'Merek produk berhasil diperbarui.');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        $this->authorize('delete', $brand);

        try {
            if ($brand->products()->exists()) {
                return redirect()->route('brands.index')->with('error', 'Merek ini masih digunakan oleh produk lain dan tidak dapat dihapus.');
            }

            $tenantId = app(TenantContext::class)->requireTenantId();
            $brandName = $brand->name;
            $brandId = $brand->id;

            $brand->delete();

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'brand.deleted',
                userId: auth()->id(),
                storeId: app(TenantContext::class)->getStoreId(),
                subjectType: Brand::class,
                subjectId: $brandId,
                description: "Merek '{$brandName}' berhasil dihapus."
            );

            return redirect()->route('brands.index')->with('success', 'Merek produk berhasil dihapus.');
        } catch (QueryException) {
            return redirect()->route('brands.index')->with('error', 'Merek ini masih terhubung dengan data lain dan tidak dapat dihapus.');
        }
    }
}
