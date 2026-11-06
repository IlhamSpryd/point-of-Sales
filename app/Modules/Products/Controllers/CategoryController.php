<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\AuditTrailService;
use App\Services\CategoryService;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CategoryController: Kontroler minimalis pemantau Kategori tanpa beban logika tinggi.
 * Memastikan payload aman tersaring sebelum diserahkan pada CategoryService.
 */
class CategoryController extends Controller
{
    public function __construct(
        protected CategoryService $categoryService,
        protected ?AuditTrailService $auditTrail = null
    ) {
        $this->auditTrail ??= app(AuditTrailService::class);
    }

    /**
     * Menampilkan list daftar indeks dari resource kategori.
     */
    public function index(Request $request): View|BinaryFileResponse
    {
        $this->authorize('viewAny', Category::class);

        // Bila diinstruksikan oleh parameter url untuk ekspor csv, alihkan kontrol ke Service
        if ($request->has('export') && $request->export === 'csv') {
            return $this->categoryService->exportCsv($request);
        }

        // Dapatkan query berisi filter dari Service Layer, paginasikan hasilnya.
        $categories = $this->categoryService->getFilteredQuery($request)->paginate(15)->withQueryString();
        $parentCategories = Category::orderBy('category_name')->get();

        return view('categories.index', compact('categories', 'parentCategories'));
    }

    /**
     * Endpoint ekspor kategori terdedikasi.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Category::class);

        return $this->categoryService->exportCsv($request);
    }

    /**
     * Menampilkan form untuk membuat resource baru.
     */
    public function create(): View
    {
        $this->authorize('create', Category::class);

        $parentCategories = Category::orderBy('category_name')->get();

        return view('categories.create', compact('parentCategories'));
    }

    /**
     * Persistensi objek baru yang belum ada memori lalu menyimpannya.
     */
    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $this->authorize('create', Category::class);

        $category = $this->categoryService->store($request->validated());

        $tenantId = app(TenantContext::class)->requireTenantId();
        $this->auditTrail?->log(
            tenantId: $tenantId,
            action: 'category.created',
            userId: auth()->id(),
            storeId: app(TenantContext::class)->getStoreId(),
            subjectType: Category::class,
            subjectId: $category->id,
            description: "Category '{$category->category_name}' ({$category->category_code}) created successfully.",
            oldValues: null,
            newValues: $category->toArray()
        );

        return redirect()->route('categories.index')->with('success', 'Category created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Category $category): View
    {
        $this->authorize('update', $category);

        $parentCategories = Category::where('id', '!=', $category->id)->orderBy('category_name')->get();

        return view('categories.edit', compact('category', 'parentCategories'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $oldValues = $category->toArray();
        $updated = $this->categoryService->update($category, $request->validated());

        $tenantId = app(TenantContext::class)->requireTenantId();
        $this->auditTrail?->log(
            tenantId: $tenantId,
            action: 'category.updated',
            userId: auth()->id(),
            storeId: app(TenantContext::class)->getStoreId(),
            subjectType: Category::class,
            subjectId: $category->id,
            description: "Category '{$category->category_name}' updated successfully.",
            oldValues: $oldValues,
            newValues: $updated->toArray()
        );

        return redirect()->route('categories.index')->with('success', 'Category updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        try {
            $tenantId = app(TenantContext::class)->requireTenantId();
            $catName = $category->category_name;
            $catId = $category->id;

            $this->categoryService->delete($category);

            $this->auditTrail?->log(
                tenantId: $tenantId,
                action: 'category.deleted',
                userId: auth()->id(),
                storeId: app(TenantContext::class)->getStoreId(),
                subjectType: Category::class,
                subjectId: $catId,
                description: "Category '{$catName}' deleted successfully."
            );

            return redirect()->route('categories.index')->with('success', 'Category deleted successfully.');
        } catch (QueryException) {
            return redirect()->route('categories.index')->with('error', 'This category is linked to other records and cannot be deleted.');
        } catch (\Throwable $e) {
            return redirect()->route('categories.index')->with('error', $e->getMessage());
        }
    }
}
