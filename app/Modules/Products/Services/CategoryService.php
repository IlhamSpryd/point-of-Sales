<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use App\Exports\CategoriesExport;
use App\Models\Category;
use App\Services\Context\TenantContext;
use App\Services\MenuCacheService;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * CategoryService mengisolasi interaksi database kategori produk.
 */
class CategoryService
{
    public function __construct(protected MenuCacheService $menuCache) {}

    public function getFilteredQuery(Request $request): Builder
    {
        $query = Category::query()->with('parent')->latest('id');
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('category_name', 'like', "%{$search}%")
                    ->orWhere('category_code', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    public function exportCsv(Request $request)
    {
        $query = $this->getFilteredQuery($request);
        $fileName = 'categories_export_'.now()->format('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new CategoriesExport($query), $fileName);
    }

    public function store(array $data): Category
    {
        return DB::transaction(function () use ($data) {
            $category = Category::create($data);
            $tenantId = app(TenantContext::class)->requireTenantId();
            $storeId = app(TenantContext::class)->getStoreId();
            $this->menuCache->flush($tenantId, $storeId);

            return $category;
        });
    }

    public function update(Category $category, array $data): Category
    {
        return DB::transaction(function () use ($category, $data) {
            if (isset($data['parent_id'])) {
                $parentId = (int) $data['parent_id'];
                if ($parentId === (int) $category->id) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'Kategori tidak dapat menjadi induk bagi dirinya sendiri.',
                    ]);
                }

                // DAG check: cegah siklus hierarki (A -> B -> A)
                $current = Category::find($parentId);
                while ($current && $current->parent_id !== null) {
                    if ((int) $current->parent_id === (int) $category->id) {
                        throw ValidationException::withMessages([
                            'parent_id' => 'Kategori induk tidak boleh membentuk siklus hierarki.',
                        ]);
                    }
                    $current = Category::find($current->parent_id);
                }
            }

            $category->update($data);
            $storeId = app(TenantContext::class)->getStoreId();
            $this->menuCache->flush((int) $category->tenant_id, $storeId);

            return $category;
        });
    }

    public function createCategory(array $data): Category
    {
        return $this->store($data);
    }

    public function updateCategory(Category $category, array $data): Category
    {
        return $this->update($category, $data);
    }

    public function delete(Category $category): bool
    {
        return DB::transaction(function () use ($category) {
            if ($category->children()->exists()) {
                throw new Exception('Tidak dapat menghapus kategori "'.$category->category_name.'" karena masih memiliki subkategori.');
            }
            if ($category->products()->exists()) {
                throw new Exception('Tidak dapat menghapus kategori "'.$category->category_name.'" karena masih memiliki '.$category->products()->count().' produk.');
            }
            $result = $category->delete();
            $storeId = app(TenantContext::class)->getStoreId();
            $this->menuCache->flush((int) $category->tenant_id, $storeId);

            return $result;
        });
    }
}
