<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Core\Support\EmailNormalizer;
use App\Enums\SupplierStatusEnum;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Modules\Settings\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierService
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected AuditTrailService $auditTrailService,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Membuat data induk pemasok baru.
     *
     * @param  array<string, mixed>  $data
     */
    public function createSupplier(array $data, ?User $actor = null): Supplier
    {
        $tenantId = (int) ($actor ? $actor->tenant_id : $this->tenantContext->requireTenantId());

        return DB::transaction(function () use ($data, $actor, $tenantId) {
            $code = ! empty($data['code'])
                ? trim((string) $data['code'])
                : $this->sequenceService->nextNumber($tenantId, 'SUP');

            $existing = Supplier::query()
                ->where('tenant_id', $tenantId)
                ->where('code', $code)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'code' => ["Kode pemasok '{$code}' sudah digunakan pada tenant ini."],
                ]);
            }

            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => ['Nama pemasok wajib diisi.'],
                ]);
            }

            $email = ! empty($data['email']) ? EmailNormalizer::normalize((string) $data['email']) : null;
            $paymentTerms = max(0, (int) ($data['payment_terms_days'] ?? 0));

            $status = $data['status'] ?? SupplierStatusEnum::Active;
            if (is_string($status)) {
                $status = SupplierStatusEnum::tryFrom($status) ?? SupplierStatusEnum::Active;
            }

            /** @var Supplier $supplier */
            $supplier = Supplier::query()->create([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $name,
                'normalized_name' => mb_strtolower($name),
                'email' => $email,
                'phone' => ! empty($data['phone']) ? trim((string) $data['phone']) : null,
                'tax_id' => ! empty($data['tax_id']) ? trim((string) $data['tax_id']) : null,
                'address' => ! empty($data['address']) ? trim((string) $data['address']) : null,
                'payment_terms_days' => $paymentTerms,
                'currency_code' => ! empty($data['currency_code']) ? strtoupper(trim((string) $data['currency_code'])) : 'IDR',
                'status' => $status,
                'notes' => ! empty($data['notes']) ? trim((string) $data['notes']) : null,
                'created_by_user_id' => $actor?->id,
                'updated_by_user_id' => $actor?->id,
            ]);

            // Audit Log
            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'supplier.created',
                userId: $actor?->id,
                subjectType: Supplier::class,
                subjectId: (int) $supplier->id,
                description: "Pemasok '{$supplier->name}' ({$supplier->code}) dibuat.",
                newValues: [
                    'code' => $supplier->code,
                    'name' => $supplier->name,
                    'status' => $supplier->getStatusEnum()->value,
                ]
            );

            return $supplier;
        });
    }

    /**
     * Memperbarui informasi pemasok.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSupplier(Supplier $supplier, array $data, ?User $actor = null): Supplier
    {
        $tenantId = (int) ($actor ? $actor->tenant_id : $this->tenantContext->requireTenantId());

        if ((int) $supplier->tenant_id !== $tenantId) {
            throw new \RuntimeException('Pemasok tidak ditemukan pada tenant aktif.');
        }

        return DB::transaction(function () use ($supplier, $data, $actor, $tenantId) {
            $oldValues = [
                'code' => $supplier->code,
                'name' => $supplier->name,
                'status' => $supplier->getStatusEnum()->value,
                'email' => $supplier->email,
                'payment_terms_days' => $supplier->payment_terms_days,
            ];

            if (isset($data['code']) && $data['code'] !== $supplier->code) {
                $code = trim((string) $data['code']);
                $duplicate = Supplier::query()
                    ->where('tenant_id', $tenantId)
                    ->where('code', $code)
                    ->where('id', '!=', $supplier->id)
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'code' => ["Kode pemasok '{$code}' sudah digunakan."],
                    ]);
                }
                $supplier->code = $code;
            }

            if (isset($data['name'])) {
                $name = trim((string) $data['name']);
                if ($name === '') {
                    throw ValidationException::withMessages([
                        'name' => ['Nama pemasok tidak boleh kosong.'],
                    ]);
                }
                $supplier->name = $name;
                $supplier->normalized_name = mb_strtolower($name);
            }

            if (array_key_exists('email', $data)) {
                $supplier->email = ! empty($data['email']) ? EmailNormalizer::normalize((string) $data['email']) : null;
            }

            if (array_key_exists('phone', $data)) {
                $supplier->phone = ! empty($data['phone']) ? trim((string) $data['phone']) : null;
            }

            if (array_key_exists('tax_id', $data)) {
                $supplier->tax_id = ! empty($data['tax_id']) ? trim((string) $data['tax_id']) : null;
            }

            if (array_key_exists('address', $data)) {
                $supplier->address = ! empty($data['address']) ? trim((string) $data['address']) : null;
            }

            if (array_key_exists('payment_terms_days', $data)) {
                $supplier->payment_terms_days = max(0, (int) $data['payment_terms_days']);
            }

            if (array_key_exists('currency_code', $data)) {
                $supplier->currency_code = strtoupper(trim((string) $data['currency_code']));
            }

            if (array_key_exists('status', $data)) {
                $status = $data['status'];
                if (is_string($status)) {
                    $status = SupplierStatusEnum::tryFrom($status) ?? $supplier->status;
                }
                $supplier->status = $status;
            }

            if (array_key_exists('notes', $data)) {
                $supplier->notes = ! empty($data['notes']) ? trim((string) $data['notes']) : null;
            }

            if ($actor) {
                $supplier->updated_by_user_id = $actor->id;
            }

            $supplier->save();

            $newValues = [
                'code' => $supplier->code,
                'name' => $supplier->name,
                'status' => $supplier->getStatusEnum()->value,
                'email' => $supplier->email,
                'payment_terms_days' => $supplier->payment_terms_days,
            ];

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'supplier.updated',
                userId: $actor?->id,
                subjectType: Supplier::class,
                subjectId: (int) $supplier->id,
                description: "Pemasok '{$supplier->name}' ({$supplier->code}) diperbarui.",
                oldValues: $oldValues,
                newValues: $newValues
            );

            return $supplier;
        });
    }

    /**
     * Mengarsipkan data pemasok (soft state transition).
     */
    public function archiveSupplier(Supplier $supplier, ?string $reason = null, ?User $actor = null): Supplier
    {
        return $this->updateSupplier($supplier, [
            'status' => SupplierStatusEnum::Archived,
            'notes' => $reason ? ($supplier->notes ? $supplier->notes."\n[Alasan Arsip]: ".$reason : '[Alasan Arsip]: '.$reason) : $supplier->notes,
        ], $actor);
    }

    /**
     * Menghapus pemasok (soft delete) dengan verifikasi ketiadaan dokumen aktif.
     */
    public function deleteSupplier(Supplier $supplier, ?User $actor = null): bool
    {
        $tenantId = (int) ($actor ? $actor->tenant_id : $this->tenantContext->requireTenantId());

        if ((int) $supplier->tenant_id !== $tenantId) {
            throw new \RuntimeException('Pemasok tidak ditemukan pada tenant aktif.');
        }

        return DB::transaction(function () use ($supplier, $actor, $tenantId) {
            // Periksa referensi dokumen (akan meluas seiring fase PO/GRN/Invoice)
            if (DB::table('purchase_orders')->where('tenant_id', $tenantId)->where('supplier_id', $supplier->id)->exists()
                || DB::table('goods_received_notes')->where('tenant_id', $tenantId)->where('supplier_id', $supplier->id)->exists()
                || DB::table('supplier_invoices')->where('tenant_id', $tenantId)->where('supplier_id', $supplier->id)->exists()
            ) {
                throw ValidationException::withMessages([
                    'supplier' => ['Pemasok tidak dapat dihapus karena telah memiliki riwayat transaksi/dokumen.'],
                ]);
            }

            $name = $supplier->name;
            $code = $supplier->code;
            $id = (int) $supplier->id;

            $supplier->delete();

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'supplier.deleted',
                userId: $actor?->id,
                subjectType: Supplier::class,
                subjectId: $id,
                description: "Pemasok '{$name}' ({$code}) dihapus."
            );

            return true;
        });
    }

    /**
     * Menambahkan kontak narahubung baru pada pemasok.
     *
     * @param  array<string, mixed>  $contactData
     */
    public function addContact(Supplier $supplier, array $contactData): SupplierContact
    {
        return DB::transaction(function () use ($supplier, $contactData) {
            $isPrimary = (bool) ($contactData['is_primary'] ?? false);

            if ($isPrimary) {
                SupplierContact::query()
                    ->where('tenant_id', $supplier->tenant_id)
                    ->where('supplier_id', $supplier->id)
                    ->update(['is_primary' => false]);
            }

            $email = ! empty($contactData['email']) ? EmailNormalizer::normalize((string) $contactData['email']) : null;

            return SupplierContact::query()->create([
                'tenant_id' => $supplier->tenant_id,
                'supplier_id' => $supplier->id,
                'name' => trim((string) ($contactData['name'] ?? '')),
                'title' => ! empty($contactData['title']) ? trim((string) $contactData['title']) : null,
                'email' => $email,
                'phone' => ! empty($contactData['phone']) ? trim((string) $contactData['phone']) : null,
                'is_primary' => $isPrimary,
                'notes' => ! empty($contactData['notes']) ? trim((string) $contactData['notes']) : null,
            ]);
        });
    }

    /**
     * Memperbarui kontak narahubung.
     *
     * @param  array<string, mixed>  $contactData
     */
    public function updateContact(SupplierContact $contact, array $contactData): SupplierContact
    {
        return DB::transaction(function () use ($contact, $contactData) {
            if (isset($contactData['is_primary']) && $contactData['is_primary']) {
                SupplierContact::query()
                    ->where('tenant_id', $contact->tenant_id)
                    ->where('supplier_id', $contact->supplier_id)
                    ->where('id', '!=', $contact->id)
                    ->update(['is_primary' => false]);

                $contact->is_primary = true;
            } elseif (array_key_exists('is_primary', $contactData)) {
                $contact->is_primary = (bool) $contactData['is_primary'];
            }

            if (isset($contactData['name'])) {
                $contact->name = trim((string) $contactData['name']);
            }

            if (array_key_exists('title', $contactData)) {
                $contact->title = ! empty($contactData['title']) ? trim((string) $contactData['title']) : null;
            }

            if (array_key_exists('email', $contactData)) {
                $contact->email = ! empty($contactData['email']) ? EmailNormalizer::normalize((string) $contactData['email']) : null;
            }

            if (array_key_exists('phone', $contactData)) {
                $contact->phone = ! empty($contactData['phone']) ? trim((string) $contactData['phone']) : null;
            }

            if (array_key_exists('notes', $contactData)) {
                $contact->notes = ! empty($contactData['notes']) ? trim((string) $contactData['notes']) : null;
            }

            $contact->save();

            return $contact;
        });
    }

    /**
     * Menghapus kontak narahubung.
     */
    public function deleteContact(SupplierContact $contact): bool
    {
        return (bool) $contact->delete();
    }

    /**
     * Menambahkan atau memperbarui item dalam katalog pemasok (SupplierProduct).
     *
     * @param  array<string, mixed>  $productData
     */
    public function addOrUpdateSupplierProduct(Supplier $supplier, array $productData, ?User $actor = null): SupplierProduct
    {
        $purchasableType = strtolower(trim((string) ($productData['purchasable_type'] ?? '')));
        if (! in_array($purchasableType, ['product', 'ingredient'], true)) {
            throw ValidationException::withMessages([
                'purchasable_type' => ["Tipe item harus 'product' atau 'ingredient'."],
            ]);
        }

        $purchasableId = (int) ($productData['purchasable_id'] ?? 0);
        $tenantId = (int) $supplier->tenant_id;

        // Validasi keberadaan item dalam tenant
        if ($purchasableType === 'product') {
            $item = Product::query()->where('tenant_id', $tenantId)->find($purchasableId);
        } else {
            $item = Ingredient::query()->where('tenant_id', $tenantId)->find($purchasableId);
        }

        if ($item === null) {
            throw ValidationException::withMessages([
                'purchasable_id' => ["Item '{$purchasableType}' dengan ID {$purchasableId} tidak ditemukan pada tenant ini."],
            ]);
        }

        $purchaseUom = trim((string) ($productData['purchase_uom'] ?? ''));
        if ($purchaseUom === '') {
            throw ValidationException::withMessages([
                'purchase_uom' => ['Satuan pembelian (purchase_uom) wajib diisi.'],
            ]);
        }

        $conversionFactor = (string) ($productData['uom_conversion_factor'] ?? '1.000000');
        if (bccomp($conversionFactor, '0', 6) <= 0) {
            throw ValidationException::withMessages([
                'uom_conversion_factor' => ['Faktor konversi UoM harus lebih besar dari 0.'],
            ]);
        }

        $standardPrice = (string) ($productData['standard_price'] ?? '0.0000');
        if (bccomp($standardPrice, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'standard_price' => ['Harga standar tidak boleh negatif.'],
            ]);
        }

        $minOrderQty = (string) ($productData['min_order_qty'] ?? '1.0000');
        if (bccomp($minOrderQty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'min_order_qty' => ['Minimal order kuantitas harus lebih besar dari 0.'],
            ]);
        }

        $orderMultipleQty = (string) ($productData['order_multiple_qty'] ?? '1.0000');
        if (bccomp($orderMultipleQty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'order_multiple_qty' => ['Kelipatan order harus lebih besar dari 0.'],
            ]);
        }

        $isPreferred = (bool) ($productData['is_preferred'] ?? false);

        return DB::transaction(function () use (
            $supplier,
            $purchasableType,
            $purchasableId,
            $productData,
            $purchaseUom,
            $conversionFactor,
            $standardPrice,
            $minOrderQty,
            $orderMultipleQty,
            $isPreferred,
            $actor,
            $tenantId
        ) {
            // Jika dijadikan preferred supplier untuk item ini, matikan flag preferred pada supplier lain
            if ($isPreferred) {
                SupplierProduct::query()
                    ->where('tenant_id', $tenantId)
                    ->where('purchasable_type', $purchasableType)
                    ->where('purchasable_id', $purchasableId)
                    ->where('supplier_id', '!=', $supplier->id)
                    ->update(['is_preferred' => false]);
            }

            /** @var SupplierProduct $supplierProduct */
            $supplierProduct = SupplierProduct::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'supplier_id' => $supplier->id,
                    'purchasable_type' => $purchasableType,
                    'purchasable_id' => $purchasableId,
                    'purchase_uom' => $purchaseUom,
                ],
                [
                    'supplier_sku' => ! empty($productData['supplier_sku']) ? trim((string) $productData['supplier_sku']) : null,
                    'uom_conversion_factor' => $conversionFactor,
                    'standard_price' => $standardPrice,
                    'last_purchase_price' => isset($productData['last_purchase_price']) ? (string) $productData['last_purchase_price'] : null,
                    'lead_time_days' => max(0, (int) ($productData['lead_time_days'] ?? 1)),
                    'min_order_qty' => $minOrderQty,
                    'order_multiple_qty' => $orderMultipleQty,
                    'is_preferred' => $isPreferred,
                    'is_active' => (bool) ($productData['is_active'] ?? true),
                ]
            );

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'supplier_product.upserted',
                userId: $actor?->id,
                subjectType: SupplierProduct::class,
                subjectId: (int) $supplierProduct->id,
                description: "Item katalog pemasok ({$purchasableType} ID {$purchasableId}) diperbarui untuk pemasok '{$supplier->name}'.",
                newValues: [
                    'standard_price' => $standardPrice,
                    'uom_conversion_factor' => $conversionFactor,
                    'is_preferred' => $isPreferred,
                ]
            );

            return $supplierProduct;
        });
    }

    /**
     * Menghapus item katalog pemasok.
     */
    public function removeSupplierProduct(SupplierProduct $supplierProduct, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($supplierProduct, $actor) {
            $id = (int) $supplierProduct->id;
            $tenantId = (int) $supplierProduct->tenant_id;

            $supplierProduct->delete();

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'supplier_product.deleted',
                userId: $actor?->id,
                subjectType: SupplierProduct::class,
                subjectId: $id,
                description: "Item katalog pemasok ID {$id} dihapus."
            );

            return true;
        });
    }
}
