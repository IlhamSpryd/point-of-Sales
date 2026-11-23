<?php

declare(strict_types=1);

namespace App\Livewire\Purchasing;

use App\Core\Context\TenantContext;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Livewire\Purchasing\Concerns\ManagesSupplierContactsAndCatalog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierContact;
use App\Modules\Purchasing\Models\SupplierProduct;
use App\Modules\Purchasing\Services\SupplierService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class SupplierManager extends Component
{
    use ManagesSupplierContactsAndCatalog;
    use RequiresTenantContext;
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $statusFilter = '';

    // Modal state
    public bool $showModal = false;

    public bool $isEditing = false;

    // Supplier form properties
    #[Locked]
    public ?int $supplierId = null;

    public string $code = '';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $tax_id = '';

    public string $address = '';

    public int $payment_terms_days = 0;

    public string $currency_code = 'IDR';

    public string $status = 'active';

    public string $notes = '';

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $user = auth()->user();
        $evaluator = app(\App\Platform\Authorization\AbilityEvaluator::class);
        $isAllowed = $user
            && $user->role
            && (
                (bool) ($user->role->is_admin ?? false)
                || in_array($user->role->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
                || $evaluator->can($user, 'purchasing.supplier.view')
                || $evaluator->can($user, 'purchasing.view')
                || $user->hasPermission('purchasing.supplier.view')
            );

        if (! $isAllowed) {
            abort(403, 'Anda tidak memiliki hak akses untuk halaman ini.');
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Supplier::class);
        $this->resetSupplierForm();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $supplier = $this->findSupplierForActiveTenant($id);
        Gate::authorize('update', $supplier);

        $this->supplierId = (int) $supplier->id;
        $this->code = (string) $supplier->code;
        $this->name = (string) $supplier->name;
        $this->email = (string) ($supplier->email ?? '');
        $this->phone = (string) ($supplier->phone ?? '');
        $this->tax_id = (string) ($supplier->tax_id ?? '');
        $this->address = (string) ($supplier->address ?? '');
        $this->payment_terms_days = (int) $supplier->payment_terms_days;
        $this->currency_code = (string) ($supplier->currency_code ?? 'IDR');
        $this->status = $supplier->status->value;
        $this->notes = (string) ($supplier->notes ?? '');

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->openEditModal($id);
    }

    public function deleteSupplier(int $id, SupplierService $supplierService): void
    {
        $supplier = $this->findSupplierForActiveTenant($id);
        Gate::authorize('delete', $supplier);

        try {
            $supplierService->deleteSupplier($supplier, auth()->user());
            session()->flash('message', "Supplier '{$supplier->name}' deleted successfully.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function saveSupplier(SupplierService $supplierService): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'currency_code' => ['required', 'string', 'size:3'],
            'status' => ['required', 'string', 'in:active,inactive,blocked'],
            'notes' => ['nullable', 'string'],
        ];

        if ($this->isEditing && $this->supplierId) {
            $rules['code'] = [
                'required',
                'string',
                'max:50',
                "unique:suppliers,code,{$this->supplierId},id,tenant_id,{$tenantId}",
            ];
        } else {
            $rules['code'] = [
                'nullable',
                'string',
                'max:50',
                "unique:suppliers,code,NULL,id,tenant_id,{$tenantId}",
            ];
        }

        $validated = $this->validate($rules);

        $data = [
            'name' => $this->name,
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'tax_id' => $this->tax_id ?: null,
            'address' => $this->address ?: null,
            'payment_terms_days' => $this->payment_terms_days,
            'currency_code' => strtoupper($this->currency_code),
            'status' => $this->status,
            'notes' => $this->notes ?: null,
        ];

        if (! empty($validated['code'])) {
            $data['code'] = $validated['code'];
        }

        try {
            if ($this->isEditing && $this->supplierId) {
                $supplier = $this->findSupplierForActiveTenant($this->supplierId);
                Gate::authorize('update', $supplier);
                $supplierService->updateSupplier($supplier, $data, auth()->user());
                session()->flash('message', "Supplier '{$supplier->name}' berhasil diperbarui.");
            } else {
                Gate::authorize('create', Supplier::class);
                $supplier = $supplierService->createSupplier($data, auth()->user());
                session()->flash('message', "Supplier '{$supplier->name}' ({$supplier->code}) berhasil ditambahkan.");
            }

            $this->showModal = false;
            $this->resetSupplierForm();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());
        }
    }

    public function toggleStatus(int $id, SupplierService $supplierService): void
    {
        $supplier = $this->findSupplierForActiveTenant($id);
        Gate::authorize('update', $supplier);

        $newStatus = $supplier->status === \App\Enums\SupplierStatusEnum::Active ? 'inactive' : 'active';

        try {
            $supplierService->updateSupplier($supplier, ['status' => $newStatus], auth()->user());
            session()->flash('message', "Status supplier '{$supplier->name}' diubah menjadi {$newStatus}.");
        } catch (ValidationException $e) {
            session()->flash('error', $e->validator->getMessageBag()->first());
        }
    }

    private function findSupplierForActiveTenant(int $id): Supplier
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        return Supplier::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);
    }

    private function resetSupplierForm(): void
    {
        $this->supplierId = null;
        $this->code = '';
        $this->name = '';
        $this->email = '';
        $this->phone = '';
        $this->tax_id = '';
        $this->address = '';
        $this->payment_terms_days = 0;
        $this->currency_code = 'IDR';
        $this->status = 'active';
        $this->notes = '';
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        $suppliers = Supplier::query()
            ->where('tenant_id', $tenantId)
            ->with(['primaryContact', 'contacts'])
            ->withCount('supplierProducts')
            ->when($this->search !== '', function ($q) {
                $needle = '%'.mb_strtolower(trim($this->search)).'%';
                $q->where(function ($sub) use ($needle) {
                    $sub->where('normalized_name', 'like', $needle)
                        ->orWhere('code', 'like', $needle)
                        ->orWhere('email', 'like', $needle)
                        ->orWhere('phone', 'like', $needle);
                });
            })
            ->when($this->statusFilter !== '', function ($q) {
                $q->where('status', $this->statusFilter);
            })
            ->orderBy('name')
            ->paginate(15);

        $activeContacts = collect();
        $activeCatalogItems = collect();
        $availableProducts = collect();
        $availableIngredients = collect();

        if ($this->showContactModal && $this->activeSupplierId) {
            $activeContacts = SupplierContact::query()
                ->where('tenant_id', $tenantId)
                ->where('supplier_id', $this->activeSupplierId)
                ->orderByDesc('is_primary')
                ->orderBy('name')
                ->get();
        }

        if ($this->showCatalogModal && $this->activeSupplierId) {
            $activeCatalogItems = SupplierProduct::query()
                ->where('tenant_id', $tenantId)
                ->where('supplier_id', $this->activeSupplierId)
                ->with('purchasable')
                ->orderByDesc('is_preferred')
                ->get();

            $availableProducts = Product::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->select(['id', 'product_name', 'sku'])
                ->orderBy('product_name')
                ->get();

            $availableIngredients = Ingredient::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->select(['id', 'name', 'unit'])
                ->orderBy('name')
                ->get();
        }

        return view('livewire.purchasing.supplier-manager', [
            'suppliers' => $suppliers,
            'activeContacts' => $activeContacts,
            'activeCatalogItems' => $activeCatalogItems,
            'availableProducts' => $availableProducts,
            'availableIngredients' => $availableIngredients,
        ]);
    }
}
