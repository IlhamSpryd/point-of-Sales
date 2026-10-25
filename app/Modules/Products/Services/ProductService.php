<?php

namespace App\Modules\Products\Services;

use App\Enums\StockMovementType;
use App\Exports\ProductsExport;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Models\ProductCostHistory;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Models\Store;
use App\Models\StoreProductAssortment;
use App\Services\AuditTrailService;
use App\Services\BundleComponentExpanderService;
use App\Services\Context\TenantContext;
use App\Services\EntitlementService;
use App\Services\MenuCacheService;
use DomainException;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * ProductService memberikan penanganan manipulasi Barang Dagang,
 * termasuk manajemen file Foto/Gambar fisiknya.
 */
class ProductService
{
    public function __construct(
        protected MenuCacheService $menuCache,
        protected ?AuditTrailService $auditTrail = null,
        protected ?BundleComponentExpanderService $bundleExpander = null
    ) {
        $this->auditTrail ??= app(AuditTrailService::class);
        $this->bundleExpander ??= app(BundleComponentExpanderService::class);
    }

    /**
     * Menyusun urutan Kueri (Product Builder) sembari meramu join ringan "Category".
     */
    public function getFilteredQuery(Request $request): Builder
    {
        $query = Product::query()->with(['category', 'brand', 'baseUom', 'taxClass', 'media', 'variants'])->latest('id');
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('product_name', 'like', "%{$search}%");
        }

        return $query;
    }

    /**
     * Mendownload spreadsheet format Excel profesional.
     */
    public function exportCsv(Request $request)
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'products_export_'.now()->format('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new ProductsExport($query), $fileName);
    }

    /**
     * Insersi data item komersial. Mengendalikan operasi unggah gambar (Storage Publik) otomatis jika terlampir.
     */
    public function store(array $data, $file = null): Product
    {
        return DB::transaction(function () use ($data, $file) {
            // Sync status and is_active
            if (isset($data['status'])) {
                $data['is_active'] = ($data['status'] === 'active');
            } elseif (isset($data['is_active'])) {
                $data['is_active'] = (bool) $data['is_active'];
                $data['status'] = $data['is_active'] ? 'active' : 'inactive';
            } else {
                $data['is_active'] = true;
                $data['status'] = 'active';
            }

            // Invariant: service, non_inventory, and bundle have track_inventory = false and stock = 0
            if (isset($data['product_type']) && in_array($data['product_type'], ['service', 'non_inventory', 'bundle'], true)) {
                $data['track_inventory'] = false;
                $data['stock'] = 0;
            }

            $tenantId = app(TenantContext::class)->requireTenantId();
            $storeId = app(TenantContext::class)->getStoreId();

            app(EntitlementService::class)->assertCanCreateProduct($tenantId);

            if ($file) {
                $path = $file->store("products/{$tenantId}", 'public');
                $data['product_photo'] = $path;
            }

            $product = Product::create($data);

            if ($file) {
                ProductMedia::create([
                    'tenant_id' => $tenantId,
                    'product_id' => $product->id,
                    'disk' => 'public',
                    'file_path' => $data['product_photo'],
                    'mime_type' => $file->getClientMimeType() ?: ($file->getMimeType() ?: 'image/jpeg'),
                    'file_size_bytes' => $file->getSize() ?: 0,
                    'alt_text' => $product->product_name,
                    'is_primary' => true,
                    'sort_order' => 0,
                ]);
            }

            if (isset($data['standard_cost']) && (float) $data['standard_cost'] > 0) {
                ProductCostHistory::create([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'product_id' => $product->id,
                    'costing_method' => $product->costing_method ?? 'standard',
                    'old_cost' => 0.00,
                    'new_cost' => (float) $product->standard_cost,
                    'reason' => 'Biaya standar awal saat pembuatan produk.',
                    'changed_by' => auth()->id(),
                    'effective_at' => now(),
                ]);

                $this->auditTrail->log(
                    tenantId: $tenantId,
                    action: 'product.cost_initialized',
                    userId: auth()->id(),
                    storeId: $storeId,
                    subjectType: Product::class,
                    subjectId: $product->id,
                    description: 'Biaya standar produk '.$product->product_name.' diinisialisasi sebesar Rp '.number_format((float) $product->standard_cost, 2, ',', '.'),
                    oldValues: ['standard_cost' => 0.00],
                    newValues: ['standard_cost' => (float) $product->standard_cost],
                );
            }

            if (isset($data['ingredients']) && is_array($data['ingredients'])) {
                $ingredientsSync = [];
                foreach ($data['ingredients'] as $ing) {
                    if (isset($ing['id']) && isset($ing['quantity'])) {
                        $ingredientsSync[$ing['id']] = [
                            'quantity_required' => $ing['quantity'],
                            'tenant_id' => $tenantId,
                        ];
                    }
                }
                $product->ingredients()->sync($ingredientsSync);
            }

            if (isset($data['modifier_groups']) && is_array($data['modifier_groups'])) {
                $modifierGroupsSync = [];
                foreach ($data['modifier_groups'] as $groupId) {
                    $modifierGroupsSync[$groupId] = ['tenant_id' => $tenantId];
                }
                $product->modifierGroups()->sync($modifierGroupsSync);
            }

            if (! empty($data['variants']) && is_array($data['variants'])) {
                $this->syncVariants($product, $data['variants'], $tenantId);
            }

            if (! empty($data['bundle_items']) && is_array($data['bundle_items'])) {
                $this->syncBundleItems($product, $data['bundle_items'], $tenantId);
            }

            // PENTING: invalidasi cache katalog menu setiap ada produk baru,
            // agar Kasir/Self-Order langsung melihat produk baru tanpa delay 1 jam.
            $storeId = app(TenantContext::class)->getStoreId();
            $this->menuCache->flush($tenantId, $storeId);

            return $product;
        });
    }

    /**
     * Perbarui entitas lama. Termasuk mengamati file lama untuk dihapus jika digantikan foto yang lebih baru.
     */
    public function update(Product $product, array $data, $file = null): Product
    {
        return DB::transaction(function () use ($product, $data, $file) {
            // Sync status and is_active
            if (isset($data['status'])) {
                $data['is_active'] = ($data['status'] === 'active');
            } elseif (isset($data['is_active'])) {
                $data['is_active'] = (bool) $data['is_active'];
                $data['status'] = $data['is_active'] ? 'active' : 'inactive';
            }

            // Invariant: service, non_inventory, and bundle have track_inventory = false and stock = 0
            if (isset($data['product_type']) && in_array($data['product_type'], ['service', 'non_inventory', 'bundle'], true)) {
                $data['track_inventory'] = false;
                $data['stock'] = 0;
            }

            $tenantId = app(TenantContext::class)->requireTenantId();
            $storeId = app(TenantContext::class)->getStoreId();

            $oldCost = (float) $product->standard_cost;
            $costChanged = isset($data['standard_cost']) && (float) $data['standard_cost'] !== $oldCost;

            if ($file) {
                if ($product->product_photo && Storage::disk('public')->exists($product->product_photo)) {
                    Storage::disk('public')->delete($product->product_photo);
                }
                $path = $file->store("products/{$tenantId}", 'public');
                $data['product_photo'] = $path;

                ProductMedia::where('tenant_id', $tenantId)
                    ->where('product_id', $product->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);

                ProductMedia::create([
                    'tenant_id' => $tenantId,
                    'product_id' => $product->id,
                    'disk' => 'public',
                    'file_path' => $path,
                    'mime_type' => $file->getClientMimeType() ?: ($file->getMimeType() ?: 'image/jpeg'),
                    'file_size_bytes' => $file->getSize() ?: 0,
                    'alt_text' => $product->product_name,
                    'is_primary' => true,
                    'sort_order' => 0,
                ]);
            }

            // [PHASE 14] Saat balance otoritatif, perubahan `stock` TIDAK boleh
            // menulis kolom langsung -- dirutekan lewat ledger adjustment + balance.
            $stockEdit = null;
            if ((bool) config('pos.stock_balances_authoritative', false) && array_key_exists('stock', $data)) {
                $newStock = (float) $data['stock'];
                unset($data['stock']);

                if ($newStock !== (float) $product->stock) {
                    $stockEdit = $newStock;
                }
            }

            $product->update($data);

            if ($costChanged) {
                $newCost = (float) $data['standard_cost'];
                ProductCostHistory::create([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'product_id' => $product->id,
                    'costing_method' => $data['costing_method'] ?? $product->costing_method ?? 'standard',
                    'old_cost' => $oldCost,
                    'new_cost' => $newCost,
                    'reason' => $data['cost_change_reason'] ?? 'Penyesuaian biaya standar via edit produk.',
                    'changed_by' => auth()->id(),
                    'effective_at' => now(),
                ]);

                $this->auditTrail->log(
                    tenantId: $tenantId,
                    action: 'product.cost_changed',
                    userId: auth()->id(),
                    storeId: $storeId,
                    subjectType: Product::class,
                    subjectId: $product->id,
                    description: 'Biaya standar produk '.$product->product_name.' diubah dari Rp '.number_format($oldCost, 2, ',', '.').' menjadi Rp '.number_format($newCost, 2, ',', '.'),
                    oldValues: ['standard_cost' => $oldCost],
                    newValues: ['standard_cost' => $newCost],
                );
            }

            if ($stockEdit !== null) {
                $this->applyStockAdjustment($product, $stockEdit, $tenantId);
            }

            if (isset($data['ingredients']) && is_array($data['ingredients'])) {
                $ingredientsSync = [];
                foreach ($data['ingredients'] as $ing) {
                    if (isset($ing['id']) && isset($ing['quantity'])) {
                        $ingredientsSync[$ing['id']] = [
                            'quantity_required' => $ing['quantity'],
                            'tenant_id' => $tenantId,
                        ];
                    }
                }
                $product->ingredients()->sync($ingredientsSync);
            } else {
                // If ingredients are omitted from the form completely, sync empty (clear BOM)
                if (request()->has('ingredients') || request()->isMethod('PUT') || request()->isMethod('PATCH')) {
                    // only clear if it was meant to be cleared. But actually we pass it as empty array if cleared in Alpine.
                    // If not passed at all, assume empty.
                    $product->ingredients()->sync([]);
                }
            }

            if (isset($data['modifier_groups']) && is_array($data['modifier_groups'])) {
                $modifierGroupsSync = [];
                foreach ($data['modifier_groups'] as $groupId) {
                    $modifierGroupsSync[$groupId] = ['tenant_id' => $tenantId];
                }
                $product->modifierGroups()->sync($modifierGroupsSync);
            } else {
                if (request()->has('modifier_groups') || request()->isMethod('PUT') || request()->isMethod('PATCH')) {
                    $product->modifierGroups()->sync([]);
                }
            }

            if (isset($data['variants']) && is_array($data['variants'])) {
                $this->syncVariants($product, $data['variants'], $tenantId);
            }

            if (isset($data['bundle_items']) && is_array($data['bundle_items'])) {
                $this->syncBundleItems($product, $data['bundle_items'], $tenantId);
            }

            $storeId = app(TenantContext::class)->getStoreId();
            $this->menuCache->flush($tenantId, $storeId);

            return $product;
        });
    }

    /**
     * Destruksi spesifik produk komoditas seutuhnya termasuk meratakan aset gambar.
     */
    public function delete(Product $product): bool
    {
        return DB::transaction(function () use ($product) {
            if ($product->orderItems()->exists()) {
                throw new Exception('Tidak dapat menghapus produk "'.$product->product_name.'" karena terdapat dalam '.$product->orderItems()->count().' pesanan.');
            }

            if ($product->product_photo && Storage::disk('public')->exists($product->product_photo)) {
                Storage::disk('public')->delete($product->product_photo);
            }

            $result = $product->delete();
            $storeId = app(TenantContext::class)->getStoreId();
            $this->menuCache->flush($product->tenant_id, $storeId);

            return $result;
        });
    }

    /**
     * [PHASE 14] Edit stok manual lewat form produk menjadi ledger adjustment
     * (append-only) + pembaruan baris balance store aktif. Kolom legacy
     * products.stock hanya ditulis sebagai MIRROR, bukan sumber kebenaran.
     */
    private function applyStockAdjustment(Product $product, float $newStock, int $tenantId): void
    {
        $storeId = app(TenantContext::class)->getStoreId();

        if ($storeId === null) {
            throw new Exception('Store aktif tidak tersedia untuk penyesuaian stok produk.');
        }

        $delta = $newStock - (float) $product->stock;

        // 1. Ledger append-only (auditable).
        DB::table('stock_movements')->insert([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'product_id' => $product->id,
            'order_id' => null,
            'order_item_id' => null,
            'type' => StockMovementType::Adjustment->value,
            'quantity' => $delta,
            'reason' => 'Penyesuaian stok manual via form produk.',
            'idempotency_key' => 'adjust:product:'.$product->id.':'.Str::uuid()->toString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Mirror kolom legacy.
        //     Hook `Product::saved()` (Phase 13) akan meneruskan perubahan
        //     kolom ini ke baris balance store aktif, sehingga TIDAK ditulis
        //     dua kali di sini.
        $product->stock = (int) round($newStock);
        $product->save();
    }

    public function createProduct(array $data, $file = null): Product
    {
        return $this->store($data, $file);
    }

    public function updateProduct(Product $product, array $data, $file = null): Product
    {
        return $this->update($product, $data, $file);
    }

    public function addMedia(Product $product, $file, bool $isPrimary = false, ?string $altText = null): ProductMedia
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $path = $file->store("products/{$tenantId}", 'public');

        if ($isPrimary) {
            ProductMedia::where('tenant_id', $tenantId)
                ->where('product_id', $product->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $product->update(['product_photo' => $path]);
        }

        return ProductMedia::create([
            'tenant_id' => $tenantId,
            'product_id' => $product->id,
            'disk' => 'public',
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType() ?: ($file->getMimeType() ?: 'image/jpeg'),
            'file_size_bytes' => $file->getSize() ?: 0,
            'alt_text' => $altText ?? $product->product_name,
            'is_primary' => $isPrimary,
            'sort_order' => $product->media()->count(),
        ]);
    }

    public function deleteMedia(ProductMedia $media): bool
    {
        if ($media->disk === 'public' && Storage::disk('public')->exists($media->file_path)) {
            Storage::disk('public')->delete($media->file_path);
        }

        return (bool) $media->delete();
    }

    /**
     * Mengatur ketersediaan dan override harga produk untuk cabang tertentu.
     */
    public function setStoreAssortment(Product $product, int $storeId, array $data): StoreProductAssortment
    {
        return DB::transaction(function () use ($product, $storeId, $data) {
            $assortment = StoreProductAssortment::withoutGlobalScopes()
                ->where('tenant_id', $product->tenant_id)
                ->where('store_id', $storeId)
                ->where('product_id', $product->id)
                ->first();

            $updateData = [];
            if (array_key_exists('is_available', $data)) {
                $updateData['is_available'] = (bool) $data['is_available'];
            }
            if (array_key_exists('store_price_override', $data)) {
                $updateData['store_price_override'] = $data['store_price_override'] !== null
                    ? (float) $data['store_price_override']
                    : null;
            }
            if (array_key_exists('allowed_channels', $data)) {
                $updateData['allowed_channels'] = $data['allowed_channels'];
            }

            if ($assortment) {
                $assortment->update($updateData);
            } else {
                $assortment = StoreProductAssortment::create(array_merge([
                    'tenant_id' => $product->tenant_id,
                    'store_id' => $storeId,
                    'product_id' => $product->id,
                    'is_available' => true,
                ], $updateData));
            }

            $this->menuCache->flush($product->tenant_id, $storeId);

            return $assortment;
        });
    }

    /**
     * Menyimpan atau menyinkronkan varian produk (ADR-01).
     *
     * @param  array<array<string, mixed>>  $variantsData
     */
    public function syncVariants(Product $product, array $variantsData, int $tenantId): void
    {
        $existingVariantIds = [];

        foreach ($variantsData as $index => $vData) {
            $variantName = trim((string) ($vData['variant_name'] ?? ''));
            if ($variantName === '') {
                continue;
            }

            $variantId = ! empty($vData['id']) ? (int) $vData['id'] : null;
            $variant = null;

            if ($variantId) {
                $variant = ProductVariant::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('product_id', $product->id)
                    ->where('id', $variantId)
                    ->first();
            }

            $priceOverride = array_key_exists('price_override', $vData) && $vData['price_override'] !== null
                ? max(0.00, (float) $vData['price_override'])
                : null;

            $payload = [
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'variant_name' => $variantName,
                'sku' => ! empty($vData['sku']) ? trim((string) $vData['sku']) : null,
                'barcode' => ! empty($vData['barcode']) ? trim((string) $vData['barcode']) : null,
                'price_override' => $priceOverride,
                'costing_method' => $vData['costing_method'] ?? 'standard',
                'standard_cost' => isset($vData['standard_cost']) ? max(0.00, (float) $vData['standard_cost']) : 0.00,
                'is_active' => isset($vData['is_active']) ? (bool) $vData['is_active'] : true,
                'sort_order' => (int) ($vData['sort_order'] ?? $index),
            ];

            if ($variant) {
                $variant->update($payload);
            } else {
                $variant = ProductVariant::create($payload);
            }

            $existingVariantIds[] = $variant->id;

            // Hubungkan atribut dan nilai atribut jika disertakan
            if (! empty($vData['attributes']) && is_array($vData['attributes'])) {
                $variant->variantAttributeValues()->delete();
                foreach ($vData['attributes'] as $attr) {
                    if (! empty($attr['attribute_id']) && ! empty($attr['attribute_value_id'])) {
                        ProductVariantAttributeValue::create([
                            'tenant_id' => $tenantId,
                            'variant_id' => $variant->id,
                            'attribute_id' => (int) $attr['attribute_id'],
                            'attribute_value_id' => (int) $attr['attribute_value_id'],
                        ]);
                    }
                }
            }
        }

        // Hapus varian yang tidak disertakan jika update
        if (! empty($existingVariantIds)) {
            ProductVariant::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('product_id', $product->id)
                ->whereNotIn('id', $existingVariantIds)
                ->delete();
        }
    }

    /**
     * Menyinkronkan komponen produk paket/bundel (Sales BOM - ADR-04).
     *
     * @param  array<array<string, mixed>>  $bundleItemsData
     *
     * @throws DomainException
     */
    public function syncBundleItems(Product $product, array $bundleItemsData, int $tenantId): void
    {
        // 1. Ekstrak component_product_ids untuk validasi DAG anti-siklik
        $componentProductIds = [];
        foreach ($bundleItemsData as $bItem) {
            if (! empty($bItem['component_product_id'])) {
                $componentProductIds[] = (int) $bItem['component_product_id'];
            }
        }

        // Jalankan validasi DAG anti-siklik
        $this->bundleExpander->validateDag($product->id, $componentProductIds);

        // 2. Hapus komponen lama dan pasang komponen baru
        ProductBundleItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('bundle_product_id', $product->id)
            ->delete();

        foreach ($bundleItemsData as $index => $bItem) {
            if (empty($bItem['component_product_id'])) {
                continue;
            }

            $qty = isset($bItem['quantity']) ? (float) $bItem['quantity'] : 1.0;
            if ($qty <= 0) {
                continue;
            }

            ProductBundleItem::create([
                'tenant_id' => $tenantId,
                'bundle_product_id' => $product->id,
                'component_product_id' => (int) $bItem['component_product_id'],
                'component_variant_id' => ! empty($bItem['component_variant_id']) ? (int) $bItem['component_variant_id'] : null,
                'quantity' => $qty,
                'uom_id' => ! empty($bItem['uom_id']) ? (int) $bItem['uom_id'] : null,
                'cost_allocation_ratio' => isset($bItem['cost_allocation_ratio']) ? (float) $bItem['cost_allocation_ratio'] : null,
                'sort_order' => (int) ($bItem['sort_order'] ?? $index),
            ]);
        }
    }
}
