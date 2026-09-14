<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Exception;

/**
 * ProductService memberikan penanganan manipulasi Barang Dagang,
 * termasuk manajemen file Foto/Gambar fisiknya.
 */
class ProductService
{
    /**
     * Menyusun urutan Kueri (Product Builder) sembari meramu join ringan "Category".
     */
    public function getFilteredQuery(\Illuminate\Http\Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = \App\Models\Product::query()->with('category')->latest();
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('product_name', 'like', "%{$search}%");
        }
        return $query;
    }

    /**
     * Mendownload spreadsheet format Excel profesional.
     */
    public function exportCsv(\Illuminate\Http\Request $request)
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'products_export_' . now()->format('Y-m-d_H-i-s') . '.xlsx';
        
        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\ProductsExport($query), $fileName);
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

            return Product::create($data);
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

            return $product;
        });
    }

    /**
     * Destruksi spesifik produk komoditas seutuhnya termasuk meratakan aset gambar.
     */
    public function delete(Product $product): bool
    {
        return DB::transaction(function () use ($product) {
            if ($product->orderDetails()->exists()) {
                throw new Exception('Tidak dapat menghapus produk "' . $product->product_name . '" karena terdapat dalam ' . $product->orderDetails()->count() . ' pesanan.');
            }

            if ($product->product_photo && Storage::disk('public')->exists($product->product_photo)) {
                Storage::disk('public')->delete($product->product_photo);
            }

            return $product->delete();
        });
    }
}
