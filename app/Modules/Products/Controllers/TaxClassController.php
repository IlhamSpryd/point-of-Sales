<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaxClassRequest;
use App\Http\Requests\UpdateTaxClassRequest;
use App\Models\TaxClass;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaxClassController extends Controller
{
    public function __construct(
        protected ?AuditTrailService $auditTrail = null
    ) {
        $this->auditTrail ??= app(AuditTrailService::class);
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TaxClass::class);

        $query = TaxClass::query()->latest('id');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $taxClasses = $query->paginate(15)->withQueryString();

        return view('tax_classes.index', compact('taxClasses'));
    }

    public function store(StoreTaxClassRequest $request): RedirectResponse
    {
        $this->authorize('create', TaxClass::class);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $taxClass = TaxClass::create($request->validated());

        $this->auditTrail->log(
            tenantId: $tenantId,
            action: 'tax_class.created',
            userId: auth()->id(),
            storeId: app(TenantContext::class)->getStoreId(),
            subjectType: TaxClass::class,
            subjectId: $taxClass->id,
            description: "Kelas Pajak '{$taxClass->name}' ({$taxClass->code}) dengan tarif {$taxClass->rate}% berhasil ditambahkan.",
            oldValues: null,
            newValues: $taxClass->only(['code', 'name', 'rate', 'is_active'])
        );

        return redirect()->route('tax-classes.index')->with('success', 'Kelas pajak berhasil ditambahkan.');
    }

    public function update(UpdateTaxClassRequest $request, TaxClass $taxClass): RedirectResponse
    {
        $this->authorize('update', $taxClass);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $oldValues = $taxClass->only(['code', 'name', 'rate', 'is_active']);

        $taxClass->update($request->validated());

        $this->auditTrail->log(
            tenantId: $tenantId,
            action: 'tax_class.updated',
            userId: auth()->id(),
            storeId: app(TenantContext::class)->getStoreId(),
            subjectType: TaxClass::class,
            subjectId: $taxClass->id,
            description: "Kelas Pajak '{$taxClass->name}' berhasil diperbarui.",
            oldValues: $oldValues,
            newValues: $taxClass->only(['code', 'name', 'rate', 'is_active'])
        );

        return redirect()->route('tax-classes.index')->with('success', 'Kelas pajak berhasil diperbarui.');
    }

    public function destroy(TaxClass $taxClass): RedirectResponse
    {
        $this->authorize('delete', $taxClass);

        try {
            $tenantId = app(TenantContext::class)->requireTenantId();
            $taxName = $taxClass->name;
            $taxId = $taxClass->id;

            $taxClass->delete();

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'tax_class.deleted',
                userId: auth()->id(),
                storeId: app(TenantContext::class)->getStoreId(),
                subjectType: TaxClass::class,
                subjectId: $taxId,
                description: "Kelas Pajak '{$taxName}' berhasil dihapus."
            );

            return redirect()->route('tax-classes.index')->with('success', 'Kelas pajak berhasil dihapus.');
        } catch (QueryException) {
            return redirect()->route('tax-classes.index')->with('error', 'Kelas pajak masih terhubung dengan data lain dan tidak dapat dihapus.');
        }
    }
}
