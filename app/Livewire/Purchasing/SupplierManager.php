<?php

declare(strict_types=1);

namespace App\Livewire\Purchasing;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\SupplierProduct;
use App\Modules\Purchasing\Services\SupplierService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class SupplierManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    // Modal state
    public bool $showModal = false;

    public bool $isEditing = false;

    public bool $showContactModal = false;

    public bool $showCatalogModal = false;

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

    // Contact form properties
    #[Locked]
    public ?int $contactId = null;

    public string $contactName = '';

    public string $contactTitle = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    public bool $contactIsPrimary = false;

    public string $contactNotes = '';

    // Catalog item form properties
    #[Locked]
    public ?int $catalogItemId = null;

    public string $catalogPurchasableType = 'product';

    public ?int $catalogPurchasableId = null;

    public string $catalogSupplierSku = '';

    public string $catalogPurchaseUom = 'PCS';

    public string $catalogConversionFactor = '1.000000';

    public string $catalogStandardPrice = '0.0000';

    public int $catalogLeadTimeDays = 1;

    public string $catalogMinOrderQty = '1.0000';

    public string $catalogOrderMultipleQty = '1.0000';

    public bool $catalogIsPreferred = false;

    public bool $catalogIsActive = true;

    // Active selection for child modals
    #[Locked]
    public ?int $activeSupplierId = null;

    public ?string $activeSupplierName = null;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $user = auth()->user();
        $isAllowed = $user
            && $user->role
            && (
                (bool) ($user->role->is_admin ?? false)
                || in_array($user->role->name, ['Owner', 'Manager', 'Inventory', 'Admin'], true)
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

    public function create(): void
    {
        Gate::authorize('create', Supplier::class);

        $this->resetSupplierForm();
        $this->isEditing = false;
        $this->showModal = true;
    }

    private function findSupplierForActiveTenant(int $id): Supplier
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $supplier = Supplier::query()->where('tenant_id', $tenantId)->find($id);

        if (! $supplier) {
            abort(404, 'Pemasok tidak ditemukan.');
        }

        return $supplier;
    }

    public function edit(int $id): void
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
        $this->currency_code = (string) $supplier->currency_code;
        $this->status = (string) $supplier->getStatusEnum()->value;
        $this->notes = (string) ($supplier->notes ?? '');

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function saveSupplier(SupplierService $supplierService): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'payment_terms_days' => ['required', 'integer', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'status' => ['required', 'string', 'in:active,on_hold,inactive,archived'],
            'notes' => ['nullable', 'string'],
        ]);

        $payload = [
            'name' => $this->name,
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'tax_id' => $this->tax_id ?: null,
            'address' => $this->address ?: null,
            'payment_terms_days' => $this->payment_terms_days,
            'currency_code' => $this->currency_code,
            'status' => $this->status,
            'notes' => $this->notes ?: null,
        ];

        if ($this->code !== '') {
            $payload['code'] = $this->code;
        }

        try {
            if ($this->isEditing && $this->supplierId) {
                $supplier = $this->findSupplierForActiveTenant($this->supplierId);
                Gate::authorize('update', $supplier);
                $supplierService->updateSupplier($supplier, $payload, auth()->user());
                session()->flash('message', "Data pemasok '{$supplier->name}' berhasil diperbarui.");
            } else {
                Gate::authorize('create', Supplier::class);
                $supplier = $supplierService->createSupplier($payload, auth()->user());
                session()->flash('message', "Pemasok '{$supplier->name}' berhasil dibuat dengan kode {$supplier->code}.");
            }

            $this->showModal = false;
            $this->resetSupplierForm();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());
        }
    }

    public function archiveSupplier(int $id, SupplierService $supplierService): void
    {
        $supplier = $this->findSupplierForActiveTenant($id);

        Gate::authorize('archive', $supplier);

        $supplierService->archiveSupplier($supplier, 'Diarsipkan melalui antarmuka pengguna.', auth()->user());
        session()->flash('message', "Pemasok '{$supplier->name}' berhasil diarsipkan.");
    }

    public function deleteSupplier(int $id, SupplierService $supplierService): void
    {
        $supplier = $this->findSupplierForActiveTenant($id);

        Gate::authorize('delete', $supplier);

        try {
            $supplierService->deleteSupplier($supplier, auth()->user());
            session()->flash('message', "Pemasok '{$supplier->name}' berhasil dihapus.");
        } catch (ValidationException $e) {
            session()->flash('error', $e->validator->getMessageBag()->first());
        }
    }

    // --- Narahubung / Contacts ---
    public function openContacts(int $supplierId): void
    {
        $supplier = $this->findSupplierForActiveTenant($supplierId);
        Gate::authorize('view', $supplier);

        $this->activeSupplierId = (int) $supplier->id;
        $this->activeSupplierName = $supplier->name;
        $this->resetContactForm();
        $this->showContactModal = true;
    }

    public function saveContact(SupplierService $supplierService): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $supplier = Supplier::query()->where('tenant_id', $tenantId)->findOrFail($this->activeSupplierId);
        Gate::authorize('update', $supplier);

        $this->validate([
            'contactName' => ['required', 'string', 'max:255'],
            'contactTitle' => ['nullable', 'string', 'max:100'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:50'],
            'contactNotes' => ['nullable', 'string'],
        ]);

        $contactData = [
            'name' => $this->contactName,
            'title' => $this->contactTitle ?: null,
            'email' => $this->contactEmail ?: null,
            'phone' => $this->contactPhone ?: null,
            'is_primary' => $this->contactIsPrimary,
            'notes' => $this->contactNotes ?: null,
        ];

        if ($this->contactId) {
            $contact = SupplierContact::query()
                ->where('tenant_id', $tenantId)
                ->where('supplier_id', $supplier->id)
                ->findOrFail($this->contactId);
            $supplierService->updateContact($contact, $contactData);
        } else {
            $supplierService->addContact($supplier, $contactData);
        }

        $this->resetContactForm();
        session()->flash('contact_message', 'Kontak berhasil disimpan.');
    }

    public function editContact(int $contactId): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $contact = SupplierContact::query()
            ->where('tenant_id', $tenantId)
            ->where('supplier_id', $this->activeSupplierId)
            ->findOrFail($contactId);

        $this->contactId = (int) $contact->id;
        $this->contactName = (string) $contact->name;
        $this->contactTitle = (string) ($contact->title ?? '');
        $this->contactEmail = (string) ($contact->email ?? '');
        $this->contactPhone = (string) ($contact->phone ?? '');
        $this->contactIsPrimary = (bool) $contact->is_primary;
        $this->contactNotes = (string) ($contact->notes ?? '');
    }

    public function deleteContact(int $contactId, SupplierService $supplierService): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $contact = SupplierContact::query()
            ->where('tenant_id', $tenantId)
            ->where('supplier_id', $this->activeSupplierId)
            ->findOrFail($contactId);

        $supplierService->deleteContact($contact);
        $this->resetContactForm();
        session()->flash('contact_message', 'Kontak berhasil dihapus.');
    }

    // --- Katalog Pemasok / Catalog ---
    public function openCatalog(int $supplierId): void
    {
        $supplier = $this->findSupplierForActiveTenant($supplierId);
        Gate::authorize('view', $supplier);

        $this->activeSupplierId = (int) $supplier->id;
        $this->activeSupplierName = $supplier->name;
        $this->resetCatalogForm();
        $this->showCatalogModal = true;
    }

    public function saveCatalogItem(SupplierService $supplierService): void
    {
        $supplier = $this->findSupplierForActiveTenant($this->activeSupplierId);
        Gate::authorize('manageCatalog', $supplier);

        $this->validate([
            'catalogPurchasableType' => ['required', 'string', 'in:product,ingredient'],
            'catalogPurchasableId' => ['required', 'integer'],
            'catalogPurchaseUom' => ['required', 'string', 'max:32'],
            'catalogConversionFactor' => ['required', 'numeric', 'gt:0'],
            'catalogStandardPrice' => ['required', 'numeric', 'min:0'],
            'catalogLeadTimeDays' => ['required', 'integer', 'min:0'],
            'catalogMinOrderQty' => ['required', 'numeric', 'gt:0'],
            'catalogOrderMultipleQty' => ['required', 'numeric', 'gt:0'],
            'catalogSupplierSku' => ['nullable', 'string', 'max:100'],
        ]);

        $itemData = [
            'purchasable_type' => $this->catalogPurchasableType,
            'purchasable_id' => $this->catalogPurchasableId,
            'supplier_sku' => $this->catalogSupplierSku ?: null,
            'purchase_uom' => $this->catalogPurchaseUom,
            'uom_conversion_factor' => $this->catalogConversionFactor,
            'standard_price' => $this->catalogStandardPrice,
            'lead_time_days' => $this->catalogLeadTimeDays,
            'min_order_qty' => $this->catalogMinOrderQty,
            'order_multiple_qty' => $this->catalogOrderMultipleQty,
            'is_preferred' => $this->catalogIsPreferred,
            'is_active' => $this->catalogIsActive,
        ];

        try {
            $supplierService->addOrUpdateSupplierProduct($supplier, $itemData, auth()->user());
            $this->resetCatalogForm();
            session()->flash('catalog_message', 'Item katalog berhasil disimpan.');
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());
        }
    }

    public function deleteCatalogItem(int $id, SupplierService $supplierService): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $item = SupplierProduct::query()
            ->where('tenant_id', $tenantId)
            ->where('supplier_id', $this->activeSupplierId)
            ->findOrFail($id);

        $supplier = Supplier::query()->where('tenant_id', $tenantId)->findOrFail($this->activeSupplierId);
        Gate::authorize('manageCatalog', $supplier);

        $supplierService->removeSupplierProduct($item, auth()->user());
        session()->flash('catalog_message', 'Item katalog berhasil dihapus.');
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

    private function resetContactForm(): void
    {
        $this->contactId = null;
        $this->contactName = '';
        $this->contactTitle = '';
        $this->contactEmail = '';
        $this->contactPhone = '';
        $this->contactIsPrimary = false;
        $this->contactNotes = '';
        $this->resetErrorBag();
    }

    private function resetCatalogForm(): void
    {
        $this->catalogItemId = null;
        $this->catalogPurchasableType = 'product';
        $this->catalogPurchasableId = null;
        $this->catalogSupplierSku = '';
        $this->catalogPurchaseUom = 'PCS';
        $this->catalogConversionFactor = '1.000000';
        $this->catalogStandardPrice = '0.0000';
        $this->catalogLeadTimeDays = 1;
        $this->catalogMinOrderQty = '1.0000';
        $this->catalogOrderMultipleQty = '1.0000';
        $this->catalogIsPreferred = false;
        $this->catalogIsActive = true;
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
