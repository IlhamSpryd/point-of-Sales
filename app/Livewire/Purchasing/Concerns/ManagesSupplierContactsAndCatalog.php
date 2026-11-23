<?php

declare(strict_types=1);

namespace App\Livewire\Purchasing\Concerns;

use App\Core\Context\TenantContext;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierContact;
use App\Modules\Purchasing\Models\SupplierProduct;
use App\Modules\Purchasing\Services\SupplierService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait ManagesSupplierContactsAndCatalog
{
    public bool $showContactModal = false;

    public bool $showCatalogModal = false;

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
        session()->flash('contact_message', 'Contact saved successfully.');
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
        session()->flash('contact_message', 'Contact deleted successfully.');
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
            session()->flash('catalog_message', 'Catalog item saved successfully.');
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
        session()->flash('catalog_message', 'Catalog item deleted successfully.');
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
}
