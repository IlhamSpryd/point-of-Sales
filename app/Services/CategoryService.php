<?php

namespace App\Services;

use App\Exports\CategoriesExport;
use App\Models\Category;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * CategoryService membantu mengisolasi interaksi Database
 * menjauhi ranah HTTP Request Lifecycle.
 */
class CategoryService
{
    /**
     * Merancang pangkalan referensi Eloquent tabel Kategori beserta fungsional filter search-nya.
     */
    public function getFilteredQuery(Request $request): Builder
    {
        $query = Category::query()->latest();
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('category_name', 'like', "%{$search}%");
        }

        return $query;
    }

    /**
     * Eksekusi blok bongkahan data per 100 row kategori dalam mencetak CSV.
     */
    public function exportCsv(Request $request)
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'categories_export_'.now()->format('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new CategoriesExport($query), $fileName);
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
                throw new Exception('Tidak dapat menghapus kategori "'.$category->category_name.'" karena masih memiliki '.$category->products()->count().' produk.');
            }

            return $category->delete();
        });
    }
}
