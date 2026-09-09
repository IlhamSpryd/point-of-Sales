<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Exception;

class ProductService
{
    public function getFilteredQuery($request)
    {
        $query = Product::with('category')->latest();
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }
        return $query;
    }

    public function exportCsv($request): StreamedResponse
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'products_export_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'SKU', 'Barcode', 'Category', 'Price', 'Cost Price', 'Stock', 'Status']);

            $query->chunk(100, function ($products) use ($handle) {
                foreach ($products as $product) {
                    fputcsv($handle, [
                        $product->id,
                        $product->name,
                        $product->sku,
                        $product->barcode,
                        $product->category ? $product->category->name : '-',
                        $product->price,
                        $product->cost_price ?? 0,
                        $product->stock,
                        $product->is_active ? 'Active' : 'Inactive'
                    ]);
                }
            });
            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * Store a new product.
     */
    public function store(array $data, $file = null): Product
    {
        return DB::transaction(function () use ($data, $file) {
            $data['is_active'] = isset($data['is_active']) ? (bool) $data['is_active'] : false;
            $data['cost_price'] = $data['cost_price'] ?? 0;

            if (empty($data['sku'])) {
                $lastProduct = Product::latest('id')->first();
                $nextId = $lastProduct ? $lastProduct->id + 1 : 1;
                $data['sku'] = 'PRD-' . str_pad($nextId, 5, '0', STR_PAD_LEFT);
            }

            if ($file) {
                $data['photo'] = $file->store('products', 'public');
            }

            return Product::create($data);
        });
    }

    /**
     * Update an existing product.
     */
    public function update(Product $product, array $data, $file = null): Product
    {
        return DB::transaction(function () use ($product, $data, $file) {
            $data['is_active'] = isset($data['is_active']) ? (bool) $data['is_active'] : false;
            $data['cost_price'] = $data['cost_price'] ?? 0;

            if (empty($data['sku'])) {
                $data['sku'] = 'PRD-' . str_pad($product->id, 5, '0', STR_PAD_LEFT);
            }

            if ($file) {
                if ($product->photo && Storage::disk('public')->exists($product->photo)) {
                    Storage::disk('public')->delete($product->photo);
                }
                $data['photo'] = $file->store('products', 'public');
            }

            $product->update($data);

            return $product;
        });
    }

    /**
     * Delete a product.
     */
    public function delete(Product $product): bool
    {
        return DB::transaction(function () use ($product) {
            if ($product->orderDetails()->exists()) {
                throw new Exception('Cannot delete product "' . $product->name . '" because it is referenced in ' . $product->orderDetails()->count() . ' order(s). Archive it instead.');
            }

            if ($product->photo && Storage::disk('public')->exists($product->photo)) {
                Storage::disk('public')->delete($product->photo);
            }
            
            return $product->delete();
        });
    }
}
