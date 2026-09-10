<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Exception;

/**
 * CategoryService membantu mengisolasi interaksi Database
 * menjauhi ranah HTTP Request Lifecycle.
 */
class CategoryService
{
    /**
     * Merancang pangkalan referensi Eloquent tabel Kategori beserta fungsional filter search-nya.
     */
    public function getFilteredQuery(\Illuminate\Http\Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = \App\Models\Category::query()->latest();
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('category_name', 'like', "%{$search}%");
        }
        return $query;
    }

    /**
     * Eksekusi blok bongkahan data per 100 row kategori dalam mencetak CSV.
     */
    public function exportCsv(\Illuminate\Http\Request $request): StreamedResponse
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'categories_export_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Category Name']);

            $query->chunk(100, function ($categories) use ($handle) {
                foreach ($categories as $category) {
                    fputcsv($handle, [
                        $category->id,
                        $category->category_name,
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
     * Penyematan instansiasi Katalog Kategori baru.
     */
    public function store(array $data): Category
    {
        return DB::transaction(function () use ($data) {
            return Category::create($data);
        });
    }

    /**
     * Menyempurnakan pembaruan Kategori.
     */
    public function update(Category $category, array $data): Category
    {
        return DB::transaction(function () use ($category, $data) {
            $category->update($data);
            return $category;
        });
    }

    /**
     * Buang klasifikasi dari daftar sistem (Menolak aksi bila kategori terhubung dengan produk).
     */
    public function delete(Category $category): bool
    {
        return DB::transaction(function () use ($category) {
            if ($category->products()->exists()) {
                throw new Exception('Tidak dapat menghapus kategori "' . $category->category_name . '" karena masih memiliki ' . $category->products()->count() . ' produk.');
            }

            return $category->delete();
        });
    }
}
