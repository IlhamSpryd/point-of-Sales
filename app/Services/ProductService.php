<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exports\ProductsExport;
use App\Models\Product;
use App\Services\Context\TenantContext;
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
    public function __construct(protected MenuCacheService $menuCache) {}

    /**
     * Menyusun urutan Kueri (Product Builder) sembari meramu join ringan "Category".
     */
    public function getFilteredQuery(Request $request): Builder
    {
        $query = Product::query()->with('category')->latest('id');
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
            $data['is_active'] = isset($data['is_active']) ? (bool) $data['is_active'] : false;

            if ($file) {
                $data['product_photo'] = $file->store('products', 'public');
            }

            $product = Product::create($data);
            $tenantId = app(TenantContext::class)->requireTenantId();

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

            // PENTING: invalidasi cache katalog menu setiap ada produk baru,
            // agar Kasir/Self-Order langsung melihat produk baru tanpa delay 1 jam.
            $this->menuCache->flush($tenantId);

            return $product;
        });
    }

    /**
     * Perbarui entitas lama. Termasuk mengamati file lama untuk dihapus jika digantikan foto yang lebih baru.
     */
    public function update(Product $product, array $data, $file = null): Product
    {
        return DB::transaction(function () use ($product, $data, $file) {
            $data['is_active'] = isset($data['is_active']) ? (bool) $data['is_active'] : false;

            if ($file) {
                if ($product->product_photo && Storage::disk('public')->exists($product->product_photo)) {
                    Storage::disk('public')->delete($product->product_photo);
                }
                $data['product_photo'] = $file->store('products', 'public');
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
            $tenantId = app(TenantContext::class)->requireTenantId();

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

            $this->menuCache->flush($tenantId);

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
            $this->menuCache->flush($product->tenant_id);

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
        $product->stock = $newStock;
        $product->save();
    }
}
