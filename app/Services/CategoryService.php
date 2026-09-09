<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Exception;

class CategoryService
{
    public function getFilteredQuery($request)
    {
        $query = Category::latest();
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }
        return $query;
    }

    public function exportCsv($request): StreamedResponse
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'categories_export_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Description', 'Status']);

            $query->chunk(100, function ($categories) use ($handle) {
                foreach ($categories as $category) {
                    fputcsv($handle, [
                        $category->id,
                        $category->name,
                        $category->description,
                        $category->is_active ? 'Active' : 'Inactive',
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
     * Store a new category.
     */
    public function store(array $data): Category
    {
        return DB::transaction(function () use ($data) {
            $data['is_active'] = isset($data['is_active']) ? (bool) $data['is_active'] : false;
            return Category::create($data);
        });
    }

    /**
     * Update an existing category.
     */
    public function update(Category $category, array $data): Category
    {
        return DB::transaction(function () use ($category, $data) {
            $data['is_active'] = isset($data['is_active']) ? (bool) $data['is_active'] : false;
            $category->update($data);
            return $category;
        });
    }

    /**
     * Delete a category.
     */
    public function delete(Category $category): bool
    {
        return DB::transaction(function () use ($category) {
            if ($category->products()->exists()) {
                throw new Exception('Cannot delete category "' . $category->name . '" because it has ' . $category->products()->count() . ' product(s) still assigned to it.');
            }

            return $category->delete();
        });
    }
}
