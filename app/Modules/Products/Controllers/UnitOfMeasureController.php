<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnitOfMeasureRequest;
use App\Http\Requests\UpdateUnitOfMeasureRequest;
use App\Models\UnitOfMeasure;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnitOfMeasureController extends Controller
{
    public function __construct(
        protected ?AuditTrailService $auditTrail = null
    ) {
        $this->auditTrail ??= app(AuditTrailService::class);
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', UnitOfMeasure::class);

        $query = UnitOfMeasure::query()->withCount('products')->latest('id');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $unitsOfMeasure = $query->paginate(15)->withQueryString();

        return view('units_of_measure.index', compact('unitsOfMeasure'));
    }

    public function store(StoreUnitOfMeasureRequest $request): RedirectResponse
    {
        $this->authorize('create', UnitOfMeasure::class);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $uom = UnitOfMeasure::create($request->validated());

        $this->auditTrail->log(
            tenantId: $tenantId,
            action: 'uom.created',
            userId: auth()->id(),
            storeId: app(TenantContext::class)->getStoreId(),
            subjectType: UnitOfMeasure::class,
            subjectId: $uom->id,
            description: "Satuan Ukur '{$uom->name}' ({$uom->code}) berhasil ditambahkan.",
            oldValues: null,
            newValues: $uom->only(['code', 'name', 'precision_scale', 'is_active'])
        );

        return redirect()->route('units-of-measure.index')->with('success', 'Satuan ukur berhasil ditambahkan.');
    }

    public function update(UpdateUnitOfMeasureRequest $request, UnitOfMeasure $unitOfMeasure): RedirectResponse
    {
        $this->authorize('update', $unitOfMeasure);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $oldValues = $unitOfMeasure->only(['code', 'name', 'precision_scale', 'is_active']);

        $unitOfMeasure->update($request->validated());

        $this->auditTrail->log(
            tenantId: $tenantId,
            action: 'uom.updated',
            userId: auth()->id(),
            storeId: app(TenantContext::class)->getStoreId(),
            subjectType: UnitOfMeasure::class,
            subjectId: $unitOfMeasure->id,
            description: "Satuan Ukur '{$unitOfMeasure->name}' berhasil diperbarui.",
            oldValues: $oldValues,
            newValues: $unitOfMeasure->only(['code', 'name', 'precision_scale', 'is_active'])
        );

        return redirect()->route('units-of-measure.index')->with('success', 'Satuan ukur berhasil diperbarui.');
    }

    public function destroy(UnitOfMeasure $unitOfMeasure): RedirectResponse
    {
        $this->authorize('delete', $unitOfMeasure);

        try {
            if ($unitOfMeasure->products()->exists()) {
                return redirect()->route('units-of-measure.index')->with('error', 'Satuan ukur ini masih digunakan oleh produk lain dan tidak dapat dihapus.');
            }

            $tenantId = app(TenantContext::class)->requireTenantId();
            $uomName = $unitOfMeasure->name;
            $uomId = $unitOfMeasure->id;

            $unitOfMeasure->delete();

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'uom.deleted',
                userId: auth()->id(),
                storeId: app(TenantContext::class)->getStoreId(),
                subjectType: UnitOfMeasure::class,
                subjectId: $uomId,
                description: "Satuan Ukur '{$uomName}' berhasil dihapus."
            );

            return redirect()->route('units-of-measure.index')->with('success', 'Satuan ukur berhasil dihapus.');
        } catch (QueryException) {
            return redirect()->route('units-of-measure.index')->with('error', 'Satuan ukur masih terhubung dengan data lain dan tidak dapat dihapus.');
        }
    }
}
