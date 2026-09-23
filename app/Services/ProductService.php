<?php

namespace App\Services;

use App\Exports\ProductsExport;
use App\Models\Product;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

            if (isset($data['ingredients']) && is_array($data['ingredients'])) {
                $ingredientsSync = [];
                foreach ($data['ingredients'] as $ing) {
                    if (isset($ing['id']) && isset($ing['quantity'])) {
                        $ingredientsSync[$ing['id']] = ['quantity_required' => $ing['quantity']];
                    }
                }
                $product->ingredients()->sync($ingredientsSync);
            }

            // PENTING: invalidasi cache katalog menu setiap ada produk baru,
            // agar Kasir/Self-Order langsung melihat produk baru tanpa delay 1 jam.
            $this->menuCache->flush();

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

            $product->update($data);

            if (isset($data['ingredients']) && is_array($data['ingredients'])) {
                $ingredientsSync = [];
                foreach ($data['ingredients'] as $ing) {
                    if (isset($ing['id']) && isset($ing['quantity'])) {
                        $ingredientsSync[$ing['id']] = ['quantity_required' => $ing['quantity']];
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

            $this->menuCache->flush();

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

            $this->menuCache->flush();

            return $result;
        });
    }
}
